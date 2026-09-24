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

test_assert(consus_bucket_for_location('magazijn') === 'eigen', 'eigen locatie uit config');
test_assert(consus_bucket_for_location('EGT') === 'egt', 'EGT locatie uit config');
test_assert(consus_bucket_for_location('DROP') === 'dropship', 'dropship locatie uit config');
test_assert(consus_bucket_for_location('ZZ-ONBEKEND') === 'onbekend', 'onbekende locatie blijft zichtbaar');

$salesQuery = consus_ledger_query(CONSUS_SALES_ENTRY_TYPES, '2025-10-01');
test_assert(
    $salesQuery['$filter'] === "Entry_Type eq 'Sale' and Posting_Date ge 2025-10-01",
    'verkoopfilter komt uit config'
);
test_assert(!str_contains($salesQuery['$filter'], 'Perkins'), 'verkoopfilter niet vast op leverancier');
test_assert(!str_contains($salesQuery['$filter'], 'COST_CENTER'), 'verkoopfilter niet vast op afdeling');

$woQuery = consus_ledger_query(CONSUS_WO_ENTRY_TYPES, '2025-10-01');
test_assert(
    $woQuery['$filter'] === "Entry_Type eq 'Assembly Consumption' and Posting_Date ge 2025-10-01",
    'werkorderverbruik gebruikt Assembly Consumption'
);
test_assert(!str_contains($woQuery['$filter'], "Entry_Type eq 'Consumption'"), 'kale Consumption is niet de default');

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
$unmapped = [];
consus_apply_stock_row($items, [
    'Item_No' => 'A1',
    'Company_Name' => 'Koninklijke van Twist',
    'Inventory' => 10,
    'Safety_Stock_Quantity' => 4,
    'Reorder_Point' => 2,
], 'Koninklijke van Twist');
consus_apply_stock_row($items, [
    'Item_No' => 'A1',
    'Company_Name' => 'Koninklijke van Twist',
    'Inventory' => 99,
    'Safety_Stock_Quantity' => 99,
    'Reorder_Point' => 99,
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
    'Location_Code' => 'MAGAZIJN',
], 'kvt', 'sales', $windows, $unmapped);
consus_apply_ledger_row($items, [
    'Item_No' => 'A1',
    'Quantity' => 1,
    'Sales_Amount_Actual' => -50,
    'Posting_Date' => '2026-08-02',
    'Location_Code' => 'MAGAZIJN',
], 'kvt', 'sales', $windows, $unmapped);
consus_apply_ledger_row($items, [
    'Item_No' => 'A1',
    'Quantity' => -2,
    'Sales_Amount_Actual' => 200,
    'Posting_Date' => '2026-09-11',
    'Location_Code' => 'EGT',
], 'kvt', 'sales', $windows, $unmapped);
consus_apply_ledger_row($items, [
    'Item_No' => 'A2',
    'Quantity' => -3,
    'Posting_Date' => '2026-09-12',
    'Location_Code' => 'DROP',
], 'kvt', 'consumption', $windows, $unmapped);
consus_apply_ledger_row($items, [
    'Item_No' => 'A2',
    'Quantity' => -4,
    'Sales_Amount_Actual' => 40,
    'Posting_Date' => '2026-09-12',
    'Location_Code' => 'LOC-ZZ',
], 'kvt', 'sales', $windows, $unmapped);
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
], 'hvt', 'sales', $windows, $unmapped);

test_assert(isset($unmapped['LOC-ZZ']), 'onbekende locatie wordt gemeld');
test_assert(!isset($items['kvt|NIET-IN-VOORRAAD']), 'artikelkaart zonder voorraad of posten telt niet mee');
test_assert((float) $items['kvt|A1']['inventory'] === 10.0, 'dubbele voorraadregel telt niet op');
test_assert((string) $items['kvt|A1']['cost_center'] === 'MAG', 'COST_CENTER op de artikelkaart wint van de dimensie');
test_assert((string) $items['kvt|A2']['cost_center'] === 'WERK', 'dimensiewaarde vult een lege kostenplaats');

$snapshot = test_snapshot($items, $windows);
test_assert(consus_default_vendor_no($snapshot['vendors']) === 'PERK', 'standaardleverancier is Perkins als die er is');
test_assert(!in_array('15', $snapshot['cost_centers'], true), 'dimensiecode 15 is geen afdeling');

$perkinsKvt = consus_summarize($snapshot, 'kvt', 'PERK', '');
test_assert(abs((float) $perkinsKvt['safety_stock'] - 4) < 0.0001, 'veiligheidsvoorraad Perkins KVT');
test_assert(abs((float) $perkinsKvt['reorder_point'] - 2) < 0.0001, 'bestelpunt');
test_assert(abs((float) $perkinsKvt['inventory'] - 10) < 0.0001, 'voorraad');
test_assert(abs((float) $perkinsKvt['sales']['eigen']['m']['qty'] - 6) < 0.0001, 'maandverkoop eigen');
test_assert(abs((float) $perkinsKvt['sales']['eigen']['q']['qty'] - 5) < 0.0001, 'kwartaalverkoop trekt retour af');
test_assert(abs((float) $perkinsKvt['sales']['eigen']['m']['amount'] - 600) < 0.0001, 'maandomzet');
test_assert(abs((float) $perkinsKvt['sales']['egt']['m']['qty'] - 2) < 0.0001, 'maandverkoop EGT');
test_assert(abs((float) $perkinsKvt['turnover']['eigen']['m'] - 0.6) < 0.0001, 'omloopsnelheid eigen maand');
test_assert(abs((float) $perkinsKvt['turnover']['egt']['m'] - 0.2) < 0.0001, 'omloopsnelheid EGT maand');
test_assert(abs((float) $perkinsKvt['turnover']['eigen']['y'] - 0.5) < 0.0001, 'omloopsnelheid eigen jaar');
test_assert(!isset($perkinsKvt['turnover']['dropship']), 'dropship heeft geen omloopsnelheid');

$bothCompanies = consus_summarize($snapshot, '', 'PERK', '');
test_assert(abs((float) $bothCompanies['inventory'] - 100) < 0.0001, 'KVT en HVT tellen samen');
test_assert(abs((float) $bothCompanies['sales']['eigen']['m']['qty'] - 15) < 0.0001, 'eigen verkoop van beide bedrijven');

$department = consus_summarize($snapshot, 'kvt', '', 'WERK');
test_assert(abs((float) $department['sales']['onbekend']['m']['qty'] - 4) < 0.0001, 'afdelingsfilter gebruikt de kostenplaats');
test_assert((int) $department['item_count'] === 1, 'afdelingsfilter laat andere afdelingen weg');

$allDepartments = consus_summarize($snapshot, 'kvt', '', '');
test_assert((int) $allDepartments['item_count'] === 2, 'lege afdeling betekent alle afdelingen');

$low = consus_new_item_fact('kvt', 'L1', 'Koninklijke van Twist');
$low['vendor_no'] = 'A';
$low['vendor_name'] = 'A';
$low['inventory'] = 10;
$low['sales']['eigen']['m']['qty'] = 10;
$high = consus_new_item_fact('kvt', 'L2', 'Koninklijke van Twist');
$high['vendor_no'] = 'B';
$high['vendor_name'] = 'B';
$high['inventory'] = 90;
$high['sales']['eigen']['m']['qty'] = 10;
$combined = consus_summarize(test_snapshot(['kvt|L1' => $low, 'kvt|L2' => $high], $windows), 'kvt', '', '');
test_assert(abs((float) $combined['turnover']['eigen']['m'] - 0.2) < 0.0001, 'omloopsnelheid deelt totalen, niet een gemiddelde van ratio\'s');

$empty = consus_summarize(consus_empty_snapshot(), '', '', '');
test_assert((float) $empty['inventory'] === 0.0, 'lege cache heeft geen voorraad');
test_assert($empty['turnover']['eigen']['y'] === null, 'delen door nul geeft geen ratio');
test_assert(consus_default_vendor_no([]) === '', 'zonder Perkins geen defaultleverancier');

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
