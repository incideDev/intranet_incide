<?php
if (!checkPermissionOrWarn('view_commesse'))
    return;

global $database;

// Commesse ATTIVE: solo stato 'Aperta'
// Stato canonico: 'Aperta' (escludi 'Chiusa')
$commesse = $database->query("
    SELECT codice, oggetto, cliente, stato, responsabile_commessa,
           data_fine_prevista, valore_prodotto, business_unit
    FROM elenco_commesse
    WHERE TRIM(UPPER(stato)) = 'APERTA'
      AND (data_chiusura IS NULL OR data_chiusura = '0000-00-00')
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
    <?php renderPageTitle("Elenco Commesse", "#cccccc"); ?>

    <div class="table-top-filters" data-table="elencoCommesseTable" data-mode="elenco">
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterBUElenco">BU</label>
            <select class="table-top-filters__select" id="filterBUElenco" data-filter="bu">
                <option value="">Tutte</option>
                <?php foreach ($uniqueBU as $b => $_): ?>
                    <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterPMElenco">PM</label>
            <select class="table-top-filters__select" id="filterPMElenco" data-filter="pm">
                <option value="">Tutti</option>
                <?php foreach ($uniquePM as $pm => $_): ?>
                    <option value="<?= htmlspecialchars($pm) ?>"><?= htmlspecialchars($pm) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterScadenzaElenco">Scadenza</label>
            <select class="table-top-filters__select" id="filterScadenzaElenco" data-filter="scadenza">
                <option value="tutte">Tutte</option>
                <option value="scadute">Scadute</option>
                <option value="entro7">Entro 7 giorni</option>
                <option value="entro30">Entro 30 giorni</option>
                <option value="senza">Senza data</option>
            </select>
        </div>
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterValoreMinElenco">Valore min</label>
            <input class="table-top-filters__input" type="number" id="filterValoreMinElenco" data-filter="valoreMin" placeholder="0" min="0" step="1000">
        </div>
        <div class="table-top-filters__group">
            <label class="table-top-filters__label" for="filterValoreMaxElenco">Valore max</label>
            <input class="table-top-filters__input" type="number" id="filterValoreMaxElenco" data-filter="valoreMax" placeholder="Illimitato" min="0" step="1000">
        </div>
        <div class="table-top-filters__group table-top-filters__group--action">
            <button class="table-top-filters__reset" data-filter="reset" type="button">Reset filtri</button>
        </div>
    </div>

    <table class="table table-filterable table--modern" id="elencoCommesseTable">
        <thead>
            <tr>
                <th class="col-actions">Azioni</th>
                <th class="col-code">Codice</th>
                <th class="col-description">Commessa</th>
                <th class="col-bu">BU</th>
                <th class="col-person">PM</th>
                <th class="col-date">Fine Prev.</th>
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

                // Formattazione data fine prevista
                $dataFinePrev = '—';
                $dataFinePrevRaw = '';
                if (!empty($c['data_fine_prevista']) && $c['data_fine_prevista'] !== '0000-00-00') {
                    $dt = DateTime::createFromFormat('Y-m-d', $c['data_fine_prevista']);
                    if ($dt) {
                        $dataFinePrev = $dt->format('d/m/Y');
                        $dataFinePrevRaw = $dt->format('Y-m-d');
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
                    data-has-workspace="<?= $hasBacheca ? '1' : '0' ?>"
                    data-codice="<?= htmlspecialchars($c['codice']) ?>"
                    data-scadenza="<?= htmlspecialchars($dataFinePrevRaw) ?>"
                    data-valore="<?= htmlspecialchars($valoreRaw) ?>"
                    data-bu="<?= htmlspecialchars($bu) ?>"
                    data-pm="<?= htmlspecialchars($responsabile_nome !== '—' ? $responsabile_nome : '') ?>"
                    data-stato="<?= htmlspecialchars($statoRaw) ?>">
                    <td class="col-actions">
                        <?php if ($hasBacheca): ?>
                            <a href="<?= htmlspecialchars($detailUrl) ?>"
                                class="table-action-btn table-action-btn--open" data-tooltip="Apri Workspace">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                    <polyline points="15 3 21 3 21 9"></polyline>
                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                </svg>
                            </a>
                        <?php else: ?>
                            <button class="table-action-btn table-action-btn--start" data-tooltip="Avvia Workspace"
                                onclick="avviaCommessa('<?= htmlspecialchars($c['codice']) ?>')">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                    fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <polygon points="5 3 19 12 5 21 5 3"></polygon>
                                </svg>
                            </button>
                        <?php endif; ?>
                    </td>
                    <td class="col-code">
                        <?php if ($hasBacheca): ?>
                            <a href="<?= htmlspecialchars($detailUrl) ?>" class="table-link"><?= htmlspecialchars($c['codice'] ?? '') ?></a>
                        <?php else: ?>
                            <a href="#" class="table-link" onclick="event.preventDefault();event.stopPropagation();avviaCommessa('<?= htmlspecialchars($c['codice']) ?>')"><?= htmlspecialchars($c['codice'] ?? '') ?></a>
                        <?php endif; ?>
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
                    <td class="col-date"><?= htmlspecialchars($dataFinePrev) ?></td>
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
    function avviaCommessa(codice) {
        const msg = `Vuoi avviare la workspace per la commessa <b>${window.escapeHtml(codice)}</b>?`;

        showConfirm(msg, async () => {
            try {
                const res = await customFetch('commesse', 'avviaCommessa', { codice_commessa: codice });

                if (res && res.success) {
                    showToast('Workspace avviato con successo.');
                    setTimeout(() => {
                        window.location.href = 'index.php?section=commesse&page=commessa&tabella=' + encodeURIComponent(codice) + '&view=dati';
                    }, 400);
                } else {
                    showToast('Errore: ' + (res?.message || 'impossibile avviare la workspace'), 'error');
                }
            } catch (e) {
                console.error(e);
                showToast('Errore di rete durante l\'avvio della workspace.', 'error');
            }
        }, { allowHtml: true });
    }

    // Click riga -> naviga via data-href, oppure chiedi conferma avvio workspace
    document.addEventListener('DOMContentLoaded', function() {
        const table = document.getElementById('elencoCommesseTable');
        if (!table) return;

        table.addEventListener('click', function(e) {
            if (e.target.closest('a, button')) return;

            const row = e.target.closest('tr.table-row-clickable');
            if (!row) return;

            // Se la workspace non esiste, chiedi conferma per avviarla
            if (row.dataset.hasWorkspace === '0') {
                const codice = row.dataset.codice;
                if (codice) avviaCommessa(codice);
                return;
            }

            const href = row.dataset.href;
            if (href) {
                window.location.href = href;
            }
        });
    });
</script>
