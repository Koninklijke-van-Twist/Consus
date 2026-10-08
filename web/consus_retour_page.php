<?php

require_once __DIR__ . '/consus_retour.php';

/**
 * Sectie "Perkins-retourkandidaten" met tandwiel-modal voor de regel per afdeling.
 *
 * @param array<int, array{value?:string,label?:string}> $departmentChoices
 */
function consus_retour_page_section(string $costFilter, string $companyFilter, int $activeYear, string $csrf, array $departmentChoices, ?array $data = null, ?array $settings = null, ?string $today = null): void
{
    $data ??= consus_retour_read_data();
    $settings ??= consus_retour_settings_read();
    $today ??= consus_retour_today();
    $result = consus_retour_candidates($data, $settings, $today, $costFilter);
    $rule = consus_retour_rule_for_department($settings, $costFilter);
    $departmentKey = consus_retour_department_key($costFilter);
    $hasOwnRule = isset($settings['departments'][$departmentKey]) && $departmentKey !== CONSUS_RETOUR_DEFAULT_KEY;
    $departmentLabel = 'alle afdelingen (standaard)';
    foreach ($departmentChoices as $choice) {
        if ((string) ($choice['value'] ?? '') === $costFilter && $costFilter !== '') {
            $departmentLabel = $costFilter === '__none__' ? '(geen afdeling)' : (string) ($choice['label'] ?? $costFilter);
        }
    }
    $generated = trim((string) ($data['generated_at'] ?? ''));
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $exportQuery = http_build_query(['cost_center' => $costFilter], '', '&', PHP_QUERY_RFC3986);
    $allRows = array_merge($result['rows'], $result['garantie']);
    ?>
    <style>
        .retour-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
        .gear-button { border: 1px solid var(--kvt-line); background: #fff; border-radius: 10px; min-width: 42px; min-height: 42px; font-size: 1.25rem; cursor: pointer; }
        .retour-actions { display: flex; gap: 8px; align-items: center; }
        .retour-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        .retour-table th, .retour-table td { padding: 8px 10px; border-bottom: 1px solid var(--kvt-line); text-align: left; vertical-align: top; }
        .retour-table td.num { text-align: right; white-space: nowrap; }
        .retour-table tr.status-controleren td { background: #fff7e0; }
        .retour-table tr.status-geen_bincontrole td { background: #f1f5f9; }
        .retour-table tr.status-garantie td { background: #f3f0ff; color: #4b3f72; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .74rem; font-weight: 700; }
        .badge-spoed { background: #ffe4e1; color: #9b1c1c; }
        .badge-voorraad { background: #e0f2fe; color: #075985; }
        #retour-settings form { display: grid; gap: 12px; }
        #retour-settings .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        #retour-settings textarea { width: 100%; min-height: 70px; padding: 9px 12px; border: 1px solid var(--kvt-line); border-radius: 10px; }
    </style>
    <section class="panel" id="retour">
        <div class="panel-head retour-head">
            <div>
                <h2>Perkins-retourkandidaten</h2>
                <p>Leverancier <?= $h(CONSUS_RETOUR_VENDOR) ?> (Hunter van Twist), gefactureerd vanaf <?= $h(consus_retour_dutch_date($rule['start_date'])) ?>. Spoed (57401) binnen <?= (int) $rule['window_spoed'] ?> dagen, voorraad (57420) binnen <?= (int) $rule['window_voorraad'] ?> dagen na factuurdatum, minimaal <?= $h(consus_retour_money($rule['min_value'])) ?> per artikel per factuur. Alleen artikelen op voorraad, niet gereserveerd en zonder veiligheidsvoorraad. Regel: <?= $h($departmentLabel) ?><?= $hasOwnRule ? ' (eigen instelling)' : '' ?>.</p>
                <p><?= count($result['rows']) ?> kandidaten<?= $result['garantie'] !== [] ? ', ' . count($result['garantie']) . ' garantieregel(s) apart gemarkeerd' : '' ?>. Gegevens van <?= $h($generated !== '' ? consus_format_dutch_datetime($generated) : 'nog niet opgehaald') ?>.</p>
            </div>
            <div class="retour-actions">
                <a class="export-link" href="retour_export.php?<?= $h($exportQuery) ?>">Exporteer retourlijst (xlsx)</a>
                <button type="button" class="gear-button" id="retour-gear" aria-label="Instellingen retourregel" title="Instellingen retourregel">⚙</button>
            </div>
        </div>
        <?php foreach ((array) ($data['warnings'] ?? []) as $warning): ?>
            <div class="notice"><?= $h($warning) ?></div>
        <?php endforeach; ?>
        <?php if ($generated === ''): ?>
            <div class="empty">De retourlijst wordt opgebouwd bij de volgende nachtrun.</div>
        <?php elseif ($allRows === []): ?>
            <div class="empty">Geen retourkandidaten met deze instellingen.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="retour-table">
                    <thead>
                    <tr>
                        <th>Artikelnummer</th><th>Omschrijving</th><th>PO-nummer</th><th>Account</th><th>Perkins-factuur</th><th>Factuurdatum</th>
                        <th>Dagen sinds factuur</th><th>Dagen over</th><th>Op voorraad</th><th>Aantal retour</th><th>Waarde</th><th>Garantie</th><th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allRows as $row): ?>
                        <?php $garantie = !empty($row['garantie']); $spoed = $row['account'] === CONSUS_RETOUR_ACCOUNT_SPOED; ?>
                        <tr class="status-<?= $h($row['status']) ?>">
                            <td><?= $h($row['item']) ?></td>
                            <td><?= $h($row['description']) ?></td>
                            <td><?= $h($row['po']) ?></td>
                            <td><span class="badge <?= $spoed ? 'badge-spoed' : 'badge-voorraad' ?>"><?= $h(consus_retour_account_label($row['account'])) ?></span></td>
                            <td><?= $h($row['vendor_invoice'] !== '' ? $row['vendor_invoice'] : '—') ?><br><span class="muted"><?= $h($row['invoice']) ?></span></td>
                            <td><?= $h(consus_retour_dutch_date($row['document_date'])) ?></td>
                            <td class="num"><?= (int) $row['days_since'] ?></td>
                            <td class="num"><?= (int) $row['days_left'] ?><br><span class="muted">t/m <?= $h(consus_retour_dutch_date($row['deadline'])) ?></span></td>
                            <td class="num"><?= $h(consus_retour_qty((float) $row['stock'])) ?><?= (float) $row['reserved'] > 0 ? '<br><span class="muted">' . $h(consus_retour_qty((float) $row['reserved'])) . ' gereserveerd</span>' : '' ?></td>
                            <td class="num"><?= $h(consus_retour_qty($garantie ? (float) $row['quantity'] : (float) $row['return_qty'])) ?></td>
                            <td class="num"><?= $h(consus_retour_money((float) $row['value'])) ?></td>
                            <td><?= $garantie ? '<strong>Garantie</strong>' : 'Nee' ?></td>
                            <td><?= $h($row['status_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <dialog id="retour-settings">
        <div class="modal-head">
            <div>
                <h2>Retourregel Perkins</h2>
                <p class="muted">Voor: <?= $h($departmentLabel) ?>. Kies bovenaan een afdeling om die apart in te stellen.</p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Sluiten">×</button>
        </div>
        <div class="modal-body">
            <form method="post" action="retour_settings.php">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="retour_department" value="<?= $h($costFilter) ?>">
                <input type="hidden" name="company" value="<?= $h($companyFilter) ?>">
                <input type="hidden" name="cost_center" value="<?= $h($costFilter) ?>">
                <input type="hidden" name="year" value="<?= $h((string) $activeYear) ?>">
                <div class="grid2">
                    <div class="field"><label for="retour-min">Minimumwaarde per artikel per factuur (€)</label><input id="retour-min" name="min_value" type="number" min="0" step="0.01" value="<?= $h(number_format($rule['min_value'], 2, '.', '')) ?>"></div>
                    <div class="field"><label for="retour-start">Alleen facturen vanaf</label><input id="retour-start" name="start_date" type="date" value="<?= $h($rule['start_date']) ?>"></div>
                    <div class="field"><label for="retour-spoed">Termijn spoed 57401 (dagen)</label><input id="retour-spoed" name="window_spoed" type="number" min="0" max="3650" value="<?= (int) $rule['window_spoed'] ?>"></div>
                    <div class="field"><label for="retour-voorraad">Termijn voorraad 57420 (dagen)</label><input id="retour-voorraad" name="window_voorraad" type="number" min="0" max="3650" value="<?= (int) $rule['window_voorraad'] ?>"></div>
                </div>
                <div class="field">
                    <label for="retour-garantie">Garantieorders (geen retourkandidaat, voor alle afdelingen)</label>
                    <textarea id="retour-garantie" name="garantie_orders" placeholder="PO-nummers, gescheiden door komma of nieuwe regel"><?= $h(implode("\n", $settings['garantie_orders'])) ?></textarea>
                </div>
                <p class="muted">Spoed = inkooporder met EGT aan en CSV uit; elke andere combinatie is voorraad.</p>
                <div class="retour-actions">
                    <button class="filter-button" type="submit">Opslaan</button>
                    <?php if ($hasOwnRule): ?>
                        <button class="export-link" type="submit" name="reset" value="1">Terug naar standaard</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </dialog>
    <script>
    (function () {
        var dialog = document.getElementById('retour-settings');
        var gear = document.getElementById('retour-gear');
        if (!dialog || !gear || typeof dialog.showModal !== 'function') { return; }
        gear.addEventListener('click', function () { dialog.showModal(); });
        dialog.querySelectorAll('[data-close]').forEach(function (button) {
            button.addEventListener('click', function () { dialog.close(); });
        });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
    })();
    </script>
    <?php
}
