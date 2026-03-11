<?php
if (!defined('HostDbDataConnector')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : null;
if (!$tabella) {
    echo "<div class='error'>Parametro 'tabella' mancante.</div>";
    return;
}

$titoloCommessa = isset($_GET['titolo']) ? trim($_GET['titolo']) : 'Commessa';
$viewTitle = 'Verbale Riunione di Coordinamento (VCS)'; // ModQC14.2
?>
<div class="main-container">
    <?php renderPageTitle($viewTitle, '#1F5F8B'); ?>

    <!-- Toolbar (classi comuni sicurezza) -->
    <div class="sicurezza-toolbar">
        <button id="btnNewVCS" class="button" data-tooltip="Nuovo verbale">+ Nuovo</button>
        <input id="searchVCS" type="search" class="sic-input" placeholder="Cerca verbale..." aria-label="Cerca verbale">
    </div>

    <!-- Lista (classi comuni sicurezza) -->
    <div id="listVCS" class="sic-list"></div>
</div>

<!-- MODAL EDITOR: classi comuni sicurezza -->
<div id="modalVCS" class="modal sic-modal" role="dialog" aria-modal="true" hidden>
    <div class="modal-content modal-content--wide">
        <div class="modal-header">
            <h3 id="modalTitleVCS">Nuovo VCS</h3>
        </div>

        <div class="modal-body">
            <form id="vcsForm" class="sic-form" onsubmit="return false;">
                <input type="hidden" id="vcsId" value="">
                <input type="hidden" name="tipo" value="VCS">
                <input type="hidden" name="tabella" value="<?= htmlspecialchars($tabella) ?>">

                <!-- A — Intestazione -->
                <fieldset class="sic-section">
                    <legend>Intestazione</legend>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label" data-tooltip="Oggetto del verbale">Titolo</label>
                            <input id="titolo" class="form-control" placeholder="Es: Riunione del 12/09/2025" required>
                        </div>
                        <div class="sic-field">
                            <label class="sic-label">Luogo riunione</label>
                            <input id="luogoRiunione" class="form-control">
                        </div>
                    </div>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label">Data riunione</label>
                            <input id="dataRiunione" type="date" class="form-control">
                        </div>
                        <div class="sic-field">
                            <label class="sic-label">Ora riunione</label>
                            <input id="oraRiunione" type="time" class="form-control">
                        </div>
                    </div>

                    <div class="sic-grid-2">
                        <div class="sic-field"><label class="sic-label">Committente</label><input id="committente" class="form-control"></div>
                        <div class="sic-field"><label class="sic-label">Cantiere di</label><input id="cantiereDi" class="form-control"></div>
                        <div class="sic-field"><label class="sic-label">Lavoro di</label><input id="lavoroDi" class="form-control"></div>
                        <div class="sic-field"><label class="sic-label">Direttore lavori</label><input id="direttoreLavori" class="form-control"></div>
                        <div class="sic-field"><label class="sic-label">Responsabile lavori</label><input id="responsabileLavori" class="form-control"></div>
                        <div class="sic-field"><label class="sic-label">Coordinatore per l’esecuzione</label><input id="coordinatoreEsecuzione" class="form-control"></div>
                    </div>
                </fieldset>

                <!-- B — Imprese / LA -->
                <fieldset class="sic-section">
                    <legend>Imprese e Lavoratori Autonomi</legend>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label" data-tooltip="Una per riga">Imprese</label>
                            <textarea id="imprese" class="form-control sic-txt-md" placeholder="Impresa 1&#10;Impresa 2&#10;…"></textarea>
                        </div>
                        <div class="sic-field">
                            <label class="sic-label" data-tooltip="Una per riga">Lavoratori autonomi</label>
                            <textarea id="lavoratoriAutonomi" class="form-control sic-txt-md" placeholder="LA 1&#10;LA 2&#10;…"></textarea>
                        </div>
                    </div>
                </fieldset>

                <!-- C — Argomenti / Decisioni / Procedure -->
                <fieldset class="sic-section">
                    <legend>Argomenti</legend>
                    <div class="sic-field">
                        <label class="sic-label">Argomenti discussi</label>
                        <textarea id="argomenti" class="form-control sic-txt-lg"></textarea>
                    </div>
                </fieldset>

                <fieldset class="sic-section">
                    <legend>Decisioni</legend>
                    <div class="sic-field">
                        <label class="sic-label">Decisioni e linee comportamentali</label>
                        <textarea id="decisioni" class="form-control sic-txt-lg"></textarea>
                    </div>
                </fieldset>

                <fieldset class="sic-section">
                    <legend>Procedure</legend>
                    <div class="sic-field">
                        <label class="sic-label">Procedure fino al prossimo incontro</label>
                        <textarea id="procedure" class="form-control sic-txt-lg"></textarea>
                    </div>
                </fieldset>

                <!-- D — Chiusura -->
                <fieldset class="sic-section">
                    <legend>Chiusura e firme</legend>

                    <div class="sic-grid-2">
                        <div class="sic-field">
                            <label class="sic-label">Ora fine riunione</label>
                            <input id="oraFine" type="time" class="form-control">
                        </div>
                        <div class="sic-field">
                            <label class="sic-label">Firma CSE</label>
                            <input id="firmaCSE" class="form-control" placeholder="Nome e Cognome">
                        </div>
                    </div>

                    <div class="sic-field">
                        <label class="sic-label" data-tooltip="Firme o nominativi dei partecipanti">Partecipanti (firme / nominativi)</label>
                        <textarea id="partecipanti" class="form-control sic-txt-md" placeholder="Impresa X – ………………………………&#10;Impresa Y – ………………………………&#10;…"></textarea>
                    </div>
                </fieldset>
            </form>
        </div>

        <div class="modal-footer">
            <button class="button" id="btnSaveVCS" data-tooltip="Salva">Salva</button>
        </div>
    </div>
</div>

<script>
    window.__tabellaVCS = <?= json_encode($tabella) ?>;
</script>

<script src="/assets/js/commesse/commessa_sic_vcs.js"></script>