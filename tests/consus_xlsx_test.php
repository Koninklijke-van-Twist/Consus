<?php

require_once __DIR__ . '/../web/consus_xlsx.php';

function xlsx_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$windows = consus_period_windows(new DateTimeImmutable('2026-10-06', new DateTimeZone('Europe/Amsterdam')));
$snapshot = consus_empty_snapshot();
$snapshot['generated_at'] = '2026-10-06T07:45:00+00:00';
$snapshot['as_of'] = $windows['as_of'];
$snapshot['windows'] = $windows;
$snapshot['version'] = CONSUS_SNAPSHOT_VERSION;
$snapshot['articles'] = [[
    'company_key' => 'kvt',
    'item_no' => 'ART-1',
    'description' => 'Filter',
    'cost_center' => '5',
    'location' => 'KVT',
    'inventory' => 5,
    'safety_stock' => 20,
    'reorder_point' => 0,
    'consumption' => ['months' => [], 'days' => [], 'm' => 0, 'q' => 0, 'y' => 0],
], [
    'company_key' => 'kvt',
    'item_no' => 'ART-9',
    'description' => 'Weg',
    'cost_center' => '5',
    'location' => 'KVT',
    'inventory' => 1,
    'safety_stock' => 1,
    'reorder_point' => 0,
    'consumption' => ['months' => [], 'days' => [], 'm' => 0, 'q' => 0, 'y' => 0],
]];
$snapshot['item_usage'] = [[
    'company_key' => 'kvt',
    'item_no' => 'ART-1',
    'cost_center' => '5',
    'months' => [
        '2026-01' => ['internal' => 3, 'customers' => ['C1' => 10, 'C2' => 4]],
    ],
    'days' => [],
], [
    'company_key' => 'kvt',
    'item_no' => 'ART-9',
    'cost_center' => '5',
    'months' => [
        '2026-01' => ['internal' => 1, 'customers' => ['C1' => 8]],
    ],
    'days' => [],
]];
$snapshot['customers'] = [[
    'company_key' => 'kvt',
    'no' => 'C1',
    'name' => 'Acme',
]];

$prefs = [
    'customers' => ['C1'],
    'items' => ['ART-9'],
];
$sheets = consus_xlsx_sheets($snapshot, $prefs, ['company' => 'kvt', 'cost_center' => '']);
$names = array_column($sheets, 'name');
xlsx_assert($names === ['2026', '2025', '2024', '2023', 'Filters'], 'jaarbladen en als laatste het filterblad');
foreach ($names as $name) {
    xlsx_assert(strlen($name) <= 31, 'sheetnaam blijft binnen 31 tekens');
}

$headers = consus_usage_column_labels();
foreach (array_slice($sheets, 0, 4) as $sheet) {
    xlsx_assert($sheet['rows'][0] === $headers, 'headerrij van ' . $sheet['name']);
}
$yearRows = $sheets[0]['rows'];
xlsx_assert(count($yearRows) === 2, 'uitgesloten artikel staat niet op het blad');
xlsx_assert($yearRows[1][0] === 'ART-1', 'overgebleven artikel');
xlsx_assert(abs((float) $yearRows[1][3] - 7) < 0.0001, 'januari zonder uitgesloten klant: 4 verkoop + 3 intern');
xlsx_assert(abs((float) $yearRows[1][2] - 7) < 0.0001, 'jaartotaal volgt januari');
xlsx_assert(abs((float) $yearRows[1][19] - 3) < 0.0001, 'intern verbruik blijft de laatste kolom');

$filter = $sheets[4]['rows'];
xlsx_assert($filter[0] === ['Gefilterde klanten', 'Gefilterde artikels'], 'filterblad heeft twee kolommen');
xlsx_assert($filter[1][0] === 'C1 — Acme', 'klant toont nummer en naam');
xlsx_assert($filter[1][1] === 'ART-9', 'uitgesloten artikel staat op het filterblad');

$binary = consus_xlsx_binary($sheets);
$path = sys_get_temp_dir() . '/consus-xlsx-test-' . getmypid() . '.xlsx';
file_put_contents($path, $binary);
$zip = new ZipArchive();
xlsx_assert($zip->open($path) === true, 'xlsx is een geldige zip');
xlsx_assert($zip->locateName('[Content_Types].xml') !== false, 'Content_Types zit in het pakket');
$workbook = $zip->getFromName('xl/workbook.xml');
xlsx_assert(is_string($workbook), 'workbook.xml zit in het pakket');
foreach (['2026', '2025', '2024', '2023', 'Filters'] as $name) {
    xlsx_assert(strpos($workbook, 'name="' . $name . '"') !== false, 'workbook noemt ' . $name);
}
$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
xlsx_assert(is_string($sheet) && strpos($sheet, 'Artikelnummer') !== false, 'eerste blad heeft de header');
xlsx_assert(strpos($sheet, '<autoFilter ') !== false, 'headerrij heeft een autofilter');
xlsx_assert(strpos($sheet, '<v>7</v>') !== false, 'getal staat als numerieke cel');
$zip->close();
unlink($path);

echo "OK\n";
