<?php
if (!defined('hostdbdataconnector')) define('hostdbdataconnector', true);
if (!defined('accessofileinterni')) define('accessofileinterni', true);

if ($Session->logged_in !== true) {
    header("Location: /index");
    exit;
}

if (!checkPermissionOrWarn('view_gare')) return;
?>

<div class="main-container page-gare">

<div id="kanban-view" class="view-content hidden">
    <?php
    // Stati colonne per le gare
    $statimap = [
        1 => "DA DEFINIRE",
        2 => "PUBBLICATA",
        3 => "IN VALUTAZIONE",
        4 => "AGGIUDICATA",
        5 => "ARCHIVIATA"
    ];

    // Carica tutte le gare
    $tasks = $database->query("SELECT * FROM elenco_bandi_gare", [], __FILE__)->fetchAll();

    // Nome logico per la tabella
    $tabella = 'elenco_bandi_gare';

    // FUNZIONE PER IL RENDER HTML DELLE TASK GARE
    $renderTaskHtml = function($task) {
        ob_start();
        ?>
        <div class="task-header">
            <div class="gara-number"><strong><?= htmlspecialchars($task['n_gara'] ?? 'N/D') ?></strong></div>
            <div class="title-field"><?= htmlspecialchars($task['titolo'] ?? '—') ?></div>
        </div>
        <div class="task-body">
            <div class="date-field">
                <img src="assets/icons/calendar.png" class="task-icon" alt="Scadenza">
                <?= !empty($task['data_scadenza']) ? htmlspecialchars(date('d/m/Y', strtotime($task['data_scadenza']))) : '-' ?>
            </div>
            <div class="sector-field">
                <img src="assets/icons/sector.png" class="task-icon" alt="Settore">
                <?= htmlspecialchars($task['settore'] ?? '-') ?>
            </div>
            <div class="import-field">
                <img src="assets/icons/money.png" class="task-icon" alt="Parcella Base">
                <?= isset($task['parcella_base']) ? htmlspecialchars(number_format($task['parcella_base'], 2, ',', '.')) : "N/A" ?>
            </div>
            <div class="ente-field">
                <img src="assets/icons/building.png" class="task-icon" alt="Stazione Appaltante">
                <?= htmlspecialchars($task['ente'] ?? '-') ?>
            </div>
            <!-- Aggiungi qui altri campi se ti servono! -->
        </div>
        <?php
        return ob_get_clean();
    };

    // Includi il template kanban universale
    include __DIR__ . '/components/kanban_template.php';
    ?>
</div>

    <!-- Contenitore Tabella -->
    <div id="table-view" class="view-content">
        <table class="table table-filterable" id="gareTable">
            <thead>
                <tr>
                    <th class="azioni-colonna">Azioni</th>
                    <th>Numero Gara</th>
                    <th>Ente</th>
                    <th>Titolo</th>
                    <th>Settore</th>
                    <th>Tipologia</th>
                    <th>Luogo</th>
                    <th>Data Uscita</th>
                    <th>Data Scadenza</th>
                    <th>Stato</th>
                </tr>
            </thead>
            <tbody id="gare-list"></tbody>
        </table>
    </div>

    <!-- Modale per aggiunta/modifica -->
    <div id="modalAddGara" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close-modal" onclick="chiudiModaleGara()">&times;</span>
            <h3 id="modal-title">Aggiungi Nuova Gara</h3>
            <div class="modal-header-line"></div>
            <div id="modal-body">
                <div class="modale-form-gara">
                    <div class="modale-form-group full-width" style="margin-bottom: 18px;">
                        <label for="modal-gara-pdf">Estrai dati da PDF con IA</label>
                        <div style="display: flex; gap: 7px; align-items: center;">
                            <input type="file" id="modal-gara-pdf" accept="application/pdf">
                            <button type="button" id="modal-gara-pdf-btn">Estrai e Compila</button>
                            <span id="modal-gara-pdf-status" style="margin-left:8px;font-size:0.93em;color:#888;"></span>
                        </div>
                    </div>
                    <div class="modale-form-grid">
                        <div class="modale-form-group">
                            <label for="garaOggetto">Oggetto dell'Appalto</label>
                            <input type="text" id="garaOggetto" required>
                        </div>

                        <div class="modale-form-group">
                            <label for="garaTipologia">Tipologia Gara</label>
                            <input type="text" id="garaTipologia" required>
                        </div>

                        <div class="modale-form-group">
                            <label for="garaSettore">Settore</label>
                            <input type="text" id="garaSettore" required>
                        </div>

                        <div class="modale-form-group">
                            <label for="garaTipologiaAppalto">Tipologia Appalto</label>
                            <input type="text" id="garaTipologiaAppalto" required>
                        </div>

                        <div class="modale-form-group">
                            <label for="garaEnte">Stazione Appaltante</label>
                            <input type="text" id="garaEnte" required>
                        </div>

                        <div class="modale-form-group">
                            <label for="garaLuogo">Luogo</label>
                            <input type="text" id="garaLuogo" required>
                        </div>

                        <div class="modale-form-group">
                            <label for="garaDataUscita">Data Uscita Gara</label>
                            <input type="date" id="garaDataUscita" required>
                        </div>

                        <div class="modale-form-group">
                            <label for="garaDataScadenza">Scadenza</label>
                            <input type="date" id="garaDataScadenza" required>
                        </div>

                        <div class="modale-form-group">
                            <label for="garaSopralluogo">Sopralluogo Obbligatorio</label>
                            <select id="garaSopralluogo">
                                <option value="No">No</option>
                                <option value="Sì">Sì</option>
                            </select>
                        </div>

                        <div class="modale-form-group">
                            <label for="garaLinkPortale">Link Portale S.A.</label>
                            <input type="url" id="garaLinkPortale">
                        </div>

                        <div class="modale-form-group full-width">
                            <label for="garaResponsabili">Responsabile Gara</label>
                            <select id="garaResponsabili" multiple>
                                <!-- Popolato dinamicamente -->
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="submit-modal-gare">
                <img src="assets/icons/save.png" alt="Salva" class="icon-btn" onclick="salvaGara()">
            </div>
        </div>
    </div>

</div>

<script src="/assets/js/modules/kanban.js"></script>