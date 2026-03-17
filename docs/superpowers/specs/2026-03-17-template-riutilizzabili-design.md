# Template Riutilizzabili per Elenco Documenti

**Date:** 2026-03-17
**Status:** Approved
**Module:** Elenco Documenti (Commesse)

## Problem

The template system for Elenco Documenti currently supports only one template per project. Users cannot save named templates, reuse them across projects, or manage a library of templates. The DB schema (`elenco_doc_commessa`) was designed with `nome_template` and `is_global` fields, but the implementation never exposed multi-template management.

## Design

### Behavior

Templates are **shared by reference**. When a template is applied to multiple commesse and then edited, the change affects all commesse using that template. To create a project-specific variation, the user duplicates the template first, then modifies the copy.

### Data Model

**Existing table `elenco_doc_commessa`** (no schema changes needed):
- `id` INT PK AUTO_INCREMENT
- `id_project` VARCHAR(32) — `'GLOBAL'` for shared templates, or a project ID for project-specific ones
- `nome_template` VARCHAR(120)
- `is_global` TINYINT(1) — 1 = available to all projects, 0 = project-specific
- `fasi`, `zone`, `discipline`, `tipi_documento` — JSON columns

**New table `elenco_doc_project_template`**:
```sql
CREATE TABLE IF NOT EXISTS elenco_doc_project_template (
    id_project    VARCHAR(32) NOT NULL,
    template_id   INT NOT NULL,
    assigned_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_project),
    INDEX idx_template (template_id),
    FOREIGN KEY (template_id) REFERENCES elenco_doc_commessa(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

This maps each project to its active template. One project = one active template.

### Backend Changes (ElencoDocumentiService.php)

#### New actions in `handleAction`:

| Action | Method | Purpose |
|--------|--------|---------|
| `getTemplateList` | `getTemplateList($idProject)` | Returns all templates (global + project-specific) with `in_use` flag for the current project |
| `applyTemplate` | `applyTemplate($input)` | Associates a template to the current project (upsert on `elenco_doc_project_template`) |
| `duplicateTemplate` | `duplicateTemplate($input)` | Clones a template with a new name, returns the new template ID |
| `deleteTemplate` | `deleteTemplate($input)` | Deletes a template if not used by any project |

#### Modified methods:

**`getTemplate($idProject)`**: Change lookup logic:
1. Check `elenco_doc_project_template` for the project's assigned template
2. If found, fetch that template from `elenco_doc_commessa`
3. If not found, fall back to global template (current behavior)
4. Include `template_name` in the response for the toolbar chip

**`saveTemplate($input)`**: Keep current behavior (update existing or insert new), but:
- Always require `templateId` for updates (no implicit "find and update")
- Accept `nomeTemplate` for renaming
- Return updated template data

**`getDocumenti($idProject)`**: Add `template_name` to the response (already calls `getTemplate` internally).

#### New method signatures:

```php
public static function getTemplateList(string $idProject): array
// Returns: ['success' => true, 'data' => [
//   ['id' => 1, 'nome_template' => 'Standard', 'is_global' => 1, 'in_use' => true, 'used_by_count' => 5],
//   ['id' => 2, 'nome_template' => 'Infrastrutture', 'is_global' => 1, 'in_use' => false, 'used_by_count' => 2],
//   ...
// ]]

public static function applyTemplate(array $input): array
// Input: { idProject, templateId }
// Upserts elenco_doc_project_template
// Returns: ['success' => true]

public static function duplicateTemplate(array $input): array
// Input: { templateId, nomeTemplate (new name) }
// Clones the template row with new name
// Returns: ['success' => true, 'data' => ['id' => newId, 'nome_template' => '...']]

public static function deleteTemplate(array $input): array
// Input: { templateId }
// Checks used_by_count first — refuses if > 0
// Returns: ['success' => true] or error
```

### Frontend Changes

#### Toolbar chip (view + JS)

In `elenco_documenti.php` toolbar, after the Template button, add:
```html
<span class="ed-tpl-chip" id="tplChipName">—</span>
```

In JS, after `loadDocumenti()` populates data, set the chip text from `data.template_name`.

#### Template Panel — Two views

The panel `#tplPanel` switches between two views:

**1. List View** (default when opening):
- Header: "Gestione Template" + close button
- List of template cards, each showing:
  - Template name (bold)
  - Badge "In uso" if active for current project
  - `used_by_count` projects count as subtitle
  - Action buttons: Applica, Modifica, Duplica, Elimina
- Footer: "Nuovo Template" button
- Elimina is disabled/hidden if `used_by_count > 0`

**2. Editor View** (when clicking Modifica or Nuovo):
- Header: back arrow + "Modifica Template" / "Nuovo Template"
- Template name input field
- Tabs: Fasi, Zone, Discipline, Tipi (current editor, unchanged)
- Footer: "Annulla" (back to list), "Salva" (save + back to list)

#### JS State changes

```javascript
let _tplList = [];        // all templates from getTemplateList
let _tplData = null;      // current template being edited
let _tplEditId = null;    // ID of template being edited (null = new)
let _tplView = 'list';    // 'list' | 'editor'
let _tplActiveTab = 'fasi';
```

#### JS Flow

```
openTemplatePanel()
  → sendRequest('getTemplateList', { idProject })
  → _tplList = result.data
  → _tplView = 'list'
  → renderTplPanel()

renderTplPanel()
  → if _tplView === 'list': renderTplList()
  → if _tplView === 'editor': renderTplEditor()

renderTplList()
  → render cards from _tplList
  → highlight "in_use" template
  → wire: Applica, Modifica, Duplica, Elimina buttons

tplApply(templateId)
  → sendRequest('applyTemplate', { idProject, templateId })
  → reload lookups from applied template
  → update toolbar chip
  → re-render list (update in_use flags)

tplEdit(templateId)
  → sendRequest('getTemplate', { idProject, templateId })  // or find in _tplList
  → _tplData = template data
  → _tplEditId = templateId
  → _tplView = 'editor'
  → renderTplPanel()

tplNew()
  → _tplData = { nome_template: '', fasi: [], zone: [], discipline: [], tipi_documento: [] }
  → _tplEditId = null
  → _tplView = 'editor'
  → renderTplPanel()

tplDuplicate(templateId)
  → prompt for new name (or auto "Copia di X")
  → sendRequest('duplicateTemplate', { templateId, nomeTemplate })
  → refresh list

tplDelete(templateId)
  → confirm dialog
  → sendRequest('deleteTemplate', { templateId })
  → refresh list

saveTemplateData()
  → sendRequest('saveTemplate', { templateId: _tplEditId, nomeTemplate, ...data })
  → if was editing the active template, refresh lookups + chip
  → _tplView = 'list'
  → refresh list

tplBackToList()
  → _tplView = 'list'
  → renderTplPanel()
```

### CSS Changes (elenco_documenti.css)

Add styles for the list view inside `#tplPanel`:
- `.ed-tpl-list` — scrollable container
- `.ed-tpl-card` — template card row (flex, border-bottom)
- `.ed-tpl-card-name` — bold name
- `.ed-tpl-card-meta` — subtitle (used by X projects)
- `.ed-tpl-card-badge` — "In uso" badge (green)
- `.ed-tpl-card-actions` — right-aligned action buttons
- `.ed-tpl-name-input` — name input in editor view
- `.ed-tpl-back-btn` — back arrow button in editor header

Existing editor styles (`.ed-tpl-table`, `.ed-tpl-tab`, etc.) remain unchanged.

### Permissions

- `view_commesse`: sees the template chip in the toolbar (read-only)
- `edit_commessa`: can open the template panel and perform all operations (apply, edit, duplicate, delete, create)

### Migration

Single SQL file creating `elenco_doc_project_template` table. No changes to `elenco_doc_commessa`.

Seed: for each project that currently has a record in `elenco_doc_commessa`, insert a row in `elenco_doc_project_template` pointing to that template (preserves current behavior).

### Files Affected

| File | Change |
|------|--------|
| `services/ElencoDocumentiService.php` | Add 4 new methods, modify `getTemplate`, `getDocumenti` |
| `service_router.php` | Add 4 new cases under `elenco_documenti` |
| `views/elenco_documenti.php` | Add chip in toolbar, rework `#tplPanel` HTML |
| `assets/js/elenco_documenti.js` | Rework template panel JS (list/editor views) |
| `assets/css/elenco_documenti.css` | Add list view styles |
| `core/migrations/` | New migration for `elenco_doc_project_template` |
