# Pulizia Post-Processing Estrazione Bandi — Design Spec

**Data:** 2026-03-18
**Obiettivo:** Eliminare il parsing ridondante nel post-processing delle estrazioni, sfruttando i dati già strutturati dall'API v1. Ridurre il tempo di post-processing da ~3-4s a <1s per job e rimuovere ~500+ righe di codice superfluo.

---

## 1. Problema attuale

Dopo che l'API v1 ritorna risultati strutturati, il backend fa 4 step sequenziali:

```
API v1 data (già strutturato)
  → mapSingleAnswer() ri-parsa data.date, data.url, data.entries  [SUPERFLUO]
  → upsertExtractions() salva in ext_extractions
  → ExtractionNormalizer legge ext_extractions → scrive ext_req_docs/econ/roles  [SECONDA LETTURA DB]
  → GaraDataNormalizer legge ext_extractions → scrive gar_gara_*  [TERZA LETTURA DB]
```

L'API v1 ritorna dati con questa struttura (esempio reale):
- **Date:** `{ date: { year: 2025, month: 9, day: 3, hour: 9, minute: 0 } }`
- **URL:** `{ url: "https://...", citations: [...] }`
- **Location:** `{ location: { city: "Broni", district: "PV", country: "Italia" } }`
- **Boolean:** `{ bool_answer: false, sopralluogo_status: "not_required" }`
- **Entries:** `{ entries: [{ id_opera_raw: "E.08", amount_eur: 4000000, ... }] }`
- **Citations:** sempre in `data.citations[]` con `page_number`, `text[]`, `reason_for_relevance`

`mapSingleAnswer()` (~250 righe) ri-estrae queste stesse cose per produrre `value_text` e `value_json` — lavoro che l'API ha già fatto.

---

## 2. Flusso semplificato

```
API v1 data (già strutturato)
  → saveApiResults() salva direttamente in ext_extractions  [1 STEP]
      value_json = data (senza chain_of_thought)
      value_text = extractDisplayValue(type, data)
      citations salvate da data.citations
  → GaraDataNormalizer + ext_req_* in un unico pass  [1 STEP]
```

---

## 3. Cambiamenti

### 3.1 Nuovo metodo: `saveApiResults(int $jobId, array $batchResults)`

Sostituisce `mapExternalAnswersFromBatch()` + `upsertExtractions()`. Per ogni risultato nel batch:

1. Prende `data` dall'API
2. Rimuove `chain_of_thought` (debug field, non va salvato)
3. Salva `data` come `value_json` in `ext_extractions`
4. Genera `value_text` con `extractDisplayValue($type, $data)`
5. Salva citations da `data.citations` in `ext_citations`
6. Salva table cells per tipi tabellari (entries/requirements)

**Dove:** In `GareService.php`, sostituisce le chiamate a righe 1898-1904.

### 3.2 Nuova funzione: `extractDisplayValue(string $type, array $data): ?string`

Funzione statica leggera (~50 righe) che dal tipo e dal data strutturato estrae il display value:

| Tipo | Campo sorgente | Esempio output |
|------|---------------|----------------|
| `data_scadenza_*`, `data_uscita_*` | `data.date.{year,month,day}` | `"03-09-2025"` |
| `luogo_provincia_*` | `data.location.{city,district}` | `"Broni (PV)"` |
| `link_portale_*` | `data.url` | `"https://..."` |
| `sopralluogo_*` | `data.bool_answer` | `"Sì"` / `"No"` |
| `oggetto_appalto` | `data.project_name` o primo citation text | `"Realizzazione nuovo Liceo..."` |
| `stazione_appaltante` | `data.answer` o `data.entity_name` | `"PROVINCIA DI PAVIA"` |
| `tipologia_*`, `settore_*` | `data.answer` | `"Procedura aperta"` |
| `importi_*`, `documentazione_*`, `requisiti_*`, `fatturato_*`, `criteri_*`, `documenti_*` | (tabellare) | `null` |

Non fa parsing — seleziona solo il campo giusto. Usa `ExtractionFormatter::formatLocationValue()` per location (già esiste) e `ExtractionFormatter::formatDateDisplay()` per date (già esiste).

### 3.3 Pulizia `cleanValueJson()` integrata

Oggi `cleanValueJson()` rimuove campi di debug. Nel nuovo flusso, la pulizia avviene dentro `saveApiResults()` prima del salvataggio: si rimuovono `chain_of_thought`, `reasoning`, `processing_time`, `error`, `error_details` dal `data` prima di salvarlo come `value_json`.

### 3.4 Consolidamento normalizzatori

**ExtractionNormalizer** viene eliminato come classe separata. La sua logica per popolare `ext_req_docs`, `ext_req_econ`, `ext_req_roles` viene spostata dentro `GaraDataNormalizer.normalizeAll()` come step aggiuntivo.

Il flusso in `jobPull()` diventa:
```php
// Salva risultati API direttamente
self::saveApiResults($jobId, $results['body']);

// Un unico normalizzatore per tutto
$normalizer = new GaraDataNormalizer($pdo);
$normalizer->normalizeAll($jobId); // ora include anche ext_req_*
```

**Metodi da spostare da ExtractionNormalizer a GaraDataNormalizer:**
- `processDocumentazioneRichiesta()` → `normalizeDocumentazione()`
- `processFatturatoGlobale()` → già coperto da `normalizeFatturatoMinimo()`
- `processRequisitiEconomici()` → già coperto da `normalizeRequisitiTecnici()` / `normalizeCapacitaEconFin()`
- `processRequisitiRuoli()` → già coperto da `normalizeIdoneitaProfessionale()`

**Nota:** I metodi duplicati (fatturato, requisiti economici, ruoli) sono già implementati in GaraDataNormalizer. Solo `processDocumentazioneRichiesta()` va spostato perché `ext_req_docs` è popolata solo da ExtractionNormalizer.

### 3.5 Semplificazione `expandEnvPlaceholders()`

Con i template URL rimossi dal .env, non ci sono più pattern `${VAR}` da sostituire. Il metodo diventa:

```php
public static function expandEnvPlaceholders(array $env): array
{
    return $env; // Nessun template URL da espandere con v1
}
```

Mantenuto come metodo (non eliminato) per non rompere i 12 call site, ma il corpo è un semplice return.

---

## 4. Codice eliminato

| Cosa | File | Righe stimate | Motivo |
|------|------|---------------|--------|
| `mapExternalAnswersFromBatch()` | GareService.php | ~50 | Sostituito da `saveApiResults()` |
| `mapSingleAnswer()` | GareService.php | ~250 | L'API v1 fornisce dati già strutturati |
| `ExtractionNormalizer.php` | AIextraction/ | ~430 | Consolidato in GaraDataNormalizer |
| `processNormalizedRequirements()` | GareService.php | ~10 | Wrapper per ExtractionNormalizer eliminato |
| Corpo di `expandEnvPlaceholders()` | GareService.php | ~25 | Ridotto a `return $env` |
| **Totale** | | **~765 righe** | |

## 5. Codice invariato

| Cosa | Motivo |
|------|--------|
| `ExtractionBuilder.php` | Legge da ext_extractions e ext_req_* — continua a funzionare identico |
| `ExtractionFormatter.php` | Formatta per display — invariato |
| `StorageManager.php` | CRUD su ext_* — invariato |
| `GaraDataNormalizer.php` | Resta, acquisisce normalizzazione ext_req_docs |
| `ExtractionConstants.php` | Invariato |
| Frontend (gare_detail.js, gare_list.js) | Invariato — riceve gli stessi dati |

## 6. Rischi e mitigazioni

| Rischio | Mitigazione |
|---------|-------------|
| `value_json` cambia formato | Il nuovo formato è lo stesso `data` dell'API — ExtractionBuilder già lo gestisce perché mapSingleAnswer() salvava `data` come `valueJson` |
| `ext_req_docs` non viene più popolata | Spostata la logica in GaraDataNormalizer |
| Estrazioni vecchie (pre-migrazione) | Già salvate in ext_extractions con formato compatibile — non vengono ri-processate |
| `extractDisplayValue` non copre un tipo | Fallback: `data.answer` se esiste, altrimenti `null` |

## 7. Impatto performance

- **Prima:** 3 letture DB (ext_extractions × 3) + parsing JSON ridondante = ~3-4s
- **Dopo:** 1 scrittura DB + 1 lettura (GaraDataNormalizer) = ~0.5-1s
- **Risparmio netto:** ~2-3 secondi per job al completamento
