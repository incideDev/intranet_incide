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
    FOREIGN KEY (template_id) REFERENCES elenco_doc_commessa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

This maps each project to its active template. One project = one active template. `ON DELETE RESTRICT` prevents accidental deletion of templates still in use (DB-level guard in addition to application-level check).

### Backend Changes (ElencoDocumentiService.php)

All new actions are added as cases in the service's `handleAction()` switch. No changes needed to `service_router.php` since it already delegates to `handleAction()`.

All new actions sanitize `$idProject` via `FILTER_SANITIZE_FULL_SPECIAL_CHARS` in the `handleAction` switch, consistent with existing actions.

#### New actions in `handleAction`:

| Action | Method | Purpose |
|--------|--------|---------|
| `getTemplateList` | `getTemplateList($idProject)` | Returns all templates with `in_use` flag for the current project and full JSON data |
| `applyTemplate` | `applyTemplate($input)` | Associates a template to the current project (upsert on `elenco_doc_project_template`) |
| `duplicateTemplate` | `duplicateTemplate($input)` | Clones a template with a new name, returns the new template ID |
| `deleteTemplate` | `deleteTemplate($input)` | Deletes a template if not used by any project |

#### Modified methods:

**`getTemplate(string $idProject, ?int $templateId = null)`**: Change lookup logic:
1. If `$templateId` is provided, fetch that specific template (used by editor)
2. Otherwise, check `elenco_doc_project_template` for the project's assigned template
3. If no assignment found, fall back to first global template (current behavior)
4. Include `nome_template` in the response for the toolbar chip

Update the `handleAction` case to also extract `$templateId`:
```php
case 'getTemplate':
    $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $templateId = isset($input['templateId']) ? (int)$input['templateId'] : null;
    return self::getTemplate($idProject, $templateId);
```

**`saveTemplate($input)`**: Changes:
- For UPDATE (`templateId` present): update the existing record including `nome_template`
- For INSERT (no `templateId`): create as `is_global = 1` (all new templates are global/shared by default). Set `id_project = 'GLOBAL'`.
- Return full template data in response: `['success' => true, 'data' => ['id' => $id, 'nome_template' => '...', ...]]`

**`getDocumenti($idProject)`**: Add `template_name` to the response (already calls `getTemplate` internally). Extract from template result.

#### New method signatures:

```php
public static function getTemplateList(string $idProject): array
// Returns all templates (global + project-specific) with full JSON data for editing.
// Each item includes: id, nome_template, is_global, fasi, zone, discipline, tipi_documento,
//   in_use (bool - true if this is the active template for $idProject),
//   used_by_count (int - number of projects using this template)
// Returns: ['success' => true, 'data' => [...]]

public static function applyTemplate(array $input): array
// Input: { idProject, templateId }
// Permission: edit_commessa
// Upserts elenco_doc_project_template
// Returns: ['success' => true]

public static function duplicateTemplate(array $input): array
// Input: { templateId, nomeTemplate (new name, optional - defaults to "Copia di X") }
// Permission: edit_commessa
// Clones the template row with new name. Clone is always global (is_global=1, id_project='GLOBAL').
// Returns: ['success' => true, 'data' => ['id' => newId, 'nome_template' => '...']]

public static function deleteTemplate(array $input): array
// Input: { templateId }
// Permission: edit_commessa
// Checks used_by_count first — refuses if > 0 with descriptive error message
// DB FK with ON DELETE RESTRICT provides additional safety
// Returns: ['success' => true] or ['success' => false, 'message' => '...']
```

### Edge Cases

**Switching templates on a project with existing documents**: Changing the active template only affects the dropdown options (fasi, zone, discipline, tipi) for new documents. Existing documents retain their values even if those values don't exist in the new template. The dropdowns will show the stored value even if it's not in the current template's list.

**Concurrent editing**: No optimistic locking. If two users edit the same template simultaneously, last save wins. This is acceptable for the current user base (small team). A future enhancement could add `updated_at` checking if needed.

**Empty state**: If no templates exist (e.g., fresh install), the list view shows "Nessun template disponibile" with a prominent "Crea il primo template" button. The migration seeds a default global "Template Standard".

**Permissions for global templates**: Any user with `edit_commessa` can create, edit, and delete global templates. No admin-only restriction — the team is small and all editors are trusted.

### Frontend Changes

#### Toolbar chip (view + JS)

In `elenco_documenti.php` toolbar, after the Template button, add:
```html
<span class="ed-tpl-chip" id="tplChipName">&mdash;</span>
```

In JS, after `loadDocumenti()` populates data, set the chip text from `data.template_name`.

#### Template Panel — Two views

The panel `#tplPanel` switches between two views:

**1. List View** (default when opening):
- Header: "Gestione Template" + close button
- List of template cards, each showing:
  - Template name (bold)
  - Badge "In uso" if active for current project
  - `used_by_count` projects count as subtitle ("Usato da X commesse")
  - Action buttons: Applica, Modifica, Duplica, Elimina
- Footer: "Nuovo Template" button
- Elimina is disabled/hidden if `used_by_count > 0`
- Empty state: "Nessun template disponibile" + "Crea il primo template" button

**2. Editor View** (when clicking Modifica or Nuovo):
- Header: back arrow + "Modifica Template" / "Nuovo Template"
- Template name input field
- Tabs: Fasi, Zone, Discipline, Tipi (current editor, unchanged)
- Footer: "Annulla" (back to list), "Salva" (save + back to list)

#### JS State changes

```javascript
let _tplList = [];        // all templates from getTemplateList (with full data)
let _tplData = null;      // current template being edited (deep copy from _tplList)
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
  → reload lookups from applied template (find in _tplList by id)
  → update toolbar chip name
  → re-render list (update in_use flags locally)

tplEdit(templateId)
  → find template in _tplList (already has full data)
  → _tplData = deep copy of template
  → _tplEditId = templateId
  → _tplView = 'editor'
  → renderTplPanel()

tplNew()
  → _tplData = { nome_template: '', fasi: [], zone: [], discipline: [], tipi_documento: [] }
  → _tplEditId = null
  → _tplView = 'editor'
  → renderTplPanel()

tplDuplicate(templateId)
  → auto-name "Copia di X"
  → sendRequest('duplicateTemplate', { templateId, nomeTemplate })
  → add returned template to _tplList
  → re-render list

tplDelete(templateId)
  → confirm dialog
  → sendRequest('deleteTemplate', { templateId })
  → if success: remove from _tplList, re-render
  → if error (in use): show alert with message

saveTemplateData()
  → sendRequest('saveTemplate', { templateId: _tplEditId, nomeTemplate, fasi, zone, discipline, tipiDocumento })
  → update _tplList with returned data
  → if was editing the active template, refresh lookups + chip
  → _tplView = 'list'
  → renderTplPanel()

tplBackToList()
  → _tplView = 'list'
  → renderTplPanel()
```

### CSS Changes (elenco_documenti.css)

Add styles for the list view inside `#tplPanel`:
- `.ed-tpl-list` — scrollable container
- `.ed-tpl-card` — template card row (flex, border-bottom, padding)
- `.ed-tpl-card-info` — left side with name + meta
- `.ed-tpl-card-name` — bold name
- `.ed-tpl-card-meta` — subtitle (used by X commesse)
- `.ed-tpl-card-badge` — "In uso" badge (green background)
- `.ed-tpl-card-actions` — right-aligned action buttons (small icon buttons)
- `.ed-tpl-name-input` — name input in editor view (full width, styled like .ed-input)
- `.ed-tpl-back-btn` — back arrow button in editor header
- `.ed-tpl-empty` — empty state styling

Existing editor styles (`.ed-tpl-table`, `.ed-tpl-tab`, etc.) remain unchanged.

### Permissions

- `view_commesse`: sees the template chip in the toolbar (read-only)
- `edit_commessa`: can open the template panel and perform all operations (apply, edit, duplicate, delete, create). This includes global templates.

### Migration

Single SQL file creating `elenco_doc_project_template` table. No changes to `elenco_doc_commessa`.

Seed: for each project that currently has a non-global record in `elenco_doc_commessa`, insert a row in `elenco_doc_project_template` pointing to that template (preserves current behavior).

### Files Affected

| File | Change |
|------|--------|
| `services/ElencoDocumentiService.php` | Add 4 new methods + 4 new cases in `handleAction`, modify `getTemplate` signature, modify `saveTemplate` response, modify `getDocumenti` response |
| `views/elenco_documenti.php` | Add chip in toolbar, rework `#tplPanel` HTML to support list/editor views |
| `assets/js/elenco_documenti.js` | Rework template panel JS (list/editor views, new state vars, new AJAX calls) |
| `assets/css/elenco_documenti.css` | Add list view styles for template cards |
| `core/migrations/` | New migration for `elenco_doc_project_template` table + seed |
