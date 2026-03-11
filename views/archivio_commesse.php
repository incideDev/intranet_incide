<?php
if (!checkPermissionOrWarn('view_commesse'))
    return;

global $database;

// Archivio: solo stato 'Chiusa'
// Stato canonico: 'Chiusa' (oppure commesse con data_chiusura valorizzata)
$commesse = $database->query("
    SELECT codice, oggetto, cliente, stato, responsabile_commessa,
           data_chiusura, valore_prodotto, business_unit
    FROM elenco_commesse
    WHERE TRIM(UPPER(stato)) = 'CHIUSA'
       OR (data_chiusura IS NOT NULL AND data_chiusura != '0000-00-00')
", [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

// Applica fixMojibake ai campi che potrebbero contenere mojibake
foreach ($commesse as &$c) {
    $c['codice'] = fixMojibake($c['codice'] ?? '');
    $c['oggetto'] = fixMojibake($c['oggetto'] ?? '');
    $c['cliente'] = fixMojibake($c['cliente'] ?? '');
    $c['stato'] = fixMojibake($c['stato'] ?? '');
    $c['responsabile_commessa'] = fixMojibake($c['responsabile_commessa'] ?? '');
    $c['business_unit'] = fixMojibake($c['business_unit'] ?? '');
}

// Mappa user_id => Nominativo (una sola query)
$personale = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
$map = [];
foreach ($personale as $p) {
    $map[(int) $p['user_id']] = $p['Nominativo'];
}

// Pre-calcolo nomi responsabili e raccolta valori unici per filtri
$uniqueBU = [];
$uniquePM = [];
$commesseProcessed = [];

foreach ($commesse as $c) {
    $responsabile_nome = '—';
    $raw = $c['responsabile_commessa'] ?? null;
    if ($raw !== null && $raw !== '') {
        if (is_numeric($raw)) {
            $responsabile_nome = $map[(int) $raw] ?? '—';
        } else {
            $responsabile_nome = $raw;
        }
        $responsabile_nome = fixMojibake($responsabile_nome);
    }
    $c['_pm_nome'] = $responsabile_nome;

    $bu = trim($c['business_unit'] ?? '');

    if ($bu !== '') $uniqueBU[$bu] = true;
    if ($responsabile_nome !== '—') $uniquePM[$responsabile_nome] = true;

    $commesseProcessed[] = $c;
}

ksort($uniqueBU);
ksort($uniquePM);
?>
<div class="main-container">
    <?php renderPageTitle("Archivio Commesse", "#999999"); ?>

    <div class="table-top-filters" data-table="archivioCommesseTable" data-mode="archivio">
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterBUArchivio">BU</label>
            <select class="table-top-filters__select" id="filterBUArchivio" data-filter="bu">
                <option value="">Tutte</option>
                <?php foreach ($uniqueBU as $b => $_): ?>
                    <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterPMArchivio">PM</label>
            <select class="table-top-filters__select" id="filterPMArchivio" data-filter="pm">
                <option value="">Tutti</option>
                <?php foreach ($uniquePM as $pm => $_): ?>
                    <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterScadenzaArchivio">Chiusura</label>
            <select class="table-top-filters__select" id="filterScadenzaArchivio" data-filter="scadenza">
                <option value="tutte">Tutte</option>
                <option value="ultimo30">Ultimi 30 giorni</option>
                <option value="ultimo90">Ultimi 90 giorni</option>
                <option value="senza">Senza data</option>
            </select>
        </div>
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterValoreMinArchivio">Valore min</label>
            <input class="table-top-filters__input" type="number" id="filterValoreMinArchivio" data-filter="valoreMin" placeholder="0" min="0" step="1000">
        </div>
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterValoreMaxArchivio">Valore max</label>
            <input class="table-top-filters__input" type="number" id="filterValoreMaxArchivio" data-filter="valoreMax" placeholder="Illimitato" min="0" step="1000">
        </div>
        <div class="table-top-filters__group table-top-filters__group--action">
            <button class="table-top-filters__reset" data-filter="reset" type="button">Reset filtri</button>
        </div>
    </div>

    <table class="table table-filterable table--modern" id="archivioCommesseTable">
        <thead>
            <tr>
                <th class="col-actions">Azioni</th>
                <th class="col-code">Codice</th>
                <th class="col-description">Commessa</th>
                <th class="col-bu">BU</th>
                <th class="col-person">PM</th>
                <th class="col-date">Chiusura</th>
                <th class="col-amount">Valore</th>
                <th class="col-status">Stato</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($commesseProcessed as $c):
                $responsabile_nome = $c['_pm_nome'];

                // Verifico se esiste la bacheca/Workspace
                $hasBacheca = $database->query(
                    "SELECT id FROM commesse_bacheche WHERE tabella = ? LIMIT 1",
                    [$c['codice']],
                    __FILE__
                )->fetchColumn();

                // Helper canonici da core/functions.php
                $initials = getInitials($responsabile_nome);
                $pmProfileImg = getProfileImage($responsabile_nome, 'nominativo', 'assets/images/default_profile.png');
                $statoPillClass = 'table-pill--' . getStatoPillClass($c['stato']);
                $detailUrl = 'index.php?section=commesse&page=commessa&tabella=' . urlencode($c['codice']) . '&view=dati';

                // Formattazione data chiusura (usa data_chiusura se presente, altrimenti "—")
                $dataChiusura = '—';
                $dataChiusuraRaw = '';
                if (!empty($c['data_chiusura']) && $c['data_chiusura'] !== '0000-00-00') {
                    $dt = DateTime::createFromFormat('Y-m-d', $c['data_chiusura']);
                    if ($dt) {
                        $dataChiusura = $dt->format('d/m/Y');
                        $dataChiusuraRaw = $dt->format('Y-m-d');
                    }
                }

                // Valore raw per dataset
                $valoreRaw = '';
                if (!empty($c['valore_prodotto']) && is_numeric($c['valore_prodotto'])) {
                    $valoreRaw = number_format(floatval($c['valore_prodotto']), 2, '.', '');
                }

                // Formattazione valore display
                $valore = '—';
                if ($valoreRaw !== '' && floatval($valoreRaw) > 0) {
                    $valore = number_format(floatval($valoreRaw), 2, ',', '.') . ' €';
                }

                // Business Unit (pill)
                $bu = trim($c['business_unit'] ?? '');
                $statoRaw = trim($c['stato'] ?? '');
                ?>
                <tr class="table-row-clickable"
                    data-href="<?= htmlspecialchars($detailUrl) ?>"
                    data-scadenza="<?= htmlspecialchars($dataChiusuraRaw) ?>"
                    data-valore="<?= htmlspecialchars($valoreRaw) ?>"
                    data-bu="<?= htmlspecialchars($bu) ?>"
                    data-pm="<?= htmlspecialchars($responsabile_nome !== '—' ? $responsabile_nome : '') ?>"
                    data-stato="<?= htmlspecialchars($statoRaw) ?>">
                    <td class="col-actions">
                        <?php if ($hasBacheca): ?>
                            <a href="<?= htmlspecialchars($detailUrl) ?>"
                                class="table-action-btn table-action-btn--view" data-tooltip="Apri Workspace">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                    <polyline points="15 3 21 3 21 9"></polyline>
                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                </svg>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-code">
                        <a href="<?= htmlspecialchars($detailUrl) ?>" class="table-link"><?= htmlspecialchars($c['codice'] ?? '') ?></a>
                    </td>
                    <td class="col-description">
                        <div class="cell-stack">
                            <span class="cell-primary"><?= htmlspecialchars($c['oggetto'] ?? '—') ?></span>
                            <span class="cell-secondary"><?= htmlspecialchars($c['cliente'] ?? '') ?></span>
                        </div>
                    </td>
                    <td class="col-bu">
                        <?php if ($bu !== ''): ?>
                            <span class="table-pill table-pill--info"><?= htmlspecialchars($bu) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-person">
                        <div class="table-person">
                            <img class="table-avatar table-avatar--sm"
                                 src="<?= htmlspecialchars($pmProfileImg) ?>"
                                 alt="<?= htmlspecialchars($initials) ?>"
                                 onerror="this.outerHTML='<span class=\'table-avatar table-avatar--sm\'><?= htmlspecialchars($initials) ?></span>'">
                            <span class="table-person__name"><?= htmlspecialchars($responsabile_nome) ?></span>
                        </div>
                    </td>
                    <td class="col-date"><?= htmlspecialchars($dataChiusura) ?></td>
                    <td class="col-amount"><?= htmlspecialchars($valore) ?></td>
                    <td class="col-status">
                        <span class="table-pill <?= $statoPillClass ?>"><?= htmlspecialchars($c['stato'] ?? '—') ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="assets/js/commesse_table_filters.js" defer></script>
<script>
    // Click riga -> naviga via data-href (pattern unico)
    document.addEventListener('DOMContentLoaded', function() {
        const table = document.getElementById('archivioCommesseTable');
        if (!table) return;

        table.addEventListener('click', function(e) {
            if (e.target.closest('a, button')) return;

            const row = e.target.closest('tr.table-row-clickable');
            if (!row) return;

            const href = row.dataset.href;
            if (href) {
                window.location.href = href;
            }
        });
    });
</script>
