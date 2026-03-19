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
        @page { size: A4; margin: 5mm 10mm !important; }
        .navbar, .fixed-sidebar, .function-bar, .bottom-bar { display: none !important; }
        .dynamic-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    }
    .gd-loading { text-align: center; padding: 60px 20px; color: #9a9893; }
    .gd-loading p { margin-top: 12px; font-size: 14px; }
    .gd-error { padding: 20px; background: #fcecea; border: 1px solid #f5c6cb; border-radius: 10px; color: #8f2a1f; margin-bottom: 20px; }
</style>
<link rel="stylesheet" href="/assets/css/gare.css">

<div class="main-container">
    <div class="gare-detail-root" id="gare-detail-root"
         data-gara-id="<?= $garaId ?: '' ?>"
         data-job-id="<?= $jobId ?: '' ?>">

        <!-- Loading state -->
        <div id="gd-loading" class="gd-loading">
            <div class="spinner"><div class="spinner-border"></div></div>
            <p>Caricamento dettaglio gara...</p>
        </div>

        <!-- Error state -->
        <div id="gd-error" class="gd-error" style="display:none"></div>

        <!-- Intestazione + API Status info icon -->
        <div id="gd-header" style="display:none"></div>
        <div id="api-status-container" class="no-print"></div>

        <!-- Panoramica -->
        <div id="gd-overview" style="display:none"></div>

        <!-- Importi e valori economici -->
        <div id="gd-importi" style="display:none"></div>

        <!-- Requisiti tecnico-professionali -->
        <div id="gd-requisiti" style="display:none"></div>

        <!-- Requisiti economico-finanziari -->
        <div id="gd-economici" style="display:none"></div>

        <!-- Documentazione e ruoli -->
        <div id="gd-docs-ruoli" style="display:none"></div>

        <!-- Tutti i campi — vista tabella fallback -->
        <div id="gd-all-fields" style="display:none"></div>

        <!-- Batch Usage Info -->
        <div id="batch-usage-container"></div>

        <!-- Action bar -->
        <div id="gd-actions" style="display:none"></div>

        <!-- Print layout (populated by JS) -->
        <div class="jobs-print print-only" id="gare-print-root"></div>
    </div>
</div>

<script src="/assets/js/gare_detail.js" defer></script>
