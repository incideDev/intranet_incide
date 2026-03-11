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
</style>
<link rel="stylesheet" href="/assets/css/gare.css">

<div class="main-container">
    <div class="gare-detail-wrapper" id="gare-detail-root" data-gara-id="<?= $garaId ?: '' ?>" data-job-id="<?= $jobId ?: '' ?>">
        <h1>Dettaglio Gara</h1>

        <!-- Commessa collegata -->
        <div id="gare-commessa-widget" style="display:none;margin-bottom:14px;max-width:440px;">
            <label for="gd-commessa" style="font-size:12px;font-weight:600;color:#586069;display:block;margin-bottom:4px;">Commessa collegata</label>
            <div style="position:relative;">
                <input type="text" id="gd-commessa"
                       placeholder="Cerca codice commessa..."
                       autocomplete="off"
                       style="width:100%;padding:7px 10px;border:1px solid #d1d5da;border-radius:6px;font-size:13px;box-sizing:border-box;">
                <div id="gd-commessa-suggestions"
                     style="position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #d1d5da;border-radius:0 0 6px 6px;z-index:200;max-height:220px;overflow-y:auto;display:none;box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>
            </div>
        </div>

        <!-- Documenti Nextcloud -->
        <div id="gare-nc-widget" style="display:none;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <span style="font-size:12px;font-weight:600;color:#586069;text-transform:uppercase;letter-spacing:.5px;">Documenti Gara (Nextcloud)</span>
                <button id="gd-nc-browse-btn" type="button"
                        style="padding:4px 12px;font-size:12px;border:1px solid #d1d5da;border-radius:6px;background:#fff;cursor:pointer;color:#24292e;">
                    + Sfoglia NC
                </button>
            </div>
            <div id="gd-nc-files-list" style="display:flex;flex-wrap:wrap;gap:8px;min-height:32px;"></div>
        </div>

        <!-- Modal browser Nextcloud -->
        <div id="gd-nc-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);">
            <div style="background:#fff;border-radius:10px;max-width:560px;width:94%;margin:60px auto;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.2);">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #e1e4e8;">
                    <strong style="font-size:15px;">Seleziona file da Nextcloud</strong>
                    <button id="gd-nc-modal-close" type="button" style="background:none;border:none;font-size:20px;cursor:pointer;color:#586069;">&times;</button>
                </div>
                <div id="gd-nc-modal-body" style="flex:1;overflow-y:auto;padding:16px 20px;">
                    <div id="gd-nc-modal-list" style="display:flex;flex-direction:column;gap:6px;"></div>
                </div>
                <div style="padding:12px 20px;border-top:1px solid #e1e4e8;text-align:right;">
                    <button id="gd-nc-modal-confirm" type="button"
                            style="padding:7px 18px;background:#0366d6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;">
                        Allega selezionati
                    </button>
                </div>
            </div>
        </div>

        <div class="jobs-container hidden" id="gare-jobs"></div>
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
