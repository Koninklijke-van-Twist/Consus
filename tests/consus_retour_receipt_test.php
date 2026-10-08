<?php

// #1159: Perkins-factuurnummer en Handling Unit uit ontvangsten; spoed vanaf de geboekte ontvangst.

$tmp = sys_get_temp_dir() . '/consus_retour_receipt_' . getmypid();
@mkdir($tmp, 0777, true);
putenv('CONSUS_RETOUR_FILE=' . $tmp . '/retour.json');
putenv('CONSUS_RETOUR_SETTINGS_FILE=' . $tmp . '/settings.json');
putenv('CONSUS_PREFS_DIR=' . $tmp . '/prefs');
putenv('CONSUS_COMPANIES_FILE=' . $tmp . '/companies.json');

require_once __DIR__ . '/../web/consus_retour_fetch.php';

function receipt_assert(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "ok - {$label}\n";
}

const H = 'Hunter van Twist';

// Echte vorm uit BC (Hunter van Twist, PO52601067 regel 510000, PK-6361774).
$receiptRows = [
    ['LVS_Order_No' => 'PO52601067', 'LVS_Order_Line_No' => 510000, 'No' => 'PK-6361774', 'Quantity' => 0, 'VendorShptNo' => '99999999'],
    ['LVS_Order_No' => 'PO52601067', 'LVS_Order_Line_No' => 510000, 'No' => 'PK-6361774', 'Quantity' => 2, 'VendorShptNo' => '90242629'],
    ['LVS_Order_No' => 'PO52601067', 'LVS_Order_Line_No' => 520000, 'No' => 'PK-6361774', 'Quantity' => 1, 'VendorShptNo' => '90240000'],
];
$headers = [
    'PWR2608256' => ['hu' => '4401996140', 'invoice' => '90242629'],
    'PWR2608271' => ['hu' => '4401996082', 'invoice' => '90242629'],
    'PWR2600001' => ['hu' => '4400000001', 'invoice' => '90240000'],
];
$entries = [
    ['Source_No' => 'PO52601067', 'Source_Line_No' => 510000, 'Item_No' => 'PK-6361774', 'Whse_Document_No' => 'PWR2608271', 'Whse_Document_Type' => 'Ontvangst'],
    ['Source_No' => 'PO52601067', 'Source_Line_No' => 510000, 'Item_No' => 'PK-6361774', 'Whse_Document_No' => 'PWR2608256', 'Whse_Document_Type' => 'Receipt'],
    ['Source_No' => 'PO52601067', 'Source_Line_No' => 510000, 'Item_No' => 'PK-6361774', 'Whse_Document_No' => 'PWR2608256', 'Whse_Document_Type' => 'Receipt'],
    ['Source_No' => 'PO52601067', 'Source_Line_No' => 520000, 'Item_No' => 'PK-6361774', 'Whse_Document_No' => 'PWR2600001', 'Whse_Document_Type' => 'Receipt'],
    ['Source_No' => 'PO52601067', 'Source_Line_No' => 510000, 'Item_No' => 'PK-6361774', 'Whse_Document_No' => 'WPA1', 'Whse_Document_Type' => 'Put-away'],
];
$info = consus_retour_receipt_info($receiptRows, $entries, $headers);
receipt_assert($info["PO52601067\x1f#510000"]['hus'] === ['4401996082', '4401996140'], 'alle dozen van de orderregel, gesorteerd, geen dubbele');
receipt_assert($info["PO52601067\x1f#510000"]['invoices'] === ['90242629'], 'Perkins-factuurnummer per orderregel, regels met aantal 0 tellen niet');
receipt_assert($info["PO52601067\x1f#520000"]['hus'] === ['4400000001'], 'zelfde artikel twee keer op de PO: exacte orderregel apart');
receipt_assert(count($info["PO52601067\x1fPK-6361774"]['hus']) === 3, 'PO+artikel bundelt alle regels (factuurregels hebben geen orderregelnummer)');

$lines = consus_retour_apply_receipt_info([
    ['invoice' => 'PI52601895', 'vendor_invoice' => '90242629', 'order_no' => 'PO52601067', 'item' => 'PK-6361774'],
    ['invoice' => 'PWR2609188', 'source' => 'receipt', 'vendor_invoice' => '', 'order_no' => 'PO52601282', 'order_line' => 10000, 'item' => 'PK-X'],
    ['invoice' => 'PI12608850', 'vendor_invoice' => '90230001', 'order_no' => 'PO12601893', 'item' => 'PK-Y'],
], $info);
receipt_assert($lines[0]['perkins_invoice'] === '90242629' && count($lines[0]['handling_units']) === 3, 'factuurregel krijgt factuurnummer en dozen');
receipt_assert($lines[1]['perkins_invoice'] === '' && $lines[1]['handling_units'] === [], 'spoed zonder factuur: leeg tot bekend');
receipt_assert($lines[2]['perkins_invoice'] === '90230001' && $lines[2]['handling_units'] === [], 'KvT zonder ontvangstgegevens: terugval op factuur, geen doos');

// Ontvangen, nog niet gefactureerd.
$open = consus_retour_receipt_lines([
    ['Document_No' => 'PWR2609188', 'Type' => 'Artikel', 'No' => 'PK-X', 'Qty_Rcd_Not_Invoiced' => 2, 'LVS_Amt_Rcd_Not_Invoiced' => 50, 'LVS_Order_No' => 'PO52601282', 'LVS_Order_Line_No' => 10000, 'Buy_from_Vendor_No' => '90101', 'Shortcut_Dimension_1_Code' => '15'],
    ['Document_No' => 'PWR1', 'Type' => 'Item', 'No' => 'PK-Z', 'Qty_Rcd_Not_Invoiced' => 0, 'LVS_Order_No' => 'PO1', 'Buy_from_Vendor_No' => '90101'],
    ['Document_No' => 'PWR2', 'Type' => 'Item', 'No' => 'PK-Z', 'Qty_Rcd_Not_Invoiced' => 1, 'LVS_Order_No' => 'PO1', 'Buy_from_Vendor_No' => '90101'],
], ['PWR2609188' => '2026-10-01']);
receipt_assert(count($open) === 1 && $open[0]['source'] === 'receipt' && $open[0]['unit_cost'] === 25.0 && $open[0]['document_date'] === '2026-10-01', 'ontvangstregel met prijs en ontvangstdatum; zonder datum of aantal valt weg');

// Volledige build met nep-BC: spoed vanaf ontvangst, voorraad niet.
$settings = consus_retour_normalize_settings([]);
$settings['rules'][H]['15'] = [
    ['id' => 'a', 'vendor' => '90101', 'type' => '57401', 'window' => 30, 'min_value' => 1.0],
];
$calls = [];
$fake = static function (string $company, string $set, array $required, array $optional, string $filter, callable $onRow) use (&$calls): void {
    $calls[] = $set;
    $rows = match ($set) {
        'GeboekteInkoopfacturen' => [['No' => 'PI52601895', 'Document_Date' => '2026-09-30', 'Buy_from_Vendor_No' => '90101', 'Vendor_Invoice_No' => '90242629']],
        'GeboekteInkoopfactuurRegels' => [['Document_No' => 'PI52601895', 'Line_No' => 10000, 'Type' => 'Item', 'No' => 'PK-6361774', 'Quantity' => 2, 'Direct_Unit_Cost' => 40, 'Order_No' => 'PO52601067', 'Shortcut_Dimension_1_Code' => '15']],
        'PurchaseReceiptLines' => str_contains($filter, 'Qty_Rcd_Not_Invoiced')
            ? [
                ['Document_No' => 'PWR2609188', 'Type' => 'Item', 'No' => 'PK-X', 'Qty_Rcd_Not_Invoiced' => 1, 'LVS_Amt_Rcd_Not_Invoiced' => 60, 'LVS_Order_No' => 'PO52601282', 'LVS_Order_Line_No' => 10000, 'Buy_from_Vendor_No' => '90101', 'Shortcut_Dimension_1_Code' => '15'],
                ['Document_No' => 'PWR2609999', 'Type' => 'Item', 'No' => 'PK-V', 'Qty_Rcd_Not_Invoiced' => 1, 'LVS_Amt_Rcd_Not_Invoiced' => 60, 'LVS_Order_No' => 'PO52609999', 'LVS_Order_Line_No' => 10000, 'Buy_from_Vendor_No' => '90101', 'Shortcut_Dimension_1_Code' => '15'],
            ]
            : $GLOBALS['receiptRows'],
        'PostedPurchaseReceipt' => [['No' => 'PWR2609188', 'Posting_Date' => '2026-10-01'], ['No' => 'PWR2609999', 'Posting_Date' => '2026-10-01']],
        'AppPurchaseOrder' => [
            ['No' => 'PO52601067', 'KVT_Export_Status_Perkins_EGT' => 'Ja', 'KVT_Export_Status_Perkins_CSV' => 'Nee'],
            ['No' => 'PO52601282', 'KVT_Export_Status_Perkins_EGT' => true, 'KVT_Export_Status_Perkins_CSV' => false],
            ['No' => 'PO52609999', 'KVT_Export_Status_Perkins_EGT' => false, 'KVT_Export_Status_Perkins_CSV' => true],
        ],
        'PostedWhseReceipt' => array_map(static fn ($no, $h) => ['No' => $no, 'KVT_Handling_Unit' => $h['hu'], 'Vendor_Shipment_No' => $h['invoice']], array_keys($GLOBALS['headers']), $GLOBALS['headers']),
        'Magazijnposten' => $GLOBALS['entries'],
        'AppItemCard' => [],
        'ItemLedgerEntries' => [['Item_No' => 'PK-6361774', 'Remaining_Quantity' => 5, 'Location_Code' => 'HVT'], ['Item_No' => 'PK-X', 'Remaining_Quantity' => 1, 'Location_Code' => 'HVT']],
        default => [],
    };
    foreach ($rows as $row) {
        $onRow($row);
    }
};
$data = consus_retour_build(H, $settings, $fake, '2026-10-08');
$bySource = [];
foreach ($data['lines'] as $l) {
    $bySource[$l['invoice']] = $l;
}
receipt_assert(isset($bySource['PWR2609188']) && !isset($bySource['PWR2609999']), 'spoed ontvangst op de lijst, voorraad-ontvangst niet');
receipt_assert($bySource['PI52601895']['perkins_invoice'] === '90242629' && in_array('4401996140', $bySource['PI52601895']['handling_units'], true) && in_array('4401996082', $bySource['PI52601895']['handling_units'], true), 'build: factuurnummer en beide dozen');
$result = consus_retour_candidates($data, $settings, '2026-10-08', H, '15');
$rows = [];
foreach ($result['rows'] as $r) {
    $rows[$r['invoice']] = $r;
}
receipt_assert(isset($rows['PWR2609188']) && consus_retour_is_receipt_row($rows['PWR2609188']) && $rows['PWR2609188']['perkins_invoice'] === '' && $rows['PWR2609188']['handling_unit'] === '', 'kandidaat vanaf ontvangst, factuurnummer/doos leeg');
receipt_assert(str_contains($rows['PI52601895']['handling_unit'], '4401996082, 4401996140'), 'Handling Units komma-gescheiden');
receipt_assert(str_contains(consus_retour_reason($rows['PWR2609188']), 'nog niet gefactureerd'), 'uitleg bij ontvangstregel');
$sheet = consus_retour_export_sheet($result);
receipt_assert($sheet['rows'][0][2] === 'Perkins-factuurnummer' && $sheet['rows'][0][3] === 'Handling Unit', 'exportkolommen');

// KvT: geen dozen -> geen Magazijnposten-calls.
$calls = [];
$kvtFake = static function (string $company, string $set, array $required, array $optional, string $filter, callable $onRow) use (&$calls, $fake): void {
    if (in_array($set, ['PostedWhseReceipt', 'Magazijnposten', 'PurchaseReceiptLines'], true)) {
        $calls[] = $set;
        return;
    }
    $fake($company, $set, $required, $optional, $filter, $onRow);
};
$kvtLines = consus_retour_enrich_receipts($kvtFake, 'Koninklijke van Twist', "Buy_from_Vendor_No eq '90101'", [['invoice' => 'PI1', 'vendor_invoice' => '9023', 'order_no' => 'PO1', 'item' => 'A']], '2026-04-01', microtime(true));
receipt_assert(!in_array('Magazijnposten', $calls, true) && $kvtLines[0][0]['perkins_invoice'] === '9023' && $kvtLines[0][0]['handling_units'] === [] && $kvtLines[1] === '', 'KvT: leeg, terugval, geen Magazijnposten');

// Tijdslimiet: over budget -> stoppen met melding.
$limited = consus_retour_enrich_receipts($fake, H, "Buy_from_Vendor_No eq '90101'", [['invoice' => 'PI1', 'vendor_invoice' => '', 'order_no' => 'PO52601067', 'item' => 'PK-6361774']], '2026-04-01', microtime(true) - 10, 5);
receipt_assert(str_contains($limited[1], 'tijdslimiet') && $limited[0][0]['handling_units'] === [], 'tijdslimiet begrenst de lookups');

// Fout bij ontvangsten breekt de build niet.
$broken = static function (string $company, string $set, array $required, array $optional, string $filter, callable $onRow) use ($fake): void {
    if (in_array($set, ['PostedWhseReceipt', 'PurchaseReceiptLines'], true)) {
        throw new RuntimeException('HTTP 500');
    }
    $fake($company, $set, $required, $optional, $filter, $onRow);
};
$data = consus_retour_build(H, $settings, $broken, '2026-10-08');
receipt_assert(count($data['lines']) === 1 && $data['lines'][0]['perkins_invoice'] === '90242629' && count($data['warnings']) >= 2, 'fout in ontvangsten: factuurregels blijven, melding');

echo "consus_retour_receipt_test OK\n";
