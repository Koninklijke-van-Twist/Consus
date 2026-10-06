<?php

require_once __DIR__ . '/../web/consus_page.php';

function page_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function page_snapshot(int $count): array
{
    $windows = consus_period_windows(new DateTimeImmutable('2026-10-06', new DateTimeZone('Europe/Amsterdam')));
    $snapshot = consus_empty_snapshot();
    $snapshot['generated_at'] = '2026-10-06T07:45:00+00:00';
    $snapshot['as_of'] = $windows['as_of'];
    $snapshot['windows'] = $windows;
    $snapshot['version'] = CONSUS_SNAPSHOT_VERSION;
    $snapshot['articles'] = [];
    $snapshot['item_usage'] = [];
    for ($index = 1; $index <= $count; $index++) {
        $item = sprintf('P-%04d', $index);
        $snapshot['articles'][] = [
            'company_key' => 'kvt',
            'item_no' => $item,
            'description' => 'Test ' . $item,
            'cost_center' => '5',
            'location' => 'KVT',
            'inventory' => $index === 1 ? 1 : 100,
            'safety_stock' => 10,
            'reorder_point' => 0,
            'consumption' => ['months' => [], 'days' => [], 'm' => 0, 'q' => 0, 'y' => 0],
        ];
        if ($index === 1) {
            $snapshot['item_usage'][] = [
                'company_key' => 'kvt',
                'item_no' => $item,
                'cost_center' => '5',
                'months' => [
                    '2023-01' => ['internal' => 100, 'customers' => []],
                    '2024-01' => ['internal' => 100, 'customers' => []],
                    '2025-01' => ['internal' => 100, 'customers' => []],
                    '2026-01' => ['internal' => 10, 'customers' => []],
                ],
                'days' => [],
            ];
        }
    }

    return $snapshot;
}

function page_html(array $snapshot, array $prefs): string
{
    ob_start();
    consus_page_render($snapshot, $prefs, [], 'tok');

    return (string) ob_get_clean();
}

function page_payload(string $html): array
{
    $marker = '<script id="year-data" type="application/json">';
    $start = strpos($html, $marker);
    page_assert($start !== false, 'jaardata staat als JSON in de pagina');
    $start += strlen($marker);
    $end = strpos($html, '</script>', $start);
    page_assert($end !== false, 'jaardata wordt afgesloten');
    $decoded = json_decode(substr($html, $start, $end - $start), true);
    page_assert(is_array($decoded), 'jaardata is geldige JSON');

    return $decoded;
}

$small = page_html(page_snapshot(25), consus_empty_prefs());
page_assert(substr_count($small, '<tbody') === 1, 'er is één tabelbody');
page_assert(substr_count($small, 'id="year-rows"') === 1, 'de body is leeg tot de browser de pagina tekent');
page_assert(substr_count($small, '<table') === 1, 'niet een gevulde tabel per jaar');
page_assert(strpos($small, 'role="tabpanel"') === false, 'jaartabs hebben geen eigen tabel in de DOM');
page_assert(strpos($small, '<td') === false, 'er staan geen datarijen in de HTML');
page_assert(strpos($small, 'Regels per pagina') !== false, 'dropdown regels per pagina');
page_assert(strpos($small, 'value="20" selected') !== false, 'standaard is 20');
page_assert(strpos($small, 'Vorige') !== false && strpos($small, 'Volgende') !== false, 'vorige en volgende staan in de paginering');
page_assert(strpos($small, 'van ') !== false, 'bereik x–y van N');
$payload = page_payload($small);
page_assert(count($payload['articles']) === 25, 'JSON bevat alle artikelen, niet alleen de pagina');
page_assert($payload['pageSize'] === 20, 'payload gebruikt de standaardpagina');
page_assert($payload['articles'][0]['item'] === 'P-0001' && $payload['articles'][0]['low'] === true, 'gele markering zit in de data van elke pagina');
page_assert(array_map('strval', array_keys($payload['articles'][0]['values'])) === ['2026', '2025', '2024', '2023'], 'elk artikel heeft de vier jaren');
page_assert(count($payload['articles'][0]['values']['2026']) === 19, 'getallen dekken de kolommen na het artikelnummer');
page_assert(isset($payload['articles'][0]['modal']['calculation']), 'modaldata hoort bij het artikel');

$sized = page_html(page_snapshot(25), ['customers' => [], 'items' => [], 'page_size' => 100]);
page_assert(strpos($sized, 'value="100" selected') !== false, 'opgeslagen paginagrootte is geselecteerd');
page_assert(page_payload($sized)['pageSize'] === 100, 'payload volgt de voorkeur');

$excluded = page_html(page_snapshot(25), ['customers' => [], 'items' => ['P-0002'], 'page_size' => 20]);
$excludedItems = array_column(page_payload($excluded)['articles'], 'item');
page_assert(!in_array('P-0002', $excludedItems, true) && count($excludedItems) === 24, 'uitsluiting gaat vóór de paginering');

$started = microtime(true);
$largeHtml = page_html(page_snapshot(2500), consus_empty_prefs());
$elapsed = microtime(true) - $started;
$large = page_payload($largeHtml);
page_assert(count($large['articles']) === 2500, '2500 artikelen zitten in de JSON');
page_assert(strpos($largeHtml, '<td') === false, '2500 artikelen worden niet als rijen in de DOM gezet');
page_assert(substr_count($largeHtml, '<tbody') === 1, 'ook bij 2500 artikelen één tabel');
page_assert($elapsed < 8, '2500 artikelen renderen binnen 8 seconden, duurde ' . round($elapsed, 2) . 's');
page_assert(memory_get_peak_usage(true) < 256 * 1024 * 1024, 'piekgeheugen blijft onder 256M');

echo "OK\n";
