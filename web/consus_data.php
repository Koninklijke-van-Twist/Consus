<?php

require_once __DIR__ . '/consus_config.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';

function consus_snapshot_file(): string
{
    $override = getenv('CONSUS_SNAPSHOT_FILE');
    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }

    return __DIR__ . '/data/consus_snapshot.json';
}

function consus_snapshot_lock_file(): string
{
    return consus_snapshot_file() . '.lock';
}

function consus_normalize_name(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace(['.', ',', '-', '_'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
}

function consus_escape_odata_string(string $value): string
{
    return str_replace("'", "''", trim($value));
}

function consus_scalar_string(mixed $value): string
{
    if (is_string($value) || is_int($value) || is_float($value)) {
        return trim((string) $value);
    }

    return '';
}

function consus_scalar_float(mixed $value): float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    $text = str_replace([' ', ','], ['', '.'], consus_scalar_string($value));
    if ($text === '' || !is_numeric($text)) {
        return 0.0;
    }

    return (float) $text;
}

function consus_parse_date(mixed $value): string
{
    $text = consus_scalar_string($value);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $match) === 1) {
        return $match[1];
    }

    return '';
}

function consus_company_key_for_name(string $name): string
{
    $normalized = consus_normalize_name($name);
    if ($normalized === '') {
        return '';
    }

    foreach (CONSUS_COMPANIES as $key => $company) {
        foreach ($company['names'] as $candidate) {
            $needle = consus_normalize_name((string) $candidate);
            if ($needle === '') {
                continue;
            }
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return (string) $key;
            }
        }
    }

    return '';
}

function consus_company_label(string $companyKey): string
{
    $company = CONSUS_COMPANIES[$companyKey] ?? null;
    if (!is_array($company)) {
        return $companyKey;
    }

    return (string) ($company['label'] ?? $companyKey);
}

function consus_company_display_name(string $companyKey, string $fallback = ''): string
{
    if ($fallback !== '') {
        return $fallback;
    }

    $company = CONSUS_COMPANIES[$companyKey] ?? null;
    $names = is_array($company) ? ($company['names'] ?? []) : [];
    $first = trim((string) ($names[0] ?? ''));

    return $first !== '' ? $first : consus_company_label($companyKey);
}

/**
 * @param array<int, string> $discovered
 * @return array<int, array{company:string,company_key:string}>
 */
function consus_companies_in_scope(array $discovered): array
{
    $matched = [];
    foreach ($discovered as $name) {
        $company = trim((string) $name);
        $key = consus_company_key_for_name($company);
        if ($company === '' || $key === '' || isset($matched[$key])) {
            continue;
        }
        $matched[$key] = [
            'company' => $company,
            'company_key' => $key,
        ];
    }

    $ordered = [];
    foreach (array_keys(CONSUS_COMPANIES) as $key) {
        if (isset($matched[$key])) {
            $ordered[] = $matched[$key];
        }
    }

    return $ordered;
}

/**
 * Bedrijven uit CONSUS_COMPANIES die discovery niet teruggegeven heeft.
 * Die horen stale te blijven, anders verdwijnt hun vorige cache terwijl nightly OK meldt.
 *
 * @param array<int, array{company:string,company_key:string}> $companies
 * @param array<int, mixed> $discoveryErrors
 * @return array{errors:array<int, array{company:string,error:string}>,company_stats:array<int, array{company:string,company_key:string,stale:bool,duration_ms:int}>}
 */
function consus_missing_company_records(array $companies, array $discoveryErrors = []): array
{
    $foundKeys = [];
    foreach ($companies as $company) {
        if (!is_array($company)) {
            continue;
        }
        $foundKeys[(string) ($company['company_key'] ?? '')] = true;
    }

    $detail = [];
    foreach ($discoveryErrors as $error) {
        $text = trim((string) $error);
        if ($text !== '') {
            $detail[] = $text;
        }
    }
    $suffix = $detail === [] ? '' : ' ' . implode(' | ', $detail);

    $errors = [];
    $stats = [];
    foreach (array_keys(CONSUS_COMPANIES) as $missingKey) {
        if (isset($foundKeys[$missingKey])) {
            continue;
        }
        $name = consus_company_display_name($missingKey);
        $errors[] = [
            'company' => $name,
            'error' => 'Bedrijf niet gevonden bij discovery.' . $suffix,
        ];
        $stats[] = [
            'company' => $name,
            'company_key' => $missingKey,
            'stale' => true,
            'duration_ms' => 0,
        ];
    }

    return [
        'errors' => $errors,
        'company_stats' => $stats,
    ];
}

function consus_location_code(array $row): string
{
    if (!array_key_exists('Location_Code', $row)) {
        return '';
    }

    return strtoupper(trim(consus_scalar_string($row['Location_Code'] ?? '')));
}

/**
 * Eigen / EGT / dropship volgens inkooppad. Locatie speelt hier niet mee.
 * Dropship wint als zowel DROP_SHIP als leverancier 90101 op één regel staan.
 */
function consus_procurement_bucket(string $purchasingCode, string $vendorNo): string
{
    $code = strtoupper(trim($purchasingCode));
    $vendor = strtoupper(trim($vendorNo));
    $dropCode = strtoupper(trim(CONSUS_DROPSHIP_PURCHASING_CODE));
    $dropVendor = strtoupper(trim(CONSUS_DROPSHIP_VENDOR_NO));
    $egtVendor = strtoupper(trim(CONSUS_EGT_VENDOR_NO));

    if (($dropCode !== '' && $code === $dropCode) || ($dropVendor !== '' && $vendor === $dropVendor)) {
        return 'dropship';
    }
    if ($egtVendor !== '' && $vendor === $egtVendor) {
        return 'egt';
    }

    return 'eigen';
}

function consus_procurement_bucket_from_row(array $row): string
{
    $purchasingField = CONSUS_ILE_PURCHASING_CODE_FIELD;
    $vendorField = CONSUS_ILE_VENDOR_NO_FIELD;

    return consus_procurement_bucket(
        consus_scalar_string($purchasingField !== '' ? ($row[$purchasingField] ?? '') : ''),
        consus_scalar_string($vendorField !== '' ? ($row[$vendorField] ?? '') : '')
    );
}

function consus_entry_type_filter(string $entryType): string
{
    $entryType = trim($entryType);
    if ($entryType === '') {
        throw new InvalidArgumentException('Geen Entry_Type geconfigureerd.');
    }

    return "Entry_Type eq '" . consus_escape_odata_string($entryType) . "'";
}

/**
 * Volgende ASCII-grens zodat Document_No ge prefix and lt upper exact dat prefix is.
 * 'WO' wordt 'WP'. PHP controleert het prefix daarna nog eens.
 *
 * @return array{from:string,to:string}
 */
function consus_document_prefix_bounds(string $prefix): array
{
    $prefix = trim($prefix);
    if ($prefix === '') {
        return ['from' => '', 'to' => ''];
    }

    $upper = $prefix;
    for ($index = strlen($upper) - 1; $index >= 0; $index--) {
        $ord = ord($upper[$index]);
        if ($ord < 255) {
            $upper[$index] = chr($ord + 1);
            $upper = substr($upper, 0, $index + 1);

            return ['from' => $prefix, 'to' => $upper];
        }
    }

    return ['from' => $prefix, 'to' => ''];
}

function consus_document_prefix_odata_filter(string $prefix): string
{
    $bounds = consus_document_prefix_bounds($prefix);
    if ($bounds['from'] === '' || $bounds['to'] === '') {
        return '';
    }

    return "Document_No ge '" . consus_escape_odata_string($bounds['from'])
        . "' and Document_No lt '" . consus_escape_odata_string($bounds['to']) . "'";
}

/**
 * @return array<int, string>
 */
function consus_ledger_required_fields(bool $includeAmount, bool $includeDocument): array
{
    $fields = CONSUS_LEDGER_FIELDS;
    if ($includeAmount) {
        $fields[] = 'Sales_Amount_Actual';
    }
    if ($includeDocument) {
        $fields[] = 'Document_No';
    }

    return $fields;
}

/**
 * Eén Entry_Type per query. BC weigert OR over verschillende velden (HTTP 501)
 * en startswith. Een documentprefix wordt daarom een bereik (WO t/m vóór WP).
 * $toDate is exclusief: Posting_Date lt die dag.
 *
 * @param array<int, string> $entryTypes
 * @return array<string, string>
 */
function consus_ledger_query(
    array $entryTypes,
    string $fromDate,
    string $toDate = '',
    string $documentPrefix = '',
    bool $includeAmount = false,
    bool $includeDocument = false
): array {
    $chosen = [];
    foreach ($entryTypes as $entryType) {
        $entryType = trim((string) $entryType);
        if ($entryType !== '') {
            $chosen[] = $entryType;
        }
    }
    if (count($chosen) !== 1) {
        throw new InvalidArgumentException('Artikelposten: één Entry_Type per query. OR in $filter wordt door BC geweigerd.');
    }

    $fromDate = consus_parse_date($fromDate);
    if ($fromDate === '') {
        throw new InvalidArgumentException('Ongeldige vanaf-datum voor artikelposten.');
    }

    $filter = consus_entry_type_filter($chosen[0]) . ' and Posting_Date ge ' . $fromDate;
    if ($toDate !== '') {
        $toDate = consus_parse_date($toDate);
        if ($toDate === '' || $toDate <= $fromDate) {
            throw new InvalidArgumentException('Ongeldige tot-datum voor artikelposten.');
        }
        $filter .= ' and Posting_Date lt ' . $toDate;
    }

    $documentPrefix = trim($documentPrefix);
    if ($documentPrefix !== '') {
        $includeDocument = true;
        $prefixFilter = consus_document_prefix_odata_filter($documentPrefix);
        if ($prefixFilter === '') {
            throw new InvalidArgumentException('Documentprefix kan niet als OData-bereik worden gezet.');
        }
        $filter .= ' and ' . $prefixFilter;
    }

    return consus_entity_query(
        consus_ledger_required_fields($includeAmount, $includeDocument),
        $filter
    );
}

/**
 * Aparte filters, in de volgorde waarin nightly ze probeert.
 *
 * @param array<int, string> $entryTypes
 * @return array<int, array<string, string>>
 */
function consus_ledger_filters(
    array $entryTypes,
    string $fromDate,
    string $toDate = '',
    string $documentPrefix = '',
    bool $includeAmount = false,
    bool $includeDocument = false
): array {
    $queries = [];
    foreach ($entryTypes as $entryType) {
        $entryType = trim((string) $entryType);
        if ($entryType === '') {
            continue;
        }
        $queries[] = consus_ledger_query(
            [$entryType],
            $fromDate,
            $toDate,
            $documentPrefix,
            $includeAmount,
            $includeDocument
        );
    }
    if ($queries === []) {
        throw new InvalidArgumentException('Geen Entry_Type geconfigureerd.');
    }

    return $queries;
}

/**
 * Negatieve correctie (documentprefix WO, client-side) en assemblageverbruik.
 * Kale consumption zit in geen van beide lijsten.
 *
 * @return array<int, array{entry_types:array<int, string>,document_prefix:string}>
 */
function consus_wo_ledger_parts(): array
{
    return [
        [
            'entry_types' => CONSUS_WO_PRIMARY_ENTRY_TYPES,
            'document_prefix' => CONSUS_WO_DOCUMENT_PREFIX,
        ],
        [
            'entry_types' => CONSUS_WO_ALSO_ENTRY_TYPES,
            'document_prefix' => '',
        ],
    ];
}

function consus_document_no_has_prefix(array $row, string $prefix): bool
{
    $prefix = trim($prefix);
    if ($prefix === '') {
        return true;
    }

    $documentNo = consus_scalar_string($row['Document_No'] ?? '');

    return $documentNo !== '' && strncasecmp($documentNo, $prefix, strlen($prefix)) === 0;
}

/**
 * @return array<string, string>
 */
function consus_dimension_query(): array
{
    $code = consus_escape_odata_string(CONSUS_COST_CENTER_DIMENSION_CODE);
    $filter = 'Table_ID eq ' . (int) CONSUS_DIMENSION_TABLE_ID . " and Dimension_Code eq '" . $code . "'";

    return consus_entity_query(CONSUS_DIMENSION_FIELDS, $filter);
}

/**
 * @param array<int, string> $fields
 * @return array<string, string>
 */
function consus_entity_query(array $fields, string $filter = '', ?int $top = null): array
{
    $query = [
        '$select' => implode(',', $fields),
    ];
    if ($filter !== '') {
        $query['$filter'] = $filter;
    }
    $top ??= CONSUS_ODATA_PAGE_SIZE;
    if ($top > 0) {
        $query['$top'] = (string) $top;
    }

    return $query;
}

/**
 * Maandgrenzen van history_start t/m de dag na as_of. Elke chunk is
 * Posting_Date ge from and lt to, zodat BC geen twaalf maanden in één
 * skip-keten hoeft te lopen. De totalen blijven hetzelfde venster.
 *
 * @param array{as_of:string,history_start:string} $windows
 * @return array<int, array{from:string,to:string}>
 */
function consus_ledger_date_chunks(array $windows): array
{
    $zone = new DateTimeZone('Europe/Amsterdam');
    $from = consus_parse_date($windows['history_start'] ?? '');
    $asOf = consus_parse_date($windows['as_of'] ?? '');
    if ($from === '' || $asOf === '') {
        throw new InvalidArgumentException('Ongeldig venster voor artikelposten.');
    }

    $cursor = new DateTimeImmutable($from . ' 00:00:00', $zone);
    $endExclusive = (new DateTimeImmutable($asOf . ' 00:00:00', $zone))->modify('+1 day');
    if ($cursor >= $endExclusive) {
        throw new InvalidArgumentException('Ongeldig venster voor artikelposten.');
    }

    $chunks = [];
    while ($cursor < $endExclusive) {
        $next = $cursor->modify('first day of next month');
        if ($next > $endExclusive) {
            $next = $endExclusive;
        }
        $chunks[] = [
            'from' => $cursor->format('Y-m-d'),
            'to' => $next->format('Y-m-d'),
        ];
        $cursor = $next;
    }

    return $chunks;
}

/**
 * @return array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string}
 */
function consus_period_windows(?DateTimeImmutable $asOf = null): array
{
    $zone = new DateTimeZone('Europe/Amsterdam');
    $asOf = ($asOf ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
    $monthStart = $asOf->modify('first day of this month')->setTime(0, 0);
    $quarterMonth = (int) (floor(((int) $asOf->format('n') - 1) / 3) * 3 + 1);
    $quarterStart = $asOf->setDate((int) $asOf->format('Y'), $quarterMonth, 1)->setTime(0, 0);
    $yearStart = $asOf->setDate((int) $asOf->format('Y'), 1, 1)->setTime(0, 0);
    $historyStart = $monthStart->modify('-11 months');

    return [
        'as_of' => $asOf->format('Y-m-d'),
        'month_start' => $monthStart->format('Y-m-d'),
        'quarter_start' => $quarterStart->format('Y-m-d'),
        'year_start' => $yearStart->format('Y-m-d'),
        'history_start' => $historyStart->format('Y-m-d'),
    ];
}

/**
 * @param array{as_of:string,history_start:string} $windows
 * @return array<int, string>
 */
function consus_month_keys(array $windows): array
{
    $zone = new DateTimeZone('Europe/Amsterdam');
    $cursor = new DateTimeImmutable($windows['history_start'] . ' 00:00:00', $zone);
    $end = new DateTimeImmutable($windows['as_of'] . ' 00:00:00', $zone);
    $keys = [];
    while ($cursor <= $end) {
        $keys[] = $cursor->format('Y-m');
        $cursor = $cursor->modify('+1 month');
    }

    return $keys;
}

/**
 * @return array{months:array<string, array{qty:float,amount:float}>,m:array{qty:float,amount:float},q:array{qty:float,amount:float},y:array{qty:float,amount:float}}
 */
function consus_empty_period_stats(): array
{
    return [
        'months' => [],
        'm' => ['qty' => 0.0, 'amount' => 0.0],
        'q' => ['qty' => 0.0, 'amount' => 0.0],
        'y' => ['qty' => 0.0, 'amount' => 0.0],
    ];
}

/**
 * @return array<string, array{months:array<string, array{qty:float,amount:float}>,m:array{qty:float,amount:float},q:array{qty:float,amount:float},y:array{qty:float,amount:float}}>
 */
function consus_empty_bucket_map(): array
{
    $map = [];
    foreach (array_keys(CONSUS_BUCKETS) as $bucket) {
        $map[$bucket] = consus_empty_period_stats();
    }

    return $map;
}

/**
 * Uitgaande BC-hoeveelheid is negatief. Positief in de cache = verkoop of verbruik.
 */
function consus_outbound_quantity(mixed $quantity): float
{
    return -1 * consus_scalar_float($quantity);
}

/**
 * @param array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string} $windows
 * @param array{months:array<string, array{qty:float,amount:float}>,m:array{qty:float,amount:float},q:array{qty:float,amount:float},y:array{qty:float,amount:float}} $stats
 */
function consus_add_to_period_stats(array &$stats, string $date, float $qty, float $amount, array $windows): void
{
    if ($date === '' || $date < $windows['history_start'] || $date > $windows['as_of']) {
        return;
    }

    $month = substr($date, 0, 7);
    if (!isset($stats['months'][$month])) {
        $stats['months'][$month] = ['qty' => 0.0, 'amount' => 0.0];
    }
    $stats['months'][$month]['qty'] += $qty;
    $stats['months'][$month]['amount'] += $amount;

    if ($date >= $windows['month_start']) {
        $stats['m']['qty'] += $qty;
        $stats['m']['amount'] += $amount;
    }
    if ($date >= $windows['quarter_start']) {
        $stats['q']['qty'] += $qty;
        $stats['q']['amount'] += $amount;
    }
    if ($date >= $windows['year_start']) {
        $stats['y']['qty'] += $qty;
        $stats['y']['amount'] += $amount;
    }
}

/**
 * @param array<string, array{months:array<string, array{qty:float,amount:float}>,m:array{qty:float,amount:float},q:array{qty:float,amount:float},y:array{qty:float,amount:float}}> $target
 * @param array<string, array{months:array<string, array{qty:float,amount:float}>,m:array{qty:float,amount:float},q:array{qty:float,amount:float},y:array{qty:float,amount:float}}> $source
 */
function consus_merge_bucket_maps(array &$target, array $source): void
{
    foreach ($source as $bucket => $stats) {
        if (!isset($target[$bucket])) {
            $target[$bucket] = consus_empty_period_stats();
        }
        foreach (['m', 'q', 'y'] as $period) {
            $target[$bucket][$period]['qty'] += (float) ($stats[$period]['qty'] ?? 0);
            $target[$bucket][$period]['amount'] += (float) ($stats[$period]['amount'] ?? 0);
        }
        foreach ($stats['months'] as $month => $values) {
            if (!isset($target[$bucket]['months'][$month])) {
                $target[$bucket]['months'][$month] = ['qty' => 0.0, 'amount' => 0.0];
            }
            $target[$bucket]['months'][$month]['qty'] += (float) ($values['qty'] ?? 0);
            $target[$bucket]['months'][$month]['amount'] += (float) ($values['amount'] ?? 0);
        }
    }
}

/**
 * @return array{company_key:string,company_name:string,item_no:string,vendor_no:string,vendor_name:string,cost_center:string,inventory:float,safety_stock:float,reorder_point:float,sales:array,consumption:array}
 */
function consus_new_item_fact(string $companyKey, string $itemNo, string $companyName = ''): array
{
    return [
        'company_key' => $companyKey,
        'company_name' => consus_company_display_name($companyKey, $companyName),
        'item_no' => $itemNo,
        'vendor_no' => '',
        'vendor_name' => '',
        'cost_center' => '',
        'inventory' => 0.0,
        'safety_stock' => 0.0,
        'reorder_point' => 0.0,
        'by_location' => [],
    ];
}

/**
 * Voorraad per locatie, zonder verkoop- of verbruiksbuckets.
 * Die buckets komen er pas bij als een artikelpost ze vult. Een lege
 * bucketmap per locatie hield de nachtrun boven memory_limit.
 *
 * @param array<string, mixed> $item
 * @return array{inventory:float,safety_stock:float,reorder_point:float}
 */
function consus_location_metrics(array &$item, string $location): array
{
    if (!isset($item['by_location'][$location]) || !is_array($item['by_location'][$location])) {
        $item['by_location'][$location] = [
            'inventory' => 0.0,
            'safety_stock' => 0.0,
            'reorder_point' => 0.0,
        ];
    }

    return $item['by_location'][$location];
}

/**
 * Eén bucket binnen sales of consumption. Andere buckets blijven weg tot er een post is.
 *
 * @param array<string, mixed> $item
 */
function consus_prepare_movement(array &$item, string $location, string $kind, string $bucket): void
{
    consus_location_metrics($item, $location);
    if (!isset($item['by_location'][$location][$kind]) || !is_array($item['by_location'][$location][$kind])) {
        $item['by_location'][$location][$kind] = [];
    }
    if (!isset($item['by_location'][$location][$kind][$bucket]) || !is_array($item['by_location'][$location][$kind][$bucket])) {
        $item['by_location'][$location][$kind][$bucket] = consus_empty_period_stats();
    }
}

function consus_vendor_name_from_item(array $row): string
{
    $name = consus_scalar_string($row['LVS_Vendor_Name'] ?? '');
    if ($name === '') {
        $name = consus_scalar_string($row['Vendor_Name'] ?? '');
    }

    return $name;
}

/**
 * @param array<string, array<string, mixed>> $items keyed by company_key|item_no
 * @param array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string} $windows
 */
function consus_stock_company_key(array $row, string $sourceCompany): string
{
    $companyName = consus_scalar_string($row['Company_Name'] ?? '');
    $companyKey = consus_company_key_for_name($companyName);
    if ($companyKey === '') {
        $companyKey = consus_company_key_for_name($sourceCompany);
    }

    return $companyKey;
}

function consus_apply_stock_row(array &$items, array $row, string $sourceCompany): void
{
    $itemNo = consus_scalar_string($row['Item_No'] ?? '');
    if ($itemNo === '') {
        return;
    }

    $companyName = consus_scalar_string($row['Company_Name'] ?? '');
    $companyKey = consus_stock_company_key($row, $sourceCompany);
    if ($companyKey === '') {
        return;
    }
    if ($companyName === '') {
        $companyName = $sourceCompany;
    }

    $key = $companyKey . '|' . $itemNo;
    if (!isset($items[$key])) {
        $items[$key] = consus_new_item_fact($companyKey, $itemNo, $companyName);
    }

    $location = consus_location_code($row);
    if (!empty($items[$key]['_stock_seen'][$location])) {
        return;
    }
    $items[$key]['_stock_seen'][$location] = true;

    $metrics = consus_location_metrics($items[$key], $location);
    $inventory = consus_scalar_float($row['Inventory'] ?? 0);
    $safety = consus_scalar_float($row['Safety_Stock_Quantity'] ?? 0);
    $reorder = consus_scalar_float($row['Reorder_Point'] ?? 0);
    $items[$key]['by_location'][$location]['inventory'] = (float) $metrics['inventory'] + $inventory;
    $items[$key]['by_location'][$location]['safety_stock'] = (float) $metrics['safety_stock'] + $safety;
    $items[$key]['by_location'][$location]['reorder_point'] = (float) $metrics['reorder_point'] + $reorder;
    $items[$key]['inventory'] += $inventory;
    $items[$key]['safety_stock'] += $safety;
    $items[$key]['reorder_point'] += $reorder;
}

/**
 * @param array<string, array<string, mixed>> $items
 */
function consus_apply_vendor_row(array &$items, array $row, string $companyKey): void
{
    if ($companyKey === '') {
        return;
    }

    $itemNo = consus_scalar_string($row['No'] ?? $row['Item_No'] ?? '');
    if ($itemNo === '') {
        return;
    }

    $key = $companyKey . '|' . $itemNo;
    if (!isset($items[$key])) {
        return;
    }

    $vendorNo = consus_scalar_string($row['Vendor_No'] ?? '');
    $vendorName = consus_vendor_name_from_item($row);
    if ($vendorNo !== '') {
        $items[$key]['vendor_no'] = $vendorNo;
    }
    if ($vendorName !== '') {
        $items[$key]['vendor_name'] = $vendorName;
    }

    $costCenter = consus_scalar_string($row['COST_CENTER'] ?? '');
    if ($costCenter !== '') {
        $items[$key]['cost_center'] = $costCenter;
    }
}

/**
 * @param array<string, array<string, mixed>> $items
 */
function consus_apply_dimension_row(array &$items, array $row, string $companyKey): void
{
    if ($companyKey === '') {
        return;
    }

    $dimensionCode = consus_scalar_string($row['Dimension_Code'] ?? '');
    if ($dimensionCode !== '' && strcasecmp($dimensionCode, CONSUS_COST_CENTER_DIMENSION_CODE) !== 0) {
        return;
    }

    $itemNo = consus_scalar_string($row['No'] ?? '');
    $value = consus_scalar_string($row['Dimension_Value_Code'] ?? '');
    if ($itemNo === '' || $value === '') {
        return;
    }

    $key = $companyKey . '|' . $itemNo;
    if (!isset($items[$key])) {
        return;
    }

    if ((string) $items[$key]['cost_center'] === '') {
        $items[$key]['cost_center'] = $value;
    }
}

/**
 * @param array<string, array<string, mixed>> $items
 * @param array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string} $windows
 */
function consus_apply_ledger_row(array &$items, array $row, string $companyKey, string $kind, array $windows): void
{
    if ($companyKey === '' || ($kind !== 'sales' && $kind !== 'consumption')) {
        return;
    }

    $itemNo = consus_scalar_string($row['Item_No'] ?? '');
    $date = consus_parse_date($row['Posting_Date'] ?? '');
    if ($itemNo === '' || $date === '') {
        return;
    }

    $location = consus_location_code($row);
    $bucket = consus_procurement_bucket_from_row($row);
    if (!isset(CONSUS_BUCKETS[$bucket])) {
        $bucket = 'eigen';
    }

    $key = $companyKey . '|' . $itemNo;
    if (!isset($items[$key])) {
        $items[$key] = consus_new_item_fact($companyKey, $itemNo);
    }

    consus_prepare_movement($items[$key], $location, $kind, $bucket);
    $qty = consus_outbound_quantity($row['Quantity'] ?? 0);
    $amount = $kind === 'sales' ? consus_scalar_float($row['Sales_Amount_Actual'] ?? 0) : 0.0;
    consus_add_to_period_stats($items[$key]['by_location'][$location][$kind][$bucket], $date, $qty, $amount, $windows);
}

function consus_compare_snapshot_rows(array $left, array $right): int
{
    return strnatcasecmp(
        implode('|', [(string) ($left['company_key'] ?? ''), (string) ($left['vendor_name'] ?? ''), (string) ($left['cost_center'] ?? ''), (string) ($left['location'] ?? '')]),
        implode('|', [(string) ($right['company_key'] ?? ''), (string) ($right['vendor_name'] ?? ''), (string) ($right['cost_center'] ?? ''), (string) ($right['location'] ?? '')])
    );
}

/**
 * @param array<string, array<string, mixed>> $items
 * @param array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string} $windows
 * @param array<int, string> $unmappedLocations
 * @return array{rows:array<int, array<string, mixed>>,vendors:array<int, array{vendor_no:string,vendor_name:string}>,cost_centers:array<int, string>,locations:array<int, string>}
 */
function consus_rollup_items(array $items, array $windows, array $unmappedLocations = []): array
{
    unset($windows, $unmappedLocations);
    $grouped = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $companyKey = (string) ($item['company_key'] ?? '');
        if (!isset(CONSUS_COMPANIES[$companyKey])) {
            continue;
        }

        $vendorNo = trim((string) ($item['vendor_no'] ?? ''));
        $vendorName = trim((string) ($item['vendor_name'] ?? ''));
        if ($vendorName === '') {
            $vendorName = $vendorNo !== '' ? $vendorNo : 'Geen leverancier';
        }
        $costCenter = trim((string) ($item['cost_center'] ?? ''));
        $locations = is_array($item['by_location'] ?? null) ? $item['by_location'] : [];
        if ($locations === []) {
            $locations = ['' => [
                'inventory' => (float) ($item['inventory'] ?? 0),
                'safety_stock' => (float) ($item['safety_stock'] ?? 0),
                'reorder_point' => (float) ($item['reorder_point'] ?? 0),
                'sales' => consus_empty_bucket_map(),
                'consumption' => consus_empty_bucket_map(),
            ]];
        }

        foreach ($locations as $location => $metrics) {
            if (!is_array($metrics)) {
                continue;
            }
            $locationCode = strtoupper(trim((string) $location));
            $groupKey = $companyKey . '|' . $vendorNo . '|' . $costCenter . '|' . $locationCode;
            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'company_key' => $companyKey,
                    'company_name' => consus_company_display_name($companyKey, (string) ($item['company_name'] ?? '')),
                    'vendor_no' => $vendorNo,
                    'vendor_name' => $vendorName,
                    'cost_center' => $costCenter,
                    'location' => $locationCode,
                    'inventory' => 0.0,
                    'safety_stock' => 0.0,
                    'reorder_point' => 0.0,
                    'item_count' => 0,
                    'item_nos' => [],
                    'sales' => consus_empty_bucket_map(),
                    'consumption' => consus_empty_bucket_map(),
                ];
            }

            if (strlen($vendorName) > strlen((string) $grouped[$groupKey]['vendor_name'])) {
                $grouped[$groupKey]['vendor_name'] = $vendorName;
            }
            $grouped[$groupKey]['inventory'] += (float) ($metrics['inventory'] ?? 0);
            $grouped[$groupKey]['safety_stock'] += (float) ($metrics['safety_stock'] ?? 0);
            $grouped[$groupKey]['reorder_point'] += (float) ($metrics['reorder_point'] ?? 0);
            $itemNo = trim((string) ($item['item_no'] ?? ''));
            if ($itemNo !== '') {
                $grouped[$groupKey]['item_nos'][$itemNo] = true;
            }
            $grouped[$groupKey]['item_count'] = count($grouped[$groupKey]['item_nos']);
            consus_merge_bucket_maps($grouped[$groupKey]['sales'], is_array($metrics['sales'] ?? null) ? $metrics['sales'] : []);
            consus_merge_bucket_maps($grouped[$groupKey]['consumption'], is_array($metrics['consumption'] ?? null) ? $metrics['consumption'] : []);
        }
    }

    $rows = array_values($grouped);
    usort($rows, consus_compare_snapshot_rows(...));

    $vendors = [];
    $costCenters = [];
    $locations = [];
    foreach ($rows as $row) {
        $vendorNo = (string) $row['vendor_no'];
        if (!isset($vendors[$vendorNo])) {
            $vendors[$vendorNo] = [
                'vendor_no' => $vendorNo,
                'vendor_name' => (string) $row['vendor_name'],
            ];
        }
        $costCenter = (string) $row['cost_center'];
        if ($costCenter !== '') {
            $costCenters[$costCenter] = $costCenter;
        }
        $location = (string) ($row['location'] ?? '');
        if ($location !== '') {
            $locations[$location] = $location;
        }
    }

    $vendorList = array_values($vendors);
    usort($vendorList, static function (array $left, array $right): int {
        return strnatcasecmp((string) $left['vendor_name'], (string) $right['vendor_name']);
    });
    $costCenterList = array_values($costCenters);
    natcasesort($costCenterList);

    $locationList = array_values($locations);
    natcasesort($locationList);

    return [
        'rows' => $rows,
        'vendors' => $vendorList,
        'cost_centers' => array_values($costCenterList),
        'locations' => array_values($locationList),
    ];
}

function consus_filter_value_matches(string $actual, string $filter): bool
{
    if ($filter === '') {
        return true;
    }
    if ($filter === '__none__') {
        return $actual === '';
    }

    return strcasecmp($actual, $filter) === 0;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function consus_matching_rows(array $rows, string $companyKey, string $costCenter = '', string $vendorNo = '', string $location = ''): array
{
    $matched = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($companyKey !== '' && (string) ($row['company_key'] ?? '') !== $companyKey) {
            continue;
        }
        if (!consus_filter_value_matches((string) ($row['cost_center'] ?? ''), $costCenter)) {
            continue;
        }
        if (!consus_filter_value_matches((string) ($row['vendor_no'] ?? ''), $vendorNo)) {
            continue;
        }
        if (!consus_filter_value_matches((string) ($row['location'] ?? ''), $location)) {
            continue;
        }
        $matched[] = $row;
    }

    return $matched;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, string>
 */
function consus_department_options(array $rows, string $companyKey): array
{
    $options = [];
    foreach (consus_matching_rows($rows, $companyKey) as $row) {
        $options[(string) ($row['cost_center'] ?? '')] = true;
    }
    $list = array_keys($options);
    natcasesort($list);

    return array_values($list);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array{vendor_no:string,vendor_name:string}>
 */
function consus_vendor_options(array $rows, string $companyKey, string $costCenter): array
{
    $options = [];
    foreach (consus_matching_rows($rows, $companyKey, $costCenter) as $row) {
        $vendorNo = (string) ($row['vendor_no'] ?? '');
        $name = (string) ($row['vendor_name'] ?? '');
        if (!isset($options[$vendorNo]) || strlen($name) > strlen((string) $options[$vendorNo]['vendor_name'])) {
            $options[$vendorNo] = [
                'vendor_no' => $vendorNo,
                'vendor_name' => $name !== '' ? $name : ($vendorNo !== '' ? $vendorNo : 'Geen leverancier'),
            ];
        }
    }
    $list = array_values($options);
    usort($list, static function (array $left, array $right): int {
        return strnatcasecmp((string) $left['vendor_name'], (string) $right['vendor_name']);
    });

    return $list;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, string>
 */
function consus_location_options(array $rows, string $companyKey, string $costCenter, string $vendorNo): array
{
    $options = [];
    foreach (consus_matching_rows($rows, $companyKey, $costCenter, $vendorNo) as $row) {
        $options[(string) ($row['location'] ?? '')] = true;
    }
    $list = array_keys($options);
    natcasesort($list);

    return array_values($list);
}

/**
 * @param array<int, array{vendor_no:string,vendor_name:string}> $vendors
 */
function consus_default_vendor_no(array $vendors): string
{
    $needle = consus_normalize_name(CONSUS_DEFAULT_VENDOR_MATCH);
    if ($needle === '') {
        return '';
    }

    $partial = '';
    foreach ($vendors as $vendor) {
        $name = consus_normalize_name((string) ($vendor['vendor_name'] ?? ''));
        $number = consus_normalize_name((string) ($vendor['vendor_no'] ?? ''));
        if ($name === $needle || $number === $needle) {
            return (string) ($vendor['vendor_no'] ?? '');
        }
        if ($partial === '' && (str_contains($name, $needle) || str_contains($number, $needle))) {
            $partial = (string) ($vendor['vendor_no'] ?? '');
        }
    }

    return $partial;
}

function consus_turnover(float $salesQty, float $inventory): ?float
{
    if (abs($inventory) < 0.0000001) {
        return null;
    }

    return $salesQty / $inventory;
}

/**
 * @param array<string, mixed> $snapshot
 * @return array<string, mixed>
 */
function consus_summarize(array $snapshot, string $companyKey, string $vendorNo, string $costCenter, string $location = ''): array
{
    $sales = consus_empty_bucket_map();
    $consumption = consus_empty_bucket_map();
    $inventory = 0.0;
    $safety = 0.0;
    $reorder = 0.0;
    $seenItems = [];
    $itemCount = 0;

    $rows = consus_matching_rows(
        is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [],
        $companyKey,
        $costCenter,
        $vendorNo,
        $location
    );
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $inventory += (float) ($row['inventory'] ?? 0);
        $safety += (float) ($row['safety_stock'] ?? 0);
        $reorder += (float) ($row['reorder_point'] ?? 0);
        $itemNos = $row['item_nos'] ?? null;
        if (is_array($itemNos) && $itemNos !== []) {
            foreach (array_keys($itemNos) as $itemNo) {
                $seenItems[(string) ($row['company_key'] ?? '') . '|' . (string) $itemNo] = true;
            }
        } else {
            $itemCount += (int) ($row['item_count'] ?? 0);
        }
        consus_merge_bucket_maps($sales, is_array($row['sales'] ?? null) ? $row['sales'] : []);
        consus_merge_bucket_maps($consumption, is_array($row['consumption'] ?? null) ? $row['consumption'] : []);
    }
    if ($seenItems !== []) {
        $itemCount += count($seenItems);
    }

    $turnover = [];
    foreach (CONSUS_TURNOVER_BUCKETS as $bucket) {
        $turnover[$bucket] = [
            'm' => consus_turnover((float) ($sales[$bucket]['m']['qty'] ?? 0), $inventory),
            'q' => consus_turnover((float) ($sales[$bucket]['q']['qty'] ?? 0), $inventory),
            'y' => consus_turnover((float) ($sales[$bucket]['y']['qty'] ?? 0), $inventory),
        ];
    }

    return [
        'inventory' => $inventory,
        'safety_stock' => $safety,
        'reorder_point' => $reorder,
        'item_count' => $itemCount,
        'sales' => $sales,
        'consumption' => $consumption,
        'turnover' => $turnover,
    ];
}

function consus_empty_snapshot(): array
{
    $windows = consus_period_windows();

    return [
        'version' => CONSUS_SNAPSHOT_VERSION,
        'generated_at' => '',
        'as_of' => $windows['as_of'],
        'windows' => $windows,
        'companies' => [],
        'errors' => [],
        'warnings' => [],
        'vendors' => [],
        'cost_centers' => [],
        'locations' => [],
        'rows' => [],
    ];
}

function consus_read_snapshot(): array
{
    $path = consus_snapshot_file();
    if (!is_file($path)) {
        return consus_empty_snapshot();
    }

    $raw = @file_get_contents($path);
    $snapshot = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($snapshot) || !is_array($snapshot['rows'] ?? null)) {
        return consus_empty_snapshot();
    }

    return array_merge(consus_empty_snapshot(), $snapshot);
}

function consus_with_snapshot_lock(callable $callback): mixed
{
    $path = consus_snapshot_file();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Snapshotmap kon niet worden aangemaakt.');
    }

    $lock = @fopen(consus_snapshot_lock_file(), 'c+');
    if ($lock === false) {
        throw new RuntimeException('Snapshot-lock kon niet worden geopend.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Snapshot-lock kon niet worden verkregen.');
        }

        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function consus_write_snapshot(array $snapshot): void
{
    $snapshot['version'] = CONSUS_SNAPSHOT_VERSION;
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Snapshot kon niet als JSON worden gecodeerd.');
    }

    $path = consus_snapshot_file();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Snapshotmap kon niet worden aangemaakt.');
    }

    $temporary = $path . '.tmp.' . getmypid();
    if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
        throw new RuntimeException('Tijdelijke snapshot kon niet worden geschreven.');
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Snapshot kon niet atomair worden vervangen.');
    }
}

function consus_company_entity_url(
    string $baseUrl,
    string $environment,
    string $company,
    string $entitySet,
    array $query = []
): string {
    $safeCompany = rawurlencode(consus_escape_odata_string($company));
    $url = rtrim($baseUrl, '/')
        . '/' . rawurlencode($environment)
        . "/ODataV4/Company('" . $safeCompany . "')/"
        . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

/**
 * Leest OData-pagina's rechtstreeks uit BC. De dagcache is alleen de snapshot.
 *
 * @return array<int, array<string, mixed>>
 */
function consus_fetch_url_live(string $url, array $auth): array
{
    $rows = [];
    consus_each_url_live($url, $auth, static function (array $row) use (&$rows): void {
        $rows[] = $row;
    });

    return $rows;
}

function consus_each_url_live(string $url, array $auth, callable $onRow, ?callable $onPage = null): int
{
    $count = 0;
    $pages = 0;
    $next = $url;
    $guard = 0;
    $handle = odata_init_curl($auth);

    try {
        while ($next !== '') {
            $guard++;
            if ($guard > 100000) {
                throw new RuntimeException('OData-paginering stopte niet.');
            }

            $response = odata_curl_json($handle, $next);
            $page = $response['value'] ?? null;
            if (!is_array($page)) {
                throw new RuntimeException('OData-response bevat geen value-array.');
            }

            foreach ($page as $row) {
                if (is_array($row)) {
                    $onRow($row);
                    $count++;
                }
            }

            $pages++;
            if ($onPage !== null) {
                $onPage($pages, $count);
            }

            $nextLink = $response['@odata.nextLink'] ?? '';
            $next = is_string($nextLink) ? $nextLink : '';
        }
    } finally {
        curl_close($handle);
    }

    return $count;
}

function consus_odata_spill_path(): string
{
    $directory = sys_get_temp_dir();

    return $directory . '/consus-odata-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.ndjson';
}

function consus_track_temp_file(string $path): void
{
    if (!isset($GLOBALS['consus_temp_files']) || !is_array($GLOBALS['consus_temp_files'])) {
        $GLOBALS['consus_temp_files'] = [];
    }
    $GLOBALS['consus_temp_files'][$path] = true;

    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(static function (): void {
        $paths = $GLOBALS['consus_temp_files'] ?? [];
        if (!is_array($paths)) {
            return;
        }
        foreach (array_keys($paths) as $tracked) {
            if (is_string($tracked) && is_file($tracked)) {
                @unlink($tracked);
            }
        }
    });
}

function consus_release_temp_file(string $path): void
{
    if (isset($GLOBALS['consus_temp_files']) && is_array($GLOBALS['consus_temp_files'])) {
        unset($GLOBALS['consus_temp_files'][$path]);
    }
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Haalt een OData-set pagina voor pagina op en schrijft die meteen weg.
 * $onRow draait pas nadat de hele poging gelukt is, zodat een geweigerd
 * filter of een afgebroken pagina niet half én daarna nog een keer telt.
 *
 * @param callable(callable(array<string, mixed>):void):int $fetchInto
 * @return array{count:int,sample:array<string, mixed>|null}
 */
function consus_collect_rows_via_spill(callable $fetchInto, callable $onRow): array
{
    $path = consus_odata_spill_path();
    consus_track_temp_file($path);
    $handle = @fopen($path, 'w+b');
    if ($handle === false) {
        consus_release_temp_file($path);
        throw new RuntimeException('Tijdelijk OData-bestand kon niet worden geopend.');
    }
    @chmod($path, 0600);

    $sample = null;
    try {
        $count = $fetchInto(static function (array $row) use ($handle, &$sample): void {
            if ($sample === null) {
                $sample = $row;
            }
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                throw new RuntimeException('OData-regel kon niet als JSON worden weggeschreven.');
            }
            if (fwrite($handle, $encoded . "\n") === false) {
                throw new RuntimeException('OData-regel kon niet worden weggeschreven.');
            }
        });
        if (!is_int($count)) {
            throw new RuntimeException('OData-telling ontbreekt.');
        }

        if ($count > 0) {
            fflush($handle);
            if (!rewind($handle)) {
                throw new RuntimeException('Tijdelijk OData-bestand kon niet worden teruggelezen.');
            }
            $replayed = 0;
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    throw new RuntimeException('Tijdelijk OData-bestand bevat een onleesbare regel.');
                }
                $onRow($row);
                $replayed++;
            }
            if ($replayed !== $count) {
                throw new RuntimeException('Tijdelijk OData-bestand is onvolledig.');
            }
        }

        return [
            'count' => $count,
            'sample' => is_array($sample) ? $sample : null,
        ];
    } finally {
        fclose($handle);
        consus_release_temp_file($path);
    }
}

/**
 * @param array<int, string> $required
 * @param array<int, string> $optional
 * @param callable(string, array<string, mixed>, callable(array<string, mixed>):void):int|null $fetchRows
 * @return array{count:int,optional_fields:bool,missing_optional:array<int, string>,page_size_fallback:bool}
 */
function consus_each_entity_rows(
    string $company,
    string $entitySet,
    array $required,
    array $optional,
    string $filter,
    callable $onRow,
    ?callable $fetchRows = null,
    ?callable $onPage = null
): array {
    global $baseUrl;

    $environment = auth_get_environment_for_company($company);
    $auth = auth_get_auth_for_environment($environment);
    $fetchRows ??= static function (string $url, array $auth, callable $onRow) use ($onPage): int {
        return consus_each_url_live($url, $auth, $onRow, $onPage);
    };
    $attempts = [];
    if ($optional !== []) {
        $attempts[] = array_values(array_unique(array_merge($required, $optional)));
    }
    $attempts[] = $required;

    $pageSizes = [];
    if (CONSUS_ODATA_PAGE_SIZE > 0) {
        $pageSizes[] = CONSUS_ODATA_PAGE_SIZE;
    }
    $pageSizes[] = 0;

    $lastError = null;
    $attemptCount = count($attempts);
    foreach ($attempts as $index => $fields) {
        foreach ($pageSizes as $pageSizeIndex => $pageSize) {
            $query = consus_entity_query($fields, $filter, $pageSize);
            $url = consus_company_entity_url((string) $baseUrl, $environment, $company, $entitySet, $query);
            $useOptionalAttempt = $index === 0 && $optional !== [];
            try {
                $fetched = consus_collect_rows_via_spill(
                    static function (callable $onSpillRow) use ($fetchRows, $url, $auth): int {
                        return $fetchRows($url, $auth, $onSpillRow);
                    },
                    $onRow
                );
                if ($useOptionalAttempt && $fetched['count'] === 0 && $index < $attemptCount - 1) {
                    continue 2;
                }
                $missing = [];
                if ($optional !== []) {
                    $sample = $fetched['sample'];
                    foreach ($optional as $field) {
                        if (!is_array($sample) || !array_key_exists($field, $sample)) {
                            $missing[] = $field;
                        }
                    }
                }

                return [
                    'count' => $fetched['count'],
                    'optional_fields' => $missing === [] && $optional !== [],
                    'missing_optional' => $missing,
                    'page_size_fallback' => $pageSize === 0 && CONSUS_ODATA_PAGE_SIZE > 0 && $pageSizeIndex > 0,
                ];
            } catch (Throwable $error) {
                $message = $error->getMessage();
                if (
                    str_contains($message, 'Tijdelijk OData-bestand bevat een onleesbare regel.')
                    || str_contains($message, 'Tijdelijk OData-bestand is onvolledig.')
                ) {
                    throw $error;
                }
                $lastError = $error;
                $morePageSizes = $pageSizeIndex < count($pageSizes) - 1;
                if ($morePageSizes && consus_odata_error_is_page_size($error)) {
                    continue;
                }
                break;
            }
        }
    }

    throw new RuntimeException(
        $entitySet . ' voor ' . $company . ' mislukt: ' . ($lastError ? $lastError->getMessage() : 'onbekend')
    );
}

function consus_odata_error_allows_entry_type_fallback(Throwable $error): bool
{
    $message = $error->getMessage();
    $lower = strtolower($message);
    if (str_contains($lower, 'is not an option')) {
        return true;
    }
    if (preg_match('/HTTP (400|501)\b/', $message) === 1) {
        return true;
    }

    return str_contains($message, 'MethodNotImplemented')
        || str_contains($lower, 'filterexpressie')
        || str_contains($lower, 'filter expression')
        || str_contains($lower, 'not supported')
        || str_contains($lower, 'niet ondersteund');
}

function consus_odata_error_is_page_size(Throwable $error): bool
{
    $lower = strtolower($error->getMessage());

    return str_contains($lower, 'page size')
        || str_contains($lower, 'pagesize')
        || str_contains($lower, 'max page')
        || str_contains($lower, 'maximum page')
        || str_contains($lower, 'paginagrootte');
}

/**
 * @return array{count:int,optional_fields:bool,missing_optional:array<int, string>,page_size_fallback:bool}
 */
function consus_fetch_ledger_query(
    string $company,
    string $entryType,
    string $fromDate,
    string $toDate,
    bool $includeAmount,
    string $documentPrefix,
    bool $includeDocument,
    callable $onRow,
    ?callable $onPage = null,
    ?callable $fetchRows = null
): array {
    $query = consus_ledger_query(
        [$entryType],
        $fromDate,
        $toDate,
        $documentPrefix,
        $includeAmount,
        $includeDocument
    );

    return consus_each_entity_rows(
        $company,
        CONSUS_LEDGER_ENTITY,
        consus_ledger_required_fields($includeAmount, $includeDocument || trim($documentPrefix) !== ''),
        CONSUS_LEDGER_OPTIONAL_FIELDS,
        (string) ($query['$filter'] ?? ''),
        $onRow,
        $fetchRows,
        $onPage
    );
}

/**
 * Probeert Entry_Type-bijschriften na elkaar. Het eerste verzoek dat BC accepteert wint.
 * Een geweigerd documentbereik probeert dezelfde Entry_Type zonder dat bereik;
 * PHP filtert het prefix dan alsnog. Een ander geweigerd filter probeert het volgende bijschrift.
 *
 * @param array<int, string> $entryTypes
 * @return array{count:int,optional_fields:bool,missing_optional:array<int, string>,page_size_fallback:bool,document_filter_rejected:bool}
 */
function consus_each_ledger_entry_type(
    string $company,
    array $entryTypes,
    string $fromDate,
    string $toDate,
    bool $includeAmount,
    string $documentPrefix,
    bool $includeDocument,
    callable $onRow,
    ?callable $onPage = null,
    ?callable $fetchRows = null
): array {
    $lastError = null;
    $documentPrefix = trim($documentPrefix);
    foreach ($entryTypes as $entryType) {
        $entryType = trim((string) $entryType);
        if ($entryType === '') {
            continue;
        }
        try {
            $result = consus_fetch_ledger_query(
                $company,
                $entryType,
                $fromDate,
                $toDate,
                $includeAmount,
                $documentPrefix,
                $includeDocument || $documentPrefix !== '',
                $onRow,
                $onPage,
                $fetchRows
            );
            $result['document_filter_rejected'] = false;

            return $result;
        } catch (Throwable $error) {
            if ($documentPrefix !== '' && consus_odata_error_allows_entry_type_fallback($error)) {
                try {
                    $result = consus_fetch_ledger_query(
                        $company,
                        $entryType,
                        $fromDate,
                        $toDate,
                        $includeAmount,
                        '',
                        true,
                        $onRow,
                        $onPage,
                        $fetchRows
                    );
                    $result['document_filter_rejected'] = true;

                    return $result;
                } catch (Throwable $withoutPrefix) {
                    $error = $withoutPrefix;
                }
            }
            $lastError = $error;
            if (!consus_odata_error_allows_entry_type_fallback($error)) {
                throw $error;
            }
        }
    }

    if ($lastError !== null) {
        throw $lastError;
    }

    throw new InvalidArgumentException('Geen Entry_Type geconfigureerd.');
}

/**
 * Voorraad van een ander bedrijf gaat per regel naar schijf. Die feiten blijven
 * niet naast het bedrijf dat nu geladen wordt in het geheugen staan.
 *
 * @param array<string, array{path:string,handle:resource}> $spills
 */
function consus_foreign_spill_write(array &$spills, string $target, array $row): void
{
    if (!isset($spills[$target])) {
        $path = sys_get_temp_dir() . '/consus-foreign-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.ndjson';
        consus_track_temp_file($path);
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            consus_release_temp_file($path);
            throw new RuntimeException('Tijdelijke voorraad kon niet worden weggeschreven.');
        }
        @chmod($path, 0600);
        $spills[$target] = [
            'path' => $path,
            'handle' => $handle,
        ];
    }

    $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || fwrite($spills[$target]['handle'], $encoded . "\n") === false) {
        throw new RuntimeException('Tijdelijke voorraad kon niet worden weggeschreven.');
    }
}

/**
 * @param array<string, array{path:string,handle:resource}> $spills
 * @return array<string, string>
 */
function consus_foreign_spill_finish(array &$spills, bool $discard): array
{
    $paths = [];
    foreach ($spills as $target => $spill) {
        $handle = $spill['handle'] ?? null;
        if (is_resource($handle)) {
            fclose($handle);
        }
        $path = (string) ($spill['path'] ?? '');
        if ($path === '') {
            continue;
        }
        if ($discard) {
            consus_release_temp_file($path);
            continue;
        }
        $paths[(string) $target] = $path;
    }
    $spills = [];

    return $paths;
}

function consus_apply_stock_ndjson(array &$items, string $path, string $sourceCompany): void
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Tijdelijke voorraad kon niet worden gelezen.');
    }

    try {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                consus_apply_stock_row($items, $row, $sourceCompany);
            }
        }
    } finally {
        fclose($handle);
    }
}

/**
 * @param array<string, mixed> $context
 */
function consus_page_progress(?callable $onProgress, array $context): ?callable
{
    if ($onProgress === null) {
        return null;
    }

    return static function (int $pages, int $rows) use ($onProgress, $context): void {
        $onProgress(array_merge($context, [
            'pages' => $pages,
            'rows' => $rows,
        ]));
    };
}

function consus_page_size_warning(string $entity): string
{
    return $entity . ': paginagrootte ' . CONSUS_ODATA_PAGE_SIZE . ' geweigerd, BC-standaard gebruikt.';
}

/**
 * Artikelposten per maand. Het venster blijft twaalf maanden; een maand
 * is alleen een kleinere BC-query. Een geweigerd documentbereik geldt voor
 * de resterende maanden van dit type.
 *
 * @param array<int, string> $entryTypes
 * @param array<string, array<string, mixed>> $items
 * @param array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string} $windows
 * @return array{count:int,missing_optional:array<int, string>,document_filter_rejected:bool,page_size_fallback:bool}
 */
function consus_collect_ledger(
    string $company,
    string $companyKey,
    array $entryTypes,
    string $kind,
    string $documentPrefix,
    bool $includeAmount,
    array &$items,
    array $windows,
    ?callable $onProgress = null
): array {
    $odataPrefix = trim($documentPrefix);
    $includeDocument = $odataPrefix !== '';
    $rejected = false;
    $pageSizeFallback = false;
    $missing = [];
    $count = 0;

    foreach (consus_ledger_date_chunks($windows) as $chunk) {
        $context = [
            'company' => $company,
            'company_key' => $companyKey,
            'step' => $kind === 'sales' ? 'verkoop' : 'verbruik',
            'entry_type' => implode(', ', $entryTypes),
            'from' => $chunk['from'],
            'to' => $chunk['to'],
            'pages' => 0,
            'rows' => 0,
        ];
        if ($onProgress !== null) {
            $onProgress($context);
        }

        $result = consus_each_ledger_entry_type(
            $company,
            $entryTypes,
            $chunk['from'],
            $chunk['to'],
            $includeAmount,
            $odataPrefix,
            $includeDocument,
            static function (array $row) use (&$items, $companyKey, $windows, $kind, $documentPrefix): void {
                if (!consus_document_no_has_prefix($row, $documentPrefix)) {
                    return;
                }
                consus_apply_ledger_row($items, $row, $companyKey, $kind, $windows);
            },
            consus_page_progress($onProgress, $context)
        );
        $count += (int) ($result['count'] ?? 0);
        if (!empty($result['document_filter_rejected'])) {
            $rejected = true;
            $odataPrefix = '';
        }
        if (!empty($result['page_size_fallback'])) {
            $pageSizeFallback = true;
        }
        foreach ($result['missing_optional'] ?? [] as $field) {
            $field = (string) $field;
            if ($field !== '' && !in_array($field, $missing, true)) {
                $missing[] = $field;
            }
        }
    }

    return [
        'count' => $count,
        'missing_optional' => $missing,
        'document_filter_rejected' => $rejected,
        'page_size_fallback' => $pageSizeFallback,
    ];
}

/**
 * @param array<string, array<string, mixed>> $items
 * @param array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string} $windows
 * @return array{warnings:array<int, string>,foreign_spills:array<string, string>}
 */
function consus_collect_company(
    string $company,
    string $companyKey,
    array &$items,
    array $windows,
    ?callable $onProgress = null
): array {
    $warnings = [];
    $foreignSpills = [];
    $note = static function (array $extra) use ($onProgress, $company, $companyKey): void {
        if ($onProgress === null) {
            return;
        }
        $onProgress(array_merge([
            'company' => $company,
            'company_key' => $companyKey,
            'pages' => 0,
            'rows' => 0,
        ], $extra));
    };

    try {
        $note(['step' => 'voorraad', 'entry_type' => CONSUS_STOCK_ENTITY]);
        $stockResult = consus_each_entity_rows(
            $company,
            CONSUS_STOCK_ENTITY,
            CONSUS_STOCK_FIELDS,
            CONSUS_STOCK_OPTIONAL_FIELDS,
            '',
            static function (array $row) use (&$items, &$foreignSpills, $company, $companyKey): void {
                $target = consus_stock_company_key($row, $company);
                if ($target === '' || $target === $companyKey) {
                    consus_apply_stock_row($items, $row, $company);
                    return;
                }
                consus_foreign_spill_write($foreignSpills, $target, $row);
            },
            null,
            consus_page_progress($onProgress, [
                'company' => $company,
                'company_key' => $companyKey,
                'step' => 'voorraad',
                'entry_type' => CONSUS_STOCK_ENTITY,
            ])
        );
        if (($stockResult['missing_optional'] ?? []) !== []) {
            $warnings[] = CONSUS_STOCK_ENTITY . ': locatieveld ontbreekt (' . implode(', ', $stockResult['missing_optional']) . '). Voorraad blijft zonder locatie; niets wordt weggefilterd.';
        }
        if (!empty($stockResult['page_size_fallback'])) {
            $warnings[] = consus_page_size_warning(CONSUS_STOCK_ENTITY);
        }

        $salesResult = consus_collect_ledger(
            $company,
            $companyKey,
            CONSUS_SALES_ENTRY_TYPES,
            'sales',
            '',
            true,
            $items,
            $windows,
            $onProgress
        );
        if (($salesResult['missing_optional'] ?? []) !== []) {
            $warnings[] = 'Verkoop: inkoopvelden ontbreken op ' . CONSUS_LEDGER_ENTITY . ' (' . implode(', ', $salesResult['missing_optional']) . '). Die regels vallen in eigen tot de veldnamen in consus_config.php kloppen.';
        }
        if (!empty($salesResult['page_size_fallback'])) {
            $warnings[] = consus_page_size_warning(CONSUS_LEDGER_ENTITY);
        }

        foreach (consus_wo_ledger_parts() as $part) {
            $prefix = (string) $part['document_prefix'];
            $consumptionResult = consus_collect_ledger(
                $company,
                $companyKey,
                $part['entry_types'],
                'consumption',
                $prefix,
                false,
                $items,
                $windows,
                $onProgress
            );
            if (!empty($consumptionResult['document_filter_rejected']) && $prefix !== '') {
                $warnings[] = 'Werkorderfilter op documentnummer wordt door BC geweigerd. Negatieve correcties worden volledig opgehaald en lokaal op prefix ' . $prefix . ' gefilterd.';
            }
            if (!empty($consumptionResult['page_size_fallback'])) {
                $warnings[] = consus_page_size_warning(CONSUS_LEDGER_ENTITY);
            }
        }

        try {
            $note(['step' => 'artikelen', 'entry_type' => CONSUS_ITEM_ENTITY]);
            $vendorResult = consus_each_entity_rows(
                $company,
                CONSUS_ITEM_ENTITY,
                CONSUS_ITEM_FIELDS,
                CONSUS_ITEM_OPTIONAL_FIELDS,
                '',
                static function (array $row) use (&$items, $companyKey): void {
                    consus_apply_vendor_row($items, $row, $companyKey);
                },
                null,
                consus_page_progress($onProgress, [
                    'company' => $company,
                    'company_key' => $companyKey,
                    'step' => 'artikelen',
                    'entry_type' => CONSUS_ITEM_ENTITY,
                ])
            );
            if ($vendorResult['optional_fields'] === false && CONSUS_ITEM_OPTIONAL_FIELDS !== []) {
                $warnings[] = CONSUS_ITEM_ENTITY . ': optionele velden (' . implode(', ', CONSUS_ITEM_OPTIONAL_FIELDS) . ') niet beschikbaar.';
            }
            if (!empty($vendorResult['page_size_fallback'])) {
                $warnings[] = consus_page_size_warning(CONSUS_ITEM_ENTITY);
            }
        } catch (Throwable $error) {
            $warnings[] = 'Artikelen (leverancier) niet geladen: ' . $error->getMessage();
        }

        try {
            $note(['step' => 'kostenplaats', 'entry_type' => CONSUS_DIMENSION_ENTITY]);
            $dimensionQuery = consus_dimension_query();
            $dimensionResult = consus_each_entity_rows(
                $company,
                CONSUS_DIMENSION_ENTITY,
                CONSUS_DIMENSION_FIELDS,
                [],
                (string) ($dimensionQuery['$filter'] ?? ''),
                static function (array $row) use (&$items, $companyKey): void {
                    consus_apply_dimension_row($items, $row, $companyKey);
                },
                null,
                consus_page_progress($onProgress, [
                    'company' => $company,
                    'company_key' => $companyKey,
                    'step' => 'kostenplaats',
                    'entry_type' => CONSUS_DIMENSION_ENTITY,
                ])
            );
            if (!empty($dimensionResult['page_size_fallback'])) {
                $warnings[] = consus_page_size_warning(CONSUS_DIMENSION_ENTITY);
            }
        } catch (Throwable $error) {
            $warnings[] = 'Kostenplaats (dimensie ' . CONSUS_COST_CENTER_DIMENSION_CODE . ') niet geladen: ' . $error->getMessage();
        }

        return [
            'warnings' => array_values(array_unique($warnings)),
            'foreign_spills' => consus_foreign_spill_finish($foreignSpills, false),
        ];
    } catch (Throwable $error) {
        consus_foreign_spill_finish($foreignSpills, true);
        throw $error;
    }
}

/**
 * Verse rollup-rijen, daarna de oude rijen van bedrijven die stale bleven.
 * Stale rijen blijven achteraan, in de volgorde van de vorige snapshot.
 *
 * @param array<string, array<int, array<string, mixed>>> $freshRows
 * @param array<string, bool> $staleKeys
 * @param array<string, array<int, array<string, mixed>>> $previousRows
 * @return array<int, array<string, mixed>>
 */
function consus_combine_snapshot_rows(array $freshRows, array $staleKeys, array $previousRows): array
{
    $rows = [];
    foreach ($freshRows as $key => $companyRows) {
        if (isset($staleKeys[(string) $key]) || !is_array($companyRows)) {
            continue;
        }
        foreach ($companyRows as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }
    usort($rows, consus_compare_snapshot_rows(...));

    foreach (array_keys($staleKeys) as $key) {
        $oldRows = $previousRows[(string) $key] ?? [];
        if (!is_array($oldRows)) {
            continue;
        }
        foreach ($oldRows as $oldRow) {
            if (is_array($oldRow)) {
                $rows[] = $oldRow;
            }
        }
    }

    return $rows;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{vendors:array<int, array{vendor_no:string,vendor_name:string}>,cost_centers:array<int, string>,locations:array<int, string>}
 */
function consus_catalog_from_rows(array $rows): array
{
    $vendors = [];
    $costCenters = [];
    $locations = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $vendorNo = (string) ($row['vendor_no'] ?? '');
        if (!isset($vendors[$vendorNo])) {
            $vendors[$vendorNo] = [
                'vendor_no' => $vendorNo,
                'vendor_name' => (string) ($row['vendor_name'] ?? ''),
            ];
        }
        $costCenter = trim((string) ($row['cost_center'] ?? ''));
        if ($costCenter !== '') {
            $costCenters[$costCenter] = $costCenter;
        }
        $location = strtoupper(trim((string) ($row['location'] ?? '')));
        if ($location !== '') {
            $locations[$location] = $location;
        }
    }
    $vendorList = array_values($vendors);
    usort($vendorList, static function (array $left, array $right): int {
        return strnatcasecmp((string) $left['vendor_name'], (string) $right['vendor_name']);
    });
    $costCenterList = array_values($costCenters);
    natcasesort($costCenterList);
    $locationList = array_values($locations);
    natcasesort($locationList);

    return [
        'vendors' => $vendorList,
        'cost_centers' => array_values($costCenterList),
        'locations' => array_values($locationList),
    ];
}

function consus_previous_rows_for_company(array $snapshot, string $companyKey): array
{
    $rows = [];
    foreach ($snapshot['rows'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['company_key'] ?? '') === $companyKey) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function consus_progress_file(): string
{
    return consus_snapshot_file() . '.progress.json';
}

function consus_write_progress(array $progress): void
{
    $progress['updated_at'] = gmdate('c');
    $json = json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }

    $path = consus_progress_file();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }

    $temporary = $path . '.tmp.' . getmypid();
    if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
        return;
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);

        return;
    }

    if (empty($GLOBALS['consus_progress_echo'])) {
        return;
    }

    $from = (string) ($progress['from'] ?? '');
    $to = (string) ($progress['to'] ?? '');
    $range = $from !== '' ? ' ' . $from . ' tot ' . $to : '';
    fwrite(STDOUT, sprintf(
        "  … %s / %s%s pagina's=%d regels=%d\n",
        (string) ($progress['company'] ?? ''),
        (string) ($progress['step'] ?? ''),
        $range,
        (int) ($progress['pages'] ?? 0),
        (int) ($progress['rows'] ?? 0)
    ));
}

/**
 * Zelfde as_of, hetzelfde historievenster en een geslaagde refresh van vandaag.
 * Een oudere snapshotversie telt niet: dan ontbreekt refreshed_on of de query is veranderd.
 *
 * @param array<string, mixed> $snapshot
 * @param array{as_of:string,history_start:string} $windows
 */
function consus_company_refresh_is_current(array $snapshot, string $companyKey, array $windows): bool
{
    if ((int) ($snapshot['version'] ?? 0) !== CONSUS_SNAPSHOT_VERSION) {
        return false;
    }
    if ((string) ($snapshot['as_of'] ?? '') !== (string) ($windows['as_of'] ?? '')) {
        return false;
    }

    $previousWindows = is_array($snapshot['windows'] ?? null) ? $snapshot['windows'] : [];
    if ((string) ($previousWindows['history_start'] ?? '') !== (string) ($windows['history_start'] ?? '')) {
        return false;
    }

    foreach ($snapshot['companies'] ?? [] as $stat) {
        if (!is_array($stat) || (string) ($stat['company_key'] ?? '') !== $companyKey) {
            continue;
        }
        if (!empty($stat['stale'])) {
            return false;
        }

        return (string) ($stat['refreshed_on'] ?? '') === (string) ($windows['as_of'] ?? '');
    }

    return false;
}

/**
 * @param array<int, array<string, mixed>> $stats
 * @return array<string, mixed>|null
 */
function consus_find_company_stat(array $stats, string $companyKey): ?array
{
    foreach ($stats as $stat) {
        if (is_array($stat) && (string) ($stat['company_key'] ?? '') === $companyKey) {
            return $stat;
        }
    }

    return null;
}

/**
 * @param array<int, array<string, mixed>> $companyStats
 * @param array<int, mixed> $previousStats
 * @return array<int, array<string, mixed>>
 */
function consus_company_stats_for_publish(array $companyStats, array $previousStats): array
{
    $byKey = [];
    foreach ($companyStats as $stat) {
        if (!is_array($stat)) {
            continue;
        }
        $key = (string) ($stat['company_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $byKey[$key] = $stat;
    }
    foreach ($previousStats as $stat) {
        if (!is_array($stat)) {
            continue;
        }
        $key = (string) ($stat['company_key'] ?? '');
        if ($key === '' || isset($byKey[$key])) {
            continue;
        }
        $byKey[$key] = $stat;
    }

    $ordered = [];
    foreach (array_keys(CONSUS_COMPANIES) as $key) {
        if (isset($byKey[$key])) {
            $ordered[] = $byKey[$key];
        }
    }

    return $ordered;
}

/**
 * Verse rijen van bedrijven die in deze run al klaar zijn, plus de vorige
 * rijen van bedrijven die nog niet (opnieuw) geladen zijn.
 *
 * @param array<string, array<int, array<string, mixed>>> $freshRows
 * @param array<string, array<int, array<string, mixed>>> $previousRows
 * @return array<int, array<string, mixed>>
 */
function consus_rows_keeping_unfetched(array $freshRows, array $previousRows): array
{
    $keep = [];
    foreach ($previousRows as $key => $rows) {
        if (!isset($freshRows[$key])) {
            $keep[(string) $key] = true;
        }
    }

    return consus_combine_snapshot_rows($freshRows, $keep, $previousRows);
}

/**
 * Zolang een bedrijf nog niet in deze run klaar is, blijft de pagina dat zien.
 * Een echte fout (stale) wordt niet nog eens als "bezig" gemeld.
 *
 * @param array<int, array<string, mixed>> $companyStats
 * @param array<string, array<int, array<string, mixed>>> $freshRows
 * @param array<int, array<string, mixed>> $errors
 * @return array<int, array<string, mixed>>
 */
function consus_running_company_errors(array $companyStats, array $freshRows, array $errors): array
{
    foreach (array_keys(CONSUS_COMPANIES) as $key) {
        if (isset($freshRows[$key])) {
            continue;
        }
        $failed = false;
        foreach ($companyStats as $stat) {
            if (is_array($stat) && (string) ($stat['company_key'] ?? '') === $key && !empty($stat['stale'])) {
                $failed = true;
                break;
            }
        }
        if ($failed) {
            continue;
        }
        $errors[] = [
            'company' => consus_company_display_name((string) $key),
            'error' => 'Nachtelijke verversing is nog bezig.',
        ];
    }

    return $errors;
}

/**
 * @param array<string, mixed> $windows
 * @param array<int, array<string, mixed>> $companyStats
 * @param array<int, array<string, mixed>> $errors
 * @param array<int, array<string, mixed>> $warnings
 * @param array<string, array<int, array<string, mixed>>> $freshRows
 * @param array<string, array<int, array<string, mixed>>> $previousRows
 * @param array<int, mixed> $previousStats
 */
function consus_publish_nightly_snapshot(
    array $windows,
    array $companyStats,
    array $errors,
    array $warnings,
    array $freshRows,
    array $previousRows,
    array $previousStats,
    bool $running = false
): array {
    if ($running) {
        $errors = consus_running_company_errors($companyStats, $freshRows, $errors);
    }
    $rows = consus_rows_keeping_unfetched($freshRows, $previousRows);
    $catalog = consus_catalog_from_rows($rows);
    $snapshot = [
        'version' => CONSUS_SNAPSHOT_VERSION,
        'generated_at' => gmdate('c'),
        'as_of' => $windows['as_of'],
        'windows' => $windows,
        'companies' => consus_company_stats_for_publish($companyStats, $previousStats),
        'errors' => $errors,
        'warnings' => $warnings,
        'vendors' => $catalog['vendors'],
        'cost_centers' => $catalog['cost_centers'],
        'locations' => $catalog['locations'],
        'rows' => $rows,
    ];
    consus_with_snapshot_lock(static function () use ($snapshot): void {
        consus_write_snapshot($snapshot);
    });

    return $snapshot;
}

function consus_run_nightly(bool $force = false): array
{
    $discovered = auth_discover_companies_across_active_environments();
    $names = is_array($discovered['companies'] ?? null) ? $discovered['companies'] : [];
    $companies = consus_companies_in_scope($names);
    if ($companies === []) {
        throw new RuntimeException('Geen KVT- of HVT-bedrijf gevonden. Controleer CONSUS_COMPANIES en auth.php.');
    }

    $windows = consus_period_windows();
    $previous = consus_read_snapshot();
    $previousRows = [];
    foreach (array_keys(CONSUS_COMPANIES) as $key) {
        $previousRows[(string) $key] = consus_previous_rows_for_company($previous, (string) $key);
    }
    $previousStats = is_array($previous['companies'] ?? null) ? $previous['companies'] : [];
    $resumeSnapshot = [
        'version' => $previous['version'] ?? 0,
        'as_of' => $previous['as_of'] ?? '',
        'windows' => is_array($previous['windows'] ?? null) ? $previous['windows'] : [],
        'companies' => $previousStats,
    ];
    unset($previous);

    $freshRows = [];
    $foreignSpills = [];
    $companyStats = [];
    $errors = [];
    $warnings = [];
    $missingCompanies = consus_missing_company_records(
        $companies,
        is_array($discovered['errors'] ?? null) ? $discovered['errors'] : []
    );
    foreach ($missingCompanies['errors'] as $error) {
        $errors[] = $error;
    }
    foreach ($missingCompanies['company_stats'] as $stat) {
        $companyStats[] = $stat;
    }

    $publish = static function (bool $running) use (
        &$windows,
        &$companyStats,
        &$errors,
        &$warnings,
        &$freshRows,
        &$previousRows,
        &$previousStats
    ): array {
        return consus_publish_nightly_snapshot(
            $windows,
            $companyStats,
            $errors,
            $warnings,
            $freshRows,
            $previousRows,
            $previousStats,
            $running
        );
    };

    foreach ($companies as $companyInfo) {
        $startedAt = hrtime(true);
        $company = (string) $companyInfo['company'];
        $companyKey = (string) $companyInfo['company_key'];
        if (!$force && consus_company_refresh_is_current($resumeSnapshot, $companyKey, $windows)) {
            $freshRows[$companyKey] = $previousRows[$companyKey] ?? [];
            foreach ($foreignSpills[$companyKey] ?? [] as $path) {
                if (is_string($path)) {
                    consus_release_temp_file($path);
                }
            }
            unset($foreignSpills[$companyKey]);
            $existing = consus_find_company_stat($previousStats, $companyKey);
            $companyStats[] = [
                'company' => $company,
                'company_key' => $companyKey,
                'stale' => false,
                'resumed' => true,
                'refreshed_on' => $windows['as_of'],
                'duration_ms' => (int) ($existing['duration_ms'] ?? 0),
            ];
            consus_write_progress([
                'company' => $company,
                'company_key' => $companyKey,
                'step' => 'overgeslagen',
                'pages' => 0,
                'rows' => count($freshRows[$companyKey]),
            ]);
            $publish(true);
            continue;
        }

        $localItems = [];
        try {
            $result = consus_collect_company(
                $company,
                $companyKey,
                $localItems,
                $windows,
                static function (array $progress): void {
                    consus_write_progress($progress);
                }
            );
            $rolled = consus_rollup_items($localItems, $windows);
            $localItems = [];
            $freshRows[$companyKey] = $rolled['rows'];
            unset($rolled);
            foreach ($foreignSpills[$companyKey] ?? [] as $path) {
                if (is_string($path)) {
                    consus_release_temp_file($path);
                }
            }
            unset($foreignSpills[$companyKey]);
            $addedSpills = $result['foreign_spills'] ?? [];
            if (is_array($addedSpills)) {
                foreach ($addedSpills as $target => $path) {
                    if (!is_string($path) || $path === '') {
                        continue;
                    }
                    $foreignSpills[(string) $target][] = $path;
                }
            }
            gc_collect_cycles();
            foreach ($result['warnings'] as $warning) {
                $warnings[] = ['company' => $company, 'warning' => $warning];
            }
            $companyStats[] = [
                'company' => $company,
                'company_key' => $companyKey,
                'stale' => false,
                'resumed' => false,
                'refreshed_on' => $windows['as_of'],
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ];
            $publish(true);
        } catch (Throwable $error) {
            $localItems = [];
            gc_collect_cycles();
            $errors[] = ['company' => $company, 'error' => $error->getMessage()];
            $companyStats[] = [
                'company' => $company,
                'company_key' => $companyKey,
                'stale' => true,
                'resumed' => false,
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ];
            $publish(true);
        }
    }

    $staleKeys = [];
    foreach ($companyStats as $stat) {
        if (!empty($stat['stale'])) {
            $staleKeys[(string) ($stat['company_key'] ?? '')] = true;
        }
    }

    foreach (array_keys($staleKeys) as $key) {
        $key = (string) $key;
        $paths = $foreignSpills[$key] ?? [];
        if (!is_array($paths)) {
            $paths = [];
        }
        if (($previousRows[$key] ?? []) !== [] || $paths === []) {
            foreach ($paths as $path) {
                if (is_string($path)) {
                    consus_release_temp_file($path);
                }
            }
            unset($foreignSpills[$key]);
            continue;
        }

        // Geen eigen nachtrun en geen vorige cache: gebruik voorraad die een
        // ander bedrijf via VoorraadPerBedrijf al meegaf. Verkoop ontbreekt dan.
        $foreignOnly = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            consus_apply_stock_ndjson($foreignOnly, $path, consus_company_display_name($key));
            consus_release_temp_file($path);
        }
        unset($foreignSpills[$key]);
        $rolled = consus_rollup_items($foreignOnly, $windows);
        unset($foreignOnly);
        $freshRows[$key] = $rolled['rows'];
        unset($rolled);
        $warnings[] = [
            'company' => $key,
            'warning' => 'Alleen voorraad uit een andere bedrijfsquery. Verkoop en verbruik ontbreken tot dit bedrijf zelf geladen kan worden.',
        ];
        unset($staleKeys[$key]);
    }
    foreach ($foreignSpills as $paths) {
        if (!is_array($paths)) {
            continue;
        }
        foreach ($paths as $path) {
            if (is_string($path)) {
                consus_release_temp_file($path);
            }
        }
    }
    unset($foreignSpills, $staleKeys);

    $snapshot = $publish(false);
    unset($freshRows, $previousRows);
    consus_write_progress([
        'company' => '',
        'company_key' => '',
        'step' => 'klaar',
        'pages' => 0,
        'rows' => count($snapshot['rows'] ?? []),
    ]);

    return $snapshot;
}
