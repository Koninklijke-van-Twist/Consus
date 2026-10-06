<?php

/**
 * Stub-snapshot voor de lokale preview. Geen Business Central.
 *
 * @return array<string, mixed>
 */
function preview_snapshot(): array
{
    $zone = new DateTimeZone('Europe/Amsterdam');
    $windows = consus_period_windows(new DateTimeImmutable('2026-10-06 00:00:00', $zone));
    $snapshot = consus_empty_snapshot();
    $snapshot['generated_at'] = '2026-10-06T07:45:00+00:00';
    $snapshot['as_of'] = $windows['as_of'];
    $snapshot['windows'] = $windows;
    $snapshot['version'] = CONSUS_SNAPSHOT_VERSION;
    $snapshot['departments'] = [
        ['company_key' => 'kvt', 'code' => '5', 'name' => 'Werkplaats', 'label' => '5 - Werkplaats'],
        ['company_key' => 'kvt', 'code' => '20', 'name' => 'Inkoop', 'label' => '20 - Inkoop'],
        ['company_key' => 'hvt', 'code' => '5', 'name' => 'Werkplaats', 'label' => '5 - Werkplaats'],
    ];
    $snapshot['rows'] = [
        ['company_key' => 'kvt', 'cost_center' => '5', 'vendor_no' => 'PERK', 'vendor_name' => 'Perkins', 'location' => 'KVT'],
        ['company_key' => 'kvt', 'cost_center' => '20', 'vendor_no' => 'PERK', 'vendor_name' => 'Perkins', 'location' => 'KVT'],
        ['company_key' => 'hvt', 'cost_center' => '5', 'vendor_no' => '', 'vendor_name' => 'Geen leverancier', 'location' => 'HVT'],
    ];
    $snapshot['customers'] = [
        ['company_key' => 'kvt', 'no' => '10001', 'name' => 'Acme Pompen'],
        ['company_key' => 'kvt', 'no' => '10002', 'name' => 'Noord Kaas'],
        ['company_key' => 'kvt', 'no' => '10003', 'name' => 'Scheepswerf De Lek'],
        ['company_key' => 'hvt', 'no' => '20001', 'name' => 'HVT Service'],
    ];

    $articles = [
        ['kvt', 'FLT-100', 'Filterpatroon 10 inch', '5', 5, 20],
        ['kvt', 'PMP-220', 'Pompwaaier RVS', '5', 80, 12],
        ['kvt', 'INK-010', 'Inkoopset pakkingen', '20', 30, 6],
        ['hvt', 'FLT-100', 'Filterpatroon 10 inch', '5', 2, 8],
    ];
    foreach ($articles as $article) {
        $snapshot['articles'][] = [
            'company_key' => $article[0],
            'company_name' => $article[0] === 'kvt' ? 'Koninklijke van Twist' : 'Hunter van Twist',
            'item_no' => $article[1],
            'description' => $article[2],
            'vendor_no' => 'PERK',
            'vendor_name' => 'Perkins',
            'cost_center' => $article[3],
            'location' => strtoupper($article[0]),
            'inventory' => $article[4],
            'safety_stock' => $article[5],
            'reorder_point' => 0,
            'consumption' => ['months' => [], 'days' => [], 'm' => 0, 'q' => 0, 'y' => 0],
        ];
    }

    $patterns = [
        'kvt|FLT-100' => [
            'customers' => ['10001' => 24, '10002' => 6, '10003' => 4],
            'internal' => 2,
        ],
        'kvt|PMP-220' => [
            'customers' => ['10002' => 1],
            'internal' => 0,
        ],
        'kvt|INK-010' => [
            'customers' => ['10003' => 3],
            'internal' => 1,
        ],
        'hvt|FLT-100' => [
            'customers' => ['20001' => 8],
            'internal' => 1,
        ],
    ];
    foreach ($patterns as $key => $pattern) {
        [$company, $item] = explode('|', $key, 2);
        $months = [];
        foreach ([2023, 2024, 2025, 2026] as $year) {
            $scale = $year === 2026 ? 0.45 : ($year === 2025 ? 1.0 : ($year === 2024 ? 0.9 : 0.8));
            for ($month = 1; $month <= 12; $month++) {
                if ($year === 2026 && $month > 10) {
                    continue;
                }
                $factor = $scale * (0.6 + ($month % 5) * 0.1);
                if ($year === 2026 && $month === 10) {
                    $factor *= 0.2;
                }
                $customers = [];
                foreach ($pattern['customers'] as $number => $base) {
                    $customers[$number] = round($base * $factor, 1);
                }
                $months[sprintf('%04d-%02d', $year, $month)] = [
                    'internal' => round($pattern['internal'] * $factor, 1),
                    'customers' => $customers,
                ];
            }
        }
        $snapshot['item_usage'][] = [
            'company_key' => $company,
            'item_no' => $item,
            'cost_center' => $item === 'INK-010' ? '20' : '5',
            'months' => $months,
            'days' => [],
        ];
    }

    return $snapshot;
}
