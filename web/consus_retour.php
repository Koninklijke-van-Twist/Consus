<?php

/**
 * Perkins-retourkandidaten (Asclepius #1159).
 *
 * Perkins (leverancier 90101, Hunter van Twist) neemt ongebruikte onderdelen
 * terug binnen een termijn na de factuurdatum: 30 dagen voor spoed (Perkins-
 * account 57401) en 90 dagen voor voorraad (account 57420).
 *
 * Bron (nachtelijk, via Mímir met BC-fallback, zelfde pad als de snapshot):
 *  - GeboekteInkoopfacturen (pagina 146): No, Vendor_Invoice_No, Document_Date.
 *  - GeboekteInkoopfactuurRegels (pagina 529): Document_No, Type, No, Quantity,
 *    Direct_Unit_Cost, Order_No, Shortcut_Dimension_1_Code. Tekstregels vallen weg.
 *  - AppPurchaseOrder: KVT_Export_Status_Perkins_EGT / _CSV (spoed of voorraad).
 *  - AppItemCard: omschrijving, veiligheidsvoorraad, Tariff_No, land van herkomst.
 *  - ItemLedgerEntries (Open): boekvoorraad per locatie.
 *  - ReservationEntries: reserveringen op voorraad (en of die naar een retourorder gaan).
 *  - BinContent (fallback Magazijnposten): bin-inhoud op HVT tegen boekvoorraad.
 *
 * Die ruwe feiten staan in web/data/consus_retour.json. De pagina rekent de
 * kandidaten bij elke weergave uit met de instellingen per afdeling, zodat een
 * gewijzigde instelling direct zichtbaar is zonder nieuwe BC-run.
 */

require_once __DIR__ . '/consus_prefs.php';

const CONSUS_RETOUR_VENDOR = '90101';
const CONSUS_RETOUR_COMPANY_KEY = 'HVT';
const CONSUS_RETOUR_ACCOUNT_SPOED = '57401';
const CONSUS_RETOUR_ACCOUNT_VOORRAAD = '57420';
const CONSUS_RETOUR_DEFAULT_KEY = '__default';
/** Locaties met bins. Andere locaties (M5xx/M6xx) krijgen 'geen bincontrole'. */
const CONSUS_RETOUR_BIN_LOCATIONS = ['HVT'];
const CONSUS_RETOUR_CHUNK = 25;
const CONSUS_RETOUR_DEFAULTS = [
    'min_value' => 50.0,
    'window_spoed' => 30,
    'window_voorraad' => 90,
    'start_date' => '2026-07-17',
];
const CONSUS_RETOUR_DEFAULT_GARANTIE = ['PO52600987'];

function consus_retour_data_file(): string
{
    $override = getenv('CONSUS_RETOUR_FILE');
    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }

    return __DIR__ . '/data/consus_retour.json';
}

function consus_retour_settings_file(): string
{
    $override = getenv('CONSUS_RETOUR_SETTINGS_FILE');
    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }

    return __DIR__ . '/data/retour_settings.json';
}

/** BC/Mímir kan Engelse of Nederlandse waarden sturen (true, "Ja", "Yes", 1). */
function consus_retour_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (float) $value !== 0.0;
    }
    $text = strtolower(trim((string) $value));

    return in_array($text, ['true', '1', 'ja', 'yes', 'j', 'y', 'waar'], true);
}

/**
 * Regel van Ivan (08-10-2026): EGT = true én CSV = false is spoed (57401).
 * Elke andere combinatie, ook onbekende vlaggen, is voorraad (57420).
 */
function consus_retour_classify(?bool $egt, ?bool $csv): string
{
    return ($egt === true && $csv === false) ? CONSUS_RETOUR_ACCOUNT_SPOED : CONSUS_RETOUR_ACCOUNT_VOORRAAD;
}

function consus_retour_account_label(string $account): string
{
    return $account === CONSUS_RETOUR_ACCOUNT_SPOED ? 'Spoed (57401)' : 'Voorraad (57420)';
}

/** Regeltype: "Item" of Nederlands "Artikel". Lege tekstregels tellen niet. */
function consus_retour_is_item_line(array $line): bool
{
    $type = strtolower(trim((string) ($line['Type'] ?? '')));
    $no = trim((string) ($line['No'] ?? ''));

    return $no !== '' && in_array($type, ['item', 'artikel'], true);
}

function consus_retour_normalize_po(string $value): string
{
    return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
}

function consus_retour_parse_date(mixed $value): string
{
    $text = trim((string) $value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && $m[1] !== '0001') {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $text, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }

    return '';
}

function consus_retour_dutch_date(string $iso): string
{
    $date = consus_retour_parse_date($iso);
    if ($date === '') {
        return '';
    }
    $months = [1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    [$y, $m, $d] = array_map('intval', explode('-', $date));

    return $d . ' ' . $months[$m] . ' ' . $y;
}

function consus_retour_days_between(string $from, string $to): int
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $a = new DateTimeImmutable($from . ' 00:00:00', $tz);
    $b = new DateTimeImmutable($to . ' 00:00:00', $tz);

    return (int) $a->diff($b)->format('%r%a');
}

function consus_retour_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
}

/** Afdelingssleutel: '05' en '5' zijn gelijk, leeg is de standaard. */
function consus_retour_department_key(string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === CONSUS_RETOUR_DEFAULT_KEY) {
        return CONSUS_RETOUR_DEFAULT_KEY;
    }
    if ($value === '__none__') {
        return '__none__';
    }
    if (ctype_digit($value)) {
        return (string) (int) $value;
    }

    return strtoupper($value);
}

/** @return array{min_value:float,window_spoed:int,window_voorraad:int,start_date:string} */
function consus_retour_normalize_rule(mixed $input, ?array $fallback = null): array
{
    $fallback ??= CONSUS_RETOUR_DEFAULTS;
    $input = is_array($input) ? $input : [];
    $min = $input['min_value'] ?? $fallback['min_value'];
    $min = is_numeric(str_replace(',', '.', (string) $min)) ? (float) str_replace(',', '.', (string) $min) : (float) $fallback['min_value'];
    $spoed = $input['window_spoed'] ?? $fallback['window_spoed'];
    $voorraad = $input['window_voorraad'] ?? $fallback['window_voorraad'];
    $start = consus_retour_parse_date($input['start_date'] ?? '');

    return [
        'min_value' => max(0.0, min(1000000.0, $min)),
        'window_spoed' => is_numeric($spoed) ? max(0, min(3650, (int) $spoed)) : (int) $fallback['window_spoed'],
        'window_voorraad' => is_numeric($voorraad) ? max(0, min(3650, (int) $voorraad)) : (int) $fallback['window_voorraad'],
        'start_date' => $start !== '' ? $start : (string) $fallback['start_date'],
    ];
}

/** @return array<int, string> */
function consus_retour_normalize_po_list(mixed $value): array
{
    if (is_string($value)) {
        $value = preg_split('/[\s,;]+/', $value) ?: [];
    }
    $list = [];
    foreach (is_array($value) ? $value : [] as $entry) {
        $po = consus_retour_normalize_po((string) $entry);
        if ($po !== '' && preg_match('/^[A-Z0-9\-_\/]{3,30}$/', $po)) {
            $list[$po] = $po;
        }
    }

    return array_values(array_slice($list, 0, 500));
}

/** @return array{departments:array<string, array>,garantie_orders:array<int,string>} */
function consus_retour_normalize_settings(mixed $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $departments = [];
    $default = consus_retour_normalize_rule($raw['departments'][CONSUS_RETOUR_DEFAULT_KEY] ?? []);
    $departments[CONSUS_RETOUR_DEFAULT_KEY] = $default;
    foreach (is_array($raw['departments'] ?? null) ? $raw['departments'] : [] as $key => $rule) {
        $normalized = consus_retour_department_key((string) $key);
        if ($normalized === CONSUS_RETOUR_DEFAULT_KEY) {
            continue;
        }
        $departments[$normalized] = consus_retour_normalize_rule($rule, $default);
    }

    return [
        'departments' => $departments,
        'garantie_orders' => array_key_exists('garantie_orders', $raw)
            ? consus_retour_normalize_po_list($raw['garantie_orders'])
            : CONSUS_RETOUR_DEFAULT_GARANTIE,
    ];
}

function consus_retour_settings_read(): array
{
    $path = consus_retour_settings_file();
    $raw = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

    return consus_retour_normalize_settings($raw);
}

/**
 * Slaat de regel voor één afdeling op ('' of __default = standaard voor alle
 * afdelingen) plus de garantielijst. 'reset' haalt de afdeling terug naar de standaard.
 */
function consus_retour_settings_write(string $department, array $rule, mixed $garantie, bool $reset = false): array
{
    $key = consus_retour_department_key($department);
    $saved = consus_update_json_locked(consus_retour_settings_file(), static function (array $current) use ($key, $rule, $garantie, $reset): array {
        $settings = consus_retour_normalize_settings($current);
        if ($reset && $key !== CONSUS_RETOUR_DEFAULT_KEY) {
            unset($settings['departments'][$key]);
        } else {
            $fallback = $settings['departments'][CONSUS_RETOUR_DEFAULT_KEY];
            $settings['departments'][$key] = consus_retour_normalize_rule($rule, $key === CONSUS_RETOUR_DEFAULT_KEY ? CONSUS_RETOUR_DEFAULTS : $fallback);
        }
        if ($garantie !== null) {
            $settings['garantie_orders'] = consus_retour_normalize_po_list($garantie);
        }

        return $settings;
    });

    return consus_retour_normalize_settings($saved);
}

function consus_retour_rule_for_department(array $settings, string $department): array
{
    $key = consus_retour_department_key($department);
    $departments = is_array($settings['departments'] ?? null) ? $settings['departments'] : [];

    return $departments[$key] ?? $departments[CONSUS_RETOUR_DEFAULT_KEY] ?? CONSUS_RETOUR_DEFAULTS;
}

function consus_retour_is_garantie(string $po, array $settings): bool
{
    $po = consus_retour_normalize_po($po);

    return $po !== '' && in_array($po, consus_retour_normalize_po_list($settings['garantie_orders'] ?? []), true);
}

function consus_retour_read_data(): array
{
    $path = consus_retour_data_file();
    $raw = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    if (!is_array($raw)) {
        return ['generated_at' => '', 'lines' => [], 'orders' => [], 'items' => [], 'warnings' => []];
    }

    return $raw + ['generated_at' => '', 'lines' => [], 'orders' => [], 'items' => [], 'warnings' => []];
}

/**
 * Rekent de retourkandidaten uit.
 *
 * $data:
 *  lines:  [{invoice, vendor_invoice, document_date, item, description, quantity, unit_cost, order_no, department}]
 *  orders: {PO: {egt:bool|null, csv:bool|null, found:bool}}
 *  items:  {ITEM: {description, safety_stock, stock:{LOC:qty}, bin:{LOC:qty}|null,
 *                  reserved:qty, reserved_return:qty, tariff, origin}}
 *
 * Stappen:
 *  1. Regels per (factuur, artikel) optellen (Ivan: €50 per artikel per factuur).
 *  2. Account per PO (classify), garantie-PO's markeren.
 *  3. Alleen factuurdatum ≥ startdatum en binnen de termijn van het account.
 *  4. Artikel: geen veiligheidsvoorraad. Vrije voorraad = boekvoorraad − reserveringen.
 *     Vrije voorraad gaat eerst naar de nieuwste factuur (wat er nog ligt komt het
 *     waarschijnlijkst uit de laatste levering), daarna naar oudere.
 *  5. Retourwaarde = retouraantal × gemiddelde inkoopprijs ≥ minimumwaarde.
 *  6. Bincontrole HVT: bin-inhoud < boekvoorraad → 'controleren'.
 *
 * @return array{rows:array<int, array>, garantie:array<int, array>, skipped:array<string,int>}
 */
function consus_retour_candidates(array $data, array $settings, string $today, string $departmentFilter = ''): array
{
    $groups = [];
    foreach ($data['lines'] ?? [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        $item = trim((string) ($line['item'] ?? ''));
        $invoice = trim((string) ($line['invoice'] ?? ''));
        $quantity = (float) ($line['quantity'] ?? 0);
        if ($item === '' || $invoice === '' || $quantity == 0.0) {
            continue;
        }
        $key = $invoice . "\x1f" . strtoupper($item);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'invoice' => $invoice,
                'vendor_invoice' => trim((string) ($line['vendor_invoice'] ?? '')),
                'document_date' => consus_retour_parse_date($line['document_date'] ?? ''),
                'item' => $item,
                'description' => trim((string) ($line['description'] ?? '')),
                'department' => trim((string) ($line['department'] ?? '')),
                'quantity' => 0.0,
                'amount' => 0.0,
                'orders' => [],
            ];
        }
        $groups[$key]['quantity'] += $quantity;
        $groups[$key]['amount'] += $quantity * (float) ($line['unit_cost'] ?? 0);
        $po = consus_retour_normalize_po((string) ($line['order_no'] ?? ''));
        if ($po !== '') {
            $groups[$key]['orders'][$po] = true;
        }
    }

    $skipped = ['voor_startdatum' => 0, 'verlopen' => 0, 'veiligheidsvoorraad' => 0, 'geen_vrije_voorraad' => 0, 'onder_minimum' => 0, 'afdeling' => 0];
    $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];
    $departmentFilterKey = $departmentFilter === '' ? '' : consus_retour_department_key($departmentFilter);

    $open = [];
    foreach ($groups as $group) {
        $groupDepartment = consus_retour_department_key($group['department'] === '' ? '__none__' : $group['department']);
        if ($departmentFilterKey !== '' && $groupDepartment !== $departmentFilterKey) {
            $skipped['afdeling']++;
            continue;
        }
        $rule = consus_retour_rule_for_department($settings, $group['department']);
        $poList = array_keys($group['orders']);
        $account = CONSUS_RETOUR_ACCOUNT_VOORRAAD;
        $flagsKnown = false;
        foreach ($poList as $po) {
            $order = is_array($orders[$po] ?? null) ? $orders[$po] : null;
            $found = $order !== null && !empty($order['found']);
            $flagsKnown = $flagsKnown || $found;
            $egt = $found && array_key_exists('egt', $order) && $order['egt'] !== null ? (bool) $order['egt'] : null;
            $csv = $found && array_key_exists('csv', $order) && $order['csv'] !== null ? (bool) $order['csv'] : null;
            if (consus_retour_classify($egt, $csv) === CONSUS_RETOUR_ACCOUNT_SPOED) {
                $account = CONSUS_RETOUR_ACCOUNT_SPOED;
            }
        }
        $garantie = false;
        foreach ($poList as $po) {
            $garantie = $garantie || consus_retour_is_garantie($po, $settings);
        }
        $date = $group['document_date'];
        if ($date === '' || $date < $rule['start_date']) {
            $skipped['voor_startdatum']++;
            continue;
        }
        $window = $account === CONSUS_RETOUR_ACCOUNT_SPOED ? $rule['window_spoed'] : $rule['window_voorraad'];
        $daysSince = consus_retour_days_between($date, $today);
        $daysLeft = $window - $daysSince;
        if ($daysLeft < 0) {
            $skipped['verlopen']++;
            continue;
        }
        $group += [
            'po' => implode(', ', $poList),
            'account' => $account,
            'flags_known' => $flagsKnown,
            'garantie' => $garantie,
            'window' => $window,
            'days_since' => $daysSince,
            'days_left' => $daysLeft,
            'deadline' => (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('Europe/Amsterdam')))->modify('+' . $window . ' days')->format('Y-m-d'),
            'unit_cost' => $group['quantity'] != 0.0 ? $group['amount'] / $group['quantity'] : 0.0,
            'rule' => $rule,
        ];
        $open[] = $group;
    }

    // Nieuwste factuur eerst krijgt de vrije voorraad.
    usort($open, static fn (array $a, array $b): int => [$b['document_date'], $b['invoice']] <=> [$a['document_date'], $a['invoice']]);
    $free = [];
    $rows = [];
    $garantieRows = [];
    foreach ($open as $group) {
        $itemKey = strtoupper($group['item']);
        $info = is_array($items[$itemKey] ?? null) ? $items[$itemKey] : (is_array($items[$group['item']] ?? null) ? $items[$group['item']] : []);
        $stockByLocation = is_array($info['stock'] ?? null) ? $info['stock'] : [];
        $stock = array_sum(array_map('floatval', $stockByLocation));
        $reserved = max(0.0, (float) ($info['reserved'] ?? 0));
        $reservedReturn = max(0.0, (float) ($info['reserved_return'] ?? 0));
        $safety = (float) ($info['safety_stock'] ?? 0);
        if (!array_key_exists($itemKey, $free)) {
            $free[$itemKey] = max(0.0, $stock - $reserved);
        }
        $row = $group + [
            'description' => $group['description'],
            'stock' => $stock,
            'reserved' => $reserved,
            'reserved_return' => $reservedReturn,
            'safety_stock' => $safety,
            'tariff' => (string) ($info['tariff'] ?? ''),
            'origin' => (string) ($info['origin'] ?? ''),
        ];
        if (($info['description'] ?? '') !== '' && $row['description'] === '') {
            $row['description'] = (string) $info['description'];
        }
        if ($group['garantie']) {
            $row['return_qty'] = 0.0;
            $row['value'] = round($group['amount'], 2);
            $row['status'] = 'garantie';
            $row['status_label'] = 'Garantieorder — handmatig bij Perkins, geen retourkandidaat';
            $garantieRows[] = $row;
            continue;
        }
        if ($safety > 0) {
            $skipped['veiligheidsvoorraad']++;
            continue;
        }
        $returnQty = min($group['quantity'], $free[$itemKey]);
        if ($returnQty <= 0) {
            $skipped['geen_vrije_voorraad']++;
            continue;
        }
        $value = round($returnQty * $row['unit_cost'], 2);
        if ($value < $group['rule']['min_value']) {
            $skipped['onder_minimum']++;
            continue;
        }
        $free[$itemKey] -= $returnQty;
        $row['return_qty'] = $returnQty;
        $row['value'] = $value;
        [$row['status'], $row['status_label']] = consus_retour_bin_status($info, $stockByLocation);
        if (!$row['flags_known']) {
            $row['status_label'] .= ' · order niet meer in BC, als voorraad (57420) gerekend';
        }
        $rows[] = $row;
    }

    $sorter = static fn (array $a, array $b): int => [$a['days_left'], $a['item']] <=> [$b['days_left'], $b['item']];
    usort($rows, $sorter);
    usort($garantieRows, $sorter);

    return ['rows' => $rows, 'garantie' => $garantieRows, 'skipped' => $skipped];
}

/**
 * Bincontrole (Ariadne, #1165): op locaties met bins (HVT) moet de bin-inhoud
 * de boekvoorraad dekken. Minder bin-inhoud = mogelijk spookvoorraad.
 * Locaties zonder bins (M5xx/M6xx) krijgen 'geen bincontrole'.
 * Ontbreekt de bin-data helemaal (webservice faalde), dan geen oordeel.
 *
 * @return array{0:string,1:string}
 */
function consus_retour_bin_status(array $info, array $stockByLocation): array
{
    $bins = $info['bin'] ?? null;
    $other = [];
    foreach ($stockByLocation as $location => $qty) {
        if ((float) $qty != 0.0 && !in_array(strtoupper((string) $location), CONSUS_RETOUR_BIN_LOCATIONS, true)) {
            $other[] = (string) $location;
        }
    }
    $suffix = $other !== [] ? ' · geen bincontrole op ' . implode(', ', $other) : '';
    if (!is_array($bins)) {
        return ['kandidaat', 'Retourkandidaat (bincontrole niet beschikbaar)' . $suffix];
    }
    foreach (CONSUS_RETOUR_BIN_LOCATIONS as $location) {
        $book = (float) ($stockByLocation[$location] ?? 0);
        $bin = (float) ($bins[$location] ?? 0);
        if ($book > 0 && $bin + 0.00001 < $book) {
            $label = $bin <= 0
                ? 'Controleren — geen bin-inhoud op ' . $location . ' (boekvoorraad ' . consus_retour_qty($book) . ')'
                : 'Controleren — bin-inhoud ' . consus_retour_qty($bin) . ' lager dan boekvoorraad ' . consus_retour_qty($book) . ' op ' . $location;

            return ['controleren', $label . $suffix];
        }
    }
    if ($other !== [] && array_sum(array_map(static fn ($l) => (float) ($stockByLocation[$l] ?? 0), CONSUS_RETOUR_BIN_LOCATIONS)) <= 0) {
        return ['geen_bincontrole', 'Geen bincontrole — voorraad alleen op ' . implode(', ', $other)];
    }

    return ['kandidaat', 'Retourkandidaat' . $suffix];
}

function consus_retour_qty(float $value): string
{
    return abs($value - round($value)) < 0.00001 ? (string) (int) round($value) : rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
}

function consus_retour_money(float $value): string
{
    return '€ ' . number_format($value, 2, ',', '.');
}

/** Kolommen zoals Ivans voorbeeld (30 Day Return), aangevuld met de Consus-velden. */
function consus_retour_export_headers(): array
{
    return [
        'Account', 'Spoed/voorraad', 'Perkins-factuur', 'BC-factuur', 'Factuurdatum', 'Artikel', 'Omschrijving',
        'PO-nummer', 'Aantal retour', 'Prijs per stuk', 'Totale prijs', 'Op voorraad', 'Gereserveerd',
        'Dagen sinds factuur', 'Dagen over', 'Uiterlijk retour', 'Tariff Code', 'Country of Origin',
        'Garantie', 'Status', 'Afdeling',
    ];
}

function consus_retour_export_row(array $row): array
{
    $garantie = !empty($row['garantie']);

    return [
        (string) $row['account'],
        $row['account'] === CONSUS_RETOUR_ACCOUNT_SPOED ? 'Spoed' : 'Voorraad',
        (string) $row['vendor_invoice'],
        (string) $row['invoice'],
        consus_retour_dutch_date((string) $row['document_date']),
        (string) $row['item'],
        (string) $row['description'],
        (string) $row['po'],
        $garantie ? (float) $row['quantity'] : (float) $row['return_qty'],
        round((float) $row['unit_cost'], 2),
        (float) $row['value'],
        (float) $row['stock'],
        (float) $row['reserved'],
        (float) $row['days_since'],
        (float) $row['days_left'],
        consus_retour_dutch_date((string) $row['deadline']),
        (string) $row['tariff'],
        (string) $row['origin'],
        $garantie ? 'Ja' : 'Nee',
        (string) $row['status_label'],
        (string) $row['department'],
    ];
}

/** @return array{name:string,rows:array} */
function consus_retour_export_sheet(array $result): array
{
    $rows = [consus_retour_export_headers()];
    foreach (array_merge($result['rows'], $result['garantie']) as $row) {
        $rows[] = consus_retour_export_row($row);
    }

    return ['name' => 'Retourkandidaten', 'rows' => $rows];
}
