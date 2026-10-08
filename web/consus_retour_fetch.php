<?php

/**
 * Nachtelijke BC-fetch voor de Retourlijst (#1159): per bedrijf met regels, in
 * de environment van dat bedrijf, voor de leveranciers uit de regels van dat
 * bedrijf. Geen regels = geen fetch.
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

/** Magazijndocumentsoort Ontvangst (BC kan Engels of Nederlands sturen). */
function consus_retour_is_receipt_doc_type(mixed $value): bool
{
    return in_array(strtolower(trim((string) $value)), ['receipt', 'ontvangst', 'posted receipt', 'geboekte ontvangst'], true);
}

/**
 * Perkins-factuurnummer en Handling Unit (doosnummer) per inkooporderregel (#1159).
 *  - $receiptLines: PurchaseReceiptLines (LVS_Order_No, LVS_Order_Line_No, No, Quantity, VendorShptNo).
 *  - $whseEntries: Magazijnposten (Source_No, Source_Line_No, Item_No, Whse_Document_No, Whse_Document_Type).
 *  - $whseHeaders: PostedWhseReceipt per No: {hu, invoice} (KVT_Handling_Unit, Vendor_Shipment_No).
 * Eén geboekte magazijnontvangst = één doos; een orderregel kan meerdere dozen hebben.
 * Sleutels: "PO\x1fREGEL" (exact) en "PO\x1fARTIKEL" (factuurregels hebben geen orderregelnummer).
 *
 * @return array<string, array{invoices:array<int,string>, hus:array<int,string>}>
 */
function consus_retour_receipt_info(array $receiptLines, array $whseEntries, array $whseHeaders): array
{
    $info = [];
    $add = static function (string $po, string $lineNo, string $item, string $invoice, string $hu) use (&$info): void {
        $keys = [];
        if ($lineNo !== '' && $lineNo !== '0') {
            $keys[] = $po . "\x1f#" . $lineNo;
        }
        if ($item !== '') {
            $keys[] = $po . "\x1f" . strtoupper($item);
        }
        foreach ($keys as $key) {
            $info[$key] ??= ['invoices' => [], 'hus' => []];
            if ($invoice !== '' && !in_array($invoice, $info[$key]['invoices'], true)) {
                $info[$key]['invoices'][] = $invoice;
            }
            if ($hu !== '' && !in_array($hu, $info[$key]['hus'], true)) {
                $info[$key]['hus'][] = $hu;
            }
        }
    };
    foreach ($receiptLines as $row) {
        $po = consus_retour_normalize_po((string) ($row['LVS_Order_No'] ?? $row['OrderNo'] ?? ''));
        // Regels met aantal 0 zijn restanten van deelboekingen.
        if ($po === '' || (float) ($row['Quantity'] ?? 0) == 0.0) {
            continue;
        }
        $add($po, (string) (int) ($row['LVS_Order_Line_No'] ?? 0), trim((string) ($row['No'] ?? '')), trim((string) ($row['VendorShptNo'] ?? '')), '');
    }
    foreach ($whseEntries as $row) {
        $doc = strtoupper(trim((string) ($row['Whse_Document_No'] ?? '')));
        if ($doc === '' || !isset($whseHeaders[$doc]) || (array_key_exists('Whse_Document_Type', $row) && !consus_retour_is_receipt_doc_type($row['Whse_Document_Type']))) {
            continue;
        }
        $po = consus_retour_normalize_po((string) ($row['Source_No'] ?? ''));
        if ($po === '') {
            continue;
        }
        $add($po, (string) (int) ($row['Source_Line_No'] ?? 0), trim((string) ($row['Item_No'] ?? '')), (string) $whseHeaders[$doc]['invoice'], (string) $whseHeaders[$doc]['hu']);
    }
    foreach ($info as $key => $entry) {
        sort($info[$key]['hus'], SORT_STRING);
    }

    return $info;
}

/**
 * Ontvangen maar nog niet gefactureerde regels (PurchaseReceiptLines met
 * Qty_Rcd_Not_Invoiced > 0) als compacte regels; invoice = nummer van de
 * inkoopontvangst, document_date = boekingsdatum van de ontvangst.
 *
 * @param array<string, string> $receiptDates Document_No => Y-m-d
 */
function consus_retour_receipt_lines(array $rows, array $receiptDates): array
{
    $lines = [];
    foreach ($rows as $row) {
        if (!consus_retour_is_item_line($row)) {
            continue;
        }
        $doc = trim((string) ($row['Document_No'] ?? ''));
        $qty = (float) ($row['Qty_Rcd_Not_Invoiced'] ?? 0);
        $date = $receiptDates[strtoupper($doc)] ?? '';
        if ($doc === '' || $qty <= 0 || $date === '') {
            continue;
        }
        $amount = $row['LVS_Amt_Rcd_Not_Invoiced'] ?? null;
        $unitCost = is_numeric($amount) ? (float) $amount / $qty : (float) ($row['Direct_Unit_Cost'] ?? 0);
        $lines[] = [
            'invoice' => $doc,
            'source' => 'receipt',
            'vendor' => consus_retour_normalize_vendor($row['Buy_from_Vendor_No'] ?? ''),
            'vendor_invoice' => '',
            'document_date' => $date,
            'item' => (string) $row['No'],
            'description' => trim((string) ($row['Description'] ?? '')),
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'order_no' => consus_retour_normalize_po((string) ($row['LVS_Order_No'] ?? $row['OrderNo'] ?? '')),
            'order_line' => (int) ($row['LVS_Order_Line_No'] ?? 0),
            'department' => trim((string) ($row['Shortcut_Dimension_1_Code'] ?? '')),
        ];
    }

    return $lines;
}

/** Zet Perkins-factuurnummer en Handling Units op de regels (leeg als onbekend). */
function consus_retour_apply_receipt_info(array $lines, array $info): array
{
    foreach ($lines as $index => $line) {
        $po = (string) ($line['order_no'] ?? '');
        $entry = null;
        if ($po !== '' && (int) ($line['order_line'] ?? 0) > 0) {
            $entry = $info[$po . "\x1f#" . (int) $line['order_line']] ?? null;
        }
        if ($entry === null && $po !== '') {
            $entry = $info[$po . "\x1f" . strtoupper((string) ($line['item'] ?? ''))] ?? null;
        }
        $invoices = $entry['invoices'] ?? [];
        $fallback = trim((string) ($line['vendor_invoice'] ?? ''));
        // Een factuurregel hoort bij één Perkins-factuur: past die bij een ontvangst, dan die.
        $lines[$index]['perkins_invoice'] = $fallback !== '' && ($invoices === [] || in_array($fallback, $invoices, true))
            ? $fallback
            : implode(', ', $invoices);
        $lines[$index]['handling_units'] = array_values($entry['hus'] ?? []);
    }

    return $lines;
}

/** Tijdsbudget voor de ontvangst-lookups in de nightly (seconden). */
const CONSUS_RETOUR_RECEIPT_BUDGET = 900;

/**
 * @param callable|null $each fn(string $company, string $entitySet, array $required, array $optional, string $filter, callable $onRow): void
 */
function consus_retour_build(string $company, array $settings, ?callable $each = null, ?string $today = null): array
{
    $today ??= consus_retour_today();
    $each ??= static function (string $company, string $entitySet, array $required, array $optional, string $filter, callable $onRow): void {
        consus_each_entity_rows($company, $entitySet, $required, $optional, $filter, $onRow);
    };
    $vendors = consus_retour_rule_vendors($settings, $company);
    $warnings = [];
    // Ruim venster: een langere termijn in de regels werkt dan direct, zonder nieuwe nightly.
    $days = max(CONSUS_RETOUR_FETCH_DAYS, consus_retour_max_window($settings, $company));
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
        'bin_locations' => consus_retour_bin_locations_for($company),
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

    // Spoed (#1159): ontvangen maar nog niet gefactureerd telt vanaf de geboekte ontvangst.
    $started = microtime(true);
    $receiptLines = [];
    try {
        $openRows = [];
        $each($company, 'PurchaseReceiptLines', ['Document_No', 'Type', 'No', 'Qty_Rcd_Not_Invoiced', 'LVS_Order_No', 'Buy_from_Vendor_No'], ['LVS_Order_Line_No', 'Description', 'LVS_Amt_Rcd_Not_Invoiced', 'Shortcut_Dimension_1_Code'], $vendor . ' and Qty_Rcd_Not_Invoiced gt 0', static function (array $row) use (&$openRows): void {
            $openRows[] = $row;
        });
        $receiptDates = [];
        $docs = array_values(array_unique(array_map(static fn (array $r): string => strtoupper(trim((string) ($r['Document_No'] ?? ''))), $openRows)));
        foreach (array_chunk(array_filter($docs), CONSUS_RETOUR_CHUNK) as $chunk) {
            $each($company, 'PostedPurchaseReceipt', ['No', 'Posting_Date'], [], consus_retour_odata_or('No', $chunk), static function (array $row) use (&$receiptDates): void {
                $receiptDates[strtoupper(trim((string) ($row['No'] ?? '')))] = consus_retour_parse_date($row['Posting_Date'] ?? '');
            });
        }
        $receiptLines = array_values(array_filter(consus_retour_receipt_lines($openRows, $receiptDates), static fn (array $line): bool => $line['document_date'] >= $minStart));
    } catch (Throwable $error) {
        $warnings[] = 'Ontvangen, nog niet gefactureerde regels niet opgehaald: ' . $error->getMessage();
    }

    $poNumbers = [];
    $itemNumbers = [];
    foreach (array_merge($lines, $receiptLines) as $line) {
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
    // De Perkins-vlaggen staan alleen op open orders (AppPurchaseOrder). Het archief
    // (GearchiveerdeInkoopkoppen/-orders, Ariadne 08-10-2026) heeft ze niet, dus daar
    // halen we niets. Een order die na volledige facturering weg is, telt als
    // voorraad (57420); de regel zegt dat er zichtbaar bij.
    // Alleen spoedorders (EGT aan, CSV uit) komen al vanaf de ontvangst op de lijst;
    // voorraadorders worden binnen een dag gefactureerd en komen via de factuur.
    $receiptLines = array_values(array_filter($receiptLines, static function (array $line) use ($orders): bool {
        $order = $orders[$line['order_no']] ?? null;
        if (!is_array($order) || empty($order['found'])) {
            return false;
        }

        return consus_retour_classify($order['egt'] ?? null, $order['csv'] ?? null) === CONSUS_RETOUR_ACCOUNT_SPOED;
    }));
    $lines = array_merge($lines, $receiptLines);

    // Perkins-factuurnummer en Handling Unit uit de ontvangsten van hetzelfde bedrijf.
    // Bij Hunter van Twist gevuld; bij KvT leeg (dan blijft alleen het factuurnummer).
    try {
        [$lines, $receiptWarning] = consus_retour_enrich_receipts($each, $company, $vendor, $lines, $minStart, $started);
        if ($receiptWarning !== '') {
            $warnings[] = $receiptWarning;
        }
    } catch (Throwable $error) {
        $lines = consus_retour_apply_receipt_info($lines, []);
        $warnings[] = 'Perkins-factuurnummer/Handling Unit niet opgehaald: ' . $error->getMessage();
    }

    $missing = array_values(array_filter(array_keys($poNumbers), static fn (string $po): bool => empty($orders[$po]['found'])));
    if ($missing !== []) {
        $warnings[] = count($missing) . ' inkooporder(s) staan niet meer open in BC; zonder EGT/CSV-vlag tellen die als voorraad (57420).';
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
    $binLocations = consus_retour_bin_locations_for($company);
    if ($binLocations === []) {
        // Bedrijf zonder bekend magazijn met bins: geen bincontrole, geen extra calls.
        foreach ($items as $key => $item) {
            $items[$key]['bin'] = null;
        }

        return ['lines' => $lines, 'orders' => $orders, 'items' => $items, 'warnings' => $warnings] + $empty;
    }
    $locationFilter = consus_retour_odata_or('Location_Code', $binLocations);
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

/**
 * Haalt ontvangstregels, geboekte magazijnontvangsten (met Handling Unit) en de
 * Magazijnposten (PO-regel) op voor de PO's van $lines. Begrensd door
 * CONSUS_RETOUR_RECEIPT_BUDGET: daarna stopt het en blijft de rest leeg.
 *
 * @return array{0:array, 1:string}
 */
function consus_retour_enrich_receipts(callable $each, string $company, string $vendorFilter, array $lines, string $minStart, float $started, ?int $budget = null): array
{
    $budget ??= CONSUS_RETOUR_RECEIPT_BUDGET;
    $overBudget = static fn (): bool => microtime(true) - $started > $budget;
    $pos = [];
    foreach ($lines as $line) {
        if (($line['order_no'] ?? '') !== '') {
            $pos[$line['order_no']] = true;
        }
    }
    $pos = array_keys($pos);
    $partial = false;
    $receiptRows = [];
    foreach (array_chunk($pos, CONSUS_RETOUR_CHUNK) as $chunk) {
        if ($overBudget()) {
            $partial = true;
            break;
        }
        $each($company, 'PurchaseReceiptLines', ['LVS_Order_No', 'No', 'Quantity'], ['LVS_Order_Line_No', 'VendorShptNo'], $vendorFilter . ' and ' . consus_retour_odata_or('LVS_Order_No', $chunk), static function (array $row) use (&$receiptRows): void {
            $receiptRows[] = $row;
        });
    }
    // Eerst de dozen zelf: geboekte magazijnontvangsten met Handling Unit. Zijn
    // die er niet (KvT), dan geen Magazijnposten-calls.
    $headers = [];
    // Ontvangst ligt vóór de factuur: 60 dagen extra marge.
    $whseFrom = (new DateTimeImmutable($minStart . ' 00:00:00', new DateTimeZone('Europe/Amsterdam')))->modify('-60 days')->format('Y-m-d');
    $each($company, 'PostedWhseReceipt', ['No', 'KVT_Handling_Unit'], ['Vendor_Shipment_No'], "Posting_Date ge " . $whseFrom . " and KVT_Handling_Unit ne ''", static function (array $row) use (&$headers): void {
        $no = strtoupper(trim((string) ($row['No'] ?? '')));
        $hu = trim((string) ($row['KVT_Handling_Unit'] ?? ''));
        if ($no !== '' && $hu !== '') {
            $headers[$no] = ['hu' => $hu, 'invoice' => trim((string) ($row['Vendor_Shipment_No'] ?? ''))];
        }
    });
    $entries = [];
    if ($headers !== []) {
        foreach (array_chunk($pos, CONSUS_RETOUR_CHUNK) as $chunk) {
            if ($overBudget()) {
                $partial = true;
                break;
            }
            $each($company, 'Magazijnposten', ['Source_No', 'Source_Line_No', 'Item_No', 'Whse_Document_No'], ['Whse_Document_Type'], consus_retour_odata_or('Source_No', $chunk), static function (array $row) use (&$entries, $headers): void {
                $doc = strtoupper(trim((string) ($row['Whse_Document_No'] ?? '')));
                if (isset($headers[$doc])) {
                    $entries[] = $row;
                }
            });
        }
    }
    $lines = consus_retour_apply_receipt_info($lines, consus_retour_receipt_info($receiptRows, $entries, $headers));

    return [$lines, $partial ? 'Perkins-factuurnummer/Handling Unit deels opgehaald (tijdslimiet van ' . (int) round($budget / 60) . ' minuten).' : ''];
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
 * Wordt na de snapshot vanuit nightly.php aangeroepen. Per bedrijf met regels
 * een eigen fetch (eigen environment via de company-map). Een fout bij één
 * bedrijf laat de vorige data van dat bedrijf staan, raakt de andere bedrijven
 * niet en raakt de snapshot niet.
 *
 * @param callable|null $build fn(string $company, array $settings): array
 * @param array<string, string>|null $map BC Name => environment (tests)
 * @return array{skipped:bool,lines:int,companies:array<string, array{ok:bool,lines:int,error:string}>}
 */
function consus_retour_refresh(?callable $build = null, ?array $map = null): array
{
    consus_retour_settings_migrate();
    $settings = consus_retour_settings_read();
    $wanted = consus_retour_companies_with_rules($settings);
    if ($wanted === []) {
        // Geen regels bij welk bedrijf dan ook: niets ophalen.
        return ['skipped' => true, 'lines' => 0, 'companies' => []];
    }
    $GLOBALS['consus_odata_max_age'] = CONSUS_NIGHTLY_MAX_AGE;
    $build ??= static fn (string $company, array $settings): array => consus_retour_build($company, $settings);
    if ($map === null) {
        $discovered = auth_discover_companies_across_active_environments();
        $map = is_array($discovered['map'] ?? null) ? $discovered['map'] : [];
    }
    $status = [];
    $results = [];
    $total = 0;
    foreach ($wanted as $company) {
        $label = consus_retour_company_label($company);
        // BC Name uit discovery (hoofdletterongevoelig), zodat het OData-pad klopt.
        $name = consus_retour_company_slot($map, $company);
        if ($name === null) {
            $status[$company] = ['ok' => false, 'lines' => 0, 'error' => $label . ' niet gevonden bij discovery voor de Retourlijst.'];
            continue;
        }
        try {
            $data = $build($name, $settings);
            $data['company'] = $name;
            $results[$company] = $data;
            $count = count($data['lines'] ?? []);
            $total += $count;
            $status[$company] = ['ok' => true, 'lines' => $count, 'error' => ''];
        } catch (Throwable $error) {
            $status[$company] = ['ok' => false, 'lines' => 0, 'error' => $label . ': ' . $error->getMessage()];
        }
    }
    consus_retour_write_data($results, $wanted);

    return ['skipped' => false, 'lines' => $total, 'companies' => $status];
}
