# Repository Docs — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collegare la tab "Repository Docs" della commessa a Nextcloud, mostrando le cartelle create dall'elenco elaborati con i file reali, anteprima, upload, spostamento e eliminazione file.

**Architecture:** La tab repository legge le sottocartelle di `/INTRANET/ELABORATI/{idProject}/` via NextcloudService. Il JS gestisce sidebar cartelle + pannello file. L'anteprima usa `window.showMediaViewer()` gia esistente. Il popover file nell'elenco elaborati riusa la stessa logica.

**Tech Stack:** PHP, Vanilla JS (IIFE), CSS, NextcloudService (WebDAV), showMediaViewer

**Spec:** `docs/superpowers/specs/2026-03-16-repository-docs-design.md`

---

## File Structure

| File | Tipo | Responsabilita |
|------|------|---------------|
| `services/ElencoDocumentiService.php` | Modify | 5 nuove actions: listRepoFolders, listRepoFiles, moveRepoFile, deleteRepoFile, uploadRepoFile |
| `views/includes/commesse/commessa_repository.php` | Rewrite | Vista PHP con layout sidebar+content, include JS/CSS |
| `assets/js/commesse/commessa_repository.js` | Create | Logica: caricamento cartelle/file, upload, sposta, elimina, preview |
| `assets/css/commessa_repository.css` | Create | Stili layout repository |
| `assets/js/elenco_documenti.js` | Modify | Popover file nella colonna File (click conteggio → lista con azioni) |

---

## Chunk 1: Backend — 5 nuove actions

### Task 1: listRepoFolders + listRepoFiles

**Files:**
- Modify: `services/ElencoDocumentiService.php` (handleAction + nuovi metodi)

- [ ] **Step 1: Leggere handleAction() e la sezione Nextcloud**

Leggere `services/ElencoDocumentiService.php` righe 25-95 (handleAction) e righe 915-980 (sezione NC).

- [ ] **Step 2: Aggiungere cases in handleAction()**

Dopo il case `deleteNcFile` (~riga 91), aggiungere:
```php
// ── Repository ──────────────────────────────────────────
case 'listRepoFolders':
    $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    return self::listRepoFolders($idProject);

case 'listRepoFiles':
    $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $folder = filter_var($input['folder'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    return self::listRepoFiles($idProject, $folder);

case 'moveRepoFile':
    return self::moveRepoFile($input);

case 'deleteRepoFile':
    return self::deleteRepoFile($input);

case 'uploadRepoFile':
    return self::uploadRepoFile($input);
```

- [ ] **Step 3: Implementare listRepoFolders()**

Aggiungere prima della chiusura della classe, dopo exportExcel:
```php
// ─────────────────────────────────────────────────────────────
// 11. REPOSITORY — Navigazione cartelle/file per la tab Repository
// ─────────────────────────────────────────────────────────────

/**
 * Lista le sottocartelle nella directory del progetto su Nextcloud.
 * Ogni cartella corrisponde a un documento dell'elenco elaborati.
 * Parsa il nome cartella: "{CODICE} - {TITOLO}" → codice + titolo separati.
 */
private static function listRepoFolders(string $idProject): array
{
    if (!userHasPermission('view_commesse')) {
        return ['success' => false, 'message' => 'Permesso negato'];
    }
    if (!$idProject) {
        return ['success' => false, 'message' => 'idProject mancante'];
    }

    try {
        \Services\Nextcloud\NextcloudService::init();
        $basePath = self::NC_ROOT . $idProject . '/';
        \Services\Nextcloud\NextcloudService::ensureFolderExists($basePath);
        $items = \Services\Nextcloud\NextcloudService::listFolder($basePath);

        $folders = [];
        foreach ($items as $item) {
            if (!($item['is_dir'] ?? false)) continue;
            $name = $item['name'] ?? '';
            if ($name === '' || $name === $idProject) continue;

            // Parse: "PRJ-FASE-ZONA-DISC-TIPO-NUM-REV - Titolo"
            $parts = explode(' - ', $name, 2);
            $code = $parts[0] ?? $name;
            $title = $parts[1] ?? '';

            // Codice breve: rimuovi prefisso progetto
            $shortCode = $code;
            if (str_starts_with($code, $idProject . '-')) {
                $shortCode = substr($code, strlen($idProject) + 1);
            }

            $folders[] = [
                'name' => $name,
                'code' => $code,
                'shortCode' => $shortCode,
                'title' => $title,
                'path' => $basePath . $name . '/',
            ];
        }

        // Ordina per codice
        usort($folders, fn($a, $b) => strcmp($a['code'], $b['code']));

        return ['success' => true, 'data' => $folders];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Lista i file dentro una specifica sottocartella del progetto.
 */
private static function listRepoFiles(string $idProject, string $folder): array
{
    if (!userHasPermission('view_commesse')) {
        return ['success' => false, 'message' => 'Permesso negato'];
    }
    if (!$idProject || !$folder) {
        return ['success' => false, 'message' => 'idProject e folder obbligatori'];
    }

    try {
        \Services\Nextcloud\NextcloudService::init();
        $folderPath = self::NC_ROOT . $idProject . '/' . $folder . '/';

        // Valida path
        if (strpos($folder, '..') !== false || strpos($folder, '/') !== false) {
            return ['success' => false, 'message' => 'Nome cartella non valido'];
        }

        $items = \Services\Nextcloud\NextcloudService::listFolder($folderPath);
        $files = [];
        foreach ($items as $item) {
            if ($item['is_dir'] ?? false) continue;
            $files[] = [
                'name' => $item['name'] ?? '',
                'path' => $item['path'] ?? '',
                'size' => $item['size'] ?? 0,
                'mime' => $item['mime'] ?? '',
                'lastModified' => $item['last_modified'] ?? '',
                'fileUrl' => 'ajax.php?section=nextcloud&action=file&path=' . urlencode($item['path'] ?? ''),
            ];
        }

        return ['success' => true, 'data' => $files, 'folder' => $folder];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
```

- [ ] **Step 4: Verificare sintassi PHP**
```bash
php -l services/ElencoDocumentiService.php
```

- [ ] **Step 5: Commit**
```
feat(repository): backend listRepoFolders e listRepoFiles
```

---

### Task 2: moveRepoFile, deleteRepoFile, uploadRepoFile

**Files:**
- Modify: `services/ElencoDocumentiService.php`

- [ ] **Step 1: Implementare moveRepoFile()**

```php
/**
 * Sposta un file da una cartella a un'altra dentro il progetto.
 */
private static function moveRepoFile(array $input): array
{
    if (!userHasPermission('edit_commessa')) {
        return ['success' => false, 'message' => 'Permesso negato'];
    }

    $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $fromPath = $input['fromPath'] ?? '';
    $toFolder = filter_var($input['toFolder'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if (!$idProject || !$fromPath || !$toFolder) {
        return ['success' => false, 'message' => 'Parametri mancanti'];
    }

    // Valida che il path sia dentro la cartella del progetto
    $projectRoot = self::NC_ROOT . $idProject . '/';
    if (strpos($fromPath, $projectRoot) !== 0) {
        return ['success' => false, 'message' => 'Path non valido'];
    }
    if (strpos($toFolder, '..') !== false || strpos($toFolder, '/') !== false) {
        return ['success' => false, 'message' => 'Cartella destinazione non valida'];
    }

    $fileName = basename($fromPath);
    $toPath = $projectRoot . $toFolder . '/' . $fileName;

    try {
        \Services\Nextcloud\NextcloudService::init();
        \Services\Nextcloud\NextcloudService::movePath($fromPath, $toPath);
        return ['success' => true];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'Errore spostamento: ' . $e->getMessage()];
    }
}

/**
 * Elimina un file da Nextcloud.
 */
private static function deleteRepoFile(array $input): array
{
    if (!userHasPermission('edit_commessa')) {
        return ['success' => false, 'message' => 'Permesso negato'];
    }

    $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $filePath = $input['filePath'] ?? '';

    if (!$idProject || !$filePath) {
        return ['success' => false, 'message' => 'Parametri mancanti'];
    }

    $projectRoot = self::NC_ROOT . $idProject . '/';
    if (strpos($filePath, $projectRoot) !== 0) {
        return ['success' => false, 'message' => 'Path non valido'];
    }

    try {
        \Services\Nextcloud\NextcloudService::init();
        \Services\Nextcloud\NextcloudService::deletePath($filePath);
        return ['success' => true];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'Errore eliminazione: ' . $e->getMessage()];
    }
}

/**
 * Carica un file in una specifica sottocartella del progetto.
 */
private static function uploadRepoFile(array $input): array
{
    if (!userHasPermission('edit_commessa')) {
        return ['success' => false, 'message' => 'Permesso negato'];
    }

    $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $folder = filter_var($input['folder'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if (!$idProject || !$folder) {
        return ['success' => false, 'message' => 'idProject e folder obbligatori'];
    }

    if (strpos($folder, '..') !== false || strpos($folder, '/') !== false) {
        return ['success' => false, 'message' => 'Nome cartella non valido'];
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File non ricevuto'];
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $origName = basename($_FILES['file']['name']);
    $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $origName);
    $remotePath = self::NC_ROOT . $idProject . '/' . $folder . '/' . $safeName;

    try {
        \Services\Nextcloud\NextcloudService::init();
        \Services\Nextcloud\NextcloudService::uploadFile($tmpPath, $remotePath);
        return ['success' => true, 'data' => ['name' => $safeName, 'path' => $remotePath]];
    } catch (\Exception $e) {
        return ['success' => false, 'message' => 'Errore upload: ' . $e->getMessage()];
    }
}
```

- [ ] **Step 2: Verificare sintassi PHP**
```bash
php -l services/ElencoDocumentiService.php
```

- [ ] **Step 3: Commit**
```
feat(repository): backend moveRepoFile, deleteRepoFile, uploadRepoFile
```

---

## Chunk 2: Frontend — Tab Repository

### Task 3: Vista PHP (commessa_repository.php)

**Files:**
- Rewrite: `views/includes/commesse/commessa_repository.php`

- [ ] **Step 1: Riscrivere la vista**

Sostituire l'intero file con:
```php
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
```

- [ ] **Step 2: Verificare sintassi PHP**
```bash
php -l views/includes/commesse/commessa_repository.php
```

- [ ] **Step 3: Commit**
```
feat(repository): vista PHP con layout sidebar+content
```

---

### Task 4: CSS repository

**Files:**
- Create: `assets/css/commessa_repository.css`

- [ ] **Step 1: Creare il file CSS**

```css
/* Repository Docs — layout e stili */

.repo-container {
    padding: 0;
}

.repo-layout {
    display: flex;
    border: 1px solid #e2e4e8;
    border-radius: 6px;
    background: #fff;
    min-height: 400px;
    overflow: hidden;
}

/* Sidebar */
.repo-sidebar {
    width: 260px;
    flex-shrink: 0;
    border-right: 1px solid #e2e4e8;
    display: flex;
    flex-direction: column;
    background: #fafbfc;
}
.repo-sidebar-header {
    padding: 10px 14px;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #6b7280;
    border-bottom: 1px solid #e2e4e8;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.repo-folder-count {
    background: #e2e4e8;
    color: #6b7280;
    font-size: 9px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 10px;
}
.repo-folder-list {
    flex: 1;
    overflow-y: auto;
    padding: 4px 0;
}
.repo-folder-item {
    display: flex;
    flex-direction: column;
    padding: 8px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f0f1f4;
    transition: background 0.1s;
}
.repo-folder-item:hover { background: #f0f1f5; }
.repo-folder-item.active { background: #e8eafe; border-left: 3px solid #6366f1; }
.repo-folder-title {
    font-size: 11px;
    font-weight: 600;
    color: #1a1d23;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.repo-folder-code {
    font-family: 'Courier New', monospace;
    font-size: 9px;
    font-weight: 700;
    color: #6b7280;
    margin-top: 1px;
}

/* Content */
.repo-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.repo-content-header {
    padding: 10px 16px;
    border-bottom: 1px solid #e2e4e8;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.repo-content-title {
    font-size: 12px;
    font-weight: 700;
    color: #1a1d23;
}
.repo-content-actions {
    display: flex;
    gap: 6px;
}
.repo-upload-btn {
    cursor: pointer;
    display: inline-flex !important;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    padding: 4px 10px;
}

.repo-file-list {
    flex: 1;
    overflow-y: auto;
    padding: 0;
}

/* File item */
.repo-file-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px;
    border-bottom: 1px solid #f0f1f4;
    transition: background 0.1s;
    cursor: pointer;
}
.repo-file-item:hover { background: #f8f9ff; }
.repo-file-icon {
    font-size: 18px;
    flex-shrink: 0;
    width: 24px;
    text-align: center;
}
.repo-file-info {
    flex: 1;
    min-width: 0;
}
.repo-file-name {
    font-size: 11px;
    font-weight: 600;
    color: #1a1d23;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.repo-file-meta {
    font-size: 9px;
    color: #6b7280;
    margin-top: 1px;
}
.repo-file-actions {
    display: flex;
    gap: 4px;
    opacity: 0;
    transition: opacity 0.15s;
}
.repo-file-item:hover .repo-file-actions { opacity: 1; }
.repo-file-action {
    width: 24px;
    height: 24px;
    border: none;
    background: none;
    cursor: pointer;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    font-size: 12px;
    transition: all 0.1s;
}
.repo-file-action:hover { background: #e2e4e8; color: #1a1d23; }
.repo-file-action.danger:hover { background: rgba(205,33,29,.1); color: #cd211d; }

/* Move dropdown */
.repo-move-dropdown {
    position: absolute;
    background: #fff;
    border: 1px solid #e2e4e8;
    border-radius: 6px;
    box-shadow: 0 8px 28px rgba(0,0,0,.14);
    z-index: 300;
    min-width: 200px;
    max-height: 250px;
    overflow-y: auto;
}
.repo-move-item {
    padding: 6px 12px;
    font-size: 10px;
    cursor: pointer;
    transition: background 0.1s;
}
.repo-move-item:hover { background: #f0f1f5; }
.repo-move-header {
    padding: 6px 12px;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    color: #6b7280;
    border-bottom: 1px solid #e2e4e8;
    background: #fafbfc;
}

/* Empty / loading */
.repo-empty, .repo-loading {
    padding: 40px;
    text-align: center;
    color: #6b7280;
    font-size: 11px;
}
```

- [ ] **Step 2: Commit**
```
feat(repository): CSS layout sidebar+content
```

---

### Task 5: JavaScript repository

**Files:**
- Create: `assets/js/commesse/commessa_repository.js`

- [ ] **Step 1: Creare il modulo JS**

```javascript
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
        dd.style.position = 'fixed';
        dd.style.left = e.clientX + 'px';
        dd.style.top = e.clientY + 'px';

        dd.innerHTML = `
            <div class="repo-move-header">Sposta "${escapeHtml(file.name)}" in:</div>
            ${folders.filter(f => f.name !== currentFolder.name).map(f => `
                <div class="repo-move-item" onclick="RepoDoc.moveFile(${fileIdx}, '${escapeHtml(f.name)}')">${escapeHtml(f.title || f.code)}</div>
            `).join('')}
        `;

        document.body.appendChild(dd);
        moveDropdown = dd;

        // Chiudi al click fuori
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
        // Ricarica file
        selectFolder(folders.indexOf(currentFolder));
    }

    // ── INIT ──
    function init() {
        const container = document.getElementById('repoContainer');
        if (!container) return;
        idProject = container.dataset.project || '';
        if (!idProject) return;

        // Upload handler
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
```

- [ ] **Step 2: Verificare sintassi JS**
```bash
node -c assets/js/commesse/commessa_repository.js
```

- [ ] **Step 3: Commit**
```
feat(repository): JS module caricamento cartelle/file, upload, sposta, elimina, preview
```

---

## Chunk 3: Popover file nell'elenco elaborati

### Task 6: Popover file nella colonna File dell'elenco elaborati

**Files:**
- Modify: `assets/js/elenco_documenti.js` (buildRowHtml + nuova funzione openFilePopover)

- [ ] **Step 1: Aggiornare la cella File in buildRowHtml()**

In `assets/js/elenco_documenti.js`, nella funzione `buildRowHtml()`, trovare la cella File e sostituirla:

Cercare:
```javascript
                <td>
                    <button class="ed-file-btn" onclick="event.stopPropagation();ElencoDoc.openProps('${doc.id}')" title="File allegati">
                        ${fileCount > 0 ? fileCount + ' file' : '—'}
                    </button>
                </td>
```

Sostituire con:
```javascript
                <td style="position:relative">
                    <button class="ed-file-btn" onclick="event.stopPropagation();ElencoDoc.openFilePopover(event,this,'${doc.id}')" title="File allegati">
                        ${fileCount > 0 ? fileCount + ' file' : '—'}
                    </button>
                </td>
```

- [ ] **Step 2: Aggiungere funzione openFilePopover()**

Aggiungere nella sezione INLINE EDITING, dopo `openNumEdit()`:

```javascript
    // ── FILE POPOVER ──
    function openFilePopover(e, btn, docId) {
        e.stopPropagation();
        closeAP();
        const doc = findDoc(docId);
        if (!doc) return;

        const files = doc.files || [];
        const canEdit = window.userHasPermission && window.userHasPermission('edit_commessa');

        const cell = btn.parentElement;
        cell.style.position = 'relative';

        const pop = document.createElement('div');
        pop.className = 'ed-popup ed-file-popover';

        let html = '<div class="ed-file-pop-header">File allegati</div>';
        if (files.length === 0) {
            html += '<div class="ed-file-pop-empty">Nessun file allegato</div>';
        } else {
            html += '<div class="ed-file-pop-list">';
            files.forEach((f, i) => {
                const name = f.name || f.path?.split('/').pop() || 'file';
                const url = f.path ? ('ajax.php?section=nextcloud&action=file&path=' + encodeURIComponent(f.path)) : '#';
                html += `<div class="ed-file-pop-item">
                    <span class="ed-file-pop-icon">${fileIcon(f.mime)}</span>
                    <a class="ed-file-pop-name" href="${url}" onclick="event.preventDefault();event.stopPropagation();if(typeof window.showMediaViewer==='function')window.showMediaViewer('${escapeHtml(url)}',{title:'${escapeHtml(name)}'});else window.open('${escapeHtml(url)}','_blank')" title="${escapeHtml(name)}">${escapeHtml(name)}</a>
                    <a class="ed-file-pop-dl" href="${url}" target="_blank" onclick="event.stopPropagation()" title="Download">↓</a>
                    ${canEdit ? `<button class="ed-file-pop-rm" onclick="event.stopPropagation();ElencoDoc.detachAndRefresh('${docId}','${escapeHtml(f.path)}')" title="Rimuovi">×</button>` : ''}
                </div>`;
            });
            html += '</div>';
        }

        if (canEdit) {
            html += `<div class="ed-file-pop-footer">
                <label class="ed-file-pop-upload">
                    + Carica file
                    <input type="file" multiple style="display:none" onchange="ElencoDoc.uploadFromPopover(this,'${docId}')">
                </label>
            </div>`;
        }

        pop.innerHTML = html;
        cell.appendChild(pop);
        activePopup = pop;
        pop.addEventListener('click', ev => ev.stopPropagation());
    }

    async function detachAndRefresh(docId, path) {
        const doc = findDoc(docId);
        if (!doc) return;
        const result = await sendRequest('detachNcFile', { idProject, docId: doc.id, path });
        if (result.success) {
            doc.files = result.data;
            closeAP();
            reRenderRow(docId);
        }
    }

    async function uploadFromPopover(input, docId) {
        const doc = findDoc(docId);
        if (!doc || !input.files.length) return;

        for (const file of input.files) {
            const formData = new FormData();
            formData.append('section', 'elenco_documenti');
            formData.append('action', 'uploadNcFile');
            formData.append('idProject', idProject);
            formData.append('docId', String(doc.id));
            formData.append('file', file);
            const csrf = document.querySelector('meta[name="token-csrf"]')?.content || '';
            try {
                const resp = await fetch('/ajax.php', {
                    method: 'POST',
                    headers: { 'X-Csrf-Token': csrf },
                    body: formData
                });
                const result = await resp.json();
                if (result.success) doc.files = result.data;
            } catch (e) { /* silenzioso */ }
        }
        input.value = '';
        closeAP();
        reRenderRow(docId);
    }
```

- [ ] **Step 3: Esporre nella public API**

Aggiungere al `return`:
```javascript
openFilePopover,
detachAndRefresh,
uploadFromPopover,
```

- [ ] **Step 4: Aggiungere CSS popover**

In `assets/css/elenco_documenti.css`, alla fine:
```css
/* File popover */
.ed-file-popover {
    position: absolute;
    top: calc(100% + 4px);
    right: 0;
    background: #fff;
    border: 1px solid #e2e4e8;
    border-radius: 6px;
    box-shadow: 0 8px 28px rgba(0,0,0,.14);
    z-index: 300;
    min-width: 220px;
    max-width: 300px;
}
.ed-file-pop-header {
    padding: 6px 10px;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    color: #6b7280;
    border-bottom: 1px solid #e2e4e8;
    background: #fafbfc;
    border-radius: 6px 6px 0 0;
}
.ed-file-pop-list { max-height: 180px; overflow-y: auto; }
.ed-file-pop-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-bottom: 1px solid #f0f1f4;
}
.ed-file-pop-icon { font-size: 14px; flex-shrink: 0; }
.ed-file-pop-name {
    flex: 1;
    font-size: 10px;
    font-weight: 600;
    color: #1a1d23;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-decoration: none;
    cursor: pointer;
}
.ed-file-pop-name:hover { color: #6366f1; }
.ed-file-pop-dl, .ed-file-pop-rm {
    width: 18px; height: 18px;
    display: flex; align-items: center; justify-content: center;
    border: none; background: none; cursor: pointer;
    color: #6b7280; font-size: 11px; border-radius: 3px;
    text-decoration: none; flex-shrink: 0;
}
.ed-file-pop-dl:hover { background: #e2e4e8; color: #1a1d23; }
.ed-file-pop-rm:hover { background: rgba(205,33,29,.1); color: #cd211d; }
.ed-file-pop-empty { padding: 12px; text-align: center; color: #6b7280; font-size: 10px; }
.ed-file-pop-footer {
    padding: 5px 10px;
    border-top: 1px solid #e2e4e8;
    background: #fafbfc;
    border-radius: 0 0 6px 6px;
}
.ed-file-pop-upload {
    font-size: 10px;
    font-weight: 600;
    color: #6366f1;
    cursor: pointer;
    display: block;
    text-align: center;
    padding: 3px 0;
}
.ed-file-pop-upload:hover { color: #4f46e5; }
```

- [ ] **Step 5: Verificare sintassi**
```bash
node -c assets/js/elenco_documenti.js
```

- [ ] **Step 6: Commit**
```
feat(elenco-documenti): popover file con preview, download, upload, detach
```

---

## Riepilogo Task

| # | Task | File principale | Scopo |
|---|------|-----------------|-------|
| 1 | listRepoFolders + listRepoFiles | ElencoDocumentiService.php | Backend: lista cartelle e file |
| 2 | moveRepoFile + deleteRepoFile + uploadRepoFile | ElencoDocumentiService.php | Backend: operazioni file |
| 3 | Vista PHP repository | commessa_repository.php | Layout HTML sidebar+content |
| 4 | CSS repository | commessa_repository.css | Stili layout |
| 5 | JS repository | commessa_repository.js | Logica frontend completa |
| 6 | Popover file elenco elaborati | elenco_documenti.js + .css | Click conteggio → popover con azioni |
