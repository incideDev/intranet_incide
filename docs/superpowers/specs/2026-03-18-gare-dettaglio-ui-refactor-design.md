# Refactor UI gare_dettaglio — Design Spec

**Data:** 2026-03-18
**Obiettivo:** Sostituire la vista piatta a tabella della pagina dettaglio gara con un layout ricco a sezioni: intestazione, timeline, card, tabelle contestuali, req-cards. Ispirato dall'export in `.agent/exports/gare_dettaglio/gare_dettaglio_preview.html`.

---

## 1. Stato attuale

La pagina `gare_dettaglio.php` è una shell HTML vuota. `gare_detail.js` (~1500 righe) carica i dati via AJAX e renderizza tutto client-side in una tabella piatta con tab espandibili per ogni estrazione. Le tabelle (importi, requisiti) sono embedded inline.

**Problema:** Tutte le estrazioni hanno lo stesso trattamento visivo — una riga in tabella — anche quando il dato è binario (sopralluogo: sì/no), una data (scadenza), un importo numerico, o un testo strutturato (requisito professionale).

---

## 2. Matrice tipo → pattern visivo

| Tipo estrazione | Pattern | Container |
|----------------|---------|-----------|
| `oggetto_appalto` | Titolo nell'intestazione | `.int-title` |
| `stazione_appaltante` | Meta cell | `.meta-row` |
| `data_scadenza_gara_appalto` | Meta cell (highlight) + timeline dot | `.meta-row` + `.tl-track` |
| `data_uscita_gara_appalto` | Meta cell + timeline dot | `.meta-row` + `.tl-track` |
| `luogo_provincia_appalto` | Meta cell | `.meta-row` |
| `tipologia_di_gara` | Meta cell | `.meta-row` |
| `tipologia_di_appalto` | Info card | Panoramica |
| `link_portale_stazione_appaltante` | Meta cell (link) | `.meta-row` |
| `sopralluogo_obbligatorio` | Feature card (verde/ambra) | Panoramica |
| `settore_industriale_gara_appalto` | Info card | Panoramica |
| `settore_gara` | Info card | Panoramica |
| `importi_opere_per_categoria_id_opere` | Stat card (somma) + tabella dettaglio | Importi |
| `importi_corrispettivi_categoria_id_opere` | Stat card (somma) + tabella dettaglio | Importi |
| `importi_requisiti_tecnici_categoria_id_opere` | Tabella | Importi |
| `fatturato_globale_n_minimo_anni` | Stat card + tabella | Requisiti economici |
| `requisiti_di_capacita_economica_finanziaria` | Tabella | Requisiti economici |
| `requisiti_tecnico_professionali` | Req-cards | Requisiti |
| `requisiti_idoneita_professionale_gruppo_lavoro` | Req-cards | Documentazione & ruoli |
| `documentazione_richiesta_tecnica` | Tabella | Documentazione & ruoli |
| `criteri_valutazione_offerta_tecnica` | Tabella / info card | Fallback |
| `documenti_di_gara` | Chips | Documentazione & ruoli |

Tipi non nella matrice finiscono nella sezione "Tutti i campi" (tabella classica con expand).

---

## 3. Struttura sezioni

### 3.1 Intestazione (`.intest`)
Card sempre visibile con:
- **Top:** chip PDF + titolo gara (da `oggetto_appalto` o `project_name`) + badge status + badge n_elementi
- **Meta row:** 6 celle (stazione_appaltante, scadenza[highlight], tipologia_gara, luogo, aggiornato, portale)
- **Confidence strip:** barra percentuale + n campi elaborati

Dati da: `jobResults()` → header info + estrazioni scalari.

### 3.2 Panoramica (grid 2 col)
**Sinistra — Timeline:**
- Dot verde: data_uscita (pubblicazione)
- Dot grigio: sopralluogo (data se obbligatorio, "nessuna data" se no)
- Dot ambra: data_scadenza + countdown giorni (calcolato JS)

**Destra — Cards:**
- Sopralluogo card (verde se no, ambra se sì)
- 2x stat card (elementi estratti / campi mancanti)
- Info card tipologia appalto

### 3.3 Importi (grid stat-cards + tabelle)
**4 stat-card in riga:**
- Importo base lavori (somma `amount_eur` da importi_opere)
- Importo corrispettivi (somma da corrispettivi)
- Fatturato minimo richiesto
- Primo ID Opera + categoria

**Sotto: tabelle dettaglio** (solo se presenti):
- Importi opere per categoria (tabella con ID opera, categoria, descrizione, importo)
- Corrispettivi per categoria (tabella)
- Requisiti tecnici per categoria (tabella)

Le tabelle mantengono esattamente la struttura attuale (headers + rows da `ext_table_cells`).

### 3.4 Requisiti tecnico-professionali (req-cards)
Per ogni requisito da `ext_req_roles` e `requisiti_tecnico_professionali`:
- Card con: nome, badge obbligatorio/facoltativo, descrizione, categoria
- Card dashed per estrazioni incomplete

### 3.5 Requisiti economico-finanziari
- Fatturato globale: stat card + tabella dettagli
- Capacità economica: tabella requisiti

### 3.6 Documentazione e ruoli (grid 2 col)
- Sinistra: documentazione richiesta (tabella da `ext_req_docs`)
- Destra: idoneità professionale (req-cards per ruolo)

### 3.7 Tutti i campi — vista tabella (fallback)
Tabella classica con expand inline per citazioni. Contiene SOLO i campi non già visualizzati nelle sezioni sopra. Pulsante "Dettagli" → espande snippet sorgente PDF.

### 3.8 Action bar
Esporta CSV · Stampa · Ri-estrai · Elimina gara

---

## 4. File da modificare

| File | Azione | Descrizione |
|------|--------|-------------|
| `views/gare_dettaglio.php` | Riscrivere | HTML shell con container per tutte le sezioni |
| `assets/js/gare_detail.js` | Riscrivere rendering | Nuove render functions per ogni sezione/pattern |
| `assets/css/gare.css` | Aggiungere | Stili per intestazione, timeline, cards, req-cards, stat-cards |

### 4.1 gare_dettaglio.php

HTML statico con container vuoti. JS li popola:

```html
<div class="main-container">
  <div id="gare-detail-root" data-job-id="<?= (int)($_GET['id'] ?? 0) ?>">
    <!-- Intestazione -->
    <div id="gd-header" class="intest"></div>

    <!-- Panoramica -->
    <div id="gd-overview" class="sec"></div>

    <!-- Importi -->
    <div id="gd-importi" class="sec"></div>

    <!-- Requisiti tecnico-professionali -->
    <div id="gd-requisiti" class="sec"></div>

    <!-- Requisiti economici -->
    <div id="gd-economici" class="sec"></div>

    <!-- Documentazione e ruoli -->
    <div id="gd-docs-ruoli" class="sec"></div>

    <!-- Tutti i campi (tabella fallback) -->
    <div id="gd-all-fields" class="sec"></div>

    <!-- Actions -->
    <div id="gd-actions" class="actions"></div>
  </div>
</div>
```

### 4.2 gare_detail.js — Architettura rendering

Un dispatcher principale che prende i dati e li distribuisce alle sezioni:

```
loadGaraDetail(jobId)
  → fetch jobResults + getEstrazioniGara
  → classifyExtractions(items) → raggruppa per sezione
  → renderHeader(data, headerItems)
  → renderOverview(data, overviewItems)
  → renderImporti(importiItems)
  → renderRequisiti(requisitiItems)
  → renderEconomici(economiciItems)
  → renderDocsRuoli(docsItems, ruoliItems)
  → renderAllFields(remainingItems)  // solo quelli non già mostrati
  → renderActions(jobId)
```

`classifyExtractions()` usa la matrice tipo→sezione per smistare. Ogni item finisce in UNA sola sezione (no duplicati).

### 4.3 CSS — Componenti dall'export preview

I pattern CSS del preview vengono portati in `gare.css` con prefisso `gd-` per evitare collisioni:

- `.intest`, `.int-top`, `.int-left`, `.int-right` → intestazione
- `.meta-row`, `.mc`, `.mc.hi` → meta cells
- `.conf-strip`, `.conf-bar`, `.conf-fill` → confidence
- `.timeline`, `.tl-track`, `.tl-item`, `.tl-dot` → timeline
- `.sop-card`, `.sop-icon` → sopralluogo
- `.stat`, `.stat-v`, `.stat-l` → stat card
- `.imp-card`, `.imp-val` → importi card
- `.req-card`, `.req-card-head`, `.req-desc`, `.req-obbl` → requisiti card
- `.tcard`, `.tcard-hd` → tabella classica
- `.dr`, `.er`, `.er-inner`, `.dbtn` → data row + expand row
- `.chips`, `.chip` → chips documentazione
- `.actions`, `.abtn` → action bar

Colori: usano le CSS variables del progetto esistenti dove possibile, con fallback ai colori dell'export preview.

---

## 5. Dati — nessun cambio backend

Il backend non cambia. Usiamo:
- `ExtractionService::jobResults($jobId)` → dati completi con estrazioni, tabelle, citazioni
- `ExtractionService::getEstrazioniGara($jobId)` → estrazioni formattate per display
- `GareService::getGaraMetadata($jobId)` → metadati gara (status, date, etc.)

I dati vengono classificati client-side in base al `type_code` per popolare le sezioni.

---

## 6. Regole

- **Zero duplicazioni:** ogni estrazione appare in UNA sola sezione
- **Tabelle mantenute** per tipi tabellari (importi, corrispettivi, requisiti tecnici, documentazione)
- **Fallback:** tipi sconosciuti finiscono nella tabella "Tutti i campi"
- **Print layout:** la sezione stampa esistente (`.print-only`) viene aggiornata per riflettere la nuova struttura
- **Riuso CSS:** le classi dell'export preview vengono adattate, non duplicate
