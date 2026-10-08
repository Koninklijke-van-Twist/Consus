<?php

$pageTmp = sys_get_temp_dir() . '/consus-page-test-' . getmypid();
@mkdir($pageTmp, 0700, true);
putenv('CONSUS_COMPANY_CATALOG_FILE=' . $pageTmp . '/companies.json');
putenv('CONSUS_RETOUR_SETTINGS_FILE=' . $pageTmp . '/retour_settings.json');
putenv('CONSUS_RETOUR_FILE=' . $pageTmp . '/consus_retour.json');
putenv('CONSUS_DEPARTMENT_CATALOG_FILE=' . $pageTmp . '/departments.json');
// Geen live BC in tests: KVT Germany krijgt zijn eigen lijst via deze fetcher.
$GLOBALS['consus_department_catalog_fetcher'] = static function (string $company): array {
    if ($company !== 'KVT Germany GmbH') {
        throw new RuntimeException('onverwachte live fetch voor ' . $company);
    }

    return [
        ['code' => '10', 'name' => 'Vertrieb', 'label' => '10 - Vertrieb'],
        ['code' => '15', 'name' => 'Lager Deutschland', 'label' => '15 - Lager Deutschland'],
    ];
};

require_once __DIR__ . '/../web/consus_page.php';

/**
 * Stopt de test met een melding als de voorwaarde niet klopt.
 */
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

/**
 * Rendert de pagina en geeft de HTML terug.
 *
 * @param array<string, mixed> $snapshot
 * @param array<string, mixed> $prefs
 */
function page_html(array $snapshot, array $prefs): string
{
    ob_start();
    consus_page_render($snapshot, $prefs, [], 'tok');

    return (string) ob_get_clean();
}

/**
 * Haalt de jaardata-JSON uit de HTML.
 *
 * @return array<string, mixed>
 */
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

// Bedrijvenlijst zoals Calculus: alle environments, geen test/FAT, label = weergavenaam.
page_assert(is_file($pageTmp . '/companies.json'), 'mislukte discovery: terugval bewaard, over een uur opnieuw');
@unlink($pageTmp . '/companies.json');
unset($GLOBALS['consus_company_catalog_memo']);
$catalog = consus_company_catalog(true, static fn (): array => [
    'Koninklijke van Twist' => 'kvtmdlive_aad',
    'Hunter van Twist' => 'kvtmdlive_aad',
    'KVT Germany GmbH' => 'kvtgermanylive_aad',
    'Koninklijke van Twist FAT' => 'kvtfat_aad',
    'Testbedrijf' => 'kvtfat2_aad',
]);
page_assert(array_column($catalog, 'name') === ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Germany GmbH'], 'drie live bedrijven, FAT weg');
page_assert(array_column($catalog, 'label') === ['Hunter & van Twist', 'Koninklijke Van Twist', 'KVT Germany GmbH'], 'weergavenamen als label');
page_assert(array_column($catalog, 'environment', 'name')['KVT Germany GmbH'] === 'kvtgermanylive_aad', 'environment volgt uit het bedrijf');
page_assert(array_column($catalog, 'key', 'name') === ['Hunter van Twist' => 'hvt', 'Koninklijke van Twist' => 'kvt', 'KVT Germany GmbH' => ''], 'sleutel naar de nachtcache');
page_assert(is_file($pageTmp . '/companies.json'), 'lijst 24 uur bewaard');
unset($GLOBALS['consus_company_catalog_memo']);
page_assert(array_column(consus_company_catalog(true, static function (): array { throw new RuntimeException('niet nodig'); }), 'name') === array_column($catalog, 'name'), 'verse cache: geen nieuwe discovery');
page_assert(consus_company_resolve('hvt', $catalog)['name'] === 'Hunter van Twist' && consus_company_resolve('HUNTER VAN TWIST', $catalog)['name'] === 'Hunter van Twist' && consus_company_resolve('Hunter & van Twist', $catalog)['name'] === 'Hunter van Twist', 'oude sleutel, Name en weergavenaam');
page_assert(consus_company_resolve('Onbekend', $catalog) === null, 'onbekend bedrijf');

// Filters: URL wint, anders de opgeslagen keuze van de gebruiker.
$fromPrefs = consus_page_filters([], '2026-10-06', ['company' => 'Hunter van Twist', 'cost_center' => '15'], $catalog);
page_assert($fromPrefs['company'] === 'Hunter van Twist' && $fromPrefs['company_key'] === 'hvt' && $fromPrefs['cost_center'] === '15', 'keuze uit de voorkeuren');
$fromUrl = consus_page_filters(['company' => 'kvt', 'cost_center' => '5'], '2026-10-06', ['company' => 'Hunter van Twist', 'cost_center' => '15'], $catalog);
page_assert($fromUrl['company'] === 'Koninklijke van Twist' && $fromUrl['cost_center'] === '5', 'deeplink met kvt wint van de voorkeur');
$all = consus_page_filters(['company' => ''], '2026-10-06', ['company' => 'Hunter van Twist'], $catalog);
page_assert($all['company'] === '' && $all['usage_available'], 'Alle blijft mogelijk');
$germany = consus_page_filters(['company' => 'KVT Germany GmbH', 'cost_center' => '15'], '2026-10-06', [], $catalog);
page_assert($germany['company_key'] === '' && !$germany['usage_available'], 'bedrijf buiten de nachtcache: alleen Retourlijst');

ob_start();
consus_page_render(page_snapshot(5), ['company' => 'Koninklijke van Twist', 'cost_center' => '5'] + consus_empty_prefs(), [], 'tok', $catalog);
$chosen = (string) ob_get_clean();
page_assert(str_contains($chosen, '<option value="Koninklijke van Twist" selected>Koninklijke Van Twist</option>'), 'opgeslagen bedrijf geselecteerd, label is weergavenaam');
page_assert(str_contains($chosen, '<option value="KVT Germany GmbH">KVT Germany GmbH</option>'), 'Germany in de dropdown');
page_assert(!str_contains($chosen, 'retour_afdeling'), 'geen eigen afdelingskeuze voor de Retourlijst');
page_assert(str_contains($chosen, 'retourkandidaten van Koninklijke Van Twist') || str_contains($chosen, 'Nog geen regels voor afdeling'), 'Retourlijst volgt het bedrijf bovenaan');
ob_start();
consus_page_render(page_snapshot(5), consus_empty_prefs(), ['company' => 'KVT Germany GmbH'], 'tok', $catalog);
$germanyHtml = (string) ob_get_clean();
page_assert(str_contains($germanyHtml, 'alleen voor KVT en HVT') && !str_contains($germanyHtml, 'id="export-link"'), 'Germany: geen verbruikstabel en geen export');

// Afdelingen per bedrijf: zelfde code, andere naam; nooit gedeeld.
$deptSnapshot = page_snapshot(1);
$deptSnapshot['rows'] = [
    ['company_key' => 'kvt', 'cost_center' => '15', 'vendor_no' => '', 'location' => ''],
    ['company_key' => 'hvt', 'cost_center' => '90', 'vendor_no' => '', 'location' => ''],
    ['company_key' => 'hvt', 'cost_center' => '80', 'vendor_no' => '', 'location' => ''],
];
$deptSnapshot['departments'] = [
    ['company_key' => 'kvt', 'code' => '15', 'name' => 'Perkins', 'label' => '15 - Perkins'],
    ['company_key' => 'kvt', 'code' => '20', 'name' => 'Inkoop KvT', 'label' => '20 - Inkoop KvT'],
    ['company_key' => 'hvt', 'code' => '15', 'name' => 'Niet gebruiken enkel bij KVT van Hunter & van Twist', 'label' => '15 - Niet gebruiken enkel bij KVT van Hunter & van Twist'],
    ['company_key' => 'hvt', 'code' => '80', 'name' => 'Spoed', 'label' => '80 - Spoed'],
    ['company_key' => 'hvt', 'code' => '90', 'name' => 'Voorraad', 'label' => '90 - Voorraad'],
    ['company_key' => '', 'code' => '42', 'name' => 'Zonder bedrijf', 'label' => '42 - Zonder bedrijf'],
];
$hvtChoices = array_column(consus_page_department_choices($deptSnapshot['rows'], $deptSnapshot['departments'], 'Hunter van Twist', 'hvt'), 'label', 'value');
page_assert($hvtChoices === ['15' => '15 - Niet gebruiken enkel bij KVT van Hunter & van Twist', '80' => '80 - Spoed', '90' => '90 - Voorraad'], 'Hunter: eigen namen, 15/80/90, niets van KvT of zonder bedrijf');
$kvtChoices = array_column(consus_page_department_choices($deptSnapshot['rows'], $deptSnapshot['departments'], 'Koninklijke van Twist', 'kvt'), 'label', 'value');
page_assert($kvtChoices === ['15' => '15 - Perkins', '20' => '20 - Inkoop KvT'], 'KvT: eigen namen, 15 is Perkins');
page_assert(consus_page_department_choices($deptSnapshot['rows'], $deptSnapshot['departments'], '', '') === [], 'Alle: geen gedeelde afdelingslijst');
$deChoices = array_column(consus_page_department_choices($deptSnapshot['rows'], $deptSnapshot['departments'], 'KVT Germany GmbH', ''), 'label', 'value');
page_assert($deChoices === ['10' => '10 - Vertrieb', '15' => '15 - Lager Deutschland'], 'Germany: eigen lijst uit BC met namen');
$cachedDepartments = json_decode((string) file_get_contents($pageTmp . '/departments.json'), true);
page_assert(array_column($cachedDepartments['companies']['kvt germany gmbh']['departments'] ?? [], 'code') === ['10', '15'], 'lijst per bedrijf bewaard onder de BC Name');
page_assert(!isset($cachedDepartments['companies']['hunter van twist']), 'Hunter komt uit de nachtcache, niet uit een gedeelde lijst');
unset($GLOBALS['consus_department_catalog_memo']);
page_assert(count(consus_company_department_catalog('KVT Germany GmbH', true, static function (): array { throw new RuntimeException('niet nodig'); })) === 2, 'verse lijst: geen nieuwe fetch');
// Nachtcache zonder lijst voor HVT: HVT haalt zijn eigen lijst op, niet die van KvT.
unset($GLOBALS['consus_department_catalog_memo']);
$hvtOwn = consus_page_department_choices([], [$deptSnapshot['departments'][0]], 'Hunter van Twist', 'hvt', static fn (string $company): array => $company === 'Hunter van Twist' ? [['code' => '90', 'name' => 'Voorraad']] : []);
page_assert(array_column($hvtOwn, 'label') === ['90 - Voorraad'], 'HVT zonder nachtlijst: eigen lijst uit BC, geen KvT-namen');
// Mislukte fetch: geen namen van een ander bedrijf, over een uur opnieuw.
unset($GLOBALS['consus_department_catalog_memo']);
page_assert(consus_company_department_catalog('Ander Bedrijf', true, static function (): array { throw new RuntimeException('BC weg'); }) === [], 'mislukte fetch: lege lijst');
$retry = json_decode((string) file_get_contents($pageTmp . '/departments.json'), true)['companies']['ander bedrijf'] ?? [];
page_assert((int) ($retry['fetched_at'] ?? 0) < time() - CONSUS_DEPARTMENT_CATALOG_TTL + 3700, 'mislukte fetch: over een uur opnieuw');

ob_start();
consus_page_render($deptSnapshot, consus_empty_prefs(), ['company' => 'Hunter van Twist'], 'tok', $catalog);
$hvtHtml = (string) ob_get_clean();
page_assert(str_contains($hvtHtml, '15 - Niet gebruiken enkel bij KVT van Hunter &amp; van Twist') && !str_contains($hvtHtml, '15 - Perkins'), 'Hunter-dropdown toont de Hunter-naam van 15');
ob_start();
consus_page_render($deptSnapshot, consus_empty_prefs(), ['company' => 'Koninklijke van Twist'], 'tok', $catalog);
$kvtHtml = (string) ob_get_clean();
page_assert(str_contains($kvtHtml, '15 - Perkins') && !str_contains($kvtHtml, 'Niet gebruiken'), 'KvT-dropdown toont de KvT-naam van 15');
ob_start();
consus_page_render($deptSnapshot, consus_empty_prefs(), ['company' => '', 'cost_center' => '15'], 'tok', $catalog);
$allHtml = (string) ob_get_clean();
page_assert(str_contains($allHtml, 'Kies eerst een bedrijf') && !str_contains($allHtml, '<option value="15"'), 'Alle: geen afdelingsfilter over bedrijven heen');
ob_start();
consus_page_render($deptSnapshot, consus_empty_prefs(), ['company' => 'KVT Germany GmbH'], 'tok', $catalog);
$deHtml = (string) ob_get_clean();
page_assert(str_contains($deHtml, '15 - Lager Deutschland') && !str_contains($deHtml, '15 - Perkins'), 'Germany-dropdown toont eigen namen');

array_map('unlink', glob($pageTmp . '/*') ?: []);
@rmdir($pageTmp);
echo "OK\n";
