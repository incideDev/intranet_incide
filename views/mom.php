<?php
if (!defined('AccessoFileInterni'))
    define('AccessoFileInterni', true);

// Controllo permessi: usa la funzione standard come tutte le altre pagine
if (!checkPermissionOrWarn('view_mom'))
    return;

// Sanifica parametri GET
$momId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$action = isset($_GET['action']) ? trim((string) $_GET['action']) : 'archivio';
$section = isset($_GET['section']) ? trim((string) $_GET['section']) : '';
$contextType = isset($_GET['contextType']) ? trim((string) $_GET['contextType']) : '';
$contextId = isset($_GET['contextId']) ? trim((string) $_GET['contextId']) : '';

// Default automatico per sezione commerciale (MVP)
if ($section === 'commerciale' && empty($contextType) && empty($contextId)) {
    $contextType = 'commerciale';
    $contextId = 'commerciale';
}

// Validazione action
if (!in_array($action, ['archivio', 'edit', 'view'], true)) {
    $action = 'archivio';
}
?>

<?php if (isset($_SESSION['CSRFtoken']) && $_SESSION['CSRFtoken'] !== ''): ?>
    <meta name="token-csrf" content="<?= $_SESSION['CSRFtoken'] ?>">
<?php endif; ?>

<link rel="stylesheet" href="/assets/css/form.css">
<script src="/assets/js/modules/repeatableTable.js"></script>
<script src="/assets/js/modules/calendar_view.js"></script>
<script src="/assets/js/modules/mom_global_views.js"></script>
<script src="/assets/js/components/bottom_bar.js"></script>

<div class="main-container">
    <?php renderPageTitle('Verbali Riunione (MOM)', '#3498DB'); ?>

    <!-- Container per viste task (kanban/calendario) - nascosti di default -->
    <div id="kanban-view" class="hidden">
        <?php
        // Determina context per le task: le task MOM hanno contextType='mom' e contextId=ID del MOM
        // Se siamo in archivio, mostriamo tutte le task MOM (contextType='mom', contextId='*' o tutte)
        // Se siamo in dettaglio, mostriamo solo le task di quel MOM specifico
        $taskContextType = 'mom';
        // Per il kanban, se siamo in archivio mostriamo tutte le task MOM (usiamo contextId vuoto o '*' per tutte)
        // Se siamo in dettaglio, usiamo l'ID del MOM come contextId
        $taskContextId = $momId > 0 ? (string) $momId : '';

        // Include template kanban con context MOM
        $entity_type = $taskContextType;
        $entity_id = $taskContextId;
        $kanbanType = 'mom';
        include substr(__DIR__, 0, strpos(__DIR__, '/views')) . '/views/components/kanban_template.php';
        ?>
    </div>

    <div id="calendar-view" class="hidden"></div>

    <?php if ($action === 'archivio'): ?>
        <!-- ARCHIVIO WRAPPER (Vista Lista) -->
        <div id="mom-archive-view-wrapper">
            <div class="archive-container" id="mom-archivio">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; gap: 16px;">
                        <h3 style="margin-bottom: 0; font-weight: bold;">Elenco Verbali</h3>
                    </div>

                    <?php if (userHasPermission('edit_mom') || isAdmin()): ?>
                        <button type="button" class="button" onclick="momApp.nuovoMom()" data-tooltip="Crea nuovo verbale">
                            + Nuovo MOM
                        </button>
                    <?php endif; ?>
                </div>
                <hr style="margin-bottom: 16px;">

                <!-- Filtri -->
                <div style="display: flex; gap: 12px; margin-bottom: 8px;">
                    <div style="flex: 1;">
                        <label for="filtro-area">Area:</label>
                        <select id="filtro-area" class="select-box">
                            <option value="">Tutte</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="filtro-codice">Codice:</label>
                        <select id="filtro-codice" class="select-box">
                            <option value="">Tutti</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label for="filtro-stato">Stato:</label>
                        <select id="filtro-stato" class="select-box">
                            <option value="">Tutti</option>
                            <option value="bozza">Bozza</option>
                            <option value="condiviso">Condiviso</option>
                            <option value="approvato">Approvato</option>
                            <option value="archiviato">Archiviato</option>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 12px;">
                    <div style="flex: 1;">
                        <label for="filtro-data-da">Data da:</label>
                        <input type="date" id="filtro-data-da" class="select-box">
                    </div>
                    <div style="flex: 1;">
                        <label for="filtro-data-a">Data a:</label>
                        <input type="date" id="filtro-data-a" class="select-box">
                    </div>
                    <div style="flex: 1;">
                        <label for="filtro-testo">Cerca:</label>
                        <input type="text" id="filtro-testo" class="select-box" placeholder="Cerca nel titolo o note...">
                    </div>
                </div>
                <!-- Sezione corrente per filtro -->
                <input type="hidden" id="filtro-section" value="<?= htmlspecialchars($section) ?>">
                <?php if (!empty($contextType) && !empty($contextId)): ?>
                    <input type="hidden" id="filtro-context-type" value="<?= htmlspecialchars($contextType) ?>">
                    <input type="hidden" id="filtro-context-id" value="<?= htmlspecialchars($contextId) ?>">
                <?php endif; ?>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; margin-bottom: 16px;">
                <button type="button" class="button" onclick="momApp.caricaArchivio()"
                    data-tooltip="Applica filtri">Filtra</button>
                <button type="button" class="button button-secondary" onclick="momApp.resetFiltri()"
                    data-tooltip="Reset filtri">Reset</button>
            </div>

            <!-- Tabella -->
            <div class="table-container" id="mom-list-container">
                <style>
                    #mom-archivio-table .filter-row {
                        display: none !important;
                    }

                    #mom-archivio-table+.table-pagination,
                    .table-container:has(#mom-archivio-table)+.table-pagination {
                        display: none !important;
                    }

                    /* Stile colonna azioni nell'archivio */
                    #mom-archivio-table .azioni-colonna {
                        text-align: center !important;
                        vertical-align: middle !important;
                        padding: 4px !important;
                        width: 120px !important;
                        min-width: 120px !important;
                        max-width: 120px !important;
                    }

                    #mom-archivio-table tbody td:last-child {
                        text-align: center !important;
                        vertical-align: middle !important;
                    }
                </style>
                <table class="table table-filterable" id="mom-archivio-table" data-no-pagination="true">
                    <thead>
                        <tr>
                            <th class="azioni-colonna">Azioni</th>
                            <th>Protocollo</th>
                            <th>Area</th>
                            <th>Codice</th>
                            <th>Titolo</th>
                            <th>Data</th>
                            <th>Luogo</th>
                            <th>Stato</th>
                            <th>Creato da</th>
                        </tr>
                    </thead>
                    <tbody id="mom-archivio-tbody">
                        <tr>
                            <td colspan="9" class="text-center">Caricamento...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- FINE ARCHIVIO WRAPPER -->

        <!-- Global Views Containers (Fuori dal wrapper lista) -->
        <div id="mom-global-kanban" class="hidden" style="margin-bottom: 16px;"></div>
        <div id="mom-global-calendar" class="hidden"
            style="margin-bottom: 16px; background:white; padding:16px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        </div>


    <?php else: ?>
        <!-- EDITOR/VIEWER -->
        <div class="top-container" id="mom-editor">

            <div class="pagina-foglio">
                <!-- Titolo Dinamico [Protocollo] - [Titolo] con Esporta PDF -->
                <!-- Nascosto per nuovi verbali, visibile dopo il primo salvataggio -->
                <div id="mom-page-title" class="mom-page-title-hidden">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h2 style="margin: 0; font-size: 1.4em; color: #1e293b; font-weight: 600;">
                            <span id="mom-page-title-text"></span>
                        </h2>
                        <div style="cursor: pointer;" onclick="momApp.exportPdf()" data-tooltip="Esporta in PDF">
                            <img src="/assets/icons/export.png" alt="Esporta PDF"
                                style="width: 24px; height: 24px; opacity: 0.7; transition: opacity 0.2s;"
                                onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                        </div>
                    </div>
                </div>

                <style>
                    /* Titolo pagina nascosto: nessun spazio */
                    .mom-page-title-hidden {
                        display: none;
                        margin: 0;
                        padding: 0;
                    }

                    /* Titolo pagina visibile: con margini e bordo */
                    .mom-page-title-visible {
                        display: block;
                        margin-bottom: 24px;
                        padding-bottom: 12px;
                        border-bottom: 2px solid #CD211D;
                    }
                </style>
                <form id="mom-form">
                    <input type="hidden" id="mom-id" name="id">
                    <input type="hidden" id="mom-section" name="section" value="<?= htmlspecialchars($section) ?>">
                    <input type="hidden" id="mom-context-type" name="contextType"
                        value="<?= htmlspecialchars($contextType) ?>">
                    <input type="hidden" id="mom-context-id" name="contextId" value="<?= htmlspecialchars($contextId) ?>">

                    <!-- Progressivo MOM -->
                    <div class="mom-section">
                        <div class="mom-section-header">
                            <div class="mom-section-icon mom-section-icon-info"></div>
                            <h3 class="mom-section-title">Informazioni Verbale</h3>
                        </div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label for="mom-area">Area:</label>
                                <select id="mom-area" name="area" class="select-box"
                                    data-tooltip="Seleziona area (Generale o Commessa)">
                                    <option value="">Seleziona area</option>
                                    <?php
                                    $puo_generale = userHasPermission('view_mom_generale') || isAdmin();
                                    $puo_commessa = userHasPermission('view_mom_commessa') || isAdmin();
                                    if ($puo_commessa): ?>
                                        <option value="commessa">Commessa</option>
                                    <?php endif; ?>
                                    <?php if ($puo_generale): ?>
                                        <option value="generale">Generale</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="mom-codice-select">Codice:</label>
                                <div id="mom-codice-select" class="custom-select-box custom-select-box-form" tabindex="0">
                                    <span class="custom-select-placeholder">Seleziona un codice</span>
                                    <div class="custom-select-dropdown" style="display:none;"></div>
                                </div>
                                <input type="hidden" id="mom-codice-protocollo-value" name="codice" value="">
                            </div>
                        </div>
                        <div class="form-grid form-grid-3">
                            <div class="form-group">
                                <label for="mom-progressivo-display">Protocollo</label>
                                <input type="text" id="mom-progressivo-display" readonly class="readonly"
                                    data-tooltip="Protocollo MOM (generato automaticamente)">
                            </div>
                            <div class="form-group">
                                <label for="mom-stato-select">Stato</label>
                                <select id="mom-stato-select" name="stato" class="select-box"
                                    data-tooltip="Stato del verbale">
                                    <option value="bozza">Bozza</option>
                                    <option value="in_revisione">In Revisione</option>
                                    <option value="chiuso">Chiuso</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Dati Riunione -->
                    <div class="mom-section">
                        <div class="mom-section-header">
                            <div class="mom-section-icon mom-section-icon-calendar"></div>
                            <h3 class="mom-section-title">Dati Riunione</h3>
                        </div>
                        <div class="form-grid form-grid-1">
                            <div class="form-group">
                                <label for="mom-titolo">Titolo/Oggetto <span class="required">*</span></label>
                                <input type="text" id="mom-titolo" name="titolo" placeholder="Titolo del verbale" required
                                    data-tooltip="Titolo del verbale">
                            </div>
                        </div>

                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label for="mom-data-meeting">Data Riunione
                                    <span class="required">*</span></label>
                                <input type="date" id="mom-data-meeting" name="dataMeeting" required
                                    data-tooltip="Data della riunione">
                            </div>
                            <div class="form-group">
                                <label for="mom-luogo">Luogo</label>
                                <input type="text" id="mom-luogo" name="luogo" placeholder="Luogo della riunione"
                                    data-tooltip="Luogo della riunione">
                            </div>
                        </div>

                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label for="mom-ora-inizio">Ora Inizio</label>
                                <input type="time" id="mom-ora-inizio" name="oraInizio"
                                    data-tooltip="Ora di inizio riunione">
                            </div>
                            <div class="form-group">
                                <label for="mom-ora-fine">Ora Fine</label>
                                <input type="time" id="mom-ora-fine" name="oraFine" data-tooltip="Ora di fine riunione">
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label for="mom-note">Note</label>
                            <textarea id="mom-note" name="note" rows="3" placeholder="Note aggiuntive"
                                data-tooltip="Note aggiuntive"></textarea>
                        </div>
                    </div>

                    <!-- Partecipanti -->
                    <div class="mom-section">
                        <div class="mom-section-header">
                            <div class="mom-section-icon mom-section-icon-users"></div>
                            <h3 class="mom-section-title">Partecipanti</h3>
                            <button type="button" class="mom-btn-add" id="mom-partecipanti-add-btn"
                                data-tooltip="Aggiungi partecipante">
                                <img src="assets/icons/plus.png" alt="Aggiungi" style="width: 14px; height: 14px;">
                                Aggiungi
                            </button>
                        </div>
                        <style>
                            #mom-partecipanti-container .table-filterable .filter-row,
                            #mom-items-container .table-filterable .filter-row {
                                display: none !important;
                            }

                            #mom-partecipanti-container .table-pagination,
                            #mom-items-container .table-pagination {
                                display: none !important;
                            }

                            /* Celle tabelle repeatable - COMPATTE, zero padding/margin */
                            #mom-partecipanti-container .table-filterable tbody td:not(.azioni-colonna),
                            #mom-items-container .table-filterable tbody td:not(.azioni-colonna) {
                                padding: 0 !important;
                                margin: 0 !important;
                                vertical-align: middle !important;
                                border-right: 1px solid #e5e7eb !important;
                                border-bottom: 1px solid #e5e7eb !important;
                            }

                            /* Input/Select/Textarea nelle celle - occupano tutto, zero padding/margin */
                            #mom-partecipanti-container .table-filterable td input,
                            #mom-partecipanti-container .table-filterable td select,
                            #mom-partecipanti-container .table-filterable td textarea,
                            #mom-items-container .table-filterable td input,
                            #mom-items-container .table-filterable td select,
                            #mom-items-container .table-filterable td textarea {
                                width: 100% !important;
                                box-sizing: border-box !important;
                                padding: 4px 6px !important;
                                border: none !important;
                                border-radius: 0 !important;
                                font-size: 0.85em !important;
                                background-color: transparent !important;
                                color: #1e293b !important;
                                transition: border-color 0.2s !important;
                                margin: 0 !important;
                                min-height: 28px !important;
                                line-height: 1.3 !important;
                            }

                            /* Focus state - minimale */
                            #mom-partecipanti-container .table-filterable td input:focus,
                            #mom-partecipanti-container .table-filterable td select:focus,
                            #mom-partecipanti-container .table-filterable td textarea:focus,
                            #mom-items-container .table-filterable td input:focus,
                            #mom-items-container .table-filterable td select:focus,
                            #mom-items-container .table-filterable td textarea:focus {
                                outline: none !important;
                                border-color: #3b82f6 !important;
                                box-shadow: none !important;
                            }

                            /* Textarea specifico - compatto */
                            #mom-partecipanti-container .table-filterable td textarea,
                            #mom-items-container .table-filterable td textarea {
                                resize: vertical !important;
                                min-height: 50px !important;
                                line-height: 1.3 !important;
                            }

                            /* Checkbox - centrato, compatto */
                            #mom-partecipanti-container .table-filterable td input[type="checkbox"],
                            #mom-items-container .table-filterable td input[type="checkbox"] {
                                width: auto !important;
                                min-height: auto !important;
                                margin: 0 auto !important;
                                padding: 0 !important;
                                display: block !important;
                                transform: scale(1.1) !important;
                            }

                            /* Select - compatto */
                            #mom-partecipanti-container .table-filterable td select,
                            #mom-items-container .table-filterable td select {
                                cursor: pointer !important;
                                appearance: none !important;
                                -webkit-appearance: none !important;
                                -moz-appearance: none !important;
                                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23334155' d='M6 9L1 4h10z'/%3E%3C/svg%3E") !important;
                                background-repeat: no-repeat !important;
                                background-position: right 6px center !important;
                                padding-right: 24px !important;
                            }

                            /* Stile colonna azioni - compatto */
                            #mom-partecipanti-container .azioni-colonna,
                            #mom-items-container .azioni-colonna {
                                text-align: center !important;
                                vertical-align: middle !important;
                                padding: 2px 4px !important;
                                margin: 0 !important;
                                border-right: 1px solid #e5e7eb !important;
                                border-bottom: 1px solid #e5e7eb !important;
                                width: 80px !important;
                                min-width: 80px !important;
                                max-width: 80px !important;
                            }

                            #mom-partecipanti-container .azioni-colonna>div,
                            #mom-items-container .azioni-colonna>div {
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                gap: 2px;
                                margin: 0;
                                padding: 0;
                            }

                            #mom-partecipanti-container .azioni-colonna .action-icon,
                            #mom-items-container .azioni-colonna .action-icon {
                                border: none !important;
                                background: transparent !important;
                                padding: 1px !important;
                                margin: 0 !important;
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                            }

                            #mom-partecipanti-container .azioni-colonna .action-icon img,
                            #mom-items-container .azioni-colonna .action-icon img {
                                width: 14px;
                                height: 14px;
                                display: block;
                            }

                            #mom-partecipanti-container .azioni-colonna .button,
                            #mom-items-container .azioni-colonna .button {
                                margin: 0 1px !important;
                                padding: 2px 6px !important;
                                font-size: 10px !important;
                            }

                            /* Header - compatto */
                            #mom-partecipanti-container .table-filterable thead th,
                            #mom-items-container .table-filterable thead th {
                                padding: 6px 8px !important;
                                margin: 0 !important;
                                border-right: 1px solid #d1d5db !important;
                                border-bottom: 2px solid #d1d5db !important;
                                font-size: 0.85em !important;
                            }

                            /* ===== LARGHEZZE DEFAULT RESPONSIVE (percentuali) ===== */
                            /* Queste sono larghezze di base che si adattano al container */
                            /* Il resize manuale ha sempre priorità su queste */

                            /* Tabella Partecipanti - 5 colonne + azioni */
                            #mom-partecipanti-container .table-filterable thead th:nth-child(1) {
                                width: 25%;
                                /* Società */
                            }

                            #mom-partecipanti-container .table-filterable thead th:nth-child(2) {
                                width: 25%;
                                /* Partecipante */
                            }

                            #mom-partecipanti-container .table-filterable thead th:nth-child(3) {
                                width: 25%;
                                /* Email */
                            }

                            #mom-partecipanti-container .table-filterable thead th:nth-child(4) {
                                width: 15%;
                                /* Telefono */
                            }

                            #mom-partecipanti-container .table-filterable thead th:nth-child(5) {
                                width: 5%;
                                /* Presente (checkbox) */
                            }

                            /* Azioni già con width fissa sopra (80px) */

                            /* Tabella Items - 6 colonne + azioni */
                            #mom-items-container .table-filterable thead th:nth-child(1) {
                                width: 5%;
                                /* Tipo (AI/OBS/EVE) */
                            }

                            #mom-items-container .table-filterable thead th:nth-child(2) {
                                width: 8%;
                                /* Item Code */
                            }

                            #mom-items-container .table-filterable thead th:nth-child(3) {
                                width: 17%;
                                /* Titolo */
                            }

                            #mom-items-container .table-filterable thead th:nth-child(4) {
                                width: 25%;
                                /* Descrizione */
                            }

                            #mom-items-container .table-filterable thead th:nth-child(5) {
                                width: 16%;
                                /* Responsabile */
                            }

                            #mom-items-container .table-filterable thead th:nth-child(6) {
                                width: 12%;
                                /* Data Target */
                            }

                            #mom-items-container .table-filterable thead th:nth-child(7) {
                                width: 13%;
                                /* Stato */
                            }

                            /* Azioni già con width fissa sopra (80px) */
                        </style>
                        <div id="mom-partecipanti-container"></div>
                    </div>

                    <!-- Items (AI/OBS/EVE) -->
                    <div class="mom-section">
                        <div class="mom-section-header">
                            <div class="mom-section-icon mom-section-icon-list"></div>
                            <h3 class="mom-section-title">Items (AI/OBS/EVE)</h3>
                            <button type="button" class="mom-btn-add" id="mom-items-add-btn" data-tooltip="Aggiungi item">
                                <img src="assets/icons/plus.png" alt="Aggiungi" style="width: 14px; height: 14px;">
                                Aggiungi
                            </button>
                        </div>
                        <div id="mom-items-container"></div>
                    </div>

                    <!-- Allegati -->
                    <div class="mom-section">
                        <div class="mom-section-header">
                            <div class="mom-section-icon mom-section-icon-attach"></div>
                            <h3 class="mom-section-title">Allegati</h3>
                        </div>
                        <div id="mom-allegati-lista" style="margin-bottom: 16px;"></div>
                        <?php if ($action === 'edit'): ?>
                            <input type="file" id="mom-allegato-upload" multiple accept="*" style="margin-bottom: 8px;">
                            <button type="button" id="mom-upload-btn" class="btn btn-primary"
                                style="padding: 6px 12px; font-size: 0.9em;">Carica file</button>
                        <?php endif; ?>
                    </div>

                    <!-- Pulsanti azione gestiti via BottomBar in JS -->
                    <div style="height: 60px;"></div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Inizializza app MOM
    document.addEventListener('DOMContentLoaded', function () {
        // Aspetta che momApp sia definito
        if (typeof momApp === 'undefined') {
            setTimeout(function () {
                initializeMomApp();
            }, 100);
        } else {
            initializeMomApp();
        }
    });

    async function initializeMomApp() {
        const momId = <?= json_encode($momId) ?>;
        const action = <?= json_encode($action) ?>;
        const contextType = <?= json_encode($contextType) ?>;
        const contextId = <?= json_encode($contextId) ?>;

        if (action === 'archivio') {
            momApp.initArchivio(contextType, contextId);
            momApp.caricaArchivio();
        } else if (momId > 0) {
            momApp.caricaDettaglio(momId, action === 'edit');
        } else if (action === 'edit') {
            // Inizializza form vuoto per nuovo MOM
            await momApp.initNuovoMom();
        }

        // Setup dropdown codice
        if (action !== 'archivio') {
            momApp.setupDropdownCodice();
        }
    }
</script>