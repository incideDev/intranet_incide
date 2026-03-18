<?php
if (!defined('hostdbdataconnector')) define('hostdbdataconnector', true);
if (!defined('accessofileinterni')) define('accessofileinterni', true);

if ($Session->logged_in !== true) {
    header("Location: /index");
    exit;
}

if (!checkPermissionOrWarn('view_gare')) return;

$garaId = isset($_GET['gara_id']) ? (int)$_GET['gara_id'] : null;
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : null;

if (!$garaId && !$jobId) {
    echo '<div class="main-container"><div class="alert alert-warning">Parametro mancante.</div></div>';
    return;
}
?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
    @media print {
        @page {
            size: A4;
            margin: 5mm 10mm !important;
        }
    }

    /* API Status & Batch Usage */
    .api-status-section { margin-bottom: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; }
    .api-status-header { padding: 0.5rem 1rem; cursor: pointer; background: #f8f9fa; }
    .api-status-header h4 { margin: 0; font-size: 0.9rem; }
    .api-status-section.collapsed .api-status-body { display: none; }
    .api-status-body { padding: 0.5rem 1rem; }
    .quota-bar { height: 8px; background: #e9ecef; border-radius: 4px; margin: 0.5rem 0; }
    .quota-bar-fill { height: 100%; background: #28a745; border-radius: 4px; transition: width 0.3s; }
    .batch-usage-info { display: flex; gap: 1.5rem; padding: 0.5rem 0; color: #6c757d; font-size: 0.85rem; }
    .highlighted-pdf-links { margin-top: 0.5rem; }
    .highlighted-pdf-links .btn { margin-right: 0.25rem; }
</style>
<link rel="stylesheet" href="/assets/css/gare.css">

<div class="main-container">
    <?php renderPageTitle('Dettaglio Gara', '#3498DB'); ?>
    <div class="gare-detail-wrapper" id="gare-detail-root" data-gara-id="<?= $garaId ?: '' ?>" data-job-id="<?= $jobId ?: '' ?>">
        <!-- API Status Section (populated by gare_detail.js) -->
        <div id="api-status-container"></div>

        <div class="jobs-container hidden" id="gare-jobs"></div>

        <!-- Batch Usage Info (populated by gare_detail.js) -->
        <div id="batch-usage-container"></div>
    </div>
</div>

<div id="modalAddGaraOverlay" class="gare-modal-overlay"></div>
<div id="modalAddGara" class="gare-modal">
    <div class="gare-modal-content">
        <button type="button" class="gare-modal-close" id="gare-modal-close" aria-label="Chiudi">×</button>
        <h2>Nuova estrazione PDF</h2>
        <form id="gareUploadForm" class="gare-upload-form" enctype="multipart/form-data">
            <div class="gare-upload-area" id="gareUploadArea">
                <input type="file" id="gareUploadInput" name="file[]" accept="application/pdf" multiple hidden>
                <div class="icon">📄</div>
                <strong>Trascina i PDF qui</strong>
                <span>oppure <a href="#" id="gareUploadSelect">clicca per selezionare</a></span>
            </div>

            <div class="gare-selected-files hidden" id="gareSelectedFiles">
                <ul id="gareSelectedList"></ul>
            </div>

            <div class="gare-form-actions">
                <button type="submit" id="gareUploadSubmit" disabled>Carica</button>
                <button type="button" id="gareUploadCancel">Annulla</button>
            </div>
        </form>

        <div class="gare-upload-status hidden" id="gareUploadStatus">
            <ul id="gareUploadStatusList"></ul>
        </div>
    </div>
</div>

<script src="/assets/js/gare_list.js" defer></script>
<script src="/assets/js/gare_detail.js" defer></script>
