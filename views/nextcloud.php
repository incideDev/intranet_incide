<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    include("page-errors/404.php");
    die();
}

if (!userHasPermission('view_nextcloud')) {
    checkPermissionOrWarn('view_nextcloud');
    return;
}
?>
<!-- Styles -->
<style>
    .nc-container {
        padding: 20px;
    }

    .nc-browser {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        overflow: hidden;
        border: 1px solid #e1e4e8;
    }

    .nc-toolbar {
        padding: 15px;
        border-bottom: 1px solid #eee;
        display: flex;
        align-items: center;
        gap: 12px;
        background: #f8f9fa;
    }

    .nc-path-input {
        flex: 1;
        padding: 10px 14px;
        border: 1px solid #d1d5da;
        border-radius: 6px;
        font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
        font-size: 14px;
        background: #fff;
        color: #24292e;
    }

    .nc-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 20px;
        padding: 25px;
    }

    .nc-item {
        cursor: pointer;
        border: 1px solid transparent;
        border-radius: 8px;
        padding: 12px;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        text-align: center;
        position: relative;
        background: #fff;
    }

    .nc-item:hover {
        background: #f1f8ff;
        border-color: #0366d633;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(3, 102, 214, 0.08);
    }

    .nc-icon {
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
    }

    .nc-thumb-container {
        width: 100%;
        height: 120px;
        border-radius: 6px;
        margin-bottom: 10px;
        background: #f6f8fa;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #f1f1f1;
    }

    .nc-thumb {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .nc-item:hover .nc-thumb {
        transform: scale(1.05);
    }

    .nc-name {
        font-size: 14px;
        color: #24292e;
        font-weight: 500;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100%;
        display: block;
        margin-top: 4px;
    }

    .nc-meta {
        font-size: 11px;
        color: #586069;
        display: block;
        margin-top: 2px;
    }

    .nc-loading {
        padding: 80px;
        text-align: center;
        color: #586069;
        font-size: 16px;
    }

    .nc-loading i {
        font-size: 32px;
        margin-bottom: 15px;
        color: #0366d6;
    }

    .nc-error {
        padding: 30px;
        color: #cb2431;
        background: #ffeef0;
        border: 1px solid #f9758333;
        margin: 30px;
        border-radius: 8px;
        text-align: center;
    }

    .nc-breadcrumb {
        padding: 12px 15px;
        background: #fff;
        border-bottom: 1px solid #e1e4e8;
        font-size: 14px;
        color: #586069;
        display: flex;
        align-items: center;
        gap: 5px;
        overflow-x: auto;
        white-space: nowrap;
    }

    .nc-breadcrumb span {
        cursor: pointer;
        color: #0366d6;
        padding: 2px 6px;
        border-radius: 4px;
        transition: background 0.2s;
    }

    .nc-breadcrumb span:hover {
        background: #f1f8ff;
        text-decoration: underline;
    }

    .nc-breadcrumb b {
        color: #24292e;
        font-weight: 600;
        padding: 2px 6px;
    }

    /* Animazione fade-in */
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .nc-grid {
        animation: fadeIn 0.3s ease-out;
    }

    .button.icon-btn {
        padding: 8px 12px;
    }
</style>

<div class="main-container">
    <div class="nc-container">
        <div class="nc-header mb-4">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h1 class="mb-1" style="font-weight: 800; letter-spacing: -0.5px; color: #1a1f23;">
                        <img src="assets/icons/cloud_col.png" style="width: 24px; height: 24px; vertical-align: middle; margin-right: 8px;"> Nextcloud
                    </h1>
                    <p class="text-muted" style="font-size: 15px; margin-left: 45px;">Accesso rapido ai file aziendali
                        condivisi</p>
                </div>
                <div class="nc-stats text-right" id="nc-stats-info" style="font-size: 13px; color: #6a737d;">
                    <!-- Stats loaded dynamically -->
                </div>
            </div>
        </div>

        <div class="nc-browser">
            <div class="nc-toolbar">
                <button class="button icon-btn secondary d-flex align-items-center justify-content-center" id="nc-back-btn" title="Su di un livello"
                    data-tooltip="Torna alla cartella superiore" style="width: 26px; height: 26px;">
                    <img src="assets/icons/ls.png" style="width: 12px; height: 12px; display: block; justify-self: center;">
                </button>
                <input type="text" id="nc-path-display" class="nc-path-input" readonly value="/INTRANET/">
                <button class="button secondary" id="nc-refresh-btn" data-tooltip="Ricarica i contenuti da Nextcloud">
                    <img src="assets/icons/sync.png" style="width: 14px; height: 14px; vertical-align: middle; margin-right: 5px;"> Aggiorna
                </button>
            </div>
            <div id="nc-breadcrumb-container" class="nc-breadcrumb"></div>

            <div id="nc-view-container">
                <!-- Content loaded via JS -->
                <div class="nc-loading">
                    <img src="assets/icons/circled_notch.png" class="fa-spin" style="width: 32px; height: 32px; margin-bottom: 15px;">
                    <p>Connessione a Nextcloud in corso...</p>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Inclusione Media Viewer -->
<script src="assets/js/media_viewer.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Read initial path from URL or default to root
        const urlParams = new URLSearchParams(window.location.search);
        let initialPath = urlParams.get('path');

        // Validation and normalization of the path
        if (!initialPath || !initialPath.startsWith('/INTRANET/')) {
            initialPath = '/INTRANET/';
        }
        
        // Ensure folder paths end with a slash if they don't look like files
        if (!initialPath.endsWith('/') && !initialPath.includes('.')) {
             initialPath += '/';
        }

        let currentPath = initialPath;
        const container = document.getElementById('nc-view-container');
        const pathDisplay = document.getElementById('nc-path-display');
        const breadcrumbContainer = document.getElementById('nc-breadcrumb-container');
        const backBtn = document.getElementById('nc-back-btn');
        const refreshBtn = document.getElementById('nc-refresh-btn');
        const statsInfo = document.getElementById('nc-stats-info');

        async function loadFolder(path) {
            currentPath = path;
            pathDisplay.value = path;
            updateBreadcrumbs(path);

            container.innerHTML = `
            <div class="nc-loading">
                <img src="assets/icons/circled_notch.png" class="fa-spin" style="width: 32px; height: 32px; margin-bottom: 15px;">
                <p>Recupero contenuti di <b>${path.split('/').filter(Boolean).pop() || 'Root'}</b>...</p>
            </div>
        `;

            try {
                const formData = new FormData();
                formData.append('section', 'nextcloud');
                formData.append('action', 'list');
                formData.append('path', path);
                formData.append('csrf_token', document.querySelector('meta[name="token-csrf"]').content);

                const response = await fetch('ajax.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);

                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    console.error("Invalid JSON:", text);
                    throw new Error("Risposta server non valida (non è JSON). Controlla la console.");
                }

                if (!result.success) {
                    throw new Error(result.message || 'Errore durante il caricamento');
                }

                renderItems(result.data);
                updateStats(result.data);
            } catch (err) {
                container.innerHTML = `
                <div class="nc-error">
                    <img src="assets/icons/error.png" style="width: 48px; height: 48px; margin-bottom: 15px;">
                    <p><b>Non è stato possibile caricare i contenuti:</b></p>
                    <code>${err.message}</code>
                    <div class="mt-3">
                        <button class="button" onclick="window.ncLoad(null)">Riprova</button>
                    </div>
                </div>`;
            }
        }

        function handleFileClick(item) {
            const url = `ajax.php?section=nextcloud&action=file&path=${encodeURIComponent(item.path)}`;
            
            const mime = item.mime || '';
            const isImage = mime.startsWith('image/');
            const isVideo = mime.startsWith('video/');
            const isPdf = mime === 'application/pdf';
            const isText = mime.startsWith('text/');

            if (isImage || isVideo || isPdf || isText) {
                // Apre nel media viewer
                if (typeof window.showMediaViewer === 'function') {
                    window.showMediaViewer(url, {
                        id_documento: item.path,
                        nome_file: item.name,
                        titolo: item.name,
                        descrizione: 'File Nextcloud',
                        dataIT: item.last_modified ? new Date(item.last_modified).toLocaleDateString() : '',
                        size: formatSize(item.size),
                        mime_type: item.mime
                    });
                } else {
                     console.warn('showMediaViewer non disponibile, fallback download');
                     window.open(url, '_blank');
                }
            } else {
                // Fallback download diretto in nuova scheda
                window.open(url, '_blank');
            }
        }

        function renderItems(items) {
            if (!items || items.length === 0) {
                // Icona folder vuota (riutilizziamo folder.png o file_doc.png come generico, qui uso folder.png per coerenza tema)
                container.innerHTML = '<div class="nc-loading"><img src="assets/icons/file_folder.png" style="width: 48px; height: 48px; margin-bottom: 10px; opacity: 0.5;"><p>Questa cartella è vuota</p></div>';
                
                if (window.currentDocumentsList) window.currentDocumentsList = [];
                return;
            }

            // Popola lista globale per navigazione Media Viewer
            window.currentDocumentsList = items.filter(i => !i.is_dir).map(i => ({
                id: i.path,
                path: `ajax.php?section=nextcloud&action=file&path=${encodeURIComponent(i.path)}`,
                nome_file: i.name,
                titolo: i.name,
                descrizione: '',
                data_caricamento: i.last_modified,
                size: i.size,
                mime_type: i.mime
            }));

            const grid = document.createElement('div');
            grid.className = 'nc-grid';

            items.forEach(item => {
                const div = document.createElement('div');
                div.className = 'nc-item';
                div.title = item.name;
                div.setAttribute('data-tooltip', item.is_dir ? `Cartella: ${item.name}` : `Documento: ${item.name} (${formatSize(item.size)})`);

                let contentHtml = '';
                
                if (item.is_dir) {
                    contentHtml = `<img src="assets/icons/file_folder.png" class="nc-icon" style="width: 52px; height: 52px; object-fit: contain;">`;
                    div.onclick = () => loadFolder(item.path);
                } else if (item.mime.startsWith('image/')) {
                    const thumbUrl = `ajax.php?section=nextcloud&action=thumb&path=${encodeURIComponent(item.path)}&w=400&h=300`;
                    contentHtml = `<div class="nc-thumb-container"><img src="${thumbUrl}" class="nc-thumb" alt="${item.name}" loading="lazy"></div>`;
                    div.onclick = () => handleFileClick(item);
                } else {
                    let iconSrc = 'assets/icons/file_doc.png'; // Default
                    const mime = item.mime || '';
                    
                    if (mime.includes('pdf')) iconSrc = 'assets/icons/file_pdf.png';
                    else if (mime.includes('word') || mime.includes('officedocument.word')) iconSrc = 'assets/icons/file_word.png';
                    else if (mime.includes('excel') || mime.includes('spreadsheet')) iconSrc = 'assets/icons/file_excel.png';
                    else if (mime.includes('zip') || mime.includes('compressed') || mime.includes('tar') || mime.includes('rar')) iconSrc = 'assets/icons/file_zip.png';
                    // Altri tipi se necessario, altrimenti file_doc.png (che sarebbe il 'file.png' generico richiesto per '�' ma 'folder' è per cartelle, qui intendo file generico)
                    
                    contentHtml = `<div style="margin-bottom: 10px;"><img src="${iconSrc}" style="width: 60px; height: 60px; object-fit: contain;"></div>`;
                    div.onclick = () => handleFileClick(item);
                }

                const lastModLabel = item.last_modified ? new Date(item.last_modified).toLocaleDateString() : '';

                div.innerHTML = `
                ${contentHtml}
                <span class="nc-name">${item.name}</span>
                <span class="nc-meta">${item.is_dir ? 'Cartella' : formatSize(item.size)} ${lastModLabel ? ' • ' + lastModLabel : ''}</span>
            `;
                grid.appendChild(div);
            });

            container.innerHTML = '';
            container.appendChild(grid);
        }

        function updateBreadcrumbs(path) {
            const parts = path.split('/').filter(Boolean);
            let html = '<span onclick="window.ncLoad(\'/INTRANET/\')"><img src="assets/icons/home_col.png" style="width: 14px; height: 14px; vertical-align: bottom;"> Root</span>';
            let current = '/';

            let startIdx = 0;
            if (parts[0] === 'INTRANET') {
                current = '/INTRANET/';
                startIdx = 1;
            }

            for (let i = startIdx; i < parts.length; i++) {
                current += parts[i] + '/';
                if (i === parts.length - 1) {
                    html += ` <img src="assets/icons/r_arrow.png" style="width: 10px; height: 10px; margin: 0 4px; opacity: 0.5;"> <b>${parts[i]}</b>`;
                } else {
                    html += ` <img src="assets/icons/r_arrow.png" style="width: 10px; height: 10px; margin: 0 4px; opacity: 0.5;"> <span onclick="window.ncLoad('${current}')">${parts[i]}</span>`;
                }
            }
            breadcrumbContainer.innerHTML = html;

            backBtn.disabled = (path === '/INTRANET/' || path === '/INTRANET');
            backBtn.style.opacity = backBtn.disabled ? '0.5' : '1';
        }

        function updateStats(items) {
            const folders = items.filter(i => i.is_dir).length;
            const files = items.filter(i => !i.is_dir).length;
            const totalSize = items.filter(i => !i.is_dir).reduce((sum, i) => sum + i.size, 0);

            statsInfo.innerHTML = `${folders} cartelle, ${files} file • Totale: ${formatSize(totalSize)}`;
        }

        backBtn.onclick = () => {
            if (currentPath === '/INTRANET/' || currentPath === '/INTRANET') return;
            const parts = currentPath.split('/').filter(Boolean);
            parts.pop();
            let parent = '/' + parts.join('/') + '/';
            if (!parent.startsWith('/INTRANET/')) parent = '/INTRANET/';
            loadFolder(parent);
        };

        refreshBtn.onclick = () => loadFolder(currentPath);

        window.ncLoad = (path) => loadFolder(path || currentPath);

        function formatSize(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        // Initial load using the potentially deep link path
        loadFolder(initialPath, false);
    });
</script>