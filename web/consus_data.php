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

function consus_wo_entry_filter(): string
{
    $primaryType = consus_escape_odata_string(CONSUS_WO_PRIMARY_ENTRY_TYPE);
    $prefix = consus_escape_odata_string(CONSUS_WO_DOCUMENT_PREFIX);
    $parts = [
        "(Entry_Type eq '" . $primaryType . "' and startswith(Document_No,'" . $prefix . "'))",
    ];
    foreach (CONSUS_WO_ALSO_ENTRY_TYPES as $entryType) {
        $entryType = trim((string) $entryType);
        if ($entryType === '') {
            continue;
        }
        $parts[] = "Entry_Type eq '" . consus_escape_odata_string($entryType) . "'";
    }

    if (count($parts) === 1) {
        return $parts[0];
    }

    return '(' . implode(' or ', $parts) . ')';
}

function consus_wo_ledger_query(string $fromDate): array
{
    $fromDate = consus_parse_date($fromDate);
    if ($fromDate === '') {
        throw new InvalidArgumentException('Ongeldige vanaf-datum voor werkorderverbruik.');
    }

    return consus_entity_query(
        CONSUS_LEDGER_FIELDS,
        consus_wo_entry_filter() . ' and Posting_Date ge ' . $fromDate
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
        'by_location' => [],
    ];
}

/**
 * @param array<string, mixed> $item
 * @return array{inventory:float,safety_stock:float,reorder_point:float,sales:array,consumption:array}
 */
function consus_location_metrics(array &$item, string $location): array
{
    if (!isset($item['by_location'][$location]) || !is_array($item['by_location'][$location])) {
        $item['by_location'][$location] = [
            'inventory' => 0.0,
            'safety_stock' => 0.0,
            'reorder_point' => 0.0,
            'sales' => consus_empty_bucket_map(),
            'consumption' => consus_empty_bucket_map(),
        ];
    }

    return $item['by_location'][$location];
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

    consus_location_metrics($items[$key], $location);
    $qty = consus_outbound_quantity($row['Quantity'] ?? 0);
    $amount = $kind === 'sales' ? consus_scalar_float($row['Sales_Amount_Actual'] ?? 0) : 0.0;
    consus_add_to_period_stats($items[$key]['by_location'][$location][$kind][$bucket], $date, $qty, $amount, $windows);
}

/**
 * @param array<string, array<string, mixed>> $items
 * @param array{as_of:string,month_start:string,quarter_start:string,year_start:string,history_start:string} $windows
 * @param array<int, string> $unmappedLocations
 * @return array{rows:array<int, array<string, mixed>>,vendors:array<int, array{vendor_no:string,vendor_name:string}>,cost_centers:array<int, string>}
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
    usort($rows, static function (array $left, array $right): int {
        return strnatcasecmp(
            implode('|', [(string) $left['company_key'], (string) $left['vendor_name'], (string) $left['cost_center'], (string) $left['location']]),
            implode('|', [(string) $right['company_key'], (string) $right['vendor_name'], (string) $right['cost_center'], (string) $right['location']])
        );
    });

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
    $attemptCount = count($attempts);
    foreach ($attempts as $index => $fields) {
        $query = consus_entity_query($fields, $filter);
        $url = consus_company_entity_url((string) $baseUrl, $environment, $company, $entitySet, $query);
        $useOptionalAttempt = $index === 0 && $optional !== [];
        try {
            $buffered = [];
            $count = consus_each_url_live($url, $auth, static function (array $row) use (&$buffered): void {
                $buffered[] = $row;
            });
            if ($useOptionalAttempt && $count === 0 && $index < $attemptCount - 1) {
                continue;
            }
            foreach ($buffered as $row) {
                $onRow($row);
            }
            $missing = [];
            if ($optional !== []) {
                $sample = $buffered[0] ?? null;
                foreach ($optional as $field) {
                    if (!is_array($sample) || !array_key_exists($field, $sample)) {
                        $missing[] = $field;
                    }
                }
            }

            return [
                'count' => $count,
                'optional_fields' => $missing === [] && $optional !== [],
                'missing_optional' => $missing,
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
 * @return array{warnings:array<int, string>,foreign:array<string, array<int, array<string, mixed>>>}
 */
function consus_collect_company(string $company, string $companyKey, array &$items, array $windows): array
{
    $warnings = [];
    $foreign = [];

    $stockResult = consus_each_entity_rows(
        $company,
        CONSUS_STOCK_ENTITY,
        CONSUS_STOCK_FIELDS,
        CONSUS_STOCK_OPTIONAL_FIELDS,
        '',
        static function (array $row) use (&$items, &$foreign, $company, $companyKey): void {
            $target = consus_stock_company_key($row, $company);
            if ($target === '' || $target === $companyKey) {
                consus_apply_stock_row($items, $row, $company);
                return;
            }
            $foreign[$target][] = $row;
        }
    );
    if (($stockResult['missing_optional'] ?? []) !== []) {
        $warnings[] = CONSUS_STOCK_ENTITY . ': locatieveld ontbreekt (' . implode(', ', $stockResult['missing_optional']) . '). Voorraad blijft zonder locatie; niets wordt weggefilterd.';
    }

    $salesQuery = consus_ledger_query(CONSUS_SALES_ENTRY_TYPES, $windows['history_start']);
    $salesResult = consus_each_entity_rows(
        $company,
        CONSUS_LEDGER_ENTITY,
        CONSUS_LEDGER_FIELDS,
        CONSUS_LEDGER_OPTIONAL_FIELDS,
        (string) ($salesQuery['$filter'] ?? ''),
        static function (array $row) use (&$items, $companyKey, $windows): void {
            consus_apply_ledger_row($items, $row, $companyKey, 'sales', $windows);
        }
    );
    if (($salesResult['missing_optional'] ?? []) !== []) {
        $warnings[] = 'Verkoop: inkoopvelden ontbreken op ' . CONSUS_LEDGER_ENTITY . ' (' . implode(', ', $salesResult['missing_optional']) . '). Die regels vallen in eigen tot de veldnamen in consus_config.php kloppen.';
    }

    $consumptionQuery = consus_wo_ledger_query($windows['history_start']);
    consus_each_entity_rows(
        $company,
        CONSUS_LEDGER_ENTITY,
        CONSUS_LEDGER_FIELDS,
        CONSUS_LEDGER_OPTIONAL_FIELDS,
        (string) ($consumptionQuery['$filter'] ?? ''),
        static function (array $row) use (&$items, $companyKey, $windows): void {
            consus_apply_ledger_row($items, $row, $companyKey, 'consumption', $windows);
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
    $previous = consus_read_snapshot();
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

    $rolled = consus_rollup_items($items, $windows);
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
        'locations' => array_values($locationList),
        'rows' => $rows,
    ];

    consus_with_snapshot_lock(static function () use ($snapshot): void {
        consus_write_snapshot($snapshot);
    });

    return $snapshot;
}
