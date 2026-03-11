<?php

require_once __DIR__ . '/components/extra_auth.php';
if (!userHasPermission('view_import_manager')) {
    die('Accesso negato');
}

if (empty($_SESSION['CSRFtoken'])) {
    $_SESSION['CSRFtoken'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['CSRFtoken'];
?>

<div class="main-container">
    <?php renderPageTitle("Import Manager Universale", "#2C3E50"); ?>
    <div id="import-manager" class="import-manager-flex">
        <!-- FORM -->
        <form id="importForm" class="import-form-side" enctype="multipart/form-data">
            <div class="form-group">
                <label class="input-label" for="modeSelect">Modalità importazione</label>
                <select name="mode" id="modeSelect" class="input-select" required>
                    <option value="insert">Solo inserimento (append)</option>
                    <option value="update">Aggiorna su campo chiave</option>
                    <option value="overwrite">Sovrascrivi tutto (truncate + insert)</option>
                    <option value="create_new">Crea nuova tabella</option>
                </select>
            </div>
            <div class="form-group" id="tableGroup">
                <label class="input-label" for="tableSelect">Seleziona tabella</label>
                <select name="table" id="tableSelect" class="input-select" required>
                    <option value="">Caricamento…</option>
                </select>
            </div>
            <div class="form-group" id="fileGroup">
                <label class="input-label" for="fileInput">Carica file CSV o XLSX</label>
                <input type="file" name="datafile" id="fileInput" class="input" accept=".csv,.xlsx" required>
            </div>
            <div class="form-group" id="keyFieldWrapper" style="display:none;">
                <label class="input-label" for="keyFieldSelect">Campo chiave (dal file caricato)</label>
                <select name="key_field" id="keyFieldSelect" class="input-select">
                    <option value="">Seleziona campo chiave...</option>
                </select>
                <small style="color:#666;font-size:12px;display:block;margin-top:4px;">
                    Scegli la colonna del file che identifica univocamente i record da aggiornare
                </small>
            </div>
            <div class="form-group" id="skipExistingWrapper" style="display:none;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 0;">
                    <input type="checkbox" name="skip_existing" id="skipExistingCheckbox" checked
                        style="accent-color:#2980b9;">
                    <span style="font-weight:500;color:#32455a;">Solo inserisci nuovi record</span>
                </label>
                <small style="color:#666;font-size:12px;display:block;margin-top:4px;">
                    Se attivo, i record con codice già esistente nel DB verranno saltati (non modificati). Deseleziona
                    per aggiornare i record esistenti.
                </small>
            </div>
            <div class="form-group" id="updateFieldsWrapper" style="display:none;">
                <label class="input-label">Colonne da aggiornare</label>
                <div id="updateFieldsList"
                    style="max-height:200px;overflow-y:auto;border:1px solid #d3d7df;border-radius:6px;padding:8px;background:#fbfcfd;">
                    <em style="color:#999;">Carica un file e fai preview per vedere le colonne disponibili</em>
                </div>
                <small style="color:#666;font-size:12px;display:block;margin-top:4px;">
                    Seleziona quali colonne aggiornare. Se nessuna è selezionata, verranno aggiornate tutte.
                </small>
            </div>
            <div class="form-group" id="newTableWrapper" style="display:none;">
                <label class="input-label" for="newTableName">Nome nuova tabella</label>
                <input type="text" id="newTableName" name="new_table_name" class="input" placeholder="es: import_2024"
                    maxlength="64" autocomplete="off" />
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <div class="button-group" style="display:flex;gap:8px;margin-top:18px;">
                <button type="button" id="previewBtn" class="button btn-primary">Preview dati</button>
                <button type="submit" id="importBtn" class="button btn-success" disabled>Importa</button>
            </div>
        </form>
        <!-- PREVIEW + RISULTATO -->
        <div class="import-preview-wrap">
            <div id="previewArea"></div>
            <div id="importResult" class="alert"></div>
        </div>
    </div>
</div>

<style>
    /* Allinea main-container a sinistra, nessun padding extra */
    .main-container {
        max-width: none !important;
        padding-left: 0 !important;
        padding-right: 0 !important;
    }

    .import-manager-flex {
        display: flex;
        align-items: flex-start;
        gap: 0;
        width: 100%;
    }

    /* FORM ancorato a sinistra */
    .import-form-side {
        min-width: 310px;
        width: 320px;
        padding: 0 16px 0 0;
        margin: 0;
        background: none;
        border: none;
        box-shadow: none;
    }

    .import-form-side .form-group {
        margin-bottom: 16px;
    }

    .import-form-side .input-label {
        font-weight: 500;
        color: #32455a;
        margin-bottom: 4px;
        display: block;
    }

    .import-form-side .input,
    .import-form-side .input-select {
        width: 100%;
        border: 1px solid #d3d7df;
        border-radius: 6px;
        background: #fbfcfd;
        padding: 7px 10px;
        font-size: 15px;
        transition: border .15s;
        box-sizing: border-box;
    }

    .import-form-side .input:focus,
    .import-form-side .input-select:focus {
        border-color: #2980b9;
    }

    .import-form-side #fileInput.input {
        background: none;
        min-width: 0;
        width: 100%;
        font-size: 14px;
        padding: 7px 5px;
    }

    .button-group .btn {
        min-width: 112px;
    }

    @media (max-width: 950px) {
        .import-manager-flex {
            flex-direction: column;
        }

        .import-form-side {
            width: 100%;
            min-width: 0;
            padding-right: 0;
        }
    }

    /* PREVIEW: prende tutto lo spazio! */
    .import-preview-wrap {
        flex: 1;
        min-width: 0;
        margin-left: 0;
        margin-right: 0;
        padding-left: 0;
        padding-right: 12px;
        background: none;
    }

    #previewArea {
        margin: 0;
        padding: 0;
        background: none;
        border: none;
        box-shadow: none;
        width: 100%;
    }

    .preview-table-scroll {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .preview-scroll-top {
        overflow-x: auto;
        overflow-y: hidden;
        height: 16px;
        border-radius: 6px;
        background: #f1f4fa;
        border: 1px solid #dfe5ef;
        display: none;
    }

    .preview-scroll-top.visible {
        display: block;
    }

    .preview-scroll-top::-webkit-scrollbar {
        height: 10px;
    }

    .preview-scroll-top::-webkit-scrollbar-track {
        background: transparent;
    }

    .preview-scroll-top::-webkit-scrollbar-thumb {
        background: rgba(44, 62, 80, 0.35);
        border-radius: 5px;
    }

    .preview-scroll-spacer {
        height: 1px;
    }

    .preview-scroll-main {
        overflow: auto;
        max-height: 70vh;
    }

    #previewArea table.table,
    #previewArea .table {
        width: 100%;
        max-width: 100%;
        border-radius: 8px;
        border: 1px solid #e1e9f0;
        font-size: 14px;
        background: #fff;
        box-shadow: 0 1px 6px rgba(34, 40, 60, 0.04);
    }

    #previewArea th,
    #previewArea td {
        padding: 7px 10px;
        border-bottom: 1px solid #e7e7ea;
        text-align: left;
        font-size: 14px;
    }

    #previewArea th {
        background: #f5f9fc;
        font-weight: 600;
        color: #2d3a4a;
    }

    #previewArea tr:nth-child(even) {
        background: #f7fafd;
    }

    #previewArea tr:last-child td {
        border-bottom: none;
    }

    #previewArea input.table-filter-input {
        font-size: 13px;
        border-radius: 3px;
        border: 1px solid #e3e3e3;
        padding: 3px 7px;
        margin: 0;
        width: 100%;
        background: #fbfbfd;
    }

    #previewArea thead tr th input[type="checkbox"] {
        margin: 0 0 0 0;
    }

    #previewArea th,
    #previewArea td {
        vertical-align: middle;
    }

    #previewArea .row-checkbox,
    #previewArea .col-checkbox {
        accent-color: #2980b9;
    }

    #previewArea h3 {
        margin: 0 0 10px 0;
        font-size: 18px;
        font-weight: 700;
        color: #1b2b38;
    }

    /* Risultato finale */
    #importResult.alert {
        background: #f8fbf7;
        color: #18572a;
        font-size: 15px;
        padding: 14px 18px;
        border-radius: 8px;
        min-height: 32px;
        border: 1px solid #dbe8dd;
        margin: 22px 0 0 0;
    }

    #updateFieldsList label {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 4px 0;
        cursor: pointer;
        font-size: 14px;
    }

    #updateFieldsList label:hover {
        background: #f0f4f8;
        padding-left: 4px;
        border-radius: 3px;
    }

    #updateFieldsList input[type="checkbox"] {
        accent-color: #2980b9;
        cursor: pointer;
    }

    /* Mapping Interface Styles */
    .mapping-container {
        background: #fff;
        border: 1px solid #e1e9f0;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 1px 6px rgba(34, 40, 60, 0.04);
    }

    .mapping-list {
        max-height: 500px;
        overflow-y: auto;
        margin-top: 15px;
    }

    .mapping-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px;
        margin-bottom: 8px;
        background: #f8f9fa;
        border-radius: 6px;
        border: 1px solid #e9ecef;
        transition: background 0.2s;
    }

    .mapping-row:hover {
        background: #f0f2f5;
    }

    .mapping-field-info {
        min-width: 200px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .mapping-field-info strong {
        color: #2d3a4a;
        font-size: 14px;
    }

    .suggestion-badge {
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 500;
        white-space: nowrap;
    }

    .suggestion-exact {
        background: #d4edda;
        color: #155724;
    }

    .suggestion-normalized {
        background: #d1ecf1;
        color: #0c5460;
    }

    .suggestion-fuzzy {
        background: #fff3cd;
        color: #856404;
    }

    .suggestion-contains,
    .suggestion-similarity {
        background: #f8d7da;
        color: #721c24;
    }

    .mapping-select {
        flex: 1;
        padding: 6px 10px;
        border: 1px solid #d3d7df;
        border-radius: 6px;
        background: #fff;
        font-size: 14px;
        min-width: 200px;
    }

    .mapping-select option[data-suggested="true"] {
        background: #e8f5e9;
        font-weight: 500;
    }

    .btn-suggest {
        padding: 6px 12px;
        background: #28a745;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        transition: background 0.2s;
    }

    .btn-suggest:hover {
        background: #218838;
    }
</style>