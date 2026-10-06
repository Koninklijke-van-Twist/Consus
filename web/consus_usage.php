<?php

require_once __DIR__ . '/consus_data.php';

/**
 * Kolommen van de jaartabel en van elk jaarblad in de xlsx, in deze volgorde.
 *
 * @return array<int, string>
 */
function consus_usage_column_labels(): array
{
    return [
        'Artikelnummer',
        'Veiligheidsvoorraad',
        'Totaal verbruik Jaar',
        'Januari',
        'Februari',
        'Maart',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Augustus',
        'September',
        'Oktober',
        'November',
        'December',
        'Kwartaal 1',
        'Kwartaal 2',
        'Kwartaal 3',
        'Kwartaal 4',
        'Intern Verbruik',
    ];
}

/**
 * @return array<int, string>
 */
function consus_usage_month_labels(): array
{
    return [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maart',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Augustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'December',
    ];
}

function consus_low_stock_legend(): string
{
    return 'Een geel artikelnummer betekent dat de huidige voorraad lager is dan het verwachte resterende verbruik. '
        . 'Verwacht resterend = gemiddeld jaarverbruik over de volledige voorgaande kalenderjaren × (dagen tot en met 31 december / dagen in het jaar). '
        . 'Uitgesloten klanten tellen niet mee in verbruik, gemiddelde, restant en deze markering.';
}

/**
 * Huidig jaar eerst, daarna de voorgaande jaren uit CONSUS_HISTORY_PREVIOUS_YEARS.
 *
 * @return array<int, int>
 */
function consus_history_years(string $asOf): array
{
    $asOf = consus_parse_date($asOf);
    if ($asOf === '') {
        $asOf = (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
    }
    $year = (int) substr($asOf, 0, 4);
    $previous = (int) CONSUS_HISTORY_PREVIOUS_YEARS;
    if ($previous < 0) {
        $previous = 0;
    }
    $years = [];
    for ($offset = 0; $offset <= $previous; $offset++) {
        $years[] = $year - $offset;
    }

    return $years;
}

/**
 * Een kalenderjaar telt mee voor het gemiddelde als het helemaal in het
 * grootboekvenster ligt en vóór het jaar van de peildatum valt.
 *
 * @param array{as_of?:string,history_start?:string} $windows
 */
function consus_year_is_complete(int $year, array $windows): bool
{
    $history = consus_parse_date($windows['history_start'] ?? '');
    $asOf = consus_parse_date($windows['as_of'] ?? '');
    if ($history === '' || $asOf === '' || $year < 1) {
        return false;
    }
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    if ($start < $history || $end > $asOf) {
        return false;
    }

    return $year < (int) substr($asOf, 0, 4);
}

/**
 * @param array{as_of?:string,history_start?:string} $windows
 * @return array<int, int>
 */
function consus_average_years(array $windows): array
{
    $asOf = consus_parse_date($windows['as_of'] ?? '');
    if ($asOf === '') {
        return [];
    }
    $year = (int) substr($asOf, 0, 4);
    $previous = (int) CONSUS_HISTORY_PREVIOUS_YEARS;
    if ($previous < 0) {
        $previous = 0;
    }
    $years = [];
    for ($offset = $previous; $offset >= 1; $offset--) {
        $candidate = $year - $offset;
        if (consus_year_is_complete($candidate, $windows)) {
            $years[] = $candidate;
        }
    }

    return $years;
}

/**
 * @param array<int, int> $years
 */
function consus_nl_year_list(array $years): string
{
    if ($years === []) {
        return 'geen historie';
    }
    $labels = [];
    foreach ($years as $year) {
        $labels[] = (string) $year;
    }
    if (count($labels) === 1) {
        return $labels[0];
    }
    $last = array_pop($labels);

    return implode(', ', $labels) . ' en ' . $last;
}

/**
 * @return array{days_in_year:int,day_of_year:int,remaining_days:int,fraction:float}
 */
function consus_remaining_year_fraction(string $asOf): array
{
    $asOf = consus_parse_date($asOf);
    $empty = [
        'days_in_year' => 0,
        'day_of_year' => 0,
        'remaining_days' => 0,
        'fraction' => 0.0,
    ];
    if ($asOf === '') {
        return $empty;
    }
    $date = new DateTimeImmutable($asOf . ' 00:00:00', new DateTimeZone('Europe/Amsterdam'));
    $daysInYear = (int) $date->format('L') === 1 ? 366 : 365;
    $dayOfYear = (int) $date->format('z') + 1;
    if (CONSUS_REMAINING_DAYS_INCLUDE_TODAY) {
        $remaining = $daysInYear - $dayOfYear + 1;
    } else {
        $remaining = $daysInYear - $dayOfYear;
    }
    if ($remaining < 0) {
        $remaining = 0;
    }

    return [
        'days_in_year' => $daysInYear,
        'day_of_year' => $dayOfYear,
        'remaining_days' => $remaining,
        'fraction' => $daysInYear > 0 ? $remaining / $daysInYear : 0.0,
    ];
}

function consus_stock_is_low(float $inventory, ?float $expectedRemaining): bool
{
    if (!CONSUS_LOW_STOCK_WHEN_INVENTORY_BELOW_EXPECTED || $expectedRemaining === null) {
        return false;
    }

    return $inventory < $expectedRemaining - 0.0000001;
}

/**
 * @param array<int, string> $excluded
 * @return array<string, bool>
 */
function consus_exclusion_index(array $excluded): array
{
    $index = [];
    foreach ($excluded as $token) {
        $key = strtolower(trim((string) $token));
        if ($key !== '') {
            $index[$key] = true;
        }
    }

    return $index;
}

function consus_token_excluded(string $value, array $excluded): bool
{
    $key = strtolower(trim($value));
    if ($key === '') {
        return false;
    }
    $index = consus_exclusion_index($excluded);

    return isset($index[$key]);
}

/**
 * Verkoop in een maand, zonder uitgesloten klanten. Een lege klantsleutel
 * (geen Source_No, of Source_Type geen Customer) blijft meetellen.
 *
 * @param array<string, mixed> $month
 * @param array<int, string> $excludedCustomers
 */
function consus_usage_sale_qty(array $month, array $excludedCustomers): float
{
    $skip = consus_exclusion_index($excludedCustomers);
    $sum = 0.0;
    $customers = is_array($month['customers'] ?? null) ? $month['customers'] : [];
    foreach ($customers as $customerNo => $qty) {
        $key = strtolower(trim((string) $customerNo));
        if ($key !== '' && isset($skip[$key])) {
            continue;
        }
        $sum += (float) $qty;
    }

    return $sum;
}

/**
 * Maandverbruik volgens CONSUS_USAGE_INCLUDES_SALE en CONSUS_USAGE_INCLUDES_INTERNAL.
 *
 * @param array<string, mixed> $month
 * @param array<int, string> $excludedCustomers
 */
function consus_usage_month_qty(array $month, array $excludedCustomers): float
{
    $qty = 0.0;
    if (CONSUS_USAGE_INCLUDES_SALE) {
        $qty += consus_usage_sale_qty($month, $excludedCustomers);
    }
    if (CONSUS_USAGE_INCLUDES_INTERNAL) {
        $qty += (float) ($month['internal'] ?? 0);
    }

    return $qty;
}

/**
 * @param array<string, mixed> $usage
 * @param array<int, string> $excludedCustomers
 * @return array{months:array<int, float>,quarters:array<int, float>,total:float,internal:float}
 */
function consus_year_metrics(array $usage, int $year, array $excludedCustomers): array
{
    $months = [];
    $total = 0.0;
    $internal = 0.0;
    $stored = is_array($usage['months'] ?? null) ? $usage['months'] : [];
    for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++) {
        $key = sprintf('%04d-%02d', $year, $monthNumber);
        $record = is_array($stored[$key] ?? null) ? $stored[$key] : ['internal' => 0.0, 'customers' => []];
        $qty = consus_usage_month_qty($record, $excludedCustomers);
        $months[$monthNumber] = $qty;
        $total += $qty;
        $internal += (float) ($record['internal'] ?? 0);
    }
    $quarters = [
        1 => $months[1] + $months[2] + $months[3],
        2 => $months[4] + $months[5] + $months[6],
        3 => $months[7] + $months[8] + $months[9],
        4 => $months[10] + $months[11] + $months[12],
    ];

    return [
        'months' => $months,
        'quarters' => $quarters,
        'total' => $total,
        'internal' => $internal,
    ];
}

/**
 * @param array<string, mixed> $usage
 * @param array{as_of?:string,history_start?:string} $windows
 * @param array<int, string> $excludedCustomers
 * @return array<string, mixed>
 */
function consus_usage_outlook(float $inventory, array $usage, array $windows, array $excludedCustomers): array
{
    $asOf = consus_parse_date($windows['as_of'] ?? '');
    $years = consus_average_years($windows);
    $average = null;
    if ($years !== []) {
        $sum = 0.0;
        foreach ($years as $year) {
            $sum += consus_year_metrics($usage, $year, $excludedCustomers)['total'];
        }
        $average = $sum / count($years);
    }
    $year = $asOf === '' ? 0 : (int) substr($asOf, 0, 4);
    $ytd = $year > 0 ? consus_year_metrics($usage, $year, $excludedCustomers)['total'] : 0.0;
    $fraction = $asOf === '' ? consus_remaining_year_fraction('1970-01-01') : consus_remaining_year_fraction($asOf);
    if ($asOf === '') {
        $fraction = [
            'days_in_year' => 0,
            'day_of_year' => 0,
            'remaining_days' => 0,
            'fraction' => 0.0,
        ];
    }
    $expected = $average === null ? null : $average * (float) $fraction['fraction'];

    return [
        'average' => $average,
        'average_years' => $years,
        'average_label' => consus_nl_year_list($years),
        'ytd' => $ytd,
        'remaining_days' => (int) $fraction['remaining_days'],
        'days_in_year' => (int) $fraction['days_in_year'],
        'fraction' => (float) $fraction['fraction'],
        'expected' => $expected,
        'low_stock' => consus_stock_is_low($inventory, $expected),
        'as_of' => $asOf,
    ];
}

/**
 * @return array<string, mixed>
 */
function consus_empty_usage_fact(string $companyKey, string $itemNo): array
{
    return [
        'company_key' => $companyKey,
        'item_no' => $itemNo,
        'description' => '',
        'cost_center' => '',
        'safety_stock' => 0.0,
        'inventory' => 0.0,
        'usage' => [
            'months' => [],
            'days' => [],
        ],
    ];
}

/**
 * @param array<string, mixed> $snapshot
 * @param array<int, string> $excludedItems
 * @return array<int, array<string, mixed>>
 */
function consus_usage_facts(array $snapshot, string $companyKey, string $costCenter, array $excludedItems): array
{
    $facts = [];
    $articles = is_array($snapshot['articles'] ?? null) ? $snapshot['articles'] : [];
    foreach ($articles as $article) {
        if (!is_array($article)) {
            continue;
        }
        $articleCompany = (string) ($article['company_key'] ?? '');
        if ($companyKey !== '' && $articleCompany !== $companyKey) {
            continue;
        }
        if (!consus_cost_center_filter_matches((string) ($article['cost_center'] ?? ''), $costCenter)) {
            continue;
        }
        $itemNo = trim((string) ($article['item_no'] ?? ''));
        if ($itemNo === '' || consus_token_excluded($itemNo, $excludedItems)) {
            continue;
        }
        $key = $articleCompany . '|' . $itemNo;
        if (!isset($facts[$key])) {
            $facts[$key] = consus_empty_usage_fact($articleCompany, $itemNo);
        }
        $description = trim((string) ($article['description'] ?? ''));
        if (strlen($description) > strlen((string) $facts[$key]['description'])) {
            $facts[$key]['description'] = $description;
        }
        if ((string) $facts[$key]['cost_center'] === '' && trim((string) ($article['cost_center'] ?? '')) !== '') {
            $facts[$key]['cost_center'] = trim((string) $article['cost_center']);
        }
        $facts[$key]['safety_stock'] += (float) ($article['safety_stock'] ?? 0);
        $facts[$key]['inventory'] += (float) ($article['inventory'] ?? 0);
    }

    $usageRows = is_array($snapshot['item_usage'] ?? null) ? $snapshot['item_usage'] : [];
    foreach ($usageRows as $usage) {
        if (!is_array($usage)) {
            continue;
        }
        $usageCompany = (string) ($usage['company_key'] ?? '');
        if ($companyKey !== '' && $usageCompany !== $companyKey) {
            continue;
        }
        $itemNo = trim((string) ($usage['item_no'] ?? ''));
        if ($itemNo === '' || consus_token_excluded($itemNo, $excludedItems)) {
            continue;
        }
        $key = $usageCompany . '|' . $itemNo;
        if (!isset($facts[$key])) {
            if (!consus_cost_center_filter_matches((string) ($usage['cost_center'] ?? ''), $costCenter)) {
                continue;
            }
            $facts[$key] = consus_empty_usage_fact($usageCompany, $itemNo);
            $facts[$key]['cost_center'] = trim((string) ($usage['cost_center'] ?? ''));
        }
        $months = is_array($usage['months'] ?? null) ? $usage['months'] : [];
        foreach ($months as $month => $values) {
            if (!is_array($values)) {
                continue;
            }
            $month = (string) $month;
            if (!isset($facts[$key]['usage']['months'][$month]) || !is_array($facts[$key]['usage']['months'][$month])) {
                $facts[$key]['usage']['months'][$month] = consus_empty_usage_month();
            }
            $target = consus_copy_usage_month($facts[$key]['usage']['months'][$month]);
            consus_usage_month_apply($target, consus_copy_usage_month($values), 1);
            $facts[$key]['usage']['months'][$month] = $target;
        }
    }

    $list = array_values($facts);
    usort($list, static function (array $left, array $right): int {
        $byItem = strnatcasecmp((string) $left['item_no'], (string) $right['item_no']);
        if ($byItem !== 0) {
            return $byItem;
        }

        return strnatcasecmp((string) $left['company_key'], (string) $right['company_key']);
    });

    return $list;
}

/**
 * @param array<string, mixed> $fact
 * @param array{as_of?:string,history_start?:string} $windows
 * @param array<int, string> $excludedCustomers
 * @return array<string, mixed>
 */
function consus_usage_year_row(array $fact, int $year, array $windows, array $excludedCustomers): array
{
    $metrics = consus_year_metrics(is_array($fact['usage'] ?? null) ? $fact['usage'] : [], $year, $excludedCustomers);
    $outlook = consus_usage_outlook(
        (float) ($fact['inventory'] ?? 0),
        is_array($fact['usage'] ?? null) ? $fact['usage'] : [],
        $windows,
        $excludedCustomers
    );
    $companyKey = (string) ($fact['company_key'] ?? '');

    return [
        'company_key' => $companyKey,
        'company_label' => consus_company_label($companyKey),
        'item_no' => (string) ($fact['item_no'] ?? ''),
        'description' => (string) ($fact['description'] ?? ''),
        'safety_stock' => (float) ($fact['safety_stock'] ?? 0),
        'inventory' => (float) ($fact['inventory'] ?? 0),
        'total' => (float) $metrics['total'],
        'months' => $metrics['months'],
        'quarters' => $metrics['quarters'],
        'internal' => (float) $metrics['internal'],
        'low_stock' => (bool) $outlook['low_stock'],
        'outlook' => $outlook,
    ];
}

/**
 * @param array<int, array<string, mixed>> $facts
 * @param array{as_of?:string,history_start?:string} $windows
 * @param array<int, string> $excludedCustomers
 * @return array<int, array<string, mixed>>
 */
function consus_usage_year_rows(array $facts, int $year, array $windows, array $excludedCustomers): array
{
    $rows = [];
    foreach ($facts as $fact) {
        if (!is_array($fact)) {
            continue;
        }
        $rows[] = consus_usage_year_row($fact, $year, $windows, $excludedCustomers);
    }

    return $rows;
}

/**
 * Platte waarden in kolomvolgorde. Het eerste veld is tekst, de rest getallen.
 *
 * @param array<string, mixed> $row
 * @return array<int, float|string>
 */
function consus_usage_row_values(array $row): array
{
    $values = [
        (string) ($row['item_no'] ?? ''),
        (float) ($row['safety_stock'] ?? 0),
        (float) ($row['total'] ?? 0),
    ];
    $months = is_array($row['months'] ?? null) ? $row['months'] : [];
    for ($month = 1; $month <= 12; $month++) {
        $values[] = (float) ($months[$month] ?? 0);
    }
    $quarters = is_array($row['quarters'] ?? null) ? $row['quarters'] : [];
    for ($quarter = 1; $quarter <= 4; $quarter++) {
        $values[] = (float) ($quarters[$quarter] ?? 0);
    }
    $values[] = (float) ($row['internal'] ?? 0);

    return $values;
}

/**
 * @param array<string, mixed> $snapshot
 * @return array<int, array{no:string,name:string,company_key:string,label:string}>
 */
function consus_customer_suggestions(array $snapshot, string $companyKey): array
{
    $byKey = [];
    $customers = is_array($snapshot['customers'] ?? null) ? $snapshot['customers'] : [];
    foreach ($customers as $customer) {
        if (!is_array($customer)) {
            continue;
        }
        $customerCompany = (string) ($customer['company_key'] ?? '');
        if ($companyKey !== '' && $customerCompany !== '' && $customerCompany !== $companyKey) {
            continue;
        }
        $number = trim((string) ($customer['no'] ?? ''));
        if ($number === '') {
            continue;
        }
        $name = trim((string) ($customer['name'] ?? ''));
        $key = strtolower($customerCompany . '|' . $number);
        $byKey[$key] = [
            'no' => $number,
            'name' => $name,
            'company_key' => $customerCompany,
            'label' => $name !== '' ? $number . ' — ' . $name : $number,
        ];
    }

    $usageRows = is_array($snapshot['item_usage'] ?? null) ? $snapshot['item_usage'] : [];
    foreach ($usageRows as $usage) {
        if (!is_array($usage)) {
            continue;
        }
        $usageCompany = (string) ($usage['company_key'] ?? '');
        if ($companyKey !== '' && $usageCompany !== $companyKey) {
            continue;
        }
        $months = is_array($usage['months'] ?? null) ? $usage['months'] : [];
        foreach ($months as $month) {
            if (!is_array($month)) {
                continue;
            }
            $customerMap = is_array($month['customers'] ?? null) ? $month['customers'] : [];
            foreach (array_keys($customerMap) as $number) {
                $number = trim((string) $number);
                if ($number === '') {
                    continue;
                }
                $key = strtolower($usageCompany . '|' . $number);
                if (isset($byKey[$key])) {
                    continue;
                }
                $byKey[$key] = [
                    'no' => $number,
                    'name' => '',
                    'company_key' => $usageCompany,
                    'label' => $number,
                ];
            }
        }
    }

    $list = array_values($byKey);
    usort($list, static function (array $left, array $right): int {
        return strnatcasecmp((string) $left['label'], (string) $right['label']);
    });

    return $list;
}

/**
 * @param array<string, mixed> $snapshot
 * @return array<int, array{item_no:string,description:string,company_key:string,label:string}>
 */
function consus_item_suggestions(array $snapshot, string $companyKey, string $costCenter): array
{
    $facts = consus_usage_facts($snapshot, $companyKey, $costCenter, []);
    $list = [];
    foreach ($facts as $fact) {
        $itemNo = (string) ($fact['item_no'] ?? '');
        $description = trim((string) ($fact['description'] ?? ''));
        $list[] = [
            'item_no' => $itemNo,
            'description' => $description,
            'company_key' => (string) ($fact['company_key'] ?? ''),
            'label' => $description !== '' ? $itemNo . ' — ' . $description : $itemNo,
        ];
    }

    return $list;
}

function consus_dutch_month_name(int $month): string
{
    $names = [
        1 => 'januari',
        2 => 'februari',
        3 => 'maart',
        4 => 'april',
        5 => 'mei',
        6 => 'juni',
        7 => 'juli',
        8 => 'augustus',
        9 => 'september',
        10 => 'oktober',
        11 => 'november',
        12 => 'december',
    ];

    return $names[$month] ?? '';
}

function consus_format_dutch_date(DateTimeImmutable $date): string
{
    $local = $date->setTimezone(new DateTimeZone('Europe/Amsterdam'));

    return (int) $local->format('j') . ' ' . consus_dutch_month_name((int) $local->format('n')) . ' ' . $local->format('Y');
}

function consus_format_dutch_datetime(string $value): string
{
    if (trim($value) === '') {
        return 'Nog niet opgebouwd';
    }
    try {
        $date = new DateTimeImmutable($value);
    } catch (Throwable $error) {
        return $value;
    }
    $local = $date->setTimezone(new DateTimeZone('Europe/Amsterdam'));

    return consus_format_dutch_date($local) . ', ' . $local->format('H:i');
}

/**
 * @param array<string, mixed> $snapshot
 * @return array{as_of:string,history_start:string,month_start:string,quarter_start:string,year_start:string}
 */
function consus_snapshot_windows(array $snapshot): array
{
    $windows = is_array($snapshot['windows'] ?? null) ? $snapshot['windows'] : [];
    $asOf = consus_parse_date($windows['as_of'] ?? ($snapshot['as_of'] ?? ''));
    if ($asOf === '') {
        return consus_period_windows();
    }
    $parsed = consus_period_windows(new DateTimeImmutable($asOf . ' 00:00:00', new DateTimeZone('Europe/Amsterdam')));
    $history = consus_parse_date($windows['history_start'] ?? '');
    if ($history !== '') {
        $parsed['history_start'] = $history;
    }

    return $parsed;
}
