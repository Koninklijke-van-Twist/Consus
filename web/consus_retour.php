<?php

/**
 * Retourlijst (Asclepius #1159): onderdelen die nog terug kunnen naar de leverancier.
 *
 * Afdelingsonafhankelijk. Per afdeling (Global Dimension 1, zelfde code als het
 * afdelingsfilter van Consus) staan regels, gedeeld voor alle gebruikers:
 * leverancier, type (optioneel, standaard "Alle"), termijn in dagen en minimaal
 * bedrag. Een afdeling kan meerdere regels hebben; zonder regels toont de
 * lijst niets.
 *
 * Bron (nachtelijk, via Mímir met BC-fallback, zelfde pad als de snapshot), voor
 * de leveranciers uit alle regels:
 *  - GeboekteInkoopfacturen (pagina 146): No, Buy_from_Vendor_No, Vendor_Invoice_No, Document_Date.
 *  - GeboekteInkoopfactuurRegels (pagina 529): Document_No, Type, No, Quantity,
 *    Direct_Unit_Cost, Order_No, Shortcut_Dimension_1_Code. Tekstregels vallen weg.
 *  - AppPurchaseOrder: KVT_Export_Status_Perkins_EGT / _CSV (type 57401/57420, alleen Perkins).
 *  - AppItemCard: omschrijving, veiligheidsvoorraad, Tariff_No, land van herkomst.
 *  - ItemLedgerEntries (Open): boekvoorraad per locatie.
 *  - ReservationEntries: reserveringen op voorraad (en of die naar een retourorder gaan).
 *  - BinContent (fallback Magazijnposten): bin-inhoud op HVT tegen boekvoorraad.
 *
 * Die ruwe feiten staan in web/data/consus_retour.json (ruim venster). De pagina
 * rekent de kandidaten bij elke weergave uit met de regels, zodat een gewijzigde
 * termijn of minimum direct zichtbaar is zonder nieuwe BC-run.
 */

require_once __DIR__ . '/consus_prefs.php';

/** Sleutel uit CONSUS_COMPANIES (kleine letters), dezelfde als de rest van de nightly. */
const CONSUS_RETOUR_COMPANY_KEY = 'hvt';
const CONSUS_RETOUR_ACCOUNT_SPOED = '57401';
const CONSUS_RETOUR_ACCOUNT_VOORRAAD = '57420';
/** Leverancier waarvoor de type-provider 57401/57420 uit de PO-vlaggen afleidt. */
const CONSUS_RETOUR_PERKINS_VENDOR = '90101';
/** Leeg type = "Alle": de regel geldt voor alle regels van die leverancier. */
const CONSUS_RETOUR_TYPE_ALL = '';
/** Locaties met bins. Andere locaties (M5xx/M6xx) krijgen 'geen bincontrole'. */
const CONSUS_RETOUR_BIN_LOCATIONS = ['HVT'];
const CONSUS_RETOUR_CHUNK = 25;
/** Nightly haalt minstens zoveel dagen op, zodat een langere termijn direct werkt. */
const CONSUS_RETOUR_FETCH_DAYS = 180;
const CONSUS_RETOUR_MAX_WINDOW = 365;
const CONSUS_RETOUR_MAX_RULES = 50;
const CONSUS_RETOUR_DEFAULT_KEY = '__default';
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

/** Een geldige afdeling voor regels: geen leeg/standaard/geen-afdeling. */
function consus_retour_valid_department(string $value): string
{
    $key = consus_retour_department_key($value);
    if (in_array($key, ['__default', '__none__'], true) || !preg_match('/^[A-Z0-9\-_]{1,20}$/', $key)) {
        return '';
    }

    return $key;
}

function consus_retour_parse_amount(mixed $value): ?float
{
    $text = str_replace([' ', '€'], '', trim((string) $value));
    if (str_contains($text, ',')) {
        $text = str_replace(['.', ','], ['', '.'], $text);
    }

    return is_numeric($text) ? (float) $text : null;
}

function consus_retour_normalize_vendor(mixed $value): string
{
    $vendor = strtoupper(trim((string) $value));

    return preg_match('/^[A-Z0-9\-_.]{1,20}$/', $vendor) ? $vendor : '';
}

function consus_retour_normalize_type(mixed $value): string
{
    $type = strtoupper(trim((string) $value));
    if ($type === '' || in_array(strtolower($type), ['alle', 'all', '*'], true)) {
        return CONSUS_RETOUR_TYPE_ALL;
    }

    return preg_match('/^[A-Z0-9\-_.]{1,20}$/', $type) ? $type : '';
}

/**
 * Valideert één regel. Ongeldig = null (bij opslaan wordt dat een foutmelding).
 *
 * @return array{id:string,vendor:string,type:string,window:int,min_value:float}|null
 */
function consus_retour_normalize_rule(mixed $input): ?array
{
    if (!is_array($input)) {
        return null;
    }
    $vendor = consus_retour_normalize_vendor($input['vendor'] ?? '');
    $window = $input['window'] ?? null;
    $min = consus_retour_parse_amount($input['min_value'] ?? '');
    $rawType = trim((string) ($input['type'] ?? ''));
    $type = consus_retour_normalize_type($rawType);
    if ($type === CONSUS_RETOUR_TYPE_ALL && $rawType !== '' && !in_array(strtolower($rawType), ['alle', 'all', '*'], true)) {
        // Ongeldige typecode is geen "alles": anders wordt een tikfout een brede regel.
        return null;
    }
    if ($vendor === '' || !is_numeric($window) || (int) $window < 1 || (int) $window > CONSUS_RETOUR_MAX_WINDOW || $min === null || $min < 0 || $min > 1000000) {
        return null;
    }
    $id = (string) ($input['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{8,32}$/', $id)) {
        $id = bin2hex(random_bytes(6));
    }

    return [
        'id' => $id,
        'vendor' => $vendor,
        'type' => $type,
        'window' => (int) $window,
        'min_value' => round($min, 2),
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

/** Sleutel van een kandidaat (factuur + artikel) voor de garantiemarkering. */
function consus_retour_line_key(string $invoice, string $item): string
{
    return strtoupper(trim($invoice)) . '|' . strtoupper(trim($item));
}

/** @return array<int, string> */
function consus_retour_normalize_line_keys(mixed $value): array
{
    $list = [];
    foreach (is_array($value) ? $value : [] as $entry) {
        $key = strtoupper(trim((string) $entry));
        if (preg_match('/^[A-Z0-9\-_\/.]{1,30}\|[A-Z0-9\-_\/. ]{1,40}$/', $key)) {
            $list[$key] = $key;
        }
    }

    return array_values(array_slice($list, 0, 2000));
}

/**
 * Instellingen (gedeeld voor alle gebruikers):
 *  rules:            {afdeling: [regel, ...]}
 *  garantie_orders:  PO's die garantie zijn (standaard PO52600987)
 *  garantie_lines:   "factuur|artikel" met de hand als garantie gemarkeerd
 *
 * De oude vorm ('departments' met termijnen en startdatum per afdeling) wordt
 * genegeerd: bij livegang heeft elke afdeling lege regels.
 *
 * @return array{version:int,rules:array<string, array<int, array>>,garantie_orders:array<int,string>,garantie_lines:array<int,string>}
 */
function consus_retour_normalize_settings(mixed $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $rules = [];
    foreach (is_array($raw['rules'] ?? null) ? $raw['rules'] : [] as $department => $list) {
        $key = consus_retour_valid_department((string) $department);
        if ($key === '' || !is_array($list)) {
            continue;
        }
        foreach ($list as $rule) {
            $normalized = consus_retour_normalize_rule($rule);
            if ($normalized !== null && count($rules[$key] ?? []) < CONSUS_RETOUR_MAX_RULES) {
                $rules[$key][] = $normalized;
            }
        }
    }
    ksort($rules, SORT_NATURAL);

    return [
        'version' => 2,
        'rules' => $rules,
        'garantie_orders' => array_key_exists('garantie_orders', $raw)
            ? consus_retour_normalize_po_list($raw['garantie_orders'])
            : CONSUS_RETOUR_DEFAULT_GARANTIE,
        'garantie_lines' => consus_retour_normalize_line_keys($raw['garantie_lines'] ?? []),
    ];
}

function consus_retour_settings_read(): array
{
    $path = consus_retour_settings_file();
    $raw = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

    return consus_retour_normalize_settings($raw);
}

/** Wijzigt de instellingen onder flock en schrijft atomair. */
function consus_retour_settings_update(callable $change): array
{
    $saved = consus_update_json_locked(consus_retour_settings_file(), static function (array $current) use ($change): array {
        return $change(consus_retour_normalize_settings($current));
    });

    return consus_retour_normalize_settings($saved);
}

/**
 * Voegt een regel toe of wijzigt hem (zelfde id). Gooit InvalidArgumentException
 * bij een ongeldige afdeling of regel.
 */
function consus_retour_rule_save(string $department, array $input, string $id = ''): array
{
    $key = consus_retour_valid_department($department);
    if ($key === '') {
        throw new InvalidArgumentException('Kies eerst een afdeling.');
    }
    $rule = consus_retour_normalize_rule(['id' => $id !== '' ? $id : null] + $input);
    if ($rule === null) {
        throw new InvalidArgumentException('Vul leverancier, termijn (1–' . CONSUS_RETOUR_MAX_WINDOW . ' dagen) en minimaal bedrag correct in.');
    }

    return consus_retour_settings_update(static function (array $settings) use ($key, $rule, $id): array {
        $list = $settings['rules'][$key] ?? [];
        $replaced = false;
        foreach ($list as $index => $existing) {
            if ($id !== '' && $existing['id'] === $id) {
                $list[$index] = $rule;
                $replaced = true;
            }
        }
        if (!$replaced) {
            if (count($list) >= CONSUS_RETOUR_MAX_RULES) {
                throw new InvalidArgumentException('Maximaal ' . CONSUS_RETOUR_MAX_RULES . ' regels per afdeling.');
            }
            $list[] = $rule;
        }
        $settings['rules'][$key] = array_values($list);

        return $settings;
    });
}

function consus_retour_rule_delete(string $department, string $id): array
{
    $key = consus_retour_valid_department($department);

    return consus_retour_settings_update(static function (array $settings) use ($key, $id): array {
        $list = array_values(array_filter($settings['rules'][$key] ?? [], static fn (array $rule): bool => $rule['id'] !== $id));
        if ($list === []) {
            unset($settings['rules'][$key]);
        } else {
            $settings['rules'][$key] = $list;
        }

        return $settings;
    });
}

/**
 * Garantie aan/uit voor één kandidaat (globaal). Uitzetten haalt ook de
 * PO's van die regel van de garantielijst, anders blijft hij garantie.
 *
 * @param array<int, string> $orders
 */
function consus_retour_garantie_set(string $invoice, string $item, array $orders, bool $on): array
{
    $lineKey = consus_retour_line_key($invoice, $item);
    if (consus_retour_normalize_line_keys([$lineKey]) === []) {
        throw new InvalidArgumentException('Onbekende regel.');
    }
    $orders = consus_retour_normalize_po_list($orders);

    return consus_retour_settings_update(static function (array $settings) use ($lineKey, $orders, $on): array {
        $lines = array_values(array_filter($settings['garantie_lines'], static fn (string $key): bool => $key !== $lineKey));
        if ($on) {
            $lines[] = $lineKey;
        } else {
            $settings['garantie_orders'] = array_values(array_diff($settings['garantie_orders'], $orders));
        }
        $settings['garantie_lines'] = $lines;

        return $settings;
    });
}

/** @return array<int, array> */
function consus_retour_rules_for_department(array $settings, string $department): array
{
    $key = consus_retour_valid_department($department);

    return $key === '' ? [] : ($settings['rules'][$key] ?? []);
}

/** @return array<int, string> Leveranciers uit alle regels van alle afdelingen. */
function consus_retour_rule_vendors(array $settings): array
{
    $vendors = [];
    foreach ($settings['rules'] ?? [] as $list) {
        foreach ($list as $rule) {
            $vendors[$rule['vendor']] = $rule['vendor'];
        }
    }
    ksort($vendors, SORT_NATURAL);

    return array_values($vendors);
}

function consus_retour_max_window(array $settings): int
{
    $max = 0;
    foreach ($settings['rules'] ?? [] as $list) {
        foreach ($list as $rule) {
            $max = max($max, (int) $rule['window']);
        }
    }

    return $max;
}

function consus_retour_is_garantie(string $po, array $settings): bool
{
    $po = consus_retour_normalize_po($po);

    return $po !== '' && in_array($po, consus_retour_normalize_po_list($settings['garantie_orders'] ?? []), true);
}

/**
 * Type-provider: welke types kent een leverancier? Bewust één functie, zodat de
 * bron later een BC-codetabel kan worden. Nu: Perkins (90101) heeft 57401/57420
 * uit de PO-vlaggen KVT_Export_Status_Perkins_EGT/_CSV; andere leveranciers
 * alleen "Alle" (lege lijst).
 *
 * @return array<int, string>
 */
function consus_retour_vendor_types(string $vendor): array
{
    return consus_retour_normalize_vendor($vendor) === CONSUS_RETOUR_PERKINS_VENDOR
        ? [CONSUS_RETOUR_ACCOUNT_SPOED, CONSUS_RETOUR_ACCOUNT_VOORRAAD]
        : [];
}

function consus_retour_type_label(string $type): string
{
    return match ($type) {
        CONSUS_RETOUR_TYPE_ALL => 'Alle',
        CONSUS_RETOUR_ACCOUNT_SPOED => 'Spoed (57401)',
        CONSUS_RETOUR_ACCOUNT_VOORRAAD => 'Voorraad (57420)',
        default => $type,
    };
}

/** Oud label, nog gebruikt door de tabel. */
function consus_retour_account_label(string $account): string
{
    return consus_retour_type_label($account);
}

function consus_retour_read_data(): array
{
    $empty = ['generated_at' => '', 'lines' => [], 'orders' => [], 'items' => [], 'warnings' => [], 'vendors' => [], 'types_by_vendor' => [], 'fetched_from' => ''];
    $path = consus_retour_data_file();
    $raw = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    if (!is_array($raw)) {
        return $empty;
    }
    $data = $raw + $empty;
    // Data van vóór de regels (alleen Perkins opgehaald, zonder leverancier per regel).
    if (!array_key_exists('vendors', $raw) && trim((string) $data['generated_at']) !== '') {
        $data['vendors'] = [CONSUS_RETOUR_PERKINS_VENDOR];
        $data['types_by_vendor'] = [CONSUS_RETOUR_PERKINS_VENDOR => consus_retour_vendor_types(CONSUS_RETOUR_PERKINS_VENDOR)];
        foreach ($data['lines'] as $index => $line) {
            if (is_array($line) && !isset($line['vendor'])) {
                $data['lines'][$index]['vendor'] = CONSUS_RETOUR_PERKINS_VENDOR;
            }
        }
    }

    return $data;
}

/** @return array<int, string> Types voor de editor: "Alle", de types uit de data en uit opgeslagen regels. */
function consus_retour_type_options(array $data, array $settings): array
{
    $types = [CONSUS_RETOUR_TYPE_ALL => CONSUS_RETOUR_TYPE_ALL];
    foreach (is_array($data['types_by_vendor'] ?? null) ? $data['types_by_vendor'] : [] as $list) {
        foreach ((array) $list as $type) {
            $type = consus_retour_normalize_type($type);
            $types[$type] = $type;
        }
    }
    foreach ($settings['rules'] ?? [] as $list) {
        foreach ($list as $rule) {
            $types[$rule['type']] = $rule['type'];
        }
    }

    return array_values($types);
}

/**
 * Hint bij een regel: nog geen data voor de leverancier, of een type dat die
 * leverancier niet kent (de regel matcht dan niets).
 */
function consus_retour_rule_hint(array $rule, array $data): string
{
    $vendors = array_map('strval', (array) ($data['vendors'] ?? []));
    if (trim((string) ($data['generated_at'] ?? '')) === '' || !in_array($rule['vendor'], $vendors, true)) {
        return 'Gegevens volgen na de volgende nightly.';
    }
    $types = array_merge(
        consus_retour_vendor_types($rule['vendor']),
        array_map('strval', (array) (($data['types_by_vendor'] ?? [])[$rule['vendor']] ?? []))
    );
    if ($rule['type'] !== CONSUS_RETOUR_TYPE_ALL && !in_array($rule['type'], $types, true)) {
        return 'Leverancier ' . $rule['vendor'] . ' kent type ' . $rule['type'] . ' niet; deze regel vindt niets. Laat Type leeg voor alles.';
    }
    $from = consus_retour_parse_date($data['fetched_from'] ?? '');
    if ($from !== '') {
        $needed = (new DateTimeImmutable(consus_retour_today() . ' 00:00:00', new DateTimeZone('Europe/Amsterdam')))->modify('-' . (int) $rule['window'] . ' days')->format('Y-m-d');
        if ($needed < $from) {
            return 'Facturen vóór ' . consus_retour_dutch_date($from) . ' volgen na de volgende nightly.';
        }
    }

    return '';
}

function consus_retour_rule_label(array $rule): string
{
    return $rule['vendor'] . ' / ' . consus_retour_type_label($rule['type']) . ' / ' . (int) $rule['window'] . ' dagen / ' . consus_retour_money((float) $rule['min_value']);
}

/**
 * Rekent de retourkandidaten uit.
 *
 * $data:
 *  lines:  [{invoice, vendor, vendor_invoice, document_date, item, description, quantity, unit_cost, order_no, department}]
 *  orders: {PO: {egt:bool|null, csv:bool|null, found:bool}}
 *  items:  {ITEM: {description, safety_stock, stock:{LOC:qty}, bin:{LOC:qty}|null,
 *                  reserved:qty, reserved_return:qty, tariff, origin}}
 *  types_by_vendor: {VENDOR: [type, ...]}
 *
 * Stappen:
 *  1. Regels per (factuur, artikel) optellen (Ivan: minimum per artikel per factuur).
 *  2. Type per PO (classify) als de leverancier types kent, anders geen type.
 *  3. Regels van de afdeling met dezelfde leverancier en type "Alle" of hetzelfde
 *     type; binnen de termijn (vanaf Document_Date) van minstens één regel.
 *  4. Artikel: geen veiligheidsvoorraad. Vrije voorraad = boekvoorraad − reserveringen.
 *     Vrije voorraad gaat eerst naar de nieuwste factuur, daarna naar oudere.
 *  5. Retourwaarde = retouraantal × gemiddelde inkoopprijs ≥ minimum van een
 *     regel waarvan de termijn nog loopt.
 *  6. Bincontrole HVT: bin-inhoud < boekvoorraad → 'controleren'.
 *
 * $department leeg = alle afdelingen met regels (export).
 *
 * @return array{rows:array<int, array>, garantie:array<int, array>, skipped:array<string,int>}
 */
function consus_retour_candidates(array $data, array $settings, string $today, string $department = ''): array
{
    $skipped = ['geen_regel' => 0, 'verlopen' => 0, 'veiligheidsvoorraad' => 0, 'geen_vrije_voorraad' => 0, 'onder_minimum' => 0, 'afdeling' => 0];
    $departmentKey = $department === '' ? '' : consus_retour_valid_department($department);
    if ($department !== '' && $departmentKey === '') {
        return ['rows' => [], 'garantie' => [], 'skipped' => $skipped];
    }
    $typesByVendor = is_array($data['types_by_vendor'] ?? null) ? $data['types_by_vendor'] : [];
    $garantieLines = array_flip(consus_retour_normalize_line_keys($settings['garantie_lines'] ?? []));

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
                'vendor' => consus_retour_normalize_vendor($line['vendor'] ?? ''),
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

    $orders = is_array($data['orders'] ?? null) ? $data['orders'] : [];
    $items = is_array($data['items'] ?? null) ? $data['items'] : [];

    $open = [];
    foreach ($groups as $group) {
        $groupDepartment = consus_retour_valid_department($group['department']);
        if ($departmentKey !== '' && $groupDepartment !== $departmentKey) {
            $skipped['afdeling']++;
            continue;
        }
        $poList = array_keys($group['orders']);
        $vendorTypes = (array) ($typesByVendor[$group['vendor']] ?? []);
        $account = CONSUS_RETOUR_TYPE_ALL;
        $flagsKnown = true;
        if ($vendorTypes !== []) {
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
        }
        $matching = array_values(array_filter(
            consus_retour_rules_for_department($settings, $group['department']),
            static fn (array $rule): bool => $rule['vendor'] === $group['vendor'] && ($rule['type'] === CONSUS_RETOUR_TYPE_ALL || $rule['type'] === $account)
        ));
        if ($matching === []) {
            $skipped['geen_regel']++;
            continue;
        }
        $date = $group['document_date'];
        if ($date === '') {
            $skipped['verlopen']++;
            continue;
        }
        $daysSince = consus_retour_days_between($date, $today);
        $active = array_values(array_filter($matching, static fn (array $rule): bool => (int) $rule['window'] - $daysSince >= 0));
        if ($active === []) {
            $skipped['verlopen']++;
            continue;
        }
        $garantie = isset($garantieLines[consus_retour_line_key($group['invoice'], $group['item'])]);
        $garantieByOrder = false;
        foreach ($poList as $po) {
            $garantieByOrder = $garantieByOrder || consus_retour_is_garantie($po, $settings);
        }
        $group += [
            'po' => implode(', ', $poList),
            'order_list' => $poList,
            'account' => $account,
            'flags_known' => $flagsKnown,
            'garantie' => $garantie || $garantieByOrder,
            'garantie_by_order' => $garantieByOrder,
            'days_since' => $daysSince,
            'active_rules' => $active,
            'unit_cost' => $group['quantity'] != 0.0 ? $group['amount'] / $group['quantity'] : 0.0,
        ];
        $open[] = $group;
    }

    // Nieuwste factuur eerst krijgt de vrije voorraad.
    usort($open, static fn (array $a, array $b): int => [$b['document_date'], $b['invoice']] <=> [$a['document_date'], $a['invoice']]);
    $free = [];
    $rows = [];
    $garantieRows = [];
    $tz = new DateTimeZone('Europe/Amsterdam');
    $applyRule = static function (array $row, array $rule) use ($tz): array {
        $row['rule'] = $rule;
        $row['window'] = (int) $rule['window'];
        $row['days_left'] = (int) $rule['window'] - (int) $row['days_since'];
        $row['deadline'] = (new DateTimeImmutable($row['document_date'] . ' 00:00:00', $tz))->modify('+' . (int) $rule['window'] . ' days')->format('Y-m-d');

        return $row;
    };
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
            'stock' => $stock,
            'stock_by_location' => $stockByLocation,
            'bin_by_location' => is_array($info['bin'] ?? null) ? $info['bin'] : null,
            'reserved' => $reserved,
            'reserved_return' => $reservedReturn,
            'safety_stock' => $safety,
            'free_before' => $free[$itemKey],
            'tariff' => (string) ($info['tariff'] ?? ''),
            'origin' => (string) ($info['origin'] ?? ''),
        ];
        if (($info['description'] ?? '') !== '' && $row['description'] === '') {
            $row['description'] = (string) $info['description'];
        }
        // Langste resterende termijn als uitleg.
        usort($group['active_rules'], static fn (array $a, array $b): int => [$b['window'], $a['min_value']] <=> [$a['window'], $b['min_value']]);
        if ($group['garantie']) {
            $row = $applyRule($row, $group['active_rules'][0]);
            $row['return_qty'] = 0.0;
            $row['value'] = round($group['amount'], 2);
            $row['status'] = 'garantie';
            $row['status_label'] = 'Garantie — handmatig afhandelen, geen retourkandidaat';
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
        $passing = array_values(array_filter($group['active_rules'], static fn (array $rule): bool => $value + 0.000001 >= (float) $rule['min_value']));
        if ($passing === []) {
            $skipped['onder_minimum']++;
            continue;
        }
        $row = $applyRule($row, $passing[0]);
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

/** Waarom deze regel op de lijst staat, in gewone taal. */
function consus_retour_reason(array $row): string
{
    $rule = $row['rule'];
    $parts = [
        'Regel ' . consus_retour_rule_label($rule) . ' van afdeling ' . $row['department'] . '.',
        'Factuurdatum ' . consus_retour_dutch_date($row['document_date']) . ', ' . (int) $row['days_since'] . ' dagen geleden; nog ' . (int) $row['days_left'] . ' dagen (t/m ' . consus_retour_dutch_date($row['deadline']) . ').',
    ];
    if ($row['account'] !== CONSUS_RETOUR_TYPE_ALL) {
        $parts[] = 'Type ' . consus_retour_type_label($row['account']) . ($row['account'] === CONSUS_RETOUR_ACCOUNT_SPOED ? ': inkooporder met EGT aan en CSV uit.' : ': geen spoedorder (EGT aan en CSV uit).');
    }
    if (!empty($row['garantie'])) {
        $parts[] = !empty($row['garantie_by_order']) ? 'Garantie via de inkooporder; geen retourkandidaat.' : 'Met de hand als garantie gemarkeerd; geen retourkandidaat.';
    } else {
        $parts[] = 'Vrije voorraad ' . consus_retour_qty((float) $row['free_before']) . ' (boekvoorraad ' . consus_retour_qty((float) $row['stock']) . ' − gereserveerd ' . consus_retour_qty((float) $row['reserved']) . '), geen veiligheidsvoorraad; retour ' . consus_retour_qty((float) $row['return_qty']) . ' stuks.';
        $parts[] = 'Retourwaarde ' . consus_retour_money((float) $row['value']) . ' ≥ minimum ' . consus_retour_money((float) $rule['min_value']) . '.';
    }

    return implode(' ', $parts);
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
        'Leverancier', 'Type', 'Leveranciersfactuur', 'BC-factuur', 'Factuurdatum', 'Artikel', 'Omschrijving',
        'PO-nummer', 'Aantal retour', 'Prijs per stuk', 'Totale prijs', 'Op voorraad', 'Gereserveerd',
        'Dagen sinds factuur', 'Dagen over', 'Uiterlijk retour', 'Tariff Code', 'Country of Origin',
        'Garantie', 'Status', 'Afdeling', 'Regel',
    ];
}

function consus_retour_export_row(array $row): array
{
    $garantie = !empty($row['garantie']);

    return [
        (string) $row['vendor'],
        consus_retour_type_label((string) $row['account']),
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
        consus_retour_rule_label($row['rule']),
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
