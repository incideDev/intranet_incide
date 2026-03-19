# Gare Dettaglio — Use API Data Directly

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop re-processing API data through 7 layers of transformation. Read `value_json` directly in the frontend renderers and use the structured fields the API already provides.

**Architecture:** Pure frontend refactor of `gare_detail.js` render functions. Each section renderer gets a `getJson(item)` helper that parses `value_json` once and reads API fields directly (e.g. `json.date.day`, `json.bool_answer`, `json.entries[]`). Backend `extractCleanAnswer()` and `display_value` become fallbacks, not primary sources. No backend changes needed — the data is already saved correctly in `value_json`.

**Tech Stack:** Vanilla JS (ES6), CSS

**Reference data:** `.agent/samples/gara_267_results.json` — real API response for gara 267

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `assets/js/gare_detail.js` | Modify | All 6 section renderers + helpers |
| `assets/css/gare.css` | Modify | Minor additions for new UI elements (booking info, criteria tree) |
| `views/gare_dettaglio.php` | No change | — |
| Backend (`ExtractionService.php`) | No change | — |

---

## Principle

For each extraction type, the renderer should:
1. `const json = parseJsonObject(item.value_json)` — get the raw API data
2. Read structured fields directly: `json.date.day`, `json.entries[0].amount_eur`, `json.bool_answer`
3. Use `getDisplayValue(item)` ONLY as last-resort fallback when JSON has no structured field

---

## Task 1: Add `getJson` helper + date formatter from API date object

**Files:**
- Modify: `assets/js/gare_detail.js` (helper section, ~line 630)

- [ ] **Step 1: Add `getJson(item)` helper after `getDisplayValue`**

```javascript
/**
 * Parse value_json once. Returns the parsed object or null.
 */
function getJson(item) {
  if (!item) return null;
  return parseJsonObject(item.value_json);
}

/**
 * Format an API date object {year, month, day, hour?, minute?} to Italian string.
 * Returns e.g. "07/11/2025 ore 12:00" or "07/11/2025"
 */
function formatApiDate(dateObj) {
  if (!dateObj || !dateObj.year) return '';
  const dd = String(dateObj.day || 1).padStart(2, '0');
  const mm = String(dateObj.month || 1).padStart(2, '0');
  const yyyy = dateObj.year;
  let result = `${dd}/${mm}/${yyyy}`;
  if (dateObj.hour !== null && dateObj.hour !== undefined) {
    const hh = String(dateObj.hour).padStart(2, '0');
    const min = String(dateObj.minute || 0).padStart(2, '0');
    result += ` ore ${hh}:${min}`;
  }
  return result;
}

/**
 * Format an API date object to long Italian: "7 novembre 2025 ore 12:00"
 */
function formatApiDateLong(dateObj) {
  if (!dateObj || !dateObj.year) return '';
  const months = [
    'gennaio','febbraio','marzo','aprile','maggio','giugno',
    'luglio','agosto','settembre','ottobre','novembre','dicembre'
  ];
  let result = `${dateObj.day} ${months[(dateObj.month || 1) - 1]} ${dateObj.year}`;
  if (dateObj.hour !== null && dateObj.hour !== undefined) {
    const hh = String(dateObj.hour).padStart(2, '0');
    const min = String(dateObj.minute || 0).padStart(2, '0');
    result += ` ore ${hh}:${min}`;
  }
  return result;
}

/**
 * Read a simple string answer from API JSON.
 * Checks: json.answer, json.url, then falls back to getDisplayValue.
 */
function getSimpleAnswer(item) {
  const json = getJson(item);
  if (json) {
    if (typeof json.answer === 'string' && json.answer.trim()) return json.answer.trim();
    if (typeof json.url === 'string' && json.url.trim()) return json.url.trim();
  }
  return getDisplayValue(item);
}
```

- [ ] **Step 2: Verify syntax**
Run: `node -c assets/js/gare_detail.js`

- [ ] **Step 3: Commit**
```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): add getJson, formatApiDate, getSimpleAnswer helpers"
```

---

## Task 2: renderHeader — use API date/location/settore directly

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderHeader()` function (~line 839)

Current problems:
- Dates: reads `getDisplayValue()` string, re-parses with `formatDateItalian()` — loses hour/minute
- Luogo: gets flattened string "Catania (CT)" — loses entity_name, entity_type
- Uses `getDisplayValue()` for everything instead of reading JSON fields

- [ ] **Step 1: Rewrite field extraction in renderHeader**

Replace the meta field extraction block (ente, scadenza, tipologia, luogo) with direct API JSON reads:

```javascript
// Meta fields — read API JSON directly
const ente = getSimpleAnswer(byType('stazione_appaltante'));

const scadenzaItem = byType('data_scadenza_gara_appalto');
const scadenzaJson = getJson(scadenzaItem);
const scadenza = scadenzaJson?.date ? formatApiDate(scadenzaJson.date) : getDisplayValue(scadenzaItem);

const tipologia = getSimpleAnswer(byType('tipologia_di_gara'));

const luogoItem = byType('luogo_provincia_appalto');
const luogoJson = getJson(luogoItem);
let luogo = '';
if (luogoJson?.location) {
  const loc = luogoJson.location;
  const parts = [loc.entity_name, loc.city].filter(Boolean);
  luogo = parts.join(', ');
  if (loc.district) luogo += ` (${loc.district})`;
} else {
  luogo = getDisplayValue(luogoItem);
}
```

- [ ] **Step 2: Verify syntax**
Run: `node -c assets/js/gare_detail.js`

- [ ] **Step 3: Commit**
```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): renderHeader reads API date/location/answer directly"
```

---

## Task 3: renderOverview — use API date objects + sopralluogo fields directly

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderOverview()` function

Current problems:
- Timeline dates use `getDisplayValue()` + `formatDateItalianLong()` — double parsing
- Sopralluogo reads synthetic split items instead of original `bool_answer` + `deadlines[]`
- Misses `booking_platform` and `booking_instructions`

- [ ] **Step 1: Rewrite timeline date extraction**

Replace timeline date extraction with direct JSON reads:

```javascript
// Timeline dates — read API date objects directly
const dataUscitaItem = byType('data_uscita_gara_appalto');
const dataUscitaJson = getJson(dataUscitaItem);
const dataUscitaVal = dataUscitaJson?.date ? formatApiDate(dataUscitaJson.date) : getDisplayValue(dataUscitaItem);
const uscitaLong = dataUscitaJson?.date ? formatApiDateLong(dataUscitaJson.date) : formatDateItalianLong(dataUscitaVal);

const dataScadenzaItem = byType('data_scadenza_gara_appalto');
const dataScadenzaJson = getJson(dataScadenzaItem);
const dataScadenzaVal = dataScadenzaJson?.date ? formatApiDate(dataScadenzaJson.date) : getDisplayValue(dataScadenzaItem);
const scadenzaLong = dataScadenzaJson?.date ? formatApiDateLong(dataScadenzaJson.date) : formatDateItalianLong(dataScadenzaVal);
```

- [ ] **Step 2: Rewrite sopralluogo extraction — use API fields directly**

Replace the sopralluogo parsing block with:

```javascript
// Sopralluogo — read API JSON directly (bool_answer, deadlines[], booking_platform)
const sopItem = byType('sopralluogo_obbligatorio') || byType('sopralluogo_obbligatorio_split');
const sopJson = getJson(sopItem?.synthetic_source || sopItem);
let sopRequired = false;
let sopDeadlineVal = '';
let sopBookingUrl = '';
let sopBookingInstructions = '';

if (sopJson) {
  sopRequired = sopJson.bool_answer === true;
  // Read structured deadline
  if (Array.isArray(sopJson.deadlines) && sopJson.deadlines.length > 0) {
    const dl = sopJson.deadlines[0];
    const dtObj = dl.calculated_effective_datetime || dl.absolute_datetime;
    sopDeadlineVal = dtObj ? formatApiDate(dtObj) : (dl.source_text || '');
  }
  // Booking info
  if (sopJson.booking_platform) {
    sopBookingUrl = sopJson.booking_platform.url || '';
  }
  sopBookingInstructions = sopJson.booking_instructions || '';
} else {
  // Fallback to synthetic items
  const sopSplitItem = byType('sopralluogo_obbligatorio_split');
  if (sopSplitItem) {
    const dv = getDisplayValue(sopSplitItem);
    const norm = (dv || '').toLowerCase().trim();
    sopRequired = (norm === 'si' || norm === 'sì' || norm === 'yes' || norm === 'true' || norm === '1');
  }
  const sopDeadlineItem = byType('sopralluogo_deadline');
  if (sopDeadlineItem) sopDeadlineVal = getDisplayValue(sopDeadlineItem);
}
```

- [ ] **Step 3: Add booking info to sopralluogo card HTML**

After the deadline note in the sop-card, add:

```javascript
${sopBookingUrl ? `<a class="sop-link" href="${escapeAttribute(sopBookingUrl)}" target="_blank" rel="noopener">Piattaforma prenotazione &#8599;</a>` : ''}
```

- [ ] **Step 4: Use daysUntil with API date object for countdown**

Replace `daysUntil(dataScadenzaVal)` with a version that can use the structured date:

```javascript
// Countdown — use structured date if available, else parse string
let scadenzaDays = null;
if (dataScadenzaJson?.date) {
  const d = new Date(dataScadenzaJson.date.year, dataScadenzaJson.date.month - 1, dataScadenzaJson.date.day);
  const now = new Date(); now.setHours(0,0,0,0); d.setHours(0,0,0,0);
  scadenzaDays = Math.ceil((d - now) / (1000 * 60 * 60 * 24));
} else {
  scadenzaDays = daysUntil(dataScadenzaVal);
}
```

- [ ] **Step 5: Rewrite settore to read object fields**

Replace settore extraction with:

```javascript
const settoreItem = byType('settore_industriale_gara_appalto') || byType('settore_gara');
const settoreJson = getJson(settoreItem);
let settoreVal = '';
if (settoreJson) {
  const code = settoreJson.prevalent_id_opere?.code || (typeof settoreJson.prevalent_id_opere === 'string' ? settoreJson.prevalent_id_opere : '');
  const cat = settoreJson.prevalent_categoria?.categoria || (typeof settoreJson.prevalent_categoria === 'string' ? settoreJson.prevalent_categoria : '');
  settoreVal = [code, cat].filter(Boolean).join(' ');
} else {
  settoreVal = getDisplayValue(settoreItem);
}
```

- [ ] **Step 6: Verify syntax + commit**
```bash
node -c assets/js/gare_detail.js
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): renderOverview uses API dates, sopralluogo, settore directly"
```

---

## Task 4: renderImporti — use API entries directly + add "Importo a base d'asta" from oggetto_appalto

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderImporti()` function

Current problems:
- Card "Importo base lavori" empty when entries=[] (correct) but oggetto_appalto.servizi_previsti has the contract value
- Fatturato parsing goes: number → Italian string → re-parse. Should just use the number.
- First ID opera: field name mismatch (category_id vs id_opera)

- [ ] **Step 1: Rename first card from "Importo base lavori" to "Importo a base d'asta"**

This card should show the contract base value. Source priority:
1. `importi_opere` entries sum (if available)
2. `oggetto_appalto.servizi_previsti[].amount_eur` sum (fallback — the contract value)

```javascript
// Importo a base d'asta: try opere sum, fallback to oggetto_appalto.servizi_previsti
let importoBaseAsta = opereSum;
if (importoBaseAsta === 0) {
  const oggettoItem = byType('oggetto_appalto');
  const oggettoJson = getJson(oggettoItem);
  if (oggettoJson?.servizi_previsti && Array.isArray(oggettoJson.servizi_previsti)) {
    importoBaseAsta = oggettoJson.servizi_previsti.reduce((sum, s) => {
      const v = (typeof s.amount_eur === 'number') ? s.amount_eur : parseItalianNumber(s.amount_eur || s.amount_raw);
      return sum + (isNaN(v) ? 0 : v);
    }, 0);
  }
}
```

Update the card HTML: replace `opereSum` with `importoBaseAsta`, change label to "Importo a base d'asta", change sub-label accordingly.

- [ ] **Step 2: Fatturato — read number directly from API JSON**

```javascript
let fatturatoVal = 0;
if (fatturatoItem) {
  const fJson = getJson(fatturatoItem);
  if (fJson?.turnover_requirement?.single_requirement) {
    const sr = fJson.turnover_requirement.single_requirement;
    fatturatoVal = (typeof sr.minimum_amount_value === 'number') ? sr.minimum_amount_value : 0;
  }
  // Fallback: backend-normalized fields
  if (fatturatoVal === 0 && fJson) {
    const raw = fJson.importo_minimo ?? fJson.importo_minimo_eur ?? null;
    if (raw !== null) {
      const v = (typeof raw === 'number') ? raw : parseItalianNumber(raw);
      if (!isNaN(v)) fatturatoVal = v;
    }
  }
  // Last fallback: display_value string
  if (fatturatoVal === 0) {
    const fv = getDisplayValue(fatturatoItem);
    const match = String(fv).match(/[\d.,]+/);
    if (match) {
      const v = parseItalianNumber(match[0]);
      if (!isNaN(v) && v > 0) fatturatoVal = v;
    }
  }
}
```

- [ ] **Step 3: First ID opera — use category_id directly**

```javascript
let firstIdOpera = '';
const idOperaSources = [opereItem, corrispettiviItem, requisitiTecItem];
for (const src of idOperaSources) {
  if (firstIdOpera) break;
  if (!src) continue;
  const json = getJson(src);
  if (json?.entries?.length > 0) {
    firstIdOpera = json.entries[0].category_id || json.entries[0].id_opera || '';
  }
  if (!firstIdOpera && json?.requirements?.length > 0) {
    firstIdOpera = json.requirements[0].id_opera || '';
  }
}
```

- [ ] **Step 4: Verify syntax + commit**
```bash
node -c assets/js/gare_detail.js
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): renderImporti reads API fields directly, adds importo base asta"
```

---

## Task 5: renderRequisiti — use API requirements[] with rich fields

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderRequisiti()` function

Current problems:
- Reads `entries[].requisito, .descrizione, .obbligatorio` — field names that don't exist in API
- API uses `requirements[].title, .description, .is_mandatory, .requirement_type, .experience_details`
- Loses `experience_details.categories[]` with per-category minimum amounts
- Loses `legal_reference`

- [ ] **Step 1: Rewrite entry parsing to use API field names**

```javascript
requisitiItems.forEach(item => {
  const json = getJson(item?.synthetic_source || item);

  // API structure: requirements[] with title, description, is_mandatory, requirement_type, etc.
  if (json?.requirements && Array.isArray(json.requirements) && json.requirements.length > 0) {
    json.requirements.forEach(req => {
      const name = req.title || req.requisito || req.titolo || 'Requisito';
      const desc = req.description || req.descrizione || '';
      const isObb = req.is_mandatory === true || String(req.obbligatorio || '').toLowerCase() === 'si';
      const reqType = req.requirement_type || '';
      const legalRef = req.legal_reference || '';

      // Experience details with per-category amounts
      let experienceHtml = '';
      if (req.experience_details?.categories?.length > 0) {
        experienceHtml = `
          <div class="req-exp">
            ${req.experience_details.categories.map(c =>
              `<span class="req-exp-chip">${escapeHtml(c.category_code || '')} ${c.minimum_amount_eur ? formatEuro(c.minimum_amount_eur) : ''}</span>`
            ).join('')}
          </div>`;
      }

      cardsHtml += `
        <div class="req-card">
          <div class="req-card-head">
            <div class="req-name">${escapeHtml(name)}</div>
            <span class="req-obbl ${isObb ? 'si' : 'no'}">${isObb ? '&#10005; Obbligatorio' : 'Facoltativo'}</span>
          </div>
          ${desc ? `<div class="req-desc">${escapeHtml(truncate(desc, 500))}</div>` : ''}
          ${legalRef ? `<div class="req-legal">${escapeHtml(legalRef)}</div>` : ''}
          ${experienceHtml}
        </div>
      `;
    });
  } else {
    // Fallback: backend entries[] or table
    const source = item.synthetic_source || item;
    const parsed = getJson(source);
    if (parsed?.entries?.length > 0) {
      parsed.entries.forEach(entry => {
        const name = entry.requisito || entry.titolo || entry.nome || 'Requisito';
        const desc = entry.descrizione || entry.description || '';
        const obbStr = String(entry.obbligatorio || entry.is_mandatory || '').toLowerCase();
        const isObb = (obbStr === 'si' || obbStr === 'yes' || obbStr === 'true' || obbStr === '1');
        cardsHtml += `
          <div class="req-card">
            <div class="req-card-head">
              <div class="req-name">${escapeHtml(name)}</div>
              <span class="req-obbl ${isObb ? 'si' : 'no'}">${isObb ? '&#10005; Obbligatorio' : 'Facoltativo'}</span>
            </div>
            ${desc ? `<div class="req-desc">${escapeHtml(truncate(desc, 500))}</div>` : ''}
          </div>
        `;
      });
    } else {
      const dv = getDisplayValue(item);
      if (dv) {
        cardsHtml += `
          <div class="req-card">
            <div class="req-card-head">
              <div class="req-name">${escapeHtml(getTypeLabel(item.type_code || item.tipo))}</div>
            </div>
            <div class="req-desc">${escapeHtml(truncate(dv, 500))}</div>
          </div>
        `;
      }
    }
  }
});
```

- [ ] **Step 2: Add CSS for experience chips and legal reference**

In `assets/css/gare.css` after `.req-cat-v`:

```css
.req-legal { font-size: 11px; color: var(--gd-blue); margin-top: 4px; font-style: italic; }
.req-exp { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
.req-exp-chip {
  font-size: 10px;
  padding: 2px 8px;
  border-radius: 12px;
  background: var(--gd-blue-l);
  color: var(--gd-blue-t);
  font-weight: 600;
}
```

- [ ] **Step 3: Verify syntax + commit**
```bash
node -c assets/js/gare_detail.js
git add assets/js/gare_detail.js assets/css/gare.css
git commit -m "feat(gare-detail): renderRequisiti uses API requirements[] with experience details"
```

---

## Task 6: renderEconomici — use API turnover + capacita fields directly

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderEconomici()` function

Current problems:
- Fatturato: shows only display_value string
- Capacita: shows only table/text, loses formula, timeframe, RTI allocation

- [ ] **Step 1: Rewrite fatturato rendering to show structured data**

```javascript
const fatturatoItem = byType('fatturato_globale_n_minimo_anni');
if (fatturatoItem) {
  const fJson = getJson(fatturatoItem);
  const sr = fJson?.turnover_requirement?.single_requirement;
  if (sr) {
    const amount = (typeof sr.minimum_amount_value === 'number') ? formatEuro(sr.minimum_amount_value) : (sr.minimum_amount_raw || 'N/D');
    const rule = sr.calculation_rule || '';
    const tc = sr.temporal_calculation;
    const temporal = tc ? `Migliori ${tc.periods_to_select || '?'} anni su ${tc.lookback_window_years || '?'}` : '';
    const scope = sr.service_scope_description || '';

    html += `
      <div class="info-card" style="margin-bottom:12px;">
        <div class="ic-l">Fatturato globale minimo</div>
        <div class="ic-v" style="font-size:18px;font-weight:700;margin-bottom:4px">${escapeHtml(amount)}</div>
        ${rule ? `<div class="ic-v sm">${escapeHtml(truncate(rule, 300))}</div>` : ''}
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
          ${temporal ? `<span class="gd-badge gd-bb">${escapeHtml(temporal)}</span>` : ''}
          ${scope ? `<span class="gd-badge gd-bb">${escapeHtml(truncate(scope, 60))}</span>` : ''}
        </div>
      </div>
    `;
  } else {
    // Fallback to display_value
    const dv = getDisplayValue(fatturatoItem);
    html += `
      <div class="info-card" style="margin-bottom:12px;">
        <div class="ic-l">Fatturato globale minimo</div>
        <div class="ic-v">${dv ? escapeHtml(truncate(dv, 300)) : '<span class="ic-v abs">Dato non presente</span>'}</div>
      </div>
    `;
  }
}
```

- [ ] **Step 2: Rewrite capacita rendering to show minimum_amount and formula**

For `requisiti_di_capacita_economica_finanziaria`, read `requirements[].minimum_amount[].value`:

```javascript
const capacitaItem = byType('requisiti_di_capacita_economica_finanziaria');
if (capacitaItem) {
  const cJson = getJson(capacitaItem);
  if (cJson?.requirements?.length > 0) {
    cJson.requirements.forEach(req => {
      const text = req.requirement_text || '';
      const amounts = (req.minimum_amount || []).filter(a => a.value);
      const timeframe = req.timeframe;
      const formula = req.formula;

      html += `
        <div class="info-card" style="margin-bottom:12px;">
          <div class="ic-l">Capacita economico-finanziaria</div>
          ${text ? `<div class="ic-v sm">${escapeHtml(truncate(text, 400))}</div>` : ''}
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
            ${amounts.map(a => `<span class="gd-badge gd-bb">${escapeHtml(formatEuro(a.value))}</span>`).join('')}
            ${timeframe ? `<span class="gd-badge gd-bb">${escapeHtml(`${timeframe.selection_method} ${timeframe.selected_count}/${timeframe.total_window} ${timeframe.unit || 'anni'}`)}</span>` : ''}
            ${formula?.multiplier ? `<span class="gd-badge gd-bb">x${formula.multiplier}</span>` : ''}
          </div>
        </div>
      `;
    });
  } else {
    // Fallback to table/display_value (existing logic)
    // ... keep existing fallback
  }
}
```

- [ ] **Step 3: Verify syntax + commit**
```bash
node -c assets/js/gare_detail.js
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): renderEconomici uses API turnover and capacita fields directly"
```

---

## Task 7: renderDocsRuoli — use API documents[] and criteria[] with rich fields

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderDocsRuoli()` function
- Modify: `assets/css/gare.css` — new styles for document cards and criteria tree

Current problems:
- `documentazione_richiesta_tecnica`: loses max_pages, requirement_status, conditional_logic, notes
- `criteri_valutazione_offerta_tecnica`: loses subcriteria hierarchy with points
- `requisiti_idoneita_professionale_gruppo_lavoro`: loses roles[] and qualifications[]

- [ ] **Step 1: Rewrite documentazione rendering**

```javascript
if (typeCode === 'documentazione_richiesta_tecnica') {
  const dJson = getJson(source);
  if (dJson?.documents?.length > 0) {
    let docsHtml = dJson.documents.map(doc => {
      const status = doc.requirement_status || '';
      const statusCls = status === 'obbligatorio' ? 'gd-br' : (status === 'condizionale' ? 'gd-ba' : 'gd-bb');
      const fmt = doc.formatting_requirements || {};
      const maxPages = fmt.max_pages ? `Max ${fmt.max_pages} pag.` : '';
      const pageSize = fmt.page_size || '';
      const notes = doc.notes || '';

      return `
        <div class="doc-card">
          <div class="doc-head">
            <span class="doc-title">${escapeHtml(doc.title || 'Documento')}</span>
            <span class="gd-badge ${statusCls}">${escapeHtml(status || 'N/D')}</span>
          </div>
          <div class="doc-meta">
            ${maxPages ? `<span class="gd-chip">${escapeHtml(maxPages)}</span>` : ''}
            ${pageSize ? `<span class="gd-chip">${escapeHtml(pageSize)}</span>` : ''}
            ${doc.document_type ? `<span class="gd-chip">${escapeHtml(doc.document_type)}</span>` : ''}
          </div>
          ${notes ? `<div class="doc-notes">${escapeHtml(truncate(notes, 200))}</div>` : ''}
        </div>
      `;
    }).join('');
    leftHtml += `
      <div class="tcard" style="margin-bottom:12px;">
        <div class="tcard-hd">${escapeHtml(label)}</div>
        <div style="padding:10px;">${docsHtml}</div>
      </div>
    `;
  } else {
    // Fallback to existing table rendering
    // ... keep existing logic
  }
}
```

- [ ] **Step 2: Rewrite criteri rendering as structured tree**

```javascript
if (typeCode === 'criteri_valutazione_offerta_tecnica') {
  const cJson = getJson(source);
  if (cJson?.criteria?.length > 0) {
    const totalPts = cJson.total_max_points || '';
    let criteriaHtml = cJson.criteria.map(crit => {
      const subsHtml = (crit.subcriteria || []).map(sub =>
        `<div class="crit-sub">
          <span class="crit-sub-label">${escapeHtml(sub.label || '')}</span>
          <span class="crit-sub-title">${escapeHtml(truncate(sub.title || '', 120))}</span>
          <span class="crit-sub-pts">${sub.max_points || 0}</span>
        </div>`
      ).join('');
      return `
        <div class="crit-group">
          <div class="crit-head">
            <span class="crit-label">${escapeHtml(crit.label || '')}</span>
            <span class="crit-title">${escapeHtml(truncate(crit.title || '', 100))}</span>
            <span class="crit-pts">${crit.max_points || 0} pt</span>
          </div>
          ${subsHtml}
        </div>
      `;
    }).join('');

    leftHtml += `
      <div class="tcard" style="margin-bottom:12px;">
        <div class="tcard-hd">${escapeHtml(label)}${totalPts ? ` — ${totalPts} punti totali` : ''}</div>
        <div style="padding:10px;">${criteriaHtml}</div>
      </div>
    `;
  } else {
    // Fallback to existing table
  }
}
```

- [ ] **Step 3: Rewrite idoneita professionale to show roles + qualifications**

```javascript
if (typeCode === 'requisiti_idoneita_professionale_gruppo_lavoro') {
  const rpJson = getJson(source);
  // Show roles if available
  if (rpJson?.roles?.length > 0) {
    rpJson.roles.forEach(role => {
      rightHtml += `
        <div class="req-card" style="margin-bottom:10px;">
          <div class="req-card-head">
            <div class="req-name">${escapeHtml(role.name || 'Ruolo')}</div>
          </div>
          ${role.applies_to_phases?.length ? `<div class="req-desc">${role.applies_to_phases.map(p => escapeHtml(p)).join(', ')}</div>` : ''}
        </div>
      `;
    });
  }
  // Show requirements with qualifications
  if (rpJson?.requirements?.length > 0) {
    rpJson.requirements.forEach(req => {
      const qualHtml = (req.qualifications || []).map(q =>
        `<span class="gd-chip">${escapeHtml(q.description || q.type || '')}</span>`
      ).join('');
      rightHtml += `
        <div class="req-card" style="margin-bottom:10px;">
          <div class="req-card-head">
            <div class="req-name">${escapeHtml(req.original_text ? truncate(req.original_text, 120) : 'Requisito')}</div>
          </div>
          ${qualHtml ? `<div class="gd-chips" style="margin-top:6px;">${qualHtml}</div>` : ''}
        </div>
      `;
    });
  } else {
    // Fallback to existing entries/table logic
  }
}
```

- [ ] **Step 4: Add CSS for document cards and criteria tree**

```css
/* ─ DOCUMENT CARD ─ */
.doc-card { padding: 10px 0; border-bottom: 1px solid var(--gd-border); }
.doc-card:last-child { border-bottom: none; }
.doc-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 4px; }
.doc-title { font-size: 13px; font-weight: 600; color: var(--gd-t0); }
.doc-meta { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 4px; }
.doc-notes { font-size: 11px; color: var(--gd-t2); line-height: 1.5; }

/* ─ CRITERIA TREE ─ */
.crit-group { margin-bottom: 10px; }
.crit-head {
  display: flex; align-items: center; gap: 8px; padding: 6px 0;
  border-bottom: 1px solid var(--gd-border); font-weight: 600;
}
.crit-label { font-size: 13px; color: var(--gd-blue); min-width: 24px; }
.crit-title { font-size: 12px; color: var(--gd-t0); flex: 1; }
.crit-pts { font-size: 12px; font-weight: 700; color: var(--gd-t0); white-space: nowrap; }
.crit-sub {
  display: flex; align-items: center; gap: 8px; padding: 4px 0 4px 24px;
  border-bottom: 1px solid var(--gd-s2);
}
.crit-sub:last-child { border-bottom: none; }
.crit-sub-label { font-size: 11px; color: var(--gd-t2); min-width: 24px; }
.crit-sub-title { font-size: 11px; color: var(--gd-t1); flex: 1; }
.crit-sub-pts { font-size: 11px; font-weight: 700; color: var(--gd-t1); white-space: nowrap; }
```

- [ ] **Step 5: Verify syntax + commit**
```bash
node -c assets/js/gare_detail.js
git add assets/js/gare_detail.js assets/css/gare.css
git commit -m "feat(gare-detail): renderDocsRuoli uses API documents/criteria/roles directly"
```

---

## Task 8: Final cleanup — remove dead helpers that existed only for re-parsing

**Files:**
- Modify: `assets/js/gare_detail.js`

- [ ] **Step 1: Check which old parsing helpers are now unused**

After all renderers use `getJson` + direct API fields, check if these are still called:
- `formatDateItalianLong()` — may still be used as fallback, keep
- `daysUntil()` — may still be used as fallback, keep
- `formatDateItalian()` — may still be used in value cells, keep
- `resolveSopralluogoBool()` — check if still called anywhere
- `resolveSopralluogoDeadline()` — check if still called anywhere
- `normalizeSopralluogoDeadlineLabel()` — check if still called

Use `grep` to verify each. Remove only those with ZERO remaining call sites (excluding their own definition).

- [ ] **Step 2: Verify full syntax check**
```bash
node -c assets/js/gare_detail.js
```

- [ ] **Step 3: Final commit**
```bash
git add assets/js/gare_detail.js
git commit -m "chore(gare-detail): remove unused parsing helpers after API-direct refactor"
```
