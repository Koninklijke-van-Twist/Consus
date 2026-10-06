<?php

require_once __DIR__ . '/consus_usage.php';
require_once __DIR__ . '/consus_prefs.php';

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
 * @param array<string, mixed> $query
 * @return array{company:string,cost_center:string,year:int}
 */
function consus_page_filters(array $query, string $asOf): array
{
    $company = trim((string) ($query['company'] ?? ''));
    if (!isset(CONSUS_COMPANIES[$company])) {
        $company = '';
    }
    $years = consus_history_years($asOf);
    $year = (int) ($query['year'] ?? 0);
    if (!in_array($year, $years, true)) {
        $year = $years[0] ?? (int) substr($asOf, 0, 4);
    }

    return [
        'company' => $company,
        'cost_center' => trim((string) ($query['cost_center'] ?? '')),
        'year' => $year,
    ];
}

/**
 * @param array<string, mixed> $snapshot
 * @param array{customers?:array<int, string>,items?:array<int, string>} $prefs
 * @param array<string, mixed> $query
 */
function consus_page_render(array $snapshot, array $prefs, array $query, string $csrf): void
{
    $hasCache = trim((string) ($snapshot['generated_at'] ?? '')) !== '';
    $ready = (int) ($snapshot['version'] ?? 0) === CONSUS_SNAPSHOT_VERSION;
    $windows = consus_snapshot_windows($snapshot);
    $asOf = (string) ($windows['as_of'] ?? '');
    $filters = consus_page_filters($query, $asOf);
    $companyFilter = $filters['company'];
    $costFilter = $filters['cost_center'];
    $activeYear = $filters['year'];
    $years = consus_history_years($asOf);

    $rows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
    $departmentCatalog = is_array($snapshot['departments'] ?? null) ? $snapshot['departments'] : [];
    $departmentChoices = consus_department_choices($rows, $departmentCatalog, $companyFilter);
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
    $facts = ($hasCache && $ready)
        ? consus_usage_facts($snapshot, $companyFilter, $costFilter, $excludedItems)
        : [];
    $tables = [];
    foreach ($years as $year) {
        $tables[$year] = consus_usage_year_rows($facts, $year, $windows, $excludedCustomers);
    }
    $customerSuggestions = consus_customer_suggestions($snapshot, $companyFilter);
    $itemSuggestions = consus_item_suggestions($snapshot, $companyFilter, $costFilter);
    $warningLines = consus_warning_lines(is_array($snapshot['warnings'] ?? null) ? $snapshot['warnings'] : []);
    $queryString = http_build_query([
        'company' => $companyFilter,
        'cost_center' => $costFilter,
        'year' => (string) $activeYear,
    ], '', '&', PHP_QUERY_RFC3986);
    $exportQuery = http_build_query([
        'company' => $companyFilter,
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
        .page { width: min(1440px, 100%); margin: 0 auto; padding: 24px; }
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
        .chips { display: flex; flex-wrap: wrap; gap: 6px; min-height: 8px; }
        .chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 999px; background: #e8f6fb; color: #00529b; font-size: .82rem; }
        .chip button { border: 0; background: transparent; color: inherit; cursor: pointer; padding: 0 2px; }
        .suggestions { position: absolute; z-index: 5; left: 0; right: 0; top: calc(100% - 4px); margin: 0; padding: 6px; list-style: none; border: 1px solid var(--kvt-line); border-radius: 10px; background: #fff; box-shadow: 0 10px 24px rgba(15, 23, 42, .08); max-height: 240px; overflow: auto; }
        .suggestions button { width: 100%; text-align: left; border: 0; background: transparent; padding: 8px; border-radius: 8px; cursor: pointer; }
        .suggestions button:hover, .suggestions button:focus { background: #f3f8fb; }
        .tabs { display: flex; gap: 8px; padding: 16px 16px 0; }
        .tabs button { border: 1px solid var(--kvt-line); background: #fff; border-radius: 999px; padding: 8px 14px; cursor: pointer; color: #34445a; }
        .tabs button[aria-selected="true"] { background: #00529b; border-color: #00529b; color: #fff; }
        .legend { margin: 0 16px 12px; color: var(--kvt-muted); font-size: .82rem; }
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

    <section class="panel">
        <form class="toolbar" method="get" action="index.php">
            <div class="field">
                <label for="cost_center">Afdeling</label>
                <select id="cost_center" name="cost_center">
                    <option value="">Alle afdelingen</option>
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
                <select id="company" name="company">
                    <option value="">Alle</option>
                    <?php foreach (CONSUS_COMPANIES as $key => $company): ?>
                        <option value="<?= consus_h($key) ?>"<?= $companyFilter === $key ? ' selected' : '' ?>><?= consus_h($company['label'] ?? $key) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="year" value="<?= consus_h((string) $activeYear) ?>">
            <button class="filter-button" type="submit">Filteren</button>
            <a class="export-link" href="export.php?<?= consus_h($exportQuery) ?>">Exporteer xlsx</a>
        </form>
    </section>

    <section class="panel">
        <form id="prefs-form" class="filters" method="post" action="prefs.php">
            <input type="hidden" name="csrf" value="<?= consus_h($csrf) ?>">
            <input type="hidden" name="redirect" value="1">
            <input type="hidden" name="company" value="<?= consus_h($companyFilter) ?>">
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
        </div>
        <div class="tabs" role="tablist" aria-label="Jaren">
            <?php foreach ($years as $year): ?>
                <button type="button" role="tab" id="tab-<?= consus_h((string) $year) ?>" data-year="<?= consus_h((string) $year) ?>" aria-selected="<?= $year === $activeYear ? 'true' : 'false' ?>" aria-controls="panel-<?= consus_h((string) $year) ?>"><?= consus_h((string) $year) ?></button>
            <?php endforeach; ?>
        </div>
        <p class="legend"><mark>Geel</mark> <?= consus_h(consus_low_stock_legend()) ?></p>
        <?php if (!$hasCache): ?>
            <div class="empty">Nog geen artikelen. De cache is leeg.</div>
        <?php elseif (!$ready): ?>
            <div class="empty">De jaartabel wacht op de volgende nachtrun.</div>
        <?php elseif ($facts === []): ?>
            <div class="empty">Geen artikelen voor deze filters.</div>
        <?php else: ?>
            <?php foreach ($years as $year): ?>
                <div class="year-panel" id="panel-<?= consus_h((string) $year) ?>" role="tabpanel" aria-labelledby="tab-<?= consus_h((string) $year) ?>"<?= $year === $activeYear ? '' : ' hidden' ?>>
                    <div class="table-wrap">
                        <table data-year-table="<?= consus_h((string) $year) ?>">
                            <thead>
                                <tr>
                                    <?php foreach ($columnLabels as $index => $label): ?>
                                        <th scope="col" data-col="<?= (int) $index ?>" data-type="<?= $index === 0 ? 'text' : 'number' ?>" class="<?= $index === 0 ? 'item' : 'numeric' ?>"><?= consus_h($label) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables[$year] as $row): ?>
                                    <?php
                                    $values = consus_usage_row_values($row);
                                    $payload = consus_modal_payload($row);
                                    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                                    ?>
                                    <tr>
                                        <?php foreach ($values as $index => $value): ?>
                                            <?php if ($index === 0): ?>
                                                <td class="item<?= !empty($row['low_stock']) ? ' low-stock' : '' ?>" data-sort="<?= consus_h((string) $value) ?>">
                                                    <button type="button" class="item-button" data-article="<?= consus_h((string) $payloadJson) ?>" title="<?= consus_h(consus_low_stock_legend()) ?>"><?= consus_h((string) $value) ?></button>
                                                    <?php if ($companyFilter === ''): ?>
                                                        <span class="company-tag"><?= consus_h((string) ($row['company_label'] ?? '')) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php else: ?>
                                                <td class="numeric" data-sort="<?= consus_h((string) $value) ?>"><?= consus_h(consus_format_qty((float) $value)) ?></td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

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

    if (form) {
        form.addEventListener('submit', function (event) {
            if (!window.fetch) { return; }
            event.preventDefault();
            save();
        });
    }

    var tabs = document.querySelectorAll('[role="tab"][data-year]');
    function showYear(year) {
        tabs.forEach(function (tab) {
            var selected = tab.getAttribute('data-year') === year;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            var panel = document.getElementById(tab.getAttribute('aria-controls'));
            if (panel) { panel.hidden = !selected; }
        });
        document.querySelectorAll('input[name="year"]').forEach(function (input) { input.value = year; });
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('year', year);
            window.history.replaceState({}, '', url.toString());
        }
    }
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { showYear(tab.getAttribute('data-year')); });
    });

    document.querySelectorAll('table[data-year-table]').forEach(function (table) {
        var headers = table.querySelectorAll('thead th');
        headers.forEach(function (header) {
            header.addEventListener('click', function () {
                var index = Number(header.getAttribute('data-col'));
                var type = header.getAttribute('data-type');
                var body = table.querySelector('tbody');
                var rows = Array.prototype.slice.call(body.querySelectorAll('tr'));
                var descending = header.getAttribute('aria-sort') === 'ascending';
                headers.forEach(function (other) { other.removeAttribute('aria-sort'); });
                header.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
                rows.sort(function (left, right) {
                    var a = left.cells[index];
                    var b = right.cells[index];
                    var result;
                    if (type === 'number') {
                        result = Number(a.getAttribute('data-sort')) - Number(b.getAttribute('data-sort'));
                    } else {
                        result = a.getAttribute('data-sort').localeCompare(b.getAttribute('data-sort'), 'nl', { numeric: true, sensitivity: 'base' });
                    }
                    return descending ? -result : result;
                });
                rows.forEach(function (row) { body.appendChild(row); });
            });
        });
    });

    var dialog = document.getElementById('article-modal');
    function fill(id, text) {
        var node = document.getElementById(id);
        if (node) { node.textContent = text; }
    }
    document.querySelectorAll('[data-article]').forEach(function (button) {
        button.addEventListener('click', function () {
            var data = JSON.parse(button.getAttribute('data-article') || '{}');
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
        });
    });
    document.querySelectorAll('[data-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (dialog && dialog.close) { dialog.close(); }
        });
    });
})();
</script>
</body>
</html>
    <?php
}
