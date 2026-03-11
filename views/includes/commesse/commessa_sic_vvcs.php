<?php
if (!defined('HostDbDataConnector')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

use Services\CommesseService;

global $database;

$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : null;
if (!$tabella) {
    echo "<div class='error'>Parametro 'tabella' mancante.</div>";
    return;
}

$titoloCommessa = isset($_GET['titolo']) ? trim($_GET['titolo']) : 'Commessa';
$viewTitle = 'Verbale Visita in Cantiere (VVCS)'; // ModQC14.1
?>
<div class="main-container">
    <?php renderPageTitle($viewTitle, '#1F5F8B'); ?>

    <div class="sicurezza-toolbar">
        <button id="btnNew" class="button" data-tooltip="Nuovo verbale">+ Nuovo</button>
        <input id="search" type="search" class="sic-input" placeholder="Cerca verbale..." aria-label="Cerca verbale">
        <button id="btnExportPDF" class="button btn-secondary" data-tooltip="Esporta il verbale aperto in PDF" style="display:none;">Esporta PDF</button>
    </div>

    <div id="list" class="sic-list"></div>
</div>

<!-- MODAL EDITOR (riutilizzabile per tutta la sezione “Sicurezza documenti”) -->
<div id="modalVVCS" class="modal sic-modal" role="dialog" aria-modal="true" hidden>
    <div class="modal-content modal-content--wide">
        <div class="modal-header">
            <h3 id="modalTitle">Nuovo VVCS</h3>
        </div>

        <div class="modal-body">
            <form id="vvcsForm" class="sic-form" onsubmit="return false;">
                <input type="hidden" name="id" id="vvcsId" value="">
                <input type="hidden" name="tipo" value="VVCS">
                <input type="hidden" name="tabella" value="<?= htmlspecialchars($tabella) ?>">

                <!-- SEZIONE A — Intestazione verbale -->
                <fieldset class="sic-section">
                    <legend>Intestazione</legend>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label" data-tooltip="Oggetto del verbale">Titolo verbale</label>
                            <input id="titolo" name="titolo" class="form-control" placeholder="Es: Visita del 12/09/2025" required>
                        </div>
                        <div class="sic-field">
                            <label class="sic-label">Data visita</label>
                            <input id="dataVisita" name="data_visita" type="date" class="form-control">
                        </div>
                    </div>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label">Coordinatore</label>
                            <input id="coordinatore" name="coordinatore" class="form-control" placeholder="Nome e Cognome">
                        </div>
                        <div class="sic-field">
                            <label class="sic-label">Luogo cantiere</label>
                            <input id="luogo" name="luogo" class="form-control" placeholder="Indirizzo / località">
                        </div>
                        <div class="sic-field">
                            <label class="sic-label">Committente</label>
                            <input id="committente" name="committente" class="form-control">
                        </div>
                        <div class="sic-field">
                            <label class="sic-label">Lavoro di</label>
                            <input id="lavoroDi" name="lavoro_di" class="form-control">
                        </div>
                    </div>
                </fieldset>

                <!-- SEZIONE B — Presenze -->
                <fieldset class="sic-section">
                    <legend>Presenze in cantiere</legend>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label" data-tooltip="Elenca le imprese presenti (una per riga)">Imprese presenti</label>
                            <textarea id="impresePresenti" name="imprese_presenti" class="form-control sic-txt-md" placeholder="Impresa 1&#10;Impresa 2&#10;…"></textarea>
                        </div>
                        <div class="sic-field">
                            <label class="sic-label" data-tooltip="Elenca eventuali lavoratori autonomi (una riga ciascuno)">Lavoratori autonomi</label>
                            <textarea id="lavoratoriAutonomi" name="lavoratori_autonomi" class="form-control sic-txt-md" placeholder="Lavoratore autonomo 1&#10;Lavoratore autonomo 2&#10;…"></textarea>
                        </div>
                    </div>

                    <div class="sic-field">
                        <label class="sic-label">Fasi di lavoro in svolgimento</label>
                        <textarea id="fasi" name="fasi" class="form-control sic-txt-md" placeholder="Descrizione fasi in corso"></textarea>
                    </div>
                </fieldset>

                <!-- SEZIONE C — Esito -->
                <fieldset class="sic-section">
                    <legend>Esito verifica</legend>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label">Condizioni di CONFORMITÀ</label>
                            <textarea id="conformita" name="conformita" class="form-control sic-txt-lg" placeholder="Durante il sopralluogo si è potuto accertare che..."></textarea>
                        </div>
                        <div class="sic-field">
                            <label class="sic-label">Condizioni di NON CONFORMITÀ</label>
                            <textarea id="nonConformita" name="non_conformita" class="form-control sic-txt-lg" placeholder="Durante il sopralluogo si sono potute accertare le seguenti difformità..."></textarea>
                        </div>
                    </div>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label" data-tooltip="Ordini/prescrizioni per gli interessati, in coerenza con PSC e procedure">Prescrizioni / Ordini</label>
                            <textarea id="notePrescrizioni" name="note_prescrizioni" class="form-control sic-txt-sm" placeholder="Predisporre gli interventi correttivi, tenendo conto del PSC e relative procedure…"></textarea>
                        </div>
                        <div class="sic-field">
                            <label class="sic-label" data-tooltip="Scadenza per gli interventi correttivi">Scadenza interventi correttivi</label>
                            <input id="scadenzaInterventi" name="scadenza_interventi" type="date" class="form-control">
                        </div>
                    </div>
                </fieldset>

                <!-- SEZIONE D — Pericolo -->
                <fieldset class="sic-section">
                    <legend>Pericolo grave e imminente</legend>
                    <div class="sic-field">
                        <label class="sic-label">Descrizione (lavorazioni interessate e provvedimenti)</label>
                        <textarea id="pericolo" name="pericolo" class="form-control sic-txt-lg" placeholder="Situazioni di pericolo e sospensione lavorazioni fino agli adeguamenti, ai sensi dell’art. 92 c.1 lett. f) D.Lgs.81/2008…"></textarea>
                    </div>
                </fieldset>

                <!-- SEZIONE E — Chiusura e firme -->
                <fieldset class="sic-section">
                    <legend>Osservazioni e chiusura</legend>

                    <div class="sic-field">
                        <label class="sic-label">Ulteriori osservazioni</label>
                        <textarea id="osservazioni" name="osservazioni" class="form-control sic-txt-sm"></textarea>
                    </div>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label">Data chiusura verbale</label>
                            <input id="dataChiusura" name="data_chiusura" type="date" class="form-control">
                        </div>
                        <div class="sic-field">
                            <label class="sic-label">Firma Coordinatore (testo)</label>
                            <input id="firmaCoord" name="firma_coord" class="form-control" placeholder="Nome e Cognome">
                        </div>
                    </div>

                    <div class="sic-field">
                        <label class="sic-label" data-tooltip="Per presa visione dei soggetti interessati">Presa visione (firme / nominativi)</label>
                        <textarea id="firmePresaVisione" name="firme_presa_visione" class="form-control sic-txt-sm" placeholder="Impresa X – ………………………………&#10;Impresa Y – ………………………………&#10;…"></textarea>
                    </div>
                </fieldset>
            </form>
        </div>

        <div class="modal-footer">
            <button class="button" id="btnSave" data-tooltip="Salva">Salva</button>
        </div>
    </div>
</div>

<script>
    window.__tabellaVVCS = <?= json_encode($tabella) ?>;
</script>
<script src="/assets/js/commesse/commessa_sic_vvcs.js"></script>