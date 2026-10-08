<?php

$dir = sys_get_temp_dir() . '/consus-retour-test-' . getmypid();
@mkdir($dir, 0700, true);
putenv('CONSUS_RETOUR_SETTINGS_FILE=' . $dir . '/retour_settings.json');
putenv('CONSUS_RETOUR_FILE=' . $dir . '/consus_retour.json');

require_once __DIR__ . '/../web/consus_retour_fetch.php';
require_once __DIR__ . '/../web/consus_retour_page.php';

const KVT = 'Koninklijke van Twist';
const HVT = 'Hunter van Twist';

function retour_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Classificatie (Ivan, 08-10-2026): alleen EGT=true + CSV=false is spoed.
retour_assert(consus_retour_classify(true, false) === '57401', 'EGT aan, CSV uit is spoed');
retour_assert(consus_retour_classify(false, true) === '57420', 'CSV is voorraad');
retour_assert(consus_retour_classify(true, true) === '57420', 'EGT en CSV samen is voorraad');
retour_assert(consus_retour_classify(false, false) === '57420', 'geen vlag is voorraad');
retour_assert(consus_retour_classify(null, null) === '57420', 'onbekende vlaggen zijn voorraad');
retour_assert(consus_retour_classify(true, null) === '57420', 'ontbrekende CSV-vlag is voorraad');
foreach ([true, 'true', 'Ja', 'yes', 1, '1'] as $yes) {
    retour_assert(consus_retour_bool($yes), 'waar: ' . var_export($yes, true));
}
foreach ([false, 'false', 'Nee', 'No', 0, '', null] as $no) {
    retour_assert(!consus_retour_bool($no), 'onwaar: ' . var_export($no, true));
}
retour_assert(consus_retour_is_item_line(['Type' => 'Artikel', 'No' => 'PK-1']), 'Nederlands regeltype telt');
retour_assert(!consus_retour_is_item_line(['Type' => ' ', 'No' => '', 'Description' => 'Order No. PO1:']), 'tekstregel valt weg');
retour_assert(!consus_retour_is_item_line(['Type' => 'G/L Account', 'No' => '460200']), 'grootboekregel valt weg');
retour_assert(consus_retour_dutch_date('2026-07-17') === '17 juli 2026', 'Nederlandse datum');

// Instellingen: bij livegang geen regels, oude vorm wordt genegeerd, garantie blijft.
file_put_contents(getenv('CONSUS_RETOUR_SETTINGS_FILE'), json_encode(['departments' => ['__default' => ['min_value' => 50, 'window_spoed' => 30, 'window_voorraad' => 90, 'start_date' => '2026-07-17'], '15' => ['min_value' => 10]], 'garantie_orders' => ['PO52600987']]));
$settings = consus_retour_settings_read();
retour_assert($settings['rules'] === [], 'oude instellingen geven geen regels, ook niet voor Perkins (15)');
retour_assert($settings['garantie_orders'] === [HVT => ['PO52600987'], KVT => ['PO52600987']], 'PO52600987 blijft garantie na migratie (oude lijst had geen bedrijf)');
retour_assert(!str_contains(json_encode($settings), 'start_date'), 'startdatum bestaat niet meer');
unlink(getenv('CONSUS_RETOUR_SETTINGS_FILE'));
$settings = consus_retour_settings_read();
retour_assert($settings['rules'] === [] && $settings['garantie_orders'][HVT] === ['PO52600987'] && $settings['garantie_lines'] === [], 'leeg bestand: geen regels, standaard garantie-PO');
retour_assert(consus_retour_rules_for($settings, KVT, '15') === [], 'afdeling 15 heeft standaard geen regels');
retour_assert(consus_retour_rule_vendors($settings) === [], 'zonder regels geen leveranciers voor de nightly');

$settings = consus_retour_rule_save(KVT, '15', ['vendor' => '90101', 'type' => '57401', 'window' => '30', 'min_value' => '50']);
$settings = consus_retour_rule_save(KVT, '015', ['vendor' => ' 90101 ', 'type' => '57420', 'window' => '90', 'min_value' => '50,00']);
$rules15 = consus_retour_rules_for($settings, KVT, '15');
retour_assert(count($rules15) === 2, 'twee regels voor afdeling 15 (015 = 15)');
retour_assert($rules15[0]['vendor'] === '90101' && $rules15[0]['type'] === '57401' && $rules15[0]['window'] === 30 && $rules15[0]['min_value'] === 50.0, 'spoedregel opgeslagen');
retour_assert($rules15[1]['type'] === '57420' && $rules15[1]['window'] === 90 && $rules15[1]['min_value'] === 50.0, 'voorraadregel opgeslagen, komma gelezen');
retour_assert(consus_retour_rules_for($settings, KVT, '80') === [], 'andere afdeling blijft leeg (per afdeling, niet per persoon)');
$settings = consus_retour_rule_save(KVT, '80', ['vendor' => '70001', 'type' => 'Alle', 'window' => '60', 'min_value' => '1.234,50']);
retour_assert(consus_retour_rules_for($settings, KVT, '80')[0]['type'] === '' && consus_retour_rules_for($settings, KVT, '80')[0]['min_value'] === 1234.5, 'type Alle is leeg, NL-bedrag gelezen');
retour_assert(consus_retour_rule_vendors($settings) === ['70001', '90101'], 'nightly-leveranciers uit alle afdelingen');
$id80 = consus_retour_rules_for($settings, KVT, '80')[0]['id'];
$settings = consus_retour_rule_save(KVT, '80', ['vendor' => '70001', 'type' => '', 'window' => '45', 'min_value' => '25'], $id80);
retour_assert(count(consus_retour_rules_for($settings, KVT, '80')) === 1 && consus_retour_rules_for($settings, KVT, '80')[0]['window'] === 45, 'bewerken vervangt de regel');
$settings = consus_retour_rule_delete(KVT, '80', $id80);
retour_assert(!isset($settings['rules']['80']), 'verwijderen haalt de regel weg');
foreach ([['vendor' => '', 'window' => 30, 'min_value' => 1], ['vendor' => '90101', 'window' => 0, 'min_value' => 1], ['vendor' => '90101', 'window' => 400, 'min_value' => 1], ['vendor' => '90101', 'window' => 30, 'min_value' => 'x']] as $bad) {
    $threw = false;
    try {
        consus_retour_rule_save(KVT, '15', $bad);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    retour_assert($threw, 'ongeldige regel geweigerd: ' . json_encode($bad));
}
foreach (['', '__none__', '__default'] as $badDepartment) {
    $threw = false;
    try {
        consus_retour_rule_save(KVT, $badDepartment, ['vendor' => '90101', 'window' => 30, 'min_value' => 1]);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    retour_assert($threw, 'regel zonder echte afdeling geweigerd');
}
retour_assert(count(consus_retour_settings_read()['rules'][KVT]['15']) === 2, 'opgeslagen op schijf, gedeeld');
retour_assert(consus_retour_is_garantie('po52600987', $settings, HVT), 'garantie-PO herkend, hoofdletterongevoelig');
retour_assert(consus_retour_vendor_types('90101') === ['57401', '57420'] && consus_retour_vendor_types('70001') === [], 'type-provider: alleen Perkins kent 57401/57420');
retour_assert(consus_retour_type_options([], $settings) === ['', '57401', '57420'], 'zonder nightly: Alle plus types uit opgeslagen regels');
retour_assert(consus_retour_type_options(['types_by_vendor' => ['90101' => ['57401', '57420']]], consus_retour_normalize_settings([])) === ['', '57401', '57420'], 'types uit de data');
retour_assert(consus_retour_rule_hint($rules15[0], []) === 'Gegevens volgen na de volgende nightly.', 'hint zonder data');
retour_assert(str_contains(consus_retour_rule_hint(['vendor' => '70001', 'type' => '57401', 'window' => 30, 'min_value' => 1.0], ['generated_at' => 'x', 'vendors' => ['70001'], 'types_by_vendor' => ['70001' => []]]), 'vindt niets'), 'hint bij type dat de leverancier niet kent');

// Tekstregel als PO-bron als Order_No leeg is.
$headers = ['PI1' => ['vendor_invoice' => '90243586', 'document_date' => '2026-09-11']];
$compact = consus_retour_compact_lines([
    ['Document_No' => 'PI1', 'Line_No' => 20000, 'Type' => 'Item', 'No' => 'PK-A', 'Quantity' => 1, 'Direct_Unit_Cost' => 10, 'Order_No' => ''],
    ['Document_No' => 'PI1', 'Line_No' => 10000, 'Type' => ' ', 'No' => '', 'Description' => 'Order No. PO52600946:'],
    ['Document_No' => 'PI2', 'Line_No' => 10000, 'Type' => 'Item', 'No' => 'PK-B', 'Quantity' => 1],
], $headers);
retour_assert(count($compact) === 1 && $compact[0]['order_no'] === 'PO52600946', 'PO uit tekstregel, onbekende factuur valt weg');
retour_assert($compact[0]['vendor_invoice'] === '90243586', 'Perkins-factuurnummer van de kop');

// Reserveringen: retourorder apart herkend, tracking telt niet.
$reservations = consus_retour_reservations([
    ['Entry_No' => 1, 'Positive' => true, 'Item_No' => 'pk-t438898', 'Quantity_Base' => 1, 'Source_Type' => 32, 'Reservation_Status' => 'Reservation'],
    ['Entry_No' => 1, 'Positive' => false, 'Item_No' => 'PK-T438898', 'Quantity_Base' => -1, 'Source_Type' => 39, 'Source_Subtype' => '5', 'Reservation_Status' => 'Reservation'],
    ['Entry_No' => 2, 'Positive' => true, 'Item_No' => 'PK-T438898', 'Quantity_Base' => 2, 'Source_Type' => 32, 'Reservation_Status' => 'Reservering'],
    ['Entry_No' => 3, 'Positive' => true, 'Item_No' => 'PK-T438898', 'Quantity_Base' => 5, 'Source_Type' => 32, 'Reservation_Status' => 'Tracking'],
]);
retour_assert($reservations['PK-T438898'] === ['reserved' => 3.0, 'reserved_return' => 1.0], 'reserveringen per artikel');

// Kandidaten.
$item = static fn (array $extra = []): array => $extra + ['description' => '', 'safety_stock' => 0, 'stock' => ['HVT' => 5], 'bin' => ['HVT' => 5], 'reserved' => 0, 'reserved_return' => 0, 'tariff' => '', 'origin' => ''];
$line = static fn (string $invoice, string $date, string $no, float $qty, float $cost, string $po, string $dept = '15', string $vendor = '90101'): array => [
    'invoice' => $invoice, 'vendor' => $vendor, 'vendor_invoice' => 'V' . $invoice, 'document_date' => $date, 'item' => $no, 'description' => $no,
    'quantity' => $qty, 'unit_cost' => $cost, 'order_no' => $po, 'department' => $dept,
];
$data = [
    'generated_at' => '2026-10-08T02:00:00+00:00',
    'bin_locations' => ['HVT'],
    'vendors' => ['90101', '70001'],
    'types_by_vendor' => ['90101' => ['57401', '57420'], '70001' => []],
    'lines' => [
        $line('PI10', '2026-09-20', 'SUM', 1, 30, 'POS'),
        $line('PI10', '2026-09-20', 'SUM', 1, 30, 'POS'),
        $line('PI10', '2026-09-20', 'LOW', 1, 40, 'POS'),
        $line('PI11', '2026-09-07', 'OLDSPOED', 1, 100, 'POS'),
        $line('PI12', '2026-09-08', 'EDGE', 1, 100, 'POS'),
        $line('PI13', '2026-08-09', 'STOCK', 1, 100, 'POV'),
        // Oud (vroeger "voor de startdatum"), maar binnen 90 dagen: telt nu mee.
        $line('PI14', '2026-07-16', 'EARLY', 1, 100, 'POV'),
        $line('PI14B', '2026-07-01', 'TOOOLD', 1, 100, 'POV'),
        $line('PI15', '2026-09-20', 'SAFE', 1, 100, 'POV'),
        $line('PI15', '2026-09-20', 'RES', 1, 100, 'POV'),
        $line('PI16', '2026-09-20', 'WARR', 1, 100, 'PO52600987'),
        $line('PI16', '2026-09-20', 'MARKED', 1, 100, 'POV'),
        $line('PI17', '2026-09-20', 'PHANTOM', 1, 100, 'POV'),
        $line('PI17', '2026-09-20', 'VAN', 1, 100, 'POV'),
        $line('PI18', '2026-08-20', 'GONE', 1, 100, 'POGONE'),
        $line('PI19', '2026-09-01', 'SPLIT', 1, 100, 'POV'),
        $line('PI20', '2026-09-25', 'SPLIT', 1, 100, 'POV'),
        $line('PI21', '2026-09-20', 'DEPT80', 1, 100, 'POV', '80'),
        // Andere leverancier zonder regel in 15.
        $line('PI22', '2026-09-20', 'OTHERV', 1, 100, 'POX', '15', '70001'),
    ],
    'orders' => [
        'POS' => ['found' => true, 'egt' => true, 'csv' => false],
        'POV' => ['found' => true, 'egt' => false, 'csv' => true],
        'PO52600987' => ['found' => true, 'egt' => false, 'csv' => false],
    ],
    'items' => [
        'SUM' => $item(), 'LOW' => $item(), 'OLDSPOED' => $item(), 'EDGE' => $item(), 'STOCK' => $item(), 'EARLY' => $item(), 'TOOOLD' => $item(),
        'SAFE' => $item(['safety_stock' => 2]), 'RES' => $item(['stock' => ['HVT' => 1], 'reserved' => 1]),
        'WARR' => $item(), 'MARKED' => $item(), 'PHANTOM' => $item(['bin' => []]), 'VAN' => $item(['stock' => ['M602' => 1], 'bin' => []]),
        'GONE' => $item(), 'SPLIT' => $item(['stock' => ['HVT' => 1], 'bin' => ['HVT' => 1]]), 'DEPT80' => $item(), 'OTHERV' => $item(),
    ],
];
// Geen regels: niets.
$none = consus_retour_candidates($data, consus_retour_normalize_settings([]), '2026-10-08', KVT, '15');
retour_assert($none['rows'] === [] && $none['garantie'] === [], 'zonder regels geen kandidaten');
// Garantie via de modal (globaal) op MARKED.
$settings = consus_retour_garantie_set(KVT, 'PI16', 'marked', ['POV'], true);
retour_assert(in_array('PI16|MARKED', consus_retour_garantie_lines(consus_retour_settings_read(), KVT), true), 'garantiemarkering opgeslagen');
$result = consus_retour_candidates($data, $settings, '2026-10-08', KVT, '15');
$byItem = [];
foreach ($result['rows'] as $row) {
    $byItem[$row['item'] . '@' . $row['invoice']] = $row;
}
retour_assert(isset($byItem['SUM@PI10']) && abs($byItem['SUM@PI10']['value'] - 60.0) < 0.001 && $byItem['SUM@PI10']['return_qty'] == 2.0, '€50 per artikel per factuur: regels opgeteld');
retour_assert($byItem['SUM@PI10']['account'] === '57401' && $byItem['SUM@PI10']['vendor_invoice'] === 'VPI10' && $byItem['SUM@PI10']['vendor'] === '90101', 'spoed en leveranciersfactuur');
retour_assert($byItem['SUM@PI10']['rule']['type'] === '57401' && $byItem['SUM@PI10']['window'] === 30, 'spoedregel gebruikt');
retour_assert(!isset($byItem['LOW@PI10']), 'onder €50 valt weg');
retour_assert(!isset($byItem['OLDSPOED@PI11']), 'spoed na 30 dagen verlopen');
retour_assert(isset($byItem['EDGE@PI12']) && $byItem['EDGE@PI12']['days_left'] === 0 && $byItem['EDGE@PI12']['days_since'] === 30, 'dag 30 telt nog mee');
retour_assert(isset($byItem['STOCK@PI13']) && $byItem['STOCK@PI13']['account'] === '57420' && $byItem['STOCK@PI13']['days_left'] === 30, 'voorraad 90 dagen');
retour_assert(isset($byItem['EARLY@PI14']), 'geen startdatumfilter meer');
retour_assert(!isset($byItem['TOOOLD@PI14B']), 'voorraad na 90 dagen verlopen');
retour_assert(!isset($byItem['SAFE@PI15']) && !isset($byItem['RES@PI15']), 'veiligheidsvoorraad en reservering vallen weg');
retour_assert(!isset($byItem['DEPT80@PI21']), 'andere afdeling valt weg');
retour_assert(!isset($byItem['OTHERV@PI22']), 'leverancier zonder regel valt weg');
retour_assert($byItem['PHANTOM@PI17']['status'] === 'controleren' && str_contains($byItem['PHANTOM@PI17']['status_label'], 'geen bin-inhoud'), 'geen bin-inhoud: controleren');
retour_assert($byItem['VAN@PI17']['status'] === 'geen_bincontrole', 'M602: geen bincontrole');
retour_assert(isset($byItem['GONE@PI18']) && $byItem['GONE@PI18']['account'] === '57420' && str_contains($byItem['GONE@PI18']['status_label'], 'niet meer in BC'), 'order weg: voorraad');
retour_assert(isset($byItem['SPLIT@PI20']) && !isset($byItem['SPLIT@PI19']), 'nieuwste factuur krijgt de vrije voorraad');
$garantieItems = array_column($result['garantie'], 'item');
sort($garantieItems);
retour_assert($garantieItems === ['MARKED', 'WARR'], 'garantie: PO52600987 en de handmatige markering, apart onderaan');
retour_assert(!isset($byItem['WARR@PI16']) && !isset($byItem['MARKED@PI16']), 'garantie is geen kandidaat');
retour_assert(str_contains(consus_retour_reason($byItem['SUM@PI10']), 'Retourwaarde € 60,00'), 'uitleg in de modal');
// Opheffen haalt ook de PO van de garantielijst.
$settings = consus_retour_garantie_set(KVT, 'PI16', 'WARR', ['PO52600987'], false);
retour_assert($settings['garantie_orders'][KVT] === [] && consus_retour_garantie_lines(consus_retour_settings_read(), KVT) === ['PI16|MARKED'], 'garantie opheffen');
$settings = consus_retour_garantie_set(KVT, 'PI16', 'WARR', ['PO52600987'], true);
retour_assert(count(consus_retour_candidates($data, $settings, '2026-10-08', KVT, '15')['garantie']) === 2, 'garantie weer aan');

// Type "Alle": één regel voor alle facturen van de leverancier, alleen termijn + minimum.
$alle = consus_retour_normalize_settings(['rules' => ['15' => [['vendor' => '90101', 'type' => '', 'window' => 30, 'min_value' => 50]], '80' => [['vendor' => '90101', 'type' => '57420', 'window' => 90, 'min_value' => 50]]]]);
$alleResult = consus_retour_candidates($data, $alle, '2026-10-08', KVT, '15');
$alleItems = array_column($alleResult['rows'], 'item');
retour_assert(in_array('SUM', $alleItems, true) && in_array('SPLIT', $alleItems, true) && !in_array('STOCK', $alleItems, true), 'type Alle: 30 dagen voor alles');
// Type 57401 bij leverancier zonder types: matcht niets.
$wrong = consus_retour_normalize_settings(['rules' => ['15' => [['vendor' => '70001', 'type' => '57401', 'window' => 30, 'min_value' => 1]]]]);
retour_assert(consus_retour_candidates($data, $wrong, '2026-10-08', KVT, '15')['rows'] === [], 'type bij leverancier zonder types matcht niets');
$alle70 = consus_retour_normalize_settings(['rules' => ['15' => [['vendor' => '70001', 'type' => '', 'window' => 30, 'min_value' => 1]]]]);
retour_assert(array_column(consus_retour_candidates($data, $alle70, '2026-10-08', KVT, '15')['rows'], 'item') === ['OTHERV'], 'Alle bij andere leverancier');
// Alle afdelingen (export): 80 eigen regel.
$all = consus_retour_candidates($data, $alle, '2026-10-08', KVT);
retour_assert(in_array('DEPT80', array_column($all['rows'], 'item'), true) && in_array('SUM', array_column($all['rows'], 'item'), true), 'export zonder afdeling: alle afdelingen met regels');
// Oude data zonder leverancier per regel.
file_put_contents(getenv('CONSUS_RETOUR_FILE'), json_encode(['generated_at' => '2026-10-08T02:00:00+00:00', 'lines' => [['invoice' => 'X']]]));
$legacy = consus_retour_read_data(HVT);
retour_assert(consus_retour_read_data(KVT)['generated_at'] === '', 'oude Hunter-data telt niet voor KVT');
retour_assert($legacy['vendors'] === ['90101'] && $legacy['lines'][0]['vendor'] === '90101', 'oude data telt als Perkins');

$sheet = consus_retour_export_sheet($result);
retour_assert($sheet['rows'][0][0] === 'Leverancier' && in_array('Leveranciersfactuur', $sheet['rows'][0], true) && in_array('Dagen over', $sheet['rows'][0], true) && !in_array('Perkins-factuur', $sheet['rows'][0], true), 'exportkop');
retour_assert(count($sheet['rows']) === count($result['rows']) + count($result['garantie']) + 1, 'export bevat kandidaten en garantieregels');
retour_assert($sheet['rows'][1][4] !== '' && !preg_match('/^\d{4}-/', (string) $sheet['rows'][1][4]), 'datum in export is Nederlands');

// Build met nep-BC: vlaggen, kop-filter, archief-fallback.
$calls = [];
$filters = [];
$fake = static function (string $company, string $entitySet, array $required, array $optional, string $filter, callable $onRow) use (&$calls, &$filters): void {
    $calls[] = $entitySet;
    $filters[$entitySet][] = $filter;
    $rows = match ($entitySet) {
        'GeboekteInkoopfacturen' => [['No' => 'PI1', 'Document_Date' => '2026-09-20', 'Vendor_Invoice_No' => '90243586', 'Buy_from_Vendor_No' => '90101'], ['No' => 'PI2', 'Document_Date' => '2026-09-20', 'Vendor_Invoice_No' => 'A1', 'Buy_from_Vendor_No' => '70001']],
        'GeboekteInkoopfactuurRegels' => [
            ['Document_No' => 'PI1', 'Line_No' => 10000, 'Type' => 'Item', 'No' => 'PK-T438898', 'Quantity' => 1, 'Direct_Unit_Cost' => 249.65, 'Order_No' => 'PO52601154', 'Shortcut_Dimension_1_Code' => '15'],
            ['Document_No' => 'PI1', 'Line_No' => 20000, 'Type' => 'Item', 'No' => 'PK-X', 'Quantity' => 1, 'Direct_Unit_Cost' => 60, 'Order_No' => 'PO1', 'Shortcut_Dimension_1_Code' => '15'],
            ['Document_No' => 'PI2', 'Line_No' => 10000, 'Type' => 'Artikel', 'No' => 'AB-1', 'Quantity' => 1, 'Direct_Unit_Cost' => 60, 'Order_No' => 'PO9', 'Shortcut_Dimension_1_Code' => '15'],
        ],
        'AppPurchaseOrder' => [['No' => 'PO52601154', 'KVT_Export_Status_Perkins_EGT' => 'Ja', 'KVT_Export_Status_Perkins_CSV' => 'Nee']],
        'GearchiveerdeInkooporders' => throw new RuntimeException('404 Onbekende tabel'),
        'AppItemCard' => [['No' => 'PK-T438898', 'Safety_Stock_Quantity' => 0], ['No' => 'PK-X', 'Safety_Stock_Quantity' => 0], ['No' => 'AB-1', 'Safety_Stock_Quantity' => 0]],
        'ItemLedgerEntries' => [['Item_No' => 'PK-T438898', 'Remaining_Quantity' => 1, 'Location_Code' => 'KVT'], ['Item_No' => 'PK-X', 'Remaining_Quantity' => 1, 'Location_Code' => 'KVT'], ['Item_No' => 'AB-1', 'Remaining_Quantity' => 1, 'Location_Code' => 'M602']],
        'ReservationEntries' => [
            ['Entry_No' => 9, 'Positive' => true, 'Item_No' => 'PK-T438898', 'Quantity_Base' => 1, 'Source_Type' => 32, 'Reservation_Status' => 'Reservation'],
            ['Entry_No' => 9, 'Positive' => false, 'Item_No' => 'PK-T438898', 'Quantity_Base' => -1, 'Source_Type' => 39, 'Source_Subtype' => '5', 'Reservation_Status' => 'Reservation'],
        ],
        'BinContent' => throw new RuntimeException('traag'),
        'Magazijnposten' => [['Item_No' => 'PK-X', 'Location_Code' => 'KVT', 'Qty_Base' => 1]],
        default => [],
    };
    foreach ($rows as $row) {
        $onRow($row);
    }
};
$noRules = consus_retour_build(KVT, consus_retour_normalize_settings([]), $fake, '2026-10-08');
retour_assert($calls === [] && $noRules['lines'] === [] && $noRules['vendors'] === [], 'zonder regels geen BC-calls');
$buildSettings = consus_retour_normalize_settings(['rules' => [
    '15' => [['vendor' => '90101', 'type' => '57420', 'window' => 90, 'min_value' => 50], ['vendor' => '70001', 'type' => '', 'window' => 30, 'min_value' => 50]],
]]);
$built = consus_retour_build(KVT, $buildSettings, $fake, '2026-10-08');
retour_assert(str_contains($filters['GeboekteInkoopfacturen'][0], "Buy_from_Vendor_No eq '70001'") && str_contains($filters['GeboekteInkoopfacturen'][0], "Buy_from_Vendor_No eq '90101'"), 'leveranciers uit de regels');
retour_assert(str_contains($filters['GeboekteInkoopfacturen'][0], 'Document_Date ge 2026-04-11'), 'ruim venster van 180 dagen');
retour_assert($built['fetched_from'] === '2026-04-11' && $built['vendors'] === ['70001', '90101'], 'venster en leveranciers in de data');
retour_assert($built['types_by_vendor'] === ['70001' => [], '90101' => ['57401', '57420']], 'types per leverancier uit de type-provider');
retour_assert(!str_contains(implode(' ', $filters['AppPurchaseOrder']), 'PO9'), 'geen PO-vlaggen voor leverancier zonder types');
retour_assert($built['orders']['PO52601154'] === ['found' => true, 'egt' => true, 'csv' => false], 'Nederlandse booleans van Mímir');
retour_assert(empty($built['orders']['PO1']['found']), 'onbekende order blijft onbekend');
retour_assert(!in_array('GearchiveerdeInkooporders', $calls, true) && count($built['warnings']) === 1 && str_contains($built['warnings'][0], 'voorraad (57420)'), 'archief heeft geen vlaggen: niet gevraagd, wel een melding');
retour_assert($built['items']['PK-T438898']['reserved_return'] == 1.0, 'retourorder-reservering');
retour_assert($built['items']['PK-X']['bin'] === ['KVT' => 1.0] && $built['bin_locations'] === ['KVT'], 'Magazijnposten als fallback voor bins, KVT-magazijn');
$builtResult = consus_retour_candidates($built, $buildSettings, '2026-10-08', KVT, '15');
$builtItems = array_column($builtResult['rows'], 'item');
sort($builtItems);
retour_assert($builtItems === ['AB-1', 'PK-X'], 'T438898 staat al op retourorder; AB-1 via Alle-regel');
retour_assert(array_column($builtResult['rows'], 'account', 'item')['PK-X'] === '57420' && array_column($builtResult['rows'], 'account', 'item')['AB-1'] === '', 'PO1 zonder vlaggen is voorraad, AB-1 zonder type');


// Type is vrije tekst: leeg/Alle = alles, ongeldige code wordt geweigerd.
$typed = consus_retour_normalize_rule(['vendor' => '90101', 'type' => ' 57401 ', 'window' => 30, 'min_value' => '10']);
retour_assert(is_array($typed) && $typed['type'] === '57401', 'typecode wordt getrimd');
$blankType = consus_retour_normalize_rule(['vendor' => '90101', 'type' => '', 'window' => 30, 'min_value' => '10']);
retour_assert(is_array($blankType) && $blankType['type'] === '', 'leeg type is alles');
$alleType = consus_retour_normalize_rule(['vendor' => '90101', 'type' => 'Alle', 'window' => 30, 'min_value' => '10']);
retour_assert(is_array($alleType) && $alleType['type'] === '', 'Alle is alles');
retour_assert(consus_retour_normalize_rule(['vendor' => '90101', 'type' => '574 01', 'window' => 30, 'min_value' => '10']) === null, 'ongeldige typecode wordt geen alles');
$hintData = ['generated_at' => '2026-10-08T03:00:00+02:00', 'vendors' => ['90101', '12345'], 'types_by_vendor' => []];
retour_assert(consus_retour_rule_hint(['vendor' => '90101', 'type' => '57401', 'window' => 30, 'min_value' => 0.0], $hintData) === '', 'type-provider kent 57401 voor 90101 zonder nightly-types');
retour_assert(str_contains(consus_retour_rule_hint(['vendor' => '12345', 'type' => '57401', 'window' => 30, 'min_value' => 0.0], $hintData), 'kent type 57401 niet'), 'onbekend type bij leverancier geeft hint');
$page = file_get_contents(__DIR__ . '/../web/consus_retour_page.php');
retour_assert(str_contains($page, 'placeholder="blanco = alles"') && !str_contains($page, '<select id="retour-type"'), 'type is een tekstveld');

// Migratie (08-10-2026): de twee Perkins-regels van Ariadne op afdeling 15 (versie 2)
// horen bij Koninklijke van Twist, afdeling 15.
$settingsFile = getenv('CONSUS_RETOUR_SETTINGS_FILE');
file_put_contents($settingsFile, json_encode([
    'version' => 2,
    'rules' => ['15' => [
        ['id' => 'aaaaaaaaaaaa', 'vendor' => '90101', 'type' => '57401', 'window' => 30, 'min_value' => 50],
        ['id' => 'bbbbbbbbbbbb', 'vendor' => '90101', 'type' => '57420', 'window' => 90, 'min_value' => 50],
    ]],
    'garantie_orders' => ['PO52600987'],
    'garantie_lines' => ['PI52601895|PK-T407852'],
]));
retour_assert(consus_retour_settings_migrate() === true, 'versie 2 wordt gemigreerd');
$onDisk = json_decode((string) file_get_contents($settingsFile), true);
retour_assert($onDisk['version'] === 3 && array_keys($onDisk['rules']) === [KVT] && array_map('strval', array_keys($onDisk['rules'][KVT])) === ['15'], 'regels staan op schijf onder KVT, afdeling 15');
retour_assert(array_column($onDisk['rules'][KVT]['15'], 'id') === ['aaaaaaaaaaaa', 'bbbbbbbbbbbb'], 'beide Perkins-regels behouden met id');
retour_assert($onDisk['rules'][KVT]['15'][0]['type'] === '57401' && $onDisk['rules'][KVT]['15'][0]['window'] === 30 && $onDisk['rules'][KVT]['15'][1]['type'] === '57420' && $onDisk['rules'][KVT]['15'][1]['window'] === 90, '30d spoed en 90d voorraad, €50');
retour_assert(consus_retour_settings_migrate() === false, 'tweede keer niets te migreren');
$migrated = consus_retour_settings_read();
retour_assert(consus_retour_rules_for($migrated, HVT, '15') === [], 'afdeling 15 bij Hunter is niet afdeling 15 bij KVT');
retour_assert(count(consus_retour_rules_for($migrated, 'koninklijke VAN twist', '015')) === 2, 'bedrijf hoofdletterongevoelig, 015 = 15');
retour_assert(consus_retour_garantie_lines($migrated, HVT) === ['PI52601895|PK-T407852'] && consus_retour_garantie_lines($migrated, KVT) === ['PI52601895|PK-T407852'], 'oude garantieregel blijft werken (beide bedrijven)');
retour_assert(consus_retour_companies_with_rules($migrated) === [KVT], 'nightly haalt alleen KVT op');

// Per bedrijf per afdeling.
$settings = consus_retour_rule_save(HVT, '15', ['vendor' => '70001', 'type' => '', 'window' => 30, 'min_value' => 10]);
retour_assert(count(consus_retour_rules_for($settings, KVT, '15')) === 2 && count(consus_retour_rules_for($settings, HVT, '15')) === 1, 'Hunter 15 los van KVT 15');
retour_assert(consus_retour_rule_vendors($settings, HVT) === ['70001'] && consus_retour_rule_vendors($settings, KVT) === ['90101'], 'leveranciers per bedrijf');
retour_assert(consus_retour_companies_with_rules($settings) === [HVT, KVT], 'twee bedrijven met regels');
$settings = consus_retour_rule_delete(HVT, '15', consus_retour_rules_for($settings, HVT, '15')[0]['id']);
retour_assert(!isset($settings['rules'][HVT]) && count(consus_retour_rules_for($settings, KVT, '15')) === 2, 'verwijderen bij Hunter raakt KVT niet');
$threw = false;
try {
    consus_retour_rule_save('', '15', ['vendor' => '90101', 'window' => 30, 'min_value' => 1]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
retour_assert($threw, 'regel zonder bedrijf geweigerd');

// Garantie per bedrijf: dezelfde factuur|artikel bij een ander bedrijf telt niet.
$settings = consus_retour_garantie_set(HVT, 'PI1', 'X', [], true);
retour_assert(in_array('PI1|X', consus_retour_garantie_lines($settings, HVT), true) && !in_array('PI1|X', consus_retour_garantie_lines($settings, KVT), true), 'garantiemarkering per bedrijf');
retour_assert(!consus_retour_is_garantie('PO-NIET', $settings, KVT), 'onbekende PO is geen garantie');

// Data per bedrijf: refresh per bedrijf, fout bij één bedrijf laat de rest staan.
consus_retour_rule_save(HVT, '80', ['vendor' => '70001', 'type' => '', 'window' => 30, 'min_value' => 10]);
$built = [];
$refresh = consus_retour_refresh(static function (string $company, array $settings) use (&$built): array {
    $built[] = $company;
    if ($company === 'Hunter van Twist') {
        throw new RuntimeException('Mímir 503');
    }

    return ['generated_at' => '2026-10-08T03:00:00+02:00', 'lines' => [['invoice' => 'PI12608850']], 'vendors' => consus_retour_rule_vendors($settings, $company)];
}, ['Koninklijke van Twist' => 'kvtmdlive_aad', 'Hunter van Twist' => 'kvtmdlive_aad', 'KVT Germany GmbH' => 'kvtgermanylive_aad']);
retour_assert($built === [HVT, KVT], 'fetch voor elk bedrijf met regels, met de BC Name uit discovery');
retour_assert($refresh['companies'][KVT]['ok'] && $refresh['companies'][KVT]['lines'] === 1, 'KVT apart gemeld');
retour_assert(!$refresh['companies'][HVT]['ok'] && str_contains($refresh['companies'][HVT]['error'], 'Hunter & van Twist: Mímir 503'), 'Hunter-fout apart gemeld met weergavenaam');
retour_assert(consus_retour_read_data(KVT)['vendors'] === ['90101'] && consus_retour_read_data(KVT)['company'] === KVT, 'KVT-data opgeslagen');
$missing = consus_retour_refresh(static fn (string $company, array $settings): array => ['lines' => []], ['Koninklijke van Twist' => 'kvtmdlive_aad']);
retour_assert(!$missing['companies'][HVT]['ok'] && str_contains($missing['companies'][HVT]['error'], 'niet gevonden bij discovery'), 'bedrijf zonder discovery apart gemeld');
retour_assert(consus_retour_read_data(KVT)['lines'] === [], 'KVT ververst');
unlink($settingsFile);
consus_retour_settings_update(static fn (array $s): array => ['version' => 3, 'rules' => []] + $s);
retour_assert(consus_retour_refresh(static fn (): array => [], [])['skipped'] === true, 'zonder regels geen fetch');

// Bincontrole volgt het bedrijf: KVT-magazijn bij KVT.
retour_assert(consus_retour_bin_locations_for(KVT) === ['KVT'] && consus_retour_bin_locations_for(HVT) === ['HVT'] && consus_retour_bin_locations_for('KVT Germany GmbH') === [], 'bin-locaties per bedrijf');
retour_assert(consus_retour_bin_status(['bin' => ['KVT' => 0]], ['KVT' => 2], ['KVT'])[0] === 'controleren', 'KVT zonder bin-inhoud: controleren');

// Pagina: geen eigen afdelingskeuze; regels alleen in de modal.
$pageSettings = consus_retour_normalize_settings(['version' => 3, 'rules' => [KVT => ['15' => [['vendor' => '90101', 'type' => '57401', 'window' => 30, 'min_value' => 50]]]]]);
ob_start();
consus_retour_page_section(KVT, 'Koninklijke Van Twist', '15', '15 - Perkins', 2026, 'tok', '', $data, $pageSettings, '2026-10-08');
$html = (string) ob_get_clean();
retour_assert(!str_contains($html, 'retour_afdeling') && !str_contains($html, 'Afdeling voor de retourlijst'), 'geen eigen afdelingskeuze');
$outsideModal = substr($html, 0, (int) strpos($html, '<dialog id="retour-rules">'));
retour_assert(!str_contains($outsideModal, 'Regels voor') && !str_contains($outsideModal, 'class="retour-rules"') && !str_contains($outsideModal, 'class="retour-hint"'), 'regels staan niet buiten de modal');
retour_assert(str_contains($outsideModal, 'Regels beheren') && str_contains($outsideModal, 'retour-row'), 'kandidaten en knop zichtbaar');
retour_assert(str_contains($html, 'name="company" value="Koninklijke van Twist"') && str_contains($html, 'name="cost_center" value="15"'), 'formulieren dragen bedrijf en afdeling van bovenaan');
retour_assert(str_contains($html, 'Afdeling 15 - Perkins van Koninklijke Van Twist'), 'label met weergavenaam');
ob_start();
consus_retour_page_section('', '', '', '', 2026, 'tok', '', $data, $pageSettings, '2026-10-08');
retour_assert(str_contains((string) ob_get_clean(), 'Kies bovenaan een bedrijf'), 'zonder bedrijf een hint');
$choices = consus_retour_department_choices([['value' => '5', 'label' => '5 - Service']], KVT, '', false, $pageSettings, ['lines' => [['department' => '80']]]);
retour_assert(array_column($choices, 'value') === ['5', '15', '80'], 'afdelingen met regels of data erbij');
$hvtChoices = consus_retour_department_choices([['value' => '90', 'label' => '90 - Voorraad']], HVT, '', false, $pageSettings, ['lines' => []]);
retour_assert(array_column($hvtChoices, 'value') === ['90'], 'KvT-regel op 15 voegt niets toe aan Hunter');
retour_assert(consus_retour_rules_for($pageSettings, HVT, '15') === [] && count(consus_retour_rules_for($pageSettings, KVT, '15')) === 1, 'regels strikt per bedrijf en afdeling');

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
echo "OK\n";
