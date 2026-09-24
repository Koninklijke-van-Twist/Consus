<?php

require_once __DIR__ . '/../web/consus_data.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_windows(): array
{
    return consus_period_windows(new DateTimeImmutable('2026-09-24', new DateTimeZone('Europe/Amsterdam')));
}

function test_snapshot(array $items, array $windows): array
{
    $rolled = consus_rollup_items($items, $windows, []);
    $snapshot = consus_empty_snapshot();
    $snapshot['generated_at'] = '2026-09-24T02:00:00+00:00';
    $snapshot['as_of'] = $windows['as_of'];
    $snapshot['windows'] = $windows;
    $snapshot['rows'] = $rolled['rows'];
    $snapshot['vendors'] = $rolled['vendors'];
    $snapshot['cost_centers'] = $rolled['cost_centers'];

    return $snapshot;
}

$windows = test_windows();
test_assert($windows['month_start'] === '2026-09-01', 'maandvenster');
test_assert($windows['quarter_start'] === '2026-07-01', 'kwartaalvenster');
test_assert($windows['year_start'] === '2026-01-01', 'jaarvenster');
test_assert($windows['history_start'] === '2025-10-01', 'twaalf maanden historie');

test_assert(CONSUS_EIGEN_LOCATION_HINTS === ['KVT', 'HVT'], 'eigen-magazijnhint is KVT en HVT');
test_assert(CONSUS_DROPSHIP_PURCHASING_CODE === 'DROP_SHIP', 'dropship-inkoopcode');
test_assert(CONSUS_DROPSHIP_VENDOR_NO === '90052', 'dropship-leverancier');
test_assert(CONSUS_EGT_VENDOR_NO === '90101', 'EGT-leverancier');
test_assert(consus_procurement_bucket('', '') === 'eigen', 'leeg inkooppad is eigen');
test_assert(consus_procurement_bucket('DROP_SHIP', '') === 'dropship', 'DROP_SHIP is dropship');
test_assert(consus_procurement_bucket('', '90052') === 'dropship', 'leverancier 90052 is dropship');
test_assert(consus_procurement_bucket('', '90101') === 'egt', 'leverancier 90101 is EGT');
test_assert(consus_procurement_bucket('DROP_SHIP', '90101') === 'dropship', 'dropship wint van EGT op dezelfde regel');
test_assert(consus_procurement_bucket('', 'PERK') === 'eigen', 'artikelleverancier is geen EGT');

$salesQuery = consus_ledger_query(CONSUS_SALES_ENTRY_TYPES, '2025-10-01');
test_assert(
    $salesQuery['$filter'] === "Entry_Type eq 'Sale' and Posting_Date ge 2025-10-01",
    'verkoopfilter komt uit config'
);
test_assert(!str_contains($salesQuery['$filter'], 'Perkins'), 'verkoopfilter niet vast op leverancier');
test_assert(!str_contains($salesQuery['$filter'], '90052'), 'verkoopfilter niet vast op dropship-leverancier');
test_assert(!str_contains($salesQuery['$filter'], '90101'), 'verkoopfilter niet vast op EGT-leverancier');
test_assert(!str_contains($salesQuery['$filter'], 'DROP_SHIP'), 'verkoopfilter niet vast op inkoopcode');
test_assert(!str_contains($salesQuery['$filter'], 'COST_CENTER'), 'verkoopfilter niet vast op afdeling');
test_assert(!str_contains($salesQuery['$filter'], 'Location_Code'), 'verkoop haalt alle locaties op');
test_assert(str_contains($salesQuery['$select'], 'Location_Code'), 'locatiecode blijft in de select');

$woQuery = consus_wo_ledger_query('2025-10-01');
test_assert(
    $woQuery['$filter'] === "((Entry_Type eq 'Negative Adjmt.' and startswith(Document_No,'WO')) or Entry_Type eq 'Assembly Consumption') and Posting_Date ge 2025-10-01",
    'werkorderverbruik is Negative Adjmt. op WO plus Assembly Consumption'
);
test_assert(str_contains($woQuery['$filter'], "Entry_Type eq 'Negative Adjmt.'"), 'primair entry type is Negative Adjmt.');
test_assert(str_contains($woQuery['$filter'], "startswith(Document_No,'WO')"), 'WO-documentfilter');
test_assert(!str_contains($woQuery['$filter'], 'Location_Code'), 'verbruik haalt alle locaties op');
test_assert(
    $woQuery['$filter'] !== "Entry_Type eq 'Assembly Consumption' and Posting_Date ge 2025-10-01",
    'verbruik leunt niet alleen op Assembly Consumption'
);

$dimensionQuery = consus_dimension_query();
test_assert(
    $dimensionQuery['$filter'] === "Table_ID eq 27 and Dimension_Code eq '15'",
    'kostenplaats haalt dimensie 15 op zonder waardfilter'
);
test_assert(!str_contains($dimensionQuery['$filter'], 'Dimension_Value_Code'), 'afdelingswaarde wordt niet vastgezet');

$stockQuery = consus_entity_query(CONSUS_STOCK_FIELDS);
test_assert(!isset($stockQuery['$filter']), 'voorraadquery filtert niet op één bedrijf of leverancier');

$scoped = consus_companies_in_scope(['KVT Gas', 'Hunter van Twist', 'Koninklijke van Twist B.V.']);
test_assert(array_column($scoped, 'company_key') === ['kvt', 'hvt'], 'alleen KVT en HVT, KVT Gas valt buiten scope');

$items = [];
consus_apply_stock_row($items, [
    'Item_No' => 'A1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'KVT',
    'Inventory' => 10,
    'Safety_Stock_Quantity' => 4,
    'Reorder_Point' => 2,
], 'Koninklijke van Twist');
consus_apply_stock_row($items, [
    'Item_No' => 'A1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'KVT',
    'Inventory' => 99,
    'Safety_Stock_Quantity' => 99,
    'Reorder_Point' => 99,
], 'Koninklijke van Twist');
consus_apply_stock_row($items, [
    'Item_No' => 'A1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'M100',
    'Inventory' => 7,
    'Safety_Stock_Quantity' => 1,
    'Reorder_Point' => 1,
], 'Koninklijke van Twist');
consus_apply_vendor_row($items, [
    'No' => 'A1',
    'Vendor_No' => 'PERK',
    'LVS_Vendor_Name' => 'Perkins Engines',
    'COST_CENTER' => 'MAG',
], 'kvt');
consus_apply_vendor_row($items, [
    'No' => 'NIET-IN-VOORRAAD',
    'Vendor_No' => 'PERK',
    'LVS_Vendor_Name' => 'Perkins Engines',
], 'kvt');
consus_apply_ledger_row($items, [
    'Item_No' => 'A1',
    'Quantity' => -6,
    'Sales_Amount_Actual' => 600,
    'Posting_Date' => '2026-09-10T00:00:00Z',
    'Location_Code' => 'KVT',
], 'kvt', 'sales', $windows);
consus_apply_ledger_row($items, [
    'Item_No' => 'A1',
    'Quantity' => 1,
    'Sales_Amount_Actual' => -50,
    'Posting_Date' => '2026-08-02',
    'Location_Code' => 'KVT',
], 'kvt', 'sales', $windows);
consus_apply_ledger_row($items, [
    'Item_No' => 'A1',
    'Quantity' => -2,
    'Sales_Amount_Actual' => 200,
    'Posting_Date' => '2026-09-11',
    'Location_Code' => 'M100',
    'Vendor_No' => '90101',
], 'kvt', 'sales', $windows);
consus_apply_ledger_row($items, [
    'Item_No' => 'A2',
    'Quantity' => -3,
    'Posting_Date' => '2026-09-12',
    'Location_Code' => 'BYKLANT',
    'Purchasing_Code' => 'DROP_SHIP',
], 'kvt', 'consumption', $windows);
consus_apply_ledger_row($items, [
    'Item_No' => 'A2',
    'Quantity' => -4,
    'Sales_Amount_Actual' => 40,
    'Posting_Date' => '2026-09-12',
    'Location_Code' => 'LOC-ZZ',
    'Vendor_No' => '90052',
], 'kvt', 'sales', $windows);
consus_apply_vendor_row($items, [
    'No' => 'A2',
    'Vendor_No' => 'ANDERS',
    'Vendor_Name' => 'Andere leverancier',
], 'kvt');
consus_apply_dimension_row($items, [
    'No' => 'A2',
    'Dimension_Code' => '15',
    'Dimension_Value_Code' => 'WERK',
], 'kvt');
consus_apply_dimension_row($items, [
    'No' => 'A1',
    'Dimension_Code' => '15',
    'Dimension_Value_Code' => 'MAG-DIM',
], 'kvt');
consus_apply_stock_row($items, [
    'Item_No' => 'B1',
    'Company_Name' => 'Hunter van Twist',
    'Inventory' => 90,
    'Safety_Stock_Quantity' => 9,
    'Reorder_Point' => 8,
], 'Hunter van Twist');
consus_apply_vendor_row($items, [
    'No' => 'B1',
    'Vendor_No' => 'PERK',
    'LVS_Vendor_Name' => 'Perkins Engines',
], 'hvt');
consus_apply_ledger_row($items, [
    'Item_No' => 'B1',
    'Quantity' => -9,
    'Sales_Amount_Actual' => 90,
    'Posting_Date' => '2026-09-03',
    'Location_Code' => 'HVT',
], 'hvt', 'sales', $windows);

test_assert(!isset($items['kvt|NIET-IN-VOORRAAD']), 'artikelkaart zonder voorraad of posten telt niet mee');
test_assert((float) $items['kvt|A1']['inventory'] === 17.0, 'KVT-duplicaat telt niet op, M100 blijft wel');
test_assert((string) $items['kvt|A1']['cost_center'] === 'MAG', 'COST_CENTER op de artikelkaart wint van de dimensie');
test_assert((string) $items['kvt|A2']['cost_center'] === 'WERK', 'dimensiewaarde vult een lege kostenplaats');

$snapshot = test_snapshot($items, $windows);
test_assert(consus_default_vendor_no($snapshot['vendors']) === 'PERK', 'standaardleverancier is Perkins als die er is');
test_assert(!in_array('15', $snapshot['cost_centers'], true), 'dimensiecode 15 is geen afdeling');

$perkinsKvt = consus_summarize($snapshot, 'kvt', 'PERK', '');
test_assert(abs((float) $perkinsKvt['safety_stock'] - 5) < 0.0001, 'veiligheidsvoorraad Perkins KVT');
test_assert(abs((float) $perkinsKvt['reorder_point'] - 3) < 0.0001, 'bestelpunt');
test_assert(abs((float) $perkinsKvt['inventory'] - 17) < 0.0001, 'voorraad inclusief M100');
test_assert(abs((float) $perkinsKvt['sales']['eigen']['m']['qty'] - 6) < 0.0001, 'maandverkoop eigen');
test_assert(abs((float) $perkinsKvt['sales']['eigen']['q']['qty'] - 5) < 0.0001, 'kwartaalverkoop trekt retour af');
test_assert(abs((float) $perkinsKvt['sales']['eigen']['m']['amount'] - 600) < 0.0001, 'maandomzet');
test_assert(abs((float) $perkinsKvt['sales']['egt']['m']['qty'] - 2) < 0.0001, 'leverancier 90101 is EGT, ook op M100');
test_assert(!isset($perkinsKvt['sales']['overig']), 'locatie maakt geen overig-bucket');
test_assert(abs((float) $perkinsKvt['turnover']['egt']['m'] - (2 / 17)) < 0.0001, 'omloopsnelheid EGT deelt door alle voorraad');
test_assert(!isset($perkinsKvt['turnover']['dropship']), 'dropship heeft geen omloopsnelheid');

$perkinsKvtWarehouse = consus_summarize($snapshot, 'kvt', 'PERK', '', 'KVT');
test_assert(abs((float) $perkinsKvtWarehouse['inventory'] - 10) < 0.0001, 'locatiefilter KVT laat M100-voorraad weg');
test_assert(abs((float) $perkinsKvtWarehouse['turnover']['eigen']['m'] - 0.6) < 0.0001, 'omloopsnelheid eigen maand');
test_assert(abs((float) $perkinsKvtWarehouse['turnover']['eigen']['y'] - 0.5) < 0.0001, 'omloopsnelheid eigen jaar');
test_assert(abs((float) $perkinsKvtWarehouse['sales']['egt']['m']['qty']) < 0.0001, 'EGT-verkoop op M100 valt buiten locatie KVT');

$projectBin = consus_summarize($snapshot, 'kvt', 'PERK', '', 'M100');
test_assert(abs((float) $projectBin['inventory'] - 7) < 0.0001, 'M-locatie blijft in de cache');

$bothCompanies = consus_summarize($snapshot, '', 'PERK', '');
test_assert(abs((float) $bothCompanies['inventory'] - 107) < 0.0001, 'KVT en HVT tellen samen');
test_assert(abs((float) $bothCompanies['sales']['eigen']['m']['qty'] - 15) < 0.0001, 'eigen verkoop van beide bedrijven');

$department = consus_summarize($snapshot, 'kvt', '', 'WERK');
test_assert(abs((float) $department['sales']['dropship']['m']['qty'] - 4) < 0.0001, 'afdelingsfilter en dropship-leverancier');
test_assert(abs((float) $department['consumption']['dropship']['m']['qty'] - 3) < 0.0001, 'DROP_SHIP-verbruik in de afdeling');
test_assert((int) $department['item_count'] === 1, 'afdelingsfilter laat andere afdelingen weg');
$departmentVendors = consus_vendor_options($snapshot['rows'], 'kvt', 'WERK');
test_assert(array_column($departmentVendors, 'vendor_no') === ['ANDERS'], 'leveranciers volgen de gekozen afdeling');
$departmentLocations = consus_location_options($snapshot['rows'], 'kvt', 'WERK', '');
test_assert($departmentLocations === ['BYKLANT', 'LOC-ZZ'], 'locaties volgen de gekozen afdeling');

$allDepartments = consus_summarize($snapshot, 'kvt', '', '');
test_assert((int) $allDepartments['item_count'] === 2, 'lege afdeling betekent alle afdelingen');

$low = consus_new_item_fact('kvt', 'L1', 'Koninklijke van Twist');
$low['vendor_no'] = 'A';
$low['vendor_name'] = 'A';
$low['by_location'][''] = [
    'inventory' => 10,
    'safety_stock' => 0,
    'reorder_point' => 0,
    'sales' => consus_empty_bucket_map(),
    'consumption' => consus_empty_bucket_map(),
];
$low['by_location']['']['sales']['eigen']['m']['qty'] = 10;
$high = consus_new_item_fact('kvt', 'L2', 'Koninklijke van Twist');
$high['vendor_no'] = 'B';
$high['vendor_name'] = 'B';
$high['by_location'][''] = [
    'inventory' => 90,
    'safety_stock' => 0,
    'reorder_point' => 0,
    'sales' => consus_empty_bucket_map(),
    'consumption' => consus_empty_bucket_map(),
];
$high['by_location']['']['sales']['eigen']['m']['qty'] = 10;
$combined = consus_summarize(test_snapshot(['kvt|L1' => $low, 'kvt|L2' => $high], $windows), 'kvt', '', '');
test_assert(abs((float) $combined['turnover']['eigen']['m'] - 0.2) < 0.0001, 'omloopsnelheid deelt totalen, niet een gemiddelde van ratio\'s');

$empty = consus_summarize(consus_empty_snapshot(), '', '', '');
test_assert((float) $empty['inventory'] === 0.0, 'lege cache heeft geen voorraad');
test_assert($empty['turnover']['eigen']['y'] === null, 'delen door nul geeft geen ratio');
test_assert(consus_default_vendor_no([]) === '', 'zonder Perkins geen defaultleverancier');

$missingHvt = consus_missing_company_records(
    [['company' => 'Koninklijke van Twist', 'company_key' => 'kvt']],
    ['HVT-omgeving niet bereikbaar']
);
test_assert(array_column($missingHvt['company_stats'], 'company_key') === ['hvt'], 'ontbrekend bedrijf wordt stale');
test_assert($missingHvt['company_stats'][0]['stale'] === true, 'stale-vlag');
test_assert(str_contains($missingHvt['errors'][0]['error'], 'HVT-omgeving niet bereikbaar'), 'discoveryfout blijft bij het bedrijf');
$noneMissing = consus_missing_company_records([
    ['company' => 'Koninklijke van Twist', 'company_key' => 'kvt'],
    ['company' => 'Hunter van Twist', 'company_key' => 'hvt'],
]);
test_assert($noneMissing['company_stats'] === [], 'gevonden bedrijven zijn niet stale');

$temp = sys_get_temp_dir() . '/consus-snapshot-' . getmypid() . '.json';
putenv('CONSUS_SNAPSHOT_FILE=' . $temp);
@unlink($temp);
$missing = consus_read_snapshot();
test_assert($missing['generated_at'] === '', 'ontbrekend bestand is een lege snapshot');
test_assert($missing['rows'] === [], 'ontbrekend bestand heeft geen rijen');
consus_write_snapshot($snapshot);
$stored = consus_read_snapshot();
test_assert($stored['generated_at'] === $snapshot['generated_at'], 'snapshot rondreis');
@unlink($temp);
@unlink($temp . '.lock');
putenv('CONSUS_SNAPSHOT_FILE');

$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
test_assert(!str_contains($index, 'odata_get'), 'index.php doet geen OData-call');
test_assert(!str_contains($index, 'curl_'), 'index.php gebruikt geen cURL');
test_assert(!str_contains($index, 'consus_run_nightly'), 'index.php start geen BC-refresh');
test_assert(!str_contains($index, 'Perkins'), 'index.php zet Perkins niet vast');
test_assert(str_contains((string) file_get_contents(__DIR__ . '/../web/nightly.php'), 'consus_run_nightly'), 'nightly.php is de refresh');

echo "OK\n";
