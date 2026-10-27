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
$rows[] = ['No' => 'PK-0530017', 'Vendor_No' => '90101', 'Description' => 'LINER', 'Blocked' => false, 'Last_Date_Modified' => '2026-07-03'];
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
$collect = static function (string $today) use ($fetch, $noSleep): array {
    $seen = [];
    $result = consus_each_item_rows_cached('Hunter van Twist', 'hvt', static function (array $row) use (&$seen): void {
        $seen[$row['No']] = $row;
    }, $fetch, $today, $noSleep);

    return [$result, $seen];
};

try {
    [$result, $seen] = $collect('2026-10-27');
    cache_assert($result['mode'] === 'full', 'zonder cache volledig');
    cache_assert(count($seen) === count($rows), 'volledige lading in stukken mist geen artikel (ook niet buiten het alfabet)');
    cache_assert(isset($seen['PK-0530017']) && $seen['PK-0530017']['Vendor_No'] === '90101', 'PK-0530017 met leverancier in de cache');
    cache_assert(!in_array('', $calls, true), 'nooit de volle AppItems in één call');
    cache_assert(!isset($seen['PK-0530017']['Last_Date_Modified']), 'cache bewaart alleen de artikelvelden');

    $calls = [];
    $rows[] = ['No' => 'PK-NIEUW', 'Vendor_No' => '90101', 'Description' => 'NIEUW', 'Last_Date_Modified' => '2026-10-27'];
    $rows[0]['Vendor_No'] = '90052';
    $rows[0]['Last_Date_Modified'] = '2026-10-28';
    [$result, $seen] = $collect('2026-10-28');
    cache_assert($result['mode'] === 'delta', 'volgende nacht delta');
    cache_assert($calls === ['Last_Date_Modified ge 2026-10-27'], 'delta filtert ge op de vorige dag: ' . json_encode($calls));
    cache_assert(isset($seen['PK-NIEUW']) && $seen['PK-00']['Vendor_No'] === '90052', 'delta voegt toe en werkt bij op No');

    $calls = [];
    array_pop($rows);
    [$result, $seen] = $collect('2026-11-04');
    cache_assert($result['mode'] === 'full', 'na 7 dagen weer volledig');
    cache_assert(!isset($seen['PK-NIEUW']), 'volledige verversing haalt verwijderde artikelen weg');

    $rows = array_slice($rows, 0, 5);
    $failed = false;
    try {
        $collect('2026-11-20');
    } catch (RuntimeException $error) {
        $failed = str_contains($error->getMessage(), 'niet vervangen');
    }
    cache_assert($failed, 'veel minder artikelen dan eerder vervangt de cache niet');
    cache_assert(count(consus_item_cache_load('hvt')['items']) > 60, 'oude cache blijft staan');

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
    @unlink(consus_item_cache_file('hvt'));
    @rmdir($root);
}

echo "consus_item_cache_test OK\n";
