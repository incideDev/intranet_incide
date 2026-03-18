/**
 * Repository Docs — Navigazione cartelle/file Nextcloud per commessa
 */
const RepoDoc = (function() {
    'use strict';

    let idProject = null;
    let folders = [];
    let currentFolder = null;
    let currentFiles = [];
    let moveDropdown = null;

    // ── AJAX ──
    async function sendRequest(action, params = {}) {
        const csrf = document.querySelector('meta[name="token-csrf"]')?.content || '';
        try {
            const resp = await fetch('/ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Csrf-Token': csrf },
                body: JSON.stringify({ section: 'elenco_documenti', action, ...params })
            });
            return await resp.json();
        } catch (e) {
            console.error('RepoDoc request error:', e);
            return { success: false, message: e.message };
        }
    }

    // ── HELPERS ──
    function fileIcon(name) {
        const ext = (name || '').split('.').pop().toLowerCase();
        const map = { pdf:'📕', doc:'📘', docx:'📘', xls:'📗', xlsx:'📗',
                      dwg:'📐', dxf:'📐', jpg:'🖼️', jpeg:'🖼️', png:'🖼️',
                      zip:'🗜️', rar:'🗜️' };
        return map[ext] || '📄';
    }

    function fmtSize(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // ── LOAD FOLDERS ──
    async function loadFolders() {
        const list = document.getElementById('repoFolderList');
        list.innerHTML = '<div class="repo-loading">Caricamento...</div>';

        const result = await sendRequest('listRepoFolders', { idProject });
        if (!result.success) {
            list.innerHTML = `<div class="repo-empty">${escapeHtml(result.message)}</div>`;
            return;
        }

        folders = result.data || [];
        document.getElementById('repoFolderCount').textContent = folders.length;

        if (folders.length === 0) {
            list.innerHTML = '<div class="repo-empty">Nessuna cartella. Definisci i documenti nell\'elenco elaborati.</div>';
            return;
        }

        list.innerHTML = folders.map((f, i) => `
            <div class="repo-folder-item" data-idx="${i}" onclick="RepoDoc.selectFolder(${i})">
                <span class="repo-folder-title" title="${escapeHtml(f.name)}">${escapeHtml(f.title || f.code)}</span>
                <span class="repo-folder-code">${escapeHtml(f.shortCode)}</span>
            </div>
        `).join('');

        // Auto-select prima cartella
        selectFolder(0);
    }

    // ── SELECT FOLDER ──
    async function selectFolder(idx) {
        const folder = folders[idx];
        if (!folder) return;
        currentFolder = folder;

        // UI: evidenzia
        document.querySelectorAll('.repo-folder-item').forEach((el, i) => {
            el.classList.toggle('active', i === idx);
        });

        // Header
        document.getElementById('repoContentTitle').textContent = folder.title || folder.code;
        const uploadBtn = document.getElementById('repoUploadBtn');
        if (uploadBtn) uploadBtn.style.display = '';

        // Load files
        const list = document.getElementById('repoFileList');
        list.innerHTML = '<div class="repo-loading">Caricamento file...</div>';

        const result = await sendRequest('listRepoFiles', { idProject, folder: folder.name });
        if (!result.success) {
            list.innerHTML = `<div class="repo-empty">${escapeHtml(result.message)}</div>`;
            return;
        }

        currentFiles = result.data || [];
        renderFiles();
    }

    // ── RENDER FILES ──
    function renderFiles() {
        const list = document.getElementById('repoFileList');
        const canEdit = window.userHasPermission && window.userHasPermission('edit_commessa');

        if (currentFiles.length === 0) {
            list.innerHTML = '<div class="repo-empty">Nessun file. Carica file o aggiungili da Nextcloud.</div>';
            return;
        }

        list.innerHTML = currentFiles.map((f, i) => `
            <div class="repo-file-item" data-idx="${i}" onclick="RepoDoc.previewFile(${i})">
                <span class="repo-file-icon">${fileIcon(f.name)}</span>
                <div class="repo-file-info">
                    <div class="repo-file-name" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</div>
                    <div class="repo-file-meta">${fmtSize(f.size)}${f.lastModified ? ' · ' + f.lastModified : ''}</div>
                </div>
                <div class="repo-file-actions" onclick="event.stopPropagation()">
                    <a class="repo-file-action" href="${f.fileUrl}" target="_blank" title="Download" onclick="event.stopPropagation()">↓</a>
                    ${canEdit ? `
                    <button class="repo-file-action" onclick="event.stopPropagation();RepoDoc.openMoveMenu(event,${i})" title="Sposta">⇄</button>
                    <button class="repo-file-action danger" onclick="event.stopPropagation();RepoDoc.deleteFile(${i})" title="Elimina">×</button>
                    ` : ''}
                </div>
            </div>
        `).join('');
    }

    // ── PREVIEW ──
    function previewFile(idx) {
        const file = currentFiles[idx];
        if (!file) return;
        if (typeof window.showMediaViewer === 'function') {
            window.showMediaViewer(file.fileUrl, { title: file.name });
        } else {
            window.open(file.fileUrl, '_blank');
        }
    }

    // ── MOVE ──
    function openMoveMenu(e, fileIdx) {
        closeMoveMenu();
        const file = currentFiles[fileIdx];
        if (!file) return;

        const dd = document.createElement('div');
        dd.className = 'repo-move-dropdown';
        dd.style.left = e.clientX + 'px';
        dd.style.top = e.clientY + 'px';

        const otherFolders = folders.filter(f => f.name !== currentFolder.name);
        if (otherFolders.length === 0) {
            dd.innerHTML = '<div class="repo-move-header">Nessuna altra cartella disponibile</div>';
        } else {
            dd.innerHTML = `
                <div class="repo-move-header">Sposta in:</div>
                ${otherFolders.map(f => `
                    <div class="repo-move-item" onclick="RepoDoc.moveFile(${fileIdx}, '${f.name.replace(/'/g, "\\'")}')">${escapeHtml(f.title || f.code)}</div>
                `).join('')}
            `;
        }

        document.body.appendChild(dd);
        moveDropdown = dd;

        setTimeout(() => {
            document.addEventListener('click', closeMoveMenu, { once: true });
        }, 10);
    }

    function closeMoveMenu() {
        if (moveDropdown) {
            moveDropdown.remove();
            moveDropdown = null;
        }
    }

    async function moveFile(fileIdx, toFolder) {
        const file = currentFiles[fileIdx];
        if (!file) return;
        closeMoveMenu();

        const result = await sendRequest('moveRepoFile', {
            idProject, fromPath: file.path, toFolder
        });

        if (result.success) {
            currentFiles.splice(fileIdx, 1);
            renderFiles();
        } else {
            alert(result.message || 'Errore spostamento');
        }
    }

    // ── DELETE ──
    async function deleteFile(idx) {
        const file = currentFiles[idx];
        if (!file) return;
        if (!confirm(`Eliminare "${file.name}" da Nextcloud? L'azione non è reversibile.`)) return;

        const result = await sendRequest('deleteRepoFile', {
            idProject, filePath: file.path
        });

        if (result.success) {
            currentFiles.splice(idx, 1);
            renderFiles();
        } else {
            alert(result.message || 'Errore eliminazione');
        }
    }

    // ── UPLOAD ──
    async function handleUpload(input) {
        if (!currentFolder || !input.files.length) return;

        for (const file of input.files) {
            const formData = new FormData();
            formData.append('section', 'elenco_documenti');
            formData.append('action', 'uploadRepoFile');
            formData.append('idProject', idProject);
            formData.append('folder', currentFolder.name);
            formData.append('file', file);

            const csrf = document.querySelector('meta[name="token-csrf"]')?.content || '';
            try {
                const resp = await fetch('/ajax.php', {
                    method: 'POST',
                    headers: { 'X-Csrf-Token': csrf },
                    body: formData
                });
                const result = await resp.json();
                if (!result.success) {
                    alert('Errore upload: ' + (result.message || ''));
                }
            } catch (e) {
                alert('Errore di rete');
            }
        }

        input.value = '';
        // Ricarica file della cartella corrente
        const idx = folders.indexOf(currentFolder);
        if (idx >= 0) selectFolder(idx);
    }

    // ── INIT ──
    function init() {
        const container = document.getElementById('repoContainer');
        if (!container) return;
        idProject = container.dataset.project || '';
        if (!idProject) return;

        const fileInput = document.getElementById('repoFileInput');
        if (fileInput) {
            fileInput.addEventListener('change', () => handleUpload(fileInput));
        }

        loadFolders();
    }

    document.addEventListener('DOMContentLoaded', init);

    return {
        selectFolder,
        previewFile,
        openMoveMenu,
        moveFile,
        deleteFile
    };
})();
