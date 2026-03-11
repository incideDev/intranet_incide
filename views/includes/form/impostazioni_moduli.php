<?php
// Verifica accesso
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    include("page-errors/404.php");
    die();
}

// Inizializza sessione (se serve, solo se non incluso altrove)
if (!isset($Session)) {
    include_once(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . "/core/session.php");
    if ($Session->logged_in !== true) {
        header("Location: index.php");
        exit;
    }
}

// Verifica permesso
if (!checkPermissionOrWarn('view_gestione_intranet'))
    return;

?>
<?php
include_once substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . "/views/includes/form/create_form.php";
?>

<div class="main-container page-moduli-admin">
    <?php renderPageTitle("Gestione Pagine", "#2C3E50"); ?>

    <div class="dashboard-section">
        <h2>Statistiche sulle pagine</h2>
        <p style="color:#666;">(In arrivo: tempo medio di completamento, pagine inattive, ecc.)</p>
    </div>

    <div class="form-grid" id="admin-form-grid"></div>

    <!-- Modale Proprietà Pagina -->
    <div id="modal-proprieta-pagina" class="modal hidden" aria-hidden="true">
        <div class="modal-dialog" role="dialog" aria-modal="true" style="max-width: 500px;">
            <div class="modal-header">
                <h3 id="modal-proprieta-title" style="margin:0;">Proprietà Pagina</h3>
                <button class="close-modal" aria-label="Chiudi">✖</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="prop-form-name">

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="prop-display-name" style="display:block; margin-bottom:4px; font-weight:500;">Nome
                        visualizzato</label>
                    <input type="text" id="prop-display-name" class="input" placeholder="Es: Richiesta Materiali"
                        style="width:100%;">
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label for="prop-description"
                        style="display:block; margin-bottom:4px; font-weight:500;">Descrizione</label>
                    <textarea id="prop-description" class="input" rows="2" placeholder="Descrizione della pagina..."
                        style="width:100%;"></textarea>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                    <div class="form-group">
                        <label for="prop-color"
                            style="display:block; margin-bottom:4px; font-weight:500;">Colore</label>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input type="color" id="prop-color" value="#CCCCCC"
                                style="width:40px; height:32px; padding:0; border:none; cursor:pointer;">
                            <input type="text" id="prop-color-hex" class="input" value="#CCCCCC" maxlength="7"
                                style="width:90px; font-size:12px;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="prop-responsabile"
                            style="display:block; margin-bottom:4px; font-weight:500;">Responsabile</label>
                        <select id="prop-responsabile" class="input" style="width:100%;"></select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                    <div class="form-group">
                        <label for="prop-section"
                            style="display:block; margin-bottom:4px; font-weight:500;">Sezione</label>
                        <select id="prop-section" class="input" style="width:100%;"></select>
                    </div>
                    <div class="form-group">
                        <label for="prop-parent" style="display:block; margin-bottom:4px; font-weight:500;">Menu
                            padre</label>
                        <select id="prop-parent" class="input" style="width:100%;"></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="button" id="btn-save-proprieta">💾 Salva</button>
                <button type="button" class="button btn-secondary close-modal">Annulla</button>
            </div>
        </div>
    </div>

    <?php
    $assetsPath = str_replace($_SERVER['DOCUMENT_ROOT'], '', substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views'))) . '/assets/js';
    ?>
    <script src="<?= $assetsPath ?>/formGenerator.js?v=<?= time() ?>" defer></script>
</div>