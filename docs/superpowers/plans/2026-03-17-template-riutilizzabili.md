# Template Riutilizzabili — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Elenco Documenti templates reusable — users can create named templates, apply them to multiple commesse, duplicate for variations, and manage a shared library.

**Architecture:** New association table `elenco_doc_project_template` maps projects to their active template. Existing `elenco_doc_commessa` table remains unchanged. Backend gets 4 new actions + 2 modified methods. Frontend template panel gets a list/editor dual-view replacing the current single editor.

**Tech Stack:** PHP (PDO), MySQL, Vanilla JS (IIFE module pattern), CSS

**Spec:** `docs/superpowers/specs/2026-03-17-template-riutilizzabili-design.md`

---

### Task 1: Database Migration

**Files:**
- Create: `core/migrations/010_elenco_doc_project_template.sql`

- [ ] **Step 1: Create migration file**

```sql
-- Migration: elenco_doc_project_template
-- Maps each project to its active template

CREATE TABLE IF NOT EXISTS elenco_doc_project_template (
    id_project    VARCHAR(32) NOT NULL,
    template_id   INT NOT NULL,
    assigned_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_project),
    INDEX idx_template (template_id),
    FOREIGN KEY (template_id) REFERENCES elenco_doc_commessa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: migrate existing project-specific templates
INSERT IGNORE INTO elenco_doc_project_template (id_project, template_id)
SELECT id_project, id FROM elenco_doc_commessa WHERE is_global = 0 AND id_project != 'GLOBAL';
```

- [ ] **Step 2: Execute migration on DB**

Run the SQL file against the intranet database via phpMyAdmin or MySQL CLI.

- [ ] **Step 3: Verify**

Run: `SELECT * FROM elenco_doc_project_template;` — should show any existing project-specific template assignments.
Run: `SHOW CREATE TABLE elenco_doc_project_template;` — verify FK with ON DELETE RESTRICT.

- [ ] **Step 4: Commit**

```bash
git add core/migrations/010_elenco_doc_project_template.sql
git commit -m "feat(elenco-doc): add project-template association table"
```

---

### Task 2: Backend — New Methods (getTemplateList, applyTemplate, duplicateTemplate, deleteTemplate)

**Files:**
- Modify: `services/ElencoDocumentiService.php` (add 4 new cases in `handleAction` at lines 27-78, add 4 new methods after line 296)

- [ ] **Step 1: Add new cases to `handleAction` switch**

In `services/ElencoDocumentiService.php`, add these cases inside the `switch ($action)` block (after the existing `saveTemplate` case at line 40):

```php
            case 'getTemplateList':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::getTemplateList($idProject);

            case 'applyTemplate':
                return self::applyTemplate($input);

            case 'duplicateTemplate':
                return self::duplicateTemplate($input);

            case 'deleteTemplate':
                return self::deleteTemplate($input);
```

- [ ] **Step 2: Add `getTemplateList` method**

Add after the existing `saveTemplate` method (after line 296):

```php
    /**
     * Returns all templates with in_use flag and used_by_count for the given project.
     */
    public static function getTemplateList(string $idProject): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        // Get all templates with usage count
        $sql = "
            SELECT t.*,
                   COUNT(pt.id_project) AS used_by_count
            FROM elenco_doc_commessa t
            LEFT JOIN elenco_doc_project_template pt ON pt.template_id = t.id
            GROUP BY t.id
            ORDER BY t.is_global DESC, t.nome_template ASC
        ";
        $stmt = $database->query($sql, [], __FILE__);
        $templates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get active template for this project
        $sqlActive = "SELECT template_id FROM elenco_doc_project_template WHERE id_project = ? LIMIT 1";
        $stmtActive = $database->query($sqlActive, [$idProject], __FILE__);
        $activeRow = $stmtActive->fetch(\PDO::FETCH_ASSOC);
        $activeId = $activeRow ? (int)$activeRow['template_id'] : null;

        // Decode JSON and add flags
        foreach ($templates as &$t) {
            $t['fasi'] = json_decode($t['fasi'] ?? '[]', true) ?: [];
            $t['zone'] = json_decode($t['zone'] ?? '[]', true) ?: [];
            $t['discipline'] = json_decode($t['discipline'] ?? '[]', true) ?: [];
            $t['tipi_documento'] = json_decode($t['tipi_documento'] ?? '[]', true) ?: [];
            $t['in_use'] = ((int)$t['id'] === $activeId);
            $t['used_by_count'] = (int)$t['used_by_count'];
        }

        return ['success' => true, 'data' => $templates];
    }

    /**
     * Associates a template to a project (upsert).
     */
    public static function applyTemplate(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $templateId = isset($input['templateId']) ? (int)$input['templateId'] : null;

        if (!$idProject || !$templateId) {
            return ['success' => false, 'message' => 'idProject e templateId obbligatori'];
        }

        // Verify template exists
        $sql = "SELECT id FROM elenco_doc_commessa WHERE id = ? LIMIT 1";
        $stmt = $database->query($sql, [$templateId], __FILE__);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'Template non trovato'];
        }

        // Upsert association
        $sql = "
            INSERT INTO elenco_doc_project_template (id_project, template_id, assigned_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), assigned_at = NOW()
        ";
        $database->query($sql, [$idProject, $templateId], __FILE__);

        return ['success' => true];
    }

    /**
     * Duplicates a template with a new name.
     */
    public static function duplicateTemplate(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $templateId = isset($input['templateId']) ? (int)$input['templateId'] : null;
        if (!$templateId) {
            return ['success' => false, 'message' => 'templateId obbligatorio'];
        }

        // Fetch source template
        $sql = "SELECT * FROM elenco_doc_commessa WHERE id = ? LIMIT 1";
        $stmt = $database->query($sql, [$templateId], __FILE__);
        $source = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$source) {
            return ['success' => false, 'message' => 'Template sorgente non trovato'];
        }

        $newName = filter_var(
            $input['nomeTemplate'] ?? ('Copia di ' . $source['nome_template']),
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );

        // Insert clone as global
        $sql = "
            INSERT INTO elenco_doc_commessa
                (id_project, nome_template, is_global, fasi, zone, discipline, tipi_documento)
            VALUES ('GLOBAL', ?, 1, ?, ?, ?, ?)
        ";
        $database->query($sql, [
            $newName, $source['fasi'], $source['zone'],
            $source['discipline'], $source['tipi_documento']
        ], __FILE__);
        $newId = $database->lastInsertId();

        return ['success' => true, 'data' => ['id' => $newId, 'nome_template' => $newName]];
    }

    /**
     * Deletes a template if not used by any project.
     */
    public static function deleteTemplate(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $templateId = isset($input['templateId']) ? (int)$input['templateId'] : null;
        if (!$templateId) {
            return ['success' => false, 'message' => 'templateId obbligatorio'];
        }

        // Check usage
        $sql = "SELECT COUNT(*) AS cnt FROM elenco_doc_project_template WHERE template_id = ?";
        $stmt = $database->query($sql, [$templateId], __FILE__);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ((int)($row['cnt'] ?? 0) > 0) {
            return ['success' => false, 'message' => 'Impossibile eliminare: template in uso da ' . $row['cnt'] . ' commesse'];
        }

        $database->query("DELETE FROM elenco_doc_commessa WHERE id = ?", [$templateId], __FILE__);

        return ['success' => true];
    }
```

- [ ] **Step 3: Verify syntax**

Open the file in browser or run `php -l services/ElencoDocumentiService.php`. Expected: no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add services/ElencoDocumentiService.php
git commit -m "feat(elenco-doc): add template list/apply/duplicate/delete methods"
```

---

### Task 3: Backend — Modify getTemplate and saveTemplate

**Files:**
- Modify: `services/ElencoDocumentiService.php` (lines 35-37 in handleAction, lines 200-296)

- [ ] **Step 1: Update `handleAction` case for `getTemplate`**

Replace lines 35-37:
```php
            case 'getTemplate':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::getTemplate($idProject);
```

With:
```php
            case 'getTemplate':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $templateId = isset($input['templateId']) ? (int)$input['templateId'] : null;
                return self::getTemplate($idProject, $templateId);
```

- [ ] **Step 2: Rewrite `getTemplate` method**

Replace the entire `getTemplate` method (lines 200-246) with:

```php
    /**
     * Returns the active template for a project.
     * If $templateId is given, fetches that specific template (for editor).
     * Otherwise looks up the project's assigned template, falling back to global.
     */
    public static function getTemplate(string $idProject, ?int $templateId = null): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        if ($templateId) {
            // Fetch specific template by ID (for editing)
            $sql = "SELECT * FROM elenco_doc_commessa WHERE id = ? LIMIT 1";
            $stmt = $database->query($sql, [$templateId], __FILE__);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC);
        } else {
            // Look up project's assigned template
            $sql = "
                SELECT t.* FROM elenco_doc_commessa t
                INNER JOIN elenco_doc_project_template pt ON pt.template_id = t.id
                WHERE pt.id_project = ?
                LIMIT 1
            ";
            $stmt = $database->query($sql, [$idProject], __FILE__);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Fallback: global template
            if (!$template) {
                $sql = "SELECT * FROM elenco_doc_commessa WHERE is_global = 1 ORDER BY id LIMIT 1";
                $stmt = $database->query($sql, [], __FILE__);
                $template = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
        }

        if (!$template) {
            return [
                'success' => true,
                'data' => [
                    'id' => null,
                    'nome_template' => 'Nessun Template',
                    'fasi' => ['PP', 'PD', 'PE', 'ES'],
                    'zone' => ['GG', '00', '01', '02'],
                    'discipline' => ['GE', 'AR', 'SA', 'EE', 'MA'],
                    'tipi_documento' => [
                        ['cod' => 'RT', 'desc' => 'Relazione tecnica', 'tipo' => 'Report'],
                        ['cod' => 'E1', 'desc' => 'Piante', 'tipo' => 'Disegno'],
                        ['cod' => 'E2', 'desc' => 'Sezioni', 'tipo' => 'Disegno']
                    ]
                ]
            ];
        }

        // Decode JSON
        $template['fasi'] = json_decode($template['fasi'] ?? '[]', true) ?: [];
        $template['zone'] = json_decode($template['zone'] ?? '[]', true) ?: [];
        $template['discipline'] = json_decode($template['discipline'] ?? '[]', true) ?: [];
        $template['tipi_documento'] = json_decode($template['tipi_documento'] ?? '[]', true) ?: [];

        return ['success' => true, 'data' => $template];
    }
```

- [ ] **Step 3: Update `saveTemplate` — force global for new templates + return full data**

In the `saveTemplate` method, replace the INSERT branch and final return. Change the INSERT block (around lines 281-292) from:
```php
        } else {
            // INSERT
            $sql = "
                INSERT INTO elenco_doc_commessa
                    (id_project, nome_template, is_global, fasi, zone, discipline, tipi_documento)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            $database->query($sql, [
                $isGlobal ? 'GLOBAL' : $idProject,
                $nomeTemplate, $isGlobal, $fasi, $zone, $discipline, $tipiDocumento
            ], __FILE__);
            $templateId = $database->lastInsertId();
        }

        return ['success' => true, 'templateId' => $templateId];
```

To:
```php
        } else {
            // INSERT — new templates are always global/shared
            $sql = "
                INSERT INTO elenco_doc_commessa
                    (id_project, nome_template, is_global, fasi, zone, discipline, tipi_documento)
                VALUES ('GLOBAL', ?, 1, ?, ?, ?, ?)
            ";
            $database->query($sql, [
                $nomeTemplate, $fasi, $zone, $discipline, $tipiDocumento
            ], __FILE__);
            $templateId = $database->lastInsertId();
        }

        // Return full updated template data
        return self::getTemplate('', (int)$templateId);
```

- [ ] **Step 4: Add `template_name` to `getDocumenti` response**

In the `getDocumenti` method, around line 448-465, replace:
```php
        // Ottieni template lookups
        $templateResult = self::getTemplate($idProject);
        $lookups = [];
        if ($templateResult['success'] && !empty($templateResult['data'])) {
            $t = $templateResult['data'];
            $lookups = [
                'fasi' => $t['fasi'] ?? [],
                'zone' => $t['zone'] ?? [],
                'discipline' => $t['discipline'] ?? [],
                'tipi_documento' => $t['tipi_documento'] ?? []
            ];
        }

        return [
            'success' => true,
            'data' => [
                'sections' => $result,
                'lookups' => $lookups
            ]
        ];
```

With:
```php
        // Ottieni template lookups
        $templateResult = self::getTemplate($idProject);
        $lookups = [];
        $templateName = '';
        if ($templateResult['success'] && !empty($templateResult['data'])) {
            $t = $templateResult['data'];
            $lookups = [
                'fasi' => $t['fasi'] ?? [],
                'zone' => $t['zone'] ?? [],
                'discipline' => $t['discipline'] ?? [],
                'tipi_documento' => $t['tipi_documento'] ?? []
            ];
            $templateName = $t['nome_template'] ?? '';
        }

        return [
            'success' => true,
            'data' => [
                'sections' => $result,
                'lookups' => $lookups,
                'template_name' => $templateName
            ]
        ];
```

- [ ] **Step 5: Verify syntax**

Run: `php -l services/ElencoDocumentiService.php`. Expected: no syntax errors.

- [ ] **Step 6: Commit**

```bash
git add services/ElencoDocumentiService.php
git commit -m "feat(elenco-doc): modify getTemplate with assignment lookup and saveTemplate response"
```

---

### Task 4: Frontend — View Changes (toolbar chip + panel HTML)

**Files:**
- Modify: `views/elenco_documenti.php` (lines 86-93 toolbar, lines 439-460 template panel)

- [ ] **Step 1: Add template chip to toolbar**

In `views/elenco_documenti.php`, after the Template button closing tag (after line 92 `<?php endif; ?>`), add:

```php
            <span class="ed-tpl-chip" id="tplChipName">&mdash;</span>
```

- [ ] **Step 2: Replace template panel HTML**

Replace the entire `<!-- Template Configuration Panel -->` block (lines 439-460) with:

```php
<!-- Template Panel (list + editor) -->
<div class="ed-tpl-panel" id="tplPanel">
    <div class="ed-tpl-header" id="tplHeader">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
            <circle cx="12" cy="12" r="3"/>
            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
        </svg>
        <h2 id="tplPanelTitle">Gestione Template</h2>
        <button class="ed-close-btn" onclick="ElencoDoc.closeTemplatePanel()">&times;</button>
    </div>
    <!-- Dynamic content: list view or editor view rendered by JS -->
    <div class="ed-tpl-content" id="tplContent"></div>
</div>
```

- [ ] **Step 3: Commit**

```bash
git add views/elenco_documenti.php
git commit -m "feat(elenco-doc): add template chip and rework panel HTML for list/editor"
```

---

### Task 5: Frontend — CSS for Template List View

**Files:**
- Modify: `assets/css/elenco_documenti.css` (after line 2009, before the FILE POPOVER section)

- [ ] **Step 1: Add new CSS styles**

After the `.ed-tpl-add-btn:hover` rule (line 2009), add:

```css

/* Template chip in toolbar */
.ed-tpl-chip {
    font-size: 11px;
    font-weight: 600;
    color: #6b7280;
    background: #f0f1f5;
    padding: 4px 10px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* Template panel — dynamic content area */
.ed-tpl-content {
    flex: 1;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

/* Template List View */
.ed-tpl-list {
    flex: 1;
    overflow-y: auto;
    padding: 10px 0;
}
.ed-tpl-card {
    display: flex;
    align-items: center;
    padding: 10px 18px;
    border-bottom: 1px solid #f0f1f4;
    transition: background 0.1s;
    gap: 10px;
}
.ed-tpl-card:hover { background: #fafbff; }
.ed-tpl-card.active { background: #f0fdf4; }
.ed-tpl-card-info { flex: 1; min-width: 0; }
.ed-tpl-card-name {
    font-size: 13px;
    font-weight: 700;
    color: #1a1d23;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ed-tpl-card-meta {
    font-size: 10px;
    color: #6b7280;
    margin-top: 2px;
}
.ed-tpl-card-badge {
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    background: #dcfce7;
    color: #166534;
    padding: 2px 7px;
    border-radius: 8px;
    flex-shrink: 0;
}
.ed-tpl-card-actions {
    display: flex;
    gap: 3px;
    flex-shrink: 0;
    opacity: 0;
    transition: opacity 0.15s;
}
.ed-tpl-card:hover .ed-tpl-card-actions { opacity: 1; }
.ed-tpl-action-btn {
    width: 28px; height: 28px;
    border: 1px solid #e2e4e8;
    background: #fff;
    border-radius: 5px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    font-size: 12px;
    transition: all 0.15s;
}
.ed-tpl-action-btn:hover { border-color: #cd211d; color: #cd211d; background: rgba(205,33,29,.04); }
.ed-tpl-action-btn.apply-btn:hover { border-color: #16a34a; color: #16a34a; background: rgba(22,163,74,.04); }
.ed-tpl-action-btn:disabled { opacity: 0.3; cursor: not-allowed; }
.ed-tpl-action-btn:disabled:hover { border-color: #e2e4e8; color: #6b7280; background: #fff; }
.ed-tpl-list-footer {
    padding: 12px 18px;
    border-top: 1px solid #e2e4e8;
    flex-shrink: 0;
}
.ed-tpl-empty {
    padding: 40px 18px;
    text-align: center;
    color: #6b7280;
    font-size: 12px;
}

/* Template Editor View (name input + tabs/table reuse existing styles) */
.ed-tpl-name-row {
    padding: 12px 18px;
    border-bottom: 1px solid #e2e4e8;
    flex-shrink: 0;
}
.ed-tpl-name-input {
    width: 100%;
    border: 1px solid #e2e4e8;
    border-radius: 6px;
    padding: 7px 12px;
    font-size: 13px;
    font-weight: 700;
    font-family: inherit;
    outline: none;
    transition: border-color 0.15s;
}
.ed-tpl-name-input:focus { border-color: #cd211d; }
.ed-tpl-back-btn {
    width: 28px; height: 28px;
    border: none;
    background: none;
    cursor: pointer;
    color: #6b7280;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.15s;
    flex-shrink: 0;
}
.ed-tpl-back-btn:hover { background: rgba(0,0,0,.06); color: #1a1d23; }
```

- [ ] **Step 2: Commit**

```bash
git add assets/css/elenco_documenti.css
git commit -m "feat(elenco-doc): add CSS for template list view and chip"
```

---

### Task 6: Frontend — JavaScript Template Panel Rework

**Files:**
- Modify: `assets/js/elenco_documenti.js` (lines 1764-1883 template section, lines 2133-2140 exports, line 270 loadDocumenti)

This is the main task. Replace the entire template panel JS block and update the exports.

- [ ] **Step 1: Update state variables**

Replace lines 1768-1769:
```javascript
    let _tplData = null;
    let _tplActiveTab = 'fasi';
```

With:
```javascript
    let _tplList = [];
    let _tplData = null;
    let _tplEditId = null;
    let _tplView = 'list';
    let _tplActiveTab = 'fasi';
```

- [ ] **Step 2: Replace `openTemplatePanel` function**

Replace the `openTemplatePanel` function (lines 1771-1778) with:

```javascript
    async function openTemplatePanel() {
        const result = await sendRequest('getTemplateList', { idProject });
        if (!result.success) { alert(result.message || 'Errore'); return; }
        _tplList = result.data || [];
        _tplView = 'list';
        renderTplPanel();
        document.getElementById('tplPanel')?.classList.add('on');
    }
```

- [ ] **Step 3: Add `renderTplPanel` dispatcher function**

Add after `openTemplatePanel`:

```javascript
    function renderTplPanel() {
        const content = document.getElementById('tplContent');
        const title = document.getElementById('tplPanelTitle');
        if (!content) return;

        if (_tplView === 'list') {
            if (title) title.textContent = 'Gestione Template';
            renderTplList(content);
        } else {
            if (title) title.textContent = _tplEditId ? 'Modifica Template' : 'Nuovo Template';
            renderTplEditor(content);
        }
    }
```

- [ ] **Step 4: Add `renderTplList` function**

```javascript
    function renderTplList(container) {
        let html = '<div class="ed-tpl-list">';

        if (_tplList.length === 0) {
            html += '<div class="ed-tpl-empty">Nessun template disponibile.<br>Crea il primo template per iniziare.</div>';
        } else {
            _tplList.forEach(t => {
                const inUse = t.in_use;
                const usedBy = t.used_by_count || 0;
                const canDelete = usedBy === 0;
                html += `
                <div class="ed-tpl-card ${inUse ? 'active' : ''}" data-tpl-id="${t.id}">
                    <div class="ed-tpl-card-info">
                        <div class="ed-tpl-card-name">${escapeHtml(t.nome_template)}</div>
                        <div class="ed-tpl-card-meta">${t.is_global ? 'Globale' : 'Progetto'} &middot; Usato da ${usedBy} commess${usedBy === 1 ? 'a' : 'e'}</div>
                    </div>
                    ${inUse ? '<span class="ed-tpl-card-badge">In uso</span>' : ''}
                    <div class="ed-tpl-card-actions">
                        ${!inUse ? `<button class="ed-tpl-action-btn apply-btn" onclick="ElencoDoc.tplApply(${t.id})" title="Applica a questa commessa">\u2713</button>` : ''}
                        <button class="ed-tpl-action-btn" onclick="ElencoDoc.tplEdit(${t.id})" title="Modifica">\u270E</button>
                        <button class="ed-tpl-action-btn" onclick="ElencoDoc.tplDuplicate(${t.id})" title="Duplica">\u2398</button>
                        <button class="ed-tpl-action-btn" onclick="ElencoDoc.tplDelete(${t.id})" title="Elimina" ${!canDelete ? 'disabled' : ''}>\u2715</button>
                    </div>
                </div>`;
            });
        }

        html += '</div>';
        html += `<div class="ed-tpl-list-footer">
            <button class="btn btn-primary" onclick="ElencoDoc.tplNew()" style="width:100%">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Nuovo Template
            </button>
        </div>`;

        container.innerHTML = html;
    }
```

- [ ] **Step 5: Add `renderTplEditor` function**

This reuses the existing tab/table rendering logic but wraps it in the editor view structure:

```javascript
    function renderTplEditor(container) {
        const isObjType = (_tplActiveTab === 'tipi_documento');
        const items = (_tplData ? _tplData[_tplActiveTab] : null) || [];

        let tableHtml = `<table class="ed-tpl-table"><thead><tr>
            <th style="width:60px">Codice</th>
            <th>${isObjType ? 'Descrizione' : 'Descrizione (opz.)'}</th>
            <th style="width:30px"></th>
        </tr></thead><tbody>`;

        items.forEach((item, idx) => {
            const code = isObjType ? (item.cod || '') : (typeof item === 'string' ? item : '');
            const desc = isObjType ? (item.desc || '') : '';
            tableHtml += `<tr>
                <td class="code-col"><input type="text" value="${escapeHtml(code)}" data-idx="${idx}" data-field="code" maxlength="4" onchange="ElencoDoc.tplFieldChange(this)"></td>
                <td><input type="text" value="${escapeHtml(desc)}" data-idx="${idx}" data-field="desc" onchange="ElencoDoc.tplFieldChange(this)" ${!isObjType ? 'placeholder="opzionale"' : ''}></td>
                <td><button class="ed-tpl-del-btn" onclick="ElencoDoc.tplRemoveRow(${idx})" title="Elimina">&times;</button></td>
            </tr>`;
        });

        tableHtml += `</tbody></table>
            <button class="ed-tpl-add-btn" onclick="ElencoDoc.tplAddRow()">+ Aggiungi</button>`;

        container.innerHTML = `
            <div class="ed-tpl-name-row">
                <input type="text" class="ed-tpl-name-input" id="tplNameInput" value="${escapeHtml(_tplData?.nome_template || '')}" placeholder="Nome del template...">
            </div>
            <div class="ed-tpl-tabs">
                <button class="ed-tpl-tab ${_tplActiveTab === 'fasi' ? 'active' : ''}" onclick="ElencoDoc.switchTplTab(this,'fasi')">Fasi</button>
                <button class="ed-tpl-tab ${_tplActiveTab === 'zone' ? 'active' : ''}" onclick="ElencoDoc.switchTplTab(this,'zone')">Zone</button>
                <button class="ed-tpl-tab ${_tplActiveTab === 'discipline' ? 'active' : ''}" onclick="ElencoDoc.switchTplTab(this,'discipline')">Discipline</button>
                <button class="ed-tpl-tab ${_tplActiveTab === 'tipi_documento' ? 'active' : ''}" onclick="ElencoDoc.switchTplTab(this,'tipi_documento')">Tipi</button>
            </div>
            <div class="ed-tpl-body">${tableHtml}</div>
            <div class="ed-tpl-footer">
                <button class="btn btn-secondary" onclick="ElencoDoc.tplBackToList()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Indietro
                </button>
                <div style="flex:1"></div>
                <button class="btn btn-primary" onclick="ElencoDoc.saveTemplate()">Salva Template</button>
            </div>`;
    }
```

- [ ] **Step 6: Add action functions (tplApply, tplEdit, tplNew, tplDuplicate, tplDelete, tplBackToList)**

```javascript
    async function tplApply(templateId) {
        const result = await sendRequest('applyTemplate', { idProject, templateId });
        if (!result.success) { alert(result.message || 'Errore'); return; }
        // Refresh lookups from the applied template
        const tpl = _tplList.find(t => t.id == templateId);
        if (tpl) {
            const prevResp = lookups.resp;
            lookups = normalizeLookups(tpl);
            lookups.resp = prevResp;
            document.getElementById('tplChipName').textContent = tpl.nome_template;
        }
        // Update in_use flags locally
        _tplList.forEach(t => { t.in_use = (t.id == templateId); });
        renderTplPanel();
        flashSave();
    }

    function tplEdit(templateId) {
        const tpl = _tplList.find(t => t.id == templateId);
        if (!tpl) return;
        // Deep copy to avoid mutating list data
        _tplData = JSON.parse(JSON.stringify(tpl));
        _tplEditId = templateId;
        _tplActiveTab = 'fasi';
        _tplView = 'editor';
        renderTplPanel();
    }

    function tplNew() {
        _tplData = { nome_template: '', fasi: [], zone: [], discipline: [], tipi_documento: [] };
        _tplEditId = null;
        _tplActiveTab = 'fasi';
        _tplView = 'editor';
        renderTplPanel();
    }

    async function tplDuplicate(templateId) {
        const tpl = _tplList.find(t => t.id == templateId);
        const newName = 'Copia di ' + (tpl?.nome_template || 'Template');
        const result = await sendRequest('duplicateTemplate', { templateId, nomeTemplate: newName });
        if (!result.success) { alert(result.message || 'Errore'); return; }
        // Refresh list
        const listResult = await sendRequest('getTemplateList', { idProject });
        if (listResult.success) _tplList = listResult.data || [];
        renderTplPanel();
        flashSave();
    }

    async function tplDelete(templateId) {
        const tpl = _tplList.find(t => t.id == templateId);
        if (!confirm(`Eliminare il template "${tpl?.nome_template || ''}"?`)) return;
        const result = await sendRequest('deleteTemplate', { templateId });
        if (!result.success) { alert(result.message || 'Errore'); return; }
        _tplList = _tplList.filter(t => t.id != templateId);
        renderTplPanel();
    }

    function tplBackToList() {
        _tplView = 'list';
        _tplData = null;
        _tplEditId = null;
        renderTplPanel();
    }
```

- [ ] **Step 7: Update `switchTplTab` to use renderTplPanel**

Replace the `switchTplTab` function:
```javascript
    function switchTplTab(btn, tab) {
        _tplActiveTab = tab;
        renderTplPanel();
    }
```

- [ ] **Step 8: Update `saveTemplateData` function**

Replace the existing `saveTemplateData` function (lines 1870-1883) with:

```javascript
    async function saveTemplateData() {
        // Read name from input
        const nameInput = document.getElementById('tplNameInput');
        const nomeTemplate = (nameInput?.value || '').trim() || 'Template';

        const payload = {
            idProject,
            templateId: _tplEditId,
            nomeTemplate,
            fasi: _tplData.fasi,
            zone: _tplData.zone,
            discipline: _tplData.discipline,
            tipiDocumento: _tplData.tipi_documento
        };

        const result = await sendRequest('saveTemplate', payload);
        if (!result.success) {
            alert(result.message || 'Errore salvataggio template');
            return;
        }

        // Refresh list
        const listResult = await sendRequest('getTemplateList', { idProject });
        if (listResult.success) _tplList = listResult.data || [];

        // If we edited the active template, refresh lookups + chip
        const activeTpl = _tplList.find(t => t.in_use);
        if (activeTpl && activeTpl.id == _tplEditId) {
            const prevResp = lookups.resp;
            lookups = normalizeLookups(activeTpl);
            lookups.resp = prevResp;
            document.getElementById('tplChipName').textContent = activeTpl.nome_template;
        }

        _tplView = 'list';
        _tplData = null;
        _tplEditId = null;
        renderTplPanel();
        flashSave();
    }
```

- [ ] **Step 9: Remove old `renderTplTab` function and update callers**

Delete the old `renderTplTab` function (the standalone one that was at lines 1791-1819) — its logic is now inside `renderTplEditor`.

Also update `tplAddRow` and `tplRemoveRow` — both call the deleted `renderTplTab()`. Replace those calls with `renderTplPanel()`:

In `tplAddRow` (around line 1844), change:
```javascript
        renderTplTab();
```
to:
```javascript
        renderTplPanel();
```

In `tplRemoveRow` (around line 1867), change:
```javascript
        renderTplTab();
```
to:
```javascript
        renderTplPanel();
```

- [ ] **Step 10: Update template chip on data load**

In the `loadDocumenti` function (around line 270), after `lookups = normalizeLookups(result.data.lookups || {});`, add:

```javascript
            // Update template chip
            const tplChip = document.getElementById('tplChipName');
            if (tplChip && result.data.template_name) {
                tplChip.textContent = result.data.template_name;
            }
```

- [ ] **Step 11: Update the return object (exports)**

Replace the template panel exports block (lines 2133-2140):
```javascript
        // Template panel
        openTemplatePanel,
        closeTemplatePanel,
        switchTplTab,
        tplFieldChange,
        tplAddRow,
        tplRemoveRow,
        saveTemplate: saveTemplateData,
```

With:
```javascript
        // Template panel
        openTemplatePanel,
        closeTemplatePanel,
        switchTplTab,
        tplFieldChange,
        tplAddRow,
        tplRemoveRow,
        saveTemplate: saveTemplateData,
        tplApply,
        tplEdit,
        tplNew,
        tplDuplicate,
        tplDelete,
        tplBackToList,
```

- [ ] **Step 12: Commit**

```bash
git add assets/js/elenco_documenti.js
git commit -m "feat(elenco-doc): rework template panel JS with list/editor views"
```

---

### Task 7: Manual Verification

- [ ] **Step 1: Test template list view**

Open a commessa with Elenco Documenti. Click "Template" button. Verify:
- Panel opens with list of templates
- The "Template Standard" (global seeded) appears
- "In uso" badge shows on the active template
- "Usato da X commesse" shows correct count

- [ ] **Step 2: Test create new template**

Click "Nuovo Template". Verify:
- Editor view opens with empty fields
- Name input is focused/empty
- Tabs work (Fasi, Zone, Discipline, Tipi)
- Add some values, give it a name, click "Salva"
- Returns to list, new template appears

- [ ] **Step 3: Test apply template**

Click the checkmark (Applica) on the new template. Verify:
- "In uso" badge moves to the new template
- Toolbar chip updates with new template name
- Dropdown options in document editing reflect new template values

- [ ] **Step 4: Test edit template**

Click the pencil (Modifica) on a template. Verify:
- Editor opens with existing values
- Name is pre-filled
- Modify a value, save
- If editing active template, lookups refresh

- [ ] **Step 5: Test duplicate template**

Click the duplicate button. Verify:
- New template "Copia di X" appears in list
- Values match the original

- [ ] **Step 6: Test delete template**

Try deleting a template in use — should be disabled/blocked.
Delete an unused template — should work with confirmation.

- [ ] **Step 7: Test toolbar chip**

Reload the page. Verify the chip shows the correct template name on load.

- [ ] **Step 8: Final commit**

```bash
git add services/ElencoDocumentiService.php views/elenco_documenti.php assets/js/elenco_documenti.js assets/css/elenco_documenti.css
git commit -m "feat(elenco-doc): complete reusable template system"
```
