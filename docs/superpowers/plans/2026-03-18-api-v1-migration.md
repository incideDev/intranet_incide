# API v1 Migration — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the PDF extraction system from legacy `/api/` endpoints to stable `/api/v1/`, consolidate duplicate HTTP code, and integrate all new API features (quota, usage, PDF download, batch history, health).

**Architecture:** ExternalApiClient becomes the single HTTP layer. GareService delegates all external calls to it. New features exposed through service_router.php actions, consumed by gare_list.js and gare_detail.js.

**Tech Stack:** PHP (cURL), Vanilla JS (ES6), MySQL

**Spec:** `docs/superpowers/specs/2026-03-18-api-v1-migration-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `config/.env` | Modify | New base URL, remove legacy vars, add AI_API_VERSION |
| `services/AIextraction/ExternalApiClient.php` | Modify | v1 paths, new methods, cleanup legacy |
| `services/GareService.php` | Modify | Remove duplicate HTTP, delegate to client, new actions |
| `services/ExtractionConstants.php` | Modify | Add 3 new extraction types |
| `services/AIextraction/ExtractionFormatter.php` | Modify | Remove deprecated sort order, update ordering |
| `ajax.php` | Modify | Add early-exit route for binary PDF streaming |
| `service_router.php` | Modify | Add 7 new action routes |
| `assets/js/gare_list.js` | Modify | Dynamic types, quota check pre-upload |
| `assets/js/gare_detail.js` | Modify | PDF download buttons, usage info, API status |
| `views/gare_dettaglio.php` | Modify | HTML for new UI sections |

---

## Task 1: Config — Update .env for v1 API

**Files:**
- Modify: `config/.env`

**Context:** The .env currently points to the old hostname and has template URL vars that are no longer needed. The new API is at `incide-api.159-69-127-5.sslip.io`.

- [ ] **Step 1: Update .env AI section**

Replace the entire AI EXTRACTION API section (lines 28-50) with:

```env
# ============================
# AI EXTRACTION API (Bandi) - v1
# ============================
AI_API_BASE=http://incide-api.159-69-127-5.sslip.io
AI_API_KEY=test-api-key-local-validation
AI_API_VERSION=v1

AI_NOTIFICATION_EMAIL=alex@aigarden.io

AI_API_HOST=incide-api.159-69-127-5.sslip.io
AI_DNS_RESOLVE=incide-api.159-69-127-5.sslip.io:80:159.69.127.5
AI_FORCE_HOST_HEADER=1

MAX_UPLOAD_MB=60
```

Removed vars: `AI_API_START_URL`, `AI_API_STATUS_URL_TPL`, `AI_API_RESULTS_URL_TPL`.

- [ ] **Step 2: Verify API reachable**

Run: `curl -s http://incide-api.159-69-127-5.sslip.io/api/v1/health | head -1`
Expected: JSON with `"status": "healthy"`

- [ ] **Step 3: Commit**

```bash
git add config/.env
git commit -m "chore: update .env to v1 API base URL and remove legacy vars"
```

---

## Task 2: ExternalApiClient — Migrate to v1 paths + cleanup

**Files:**
- Modify: `services/AIextraction/ExternalApiClient.php`

**Context:** This is the HTTP client that talks to the external API. All paths are currently `/api/...` (legacy). We need to switch to `/api/v1/...`, remove deprecated methods, and remove legacy config fallbacks. Read the full file first — it's ~530 lines.

- [ ] **Step 1: Update `buildUrl()` to add version prefix**

Replace the current `buildUrl()` method (lines 295-303) with:

```php
private function buildUrl(string $path): string
{
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    $base = rtrim($this->getApiBase(), '/');
    $version = $this->config['AI_API_VERSION'] ?? 'v1';
    // If path already has /api/vN, use as-is
    if (preg_match('#^/api/v\d#', $path)) {
        return $base . $path;
    }
    return $base . '/api/' . $version . $path;
}
```

- [ ] **Step 2: Update `getBatchStatus()` path**

Change line 70 from:
```php
$url = $this->buildUrl('/api/batch/' . rawurlencode($batchId) . '/status');
```
to:
```php
$url = $this->buildUrl('/batch/' . rawurlencode($batchId) . '/status');
```

- [ ] **Step 3: Update `getBatchResults()` path**

Change line 82 from:
```php
$url = $this->buildUrl('/api/batch/' . rawurlencode($batchId) . '/results');
```
to:
```php
$url = $this->buildUrl('/batch/' . rawurlencode($batchId) . '/results');
```

- [ ] **Step 4: Update `listExtractionTypes()` path**

Change line 408 from:
```php
$url = $client->buildUrl('/api/extraction-types');
```
to:
```php
$url = $client->buildUrl('/extraction-types');
```

- [ ] **Step 5: Update static method paths**

Update `jobStatus()` (line 480): `/api/jobs/` → `/jobs/`
Update `jobResult()` (line 493): `/api/jobs/` → `/jobs/`

- [ ] **Step 6: Remove `getStartUrl()` and update `analyzeSingleFile()`**

In `analyzeSingleFile()` (line 37-60), replace:
```php
$startUrl = $this->getStartUrl();
```
with:
```php
$startUrl = $this->buildUrl('/batch/analyze');
```

Then delete the entire `getStartUrl()` method (lines 320-332).

- [ ] **Step 7: Remove `getDefaultEmail()` method**

Delete the `getDefaultEmail()` method (lines 337-343). In `analyzeSingleFile()`, replace:
```php
'notification_email' => $fields['notification_email'] ?? $this->getDefaultEmail(),
```
with:
```php
'notification_email' => $fields['notification_email'] ?? ($this->config['AI_NOTIFICATION_EMAIL'] ?? ''),
```

- [ ] **Step 8: Remove `loadEnvConfig()` deprecated method**

Delete the `loadEnvConfig()` method (lines 349-352).

- [ ] **Step 9: Remove legacy fallbacks from `getApiBase()` and `buildHeaders()`**

In `getApiBase()` (line 310): change `$this->config['AI_API_BASE'] ?? $this->config['PDF_API_BASE'] ?? ''` to `$this->config['AI_API_BASE'] ?? ''`.

In `buildHeaders()` (line 252): change `$this->config['AI_API_KEY'] ?? $this->config['PDF_API_KEY'] ?? null` to `$this->config['AI_API_KEY'] ?? null`.

- [ ] **Step 10: Commit**

```bash
git add services/AIextraction/ExternalApiClient.php
git commit -m "refactor: migrate ExternalApiClient to v1 paths and remove legacy code"
```

---

## Task 3: ExternalApiClient — Add new API methods

**Files:**
- Modify: `services/AIextraction/ExternalApiClient.php`

**Context:** Add methods for all new v1 endpoints: quota, usage, health, batches, binary download, delete. These go after the existing static methods section. New methods return `['success' => bool, ...]` pattern.

- [ ] **Step 1: Add quota and usage methods**

Add after the `download()` method (around line 530):

```php
// ===== NEW V1 API METHODS =====

public function getQuota(): array
{
    $url = $this->buildUrl('/quota');
    $res = $this->jsonRequest('GET', $url);
    if (($res['status'] ?? 500) >= 400) {
        return ['success' => false, 'message' => 'Quota check failed: HTTP ' . ($res['status'] ?? 'unknown')];
    }
    return ['success' => true, 'data' => $res['body']];
}

public function checkQuota(int $needed): array
{
    $url = $this->buildUrl('/quota/check');
    $res = $this->jsonRequest('POST', $url, ['requested' => $needed]);
    if (($res['status'] ?? 500) >= 400) {
        return ['success' => false, 'message' => 'Quota check failed: HTTP ' . ($res['status'] ?? 'unknown')];
    }
    return ['success' => true, 'data' => $res['body']];
}

public function getDailyUsage(?string $date = null): array
{
    $url = $this->buildUrl('/usage');
    if ($date) {
        $url .= '?date=' . rawurlencode($date);
    }
    $res = $this->jsonRequest('GET', $url);
    if (($res['status'] ?? 500) >= 400) {
        return ['success' => false, 'message' => 'Usage fetch failed: HTTP ' . ($res['status'] ?? 'unknown')];
    }
    return ['success' => true, 'data' => $res['body']];
}

public function getBatchUsage(string $batchId): array
{
    $url = $this->buildUrl('/usage/batch/' . rawurlencode($batchId));
    $res = $this->jsonRequest('GET', $url);
    if (($res['status'] ?? 500) >= 400) {
        return ['success' => false, 'message' => 'Batch usage fetch failed: HTTP ' . ($res['status'] ?? 'unknown')];
    }
    return ['success' => true, 'data' => $res['body']];
}

public function getUsageHistory(int $days = 30, ?string $cursor = null): array
{
    $url = $this->buildUrl('/usage/history') . '?days=' . $days;
    if ($cursor) {
        $url .= '&cursor=' . rawurlencode($cursor);
    }
    $res = $this->jsonRequest('GET', $url);
    if (($res['status'] ?? 500) >= 400) {
        return ['success' => false, 'message' => 'Usage history failed: HTTP ' . ($res['status'] ?? 'unknown')];
    }
    return ['success' => true, 'data' => $res['body']];
}
```

- [ ] **Step 2: Add batch management and health methods**

```php
public function healthCheck(): array
{
    $url = $this->buildUrl('/health');
    $res = $this->jsonRequest('GET', $url);
    if (($res['status'] ?? 500) >= 400) {
        return ['success' => false, 'message' => 'Health check failed: HTTP ' . ($res['status'] ?? 'unknown')];
    }
    return ['success' => true, 'data' => $res['body']];
}

public function listBatches(?string $status = null, int $limit = 20, int $offset = 0): array
{
    $url = $this->buildUrl('/batches') . '?limit=' . $limit . '&offset=' . $offset;
    if ($status) {
        $url .= '&status=' . rawurlencode($status);
    }
    $res = $this->jsonRequest('GET', $url);
    if (($res['status'] ?? 500) >= 400) {
        return ['success' => false, 'message' => 'Batch list failed: HTTP ' . ($res['status'] ?? 'unknown')];
    }
    return ['success' => true, 'data' => $res['body']];
}

public function deleteJob(string $jobId): array
{
    $url = $this->buildUrl('/jobs/' . rawurlencode($jobId));
    $res = $this->jsonRequest('DELETE', $url);
    if (($res['status'] ?? 500) >= 400) {
        return ['success' => false, 'message' => 'Delete failed: HTTP ' . ($res['status'] ?? 'unknown')];
    }
    return ['success' => true, 'data' => $res['body']];
}
```

- [ ] **Step 3: Add `downloadBinary()` for PDF streaming**

This method does NOT use `jsonRequest()` because the response is binary PDF, not JSON.

```php
/**
 * Download binary file (highlighted PDFs).
 * Returns raw binary content, not JSON-decoded.
 *
 * @return array ['status' => int, 'body' => string, 'content_type' => string]
 */
public function downloadBinary(string $jobId, string $filename): array
{
    $url = $this->buildUrl('/jobs/' . rawurlencode($jobId) . '/download/' . rawurlencode($filename));

    $headers = $this->buildHeaders();
    $headers[] = 'Accept: application/pdf, application/octet-stream';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $this->applyCurlOptions($ch);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'body' => '', 'content_type' => '', 'error' => $err];
    }

    return ['status' => $code, 'body' => $raw, 'content_type' => $ct];
}
```

- [ ] **Step 4: Commit**

```bash
git add services/AIextraction/ExternalApiClient.php
git commit -m "feat: add new v1 API methods (quota, usage, health, batches, PDF download)"
```

---

## Task 4: GareService — Consolidate HTTP layer (remove duplicates)

**Files:**
- Modify: `services/GareService.php`

**Context:** GareService has its own duplicate HTTP methods (~180 lines) that duplicate ExternalApiClient. Read lines 4047-4230 of GareService.php first. We need to make the wrapper methods delegate to ExternalApiClient and remove the duplicate HTTP code.

- [ ] **Step 1: Rewrite `externalAnalyzeSingle()` to delegate**

Replace the method at line 4047-4051:

```php
private static function externalAnalyzeSingle(array $fields, array $file, array $env): array
{
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->analyzeSingleFile($fields, $file);
}
```

(This is likely already correct — verify it delegates to ExternalApiClient, not local HTTP.)

- [ ] **Step 2: Rewrite `externalBatchStatus()` to delegate**

Replace the method at line 4052-4056:

```php
private static function externalBatchStatus(string $batchId, array $env): array
{
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->getBatchStatus($batchId);
}
```

- [ ] **Step 3: Rewrite `externalBatchResults()` to delegate**

Replace the method at line 4058-4062:

```php
private static function externalBatchResults(string $batchId, array $env): array
{
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->getBatchResults($batchId);
}
```

- [ ] **Step 4: Delete duplicate HTTP methods**

Delete the following methods from GareService.php:
- `externalUrl()` (lines ~4064-4074)
- `externalJsonRequest()` (lines ~4076-4107)
- `externalMultipartManual()` (lines ~4109-4181)
- `authHeaders()` (lines ~4183-4202)
- `applyCurlExtras()` (lines ~4204-4228)

That's approximately 160 lines removed.

- [ ] **Step 5: Remove all `PDF_API_BASE` / `PDF_API_KEY` fallbacks**

Search for `PDF_API_BASE` and `PDF_API_KEY` in GareService.php (found at lines 402, 403, 1458, 1459, 1735, 1736, 4069, 4186, 4767, 4784, 4785).

Replace every `$env['AI_API_BASE'] ?? $env['PDF_API_BASE'] ?? ''` with `$env['AI_API_BASE'] ?? ''`.
Replace every `$env['AI_API_KEY'] ?? $env['PDF_API_KEY'] ?? ''` with `$env['AI_API_KEY'] ?? ''`.

In `loadEnvConfig()` (line 4767): remove `'PDF_API_BASE', 'PDF_API_KEY'` from the env var list.

- [ ] **Step 6: Add `AI_API_VERSION` to `loadEnvConfig()` var list**

In `loadEnvConfig()` (around line 4725), find the array of env var names and add `'AI_API_VERSION'` to it, near the other `AI_*` vars.

- [ ] **Step 7: Commit**

```bash
git add services/GareService.php
git commit -m "refactor: consolidate HTTP layer - GareService delegates to ExternalApiClient"
```

---

## Task 5: GareService — Add new action methods

**Files:**
- Modify: `services/GareService.php`

**Context:** Add new static methods for the new features. These will be called from service_router.php. Each method creates an ExternalApiClient instance and delegates. Read `rules/php.md` for the service pattern.

- [ ] **Step 1: Add `checkQuota()` method**

Add near the other public static methods:

```php
public static function checkQuota(array $input): array
{
    $needed = (int) ($input['needed'] ?? 0);
    if ($needed <= 0 || $needed > 100) {
        return ['success' => false, 'message' => 'needed deve essere un intero positivo (max 100)'];
    }

    $env = self::expandEnvPlaceholders(self::loadEnvConfig());
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->checkQuota($needed);
}
```

- [ ] **Step 2: Add `getExtractionTypes()` method**

```php
public static function getExtractionTypes(): array
{
    $env = self::expandEnvPlaceholders(self::loadEnvConfig());
    $client = new \Services\AIextraction\ExternalApiClient($env);
    try {
        $types = $client->listExtractionTypes();
        return ['success' => true, 'data' => $types];
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
```

- [ ] **Step 3: Add `apiHealth()` method**

```php
public static function apiHealth(): array
{
    $env = self::expandEnvPlaceholders(self::loadEnvConfig());
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->healthCheck();
}
```

- [ ] **Step 4: Add `getBatchUsageAction()` method**

```php
public static function getBatchUsageAction(array $input): array
{
    $batchId = trim((string) ($input['batch_id'] ?? ''));
    if ($batchId === '') {
        return ['success' => false, 'message' => 'batch_id obbligatorio'];
    }

    $env = self::expandEnvPlaceholders(self::loadEnvConfig());
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->getBatchUsage($batchId);
}
```

- [ ] **Step 5: Add `listBatchesAction()` method**

```php
public static function listBatchesAction(array $input): array
{
    $status = !empty($input['status']) ? trim($input['status']) : null;
    $limit  = min(100, max(1, (int) ($input['limit'] ?? 20)));
    $offset = max(0, (int) ($input['offset'] ?? 0));

    $env = self::expandEnvPlaceholders(self::loadEnvConfig());
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->listBatches($status, $limit, $offset);
}
```

- [ ] **Step 6: Add `downloadHighlightedPdf()` method**

This method streams binary — it does NOT return JSON. It outputs directly and exits.

```php
public static function downloadHighlightedPdf(array $input): void
{
    $jobId    = trim((string) ($input['job_id'] ?? ''));
    $filename = trim((string) ($input['filename'] ?? ''));

    if ($jobId === '' || $filename === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'job_id e filename obbligatori']);
        exit;
    }

    // Sanitize filename: only allow safe characters
    $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    if ($sanitized === '' || $sanitized !== $filename) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Filename non valido']);
        exit;
    }

    $env = self::expandEnvPlaceholders(self::loadEnvConfig());
    $client = new \Services\AIextraction\ExternalApiClient($env);
    $response = $client->downloadBinary($jobId, $sanitized);

    if (($response['status'] ?? 0) !== 200 || empty($response['body'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Download fallito: HTTP ' . ($response['status'] ?? 'unknown')]);
        exit;
    }

    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . ($response['content_type'] ?: 'application/pdf'));
    header('Content-Disposition: inline; filename="' . $sanitized . '"');
    header('Content-Length: ' . strlen($response['body']));
    echo $response['body'];
    exit;
}
```

- [ ] **Step 7: Add `deleteRemoteJob()` method**

```php
public static function deleteRemoteJob(array $input): array
{
    $jobId = trim((string) ($input['job_id'] ?? ''));
    if ($jobId === '') {
        return ['success' => false, 'message' => 'job_id obbligatorio'];
    }

    $env = self::expandEnvPlaceholders(self::loadEnvConfig());
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->deleteJob($jobId);
}
```

- [ ] **Step 8: Commit**

```bash
git add services/GareService.php
git commit -m "feat: add GareService action methods for quota, usage, health, batches, PDF download"
```

---

## Task 6: ExtractionConstants + ExtractionFormatter — Update types and ordering

**Files:**
- Modify: `services/ExtractionConstants.php`
- Modify: `services/AIextraction/ExtractionFormatter.php`

- [ ] **Step 1: Add 3 new types to ExtractionConstants.php**

In the `EXTRACTION_TYPE_LABELS` array (after line 29), add:

```php
'settore_gara' => 'Settore della gara (classificazione)',
'criteri_valutazione_offerta_tecnica' => 'Criteri di valutazione dell\'offerta tecnica',
'documenti_di_gara' => 'Documenti di gara',
```

- [ ] **Step 2: Remove `EXTRACTION_SORT_ORDER` from ExtractionFormatter.php**

Delete the entire `EXTRACTION_SORT_ORDER` constant (lines 52-72) and the `@deprecated` comment above it (lines 50-51).

- [ ] **Step 3: Remove `EXTRACTION_SORT_ORDER` fallback in `sortKeyForType()`**

In `sortKeyForType()` (around lines 494-497), delete the block:

```php
// Priorità 2: fallback a EXTRACTION_SORT_ORDER (per retrocompatibilità)
if (isset(self::EXTRACTION_SORT_ORDER[$type])) {
    return self::EXTRACTION_SORT_ORDER[$type];
}
```

- [ ] **Step 4: Update `DETTAGLIO_GARA_ORDER` with new types and re-index**

Replace the entire `DETTAGLIO_GARA_ORDER` constant with:

```php
private const DETTAGLIO_GARA_ORDER = [
    'oggetto_appalto' => 1,
    'luogo_provincia_appalto' => 2,
    'data_scadenza_gara_appalto' => 3,
    'data_uscita_gara_appalto' => 4,
    'settore_industriale_gara_appalto' => 5,
    'settore_gara' => 6,
    'sopralluogo_obbligatorio' => 7,
    'sopralluogo_deadline' => 8,
    'stazione_appaltante' => 9,
    'tipologia_di_appalto' => 10,
    'tipologia_di_gara' => 11,
    'link_portale_stazione_appaltante' => 12,
    'importi_opere_per_categoria_id_opere' => 13,
    'importi_corrispettivi_categoria_id_opere' => 14,
    'importi_requisiti_tecnici_categoria_id_opere' => 15,
    'documentazione_richiesta_tecnica' => 16,
    'requisiti_tecnico_professionali' => 17,
    'fatturato_globale_n_minimo_anni' => 18,
    'requisiti_di_capacita_economica_finanziaria' => 19,
    'requisiti_idoneita_professionale_gruppo_lavoro' => 20,
    'criteri_valutazione_offerta_tecnica' => 21,
    'documenti_di_gara' => 22,
];
```

- [ ] **Step 5: Commit**

```bash
git add services/ExtractionConstants.php services/AIextraction/ExtractionFormatter.php
git commit -m "feat: add 3 new extraction types, remove deprecated sort order"
```

---

## Task 7: Routing — ajax.php early-exit + service_router.php actions

**Files:**
- Modify: `ajax.php`
- Modify: `service_router.php`

**Context:** Binary PDF streaming cannot go through the normal ajax.php JSON flow (CSRF header required, Content-Type set to JSON). We need an early-exit in ajax.php, following the existing Nextcloud pattern at lines 13-50. For JSON actions, we add cases in service_router.php.

- [ ] **Step 1: Add early-exit for PDF binary streaming in ajax.php**

In `ajax.php`, after the existing Nextcloud early-exit block (after line 50, before `header('Content-Type: application/json')` at line 54), add:

```php
// ── EARLY-EXIT: stream binario PDF evidenziato (gare AI) ─────────
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['section'] ?? '') === 'gare'
    && ($_GET['action'] ?? '') === 'downloadHighlightedPdf'
) {
    ob_end_clean();

    // Sessione valida (utente loggato)
    if ($database->LockedTime() > 0 || $Session->logged_in !== true) {
        http_response_code(403);
        exit('Not authenticated');
    }

    // Permission check
    if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
        http_response_code(403);
        exit('Permesso negato');
    }

    \Services\GareService::downloadHighlightedPdf($_GET);
    exit;
}
// ── FINE EARLY-EXIT PDF ──────────────────────────────────────────
```

- [ ] **Step 2: Read the existing gare routing block in service_router.php**

Read `service_router.php` lines 1333-1470 to understand the pattern.

- [ ] **Step 3: Add JSON action cases in service_router.php**

After the last existing case in the `'gare'` block (around line 1460), add. Use `sendJsonResponse($data, JSON_UNESCAPED_UNICODE)` to match existing pattern:

```php
case 'checkQuota':
    if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
        http_response_code(403);
        sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
        break;
    }
    sendJsonResponse(\Services\GareService::checkQuota($input), JSON_UNESCAPED_UNICODE);
    break;

case 'getExtractionTypes':
    if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
        http_response_code(403);
        sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
        break;
    }
    sendJsonResponse(\Services\GareService::getExtractionTypes(), JSON_UNESCAPED_UNICODE);
    break;

case 'apiHealth':
    if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
        http_response_code(403);
        sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
        break;
    }
    sendJsonResponse(\Services\GareService::apiHealth(), JSON_UNESCAPED_UNICODE);
    break;

case 'getQuota':
    if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
        http_response_code(403);
        sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
        break;
    }
    $env = \Services\GareService::expandEnvPlaceholders(\Services\GareService::loadEnvConfig());
    $client = new \Services\AIextraction\ExternalApiClient($env);
    sendJsonResponse($client->getQuota(), JSON_UNESCAPED_UNICODE);
    break;

case 'getBatchUsage':
    if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
        http_response_code(403);
        sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
        break;
    }
    sendJsonResponse(\Services\GareService::getBatchUsageAction($input), JSON_UNESCAPED_UNICODE);
    break;

case 'listBatches':
    if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
        http_response_code(403);
        sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
        break;
    }
    sendJsonResponse(\Services\GareService::listBatchesAction($input), JSON_UNESCAPED_UNICODE);
    break;

case 'deleteRemoteJob':
    if (!function_exists('userHasPermission') ||
        (!userHasPermission('edit_gare') && !userHasPermission('create_gare'))) {
        http_response_code(403);
        sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
        break;
    }
    sendJsonResponse(\Services\GareService::deleteRemoteJob($input), JSON_UNESCAPED_UNICODE);
    break;
```

Note: `downloadHighlightedPdf` is NOT routed here — it uses the ajax.php early-exit (Step 1).

- [ ] **Step 4: Commit**

```bash
git add ajax.php service_router.php
git commit -m "feat: add routes for quota, usage, health, batches + binary PDF early-exit"
```

---

## Task 8: Frontend — Dynamic extraction types + quota check (gare_list.js)

**Files:**
- Modify: `assets/js/gare_list.js`

**Context:** Currently `defaultExtractionTypes()` (line 1630) returns a hardcoded array of 18 types. The upload handler (lines 294, 358) calls this function. We need to: (1) load types dynamically from API, (2) add quota check before upload. Read the upload flow (lines 280-420) first.

- [ ] **Step 1: Add dynamic extraction types loader**

Add a new function near `defaultExtractionTypes()` (around line 1630):

```javascript
let cachedExtractionTypes = null;

async function loadExtractionTypes() {
    if (cachedExtractionTypes) return cachedExtractionTypes;
    try {
        const res = await customFetch('gare', 'getExtractionTypes');
        if (res.success && Array.isArray(res.data) && res.data.length > 0) {
            cachedExtractionTypes = res.data;
            return cachedExtractionTypes;
        }
    } catch (e) {
        console.warn('Failed to load extraction types from API, using defaults', e);
    }
    return defaultExtractionTypes();
}
```

- [ ] **Step 2: Update upload handlers to use dynamic types**

In the single-file upload function (around line 294), change:
```javascript
const extractionTypes = JSON.stringify(defaultExtractionTypes());
```
to:
```javascript
const types = await loadExtractionTypes();
const extractionTypes = JSON.stringify(types);
```

Same change in the form upload function (around line 358).

- [ ] **Step 3: Add quota check and health check before upload**

In the upload handler, before the file loop, add:

```javascript
// Pre-flight: check API is online and has enough quota
const types = await loadExtractionTypes();
const needed = files.length * types.length;
try {
    // Health check — block upload if API is offline
    const healthRes = await customFetch('gare', 'apiHealth');
    if (!healthRes.success || !healthRes.data || healthRes.data.status !== 'healthy') {
        showNotification('API di estrazione non disponibile. Riprova più tardi.', 'error');
        return;
    }

    // Quota check — block upload if insufficient
    const quotaRes = await customFetch('gare', 'checkQuota', { needed });
    if (quotaRes.success && quotaRes.data && quotaRes.data.can_fulfill === false) {
        const remaining = quotaRes.data.rpd_remaining ?? 0;
        showNotification(`Quota API insufficiente: servono ${needed} richieste ma ne restano solo ${remaining}. Riprova domani.`, 'warning');
        return;
    }
} catch (e) {
    console.warn('Pre-flight check failed, proceeding anyway', e);
}
```

- [ ] **Step 4: Commit**

```bash
git add assets/js/gare_list.js
git commit -m "feat: dynamic extraction types and quota check pre-upload"
```

---

## Task 9: Frontend — PDF download, usage, API status (gare_detail.js)

**Files:**
- Modify: `assets/js/gare_detail.js`

**Context:** This file renders extraction results. We need to add: (1) download buttons for highlighted PDFs, (2) usage/cost info after completion, (3) API health status section. Read the file to understand the rendering flow before making changes.

- [ ] **Step 1: Read gare_detail.js to understand rendering flow**

Read the file to find where extractions are rendered and where the job status is shown. Identify the render functions and the DOM structure.

- [ ] **Step 2: Add PDF download button rendering**

In the function that renders individual extractions, after the citations section, add a download button when `highlighted_pdf_paths` exists. The URL uses a GET request to `ajax.php` which hits the early-exit route (no CSRF needed for GET binary streams, same pattern as Nextcloud file downloads):

```javascript
function renderHighlightedPdfLinks(extraction, container) {
    const paths = extraction.highlighted_pdf_paths;
    if (!paths || !Array.isArray(paths) || paths.length === 0) return;

    const wrapper = document.createElement('div');
    wrapper.className = 'highlighted-pdf-links';
    paths.forEach(path => {
        const filename = path.split('/').pop();
        const btn = document.createElement('a');
        btn.className = 'btn btn-sm btn-secondary';
        btn.textContent = 'Vedi nel PDF';
        const jobId = extraction.ext_job_id || extraction.job_id;
        btn.href = `ajax.php?section=gare&action=downloadHighlightedPdf&job_id=${encodeURIComponent(jobId)}&filename=${encodeURIComponent(filename)}`;
        btn.target = '_blank';
        wrapper.appendChild(btn);
    });
    container.appendChild(wrapper);
}
```

Integrate this call into the existing extraction rendering function.

- [ ] **Step 3: Add usage/cost info loader**

```javascript
async function loadBatchUsage(batchId, container) {
    if (!batchId) return;
    try {
        const res = await customFetch('gare', 'getBatchUsage', { batch_id: batchId });
        if (res.success && res.data) {
            const d = res.data;
            const usageHtml = `
                <div class="batch-usage-info">
                    <span><strong>Token:</strong> ${(d.tokens?.prompt_tokens || 0).toLocaleString()} in / ${(d.tokens?.output_tokens || 0).toLocaleString()} out</span>
                    <span><strong>Costo:</strong> $${(d.cost?.total_cost || 0).toFixed(4)}</span>
                </div>`;
            container.insertAdjacentHTML('beforeend', usageHtml);
        }
    } catch (e) {
        console.warn('Failed to load batch usage', e);
    }
}
```

Call this after extraction results are rendered, passing the `ext_batch_id`.

- [ ] **Step 4: Add API health status section**

```javascript
async function loadApiStatus(container) {
    try {
        const [healthRes, quotaRes] = await Promise.all([
            customFetch('gare', 'apiHealth'),
            customFetch('gare', 'getQuota')
        ]);

        let html = '<div class="api-status-section collapsible">';
        html += '<div class="api-status-header" onclick="this.parentElement.classList.toggle(\'collapsed\')">';
        html += '<h4>Stato API Estrazione</h4></div>';
        html += '<div class="api-status-body">';

        if (healthRes.success && healthRes.data) {
            const h = healthRes.data;
            const statusClass = h.status === 'healthy' ? 'pill-success' : 'pill-danger';
            html += `<p><span class="pill ${statusClass}">${h.status}</span> Modello: ${h.gemini_model || 'N/A'}</p>`;
        } else {
            html += '<p><span class="pill pill-danger">Offline</span></p>';
        }

        if (quotaRes.success && quotaRes.data) {
            const q = quotaRes.data;
            const pctUsed = q.percentage_used || 0;
            html += `<div class="quota-bar">
                <div class="quota-bar-fill" style="width: ${pctUsed}%"></div>
            </div>
            <p>Quota: ${q.rpd_remaining || 0} / ${q.rpd_limit || 0} richieste rimanenti</p>`;
        }

        html += '</div></div>';
        container.insertAdjacentHTML('afterbegin', html);
    } catch (e) {
        console.warn('Failed to load API status', e);
    }
}
```

Call `loadApiStatus()` at page load, passing the appropriate container element.

- [ ] **Step 5: Commit**

```bash
git add assets/js/gare_detail.js
git commit -m "feat: add PDF download buttons, usage info, and API health status"
```

---

## Task 10: View — HTML for new UI sections (gare_dettaglio.php)

**Files:**
- Modify: `views/gare_dettaglio.php`

**Context:** Read the file to find where extraction results are displayed. Add placeholder containers for the JS to populate.

- [ ] **Step 1: Read gare_dettaglio.php to find extraction section**

Locate the section where extraction results are rendered.

- [ ] **Step 2: Add API status container**

Before the extraction results section, add:

```html
<!-- API Status Section (populated by gare_detail.js) -->
<div id="api-status-container"></div>
```

- [ ] **Step 3: Add usage container**

After the extraction results section, add:

```html
<!-- Batch Usage Info (populated by gare_detail.js) -->
<div id="batch-usage-container"></div>
```

- [ ] **Step 4: Add minimal CSS for new sections**

Add styles (in the existing CSS file or inline if the project uses inline styles for this view):

```css
.api-status-section { margin-bottom: 1rem; border: 1px solid #e0e0e0; border-radius: 4px; }
.api-status-header { padding: 0.5rem 1rem; cursor: pointer; background: #f8f9fa; }
.api-status-header h4 { margin: 0; font-size: 0.9rem; }
.api-status-section.collapsed .api-status-body { display: none; }
.api-status-body { padding: 0.5rem 1rem; }
.quota-bar { height: 8px; background: #e9ecef; border-radius: 4px; margin: 0.5rem 0; }
.quota-bar-fill { height: 100%; background: #28a745; border-radius: 4px; transition: width 0.3s; }
.batch-usage-info { display: flex; gap: 1.5rem; padding: 0.5rem 0; color: #6c757d; font-size: 0.85rem; }
.highlighted-pdf-links { margin-top: 0.5rem; }
.highlighted-pdf-links .btn { margin-right: 0.25rem; }
```

- [ ] **Step 5: Commit**

```bash
git add views/gare_dettaglio.php
git commit -m "feat: add HTML containers and CSS for API status, usage, PDF download"
```

---

## Task 11: Manual verification

- [ ] **Step 1: Verify API connectivity**

Open browser, navigate to a gara detail page. Check browser console for:
- API status section loads (shows healthy/offline)
- No JS errors

- [ ] **Step 2: Test upload flow with quota check**

Upload a test PDF. Verify:
- Quota check happens before upload (check Network tab for `checkQuota` call)
- Extraction types loaded dynamically (check Network tab for `getExtractionTypes` call)
- Upload proceeds normally

- [ ] **Step 3: Test polling and results**

After upload, verify:
- Polling uses v1 endpoints (check Network tab — should see `jobPull` calls)
- Results render correctly when complete
- Usage info appears after completion

- [ ] **Step 4: Test PDF download**

If the completed job has `highlighted_pdf_paths`, click the "Vedi nel PDF" button. Verify PDF opens in new tab.

- [ ] **Step 5: Final commit**

If any fixes were needed:

```bash
git add -u
git commit -m "fix: address manual testing issues in v1 migration"
```
