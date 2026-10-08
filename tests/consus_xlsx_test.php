<?php

$retourDir = sys_get_temp_dir() . '/consus-xlsx-retour-' . getmypid();
@mkdir($retourDir, 0700, true);
putenv('CONSUS_RETOUR_SETTINGS_FILE=' . $retourDir . '/retour_settings.json');
putenv('CONSUS_RETOUR_FILE=' . $retourDir . '/consus_retour.json');

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
xlsx_assert($names === ['2026', '2025', '2024', '2023', 'Retourkandidaten', 'Filters'], 'jaarbladen, retourkandidaten en als laatste het filterblad');
foreach ($names as $name) {
    xlsx_assert(strlen($name) <= 31, 'sheetnaam blijft binnen 31 tekens');
}
$retourSheet = $sheets[4]['rows'];
xlsx_assert(count($retourSheet) === 1 && $retourSheet[0][0] === 'Leverancier' && !in_array('Perkins-factuur', $retourSheet[0], true), 'zonder regels alleen de kop van de retourlijst');

// Retourlijst met regels voor afdeling 15 (Perkins) en cache-data.
$today = consus_retour_today();
$invoiceDate = (new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('Europe/Amsterdam')))->modify('-10 days')->format('Y-m-d');
file_put_contents(getenv('CONSUS_RETOUR_FILE'), json_encode([
    'generated_at' => '2026-10-08T02:00:00+00:00',
    'vendors' => ['90101'],
    'types_by_vendor' => ['90101' => ['57401', '57420']],
    'lines' => [
        ['invoice' => 'PI1', 'vendor' => '90101', 'vendor_invoice' => '9024', 'document_date' => $invoiceDate, 'item' => 'PK-1', 'description' => 'Filter', 'quantity' => 1, 'unit_cost' => 80, 'order_no' => 'PO1', 'department' => '15'],
        ['invoice' => 'PI2', 'vendor' => '90101', 'vendor_invoice' => '9025', 'document_date' => $invoiceDate, 'item' => 'PK-2', 'description' => 'Pomp', 'quantity' => 1, 'unit_cost' => 80, 'order_no' => 'PO2', 'department' => '80'],
    ],
    'orders' => ['PO1' => ['found' => true, 'egt' => false, 'csv' => true]],
    'items' => [
        'PK-1' => ['safety_stock' => 0, 'stock' => ['HVT' => 1], 'bin' => ['HVT' => 1], 'reserved' => 0, 'reserved_return' => 0],
        'PK-2' => ['safety_stock' => 0, 'stock' => ['HVT' => 1], 'bin' => ['HVT' => 1], 'reserved' => 0, 'reserved_return' => 0],
    ],
]));
consus_retour_rule_save('15', ['vendor' => '90101', 'type' => '57401', 'window' => 30, 'min_value' => 50]);
consus_retour_rule_save('15', ['vendor' => '90101', 'type' => '57420', 'window' => 90, 'min_value' => 50]);
$retourRows = consus_xlsx_sheets($snapshot, $prefs, ['company' => 'kvt', 'cost_center' => '', 'retour_afdeling' => '15'])[4]['rows'];
xlsx_assert(count($retourRows) === 2 && $retourRows[1][0] === '90101' && $retourRows[1][1] === 'Voorraad (57420)' && $retourRows[1][5] === 'PK-1', 'retourlijst voor afdeling 15');
xlsx_assert(!preg_match('/^\d{4}-/', (string) $retourRows[1][4]), 'factuurdatum in Nederlandse notatie');
$retourAll = consus_xlsx_sheets($snapshot, $prefs, ['company' => 'kvt', 'cost_center' => ''])[4]['rows'];
xlsx_assert(count($retourAll) === 2, 'zonder afdeling: alleen afdelingen met regels (80 heeft er geen)');
array_map('unlink', glob($retourDir . '/*') ?: []);
@rmdir($retourDir);

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

$filter = $sheets[5]['rows'];
xlsx_assert($filter[0] === ['Gefilterde klanten', 'Gefilterde artikels'], 'filterblad heeft twee kolommen');
xlsx_assert($filter[1][0] === 'C1 — Acme', 'klant toont nummer en naam');
xlsx_assert($filter[1][1] === 'ART-9', 'uitgesloten artikel staat op het filterblad');

$snapshot['articles'][] = [
    'company_key' => 'hvt',
    'item_no' => 'ART-1',
    'description' => 'Filter HVT',
    'cost_center' => '5',
    'location' => 'HVT',
    'inventory' => 2,
    'safety_stock' => 4,
    'reorder_point' => 0,
    'consumption' => ['months' => [], 'days' => [], 'm' => 0, 'q' => 0, 'y' => 0],
];
$alle = consus_xlsx_sheets($snapshot, ['customers' => [], 'items' => []], ['company' => '']);
$alleItems = [];
foreach (array_slice($alle[0]['rows'], 1) as $alleRow) {
    $alleItems[] = (string) $alleRow[0];
}
xlsx_assert(in_array('ART-1 (KVT)', $alleItems, true) && in_array('ART-1 (HVT)', $alleItems, true), 'Alle toont het bedrijf bij het artikelnummer');

$stale = $snapshot;
$stale['version'] = CONSUS_SNAPSHOT_VERSION - 1;
$staleSheets = consus_xlsx_sheets($stale, ['customers' => [], 'items' => []], ['company' => 'kvt']);
xlsx_assert(count($staleSheets[0]['rows']) === 1, 'oude snapshotversie exporteert alleen de header');
$ungenerated = $snapshot;
$ungenerated['generated_at'] = '';
$ungeneratedSheets = consus_xlsx_sheets($ungenerated, ['customers' => [], 'items' => []], ['company' => 'kvt']);
xlsx_assert(count($ungeneratedSheets[0]['rows']) === 1, 'lege generated_at exporteert alleen de header');

$sorted = consus_xlsx_sheets($snapshot, ['customers' => [], 'items' => [], 'page_size' => 10], [
    'company' => 'kvt',
    'sort' => '2',
    'dir' => 'desc',
]);
xlsx_assert(count($sorted[0]['rows']) === 3, 'export bevat alle rijen, niet één pagina');
xlsx_assert((string) $sorted[0]['rows'][1][0] === 'ART-1', 'aflopend jaartotaal zet het grootste artikel eerst');
xlsx_assert((float) $sorted[0]['rows'][1][2] > (float) $sorted[0]['rows'][2][2], 'eerste totaal is groter dan het tweede');

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
