<?php

require_once __DIR__ . '/../web/consus_data.php';

function cache_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/consus-itemcache-' . getmypid();
@mkdir($root, 0775, true);
putenv('CONSUS_SNAPSHOT_FILE=' . $root . '/consus_snapshot.json');
$GLOBALS['baseUrl'] = 'https://bc.example.test/BC';
$GLOBALS['auth_list'] = ['Test' => ['mode' => 'basic', 'user' => 'svc', 'pass' => 'x']];
$GLOBALS['environment'] = 'Test';
$GLOBALS['demeter_company_environment_map'] = ['Hunter van Twist' => 'Test'];

$rows = [];
for ($i = 0; $i < 60; $i++) {
    $rows[] = ['No' => sprintf('PK-%02d', $i), 'Vendor_No' => '90101', 'Description' => 'LINER', 'Last_Date_Modified' => '2026-07-03'];
}
$rows[] = ['No' => 'PK-0530017', 'Vendor_No' => '90101', 'LVS_Vendor_Name' => 'OUDE NAAM', 'Description' => 'LINER', 'Blocked' => false, 'Last_Date_Modified' => '2026-07-03'];
$rows[] = ['No' => 'A-1', 'Vendor_No' => '1', 'Description' => 'A', 'Last_Date_Modified' => '2026-07-03'];
$rows[] = ['No' => 'Z9', 'Vendor_No' => '2', 'Description' => 'Z', 'Last_Date_Modified' => '2026-07-03'];
$rows[] = ['No' => '~X', 'Vendor_No' => '3', 'Description' => 'buiten het alfabet', 'Last_Date_Modified' => '2026-07-03'];
$calls = [];

// Nep-Mímir: te veel rijen in één antwoord geeft dezelfde fout als echt (HTTP 500 memory).
$fetch = static function (string $url, array $auth, callable $onRow) use (&$rows, &$calls): int {
    unset($auth);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $filter = (string) ($query['$filter'] ?? '');
    $calls[] = $filter;
    preg_match_all("/(No|Last_Date_Modified) (ge|lt) '?([^' )]*)'?/", $filter, $matches, PREG_SET_ORDER);
    $hits = [];
    foreach ($rows as $row) {
        $ok = true;
        foreach ($matches as [, $field, $op, $value]) {
            $cmp = strcmp((string) $row[$field], $value);
            $ok = $ok && ($op === 'ge' ? $cmp >= 0 : $cmp < 0);
        }
        if ($ok) {
            $hits[] = $row;
        }
    }
    if (count($hits) > 20) {
        throw new RuntimeException('Mímir HTTP 500: Allowed memory size of 536870912 bytes exhausted');
    }
    foreach ($hits as $row) {
        $onRow($row);
    }

    return count($hits);
};
$noSleep = static function (int $seconds): void {
    unset($seconds);
};
$bcCount = static function (string $company) use (&$rows): ?int {
    unset($company);

    return count($rows);
};
$noCount = static function (string $company): ?int {
    unset($company);

    return null;
};
$vendorList = ['90101' => 'PERKINS'];
$vendorNames = static function (string $company) use (&$vendorList): ?array {
    unset($company);

    return $vendorList;
};
$collect = static function (string $today, ?callable $count = null) use ($fetch, $noSleep, $bcCount, $vendorNames): array {
    $seen = [];
    $result = consus_each_item_rows_cached('Hunter van Twist', 'hvt', static function (array $row) use (&$seen): void {
        $seen[$row['No']] = $row;
    }, $fetch, $today, $noSleep, $count ?? $bcCount, $vendorNames);

    return [$result, $seen];
};

try {
    [$result, $seen] = $collect('2026-10-27');
    cache_assert($result['mode'] === 'full', 'zonder cache volledig');
    cache_assert(count($seen) === count($rows), 'volledige lading in stukken mist geen artikel (ook niet buiten het alfabet)');
    cache_assert(isset($seen['PK-0530017']) && $seen['PK-0530017']['Vendor_No'] === '90101', 'PK-0530017 met leverancier in de cache');
    cache_assert(!in_array('', $calls, true), 'nooit de volle AppItems in één call');
    cache_assert(!isset($seen['PK-0530017']['Last_Date_Modified']), 'cache bewaart alleen de artikelvelden');
    cache_assert($seen['PK-0530017']['LVS_Vendor_Name'] === 'PERKINS', 'naam uit de leverancierslijst, niet uit de FlowField');
    $vendorList = null;
    [, $seen] = $collect('2026-10-27');
    cache_assert($seen['PK-0530017']['LVS_Vendor_Name'] === 'OUDE NAAM', 'lijst faalt: cachewaarde als terugval');
    $vendorList = ['90101' => 'PERKINS'];

    $calls = [];
    $rows[] = ['No' => 'PK-NIEUW', 'Vendor_No' => '90101', 'Description' => 'NIEUW', 'Last_Date_Modified' => '2026-10-27'];
    $rows[0]['Vendor_No'] = '90052';
    $rows[0]['Last_Date_Modified'] = '2026-10-28';
    [$result, $seen] = $collect('2026-10-28');
    cache_assert($result['mode'] === 'delta', 'volgende nacht delta');
    cache_assert($calls === ['Last_Date_Modified ge 2026-10-27'], 'delta filtert ge op de vorige dag: ' . json_encode($calls));
    cache_assert(isset($seen['PK-NIEUW']) && $seen['PK-00']['Vendor_No'] === '90052', 'delta voegt toe en werkt bij op No');

    cache_assert(consus_item_cache_load('hvt')['full_on'] === '2026-10-27', 'delta doet geen volledige lading');

    // Count ongelijk (hier: geen count): alleen No in stukken, verwijderde artikelen eruit.
    $calls = [];
    array_pop($rows);
    [$result, $seen] = $collect('2026-11-04', $noCount);
    cache_assert($result['mode'] === 'delta+verwijderd', 'geen count: No-scan verwijdert: ' . $result['mode']);
    cache_assert(!isset($seen['PK-NIEUW']) && isset($seen['PK-0530017']), 'alleen het verwijderde artikel weg');
    cache_assert(count($calls) > 2, 'No-scan in stukken');

    // Count gelijk: geen scan.
    $calls = [];
    [$result] = $collect('2026-11-05');
    cache_assert($result['mode'] === 'delta' && count($calls) === 1, 'count gelijk: geen No-scan');

    // Scan met veel te weinig nummers: cache blijft heel.
    $saved = $rows;
    $rows = array_slice($rows, 0, 5);
    [$result, $seen] = $collect('2026-11-06');
    cache_assert(count($seen) > 60, 'nooit de halve cache weggooien: ' . count($seen));
    $rows = $saved;

    // Weekend: rekenwerk.
    $ams = new DateTimeZone('Europe/Amsterdam');
    $at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable($time, $ams);
    cache_assert(consus_item_weekend_slice_size(85481) === 1781 && consus_item_weekend_slice_size(48) === 1, 'stukgrootte ceil(totaal/48)');
    cache_assert(consus_item_weekend_due($at('2026-10-30 23:59')) === null, 'vrijdag niets');
    cache_assert(consus_item_weekend_due($at('2026-10-31 00:05')) === ['saturday' => '2026-10-31', 'due' => 1], 'za 00:00 = stuk 1');
    cache_assert(consus_item_weekend_due($at('2026-11-01 23:10'))['due'] === 48, 'zo 23:00 = stuk 48');
    cache_assert(consus_item_weekend_due($at('2026-11-01 23:10'))['saturday'] === '2026-10-31', 'zondag hoort bij zaterdag');
    cache_assert(consus_item_weekend_due($at('2026-11-02 00:10')) === null, 'maandag niets');

    $sliceCalls = 0;
    $fail = false;
    $fetchSlice = static function (string $company, string $after, int $top) use (&$rows, &$sliceCalls, &$fail): array {
        unset($company);
        $sliceCalls++;
        if ($fail) {
            throw new RuntimeException('BC HTTP 401: Unauthorized');
        }
        $sorted = $rows;
        usort($sorted, static fn (array $a, array $b): int => strcmp($a['No'], $b['No']));
        $out = array_values(array_filter($sorted, static fn (array $row): bool => $after === '' || strcmp($row['No'], $after) > 0));

        return array_slice($out, 0, $top);
    };
    $cache = consus_item_cache_load('hvt');
    $cache['items']['PK-WEG'] = ['No' => 'PK-WEG'];
    consus_item_cache_save('hvt', $cache);
    $step = consus_item_weekend_step('Hunter van Twist', 'hvt', $at('2026-10-31 00:10'), $fetchSlice, $bcCount);
    cache_assert($step['done'] === 1 && $step['slice'] === 2, 'stuk 1: ' . json_encode($step));
    $step = consus_item_weekend_step('Hunter van Twist', 'hvt', $at('2026-10-31 04:10'), $fetchSlice, $bcCount);
    cache_assert($step['done'] === 5 && !$step['closed'], 'gemiste uren ingehaald: ' . json_encode($step));
    $lock = consus_item_cache_lock('hvt', true);
    $step = consus_item_weekend_step('Hunter van Twist', 'hvt', $at('2026-10-31 05:10'), $fetchSlice, $bcCount);
    consus_item_cache_unlock($lock);
    cache_assert($step['status'] === 'bezet', 'nooit tegelijk met nightly');
    $step = consus_item_weekend_step('Hunter van Twist', 'hvt', $at('2026-11-01 23:05'), $fetchSlice, $bcCount);
    cache_assert($step['done'] === 48 && $step['closed'] && $step['removed'] === 1, 'zondag afsluiten: ' . json_encode($step));
    $items = consus_item_cache_load('hvt')['items'];
    cache_assert(!isset($items['PK-WEG']) && count($items) === count($rows), 'niet geziene artikelen weg, rest heel');

    // Volgend weekend met een fout: niets weggooien.
    $cache = consus_item_cache_load('hvt');
    $cache['items']['PK-WEG'] = ['No' => 'PK-WEG'];
    consus_item_cache_save('hvt', $cache);
    consus_item_weekend_step('Hunter van Twist', 'hvt', $at('2026-11-07 01:10'), $fetchSlice, $bcCount);
    $fail = true;
    $step = consus_item_weekend_step('Hunter van Twist', 'hvt', $at('2026-11-08 23:05'), $fetchSlice, $bcCount);
    cache_assert($step['status'] === 'fout' && !$step['closed'] && $step['done'] === 2, 'fout: niet afsluiten: ' . json_encode($step));
    cache_assert(isset(consus_item_cache_load('hvt')['items']['PK-WEG']), 'bij een fout blijft alles staan');
    $fail = false;
    cache_assert(consus_item_weekend_step('Hunter van Twist', 'hvt', $at('2026-11-09 00:05'), $fetchSlice, $bcCount)['status'] === 'geen weekend', 'maandag niet');

    $calls = [];
    $denied = static function (string $url, array $auth, callable $onRow) use (&$calls): int {
        unset($url, $auth, $onRow);
        $calls[] = 1;
        throw new RuntimeException('BC HTTP 401: Unauthorized');
    };
    try {
        consus_each_item_rows_cached('Hunter van Twist', 'hvt', static function (array $row): void {
            unset($row);
        }, $denied, '2026-12-01', $noSleep);
    } catch (Throwable $ignored) {
    }
    cache_assert(count($calls) < 10, 'een inlogfout splitst niet eindeloos: ' . count($calls));
} finally {
    array_map('unlink', glob($root . '/*') ?: []);
    @rmdir($root);
}

echo "consus_item_cache_test OK\n";
