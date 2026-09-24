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
    $snapshot['articles'] = $rolled['articles'];
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
test_assert(consus_procurement_bucket('', '') === 'eigen' && consus_procurement_bucket_from_row(['Location_Code' => 'BYKLANT']) === 'eigen', 'locatiecode maakt geen dropship');

test_assert(CONSUS_SALES_ENTRY_TYPES === ['Sale'], 'verkoop is de Engelse optienaam Sale');
test_assert(CONSUS_ODATA_PAGE_SIZE === 20000, 'pagina is groot genoeg om round-trips te beperken');
$chunks = consus_ledger_date_chunks($windows);
test_assert(count($chunks) === 12, 'artikelposten lopen per maand door het twaalfmaandsvenster');
test_assert($chunks[0] === ['from' => '2025-10-01', 'to' => '2025-11-01'], 'eerste maand start op history_start');
test_assert($chunks[11] === ['from' => '2026-09-01', 'to' => '2026-09-25'], 'lopende maand stopt de dag na as_of');
$previousChunkEnd = $windows['history_start'];
foreach ($chunks as $chunk) {
    test_assert($chunk['from'] === $previousChunkEnd, 'maanden sluiten op elkaar aan');
    $previousChunkEnd = $chunk['to'];
}
test_assert($previousChunkEnd === '2026-09-25', 'laatste grens is de dag na as_of');
$salesFilters = consus_ledger_filters(CONSUS_SALES_ENTRY_TYPES, $chunks[0]['from'], $chunks[0]['to'], '', true, false);
test_assert(count($salesFilters) === 1, 'verkoop is één Entry_Type-query');
test_assert(
    $salesFilters[0]['$filter'] === "Entry_Type eq 'Sale' and Posting_Date ge 2025-10-01 and Posting_Date lt 2025-11-01",
    'verkoopfilter gebruikt de Engelse optienaam en een maandgrens'
);
test_assert(!str_contains($salesFilters[0]['$filter'], 'Verkoop'), 'Nederlands bijschrift Verkoop is geen optie op ItemLedgerEntries');
foreach ($salesFilters as $salesQuery) {
    test_assert(!str_contains($salesQuery['$filter'], ' or '), 'verkoopfilter heeft geen OR');
    test_assert(!str_contains(strtolower($salesQuery['$filter']), 'startswith'), 'verkoopfilter heeft geen startswith');
    test_assert(!str_contains($salesQuery['$filter'], 'Perkins'), 'verkoopfilter niet vast op leverancier');
    test_assert(!str_contains($salesQuery['$filter'], '90052'), 'verkoopfilter niet vast op dropship-leverancier');
    test_assert(!str_contains($salesQuery['$filter'], '90101'), 'verkoopfilter niet vast op EGT-leverancier');
    test_assert(!str_contains($salesQuery['$filter'], 'DROP_SHIP'), 'verkoopfilter niet vast op inkoopcode');
    test_assert(!str_contains($salesQuery['$filter'], 'COST_CENTER'), 'verkoopfilter niet vast op afdeling');
    test_assert(!str_contains($salesQuery['$filter'], 'Location_Code'), 'verkoop haalt alle locaties op');
    test_assert(!str_contains($salesQuery['$filter'], 'Document_No'), 'verkoop filtert niet op documentnummer');
    test_assert(str_contains($salesQuery['$select'], 'Location_Code'), 'locatiecode blijft in de select');
    test_assert(str_contains($salesQuery['$select'], 'Sales_Amount_Actual'), 'verkoop houdt omzet');
    test_assert(!str_contains($salesQuery['$select'], 'Document_No'), 'verkoop haalt geen documentnummer op');
    test_assert(!str_contains($salesQuery['$select'], 'Entry_Type'), 'entry type staat al in het filter');
    test_assert((int) $salesQuery['$top'] === CONSUS_ODATA_PAGE_SIZE, 'verkoop gebruikt de afgesproken paginagrootte');
}
$combinedSales = false;
try {
    consus_ledger_query(['Sale', 'Verkoop'], '2025-10-01');
} catch (InvalidArgumentException $error) {
    $combinedSales = str_contains($error->getMessage(), 'OR');
}
test_assert($combinedSales, 'twee entry types worden niet met OR gecombineerd');

$woParts = consus_wo_ledger_parts();
test_assert(count($woParts) === 2, 'werkorderverbruik is twee aparte queries');
test_assert($woParts[0]['document_prefix'] === 'WO', 'negatieve correctie houdt documentprefix WO');
test_assert($woParts[1]['document_prefix'] === '', 'assemblageverbruik heeft geen documentprefix');
test_assert($woParts[0]['entry_types'] === ['Negative Adjmt.'], 'primair verbruik is Negative Adjmt.');
test_assert($woParts[1]['entry_types'] === ['Assembly Consumption'], 'tweede verbruikquery is Assembly Consumption');
$primaryFilters = consus_ledger_filters($woParts[0]['entry_types'], '2025-10-01', '2025-11-01', $woParts[0]['document_prefix'], false);
$alsoFilters = consus_ledger_filters($woParts[1]['entry_types'], '2025-10-01', '2025-11-01', '', false);
test_assert(
    $primaryFilters[0]['$filter'] === "Entry_Type eq 'Negative Adjmt.' and Posting_Date ge 2025-10-01 and Posting_Date lt 2025-11-01 and Document_No ge 'WO' and Document_No lt 'WP'",
    'primair verbruik is Negative Adjmt. met een WO-bereik in plaats van startswith'
);
test_assert(
    $alsoFilters[0]['$filter'] === "Entry_Type eq 'Assembly Consumption' and Posting_Date ge 2025-10-01 and Posting_Date lt 2025-11-01",
    'assemblageverbruik is een eigen query'
);
test_assert(str_contains($primaryFilters[0]['$select'], 'Document_No'), 'negatieve correctie houdt documentnummer voor de PHP-check');
test_assert(!str_contains($primaryFilters[0]['$select'], 'Sales_Amount_Actual'), 'verbruik haalt geen omzet op');
test_assert(!str_contains($alsoFilters[0]['$select'], 'Document_No'), 'assemblageverbruik haalt geen documentnummer op');
test_assert(!str_contains($alsoFilters[0]['$select'], 'Sales_Amount_Actual'), 'assemblageverbruik haalt geen omzet op');
test_assert(consus_document_prefix_bounds('WO') === ['from' => 'WO', 'to' => 'WP'], 'WO-bereik loopt tot WP');
test_assert(!str_contains($primaryFilters[0]['$filter'], 'Negatieve correctie'), 'Negatieve correctie is geen optie');
test_assert(!str_contains($alsoFilters[0]['$filter'], 'Assemblageverbruik'), 'Assemblageverbruik is geen optie');
foreach (array_merge($primaryFilters, $alsoFilters) as $woQuery) {
    test_assert(!str_contains($woQuery['$filter'], ' or '), 'verbruikfilter heeft geen OR');
    test_assert(!str_contains(strtolower($woQuery['$filter']), 'startswith'), 'verbruikfilter heeft geen startswith');
    test_assert(!str_contains($woQuery['$filter'], 'Location_Code'), 'verbruik haalt alle locaties op');
    test_assert(
        preg_match("/Entry_Type eq '(Consumption|Verbruik|Gebruik)'/", $woQuery['$filter']) !== 1,
        'kale consumption blijft buiten de query'
    );
}
test_assert(count($primaryFilters) > 0 && count($alsoFilters) > 0, 'verbruik leunt niet alleen op assemblageverbruik');
test_assert(consus_document_no_has_prefix(['Document_No' => 'WO12345'], 'WO'), 'WO-document telt mee');
test_assert(consus_document_no_has_prefix(['Document_No' => 'wo9'], 'WO'), 'prefix is niet hoofdlettergevoelig');
test_assert(!consus_document_no_has_prefix(['Document_No' => 'INV1'], 'WO'), 'andere documenten vallen af');
test_assert(!consus_document_no_has_prefix(['Document_No' => ''], 'WO'), 'leeg documentnummer valt af');
test_assert(consus_document_no_has_prefix(['Document_No' => 'ASM-1'], ''), 'zonder prefix blijft elke regel');
$rejectedFilter = new RuntimeException('ItemLedgerEntries voor Koninklijke van Twist mislukt: HTTP 501 from OData: {"error":{"code":"BadRequest_MethodNotImplemented","message":"De OData-filterexpressie wordt niet ondersteund."}}');
test_assert(consus_odata_error_allows_entry_type_fallback($rejectedFilter), '501 filterexpressie probeert het volgende bijschrift');
$notAnOption = new RuntimeException("HTTP 400 Unknown: 'Verkoop' is not an option. The existing options are: Purchase,Sale,Positive Adjmt.,Negative Adjmt.,Transfer,Consumption,Output, ,Assembly Consumption,Assembly ...");
test_assert(consus_odata_error_allows_entry_type_fallback($notAnOption), '400 is not an option probeert het volgende bijschrift');
test_assert(!consus_odata_error_allows_entry_type_fallback(new RuntimeException('cURL error: timeout')), 'netwerkfout is geen filterfout');
test_assert(consus_odata_error_is_page_size(new RuntimeException('HTTP 400 The maximum page size is 1000')), 'paginagrootte is herkenbaar');
test_assert(!consus_odata_error_is_page_size(new RuntimeException('HTTP 501 filterexpressie')), 'filterfout is geen paginagrootte');
$resumeSnapshot = [
    'version' => CONSUS_SNAPSHOT_VERSION,
    'as_of' => $windows['as_of'],
    'windows' => $windows,
    'companies' => [[
        'company' => 'Koninklijke van Twist',
        'company_key' => 'kvt',
        'stale' => false,
        'refreshed_on' => $windows['as_of'],
    ]],
];
test_assert(consus_company_refresh_is_current($resumeSnapshot, 'kvt', $windows), 'vers bedrijf van vandaag slaan we over');
test_assert(!consus_company_refresh_is_current($resumeSnapshot, 'hvt', $windows), 'ander bedrijf blijft laden');
$resumeSnapshot['companies'][0]['stale'] = true;
test_assert(!consus_company_refresh_is_current($resumeSnapshot, 'kvt', $windows), 'stale bedrijf laadt opnieuw');
$resumeSnapshot['companies'][0]['stale'] = false;
$resumeSnapshot['companies'][0]['refreshed_on'] = '2026-09-23';
test_assert(!consus_company_refresh_is_current($resumeSnapshot, 'kvt', $windows), 'gisteren telt niet voor vandaag');
$resumeSnapshot['companies'][0]['refreshed_on'] = $windows['as_of'];
$resumeSnapshot['version'] = CONSUS_SNAPSHOT_VERSION - 1;
test_assert(!consus_company_refresh_is_current($resumeSnapshot, 'kvt', $windows), 'oude snapshotversie wordt opnieuw geladen');
$keptRows = consus_rows_keeping_unfetched(
    ['kvt' => [['company_key' => 'kvt', 'vendor_name' => 'Vers', 'vendor_no' => 'V', 'cost_center' => '', 'location' => '']]],
    [
        'kvt' => [['company_key' => 'kvt', 'vendor_name' => 'Oud', 'vendor_no' => 'O', 'cost_center' => '', 'location' => '']],
        'hvt' => [['company_key' => 'hvt', 'vendor_name' => 'Blijft', 'vendor_no' => 'B', 'cost_center' => '', 'location' => '']],
    ]
);
test_assert(array_column($keptRows, 'vendor_no') === ['V', 'B'], 'nog niet geladen bedrijf houdt de vorige rijen');
$publishTemp = sys_get_temp_dir() . '/consus-publish-' . getmypid() . '.json';
$savedPublishEnv = getenv('CONSUS_SNAPSHOT_FILE');
putenv('CONSUS_SNAPSHOT_FILE=' . $publishTemp);
@unlink($publishTemp);
$partialPublish = consus_publish_nightly_snapshot(
    $windows,
    [[
        'company' => 'Koninklijke van Twist',
        'company_key' => 'kvt',
        'stale' => false,
        'refreshed_on' => $windows['as_of'],
        'duration_ms' => 5,
    ]],
    [],
    [],
    ['kvt' => [[
        'company_key' => 'kvt',
        'vendor_no' => 'V',
        'vendor_name' => 'Vers',
        'cost_center' => '',
        'location' => '',
    ]]],
    ['hvt' => [[
        'company_key' => 'hvt',
        'vendor_no' => 'B',
        'vendor_name' => 'Blijft',
        'cost_center' => '',
        'location' => '',
    ]]],
    [],
    true
);
test_assert(count($partialPublish['errors']) === 1, 'lopend bedrijf meldt dat de run nog bezig is');
test_assert(str_contains((string) $partialPublish['errors'][0]['error'], 'nog bezig'), 'bezig-melding is Nederlands');
test_assert(in_array('B', array_column($partialPublish['rows'], 'vendor_no'), true), 'bezig-snapshot houdt de vorige rijen');
$donePublish = consus_publish_nightly_snapshot(
    $windows,
    [
        ['company' => 'Koninklijke van Twist', 'company_key' => 'kvt', 'stale' => false, 'refreshed_on' => $windows['as_of'], 'duration_ms' => 5],
        ['company' => 'Hunter van Twist', 'company_key' => 'hvt', 'stale' => false, 'refreshed_on' => $windows['as_of'], 'duration_ms' => 6],
    ],
    [],
    [],
    [
        'kvt' => [[
            'company_key' => 'kvt',
            'vendor_no' => 'V',
            'vendor_name' => 'Vers',
            'cost_center' => '',
            'location' => '',
        ]],
        'hvt' => [[
            'company_key' => 'hvt',
            'vendor_no' => 'B',
            'vendor_name' => 'Blijft',
            'cost_center' => '',
            'location' => '',
        ]],
    ],
    [],
    [],
    false
);
test_assert($donePublish['errors'] === [], 'afgeronde run zonder fouten heeft geen bezig-melding');
test_assert((int) $donePublish['version'] === CONSUS_SNAPSHOT_VERSION, 'snapshot schrijft de versie');
$nightlySource = (string) file_get_contents(__DIR__ . '/../web/nightly.php');
test_assert(str_contains($nightlySource, "'version' => (int) (\$snapshot['version'] ?? CONSUS_SNAPSHOT_VERSION)"), 'nightly-JSON noemt de snapshotversie');
@unlink($publishTemp);
@unlink($publishTemp . '.lock');
if ($savedPublishEnv === false) {
    putenv('CONSUS_SNAPSHOT_FILE');
} else {
    putenv('CONSUS_SNAPSHOT_FILE=' . $savedPublishEnv);
}

$dimensionQuery = consus_dimension_query();
test_assert(
    $dimensionQuery['$filter'] === "Table_ID eq 27 and Dimension_Code eq '15'",
    'kostenplaats haalt dimensie 15 op zonder waardfilter'
);
test_assert(consus_dimension_code_filter() === "Dimension_Code eq '15'", 'terugvalfilter houdt alleen dimensie 15');
test_assert(!str_contains($dimensionQuery['$filter'], 'Dimension_Value_Code'), 'afdelingswaarde wordt niet vastgezet');

$stockQuery = consus_entity_query(CONSUS_STOCK_FIELDS);
test_assert(!isset($stockQuery['$filter']), 'voorraadquery filtert niet op één bedrijf of leverancier');
test_assert((int) $stockQuery['$top'] === CONSUS_ODATA_PAGE_SIZE, 'voorraad gebruikt dezelfde paginagrootte');
test_assert(!isset(consus_entity_query(CONSUS_STOCK_FIELDS, '', 0)['$top']), 'paginagrootte 0 laat $top weg');

$scoped = consus_companies_in_scope(['KVT Gas', 'Hunter van Twist', 'Koninklijke van Twist B.V.']);
test_assert(array_column($scoped, 'company_key') === ['kvt', 'hvt'], 'alleen KVT en HVT, KVT Gas valt buiten scope');
test_assert(consus_company_key_for_name('KVT') === 'kvt', 'label KVT is Koninklijke van Twist');
test_assert(consus_company_key_for_name('HVT') === 'hvt', 'label HVT is Hunter van Twist');
test_assert(consus_company_key_for_name('KVT Gas') === '', 'KVT Gas blijft buiten het label');
test_assert(consus_stock_company_key(['Company_Name' => 'KVT'], 'Hunter van Twist') === 'kvt', 'KVT-voorraad in de HVT-query blijft van KVT');
test_assert(consus_stock_company_key(['Company_Name' => ''], 'Hunter van Twist') === 'hvt', 'lege Company_Name hoort bij de query');
test_assert(consus_stock_company_key(['Company_Name' => 'ACME'], 'Hunter van Twist') === '', 'onbekend bedrijf valt niet terug op de query');

$misrouted = [];
consus_apply_stock_row($misrouted, [
    'Item_No' => 'A1',
    'Company_Name' => 'KVT',
    'Locatiecode' => 'MAG',
    'Inventory' => 12,
    'Safety_Stock_Quantity' => 4,
    'Reorder_Point' => 0,
    'Bestelpunt' => 3,
], 'Hunter van Twist');
consus_apply_stock_row($misrouted, [
    'No' => 'H1',
    'Company_Name' => 'HVT',
    'Inventory' => 9,
    'Safety_Stock_Quantity' => 1,
    'Reorder_Point' => 2,
], 'Hunter van Twist');
test_assert(isset($misrouted['kvt|A1']) && !isset($misrouted['hvt|A1']), 'kort label KVT maakt geen HVT-artikel');
test_assert((float) $misrouted['kvt|A1']['inventory'] === 12.0, 'KVT-voorraad blijft op KVT');
test_assert((float) $misrouted['kvt|A1']['safety_stock'] === 4.0, 'KVT-veiligheidsvoorraad blijft op KVT');
test_assert((float) $misrouted['kvt|A1']['reorder_point'] === 3.0, 'Bestelpunt vult een leeg Reorder_Point');
test_assert((string) array_key_first($misrouted['kvt|A1']['by_location']) === 'MAG', 'Locatiecode vult een ontbrekende Location_Code');
test_assert((float) $misrouted['hvt|H1']['inventory'] === 9.0, 'HVT-voorraad blijft op HVT');
test_assert((float) $misrouted['hvt|H1']['reorder_point'] === 2.0, 'gevuld Reorder_Point blijft het bestelpunt');
$unknownCompany = [];
consus_apply_stock_row($unknownCompany, [
    'Item_No' => 'Z',
    'Company_Name' => 'ACME',
    'Inventory' => 5,
], 'Hunter van Twist');
test_assert($unknownCompany === [], 'onbekende Company_Name wordt niet op HVT gezet');

$kvtLedgerRows = [[
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'vendor_no' => 'PERK',
    'vendor_name' => 'Perkins',
    'cost_center' => '',
    'location' => 'KVT',
    'inventory' => 0.0,
    'safety_stock' => 0.0,
    'reorder_point' => 0.0,
    'item_count' => 1,
    'item_nos' => ['A1' => true],
    'sales' => [],
    'consumption' => [],
]];
$kvtLedgerArticles = [[
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'item_no' => 'A1',
    'description' => 'Filter',
    'vendor_no' => 'PERK',
    'vendor_name' => 'Perkins',
    'cost_center' => '',
    'location' => 'KVT',
    'inventory' => 0.0,
    'safety_stock' => 0.0,
    'reorder_point' => 0.0,
    'consumption' => consus_empty_consumption_qty(),
]];
test_assert(consus_rows_lack_stock($kvtLedgerRows), 'KVT zonder aantallen mag vreemde voorraad nog krijgen');
consus_overlay_spilled_stock($kvtLedgerRows, $kvtLedgerArticles, $misrouted, 'kvt');
test_assert(abs((float) $kvtLedgerRows[0]['inventory'] - 12) < 0.0001, 'spilled KVT-voorraad landt op de bestaande regel');
test_assert(abs((float) $kvtLedgerRows[0]['safety_stock'] - 4) < 0.0001, 'spilled veiligheidsvoorraad landt op dezelfde regel');
test_assert(abs((float) $kvtLedgerRows[0]['reorder_point'] - 3) < 0.0001, 'spilled bestelpunt landt op dezelfde regel');
test_assert((string) $kvtLedgerRows[0]['vendor_no'] === 'PERK', 'de leverancier van de grootboekregel blijft');
test_assert(abs((float) $kvtLedgerArticles[0]['inventory'] - 12) < 0.0001, 'artikeltabel krijgt dezelfde KVT-voorraad');
test_assert(!consus_rows_lack_stock($kvtLedgerRows), 'na de spill heeft KVT voorraad');
$hvtUntouched = [[
    'company_key' => 'hvt',
    'inventory' => 9.0,
    'safety_stock' => 1.0,
    'reorder_point' => 2.0,
    'item_nos' => ['B2' => true],
    'location' => '',
]];
$hvtArticles = [];
consus_overlay_spilled_stock($hvtUntouched, $hvtArticles, $misrouted, 'hvt');
test_assert(abs((float) $hvtUntouched[0]['inventory'] - 9) < 0.0001, 'KVT-spill telt niet bij een andere HVT-regel op');
test_assert((string) $hvtUntouched[0]['company_key'] === 'hvt', 'de bestaande HVT-regel blijft HVT');
$hvtKeys = [];
foreach ($hvtUntouched as $hvtRow) {
    $hvtKeys[] = (string) ($hvtRow['company_key'] ?? '');
}
test_assert(!in_array('kvt', $hvtKeys, true), 'KVT-artikel komt niet in de HVT-rijen');

$cardItems = [];
consus_apply_stock_row($cardItems, [
    'Item_No' => 'A1',
    'Company_Name' => 'Koninklijke van Twist',
    'Inventory' => 1,
], 'Koninklijke van Twist');
consus_apply_vendor_row($cardItems, [
    'No' => 'A1',
    'Vendor_No' => 'PERK',
    'Global_Dimension_1_Code' => 'WERK',
], 'kvt');
test_assert((string) $cardItems['kvt|A1']['cost_center'] === 'WERK', 'globale dimensie 1 vult een ontbrekende COST_CENTER');

$planningItems = [];
consus_apply_stock_row($planningItems, [
    'Item_No' => 'P1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'A',
    'Inventory' => 10,
    'Safety_Stock_Quantity' => 0,
    'Reorder_Point' => 0,
], 'Koninklijke van Twist');
consus_apply_stock_row($planningItems, [
    'Item_No' => 'P1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'B',
    'Inventory' => 3,
    'Safety_Stock_Quantity' => 0,
    'Reorder_Point' => 0,
], 'Koninklijke van Twist');
consus_apply_vendor_row($planningItems, [
    'No' => 'P1',
    'Vendor_No' => 'PERK',
    'Safety_Stock_Quantity' => 4,
    'Reorder_Point' => 2,
], 'kvt');
test_assert((float) $planningItems['kvt|P1']['safety_stock'] === 4.0, 'artikelkaart vult veiligheidsvoorraad één keer');
test_assert((float) $planningItems['kvt|P1']['reorder_point'] === 2.0, 'artikelkaart vult bestelpunt één keer');
$planningSafetyLocations = 0;
foreach ($planningItems['kvt|P1']['by_location'] as $planningMetrics) {
    if (abs((float) ($planningMetrics['safety_stock'] ?? 0)) >= 0.0001) {
        $planningSafetyLocations++;
    }
}
test_assert($planningSafetyLocations === 1, 'veiligheidsvoorraad van de artikelkaart komt op één locatie');
$planningSnapshot = test_snapshot($planningItems, $windows);
$planningListed = consus_list_articles($planningSnapshot, 'kvt', 'PERK', '', '');
test_assert(count($planningListed) === 1, 'artikel met voorraad staat in de tabel');
test_assert(abs((float) $planningListed[0]['safety_stock'] - 4) < 0.0001, 'artikeltabel toont veiligheidsvoorraad van de kaart');
test_assert(abs((float) $planningListed[0]['inventory'] - 13) < 0.0001, 'artikeltabel telt beide locaties');

$keptPlanning = [];
consus_apply_stock_row($keptPlanning, [
    'Item_No' => 'P2',
    'Company_Name' => 'Koninklijke van Twist',
    'Inventory' => 8,
    'Safety_Stock_Quantity' => 5,
    'Reorder_Point' => 1,
], 'Koninklijke van Twist');
consus_apply_vendor_row($keptPlanning, [
    'No' => 'P2',
    'Vendor_No' => 'PERK',
    'Safety_Stock_Quantity' => 0,
    'Reorder_Point' => 9,
], 'kvt');
test_assert((float) $keptPlanning['kvt|P2']['safety_stock'] === 5.0, 'gevulde voorraad-veiligheidsvoorraad blijft');
test_assert((float) $keptPlanning['kvt|P2']['reorder_point'] === 1.0, 'gevuld bestelpunt op voorraad wint van de artikelkaart');

$blankLocation = [];
consus_apply_stock_row($blankLocation, [
    'Item_No' => 'S1',
    'Company_Name' => 'Koninklijke van Twist',
    'Inventory' => 19,
    'Safety_Stock_Quantity' => 0,
    'Reorder_Point' => 0,
], 'Koninklijke van Twist');
consus_apply_vendor_row($blankLocation, [
    'No' => 'S1',
    'Vendor_No' => 'PERK',
    'Veiligheidsvoorraad' => 6,
    'Bestelpunt' => 3,
    'Global_Dimension_1_Code' => 'WERK',
], 'kvt');
test_assert((float) $blankLocation['kvt|S1']['safety_stock'] === 6.0, 'Nederlandse veiligheidsvoorraad op de kaart vult de voorraadregel');
test_assert((float) $blankLocation['kvt|S1']['reorder_point'] === 3.0, 'Nederlands bestelpunt op de kaart vult de voorraadregel');
test_assert((string) $blankLocation['kvt|S1']['cost_center'] === 'WERK', 'afdeling van de kaart blijft staan zonder COST_CENTER');
test_assert(consus_location_code(['LocationCode' => 'mag']) === 'MAG', 'LocationCode vult de locatie');
$gapWarnings = consus_planning_gap_warnings($blankLocation, 'kvt');
test_assert(count($gapWarnings) === 1 && str_contains($gapWarnings[0], 'zonder locatie'), 'locatie-opmerking noemt (zonder locatie)');
$openGaps = [];
consus_apply_stock_row($openGaps, [
    'Item_No' => 'G1',
    'Company_Name' => 'Koninklijke van Twist',
    'Inventory' => 10,
    'Safety_Stock_Quantity' => 0,
    'Reorder_Point' => 0,
], 'Koninklijke van Twist');
$openGapWarnings = consus_planning_gap_warnings($openGaps, 'kvt');
test_assert(count($openGapWarnings) === 4, 'gevulde voorraad zonder planning, afdeling en locatie waarschuwt');
test_assert(consus_is_planning_gap_warning($openGapWarnings[0]), 'uitkomstwaarschuwing is herkenbaar');
$warningLines = consus_warning_lines([
    ['company' => 'KVT', 'warning' => 'locatie ontbreekt'],
    'losse regel',
    ['warning' => ''],
    '  ',
    12,
]);
test_assert($warningLines === ['KVT: locatie ontbreekt', 'losse regel'], 'waarschuwingen worden regels');
test_assert(consus_procurement_bucket_from_row(['Buy_from_Vendor_No' => '90101']) === 'egt', 'inkoop-leverancier op de artikelpost telt als EGT');
test_assert(consus_procurement_bucket_from_row(['PurchasingCode' => 'DROP_SHIP']) === 'dropship', 'PurchasingCode telt als dropship');

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
    'Description' => 'Filterelement',
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
test_assert((string) $items['kvt|A1']['description'] === 'Filterelement', 'omschrijving komt van de artikelkaart');
$zeroFirst = [];
consus_apply_stock_row($zeroFirst, [
    'Item_No' => 'Z1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'KVT',
    'Inventory' => 0,
    'Safety_Stock_Quantity' => 0,
    'Reorder_Point' => 0,
], 'Koninklijke van Twist');
consus_apply_stock_row($zeroFirst, [
    'Item_No' => 'Z1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'KVT',
    'Inventory' => 12,
    'Safety_Stock_Quantity' => 5,
    'Reorder_Point' => 3,
], 'Koninklijke van Twist');
consus_apply_stock_row($zeroFirst, [
    'Item_No' => 'Z1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'KVT',
    'Inventory' => 40,
    'Safety_Stock_Quantity' => 9,
    'Reorder_Point' => 8,
], 'Koninklijke van Twist');
test_assert((float) $zeroFirst['kvt|Z1']['inventory'] === 12.0, 'nulregel blokkeert de echte voorraad niet');
test_assert((float) $zeroFirst['kvt|Z1']['safety_stock'] === 5.0, 'nulregel blokkeert de veiligheidsvoorraad niet');
test_assert((float) $zeroFirst['kvt|Z1']['reorder_point'] === 3.0, 'nulregel blokkeert het bestelpunt niet');
test_assert((float) $zeroFirst['kvt|Z1']['by_location']['KVT']['inventory'] === 12.0, 'tweede niet-nul op dezelfde locatie telt niet op');
test_assert((string) $items['kvt|A1']['cost_center'] === 'MAG', 'COST_CENTER op de artikelkaart wint van de dimensie');
test_assert((string) $items['kvt|A2']['cost_center'] === 'WERK', 'dimensiewaarde vult een lege kostenplaats');
test_assert(isset($items['kvt|A1']['by_location']['KVT']['sales']['eigen']), 'verkoopbucket bestaat na een post');
test_assert(!isset($items['kvt|A1']['by_location']['KVT']['sales']['dropship']), 'ongebruikte verkoopbucket blijft weg');
test_assert(!isset($items['kvt|A1']['by_location']['KVT']['sales']['egt']), 'EGT-bucket blijft weg zonder EGT-post op die locatie');
test_assert(!isset($items['kvt|A1']['by_location']['KVT']['consumption']), 'verbruiksmappen blijven weg zonder verbruik');
test_assert(!isset($items['hvt|B1']['by_location']['']['sales']), 'voorraad zonder post maakt geen verkoopmap');
test_assert(isset($items['hvt|B1']['by_location']['HVT']['sales']['eigen']), 'verkoop op HVT houdt alleen de gevulde bucket');

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
test_assert(abs((float) $perkinsKvt['turnover']['egt']['m'] - (2 / 17)) < 0.0001, 'omloopsnelheid EGT deelt door de voorraad');
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
test_assert(abs((float) $department['sales']['dropship']['m']['qty'] - 4) < 0.0001, 'leverancier 90052 is dropship, ook op LOC-ZZ');
test_assert(abs((float) $department['consumption']['dropship']['m']['qty'] - 3) < 0.0001, 'DROP_SHIP-verbruik is dropship');
test_assert((int) $department['item_count'] === 1, 'afdelingsfilter laat andere afdelingen weg');
$departmentVendors = consus_vendor_options($snapshot['rows'], 'kvt', 'WERK');
test_assert(array_column($departmentVendors, 'vendor_no') === ['ANDERS'], 'leveranciers volgen de gekozen afdeling');
$departmentLocations = consus_location_options($snapshot['rows'], 'kvt', 'WERK', '');
test_assert($departmentLocations === ['BYKLANT', 'LOC-ZZ'], 'locaties volgen de gekozen afdeling');

$allDepartments = consus_summarize($snapshot, 'kvt', '', '');
test_assert((int) $allDepartments['item_count'] === 2, 'lege afdeling betekent alle afdelingen');

$perkinsArticles = consus_list_articles($snapshot, 'kvt', 'PERK', '', '');
test_assert(count($perkinsArticles) === 1 && $perkinsArticles[0]['item_no'] === 'A1', 'Perkins KVT toont artikel A1');
test_assert($perkinsArticles[0]['description'] === 'Filterelement', 'artikellijst houdt de omschrijving');
test_assert(abs((float) $perkinsArticles[0]['safety_stock'] - 5) < 0.0001, 'veiligheidsvoorraad per artikel telt locaties op');
test_assert(abs((float) $perkinsArticles[0]['inventory'] - 17) < 0.0001, 'voorraad per artikel telt locaties op');
test_assert(abs((float) $perkinsArticles[0]['consumption_y']) < 0.0001, 'A1 heeft geen WO-verbruik');
$perkinsWarehouseArticles = consus_list_articles($snapshot, 'kvt', 'PERK', '', 'KVT');
test_assert(abs((float) $perkinsWarehouseArticles[0]['inventory'] - 10) < 0.0001, 'locatiefilter beperkt de artikelvoorraad');
test_assert(abs((float) $perkinsWarehouseArticles[0]['safety_stock'] - 4) < 0.0001, 'locatiefilter beperkt de veiligheidsvoorraad');
$departmentArticles = consus_list_articles($snapshot, 'kvt', '', 'WERK', '');
test_assert(count($departmentArticles) === 1 && $departmentArticles[0]['item_no'] === 'A2', 'afdeling filtert de artikellijst');
test_assert(abs((float) $departmentArticles[0]['consumption_m'] - 3) < 0.0001, 'WO-verbruik per artikel is de maandhoeveelheid');
test_assert(abs((float) $departmentArticles[0]['consumption_q'] - 3) < 0.0001, 'WO-verbruik per artikel is het kwartaal');
test_assert(abs((float) $departmentArticles[0]['consumption_y'] - 3) < 0.0001, 'WO-verbruik per artikel is het jaar');
$bothArticles = consus_list_articles($snapshot, '', 'PERK', '', '');
test_assert(array_column($bothArticles, 'item_no') === ['A1', 'B1'], 'artikelen van KVT en HVT blijven apart en op nummer');
$articleInventory = 0.0;
$articleSafety = 0.0;
$articleConsumption = 0.0;
foreach (consus_list_articles($snapshot, 'kvt', '', '', '') as $listed) {
    $articleInventory += (float) $listed['inventory'];
    $articleSafety += (float) $listed['safety_stock'];
    $articleConsumption += (float) $listed['consumption_y'];
}
test_assert(abs($articleInventory - (float) $allDepartments['inventory']) < 0.0001, 'som van artikelvoorraad is de samenvatting');
test_assert(abs($articleSafety - (float) $allDepartments['safety_stock']) < 0.0001, 'som van artikel-veiligheidsvoorraad is de samenvatting');
test_assert(
    abs($articleConsumption - (
        (float) ($allDepartments['consumption']['eigen']['y']['qty'] ?? 0)
        + (float) ($allDepartments['consumption']['egt']['y']['qty'] ?? 0)
        + (float) ($allDepartments['consumption']['dropship']['y']['qty'] ?? 0)
    )) < 0.0001,
    'som van WO-verbruik per artikel is de samenvatting'
);
$blankVendorRows = [[
    'company_key' => 'kvt',
    'vendor_no' => '',
    'inventory' => 0,
]];
test_assert(consus_company_rows_need_full_ledger($blankVendorRows), 'rijen zonder leverancier vragen een koud grootboek');
test_assert(!consus_company_rows_need_full_ledger([['vendor_no' => 'PERK']]), 'een bekende leverancier blijft warm');

$unassigned = [];
consus_apply_stock_row($unassigned, [
    'Item_No' => 'U1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'KVT',
    'Inventory' => 8,
    'Safety_Stock_Quantity' => 2,
    'Reorder_Point' => 1,
], 'Koninklijke van Twist');
consus_apply_ledger_row($unassigned, [
    'Item_No' => 'U1',
    'Quantity' => -5,
    'Sales_Amount_Actual' => 50,
    'Posting_Date' => '2026-09-10',
    'Location_Code' => 'KVT',
], 'kvt', 'sales', $windows);
consus_apply_vendor_row($unassigned, [
    'No' => 'NOG-NIET-IN-VOORRAAD',
    'Vendor_No' => 'PERK',
    'COST_CENTER' => 'MAG',
], 'kvt');
consus_apply_dimension_row($unassigned, [
    'No' => 'NOG-NIET-IN-VOORRAAD',
    'Dimension_Code' => '15',
    'Dimension_Value_Code' => 'WERK',
], 'kvt');
$unassignedRollup = consus_rollup_items($unassigned, $windows);
test_assert(count($unassignedRollup['rows']) === 1, 'voorraad zonder artikelkaart publiceert nog een rij');
test_assert((string) $unassignedRollup['rows'][0]['vendor_no'] === '', 'mislukte artikelkaart laat vendor_no leeg');
test_assert((string) $unassignedRollup['rows'][0]['cost_center'] === '', 'mislukte dimensie laat cost_center leeg');
test_assert(abs((float) $unassignedRollup['rows'][0]['inventory'] - 8) < 0.0001, 'voorraad blijft staan zonder leverancier');
test_assert(abs((float) $unassignedRollup['rows'][0]['safety_stock'] - 2) < 0.0001, 'veiligheidsvoorraad blijft staan zonder leverancier');
test_assert(abs((float) $unassignedRollup['rows'][0]['reorder_point'] - 1) < 0.0001, 'bestelpunt blijft staan zonder leverancier');
test_assert($unassignedRollup['cost_centers'] === [], 'catalogus laat een lege kostenplaats weg');
test_assert($unassignedRollup['vendors'] === [['vendor_no' => '', 'vendor_name' => 'Geen leverancier']], 'catalogus houdt de lege leverancier');
$blankDepartments = consus_department_options($unassignedRollup['rows'], 'kvt');
test_assert($blankDepartments === [''], 'lege kostenplaats blijft een afdelingskeuze');
$blankVendors = consus_vendor_options($unassignedRollup['rows'], 'kvt', '');
test_assert(
    count($blankVendors) === 1 && $blankVendors[0]['vendor_no'] === '' && $blankVendors[0]['vendor_name'] === 'Geen leverancier',
    'lege leverancier blijft Geen leverancier'
);
$noneSummary = consus_summarize(['rows' => $unassignedRollup['rows']], 'kvt', '__none__', '__none__', 'KVT');
test_assert(abs((float) $noneSummary['inventory'] - 8) < 0.0001, 'filter zonder leverancier en afdeling houdt de rij');
$noneArticles = consus_list_articles([
    'articles' => $unassignedRollup['articles'],
], 'kvt', '__none__', '__none__', 'KVT');
test_assert(count($noneArticles) === 1 && $noneArticles[0]['item_no'] === 'U1', 'artikeltabel volgt de lege leverancier');
test_assert(consus_department_options([], '') === [], 'geen rijen betekent geen afdelingen');
test_assert(consus_vendor_options([], '', '') === [], 'geen rijen betekent geen leveranciers');
test_assert(consus_default_vendor_no($blankVendors) === '', 'alleen Geen leverancier wordt niet de standaard');
test_assert(!consus_snapshot_can_warm_ledger(['version' => CONSUS_SNAPSHOT_VERSION - 1], [['vendor_no' => 'PERK']]), 'oude snapshotversie is koud');
test_assert(consus_snapshot_can_warm_ledger(['version' => CONSUS_SNAPSHOT_VERSION], [['vendor_no' => 'PERK']]), 'huidige versie met leverancier mag warm');

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

$byCompany = [];
foreach ($items as $factKey => $fact) {
    $companyKey = (string) $fact['company_key'];
    $byCompany[$companyKey][$factKey] = $fact;
}
$freshByCompany = [];
foreach ($byCompany as $companyKey => $subset) {
    $freshByCompany[$companyKey] = consus_rollup_items($subset, $windows)['rows'];
}
$combinedRows = consus_combine_snapshot_rows($freshByCompany, [], []);
$togetherRows = consus_rollup_items($items, $windows)['rows'];
test_assert(
    json_encode($combinedRows) === json_encode($togetherRows),
    'per bedrijf oprollen geeft dezelfde snapshotrijen'
);

$staleHvt = $freshByCompany['hvt'];
$staleHvt[0]['inventory'] = 12345;
$replaced = consus_combine_snapshot_rows(
    [
        'kvt' => $freshByCompany['kvt'],
        'hvt' => [[
            'company_key' => 'hvt',
            'vendor_name' => 'Niet gebruiken',
            'vendor_no' => 'NEE',
            'cost_center' => '',
            'location' => 'HVT',
            'inventory' => 1,
        ]],
    ],
    ['hvt' => true],
    ['hvt' => $staleHvt]
);
test_assert($replaced[0]['company_key'] === 'kvt', 'verse rijen blijven voorop');
$staleKept = false;
$sawFreshHvt = false;
foreach ($replaced as $replacedRow) {
    if ((string) ($replacedRow['company_key'] ?? '') !== 'hvt') {
        continue;
    }
    $sawFreshHvt = true;
    if ((float) ($replacedRow['inventory'] ?? 0) === 12345.0) {
        $staleKept = true;
    }
}
test_assert($sawFreshHvt, 'stale HVT-rijen blijven in de snapshot');
test_assert($staleKept, 'stale bedrijf houdt de vorige rij');
test_assert(
    array_column($replaced, 'vendor_no') !== ['NEE'] && !in_array('NEE', array_column($replaced, 'vendor_no'), true),
    'mislukte bedrijfsrollup vervangt de vorige rijen niet'
);
$catalog = consus_catalog_from_rows($replaced);
test_assert(in_array('PERK', array_column($catalog['vendors'], 'vendor_no'), true), 'catalogus volgt de gecombineerde rijen');
test_assert(in_array('HVT', $catalog['locations'], true), 'locaties volgen de gecombineerde rijen');

$foreignSpills = [];
consus_foreign_spill_write($foreignSpills, 'hvt', [
    'Item_No' => 'B9',
    'Company_Name' => 'Hunter van Twist',
    'Location_Code' => 'HVT',
    'Inventory' => 4,
    'Safety_Stock_Quantity' => 1,
    'Reorder_Point' => 2,
]);
consus_foreign_spill_write($foreignSpills, 'hvt', [
    'Item_No' => 'B9',
    'Company_Name' => 'Hunter van Twist',
    'Location_Code' => 'HVT',
    'Inventory' => 99,
    'Safety_Stock_Quantity' => 99,
    'Reorder_Point' => 99,
]);
consus_foreign_spill_write($foreignSpills, 'hvt', [
    'Item_No' => 'B9',
    'Company_Name' => 'Hunter van Twist',
    'Location_Code' => 'M1',
    'Inventory' => 3,
    'Safety_Stock_Quantity' => 0,
    'Reorder_Point' => 0,
]);
$foreignPaths = consus_foreign_spill_finish($foreignSpills, false);
$foreignItems = [];
consus_apply_stock_ndjson($foreignItems, $foreignPaths['hvt'], 'Hunter van Twist');
consus_release_temp_file($foreignPaths['hvt']);
test_assert((float) $foreignItems['hvt|B9']['inventory'] === 7.0, 'vreemde voorraad telt een locatie één keer en houdt een nieuwe locatie');
test_assert((float) $foreignItems['hvt|B9']['by_location']['HVT']['safety_stock'] === 1.0, 'eerste vreemde voorraadlocatie wint');
test_assert(!is_file($foreignPaths['hvt']), 'vreemd voorraadbestand is weg');
$discardSpills = [];
consus_foreign_spill_write($discardSpills, 'kvt', ['Item_No' => 'Z', 'Company_Name' => 'Koninklijke van Twist', 'Inventory' => 1]);
$discardPath = (string) $discardSpills['kvt']['path'];
consus_foreign_spill_finish($discardSpills, true);
test_assert($discardSpills === [] && !is_file($discardPath), 'mislukte collect ruimt vreemde voorraad op');

$bulkBefore = memory_get_usage(false);
$bulk = [];
for ($i = 0; $i < 4000; $i++) {
    consus_apply_stock_row($bulk, [
        'Item_No' => 'S' . $i,
        'Company_Name' => 'Koninklijke van Twist',
        'Location_Code' => 'KVT',
        'Inventory' => 1,
        'Safety_Stock_Quantity' => 1,
        'Reorder_Point' => 1,
    ], 'Koninklijke van Twist');
}
$bulkPerRow = (memory_get_usage(false) - $bulkBefore) / 4000;
test_assert($bulkPerRow < 2800, 'voorraadregel blijft compact, kreeg ' . (int) $bulkPerRow . ' bytes');
unset($bulk);

$savedSnapshotEnv = getenv('CONSUS_SNAPSHOT_FILE');
$spillApplied = 0;
try {
    consus_collect_rows_via_spill(
        static function (callable $onSpill): int {
            $onSpill(['Item_No' => 'X']);
            $onSpill(['Item_No' => 'Y']);
            throw new RuntimeException('HTTP 400 kapot');
        },
        static function () use (&$spillApplied): void {
            $spillApplied++;
        }
    );
    test_assert(false, 'afgebroken spill moet falen');
} catch (RuntimeException $spillError) {
    test_assert(str_contains($spillError->getMessage(), 'HTTP 400'), 'spill geeft de OData-fout door');
}
test_assert($spillApplied === 0, 'afgebroken OData-poging past geen regels toe');

$spilled = [];
$spilledResult = consus_collect_rows_via_spill(
    static function (callable $onSpill): int {
        $onSpill(['Item_No' => 'A', 'Quantity' => -1]);
        $onSpill(['Item_No' => 'B', 'Quantity' => -2]);
        return 2;
    },
    static function (array $row) use (&$spilled): void {
        $spilled[] = (string) $row['Item_No'];
    }
);
test_assert($spilled === ['A', 'B'], 'gelukte spill geeft elke regel één keer door');
test_assert($spilledResult['count'] === 2, 'spill bewaart de telling');
test_assert(($spilledResult['sample']['Item_No'] ?? '') === 'A', 'sample is de eerste regel');
$incompleteApplied = 0;
try {
    consus_collect_rows_via_spill(
        static function (callable $onSpill): int {
            $onSpill(['Item_No' => 'A']);
            return 2;
        },
        static function () use (&$incompleteApplied): void {
            $incompleteApplied++;
        }
    );
    test_assert(false, 'onvolledige spill moet falen');
} catch (RuntimeException $incompleteError) {
    test_assert(str_contains($incompleteError->getMessage(), 'onvolledig'), 'replay weigert een tekort aan regels');
}
test_assert($incompleteApplied === 1, 'de geschreven regel is wel doorgegeven voor de telling faalt');
$spillLeft = glob(sys_get_temp_dir() . '/consus-odata-' . getmypid() . '-*.ndjson');
test_assert($spillLeft === [] || $spillLeft === false, 'spillbestanden zijn verwijderd');

$savedGlobals = [];
foreach (['baseUrl', 'auth_list', 'environment', 'demeter_company_environment_map'] as $globalName) {
    $savedGlobals[$globalName] = $GLOBALS[$globalName] ?? null;
}
try {
    $GLOBALS['baseUrl'] = 'https://bc.example.test/BC';
    $GLOBALS['auth_list'] = [
        'Test' => ['mode' => 'basic', 'user' => 'svc', 'pass' => 'x'],
    ];
    $GLOBALS['environment'] = 'Test';
    $GLOBALS['demeter_company_environment_map'] = [
        'Koninklijke van Twist' => 'Test',
    ];

    $fetchCalls = 0;
    $seenItems = [];
    $fetched = consus_each_entity_rows(
        'Koninklijke van Twist',
        CONSUS_LEDGER_ENTITY,
        CONSUS_LEDGER_FIELDS,
        CONSUS_LEDGER_OPTIONAL_FIELDS,
        "Entry_Type eq 'Sale' and Posting_Date ge 2025-10-01",
        static function (array $row) use (&$seenItems): void {
            $seenItems[] = (string) ($row['Item_No'] ?? '');
        },
        static function (string $url, array $auth, callable $onRow) use (&$fetchCalls): int {
            $fetchCalls++;
            test_assert(($auth['pass'] ?? '') === 'x', 'auth blijft bij het verzoek');
            if (str_contains($url, 'Purchasing_Code')) {
                $onRow(['Item_No' => 'SHOULD-NOT-APPLY', 'Purchasing_Code' => 'DROP_SHIP']);
                throw new RuntimeException("HTTP 400 Unknown: 'bad' is not an option");
            }
            $onRow(['Item_No' => 'A1', 'Quantity' => -1]);
            return 1;
        }
    );
    test_assert($seenItems === ['A1'], 'mislukte veldpoging wordt niet toegepast');
    test_assert($fetched['count'] === 1, 'telling hoort bij de geslaagde poging');
    test_assert($fetchCalls === 2, 'lege of geweigerde optie probeert de verplichte velden');
    test_assert($fetched['optional_fields'] === false, 'terugval heeft de optionele velden niet');

    $emptyThenRow = [];
    $emptyCalls = 0;
    consus_each_entity_rows(
        'Koninklijke van Twist',
        CONSUS_LEDGER_ENTITY,
        CONSUS_LEDGER_FIELDS,
        CONSUS_LEDGER_OPTIONAL_FIELDS,
        "Entry_Type eq 'Sale' and Posting_Date ge 2025-10-01",
        static function (array $row) use (&$emptyThenRow): void {
            $emptyThenRow[] = (string) ($row['Item_No'] ?? '');
        },
        static function (string $url, array $auth, callable $onRow) use (&$emptyCalls): int {
            unset($auth);
            $emptyCalls++;
            if (str_contains($url, 'Purchasing_Code')) {
                return 0;
            }
            $onRow(['Item_No' => 'ALLEEN', 'Quantity' => -3, 'Posting_Date' => '2026-09-01']);
            return 1;
        }
    );
    test_assert($emptyThenRow === ['ALLEEN'], 'lege optionele poging probeert opnieuw zonder die regels');
    test_assert($emptyCalls === 2, 'lege optionele poging telt als verzoek');

    $pageCalls = 0;
    $pageRows = [];
    $pageFetched = consus_each_entity_rows(
        'Koninklijke van Twist',
        CONSUS_LEDGER_ENTITY,
        ['Item_No', 'Quantity'],
        [],
        "Entry_Type eq 'Sale'",
        static function (array $row) use (&$pageRows): void {
            $pageRows[] = (string) ($row['Item_No'] ?? '');
        },
        static function (string $url, array $auth, callable $onRow) use (&$pageCalls): int {
            unset($auth);
            $pageCalls++;
            if (str_contains($url, 'top=')) {
                throw new RuntimeException('HTTP 400 The maximum page size is 1000');
            }
            $onRow(['Item_No' => 'P1', 'Quantity' => -1]);

            return 1;
        }
    );
    test_assert($pageCalls === 2, 'te grote pagina probeert zonder $top');
    test_assert($pageRows === ['P1'], 'mislukte paginagrootte wordt niet toegepast');
    test_assert($pageFetched['page_size_fallback'] === true, 'terugval op BC-standaard is zichtbaar');

    $peelCalls = 0;
    $peeledRows = [];
    $peeled = consus_each_entity_rows(
        'Koninklijke van Twist',
        CONSUS_ITEM_ENTITY,
        ['No', 'Vendor_No'],
        ['LVS_Vendor_Name', 'Description'],
        '',
        static function (array $row) use (&$peeledRows): void {
            $peeledRows[] = (string) ($row['Vendor_No'] ?? '');
        },
        static function (string $url, array $auth, callable $onRow) use (&$peelCalls): int {
            unset($auth);
            $peelCalls++;
            if (str_contains($url, 'Description')) {
                throw new RuntimeException("HTTP 400 Could not find a property named 'Description' on type 'NAV.AppItemCard'");
            }
            $onRow(['No' => 'A1', 'Vendor_No' => 'PERK', 'LVS_Vendor_Name' => 'Perkins']);

            return 1;
        }
    );
    test_assert($peelCalls === 2, 'een geweigerd veld wordt uit de select gehaald');
    test_assert(($peeledRows[0] ?? '') === 'PERK', 'leverancier blijft na het weghalen van het veld');
    test_assert($peeled['missing_optional'] === ['Description'], 'het geweigerde veld staat bij de ontbrekende velden');

    $networkCalls = 0;
    try {
        consus_each_entity_rows(
            'Koninklijke van Twist',
            CONSUS_LEDGER_ENTITY,
            ['Item_No'],
            [],
            "Entry_Type eq 'Sale'",
            static function (): void {
            },
            static function (string $url, array $auth, callable $onRow) use (&$networkCalls): int {
                unset($url, $auth, $onRow);
                $networkCalls++;
                throw new RuntimeException('cURL error: timeout');
            }
        );
        test_assert(false, 'netwerkfout moet falen');
    } catch (RuntimeException $networkError) {
        test_assert(str_contains($networkError->getMessage(), 'cURL error'), 'netwerkfout blijft een netwerkfout');
    }
    test_assert($networkCalls === 1, 'netwerkfout probeert geen andere paginagrootte');

    $withPrefix = 0;
    $withoutPrefix = 0;
    $prefixRows = [];
    $prefixResult = consus_each_ledger_entry_type(
        'Koninklijke van Twist',
        ['Negative Adjmt.'],
        '2025-10-01',
        '2025-11-01',
        false,
        'WO',
        true,
        static function (array $row) use (&$prefixRows): void {
            $prefixRows[] = (string) ($row['Item_No'] ?? '');
        },
        null,
        static function (string $url, array $auth, callable $onRow) use (&$withPrefix, &$withoutPrefix): int {
            unset($auth);
            $decoded = rawurldecode($url);
            if (str_contains($decoded, "Document_No ge 'WO'")) {
                $withPrefix++;
                throw new RuntimeException('HTTP 501 from OData: De OData-filterexpressie wordt niet ondersteund.');
            }
            $withoutPrefix++;
            test_assert(str_contains($decoded, 'Document_No'), 'terugval houdt Document_No in de select');
            test_assert(!str_contains($decoded, 'Sales_Amount_Actual'), 'verbruikterugval haalt geen omzet op');
            $onRow(['Item_No' => 'W1', 'Document_No' => 'WO1', 'Quantity' => -1]);

            return 1;
        }
    );
    test_assert($withPrefix >= 1, 'documentbereik wordt geprobeerd');
    test_assert($withoutPrefix === 1, 'zonder bereik lukt het in één keer');
    test_assert($prefixResult['document_filter_rejected'] === true, 'geweigerd documentbereik valt terug');
    test_assert($prefixRows === ['W1'], 'terugval past de regel één keer toe');

    $dimensionTableCalls = 0;
    $dimensionCodeCalls = 0;
    $dimensionApplied = [];
    $dimensionResult = consus_each_dimension_rows(
        'Koninklijke van Twist',
        static function (array $row) use (&$dimensionApplied): void {
            $dimensionApplied[] = (string) ($row['Dimension_Value_Code'] ?? '');
        },
        static function (string $url, array $auth, callable $onRow) use (&$dimensionTableCalls, &$dimensionCodeCalls): int {
            unset($auth);
            $decoded = rawurldecode($url);
            if (str_contains($decoded, 'Table_ID')) {
                $dimensionTableCalls++;
                throw new RuntimeException('HTTP 400 from OData: De OData-filterexpressie wordt niet ondersteund.');
            }
            $dimensionCodeCalls++;
            test_assert(str_contains($decoded, "Dimension_Code eq '15'"), 'terugval filtert op dimensie 15');
            test_assert(!str_contains($decoded, 'Table_ID'), 'terugval laat Table_ID weg');
            $onRow(['No' => 'P1', 'Dimension_Code' => '15', 'Dimension_Value_Code' => 'WERK']);

            return 1;
        }
    );
    test_assert($dimensionTableCalls === 2, 'tabelfilter wordt op optionele en verplichte velden geprobeerd');
    test_assert($dimensionCodeCalls === 1, 'zonder Table_ID lukt dimensie 15 in één keer');
    test_assert($dimensionResult['filter_fallback'] === true, 'geweigerd tabelfilter valt terug');
    test_assert($dimensionApplied === ['WERK'], 'terugval past de dimensieregel één keer toe');

    $dimensionNetworkCalls = 0;
    try {
        consus_each_dimension_rows(
            'Koninklijke van Twist',
            static function (): void {
            },
            static function (string $url, array $auth, callable $onRow) use (&$dimensionNetworkCalls): int {
                unset($url, $auth, $onRow);
                $dimensionNetworkCalls++;
                throw new RuntimeException('cURL error: timeout');
            }
        );
        test_assert(false, 'netwerkfout op dimensies moet falen');
    } catch (RuntimeException $dimensionNetwork) {
        test_assert(str_contains($dimensionNetwork->getMessage(), 'cURL error'), 'dimensie-netwerkfout wordt niet als filterfout behandeld');
    }
    test_assert($dimensionNetworkCalls === 2, 'netwerkfout probeert het dimensiefilter niet opnieuw');

    $fatalCalls = 0;
    try {
        consus_each_ledger_entry_type(
            'Koninklijke van Twist',
            ['Negative Adjmt.'],
            '2025-10-01',
            '2025-11-01',
            false,
            'WO',
            true,
            static function (): void {
            },
            null,
            static function (string $url, array $auth, callable $onRow) use (&$fatalCalls): int {
                unset($url, $auth, $onRow);
                $fatalCalls++;
                throw new RuntimeException('cURL error: timeout');
            }
        );
        test_assert(false, 'netwerkfout op artikelposten moet falen');
    } catch (RuntimeException $ledgerNetwork) {
        test_assert(str_contains($ledgerNetwork->getMessage(), 'cURL error'), 'netwerkfout wordt niet als filterfout behandeld');
    }
    test_assert($fatalCalls === 2, 'netwerkfout laat het documentfilter niet vallen');
} finally {
    foreach ($savedGlobals as $globalName => $globalValue) {
        $GLOBALS[$globalName] = $globalValue;
    }
    if ($savedSnapshotEnv === false) {
        putenv('CONSUS_SNAPSHOT_FILE');
    } else {
        putenv('CONSUS_SNAPSHOT_FILE=' . $savedSnapshotEnv);
    }
}

$badTo = false;
try {
    consus_ledger_query(['Sale'], '2025-10-01', '2025-10-01', '', true);
} catch (InvalidArgumentException $badToError) {
    $badTo = str_contains($badToError->getMessage(), 'tot-datum');
}
test_assert($badTo, 'ongeldige tot-datum blijft Nederlands');

$progressTemp = sys_get_temp_dir() . '/consus-progress-' . getmypid() . '.json';
$savedProgressEnv = getenv('CONSUS_SNAPSHOT_FILE');
putenv('CONSUS_SNAPSHOT_FILE=' . $progressTemp);
@unlink(consus_progress_file());
consus_write_progress([
    'company' => 'Koninklijke van Twist',
    'company_key' => 'kvt',
    'step' => 'verkoop',
    'from' => '2025-10-01',
    'to' => '2025-11-01',
    'pages' => 3,
    'rows' => 40,
]);
$progress = json_decode((string) file_get_contents(consus_progress_file()), true);
test_assert(is_array($progress) && ($progress['step'] ?? '') === 'verkoop', 'voortgang noemt de stap');
test_assert(($progress['pages'] ?? 0) === 3, 'voortgang telt pagina\'s');
test_assert(($progress['from'] ?? '') === '2025-10-01', 'voortgang noemt de maand');
test_assert(($progress['rows'] ?? 0) === 40, 'voortgang telt regels');
@unlink(consus_progress_file());
@unlink($progressTemp);
@unlink($progressTemp . '.lock');
if ($savedProgressEnv === false) {
    putenv('CONSUS_SNAPSHOT_FILE');
} else {
    putenv('CONSUS_SNAPSHOT_FILE=' . $savedProgressEnv);
}

$index = (string) file_get_contents(__DIR__ . '/../web/index.php');
test_assert(!str_contains($index, 'odata_get'), 'index.php doet geen OData-call');
test_assert(!str_contains($index, 'curl_'), 'index.php gebruikt geen cURL');
test_assert(!str_contains($index, 'consus_run_nightly'), 'index.php start geen BC-refresh');
test_assert(!str_contains($index, 'Perkins'), 'index.php zet Perkins niet vast');
test_assert(str_contains($index, 'consus_warning_lines'), 'index toont snapshotwaarschuwingen');
test_assert(str_contains($index, 'De nachtrun is afgerond, met opmerkingen.'), 'opmerkingen hebben een zichtbare kop');
test_assert(str_contains($index, '(geen afdeling)'), 'lege afdeling blijft kiesbaar');
test_assert(str_contains($index, 'Geen leverancier'), 'lege leverancier blijft kiesbaar');
test_assert(str_contains($index, "\$number === '' ? '__none__' : \$number"), 'lege leverancier gebruikt dezelfde sentinel als afdeling');
test_assert(!str_contains($index, "if (\$number === '') { continue; }"), 'lege leverancier wordt niet uit de dropdown gelaten');
test_assert(str_contains((string) file_get_contents(__DIR__ . '/../web/nightly.php'), 'consus_run_nightly'), 'nightly.php is de refresh');

$lockProbeDir = sys_get_temp_dir() . '/consus-lock-probe-' . getmypid();
mkdir($lockProbeDir, 0775, true);
$snapshotProbe = $lockProbeDir . '/consus_snapshot.json';
putenv('CONSUS_SNAPSHOT_FILE=' . $snapshotProbe);
$badLock = $snapshotProbe . '.lock';
mkdir($badLock, 0775);
$sawBadLock = false;
try {
    consus_with_snapshot_lock(static function (): void {
    });
} catch (RuntimeException $lockError) {
    $sawBadLock = true;
    test_assert(str_contains($lockError->getMessage(), $badLock), 'lockfout noemt het pad');
    test_assert(str_contains($lockError->getMessage(), 'geen gewoon bestand'), 'lockfout meldt geen gewoon bestand');
    test_assert(str_contains($lockError->getMessage(), 'web/data'), 'lockfout noemt de repo-map web/data');
    test_assert(str_contains($lockError->getMessage(), '/var/www/html/consus/data/'), 'lockfout noemt het serverpad');
}
test_assert($sawBadLock, 'directory-lock gooit een fout');
rmdir($badLock);
putenv('CONSUS_SNAPSHOT_FILE');
rmdir($lockProbeDir);

$stepIds = array_column(consus_ledger_steps(), 'id');
test_assert($stepIds === ['verkoop', 'verbruik-wo', 'verbruik-assemblage'], 'checkpoints per verkoop, WO en assemblage');
test_assert(consus_shift_date('2026-09-24', -1) === '2026-09-23', 'dag terug blijft een kalenderdag');

$warmQuery = consus_ledger_query(['Sale'], '2026-09-23', '2026-09-25', '', true, false);
test_assert(
    str_contains($warmQuery['$filter'], 'Posting_Date ge 2026-09-23')
    && str_contains($warmQuery['$filter'], 'Posting_Date lt 2026-09-25'),
    'warme filter begint op het watermerk en houdt een bovengrens'
);

$warmStat = [
    'ledger_through' => '2026-09-23',
    'ledger_overlap_from' => '2026-09-23',
    'stale' => true,
];
test_assert(consus_warm_ledger_from($warmStat, $windows, true, false) === '2026-09-23', 'stale met watermerk blijft warm');
test_assert(consus_warm_ledger_from($warmStat, $windows, true, true) === '', 'full negeert het watermerk');
test_assert(consus_warm_ledger_from($warmStat, $windows, false, false) === '', 'zonder vorige rijen geen warm');
test_assert(consus_warm_ledger_from([], $windows, true, false) === '', 'zonder watermerk koud');
$staleMarker = $warmStat;
$staleMarker['ledger_through'] = '2024-01-01';
$staleMarker['ledger_overlap_from'] = '2024-01-01';
test_assert(consus_warm_ledger_from($staleMarker, $windows, true, false) === '', 'watermerk buiten het venster is koud');

$warmPlan = consus_ledger_plan($warmStat, $windows, true, false);
test_assert($warmPlan['mode'] === 'warm', 'plan met watermerk is warm');
test_assert($warmPlan['chunks'] === [['from' => '2026-09-23', 'to' => '2026-09-25']], 'warm haalt de overlapdag en de nieuwe dag');
$coldPlan = consus_ledger_plan($warmStat, $windows, true, true);
test_assert($coldPlan['mode'] === 'cold' && count($coldPlan['chunks']) === 12, 'full haalt twaalf maanden');

$failedKeep = consus_failed_company_stat('Koninklijke van Twist', 'kvt', 4, $warmStat, true);
test_assert(($failedKeep['ledger_through'] ?? '') === '2026-09-23', 'stale houdt het watermerk als de vorige rijen blijven');
test_assert(($failedKeep['ledger_overlap_from'] ?? '') === '2026-09-23', 'stale houdt ook de overlapdag');
test_assert(!isset($failedKeep['refreshed_on']), 'mislukte run zet refreshed_on niet');
$failedDrop = consus_failed_company_stat('Koninklijke van Twist', 'kvt', 4, $warmStat, false);
test_assert(!isset($failedDrop['ledger_through']), 'zonder vorige rijen valt het watermerk weg');
$stockOnlyStat = consus_stock_only_company_stat($failedKeep);
test_assert(!isset($stockOnlyStat['ledger_through']) && !isset($stockOnlyStat['ledger_overlap_from']), 'alleen-voorraad wist het watermerk');

$completed = consus_completed_company_stat('Koninklijke van Twist', 'kvt', $windows, 9, 'warm', true);
test_assert($completed['refreshed_on'] === $windows['as_of'], 'refreshed_on komt pas als het bedrijf af is');
test_assert($completed['ledger_through'] === $windows['as_of'] && $completed['ledger_overlap_from'] === $windows['as_of'], 'watermerk is de peildatum, één dag overlap');
test_assert($completed['checkpoint_resumed'] === true && $completed['ledger_mode'] === 'warm', 'afgerond bedrijf onthoudt hervatting en warm');

$partialCompany = [
    'version' => CONSUS_SNAPSHOT_VERSION,
    'as_of' => $windows['as_of'],
    'windows' => $windows,
    'companies' => [[
        'company_key' => 'kvt',
        'stale' => false,
        'ledger_through' => $windows['as_of'],
        'ledger_overlap_from' => $windows['as_of'],
    ]],
];
test_assert(!consus_company_refresh_is_current($partialCompany, 'kvt', $windows), 'watermerk zonder refreshed_on slaat het bedrijf niet over');

$previousWarm = [[
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'vendor_no' => 'PERK',
    'vendor_name' => 'Perkins',
    'cost_center' => 'MAG',
    'location' => 'KVT',
    'inventory' => 9,
    'safety_stock' => 2,
    'reorder_point' => 1,
    'item_count' => 3,
    'item_nos' => ['A1' => true, 'OLD' => true, 'MOVED' => true],
    'sales' => ['eigen' => [
        'months' => [
            '2025-10' => ['qty' => 10, 'amount' => 100],
            '2026-08' => ['qty' => 4, 'amount' => 40],
            '2026-09' => ['qty' => 20, 'amount' => 200],
        ],
        'days' => [
            '2026-09-23' => ['qty' => 5, 'amount' => 50],
        ],
        'm' => ['qty' => 999, 'amount' => 999],
        'q' => ['qty' => 999, 'amount' => 999],
        'y' => ['qty' => 999, 'amount' => 999],
    ]],
    'consumption' => [],
], [
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'vendor_no' => 'PERK',
    'vendor_name' => 'Perkins',
    'cost_center' => 'MAG',
    'location' => 'M100',
    'inventory' => 3,
    'safety_stock' => 1,
    'reorder_point' => 1,
    'item_count' => 1,
    'item_nos' => ['A9' => true],
    'sales' => ['eigen' => [
        'months' => ['2026-09' => ['qty' => 6, 'amount' => 60]],
        'days' => ['2026-09-23' => ['qty' => 1, 'amount' => 10]],
        'm' => ['qty' => 6, 'amount' => 60],
        'q' => ['qty' => 6, 'amount' => 60],
        'y' => ['qty' => 6, 'amount' => 60],
    ]],
    'consumption' => [],
]];
$freshWarm = [[
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'vendor_no' => 'PERK',
    'vendor_name' => 'Perkins',
    'cost_center' => 'MAG',
    'location' => 'KVT',
    'inventory' => 12,
    'safety_stock' => 4,
    'reorder_point' => 2,
    'item_count' => 1,
    'item_nos' => ['A1' => true],
    'sales' => ['eigen' => [
        'months' => ['2026-09' => ['qty' => 9, 'amount' => 90]],
        'days' => ['2026-09-24' => ['qty' => 2, 'amount' => 20]],
        'm' => ['qty' => 9, 'amount' => 90],
        'q' => ['qty' => 9, 'amount' => 90],
        'y' => ['qty' => 9, 'amount' => 90],
    ]],
    'consumption' => [],
], [
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'vendor_no' => 'ANDERS',
    'vendor_name' => 'Anders',
    'cost_center' => 'MAG',
    'location' => 'KVT',
    'inventory' => 4,
    'safety_stock' => 0,
    'reorder_point' => 0,
    'item_count' => 1,
    'item_nos' => ['MOVED' => true],
    'sales' => ['eigen' => [
        'months' => ['2026-09' => ['qty' => 3, 'amount' => 30]],
        'days' => ['2026-09-24' => ['qty' => 3, 'amount' => 30]],
        'm' => ['qty' => 3, 'amount' => 30],
        'q' => ['qty' => 3, 'amount' => 30],
        'y' => ['qty' => 3, 'amount' => 30],
    ]],
    'consumption' => [],
]];
$mergedWarm = consus_merge_warm_company_rows($previousWarm, $freshWarm, $windows, '2026-09-23', '2026-09-23');
$mergedByKey = [];
foreach ($mergedWarm as $mergedRow) {
    $mergedByKey[$mergedRow['vendor_no'] . '|' . $mergedRow['location']] = $mergedRow;
}
$perk = $mergedByKey['PERK|KVT'];
test_assert(abs((float) $perk['sales']['eigen']['months']['2026-09']['qty'] - 24) < 0.0001, 'september is oud min overlap plus de nieuwe query');
test_assert(abs((float) $perk['sales']['eigen']['months']['2026-08']['qty'] - 4) < 0.0001, 'oudere maand blijft staan');
test_assert(abs((float) $perk['sales']['eigen']['months']['2025-10']['qty'] - 10) < 0.0001, 'maand buiten het warme venster blijft staan');
test_assert(abs((float) $perk['sales']['eigen']['m']['qty'] - 24) < 0.0001, 'maandtotaal wordt herberekend en niet opgeteld bij het oude');
test_assert(abs((float) $perk['sales']['eigen']['q']['qty'] - 28) < 0.0001, 'kwartaal telt augustus en september');
test_assert(!isset($perk['sales']['eigen']['days']['2026-09-23']), 'oude overlapdag is vervangen');
test_assert(abs((float) $perk['sales']['eigen']['days']['2026-09-24']['qty'] - 2) < 0.0001, 'nieuwe overlapdag is de peildatum');
test_assert((float) $perk['inventory'] === 12.0, 'voorraad komt uit de verse snapshot');
test_assert(isset($perk['item_nos']['OLD']) && isset($perk['item_nos']['A1']) && !isset($perk['item_nos']['MOVED']), 'artikel dat van leverancier wisselt verlaat de oude groep');
test_assert((float) $mergedByKey['PERK|M100']['inventory'] === 0.0, 'locatie zonder verse voorraad gaat naar nul');
test_assert(abs((float) $mergedByKey['PERK|M100']['sales']['eigen']['months']['2026-09']['qty'] - 5) < 0.0001, 'verkoop zonder nieuwe posten houdt de historie na aftrek van de overlap');
test_assert(abs((float) $mergedByKey['ANDERS|KVT']['sales']['eigen']['months']['2026-09']['qty'] - 3) < 0.0001, 'nieuwe groep houdt alleen de warme delta');

$previousArticles = [[
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'item_no' => 'A1',
    'description' => 'Filterelement',
    'vendor_no' => 'PERK',
    'vendor_name' => 'Perkins',
    'cost_center' => 'MAG',
    'location' => 'KVT',
    'inventory' => 9,
    'safety_stock' => 2,
    'reorder_point' => 1,
    'consumption' => [
        'months' => ['2026-08' => 4.0, '2026-09' => 10.0],
        'days' => ['2026-09-23' => 3.0],
        'm' => 10.0,
        'q' => 14.0,
        'y' => 14.0,
    ],
], [
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'item_no' => 'MOVED',
    'description' => '',
    'vendor_no' => 'PERK',
    'vendor_name' => 'Perkins',
    'cost_center' => 'MAG',
    'location' => 'KVT',
    'inventory' => 1,
    'safety_stock' => 0,
    'reorder_point' => 0,
    'consumption' => [
        'months' => ['2026-09' => 5.0],
        'days' => ['2026-09-23' => 1.0],
        'm' => 5.0,
        'q' => 5.0,
        'y' => 5.0,
    ],
]];
$freshArticles = [[
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'item_no' => 'A1',
    'description' => 'Filterelement',
    'vendor_no' => 'PERK',
    'vendor_name' => 'Perkins',
    'cost_center' => 'MAG',
    'location' => 'KVT',
    'inventory' => 8,
    'safety_stock' => 4,
    'reorder_point' => 2,
    'consumption' => [
        'months' => ['2026-09' => 6.0],
        'days' => ['2026-09-24' => 2.0],
        'm' => 6.0,
        'q' => 6.0,
        'y' => 6.0,
    ],
], [
    'company_key' => 'kvt',
    'company_name' => 'Koninklijke van Twist',
    'item_no' => 'MOVED',
    'description' => 'Verhuisd',
    'vendor_no' => 'ANDERS',
    'vendor_name' => 'Anders',
    'cost_center' => 'MAG',
    'location' => 'KVT',
    'inventory' => 3,
    'safety_stock' => 1,
    'reorder_point' => 0,
    'consumption' => [
        'months' => ['2026-09' => 2.0],
        'days' => ['2026-09-24' => 2.0],
        'm' => 2.0,
        'q' => 2.0,
        'y' => 2.0,
    ],
]];
$mergedArticles = consus_merge_warm_company_articles($previousArticles, $freshArticles, $windows, '2026-09-23', '2026-09-23');
$mergedArticleByKey = [];
foreach ($mergedArticles as $mergedArticle) {
    $mergedArticleByKey[$mergedArticle['vendor_no'] . '|' . $mergedArticle['item_no']] = $mergedArticle;
}
test_assert(abs((float) $mergedArticleByKey['PERK|A1']['consumption']['months']['2026-09'] - 13) < 0.0001, 'artikelverbruik is oud min overlap plus de nieuwe query');
test_assert(abs((float) $mergedArticleByKey['PERK|A1']['consumption']['months']['2026-08'] - 4) < 0.0001, 'oudere verbruiksmaand van het artikel blijft');
test_assert(abs((float) $mergedArticleByKey['PERK|A1']['consumption']['m'] - 13) < 0.0001, 'artikelmaand wordt herberekend');
test_assert((float) $mergedArticleByKey['PERK|A1']['inventory'] === 8.0, 'artikelvoorraad komt uit de verse rollup');
test_assert((float) $mergedArticleByKey['PERK|A1']['safety_stock'] === 4.0, 'artikel-veiligheidsvoorraad komt uit de verse rollup');
test_assert(abs((float) $mergedArticleByKey['PERK|MOVED']['consumption']['months']['2026-09'] - 4) < 0.0001, 'oud verbruik blijft bij de vorige leverancier');
test_assert((float) $mergedArticleByKey['PERK|MOVED']['inventory'] === 0.0, 'verhuisd artikel heeft geen voorraad meer bij de oude leverancier');
test_assert((float) $mergedArticleByKey['ANDERS|MOVED']['inventory'] === 3.0, 'verhuisd artikel houdt de verse voorraad');
test_assert(abs((float) $mergedArticleByKey['ANDERS|MOVED']['consumption']['m'] - 2) < 0.0001, 'nieuwe leverancier houdt alleen de warme delta');
$listedMoved = consus_list_articles([
    'articles' => $mergedArticles,
], 'kvt', '', '', '');
$movedByVendor = [];
foreach ($mergedArticles as $mergedArticle) {
    if ($mergedArticle['item_no'] === 'MOVED') {
        $movedByVendor[$mergedArticle['vendor_no']] = true;
    }
}
test_assert(isset($movedByVendor['PERK'], $movedByVendor['ANDERS']), 'verhuisd artikel blijft in beide leveranciersslices');
$listedPerk = consus_list_articles(['articles' => $mergedArticles], 'kvt', 'PERK', '', 'KVT');
test_assert(array_column($listedPerk, 'item_no') === ['A1', 'MOVED'], 'filter leverancier en locatie beperkt de artikellijst');
unset($listedMoved);

$octoberWindows = consus_period_windows(new DateTimeImmutable('2026-10-01', new DateTimeZone('Europe/Amsterdam')));
$octoberMerged = consus_merge_warm_company_rows(
    [[
        'company_key' => 'kvt',
        'company_name' => 'Koninklijke van Twist',
        'vendor_no' => 'PERK',
        'vendor_name' => 'Perkins',
        'cost_center' => '',
        'location' => 'KVT',
        'inventory' => 9,
        'safety_stock' => 0,
        'reorder_point' => 0,
        'item_nos' => ['A1' => true],
        'sales' => ['eigen' => [
            'months' => [
                '2025-10' => ['qty' => 10, 'amount' => 10],
                '2026-09' => ['qty' => 30, 'amount' => 30],
            ],
            'days' => ['2026-09-30' => ['qty' => 3, 'amount' => 3]],
            'm' => ['qty' => 30, 'amount' => 30],
            'q' => ['qty' => 30, 'amount' => 30],
            'y' => ['qty' => 30, 'amount' => 30],
        ]],
        'consumption' => [],
    ]],
    [[
        'company_key' => 'kvt',
        'company_name' => 'Koninklijke van Twist',
        'vendor_no' => 'PERK',
        'vendor_name' => 'Perkins',
        'cost_center' => '',
        'location' => 'KVT',
        'inventory' => 8,
        'safety_stock' => 0,
        'reorder_point' => 0,
        'item_nos' => ['A1' => true],
        'sales' => ['eigen' => [
            'months' => [
                '2026-09' => ['qty' => 4, 'amount' => 4],
                '2026-10' => ['qty' => 1, 'amount' => 1],
            ],
            'days' => ['2026-10-01' => ['qty' => 1, 'amount' => 1]],
            'm' => ['qty' => 1, 'amount' => 1],
            'q' => ['qty' => 1, 'amount' => 1],
            'y' => ['qty' => 5, 'amount' => 5],
        ]],
        'consumption' => [],
    ]],
    $octoberWindows,
    '2026-09-30',
    '2026-09-30'
);
$octoberRow = $octoberMerged[0];
test_assert(abs((float) $octoberRow['sales']['eigen']['months']['2026-09']['qty'] - 31) < 0.0001, 'september blijft volledig na de maandgrens');
test_assert(abs((float) $octoberRow['sales']['eigen']['m']['qty'] - 1) < 0.0001, 'nieuwe maand telt alleen oktober');
test_assert(!isset($octoberRow['sales']['eigen']['months']['2025-10']), 'maand die uit het venster valt verdwijnt');
test_assert(abs((float) $octoberRow['sales']['eigen']['days']['2026-10-01']['qty'] - 1) < 0.0001, 'overlapdag schuift mee naar de nieuwe peildatum');

$checkpointRoot = sys_get_temp_dir() . '/consus-checkpoint-' . getmypid() . '.json';
$savedCheckpointEnv = getenv('CONSUS_SNAPSHOT_FILE');
putenv('CONSUS_SNAPSHOT_FILE=' . $checkpointRoot);
@unlink($checkpointRoot);
consus_clear_checkpoint();
try {
    $stockSteps = [];
    $stockApplied = [];
    $stockFetches = 0;
    $stockSaves = 0;
    $fetchStock = static function (?array &$writer) use (&$stockFetches, &$stockApplied): array {
        $stockFetches++;
        $stockApplied[] = 'fetch';
        consus_checkpoint_write_row($writer, [
            'Item_No' => 'S1',
            'Company_Name' => 'Koninklijke van Twist',
            'Location_Code' => 'KVT',
            'Inventory' => 4,
        ]);

        return ['count' => 1];
    };
    $replayStock = static function (array $row) use (&$stockApplied): void {
        $stockApplied[] = (string) ($row['Item_No'] ?? '');
    };
    $saveStock = static function () use (&$stockSaves): void {
        $stockSaves++;
    };
    $firstStock = consus_collect_entity_with_checkpoint($stockSteps, 'kvt', 'voorraad', $replayStock, $fetchStock, $saveStock, true);
    test_assert($firstStock['replayed'] === false && $stockSaves === 1, 'voorraad schrijft een tussenstap');
    test_assert(($stockSteps['voorraad']['done'] ?? false) === true, 'voorraadstap is af');
    $secondStock = consus_collect_entity_with_checkpoint($stockSteps, 'kvt', 'voorraad', $replayStock, $fetchStock, $saveStock, true);
    test_assert($secondStock['replayed'] === true && $stockFetches === 1, 'voorraad wordt niet opnieuw opgehaald');
    test_assert($stockApplied === ['fetch', 'S1'], 'hervatte voorraad leest de opgeslagen regel');

    $emptySteps = [
        'voorraad' => [
            'done' => true,
            'file' => 'kvt-voorraad-leeg.ndjson',
            'rows' => 0,
        ],
    ];
    $emptyFile = consus_checkpoint_directory() . '/kvt-voorraad-leeg.ndjson';
    file_put_contents($emptyFile, '');
    $emptyFetches = 0;
    $emptyApplied = [];
    $emptyFetched = consus_collect_entity_with_checkpoint(
        $emptySteps,
        'kvt',
        'voorraad',
        static function (array $row) use (&$emptyApplied): void {
            $emptyApplied[] = (string) ($row['Item_No'] ?? '');
        },
        static function (?array &$writer) use (&$emptyFetches): array {
            $emptyFetches++;
            consus_checkpoint_write_row($writer, [
                'Item_No' => 'S2',
                'Company_Name' => 'Koninklijke van Twist',
                'Location_Code' => 'KVT',
                'Inventory' => 6,
                'Safety_Stock_Quantity' => 2,
                'Reorder_Point' => 1,
            ]);

            return ['count' => 1, 'optional_fields' => true, 'missing_optional' => [], 'page_size_fallback' => false];
        },
        static function (): void {
        },
        true
    );
    test_assert($emptyFetched['replayed'] === false && $emptyFetches === 1, 'lege voorraadstap wordt opnieuw opgehaald');
    test_assert($emptyApplied === [], 'lege tussenstap past geen regels toe');
    $emptyReplay = [];
    consus_collect_entity_with_checkpoint(
        $emptySteps,
        'kvt',
        'voorraad',
        static function (array $row) use (&$emptyReplay): void {
            $emptyReplay[] = (string) ($row['Item_No'] ?? '');
        },
        static function (?array &$writer): array {
            unset($writer);
            test_assert(false, 'gevulde voorraadstap hoeft niet nog eens');

            return ['count' => 0];
        },
        static function (): void {
        },
        true
    );
    test_assert($emptyReplay === ['S2'], 'na de nieuwe ophaalronde hervat de voorraad wel');

    $articleItems = [];
    consus_apply_stock_row($articleItems, [
        'Item_No' => 'U1',
        'Company_Name' => 'Koninklijke van Twist',
        'Location_Code' => 'KVT',
        'Inventory' => 8,
        'Safety_Stock_Quantity' => 2,
        'Reorder_Point' => 1,
    ], 'Koninklijke van Twist');
    $articleFile = consus_checkpoint_directory() . '/kvt-artikelen-gevuld.ndjson';
    file_put_contents($articleFile, json_encode([
        'No' => 'U1',
        'Vendor_No' => 'PERK',
        'LVS_Vendor_Name' => 'Perkins Engines',
        'COST_CENTER' => 'MAG',
    ], JSON_UNESCAPED_UNICODE) . "\n");
    $articleSteps = [
        'artikelen' => [
            'done' => true,
            'file' => 'kvt-artikelen-gevuld.ndjson',
            'rows' => 1,
        ],
    ];
    $articleFetches = 0;
    $articleResume = consus_collect_entity_with_checkpoint(
        $articleSteps,
        'kvt',
        'artikelen',
        static function (array $row) use (&$articleItems): void {
            consus_apply_vendor_row($articleItems, $row, 'kvt');
        },
        static function (?array &$writer) use (&$articleFetches): array {
            $articleFetches++;
            unset($writer);

            return ['count' => 0];
        },
        static function (): void {
        },
        true
    );
    test_assert($articleResume['replayed'] === true && $articleFetches === 0, 'gevulde artikelstap wordt hervat, niet overgeslagen');
    test_assert((string) $articleItems['kvt|U1']['vendor_no'] === 'PERK', 'hervatte artikelkaart vult vendor_no');
    test_assert((string) $articleItems['kvt|U1']['cost_center'] === 'MAG', 'hervatte artikelkaart vult cost_center');

    $emptyArticleSteps = [
        'artikelen' => [
            'done' => true,
            'file' => 'kvt-artikelen-leeg.ndjson',
            'rows' => 0,
        ],
    ];
    file_put_contents(consus_checkpoint_directory() . '/kvt-artikelen-leeg.ndjson', '');
    $emptyArticleFetches = 0;
    $emptyArticle = consus_collect_entity_with_checkpoint(
        $emptyArticleSteps,
        'kvt',
        'artikelen',
        static function (array $row): void {
            unset($row);
        },
        static function (?array &$writer) use (&$emptyArticleFetches): array {
            $emptyArticleFetches++;
            consus_checkpoint_write_row($writer, [
                'No' => 'U1',
                'Vendor_No' => 'PERK',
            ]);

            return ['count' => 1, 'optional_fields' => true, 'missing_optional' => [], 'page_size_fallback' => false];
        },
        static function (): void {
        },
        true
    );
    test_assert($emptyArticle['replayed'] === false && $emptyArticleFetches === 1, 'lege artikelstap wordt opnieuw opgehaald');

    $failSteps = [];
    $stockFailed = false;
    try {
        consus_collect_entity_with_checkpoint(
            $failSteps,
            'hvt',
            'voorraad',
            static function (array $row): void {
                unset($row);
            },
            static function (?array &$writer): array {
                unset($writer);
                throw new RuntimeException('cURL error: timeout');
            },
            static function (): void {
            },
            true
        );
    } catch (RuntimeException $stockFail) {
        $stockFailed = str_contains($stockFail->getMessage(), 'cURL error');
    }
    test_assert($stockFailed, 'mislukte voorraad faalt');
    test_assert(empty($failSteps['voorraad']['done']), 'mislukte voorraad is niet af');
    test_assert(!is_file(consus_checkpoint_directory() . '/hvt-voorraad.ndjson'), 'mislukte voorraad laat geen bestand achter');
    $stockTmps = glob(consus_checkpoint_directory() . '/*.tmp.*');
    test_assert($stockTmps === [] || $stockTmps === false, 'tijdelijke tussenstand is opgeruimd');

    $savedResumeGlobals = [];
    foreach (['baseUrl', 'auth_list', 'environment', 'demeter_company_environment_map'] as $globalName) {
        $savedResumeGlobals[$globalName] = $GLOBALS[$globalName] ?? null;
    }
    $GLOBALS['baseUrl'] = 'https://bc.example.test/BC';
    $GLOBALS['auth_list'] = [
        'Test' => ['mode' => 'basic', 'user' => 'svc', 'pass' => 'x'],
    ];
    $GLOBALS['environment'] = 'Test';
    $GLOBALS['demeter_company_environment_map'] = [
        'Koninklijke van Twist' => 'Test',
    ];
    try {
        $resumeChunks = [
            ['from' => '2026-09-01', 'to' => '2026-09-23'],
            ['from' => '2026-09-23', 'to' => '2026-09-25'],
        ];
        $failSecondMonth = true;
        $resumeFetches = 0;
        $resumeFetch = static function (string $url, array $auth, callable $onRow) use (&$resumeFetches, &$failSecondMonth): int {
            unset($auth);
            $resumeFetches++;
            $decoded = rawurldecode($url);
            if (str_contains($decoded, 'Posting_Date ge 2026-09-23')) {
                $onRow([
                    'Item_No' => 'A1',
                    'Quantity' => -4,
                    'Sales_Amount_Actual' => 40,
                    'Posting_Date' => '2026-09-24',
                    'Location_Code' => 'KVT',
                ]);
                if ($failSecondMonth) {
                    throw new RuntimeException('cURL error: timeout');
                }

                return 1;
            }
            test_assert(str_contains($decoded, 'Posting_Date ge 2026-09-01'), 'open maand houdt zijn eigen grens');
            $onRow([
                'Item_No' => 'A1',
                'Quantity' => -10,
                'Sales_Amount_Actual' => 100,
                'Posting_Date' => '2026-09-10',
                'Location_Code' => 'KVT',
            ]);

            return 1;
        };
        $resumeStep = ['months' => []];
        $saveResume = static function () use (&$resumeStep, $windows): void {
            $stored = consus_empty_checkpoint($windows);
            $stored['companies']['kvt'] = [
                'company' => 'Koninklijke van Twist',
                'company_key' => 'kvt',
                'mode' => 'cold',
                'ledger_from' => '2026-09-01',
                'steps' => ['verkoop' => $resumeStep],
                'warnings' => [],
            ];
            consus_write_checkpoint($stored);
        };
        $resumeItems = [];
        $resumeThrew = false;
        try {
            consus_collect_ledger(
                'Koninklijke van Twist',
                'kvt',
                ['Sale'],
                'sales',
                '',
                true,
                $resumeItems,
                $windows,
                null,
                $resumeChunks,
                $resumeFetch,
                $resumeStep,
                $saveResume,
                'verkoop',
                'cold'
            );
        } catch (RuntimeException $resumeError) {
            $resumeThrew = str_contains($resumeError->getMessage(), 'cURL error');
        }
        test_assert($resumeThrew, 'afgebroken maand faalt');
        test_assert($resumeFetches >= 2, 'de afgebroken maand is wel geprobeerd');
        test_assert(isset($resumeStep['months']['2026-09-01']), 'afgeronde maand blijft in de tussenstand');
        test_assert(!isset($resumeStep['months']['2026-09-23']), 'afgebroken maand wordt niet vastgelegd');
        test_assert(
            abs((float) ($resumeItems['kvt|A1']['by_location']['KVT']['sales']['eigen']['m']['qty'] ?? 0) - 10) < 0.0001,
            'half binnengehaalde maand telt niet mee'
        );
        test_assert(is_file(consus_checkpoint_directory() . '/' . consus_checkpoint_basename('kvt', 'verkoop', '2026-09-01')), 'afgeronde maand staat op schijf');
        test_assert(!is_file(consus_checkpoint_directory() . '/' . consus_checkpoint_basename('kvt', 'verkoop', '2026-09-23')), 'afgebroken maand laat geen bestand achter');
        $loadedPartial = consus_load_checkpoint($windows);
        test_assert(isset($loadedPartial['companies']['kvt']['steps']['verkoop']['months']['2026-09-01']), 'manifest onthoudt de afgeronde maand');
        test_assert(!isset($loadedPartial['companies']['kvt']['steps']['verkoop']['months']['2026-09-23']), 'manifest onthoudt de afgebroken maand niet');

        $failSecondMonth = false;
        $resumeFetches = 0;
        $resumedItems = [];
        consus_collect_ledger(
            'Koninklijke van Twist',
            'kvt',
            ['Sale'],
            'sales',
            '',
            true,
            $resumedItems,
            $windows,
            null,
            $resumeChunks,
            $resumeFetch,
            $resumeStep,
            $saveResume,
            'verkoop',
            'cold'
        );
        test_assert($resumeFetches === 1, 'hervatten haalt alleen de open maand op');
        test_assert(
            abs((float) ($resumedItems['kvt|A1']['by_location']['KVT']['sales']['eigen']['m']['qty'] ?? 0) - 14) < 0.0001,
            'hervatte maanden vormen samen het venster'
        );
        test_assert(
            abs((float) ($resumedItems['kvt|A1']['by_location']['KVT']['sales']['eigen']['days']['2026-09-24']['qty'] ?? 0) - 4) < 0.0001,
            'alleen de peildatum blijft als overlapdag bewaard'
        );
        test_assert(isset($resumeStep['months']['2026-09-23']), 'tweede maand is daarna vastgelegd');
        test_assert(!consus_checkpoint_plan_matches(
            ['mode' => 'cold', 'ledger_from' => '2026-09-01'],
            ['mode' => 'warm', 'from' => '2026-09-23']
        ), 'een warm plan hervat geen koude tussenstand');
        $source = (string) file_get_contents(__DIR__ . '/../web/consus_data.php');
        test_assert(preg_match('/if \(\$force\) \{\s+consus_clear_checkpoint\(\);/', $source) === 1, 'force wist de tussenstand');
        $nextDay = consus_period_windows(new DateTimeImmutable('2026-09-25', new DateTimeZone('Europe/Amsterdam')));
        $clearedCheckpoint = consus_load_checkpoint($nextDay);
        test_assert($clearedCheckpoint['companies'] === [], 'andere peildatum wist de tussenstand');
        test_assert(!is_file(consus_checkpoint_manifest_file()), 'vervallen manifest is weg');
    } finally {
        foreach ($savedResumeGlobals as $globalName => $globalValue) {
            $GLOBALS[$globalName] = $globalValue;
        }
    }
} finally {
    consus_clear_checkpoint();
    @unlink($checkpointRoot);
    @unlink($checkpointRoot . '.lock');
    if ($savedCheckpointEnv === false) {
        putenv('CONSUS_SNAPSHOT_FILE');
    } else {
        putenv('CONSUS_SNAPSHOT_FILE=' . $savedCheckpointEnv);
    }
}

echo "OK\n";
