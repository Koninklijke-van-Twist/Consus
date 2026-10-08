<?php

$dir = sys_get_temp_dir() . '/consus-retour-test-' . getmypid();
@mkdir($dir, 0700, true);
putenv('CONSUS_RETOUR_SETTINGS_FILE=' . $dir . '/retour_settings.json');
putenv('CONSUS_RETOUR_FILE=' . $dir . '/consus_retour.json');

require_once __DIR__ . '/../web/consus_retour_fetch.php';

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

// Instellingen per afdeling, standaard en garantielijst.
$settings = consus_retour_settings_read();
retour_assert($settings['departments']['__default'] === ['min_value' => 50.0, 'window_spoed' => 30, 'window_voorraad' => 90, 'start_date' => '2026-07-17'], 'standaardregel');
retour_assert($settings['garantie_orders'] === ['PO52600987'], 'PO52600987 staat standaard op de garantielijst');
$settings = consus_retour_settings_write('080', ['min_value' => '75,5', 'window_spoed' => '14', 'window_voorraad' => '60', 'start_date' => '01-08-2026'], "PO52600987\npo52600796, x y");
retour_assert($settings['departments']['80'] === ['min_value' => 75.5, 'window_spoed' => 14, 'window_voorraad' => 60, 'start_date' => '2026-08-01'], 'afdeling 080 wordt 80, komma en NL-datum gelezen');
retour_assert($settings['garantie_orders'] === ['PO52600987', 'PO52600796', 'X', 'Y'] || in_array('PO52600796', $settings['garantie_orders'], true), 'garantielijst genormaliseerd');
retour_assert(consus_retour_rule_for_department($settings, '80')['window_spoed'] === 14, 'afdeling 80 gebruikt eigen regel');
retour_assert(consus_retour_rule_for_department($settings, '90')['window_spoed'] === 30, 'afdeling 90 valt terug op standaard');
$settings = consus_retour_settings_write('80', [], null, true);
retour_assert(!isset($settings['departments']['80']), 'terug naar standaard verwijdert de afdelingsregel');
$settings = consus_retour_settings_write('', ['min_value' => '50', 'window_spoed' => '30', 'window_voorraad' => '90', 'start_date' => '2026-07-17'], 'PO52600987');
retour_assert(consus_retour_is_garantie('po52600987', $settings), 'garantie-PO herkend, hoofdletterongevoelig');

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
$line = static fn (string $invoice, string $date, string $no, float $qty, float $cost, string $po, string $dept = '90'): array => [
    'invoice' => $invoice, 'vendor_invoice' => 'V' . $invoice, 'document_date' => $date, 'item' => $no, 'description' => $no,
    'quantity' => $qty, 'unit_cost' => $cost, 'order_no' => $po, 'department' => $dept,
];
$data = [
    'generated_at' => '2026-10-08T02:00:00+00:00',
    'lines' => [
        // €30 + €30 op dezelfde factuur = €60: samen boven €50.
        $line('PI10', '2026-09-20', 'SUM', 1, 30, 'POS'),
        $line('PI10', '2026-09-20', 'SUM', 1, 30, 'POS'),
        // €40 alleen: onder minimum.
        $line('PI10', '2026-09-20', 'LOW', 1, 40, 'POS'),
        // Spoed, 31 dagen oud: buiten 30 dagen.
        $line('PI11', '2026-09-07', 'OLDSPOED', 1, 100, 'POS'),
        // Spoed, 30 dagen oud: nog net binnen.
        $line('PI12', '2026-09-08', 'EDGE', 1, 100, 'POS'),
        // Voorraad, 60 dagen oud: binnen 90.
        $line('PI13', '2026-08-09', 'STOCK', 1, 100, 'POV'),
        // Voor de startdatum.
        $line('PI14', '2026-07-16', 'EARLY', 1, 100, 'POV'),
        // Veiligheidsvoorraad.
        $line('PI15', '2026-09-20', 'SAFE', 1, 100, 'POV'),
        // Volledig gereserveerd.
        $line('PI15', '2026-09-20', 'RES', 1, 100, 'POV'),
        // Garantie.
        $line('PI16', '2026-09-20', 'WARR', 1, 100, 'PO52600987'),
        // Geen bin-inhoud op HVT.
        $line('PI17', '2026-09-20', 'PHANTOM', 1, 100, 'POV'),
        // Voorraad alleen op M602.
        $line('PI17', '2026-09-20', 'VAN', 1, 100, 'POV'),
        // Order niet meer in BC: voorraad.
        $line('PI18', '2026-08-20', 'GONE', 1, 100, 'POGONE'),
        // Twee facturen, 1 op voorraad: de nieuwste krijgt hem.
        $line('PI19', '2026-09-01', 'SPLIT', 1, 100, 'POV'),
        $line('PI20', '2026-09-25', 'SPLIT', 1, 100, 'POV'),
        // Andere afdeling.
        $line('PI21', '2026-09-20', 'DEPT80', 1, 100, 'POV', '80'),
    ],
    'orders' => [
        'POS' => ['found' => true, 'egt' => true, 'csv' => false],
        'POV' => ['found' => true, 'egt' => false, 'csv' => true],
        'PO52600987' => ['found' => true, 'egt' => false, 'csv' => false],
    ],
    'items' => [
        'SUM' => $item(), 'LOW' => $item(), 'OLDSPOED' => $item(), 'EDGE' => $item(), 'STOCK' => $item(), 'EARLY' => $item(),
        'SAFE' => $item(['safety_stock' => 2]), 'RES' => $item(['stock' => ['HVT' => 1], 'reserved' => 1]),
        'WARR' => $item(), 'PHANTOM' => $item(['bin' => []]), 'VAN' => $item(['stock' => ['M602' => 1], 'bin' => []]),
        'GONE' => $item(), 'SPLIT' => $item(['stock' => ['HVT' => 1], 'bin' => ['HVT' => 1]]), 'DEPT80' => $item(),
    ],
];
$result = consus_retour_candidates($data, $settings, '2026-10-08');
$byItem = [];
foreach ($result['rows'] as $row) {
    $byItem[$row['item'] . '@' . $row['invoice']] = $row;
}
retour_assert(isset($byItem['SUM@PI10']) && abs($byItem['SUM@PI10']['value'] - 60.0) < 0.001 && $byItem['SUM@PI10']['return_qty'] == 2.0, '€50 per artikel per factuur: regels opgeteld');
retour_assert($byItem['SUM@PI10']['account'] === '57401' && $byItem['SUM@PI10']['vendor_invoice'] === 'VPI10', 'spoed en Perkins-factuurnummer');
retour_assert(!isset($byItem['LOW@PI10']), 'onder €50 valt weg');
retour_assert(!isset($byItem['OLDSPOED@PI11']), 'spoed na 30 dagen verlopen');
retour_assert(isset($byItem['EDGE@PI12']) && $byItem['EDGE@PI12']['days_left'] === 0 && $byItem['EDGE@PI12']['days_since'] === 30, 'dag 30 telt nog mee');
retour_assert(isset($byItem['STOCK@PI13']) && $byItem['STOCK@PI13']['account'] === '57420' && $byItem['STOCK@PI13']['days_left'] === 30, 'voorraad: 90 dagen');
retour_assert($byItem['STOCK@PI13']['deadline'] === '2026-11-07', 'uiterste retourdatum');
retour_assert(!isset($byItem['EARLY@PI14']), 'factuur voor 17 juli 2026 telt niet');
retour_assert(!isset($byItem['SAFE@PI15']), 'artikel met veiligheidsvoorraad valt weg');
retour_assert(!isset($byItem['RES@PI15']), 'gereserveerde voorraad is geen kandidaat');
retour_assert(!isset($byItem['WARR@PI16']), 'garantieorder is geen kandidaat');
retour_assert(count($result['garantie']) === 1 && $result['garantie'][0]['item'] === 'WARR' && $result['garantie'][0]['garantie'] === true, 'garantieorder apart gemarkeerd');
retour_assert($byItem['PHANTOM@PI17']['status'] === 'controleren' && str_contains($byItem['PHANTOM@PI17']['status_label'], 'geen bin-inhoud'), 'spookvoorraad: controleren');
retour_assert($byItem['VAN@PI17']['status'] === 'geen_bincontrole', 'M602 zonder bincontrole');
retour_assert($byItem['SUM@PI10']['status'] === 'kandidaat', 'gewone kandidaat');
retour_assert($byItem['GONE@PI18']['account'] === '57420' && str_contains($byItem['GONE@PI18']['status_label'], 'niet meer in BC'), 'verdwenen order telt als voorraad met opmerking');
retour_assert(isset($byItem['SPLIT@PI20']) && !isset($byItem['SPLIT@PI19']), 'vrije voorraad gaat naar de nieuwste factuur');
retour_assert(isset($byItem['DEPT80@PI21']), 'alle afdelingen zonder filter');
retour_assert($result['rows'][0]['days_left'] <= $result['rows'][count($result['rows']) - 1]['days_left'], 'gesorteerd op dagen over');

$only80 = consus_retour_candidates($data, $settings, '2026-10-08', '080');
retour_assert(count($only80['rows']) === 1 && $only80['rows'][0]['item'] === 'DEPT80', 'afdelingsfilter');
$strict = consus_retour_settings_write('90', ['min_value' => '100.01', 'window_spoed' => '30', 'window_voorraad' => '90', 'start_date' => '2026-07-17'], null);
$strictResult = consus_retour_candidates($data, $strict, '2026-10-08');
$strictItems = array_column($strictResult['rows'], 'item');
retour_assert(!in_array('STOCK', $strictItems, true) && in_array('DEPT80', $strictItems, true), 'minimum per afdeling');
retour_assert($strict['garantie_orders'] === ['PO52600987'], 'garantielijst blijft staan bij null');

// Export volgt Ivans kolommen.
$sheet = consus_retour_export_sheet($result);
retour_assert($sheet['rows'][0][0] === 'Account' && in_array('Perkins-factuur', $sheet['rows'][0], true) && in_array('Dagen over', $sheet['rows'][0], true), 'exportkop');
retour_assert(count($sheet['rows']) === count($result['rows']) + count($result['garantie']) + 1, 'export bevat kandidaten en garantieregels');
retour_assert($sheet['rows'][1][4] !== '' && !preg_match('/^\d{4}-/', (string) $sheet['rows'][1][4]), 'datum in export is Nederlands');

// Build met nep-BC: vlaggen, kop-filter, archief-fallback.
$calls = [];
$fake = static function (string $company, string $entitySet, array $required, array $optional, string $filter, callable $onRow) use (&$calls): void {
    $calls[] = $entitySet;
    $rows = match ($entitySet) {
        'GeboekteInkoopfacturen' => [['No' => 'PI1', 'Document_Date' => '2026-09-20', 'Vendor_Invoice_No' => '90243586']],
        'GeboekteInkoopfactuurRegels' => [
            ['Document_No' => 'PI1', 'Line_No' => 10000, 'Type' => 'Item', 'No' => 'PK-T438898', 'Quantity' => 1, 'Direct_Unit_Cost' => 249.65, 'Order_No' => 'PO52601154', 'Shortcut_Dimension_1_Code' => '90'],
            ['Document_No' => 'PI1', 'Line_No' => 20000, 'Type' => 'Item', 'No' => 'PK-X', 'Quantity' => 1, 'Direct_Unit_Cost' => 60, 'Order_No' => 'PO1', 'Shortcut_Dimension_1_Code' => '90'],
        ],
        'AppPurchaseOrder' => [['No' => 'PO52601154', 'KVT_Export_Status_Perkins_EGT' => 'Ja', 'KVT_Export_Status_Perkins_CSV' => 'Nee']],
        'GearchiveerdeInkooporders' => throw new RuntimeException('404 Onbekende tabel'),
        'AppItemCard' => [['No' => 'PK-T438898', 'Safety_Stock_Quantity' => 0], ['No' => 'PK-X', 'Safety_Stock_Quantity' => 0]],
        'ItemLedgerEntries' => [['Item_No' => 'PK-T438898', 'Remaining_Quantity' => 1, 'Location_Code' => 'HVT'], ['Item_No' => 'PK-X', 'Remaining_Quantity' => 1, 'Location_Code' => 'HVT']],
        'ReservationEntries' => [
            ['Entry_No' => 9, 'Positive' => true, 'Item_No' => 'PK-T438898', 'Quantity_Base' => 1, 'Source_Type' => 32, 'Reservation_Status' => 'Reservation'],
            ['Entry_No' => 9, 'Positive' => false, 'Item_No' => 'PK-T438898', 'Quantity_Base' => -1, 'Source_Type' => 39, 'Source_Subtype' => '5', 'Reservation_Status' => 'Reservation'],
        ],
        'BinContent' => throw new RuntimeException('traag'),
        'Magazijnposten' => [['Item_No' => 'PK-X', 'Location_Code' => 'HVT', 'Qty_Base' => 1]],
        default => [],
    };
    foreach ($rows as $row) {
        $onRow($row);
    }
};
$built = consus_retour_build('Hunter van Twist', consus_retour_normalize_settings([]), $fake, '2026-10-08');
retour_assert($built['orders']['PO52601154'] === ['found' => true, 'egt' => true, 'csv' => false], 'Nederlandse booleans van Mímir');
retour_assert(empty($built['orders']['PO1']['found']), 'onbekende order blijft onbekend');
retour_assert(in_array('GearchiveerdeInkooporders', $calls, true) && count($built['warnings']) === 1, 'archief geprobeerd, waarschuwing bij 404');
retour_assert($built['items']['PK-T438898']['reserved_return'] == 1.0, 'retourorder-reservering');
retour_assert($built['items']['PK-X']['bin'] === ['HVT' => 1.0], 'Magazijnposten als fallback voor bins');
$builtResult = consus_retour_candidates($built, consus_retour_normalize_settings([]), '2026-10-08');
retour_assert(array_column($builtResult['rows'], 'item') === ['PK-X'], 'T438898 staat al op retourorder en is geen kandidaat');
retour_assert($builtResult['rows'][0]['account'] === '57420', 'PO1 zonder vlaggen is voorraad');

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
echo "OK\n";
