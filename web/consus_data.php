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

function consus_bucket_for_location(string $locationCode): string
{
    $code = strtoupper(trim($locationCode));
    if ($code === '') {
        return 'onbekend';
    }

    $bucket = CONSUS_LOCATION_BUCKETS[$code] ?? '';
    if (!isset(CONSUS_BUCKETS[$bucket])) {
        return 'onbekend';
    }

    return $bucket;
}

/**
 * @param array<int, string> $entryTypes
 */
function consus_entry_type_filter(array $entryTypes): string
{
    $parts = [];
    foreach ($entryTypes as $entryType) {
        $entryType = trim((string) $entryType);
        if ($entryType === '') {
            continue;
        }
        $parts[] = "Entry_Type eq '" . consus_escape_odata_string($entryType) . "'";
    }

    if ($parts === []) {
        throw new InvalidArgumentException('Geen Entry_Type geconfigureerd.');
    }

    if (count($parts) === 1) {
        return $parts[0];
    }

    return '(' . implode(' or ', $parts) . ')';
}

/**
 * @param array<int, string> $entryTypes
 * @return array<string, string>
 */
function consus_ledger_query(array $entryTypes, string $fromDate): array
{
    $fromDate = consus_parse_date($fromDate);
    if ($fromDate === '') {
        throw new InvalidArgumentException('Ongeldige vanaf-datum voor artikelposten.');
    }

    return consus_entity_query(
        CONSUS_LEDGER_FIELDS,
        consus_entry_type_filter($entryTypes) . ' and Posting_Date ge ' . $fromDate
    );
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
function consus_entity_query(array $fields, string $filter = ''): array
{
    $query = [
        '$select' => implode(',', $fields),
    ];
    if ($filter !== '') {
        $query['$filter'] = $filter;
    }

    return $query;
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
        'sales' => consus_empty_bucket_map(),
        'consumption' => consus_empty_bucket_map(),
    ];
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
        $items[$key]['inventory'] = consus_scalar_float($row['Inventory'] ?? 0);
        $items[$key]['safety_stock'] = consus_scalar_float($row['Safety_Stock_Quantity'] ?? 0);
        $items[$key]['reorder_point'] = consus_scalar_float($row['Reorder_Point'] ?? 0);
        return;
    }

    // Zelfde artikel kan via een geconsolideerde VoorraadPerBedrijf twee keer
    // binnenkomen. Eerste niet-nulvoorraad wint; we tellen niet dubbel.
    if ((float) $items[$key]['inventory'] === 0.0) {
        $items[$key]['inventory'] = consus_scalar_float($row['Inventory'] ?? 0);
    }
    if ((float) $items[$key]['safety_stock'] === 0.0) {
        $items[$key]['safety_stock'] = consus_scalar_float($row['Safety_Stock_Quantity'] ?? 0);
    }
    if ((float) $items[$key]['reorder_point'] === 0.0) {
        $items[$key]['reorder_point'] = consus_scalar_float($row['Reorder_Point'] ?? 0);
    }
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
 * @param array<string, true> $unmapped
 */
function consus_apply_ledger_row(array &$items, array $row, string $companyKey, string $kind, array $windows, array &$unmapped): void
{
    if ($companyKey === '' || ($kind !== 'sales' && $kind !== 'consumption')) {
        return;
    }

    $itemNo = consus_scalar_string($row['Item_No'] ?? '');
    $date = consus_parse_date($row['Posting_Date'] ?? '');
    if ($itemNo === '' || $date === '') {
        return;
    }

    $location = consus_scalar_string($row['Location_Code'] ?? '');
    $bucket = consus_bucket_for_location($location);
    if ($bucket === 'onbekend' && trim($location) !== '') {
        $unmapped[strtoupper(trim($location))] = true;
    }

    $key = $companyKey . '|' . $itemNo;
    if (!isset($items[$key])) {
        $items[$key] = consus_new_item_fact($companyKey, $itemNo);
    }

    $qty = consus_outbound_quantity($row['Quantity'] ?? 0);
    $amount = $kind === 'sales' ? consus_scalar_float($row['Sales_Amount_Actual'] ?? 0) : 0.0;
    consus_add_to_period_stats($items[$key][$kind][$bucket], $date, $qty, $amount, $windows);
}

/**
 * @param array<string, array<string, mixed>> $items
 * @param array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string} $windows
 * @param array<int, string> $unmappedLocations
 * @return array{rows:array<int, array<string, mixed>>,vendors:array<int, array{vendor_no:string,vendor_name:string}>,cost_centers:array<int, string>}
 */
function consus_rollup_items(array $items, array $windows, array $unmappedLocations = []): array
{
    unset($windows);
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
        $groupKey = $companyKey . '|' . $vendorNo . '|' . $costCenter;
        if (!isset($grouped[$groupKey])) {
            $grouped[$groupKey] = [
                'company_key' => $companyKey,
                'company_name' => consus_company_display_name($companyKey, (string) ($item['company_name'] ?? '')),
                'vendor_no' => $vendorNo,
                'vendor_name' => $vendorName,
                'cost_center' => $costCenter,
                'inventory' => 0.0,
                'safety_stock' => 0.0,
                'reorder_point' => 0.0,
                'item_count' => 0,
                'sales' => consus_empty_bucket_map(),
                'consumption' => consus_empty_bucket_map(),
            ];
        }

        if (strlen($vendorName) > strlen((string) $grouped[$groupKey]['vendor_name'])) {
            $grouped[$groupKey]['vendor_name'] = $vendorName;
        }
        $grouped[$groupKey]['inventory'] += (float) ($item['inventory'] ?? 0);
        $grouped[$groupKey]['safety_stock'] += (float) ($item['safety_stock'] ?? 0);
        $grouped[$groupKey]['reorder_point'] += (float) ($item['reorder_point'] ?? 0);
        $grouped[$groupKey]['item_count']++;
        consus_merge_bucket_maps($grouped[$groupKey]['sales'], is_array($item['sales'] ?? null) ? $item['sales'] : []);
        consus_merge_bucket_maps($grouped[$groupKey]['consumption'], is_array($item['consumption'] ?? null) ? $item['consumption'] : []);
    }

    $rows = array_values($grouped);
    usort($rows, static function (array $left, array $right): int {
        return strnatcasecmp(
            implode('|', [(string) $left['company_key'], (string) $left['vendor_name'], (string) $left['cost_center']]),
            implode('|', [(string) $right['company_key'], (string) $right['vendor_name'], (string) $right['cost_center']])
        );
    });

    $vendors = [];
    $costCenters = [];
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
    }

    $vendorList = array_values($vendors);
    usort($vendorList, static function (array $left, array $right): int {
        return strnatcasecmp((string) $left['vendor_name'], (string) $right['vendor_name']);
    });
    $costCenterList = array_values($costCenters);
    natcasesort($costCenterList);

    return [
        'rows' => $rows,
        'vendors' => $vendorList,
        'cost_centers' => array_values($costCenterList),
        'unmapped_locations' => array_values($unmappedLocations),
    ];
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
function consus_summarize(array $snapshot, string $companyKey, string $vendorNo, string $costCenter): array
{
    $sales = consus_empty_bucket_map();
    $consumption = consus_empty_bucket_map();
    $inventory = 0.0;
    $safety = 0.0;
    $reorder = 0.0;
    $itemCount = 0;

    foreach ($snapshot['rows'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($companyKey !== '' && (string) ($row['company_key'] ?? '') !== $companyKey) {
            continue;
        }
        if ($vendorNo !== '' && (string) ($row['vendor_no'] ?? '') !== $vendorNo) {
            continue;
        }
        if ($costCenter !== '' && (string) ($row['cost_center'] ?? '') !== $costCenter) {
            continue;
        }

        $inventory += (float) ($row['inventory'] ?? 0);
        $safety += (float) ($row['safety_stock'] ?? 0);
        $reorder += (float) ($row['reorder_point'] ?? 0);
        $itemCount += (int) ($row['item_count'] ?? 0);
        consus_merge_bucket_maps($sales, is_array($row['sales'] ?? null) ? $row['sales'] : []);
        consus_merge_bucket_maps($consumption, is_array($row['consumption'] ?? null) ? $row['consumption'] : []);
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
        'unmapped_locations' => [],
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

function consus_each_url_live(string $url, array $auth, callable $onRow): int
{
    $count = 0;
    $next = $url;
    $guard = 0;

    while ($next !== '') {
        $guard++;
        if ($guard > 100000) {
            throw new RuntimeException('OData-paginering stopte niet.');
        }

        $response = odata_get_json($next, $auth);
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

        $nextLink = $response['@odata.nextLink'] ?? '';
        $next = is_string($nextLink) ? $nextLink : '';
    }

    return $count;
}

/**
 * @param array<int, string> $required
 * @param array<int, string> $optional
 */
function consus_each_entity_rows(
    string $company,
    string $entitySet,
    array $required,
    array $optional,
    string $filter,
    callable $onRow
): array {
    global $baseUrl;

    $environment = auth_get_environment_for_company($company);
    $auth = auth_get_auth_for_environment($environment);
    $attempts = [];
    if ($optional !== []) {
        $attempts[] = array_values(array_unique(array_merge($required, $optional)));
    }
    $attempts[] = $required;

    $lastError = null;
    foreach ($attempts as $index => $fields) {
        $query = consus_entity_query($fields, $filter);
        $url = consus_company_entity_url((string) $baseUrl, $environment, $company, $entitySet, $query);
        $useOptionalAttempt = $index === 0 && $optional !== [];
        try {
            if ($useOptionalAttempt) {
                $buffered = [];
                $count = consus_each_url_live($url, $auth, static function (array $row) use (&$buffered): void {
                    $buffered[] = $row;
                });
                foreach ($buffered as $row) {
                    $onRow($row);
                }
            } else {
                $count = consus_each_url_live($url, $auth, $onRow);
            }

            return [
                'count' => $count,
                'optional_fields' => $useOptionalAttempt,
            ];
        } catch (Throwable $error) {
            $lastError = $error;
        }
    }

    throw new RuntimeException(
        $entitySet . ' voor ' . $company . ' mislukt: ' . ($lastError ? $lastError->getMessage() : 'onbekend')
    );
}

/**
 * @param array<string, array<string, mixed>> $items
 * @param array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string} $windows
 * @return array{warnings:array<int, string>,unmapped:array<string, true>,foreign:array<string, array<int, array<string, mixed>>>}
 */
function consus_collect_company(string $company, string $companyKey, array &$items, array $windows): array
{
    $warnings = [];
    $unmapped = [];
    $foreign = [];

    consus_each_entity_rows($company, CONSUS_STOCK_ENTITY, CONSUS_STOCK_FIELDS, [], '', static function (array $row) use (&$items, &$foreign, $company, $companyKey): void {
        $target = consus_stock_company_key($row, $company);
        if ($target === '' || $target === $companyKey) {
            consus_apply_stock_row($items, $row, $company);
            return;
        }
        $foreign[$target][] = $row;
    });

    $salesQuery = consus_ledger_query(CONSUS_SALES_ENTRY_TYPES, $windows['history_start']);
    consus_each_entity_rows(
        $company,
        CONSUS_LEDGER_ENTITY,
        CONSUS_LEDGER_FIELDS,
        [],
        (string) ($salesQuery['$filter'] ?? ''),
        static function (array $row) use (&$items, $companyKey, $windows, &$unmapped): void {
            consus_apply_ledger_row($items, $row, $companyKey, 'sales', $windows, $unmapped);
        }
    );

    $consumptionQuery = consus_ledger_query(CONSUS_WO_ENTRY_TYPES, $windows['history_start']);
    consus_each_entity_rows(
        $company,
        CONSUS_LEDGER_ENTITY,
        CONSUS_LEDGER_FIELDS,
        [],
        (string) ($consumptionQuery['$filter'] ?? ''),
        static function (array $row) use (&$items, $companyKey, $windows, &$unmapped): void {
            consus_apply_ledger_row($items, $row, $companyKey, 'consumption', $windows, $unmapped);
        }
    );

    try {
        $vendorResult = consus_each_entity_rows(
            $company,
            CONSUS_ITEM_ENTITY,
            CONSUS_ITEM_FIELDS,
            CONSUS_ITEM_OPTIONAL_FIELDS,
            '',
            static function (array $row) use (&$items, $companyKey): void {
                consus_apply_vendor_row($items, $row, $companyKey);
            }
        );
        if ($vendorResult['optional_fields'] === false && CONSUS_ITEM_OPTIONAL_FIELDS !== []) {
            $warnings[] = CONSUS_ITEM_ENTITY . ': optionele velden (' . implode(', ', CONSUS_ITEM_OPTIONAL_FIELDS) . ') niet beschikbaar.';
        }
    } catch (Throwable $error) {
        $warnings[] = 'Artikelen (leverancier) niet geladen: ' . $error->getMessage();
    }

    try {
        $dimensionQuery = consus_dimension_query();
        consus_each_entity_rows(
            $company,
            CONSUS_DIMENSION_ENTITY,
            CONSUS_DIMENSION_FIELDS,
            [],
            (string) ($dimensionQuery['$filter'] ?? ''),
            static function (array $row) use (&$items, $companyKey): void {
                consus_apply_dimension_row($items, $row, $companyKey);
            }
        );
    } catch (Throwable $error) {
        $warnings[] = 'Kostenplaats (dimensie ' . CONSUS_COST_CENTER_DIMENSION_CODE . ') niet geladen: ' . $error->getMessage();
    }

    return [
        'warnings' => $warnings,
        'unmapped' => $unmapped,
        'foreign' => $foreign,
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

function consus_run_nightly(): array
{
    $discovered = auth_discover_companies_across_active_environments();
    $names = is_array($discovered['companies'] ?? null) ? $discovered['companies'] : [];
    $companies = consus_companies_in_scope($names);
    if ($companies === []) {
        throw new RuntimeException('Geen KVT- of HVT-bedrijf gevonden. Controleer CONSUS_COMPANIES en auth.php.');
    }

    $windows = consus_period_windows();
    $items = [];
    $foreignStock = [];
    $companyStats = [];
    $errors = [];
    $warnings = [];
    $unmapped = [];
    $previous = consus_read_snapshot();

    foreach ($companies as $companyInfo) {
        $startedAt = hrtime(true);
        $company = (string) $companyInfo['company'];
        $companyKey = (string) $companyInfo['company_key'];
        $localItems = [];
        try {
            $result = consus_collect_company($company, $companyKey, $localItems, $windows);
            foreach ($localItems as $factKey => $fact) {
                $items[$factKey] = $fact;
            }
            foreach ($result['foreign'] as $otherKey => $stockRows) {
                foreach ($stockRows as $stockRow) {
                    $foreignStock[(string) $otherKey][] = $stockRow;
                }
            }
            foreach ($result['warnings'] as $warning) {
                $warnings[] = ['company' => $company, 'warning' => $warning];
            }
            foreach ($result['unmapped'] as $code => $unused) {
                unset($unused);
                $unmapped[$code] = $code;
            }
            $companyStats[] = [
                'company' => $company,
                'company_key' => $companyKey,
                'stale' => false,
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ];
        } catch (Throwable $error) {
            $errors[] = ['company' => $company, 'error' => $error->getMessage()];
            $companyStats[] = [
                'company' => $company,
                'company_key' => $companyKey,
                'stale' => true,
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ];
        }
    }

    $staleKeys = [];
    foreach ($companyStats as $stat) {
        if (!empty($stat['stale'])) {
            $staleKeys[(string) ($stat['company_key'] ?? '')] = true;
        }
    }

    foreach (array_keys($staleKeys) as $key) {
        if (consus_previous_rows_for_company($previous, $key) !== [] || !isset($foreignStock[$key])) {
            continue;
        }

        // Geen eigen nachtrun en geen vorige cache: gebruik voorraad die een
        // ander bedrijf via VoorraadPerBedrijf al meegaf. Verkoop ontbreekt dan.
        foreach ($foreignStock[$key] as $stockRow) {
            consus_apply_stock_row($items, $stockRow, consus_company_display_name($key));
        }
        $warnings[] = [
            'company' => $key,
            'warning' => 'Alleen voorraad uit een andere bedrijfsquery. Verkoop en verbruik ontbreken tot dit bedrijf zelf geladen kan worden.',
        ];
        unset($staleKeys[$key]);
    }

    $rolled = consus_rollup_items($items, $windows, array_values($unmapped));
    $rows = [];
    foreach ($rolled['rows'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = (string) ($row['company_key'] ?? '');
        if (isset($staleKeys[$key])) {
            continue;
        }
        $rows[] = $row;
    }

    foreach (array_keys($staleKeys) as $key) {
        foreach (consus_previous_rows_for_company($previous, $key) as $oldRow) {
            $rows[] = $oldRow;
        }
    }

    $vendors = [];
    $costCenters = [];
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
    }
    $vendorList = array_values($vendors);
    usort($vendorList, static function (array $left, array $right): int {
        return strnatcasecmp((string) $left['vendor_name'], (string) $right['vendor_name']);
    });
    $costCenterList = array_values($costCenters);
    natcasesort($costCenterList);

    natcasesort($unmapped);

    $snapshot = [
        'version' => CONSUS_SNAPSHOT_VERSION,
        'generated_at' => gmdate('c'),
        'as_of' => $windows['as_of'],
        'windows' => $windows,
        'companies' => $companyStats,
        'errors' => $errors,
        'warnings' => $warnings,
        'vendors' => $vendorList,
        'cost_centers' => array_values($costCenterList),
        'unmapped_locations' => array_values($unmapped),
        'rows' => $rows,
    ];

    consus_with_snapshot_lock(static function () use ($snapshot): void {
        consus_write_snapshot($snapshot);
    });

    return $snapshot;
}
