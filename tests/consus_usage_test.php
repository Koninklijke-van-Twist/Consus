<?php

require_once __DIR__ . '/../web/consus_usage.php';

function usage_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$zone = new DateTimeZone('Europe/Amsterdam');
$asOf = new DateTimeImmutable('2026-10-06 00:00:00', $zone);
$windows = consus_period_windows($asOf);
usage_assert($windows['history_start'] === '2023-01-01', 'venster begint op 1 januari 2023');
usage_assert(consus_history_years('2026-10-06') === [2026, 2025, 2024, 2023], 'tabs: huidig jaar en drie voorgaande');
usage_assert(consus_average_years($windows) === [2023, 2024, 2025], 'gemiddelde gebruikt alleen volledige voorgaande jaren');
usage_assert(consus_nl_year_list([2023, 2024, 2025]) === '2023, 2024 en 2025', 'jaartalopsomming');
usage_assert(consus_nl_year_list([]) === 'geen historie', 'lege jaartallen');

$january = consus_remaining_year_fraction('2026-01-01');
usage_assert($january['days_in_year'] === 365 && $january['remaining_days'] === 365 && abs($january['fraction'] - 1) < 0.0000001, '1 januari telt het hele jaar');
$december = consus_remaining_year_fraction('2026-12-31');
usage_assert($december['remaining_days'] === 1 && abs($december['fraction'] - (1 / 365)) < 0.0000001, '31 december telt één dag');
$leap = consus_remaining_year_fraction('2024-12-31');
usage_assert($leap['days_in_year'] === 366 && $leap['remaining_days'] === 1, '31 december in een schrikkeljaar telt één dag');
$october = consus_remaining_year_fraction('2026-10-06');
usage_assert($october['remaining_days'] === 87 && $october['days_in_year'] === 365, '6 oktober 2026 heeft 87 dagen tot en met 31 december');

$labels = consus_usage_column_labels();
usage_assert($labels === [
    'Artikelnummer',
    'Veiligheidsvoorraad',
    'Totaal verbruik Jaar',
    'Januari',
    'Februari',
    'Maart',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Augustus',
    'September',
    'Oktober',
    'November',
    'December',
    'Kwartaal 1',
    'Kwartaal 2',
    'Kwartaal 3',
    'Kwartaal 4',
    'Intern Verbruik',
], 'kolomvolgorde');

$items = [];
consus_apply_ledger_row($items, [
    'Item_No' => 'ART-1',
    'Quantity' => -10,
    'Posting_Date' => '2026-01-15',
    'Location_Code' => 'KVT',
    'Source_No' => 'C1',
    'Source_Type' => 'Customer',
    'Sales_Amount_Actual' => 100,
], 'kvt', 'sales', $windows);
consus_apply_ledger_row($items, [
    'Item_No' => 'ART-1',
    'Quantity' => -4,
    'Posting_Date' => '2026-01-20',
    'Location_Code' => 'KVT',
    'Source_No' => 'C2',
    'Source_Type' => 'Customer',
], 'kvt', 'sales', $windows);
consus_apply_ledger_row($items, [
    'Item_No' => 'ART-1',
    'Quantity' => -2,
    'Posting_Date' => '2026-01-21',
    'Location_Code' => 'KVT',
    'Source_No' => 'V9',
    'Source_Type' => 'Vendor',
], 'kvt', 'sales', $windows);
consus_apply_ledger_row($items, [
    'Item_No' => 'ART-1',
    'Quantity' => -3,
    'Posting_Date' => '2026-02-02',
    'Location_Code' => 'KVT',
    'Document_No' => 'WO100',
], 'kvt', 'consumption', $windows);
consus_apply_ledger_row($items, [
    'Item_No' => 'ART-1',
    'Quantity' => 1,
    'Posting_Date' => '2026-03-03',
    'Location_Code' => 'KVT',
    'Source_No' => 'C1',
    'Source_Type' => 'Customer',
], 'kvt', 'sales', $windows);
consus_apply_ledger_row($items, [
    'Item_No' => 'ART-2',
    'Quantity' => -8,
    'Posting_Date' => '2025-06-01',
    'Location_Code' => 'KVT',
    'Source_No' => 'C1',
    'Source_Type' => 'Customer',
], 'kvt', 'sales', $windows);
consus_apply_stock_row($items, [
    'Item_No' => 'ART-1',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'KVT',
    'Inventory' => 5,
    'Safety_Stock_Quantity' => 20,
], 'Koninklijke van Twist');
$items['kvt|ART-1']['cost_center'] = '5';
$items['kvt|ART-1']['description'] = 'Filter';
consus_apply_stock_row($items, [
    'Item_No' => 'ART-3',
    'Company_Name' => 'Koninklijke van Twist',
    'Location_Code' => 'KVT',
    'Inventory' => 40,
    'Safety_Stock_Quantity' => 4,
], 'Koninklijke van Twist');
$items['kvt|ART-3']['cost_center'] = '20';

$rolled = consus_rollup_items($items, $windows);
$usageByItem = [];
foreach ($rolled['item_usage'] as $record) {
    $usageByItem[$record['item_no']] = $record;
}
usage_assert(isset($usageByItem['ART-1']['months']['2026-01']['customers']['C1']), 'klant C1 staat in januari');
usage_assert(!isset($usageByItem['ART-1']['months']['2026-01']['customers']['V9']), 'Vendor is geen klant');
usage_assert(abs((float) $usageByItem['ART-1']['months']['2026-01']['customers'][''] - 2) < 0.0001, 'verkoop zonder klant blijft meetellen');
usage_assert(abs((float) $usageByItem['ART-1']['months']['2026-02']['internal'] - 3) < 0.0001, 'intern verbruik staat apart');

$snapshot = consus_empty_snapshot();
$snapshot['generated_at'] = '2026-10-06T07:45:00+00:00';
$snapshot['as_of'] = $windows['as_of'];
$snapshot['windows'] = $windows;
$snapshot['rows'] = $rolled['rows'];
$snapshot['articles'] = $rolled['articles'];
$snapshot['item_usage'] = $rolled['item_usage'];
$snapshot['version'] = CONSUS_SNAPSHOT_VERSION;

$facts = consus_usage_facts($snapshot, 'kvt', '', []);
$byItem = [];
foreach ($facts as $fact) {
    $byItem[$fact['item_no']] = $fact;
}
usage_assert(isset($byItem['ART-1'], $byItem['ART-2'], $byItem['ART-3']), 'verkoop, intern en voorraad zonder verbruik komen in de tabel');
usage_assert(abs((float) $byItem['ART-1']['safety_stock'] - 20) < 0.0001, 'veiligheidsvoorraad');
usage_assert(abs((float) $byItem['ART-1']['inventory'] - 5) < 0.0001, 'huidige voorraad');

$row = consus_usage_year_row($byItem['ART-1'], 2026, $windows, []);
usage_assert(abs($row['months'][1] - 16) < 0.0001, 'januari is verkoop 16');
usage_assert(abs($row['months'][2] - 3) < 0.0001, 'februari is intern 3');
usage_assert(abs($row['months'][3] - (-1)) < 0.0001, 'maart trekt de retour af');
usage_assert(abs($row['quarters'][1] - 18) < 0.0001, 'kwartaal 1 is de som van de maanden');
usage_assert(abs($row['total'] - 18) < 0.0001, 'totaal is de som van de maanden');
usage_assert(abs($row['internal'] - 3) < 0.0001, 'intern verbruik is het jaartotaal');
$quarterSum = $row['quarters'][1] + $row['quarters'][2] + $row['quarters'][3] + $row['quarters'][4];
$monthSum = 0.0;
foreach ($row['months'] as $qty) {
    $monthSum += $qty;
}
usage_assert(abs($row['total'] - $monthSum) < 0.0001 && abs($row['total'] - $quarterSum) < 0.0001, 'totaal = som maanden = som kwartalen');
usage_assert(consus_usage_row_values($row)[0] === 'ART-1', 'eerste kolom is het artikelnummer');
usage_assert(count(consus_usage_row_values($row)) === count($labels), 'elke kolom heeft een waarde');

$excluded = consus_usage_year_row($byItem['ART-1'], 2026, $windows, ['c1']);
usage_assert(abs($excluded['months'][1] - 6) < 0.0001, 'uitgesloten klant C1 verdwijnt uit januari');
usage_assert(abs($excluded['months'][3] - 0) < 0.0001, 'retour van C1 verdwijnt ook');
usage_assert(abs($excluded['internal'] - 3) < 0.0001, 'intern verbruik blijft bij klantuitsluiting');
usage_assert(abs($excluded['total'] - ($excluded['months'][1] + $excluded['months'][2])) < 0.0001, 'totaal volgt de gefilterde maanden');

$hidden = consus_usage_facts($snapshot, 'kvt', '', ['art-1']);
$hiddenNos = array_column($hidden, 'item_no');
usage_assert(!in_array('ART-1', $hiddenNos, true) && in_array('ART-3', $hiddenNos, true), 'uitgesloten artikel verdwijnt');
$department = consus_usage_facts($snapshot, 'kvt', '5', []);
usage_assert(array_column($department, 'item_no') === ['ART-1'], 'afdeling beperkt de tabel');

$fullYears = [
    2023 => 365.0,
    2024 => 365.0,
    2025 => 365.0,
];
$historyUsage = ['months' => []];
foreach ($fullYears as $year => $total) {
    $historyUsage['months'][$year . '-06'] = [
        'internal' => 0.0,
        'customers' => ['C9' => $total],
    ];
}
$historyUsage['months']['2026-01'] = [
    'internal' => 0.0,
    'customers' => ['C9' => 40.0],
];
$outlook = consus_usage_outlook(5.0, $historyUsage, $windows, []);
usage_assert($outlook['average_years'] === [2023, 2024, 2025], 'gemiddelde noemt de jaren');
usage_assert(abs((float) $outlook['average'] - 365) < 0.0001, 'gemiddelde van drie volle jaren');
usage_assert(abs((float) $outlook['ytd'] - 40) < 0.0001, 'dit jaar al verbruikt');
usage_assert(abs((float) $outlook['expected'] - 87) < 0.0001, 'verwacht restant is gemiddelde maal 87/365');
usage_assert($outlook['low_stock'] === true, 'voorraad 5 is lager dan restant 87');

$equal = consus_usage_outlook(87.0, $historyUsage, $windows, []);
usage_assert($equal['low_stock'] === false, 'gelijke voorraad is niet te weinig');
$withoutCustomer = consus_usage_outlook(5.0, $historyUsage, $windows, ['C9']);
usage_assert(abs((float) $withoutCustomer['average']) < 0.0001, 'uitgesloten klant haalt het gemiddelde leeg');
usage_assert($withoutCustomer['low_stock'] === false, 'zonder verbruik is er geen tekort');

$newYear = consus_usage_outlook(1.0, $historyUsage, [
    'as_of' => '2026-01-01',
    'history_start' => '2023-01-01',
], []);
usage_assert(abs((float) $newYear['fraction'] - 1) < 0.0000001 || abs((float) $newYear['expected'] - 365) < 0.0001, 'op 1 januari is het restant het hele gemiddelde');
usage_assert($newYear['low_stock'] === true, '1 januari met voorraad 1 is te weinig');

$yearEnd = consus_usage_outlook(0.0, $historyUsage, [
    'as_of' => '2026-12-31',
    'history_start' => '2023-01-01',
], []);
usage_assert(abs((float) $yearEnd['expected'] - 1) < 0.0001, 'op 31 december blijft één dag van het gemiddelde');
usage_assert($yearEnd['low_stock'] === true, 'voorraad 0 is lager dan die ene dag');
$covered = consus_usage_outlook(1.0, $historyUsage, [
    'as_of' => '2026-12-31',
    'history_start' => '2023-01-01',
], []);
usage_assert($covered['low_stock'] === false, 'voorraad gelijk aan de laatste dag is genoeg');

$noHistory = consus_usage_outlook(0.0, ['months' => []], [
    'as_of' => '2026-10-06',
    'history_start' => '2026-10-06',
], []);
usage_assert($noHistory['average'] === null && $noHistory['expected'] === null, 'geen volledig jaar geeft geen gemiddelde');
usage_assert($noHistory['average_label'] === 'geen historie', 'label bij geen historie');
usage_assert($noHistory['low_stock'] === false, 'zonder historie wordt een artikel niet geel');

$previous = [[
    'company_key' => 'kvt',
    'item_no' => 'ART-1',
    'cost_center' => '5',
    'months' => [
        '2026-09' => ['internal' => 1.0, 'customers' => ['C1' => 9.0]],
        '2026-08' => ['internal' => 4.0, 'customers' => []],
    ],
    'days' => [
        '2026-09-23' => ['internal' => 1.0, 'customers' => ['C1' => 5.0]],
    ],
]];
$fresh = [[
    'company_key' => 'kvt',
    'item_no' => 'ART-1',
    'cost_center' => '5',
    'months' => [
        '2026-09' => ['internal' => 2.0, 'customers' => ['C1' => 6.0]],
    ],
    'days' => [
        '2026-09-24' => ['internal' => 2.0, 'customers' => ['C1' => 6.0]],
    ],
]];
$merged = consus_merge_warm_item_usage($previous, $fresh, $windows, '2026-09-23', '2026-09-23');
usage_assert(count($merged) === 1, 'warme merge houdt het artikel');
usage_assert(abs((float) $merged[0]['months']['2026-09']['customers']['C1'] - 10) < 0.0001, 'overlapdag wordt vervangen: 9 - 5 + 6');
usage_assert(abs((float) $merged[0]['months']['2026-09']['internal'] - 2) < 0.0001, 'intern op de overlapdag wordt vervangen: 1 - 1 + 2');
usage_assert(abs((float) $merged[0]['months']['2026-08']['internal'] - 4) < 0.0001, 'oudere maand blijft staan');

usage_assert(
    consus_format_dutch_datetime('2026-10-06T07:45:00+00:00') === '6 oktober 2026, 09:45',
    'tijdstip in Europe/Amsterdam, leesbaar Nederlands'
);

$customerNo = consus_ledger_customer_no(['Source_No' => 'C1', 'Source_Type' => 'Customer']);
usage_assert($customerNo === 'C1', 'Source_Type Customer levert het klantnummer');
usage_assert(consus_ledger_customer_no(['Source_No' => 'V9', 'Source_Type' => 'Vendor']) === '', 'Vendor telt niet als klant');
usage_assert(consus_ledger_customer_no(['Source_No' => 'C1']) === 'C1', 'ontbrekend Source_Type valt terug op het nummer');

echo "OK\n";
