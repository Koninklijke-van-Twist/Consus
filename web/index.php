<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/consus_data.php';

function consus_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function consus_format_qty(float $value): string
{
    $decimals = abs($value - round($value)) < 0.00001 ? 0 : 1;

    return number_format($value, $decimals, ',', '.');
}

function consus_format_money(float $value): string
{
    return '€ ' . number_format($value, 0, ',', '.');
}

function consus_format_ratio(?float $value): string
{
    if ($value === null) {
        return '—';
    }

    return number_format($value, 2, ',', '.') . '×';
}

function consus_format_generated_at(string $value): string
{
    if ($value === '') {
        return 'Nog niet opgebouwd';
    }
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('Europe/Amsterdam'))
            ->format('d-m-Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

function consus_period_total(array $byBucket, string $period, string $field): float
{
    $total = 0.0;
    foreach (array_keys(CONSUS_BUCKETS) as $bucket) {
        $total += (float) ($byBucket[$bucket][$period][$field] ?? 0);
    }

    return $total;
}

function consus_month_label(string $yearMonth): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m', $yearMonth);
    if (!$date instanceof DateTimeImmutable) {
        return $yearMonth;
    }

    $labels = [
        1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr', 5 => 'mei', 6 => 'jun',
        7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec',
    ];

    return ($labels[(int) $date->format('n')] ?? $yearMonth) . ' ' . $date->format('Y');
}

$snapshot = consus_read_snapshot();
$hasCache = trim((string) ($snapshot['generated_at'] ?? '')) !== '';
$windows = is_array($snapshot['windows'] ?? null) ? $snapshot['windows'] : consus_period_windows();
$monthKeys = consus_month_keys([
    'as_of' => (string) ($windows['as_of'] ?? ''),
    'history_start' => (string) ($windows['history_start'] ?? ''),
]);

$companyFilter = trim((string) ($_GET['company'] ?? ''));
if (!isset(CONSUS_COMPANIES[$companyFilter])) {
    $companyFilter = '';
}

$rows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
$departments = consus_department_options($rows, $companyFilter);
$costFilter = trim((string) ($_GET['cost_center'] ?? ''));
$departmentValues = [];
foreach ($departments as $department) {
    $departmentValues[$department === '' ? '__none__' : $department] = true;
}
if ($costFilter !== '' && !isset($departmentValues[$costFilter])) {
    $costFilter = '';
}

$vendors = consus_vendor_options($rows, $companyFilter, $costFilter);
$vendorNumbers = [];
foreach ($vendors as $vendor) {
    $vendorNumbers[(string) ($vendor['vendor_no'] ?? '')] = true;
}
if (array_key_exists('vendor', $_GET)) {
    $vendorFilter = trim((string) $_GET['vendor']);
} else {
    $vendorFilter = consus_default_vendor_no($vendors);
}
if ($vendorFilter !== '' && !isset($vendorNumbers[$vendorFilter])) {
    $vendorFilter = '';
}

$locations = consus_location_options($rows, $companyFilter, $costFilter, $vendorFilter);
$locationValues = [];
foreach ($locations as $location) {
    $locationValues[$location === '' ? '__none__' : $location] = true;
}
$locationFilter = trim((string) ($_GET['location'] ?? ''));
if ($locationFilter !== '' && !isset($locationValues[$locationFilter])) {
    $locationFilter = '';
}

$summary = consus_summarize($snapshot, $companyFilter, $vendorFilter, $costFilter, $locationFilter);
$sales = is_array($summary['sales'] ?? null) ? $summary['sales'] : [];
$consumption = is_array($summary['consumption'] ?? null) ? $summary['consumption'] : [];
$turnover = is_array($summary['turnover'] ?? null) ? $summary['turnover'] : [];

$selectedVendorName = 'Alle leveranciers';
foreach ($vendors as $vendor) {
    if (is_array($vendor) && (string) ($vendor['vendor_no'] ?? '') === $vendorFilter && $vendorFilter !== '') {
        $selectedVendorName = (string) ($vendor['vendor_name'] ?? $vendorFilter);
    }
}
?><!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0099cc">
    <title>Consus · Veiligheidsvoorraad en omloopsnelheid</title>
    <link rel="stylesheet" href="brand.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="doc.svg" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f7fb; color: var(--kvt-text); }
        button, input, select { font: inherit; }
        .page { width: min(1280px, 100%); margin: 0 auto; padding: 24px; }
        .hero {
            display: flex; align-items: flex-start; justify-content: space-between; gap: 24px;
            margin-bottom: 20px; padding: 24px; border-radius: 18px; color: #fff;
            background: linear-gradient(120deg, #00529b, #0099cc);
            box-shadow: 0 16px 38px rgba(0, 82, 155, .16);
        }
        .hero-main { display: flex; gap: 18px; align-items: center; }
        .hero-logo {
            display: grid; place-items: center; flex: 0 0 auto;
            padding: 12px 16px; border-radius: 16px; background: #fff;
        }
        .hero-logo img { display: block; width: auto; height: 40px; }
        h1 { margin: 0 0 6px; font-size: clamp(1.65rem, 3vw, 2.35rem); }
        h2 { margin: 0; font-size: 1.05rem; color: #00529b; }
        .hero p { margin: 0; max-width: 760px; color: rgba(255,255,255,.86); }
        .snapshot { flex: 0 0 auto; text-align: right; font-size: .83rem; color: rgba(255,255,255,.8); }
        .snapshot strong { display: block; margin-top: 4px; color: #fff; font-size: .98rem; }
        .stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .stat { padding: 16px 18px; border: 1px solid var(--kvt-line); border-radius: 14px; background: #fff; }
        .stat-label { color: var(--kvt-muted); font-size: .78rem; }
        .stat-value { display: block; margin-top: 4px; font-size: 1.35rem; font-weight: 800; color: #00529b; }
        .stat-sub { display: block; margin-top: 4px; color: var(--kvt-muted); font-size: .78rem; }
        .panel { border: 1px solid var(--kvt-line); border-radius: 16px; background: #fff; overflow: hidden; margin-bottom: 16px; }
        .panel-head { padding: 16px 16px 0; }
        .panel-head p { margin: 6px 0 0; color: var(--kvt-muted); font-size: .84rem; }
        .toolbar {
            display: grid; grid-template-columns: repeat(4, minmax(140px, 1fr)) auto;
            gap: 10px; padding: 16px; align-items: end;
        }
        .field { display: grid; gap: 5px; }
        .field label { color: var(--kvt-muted); font-size: .76rem; }
        .field select {
            width: 100%; min-height: 42px; padding: 9px 12px; border: 1px solid var(--kvt-line);
            border-radius: 10px; color: var(--kvt-text); background: #fff;
        }
        .field select:focus { outline: 3px solid rgba(0,153,204,.16); border-color: #0099cc; }
        .filter-button {
            min-height: 42px; padding: 9px 18px; border: 1px solid #0099cc; border-radius: 10px;
            background: #0099cc; color: #fff; cursor: pointer;
        }
        .notice { margin-bottom: 16px; padding: 13px 16px; border-radius: 12px; background: #fff8e7; border: 1px solid #f3d691; color: #704d00; }
        .notice.error { background: #fff0f0; border-color: #efb3b3; color: #8b2020; }
        .table-wrap { overflow: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .89rem; }
        th {
            padding: 12px; border-bottom: 1px solid var(--kvt-line);
            background: #f8fafc; color: #34445a; text-align: left; white-space: nowrap;
        }
        td { padding: 12px; border-bottom: 1px solid #e8edf4; vertical-align: top; }
        .numeric { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .muted { color: var(--kvt-muted); }
        .amount { display: block; color: var(--kvt-muted); font-size: .75rem; }
        .empty { padding: 36px 20px; text-align: center; color: var(--kvt-muted); }
        .footnote { margin: 0 0 8px; color: var(--kvt-muted); font-size: .8rem; }
        @media (max-width: 980px) {
            .page { padding: 12px; }
            .hero { padding: 18px; flex-direction: column; }
            .snapshot { text-align: left; }
            .toolbar, .stats { grid-template-columns: 1fr 1fr; }
            .hero-logo img { height: 28px; }
        }
        @media (max-width: 640px) {
            .toolbar, .stats { grid-template-columns: 1fr; }
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
                <p>Veiligheidsvoorraad, verkopen, werkorderverbruik en omloopsnelheid voor KVT en HVT. <?= consus_h($selectedVendorName) ?>.</p>
            </div>
        </div>
        <div class="snapshot">
            Nachtelijke cache
            <strong><?= consus_h(consus_format_generated_at((string) ($snapshot['generated_at'] ?? ''))) ?></strong>
        </div>
    </header>

    <?php if (!$hasCache): ?>
        <div class="notice">Er is nog geen cache. Deze pagina leest alleen het bestand van <strong>nightly.php</strong> en haalt hier geen Business Central-gegevens op.</div>
    <?php endif; ?>
    <?php if (($snapshot['errors'] ?? []) !== []): ?>
        <div class="notice error">De laatste nachtelijke controle was niet voor ieder bedrijf succesvol. Eerdere cijfers zijn waar mogelijk behouden.</div>
    <?php endif; ?>
    <section class="panel">
        <form class="toolbar" method="get">
            <div class="field">
                <label for="cost_center">Afdeling</label>
                <select id="cost_center" name="cost_center">
                    <option value="">Alle afdelingen</option>
                    <?php foreach ($departments as $department): ?>
                        <?php $departmentValue = $department === '' ? '__none__' : $department; ?>
                        <option value="<?= consus_h($departmentValue) ?>"<?= $costFilter === $departmentValue ? ' selected' : '' ?>><?= consus_h($department === '' ? '(geen afdeling)' : $department) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="vendor">Leverancier</label>
                <select id="vendor" name="vendor">
                    <option value="">Alle leveranciers</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <?php $number = (string) ($vendor['vendor_no'] ?? ''); if ($number === '') { continue; } ?>
                        <option value="<?= consus_h($number) ?>"<?= $vendorFilter === $number ? ' selected' : '' ?>><?= consus_h($vendor['vendor_name'] ?? $number) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="location">Locatie</label>
                <select id="location" name="location">
                    <option value="">Alle locaties</option>
                    <?php foreach ($locations as $location): ?>
                        <?php $locationValue = $location === '' ? '__none__' : $location; ?>
                        <option value="<?= consus_h($locationValue) ?>"<?= $locationFilter === $locationValue ? ' selected' : '' ?>><?= consus_h($location === '' ? '(zonder locatie)' : $location) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="company">Bedrijf</label>
                <select id="company" name="company">
                    <option value="">KVT en HVT</option>
                    <?php foreach (CONSUS_COMPANIES as $key => $company): ?>
                        <option value="<?= consus_h($key) ?>"<?= $companyFilter === $key ? ' selected' : '' ?>><?= consus_h($company['label'] ?? $key) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="filter-button" type="submit">Filteren</button>
        </form>
    </section>

    <section class="stats" aria-label="Samenvatting">
        <div class="stat">
            <span class="stat-label">Veiligheidsvoorraad</span>
            <strong class="stat-value"><?= consus_h(consus_format_qty((float) $summary['safety_stock'])) ?></strong>
        </div>
        <div class="stat">
            <span class="stat-label">Bestelpunt</span>
            <strong class="stat-value"><?= consus_h(consus_format_qty((float) $summary['reorder_point'])) ?></strong>
        </div>
        <div class="stat">
            <span class="stat-label">Totale voorraad</span>
            <strong class="stat-value"><?= consus_h(consus_format_qty((float) $summary['inventory'])) ?></strong>
            <span class="stat-sub"><?= (int) $summary['item_count'] ?> artikelen</span>
        </div>
        <div class="stat">
            <span class="stat-label">Verkoop deze maand</span>
            <strong class="stat-value"><?= consus_h(consus_format_qty(consus_period_total($sales, 'm', 'qty'))) ?></strong>
            <span class="stat-sub"><?= consus_h(consus_format_money(consus_period_total($sales, 'm', 'amount'))) ?></span>
        </div>
        <div class="stat">
            <span class="stat-label">WO-verbruik dit jaar</span>
            <strong class="stat-value"><?= consus_h(consus_format_qty(consus_period_total($consumption, 'y', 'qty'))) ?></strong>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Omloopsnelheid</h2>
            <p>Verkoophoeveelheid in de periode gedeeld door de voorraad van de gekozen locaties. Eigen is locatie KVT of HVT. EGT blijft leeg tot de locatiecodes in consus_config.php staan. Dropship telt niet mee.</p>
        </div>
        <?php if (!$hasCache): ?>
            <div class="empty">Nog geen omloopsnelheid. De cache is leeg.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Locatiegroep</th>
                            <th class="numeric">Maand</th>
                            <th class="numeric">Kwartaal</th>
                            <th class="numeric">Jaar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (CONSUS_TURNOVER_BUCKETS as $bucket): ?>
                            <tr>
                                <td><?= consus_h(CONSUS_BUCKETS[$bucket] ?? $bucket) ?></td>
                                <td class="numeric"><?= consus_h(consus_format_ratio($turnover[$bucket]['m'] ?? null)) ?></td>
                                <td class="numeric"><?= consus_h(consus_format_ratio($turnover[$bucket]['q'] ?? null)) ?></td>
                                <td class="numeric"><?= consus_h(consus_format_ratio($turnover[$bucket]['y'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Verkopen per maand</h2>
            <p>Verkoop-artikelposten, gesplitst op locatie. Eigen is <?= consus_h(implode(' en ', CONSUS_LOCATIONS_EIGEN)) ?>, dropship is <?= consus_h(implode(', ', CONSUS_LOCATIONS_DROPSHIP)) ?>. EGT-codes zijn nog leeg; andere locaties vallen in Overig.</p>
        </div>
        <?php if (!$hasCache): ?>
            <div class="empty">Nog geen verkopen. De cache is leeg.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Maand</th>
                            <?php foreach (CONSUS_BUCKETS as $label): ?>
                                <th class="numeric"><?= consus_h($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthKeys as $monthKey): ?>
                            <tr>
                                <td><?= consus_h(consus_month_label($monthKey)) ?></td>
                                <?php foreach (array_keys(CONSUS_BUCKETS) as $bucket): ?>
                                    <?php $cell = $sales[$bucket]['months'][$monthKey] ?? ['qty' => 0, 'amount' => 0]; ?>
                                    <td class="numeric">
                                        <?= consus_h(consus_format_qty((float) ($cell['qty'] ?? 0))) ?>
                                        <span class="amount"><?= consus_h(consus_format_money((float) ($cell['amount'] ?? 0))) ?></span>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2>Werkorderverbruik</h2>
            <p>Negative Adjmt. met documentnummer WO, plus Assembly Consumption. Hoeveelheid is positief bij verbruik.</p>
        </div>
        <?php if (!$hasCache): ?>
            <div class="empty">Nog geen verbruik. De cache is leeg.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <?php foreach (CONSUS_BUCKETS as $label): ?>
                                <th class="numeric"><?= consus_h($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (['m' => 'Maand', 'q' => 'Kwartaal', 'y' => 'Jaar'] as $period => $label): ?>
                            <tr>
                                <td><?= consus_h($label) ?></td>
                                <?php foreach (array_keys(CONSUS_BUCKETS) as $bucket): ?>
                                    <td class="numeric"><?= consus_h(consus_format_qty((float) ($consumption[$bucket][$period]['qty'] ?? 0))) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <p class="footnote">Cijfers komen uit de nachtelijke snapshot<?= $hasCache ? ' t/m ' . consus_h((string) ($windows['as_of'] ?? '')) : '' ?>. Kies eerst een afdeling; leverancier en locatie tonen daarna alleen wat bij die afdeling hoort. Elke locatie uit de cache is te kiezen. EGT-locaties vult Joost later in consus_config.php.</p>
</main>
</body>
</html>
