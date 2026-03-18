# Elenco Documenti — Completamento Pagina — Piano Operativo

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Completare la pagina Elenco Documenti allineandola al reference del capo: tabella a 19 colonne split con inline editing, stat cards, template config panel, export Excel, auto-creazione cartelle Nextcloud.

**Architecture:** Riscrittura progressiva del frontend (rendering tabella + inline editing) mantenendo intatto tutto il codice funzionante (submittal, lettera, mail, Nextcloud browser, props panel). Backend quasi completo, solo aggiunte puntuali.

**Tech Stack:** PHP (PDO), Vanilla JS (IIFE pattern), CSS, table-filterable component, SimpleXLSXGen, WebDAV/Nextcloud

**Spec:** `docs/superpowers/specs/2026-03-16-elenco-documenti-completamento-design.md`

---

## File Structure

### Files da modificare
| File | Responsabilita | Stima modifiche |
|------|---------------|-----------------|
| `assets/js/modules/main_core.js:784` | Fix `initTableFilters()` per doppio header | ~10 righe |
| `assets/js/elenco_documenti.js:340-461` | Riscrittura `renderSections()` + `buildRowHtml()` | ~300 righe |
| `assets/js/elenco_documenti.js:463-470` | Riscrittura `updateStats()` | ~20 righe |
| `assets/js/elenco_documenti.js:1473-1510` | Aggiornamento `init()` (rimozione filtri manuali, aggiunta template panel) | ~30 righe |
| `views/elenco_documenti.php:45-150` | Riscrittura stat cards + toolbar + sezione template panel HTML | ~100 righe |
| `assets/css/elenco_documenti.css` | Stili gruppi header, stat cards, inline dropdowns, template panel | ~200 righe |
| `services/ElencoDocumentiService.php` | Aggiunta `exportExcel()` + logica NC in `saveDocumento()` | ~80 righe |

### Files di riferimento (read-only)
| File | Uso |
|------|-----|
| `.agent/exports/elenco_documenti_example/document_list_preview.html` | Reference UI/CSS del capo |
| `assets/js/modules/main_core.js` | Capire `initTableFilters()` |
| `assets/js/modules/table_resize.js` | Capire resize columns |
| `assets/css/tables.css` | Stili table-filterable esistenti |

---

## Chunk 1: Preparazione infrastruttura

### Task 1: Fix main_core.js per supportare riga th-groups

**Files:**
- Modify: `assets/js/modules/main_core.js:784`
- Modify: `assets/js/modules/main_core.js:1028`
- Modify: `assets/js/modules/main_core.js:1531`

**Contesto:** `initTableFilters()` usa `thead.rows[0]` come riga header. Con la riga `th-groups` sopra, si rompe. Serve trovare la riga header corretta (quella senza classe `th-groups`).

- [ ] **Step 1: Leggere il codice attuale**

Leggere `assets/js/modules/main_core.js` righe 744-830 (initTableFilters locale), 1020-1100 (initRemoteTable), 1525-1545 (altra referenza thead.rows[0]). Capire tutti i punti che assumono `thead.rows[0]` come header row.

- [ ] **Step 2: Creare helper function**

Aggiungere in cima alla funzione `initTableFilters` (dopo riga 758):
```javascript
// Trova la riga header effettiva, skippando righe decorative (es. th-groups)
function getHeaderRow(thead) {
    for (let i = 0; i < thead.rows.length; i++) {
        if (!thead.rows[i].classList.contains('th-groups') &&
            !thead.rows[i].classList.contains('filter-row')) {
            return thead.rows[i];
        }
    }
    return thead.rows[0]; // fallback
}
```

- [ ] **Step 3: Sostituire thead.rows[0] con getHeaderRow(thead)**

In modalita locale (riga 784):
```javascript
// PRIMA: const headerRow = thead.rows[0];
const headerRow = getHeaderRow(thead);
```

In `initRemoteTable` (riga 1028):
```javascript
// PRIMA: const headerRow = thead.rows[0];
const headerRow = getHeaderRow(thead);
```

In ogni altro punto che usa `thead.rows[0]` per la riga header (riga 1531):
```javascript
// PRIMA: const headerRow = thead.rows[0];
const headerRow = getHeaderRow(thead);
```

- [ ] **Step 4: Fix insertRow per filter-row**

In modalita locale (riga 794), la `filterRow` viene inserita con `thead.insertRow(1)`. Con il doppio header, deve andare DOPO la riga header effettiva. Cambiare:
```javascript
// PRIMA: const filterRow = thead.insertRow(1);
// Inserisci filter row subito dopo la header row effettiva
const headerRowIndex = Array.from(thead.rows).indexOf(headerRow);
const filterRow = thead.insertRow(headerRowIndex + 1);
```

Stessa modifica in initRemoteTable (riga 1084):
```javascript
const headerRowIndex = Array.from(thead.rows).indexOf(headerRow);
const filterRow = thead.insertRow(headerRowIndex + 1);
```

- [ ] **Step 5: Verificare retrocompatibilita**

Aprire nel browser una pagina che usa `table-filterable` senza `th-groups` (es. `?page=elenco_gare` o `?page=commesse`). Verificare che filtri, resize e paginazione funzionino esattamente come prima.

- [ ] **Step 6: Commit**
```
fix: support th-groups row in table-filterable initTableFilters
```

---

### Task 2: Installare SimpleXLSXGen

**Files:**
- Create: `IntLibs/SimpleXLSXGen/SimpleXLSXGen.php` (o via Composer)

- [ ] **Step 1: Verificare se presente**

Cercare `SimpleXLSXGen` nel progetto. Se non trovato (confermato: assente), procedere.

- [ ] **Step 2: Installare**

Opzione A (preferita, coerente con SimpleXLSX gia in IntLibs):
```bash
# Scaricare da https://github.com/shuchkin/simplexlsxgen
# Copiare SimpleXLSXGen.php in IntLibs/SimpleXLSXGen/
```

Opzione B (Composer):
```bash
composer require shuchkin/simplexlsxgen
```

- [ ] **Step 3: Verificare che il file sia accessibile**

Controllare che l'autoloader o un `require_once` possa caricare la classe.

- [ ] **Step 4: Commit**
```
chore: add SimpleXLSXGen library for Excel export
```

---

## Chunk 2: Stat Cards + Toolbar

### Task 3: Riscrittura stat cards nella view PHP

**Files:**
- Modify: `views/elenco_documenti.php:46-77` (header section)

**Contesto:** Sostituire le 3 stat inline nell'header con 3 cards a griglia con bordo colorato. Gli ID `tot-count`, `avg-prog`, `issued-count` devono essere mantenuti perche il JS li usa in `updateStats()`.

- [ ] **Step 1: Leggere il codice attuale**

Leggere `views/elenco_documenti.php` righe 46-77 per capire la struttura header attuale.

- [ ] **Step 2: Sostituire header con stat cards**

Sostituire il blocco `<div class="ed-header">...</div>` (righe ~47-77) con:
```php
<div class="ed-header">
    <div class="ed-header-left">
        <h1 class="ed-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
                <polyline points="10 9 9 9 8 9"/>
            </svg>
            Elenco Documenti
        </h1>
        <span class="ed-project-badge" id="projectBadge">Caricamento...</span>
    </div>
</div>
<div class="ed-stat-cards">
    <div class="ed-stat-card ed-stat-red">
        <div class="ed-stat-value" id="tot-count">0</div>
        <div class="ed-stat-label">Documenti totali</div>
        <div class="ed-stat-sub" id="stat-sub-docs">—</div>
    </div>
    <div class="ed-stat-card ed-stat-green">
        <div class="ed-stat-value" id="avg-prog">0%</div>
        <div class="ed-stat-label">Avanzamento medio</div>
        <div class="ed-stat-sub" id="stat-sub-issued">—</div>
    </div>
    <div class="ed-stat-card ed-stat-yellow">
        <div class="ed-stat-value" id="stat-submittal-count">0</div>
        <div class="ed-stat-label">Submittal</div>
        <div class="ed-stat-sub" id="stat-sub-submittal">—</div>
    </div>
</div>
```

- [ ] **Step 3: Aggiornare CSS**

Aggiungere in `assets/css/elenco_documenti.css`:
```css
/* Stat Cards */
.ed-stat-cards {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 14px;
}
.ed-stat-card {
    background: var(--sf, #fff);
    border: 1px solid var(--br, #e2e4e8);
    border-radius: 6px;
    padding: 12px 14px;
    border-left: 3px solid var(--gl, #ccc);
}
.ed-stat-red { border-left-color: var(--red, #cd211d); }
.ed-stat-green { border-left-color: #10b981; }
.ed-stat-yellow { border-left-color: #f59e0b; }
.ed-stat-value {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.5px;
}
.ed-stat-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--mu, #6b7280);
    margin-top: 1px;
}
.ed-stat-sub {
    font-size: 11px;
    color: var(--mu, #6b7280);
    margin-top: 3px;
}
```

- [ ] **Step 4: Aggiornare updateStats() nel JS**

In `assets/js/elenco_documenti.js`, sostituire `updateStats()` (righe ~463-470) con:
```javascript
function updateStats() {
    const docs = allDocs();
    const el = (id) => document.getElementById(id);

    // Card 1: Documenti totali
    el('tot-count').textContent = docs.length;
    const nSec = sections.length;
    const nDisc = new Set(docs.map(d => d.disc).filter(Boolean)).size;
    const subDocs = el('stat-sub-docs');
    if (subDocs) subDocs.textContent = `${nSec} sezioni · ${nDisc} discipline`;

    // Card 2: Avanzamento medio
    const avg = docs.length ? Math.round(docs.reduce((a, d) => a + (d.prog || 0), 0) / docs.length) : 0;
    el('avg-prog').textContent = avg + '%';
    const iss = docs.filter(d => d.status === 'EMESSO').length;
    const subIss = el('stat-sub-issued');
    if (subIss) subIss.textContent = `${iss} emessi su ${docs.length}`;

    // Card 3: Submittal
    const subEl = el('stat-submittal-count');
    if (subEl) subEl.textContent = submittals.length;
    const subSub = el('stat-sub-submittal');
    if (subSub) {
        const planned = submittals.filter(s => s.status === 'Pianificato');
        if (planned.length > 0) {
            const next = planned.sort((a, b) => (a.date || '').localeCompare(b.date || ''))[0];
            subSub.textContent = `Prossimo: ${isoToDisp(next.date)}`;
        } else {
            subSub.textContent = submittals.length > 0 ? 'Tutti emessi' : 'Nessun submittal';
        }
    }
}
```

- [ ] **Step 5: Verificare nel browser**

Aprire la pagina `?section=commesse&page=commessa&tabella=3DY01&view=elaborati`. Le 3 stat cards devono apparire sopra la toolbar con bordi colorati.

- [ ] **Step 6: Commit**
```
feat(elenco-documenti): stat cards con bordo colorato e sottotesti informativi
```

---

### Task 4: Aggiornare toolbar (bottoni Template + Export)

**Files:**
- Modify: `views/elenco_documenti.php:79-133` (toolbar section)

- [ ] **Step 1: Leggere toolbar attuale**

Leggere righe 79-133 di `views/elenco_documenti.php`.

- [ ] **Step 2: Aggiungere bottoni nella toolbar-right**

Nella `<div class="ed-toolbar-right">`, aggiungere PRIMA del bottone Submittal:
```php
<?php if (userHasPermission('edit_commessa')): ?>
<button class="btn btn-secondary ed-btn" id="btnConfigTemplate" title="Configura Template">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
        <circle cx="12" cy="12" r="3"/>
        <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
    </svg>
    Template
</button>
<?php endif; ?>
<button class="btn btn-secondary ed-btn" id="btnExport" title="Esporta Excel">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="7 10 12 15 17 10"/>
        <line x1="12" y1="15" x2="12" y2="3"/>
    </svg>
    Esporta
</button>
```

- [ ] **Step 3: Rimuovere filtri globali dalla toolbar**

Rimuovere il blocco `<div class="ed-filter-group">` (righe ~82-112) con i select filter-stato/filter-disc/filter-resp/filter-text. Questi filtri vengono ora gestiti automaticamente da `table-filterable` nella riga filter-row di ogni tabella.

- [ ] **Step 4: Aggiornare init() nel JS**

In `assets/js/elenco_documenti.js`, nella funzione `init()` (righe ~1486-1495):
- Rimuovere i listener per `filter-stato`, `filter-disc`, `filter-resp`, `filter-text` (righe 1489-1492) — non servono piu, i filtri sono gestiti da table-filterable.
- Aggiungere:
```javascript
document.getElementById('btnConfigTemplate')?.addEventListener('click', openTemplatePanel);
document.getElementById('btnExport')?.addEventListener('click', exportExcel);
```

- [ ] **Step 5: Verificare nel browser**

I nuovi bottoni devono apparire nella toolbar. I vecchi filtri select non devono piu esserci.

- [ ] **Step 6: Commit**
```
feat(elenco-documenti): toolbar con bottoni Template e Esporta, rimossi filtri manuali
```

---

## Chunk 3: Tabella a 19 colonne con inline editing

### Task 5: Riscrittura renderSections() per tabella split

**Files:**
- Modify: `assets/js/elenco_documenti.js:340-418` (renderSections)

**Contesto:** Questa e la modifica piu grande. La funzione attuale genera una tabella `ed-table` con 8 colonne. Va riscritta per generare una `table table-filterable` con 19 colonne, doppio header con gruppi colorati, e inizializzare table-filterable su ogni tabella di sezione.

- [ ] **Step 1: Leggere renderSections() attuale**

Leggere `assets/js/elenco_documenti.js` righe 340-418.

- [ ] **Step 2: Riscrivere renderSections()**

Sostituire la funzione `renderSections()` con questa nuova versione:

```javascript
function renderSections() {
    const cont = document.getElementById('seccont');
    if (!cont) return;
    cont.innerHTML = '';

    if (sections.length === 0) {
        cont.innerHTML = '<div class="ed-empty-state">Nessuna sezione. Clicca "Nuova Sezione" per iniziare.</div>';
        return;
    }

    const canEdit = window.userHasPermission && window.userHasPermission('edit_commessa');

    sections.forEach(sec => {
        const secDiv = document.createElement('div');
        secDiv.className = 'ed-section';

        // Section header (collapsible)
        secDiv.innerHTML = `
            <div class="ed-section-header" onclick="ElencoDoc.toggleSec('${sec.id}', this)">
                <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
                <span class="ed-section-title">${escapeHtml(sec.name)}</span>
                <span class="ed-section-badge" onclick="event.stopPropagation();ElencoDoc.openRangePicker(event,'${sec.id}')">${sec.rangeFrom || 0}–${sec.rangeTo || 999}</span>
                <span class="ed-section-count">${(sec.docs || []).length} documenti</span>
                ${canEdit ? `
                <div class="ed-section-actions">
                    <button class="ed-section-btn" onclick="event.stopPropagation();ElencoDoc.renameSection('${sec.id}')" title="Rinomina">✏</button>
                    <button class="ed-section-btn" onclick="event.stopPropagation();ElencoDoc.deleteSectionConfirm('${sec.id}')" title="Elimina">🗑</button>
                </div>` : ''}
            </div>
            <div class="ed-section-body" id="body-${sec.id}">
                <table class="table table-filterable" id="tbl-${sec.id}" data-no-pagination="true" data-table-key="ed-sec-${sec.id}">
                    <thead>
                        <tr class="th-groups">
                            <th colspan="7" class="grp-code">Codice Documento</th>
                            <th colspan="4" class="grp-info">Informazioni</th>
                            <th colspan="2" class="grp-state">Stato</th>
                            <th colspan="4" class="grp-plan">Pianificazione</th>
                            <th colspan="2"></th>
                        </tr>
                        <tr>
                            <th style="width:52px">Fase</th>
                            <th style="width:42px">Zona</th>
                            <th style="width:42px">Disc</th>
                            <th style="width:42px">Tipo</th>
                            <th style="width:58px">Num</th>
                            <th style="width:36px">Rev</th>
                            <th style="min-width:160px">Codice</th>
                            <th style="min-width:180px">Titolo</th>
                            <th style="width:90px">Tipo doc</th>
                            <th style="width:70px">Resp.</th>
                            <th style="width:70px">Output</th>
                            <th style="width:100px">Stato</th>
                            <th style="width:70px">Avanz.</th>
                            <th style="width:85px">Inizio</th>
                            <th style="width:85px">Fine prev.</th>
                            <th style="width:85px">Emissione</th>
                            <th style="width:100px">Submittal</th>
                            <th style="width:50px">File</th>
                            <th style="width:60px" class="azioni-colonna">Azioni</th>
                        </tr>
                    </thead>
                    <tbody id="tb-${sec.id}">
                        ${(sec.docs || []).map(d => buildRowHtml(d, canEdit)).join('')}
                        ${canEdit ? `
                        <tr class="ed-add-row">
                            <td colspan="19">
                                <button class="ed-add-btn" onclick="ElencoDoc.addDocToSection('${sec.id}')">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                                        <line x1="12" y1="5" x2="12" y2="19"/>
                                        <line x1="5" y1="12" x2="19" y2="12"/>
                                    </svg>
                                    Aggiungi documento
                                </button>
                            </td>
                        </tr>` : ''}
                    </tbody>
                </table>
            </div>
        `;
        cont.appendChild(secDiv);

        // Inizializza table-filterable su questa tabella
        if (window.initTableFilters) {
            window.initTableFilters('tbl-' + sec.id);
        }
    });
}
```

- [ ] **Step 3: Aggiungere CSS per gruppi header**

In `assets/css/elenco_documenti.css`:
```css
/* Column group headers */
.ed-section-body .th-groups th {
    padding: 4px 8px;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-align: center;
    white-space: nowrap;
    background: #f4f5f7;
    color: var(--mu, #6b7280);
    border-bottom: 1px solid #e8eaed;
}
.ed-section-body .grp-code { background: #eef0f8; color: #4f46e5; }
.ed-section-body .grp-info { background: #f0fdf4; color: #166534; }
.ed-section-body .grp-state { background: #fef3c7; color: #92400e; }
.ed-section-body .grp-plan { background: #fff7ed; color: #9a3412; }
```

- [ ] **Step 4: Verificare che le sezioni si rendano**

Aprire la pagina nel browser. Le sezioni devono mostrare la tabella con doppio header e gruppi colorati. I filtri table-filterable devono apparire nella riga sotto le colonne.

- [ ] **Step 5: Commit**
```
feat(elenco-documenti): renderSections con tabella 19 colonne e gruppi colorati
```

---

### Task 6: Riscrittura buildRowHtml() per 19 colonne

**Files:**
- Modify: `assets/js/elenco_documenti.js:420-461` (buildRowHtml)

**Contesto:** La funzione attuale genera una riga con 8 `<td>`. Va riscritta per 19 colonne, con celle cliccabili per inline editing.

- [ ] **Step 1: Leggere buildRowHtml() attuale**

Leggere `assets/js/elenco_documenti.js` righe 420-461.

- [ ] **Step 2: Riscrivere buildRowHtml()**

Sostituire con:
```javascript
function buildRowHtml(doc, canEdit) {
    const cfg = STATUS_CFG[doc.status] || STATUS_CFG['PIANIFICATO'];
    const today = new Date();
    const code = codeStr(doc);

    // Date formatting helpers
    const fmtDate = (iso) => isoToDisp(iso);
    const dateCls = (iso) => {
        if (!iso || iso === '—') return 'empty';
        return new Date(iso) < today ? 'late' : 'ok';
    };

    // Tipo label from lookup
    const tipoLkp = (lookups.tipo || []).find(t => t.c === doc.tipo);
    const tipoLabel = tipoLkp ? tipoLkp.d : doc.tipo;

    // File count
    const fileCount = (doc.files || []).length;

    // Submittal info
    const sub = submittals.find(s => (s.docIds || []).includes(doc.id));
    const subHtml = sub
        ? `<span class="ed-sub-chip" onclick="event.stopPropagation();ElencoDoc.openSmgr()" title="${escapeHtml(sub.code)}">${escapeHtml(sub.code)}</span>`
        : '<span class="ed-cell-empty">—</span>';

    // Edit class helper
    const ec = canEdit ? 'ed-cell-edit' : '';

    return `
        <tr data-id="${doc.id}">
            <!-- CODICE DOCUMENTO -->
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openSegDrop(event,this,'fase','${doc.id}')"` : ''}>
                <span class="ed-seg">${escapeHtml(doc.fase)}</span>
            </td>
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openSegDrop(event,this,'zona','${doc.id}')"` : ''}>
                <span class="ed-seg">${escapeHtml(doc.zona)}</span>
            </td>
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openSegDrop(event,this,'disc','${doc.id}')"` : ''}>
                <span class="ed-seg">${escapeHtml(doc.disc)}</span>
            </td>
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openSegDrop(event,this,'tipo','${doc.id}')"` : ''}>
                <span class="ed-seg">${escapeHtml(doc.tipo)}</span>
            </td>
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openNumEdit(event,this,'${doc.id}')"` : ''}>
                <span class="ed-num">${fmtNum(doc.num)}</span>
            </td>
            <td><span class="ed-rev-badge">${doc.rev || '—'}</span></td>
            <td><span class="ed-full-code" title="${code}">${code}</span></td>
            <!-- INFORMAZIONI -->
            <td class="ed-title-cell" onclick="ElencoDoc.openProps('${doc.id}')" style="cursor:pointer">
                <div class="ed-doc-title">${escapeHtml(doc.title)}</div>
                ${doc.sub ? `<div class="ed-doc-sub">${escapeHtml(doc.sub)}</div>` : ''}
            </td>
            <td><span class="ed-tipo-label">${escapeHtml(tipoLabel)}</span></td>
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openSegDrop(event,this,'resp','${doc.id}')"` : ''}>
                <span class="ed-badge-resp">${respDisplay(doc.resp)}</span>
            </td>
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openSegDrop(event,this,'output','${doc.id}')"` : ''}>
                <span class="ed-badge-out">${escapeHtml(doc.output || '—')}</span>
            </td>
            <!-- STATO -->
            <td>
                <span class="ed-status-badge ${cfg.cls}" ${canEdit ? `onclick="ElencoDoc.openStatusPop(event,this,'${doc.id}')"` : ''}>
                    <span class="ed-status-dot" style="background:${cfg.dot}"></span>
                    ${doc.status}
                </span>
            </td>
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openProg(event,this,'${doc.id}')"` : ''}>
                <div class="ed-progress-bar"><div class="ed-progress-fill ${pc(doc.prog || 0)}" style="width:${doc.prog || 0}%"></div></div>
                <div class="ed-progress-label">${doc.prog || 0}%</div>
            </td>
            <!-- PIANIFICAZIONE -->
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openDatePop(event,this,'${doc.id}','dateStart')"` : ''}>
                <span class="ed-date-display ${dateCls(doc.dateStart)}">${fmtDate(doc.dateStart)}</span>
            </td>
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openDatePop(event,this,'${doc.id}','dateEnd')"` : ''}>
                <span class="ed-date-display ${dateCls(doc.dateEnd)}">${fmtDate(doc.dateEnd)}</span>
            </td>
            <td class="${ec}" ${canEdit ? `onclick="ElencoDoc.openDatePop(event,this,'${doc.id}','dateEmission')"` : ''}>
                <span class="ed-date-display ${dateCls(doc.dateEmission)}">${fmtDate(doc.dateEmission)}</span>
            </td>
            <td>${subHtml}</td>
            <!-- FILE + AZIONI -->
            <td>
                <button class="ed-file-btn" onclick="event.stopPropagation();ElencoDoc.openProps('${doc.id}');setTimeout(()=>document.getElementById('pp-files-tab')?.click(),100)" title="File allegati">
                    ${fileCount > 0 ? `📄 ${fileCount}` : '📎'}
                </button>
            </td>
            <td class="ed-actions-cell">
                ${canEdit && doc.status === 'EMESSO' ? `<button class="ed-action-btn dup" onclick="event.stopPropagation();ElencoDoc.dupRevision('${doc.id}')" title="Nuova revisione">↻</button>` : ''}
                ${canEdit ? `<button class="ed-action-btn danger" onclick="event.stopPropagation();ElencoDoc.deleteDoc('${doc.id}')" title="Elimina">×</button>` : ''}
            </td>
        </tr>
    `;
}
```

- [ ] **Step 3: Aggiungere CSS per le nuove celle**

In `assets/css/elenco_documenti.css`:
```css
/* Segment cells */
.ed-seg {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    font-weight: 700;
    color: var(--gd, #666);
    display: block;
    text-align: center;
}
.ed-cell-edit { cursor: pointer; }
.ed-cell-edit:hover { background: rgba(205,33,29,.04); }

/* Num cell */
.ed-num {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    font-weight: 700;
    text-align: center;
    display: block;
}

/* Full code */
.ed-full-code {
    font-family: 'Courier New', monospace;
    font-size: 10px;
    font-weight: 700;
    color: #3730a3;
    background: #eef0fc;
    border: 1px solid #c7d0f5;
    border-radius: 4px;
    padding: 3px 7px;
    display: inline-block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
}

/* Tipo label */
.ed-tipo-label {
    font-size: 10px;
    color: var(--mu, #6b7280);
}

/* Badge resp/output */
.ed-badge-resp, .ed-badge-out {
    font-size: 10px;
    font-weight: 600;
    text-align: center;
    display: block;
}

/* Submittal chip */
.ed-sub-chip {
    font-family: 'Courier New', monospace;
    font-size: 10px;
    font-weight: 700;
    color: #4f46e5;
    background: #eef0fc;
    border: 1px solid #c7d0f5;
    border-radius: 4px;
    padding: 3px 7px;
    cursor: pointer;
    white-space: nowrap;
}
.ed-sub-chip:hover { background: #4f46e5; color: #fff; }

.ed-cell-empty { font-size: 10px; color: #d1d5db; font-style: italic; }

/* File button */
.ed-file-btn {
    border: none;
    background: none;
    cursor: pointer;
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 3px;
}
.ed-file-btn:hover { background: #e7f0ff; }
```

- [ ] **Step 4: Aggiornare reRenderRow()**

Trovare la funzione `reRenderRow()` nel JS (dovrebbe essere intorno a riga 830-835). Aggiornarla per usare il nuovo buildRowHtml con 2 argomenti:
```javascript
function reRenderRow(docId) {
    const doc = findDoc(docId);
    if (!doc) return;
    const canEdit = window.userHasPermission && window.userHasPermission('edit_commessa');
    const row = document.querySelector(`tr[data-id="${docId}"]`);
    if (row) {
        row.outerHTML = buildRowHtml(doc, canEdit);
    }
}
```

- [ ] **Step 5: Verificare nel browser**

La tabella deve mostrare 19 colonne con i dati split. Il codice completo deve essere calcolato. Scroll orizzontale funzionante.

- [ ] **Step 6: Commit**
```
feat(elenco-documenti): buildRowHtml con 19 colonne split e CSS celle
```

---

### Task 7: Inline editing — dropdown segmenti

**Files:**
- Modify: `assets/js/elenco_documenti.js` (aggiungere funzioni)

**Contesto:** Click su cella Fase/Zona/Disc/Tipo/Resp/Output apre un dropdown posizionato con i valori dalla lookup. La selezione aggiorna il doc e salva.

- [ ] **Step 1: Aggiungere openSegDrop()**

Aggiungere questa funzione nel JS (nella sezione POPUP MANAGEMENT dopo `closeAP()`):

```javascript
function openSegDrop(e, cell, seg, docId) {
    e.stopPropagation();
    closeAP();
    const doc = findDoc(docId);
    if (!doc) return;

    cell.style.position = 'relative';

    const items = lookups[seg] || [];
    const dd = document.createElement('div');
    dd.className = 'ed-popup ed-seg-dropdown';

    dd.innerHTML = `
        <div class="ed-seg-dd-header">${seg.toUpperCase()}</div>
        <div class="ed-seg-dd-list">
            ${items.map(item => `
                <div class="ed-seg-dd-item ${item.c === doc[seg] ? 'active' : ''}" data-val="${escapeHtml(item.c)}">
                    <span class="ed-seg-dd-code">${escapeHtml(item.c)}</span>
                    ${item.d !== item.c ? `<span class="ed-seg-dd-desc">${escapeHtml(item.d)}</span>` : ''}
                </div>
            `).join('')}
        </div>
    `;

    cell.appendChild(dd);
    activePopup = dd;

    dd.querySelectorAll('.ed-seg-dd-item').forEach(opt => {
        opt.addEventListener('click', async () => {
            const val = opt.dataset.val;
            doc[seg] = val;
            reRenderRow(docId);
            closeAP();
            flashSave();
            updateStats();
            await saveOneDoc(doc, doc.idSection);
        });
    });
}
```

- [ ] **Step 2: Aggiungere openNumEdit()**

```javascript
function openNumEdit(e, cell, docId) {
    e.stopPropagation();
    closeAP();
    const doc = findDoc(docId);
    if (!doc) return;

    cell.style.position = 'relative';
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'ed-num-input';
    input.value = fmtNum(doc.num);
    input.maxLength = 4;

    cell.innerHTML = '';
    cell.appendChild(input);
    input.focus();
    input.select();

    const save = async () => {
        const val = parseInt(input.value) || doc.num;
        doc.num = val;
        reRenderRow(docId);
        flashSave();
        await saveOneDoc(doc, doc.idSection);
    };

    input.addEventListener('blur', save);
    input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); input.blur(); }
        if (ev.key === 'Escape') { reRenderRow(docId); }
    });
}
```

- [ ] **Step 3: Aggiungere CSS dropdown**

In `assets/css/elenco_documenti.css`:
```css
/* Segment dropdown */
.ed-seg-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 50%;
    transform: translateX(-50%);
    background: var(--sf, #fff);
    border: 1px solid var(--br, #e2e4e8);
    border-radius: 6px;
    box-shadow: 0 8px 28px rgba(0,0,0,.14);
    z-index: 300;
    min-width: 150px;
    overflow: hidden;
}
.ed-seg-dd-header {
    padding: 6px 10px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--mu, #6b7280);
    border-bottom: 1px solid var(--br, #e2e4e8);
    background: #fafbfc;
}
.ed-seg-dd-list {
    max-height: 200px;
    overflow-y: auto;
}
.ed-seg-dd-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    cursor: pointer;
    transition: background 0.1s;
}
.ed-seg-dd-item:hover { background: rgba(205,33,29,.06); }
.ed-seg-dd-item.active { background: #fef2f2; }
.ed-seg-dd-code {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    font-size: 11px;
    color: var(--red, #cd211d);
    min-width: 36px;
}
.ed-seg-dd-desc {
    color: var(--mu, #6b7280);
    font-size: 11px;
}

/* Num inline input */
.ed-num-input {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    font-weight: 700;
    background: transparent;
    border: 1px solid var(--red, #cd211d);
    border-radius: 3px;
    outline: none;
    width: 52px;
    padding: 2px 4px;
    text-align: center;
}
```

- [ ] **Step 4: Esporre nuove funzioni nella public API**

In fondo al IIFE (oggetto `return` a riga ~1690), aggiungere:
```javascript
openSegDrop,
openNumEdit,
```

- [ ] **Step 5: Verificare nel browser**

Click su una cella Fase → deve apparire dropdown con valori dalla lookup. Selezionare un valore → la cella si aggiorna, il codice completo si ricalcola, viene salvato automaticamente. Click su Numero → input inline, Enter → salva.

- [ ] **Step 6: Commit**
```
feat(elenco-documenti): inline editing dropdown per segmenti codice e numero
```

---

## Chunk 4: Template Panel + Export

### Task 8: Template Configuration Panel — HTML + CSS

**Files:**
- Modify: `views/elenco_documenti.php` (aggiungere dopo Nextcloud browser modal, prima dell'hidden input)
- Modify: `assets/css/elenco_documenti.css`

- [ ] **Step 1: Aggiungere markup template panel**

In `views/elenco_documenti.php`, prima della riga `<input type="hidden" id="idProject"...>` (riga ~451), aggiungere:

```php
<!-- Template Configuration Panel -->
<div class="ed-tpl-panel" id="tplPanel">
    <div class="ed-tpl-header">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18">
            <circle cx="12" cy="12" r="3"/>
            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18..."/><!-- path completo dell'ingranaggio -->
        </svg>
        <h2>Configura Template</h2>
        <button class="ed-close-btn" onclick="ElencoDoc.closeTemplatePanel()">×</button>
    </div>
    <div class="ed-tpl-tabs">
        <button class="ed-tpl-tab active" data-tab="fasi" onclick="ElencoDoc.switchTplTab(this,'fasi')">Fasi</button>
        <button class="ed-tpl-tab" data-tab="zone" onclick="ElencoDoc.switchTplTab(this,'zone')">Zone</button>
        <button class="ed-tpl-tab" data-tab="discipline" onclick="ElencoDoc.switchTplTab(this,'discipline')">Discipline</button>
        <button class="ed-tpl-tab" data-tab="tipi" onclick="ElencoDoc.switchTplTab(this,'tipi')">Tipi</button>
    </div>
    <div class="ed-tpl-body" id="tplBody">
        <!-- Populated by JS -->
    </div>
    <div class="ed-tpl-footer">
        <button class="btn btn-secondary" onclick="ElencoDoc.closeTemplatePanel()">Annulla</button>
        <button class="btn btn-primary" onclick="ElencoDoc.saveTemplate()">Salva Template</button>
    </div>
</div>
```

- [ ] **Step 2: Aggiungere CSS template panel**

```css
/* Template Panel */
.ed-tpl-panel {
    position: fixed;
    right: 0; top: 0; bottom: 0;
    width: 500px;
    background: var(--sf, #fff);
    box-shadow: -4px 0 24px rgba(0,0,0,.13);
    z-index: 850;
    transform: translateX(100%);
    transition: transform 0.25s cubic-bezier(.4,0,.2,1);
    display: flex;
    flex-direction: column;
    border-left: 1px solid var(--br, #e2e4e8);
}
.ed-tpl-panel.on { transform: translateX(0); }
.ed-tpl-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--br, #e2e4e8);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.ed-tpl-header h2 { font-size: 15px; font-weight: 700; flex: 1; }
.ed-tpl-tabs {
    display: flex;
    border-bottom: 1px solid var(--br, #e2e4e8);
    flex-shrink: 0;
}
.ed-tpl-tab {
    flex: 1;
    padding: 9px 12px;
    border: none;
    background: none;
    font-size: 11px;
    font-weight: 700;
    color: var(--mu, #6b7280);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    text-align: center;
}
.ed-tpl-tab:hover { color: var(--tx, #1a1d23); }
.ed-tpl-tab.active { color: var(--red, #cd211d); border-bottom-color: var(--red, #cd211d); }
.ed-tpl-body { flex: 1; overflow-y: auto; padding: 16px 18px; }
.ed-tpl-footer {
    padding: 12px 18px;
    border-top: 1px solid var(--br, #e2e4e8);
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    flex-shrink: 0;
}

/* Template lookup table */
.ed-tpl-table { width: 100%; border-collapse: collapse; }
.ed-tpl-table th {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: var(--mu, #6b7280);
    padding: 4px 8px;
    background: #fafbfc;
    border: 1px solid var(--br, #e2e4e8);
    font-weight: 700;
}
.ed-tpl-table td { padding: 0; border: 1px solid var(--br, #e2e4e8); }
.ed-tpl-table td input {
    border: none;
    outline: none;
    padding: 5px 8px;
    font-size: 12px;
    width: 100%;
    background: transparent;
}
.ed-tpl-table td.code-col input {
    font-family: 'Courier New', monospace;
    font-weight: 700;
    color: var(--red, #cd211d);
}
.ed-tpl-table tr:hover td { background: #fafbff; }
.ed-tpl-del-btn {
    width: 22px; height: 22px;
    border: none; background: none;
    cursor: pointer; color: var(--mu, #6b7280);
    border-radius: 3px;
}
.ed-tpl-del-btn:hover { background: rgba(205,33,29,.1); color: var(--red, #cd211d); }
.ed-tpl-add-btn {
    width: 100%;
    margin-top: 5px;
    text-align: center;
    font-size: 11px;
    font-weight: 600;
    color: var(--red, #cd211d);
    background: none;
    border: 1px dashed rgba(205,33,29,.4);
    border-radius: 6px;
    padding: 5px;
    cursor: pointer;
}
.ed-tpl-add-btn:hover { background: rgba(205,33,29,.06); }
```

- [ ] **Step 3: Commit**
```
feat(elenco-documenti): template configuration panel markup e CSS
```

---

### Task 9: Template Configuration Panel — JavaScript

**Files:**
- Modify: `assets/js/elenco_documenti.js`

- [ ] **Step 1: Aggiungere state per template editing**

Dopo la variabile `_ncBrowserFiles` (~riga 29), aggiungere:
```javascript
let _tplData = null; // Template data for editing
let _tplActiveTab = 'fasi';
```

- [ ] **Step 2: Aggiungere funzioni template panel**

Aggiungere prima della sezione PUBLIC API:
```javascript
// ================================
// TEMPLATE PANEL
// ================================
async function openTemplatePanel() {
    const result = await sendRequest('getTemplate', { idProject });
    if (!result.success) { alert(result.message || 'Errore'); return; }
    _tplData = result.data || { fasi: [], zone: [], discipline: [], tipi_documento: [] };
    _tplActiveTab = 'fasi';
    renderTplTab();
    document.getElementById('tplPanel')?.classList.add('on');
}

function closeTemplatePanel() {
    document.getElementById('tplPanel')?.classList.remove('on');
}

function switchTplTab(btn, tab) {
    document.querySelectorAll('.ed-tpl-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _tplActiveTab = tab;
    renderTplTab();
}

function renderTplTab() {
    const body = document.getElementById('tplBody');
    if (!body || !_tplData) return;

    const tab = _tplActiveTab;
    const isObjType = (tab === 'tipi_documento');
    const items = _tplData[tab] || [];

    let html = `<table class="ed-tpl-table"><thead><tr>
        <th style="width:60px">Codice</th>
        <th>${isObjType ? 'Descrizione' : 'Descrizione (opz.)'}</th>
        <th style="width:30px"></th>
    </tr></thead><tbody>`;

    items.forEach((item, idx) => {
        const code = isObjType ? item.cod : item;
        const desc = isObjType ? (item.desc || '') : '';
        html += `<tr>
            <td class="code-col"><input type="text" value="${escapeHtml(code)}" data-idx="${idx}" data-field="code" maxlength="4" onchange="ElencoDoc.tplFieldChange(this)"></td>
            <td><input type="text" value="${escapeHtml(desc)}" data-idx="${idx}" data-field="desc" onchange="ElencoDoc.tplFieldChange(this)" ${!isObjType ? 'placeholder="opzionale"' : ''}></td>
            <td><button class="ed-tpl-del-btn" onclick="ElencoDoc.tplRemoveRow(${idx})" title="Elimina">×</button></td>
        </tr>`;
    });

    html += `</tbody></table>
        <button class="ed-tpl-add-btn" onclick="ElencoDoc.tplAddRow()">+ Aggiungi ${tab.slice(0, -1) || tab}</button>`;

    body.innerHTML = html;
}

function tplFieldChange(input) {
    const idx = parseInt(input.dataset.idx);
    const field = input.dataset.field;
    const tab = _tplActiveTab;
    const isObj = (tab === 'tipi_documento');

    if (isObj) {
        if (field === 'code') _tplData[tab][idx].cod = input.value.trim();
        else _tplData[tab][idx].desc = input.value.trim();
    } else {
        if (field === 'code') _tplData[tab][idx] = input.value.trim();
    }
}

function tplAddRow() {
    const tab = _tplActiveTab;
    if (tab === 'tipi_documento') {
        _tplData[tab].push({ cod: '', desc: '' });
    } else {
        _tplData[tab].push('');
    }
    renderTplTab();
    // Focus last code input
    const inputs = document.querySelectorAll('.ed-tpl-table input[data-field="code"]');
    if (inputs.length) inputs[inputs.length - 1].focus();
}

function tplRemoveRow(idx) {
    const tab = _tplActiveTab;
    const item = _tplData[tab][idx];
    const code = (typeof item === 'string') ? item : item.cod;

    // Guard: check if used by existing docs
    const docs = allDocs();
    const segKey = tab === 'tipi_documento' ? 'tipo'
        : tab === 'discipline' ? 'disc'
        : tab === 'fasi' ? 'fase'
        : 'zona';
    const used = docs.some(d => d[segKey] === code);
    if (used) {
        alert(`Impossibile eliminare "${code}": è utilizzato da documenti esistenti.`);
        return;
    }

    _tplData[tab].splice(idx, 1);
    renderTplTab();
}

async function saveTemplateData() {
    const result = await sendRequest('saveTemplate', {
        idProject,
        template: _tplData
    });
    if (result.success) {
        // Aggiorna lookups locali
        const prevResp = lookups.resp;
        lookups = normalizeLookups(_tplData);
        lookups.resp = prevResp;
        flashSave();
        closeTemplatePanel();
    } else {
        alert(result.message || 'Errore salvataggio template');
    }
}
```

- [ ] **Step 3: Esporre funzioni nella public API**

Aggiungere al `return`:
```javascript
openTemplatePanel: openTemplatePanel,
closeTemplatePanel,
switchTplTab,
tplFieldChange,
tplAddRow,
tplRemoveRow,
saveTemplate: saveTemplateData,
```

- [ ] **Step 4: Verificare nel browser**

Click "Template" nella toolbar → si apre il pannello. Le 4 tab mostrano i valori. Aggiungere/eliminare righe. Salvare → i dropdown inline si aggiornano.

- [ ] **Step 5: Commit**
```
feat(elenco-documenti): template configuration panel con CRUD lookup
```

---

### Task 10: Export Excel — Backend

**Files:**
- Modify: `services/ElencoDocumentiService.php`

- [ ] **Step 1: Leggere handleAction() nel service**

Leggere `services/ElencoDocumentiService.php` righe 25-95 per capire come aggiungere la nuova action.

- [ ] **Step 2: Aggiungere case 'exportExcel' in handleAction()**

Nel switch di handleAction():
```php
case 'exportExcel': return self::exportExcel($input);
```

- [ ] **Step 3: Implementare exportExcel()**

```php
private static function exportExcel($input) {
    global $database;

    if (!userHasPermission('view_commesse')) {
        return ['success' => false, 'message' => 'Permesso negato'];
    }

    $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if (empty($idProject)) {
        return ['success' => false, 'message' => 'idProject obbligatorio'];
    }

    // Fetch sections + documents
    $sections = $database->query(
        "SELECT id, nome FROM elenco_doc_sections WHERE id_project = ? ORDER BY ordine, id",
        [$idProject], __FILE__
    );

    $docs = $database->query(
        "SELECT d.*, s.nome as section_name
         FROM elenco_doc_documents d
         LEFT JOIN elenco_doc_sections s ON s.id = d.id_section
         WHERE d.id_project = ?
         ORDER BY s.ordine, s.id, d.seg_numero",
        [$idProject], __FILE__
    );

    // Build Excel data
    $header = ['Sezione','Fase','Zona','Disc','Tipo','Numero','Rev',
               'Codice','Titolo','Tipo Documento','Resp.','Output',
               'Stato','Avanzamento %','Data Inizio','Data Fine Prev.',
               'Data Emissione','Submittal'];

    $rows = [$header];
    foreach ($docs as $d) {
        $code = $idProject.'-'.$d['seg_fase'].'-'.$d['seg_zona'].'-'.
                $d['seg_disc'].'-'.$d['seg_tipo'].'-'.
                str_pad($d['seg_numero'],4,'0',STR_PAD_LEFT).'-'.$d['revisione'];
        $rows[] = [
            $d['section_name'] ?? '',
            $d['seg_fase'], $d['seg_zona'], $d['seg_disc'], $d['seg_tipo'],
            $d['seg_numero'], $d['revisione'],
            $code, $d['titolo'], $d['tipo_documento'] ?? '',
            $d['responsabile'] ?? '', $d['output_software'] ?? '',
            $d['stato'], $d['avanzamento_pct'] ?? 0,
            $d['data_inizio'] ?? '', $d['data_fine_prev'] ?? '',
            $d['data_emissione'] ?? '', ''
        ];
    }

    // Generate XLSX
    require_once __DIR__ . '/../IntLibs/SimpleXLSXGen/SimpleXLSXGen.php';
    $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($rows);

    // Stream download
    $filename = 'Elenco_Documenti_' . $idProject . '_' . date('Ymd') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $xlsx->downloadAs($filename);
    exit;
}
```

**Nota:** Questo metodo fa exit diretto (non ritorna JSON). Il JS deve fare una richiesta via window.open o form submit, non via fetch.

- [ ] **Step 4: Commit**
```
feat(elenco-documenti): backend exportExcel con SimpleXLSXGen
```

---

### Task 11: Export Excel — Frontend

**Files:**
- Modify: `assets/js/elenco_documenti.js`

- [ ] **Step 1: Aggiungere funzione exportExcel()**

```javascript
function exportExcel() {
    const csrf = document.querySelector('meta[name="token-csrf"]')?.content || '';
    // Usa service_router per export diretto (non ajax.php perche fa exit con file)
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/service_router.php';
    form.style.display = 'none';

    const fields = {
        section: 'elenco_documenti',
        action: 'exportExcel',
        idProject: idProject,
        csrf_token: csrf
    };

    Object.entries(fields).forEach(([k, v]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = k;
        input.value = v;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    form.remove();
}
```

**Nota:** Verificare che `service_router.php` gestisca il CSRF da POST body per questa action. Se usa solo header, potrebbe servire un approccio diverso (es. window.open con GET params o una route dedicata).

- [ ] **Step 2: Esporre nella public API**

Aggiungere `exportExcel` al return.

- [ ] **Step 3: Verificare nel browser**

Click "Esporta" → scarica file `.xlsx` con tutti i documenti.

- [ ] **Step 4: Commit**
```
feat(elenco-documenti): frontend export Excel via form submit
```

---

## Chunk 5: Nextcloud Auto-Cartelle

### Task 12: Auto-creazione cartelle Nextcloud in saveDocumento()

**Files:**
- Modify: `services/ElencoDocumentiService.php` (metodo `saveDocumento`)

- [ ] **Step 1: Leggere saveDocumento() attuale**

Leggere il metodo `saveDocumento()` nel service per capire il flusso attuale.

- [ ] **Step 2: Aggiungere helper buildNcFolderName()**

Aggiungere metodo privato:
```php
private static function buildNcFolderName($idProject, $doc) {
    $code = $idProject . '-' . $doc['seg_fase'] . '-' . $doc['seg_zona'] . '-' .
            $doc['seg_disc'] . '-' . $doc['seg_tipo'] . '-' .
            str_pad($doc['seg_numero'], 4, '0', STR_PAD_LEFT) . '-' . $doc['revisione'];
    $title = $doc['titolo'] ?? '';
    // Sanitize per filesystem: rimuovi caratteri non validi
    $safeName = preg_replace('/[<>:"\/\\|?*]/', '_', $code . ' - ' . $title);
    return trim($safeName);
}
```

- [ ] **Step 3: Aggiungere logica NC in saveDocumento()**

Dopo il salvataggio DB in `saveDocumento()`, aggiungere:
```php
// Auto-creazione/rename cartella Nextcloud
$ncWarning = null;
try {
    $newFolder = self::buildNcFolderName($idProject, $input);
    $ncBasePath = self::NC_ROOT . $idProject . '/';

    // Se update, controlla se il codice e cambiato
    if (!empty($input['docId'])) {
        $oldDoc = $database->query(
            "SELECT * FROM elenco_doc_documents WHERE id = ?",
            [$input['docId']], __FILE__
        );
        if (!empty($oldDoc)) {
            $oldFolder = self::buildNcFolderName($idProject, $oldDoc[0]);
            if ($oldFolder !== $newFolder) {
                // Rename via WebDAV MOVE
                $ncService = new \Services\NextcloudService();
                $moveResult = $ncService->move(
                    $ncBasePath . $oldFolder,
                    $ncBasePath . $newFolder
                );
                if (!$moveResult) {
                    $ncWarning = 'Impossibile rinominare la cartella Nextcloud';
                }
            }
        }
    } else {
        // Nuovo documento: crea cartella
        $ncService = new \Services\NextcloudService();
        $mkdirResult = $ncService->createFolder($ncBasePath . $newFolder);
        if (!$mkdirResult) {
            $ncWarning = 'Impossibile creare la cartella Nextcloud';
        }
    }
} catch (\Exception $e) {
    $ncWarning = 'Errore Nextcloud: ' . $e->getMessage();
}

$response = ['success' => true, 'data' => ['id' => $docId]];
if ($ncWarning) $response['nc_warning'] = $ncWarning;
return $response;
```

**Nota:** Verificare l'API esatta di NextcloudService (metodi `move()`, `createFolder()`) leggendo il file `services/NextcloudService.php`. Adattare i nomi dei metodi se diversi.

- [ ] **Step 4: Gestire nc_warning nel frontend**

In `saveOneDoc()` nel JS, dopo il salvataggio:
```javascript
if (result.nc_warning) {
    console.warn('Nextcloud:', result.nc_warning);
    // Opzionale: mostrare un toast di warning
}
```

- [ ] **Step 5: Verificare**

Creare un nuovo documento → verificare in Nextcloud che la cartella venga creata sotto `/INTRANET/ELABORATI/{idProject}/`. Modificare il codice di un documento → verificare che la cartella venga rinominata.

- [ ] **Step 6: Commit**
```
feat(elenco-documenti): auto-creazione cartelle Nextcloud al salvataggio documento
```

---

## Chunk 6: Pulizia e verifica finale

### Task 13: Pulizia filtri e init

**Files:**
- Modify: `assets/js/elenco_documenti.js`

- [ ] **Step 1: Rimuovere populateFilterDropdowns()**

La funzione `populateFilterDropdowns()` (~righe 311-335) riempiva i select `filter-stato/filter-disc/filter-resp` che non esistono piu. Rimuoverla, e rimuovere le chiamate a essa in `loadRisorse()` (riga 253) e `loadDocumenti()` (riga 272).

- [ ] **Step 2: Aggiungere loadTemplate in init()**

In `init()`, dopo `loadRisorse()` aggiungere:
```javascript
await loadTemplate();
```

E aggiungere la funzione:
```javascript
async function loadTemplate() {
    const result = await sendRequest('getTemplate', { idProject });
    if (result.success && result.data) {
        const prevResp = lookups.resp;
        lookups = normalizeLookups(result.data);
        lookups.resp = prevResp;
    }
}
```

**Nota:** Attualmente il template viene caricato dentro `loadDocumenti()` (le lookups arrivano nella risposta di getDocumenti). Verificare se e cosi — se le lookups arrivano gia da getDocumenti, non serve una chiamata separata. In quel caso, non aggiungere `loadTemplate()` ma assicurarsi che le lookups siano correttamente populate per alimentare i dropdown inline.

- [ ] **Step 3: Verificare che la colonna Submittal nella tabella mostri i dati**

Verificare che `submittals` sia caricato prima di `renderSections()`. Se serve, spostare `await loadSubmittals()` prima di `renderSections()` in `loadDocumenti()`.

- [ ] **Step 4: Test completo nel browser**

1. Aprire la pagina elaborati
2. Verificare stat cards con dati corretti
3. Verificare tabella 19 colonne con scroll orizzontale
4. Click su cella Fase → dropdown funzionante
5. Click su Numero → input inline
6. Click su Status → dropdown esistente (gia funzionava)
7. Click su Titolo → si apre il props panel (gia funzionava)
8. Click Template → pannello con 4 tab
9. Click Esporta → scarica Excel
10. Submittal manager → funziona (non toccato)
11. Lettera trasmissione → funziona (non toccata)

- [ ] **Step 5: Commit finale**
```
feat(elenco-documenti): pulizia init, caricamento template, verifica completa
```

---

## Riepilogo Task

| # | Task | File principale | Stima |
|---|------|-----------------|-------|
| 1 | Fix main_core.js per th-groups | main_core.js | 15 min |
| 2 | Installare SimpleXLSXGen | IntLibs/ | 5 min |
| 3 | Stat cards (PHP + CSS + JS) | elenco_documenti.php + .css + .js | 20 min |
| 4 | Toolbar (Template + Export buttons) | elenco_documenti.php + .js | 15 min |
| 5 | renderSections() 19 colonne | elenco_documenti.js | 25 min |
| 6 | buildRowHtml() 19 colonne + CSS | elenco_documenti.js + .css | 30 min |
| 7 | Inline editing dropdowns | elenco_documenti.js + .css | 25 min |
| 8 | Template panel HTML + CSS | elenco_documenti.php + .css | 20 min |
| 9 | Template panel JS | elenco_documenti.js | 25 min |
| 10 | Export Excel backend | ElencoDocumentiService.php | 20 min |
| 11 | Export Excel frontend | elenco_documenti.js | 10 min |
| 12 | Nextcloud auto-cartelle | ElencoDocumentiService.php | 25 min |
| 13 | Pulizia + verifica | elenco_documenti.js | 15 min |
