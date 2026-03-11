<?php
if (!defined('HostDbDataConnector')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

use Services\CommesseService;

global $database;

/* --- Parametri (sanificati) --- */
$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : '';
$titolo = isset($_GET['titolo']) ? strip_tags($_GET['titolo']) : strtoupper($tabella);

if ($tabella === '') {
    echo "<div class='error'>Parametro 'tabella' mancante.</div>";
    return;
}

/* Token CSRF (meta name="token-csrf") */
if (empty($_SESSION['CSRFtoken'])) {
    $_SESSION['CSRFtoken'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['CSRFtoken'];
?>
<div class="main-container">
    <?php renderPageTitle('Documenti sicurezza — elenco', '#2C3E50'); ?>

    <div class="table-wrap">
        <table class="table table-filterable" id="docs-table">
            <thead>
                <tr>
                    <th>Azioni</th>
                    <th>Codice</th>
                    <th>Azienda</th>
                    <th>Personale</th>
                    <th>Nome file</th>
                    <th>Tipo</th>
                    <th>Stato</th>
                </tr>
            </thead>
            <tbody><!-- popolata via JS --></tbody>
        </table>
    </div>

    <!-- Modal: Upload documento sicurezza -->
    <div id="modal-sic-doc" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close close-modal" aria-label="Chiudi" data-tooltip="Chiudi">&times;</span>

            <div class="modal-header">
                <h3 style="margin:0;">Carica documento sicurezza</h3>
            </div>

            <div class="modal-body">
                <form id="form-sic-doc" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="tabella" id="sic-tabella" value="<?= htmlspecialchars($tabella) ?>">

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="sic-az-select">Azienda <span class="req">*</span></label>
                            <select id="sic-az-select" name="azienda_id" required>
                                <option value="">— Seleziona azienda —</option>
                            </select>
                            <small class="hint">Solo aziende presenti nell’organigramma imprese.</small>
                        </div>

                        <div class="form-group">
                            <label for="sic-tipo-select">Tipo documento <span class="req">*</span></label>
                            <select id="sic-tipo-select" name="tipo" required>
                                <option value="">— Seleziona tipo —</option>
                            </select>
                            <small class="hint">Es. POS, DURC, CRD, … (da impostazioni).</small>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="sic-scadenza">Scadenza (opz.)</label>
                            <input type="date" id="sic-scadenza" name="scadenza">
                        </div>

                        <div class="form-group">
                            <label for="sic-file">File <span class="req">*</span></label>
                            <input type="file" id="sic-file" name="file" accept=".pdf,image/*" required>
                            <small class="hint">Carica un documento per volta (PDF/JPG/PNG).</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Personale (opz.)</label>
                        <div id="sic-pers-list" class="chip-list"></div>
                        <div class="inline-add">
                            <input type="text" id="sic-pers-input" placeholder="Nome e Cognome…">
                            <button type="button" class="btn" id="sic-pers-add"
                                data-tooltip="Aggiungi nominativo">+</button>
                        </div>
                        <small class="hint">Se il documento è riferito a persone specifiche, aggiungile qui. Puoi
                            inserire più nominativi.</small>
                    </div>
                </form>
            </div>

            <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn primary" id="sic-upload-btn"
                    data-tooltip="Carica documento">Carica</button>
            </div>
        </div>
    </div>

</div>

</div>

<style>
    /* il core gestisce display inline: noi impediamo a .show di sovrascrivere sul nostro id */
    #modal-sic-doc.show {
        display: none !important;
    }

    /* opzionale: garantisci centraggio standard senza dipendere da .show */
    #modal-sic-doc .modal-content {
        margin: auto;
    }

    /* --- base: preveniamo “salti” e overflow --- */
    #docs-table,
    #sic-filter-bar,
    .sic-filter-bar,
    .table-wrap {
        box-sizing: border-box;
    }

    .sic-filter-bar *,
    #docs-table * {
        box-sizing: inherit;
    }

    /* ====== FILTER BAR ====== */
    .sic-filter-bar {
        width: 100%;
        display: grid;
        grid-template-columns: repeat(6, minmax(180px, 1fr));
        gap: 12px;
        margin: 14px 0 14px;
        align-items: end;
    }

    .sic-control {
        display: block;
        width: 100%;
        min-height: 38px;
        height: 38px;
        /* altezza uniforme input/select */
        padding: 6px 10px;
        border: 1px solid #d7dce3;
        border-radius: 8px;
        background: #fff;
        color: #2c3e50;
        outline: none;
        transition: border-color .15s, box-shadow .15s, background-color .15s;
        font-size: 14px;
        line-height: 24px;
        max-width: 100%;
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        /* normalizza select FF/Safari */
    }

    /* ====== TABLE POLISH ====== */
    #docs-table td.col-file {
        white-space: normal;
    }

    /* link file può andare a capo */
    #docs-table td.col-file a {
        text-decoration: none;
        border-bottom: 1px dashed #2d7bdc;
        word-break: break-all;
    }
</style>


<!-- Boot dati -->
<meta name="token-csrf" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<script>
    window._tabellaCommessa = <?= json_encode($tabella, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window._titoloCommessa = <?= json_encode($titolo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/commesse/commessa_sic_elenco_doc.js"></script>