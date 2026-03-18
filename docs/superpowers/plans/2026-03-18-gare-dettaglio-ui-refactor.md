# Refactor UI gare_dettaglio — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat table rendering of gare_dettaglio with a rich sectioned layout: header with meta cells, timeline, stat/feature cards, contextual tables, req-cards, and a fallback table for remaining fields.

**Architecture:** Pure frontend refactor. Backend unchanged. gare_detail.js gets a dispatcher that classifies extractions by type_code into sections. Each section has its own render function. CSS components from the export preview adapted to the project's design system.

**Tech Stack:** Vanilla JS (ES6), CSS, PHP (view shell only)

**Spec:** `docs/superpowers/specs/2026-03-18-gare-dettaglio-ui-refactor-design.md`
**Visual reference:** `.agent/exports/gare_dettaglio/gare_dettaglio_preview.html`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `assets/css/gare.css` | Modify | Add all new component styles (intestazione, timeline, cards, req-cards, etc.) |
| `views/gare_dettaglio.php` | Rewrite | HTML shell with section containers |
| `assets/js/gare_detail.js` | Rewrite rendering | New dispatcher + section render functions |

---

## Task 1: CSS — Add all component styles to gare.css

**Files:**
- Modify: `assets/css/gare.css`

**Context:** Port the CSS components from `.agent/exports/gare_dettaglio/gare_dettaglio_preview.html` (lines 8-224) into `gare.css`. These go BEFORE the existing `@media print` section. Adapt colors to use the project's existing CSS variables where they exist, fall back to the export preview's colors where they don't.

- [ ] **Step 1: Read the export preview CSS**

Read `.agent/exports/gare_dettaglio/gare_dettaglio_preview.html` lines 8-224 for all CSS.

- [ ] **Step 2: Read existing gare.css to find insertion point**

The new styles go BEFORE the `/* Print Utilities */` section (currently around line 1396).

- [ ] **Step 3: Add CSS variables block**

Add the CSS custom properties from the export preview at the top of the new section. Only add variables that DON'T already exist in the project. Check `assets/css/styles.css` for existing variables first.

```css
/* ============================================
   GARE DETTAGLIO — Component Styles
   ============================================ */
```

- [ ] **Step 4: Add component styles**

Port these component groups from the preview, in order:
1. **Intestazione** (`.intest`, `.int-top`, `.int-left`, `.int-right`, `.pdf-chip`, `.int-meta2`, `.int-file`, `.int-title`)
2. **Meta cells** (`.meta-row`, `.mc`, `.mc.hi`, `.mc-l`, `.mc-v`)
3. **Confidence strip** (`.conf-strip`, `.conf-bar`, `.conf-fill`, `.conf-pct`, `.conf-info`)
4. **Section headers** (`.sec`, `.sec-hd`) — check if these conflict with existing `.sec` class
5. **Grid layouts** (`.g2`, `.g3`, `.g4`, `.span2`) — check for conflicts
6. **Cards** (`.card`, `.card-sm`) — check for conflicts with existing `.card` class
7. **Timeline** (`.timeline`, `.tl-title`, `.tl-track`, `.tl-item`, `.tl-dot`, `.tl-body`, `.tl-label`, `.tl-date`, `.tl-sub`, `.countdown`)
8. **Sopralluogo card** (`.sop-card`, `.sop-icon`, `.sop-body`, `.sop-label`, `.sop-val`, `.sop-note`)
9. **Stat card** (`.stat`, `.stat-l`, `.stat-v`, `.stat-sub`)
10. **Req cards** (`.req-grid`, `.req-card`, `.req-card-head`, `.req-name`, `.req-obbl`, `.req-desc`, `.req-cat`)
11. **Table card** (`.tcard`, `.tcard-hd`, `.dr`, `.er`, `.er-inner`, `.er-src`, `.er-lbl`, `.dbtn`, `.ar`)
12. **Import card** (`.imp-card`, `.imp-label`, `.imp-val`, `.imp-sub`, `.imp-bar`, `.imp-fill`)
13. **Info card** (`.info-card`, `.ic-l`, `.ic-v`)
14. **Chips** (`.chips`, `.chip`, `.chip.cta`)
15. **Empty state** (`.empty-section`, `.empty-head`, `.empty-icon`, `.empty-title`, `.empty-sub`)
16. **Actions** (`.actions`, `.abtn`, `.abtn.pri`, `.abtn.del`, `.sp`)
17. **Badges** (`.badge`, `.bg`, `.bb`, `.ba`, `.br`, `.bp`, `.dot`) — check for conflicts with existing badge classes

**IMPORTANT:** For ANY class that already exists in the project's CSS, prefix with `gd-` (gare dettaglio) to avoid conflicts. Check `styles.css`, `modals-dialogs.css`, `badges-alerts.css`, `buttons.css` for conflicts.

- [ ] **Step 5: Commit**

```bash
git add assets/css/gare.css
git commit -m "feat: add gare_dettaglio component styles (intestazione, timeline, cards, req-cards)"
```

---

## Task 2: PHP View — Rewrite gare_dettaglio.php

**Files:**
- Modify: `views/gare_dettaglio.php`

**Context:** Replace the current minimal shell with a structured HTML template with section containers. JS will populate each container. The view stays thin — no PHP logic, just structure.

- [ ] **Step 1: Read current gare_dettaglio.php**

Understand what exists (the `#gare-detail-root`, `#api-status-container`, `#gare-jobs`, `#batch-usage-container` containers, plus the style tag and CSS we added recently).

- [ ] **Step 2: Rewrite the view**

Replace the content with:

```php
<?php if (!defined('AccessoFileInterni')) { die(); } ?>

<div class="main-container">
  <div id="gare-detail-root" data-job-id="0">

    <!-- Loading state -->
    <div id="gd-loading" class="gd-loading">
      <div class="spinner"><div class="spinner-border"></div></div>
      <p>Caricamento dettaglio gara...</p>
    </div>

    <!-- Error state -->
    <div id="gd-error" class="gd-error" style="display:none"></div>

    <!-- Intestazione -->
    <div id="gd-header" style="display:none"></div>

    <!-- API Status (from previous migration) -->
    <div id="api-status-container"></div>

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

  </div>
</div>
```

Keep the existing `<style>` tag for print-specific @page rules if present.

- [ ] **Step 3: Commit**

```bash
git add views/gare_dettaglio.php
git commit -m "feat: rewrite gare_dettaglio.php with sectioned container structure"
```

---

## Task 3: JS — Core dispatcher and data classification

**Files:**
- Modify: `assets/js/gare_detail.js`

**Context:** This is the biggest task. The current file has ~1500 lines of rendering code organized around a flat table view. We need to restructure it around a section-based dispatcher. Keep ALL existing utility functions (escapeHtml, formatDate, customFetch wrappers, etc.) and the data loading logic. Replace only the rendering functions.

**IMPORTANT:** Read the ENTIRE current file first to understand what to keep vs rewrite. The following MUST be preserved:
- All `customFetch` calls and data loading logic
- `normalizeItems()`, `normalizeJob()`, `sortExtractionItems()`
- `buildExtractionTabs()`, `renderTabbedDetail()` (reused in fallback table)
- `renderValueCell()`, `extractPrimaryValue()`, `stringifyValue()`
- `collectCitations()`
- `loadApiStatus()`, `loadBatchUsage()`, `renderHighlightedPdfLinks()`
- All print rendering functions (`renderJobsPrint`, `renderPrintExtraction`, etc.)
- All utility functions (escapeHtml, formatDate, isISODateString, etc.)

- [ ] **Step 1: Add the extraction type → section classification map**

Near the top of the IIFE, add:

```javascript
// Maps extraction type_code to the section it belongs to
const SECTION_MAP = {
  // Header meta cells
  oggetto_appalto: 'header',
  stazione_appaltante: 'header',
  data_scadenza_gara_appalto: 'header',
  data_uscita_gara_appalto: 'header',
  luogo_provincia_appalto: 'header',
  tipologia_di_gara: 'header',
  link_portale_stazione_appaltante: 'header',

  // Overview section
  sopralluogo_obbligatorio: 'overview',
  tipologia_di_appalto: 'overview',
  settore_industriale_gara_appalto: 'overview',
  settore_gara: 'overview',

  // Importi section
  importi_opere_per_categoria_id_opere: 'importi',
  importi_corrispettivi_categoria_id_opere: 'importi',
  importi_requisiti_tecnici_categoria_id_opere: 'importi',

  // Requisiti section
  requisiti_tecnico_professionali: 'requisiti',

  // Economici section
  fatturato_globale_n_minimo_anni: 'economici',
  requisiti_di_capacita_economica_finanziaria: 'economici',

  // Docs & roles section
  documentazione_richiesta_tecnica: 'docs_ruoli',
  requisiti_idoneita_professionale_gruppo_lavoro: 'docs_ruoli',
  documenti_di_gara: 'docs_ruoli',
  criteri_valutazione_offerta_tecnica: 'docs_ruoli',
};

function classifyExtractions(items) {
  const sections = {
    header: [], overview: [], importi: [],
    requisiti: [], economici: [], docs_ruoli: [],
    fallback: []
  };
  for (const item of items) {
    const type = (item.type_code || item.tipo || '').toLowerCase();
    const section = SECTION_MAP[type] || 'fallback';
    sections[section].push(item);
  }
  return sections;
}
```

- [ ] **Step 2: Add the main dispatcher function**

Replace the current `renderJobs()` / `renderJobsContent()` main render flow with a dispatcher that calls section renderers:

```javascript
function renderGaraDetail(job) {
  const items = job.normalized_items || [];
  const sections = classifyExtractions(items);

  // Helper to find extraction by type
  const byType = (type) => items.find(i =>
    (i.type_code || i.tipo || '').toLowerCase() === type
  );

  renderHeader(job, sections.header, byType);
  renderOverview(job, sections.overview, byType);
  renderImporti(sections.importi);
  renderRequisiti(sections.requisiti);
  renderEconomici(sections.economici);
  renderDocsRuoli(sections.docs_ruoli);
  renderAllFields(sections.fallback);
  renderActionBar(job);

  // Hide loading, show sections
  hide('gd-loading');
  ['gd-header','gd-overview','gd-importi','gd-requisiti',
   'gd-economici','gd-docs-ruoli','gd-all-fields','gd-actions'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (el && el.innerHTML.trim()) el.style.display = '';
  });
}
```

- [ ] **Step 3: Commit core dispatcher**

```bash
git add assets/js/gare_detail.js
git commit -m "feat: add extraction classifier and section dispatcher for gare_dettaglio"
```

---

## Task 4: JS — Section renderers (header, overview, importi)

**Files:**
- Modify: `assets/js/gare_detail.js`

- [ ] **Step 1: Implement `renderHeader()`**

Renders the `.intest` card with:
- Top bar: PDF chip, title (from oggetto_appalto or file_name), status badge, element count
- Meta row: 6 cells (stazione_appaltante, scadenza, tipologia_gara, luogo, aggiornato, portale)
- Confidence strip: bar + percentage + field count

Uses data from the classified `header` items + job metadata.

- [ ] **Step 2: Implement `renderOverview()`**

Renders the panoramica section with grid 2 columns:
- Left: Timeline (data_uscita → sopralluogo → data_scadenza + countdown)
- Right: Sopralluogo card + 2 stat cards + info card tipologia appalto

- [ ] **Step 3: Implement `renderImporti()`**

Renders importi section:
- 4 stat cards in a row (importo lavori, corrispettivi, fatturato, primo ID opera)
- Below: detail tables for each importi type (reuse existing table rendering from `buildExtractionTabs`)

- [ ] **Step 4: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat: implement header, overview, importi section renderers"
```

---

## Task 5: JS — Section renderers (requisiti, economici, docs, fallback, actions)

**Files:**
- Modify: `assets/js/gare_detail.js`

- [ ] **Step 1: Implement `renderRequisiti()`**

Renders req-cards for `requisiti_tecnico_professionali`:
- Each requirement as a `.req-card` with name, badge (obbligatorio/facoltativo), description, category
- Uses data from the extraction's table rows or value_json

- [ ] **Step 2: Implement `renderEconomici()`**

Renders fatturato + capacità economica:
- Fatturato as stat card + detail table
- Capacità as table (reuse existing table rendering)

- [ ] **Step 3: Implement `renderDocsRuoli()`**

Grid 2 columns:
- Left: documentazione_richiesta_tecnica as table
- Right: idoneità professionale as req-cards for each role
- documenti_di_gara as chips
- criteri_valutazione as table

- [ ] **Step 4: Implement `renderAllFields()`**

Classic table with expand for remaining items not shown elsewhere.
Uses the existing `buildExtractionTabs()` and `renderTabbedDetail()`.

Structure: `.tcard` with `.tcard-hd` + table with `.dr` (data rows) and `.er` (expand rows).

- [ ] **Step 5: Implement `renderActionBar()`**

Action buttons: Esporta CSV, Stampa, Ri-estrai, Elimina gara.

- [ ] **Step 6: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat: implement requisiti, economici, docs, fallback, actions renderers"
```

---

## Task 6: Integration and cleanup

- [ ] **Step 1: Wire up the dispatcher to the data loading flow**

In the existing data loading code (where `renderJobs()` or `loadJobResults()` is called), call `renderGaraDetail(job)` instead for the single-job view.

- [ ] **Step 2: Update print rendering**

Update print functions to use the same section structure. The print layout should mirror the screen layout sections.

- [ ] **Step 3: Remove dead rendering code**

Remove old rendering functions that are no longer called:
- Old `renderJobs()` / `renderJobsContent()` if replaced
- Any rendering code that was only used by the flat table view

**Be careful:** only remove code that is truly dead. Check all callers before deleting.

- [ ] **Step 4: Test manually**

- Open a completed gara (e.g., job 258 or 266) — verify all sections render
- Open a queued/processing gara — verify loading/status shows correctly
- Test print (Ctrl+P) — verify sections print correctly
- Test action buttons (CSV export, re-extract)

- [ ] **Step 5: Commit**

```bash
git add assets/js/gare_detail.js views/gare_dettaglio.php assets/css/gare.css
git commit -m "feat: complete gare_dettaglio UI refactor with sectioned layout"
```
