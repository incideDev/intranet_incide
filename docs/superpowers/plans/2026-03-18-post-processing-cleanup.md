# Post-Processing Cleanup — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate redundant parsing in the extraction pipeline by saving API v1 structured data directly to ext_extractions, removing dead code, and consolidating the post-processing flow.

**Architecture:** Replace `mapExternalAnswersFromBatch()` + `mapSingleAnswer()` with a new `saveApiResults()` that writes API `data` directly to ext_extractions. Delete ExtractionNormalizer (all stubs). Simplify `expandEnvPlaceholders()` to pass-through.

**Tech Stack:** PHP (PDO)

**Spec:** `docs/superpowers/specs/2026-03-18-post-processing-cleanup-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `services/GareService.php` | Modify | New `saveApiResults()`, remove `mapExternalAnswersFromBatch`, `mapSingleAnswer`, `processNormalizedRequirements`, simplify `expandEnvPlaceholders` |
| `services/AIextraction/ExtractionNormalizer.php` | Delete | Entirely stubs — dead code |

---

## Task 1: Add `saveApiResults()` to GareService

**Files:**
- Modify: `services/GareService.php`

**Context:** This new method replaces the `mapExternalAnswersFromBatch()` → `upsertExtractions()` chain. It takes the raw API v1 batch response and saves each result directly to ext_extractions. The key insight: `data` from the API IS the `value_json` (after removing debug fields). The existing `extractCleanAnswer()` method (line 2666) already extracts display values from structured data. The existing `cleanValueJson()` (line 2741) already strips debug fields. The existing `upsertExtractions()` (line 2417) already handles transaction, deletion of old data, and table cell saving. We reuse all of these.

- [ ] **Step 1: Read the current flow**

Read GareService.php lines 1893-1965 (the section in `jobPull()` that calls `mapExternalAnswersFromBatch` → `upsertExtractions` → `processNormalizedRequirements` → `normalizeGara`).

Also read: `upsertExtractions()` (line 2417-2653), `extractCleanAnswer()` (line 2666-2736), `cleanValueJson()` (line 2741-2759), `saveExtraction()` (line 2761-2779).

- [ ] **Step 2: Create `saveApiResults()` method**

Add this new method near `upsertExtractions()`. It converts API v1 batch results into the format `upsertExtractions()` expects, then delegates to it:

```php
/**
 * Salva i risultati dell'API v1 direttamente in ext_extractions.
 * Converte il formato API v1 (results[].data) nel formato atteso da upsertExtractions().
 * Elimina il bisogno di mapExternalAnswersFromBatch() e mapSingleAnswer().
 */
private static function saveApiResults(int $jobId, array $batchBody): void
{
    $results = $batchBody['results'] ?? [];
    if (empty($results)) {
        self::addDebugLog("Job {$jobId}: saveApiResults - nessun risultato nel batch");
        return;
    }

    $answers = [];
    foreach ($results as $r) {
        $status = $r['status'] ?? 'unknown';
        if ($status !== 'completed') {
            self::addDebugLog("Job {$jobId}: skip result {$r['extraction_type']} - status: {$status}");
            continue;
        }

        $type = $r['extraction_type'] ?? 'unknown';
        $data = $r['data'] ?? null;

        if (!is_array($data)) {
            continue;
        }

        // data È il value_json — puliscilo dai campi di debug
        $valueJson = self::cleanValueJson($data);

        // Genera value_text dal dato strutturato usando extractCleanAnswer() esistente
        $valueText = self::extractCleanAnswer($data);

        // Estrai citations da data.citations (l'API le mette dentro data)
        $citations = [];
        if (!empty($data['citations']) && is_array($data['citations'])) {
            foreach ($data['citations'] as $cit) {
                if (!is_array($cit)) continue;
                $citations[] = [
                    'page_number' => $cit['page_number'] ?? 0,
                    'snippet' => is_array($cit['text'] ?? null) ? implode(' ', $cit['text']) : ($cit['text'] ?? null),
                    'highlight_rel_path' => null,
                ];
            }
        }

        $answers[] = [
            'type_code'  => $type,
            'value_text' => $valueText,
            'value_json' => $valueJson,
            'confidence' => null,
            'citations'  => $citations,
        ];
    }

    if (!empty($answers)) {
        self::upsertExtractions($jobId, $answers);
    }
}
```

- [ ] **Step 3: Update `jobPull()` to use `saveApiResults()`**

In `jobPull()`, replace the block at lines 1897-1920:

**Before:**
```php
$answers = self::mapExternalAnswersFromBatch($results['body'] ?? []);
// ... logging ...
if (!empty($answers)) {
    self::upsertExtractions($jobId, $answers);
    // ... logging ...
    try {
        self::processNormalizedRequirements($jobId);
        // ... logging ...
    } catch (\Throwable $e) {
        // ... error handling ...
    }
```

**After:**
```php
$timeStart = microtime(true);
self::saveApiResults($jobId, $results['body'] ?? []);
$timeSave = microtime(true) - $timeStart;
self::addDebugLog("Job {$jobId}: saveApiResults completato (tempo: " . round($timeSave * 1000, 2) . "ms)");
```

Keep the `normalizeGara()` call that follows (lines 1923-1958) — that stays unchanged.

Remove the `else` block at lines 1959-1964 (the "no answers" log) and replace with a simpler flow: the `normalizeGara` call should always happen after `saveApiResults`, unconditionally.

- [ ] **Step 4: Verify syntax**

Run: `php -l services/GareService.php`
Expected: No syntax errors

- [ ] **Step 5: Commit**

```bash
git add services/GareService.php
git commit -m "feat: add saveApiResults() - direct API v1 data to ext_extractions"
```

---

## Task 2: Delete dead code from GareService

**Files:**
- Modify: `services/GareService.php`

**Context:** With `saveApiResults()` in place, several methods are no longer called. Delete them to avoid confusion and reduce file size.

- [ ] **Step 1: Delete `mapExternalAnswersFromBatch()`**

Find and delete the entire method (starts around line 4079). Search for `function mapExternalAnswersFromBatch` to find exact location.

- [ ] **Step 2: Delete `mapSingleAnswer()`**

Find and delete the entire method (starts around line 4125). This is ~250 lines.

- [ ] **Step 3: Delete `processNormalizedRequirements()`**

Find and delete the method (around line 2824-2835). It's a small wrapper that calls ExtractionNormalizer.

- [ ] **Step 4: Verify no remaining references**

Search for `mapExternalAnswersFromBatch`, `mapSingleAnswer`, `processNormalizedRequirements` in GareService.php. The only remaining references should be in comments (which should also be removed).

- [ ] **Step 5: Verify syntax**

Run: `php -l services/GareService.php`

- [ ] **Step 6: Commit**

```bash
git add services/GareService.php
git commit -m "refactor: remove mapSingleAnswer, mapExternalAnswersFromBatch, processNormalizedRequirements"
```

---

## Task 3: Delete ExtractionNormalizer.php

**Files:**
- Delete: `services/AIextraction/ExtractionNormalizer.php`

**Context:** This entire class is stubs — all 4 processing methods (`processDocumentazioneRichiesta`, `processFatturatoGlobale`, `processRequisitiEconomici`, `processRequisitiRuoli`) are empty with only debug log statements. The real logic lives in GareService.php methods with the same names.

- [ ] **Step 1: Verify no other callers**

Search entire codebase for `ExtractionNormalizer`:
```bash
grep -r "ExtractionNormalizer" services/ views/ assets/ --include="*.php" --include="*.js"
```

Expected: Only in `GareService.php` (the already-deleted `processNormalizedRequirements()`), and the file itself. If found elsewhere, do NOT delete and report.

- [ ] **Step 2: Delete the file**

```bash
rm services/AIextraction/ExtractionNormalizer.php
```

- [ ] **Step 3: Commit**

```bash
git add -u services/AIextraction/ExtractionNormalizer.php
git commit -m "refactor: delete ExtractionNormalizer.php (entirely stubs)"
```

---

## Task 4: Simplify `expandEnvPlaceholders()`

**Files:**
- Modify: `services/GareService.php`

**Context:** With template URL variables removed from .env (done in the API v1 migration), there are no `${VAR}` patterns to expand. The method is called from 12+ places. Rather than updating all call sites, simplify the method body to a pass-through.

- [ ] **Step 1: Replace method body**

Find `expandEnvPlaceholders()` (around line 2007). Replace the entire body with:

```php
public static function expandEnvPlaceholders(array $env): array
{
    // Template URL variables (${AI_API_BASE}/...) removed in v1 migration.
    // Method kept as pass-through to avoid updating 12+ call sites.
    return $env;
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l services/GareService.php`

- [ ] **Step 3: Commit**

```bash
git add services/GareService.php
git commit -m "refactor: simplify expandEnvPlaceholders to pass-through (no template URLs in v1)"
```

---

## Task 5: Verify end-to-end

- [ ] **Step 1: PHP syntax check all modified files**

```bash
php -l services/GareService.php
```

- [ ] **Step 2: Test upload flow**

Upload a test PDF through the UI. Verify:
- Upload succeeds (check Network tab for `uploadExtraction` response)
- Polling works (check `jobPull` calls)
- When completed: extractions saved in ext_extractions with structured value_json
- GaraDataNormalizer runs (check gar_gare_anagrafica populated)

- [ ] **Step 3: Test existing extraction display**

Open a previously-completed gara (e.g., job 258 or 266). Verify:
- All extraction types render correctly
- Tables (importi, corrispettivi, requisiti) display with data
- Dates formatted correctly
- Location shows "City (Province)"
