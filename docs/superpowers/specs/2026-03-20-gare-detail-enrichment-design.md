# Gare Detail Page — Full Enrichment Design

**Date:** 2026-03-20
**Status:** Approved
**Scope:** Enrich the gare detail page (`gare_detail.js`) with all structured data from the AI extraction API that is currently not displayed.

## Context

The AI extraction API returns 19 extraction types per gara. The detail page currently shows most data but loses many structured sub-fields. This spec covers displaying ALL useful information, correcting wrong labels, and adding a new "Servizi e Prestazioni" section.

**Files affected:**
- `assets/js/gare_detail.js` — main rendering (all section renderers)
- `views/gare_dettaglio.php` — add new `gd-servizi` container div
- `assets/css/gare.css` — styles for new section (loaded by `gare_dettaglio.php` at line 31)

**Reference data:** `.agent/samples/gara_267_results.json`

---

## 1. HEADER — Correzioni

### 1.1 Luogo arricchito
**Current:** `"Santo Bambino, Catania (CT)"` — only `entity_name + city (district)`
**New:** `"Ex Presidio Ospedaliero Santo Bambino, Catania (CT)"` — prepend `entity_type` when available.

**Logic:**
```
location.entity_type + " " + location.entity_name + ", " + location.city + " (" + location.district + ")"
```
- If `country` is not "Italia" or `nuts_code` is present, append them.
- Graceful fallback: if `entity_type` is empty, behave as before.

**Function:** `renderHeader()` — modify the luogo construction block (~line 997-1004).

---

## 2. OVERVIEW — Arricchimenti panoramica

### 2.1 Sopralluogo arricchito
**Current:** Status + deadline date + booking platform link.
**Add:**
- `booking_instructions` — text paragraph below the platform link
- `deadline.notes` — badge/tag (e.g., "Termini tassativi")
- `booking_contacts[]` — list of contacts if not empty

**Function:** `renderOverview()` — modify sopralluogo card block (~line 1204-1225).

### 2.2 Info procedurali — new card
**Position:** Below the "Tipologia appalto" info-card in the right column.
**Content:**
- **Inversione procedimentale:** Sì/No — search `tipologia_di_gara` (SECTION_MAP: `'header'`) `citations[].text[]` for keyword "inversione procedimentale". If found, show "Sì"; if not found in any citation, show "No".
- **Metodo di aggiudicazione:** e.g., "Aggregativo-compensatore" — search `criteri_valutazione_offerta_tecnica` (SECTION_MAP: `'docs_ruoli'`) `citations[].text[]` for keyword "aggregativo" or "metodo". Extract the sentence fragment.
- **Criterio di aggiudicazione:** e.g., "Offerta economicamente più vantaggiosa" — search `tipologia_di_gara` `citations[].text[]` for keyword "criterio" or "offerta economicamente". Extract the sentence fragment.

**Detection strategy:** All three use keyword search in `citations[].text[]` arrays (not `chain_of_thought`). Use `byType()` to access items already classified in other sections — no SECTION_MAP changes needed.

**Style:** Uses existing `info-card` pattern (`.ic-l` label + `.ic-v` value).

**Function:** `renderOverview()` — add new card after tipologiaAppalto block (~line 1238-1247).

---

## 3. NEW SECTION: "Servizi e Prestazioni richieste"

### 3.1 Position and structure
- New section between Overview and Importi
- **View:** Insert `<div id="gd-servizi" style="display:none"></div>` in `gare_dettaglio.php` between `gd-overview` and `gd-importi` containers
- **Orchestration:** Call `renderServizi(byType)` in the main render pipeline (currently ~line 935-941 of `gare_detail.js`) between `renderOverview()` and `renderImporti()` calls
- No SECTION_MAP changes needed — `renderServizi` uses `byType()` to access items already classified into `'header'` (`oggetto_appalto`) and `'importi'` (`importi_corrispettivi_categoria_id_opere`)
- New renderer function: `renderServizi(byType)`

### 3.2 Card servizi richiesti
One card per item in `oggetto_appalto.servizi_previsti[]`:
- **Label** (e.g., "Direzione Lavori")
- **Badge** obbligatorio/opzionale (from `is_optional`)
- **Importo** (e.g., "€ 253.568,93 + IVA") from `amount_raw` or formatted `amount_eur`
- **Riferimento normativo** (e.g., "artt. 114 e 115 del D.lgs. 36/2023...") from `legal_reference`
- **Note** (e.g., "quota fissa 65%, variabile 35%") from `notes`

**Style:** `info-card` with badges.

### 3.3 Tabella prestazioni per categoria
Grid with columns parsed from `importi_corrispettivi_categoria_id_opere`:

| Categoria | ID Opera | Codici Prestazione | Grado Complessità | Importo |
|-----------|----------|-------------------|-------------------|---------|

- `entries[]` provides: `category_id`, `category_name`, `amount_eur`
- `citations[].text[]` provides the raw lines (e.g., "Edilizia E.20 Qcl.01 – Qcl.02 – Qcl.09 0.95 € 133.341,63")
- **Parsing strategy:** For each entry, match by `category_id` against the citation text lines to extract Qcl codes and complexity grade. See "Parsing strategy" section below for canonical regex.
- Footer row with total amount

**Style:** `table--modern` consistent with existing tables.

---

## 4. IMPORTI — Correzione label e ristrutturazione

### 4.1 Stat cards: from 4 to 3

**Card 1 — "Importo a base d'appalto"** (was "Importo a base d'asta")
- Source: `oggetto_appalto.servizi_previsti[].amount_eur` sum (primary), fall back to opere sum. **Note:** This intentionally inverts the current fallback priority (current: opereSum primary, servizi_previsti fallback). Rationale: `servizi_previsti` gives the official tender amount from the disciplinare, while `opereSum` is works-level which may not exist for service tenders.
- Sub-label: "corrispettivo a corpo" (from `tipologia_di_appalto.answer` if it contains "a corpo")

**Card 2 — "Corrispettivi professionali"** (was "Corrispettivo")
- Source: `importi_corrispettivi` entries sum (unchanged logic)
- Sub-label: "Somma per categoria"

**Card 3 — "Fatturato minimo richiesto"** (was "Fatturato minimo")
- Source: unchanged
- Sub-label: `derivation_formula` (e.g., "= importo a base d'asta")
- Additional small text: `service_scope_description` (e.g., "servizi di ingegneria e architettura")

**Removed:** "Categoria ID Opera" card — redundant with new Servizi section. The `firstIdOpera` computation block (~line 1313-1331) can also be removed as dead code.

**Function:** `renderImporti()` — rewrite stat cards block (~line 1334-1357).

### 4.2 Tables enriched
- "Corrispettivi per categoria" table: add **Codici Prestazione** and **Grado Complessità** columns (same parsing as section 3.3)
- Other tables unchanged

**Function:** `renderImporti()` — modify table rendering or the `buildExtractionTabs` output.

---

## 5. REQUISITI — Arricchimenti tecnico-professionali

### 5.1 Young professional details
When `requirement_type === 'young_professional'`, render additional fields from `young_professional_details`:
- `academic_requirement`: "laureato abilitato"
- `maximum_registration_years`: "iscritto da meno di N anni"
- `minimum_count`: "almeno N"
- `must_be_designer`: Sì/No
- `applies_to_organization_types[]`: badges (e.g., "Raggruppamenti temporanei", "Consorzi")

### 5.2 Experience details enriched
When `requirement_type === 'experience'`, add:
- `analogy_criteria`: descriptive text for "analogous services"
- `min_project_count`: if present, "Minimo N progetti"
- `time_period_years` + `reference_date` in a visible label (e.g., "Ultimi 10 anni dalla data di pubblicazione del bando")

### 5.3 Requirements summary
At the bottom of the section, a compact summary from `requirements_summary`:
- Total requirements count
- Mandatory count
- With legal reference count

**Style:** small badges/pills row.

**Function:** `renderRequisiti()` — enrich the per-requirement card rendering.

---

## 6. ECONOMICI — Arricchimenti requisiti economico-finanziari

### 6.1 Fatturato globale enriched
Add to the existing fatturato card:
- `derivation_formula`: e.g., "pari all'importo a base d'asta"
- `service_scope_description`: e.g., "servizi di ingegneria e di architettura" (badge)
- `threshold_direction_phrase`: e.g., "pari a" / "non inferiore a"
- `calculation_method`: "cumulative_total" → "Totale cumulativo"
- `amount_basis`: "derived_from_contract" → note indicating derivation

### 6.2 Capacità economico-finanziaria enriched
Add to existing capacity cards:
- `calculation_rule`: full descriptive text
- `formula`: multiplier + base_reference (e.g., "1.0x valore contratto")
- **RTI rules block:** dedicated sub-section when `rti_allocation` is present:
  - `distribution_rules`: text
  - `lead_firm_minimum_percentage`: if present
  - `minimum_per_member_percentage`: if present

**Function:** `renderEconomici()` — enrich card rendering.

---

## 7. DOCS E RUOLI — Arricchimenti documentazione e ruoli

### 7.1 Documentazione richiesta tecnica — table enriched
Add columns/info:
- **Template** column: `template_reference.template_id` as badge (e.g., "Modello C")
- **Condizione** column: `conditional_logic.description` — badge giallo "Condizionale" + text when `requirement_status === "condizionale"`
- Below each row (expandable):
  - `document_cardinality`: e.g., "Max 3 servizi, per ciascuno max 6 schede A4"
  - `required_components[]`: e.g., "Obbligatorio: organigramma del gruppo di lavoro"
  - `notes`: detailed notes
  - `formatting_requirements.page_count_exclusions[]`: e.g., "Non contano: testate, indici"

### 7.2 Criteri valutazione offerta tecnica — enriched
Add as header text ABOVE the existing criteria tree (which already shows `total_max_points` and subcriteria):
- **Metodo di aggiudicazione**: "Aggregativo-compensatore" — search `criteri_valutazione_offerta_tecnica` `citations[].text[]` for keyword "aggregativo" or "metodo"
- **Punteggio totale**: "70/100 tecnica — 30/100 economica" — from `citations[].text[]` matching "Offerta tecnica" + "Offerta economica" with points, or hardcoded from the `total_max_points` field + complement to 100
- **Re-parametrization note**: search `citations[].text[]` for keyword "riparametr" — if found, show the matched citation text as a note. If not found, skip.

### 7.3 Requisiti idoneità professionale — enriched
Add to role cards:
- `role_staffing_constraints.minimum_personnel`: "Personale minimo: N"
- `role_staffing_constraints.incompatible_role_ids`: incompatibility note
- `is_minimum_composition`: badge "Composizione minima" / "Composizione indicativa"

**Function:** `renderDocsRuoli()` — enrich each sub-renderer.

---

## Data flow

No backend changes needed. All data is already in `value_json` of `ext_extractions`. The frontend receives it via `getEstrazioniGara()` and accesses it via `getJson(item)`.

The new section `gd-servizi` is a **cross-extraction renderer** — it reads from two extraction types (`oggetto_appalto` and `importi_corrispettivi_categoria_id_opere`) to compose a unified view. This is the same pattern already used by `renderImporti()` which reads from multiple byType() calls.

## Parsing strategy for Qcl codes and complexity grades

The API returns Qcl codes and complexity grades only in citation text, not as structured fields. Parsing approach:

1. Get `citations[].text[]` from `importi_corrispettivi_categoria_id_opere`
2. Each line follows the pattern: `"Categoria ID Qcl.XX – Qcl.YY Grade € Amount"`
3. Canonical regex: `/^(.+?)\s+((?:[A-Z]+\.)\d+)\s+(Qcl\.\d+(?:\s+[–\-]\s+Qcl\.\d+)*)\s+([\d.,]+)\s+€\s*([\d.,]+)/`
   - Group 1: category name, Group 2: ID opera, Group 3: Qcl codes, Group 4: complexity grade, Group 5: amount
   - Note: separator between Qcl codes uses `\s+[–\-]\s+` (space + dash/en-dash + space) to match both ` – ` and ` - `
4. Match each parsed line to `entries[]` by `category_id`
5. Fallback: if parsing fails, show entries without Qcl/grade columns (graceful degradation)

## Print layout

The view includes a print layout section (`gare-print-root`). The new `gd-servizi` section should be included in print output. Ensure the print CSS rule that shows/hides sections also covers `#gd-servizi`. No special print formatting needed — the existing print styles for `gd-sec` sections will apply.

## Graceful degradation

Every enrichment is additive and conditional:
- If a sub-field is null/empty/missing, skip it (don't show "N/D" for every field)
- If citation parsing fails for Qcl codes, show table without those columns
- If `servizi_previsti` is empty, don't render the services cards (section shows only table if available)
- If no data at all for the new section, hide `gd-servizi` entirely (same pattern as other sections)
