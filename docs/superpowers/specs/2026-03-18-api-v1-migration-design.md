# Migrazione API Estrazione Bandi a v1 — Design Spec

**Data:** 2026-03-18
**Obiettivo:** Sostituire completamente l'integrazione API legacy (`/api/...`) con la v1 stabile (`/api/v1/...`) della nuova PDF Analysis API, integrando tutte le nuove feature disponibili.

---

## 1. Contesto

L'intranet usa un sistema di estrazione dati da PDF di bandi tramite un'API esterna (FastAPI + Gemini AI). L'API è stata aggiornata e ora espone endpoint v1 stabili su `http://incide-api.159-69-127-5.sslip.io`. Gli endpoint legacy (`/api/...`) restano per retrocompatibilità ma verranno rimossi.

### API Nuova (v1)
- **Base:** `http://incide-api.159-69-127-5.sslip.io`
- **Versione:** 1.0.0
- **Modello AI:** gemini-3-flash-preview
- **21 tipi di estrazione** (vs 18 attuali)
- **Nuove feature:** quota management, usage tracking, PDF evidenziati, storico batch, health check

### File coinvolti nell'integrazione attuale

| Layer | File | Ruolo |
|-------|------|-------|
| HTTP Client | `services/AIextraction/ExternalApiClient.php` | cURL verso API esterna |
| Orchestratore | `services/GareService.php` | Upload, polling, mapping risultati |
| Storage | `services/AIextraction/StorageManager.php` | Salvataggio PDF + estrazioni in DB |
| Normalizzatore | `services/AIextraction/GaraDataNormalizer.php` | Denormalizzazione su tabelle dominio |
| Builder | `services/AIextraction/ExtractionBuilder.php` | Costruzione estrazioni per frontend |
| Formatter | `services/AIextraction/ExtractionFormatter.php` | Display value, ordinamento, date |
| Constants | `services/ExtractionConstants.php` | Label tipi estrazione |
| Config | `config/.env` | URL base, API key, opzioni TLS |
| Frontend Upload | `assets/js/gare_list.js` | Upload + polling |
| Frontend Detail | `assets/js/gare_detail.js` | Visualizzazione risultati |
| View | `views/gare_dettaglio.php` | HTML pagina dettaglio gara |
| Routing | `service_router.php` | Routing action AJAX |

---

## 2. Migrazione endpoint: `/api/` → `/api/v1/`

### 2.1 Mapping endpoint

| Attuale | Nuovo |
|---------|-------|
| `POST /api/batch/analyze` | `POST /api/v1/batch/analyze` |
| `GET /api/batch/{id}/status` | `GET /api/v1/batch/{id}/status` |
| `GET /api/batch/{id}/results` | `GET /api/v1/batch/{id}/results` |
| `GET /api/jobs/{id}` | `GET /api/v1/jobs/{id}` |
| `GET /api/jobs/{id}/result` | `GET /api/v1/jobs/{id}/result` |
| `GET /api/extraction-types` | `GET /api/v1/extraction-types` |
| `GET /api/health` | `GET /api/v1/health` |
| `GET /api/quota` | `GET /api/v1/quota` |
| `GET /api/batches` | `GET /api/v1/batches` |

### 2.2 Nuovi endpoint (non esistevano prima)

| Endpoint | Metodo client | Scopo |
|----------|---------------|-------|
| `POST /api/v1/quota/check` | `checkQuota(int $needed)` | Pre-flight check quota prima upload |
| `GET /api/v1/usage` | `getDailyUsage(?string $date)` | Token/costi giornalieri |
| `GET /api/v1/usage/batch/{id}` | `getBatchUsage(string $batchId)` | Token/costi per batch |
| `GET /api/v1/usage/history` | `getUsageHistory(int $days)` | Storico costi con pagination |
| `GET /api/v1/jobs/{id}/download/{filename}` | `downloadFile(string $jobId, string $filename)` | PDF evidenziati |
| `DELETE /api/v1/jobs/{id}` | `deleteJob(string $jobId)` | Cancella job remoto |

---

## 3. Configurazione (.env)

### 3.1 Nuovo .env (sezione AI)

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

### 3.2 Variabili rimosse

- `AI_API_START_URL` — il path viene costruito dal client
- `AI_API_STATUS_URL_TPL` — il path viene costruito dal client
- `AI_API_RESULTS_URL_TPL` — il path viene costruito dal client
- `PDF_API_BASE` — fallback legacy, non più necessario
- `PDF_API_KEY` — fallback legacy, non più necessario

### 3.3 Costruzione URL nel client

Il client costruisce gli URL con: `AI_API_BASE + '/api/' + AI_API_VERSION + '/' + endpoint`

Esempio: `http://incide-api.159-69-127-5.sslip.io/api/v1/batch/analyze`

---

## 4. Cambiamenti per file

### 4.1 Consolidamento layer HTTP: eliminare duplicati GareService ↔ ExternalApiClient

**Problema attuale:** GareService contiene un proprio layer HTTP duplicato (~180 righe): `externalJsonRequest()`, `externalMultipartManual()`, `externalUrl()`, `authHeaders()`, `applyCurlExtras()`. Questi sono quasi identici ai metodi di ExternalApiClient.

**Soluzione:** Migrare `externalBatchStatus()`, `externalBatchResults()` e `externalAnalyzeSingle()` di GareService a delegare interamente a ExternalApiClient. Rimuovere i metodi HTTP duplicati da GareService:

| Metodo GareService da rimuovere | Sostituito da |
|--------------------------------|---------------|
| `externalJsonRequest()` | `ExternalApiClient::jsonRequest()` |
| `externalMultipartManual()` | `ExternalApiClient::multipartRequest()` |
| `externalUrl()` | `ExternalApiClient::buildUrl()` |
| `authHeaders()` | `ExternalApiClient::buildHeaders()` |
| `applyCurlExtras()` | `ExternalApiClient::applyCurlOptions()` |

I metodi wrapper in GareService diventano:
```php
private static function externalBatchStatus(string $batchId, array $env): array
{
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->getBatchStatus($batchId);
}

private static function externalBatchResults(string $batchId, array $env): array
{
    $client = new \Services\AIextraction\ExternalApiClient($env);
    return $client->getBatchResults($batchId);
}
```

### 4.2 ExternalApiClient.php

**Path v1:** Tutti i metodi che costruiscono URL passano da `/api/` a `/api/v1/`.

Metodo `buildUrl()` aggiornato:
```php
private function buildUrl(string $path): string
{
    $base = rtrim($this->getApiBase(), '/');
    $version = $this->config['AI_API_VERSION'] ?? 'v1';
    // Se il path inizia già con http, usalo direttamente
    if (str_starts_with($path, 'http')) return $path;
    // Se il path inizia con /api/v, è già versionato
    if (preg_match('#^/api/v\d#', $path)) return $base . $path;
    // Altrimenti costruisci con versione
    return $base . '/api/' . $version . $path;
}
```

I metodi esistenti cambiano i path:
- `getBatchStatus()`: `/batch/{id}/status` (buildUrl aggiunge `/api/v1`)
- `getBatchResults()`: `/batch/{id}/results`
- `listExtractionTypes()`: `/extraction-types`
- `jobStatus()`: `/jobs/{id}`
- `jobResult()`: `/jobs/{id}/result`

**`getStartUrl()` rimosso.** `analyzeSingleFile()` usa direttamente `$this->buildUrl('/batch/analyze')`.

**Nuovi metodi:**

```php
// Quota & Usage
public function getQuota(): array
public function checkQuota(int $needed): array
public function getDailyUsage(?string $date = null): array
public function getBatchUsage(string $batchId): array
public function getUsageHistory(int $days = 30, ?string $cursor = null): array

// Batch management
public function listBatches(?string $status = null, int $limit = 20, int $offset = 0): array
public function deleteJob(string $jobId): array
public function healthCheck(): array

// Binary download (PDF evidenziati) — metodo dedicato, non usa jsonRequest()
public function downloadBinary(string $jobId, string $filename): array
```

**Nota su `downloadBinary()`:** Questo metodo NON usa `jsonRequest()` (che decodifica JSON). Usa cURL direttamente e ritorna `['status' => int, 'body' => string (binary), 'content_type' => string]`. Il chiamante (GareService) streamma il contenuto binario al browser.

**Pulizia:**
- Rimuovere metodo `loadEnvConfig()` deprecato (riga 349-352)
- Rimuovere tutti i fallback `$this->config['PDF_API_BASE']` e `$this->config['PDF_API_KEY']`
- Rimuovere `getStartUrl()` — sostituito da `buildUrl('/batch/analyze')`
- Rimuovere `getDefaultEmail()` se non più usato dall'API v1

**Pattern eccezioni:** I nuovi metodi restituiscono `['success' => false, 'message' => ...]` in caso di errore HTTP, allineandosi al pattern del progetto (`rules/php.md`). I metodi esistenti che lanciano `RuntimeException` vengono gradualmente migrati a restituire array di errore.

### 4.3 GareService.php

**Eliminazione layer HTTP duplicato:** Rimuovere `externalJsonRequest()`, `externalMultipartManual()`, `externalUrl()`, `authHeaders()`, `applyCurlExtras()`. Vedere sezione 4.1.

**Metodi `externalBatch*`:** Delegano a ExternalApiClient (non più HTTP diretto).

**Rimozione fallback legacy:** in `upload()` e `jobPull()`, rimuovere tutti i `?? $env['PDF_API_BASE']` e `?? $env['PDF_API_KEY']`.

**`AI_API_VERSION` nel loadEnvConfig():** Aggiungere `'AI_API_VERSION'` alla lista di variabili caricate in `loadEnvConfig()` perché il metodo usa una lista esplicita di env vars.

**Nuovi metodi statici** (chiamati da `service_router.php`, non da `handleAction()` che non esiste):

| Action (in service_router.php) | Metodo GareService | Descrizione |
|--------|--------|-------------|
| `checkQuota` | `checkQuota($input)` | Pre-flight quota check |
| `getExtractionTypes` | `getExtractionTypes()` | Tipi estrazione dinamici da API |
| `downloadHighlightedPdf` | `downloadHighlightedPdf($input)` | Proxy download PDF evidenziato |
| `getBatchUsage` | `getBatchUsage($input)` | Usage/costi per batch |
| `apiHealth` | `apiHealth()` | Health check API |
| `listBatches` | `listBatches($input)` | Storico batch |
| `deleteRemoteJob` | `deleteRemoteJob($input)` | Cancella job remoto |

**`downloadHighlightedPdf` — gestione binaria:**
Questo metodo NON usa `sendJsonResponse()`. Riceve il binary da `ExternalApiClient::downloadBinary()` e lo streamma con:
```php
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $sanitizedFilename . '"');
echo $response['body'];
exit;
```
Il filename deve essere sanitizzato: solo caratteri alfanumerici, trattini, underscore, punto. Nessun path traversal (`../`).

### 4.4 ExtractionConstants.php

Aggiungere 3 nuovi tipi dalla API v1. **Nota:** `settore_gara` è un type_code DISTINTO da `settore_industriale_gara_appalto` (che resta). L'API v1 li tratta come estrazioni separate.

```php
'settore_gara' => 'Settore della gara (classificazione)',
'criteri_valutazione_offerta_tecnica' => 'Criteri di valutazione dell\'offerta tecnica',
'documenti_di_gara' => 'Documenti di gara',
```

**Mapping completo dei 21 tipi API v1:**

| # | type_code API v1 | Presente in ExtractionConstants? |
|---|-----------------|--------------------------------|
| 1 | `link_portale_stazione_appaltante` | Sì |
| 2 | `importi_corrispettivi_categoria_id_opere` | Sì |
| 3 | `importi_opere_per_categoria_id_opere` | Sì |
| 4 | `importi_requisiti_tecnici_categoria_id_opere` | Sì |
| 5 | `data_scadenza_gara_appalto` | Sì |
| 6 | `data_uscita_gara_appalto` | Sì |
| 7 | `oggetto_appalto` | Sì |
| 8 | `sopralluogo_obbligatorio` | Sì |
| 9 | `stazione_appaltante` | Sì |
| 10 | `tipologia_di_appalto` | Sì |
| 11 | `tipologia_di_gara` | Sì |
| 12 | `luogo_provincia_appalto` | Sì |
| 13 | `requisiti_tecnico_professionali` | Sì |
| 14 | `settore_industriale_gara_appalto` | Sì (resta) |
| 15 | `fatturato_globale_n_minimo_anni` | Sì |
| 16 | `documentazione_richiesta_tecnica` | Sì |
| 17 | `requisiti_di_capacita_economica_finanziaria` | Sì |
| 18 | `requisiti_idoneita_professionale_gruppo_lavoro` | Sì |
| 19 | `settore_gara` | **NUOVO** — distinto da `settore_industriale_gara_appalto` |
| 20 | `criteri_valutazione_offerta_tecnica` | **NUOVO** |
| 21 | `documenti_di_gara` | **NUOVO** |

Nessun tipo è stato rinominato. I 18 esistenti restano identici, si aggiungono 3 nuovi.

### 4.5 ExtractionFormatter.php

**Rimuovere:** `EXTRACTION_SORT_ORDER` (deprecata, righe 52-72) e il fallback in `sortKeyForType()` (righe 494-497).

**Verificare callers:** `sortKeyForType()` è usato da `compareExtractionSort()` e `compareExtractionSortRow()`. Entrambi funzionano correttamente con solo `DETTAGLIO_GARA_ORDER` perché i type_code non nella mappa finiscono in coda (indice >= 1000).

**Aggiornare `DETTAGLIO_GARA_ORDER`:** aggiungere i 3 nuovi tipi in posizione logica:

```php
'settore_gara' => 6,                           // dopo settore_industriale (5), sono affini
'settore_industriale_gara_appalto' => 5,        // resta invariato
'criteri_valutazione_offerta_tecnica' => 20,    // in coda
'documenti_di_gara' => 21,                      // in coda
```

Nota: `settore_gara` va vicino a `settore_industriale_gara_appalto` (indice 5) perché sono affini. Si inserisce a indice 6, spostando gli indici successivi di 1 (da sopralluogo_obbligatorio in poi: 7, 8, 9, ...).

### 4.6 service_router.php

Aggiungere le nuove action nel case `'gare'` (switch su `$action`):

```php
case 'checkQuota':
case 'getExtractionTypes':
case 'downloadHighlightedPdf':
case 'getBatchUsage':
case 'apiHealth':
case 'listBatches':
case 'deleteRemoteJob':
```

**Permission per action:**
- `checkQuota`, `getExtractionTypes`, `apiHealth`, `listBatches` → `view_gare`
- `getBatchUsage` → `view_gare`
- `downloadHighlightedPdf` → `view_gare`
- `deleteRemoteJob` → `edit_gare` o `create_gare`

**Nota:** `downloadHighlightedPdf` NON passa per `sendJsonResponse()`. GareService gestisce direttamente gli header HTTP e lo streaming binario.

### 4.6 config/.env

Come descritto nella sezione 3.

### 4.7 gare_list.js

**Quota check pre-upload:**
- Prima del submit, chiama `customFetch('gare', 'checkQuota', { needed: numFiles * numTypes })`
- Se `can_fulfill === false`, mostra warning con quota rimanente e blocca upload
- Badge con quota rimanente nella modale upload

**Tipi estrazione dinamici:**
- Al caricamento modale upload, chiama `customFetch('gare', 'getExtractionTypes')`
- Renderizza checkbox dai risultati API invece di usare `defaultExtractionTypes()` hardcoded
- Fallback ai tipi hardcoded se la chiamata API fallisce

### 4.8 gare_detail.js

**Download PDF evidenziati:**
- Per ogni estrazione con `highlighted_pdf_paths` non vuoto, mostra pulsante "Vedi nel PDF"
- Click → `window.open()` verso endpoint proxy `downloadHighlightedPdf`

**Sezione usage/costi:**
- Dopo completamento, carica `getBatchUsage` con l'ext_batch_id
- Mostra: input tokens, output tokens, costo totale USD in un blocco compatto

**Sezione stato API:**
- Carica `apiHealth` e `getQuota` al load della pagina
- Mostra: status online/offline, modello AI, quota rimanente (barra percentuale)
- Sezione collassabile

### 4.9 gare_dettaglio.php

**Nuovi blocchi HTML:**

1. **Sezione stato API** (collassabile, prima della sezione estrazioni):
   - Indicatore online/offline
   - Modello AI in uso
   - Barra quota giornaliera

2. **Blocco usage** (dopo i risultati estrazioni):
   - Token input/output
   - Costo stimato

3. **Pulsanti download PDF** (per ogni estrazione con highlighted_pdf_paths):
   - Icona/link inline nel blocco estrazione

---

## 5. Pulizia debito tecnico

### 5.1 Codice da rimuovere

| File | Cosa | Motivo |
|------|------|--------|
| `ExternalApiClient.php` | `loadEnvConfig()` (riga 349-352) | Deprecato, delega già a GareService |
| `ExternalApiClient.php` | `getStartUrl()` | Sostituito da `buildUrl('/batch/analyze')` |
| `ExternalApiClient.php` | `getDefaultEmail()` | Non usato dall'API v1 |
| `ExternalApiClient.php` | Fallback `PDF_API_BASE`/`PDF_API_KEY` in `buildHeaders()` e `getApiBase()` | Legacy rimosso da .env |
| `ExtractionFormatter.php` | `EXTRACTION_SORT_ORDER` (righe 52-72) | Duplicato deprecato di `DETTAGLIO_GARA_ORDER` |
| `ExtractionFormatter.php` | Fallback a `EXTRACTION_SORT_ORDER` in `sortKeyForType()` (righe 494-497) | Usa solo `DETTAGLIO_GARA_ORDER` |
| `GareService.php` | `externalJsonRequest()` (~50 righe) | Duplicato di `ExternalApiClient::jsonRequest()` |
| `GareService.php` | `externalMultipartManual()` (~60 righe) | Duplicato di `ExternalApiClient::multipartRequest()` |
| `GareService.php` | `externalUrl()` (~10 righe) | Duplicato di `ExternalApiClient::buildUrl()` |
| `GareService.php` | `authHeaders()` (~20 righe) | Duplicato di `ExternalApiClient::buildHeaders()` |
| `GareService.php` | `applyCurlExtras()` (~15 righe) | Duplicato di `ExternalApiClient::applyCurlOptions()` |
| `GareService.php` | Tutti i `?? $env['PDF_API_BASE']` e `?? $env['PDF_API_KEY']` | Fallback legacy rimossi |
| `config/.env` | `AI_API_START_URL`, `AI_API_STATUS_URL_TPL`, `AI_API_RESULTS_URL_TPL` | Template URL non più necessari |

### 5.2 Nessun file nuovo

Tutti i cambiamenti avvengono nei file esistenti. Nessun file creato, nessun file duplicato.

### 5.3 Nessun alias di compatibilità

I path legacy (`/api/...`) non vengono mantenuti nel codice. Switch netto a v1.

### 5.4 Singleton e cache

`ExternalApiClient::getInstance()` usa un singleton statico. Dopo la migrazione, il singleton viene rigenerato ad ogni richiesta PHP (Apache per-request lifecycle), quindi non c'è rischio di config stale. Stesso discorso per `GareService::loadEnvConfig()` che usa `static $cache`.

---

## 6. Flusso dati aggiornato

### Upload
```
Frontend (gare_list.js)
  → checkQuota(needed) → GareService → ExternalApiClient → GET /api/v1/quota/check
  → [se ok] uploadExtraction → GareService::upload()
    → ExternalApiClient::analyzeSingleFile() → POST /api/v1/batch/analyze
    → Salva ext_job_id, ext_batch_id in ext_jobs
```

### Polling
```
Frontend (gare_list.js) polling
  → jobPull → GareService::jobPull()
    → ExternalApiClient::getBatchStatus() → GET /api/v1/batch/{id}/status
    → [se completed] ExternalApiClient::getBatchResults() → GET /api/v1/batch/{id}/results
    → mapExternalAnswersFromBatch() → StorageManager::replaceExtractions()
    → GaraDataNormalizer::normalizeAll()
```

### Visualizzazione risultati
```
Frontend (gare_detail.js)
  → loadExtractions → GareService::jobResults()
    → ExtractionBuilder (costruisce display)
    → ExtractionFormatter (ordina, formatta)
  → [per ogni highlighted_pdf] mostra pulsante download
  → getBatchUsage → mostra costi
  → apiHealth + getQuota → mostra stato API
```

---

## 7. Sicurezza

- Tutte le nuove action richiedono login + CSRF (gestiti da ajax.php)
- `downloadHighlightedPdf` fa proxy server-side (non espone URL API al frontend)
- `downloadHighlightedPdf`: il parametro `filename` viene sanitizzato contro path traversal: solo `[a-zA-Z0-9._-]` ammessi, nessun `../` o `..\\`
- `apiHealth` e `getQuota` richiedono almeno `view_gare` permission
- L'API key non viene mai esposta al frontend
- `deleteRemoteJob` richiede `edit_gare` o `create_gare` permission
- `checkQuota`: il parametro `needed` viene validato come intero positivo (max 100)

---

## 8. Rischi e mitigazioni

| Rischio | Mitigazione |
|---------|-------------|
| API v1 cambia formato risposta | Il mapping in `mapExternalAnswersFromBatch` già gestisce strutture multiple (results, files, jobs) |
| Quota insufficiente | Pre-flight check prima dell'upload con messaggio chiaro |
| API offline | Health check mostra stato, upload disabilitato se offline |
| Tipi estrazione cambiano | Caricamento dinamico + fallback su ExtractionConstants |
