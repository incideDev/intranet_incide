# Importi Tables — Full-Stack Fix

**Date:** 2026-03-23
**Status:** Approved
**Scope:** Fix missing columns in "Importi opere per categoria" and "Requisiti tecnici per categoria" tables by propagating all relevant API fields through DB → Normalizer → ExtractionService → Frontend.

---

## Context

The AI extraction API returns rich structured data for three importi-related extraction types. Currently, several fields present in the API response are lost during normalization and never reach the UI.

**Reference samples:**
- `.agent/samples/gara_267_results.json`
- `.agent/samples/Gara_Castelvecchio_Stupinigi.json`
- `.agent/samples/ex_convento_gagliano.json`

**Files affected:**
- `services/AIextraction/GaraDataNormalizer.php` — normalizer
- `services/ExtractionService.php` — table builder
- `assets/js/gare_detail.js` — frontend renderer (bug fix only)
- DB migration (inline SQL)

**No frontend view changes needed.** The `renderImporti()` renderer already reads from `item.table` produced by ExtractionService and already builds chips client-side from the raw `value_json`.

---

## 1. API → DB Mapping

### 1.1 `importi_requisiti_tecnici_categoria_id_opere`

API structure:
```json
{
  "multiplier_coefficient": "0,4 volte",
  "lookback_period_years": 10,
  "minimum_service_count": 1,
  "alternative_fulfillment": {
    "min_services": 1,
    "condition_text": "In luogo dei due servizi..."
  },
  "requirements": [
    {
      "id_opera": "E.20",
      "category_name": "Edilizia",
      "description": "Edifici e manufatti...",
      "base_value_eur": 1150000,
      "minimum_amount_eur": 460000,
      "complexity": 1.55,
      "legal_correspondence": "I/e"
    }
  ]
}
```

**Table: `gar_gara_requisiti_tecnici_categoria`**

Columns to add:
| Colonna | Tipo | Sorgente API | Note |
|---------|------|-------------|------|
| `grado_complessita` | `DECIMAL(5,2)` | `requirements[].complexity` | Nullable |
| `importo_lavori_eur` | `DECIMAL(15,2)` | `requirements[].base_value_eur` | Nullable |
| `corrispondenza_dm` | `VARCHAR(20)` | `requirements[].legal_correspondence` | Nullable |
| `moltiplicatore` | `VARCHAR(50)` | top-level `multiplier_coefficient` | Denormalizzato per riga |
| `anni_riferimento` | `TINYINT UNSIGNED` | top-level `lookback_period_years` | Denormalizzato per riga |
| `min_servizi` | `TINYINT UNSIGNED` | top-level `minimum_service_count` | Denormalizzato per riga |
| `fulfillment_alternativo` | `TEXT` | `alternative_fulfillment.condition_text` | Nullable, denorm. per riga |

Columns already existing (no change):
- `identificazione_opera` ← `requirements[].id_opera`
- `categoria` ← `requirements[].category_name`
- `importo_minimo_eur` ← `requirements[].minimum_amount_eur`

**Denormalization rationale:** The top-level fields (`multiplier_coefficient`, `lookback_period_years`, etc.) apply to the entire gara extraction, not to individual rows. Denormalizing them into each row avoids a separate meta table while keeping the ExtractionService query simple (single table, no join).

### 1.2 `importi_opere_per_categoria_id_opere` (extraction type) → table `gar_gara_importi_opere`

API structure:
```json
{
  "entries": [
    {
      "id_opera_raw": "EDILIZIA E.20",
      "id_opera_normalized": "E.20",
      "category_name": "Edilizia",
      "work_types": ["Nuova costruzione", "Restauro"],
      "amount_eur": 1500000
    }
  ]
}
```

Column to add to `gar_gara_importi_opere`:
| Colonna | Tipo | Sorgente API | Note |
|---------|------|-------------|------|
| `tipo_lavori` | `TEXT` | `entries[].work_types` joined con `, ` | Nullable |

---

## 2. GaraDataNormalizer.php Changes

### 2.1 `processRequisitiTecniciCategoria()`

This method uses a two-step accumulator:
- **STEP 1** — processes `importi_requisiti_tecnici_categoria_id_opere`: extracts per-row fields and top-level fields, builds accumulator entries
- **STEP 2** — processes `requisiti_di_capacita_economica_finanziaria`: merges additional entries by `id_opera`
- **STEP 3/4** — executes INSERT statements for all accumulated entries

The top-level fields (`multiplier_coefficient`, `lookback_period_years`, `minimum_service_count`, `alternative_fulfillment`) exist only in the STEP 1 extraction. They must be extracted during STEP 1 and stored in variables that persist into STEP 4 (the INSERT loop), so they can be written to every inserted row.

**During STEP 1**, extract top-level fields:
```php
$moltiplicatore = $data['multiplier_coefficient'] ?? null;
$anniRiferimento = isset($data['lookback_period_years']) ? (int)$data['lookback_period_years'] : null;
$minServizi = isset($data['minimum_service_count']) ? (int)$data['minimum_service_count'] : null;
$fulfillmentAlternativo = $data['alternative_fulfillment']['condition_text'] ?? null;
```

**During STEP 1**, add per-row fields to the accumulator entry for each `requirements[]` item:
```php
'grado_complessita'  => isset($req['complexity']) ? (float)$req['complexity'] : null,
'importo_lavori_eur' => isset($req['base_value_eur']) ? (float)$req['base_value_eur'] : null,
'corrispondenza_dm'  => $req['legal_correspondence'] ?? null,
```

**During STEP 4 (INSERT loop)**, use the top-level variables and per-row accumulator values. The INSERT column list and `ON DUPLICATE KEY UPDATE` clause must include all 7 new columns:

```sql
INSERT INTO gar_gara_requisiti_tecnici_categoria
  (..., grado_complessita, importo_lavori_eur, corrispondenza_dm,
   moltiplicatore, anni_riferimento, min_servizi, fulfillment_alternativo)
VALUES
  (?, ?, ?, ?, ?, ?, ?, ...)
ON DUPLICATE KEY UPDATE
  grado_complessita = VALUES(grado_complessita),
  importo_lavori_eur = VALUES(importo_lavori_eur),
  corrispondenza_dm = VALUES(corrispondenza_dm),
  moltiplicatore = VALUES(moltiplicatore),
  anni_riferimento = VALUES(anni_riferimento),
  min_servizi = VALUES(min_servizi),
  fulfillment_alternativo = VALUES(fulfillment_alternativo),
  ... (existing UPDATE fields unchanged)
```

### 2.2 `normalizeImportiOpere()`

For each `entries[]` entry, add `tipo_lavori` to the INSERT values:
```php
'tipo_lavori' => !empty($entry['work_types'])
    ? implode(', ', $entry['work_types'])
    : null,
```

The INSERT column list and `ON DUPLICATE KEY UPDATE` clause must both include `tipo_lavori`:
```sql
ON DUPLICATE KEY UPDATE
  tipo_lavori = VALUES(tipo_lavori),
  ... (existing UPDATE fields unchanged)
```

---

## 3. ExtractionService.php Changes

### 3.1 `buildEstrazioneFromNormalizedRequisitiTecnici()`

**Current headers:** `['ID Opera', 'Categoria', 'Descrizione', 'Importo minimo']`

**New headers:** `['ID Opera', 'Categoria', 'Descrizione', 'Importo lavori', 'Importo minimo', 'Complessità', 'Corrisp. DM']`

**New columns per row** (from DB):
- `importo_lavori_eur` — formatted as currency (e.g., `€ 1.150.000,00`), show `—` if null
- `grado_complessita` — formatted as decimal (e.g., `1.55`), show `—` if null
- `corrispondenza_dm` — string (e.g., `I/e`), show `—` if null

**Note on chips:** The chip row above the requisiti tecnici table (showing `moltiplicatore`, `anni_riferimento`, `min_servizi`) is rendered **client-side** in `renderImporti()` by reading from the raw `value_json` (variable `reqJson`). These top-level fields already exist in `value_json` as returned by the API. No change to the chip rendering path is needed — ExtractionService does NOT need to produce a `chips` field. The only chip fix needed is in the frontend (see section 4).

### 3.2 `buildEstrazioneFromNormalizedImportiOpere()`

Add `tipo_lavori` column after `categoria`:

**Current headers:** `['ID opere', 'Categoria', 'Descrizione', 'Grado di complessità', 'Importo del corrispettivo']`

**New headers:** `['ID opere', 'Categoria', 'Tipo lavori', 'Descrizione', 'Grado di complessità', 'Importo del corrispettivo']`

Show `—` if `tipo_lavori` is null.

---

## 4. Frontend gare_detail.js — Bug Fix

**File:** `assets/js/gare_detail.js`
**Function:** `renderImporti()`

**Bug:** Chip for `multiplier_coefficient` appends literal `x` suffix:
```javascript
// WRONG — outputs "0,4 voltex"
chips.push(`Coefficiente moltiplicatore: ${reqJson.multiplier_coefficient}x`);
```

**Fix:** Remove the `x` suffix — the API value already contains the unit (e.g., `"0,4 volte"`):
```javascript
chips.push(`Coefficiente moltiplicatore: ${reqJson.multiplier_coefficient}`);
```

**All other chips** (`lookback_period_years`, `minimum_service_count`, `alternative_fulfillment`) also read from `reqJson` (the raw `value_json`). These top-level API fields are already present in `value_json` and will continue to be read from there — no change needed beyond the `x` suffix fix.

---

## 5. DB Migration

```sql
-- gar_gara_requisiti_tecnici_categoria
ALTER TABLE gar_gara_requisiti_tecnici_categoria
    ADD COLUMN grado_complessita       DECIMAL(5,2)       NULL AFTER importo_minimo_eur,
    ADD COLUMN importo_lavori_eur      DECIMAL(15,2)      NULL AFTER grado_complessita,
    ADD COLUMN corrispondenza_dm       VARCHAR(20)        NULL AFTER importo_lavori_eur,
    ADD COLUMN moltiplicatore          VARCHAR(50)        NULL AFTER corrispondenza_dm,
    ADD COLUMN anni_riferimento        TINYINT UNSIGNED   NULL AFTER moltiplicatore,
    ADD COLUMN min_servizi             TINYINT UNSIGNED   NULL AFTER anni_riferimento,
    ADD COLUMN fulfillment_alternativo TEXT               NULL AFTER min_servizi;

-- gar_gara_importi_opere
ALTER TABLE gar_gara_importi_opere
    ADD COLUMN tipo_lavori TEXT NULL AFTER categoria;
```

Migration must be applied before deploying the normalizer changes. Existing rows will have NULL in the new columns — acceptable since old gare are not re-processed.

---

## 6. Graceful Degradation

- All new columns are nullable. If a field is absent in the API response, `null` is stored and `—` is shown in the table.
- If `entries[]` or `requirements[]` is empty, ExtractionService returns no table (same as current behavior).
- Old gare (pre-migration) retain NULL in new columns and display `—` — no visual regression.

---

## 7. Out of Scope

- No re-sync mechanism. Re-processing a gara requires re-uploading the PDF (full normalization overwrites all rows).
- `importi_corrispettivi_categoria_id_opere`: no DB changes needed. Qcl codes and complexity grade for this type are parsed client-side from citation text via `parseQclFromCitations()` (already implemented).
- No new AJAX endpoints.
- No view file changes.
