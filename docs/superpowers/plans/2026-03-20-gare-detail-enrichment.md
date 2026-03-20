# Gare Detail Full Enrichment — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enrich the gare detail page with all structured data from the AI extraction API that is currently not displayed, correct wrong labels, and add a new "Servizi e Prestazioni" section.

**Architecture:** All changes are frontend-only in `gare_detail.js` (section renderers) and `gare_dettaglio.php` (one new container div). Data is already available via `getJson(item)` — we just need to read and render additional sub-fields. One new renderer function `renderServizi()` is added; all others are enriched in-place.

**Tech Stack:** Vanilla JS (ES6), PHP views, CSS

**Spec:** `docs/superpowers/specs/2026-03-20-gare-detail-enrichment-design.md`
**Reference data:** `.agent/samples/gara_267_results.json`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `views/gare_dettaglio.php` | Modify (line 52-55) | Add `gd-servizi` container div |
| `assets/js/gare_detail.js` | Modify (multiple functions) | All rendering changes |

---

### Task 1: Add gd-servizi container in view

**Files:**
- Modify: `views/gare_dettaglio.php:52-55`

- [ ] **Step 1: Insert the new div between gd-overview and gd-importi**

In `views/gare_dettaglio.php`, after line 52 (`<div id="gd-overview" ...>`), add:

```php
        <!-- Servizi e Prestazioni richieste -->
        <div id="gd-servizi" style="display:none"></div>
```

So the result is:
```php
        <!-- Panoramica -->
        <div id="gd-overview" style="display:none"></div>

        <!-- Servizi e Prestazioni richieste -->
        <div id="gd-servizi" style="display:none"></div>

        <!-- Importi e valori economici -->
        <div id="gd-importi" style="display:none"></div>
```

- [ ] **Step 2: Verify in browser**

Open any gare detail page. The new div should be invisible (display:none). No visual change yet.

- [ ] **Step 3: Commit**

```bash
git add views/gare_dettaglio.php
git commit -m "feat(gare-detail): add gd-servizi container div"
```

---

### Task 2: Header — Enrich luogo with entity_type

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderHeader()` (~line 997-1004)

- [ ] **Step 1: Modify the luogo construction block**

Current code (lines 997-1004):
```javascript
    if (luogoJson?.location) {
      const loc = luogoJson.location;
      const parts = [loc.entity_name, loc.city].filter(Boolean);
      luogo = parts.join(', ');
      if (loc.district) luogo += ` (${loc.district})`;
    } else {
      luogo = getDisplayValue(luogoItem);
    }
```

Replace with:
```javascript
    if (luogoJson?.location) {
      const loc = luogoJson.location;
      const nameParts = [loc.entity_type, loc.entity_name].filter(Boolean);
      const entityStr = nameParts.join(' ');
      const parts = [entityStr, loc.city].filter(Boolean);
      luogo = parts.join(', ');
      if (loc.district) luogo += ` (${loc.district})`;
      if (loc.country && loc.country !== 'Italia') luogo += ` — ${loc.country}`;
      if (loc.nuts_code) luogo += ` [${loc.nuts_code}]`;
    } else {
      luogo = getDisplayValue(luogoItem);
    }
```

- [ ] **Step 2: Verify in browser**

Open gara 267 detail. Luogo should now show: "Ex Presidio Ospedaliero Santo Bambino, Catania (CT)" instead of "Santo Bambino, Catania (CT)".

- [ ] **Step 3: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): enrich header luogo with entity_type"
```

---

### Task 3: Overview — Enrich sopralluogo card

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderOverview()` (~line 1204-1225)

- [ ] **Step 1: Extract additional sopralluogo fields**

After line 1120 (`if (sopJson.booking_platform?.url) sopBookingUrl = sopJson.booking_platform.url;`), add:

```javascript
      sopBookingInstructions = sopJson.booking_instructions || '';
      sopDeadlineNotes = (Array.isArray(sopJson.deadlines) && sopJson.deadlines.length > 0)
        ? (sopJson.deadlines[0].notes || '') : '';
      sopBookingContacts = Array.isArray(sopJson.booking_contacts) ? sopJson.booking_contacts : [];
```

And declare these variables near the other `let sop*` declarations (~line 1109):
```javascript
    let sopBookingInstructions = '';
    let sopDeadlineNotes = '';
    let sopBookingContacts = [];
```

- [ ] **Step 2: Render the additional fields in the sopralluogo card**

In the sopralluogo card HTML block (~line 1215-1225), after the booking link line (`${sopBookingUrl ? ...}`), add:

```javascript
            ${sopDeadlineNotes ? `<div class="sop-note" style="margin-top:4px"><span class="gd-badge gd-ba" style="font-size:11px">${escapeHtml(sopDeadlineNotes)}</span></div>` : ''}
            ${sopBookingInstructions ? `<div class="sop-instr" style="margin-top:6px;font-size:12px;color:var(--gd-t2);line-height:1.4">${escapeHtml(truncate(sopBookingInstructions, 200))}</div>` : ''}
            ${sopBookingContacts.length > 0 ? `<div class="sop-contacts" style="margin-top:4px;font-size:12px">${sopBookingContacts.map(c => escapeHtml(typeof c === 'string' ? c : (c.name || c.email || JSON.stringify(c)))).join(', ')}</div>` : ''}
```

- [ ] **Step 3: Verify in browser**

Open gara 267 detail. Sopralluogo card should now show "Termini tassativi" badge and the booking instructions text below the platform link.

- [ ] **Step 4: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): enrich sopralluogo with instructions and deadline notes"
```

---

### Task 4: Overview — Add info procedurali card

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderOverview()` (~line 1238-1247)

- [ ] **Step 1: Add helper to search citations for keywords**

Add this utility function right before `renderOverview()` (before line 1091), after the helper functions block:

```javascript
  /**
   * Search an extraction item's citations for a keyword match.
   * Returns the matched citation text fragment or '' if not found.
   */
  function findInCitations(item, keyword) {
    const json = getJson(item?.synthetic_source || item);
    if (!json) return '';
    const citations = json.citations || [];
    for (const cit of citations) {
      const texts = Array.isArray(cit.text) ? cit.text : [cit.text || ''];
      for (const t of texts) {
        if (t.toLowerCase().includes(keyword.toLowerCase())) return t;
      }
    }
    return '';
  }
```

- [ ] **Step 2: Build the info procedurali card in renderOverview**

In `renderOverview()`, after the tipologiaAppalto/settore card block (~line 1247, right before the closing `</div>` of rightColHtml), add the following code:

```javascript
        // Info procedurali card
        const tipologiaGaraItem = byType('tipologia_di_gara');
        const criteriItem = byType('criteri_valutazione_offerta_tecnica');

        const inversioneText = findInCitations(tipologiaGaraItem, 'inversione procedimentale');
        const hasInversione = !!inversioneText;

        const metodoText = findInCitations(criteriItem, 'aggregativo') || findInCitations(criteriItem, 'metodo');
        const criterioText = findInCitations(tipologiaGaraItem, 'offerta economicamente') || findInCitations(tipologiaGaraItem, 'criterio');

        const procItems = [];
        procItems.push(`<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0"><span class="ic-l" style="margin:0">Inversione procedimentale</span><span class="gd-badge ${hasInversione ? 'gd-ba' : 'gd-bb'}" style="font-size:11px">${hasInversione ? 'Sì' : 'No'}</span></div>`);
        if (metodoText) procItems.push(`<div style="padding:4px 0"><span class="ic-l" style="margin:0">Metodo di aggiudicazione</span><div class="ic-v" style="font-size:13px;margin-top:2px">${escapeHtml(truncate(metodoText, 120))}</div></div>`);
        if (criterioText) procItems.push(`<div style="padding:4px 0"><span class="ic-l" style="margin:0">Criterio di aggiudicazione</span><div class="ic-v" style="font-size:13px;margin-top:2px">${escapeHtml(truncate(criterioText, 120))}</div></div>`);
```

Then append the card to rightColHtml. The easiest approach: build `procCardHtml` as a variable and include it in the rightColHtml template literal. Since `rightColHtml` is constructed as a template literal, add the card after the tipologiaAppalto info-card block:

```javascript
        const procCardHtml = procItems.length > 0 ? `
        <div class="info-card">
          <div class="ic-l" style="font-weight:600;margin-bottom:6px">Informazioni procedurali</div>
          ${procItems.join('<div style="border-top:1px solid #eee"></div>')}
        </div>` : '';
```

Insert `${procCardHtml}` in the rightColHtml template literal, after the tipologiaAppalto/settore card block.

- [ ] **Step 3: Verify in browser**

Open gara 267 detail. Overview should now show an "Informazioni procedurali" card with: Inversione procedimentale = Sì, Metodo = aggregativo-compensatore, Criterio = offerta economicamente più vantaggiosa.

- [ ] **Step 4: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): add info procedurali card in overview"
```

---

### Task 5: New section — renderServizi()

**Files:**
- Modify: `assets/js/gare_detail.js` — add new function + orchestration call

- [ ] **Step 1: Add the Qcl citation parser utility**

Add this utility function near the other helpers:

```javascript
  /**
   * Parse Qcl codes and complexity grades from importi_corrispettivi citation text.
   * Returns a Map of category_id → { qclCodes: string, complexityGrade: string }
   */
  function parseQclFromCitations(corrispettiviItem) {
    const result = new Map();
    const json = getJson(corrispettiviItem?.synthetic_source || corrispettiviItem);
    if (!json?.citations) return result;
    const regex = /^(.+?)\s+((?:[A-Z]+\.)\d+)\s+(Qcl\.\d+(?:\s+[–\-]\s+Qcl\.\d+)*)\s+([\d.,]+)\s+€/;
    for (const cit of json.citations) {
      const texts = Array.isArray(cit.text) ? cit.text : [cit.text || ''];
      for (const line of texts) {
        const m = line.match(regex);
        if (m) {
          result.set(m[2], { qclCodes: m[3], complexityGrade: m[4] });
        }
      }
    }
    return result;
  }
```

- [ ] **Step 2: Add the renderServizi function**

Add this new function after `renderOverview()` and before `renderImporti()`:

```javascript
  /**
   * Render the Servizi e Prestazioni richieste section.
   * Cross-extraction renderer: reads oggetto_appalto + importi_corrispettivi.
   */
  function renderServizi(byType) {
    const el = document.getElementById('gd-servizi');
    if (!el) return;

    const oggettoJson = getJson(byType('oggetto_appalto'));
    const corrispettiviItem = byType('importi_corrispettivi_categoria_id_opere');
    const corrispettiviJson = getJson(corrispettiviItem?.synthetic_source || corrispettiviItem);

    const servizi = oggettoJson?.servizi_previsti || [];
    const entries = corrispettiviJson?.entries || [];
    const qclMap = parseQclFromCitations(corrispettiviItem);

    if (servizi.length === 0 && entries.length === 0) return;

    // Service cards
    let serviziCardsHtml = '';
    if (servizi.length > 0) {
      serviziCardsHtml = `<div class="${servizi.length > 1 ? 'g2' : ''}" style="margin-bottom:16px">` +
        servizi.map(s => {
          const amount = (typeof s.amount_eur === 'number' && s.amount_eur > 0)
            ? formatEuro(s.amount_eur)
            : (s.amount_raw || '');
          const isObb = s.is_optional === false;
          return `
            <div class="info-card">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <div class="ic-l" style="margin:0;font-weight:600">${escapeHtml(s.label || s.service_type || 'Servizio')}</div>
                <span class="gd-badge ${isObb ? 'gd-br' : 'gd-bb'}">${isObb ? 'Obbligatorio' : 'Opzionale'}</span>
              </div>
              ${amount ? `<div class="ic-v" style="font-size:16px;font-weight:700;margin:4px 0">${escapeHtml(amount)}</div>` : ''}
              ${s.legal_reference ? `<div style="font-size:12px;color:var(--gd-t2);margin-top:4px">${escapeHtml(truncate(s.legal_reference, 150))}</div>` : ''}
              ${s.notes ? `<div style="font-size:12px;color:var(--gd-t2);margin-top:4px;font-style:italic">${escapeHtml(truncate(s.notes, 200))}</div>` : ''}
            </div>`;
        }).join('') + '</div>';
    }

    // Prestazioni table
    let tableHtml = '';
    if (entries.length > 0) {
      const hasQcl = qclMap.size > 0;
      const rows = entries.map(e => {
        const catId = e.category_id || e.id_opera || '';
        const parsed = qclMap.get(catId) || {};
        const amount = (typeof e.amount_eur === 'number') ? formatEuro(e.amount_eur) : (e.amount_raw || '—');
        return `<tr>
          <td>${escapeHtml(e.category_name || '—')}</td>
          <td><strong>${escapeHtml(catId)}</strong></td>
          ${hasQcl ? `<td>${escapeHtml(parsed.qclCodes || '—')}</td>` : ''}
          ${hasQcl ? `<td class="tv">${escapeHtml(parsed.complexityGrade || '—')}</td>` : ''}
          <td class="tv">${escapeHtml(amount)}</td>
        </tr>`;
      }).join('');

      const totalAmount = entries.reduce((sum, e) => {
        const v = (typeof e.amount_eur === 'number') ? e.amount_eur : 0;
        return sum + v;
      }, 0);

      tableHtml = `
        <div class="tcard">
          <div class="tcard-hd">Prestazioni per categoria</div>
          <table class="table--modern">
            <thead><tr>
              <th>Categoria</th><th>ID Opera</th>
              ${hasQcl ? '<th>Codici Prestazione</th><th>Grado Complessità</th>' : ''}
              <th>Importo</th>
            </tr></thead>
            <tbody>${rows}</tbody>
            ${totalAmount > 0 ? `<tfoot><tr>
              <td colspan="${hasQcl ? 4 : 2}" style="text-align:right;font-weight:600">Totale</td>
              <td class="tv" style="font-weight:700">${escapeHtml(formatEuro(totalAmount))}</td>
            </tr></tfoot>` : ''}
          </table>
        </div>`;
    }

    el.innerHTML = `
      <div class="gd-sec">
        <div class="gd-sec-hd">Servizi e prestazioni richieste</div>
        ${serviziCardsHtml}
        ${tableHtml}
      </div>
    `;
    showSection('gd-servizi');
  }
```

- [ ] **Step 3: Wire renderServizi into orchestration**

At line 936-937, change:

```javascript
    renderOverview(sections.overview, byType, allItems);
    renderImporti(sections.importi, byType);
```

To:

```javascript
    renderOverview(sections.overview, byType, allItems);
    renderServizi(byType);
    renderImporti(sections.importi, byType);
```

- [ ] **Step 4: Verify in browser**

Open gara 267 detail. Between Overview and Importi, a new "Servizi e prestazioni richieste" section should appear with:
- A card "Direzione Lavori" showing amount, legal reference, and notes
- A table with 6 rows (E.20, IA.01-04, S.03) with Qcl codes, complexity grades, and amounts

- [ ] **Step 5: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): add Servizi e Prestazioni section with Qcl parsing"
```

---

### Task 6: Importi — Fix labels and restructure stat cards

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderImporti()` (~line 1266-1394)

- [ ] **Step 1: Rewrite stat cards from 4 to 3 with correct labels**

Replace the entire stat cards block (lines 1280-1357) with:

```javascript
    // Importo a base d'appalto: prefer servizi_previsti sum, fallback to opere sum
    const oggettoJson = getJson(byType('oggetto_appalto'));
    let importoBase = 0;
    let importoBaseLabel = '';
    if (oggettoJson?.servizi_previsti && Array.isArray(oggettoJson.servizi_previsti)) {
      importoBase = oggettoJson.servizi_previsti.reduce((sum, s) => {
        const v = (typeof s.amount_eur === 'number') ? s.amount_eur : parseItalianNumber(s.amount_eur || s.amount_raw);
        return sum + (isNaN(v) ? 0 : v);
      }, 0);
      if (importoBase > 0) importoBaseLabel = 'Da oggetto dell\'appalto';
    }
    if (importoBase === 0 && opereSum > 0) {
      importoBase = opereSum;
      importoBaseLabel = 'Somma importi per categoria';
    }

    // Sub-label: check if "a corpo" in tipologia_di_appalto
    const tipAppaltoAnswer = getSimpleAnswer(byType('tipologia_di_appalto')) || '';
    if (tipAppaltoAnswer.toLowerCase().includes('a corpo')) {
      importoBaseLabel += importoBaseLabel ? ' · corrispettivo a corpo' : 'Corrispettivo a corpo';
    }

    // Fatturato — read API turnover_requirement directly
    let fatturatoVal = 0;
    let fatturatoDerivation = '';
    let fatturatoScope = '';
    const fatturatoItem = byType('fatturato_globale_n_minimo_anni');
    if (fatturatoItem) {
      const fJson = getJson(fatturatoItem);
      if (fJson?.turnover_requirement?.single_requirement) {
        const sr = fJson.turnover_requirement.single_requirement;
        fatturatoVal = (typeof sr.minimum_amount_value === 'number') ? sr.minimum_amount_value : 0;
        fatturatoDerivation = sr.derivation_formula || '';
        fatturatoScope = sr.service_scope_description || '';
      }
      if (fatturatoVal === 0 && fJson) {
        const raw = fJson.importo_minimo ?? fJson.importo_minimo_eur ?? null;
        if (raw !== null) { const v = (typeof raw === 'number') ? raw : parseItalianNumber(raw); if (!isNaN(v)) fatturatoVal = v; }
      }
      if (fatturatoVal === 0) {
        const fv = getDisplayValue(fatturatoItem);
        const match = String(fv).match(/[\d.,]+/);
        if (match) { const v = parseItalianNumber(match[0]); if (!isNaN(v) && v > 0) fatturatoVal = v; }
      }
    }

    const fatturatoSub = [fatturatoDerivation, fatturatoScope].filter(Boolean).join(' · ') || 'Requisito economico';

    // 3 stat cards
    const statCardsHtml = `
      <div class="g3">
        <div class="imp-card">
          <div class="imp-label">Importo a base d'appalto</div>
          ${importoBase > 0 ? `<div class="imp-val">${escapeHtml(formatEuro(importoBase))}</div>` : '<div class="imp-val na">N/D</div>'}
          <div class="imp-sub">${importoBase > 0 ? escapeHtml(importoBaseLabel) : 'Non presente nel disciplinare'}</div>
        </div>
        <div class="imp-card">
          <div class="imp-label">Corrispettivi professionali</div>
          ${corrispettiviSum > 0 ? `<div class="imp-val">${escapeHtml(formatEuro(corrispettiviSum))}</div>` : '<div class="imp-val na">N/D</div>'}
          <div class="imp-sub">${corrispettiviSum > 0 ? 'Somma per categoria' : 'Non strutturato'}</div>
        </div>
        <div class="imp-card">
          <div class="imp-label">Fatturato minimo richiesto</div>
          ${fatturatoVal > 0 ? `<div class="imp-val">${escapeHtml(formatEuro(fatturatoVal))}</div>` : '<div class="imp-val na">N/D</div>'}
          <div class="imp-sub">${fatturatoVal > 0 ? escapeHtml(truncate(fatturatoSub, 60)) : 'Non specificato'}</div>
        </div>
      </div>
    `;
```

Also remove the `firstIdOpera` computation block (lines 1313-1331) — no longer needed.

**Note on spec 4.2 (Importi table Qcl columns):** The spec calls for adding Qcl/Grade columns to the "Corrispettivi per categoria" table in Importi. This is intentionally NOT implemented here because: (1) the new Servizi section (Task 5) already displays a complete prestazioni table with Qcl codes and grades, (2) duplicating the same columns in Importi would be redundant, (3) the Importi tables are rendered via `buildExtractionTabs()` which auto-generates columns from `ext_table_cells` data — modifying this generic renderer would have side effects on other extraction types.

- [ ] **Step 2: Verify g3 CSS class exists**

The `.g3` class already exists at line 1377 of `assets/css/gare.css`. No CSS changes needed. If for any reason it does not exist, add:

```css
.g3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
@media (max-width: 768px) { .g3 { grid-template-columns: 1fr; } }
```

- [ ] **Step 3: Verify in browser**

Open gara 267 detail. Importi section should show 3 cards:
1. "Importo a base d'appalto" = € 253.568,93 with "Da oggetto dell'appalto · corrispettivo a corpo"
2. "Corrispettivi professionali" = sum of category fees
3. "Fatturato minimo richiesto" = € 253.568,93 with "pari all'importo a base d'asta · servizi di ingegneria e di architettura"

- [ ] **Step 4: Commit**

```bash
git add assets/js/gare_detail.js assets/css/gare.css
git commit -m "feat(gare-detail): fix importi labels, restructure to 3 stat cards"
```

---

### Task 7: Requisiti — Enrich young_professional and experience cards

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderRequisiti()` (~line 1411-1505)

- [ ] **Step 1: Add young_professional_details rendering**

In `renderRequisiti()`, inside the `json.requirements.forEach` block, after the `experienceHtml` block (~line 1442), add:

```javascript
          // Young professional details
          let ypHtml = '';
          if (reqType === 'young_professional' && req.young_professional_details) {
            const yp = req.young_professional_details;
            const ypChips = [];
            if (yp.academic_requirement) ypChips.push(yp.academic_requirement);
            if (yp.maximum_registration_years) ypChips.push(`iscritto da meno di ${yp.maximum_registration_years} anni`);
            if (yp.minimum_count) ypChips.push(`almeno ${yp.minimum_count}`);
            if (yp.must_be_designer) ypChips.push('deve essere progettista');
            if (yp.applies_to_organization_types?.length > 0) {
              ypChips.push(...yp.applies_to_organization_types);
            }
            if (ypChips.length > 0) {
              ypHtml = `<div class="req-exp">${ypChips.map(c => `<span class="req-exp-chip">${escapeHtml(c)}</span>`).join('')}</div>`;
            }
          }
```

- [ ] **Step 2: Add experience details enrichment**

After the `ypHtml` block, add:

```javascript
          // Experience details enrichment
          let expDetailHtml = '';
          if (reqType === 'experience' && req.experience_details) {
            const ed = req.experience_details;
            const edParts = [];
            if (ed.time_period_years && ed.reference_date) {
              edParts.push(`<div style="font-size:12px;color:var(--gd-t2);margin-top:4px">Ultimi ${ed.time_period_years} anni dalla ${escapeHtml(ed.reference_date)}</div>`);
            }
            if (ed.analogy_criteria) {
              edParts.push(`<div style="font-size:12px;color:var(--gd-t2);margin-top:2px;font-style:italic">${escapeHtml(truncate(ed.analogy_criteria, 150))}</div>`);
            }
            if (ed.min_project_count) {
              edParts.push(`<div style="font-size:12px;margin-top:2px"><span class="gd-badge gd-bb">Minimo ${ed.min_project_count} progetti</span></div>`);
            }
            expDetailHtml = edParts.join('');
          }
```

- [ ] **Step 3: Include the new HTML in the card template**

In the card template (~line 1444-1459), add `${ypHtml}` and `${expDetailHtml}` in the `req-foot` div, after `${experienceHtml}`:

```javascript
              <div class="req-foot">
                ${legalRef ? `<span class="req-legal">${escapeHtml(legalRef)}</span>` : ''}
                ${experienceHtml}
                ${ypHtml}
                ${expDetailHtml}
              </div>
```

- [ ] **Step 4: Add requirements_summary at the bottom**

After the `requisitiItems.forEach` loop (~line 1488), before `if (!cardsHtml) return;`, add:

```javascript
    // Requirements summary
    let summaryHtml = '';
    requisitiItems.forEach(item => {
      const json = getJson(item?.synthetic_source || item);
      if (json?.requirements_summary) {
        const rs = json.requirements_summary;
        const pills = [];
        if (rs.total_count) pills.push(`${rs.total_count} requisiti totali`);
        if (rs.all_mandatory === false && rs.total_count) pills.push(`${rs.count_experience || 0} esperienza`);
        if (rs.has_legal_references) pills.push(`${rs.has_legal_references} con rif. normativo`);
        if (pills.length > 0) {
          summaryHtml = `<div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">${pills.map(p => `<span class="gd-badge gd-bb" style="font-size:11px">${escapeHtml(p)}</span>`).join('')}</div>`;
        }
      }
    });
```

Then modify the final innerHTML to include the summary:

```javascript
    el.innerHTML = `<div class="gd-sec"><div class="gd-sec-hd">Requisiti tecnico-professionali</div><div class="req-grid">${cardsHtml}</div>${summaryHtml}</div>`;
```

- [ ] **Step 5: Verify in browser**

Open gara 267 detail. Requisiti section should now show:
- Young professional card with "laureato abilitato", "iscritto da meno di 5 anni", "almeno 1", org type badges
- Experience card with "Ultimi 10 anni dalla data di pubblicazione del bando" and analogy criteria text
- Summary pills at the bottom: "3 requisiti totali", "2 con rif. normativo"

- [ ] **Step 6: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): enrich requisiti with young_professional, experience details, summary"
```

---

### Task 8: Economici — Enrich fatturato and capacità cards

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderEconomici()` (~line 1510-1599)

- [ ] **Step 1: Enrich the fatturato card**

In `renderEconomici()`, replace the fatturato card chips block (lines 1531-1532):

```javascript
      let chipsHtml = [temporalLabel, derivation, scope].filter(Boolean)
        .map(t => `<span class="gd-chip">${escapeHtml(truncate(t, 50))}</span>`).join('');
```

With:

```javascript
      const thresholdPhrase = sr.threshold_direction_phrase || '';
      const calcMethod = sr.calculation_method || '';
      const calcMethodLabel = calcMethod === 'cumulative_total' ? 'Totale cumulativo' :
        calcMethod === 'average' ? 'Media' : calcMethod;
      const amountBasis = sr.amount_basis || '';
      const amountBasisLabel = amountBasis === 'derived_from_contract' ? 'Derivato dal valore contrattuale' : '';

      const chipItems = [temporalLabel, derivation, scope, thresholdPhrase, calcMethodLabel, amountBasisLabel]
        .filter(Boolean);
      let chipsHtml = chipItems.map(t => `<span class="gd-chip">${escapeHtml(truncate(t, 60))}</span>`).join('');
```

- [ ] **Step 2: Enrich the capacità economica cards**

In the `cJson.requirements.forEach` block (~line 1552-1571), after the existing `rtiRules` line, add formula details:

```javascript
        const formula = req.formula;
        let formulaHtml = '';
        if (formula && formula.multiplier && formula.base_reference) {
          const baseRefLabel = formula.base_reference === 'contract_value' ? 'valore contratto' : formula.base_reference;
          formulaHtml = `<div style="font-size:12px;color:var(--gd-t2);margin-top:4px"><strong>Formula:</strong> ${formula.multiplier}x ${escapeHtml(baseRefLabel)}</div>`;
        }

        const calcRule = req.calculation_rule || '';
        let calcRuleHtml = '';
        if (calcRule) {
          calcRuleHtml = `<div class="ic-v sm expandable" onclick="this.classList.toggle('open')" data-full="${escapeAttribute(calcRule)}" style="margin-top:6px;font-size:12px">${escapeHtml(truncate(calcRule, 150))}</div>`;
        }

        // RTI enrichment
        let rtiHtml = '';
        const rti = req.rti_allocation;
        if (rti) {
          const rtiParts = [];
          if (rti.distribution_rules) rtiParts.push(rti.distribution_rules);
          if (rti.lead_firm_minimum_percentage) rtiParts.push(`Capogruppo minimo: ${rti.lead_firm_minimum_percentage}%`);
          if (rti.minimum_per_member_percentage) rtiParts.push(`Minimo per membro: ${rti.minimum_per_member_percentage}%`);
          if (rtiParts.length > 0) {
            const rtiText = rtiParts.join(' · ');
            rtiHtml = `<div class="ic-v sm expandable" onclick="this.classList.toggle('open')" data-full="${escapeAttribute('RTI: ' + rtiText)}" style="margin-top:8px;font-style:italic"><strong>RTI:</strong> ${escapeHtml(truncate(rtiText, 150))}</div>`;
          }
        }
```

Then update the card template to include `${formulaHtml}`, `${calcRuleHtml}`, and replace the old `${rtiRules ? ...}` line with `${rtiHtml}`:

```javascript
        cards.push(`
          <div class="info-card">
            <div class="ic-l">Capacità economico-finanziaria</div>
            ${reqText ? `<div class="ic-v sm expandable" onclick="this.classList.toggle('open')" data-full="${escapeAttribute(reqText)}">${escapeHtml(truncate(reqText, 200))}</div>` : ''}
            ${chipsHtml ? `<div class="gd-chips" style="margin-top:8px">${chipsHtml}</div>` : ''}
            ${formulaHtml}
            ${calcRuleHtml}
            ${rtiHtml}
          </div>
        `);
```

- [ ] **Step 3: Verify in browser**

Open gara 267 detail. Economici section should now show:
- Fatturato card with extra chips: "pari a", "Totale cumulativo", "Derivato dal valore contrattuale"
- Capacità card with formula "1.0x valore contratto", calculation rule text, and enriched RTI rules

- [ ] **Step 4: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): enrich economici with formula, calculation rules, RTI details"
```

---

### Task 9: Docs — Enrich documentazione table

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderDocsRuoli()` — documentazione block (~line 1618-1646)

- [ ] **Step 1: Enrich the documents table rows**

Replace the documents table rendering block (lines 1621-1641) with:

```javascript
        if (json?.documents?.length > 0) {
          const rows = json.documents.map(doc => {
            const status = doc.requirement_status || '';
            const statusCls = status === 'obbligatorio' ? 'gd-br' : (status === 'condizionale' ? 'gd-ba' : 'gd-bb');
            const fmt = doc.formatting_requirements || {};
            const maxPages = fmt.max_pages || '—';
            const pageSize = fmt.page_size || '—';
            const template = doc.template_reference?.template_id || '';

            // Condition text for condizionale documents
            const condText = (status === 'condizionale' && doc.conditional_logic?.description) ? doc.conditional_logic.description : '';

            // Expandable details
            const detailParts = [];
            if (doc.document_cardinality) {
              const dc = doc.document_cardinality;
              const cardText = [dc.max_instances ? `Max ${dc.max_instances} ${dc.per_unit || ''}` : '', dc.description || ''].filter(Boolean).join(' — ');
              if (cardText) detailParts.push(cardText);
            }
            if (doc.required_components?.length > 0) {
              doc.required_components.forEach(rc => {
                detailParts.push(`${rc.is_mandatory ? 'Obbligatorio' : 'Facoltativo'}: ${rc.description || rc.component_type}`);
              });
            }
            if (fmt.page_count_exclusions?.length > 0) {
              detailParts.push(`Non contano: ${fmt.page_count_exclusions.join(', ')}`);
            }
            if (doc.notes) detailParts.push(doc.notes);

            const detailHtml = detailParts.length > 0
              ? `<tr class="doc-detail-row"><td colspan="5" style="padding:4px 12px 8px;font-size:12px;color:var(--gd-t2);border-top:none">${detailParts.map(d => `<div style="margin-bottom:2px">${escapeHtml(truncate(d, 200))}</div>`).join('')}</td></tr>`
              : '';

            return `<tr>
              <td class="tt">${escapeHtml(truncate(doc.title || 'Documento', 80))}</td>
              <td><span class="gd-badge ${statusCls}">${escapeHtml(status || '—')}</span>${condText ? `<div style="font-size:11px;color:var(--gd-t2);margin-top:2px">${escapeHtml(truncate(condText, 80))}</div>` : ''}</td>
              <td class="tv">${template ? `<span class="gd-badge gd-bb" style="font-size:11px">${escapeHtml(template)}</span>` : '—'}</td>
              <td class="tv">${escapeHtml(String(maxPages))}</td>
              <td class="tv">${escapeHtml(pageSize)}</td>
            </tr>${detailHtml}`;
          }).join('');
          leftHtml += `
            <div class="tcard" style="margin-bottom:12px;">
              <div class="tcard-hd">${escapeHtml(label)} (${json.documents.length})</div>
              <table>
                <thead><tr><th>Documento</th><th>Stato</th><th>Template</th><th>Pagine</th><th>Formato</th></tr></thead>
                <tbody>${rows}</tbody>
              </table>
            </div>`;
        }
```

- [ ] **Step 2: Verify in browser**

Open gara 267 detail. Documents table should now show:
- New "Template" column with badges (Modello C, Modello AA)
- Condizionale documents show condition text under the status badge
- Detail rows below documents with cardinality, components, notes, page exclusions

- [ ] **Step 3: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): enrich docs table with template, conditions, details"
```

---

### Task 10: Docs — Enrich criteri valutazione header

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderDocsRuoli()` — criteri block (~line 1647-1662)

- [ ] **Step 1: Add metodo and punteggio header to criteri section**

Replace the criteri rendering block (lines 1647-1662) with:

```javascript
      } else if (typeCode === 'criteri_valutazione_offerta_tecnica') {
        if (json?.criteria?.length > 0) {
          const totalPts = json.total_max_points || '';
          const econPts = totalPts ? (100 - totalPts) : '';

          // Search citations for metodo and riparametrazione
          const metodoMatch = findInCitations(item, 'aggregativo') || findInCitations(item, 'metodo');
          const riparametrMatch = findInCitations(item, 'riparametr');

          let headerInfo = '';
          const headerParts = [];
          if (metodoMatch) headerParts.push(`<span class="gd-chip">${escapeHtml(truncate(metodoMatch, 60))}</span>`);
          if (totalPts && econPts) headerParts.push(`<span class="gd-chip">${totalPts}/100 tecnica — ${econPts}/100 economica</span>`);
          if (headerParts.length > 0) headerInfo = `<div class="gd-chips" style="padding:6px 10px">${headerParts.join('')}</div>`;

          const riparametrHtml = riparametrMatch
            ? `<div style="padding:4px 10px;font-size:12px;color:var(--gd-t2);font-style:italic">${escapeHtml(truncate(riparametrMatch, 200))}</div>` : '';

          const criteriaHtml = json.criteria.map(crit => {
            const subsHtml = (crit.subcriteria || []).map(sub =>
              `<div class="crit-sub"><span class="crit-sub-label">${escapeHtml(sub.label || '')}</span><span class="crit-sub-title">${escapeHtml(truncate(sub.title || '', 120))}</span><span class="crit-sub-pts">${sub.max_points || 0}</span></div>`
            ).join('');
            return `<div class="crit-group"><div class="crit-head"><span class="crit-label">${escapeHtml(crit.label || '')}</span><span class="crit-title">${escapeHtml(truncate(crit.title || '', 100))}</span><span class="crit-pts">${crit.max_points || 0} pt</span></div>${subsHtml}</div>`;
          }).join('');
          leftHtml += `<div class="tcard" style="margin-bottom:12px;"><div class="tcard-hd">${escapeHtml(label)}${totalPts ? ` — ${totalPts} punti totali` : ''}</div>${headerInfo}${riparametrHtml}<div style="padding:10px;">${criteriaHtml}</div></div>`;
        } else {
          const tabs = buildExtractionTabs(source);
          const responseTab = tabs.find(t => t.id === 'response');
          if (responseTab?.content) leftHtml += `<div class="tcard" style="margin-bottom:12px;"><div class="tcard-hd">${escapeHtml(label)}</div><div style="overflow-x:auto;">${responseTab.content}</div></div>`;
        }
```

- [ ] **Step 2: Verify in browser**

Open gara 267 detail. Criteri section should now show:
- Chips with "metodo aggregativo-compensatore" and "70/100 tecnica — 30/100 economica"
- Riparametrazione note if present

- [ ] **Step 3: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): enrich criteri with metodo, punteggio, riparametrazione"
```

---

### Task 11: Docs — Enrich requisiti idoneità professionale

**Files:**
- Modify: `assets/js/gare_detail.js` — `renderDocsRuoli()` — idoneità block (~line 1663-1734)

- [ ] **Step 1: Add staffing constraints and composition badge**

In the idoneità professionale block, after the roles table header row (`<thead>...<th>Qualifiche richieste</th>...`), modify the role row rendering (~line 1695-1703) to include staffing constraints:

After the `qualsByRoleId` construction and before the table rendering, add a constraint map:

```javascript
        const constraintsByRoleId = {};
        reqs.forEach(req => {
          const sc = req.role_staffing_constraints;
          if (sc) {
            const roleIds = req.applies_to_all_roles ? roles.map(r => r.id) : (req.applies_to_role_ids || []);
            roleIds.forEach(rid => { constraintsByRoleId[rid] = sc; });
          }
        });
        const isMinComposition = json.is_minimum_composition;
```

Then in the role row rendering, after the qualifications column, add a constraints column:

Replace the existing role row rendering:
```javascript
              tableRows += `<tr data-group="${pid}">
                <td class="prof-name">${escapeHtml(role.name || 'Ruolo')}</td>
                <td class="prof-quals">${quals.length > 0
                  ? quals.map(q => `<span class="gd-chip">${escapeHtml(truncate(q, 50))}</span>`).join('')
                  : '<span style="color:var(--gd-t2);font-size:11px">—</span>'}</td>
              </tr>`;
```

With:
```javascript
              const constraint = constraintsByRoleId[role.id];
              const minPers = constraint?.minimum_personnel;
              const constraintHtml = minPers ? `<span class="gd-badge gd-bb" style="font-size:10px">Min. ${minPers}</span>` : '';
              tableRows += `<tr data-group="${pid}">
                <td class="prof-name">${escapeHtml(role.name || 'Ruolo')} ${constraintHtml}</td>
                <td class="prof-quals">${quals.length > 0
                  ? quals.map(q => `<span class="gd-chip">${escapeHtml(truncate(q, 50))}</span>`).join('')
                  : '<span style="color:var(--gd-t2);font-size:11px">—</span>'}</td>
              </tr>`;
```

And modify the tcard-hd to include the composition badge:

```javascript
          rightHtml += `
            <div class="tcard" style="margin-bottom:12px;">
              <div class="tcard-hd">Gruppo di lavoro (${roles.length} ruoli) ${typeof isMinComposition === 'boolean' ? `<span class="gd-badge ${isMinComposition ? 'gd-br' : 'gd-bb'}" style="font-size:11px;margin-left:8px">${isMinComposition ? 'Composizione minima' : 'Composizione indicativa'}</span>` : ''}</div>
```

- [ ] **Step 2: Verify in browser**

Open gara 267 detail. Roles table should now show "Min. 1" badge next to "Direttore dei Lavori" and a "Composizione indicativa" badge in the header.

- [ ] **Step 3: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat(gare-detail): enrich idoneità with staffing constraints and composition badge"
```

---

### Task 12: Final verification and cleanup

**Files:**
- Review: `assets/js/gare_detail.js`

- [ ] **Step 1: Full browser test**

Open gara 267 detail page and verify all 7 sections:
1. Header: luogo shows entity_type
2. Overview: sopralluogo enriched + info procedurali card
3. Servizi: new section with service card + prestazioni table
4. Importi: 3 corrected stat cards
5. Requisiti: young professional + experience enrichment + summary
6. Economici: enriched chips + formula + RTI
7. Docs: enriched docs table + criteri header + idoneità constraints

- [ ] **Step 2: Test with a gara that has minimal data**

Open a gara with few extractions. Verify graceful degradation:
- Sections with no data are hidden (not shown with "N/D" everywhere)
- Servizi section hidden if no servizi_previsti and no corrispettivi entries
- Qcl columns hidden if citation parsing finds nothing

- [ ] **Step 3: Check print layout**

Open the page and use browser Print Preview. Verify the new `gd-servizi` section appears in print. The existing `gd-sec` print styles should apply automatically.

- [ ] **Step 4: Final commit (only if there are remaining unstaged changes)**

```bash
git add assets/js/gare_detail.js views/gare_dettaglio.php
git commit -m "feat(gare-detail): full enrichment of all extraction data in detail page"
```
