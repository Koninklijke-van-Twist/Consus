<?php

require_once __DIR__ . '/consus_usage.php';
require_once __DIR__ . '/consus_prefs.php';
require_once __DIR__ . '/consus_retour_page.php';
require_once __DIR__ . '/consus_companies.php';

function consus_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function consus_format_qty(float $value): string
{
    $decimals = abs($value - round($value)) < 0.00001 ? 0 : 1;

    return number_format($value, $decimals, ',', '.');
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function consus_modal_payload(array $row): array
{
    $outlook = is_array($row['outlook'] ?? null) ? $row['outlook'] : [];
    $average = $outlook['average'] ?? null;
    $expected = $outlook['expected'] ?? null;
    $averageText = is_float($average) || is_int($average) ? consus_format_qty((float) $average) : 'geen historie';
    $expectedText = is_float($expected) || is_int($expected) ? consus_format_qty((float) $expected) : '—';
    $remaining = (int) ($outlook['remaining_days'] ?? 0);
    $days = (int) ($outlook['days_in_year'] ?? 0);
    if ($average === null) {
        $calculation = 'Geen volledig voorgaand kalenderjaar in deze snapshot. Er is dan geen gemiddelde en geen verwacht restant, en het artikelnummer wordt niet geel.';
    } else {
        $calculation = consus_format_qty((float) $average)
            . ' × (' . $remaining . ' / ' . $days . ') = ' . $expectedText;
    }
    $asOf = consus_parse_date((string) ($outlook['as_of'] ?? ''));
    $asOfLabel = $asOf === '' ? '' : consus_format_dutch_date(new DateTimeImmutable($asOf . ' 00:00:00', new DateTimeZone('Europe/Amsterdam')));

    return [
        'item_no' => (string) ($row['item_no'] ?? ''),
        'company' => (string) ($row['company_label'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'safety' => consus_format_qty((float) ($row['safety_stock'] ?? 0)),
        'inventory' => consus_format_qty((float) ($row['inventory'] ?? 0)),
        'average' => $averageText,
        'years' => (string) ($outlook['average_label'] ?? 'geen historie'),
        'ytd' => consus_format_qty((float) ($outlook['ytd'] ?? 0)),
        'expected' => $expectedText,
        'calculation' => $calculation,
        'remaining' => $remaining,
        'days' => $days,
        'as_of' => $asOfLabel,
        'low' => !empty($row['low_stock']),
    ];
}

/**
 * Filters uit de URL; ontbreekt een parameter, dan de laatste keuze van de
 * gebruiker (server-side voorkeuren). `company` is de BC Name uit de
 * bedrijvenlijst; oude deeplinks met kvt/hvt werken ook.
 *
 * @param array<string, mixed> $query
 * @param array<string, mixed> $prefs
 * @param array<int, array{name:string,environment:string,label:string,key:string}>|null $catalog
 * @return array{company:string,company_key:string,company_label:string,usage_available:bool,cost_center:string,year:int}
 */
function consus_page_filters(array $query, string $asOf, array $prefs = [], ?array $catalog = null): array
{
    $catalog ??= consus_company_catalog_static();
    $rawCompany = array_key_exists('company', $query)
        ? trim((string) $query['company'])
        : (string) ($prefs['company'] ?? '');
    $selected = consus_company_resolve($rawCompany, $catalog);
    $years = consus_history_years($asOf);
    $year = (int) ($query['year'] ?? 0);
    if (!in_array($year, $years, true)) {
        $year = $years[0] ?? (int) substr($asOf, 0, 4);
    }
    $costCenter = array_key_exists('cost_center', $query)
        ? trim((string) $query['cost_center'])
        : (string) ($prefs['cost_center'] ?? '');

    return [
        'company' => $selected['name'] ?? '',
        'company_key' => $selected['key'] ?? '',
        'company_label' => $selected['label'] ?? '',
        // De nachtcache heeft alleen KVT en HVT; een ander bedrijf heeft hier alleen de Retourlijst.
        'usage_available' => $selected === null || ($selected['key'] ?? '') !== '',
        'cost_center' => $costCenter,
        'year' => $year,
    ];
}

/**
 * Compacte rijen voor de browser. Alleen de huidige pagina komt in de tabel;
 * de andere jaren blijven in deze lijst en worden niet als extra DOM gezet.
 *
 * @param array<int, array<string, mixed>> $facts
 * @param array<int, int> $years
 * @param array{as_of?:string,history_start?:string} $windows
 * @param array<int, string> $excludedCustomers
 * @return array<int, array{item:string,company:string,low:bool,modal:array<string, mixed>,values:array<int, array<int, float>>}>
 */
function consus_page_client_rows(array $facts, array $years, array $windows, array $excludedCustomers, bool $showCompany): array
{
    $articles = [];
    foreach ($facts as $fact) {
        if (!is_array($fact) || $years === []) {
            continue;
        }
        $sample = null;
        $values = [];
        foreach ($years as $year) {
            $row = consus_usage_year_row($fact, (int) $year, $windows, $excludedCustomers);
            if ($sample === null) {
                $sample = $row;
            }
            $flat = consus_usage_row_values($row);
            array_shift($flat);
            $numbers = [];
            foreach ($flat as $number) {
                $numbers[] = (float) $number;
            }
            $values[(int) $year] = $numbers;
        }
        if ($sample === null) {
            continue;
        }
        $articles[] = [
            'item' => (string) ($sample['item_no'] ?? ''),
            'company' => $showCompany ? (string) ($sample['company_label'] ?? '') : '',
            'low' => !empty($sample['low_stock']),
            'modal' => consus_modal_payload($sample),
            'values' => $values,
        ];
    }

    return $articles;
}

/**
 * @param array<string, mixed> $snapshot
 * @param array{customers?:array<int, string>,items?:array<int, string>,page_size?:int} $prefs
 * @param array<string, mixed> $query
 */
function consus_page_render(array $snapshot, array $prefs, array $query, string $csrf, ?array $companyCatalog = null): void
{
    $hasCache = trim((string) ($snapshot['generated_at'] ?? '')) !== '';
    $ready = (int) ($snapshot['version'] ?? 0) === CONSUS_SNAPSHOT_VERSION;
    $windows = consus_snapshot_windows($snapshot);
    $asOf = (string) ($windows['as_of'] ?? '');
    $companyCatalog = $companyCatalog ?? consus_company_catalog();
    $filters = consus_page_filters($query, $asOf, $prefs, $companyCatalog);
    $companyName = $filters['company'];
    $companyLabel = $filters['company_label'];
    $companyFilter = $filters['company_key'];
    $usageAvailable = $filters['usage_available'];
    $costFilter = $filters['cost_center'];
    $activeYear = $filters['year'];
    $years = consus_history_years($asOf);

    $rows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
    $departmentCatalog = is_array($snapshot['departments'] ?? null) ? $snapshot['departments'] : [];
    // Afdelingen per bedrijf (code én naam uit dát bedrijf); bij "Alle" geen afdelingskeuze.
    $departmentChoices = consus_page_department_choices($rows, $departmentCatalog, $companyName, $companyFilter);
    if ($companyName !== '') {
        // Afdelingen met retourregels of retourdata van dit bedrijf horen er ook bij.
        $departmentChoices = consus_retour_department_choices($departmentChoices, $companyName, $costFilter, !$usageAvailable);
    }
    $departmentValues = [];
    foreach ($departmentChoices as $departmentChoice) {
        $departmentValues[(string) ($departmentChoice['value'] ?? '')] = true;
    }
    if ($costFilter !== '' && !isset($departmentValues[$costFilter])) {
        $matchedDepartment = '';
        foreach ($departmentChoices as $departmentChoice) {
            $departmentValue = (string) ($departmentChoice['value'] ?? '');
            if ($departmentValue !== '__none__' && consus_cost_centers_match($departmentValue, $costFilter)) {
                $matchedDepartment = $departmentValue;
                break;
            }
        }
        $costFilter = $matchedDepartment;
    }

    $excludedCustomers = consus_prefs_normalize_list($prefs['customers'] ?? []);
    $excludedItems = consus_prefs_normalize_list($prefs['items'] ?? []);
    $pageSize = consus_normalize_page_size($prefs['page_size'] ?? CONSUS_DEFAULT_PAGE_SIZE);
    $facts = ($hasCache && $ready && $usageAvailable)
        ? consus_usage_facts($snapshot, $companyFilter, $costFilter, $excludedItems)
        : [];
    $clientRows = consus_page_client_rows($facts, $years, $windows, $excludedCustomers, $companyFilter === '');
    $tablePayload = [
        'pageSize' => $pageSize,
        'activeYear' => $activeYear,
        'legend' => consus_low_stock_legend(),
        'articles' => $clientRows,
    ];
    $customerSuggestions = consus_customer_suggestions($snapshot, $companyFilter);
    $itemSuggestions = consus_item_suggestions($snapshot, $companyFilter, $costFilter);
    $warningLines = consus_warning_lines(is_array($snapshot['warnings'] ?? null) ? $snapshot['warnings'] : []);
    $infoLines = consus_info_lines(is_array($snapshot['warnings'] ?? null) ? $snapshot['warnings'] : []);
    $queryString = http_build_query([
        'company' => $companyName,
        'cost_center' => $costFilter,
        'year' => (string) $activeYear,
    ], '', '&', PHP_QUERY_RFC3986);
    $exportQuery = http_build_query([
        'company' => $companyName,
        'cost_center' => $costFilter,
    ], '', '&', PHP_QUERY_RFC3986);
    $columnLabels = consus_usage_column_labels();
    ?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0099cc">
    <title>Consus · Verbruik per jaar</title>
    <link rel="stylesheet" href="brand.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="doc.svg" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f7fb; color: var(--kvt-text); }
        button, input, select { font: inherit; }
        .page { width: min(1940px, 100%); margin: 0 auto; padding: 24px; }
        .hero {
            display: flex; align-items: flex-start; justify-content: space-between; gap: 24px;
            margin-bottom: 20px; padding: 24px; border-radius: 18px; color: #fff;
            background: linear-gradient(120deg, #00529b, #0099cc);
            box-shadow: 0 16px 38px rgba(0, 82, 155, .16);
        }
        .hero-main { display: flex; gap: 18px; align-items: center; }
        .hero-logo { display: grid; place-items: center; flex: 0 0 auto; padding: 12px 16px; border-radius: 16px; background: #fff; }
        .hero-logo img { display: block; width: auto; height: 40px; }
        h1 { margin: 0 0 6px; font-size: clamp(1.65rem, 3vw, 2.35rem); }
        h2 { margin: 0; font-size: 1.05rem; color: #00529b; }
        .hero p { margin: 0; max-width: 760px; color: rgba(255,255,255,.86); }
        .snapshot { flex: 0 0 auto; text-align: right; font-size: .83rem; color: rgba(255,255,255,.8); }
        .snapshot strong { display: block; margin-top: 4px; color: #fff; font-size: .98rem; }
        .panel { border: 1px solid var(--kvt-line); border-radius: 16px; background: #fff; overflow: hidden; margin-bottom: 16px; }
        .panel-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-end; padding: 16px 16px 0; }
        .panel-head p { margin: 6px 0 0; color: var(--kvt-muted); font-size: .84rem; }
        .toolbar, .filters { display: grid; gap: 10px; padding: 16px; align-items: end; }
        .toolbar { grid-template-columns: minmax(160px, 1fr) minmax(160px, 1fr) auto auto; }
        .filters { grid-template-columns: 1fr 1fr; }
        .field { display: grid; gap: 5px; position: relative; }
        .field label { color: var(--kvt-muted); font-size: .76rem; }
        .field select, .field input[type="text"] {
            width: 100%; min-height: 42px; padding: 9px 12px; border: 1px solid var(--kvt-line);
            border-radius: 10px; color: var(--kvt-text); background: #fff;
        }
        .field select:focus, .field input[type="text"]:focus { outline: 3px solid rgba(0,153,204,.16); border-color: #0099cc; }
        .filter-button, .export-link {
            min-height: 42px; padding: 9px 18px; border: 1px solid #0099cc; border-radius: 10px;
            background: #0099cc; color: #fff; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center;
        }
        .export-link { background: #fff; color: #00529b; }
        .notice { margin-bottom: 16px; padding: 13px 16px; border-radius: 12px; background: #fff8e7; border: 1px solid #f3d691; color: #704d00; }
        .notice p { margin: 0 0 8px; }
        .notice div + div { margin-top: 6px; }
        .notice.error { background: #fff0f0; border-color: #efb3b3; color: #8b2020; }
        .save-status { grid-column: 1 / -1; margin: 0; color: #8b2020; font-size: .84rem; }
        .filter-status { grid-column: 1 / -1; margin: 0; font-size: .84rem; }
        .field select:disabled { background: #f4f7fb; color: var(--kvt-muted); cursor: not-allowed; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; min-height: 8px; }
        .chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 999px; background: #e8f6fb; color: #00529b; font-size: .82rem; }
        .chip button { border: 0; background: transparent; color: inherit; cursor: pointer; padding: 0 2px; }
        .suggestions { position: absolute; z-index: 5; left: 0; right: 0; top: calc(100% - 4px); margin: 0; padding: 6px; list-style: none; border: 1px solid var(--kvt-line); border-radius: 10px; background: #fff; box-shadow: 0 10px 24px rgba(15, 23, 42, .08); max-height: 240px; overflow: auto; }
        .suggestions button { width: 100%; text-align: left; border: 0; background: transparent; padding: 8px; border-radius: 8px; cursor: pointer; }
        .suggestions button:hover, .suggestions button:focus { background: #f3f8fb; }
        .tabs { display: flex; gap: 8px; padding: 16px 16px 0; }
        .tabs button { border: 1px solid var(--kvt-line); background: #fff; border-radius: 999px; padding: 8px 14px; cursor: pointer; color: #34445a; }
        .tabs button[aria-selected="true"] { background: #00529b; border-color: #00529b; color: #fff; }
        .page-size { min-width: 148px; }
        .page-size select { min-width: 120px; }
        .legend { margin: 0 16px 12px; color: var(--kvt-muted); font-size: .82rem; }
        .pager { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; padding: 12px 16px; }
        .pager-pages { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
        .pager button { min-width: 36px; min-height: 36px; padding: 0 10px; border: 1px solid var(--kvt-line); background: #fff; border-radius: 8px; cursor: pointer; color: #34445a; }
        .pager button[aria-current="page"] { background: #00529b; color: #fff; border-color: #00529b; }
        .pager button:disabled { opacity: .45; cursor: default; }
        .pager-range { color: var(--kvt-muted); font-size: .84rem; }
        .pager-gap { min-width: 16px; text-align: center; color: var(--kvt-muted); }
        .legend mark { background: #ffe08a; color: inherit; padding: 0 4px; border-radius: 4px; }
        .table-wrap { overflow: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        th {
            padding: 10px 12px; border-bottom: 1px solid var(--kvt-line); background: #f8fafc;
            color: #34445a; text-align: left; white-space: nowrap; cursor: pointer; position: sticky; top: 0;
        }
        td { padding: 10px 12px; border-bottom: 1px solid #e8edf4; vertical-align: top; }
        .numeric { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        td.item, th.item { position: sticky; left: 0; background: #fff; z-index: 1; }
        th.item { background: #f8fafc; z-index: 3; }
        .item-button { border: 0; background: transparent; color: #00529b; font-weight: 700; cursor: pointer; padding: 0; text-align: left; }
        .item-button:hover, .item-button:focus { text-decoration: underline; }
        td.low-stock { background: #ffe08a; }
        td.low-stock .item-button { background: transparent; }
        .company-tag { display: block; color: var(--kvt-muted); font-size: .72rem; font-weight: 500; }
        .muted { color: var(--kvt-muted); }
        .empty { padding: 36px 20px; text-align: center; color: var(--kvt-muted); }
        .footnote { margin: 0 0 8px; color: var(--kvt-muted); font-size: .8rem; }
        .year-panel[hidden] { display: none; }
        dialog { width: min(560px, calc(100% - 32px)); border: 0; border-radius: 16px; padding: 0; box-shadow: 0 24px 60px rgba(15, 23, 42, .24); }
        dialog::backdrop { background: rgba(15, 23, 42, .45); }
        .modal-head, .modal-body { padding: 18px 20px; }
        .modal-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; background: #f8fafc; }
        .modal-head h2 { font-size: 1.2rem; }
        .modal-close { border: 0; background: transparent; font-size: 1.4rem; cursor: pointer; line-height: 1; }
        .modal-body dl { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(0, 1.2fr); gap: 8px 12px; margin: 0; }
        .modal-body dt { color: var(--kvt-muted); }
        .modal-body dd { margin: 0; font-weight: 700; }
        .calc { grid-column: 1 / -1; font-weight: 500; color: #34445a; }
        @media (max-width: 980px) {
            .page { padding: 12px; }
            .hero { padding: 18px; flex-direction: column; }
            .snapshot { text-align: left; }
            .toolbar, .filters { grid-template-columns: 1fr; }
            .hero-logo img { height: 28px; }
        }
    </style>
</head>
<body>
<main class="page">
    <header class="hero">
        <div class="hero-main">
            <div class="hero-logo">
                <img src="logo-website.png" alt="Koninklijke van Twist" width="1378" height="364">
            </div>
            <div>
                <h1>Consus</h1>
                <p>Verbruik per artikel en per jaar voor KVT en HVT. Verbruik is klantafname plus intern verbruik. Een geel artikelnummer heeft te weinig voorraad voor het verwachte restant van dit jaar.</p>
            </div>
        </div>
        <div class="snapshot">
            Nachtelijke cache
            <strong><?= consus_h(consus_format_dutch_datetime((string) ($snapshot['generated_at'] ?? ''))) ?></strong>
        </div>
    </header>

    <?php if (!$hasCache): ?>
        <div class="notice">Er is nog geen cache. Deze pagina leest alleen het bestand van <strong>nightly.php</strong> en haalt hier geen Business Central-gegevens op.</div>
    <?php endif; ?>
    <?php if ($hasCache && !$ready): ?>
        <div class="notice">De jaartabel staat in de cache na de volgende geslaagde nachtrun. Die run is koud, omdat de snapshotversie is verhoogd.</div>
    <?php endif; ?>
    <?php if (($snapshot['errors'] ?? []) !== []): ?>
        <div class="notice error">De laatste nachtelijke controle was niet voor ieder bedrijf succesvol. Eerdere cijfers zijn waar mogelijk behouden.</div>
    <?php endif; ?>
    <?php if ($warningLines !== []): ?>
        <div class="notice">
            <p><strong>De nachtrun is afgerond, met opmerkingen.</strong> De cijfers zijn bijgewerkt. Hieronder staat wat Business Central niet meegaf.</p>
            <?php foreach ($warningLines as $warningLine): ?>
                <div><?= consus_h($warningLine) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($infoLines !== []): ?>
        <div class="notice info">
            <?php foreach ($infoLines as $infoLine): ?>
                <div>Ter info: <?= consus_h($infoLine) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <form class="toolbar" method="get" action="index.php" id="filter-form">
            <div class="field">
                <label for="cost_center">Afdeling</label>
                <select id="cost_center" name="cost_center" autocomplete="off"<?= $companyName === '' ? ' disabled data-company-required' : '' ?>>
                    <option value="">Alle afdelingen</option>
                    <?php if ($companyName === ''): ?>
                        <option value="" disabled>Kies eerst een bedrijf: afdelingen verschillen per bedrijf</option>
                    <?php endif; ?>
                    <?php foreach ($departmentChoices as $departmentChoice): ?>
                        <?php
                        $departmentValue = (string) ($departmentChoice['value'] ?? '');
                        $departmentLabel = $departmentValue === '__none__' ? '(geen afdeling)' : (string) ($departmentChoice['label'] ?? $departmentValue);
                        ?>
                        <option value="<?= consus_h($departmentValue) ?>"<?= $costFilter === $departmentValue ? ' selected' : '' ?>><?= consus_h($departmentLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="company">Bedrijf</label>
                <select id="company" name="company" autocomplete="off">
                    <option value="">Alle (KVT en HVT)</option>
                    <?php foreach ($companyCatalog as $catalogCompany): ?>
                        <option value="<?= consus_h($catalogCompany['name']) ?>"<?= $companyName === $catalogCompany['name'] ? ' selected' : '' ?>><?= consus_h($catalogCompany['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="year" value="<?= consus_h((string) $activeYear) ?>">
            <noscript><button class="filter-button" type="submit">Filteren</button></noscript>
            <p class="filter-status muted" data-filter-status role="status" aria-live="polite" hidden></p>
            <?php if ($usageAvailable): ?>
                <a class="export-link" id="export-link" href="export.php?<?= consus_h($exportQuery) ?>">Exporteer xlsx</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="panel">
        <form id="prefs-form" class="filters" method="post" action="prefs.php">
            <input type="hidden" name="csrf" value="<?= consus_h($csrf) ?>">
            <input type="hidden" name="redirect" value="1">
            <input type="hidden" name="company" value="<?= consus_h($companyName) ?>">
            <input type="hidden" name="cost_center" value="<?= consus_h($costFilter) ?>">
            <input type="hidden" name="year" value="<?= consus_h((string) $activeYear) ?>">
            <div class="field" data-combobox="customers">
                <label for="customer-search">Klanten uitsluiten</label>
                <div class="chips" data-chips></div>
                <input id="customer-search" type="text" autocomplete="off" placeholder="Nummer of naam, meerdere met een komma" aria-describedby="customer-hint">
                <ul class="suggestions" data-suggestions hidden></ul>
                <input type="hidden" name="customers" value="<?= consus_h(implode(', ', $excludedCustomers)) ?>">
                <span id="customer-hint" class="muted">Uitgesloten klanten tellen niet mee in de tabel, de modal, de gele markering en de export.</span>
            </div>
            <div class="field" data-combobox="items">
                <label for="item-search">Artikelen uitsluiten</label>
                <div class="chips" data-chips></div>
                <input id="item-search" type="text" autocomplete="off" placeholder="Artikelnummer, meerdere met een komma">
                <ul class="suggestions" data-suggestions hidden></ul>
                <input type="hidden" name="items" value="<?= consus_h(implode(', ', $excludedItems)) ?>">
                <span class="muted">Uitgesloten artikelen verdwijnen uit de tabel en de export.</span>
            </div>
            <p class="save-status" data-save-status hidden></p>
            <button class="filter-button" type="submit">Filters opslaan</button>
        </form>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <h2>Verbruik per jaar</h2>
                <p><?= count($facts) ?> artikelen. Klik een artikelnummer voor de voorraadberekening. Klik een kolomkop om te sorteren.</p>
            </div>
            <div class="field page-size">
                <label for="page-size">Regels per pagina</label>
                <select id="page-size">
                    <?php foreach (CONSUS_PAGE_SIZES as $size): ?>
                        <option value="<?= (int) $size ?>"<?= $pageSize === (int) $size ? ' selected' : '' ?>><?= (int) $size ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="tabs" role="tablist" aria-label="Jaren">
            <?php foreach ($years as $year): ?>
                <button type="button" role="tab" id="tab-<?= consus_h((string) $year) ?>" data-year="<?= consus_h((string) $year) ?>" aria-selected="<?= $year === $activeYear ? 'true' : 'false' ?>" aria-controls="year-table"><?= consus_h((string) $year) ?></button>
            <?php endforeach; ?>
        </div>
        <p class="legend"><mark>Geel</mark> <?= consus_h(consus_low_stock_legend()) ?></p>
        <?php if (!$hasCache): ?>
            <div class="empty">Nog geen artikelen. De cache is leeg.</div>
        <?php elseif (!$ready): ?>
            <div class="empty">De jaartabel wacht op de volgende nachtrun.</div>
        <?php elseif (!$usageAvailable): ?>
            <div class="empty">Verbruik per jaar staat alleen voor KVT en HVT in de nachtcache. Voor <?= consus_h($companyLabel) ?> toont deze pagina alleen de Retourlijst.</div>
        <?php elseif ($facts === []): ?>
            <div class="empty">Geen artikelen voor deze filters.</div>
        <?php else: ?>
            <nav class="pager" data-pager="top" aria-label="Paginering"></nav>
            <div class="table-wrap">
                <table id="year-table">
                    <thead>
                        <tr>
                            <?php foreach ($columnLabels as $index => $label): ?>
                                <th scope="col" data-col="<?= (int) $index ?>" data-type="<?= $index === 0 ? 'text' : 'number' ?>" class="<?= $index === 0 ? 'item' : 'numeric' ?>"><?= consus_h($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="year-rows"></tbody>
                </table>
            </div>
            <nav class="pager" data-pager="bottom" aria-label="Paginering"></nav>
        <?php endif; ?>
    </section>

    <?php
    // Retourlijst: volgt bedrijf en afdeling van de keuze bovenaan.
    $retourError = in_array((string) ($query['retour_fout'] ?? ''), ['regel', 'opslaan'], true) ? (string) $query['retour_fout'] : '';
    $retourDepartmentLabel = '';
    foreach ($departmentChoices as $departmentChoice) {
        if ((string) ($departmentChoice['value'] ?? '') === $costFilter) {
            $retourDepartmentLabel = (string) ($departmentChoice['label'] ?? '');
        }
    }
    consus_retour_page_section($companyName, $companyLabel, $costFilter, $retourDepartmentLabel, (int) $activeYear, $csrf, $retourError);
    ?>

    <p class="footnote">Verbruik in een maand, kwartaal of jaar is Sale (hoeveelheid met omgedraaid teken, retouren trekken af<?= CONSUS_USAGE_INCLUDES_SALE ? '' : ', nu uitgeschakeld' ?>)<?= CONSUS_USAGE_INCLUDES_INTERNAL ? ' plus intern verbruik' : '' ?>. Intern verbruik is Negative Adjmt. met documentnummer WO plus Assembly Consumption, op Item_No, Quantity en Posting_Date. Het totaal is de som van de twaalf maanden en gelijk aan de som van de vier kwartalen. Cijfers komen uit de nachtelijke snapshot<?= $hasCache && $asOf !== '' ? ' t/m ' . consus_h(consus_format_dutch_date(new DateTimeImmutable($asOf . ' 00:00:00', new DateTimeZone('Europe/Amsterdam')))) : '' ?>. Gemiddeld verbruik in de modal loopt over <?= consus_h(consus_nl_year_list(consus_average_years($windows))) ?>.</p>
</main>

<dialog id="article-modal">
    <div class="modal-head">
        <div>
            <h2 id="modal-title">Artikel</h2>
            <p class="muted" id="modal-description"></p>
        </div>
        <button type="button" class="modal-close" data-close aria-label="Sluiten">×</button>
    </div>
    <div class="modal-body">
        <dl>
            <dt>Veiligheidsvoorraad</dt><dd id="modal-safety"></dd>
            <dt>Huidige voorraad</dt><dd id="modal-inventory"></dd>
            <dt>Gemiddeld verbruik per jaar</dt><dd id="modal-average"></dd>
            <dt>Jaren in het gemiddelde</dt><dd id="modal-years"></dd>
            <dt>Dit jaar al verbruikt</dt><dd id="modal-ytd"></dd>
            <dt>Verwacht resterend verbruik</dt><dd id="modal-expected"></dd>
            <dt>Te weinig voorraad</dt><dd id="modal-low"></dd>
            <dd class="calc" id="modal-calculation"></dd>
        </dl>
    </div>
</dialog>

<script id="customer-suggestions" type="application/json"><?= json_encode($customerSuggestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="item-suggestions" type="application/json"><?= json_encode($itemSuggestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script id="year-data" type="application/json"><?= json_encode($tablePayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
(function () {
    var form = document.getElementById('prefs-form');
    var customerData = JSON.parse(document.getElementById('customer-suggestions').textContent || '[]');
    var itemData = JSON.parse(document.getElementById('item-suggestions').textContent || '[]');

    function parseList(value) {
        return value.split(',').map(function (part) { return part.trim(); }).filter(function (part) { return part !== ''; });
    }

    function unique(list) {
        var seen = {};
        var out = [];
        list.forEach(function (item) {
            var key = item.toLowerCase();
            if (seen[key]) { return; }
            seen[key] = true;
            out.push(item);
        });
        return out;
    }

    function setupCombobox(root, suggestions, labelFor) {
        var hidden = root.querySelector('input[type="hidden"]');
        var search = root.querySelector('input[type="text"]');
        var chips = root.querySelector('[data-chips]');
        var list = root.querySelector('[data-suggestions]');
        var values = unique(parseList(hidden.value));

        function render() {
            hidden.value = values.join(', ');
            chips.innerHTML = '';
            values.forEach(function (value) {
                var chip = document.createElement('span');
                chip.className = 'chip';
                var name = labelFor(value);
                chip.appendChild(document.createTextNode(name || value));
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Verwijder ' + value);
                remove.textContent = '×';
                remove.addEventListener('click', function () {
                    values = values.filter(function (item) { return item.toLowerCase() !== value.toLowerCase(); });
                    render();
                    save();
                });
                chip.appendChild(remove);
                chips.appendChild(chip);
            });
        }

        function add(raw) {
            unique(parseList(raw)).forEach(function (token) {
                if (!values.some(function (item) { return item.toLowerCase() === token.toLowerCase(); })) {
                    values.push(token);
                }
            });
            search.value = '';
            list.hidden = true;
            render();
            save();
        }

        function showSuggestions() {
            var query = search.value.trim().toLowerCase();
            list.innerHTML = '';
            if (query === '') {
                list.hidden = true;
                return;
            }
            var matches = suggestions.filter(function (item) {
                return (item.label || '').toLowerCase().indexOf(query) !== -1 || (item.no || item.item_no || '').toLowerCase().indexOf(query) !== -1;
            }).slice(0, 12);
            matches.forEach(function (item) {
                var entry = document.createElement('li');
                var button = document.createElement('button');
                button.type = 'button';
                button.textContent = item.label;
                button.addEventListener('click', function () {
                    add(item.no || item.item_no);
                });
                entry.appendChild(button);
                list.appendChild(entry);
            });
            list.hidden = matches.length === 0;
        }

        search.addEventListener('input', showSuggestions);
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                if (search.value.trim() !== '') { add(search.value); }
            }
        });
        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) { list.hidden = true; }
        });
        render();
    }

    function labelCustomer(token) {
        var found = customerData.find(function (item) { return String(item.no).toLowerCase() === token.toLowerCase(); });
        return found ? found.label : token;
    }
    function labelItem(token) {
        var found = itemData.find(function (item) { return String(item.item_no).toLowerCase() === token.toLowerCase(); });
        return found ? found.label : token;
    }

    var saving = false;
    var pending = false;
    var filterNavigating = false;
    function save() {
        if (!form) { return; }
        if (saving) { pending = true; return; }
        if (!window.fetch) { return; }
        saving = true;
        pending = false;
        var status = document.querySelector('[data-save-status]');
        if (status) { status.hidden = true; status.textContent = ''; }
        var body = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            if (!response.ok) { throw new Error('opslaan mislukt'); }
            if (pending) { pending = false; saving = false; save(); return; }
            // Een keuze bovenaan is al onderweg: die navigatie wint.
            if (filterNavigating) { saving = false; return; }
            var params = new URLSearchParams();
            params.set('company', (form.querySelector('[name="company"]') || {}).value || '');
            params.set('cost_center', (form.querySelector('[name="cost_center"]') || {}).value || '');
            params.set('year', (form.querySelector('[name="year"]') || {}).value || '');
            window.location.href = 'index.php?' + params.toString();
        }).catch(function () {
            saving = false;
            pending = false;
            if (status) {
                status.hidden = false;
                status.textContent = 'Opslaan mislukt. Probeer het nog eens.';
            }
        });
    }

    document.querySelectorAll('[data-combobox="customers"]').forEach(function (root) {
        setupCombobox(root, customerData, labelCustomer);
    });
    document.querySelectorAll('[data-combobox="items"]').forEach(function (root) {
        setupCombobox(root, itemData, labelItem);
    });

    // Bedrijf en afdeling: direct tonen, en per gebruiker op de server onthouden.
    // Eén navigatie per keuze: een POST naar prefs.php slaat op en stuurt door
    // naar de pagina met precies die keuze. Vroeger ging eerst een fetch en pas
    // daarna de navigatie; een afdelingskeuze terwijl de pagina van een
    // bedrijfswissel nog laadde, werd dan door die lopende navigatie
    // (afdeling leeg) ingehaald, of nam een afdeling uit de lijst van het
    // vorige bedrijf mee. Daarom gaan de keuzes op slot tot de nieuwe pagina er is.
    var filterForm = document.getElementById('filter-form');
    if (filterForm) {
        var companySelect = filterForm.querySelector('select[name="company"]');
        var departmentSelect = filterForm.querySelector('select[name="cost_center"]');
        var filterStatus = filterForm.querySelector('[data-filter-status]');
        var retourEmpty = document.querySelector('#retour > .empty');
        var retourEmptyText = retourEmpty ? retourEmpty.textContent : '';
        // De keuze die de server heeft getoond (selected in de HTML). De browser
        // kan bij vernieuwen of de terug-knop een eerdere keuze in de select
        // terugzetten; dan staat er een afdeling in beeld die niet bij de
        // getoonde lijst hoort, en geeft dezelfde keuze nog eens geen change.
        var renderedValue = function (select) {
            if (!select) { return ''; }
            for (var index = 0; index < select.options.length; index++) {
                if (select.options[index].defaultSelected) { return select.options[index].value; }
            }
            return select.options.length ? select.options[0].value : '';
        };
        var shownCompany = renderedValue(companySelect);
        var shownDepartment = renderedValue(departmentSelect);
        var lockFilters = function (company, department) {
            filterNavigating = true;
            [companySelect, departmentSelect].forEach(function (select) { if (select) { select.disabled = true; } });
            var text = company === '' ? 'Alle bedrijven laden…' : (company !== shownCompany ? 'Ander bedrijf: afdelingen laden…' : 'Retourlijst en verbruik laden…');
            if (filterStatus) { filterStatus.hidden = false; filterStatus.textContent = text; }
            if (retourEmpty) { retourEmpty.textContent = text; }
        };
        var unlockFilters = function () {
            filterNavigating = false;
            if (companySelect) { companySelect.disabled = false; companySelect.value = shownCompany; }
            if (departmentSelect) {
                departmentSelect.value = shownDepartment;
                departmentSelect.disabled = departmentSelect.hasAttribute('data-company-required');
            }
            if (filterStatus) { filterStatus.hidden = true; filterStatus.textContent = ''; }
            if (retourEmpty) { retourEmpty.textContent = retourEmptyText; }
        };
        var chooseFilter = function (company, department) {
            if (filterNavigating) { return; }
            // Afdelingen verschillen per bedrijf: zonder bedrijf ("Alle") geen afdeling.
            if (company === '') { department = ''; }
            var year = (filterForm.querySelector('input[name="year"]') || {}).value || '';
            lockFilters(company, department);
            var csrf = form ? ((form.querySelector('[name="csrf"]') || {}).value || '') : '';
            if (csrf === '') {
                var params = new URLSearchParams();
                params.set('company', company);
                params.set('cost_center', department);
                params.set('year', year);
                window.location.assign('index.php?' + params.toString());
                return;
            }
            var post = document.createElement('form');
            post.method = 'post';
            post.action = (form && form.getAttribute('action')) || 'prefs.php';
            post.hidden = true;
            [['csrf', csrf], ['filter', '1'], ['company', company], ['cost_center', department], ['year', year]].forEach(function (pair) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = pair[0];
                input.value = pair[1];
                post.appendChild(input);
            });
            document.body.appendChild(post);
            post.submit();
        };
        if (companySelect) {
            companySelect.addEventListener('change', function () {
                // De afdelingslijst op deze pagina hoort bij het vorige bedrijf: niet meesturen.
                chooseFilter(companySelect.value, '');
            });
        }
        if (departmentSelect) {
            departmentSelect.addEventListener('change', function () {
                chooseFilter(companySelect ? shownCompany : '', departmentSelect.value);
            });
        }
        // Bij laden, vernieuwen en de terug-knop (ook bfcache): de selects tonen
        // de keuze van deze pagina en zijn weer te bedienen.
        unlockFilters();
        window.addEventListener('pageshow', unlockFilters);
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            if (!window.fetch) { return; }
            event.preventDefault();
            save();
        });
    }

    var yearNode = document.getElementById('year-data');
    var tableData = yearNode ? JSON.parse(yearNode.textContent || '{}') : { articles: [] };
    var articles = tableData.articles || [];
    var year = String(tableData.activeYear || '');
    var pageSize = Number(tableData.pageSize) || 20;
    var page = 1;
    var sortCol = null;
    var sortDir = 1;
    var body = document.getElementById('year-rows');
    var table = document.getElementById('year-table');
    var pageSizeSelect = document.getElementById('page-size');
    var exportLink = document.getElementById('export-link');
    var tabs = document.querySelectorAll('[role="tab"][data-year]');

    function formatQty(value) {
        var number = Number(value) || 0;
        var decimals = Math.abs(number - Math.round(number)) < 0.00001 ? 0 : 1;
        return new Intl.NumberFormat('nl-NL', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(number);
    }

    function compareText(left, right) {
        return String(left || '').localeCompare(String(right || ''), 'nl', { numeric: true, sensitivity: 'base' });
    }

    function sortedIndexes() {
        var indexes = articles.map(function (_, index) { return index; });
        indexes.sort(function (leftIndex, rightIndex) {
            var left = articles[leftIndex];
            var right = articles[rightIndex];
            var result = 0;
            if (sortCol === null) {
                result = compareText(left.item, right.item);
                if (result === 0) { result = compareText(left.company, right.company); }
                return result;
            }
            if (sortCol === 0) {
                result = compareText(left.item, right.item);
            } else {
                var leftValues = (left.values && left.values[year]) || [];
                var rightValues = (right.values && right.values[year]) || [];
                var leftNumber = Number(leftValues[sortCol - 1]) || 0;
                var rightNumber = Number(rightValues[sortCol - 1]) || 0;
                result = leftNumber < rightNumber ? -1 : (leftNumber > rightNumber ? 1 : 0);
            }
            if (result === 0) { result = compareText(left.item, right.item); }
            if (result === 0) { result = compareText(left.company, right.company); }
            return sortDir < 0 ? -result : result;
        });
        return indexes;
    }

    function pageWindow(current, pages) {
        if (pages <= 7) {
            var all = [];
            for (var number = 1; number <= pages; number++) { all.push(number); }
            return all;
        }
        var items = [1];
        var start = Math.max(2, current - 1);
        var end = Math.min(pages - 1, current + 1);
        if (start > 2) { items.push(null); }
        for (var pageNumber = start; pageNumber <= end; pageNumber++) { items.push(pageNumber); }
        if (end < pages - 1) { items.push(null); }
        items.push(pages);
        return items;
    }

    function renderPager(nav, total, from, to, pages) {
        nav.innerHTML = '';
        var range = document.createElement('span');
        range.className = 'pager-range';
        range.textContent = from + '\u2013' + to + ' van ' + total + ' artikelen';
        var controls = document.createElement('div');
        controls.className = 'pager-pages';
        function addButton(label, target, options) {
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            if (options && options.current) { button.setAttribute('aria-current', 'page'); }
            if (options && options.disabled) { button.disabled = true; }
            if (target) {
                button.addEventListener('click', function () {
                    page = target;
                    renderTable();
                });
            }
            controls.appendChild(button);
        }
        addButton('Vorige', page - 1, { disabled: page <= 1 });
        pageWindow(page, pages).forEach(function (entry) {
            if (entry === null) {
                var gap = document.createElement('span');
                gap.className = 'pager-gap';
                gap.textContent = '\u2026';
                controls.appendChild(gap);
                return;
            }
            addButton(String(entry), entry, { current: entry === page });
        });
        addButton('Volgende', page + 1, { disabled: page >= pages });
        nav.appendChild(range);
        nav.appendChild(controls);
    }

    function renderTable() {
        if (!body) { return; }
        var indexes = sortedIndexes();
        var total = indexes.length;
        var pages = Math.max(1, Math.ceil(total / pageSize));
        if (page > pages) { page = pages; }
        if (page < 1) { page = 1; }
        var start = (page - 1) * pageSize;
        var slice = indexes.slice(start, start + pageSize);
        body.innerHTML = '';
        slice.forEach(function (index) {
            var article = articles[index];
            var row = document.createElement('tr');
            var itemCell = document.createElement('td');
            itemCell.className = 'item' + (article.low ? ' low-stock' : '');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'item-button';
            button.textContent = article.item || '';
            button.title = tableData.legend || '';
            button.addEventListener('click', function () { openModal(article.modal || {}); });
            itemCell.appendChild(button);
            if (article.company) {
                var tag = document.createElement('span');
                tag.className = 'company-tag';
                tag.textContent = article.company;
                itemCell.appendChild(tag);
            }
            row.appendChild(itemCell);
            var numbers = (article.values && article.values[year]) || [];
            numbers.forEach(function (number) {
                var cell = document.createElement('td');
                cell.className = 'numeric';
                cell.textContent = formatQty(number);
                row.appendChild(cell);
            });
            body.appendChild(row);
        });
        var from = total === 0 ? 0 : start + 1;
        var to = start + slice.length;
        document.querySelectorAll('[data-pager]').forEach(function (nav) {
            renderPager(nav, total, from, to, pages);
        });
    }

    function updateExport() {
        if (!exportLink) { return; }
        var url = new URL(exportLink.getAttribute('href'), window.location.href);
        if (sortCol === null) {
            url.searchParams.delete('sort');
            url.searchParams.delete('dir');
        } else {
            url.searchParams.set('sort', String(sortCol));
            url.searchParams.set('dir', sortDir < 0 ? 'desc' : 'asc');
        }
        exportLink.setAttribute('href', 'export.php' + url.search);
    }

    function showYear(nextYear) {
        year = String(nextYear);
        page = 1;
        tabs.forEach(function (tab) {
            var selected = tab.getAttribute('data-year') === year;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        document.querySelectorAll('input[name="year"]').forEach(function (input) { input.value = year; });
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('year', year);
            window.history.replaceState({}, '', url.toString());
        }
        renderTable();
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { showYear(tab.getAttribute('data-year')); });
    });

    if (table) {
        table.querySelectorAll('thead th').forEach(function (header) {
            header.addEventListener('click', function () {
                var index = Number(header.getAttribute('data-col'));
                var descending = header.getAttribute('aria-sort') === 'ascending';
                table.querySelectorAll('thead th').forEach(function (other) { other.removeAttribute('aria-sort'); });
                header.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
                sortCol = index;
                sortDir = descending ? -1 : 1;
                page = 1;
                renderTable();
                updateExport();
            });
        });
    }

    if (pageSizeSelect) {
        // Opslaan gaat één verzoek tegelijk, zodat een oudere keuze op de server
        // nooit een nieuwere overschrijft. Alleen de laatste keuze mag de select
        // terugzetten, en dan naar de waarde die de server het laatst bewaarde.
        var savedPageSize = pageSize;
        var pageSizeSeq = 0;
        var pageSizeQueue = Promise.resolve();
        var sendPageSize = function (size, seq) {
            if (seq !== pageSizeSeq) { return Promise.resolve(); }
            var payload = new FormData();
            payload.set('csrf', (form.querySelector('[name="csrf"]') || {}).value || '');
            payload.set('page_size', String(size));
            return fetch(form.action, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                if (!response.ok) { throw new Error('opslaan mislukt'); }
                savedPageSize = size;
                var done = document.querySelector('[data-save-status]');
                if (done && seq === pageSizeSeq) { done.hidden = true; done.textContent = ''; }
            }).catch(function () {
                if (seq !== pageSizeSeq) { return; }
                pageSize = savedPageSize;
                pageSizeSelect.value = String(savedPageSize);
                page = 1;
                renderTable();
                var status = document.querySelector('[data-save-status]');
                if (status) {
                    status.hidden = false;
                    status.textContent = 'Opslaan mislukt. Probeer het nog eens.';
                }
            });
        };
        pageSizeSelect.addEventListener('change', function () {
            var next = Number(pageSizeSelect.value);
            if (!next || next === pageSize) { return; }
            pageSize = next;
            page = 1;
            renderTable();
            if (!form || !window.fetch) { return; }
            var seq = ++pageSizeSeq;
            pageSizeQueue = pageSizeQueue.then(function () { return sendPageSize(next, seq); });
        });
    }

    var dialog = document.getElementById('article-modal');
    function fill(id, text) {
        var node = document.getElementById(id);
        if (node) { node.textContent = text; }
    }
    function openModal(data) {
        fill('modal-title', data.item_no || 'Artikel');
        fill('modal-description', data.description || data.company || '');
        fill('modal-safety', data.safety || '');
        fill('modal-inventory', data.inventory || '');
        fill('modal-average', data.average || '');
        fill('modal-years', data.years || '');
        fill('modal-ytd', data.ytd || '');
        fill('modal-expected', data.expected || '');
        fill('modal-low', data.low ? 'Ja' : 'Nee');
        fill('modal-calculation', (data.as_of ? data.as_of + ': ' : '') + (data.calculation || ''));
        if (dialog && dialog.showModal) { dialog.showModal(); }
    }
    document.querySelectorAll('[data-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (dialog && dialog.close) { dialog.close(); }
        });
    });

    if (body) { renderTable(); }
})();
</script>
</body>
</html>
    <?php
}
