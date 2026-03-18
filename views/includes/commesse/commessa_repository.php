<?php
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}
$canEdit = userHasPermission('edit_commessa');
?>
<link rel="stylesheet" href="/assets/css/commessa_repository.css">

<div class="repo-container" id="repoContainer" data-project="<?= htmlspecialchars($tabella) ?>">
    <div class="repo-layout">
        <!-- Sidebar cartelle -->
        <div class="repo-sidebar">
            <div class="repo-sidebar-header">
                <span class="repo-sidebar-title">Cartelle</span>
                <span class="repo-folder-count" id="repoFolderCount">0</span>
            </div>
            <div class="repo-folder-list" id="repoFolderList">
                <div class="repo-loading">Caricamento...</div>
            </div>
        </div>

        <!-- Pannello file -->
        <div class="repo-content">
            <div class="repo-content-header" id="repoContentHeader">
                <div class="repo-content-title" id="repoContentTitle">Seleziona una cartella</div>
                <div class="repo-content-actions">
                    <?php if ($canEdit): ?>
                    <label class="btn btn-secondary repo-upload-btn" id="repoUploadBtn" style="display:none">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        Carica
                        <input type="file" id="repoFileInput" multiple style="display:none">
                    </label>
                    <?php endif; ?>
                </div>
            </div>
            <div class="repo-file-list" id="repoFileList">
                <div class="repo-empty">Seleziona una cartella dalla sidebar per visualizzare i file.</div>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/media_viewer.js"></script>
<script src="/assets/js/commesse/commessa_repository.js"></script>
