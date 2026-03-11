<?php

namespace Services\AIextraction;

/**
 * Normalizza dati grezzi di estrazioni nelle tabelle gar_gara_*.
 * 
 * Responsabilità:
 * - Popola gar_gare_anagrafica (dati scalari: oggetto, stazione appaltante, ecc.)
 * - Popola gar_gara_importi_opere
 * - Popola gar_gara_corrispettivi_opere_fasi (dettaglio per fase) e gar_gara_corrispettivi_opere (totali per categoria)
 * - Popola gar_gara_requisiti_tecnici + gar_gara_requisiti_tecnici_categoria
 * - Popola gar_gara_fatturato_minimo
 * - Popola gar_gara_capacita_econ_fin
 * - Popola gar_gara_idoneita_professionale (con extra_json strutturato)
 * - Gestione transazioni
 * 
 * IMPORTANTE:
 * - Questo normalizzatore viene chiamato automaticamente dopo il salvataggio
 *   delle estrazioni in ext_extractions (vedi GareService::jobPull, riga ~1470)
 * - Se le tabelle gar_gara_* non esistono, la normalizzazione viene saltata
 *   silenziosamente (vedi tableExists())
 * 
 * FONTE DI VERITÀ:
 * - Le tabelle ext_* (ext_extractions, ext_table_cells, ecc.) sono la fonte
 *   di verità principale e vengono usate dal frontend
 * - Le tabelle gar_gara_* sono tabelle normalizzate di dominio, utili per
 *   calcoli e analisi
 * 
 * REGOLE IMPORTANTI:
 * - MAI usare chain_of_thought, reasoning, citations o altri campi di debug
 *   come valori business (testi, importi, ecc.)
 * - Usare sempre extraction_id per tracciare la fonte dei dati
 * - I valori devono derivare da: answer, value, amount_eur, n_anni, ecc.
 *   (campi strutturati), mai da spiegazioni interne del modello
 */
class GaraDataNormalizer
{
    private $pdo;
    private $debugLogs = [];

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Normalizza tutti i dati di una gara
     * Punto di ingresso principale
     */
    public function normalizeAll(int $jobId): array
    {
        $this->addDebugLog("=== INIZIO normalizeAll per job {$jobId} ===");

        try {
            $this->pdo->beginTransaction();

            // Carica estrazioni (con extraction_id)
            $extractionsByType = $this->loadExtractions($jobId);

            if (empty($extractionsByType)) {
                $this->pdo->commit();
                return ['success' => false, 'message' => 'Nessuna estrazione trovata'];
            }

            // Normalizza per tipo
            $this->normalizeAnagrafica($jobId, $extractionsByType);
            $this->normalizeImportiOpere($jobId, $extractionsByType);
            $this->normalizeCorrispettiviOpere($jobId, $extractionsByType);
            $this->normalizeRequisitiTecnici($jobId, $extractionsByType);
        $this->normalizeFatturatoMinimo($jobId, $extractionsByType);
        // NOTA: gar_gara_capacita_econ_fin è stata rimossa - i dati vanno in gar_gara_requisiti_tecnici_categoria
        // $this->normalizeCapacitaEconFin($jobId, $extractionsByType);
        $this->normalizeIdoneitaProfessionale($jobId, $extractionsByType);

            $this->pdo->commit();
            $this->addDebugLog("Job {$jobId}: normalizeAll completato");

            return ['success' => true, 'message' => 'Normalizzazione completata'];

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $errorMsg = "Errore normalizzazione job {$jobId}: " . $e->getMessage();
            $this->addDebugLog($errorMsg);
            return ['success' => false, 'message' => $errorMsg];
        }
    }

    /**
     * Carica e raggruppa estrazioni per type_code
     * IMPORTANTE: Include extraction_id per tracciare la fonte
     */
    private function loadExtractions(int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, type_code, value_json, value_text FROM ext_extractions WHERE job_id = :job_id"
        );
        $stmt->execute([':job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $type = $row['type_code'] ?? '';
            if (empty($type)) {
                continue;
            }

            $valueJson = $row['value_json'] ?? null;
            if (is_string($valueJson)) {
                $valueJson = json_decode($valueJson, true);
            }

            // Filtra campi di debug da value_json
            if (is_array($valueJson)) {
                $valueJson = $this->cleanValueJson($valueJson);
            }

            if ($valueJson === null && empty($row['value_text'])) {
                continue;
            }

            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }

            $grouped[$type][] = [
                'extraction_id' => (int)$row['id'],
                'value_json' => $valueJson,
                'value_text' => $row['value_text'],
            ];
        }

        $this->addDebugLog("Job {$jobId}: Caricati " . count($rows) . " estrazioni, " . count($grouped) . " tipi");
        return $grouped;
    }

    /**
     * Rimuove campi di debug da value_json
     * IMPORTANTE: Non usare mai questi campi come valori business
     */
    private function cleanValueJson(array $json): array
    {
        $debugFields = [
            'chain_of_thought', 'chainOfThought', 'reasoning', 
            'processing_time', 'error', 'error_details',
            'empty_reason', 'reason', 'message', 'note', 'explanation',
            'raw_block', 'raw_text', 'debug', 'logs', 'citations'
        ];
        
        foreach ($debugFields as $field) {
            unset($json[$field]);
        }
        
        return $json;
    }

    /**
     * Estrae valore pulito da value_json (senza campi di debug)
     */
    private function extractCleanValue(?string $valueText, ?array $valueJson): ?string
    {
        // Priorità 1: value_text (se non contiene debug)
        if (!empty($valueText)) {
            $trimmed = trim($valueText);
            if ($trimmed !== '' && 
                strlen($trimmed) < 500 &&
                !preg_match('/^\s*[\{\[]/', $trimmed) &&
                stripos($trimmed, 'chain_of_thought') === false &&
                stripos($trimmed, 'reasoning') === false) {
                return $trimmed;
            }
        }

        // Priorità 2: value_json (campi accettabili)
        if (is_array($valueJson)) {
            $acceptableFields = ['answer', 'value', 'result', 'response', 'text', 'url'];
            foreach ($acceptableFields as $field) {
                if (isset($valueJson[$field])) {
                    $val = $valueJson[$field];
                    if (is_string($val) && strlen($val) < 500) {
                        return trim($val);
                    } elseif (is_scalar($val)) {
                        return (string)$val;
                    }
                }
            }
        }

        return null;
    }

    // ========== ANAGRAFICA (dati scalari) ==========

    /**
     * Normalizza dati anagrafici della gara
     * Popola gar_gare_anagrafica
     * 
     * Mapping type_code -> colonna:
     * - oggetto_appalto → oggetto_appalto (NOT NULL)
     * - stazione_appaltante → stazione_appaltante
     * - settore_industriale_gara_appalto → settore
     * - tipologia_di_gara → tipologia_gara
     * - tipologia_di_appalto → tipologia_appalto
     * - link_portale_stazione_appaltante → link_portale
     * - luogo_provincia_appalto → luogo, provincia (se separabili)
     * - data_uscita_gara_appalto → data_uscita (DATE)
     * - data_scadenza_gara_appalto → data_scadenza (DATE)
     * - sopralluogo_obbligatorio → sopralluogo_obbligatorio (0/1)
     */
    private function normalizeAnagrafica(int $jobId, array $extractionsByType): void
    {
        if (!$this->tableExists('gar_gare_anagrafica')) {
            $this->addDebugLog("Job {$jobId}: Tabella gar_gare_anagrafica non esiste, skip");
            return;
        }

        $data = ['job_id' => $jobId];

        // Mappa type_code -> colonna (escludendo settore che ha gestione speciale)
        $fieldMap = [
            'oggetto_appalto' => 'oggetto_appalto',
            'stazione_appaltante' => 'stazione_appaltante',
            'data_uscita_gara_appalto' => 'data_uscita',
            'data_scadenza_gara_appalto' => 'data_scadenza',
            'link_portale_stazione_appaltante' => 'link_portale',
            'tipologia_di_gara' => 'tipologia_gara',
            'tipologia_di_appalto' => 'tipologia_appalto',
        ];

        foreach ($fieldMap as $typeCode => $column) {
            if (!isset($extractionsByType[$typeCode])) {
                continue;
            }

            $ext = $extractionsByType[$typeCode][0];
            $value = $this->extractCleanValue($ext['value_text'], $ext['value_json']);

            if ($value === null) {
                continue;
            }

            // Trattamento speciale per date
            if (in_array($typeCode, ['data_uscita_gara_appalto', 'data_scadenza_gara_appalto'])) {
                $value = $this->normalizeDate($value);
            }

            $data[$column] = $value;
        }

        // Gestione settore_industriale_gara_appalto (gestione speciale)
        // IMPORTANTE: settore_raw = risposta grezza/verbosa, settore = etichetta sintetica in italiano
        if (isset($extractionsByType['settore_industriale_gara_appalto'])) {
            $ext = $extractionsByType['settore_industriale_gara_appalto'][0];
            $valueJson = $ext['value_json'] ?? null;
            
            // settore_raw = risposta grezza (anche in inglese)
            $settoreRaw = $ext['value_text'] ?? null;
            if (empty($settoreRaw) && is_array($valueJson)) {
                $settoreRaw = $valueJson['answer'] ?? $valueJson['text'] ?? null;
            }
            $data['settore_raw'] = $settoreRaw;
            
            // settore = etichetta sintetica in italiano con CPV codes
            $settoreSintetico = null;
            if (is_array($valueJson)) {
                // Cerca CPV codes nel JSON
                $cpvCodes = [];
                if (isset($valueJson['cpv_codes']) && is_array($valueJson['cpv_codes'])) {
                    $cpvCodes = $valueJson['cpv_codes'];
                } elseif (isset($valueJson['cpv']) && is_array($valueJson['cpv'])) {
                    $cpvCodes = $valueJson['cpv'];
                } elseif (isset($valueJson['answer']) && is_string($valueJson['answer'])) {
                    // Prova a estrarre CPV codes dal testo (es. "71250000-5", "79417000-0")
                    preg_match_all('/\b\d{8}-\d\b/', $valueJson['answer'], $matches);
                    if (!empty($matches[0])) {
                        $cpvCodes = $matches[0];
                    }
                }
                
                // Cerca descrizioni CPV
                $cpvDescriptions = [];
                if (isset($valueJson['cpv_descriptions']) && is_array($valueJson['cpv_descriptions'])) {
                    $cpvDescriptions = $valueJson['cpv_descriptions'];
                }
                
                // Costruisci etichetta sintetica
                if (!empty($cpvCodes) || !empty($cpvDescriptions)) {
                    $parts = [];
                    if (!empty($cpvDescriptions)) {
                        // Usa le descrizioni se disponibili
                        foreach ($cpvDescriptions as $desc) {
                            if (is_string($desc) && !empty($desc)) {
                                $parts[] = $desc;
                            }
                        }
                    }
                    if (empty($parts) && !empty($cpvCodes)) {
                        // Fallback: usa solo i codici CPV
                        $parts[] = 'CPV: ' . implode('; ', array_slice($cpvCodes, 0, 3));
                    }
                    if (!empty($cpvCodes)) {
                        $parts[] = '(' . implode('; ', array_slice($cpvCodes, 0, 3)) . ')';
                    }
                    $settoreSintetico = implode(' ', $parts);
                }
            }
            
            // Se non abbiamo costruito un settore sintetico, prova a estrarre dal testo grezzo
            if (empty($settoreSintetico) && !empty($settoreRaw)) {
                // Rimuovi spiegazioni in inglese tipo "The industrial sector is identified by..."
                $cleaned = preg_replace('/The industrial sector is identified by.*?CPV.*?codes?[:\s]*/i', '', $settoreRaw);
                $cleaned = preg_replace('/CPV\s*\(Common Procurement Vocabulary\)\s*codes?[:\s]*/i', 'CPV: ', $cleaned);
                $cleaned = trim($cleaned);
                
                // Se il testo pulito è ragionevolmente breve (< 200 caratteri) e non contiene spiegazioni, usalo
                if (strlen($cleaned) < 200 && 
                    stripos($cleaned, 'is identified') === false &&
                    stripos($cleaned, 'are the standardized') === false) {
                    $settoreSintetico = $cleaned;
                }
            }
            
            $data['settore'] = $settoreSintetico; // Può essere NULL se non riusciamo a sintetizzare
        }

        // Gestione luogo_provincia_appalto (può essere combinato)
        if (isset($extractionsByType['luogo_provincia_appalto'])) {
            $ext = $extractionsByType['luogo_provincia_appalto'][0];
            $locationValue = $this->extractCleanValue($ext['value_text'], $ext['value_json']);
            
            if ($locationValue) {
                // Prova a estrarre location strutturata da value_json
                $valueJson = $ext['value_json'] ?? null;
                if (is_array($valueJson) && isset($valueJson['location']) && is_array($valueJson['location'])) {
                    $loc = $valueJson['location'];
                    $data['luogo'] = $loc['city'] ?? $loc['entity_name'] ?? $locationValue;
                    $data['provincia'] = $loc['district'] ?? null;
                } else {
                    // Fallback: metti tutto in luogo
                    $data['luogo'] = $locationValue;
                    $data['provincia'] = null;
                }
            }
        }

        // Gestione sopralluogo_obbligatorio
        if (isset($extractionsByType['sopralluogo_obbligatorio'])) {
            $ext = $extractionsByType['sopralluogo_obbligatorio'][0];
            $valueJson = $ext['value_json'] ?? null;
            
            if (is_array($valueJson)) {
                $boolAnswer = $valueJson['bool_answer'] ?? $valueJson['sopralluogo_status'] ?? null;
                if ($boolAnswer !== null) {
                    $data['sopralluogo_obbligatorio'] = ($boolAnswer === true || $boolAnswer === 'required' || $boolAnswer === 'obbligatorio') ? 1 : 0;
                }
                
                // Eventuali info aggiuntive sul sopralluogo
                if (isset($valueJson['deadlines']) && is_array($valueJson['deadlines']) && !empty($valueJson['deadlines'])) {
                    $firstDeadline = $valueJson['deadlines'][0];
                    if (isset($firstDeadline['date'])) {
                        $date = $firstDeadline['date'];
                        if (is_array($date) && isset($date['year'], $date['month'], $date['day'])) {
                            $data['sopralluogo_deadline'] = sprintf('%04d-%02d-%02d', $date['year'], $date['month'], $date['day']);
                        }
                    }
                }
                
                if (isset($valueJson['booking_instructions'])) {
                    $data['sopralluogo_note'] = $valueJson['booking_instructions'];
                }
            }
        }

        // oggetto_appalto è NOT NULL, quindi se manca usiamo un placeholder
        if (empty($data['oggetto_appalto'])) {
            $data['oggetto_appalto'] = 'Non specificato';
        }

        $this->upsertAnagrafica($jobId, $data);
    }

    /**
     * Upsert dati anagrafici (job_id è UNIQUE)
     */
    private function upsertAnagrafica(int $jobId, array $data): void
    {
        $jobIdValue = $data['job_id'];
        unset($data['job_id']);

        $columns = array_keys($data);
        $sets = [];
        $params = [':job_id' => $jobIdValue];

        foreach ($columns as $col) {
            $params[':' . $col] = $data[$col];
            $sets[] = "`{$col}` = :{$col}";
        }

        $columnsList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        $valuesList = implode(', ', array_map(fn($c) => ":{$c}", $columns));
        $updateList = implode(', ', $sets);

        $sql = "INSERT INTO gar_gare_anagrafica (job_id, {$columnsList}) 
                VALUES (:job_id, {$valuesList})
                ON DUPLICATE KEY UPDATE {$updateList}";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $this->addDebugLog("Job {$jobId}: gar_gare_anagrafica normalizzata");
        } catch (\PDOException $e) {
            $this->addDebugLog("Job {$jobId}: Errore gar_gare_anagrafica: " . $e->getMessage());
        }
    }

    // ========== IMPORTI OPERE ==========

    /**
     * Normalizza importi opere per categoria
     * Popola gar_gara_importi_opere
     * 
     * Fonte: type_code = importi_opere_per_categoria_id_opere
     * Per ogni entry: job_id, extraction_id, id_opera, lookup gar_opere_dm50,
     * importo_lavori_eur, importo_lavori_raw
     */
    private function normalizeImportiOpere(int $jobId, array $extractionsByType): void
    {
        if (!isset($extractionsByType['importi_opere_per_categoria_id_opere'])) {
            return;
        }

        if (!$this->tableExists('gar_gara_importi_opere')) {
            $this->addDebugLog("Job {$jobId}: Tabella gar_gara_importi_opere non esiste");
            return;
        }

        // Nota: Usiamo ON DUPLICATE KEY UPDATE per gestire eventuali vincoli UNIQUE su (job_id, id_opera)
        // Non eliminiamo i vecchi dati qui per evitare race conditions

        $extractions = $extractionsByType['importi_opere_per_categoria_id_opere'];

        foreach ($extractions as $ext) {
            $extractionId = $ext['extraction_id'];
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson) || empty($valueJson['entries'])) {
                continue;
            }

            // Raccogli id_opera per lookup
            $idOpereArray = [];
            foreach ($valueJson['entries'] as $entry) {
                if (!is_array($entry)) continue;
                $id = $entry['id_opera'] ?? $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? null;
                if ($id) $idOpereArray[] = (string)$id;
            }

            $opereDm50Data = $this->getOpereDm50Data($idOpereArray);

            // Inserisci entries
            foreach ($valueJson['entries'] as $entry) {
                if (!is_array($entry)) continue;

                $idOpera = $entry['id_opera'] ?? $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? '';
                $idOperaStr = (string)$idOpera;
                $idOperaRaw = $entry['id_opera_raw'] ?? $idOperaStr;

                // Lookup gar_opere_dm50
                $garOperaId = null;
                $categoria = null;
                $identificazioneOpera = null;
                $complessitaDm50 = null;

                if ($idOperaStr && isset($opereDm50Data[$idOperaStr])) {
                    $dm50 = $opereDm50Data[$idOperaStr];
                    $categoria = $dm50['categoria'] ?? null;
                    $identificazioneOpera = $dm50['identificazione_opera'] ?? null;
                    $complessitaDm50 = $dm50['complessita'] ?? null;
                    
                    // Cerca gar_opera_id (PK di gar_opere_dm50)
                    try {
                        $stmt = $this->pdo->prepare("SELECT id FROM gar_opere_dm50 WHERE id_opera = :id_opera LIMIT 1");
                        $stmt->execute([':id_opera' => $idOperaStr]);
                        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                        if ($row) {
                            $garOperaId = (int)$row['id'];
                        }
                    } catch (\PDOException $e) {
                        // Ignora se non trovato
                    }
                }

                // Importo lavori
                $importoLavoriEur = null;
                $importoLavoriRaw = null;
                
                if (isset($entry['amount_eur']) && is_numeric($entry['amount_eur'])) {
                    $importoLavoriEur = (float)$entry['amount_eur'];
                }
                
                if (isset($entry['amount_raw'])) {
                    $importoLavoriRaw = $entry['amount_raw'];
                }

                try {
                    $sql = "INSERT INTO gar_gara_importi_opere 
                            (job_id, extraction_id, id_opera, id_opera_raw, gar_opera_id, categoria, identificazione_opera, complessita_dm50, importo_lavori_eur, importo_lavori_raw, note)
                            VALUES (:job_id, :extraction_id, :id_opera, :id_opera_raw, :gar_opera_id, :categoria, :identificazione_opera, :complessita_dm50, :importo_lavori_eur, :importo_lavori_raw, :note)
                            ON DUPLICATE KEY UPDATE
                            extraction_id = VALUES(extraction_id),
                            id_opera_raw = VALUES(id_opera_raw),
                            gar_opera_id = VALUES(gar_opera_id),
                            categoria = VALUES(categoria),
                            identificazione_opera = VALUES(identificazione_opera),
                            complessita_dm50 = VALUES(complessita_dm50),
                            importo_lavori_eur = VALUES(importo_lavori_eur),
                            importo_lavori_raw = VALUES(importo_lavori_raw),
                            note = VALUES(note)";

                    $stmt = $this->pdo->prepare($sql);
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
                } catch (\PDOException $e) {
                    $this->addDebugLog("Job {$jobId}: Errore insert importi_opere: " . $e->getMessage());
                }
            }
        }

        $this->addDebugLog("Job {$jobId}: gar_gara_importi_opere normalizzata");
    }

    // ========== CORRISPETTIVI OPERE ==========

    /**
     * Normalizza corrispettivi opere
     * Popola gar_gara_corrispettivi_opere_fasi (dettaglio per fase) e gar_gara_corrispettivi_opere (totali per categoria)
     * 
     * Fonte: type_code = importi_corrispettivi_categoria_id_opere
     * 
     * Logica:
     * 1. Per ogni entry con service_phase → inserisci in gar_gara_corrispettivi_opere_fasi
     * 2. Calcola totali per categoria (job_id, id_opera) → inserisci/aggiorna in gar_gara_corrispettivi_opere
     * 
     * IMPORTANTE: gar_gara_corrispettivi_opere rappresenta il corrispettivo TOTALE per categoria,
     * NON per fase. Una sola riga per (job_id, id_opera) con UNIQUE KEY.
     */
    private function normalizeCorrispettiviOpere(int $jobId, array $extractionsByType): void
    {
        if (!isset($extractionsByType['importi_corrispettivi_categoria_id_opere'])) {
            return;
        }

        // Verifica tabelle esistano
        $hasFasiTable = $this->tableExists('gar_gara_corrispettivi_opere_fasi');
        $hasTotaliTable = $this->tableExists('gar_gara_corrispettivi_opere');
        
        if (!$hasFasiTable && !$hasTotaliTable) {
            return;
        }

        $extractions = $extractionsByType['importi_corrispettivi_categoria_id_opere'];
        $extractionIdMain = null; // Estrazione principale per i totali

        // Raccogli tutte le id_opera per lookup batch
        $idOpereArray = [];
        foreach ($extractions as $ext) {
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson) || empty($valueJson['entries'])) {
                continue;
            }
            
            if ($extractionIdMain === null) {
                $extractionIdMain = $ext['extraction_id'];
            }
            
            foreach ($valueJson['entries'] as $entry) {
                if (!is_array($entry)) continue;
                $id = $entry['category_id'] ?? $entry['id_opera'] ?? $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? null;
                if ($id) $idOpereArray[] = (string)$id;
            }
        }

        $opereDm50Data = $this->getOpereDm50Data($idOpereArray);

        // Accumulatore per calcolare totali per categoria
        $totaliPerCategoria = []; // [id_opera => [importo_totale, dati_comuni]]

        // STEP 1: Inserisci dettaglio per fase in gar_gara_corrispettivi_opere_fasi
        foreach ($extractions as $ext) {
            $extractionId = $ext['extraction_id'];
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson) || empty($valueJson['entries'])) {
                continue;
            }

            foreach ($valueJson['entries'] as $entry) {
                if (!is_array($entry)) continue;

                // Estrai id_opera
                $idOpera = $entry['category_id'] ?? $entry['id_opera'] ?? $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? '';
                $idOperaStr = (string)$idOpera;
                if (empty($idOperaStr)) {
                    continue; // Skip se non c'è id_opera
                }
                
                $idOperaRaw = $entry['id_opera_raw'] ?? $idOperaStr;

                // Estrai service_phase (obbligatorio per la tabella fasi)
                // IMPORTANTE: service_phase può essere: feasibility_study, executive_project, 
                // safety_coordination, site_supervision, accounting, ecc.
                $servicePhase = $entry['service_phase'] ?? null;
                if (empty($servicePhase) || !is_string($servicePhase)) {
                    // Se non c'è service_phase, salta questa entry per la tabella fasi
                    // ma continua per calcolare i totali (accumula comunque l'importo)
                }

                // Lookup gar_opere_dm50
                $garOperaId = null;
                $categoria = null;
                $identificazioneOpera = null;
                $gradoComplessita = null;
                $gradoComplessitaRaw = null;

                // Priorità 1: usa category_name dall'entry se disponibile
                if (isset($entry['category_name']) && !empty($entry['category_name'])) {
                    $categoria = $entry['category_name'];
                }

                // Priorità 2: lookup in gar_opere_dm50
                if (isset($opereDm50Data[$idOperaStr])) {
                    $dm50 = $opereDm50Data[$idOperaStr];
                    // Usa categoria da DM50 solo se non l'abbiamo già dall'entry
                    if (empty($categoria)) {
                        $categoria = $dm50['categoria'] ?? null;
                    }
                    $identificazioneOpera = $dm50['identificazione_opera'] ?? null;
                    $gradoComplessita = $dm50['complessita'] ?? null;
                    
                    // Cerca gar_opera_id
                    try {
                        $stmt = $this->pdo->prepare("SELECT id FROM gar_opere_dm50 WHERE id_opera = :id_opera LIMIT 1");
                        $stmt->execute([':id_opera' => $idOperaStr]);
                        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                        if ($row) {
                            $garOperaId = (int)$row['id'];
                        }
                    } catch (\PDOException $e) {
                        // Ignora
                    }
                } else {
                    // Se non c'è lookup in DM50, prova comunque a cercare gar_opera_id
                    try {
                        $stmt = $this->pdo->prepare("SELECT id FROM gar_opere_dm50 WHERE id_opera = :id_opera LIMIT 1");
                        $stmt->execute([':id_opera' => $idOperaStr]);
                        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                        if ($row) {
                            $garOperaId = (int)$row['id'];
                        }
                    } catch (\PDOException $e) {
                        // Ignora
                    }
                }

                // Complessità (priorità: dall'entry, poi da DM50)
                if (isset($entry['complexity']) && is_numeric($entry['complexity'])) {
                    $gradoComplessita = (float)$entry['complexity'];
                }
                if (isset($entry['complexity_raw'])) {
                    $gradoComplessitaRaw = $entry['complexity_raw'];
                }

                // Importo corrispettivo
                $importoCorrispettivoEur = null;
                $importoCorrispettivoRaw = null;
                
                if (isset($entry['amount_eur']) || isset($entry['fee_amount']) || isset($entry['corrispettivo_eur'])) {
                    $val = $entry['amount_eur'] ?? $entry['fee_amount'] ?? $entry['corrispettivo_eur'];
                    if (is_numeric($val)) {
                        $importoCorrispettivoEur = (float)$val;
                    }
                }
                
                if (isset($entry['amount_raw']) || isset($entry['fee_amount_raw'])) {
                    $importoCorrispettivoRaw = $entry['amount_raw'] ?? $entry['fee_amount_raw'];
                }

                $sourcePage = $entry['source_page'] ?? $entry['page'] ?? null;

                // Inserisci in gar_gara_corrispettivi_opere_fasi (solo se c'è service_phase)
                if ($hasFasiTable && !empty($servicePhase)) {
                    try {
                        $sql = "INSERT INTO gar_gara_corrispettivi_opere_fasi 
                                (job_id, extraction_id, id_opera, id_opera_raw, gar_opera_id, categoria, identificazione_opera, grado_complessita, grado_complessita_raw, service_phase, importo_corrispettivo_eur, importo_corrispettivo_raw, source_page, note)
                                VALUES (:job_id, :extraction_id, :id_opera, :id_opera_raw, :gar_opera_id, :categoria, :identificazione_opera, :grado_complessita, :grado_complessita_raw, :service_phase, :importo_corrispettivo_eur, :importo_corrispettivo_raw, :source_page, :note)
                                ON DUPLICATE KEY UPDATE
                                extraction_id = VALUES(extraction_id),
                                id_opera_raw = VALUES(id_opera_raw),
                                gar_opera_id = VALUES(gar_opera_id),
                                categoria = VALUES(categoria),
                                identificazione_opera = VALUES(identificazione_opera),
                                grado_complessita = VALUES(grado_complessita),
                                grado_complessita_raw = VALUES(grado_complessita_raw),
                                importo_corrispettivo_eur = VALUES(importo_corrispettivo_eur),
                                importo_corrispettivo_raw = VALUES(importo_corrispettivo_raw),
                                source_page = VALUES(source_page),
                                note = VALUES(note)";

                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute([
                            ':job_id' => $jobId,
                            ':extraction_id' => $extractionId,
                            ':id_opera' => $idOperaStr,
                            ':id_opera_raw' => $idOperaRaw ?: null,
                            ':gar_opera_id' => $garOperaId,
                            ':categoria' => $categoria,
                            ':identificazione_opera' => $identificazioneOpera,
                            ':grado_complessita' => $gradoComplessita,
                            ':grado_complessita_raw' => $gradoComplessitaRaw,
                            ':service_phase' => $servicePhase,
                            ':importo_corrispettivo_eur' => $importoCorrispettivoEur,
                            ':importo_corrispettivo_raw' => $importoCorrispettivoRaw,
                            ':source_page' => $sourcePage,
                            ':note' => null,
                        ]);
                    } catch (\PDOException $e) {
                        $this->addDebugLog("Job {$jobId}: Errore insert corrispettivi_fasi: " . $e->getMessage());
                    }
                }

                // Accumula per calcolo totali (anche se non c'è service_phase)
                // IMPORTANTE: accumula sempre, anche se service_phase manca, così possiamo popolare gar_gara_corrispettivi_opere
                if ($importoCorrispettivoEur !== null && $importoCorrispettivoEur > 0) {
                    if (!isset($totaliPerCategoria[$idOperaStr])) {
                        $totaliPerCategoria[$idOperaStr] = [
                            'importo_totale' => 0.0,
                            'id_opera_raw' => $idOperaRaw,
                            'gar_opera_id' => $garOperaId,
                            'categoria' => $categoria, // Può venire da category_name o da DM50
                            'identificazione_opera' => $identificazioneOpera,
                            'grado_complessita' => $gradoComplessita,
                            'grado_complessita_raw' => $gradoComplessitaRaw,
                        ];
                    } else {
                        // Aggiorna categoria se non era stata impostata prima
                        if (empty($totaliPerCategoria[$idOperaStr]['categoria']) && !empty($categoria)) {
                            $totaliPerCategoria[$idOperaStr]['categoria'] = $categoria;
                        }
                        // Aggiorna altri campi se mancanti
                        if (empty($totaliPerCategoria[$idOperaStr]['identificazione_opera']) && !empty($identificazioneOpera)) {
                            $totaliPerCategoria[$idOperaStr]['identificazione_opera'] = $identificazioneOpera;
                        }
                        if ($garOperaId !== null && $totaliPerCategoria[$idOperaStr]['gar_opera_id'] === null) {
                            $totaliPerCategoria[$idOperaStr]['gar_opera_id'] = $garOperaId;
                        }
                    }
                    $totaliPerCategoria[$idOperaStr]['importo_totale'] += $importoCorrispettivoEur;
                }
            }
        }

        // STEP 2: Calcola totali per categoria e inserisci/aggiorna in gar_gara_corrispettivi_opere
        // IMPORTANTE: Se la tabella fasi esiste e ha dati, calcoliamo i totali da lì (più accurato).
        // Altrimenti usiamo i totali accumulati sopra (anche se service_phase mancava).
        $totaliFromFasi = []; // Inizializza per evitare undefined variable
        if ($hasTotaliTable) {
            if ($hasFasiTable) {
                // Prova a calcolare totali dalla tabella fasi (se ci sono dati)
                try {
                    $stmt = $this->pdo->prepare(
                        "SELECT 
                            id_opera,
                            MAX(id_opera_raw) as id_opera_raw,
                            MAX(gar_opera_id) as gar_opera_id,
                            MAX(categoria) as categoria,
                            MAX(identificazione_opera) as identificazione_opera,
                            MAX(grado_complessita) as grado_complessita,
                            MAX(grado_complessita_raw) as grado_complessita_raw,
                            SUM(importo_corrispettivo_eur) as importo_totale_eur
                         FROM gar_gara_corrispettivi_opere_fasi
                         WHERE job_id = :job_id
                         GROUP BY id_opera"
                    );
                    $stmt->execute([':job_id' => $jobId]);
                    $totaliFromFasi = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                    // Se ci sono dati dalla tabella fasi, usali
                    if (!empty($totaliFromFasi)) {
                        foreach ($totaliFromFasi as $totale) {
                            $idOperaStr = $totale['id_opera'] ?? '';
                            if (empty($idOperaStr)) {
                                continue;
                            }

                            try {
                                $sql = "INSERT INTO gar_gara_corrispettivi_opere 
                                        (job_id, extraction_id, id_opera, id_opera_raw, gar_opera_id, categoria, identificazione_opera, grado_complessita, grado_complessita_raw, importo_corrispettivo_eur, importo_corrispettivo_raw, note)
                                        VALUES (:job_id, :extraction_id, :id_opera, :id_opera_raw, :gar_opera_id, :categoria, :identificazione_opera, :grado_complessita, :grado_complessita_raw, :importo_corrispettivo_eur, :importo_corrispettivo_raw, :note)
                                        ON DUPLICATE KEY UPDATE
                                        extraction_id = VALUES(extraction_id),
                                        id_opera_raw = VALUES(id_opera_raw),
                                        gar_opera_id = VALUES(gar_opera_id),
                                        categoria = VALUES(categoria),
                                        identificazione_opera = VALUES(identificazione_opera),
                                        grado_complessita = VALUES(grado_complessita),
                                        grado_complessita_raw = VALUES(grado_complessita_raw),
                                        importo_corrispettivo_eur = VALUES(importo_corrispettivo_eur),
                                        importo_corrispettivo_raw = VALUES(importo_corrispettivo_raw),
                                        note = VALUES(note)";

                                $stmt = $this->pdo->prepare($sql);
                                $stmt->execute([
                                    ':job_id' => $jobId,
                                    ':extraction_id' => $extractionIdMain,
                                    ':id_opera' => $idOperaStr,
                                    ':id_opera_raw' => $totale['id_opera_raw'],
                                    ':gar_opera_id' => $totale['gar_opera_id'] ? (int)$totale['gar_opera_id'] : null,
                                    ':categoria' => $totale['categoria'],
                                    ':identificazione_opera' => $totale['identificazione_opera'],
                                    ':grado_complessita' => $totale['grado_complessita'] ? (float)$totale['grado_complessita'] : null,
                                    ':grado_complessita_raw' => $totale['grado_complessita_raw'],
                                    ':importo_corrispettivo_eur' => $totale['importo_totale_eur'] ? (float)$totale['importo_totale_eur'] : null,
                                    ':importo_corrispettivo_raw' => null, // Non abbiamo un raw totale
                                    ':note' => null,
                                ]);
                            } catch (\PDOException $e) {
                                $this->addDebugLog("Job {$jobId}: Errore insert corrispettivi totali: " . $e->getMessage());
                            }
                        }
                    } else {
                        // Tabella fasi vuota (probabilmente service_phase mancava), usa totali accumulati
                        $this->addDebugLog("Job {$jobId}: Tabella fasi vuota, uso totali accumulati");
                        // Continua con il codice sotto (else branch)
                    }
                } catch (\PDOException $e) {
                    $this->addDebugLog("Job {$jobId}: Errore calcolo totali da fasi: " . $e->getMessage());
                    // Continua con i totali accumulati
                }
            }
            
            // Usa totali accumulati (se tabella fasi non esiste o è vuota)
            // IMPORTANTE: anche se la tabella fasi esiste ma è vuota (service_phase mancava),
            // dobbiamo comunque popolare gar_gara_corrispettivi_opere usando i totali accumulati
            $totaliFromFasi = $totaliFromFasi ?? [];
            if (!$hasFasiTable || empty($totaliFromFasi)) {
                // Tabella fasi non esiste o è vuota, usa totali accumulati
                foreach ($totaliPerCategoria as $idOperaStr => $totale) {
                    try {
                        $sql = "INSERT INTO gar_gara_corrispettivi_opere 
                                (job_id, extraction_id, id_opera, id_opera_raw, gar_opera_id, categoria, identificazione_opera, grado_complessita, grado_complessita_raw, importo_corrispettivo_eur, importo_corrispettivo_raw, note)
                                VALUES (:job_id, :extraction_id, :id_opera, :id_opera_raw, :gar_opera_id, :categoria, :identificazione_opera, :grado_complessita, :grado_complessita_raw, :importo_corrispettivo_eur, :importo_corrispettivo_raw, :note)
                                ON DUPLICATE KEY UPDATE
                                extraction_id = VALUES(extraction_id),
                                id_opera_raw = VALUES(id_opera_raw),
                                gar_opera_id = VALUES(gar_opera_id),
                                categoria = VALUES(categoria),
                                identificazione_opera = VALUES(identificazione_opera),
                                grado_complessita = VALUES(grado_complessita),
                                grado_complessita_raw = VALUES(grado_complessita_raw),
                                importo_corrispettivo_eur = VALUES(importo_corrispettivo_eur),
                                importo_corrispettivo_raw = VALUES(importo_corrispettivo_raw),
                                note = VALUES(note)";

                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute([
                            ':job_id' => $jobId,
                            ':extraction_id' => $extractionIdMain,
                            ':id_opera' => $idOperaStr,
                            ':id_opera_raw' => $totale['id_opera_raw'],
                            ':gar_opera_id' => $totale['gar_opera_id'],
                            ':categoria' => $totale['categoria'],
                            ':identificazione_opera' => $totale['identificazione_opera'],
                            ':grado_complessita' => $totale['grado_complessita'],
                            ':grado_complessita_raw' => $totale['grado_complessita_raw'],
                            ':importo_corrispettivo_eur' => $totale['importo_totale'],
                            ':importo_corrispettivo_raw' => null,
                            ':note' => null,
                        ]);
                    } catch (\PDOException $e) {
                        $this->addDebugLog("Job {$jobId}: Errore insert corrispettivi totali: " . $e->getMessage());
                    }
                }
            }
        }

        $this->addDebugLog("Job {$jobId}: gar_gara_corrispettivi_opere normalizzata (fasi e totali)");
    }

    // ========== REQUISITI TECNICI ==========

    /**
     * Mappa requirement_type a label italiana per testo_sintetico
     */
    private function labelForRequirementType(string $type): string
    {
        switch ($type) {
            case 'registration':
                return 'Idoneità professionale e iscrizioni';
            case 'technical_director':
                return 'Direttore tecnico (società di ingegneria)';
            case 'team_composition':
                return 'Composizione minima del gruppo di lavoro';
            case 'young_professional':
                return 'Giovane professionista nei raggruppamenti';
            case 'experience':
                return 'Esperienza in servizi analoghi';
            default:
                return 'Requisito tecnico-professionale';
        }
    }

    /**
     * Normalizza requisiti tecnici
     * Popola gar_gara_requisiti_tecnici (una riga per ogni requirement) + gar_gara_requisiti_tecnici_categoria
     * 
     * Fonte principale: requisiti_tecnico_professionali (per testo generale)
     * Fonte per categoria: importi_requisiti_tecnici_categoria_id_opere
     */
    private function normalizeRequisitiTecnici(int $jobId, array $extractionsByType): void
    {
        if (!$this->tableExists('gar_gara_requisiti_tecnici')) {
            return;
        }

        // Elimina vecchi
        $this->pdo->prepare("DELETE FROM gar_gara_requisiti_tecnici WHERE job_id = :job_id")
            ->execute([':job_id' => $jobId]);
        
        if ($this->tableExists('gar_gara_requisiti_tecnici_categoria')) {
            $this->pdo->prepare("DELETE FROM gar_gara_requisiti_tecnici_categoria WHERE job_id = :job_id")
                ->execute([':job_id' => $jobId]);
        }

        // 1. gar_gara_requisiti_tecnici (una riga per ogni requirement)
        if (isset($extractionsByType['requisiti_tecnico_professionali'])) {
            $ext = $extractionsByType['requisiti_tecnico_professionali'][0];
            $extractionId = $ext['extraction_id'];
            $valueJson = $ext['value_json'] ?? null;
            
            if (empty($valueJson) || !is_array($valueJson)) {
                return;
            }
            
            // Estrai data.requirements dalla struttura AI
            $requirements = null;
            if (isset($valueJson['data']['requirements']) && is_array($valueJson['data']['requirements'])) {
                $requirements = $valueJson['data']['requirements'];
            } elseif (isset($valueJson['requirements']) && is_array($valueJson['requirements'])) {
                $requirements = $valueJson['requirements'];
            }
            
            if (empty($requirements) || !is_array($requirements)) {
                return;
            }
            
            // Inserisci ogni requirement come riga separata
            foreach ($requirements as $requirement) {
                if (!is_array($requirement)) {
                    continue;
                }
                
                // Costruisci requisito da requirement_type (NON da title che è in inglese)
                $requirementType = $requirement['requirement_type'] ?? '';
                $requisito = $this->labelForRequirementType($requirementType);
                
                // Costruisci descrizione SOLO da citations[].text (testi italiani)
                $citations = $requirement['citations'] ?? [];
                $descrizioneParts = [];
                if (is_array($citations)) {
                    foreach ($citations as $citation) {
                        if (!is_array($citation)) {
                            continue;
                        }
                        $text = trim((string) ($citation['text'] ?? ''));
                        if ($text !== '') {
                            $descrizioneParts[] = $text;
                        }
                    }
                }
                $descrizione = implode("\n\n", $descrizioneParts);
                
                // Costruisci raw_json con solo i campi permessi
                $refPayload = [
                    'is_mandatory' => $requirement['is_mandatory'] ?? null,
                    'legal_reference' => $requirement['legal_reference'] ?? null,
                    'source_location' => $requirement['source_location'] ?? null,
                    'requirement_type' => $requirement['requirement_type'] ?? null,
                    'experience_details' => $requirement['experience_details'] ?? null,
                    'team_details' => $requirement['team_details'] ?? null,
                    'young_professional_details' => $requirement['young_professional_details'] ?? null,
                ];
                $rawJson = json_encode($refPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                
                // Inserisci la riga
                try {
                    $insertStmt = $this->pdo->prepare("INSERT INTO gar_gara_requisiti_tecnici 
                        (job_id, extraction_id, testo_sintetico, testo_completo, raw_json, note) 
                        VALUES (:job_id, :extraction_id, :testo_sintetico, :testo_completo, :raw_json, NULL)");
                    $insertStmt->execute([
                        ':job_id' => $jobId,
                        ':extraction_id' => $extractionId,
                        ':testo_sintetico' => $requisito,
                        ':testo_completo' => $descrizione,
                        ':raw_json' => $rawJson
                    ]);
                } catch (\PDOException $e) {
                    $this->addDebugLog("Job {$jobId}: Errore insert requisiti_tecnici: " . $e->getMessage());
                    continue;
                }
            }
        }

        // 2. gar_gara_requisiti_tecnici_categoria (per categoria/id_opera)
        // IMPORTANTE: popola importo_minimo_eur (1× lavori) e importo_minimo_punta_eur (0,6× servizi di punta)
        // Legge da: importi_requisiti_tecnici_categoria_id_opere.requirements[] e requisiti_di_capacita_economica_finanziaria.requirements[]
        if ($this->tableExists('gar_gara_requisiti_tecnici_categoria')) {
            $this->processRequisitiTecniciCategoria($jobId, $extractionsByType);
        }

        $this->addDebugLog("Job {$jobId}: gar_gara_requisiti_tecnici normalizzata");
    }

    /**
     * Processa requisiti tecnici per categoria
     * Popola gar_gara_requisiti_tecnici_categoria con:
     * - importo_minimo_eur: valori 1× lavori (da importi_requisiti_tecnici_categoria_id_opere o requisiti_di_capacita_economica_finanziaria con multiplier 1.0)
     * - importo_minimo_punta_eur: valori 0,6× servizi di punta (da requisiti_di_capacita_economica_finanziaria con multiplier 0.6)
     * 
     * Legge da:
     * - importi_requisiti_tecnici_categoria_id_opere.requirements[]
     * - requisiti_di_capacita_economica_finanziaria.requirements[] (se presente)
     */
    private function processRequisitiTecniciCategoria(int $jobId, array $extractionsByType): void
    {
        // Accumulatore: [id_opera => [dati_comuni, importo_minimo_eur, importo_minimo_punta_eur]]
        $requisitiPerCategoria = [];

        // STEP 1: Leggi da importi_requisiti_tecnici_categoria_id_opere (1× lavori)
        if (isset($extractionsByType['importi_requisiti_tecnici_categoria_id_opere'])) {
            $extractions = $extractionsByType['importi_requisiti_tecnici_categoria_id_opere'];
            foreach ($extractions as $ext) {
                $extractionId = $ext['extraction_id'];
                $valueJson = $ext['value_json'] ?? null;
                if (!is_array($valueJson) || empty($valueJson['requirements'])) {
                    continue;
                }

                foreach ($valueJson['requirements'] as $req) {
                    if (!is_array($req)) continue;

                    $idOpera = $req['id_opera'] ?? '';
                    $idOperaStr = (string)$idOpera;
                    if (empty($idOperaStr)) {
                        continue;
                    }

                    // Inizializza se non esiste
                    if (!isset($requisitiPerCategoria[$idOperaStr])) {
                        $requisitiPerCategoria[$idOperaStr] = [
                            'extraction_id' => $extractionId,
                            'id_opera_raw' => $req['id_opera_raw'] ?? $idOperaStr,
                            'importo_minimo_eur' => null,
                            'importo_minimo_raw' => null,
                            'importo_minimo_punta_eur' => null,
                            'importo_minimo_punta_raw' => null,
                        ];
                    }

                    // Estrai importo_minimo_eur (1× lavori)
                    // Priorità: minimum_amount_eur, poi base_value_eur (se coincidono nel JSON)
                    if (isset($req['minimum_amount_eur']) && is_numeric($req['minimum_amount_eur'])) {
                        $requisitiPerCategoria[$idOperaStr]['importo_minimo_eur'] = (float)$req['minimum_amount_eur'];
                    } elseif (isset($req['base_value_eur']) && is_numeric($req['base_value_eur'])) {
                        // Fallback: base_value_eur è spesso uguale a minimum_amount_eur quando multiplier = 1.0
                        $requisitiPerCategoria[$idOperaStr]['importo_minimo_eur'] = (float)$req['base_value_eur'];
                    }
                    
                    if (isset($req['minimum_amount_raw'])) {
                        $requisitiPerCategoria[$idOperaStr]['importo_minimo_raw'] = $req['minimum_amount_raw'];
                    } elseif (isset($req['amount_raw'])) {
                        $requisitiPerCategoria[$idOperaStr]['importo_minimo_raw'] = $req['amount_raw'];
                    }
                }
            }
        }

        // STEP 2: Leggi da requisiti_di_capacita_economica_finanziaria (1× e 0,6×)
        if (isset($extractionsByType['requisiti_di_capacita_economica_finanziaria'])) {
            $extractions = $extractionsByType['requisiti_di_capacita_economica_finanziaria'];
            foreach ($extractions as $ext) {
                $extractionId = $ext['extraction_id'];
                $valueJson = $ext['value_json'] ?? null;
                if (!is_array($valueJson) || empty($valueJson['requirements'])) {
                    continue;
                }

                foreach ($valueJson['requirements'] as $req) {
                    if (!is_array($req)) continue;

                    // Estrai multiplier per distinguere 1× da 0,6×
                    $multiplier = null;
                    if (isset($req['formula']) && is_array($req['formula'])) {
                        $multiplier = $req['formula']['multiplier'] ?? null;
                    }

                    // Estrai minimum_amount per categoria
                    $minimumAmount = $req['minimum_amount'] ?? null;
                    if (!is_array($minimumAmount) || empty($minimumAmount)) {
                        continue;
                    }

                    foreach ($minimumAmount as $amountEntry) {
                        if (!is_array($amountEntry)) continue;

                        $category = $amountEntry['category'] ?? null;
                        if (!is_array($category)) continue;

                        $idOpera = $category['id'] ?? '';
                        $idOperaStr = (string)$idOpera;
                        if (empty($idOperaStr)) {
                            continue;
                        }

                        // Inizializza se non esiste
                        if (!isset($requisitiPerCategoria[$idOperaStr])) {
                            $requisitiPerCategoria[$idOperaStr] = [
                                'extraction_id' => $extractionId,
                                'id_opera_raw' => $idOperaStr,
                                'importo_minimo_eur' => null,
                                'importo_minimo_raw' => null,
                                'importo_minimo_punta_eur' => null,
                                'importo_minimo_punta_raw' => null,
                            ];
                        }

                        $value = $amountEntry['value'] ?? null;
                        $raw = $amountEntry['raw'] ?? null;

                        // Assegna in base al multiplier
                        if ($multiplier !== null) {
                            if (abs($multiplier - 1.0) < 0.01) {
                                // 1× lavori → importo_minimo_eur
                                if (is_numeric($value)) {
                                    $requisitiPerCategoria[$idOperaStr]['importo_minimo_eur'] = (float)$value;
                                }
                                if (!empty($raw)) {
                                    $requisitiPerCategoria[$idOperaStr]['importo_minimo_raw'] = $raw;
                                }
                            } elseif (abs($multiplier - 0.6) < 0.01) {
                                // 0,6× servizi di punta → importo_minimo_punta_eur
                                if (is_numeric($value)) {
                                    $requisitiPerCategoria[$idOperaStr]['importo_minimo_punta_eur'] = (float)$value;
                                }
                                if (!empty($raw)) {
                                    $requisitiPerCategoria[$idOperaStr]['importo_minimo_punta_raw'] = $raw;
                                }
                            }
                        }
                    }
                }
            }
        }

        // STEP 3: Raccogli tutti gli id_opera per lookup batch in gar_opere_dm50
        $idOpereArray = array_keys($requisitiPerCategoria);
        $opereDm50Data = $this->getOpereDm50Data($idOpereArray);

        // STEP 4: Inserisci/aggiorna in gar_gara_requisiti_tecnici_categoria
        foreach ($requisitiPerCategoria as $idOperaStr => $data) {
            $garOperaId = null;
            $categoria = null;
            $identificazioneOpera = null;

            if (isset($opereDm50Data[$idOperaStr])) {
                $dm50 = $opereDm50Data[$idOperaStr];
                $categoria = $dm50['categoria'] ?? null;
                $identificazioneOpera = $dm50['identificazione_opera'] ?? null;
                
                try {
                    $stmt = $this->pdo->prepare("SELECT id FROM gar_opere_dm50 WHERE id_opera = :id_opera LIMIT 1");
                    $stmt->execute([':id_opera' => $idOperaStr]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($row) {
                        $garOperaId = (int)$row['id'];
                    }
                } catch (\PDOException $e) {
                    // Ignora
                }
            }

            try {
                $sql = "INSERT INTO gar_gara_requisiti_tecnici_categoria 
                        (job_id, extraction_id, id_opera, id_opera_raw, gar_opera_id, categoria, identificazione_opera, importo_minimo_eur, importo_minimo_raw, importo_minimo_punta_eur, note)
                        VALUES (:job_id, :extraction_id, :id_opera, :id_opera_raw, :gar_opera_id, :categoria, :identificazione_opera, :importo_minimo_eur, :importo_minimo_raw, :importo_minimo_punta_eur, :note)
                        ON DUPLICATE KEY UPDATE
                        extraction_id = VALUES(extraction_id),
                        id_opera_raw = VALUES(id_opera_raw),
                        gar_opera_id = VALUES(gar_opera_id),
                        categoria = VALUES(categoria),
                        identificazione_opera = VALUES(identificazione_opera),
                        importo_minimo_eur = VALUES(importo_minimo_eur),
                        importo_minimo_raw = VALUES(importo_minimo_raw),
                        importo_minimo_punta_eur = VALUES(importo_minimo_punta_eur),
                        note = VALUES(note)";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':job_id' => $jobId,
                    ':extraction_id' => $data['extraction_id'],
                    ':id_opera' => $idOperaStr,
                    ':id_opera_raw' => $data['id_opera_raw'] ?: null,
                    ':gar_opera_id' => $garOperaId,
                    ':categoria' => $categoria,
                    ':identificazione_opera' => $identificazioneOpera,
                    ':importo_minimo_eur' => $data['importo_minimo_eur'],
                    ':importo_minimo_raw' => $data['importo_minimo_raw'],
                    ':importo_minimo_punta_eur' => $data['importo_minimo_punta_eur'],
                    ':note' => null,
                ]);
            } catch (\PDOException $e) {
                $this->addDebugLog("Job {$jobId}: Errore insert requisiti_tecnici_categoria: " . $e->getMessage());
            }
        }
    }

    // ========== FATTURATO MINIMO ==========

    /**
     * Normalizza fatturato minimo
     * Popola gar_gara_fatturato_minimo
     * 
     * Fonte: type_code = fatturato_globale_n_minimo_anni
     * 
     * Mapping:
     * - anni_minimi: da turnover_requirement.single_requirement.temporal_calculation.periods_to_select
     * - importo_minimo_eur: da normalized_value (cast a DECIMAL)
     * - importo_minimo_raw: da minimum_amount / equivalente
     * - note: sintesi in italiano della regola (es. "migliori 3 anni degli ultimi 5, IVA esclusa")
     */
    private function normalizeFatturatoMinimo(int $jobId, array $extractionsByType): void
    {
        if (!isset($extractionsByType['fatturato_globale_n_minimo_anni'])) {
            return;
        }

        if (!$this->tableExists('gar_gara_fatturato_minimo')) {
            return;
        }

        $extractions = $extractionsByType['fatturato_globale_n_minimo_anni'];

        foreach ($extractions as $ext) {
            $extractionId = $ext['extraction_id'];
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson)) {
                continue;
            }

            // Estrai importo_minimo_eur da normalized_value (priorità) o importo_minimo
            $importoMinimo = null;
            if (isset($valueJson['normalized_value']) && is_numeric($valueJson['normalized_value'])) {
                $importoMinimo = (float)$valueJson['normalized_value'];
            } elseif (isset($valueJson['importo_minimo'])) {
                $val = $valueJson['importo_minimo'];
                if (is_numeric($val)) {
                    $importoMinimo = (float)$val;
                } elseif (is_string($val)) {
                    $importoMinimo = $this->normalizeImporto($val);
                }
            }

            if ($importoMinimo === null || $importoMinimo <= 0) {
                continue;
            }

            // Estrai importo_minimo_raw
            $importoMinimoRaw = $valueJson['minimum_amount'] ?? 
                               $valueJson['importo_minimo_raw'] ?? 
                               $valueJson['amount_raw'] ?? null;

            // Estrai anni_minimi da turnover_requirement.single_requirement.temporal_calculation.periods_to_select
            $anniMinimi = null;
            if (isset($valueJson['turnover_requirement']) && is_array($valueJson['turnover_requirement'])) {
                $tr = $valueJson['turnover_requirement'];
                if (isset($tr['single_requirement']) && is_array($tr['single_requirement'])) {
                    $sr = $tr['single_requirement'];
                    if (isset($sr['temporal_calculation']) && is_array($sr['temporal_calculation'])) {
                        $tc = $sr['temporal_calculation'];
                        if (isset($tc['periods_to_select']) && is_numeric($tc['periods_to_select'])) {
                            $anniMinimi = (int)$tc['periods_to_select'];
                        }
                    }
                }
            }
            
            // Fallback: cerca n_anni direttamente
            if ($anniMinimi === null && isset($valueJson['n_anni']) && is_numeric($valueJson['n_anni'])) {
                $anniMinimi = (int)$valueJson['n_anni'];
            }

            // Genera note sintetica in italiano
            $note = $this->generateItalianFatturatoNote($valueJson);

            try {
                $sql = "INSERT INTO gar_gara_fatturato_minimo 
                        (job_id, extraction_id, anni_minimi, importo_minimo_eur, importo_minimo_raw, note)
                        VALUES (:job_id, :extraction_id, :anni_minimi, :importo_minimo_eur, :importo_minimo_raw, :note)
                        ON DUPLICATE KEY UPDATE
                        extraction_id = VALUES(extraction_id),
                        anni_minimi = VALUES(anni_minimi),
                        importo_minimo_eur = VALUES(importo_minimo_eur),
                        importo_minimo_raw = VALUES(importo_minimo_raw),
                        note = VALUES(note)";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':job_id' => $jobId,
                    ':extraction_id' => $extractionId,
                    ':anni_minimi' => $anniMinimi,
                    ':importo_minimo_eur' => $importoMinimo,
                    ':importo_minimo_raw' => $importoMinimoRaw,
                    ':note' => $note,
                ]);
            } catch (\PDOException $e) {
                $this->addDebugLog("Job {$jobId}: Errore insert fatturato_minimo: " . $e->getMessage());
            }
        }

        $this->addDebugLog("Job {$jobId}: gar_gara_fatturato_minimo normalizzata");
    }

    // ========== CAPACITÀ ECONOMICO-FINANZIARIA ==========
    // DEPRECATO: gar_gara_capacita_econ_fin non deve più essere usata.
    // I dati vanno in gar_gara_requisiti_tecnici_categoria (importo_minimo_eur e importo_minimo_punta_eur).
    // Il metodo processRequisitiTecniciCategoria() legge anche da requisiti_di_capacita_economica_finanziaria.

    /**
     * @deprecated Questa tabella è stata rimossa. I dati vanno in gar_gara_requisiti_tecnici_categoria.
     */
    private function normalizeCapacitaEconFin(int $jobId, array $extractionsByType): void
    {
        if (!isset($extractionsByType['requisiti_di_capacita_economica_finanziaria'])) {
            return;
        }

        if (!$this->tableExists('gar_gara_capacita_econ_fin')) {
            return;
        }

        // Elimina vecchi
        $this->pdo->prepare("DELETE FROM gar_gara_capacita_econ_fin WHERE job_id = :job_id")
            ->execute([':job_id' => $jobId]);

        $extractions = $extractionsByType['requisiti_di_capacita_economica_finanziaria'];

        foreach ($extractions as $ext) {
            $extractionId = $ext['extraction_id'];
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson)) {
                continue;
            }

            // Cerca array di requisiti
            $requirements = $valueJson['requirements'] ?? $valueJson['entries'] ?? null;
            if (!is_array($requirements) || empty($requirements)) {
                continue;
            }

            foreach ($requirements as $req) {
                if (!is_array($req)) continue;

                // Estrai categoria (etichetta breve)
                $categoria = $req['category'] ?? $req['categoria'] ?? $req['type'] ?? null;
                if (empty($categoria)) {
                    // Prova a derivare da category_name o id_opera
                    if (isset($req['category_name'])) {
                        $categoria = $req['category_name'];
                    } elseif (isset($req['id_opera'])) {
                        $categoria = "Servizi analoghi " . $req['id_opera'];
                    } else {
                        $categoria = "Altro requisito econ/fin";
                    }
                }

                // Estrai descrizione (testo in italiano, non copia/incolla inglese)
                $descrizione = $this->extractItalianDescription($req);
                if (empty($descrizione)) {
                    // Fallback: usa description/descrizione/text ma solo se non contiene spiegazioni inglesi
                    $descrizioneRaw = $req['description'] ?? $req['descrizione'] ?? $req['text'] ?? null;
                    if (!empty($descrizioneRaw) && 
                        stripos($descrizioneRaw, 'The user wants') === false &&
                        stripos($descrizioneRaw, 'I will scan') === false) {
                        $descrizione = $descrizioneRaw;
                    }
                }

                // Estrai tipo (sintetico per filtri)
                $tipo = $req['requirement_type'] ?? $req['tipo'] ?? null;
                if (empty($tipo)) {
                    // Deriva tipo da categoria o struttura
                    if (isset($req['id_opera'])) {
                        $tipo = "servizi_analoghi";
                    } else {
                        $tipo = "altro_econ_fin";
                    }
                }

                // Estrai importo_minimo_eur
                $importoMinimo = null;
                if (isset($req['minimum_amount_eur']) && is_numeric($req['minimum_amount_eur'])) {
                    $importoMinimo = (float)$req['minimum_amount_eur'];
                } elseif (isset($req['importo_minimo'])) {
                    $val = $req['importo_minimo'];
                    if (is_numeric($val)) {
                        $importoMinimo = (float)$val;
                    } elseif (is_string($val)) {
                        $importoMinimo = $this->normalizeImporto($val);
                    }
                } elseif (isset($req['minimum_amount']) && is_numeric($req['minimum_amount'])) {
                    $importoMinimo = (float)$req['minimum_amount'];
                }

                // Estrai importo_minimo_raw
                $importoMinimoRaw = $req['importo_minimo_raw'] ?? 
                                   $req['amount_raw'] ?? 
                                   $req['minimum_amount_raw'] ?? null;

                // Skip se non abbiamo almeno categoria o descrizione
                if (empty($categoria) && empty($descrizione)) {
                    continue;
                }

                try {
                    $sql = "INSERT INTO gar_gara_capacita_econ_fin 
                            (job_id, extraction_id, categoria, descrizione, importo_minimo_eur, importo_minimo_raw, tipo)
                            VALUES (:job_id, :extraction_id, :categoria, :descrizione, :importo_minimo_eur, :importo_minimo_raw, :tipo)";

                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute([
                        ':job_id' => $jobId,
                        ':extraction_id' => $extractionId,
                        ':categoria' => $categoria,
                        ':descrizione' => $descrizione,
                        ':importo_minimo_eur' => $importoMinimo,
                        ':importo_minimo_raw' => $importoMinimoRaw,
                        ':tipo' => $tipo,
                    ]);
                } catch (\PDOException $e) {
                    $this->addDebugLog("Job {$jobId}: Errore insert capacita_econ_fin: " . $e->getMessage());
                }
            }
        }

        $this->addDebugLog("Job {$jobId}: gar_gara_capacita_econ_fin normalizzata");
    }

    // ========== IDONEITÀ PROFESSIONALE ==========

    /**
     * Normalizza requisiti di idoneità professionale
     * Popola gar_gara_idoneita_professionale
     * 
     * Fonte: type_code = requisiti_idoneita_professionale_gruppo_lavoro
     * 
     * Struttura JSON attesa:
     * - roles[]: array di ruoli con id, title, ecc.
     * - requirements[]: array di requisiti con:
     *   - original_text, qualifications, experience_requirements, is_mandatory
     *   - applies_to_role_ids[] o applies_to_all_roles
     *   - legal_citations, ecc.
     * 
     * Logica:
     * - Per ogni requisito, crea una riga per ogni ruolo applicabile
     * - Se applies_to_all_roles = true, usa "Tutti i ruoli" come ruolo
     * - Salva extra_json con dettagli strutturati (qualifications, experience_requirements, legal_citations, ecc.)
     */
    private function normalizeIdoneitaProfessionale(int $jobId, array $extractionsByType): void
    {
        if (!isset($extractionsByType['requisiti_idoneita_professionale_gruppo_lavoro'])) {
            return;
        }

        if (!$this->tableExists('gar_gara_idoneita_professionale')) {
            return;
        }

        // Elimina vecchi
        $this->pdo->prepare("DELETE FROM gar_gara_idoneita_professionale WHERE job_id = :job_id")
            ->execute([':job_id' => $jobId]);

        $extractions = $extractionsByType['requisiti_idoneita_professionale_gruppo_lavoro'];

        foreach ($extractions as $ext) {
            $extractionId = $ext['extraction_id'];
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson)) {
                continue;
            }

            // Estrai roles[] e requirements[]
            $roles = $valueJson['roles'] ?? [];
            $requirements = $valueJson['requirements'] ?? [];
            
            if (empty($requirements)) {
                continue;
            }

            // Crea mappa ruoli per lookup rapido
            $rolesMap = [];
            foreach ($roles as $role) {
                if (!is_array($role)) continue;
                $roleId = $role['id'] ?? $role['role_id'] ?? null;
                $roleTitle = $role['title'] ?? $role['role_title'] ?? $role['name'] ?? null;
                if ($roleId !== null && $roleTitle !== null) {
                    $rolesMap[$roleId] = $roleTitle;
                }
            }

            // Processa ogni requisito
            foreach ($requirements as $req) {
                if (!is_array($req)) continue;

                // Estrai testo requisito (vista umana in italiano)
                $requisiti = $this->extractItalianDescription($req);
                if (empty($requisiti)) {
                    // Fallback: usa original_text/description/text solo se non contiene spiegazioni inglesi
                    $requisitiRaw = $req['original_text'] ?? $req['description'] ?? $req['text'] ?? null;
                    if (!empty($requisitiRaw) && 
                        stripos($requisitiRaw, 'The user wants') === false &&
                        stripos($requisitiRaw, 'I will scan') === false) {
                        $requisiti = $requisitiRaw;
                    }
                }
                
                // Normalizza obbligatorio
                $obbligatorio = 1; // Default
                if (isset($req['is_mandatory'])) {
                    $obbligatorio = $req['is_mandatory'] === true || $req['is_mandatory'] === 'true' || $req['is_mandatory'] === 1 ? 1 : 0;
                } elseif (isset($req['mandatory'])) {
                    $obbligatorio = $req['mandatory'] === true || $req['mandatory'] === 'true' || $req['mandatory'] === 1 ? 1 : 0;
                }

                // Costruisci extra_json con dettagli strutturati (senza campi di debug)
                // IMPORTANTE: popola sempre extra_json se ci sono dati strutturati, anche per ruoli che prima avevano NULL
                $extraJson = [];
                
                if (isset($req['qualifications']) && !empty($req['qualifications'])) {
                    $extraJson['qualifications'] = $req['qualifications'];
                }
                
                if (isset($req['experience_requirements']) && !empty($req['experience_requirements'])) {
                    $extraJson['experience_requirements'] = $req['experience_requirements'];
                }
                
                if (isset($req['legal_citations']) && is_array($req['legal_citations']) && !empty($req['legal_citations'])) {
                    $extraJson['legal_citations'] = $req['legal_citations'];
                }
                
                // Altri campi strutturati utili (es. solo_raggruppamenti, solo_rti, ecc.)
                if (isset($req['applies_to_organization_types']) && is_array($req['applies_to_organization_types'])) {
                    $extraJson['applies_to_organization_types'] = $req['applies_to_organization_types'];
                }
                
                if (isset($req['minimum_experience_years']) && is_numeric($req['minimum_experience_years'])) {
                    $extraJson['minimum_experience_years'] = (int)$req['minimum_experience_years'];
                }
                
                if (isset($req['certifications']) && is_array($req['certifications'])) {
                    $extraJson['certifications'] = $req['certifications'];
                }
                
                // Aggiungi altri campi strutturati se presenti (es. team_details, young_professional_details)
                if (isset($req['team_details']) && is_array($req['team_details'])) {
                    $extraJson['team_details'] = $req['team_details'];
                }
                
                if (isset($req['young_professional_details']) && is_array($req['young_professional_details'])) {
                    $extraJson['young_professional_details'] = $req['young_professional_details'];
                }
                
                if (isset($req['soa_details']) && is_array($req['soa_details'])) {
                    $extraJson['soa_details'] = $req['soa_details'];
                }

                // Determina a quali ruoli si applica questo requisito
                $appliesToAllRoles = $req['applies_to_all_roles'] ?? false;
                $appliesToRoleIds = $req['applies_to_role_ids'] ?? [];
                
                if ($appliesToAllRoles) {
                    // Crea una riga con "Tutti i ruoli"
                    $ruolo = "Tutti i ruoli";
                    
                    try {
                        $sql = "INSERT INTO gar_gara_idoneita_professionale 
                                (job_id, extraction_id, ruolo, requisiti, obbligatorio, extra_json)
                                VALUES (:job_id, :extraction_id, :ruolo, :requisiti, :obbligatorio, :extra_json)";

                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute([
                            ':job_id' => $jobId,
                            ':extraction_id' => $extractionId,
                            ':ruolo' => $ruolo,
                            ':requisiti' => $requisiti,
                            ':obbligatorio' => $obbligatorio,
                            ':extra_json' => !empty($extraJson) ? json_encode($extraJson, JSON_UNESCAPED_UNICODE) : null,
                        ]);
                    } catch (\PDOException $e) {
                        $this->addDebugLog("Job {$jobId}: Errore insert idoneita_professionale (tutti i ruoli): " . $e->getMessage());
                    }
                } elseif (!empty($appliesToRoleIds) && is_array($appliesToRoleIds)) {
                    // Crea una riga per ogni ruolo specifico
                    foreach ($appliesToRoleIds as $roleId) {
                        $ruolo = $rolesMap[$roleId] ?? null;
                        
                        // Se il ruolo non è nella mappa, prova a usare il roleId come stringa
                        if (empty($ruolo)) {
                            $ruolo = is_string($roleId) ? $roleId : (string)$roleId;
                        }
                        
                        if (empty($ruolo)) {
                            continue; // Skip se non riusciamo a determinare il ruolo
                        }

                        try {
                            $sql = "INSERT INTO gar_gara_idoneita_professionale 
                                    (job_id, extraction_id, ruolo, requisiti, obbligatorio, extra_json)
                                    VALUES (:job_id, :extraction_id, :ruolo, :requisiti, :obbligatorio, :extra_json)";

                            $stmt = $this->pdo->prepare($sql);
                            $stmt->execute([
                                ':job_id' => $jobId,
                                ':extraction_id' => $extractionId,
                                ':ruolo' => $ruolo,
                                ':requisiti' => $requisiti,
                                ':obbligatorio' => $obbligatorio,
                                ':extra_json' => !empty($extraJson) ? json_encode($extraJson, JSON_UNESCAPED_UNICODE) : null,
                            ]);
                        } catch (\PDOException $e) {
                            $this->addDebugLog("Job {$jobId}: Errore insert idoneita_professionale (ruolo {$ruolo}): " . $e->getMessage());
                        }
                    }
                } else {
                    // Fallback: se non c'è applies_to_all_roles né applies_to_role_ids,
                    // ma c'è un requisito, crea una riga generica
                    // (potrebbe essere un requisito generale non legato a ruoli specifici)
                    $ruolo = "Requisito generale";
                    
                    try {
                        $sql = "INSERT INTO gar_gara_idoneita_professionale 
                                (job_id, extraction_id, ruolo, requisiti, obbligatorio, extra_json)
                                VALUES (:job_id, :extraction_id, :ruolo, :requisiti, :obbligatorio, :extra_json)";

                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute([
                            ':job_id' => $jobId,
                            ':extraction_id' => $extractionId,
                            ':ruolo' => $ruolo,
                            ':requisiti' => $requisiti,
                            ':obbligatorio' => $obbligatorio,
                            ':extra_json' => !empty($extraJson) ? json_encode($extraJson, JSON_UNESCAPED_UNICODE) : null,
                        ]);
                    } catch (\PDOException $e) {
                        $this->addDebugLog("Job {$jobId}: Errore insert idoneita_professionale (generico): " . $e->getMessage());
                    }
                }
            }
        }

        $this->addDebugLog("Job {$jobId}: gar_gara_idoneita_professionale normalizzata");
    }

    // ========== HELPER METHODS ==========

    /**
     * Verifica se tabella esiste
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            $exists = $stmt->rowCount() > 0;
            
            if (!$exists) {
                $this->addDebugLog("Tabella {$tableName} non esiste nel database");
            }
            
            return $exists;
        } catch (\PDOException $e) {
            $this->addDebugLog("Errore verifica tabella {$tableName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recupera dati da gar_opere_dm50
     */
    private function getOpereDm50Data(array $idOpereArray): array
    {
        if (empty($idOpereArray)) {
            return [];
        }

        $cleanIds = array_filter(
            array_map(fn($id) => is_string($id) ? trim($id) : (is_numeric($id) ? (string)$id : null), $idOpereArray),
            fn($id) => $id !== null && $id !== ''
        );

        if (empty($cleanIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));

        try {
            $stmt = $this->pdo->prepare(
                "SELECT id_opera, categoria, identificazione_opera, complessita 
                 FROM gar_opere_dm50 
                 WHERE id_opera IN ($placeholders)"
            );
            $stmt->execute($cleanIds);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $id = $row['id_opera'] ?? '';
                if ($id) {
                    $result[$id] = [
                        'categoria' => $row['categoria'] ?? null,
                        'identificazione_opera' => $row['identificazione_opera'] ?? null,
                        'complessita' => $row['complessita'] ?? null
                    ];
                }
            }

            return $result;
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Normalizza data da stringa a formato DATE (Y-m-d)
     */
    private function normalizeDate(?string $dateValue): ?string
    {
        if (empty($dateValue)) {
            return null;
        }

        // Prova a estrarre da value_json se è una struttura date
        $isoDate = \Services\AIextraction\TextDecoder::extractDate($dateValue);
        if ($isoDate === null) {
            return null;
        }

        try {
            $dt = new \DateTime($isoDate);
            return $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Normalizza importo da stringa a float
     */
    private function normalizeImporto(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^\d.,]/', '', $value);

        if (strpos($normalized, ',') !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }

        return is_numeric($normalized) ? (float)$normalized : null;
    }

    /**
     * Genera testo completo in italiano per requisiti tecnici
     * Partendo dai requisiti strutturati, costruisce un testo leggibile in italiano
     */
    private function generateItalianRequisitiTecnici(?array $valueJson): ?string
    {
        if (!is_array($valueJson) || empty($valueJson['requirements'])) {
            return null;
        }

        $requirements = $valueJson['requirements'] ?? [];
        if (!is_array($requirements) || empty($requirements)) {
            return null;
        }

        $parts = [];
        $parts[] = "REQUISITI TECNICO-PROFESSIONALI\n";

        foreach ($requirements as $idx => $req) {
            if (!is_array($req)) continue;

            $title = $req['title'] ?? null;
            $description = $req['description'] ?? null;
            $requirementType = $req['requirement_type'] ?? null;
            $isMandatory = $req['is_mandatory'] ?? true;

            $part = "";
            if ($title) {
                $part .= ($idx + 1) . ". " . $title;
            } elseif ($requirementType) {
                $typeLabels = [
                    'registration' => 'Registrazione professionale',
                    'team_composition' => 'Composizione del team',
                    'experience' => 'Esperienza in servizi analoghi',
                    'young_professional' => 'Giovane professionista',
                    'technical_director' => 'Direttore tecnico',
                ];
                $part .= ($idx + 1) . ". " . ($typeLabels[$requirementType] ?? ucfirst($requirementType));
            } else {
                $part .= ($idx + 1) . ". Requisito tecnico-professionale";
            }

            if ($isMandatory === false) {
                $part .= " (facoltativo)";
            }

            $part .= "\n";

            if ($description) {
                // Rimuovi spiegazioni inglesi
                $desc = preg_replace('/The user wants.*?\./i', '', $description);
                $desc = preg_replace('/I will scan.*?\./i', '', $desc);
                $desc = trim($desc);
                if (!empty($desc)) {
                    $part .= "   " . $desc . "\n";
                }
            }

            // Aggiungi dettagli se presenti
            if (isset($req['experience_details']) && is_array($req['experience_details'])) {
                $exp = $req['experience_details'];
                if (isset($exp['time_period_years'])) {
                    $part .= "   Periodo di riferimento: ultimi " . $exp['time_period_years'] . " anni\n";
                }
                if (isset($exp['categories']) && is_array($exp['categories'])) {
                    foreach ($exp['categories'] as $cat) {
                        if (isset($cat['minimum_amount_eur'])) {
                            $part .= "   - " . ($cat['category_name'] ?? $cat['category_code'] ?? '') . 
                                    ": minimo €" . number_format($cat['minimum_amount_eur'], 2, ',', '.') . "\n";
                        }
                    }
                }
            }

            if (isset($req['team_details']) && is_array($req['team_details'])) {
                $team = $req['team_details'];
                if (isset($team['required_roles']) && is_array($team['required_roles'])) {
                    $part .= "   Ruoli richiesti:\n";
                    foreach ($team['required_roles'] as $role) {
                        $roleTitle = $role['role_title'] ?? $role['required_qualification'] ?? '';
                        if ($roleTitle) {
                            $part .= "     - " . $roleTitle;
                            if (isset($role['minimum_experience_years'])) {
                                $part .= " (minimo " . $role['minimum_experience_years'] . " anni di esperienza)";
                            }
                            $part .= "\n";
                        }
                    }
                }
            }

            $parts[] = $part . "\n";
        }

        return implode("", $parts);
    }

    /**
     * Genera riassunto sintetico in italiano per requisiti tecnici (1-2 frasi)
     */
    private function generateItalianRequisitiSintetico(?array $valueJson): ?string
    {
        if (!is_array($valueJson) || empty($valueJson['requirements'])) {
            return null;
        }

        $requirements = $valueJson['requirements'] ?? [];
        if (!is_array($requirements) || empty($requirements)) {
            return null;
        }

        $count = count($requirements);
        $types = [];
        foreach ($requirements as $req) {
            if (!is_array($req)) continue;
            $type = $req['requirement_type'] ?? null;
            if ($type) {
                $typeLabels = [
                    'registration' => 'registrazione',
                    'team_composition' => 'composizione team',
                    'experience' => 'esperienza',
                    'young_professional' => 'giovane professionista',
                    'technical_director' => 'direttore tecnico',
                ];
                $types[] = $typeLabels[$type] ?? $type;
            }
        }

        $parts = [];
        $parts[] = "Requisiti tecnico-professionali (" . $count . " totali)";
        
        if (!empty($types)) {
            $parts[] = "includono: " . implode(", ", array_unique(array_slice($types, 0, 3)));
        }

        return implode(". ", $parts) . ".";
    }

    /**
     * Estrae descrizione in italiano da un requisito/entry
     * Cerca campi italiani o traduce/estrae da campi strutturati
     */
    private function extractItalianDescription(array $req): ?string
    {
        // Priorità 1: campi esplicitamente italiani
        if (isset($req['descrizione']) && !empty($req['descrizione'])) {
            $desc = $req['descrizione'];
            if (stripos($desc, 'The user wants') === false && stripos($desc, 'I will scan') === false) {
                return $desc;
            }
        }

        // Priorità 2: original_text (spesso già in italiano se presente)
        if (isset($req['original_text']) && !empty($req['original_text'])) {
            $desc = $req['original_text'];
            if (stripos($desc, 'The user wants') === false && stripos($desc, 'I will scan') === false) {
                return $desc;
            }
        }

        // Priorità 3: costruisci da campi strutturati
        $parts = [];
        
        if (isset($req['description']) && !empty($req['description'])) {
            $desc = $req['description'];
            // Rimuovi spiegazioni inglesi
            $desc = preg_replace('/The user wants.*?\./i', '', $desc);
            $desc = preg_replace('/I will scan.*?\./i', '', $desc);
            $desc = trim($desc);
            if (!empty($desc) && strlen($desc) < 500) {
                $parts[] = $desc;
            }
        }

        // Se abbiamo almeno una parte, restituiscila
        if (!empty($parts)) {
            return implode(". ", $parts);
        }

        return null;
    }

    /**
     * Genera nota sintetica in italiano per fatturato minimo
     * Es. "migliori 3 anni degli ultimi 5, IVA esclusa"
     */
    private function generateItalianFatturatoNote(?array $valueJson): ?string
    {
        if (!is_array($valueJson)) {
            return null;
        }

        $parts = [];

        // Estrai informazioni temporali
        $periodsToSelect = null;
        $totalPeriods = null;
        if (isset($valueJson['turnover_requirement']) && is_array($valueJson['turnover_requirement'])) {
            $tr = $valueJson['turnover_requirement'];
            if (isset($tr['single_requirement']) && is_array($tr['single_requirement'])) {
                $sr = $tr['single_requirement'];
                if (isset($sr['temporal_calculation']) && is_array($sr['temporal_calculation'])) {
                    $tc = $sr['temporal_calculation'];
                    $periodsToSelect = $tc['periods_to_select'] ?? null;
                    $totalPeriods = $tc['total_periods'] ?? null;
                }
            }
        }

        if ($periodsToSelect !== null && $totalPeriods !== null) {
            $parts[] = "migliori " . $periodsToSelect . " anni degli ultimi " . $totalPeriods;
        } elseif ($periodsToSelect !== null) {
            $parts[] = "migliori " . $periodsToSelect . " anni";
        }

        // Estrai informazioni IVA
        $vatIncluded = $valueJson['vat_included'] ?? null;
        if ($vatIncluded === false) {
            $parts[] = "IVA esclusa";
        } elseif ($vatIncluded === true) {
            $parts[] = "IVA inclusa";
        }

        return !empty($parts) ? implode(", ", $parts) : null;
    }

    /**
     * Aggiunge log
     */
    private function addDebugLog(string $message): void
    {
        $this->debugLogs[] = date('Y-m-d H:i:s') . ' - ' . $message;
        error_log($message);
    }

    /**
     * Ottiene debug logs
     */
    public function getDebugLogs(): array
    {
        $logs = $this->debugLogs;
        $this->debugLogs = [];
        return $logs;
    }

    /**
     * Re-sincronizza i corrispettivi per un job specifico
     * Legge l'ultima estrazione completed per importi_corrispettivi_categoria_id_opere
     * e ripopola da zero le tabelle gar_gara_corrispettivi_opere e gar_gara_corrispettivi_opere_fasi
     * 
     * @param int $jobId Job ID da re-sincronizzare
     * @return array ['success' => bool, 'message' => string, 'rows_fasi' => int, 'rows_totali' => int]
     */
    public function resyncCorrispettivi(int $jobId): array
    {
        try {
            $this->pdo->beginTransaction();

            // 1. Trova l'ultima estrazione completed per importi_corrispettivi_categoria_id_opere
            $stmt = $this->pdo->prepare(
                "SELECT id, value_json, value_text 
                 FROM ext_extractions 
                 WHERE job_id = :job_id 
                   AND type_code = 'importi_corrispettivi_categoria_id_opere'
                 ORDER BY id DESC 
                 LIMIT 1"
            );
            $stmt->execute([':job_id' => $jobId]);
            $extraction = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$extraction) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'message' => "Nessuna estrazione trovata per importi_corrispettivi_categoria_id_opere (job_id: {$jobId})",
                    'rows_fasi' => 0,
                    'rows_totali' => 0
                ];
            }

            $extractionId = (int)$extraction['id'];
            $valueJson = $extraction['value_json'] ?? null;
            
            if (is_string($valueJson)) {
                $valueJson = json_decode($valueJson, true);
            }

            if (!is_array($valueJson) || empty($valueJson['entries'])) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'message' => "JSON non valido o entries vuote (extraction_id: {$extractionId})",
                    'rows_fasi' => 0,
                    'rows_totali' => 0
                ];
            }

            // 2. Elimina righe esistenti per questo job
            $deletedFasi = 0;
            $deletedTotali = 0;
            
            if ($this->tableExists('gar_gara_corrispettivi_opere_fasi')) {
                $stmt = $this->pdo->prepare("DELETE FROM gar_gara_corrispettivi_opere_fasi WHERE job_id = :job_id");
                $stmt->execute([':job_id' => $jobId]);
                $deletedFasi = $stmt->rowCount();
            }
            
            if ($this->tableExists('gar_gara_corrispettivi_opere')) {
                $stmt = $this->pdo->prepare("DELETE FROM gar_gara_corrispettivi_opere WHERE job_id = :job_id");
                $stmt->execute([':job_id' => $jobId]);
                $deletedTotali = $stmt->rowCount();
            }

            // 3. Prepara dati per normalizzazione
            $extractionsByType = [
                'importi_corrispettivi_categoria_id_opere' => [
                    [
                        'extraction_id' => $extractionId,
                        'value_json' => $valueJson,
                        'value_text' => $extraction['value_text']
                    ]
                ]
            ];

            // 4. Chiama normalizeCorrispettiviOpere per ripopolare
            $this->normalizeCorrispettiviOpere($jobId, $extractionsByType);

            // 5. Verifica risultati
            $rowsFasi = 0;
            $rowsTotali = 0;
            
            if ($this->tableExists('gar_gara_corrispettivi_opere_fasi')) {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) as cnt FROM gar_gara_corrispettivi_opere_fasi WHERE job_id = :job_id");
                $stmt->execute([':job_id' => $jobId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $rowsFasi = (int)($row['cnt'] ?? 0);
            }
            
            if ($this->tableExists('gar_gara_corrispettivi_opere')) {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) as cnt FROM gar_gara_corrispettivi_opere WHERE job_id = :job_id");
                $stmt->execute([':job_id' => $jobId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $rowsTotali = (int)($row['cnt'] ?? 0);
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => "Re-sync completato: eliminati {$deletedFasi} fasi e {$deletedTotali} totali, inseriti {$rowsFasi} fasi e {$rowsTotali} totali",
                'rows_fasi' => $rowsFasi,
                'rows_totali' => $rowsTotali,
                'deleted_fasi' => $deletedFasi,
                'deleted_totali' => $deletedTotali
            ];

        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            return [
                'success' => false,
                'message' => "Errore durante re-sync: " . $e->getMessage(),
                'rows_fasi' => 0,
                'rows_totali' => 0
            ];
        }
    }
}
