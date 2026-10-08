<?php

require_once __DIR__ . '/consus_retour.php';

/**
 * Sectie "Retourlijst": afdeling kiezen, regels beheren (gedeeld voor alle
 * gebruikers), kandidaten met detailmodal en garantiemarkering.
 *
 * @param array<int, array{value?:string,label?:string}> $departmentChoices
 */
function consus_retour_page_section(string $retourDepartment, string $costFilter, string $companyFilter, int $activeYear, string $csrf, array $departmentChoices, string $error = '', ?array $data = null, ?array $settings = null, ?string $today = null): void
{
    $data ??= consus_retour_read_data();
    $settings ??= consus_retour_settings_read();
    $today ??= consus_retour_today();
    $department = consus_retour_valid_department($retourDepartment);
    $h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Afdelingen uit het Consus-filter plus afdelingen die al regels hebben.
    $choices = [];
    foreach ($departmentChoices as $choice) {
        $key = consus_retour_valid_department((string) ($choice['value'] ?? ''));
        if ($key !== '' && !isset($choices[$key])) {
            $choices[$key] = (string) ($choice['label'] ?? $key);
        }
    }
    foreach (array_keys($settings['rules']) as $key) {
        $choices[(string) $key] ??= (string) $key;
    }
    if ($department !== '') {
        $choices[$department] ??= $department;
    }
    uksort($choices, 'strnatcasecmp');
    $departmentLabel = $department !== '' ? ($choices[$department] ?? $department) : '';
    $rules = consus_retour_rules_for_department($settings, $department);
    $result = $department !== '' && $rules !== [] ? consus_retour_candidates($data, $settings, $today, $department) : ['rows' => [], 'garantie' => [], 'skipped' => []];
    $allRows = array_merge($result['rows'], $result['garantie']);
    $generated = trim((string) ($data['generated_at'] ?? ''));
    $typeOptions = consus_retour_type_options($data, $settings);
    $hidden = static function () use ($h, $csrf, $department, $companyFilter, $costFilter, $activeYear): string {
        return '<input type="hidden" name="csrf" value="' . $h($csrf) . '">'
            . '<input type="hidden" name="retour_afdeling" value="' . $h($department) . '">'
            . '<input type="hidden" name="company" value="' . $h($companyFilter) . '">'
            . '<input type="hidden" name="cost_center" value="' . $h($costFilter) . '">'
            . '<input type="hidden" name="year" value="' . $h((string) $activeYear) . '">';
    };
    $exportQuery = http_build_query(['afdeling' => $department], '', '&', PHP_QUERY_RFC3986);
    ?>
    <style>
        .retour-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
        .retour-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .retour-pick { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin: 12px 0; }
        .retour-table { width: 100%; border-collapse: collapse; font-size: .84rem; }
        .retour-table th, .retour-table td { padding: 8px 10px; border-bottom: 1px solid var(--kvt-line); text-align: left; vertical-align: top; }
        .retour-table td.num { text-align: right; white-space: nowrap; }
        .retour-table tbody tr.retour-row { cursor: pointer; }
        .retour-table tbody tr.retour-row:hover td, .retour-table tbody tr.retour-row:focus td { background: #eef6fb; }
        .retour-table tr.status-controleren td { background: #fff7e0; }
        .retour-table tr.status-geen_bincontrole td { background: #f1f5f9; }
        .retour-table tr.status-garantie td { background: #f3f0ff; color: #4b3f72; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .74rem; font-weight: 700; }
        .badge-spoed { background: #ffe4e1; color: #9b1c1c; }
        .badge-voorraad { background: #e0f2fe; color: #075985; }
        .badge-alle { background: #eef2f7; color: #334155; }
        .retour-rules { width: 100%; border-collapse: collapse; font-size: .86rem; margin-bottom: 14px; }
        .retour-rules th, .retour-rules td { padding: 7px 8px; border-bottom: 1px solid var(--kvt-line); text-align: left; vertical-align: top; }
        .retour-hint { color: #8a5a00; font-size: .8rem; }
        .retour-form { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 12px; }
        .retour-form .field input, .retour-form .field select { width: 100%; }
        .retour-detail dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; margin: 0 0 12px; font-size: .88rem; }
        .retour-detail dt { color: var(--kvt-muted); }
        .retour-detail dd { margin: 0; }
        .link-button { border: 0; background: none; color: #00529b; cursor: pointer; padding: 0 4px; font: inherit; text-decoration: underline; }
        .link-button.danger { color: #9b1c1c; }
        #retour-detail-modal { width: min(680px, calc(100% - 32px)); }
    </style>
    <section class="panel" id="retour">
        <div class="panel-head retour-head">
            <div>
                <h2>Retourlijst</h2>
                <p>Onderdelen die nog terug kunnen naar de leverancier. Per afdeling bepalen regels (leverancier, type, termijn, minimaal bedrag) wat op de lijst komt. De termijn telt vanaf de factuurdatum; het minimum geldt per artikel per factuur. Alleen artikelen op voorraad, niet gereserveerd en zonder veiligheidsvoorraad. Klik op een regel voor details.</p>
            </div>
        </div>
        <?php if ($error !== ''): ?>
            <div class="notice error"><?= $error === 'regel' ? 'Niet opgeslagen: vul leverancier, termijn (1–' . CONSUS_RETOUR_MAX_WINDOW . ' dagen) en minimaal bedrag correct in.' : 'Niet opgeslagen. Probeer het opnieuw.' ?></div>
        <?php endif; ?>
        <form class="retour-pick" method="get" action="index.php#retour" id="retour-pick">
            <input type="hidden" name="company" value="<?= $h($companyFilter) ?>">
            <input type="hidden" name="cost_center" value="<?= $h($costFilter) ?>">
            <input type="hidden" name="year" value="<?= $h((string) $activeYear) ?>">
            <div class="field">
                <label for="retour_afdeling">Afdeling voor de retourlijst</label>
                <select id="retour_afdeling" name="retour_afdeling">
                    <option value="">Kies een afdeling…</option>
                    <?php foreach ($choices as $value => $label): ?>
                        <option value="<?= $h($value) ?>"<?= $department === (string) $value ? ' selected' : '' ?>><?= $h($label) ?><?= isset($settings['rules'][$value]) ? ' (' . count($settings['rules'][$value]) . ' regel' . (count($settings['rules'][$value]) === 1 ? '' : 's') . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="filter-button" type="submit">Toon</button>
            <?php if ($department !== ''): ?>
                <button type="button" class="export-link" id="retour-rules-open">Regels beheren</button>
                <?php if ($rules !== []): ?>
                    <a class="export-link" href="retour_export.php?<?= $h($exportQuery) ?>">Exporteer retourlijst (xlsx)</a>
                <?php endif; ?>
            <?php endif; ?>
        </form>

        <?php if ($department === ''): ?>
            <div class="empty">Kies een afdeling om de retourkandidaten te zien.</div>
        <?php elseif ($rules === []): ?>
            <div class="empty">Nog geen regels voor deze afdeling. Voeg via <strong>Regels beheren</strong> een regel toe (leverancier, type, termijn, minimaal bedrag).</div>
        <?php else: ?>
            <p class="muted">Regels voor <?= $h($departmentLabel) ?>:
                <?php foreach ($rules as $index => $rule): ?><?= $index > 0 ? '; ' : '' ?><?= $h(consus_retour_rule_label($rule)) ?><?php $hint = consus_retour_rule_hint($rule, $data); if ($hint !== ''): ?> <span class="retour-hint">(<?= $h($hint) ?>)</span><?php endif; ?><?php endforeach; ?>.
                <?= count($result['rows']) ?> kandidaten<?= $result['garantie'] !== [] ? ', ' . count($result['garantie']) . ' garantieregel(s) onderaan' : '' ?>. Gegevens van <?= $h($generated !== '' ? consus_format_dutch_datetime($generated) : 'nog niet opgehaald') ?>.</p>
            <?php foreach ((array) ($data['warnings'] ?? []) as $warning): ?>
                <div class="notice"><?= $h($warning) ?></div>
            <?php endforeach; ?>
            <?php if ($generated === ''): ?>
                <div class="empty">Gegevens volgen na de volgende nightly.</div>
            <?php elseif ($allRows === []): ?>
                <div class="empty">Geen retourkandidaten met deze regels.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="retour-table">
                        <thead>
                        <tr>
                            <th>Artikelnummer</th><th>Omschrijving</th><th>Leverancier</th><th>PO-nummer</th><th>Type</th><th>Leveranciersfactuur</th><th>Factuurdatum</th>
                            <th>Dagen over</th><th>Op voorraad</th><th>Aantal retour</th><th>Waarde</th><th>Garantie</th><th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allRows as $index => $row): ?>
                            <?php
                            $garantie = !empty($row['garantie']);
                            $badge = match ($row['account']) {
                                CONSUS_RETOUR_ACCOUNT_SPOED => 'badge-spoed',
                                CONSUS_RETOUR_ACCOUNT_VOORRAAD => 'badge-voorraad',
                                default => 'badge-alle',
                            };
                            ?>
                            <tr class="retour-row status-<?= $h($row['status']) ?>" tabindex="0" data-detail="retour-detail-<?= (int) $index ?>">
                                <td><?= $h($row['item']) ?></td>
                                <td><?= $h($row['description']) ?></td>
                                <td><?= $h($row['vendor']) ?></td>
                                <td><?= $h($row['po']) ?></td>
                                <td><span class="badge <?= $badge ?>"><?= $h(consus_retour_type_label($row['account'])) ?></span></td>
                                <td><?= $h($row['vendor_invoice'] !== '' ? $row['vendor_invoice'] : '—') ?><br><span class="muted"><?= $h($row['invoice']) ?></span></td>
                                <td><?= $h(consus_retour_dutch_date($row['document_date'])) ?></td>
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
                <?php foreach ($allRows as $index => $row): ?>
                    <?php $garantie = !empty($row['garantie']); ?>
                    <div class="retour-detail" id="retour-detail-<?= (int) $index ?>" hidden data-title="<?= $h($row['item'] . ' · ' . $row['description']) ?>">
                        <dl>
                            <dt>Artikel</dt><dd><?= $h($row['item']) ?> — <?= $h($row['description']) ?></dd>
                            <dt>Leverancier</dt><dd><?= $h($row['vendor']) ?></dd>
                            <dt>Leveranciersfactuur</dt><dd><?= $h($row['vendor_invoice'] !== '' ? $row['vendor_invoice'] : '—') ?></dd>
                            <dt>BC-factuur</dt><dd><?= $h($row['invoice']) ?></dd>
                            <dt>Inkooporder</dt><dd><?= $h($row['po'] !== '' ? $row['po'] : '—') ?></dd>
                            <dt>Factuurdatum</dt><dd><?= $h(consus_retour_dutch_date($row['document_date'])) ?> (<?= (int) $row['days_since'] ?> dagen geleden)</dd>
                            <dt>Uiterlijk retour</dt><dd><?= $h(consus_retour_dutch_date($row['deadline'])) ?> (nog <?= (int) $row['days_left'] ?> dagen)</dd>
                            <dt>Type</dt><dd><?= $h(consus_retour_type_label($row['account'])) ?></dd>
                            <dt>Gefactureerd</dt><dd><?= $h(consus_retour_qty((float) $row['quantity'])) ?> × <?= $h(consus_retour_money((float) $row['unit_cost'])) ?> = <?= $h(consus_retour_money((float) $row['amount'])) ?></dd>
                            <dt>Aantal retour</dt><dd><?= $h(consus_retour_qty($garantie ? 0.0 : (float) $row['return_qty'])) ?> (waarde <?= $h(consus_retour_money($garantie ? 0.0 : (float) $row['value'])) ?>)</dd>
                            <dt>Boekvoorraad</dt><dd><?php $parts = []; foreach ((array) $row['stock_by_location'] as $loc => $qty) { if ((float) $qty != 0.0) { $parts[] = $loc . ': ' . consus_retour_qty((float) $qty); } } ?><?= $h($parts !== [] ? implode(', ', $parts) : '0') ?></dd>
                            <dt>Bin-inhoud</dt><dd><?php if (!is_array($row['bin_by_location'])): ?>niet beschikbaar<?php else: $parts = []; foreach ($row['bin_by_location'] as $loc => $qty) { $parts[] = $loc . ': ' . consus_retour_qty((float) $qty); } ?><?= $h($parts !== [] ? implode(', ', $parts) : 'geen') ?><?php endif; ?></dd>
                            <dt>Gereserveerd</dt><dd><?= $h(consus_retour_qty((float) $row['reserved'])) ?><?= (float) $row['reserved_return'] > 0 ? ' (waarvan ' . $h(consus_retour_qty((float) $row['reserved_return'])) . ' al op een retourorder)' : '' ?></dd>
                            <dt>Veiligheidsvoorraad</dt><dd><?= $h(consus_retour_qty((float) $row['safety_stock'])) ?></dd>
                            <dt>Status</dt><dd><?= $h($row['status_label']) ?></dd>
                            <dt>Waarom</dt><dd><?= $h(consus_retour_reason($row)) ?></dd>
                        </dl>
                        <form method="post" action="retour_settings.php">
                            <?= $hidden() ?>
                            <input type="hidden" name="action" value="garantie">
                            <input type="hidden" name="invoice" value="<?= $h($row['invoice']) ?>">
                            <input type="hidden" name="item" value="<?= $h($row['item']) ?>">
                            <input type="hidden" name="orders" value="<?= $h(implode(',', (array) $row['order_list'])) ?>">
                            <input type="hidden" name="garantie" value="<?= $garantie ? '0' : '1' ?>">
                            <button class="filter-button" type="submit"><?= $garantie ? 'Garantiemarkering opheffen' : 'Markeer als garantie' ?></button>
                            <span class="muted">Geldt voor alle gebruikers.</span>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <dialog id="retour-detail-modal">
        <div class="modal-head">
            <div><h2 id="retour-detail-title">Retourkandidaat</h2></div>
            <button type="button" class="modal-close" data-close aria-label="Sluiten">×</button>
        </div>
        <div class="modal-body" id="retour-detail-body"></div>
    </dialog>

    <?php if ($department !== ''): ?>
    <dialog id="retour-rules">
        <div class="modal-head">
            <div>
                <h2>Regels retourlijst</h2>
                <p class="muted">Afdeling <?= $h($departmentLabel) ?>. Gedeeld voor alle gebruikers.</p>
            </div>
            <button type="button" class="modal-close" data-close aria-label="Sluiten">×</button>
        </div>
        <div class="modal-body">
            <?php if ($rules === []): ?>
                <p class="muted">Nog geen regels voor deze afdeling.</p>
            <?php else: ?>
                <table class="retour-rules">
                    <thead><tr><th>Leverancier</th><th>Type</th><th>Termijn</th><th>Minimaal bedrag</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rules as $rule): ?>
                        <?php $hint = consus_retour_rule_hint($rule, $data); ?>
                        <tr>
                            <td><?= $h($rule['vendor']) ?></td>
                            <td><?= $h(consus_retour_type_label($rule['type'])) ?></td>
                            <td><?= (int) $rule['window'] ?> dagen</td>
                            <td><?= $h(consus_retour_money((float) $rule['min_value'])) ?></td>
                            <td>
                                <button type="button" class="link-button" data-edit-rule
                                    data-id="<?= $h($rule['id']) ?>" data-vendor="<?= $h($rule['vendor']) ?>" data-type="<?= $h($rule['type']) ?>"
                                    data-window="<?= (int) $rule['window'] ?>" data-min="<?= $h(number_format((float) $rule['min_value'], 2, '.', '')) ?>">Bewerken</button>
                                <form method="post" action="retour_settings.php" class="retour-delete" style="display:inline" data-label="<?= $h(consus_retour_rule_label($rule)) ?>">
                                    <?= $hidden() ?>
                                    <input type="hidden" name="action" value="delete_rule">
                                    <input type="hidden" name="rule_id" value="<?= $h($rule['id']) ?>">
                                    <button type="submit" class="link-button danger">Verwijderen</button>
                                </form>
                            </td>
                        </tr>
                        <?php if ($hint !== ''): ?>
                            <tr><td colspan="5" class="retour-hint"><?= $h($hint) ?></td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <h3 id="retour-rule-form-title">Regel toevoegen</h3>
            <form method="post" action="retour_settings.php" id="retour-rule-form">
                <?= $hidden() ?>
                <input type="hidden" name="action" value="save_rule">
                <input type="hidden" name="rule_id" value="">
                <div class="retour-form">
                    <div class="field"><label for="retour-vendor">Leverancier (leveranciersnr.)</label><input id="retour-vendor" name="vendor" required maxlength="20" placeholder="bijv. 90101"></div>
                    <div class="field"><label for="retour-type">Type</label>
                        <select id="retour-type" name="type">
                            <?php foreach ($typeOptions as $type): ?>
                                <option value="<?= $h($type) ?>"><?= $h(consus_retour_type_label($type)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field"><label for="retour-window">Termijn (dagen)</label><input id="retour-window" name="window" type="number" min="1" max="<?= CONSUS_RETOUR_MAX_WINDOW ?>" required></div>
                    <div class="field"><label for="retour-min">Minimaal bedrag (€)</label><input id="retour-min" name="min_value" type="number" min="0" step="0.01" required></div>
                </div>
                <p class="muted">Type "Alle" geldt voor alle facturen van de leverancier. Spoed (57401) = inkooporder met EGT aan en CSV uit; elke andere combinatie is voorraad (57420).</p>
                <div class="retour-actions">
                    <button class="filter-button" type="submit" id="retour-rule-submit">Regel toevoegen</button>
                    <button class="export-link" type="button" id="retour-rule-reset" hidden>Annuleren</button>
                </div>
            </form>
        </div>
    </dialog>
    <?php endif; ?>
    <script>
    (function () {
        function wire(dialog) {
            if (!dialog) { return; }
            dialog.querySelectorAll('[data-close]').forEach(function (button) {
                button.addEventListener('click', function () { dialog.close(); });
            });
            dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
        }
        var pick = document.getElementById('retour-pick');
        var select = document.getElementById('retour_afdeling');
        if (pick && select) { select.addEventListener('change', function () { pick.submit(); }); }

        var detail = document.getElementById('retour-detail-modal');
        wire(detail);
        function openDetail(row) {
            var source = document.getElementById(row.getAttribute('data-detail'));
            if (!source || !detail || typeof detail.showModal !== 'function') { return; }
            document.getElementById('retour-detail-title').textContent = source.getAttribute('data-title') || 'Retourkandidaat';
            var body = document.getElementById('retour-detail-body');
            body.innerHTML = '';
            var clone = source.cloneNode(true);
            clone.hidden = false;
            clone.removeAttribute('id');
            body.appendChild(clone);
            detail.showModal();
        }
        document.querySelectorAll('tr.retour-row').forEach(function (row) {
            row.addEventListener('click', function () { openDetail(row); });
            row.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openDetail(row); }
            });
        });

        var rules = document.getElementById('retour-rules');
        var open = document.getElementById('retour-rules-open');
        if (!rules || !open || typeof rules.showModal !== 'function') { return; }
        wire(rules);
        open.addEventListener('click', function () { rules.showModal(); });
        var form = document.getElementById('retour-rule-form');
        var title = document.getElementById('retour-rule-form-title');
        var submit = document.getElementById('retour-rule-submit');
        var reset = document.getElementById('retour-rule-reset');
        function setForm(id, vendor, type, windowDays, min) {
            form.elements.rule_id.value = id;
            form.elements.vendor.value = vendor;
            var typeSelect = form.elements.type;
            if (![].some.call(typeSelect.options, function (o) { return o.value === type; })) {
                var option = document.createElement('option');
                option.value = type; option.textContent = type; typeSelect.appendChild(option);
            }
            typeSelect.value = type;
            form.elements.window.value = windowDays;
            form.elements.min_value.value = min;
            title.textContent = id ? 'Regel bewerken' : 'Regel toevoegen';
            submit.textContent = id ? 'Wijziging opslaan' : 'Regel toevoegen';
            reset.hidden = !id;
        }
        rules.querySelectorAll('[data-edit-rule]').forEach(function (button) {
            button.addEventListener('click', function () {
                setForm(button.dataset.id, button.dataset.vendor, button.dataset.type, button.dataset.window, button.dataset.min);
                form.elements.vendor.focus();
            });
        });
        reset.addEventListener('click', function () { setForm('', '', '', '', ''); });
        rules.querySelectorAll('form.retour-delete').forEach(function (deleteForm) {
            deleteForm.addEventListener('submit', function (event) {
                if (!window.confirm('Regel ' + (deleteForm.dataset.label || '') + ' verwijderen? Dit geldt voor alle gebruikers.')) { event.preventDefault(); }
            });
        });
    })();
    </script>
    <?php
}
