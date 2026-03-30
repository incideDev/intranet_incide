# Importi Tables — Full-Stack Fix Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add missing API fields (complexity, base value, legal correspondence, work types, and top-level meta fields) to the importi tables, propagating them through DB → GaraDataNormalizer → ExtractionService → frontend chip bug fix.

**Architecture:** DB migration adds nullable columns to two tables. GaraDataNormalizer writes the new fields during PDF extraction. ExtractionService reads the new DB columns and adds them as extra columns in `item.table`. The frontend chip rendering already reads from `value_json` (raw API JSON) — only the `x` suffix bug needs fixing there.

**Tech Stack:** MySQL (ALTER TABLE, ON DUPLICATE KEY UPDATE), PHP (PDO, prepared statements), Vanilla JS

**Spec:** `docs/superpowers/specs/2026-03-23-importi-tables-fullstack-fix.md`

---

## Files

| File | Change |
|------|--------|
| DB (via phpMyAdmin or direct SQL) | ALTER TABLE — 2 tables |
| `services/AIextraction/GaraDataNormalizer.php` | Modify `normalizeImportiOpere()` + `processRequisitiTecniciCategoria()` |
| `services/ExtractionService.php` | Modify `buildEstrazioneFromNormalizedImportiOpere()` + `buildEstrazioneFromNormalizedRequisitiTecnici()` |
| `assets/js/gare_detail.js` | Fix chip bug in `renderImporti()` (1 line) |

---

## Task 1: DB Migration

**Files:**
- Modify: DB schema (run SQL directly)

- [ ] **Step 1: Run migration for `gar_gara_requisiti_tecnici_categoria`**

```sql
ALTER TABLE gar_gara_requisiti_tecnici_categoria
    ADD COLUMN grado_complessita       DECIMAL(5,2)       NULL AFTER importo_minimo_eur,
    ADD COLUMN importo_lavori_eur      DECIMAL(15,2)      NULL AFTER grado_complessita,
    ADD COLUMN corrispondenza_dm       VARCHAR(20)        NULL AFTER importo_lavori_eur,
    ADD COLUMN moltiplicatore          VARCHAR(50)        NULL AFTER corrispondenza_dm,
    ADD COLUMN anni_riferimento        TINYINT UNSIGNED   NULL AFTER moltiplicatore,
    ADD COLUMN min_servizi             TINYINT UNSIGNED   NULL AFTER anni_riferimento,
    ADD COLUMN fulfillment_alternativo TEXT               NULL AFTER min_servizi;
```

Expected: "7 row(s) affected" in phpMyAdmin, or `Query OK, ... rows affected` in MySQL CLI.

- [ ] **Step 2: Run migration for `gar_gara_importi_opere`**

```sql
ALTER TABLE gar_gara_importi_opere
    ADD COLUMN tipo_lavori TEXT NULL AFTER categoria;
```

Expected: "1 row(s) affected".

- [ ] **Step 3: Verify columns exist**

```sql
SHOW COLUMNS FROM gar_gara_requisiti_tecnici_categoria;
SHOW COLUMNS FROM gar_gara_importi_opere;
```

Expected: both `grado_complessita` ... `fulfillment_alternativo` visible in first result; `tipo_lavori` visible in second.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat(importi-tables): add DB columns for complexity, work_types, meta fields"
```

---

## Task 2: Normalizer — `normalizeImportiOpere()`

**File:** `services/AIextraction/GaraDataNormalizer.php`
**Lines to modify:** ~506-534 (the INSERT block inside `normalizeImportiOpere()`)

Current INSERT column list (line 508):
```
(job_id, extraction_id, id_opera, id_opera_raw, gar_opera_id, categoria, identificazione_opera, complessita_dm50, importo_lavori_eur, importo_lavori_raw, note)
```

Current execute block (line 521-534):
```php
$stmt->execute([
    ':job_id' => $jobId,
    ':extraction_id' => $extractionId,
    ':id_opera' => $idOperaStr ?: null,
    ':id_opera_raw' => $idOperaRaw ?: null,
    ':gar_opera_id' => $garOperaId,
    ':categoria' => $categoria,
    ':identificazione_opera' => $identificazioneOpera,
    ':complessita_dm50' => $complessitaDm50,
    ':importo_lavori_eur' => $importoLavoriEur,
    ':importo_lavori_raw' => $importoLavoriRaw,
    ':note' => null,
]);
```

- [ ] **Step 1: Add `tipo_lavori` extraction before the INSERT block**

At line ~504 (before `try {`), after the `$importoLavoriRaw` block, add:

```php
$tipoLavori = null;
if (!empty($entry['work_types']) && is_array($entry['work_types'])) {
    $tipoLavori = implode(', ', $entry['work_types']);
}
```

- [ ] **Step 2: Update the INSERT SQL**

Replace the `$sql = "INSERT INTO gar_gara_importi_opere` block (lines 507-519) with:

```php
$sql = "INSERT INTO gar_gara_importi_opere
        (job_id, extraction_id, id_opera, id_opera_raw, gar_opera_id, categoria, tipo_lavori, identificazione_opera, complessita_dm50, importo_lavori_eur, importo_lavori_raw, note)
        VALUES (:job_id, :extraction_id, :id_opera, :id_opera_raw, :gar_opera_id, :categoria, :tipo_lavori, :identificazione_opera, :complessita_dm50, :importo_lavori_eur, :importo_lavori_raw, :note)
        ON DUPLICATE KEY UPDATE
        extraction_id = VALUES(extraction_id),
        id_opera_raw = VALUES(id_opera_raw),
        gar_opera_id = VALUES(gar_opera_id),
        categoria = VALUES(categoria),
        tipo_lavori = VALUES(tipo_lavori),
        identificazione_opera = VALUES(identificazione_opera),
        complessita_dm50 = VALUES(complessita_dm50),
        importo_lavori_eur = VALUES(importo_lavori_eur),
        importo_lavori_raw = VALUES(importo_lavori_raw),
        note = VALUES(note)";
```

- [ ] **Step 3: Add `:tipo_lavori` to the execute array**

In the `$stmt->execute([...])` block, add:

```php
':tipo_lavori' => $tipoLavori,
```

after `':categoria' => $categoria,`.

- [ ] **Step 4: Commit**

```bash
git add services/AIextraction/GaraDataNormalizer.php
git commit -m "feat(normalizer): save work_types as tipo_lavori in gar_gara_importi_opere"
```

---

## Task 3: Normalizer — `processRequisitiTecniciCategoria()`

**File:** `services/AIextraction/GaraDataNormalizer.php`
**Lines to modify:** ~1049-1234

The method has 4 steps:
- STEP 1 (~line 1050): reads from `importi_requisiti_tecnici_categoria_id_opere`
- STEP 2 (~line 1098): reads from `requisiti_di_capacita_economica_finanziaria`
- STEP 3 (~line 1175): batch DM50 lookup
- STEP 4 (~line 1179): INSERT loop

- [ ] **Step 1: Extract top-level fields during STEP 1**

In the STEP 1 block, AFTER `$valueJson = $ext['value_json'] ?? null;` and BEFORE the `foreach ($valueJson['requirements'] as $req)` loop (around line 1054-1059), add variable extraction from the top-level JSON:

```php
// Top-level meta fields (only in importi_requisiti_tecnici)
$moltiplicatore = $valueJson['multiplier_coefficient'] ?? null;
$anniRiferimento = isset($valueJson['lookback_period_years'])
    ? (int)$valueJson['lookback_period_years']
    : null;
$minServizi = isset($valueJson['minimum_service_count'])
    ? (int)$valueJson['minimum_service_count']
    : null;
$fulfillmentAlternativo = $valueJson['alternative_fulfillment']['condition_text'] ?? null;
```

These variables are declared inside the `foreach ($extractions as $ext)` loop. They persist into STEP 4 (the INSERT loop is in the same method scope via `$requisitiPerCategoria`). Since there is typically one extraction of this type per job, this is safe.

**Important:** Also initialize these variables to `null` before the STEP 1 block (around line 1047, after the `$requisitiPerCategoria = [];` line), so they exist in STEP 4 even if STEP 1 finds no data:

```php
// Meta fields from importi_requisiti_tecnici (top-level, denormalized per row)
$moltiplicatore = null;
$anniRiferimento = null;
$minServizi = null;
$fulfillmentAlternativo = null;
```

- [ ] **Step 2: Add per-row fields to the accumulator in STEP 1**

Inside the `foreach ($valueJson['requirements'] as $req)` loop, inside the `if (!isset($requisitiPerCategoria[$idOperaStr]))` block, add new fields to the accumulator initialization:

```php
$requisitiPerCategoria[$idOperaStr] = [
    'extraction_id'      => $extractionId,
    'id_opera_raw'       => $req['id_opera_raw'] ?? $idOperaStr,
    'importo_minimo_eur' => null,
    'importo_minimo_raw' => null,
    'importo_minimo_punta_eur'  => null,
    'importo_minimo_punta_raw'  => null,
    // New fields
    'grado_complessita'  => null,
    'importo_lavori_eur' => null,
    'corrispondenza_dm'  => null,
];
```

After the existing `importo_minimo_eur` extraction block (around line 1093), add extraction of per-row fields:

```php
// New per-row fields
if (isset($req['complexity']) && is_numeric($req['complexity'])) {
    $requisitiPerCategoria[$idOperaStr]['grado_complessita'] = (float)$req['complexity'];
}
if (isset($req['base_value_eur']) && is_numeric($req['base_value_eur'])) {
    $requisitiPerCategoria[$idOperaStr]['importo_lavori_eur'] = (float)$req['base_value_eur'];
}
if (isset($req['legal_correspondence'])) {
    $requisitiPerCategoria[$idOperaStr]['corrispondenza_dm'] = (string)$req['legal_correspondence'];
}
```

- [ ] **Step 3: Update the INSERT SQL in STEP 4**

Replace the `$sql = "INSERT INTO gar_gara_requisiti_tecnici_categoria` block (lines 1203-1215) with:

```php
$sql = "INSERT INTO gar_gara_requisiti_tecnici_categoria
        (job_id, extraction_id, id_opera, id_opera_raw, gar_opera_id, categoria, identificazione_opera, importo_minimo_eur, importo_minimo_raw, importo_minimo_punta_eur, note, grado_complessita, importo_lavori_eur, corrispondenza_dm, moltiplicatore, anni_riferimento, min_servizi, fulfillment_alternativo)
        VALUES (:job_id, :extraction_id, :id_opera, :id_opera_raw, :gar_opera_id, :categoria, :identificazione_opera, :importo_minimo_eur, :importo_minimo_raw, :importo_minimo_punta_eur, :note, :grado_complessita, :importo_lavori_eur, :corrispondenza_dm, :moltiplicatore, :anni_riferimento, :min_servizi, :fulfillment_alternativo)
        ON DUPLICATE KEY UPDATE
        extraction_id = VALUES(extraction_id),
        id_opera_raw = VALUES(id_opera_raw),
        gar_opera_id = VALUES(gar_opera_id),
        categoria = VALUES(categoria),
        identificazione_opera = VALUES(identificazione_opera),
        importo_minimo_eur = VALUES(importo_minimo_eur),
        importo_minimo_raw = VALUES(importo_minimo_raw),
        importo_minimo_punta_eur = VALUES(importo_minimo_punta_eur),
        note = VALUES(note),
        grado_complessita = VALUES(grado_complessita),
        importo_lavori_eur = VALUES(importo_lavori_eur),
        corrispondenza_dm = VALUES(corrispondenza_dm),
        moltiplicatore = VALUES(moltiplicatore),
        anni_riferimento = VALUES(anni_riferimento),
        min_servizi = VALUES(min_servizi),
        fulfillment_alternativo = VALUES(fulfillment_alternativo)";
```

- [ ] **Step 4: Add new parameters to the `execute` array**

In the `$stmt->execute([...])` block (lines 1218-1230), add after `':note' => null,`:

```php
':grado_complessita'       => $data['grado_complessita'],
':importo_lavori_eur'      => $data['importo_lavori_eur'],
':corrispondenza_dm'       => $data['corrispondenza_dm'],
':moltiplicatore'          => $moltiplicatore,
':anni_riferimento'        => $anniRiferimento,
':min_servizi'             => $minServizi,
':fulfillment_alternativo' => $fulfillmentAlternativo,
```

- [ ] **Step 5: Commit**

```bash
git add services/AIextraction/GaraDataNormalizer.php
git commit -m "feat(normalizer): save complexity, base_value, legal_correspondence and meta fields in gar_gara_requisiti_tecnici_categoria"
```

---

## Task 4: ExtractionService — `buildEstrazioneFromNormalizedImportiOpere()`

**File:** `services/ExtractionService.php`
**Lines to modify:** ~7763-7842

The function currently reads from `gar_gara_importi_opere` via a SELECT, builds an `entries` array, and returns without a `table` key. The frontend renders via `buildExtractionTabs()` which needs a `table` key to show headers.

- [ ] **Step 1: Add `imp.tipo_lavori` to the SELECT query**

In the SQL at line ~7770, add `imp.tipo_lavori,` after `imp.importo_lavori_raw,`:

```sql
SELECT
    imp.id,
    imp.job_id,
    imp.extraction_id,
    imp.id_opera,
    imp.id_opera_raw,
    imp.gar_opera_id,
    COALESCE(imp.categoria, dm50.categoria) AS categoria,
    COALESCE(imp.identificazione_opera, dm50.identificazione_opera) AS identificazione_opera,
    COALESCE(imp.complessita_dm50, dm50.complessita) AS complessita_dm50,
    imp.tipo_lavori,
    imp.importo_lavori_eur,
    imp.importo_lavori_raw,
    imp.note
FROM gar_gara_importi_opere imp
LEFT JOIN gar_opere_dm50 dm50 ON dm50.id_opera = imp.id_opera
WHERE imp.job_id = :job_id
ORDER BY imp.id ASC
```

- [ ] **Step 2: Add `tipo_lavori` to the entries array**

In the `foreach ($rows as $r)` loop (line ~7800), add to each entry:

```php
'tipo_lavori' => $r['tipo_lavori'] ?? null,
```

- [ ] **Step 3: Add a `table` key to the return array**

After the `$entries` building loop and before the `$updatedAt` block (around line 7817), add:

```php
// Costruisce tabella con colonne fisse
$opereHeaders = ['ID opere', 'Categoria', 'Tipo lavori', 'Descrizione', 'Grado di complessità', 'Importo lavori'];
$opereRows = [];
foreach ($rows as $r) {
    $importoFmt = '—';
    if (isset($r['importo_lavori_eur']) && is_numeric($r['importo_lavori_eur'])) {
        $importoFmt = '€ ' . number_format((float)$r['importo_lavori_eur'], 2, ',', '.');
    } elseif (!empty($r['importo_lavori_raw'])) {
        $importoFmt = $r['importo_lavori_raw'];
    }
    $opereRows[] = [
        $r['id_opera'] ?: '—',
        $r['categoria'] ?: '—',
        $r['tipo_lavori'] ?: '—',
        $r['identificazione_opera'] ?: '—',
        isset($r['complessita_dm50']) && $r['complessita_dm50'] !== null ? (string)$r['complessita_dm50'] : '—',
        $importoFmt,
    ];
}
```

- [ ] **Step 4: Include the `table` key in the return array**

In the `return [...]` block (line ~7828), add:

```php
'table' => [
    'headers' => $opereHeaders,
    'rows'    => $opereRows,
],
```

- [ ] **Step 5: Commit**

```bash
git add services/ExtractionService.php
git commit -m "feat(extraction-service): add tipo_lavori column and table structure to importi opere"
```

---

## Task 5: ExtractionService — `buildEstrazioneFromNormalizedRequisitiTecnici()`

**File:** `services/ExtractionService.php`
**Lines to modify:** ~8032-8170

- [ ] **Step 1: Add new columns to the SELECT query**

In the SQL at line ~8039, add after `reqcat.importo_minimo_punta_raw,`:

```sql
reqcat.grado_complessita,
reqcat.importo_lavori_eur,
reqcat.corrispondenza_dm,
```

- [ ] **Step 2: Update `$headers` and `$tableRows` building**

Replace the current `$headers` declaration and `$tableRows` building block (lines ~8121-8142) with:

```php
// Costruisce tabella con colonne fisse: ID Opera, Categoria, Descrizione, Importo lavori, Importo minimo, Complessità, Corrisp. DM
$headers = ['ID Opera', 'Categoria', 'Descrizione', 'Importo lavori', 'Importo minimo', 'Complessità', 'Corrisp. DM'];
$tableRows = [];
foreach ($rowsCategoria as $r) {
    $idOpera    = $r['id_opera'] ?: '—';
    $categoria  = $r['categoria'] ?: '—';
    $descrizione = $r['identificazione_opera'] ?: '—';

    $importoLavori = '—';
    if (isset($r['importo_lavori_eur']) && $r['importo_lavori_eur'] !== null && is_numeric($r['importo_lavori_eur'])) {
        $importoLavori = '€ ' . number_format((float)$r['importo_lavori_eur'], 2, ',', '.');
    }

    $importoMinimo = '—';
    if (isset($r['importo_minimo_eur']) && $r['importo_minimo_eur'] !== null && is_numeric($r['importo_minimo_eur'])) {
        $importoMinimo = number_format((float)$r['importo_minimo_eur'], 2, ',', '.') . ' €';
    } elseif (!empty($r['importo_minimo_raw'])) {
        $importoMinimo = $r['importo_minimo_raw'];
    }

    $complessita = isset($r['grado_complessita']) && $r['grado_complessita'] !== null
        ? (string)$r['grado_complessita']
        : '—';

    $corrDm = $r['corrispondenza_dm'] ?: '—';

    $tableRows[] = [
        $idOpera,
        $categoria,
        $descrizione,
        $importoLavori,
        $importoMinimo,
        $complessita,
        $corrDm,
    ];
}
```

- [ ] **Step 3: Commit**

```bash
git add services/ExtractionService.php
git commit -m "feat(extraction-service): add importo_lavori, complessita, corrispondenza_dm columns to requisiti tecnici table"
```

---

## Task 6: Frontend Bug Fix

**File:** `assets/js/gare_detail.js`
**Line:** ~1560

- [ ] **Step 1: Fix the `x` suffix bug**

Line 1560 currently reads:
```javascript
if (reqJson?.multiplier_coefficient) chips.push(`Coefficiente moltiplicatore: ${reqJson.multiplier_coefficient}x`);
```

Change to:
```javascript
if (reqJson?.multiplier_coefficient) chips.push(`Coefficiente moltiplicatore: ${reqJson.multiplier_coefficient}`);
```

(Remove the trailing `x` — the API value already contains the unit, e.g., `"0,4 volte"`)

- [ ] **Step 2: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "fix(importi): remove spurious x suffix from moltiplicatore chip"
```

---

## Task 7: Manual Verification

No automated tests exist in this project. Verify manually.

- [ ] **Step 1: Re-process a gara with known data**

Upload a PDF for a gara that has data in all three extraction types. Use one of the reference samples to know expected values:
- `.agent/samples/Gara_Castelvecchio_Stupinigi.json`: `multiplier_coefficient: "0,4 volte"`, `complexity: 1.55`, `work_types` in opere entries
- `.agent/samples/ex_convento_gagliano.json`: `multiplier_coefficient: "0,5 volte"`, `alternative_fulfillment` present

- [ ] **Step 2: Verify DB rows have new columns populated**

```sql
SELECT id_opera, grado_complessita, importo_lavori_eur, corrispondenza_dm, moltiplicatore, anni_riferimento, min_servizi
FROM gar_gara_requisiti_tecnici_categoria
WHERE job_id = <your_job_id>;

SELECT id_opera, tipo_lavori
FROM gar_gara_importi_opere
WHERE job_id = <your_job_id>;
```

Expected: non-NULL values for gare with rich API responses.

- [ ] **Step 3: Open gara detail page in browser**

- Open the gara detail page
- Navigate to the "Importi" section
- Verify "Importi opere per categoria" table shows 6 columns including "Tipo lavori"
- Verify "Requisiti tecnici per categoria" table shows 7 columns including "Importo lavori", "Complessità", "Corrisp. DM"
- Verify the chip above "Requisiti tecnici per categoria" shows e.g. `Coefficiente moltiplicatore: 0,4 volte` (no trailing `x`)

- [ ] **Step 4: Verify graceful degradation**

Open an older gara (not re-processed) in the same section. All new columns should show `—` with no errors.
