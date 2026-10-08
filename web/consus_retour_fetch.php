<?php

/**
 * Nachtelijke BC-fetch voor de Retourlijst (#1159), voor de leveranciers uit de
 * regels van alle afdelingen. Geen regels = geen fetch.
 * Gebruikt consus_each_entity_rows: Mímir eerst (max_age van nightly, 4 uur),
 * daarna het directe BC-pad; een geweigerd optioneel veld wordt uit $select gehaald.
 */

require_once __DIR__ . '/consus_data.php';
require_once __DIR__ . '/consus_retour.php';

function consus_retour_odata_or(string $field, array $values): string
{
    $parts = [];
    foreach ($values as $value) {
        $parts[] = $field . " eq '" . consus_escape_odata_string((string) $value) . "'";
    }

    return '(' . implode(' or ', $parts) . ')';
}

/**
 * Vindt het PO-nummer in een tekstregel als "Order No. PO52600946:" of
 * "Ordernr. PO52600946:" (factuur via Ontvangstregels ophalen).
 */
function consus_retour_po_from_text(string $description): string
{
    if (preg_match('/\b(PO\d{6,}|4500\d{6})\b/i', $description, $m)) {
        return strtoupper($m[1]);
    }

    return '';
}

/**
 * Zet ruwe factuurregels om naar compacte artikelregels. Order_No op de regel
 * wint; is die leeg, dan geldt het PO uit de laatste tekstregel van dezelfde factuur.
 *
 * @param array<int, array<string, mixed>> $rawLines
 * @param array<string, array{vendor_invoice:string,document_date:string}> $headers
 */
function consus_retour_compact_lines(array $rawLines, array $headers): array
{
    usort($rawLines, static fn (array $a, array $b): int => [(string) ($a['Document_No'] ?? ''), (int) ($a['Line_No'] ?? 0)] <=> [(string) ($b['Document_No'] ?? ''), (int) ($b['Line_No'] ?? 0)]);
    $lines = [];
    $context = [];
    foreach ($rawLines as $raw) {
        $invoice = (string) ($raw['Document_No'] ?? '');
        if (!isset($headers[$invoice])) {
            continue;
        }
        if (!consus_retour_is_item_line($raw)) {
            $po = consus_retour_po_from_text((string) ($raw['Description'] ?? ''));
            if ($po !== '') {
                $context[$invoice] = $po;
            }
            continue;
        }
        $order = consus_retour_normalize_po((string) ($raw['Order_No'] ?? ''));
        if ($order === '') {
            $order = $context[$invoice] ?? '';
        }
        $unitCost = $raw['Direct_Unit_Cost'] ?? null;
        if (!is_numeric($unitCost)) {
            $quantity = (float) ($raw['Quantity'] ?? 0);
            $unitCost = $quantity != 0.0 && is_numeric($raw['Amount'] ?? null) ? (float) $raw['Amount'] / $quantity : 0.0;
        }
        $lines[] = [
            'invoice' => $invoice,
            'vendor' => (string) ($headers[$invoice]['vendor'] ?? ''),
            'vendor_invoice' => $headers[$invoice]['vendor_invoice'],
            'document_date' => $headers[$invoice]['document_date'],
            'item' => (string) $raw['No'],
            'description' => trim((string) ($raw['Description'] ?? '')),
            'quantity' => (float) ($raw['Quantity'] ?? 0),
            'unit_cost' => (float) $unitCost,
            'order_no' => $order,
            'department' => trim((string) ($raw['Shortcut_Dimension_1_Code'] ?? '')),
        ];
    }

    return $lines;
}

/**
 * Reserveringen per artikel. Alleen status Reservation/Reservering. De positieve
 * kant uit een artikelpost (Source_Type 32) is gereserveerde voorraad; gaat de
 * negatieve kant naar een inkoopretourorder (39, subtype 5), dan staat het al op retour.
 *
 * @return array<string, array{reserved:float,reserved_return:float}>
 */
function consus_retour_reservations(array $entries): array
{
    $byEntry = [];
    foreach ($entries as $entry) {
        $status = strtolower(trim((string) ($entry['Reservation_Status'] ?? 'Reservation')));
        if (!in_array($status, ['reservation', 'reservering'], true)) {
            continue;
        }
        $side = consus_retour_bool($entry['Positive'] ?? false) ? 'pos' : 'neg';
        $byEntry[(string) ($entry['Entry_No'] ?? '')][$side] = $entry;
    }
    $result = [];
    foreach ($byEntry as $pair) {
        $pos = $pair['pos'] ?? null;
        if (!is_array($pos) || (int) ($pos['Source_Type'] ?? 0) !== 32) {
            continue;
        }
        $item = strtoupper(trim((string) ($pos['Item_No'] ?? '')));
        $qty = abs((float) ($pos['Quantity_Base'] ?? 0));
        $result[$item] ??= ['reserved' => 0.0, 'reserved_return' => 0.0];
        $result[$item]['reserved'] += $qty;
        $neg = $pair['neg'] ?? null;
        if (is_array($neg) && (int) ($neg['Source_Type'] ?? 0) === 39 && trim((string) ($neg['Source_Subtype'] ?? '')) === '5') {
            $result[$item]['reserved_return'] += $qty;
        }
    }

    return $result;
}

/**
 * @param callable|null $each fn(string $company, string $entitySet, array $required, array $optional, string $filter, callable $onRow): void
 */
function consus_retour_build(string $company, array $settings, ?callable $each = null, ?string $today = null): array
{
    $today ??= consus_retour_today();
    $each ??= static function (string $company, string $entitySet, array $required, array $optional, string $filter, callable $onRow): void {
        consus_each_entity_rows($company, $entitySet, $required, $optional, $filter, $onRow);
    };
    $vendors = consus_retour_rule_vendors($settings);
    $warnings = [];
    // Ruim venster: een langere termijn in de regels werkt dan direct, zonder nieuwe nightly.
    $days = max(CONSUS_RETOUR_FETCH_DAYS, consus_retour_max_window($settings));
    $minStart = (new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('Europe/Amsterdam')))->modify('-' . $days . ' days')->format('Y-m-d');
    $typesByVendor = [];
    foreach ($vendors as $vendorNo) {
        $typesByVendor[$vendorNo] = consus_retour_vendor_types($vendorNo);
    }
    $empty = [
        'generated_at' => gmdate('c'),
        'company' => $company,
        'fetched_from' => $minStart,
        'vendors' => $vendors,
        'types_by_vendor' => $typesByVendor,
        'lines' => [],
        'orders' => [],
        'items' => [],
        'warnings' => [],
    ];
    if ($vendors === []) {
        return $empty;
    }
    $vendor = consus_retour_odata_or('Buy_from_Vendor_No', $vendors);

    $headers = [];
    $each($company, 'GeboekteInkoopfacturen', ['No', 'Document_Date', 'Buy_from_Vendor_No'], ['Vendor_Invoice_No', 'Posting_Date'], $vendor . ' and Document_Date ge ' . $minStart, static function (array $row) use (&$headers): void {
        $no = (string) ($row['No'] ?? '');
        if ($no !== '') {
            $headers[$no] = [
                'vendor' => consus_retour_normalize_vendor($row['Buy_from_Vendor_No'] ?? ''),
                'vendor_invoice' => trim((string) ($row['Vendor_Invoice_No'] ?? '')),
                'document_date' => consus_retour_parse_date($row['Document_Date'] ?? ''),
            ];
        }
    });

    $rawLines = [];
    foreach (array_chunk(array_keys($headers), CONSUS_RETOUR_CHUNK) as $chunk) {
        $each($company, 'GeboekteInkoopfactuurRegels', ['Document_No', 'Type', 'No', 'Quantity'], ['Line_No', 'Description', 'Direct_Unit_Cost', 'Amount', 'Order_No', 'Shortcut_Dimension_1_Code'], $vendor . ' and ' . consus_retour_odata_or('Document_No', $chunk), static function (array $row) use (&$rawLines): void {
            $rawLines[] = $row;
        });
    }
    $lines = consus_retour_compact_lines($rawLines, $headers);
    $lines = array_values(array_filter($lines, static fn (array $line): bool => $line['document_date'] !== '' && $line['document_date'] >= $minStart));

    $poNumbers = [];
    $itemNumbers = [];
    foreach ($lines as $line) {
        // PO-vlaggen alleen voor leveranciers waarvan de type-provider types afleidt.
        if ($line['order_no'] !== '' && ($typesByVendor[$line['vendor']] ?? []) !== []) {
            $poNumbers[$line['order_no']] = true;
        }
        $itemNumbers[strtoupper($line['item'])] = $line['item'];
    }

    $orders = [];
    $orderFields = ['KVT_Export_Status_Perkins_EGT', 'KVT_Export_Status_EGT', 'KVT_Export_Status_Perkins_CSV'];
    $onOrder = static function (array $row) use (&$orders): void {
        $no = consus_retour_normalize_po((string) ($row['No'] ?? ''));
        if ($no === '' || !empty($orders[$no]['found'])) {
            return;
        }
        $egt = array_key_exists('KVT_Export_Status_Perkins_EGT', $row) ? $row['KVT_Export_Status_Perkins_EGT'] : ($row['KVT_Export_Status_EGT'] ?? null);
        $csv = $row['KVT_Export_Status_Perkins_CSV'] ?? null;
        $orders[$no] = [
            'found' => true,
            'egt' => $egt === null ? null : consus_retour_bool($egt),
            'csv' => $csv === null ? null : consus_retour_bool($csv),
        ];
    };
    foreach (array_chunk(array_keys($poNumbers), CONSUS_RETOUR_CHUNK) as $chunk) {
        $each($company, 'AppPurchaseOrder', ['No'], $orderFields, consus_retour_odata_or('No', $chunk), $onOrder);
    }
    $missing = array_values(array_filter(array_keys($poNumbers), static fn (string $po): bool => empty($orders[$po]['found'])));
    if ($missing !== []) {
        // Order is na volledige facturering verwijderd: probeer het archief (pagina 5168),
        // als Ariadne dat als GearchiveerdeInkooporders publiceert.
        try {
            foreach (array_chunk($missing, CONSUS_RETOUR_CHUNK) as $chunk) {
                $each($company, 'GearchiveerdeInkooporders', ['No'], $orderFields, consus_retour_odata_or('No', $chunk), $onOrder);
            }
        } catch (Throwable $error) {
            $warnings[] = count($missing) . ' inkooporder(s) staan niet meer in BC en het archief is niet bereikbaar; die tellen als voorraad (57420).';
        }
    }

    $items = [];
    foreach ($itemNumbers as $key => $no) {
        $items[$key] = ['description' => '', 'safety_stock' => 0.0, 'stock' => [], 'bin' => [], 'reserved' => 0.0, 'reserved_return' => 0.0, 'tariff' => '', 'origin' => ''];
    }
    $chunks = array_chunk(array_values($itemNumbers), CONSUS_RETOUR_CHUNK);
    foreach ($chunks as $chunk) {
        $each($company, 'AppItemCard', ['No'], ['Description', 'Safety_Stock_Quantity', 'Tariff_No', 'Country_Region_of_Origin_Code'], consus_retour_odata_or('No', $chunk), static function (array $row) use (&$items): void {
            $key = strtoupper((string) ($row['No'] ?? ''));
            if (!isset($items[$key])) {
                return;
            }
            $items[$key]['description'] = trim((string) ($row['Description'] ?? ''));
            $items[$key]['safety_stock'] = (float) ($row['Safety_Stock_Quantity'] ?? 0);
            $items[$key]['tariff'] = trim((string) ($row['Tariff_No'] ?? ''));
            $items[$key]['origin'] = trim((string) ($row['Country_Region_of_Origin_Code'] ?? ''));
        });
        $each($company, 'ItemLedgerEntries', ['Item_No', 'Remaining_Quantity', 'Location_Code'], [], 'Open eq true and ' . consus_retour_odata_or('Item_No', $chunk), static function (array $row) use (&$items): void {
            $key = strtoupper((string) ($row['Item_No'] ?? ''));
            if (!isset($items[$key])) {
                return;
            }
            $location = strtoupper(trim((string) ($row['Location_Code'] ?? '')));
            $items[$key]['stock'][$location] = ($items[$key]['stock'][$location] ?? 0.0) + (float) ($row['Remaining_Quantity'] ?? 0);
        });
        $reservationRows = [];
        $each($company, 'ReservationEntries', ['Entry_No', 'Positive', 'Item_No', 'Quantity_Base', 'Source_Type'], ['Source_Subtype', 'Reservation_Status'], consus_retour_odata_or('Item_No', $chunk), static function (array $row) use (&$reservationRows): void {
            $reservationRows[] = $row;
        });
        foreach (consus_retour_reservations($reservationRows) as $key => $reservation) {
            if (isset($items[$key])) {
                $items[$key]['reserved'] += $reservation['reserved'];
                $items[$key]['reserved_return'] += $reservation['reserved_return'];
            }
        }
    }

    // Bincontrole: BinContent, anders Magazijnposten; lukt geen van beide, dan geen oordeel (bin = null).
    $binOk = true;
    $locationFilter = consus_retour_odata_or('Location_Code', CONSUS_RETOUR_BIN_LOCATIONS);
    try {
        $bins = consus_retour_collect_bins($each, $company, 'BinContent', 'Quantity_Base', $locationFilter, $chunks);
    } catch (Throwable $error) {
        try {
            $bins = consus_retour_collect_bins($each, $company, 'Magazijnposten', 'Qty_Base', $locationFilter, $chunks);
        } catch (Throwable $second) {
            $binOk = false;
            $bins = [];
            $warnings[] = 'Bincontrole niet beschikbaar (BinContent en Magazijnposten mislukt).';
        }
    }
    foreach ($items as $key => $item) {
        $items[$key]['bin'] = $binOk ? ($bins[$key] ?? []) : null;
    }

    return [
        'lines' => $lines,
        'orders' => $orders,
        'items' => $items,
        'warnings' => $warnings,
    ] + $empty;
}

/** @return array<string, array<string, float>> */
function consus_retour_collect_bins(callable $each, string $company, string $entitySet, string $qtyField, string $locationFilter, array $chunks): array
{
    $bins = [];
    foreach ($chunks as $chunk) {
        $each($company, $entitySet, ['Item_No', 'Location_Code', $qtyField], [], $locationFilter . ' and ' . consus_retour_odata_or('Item_No', $chunk), static function (array $row) use (&$bins, $qtyField): void {
            $key = strtoupper((string) ($row['Item_No'] ?? ''));
            $location = strtoupper(trim((string) ($row['Location_Code'] ?? '')));
            $bins[$key][$location] = ($bins[$key][$location] ?? 0.0) + (float) ($row[$qtyField] ?? 0);
        });
    }

    return $bins;
}

/**
 * Wordt na de snapshot vanuit nightly.php aangeroepen. Een fout hier laat de
 * vorige retourdata staan en raakt de snapshot niet.
 */
function consus_retour_refresh(): array
{
    $settings = consus_retour_settings_read();
    if (consus_retour_rule_vendors($settings) === []) {
        // Geen regels in welke afdeling dan ook: niets ophalen.
        return ['skipped' => true, 'lines' => []];
    }
    $GLOBALS['consus_odata_max_age'] = CONSUS_NIGHTLY_MAX_AGE;
    $discovered = auth_discover_companies_across_active_environments();
    $names = is_array($discovered['companies'] ?? null) ? $discovered['companies'] : [];
    $company = '';
    foreach (consus_companies_in_scope($names) as $entry) {
        if (($entry['company_key'] ?? '') === CONSUS_RETOUR_COMPANY_KEY) {
            $company = (string) $entry['company'];
        }
    }
    if ($company === '') {
        throw new RuntimeException('Hunter van Twist niet gevonden voor de Retourlijst.');
    }
    $data = consus_retour_build($company, $settings);
    consus_write_json_locked(consus_retour_data_file(), $data);

    return $data;
}
