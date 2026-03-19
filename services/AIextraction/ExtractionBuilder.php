<?php

namespace Services;

/**
 * Costruisce estrazioni nel formato richiesto dal frontend.
 * 
 * Responsabilità:
 * - Costruisce estrazioni da dati normalizzati (ext_req_docs, ext_req_econ, ext_req_roles)
 * - Converte dati grezzi in formato strutturato
 * - Gestisce tabelle, citazioni, display value
 * - Integra dati da gar_opere_dm50
 */
class ExtractionBuilder
{
    private $pdo;
    private $opereDm50Cache = [];

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Costruisce estrazione da dati normalizzati ext_req_docs
     */
    public function buildFromNormalizedDocs(array $row, int $jobId, array $docsMap): ?array
    {
        if (empty($docsMap)) {
            return null;
        }

        // Costruisci tabella con colonne fisse: Documento, Tipo, Stato, Formato, Max pagine
        $headers = ['Documento', 'Tipo', 'Stato', 'Formato', 'Max pagine'];
        $tableData = [];
        $entries = [];

        // Prova a recuperare dati originali da value_json se disponibile
        $originalValueJson = null;
        if (!empty($row['value_json'])) {
            $decoded = json_decode($row['value_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $originalValueJson = $decoded;
            }
        }

        foreach ($docsMap as $doc) {
            // Documento → titolo
            $documento = $doc['titolo'] ?? '';
            
            // Tipo → tipo_documento
            $tipo = $doc['tipo_documento'] ?? '';
            
            // Stato → mappa obbligatorieta (con ucfirst per display)
            $obbligatorieta = $doc['obbligatorieta'] ?? '';
            $stato = $obbligatorieta ? ucfirst(strtolower($obbligatorieta)) : '';
            
            // Formato → cerca nel payload originale o lascia vuoto
            $formato = null;
            if ($originalValueJson && isset($originalValueJson['entries']) && is_array($originalValueJson['entries'])) {
                foreach ($originalValueJson['entries'] as $entry) {
                    if (is_array($entry) && 
                        (($entry['doc_code'] ?? '') === ($doc['doc_code'] ?? '') ||
                         ($entry['titolo'] ?? '') === $documento)) {
                        $formato = $entry['formato'] ?? $entry['format'] ?? null;
                        break;
                    }
                }
            }
            $formato = $formato ?? '';
            
            // Max pagine → cerca nel payload originale o lascia vuoto
            $maxPagine = null;
            if ($originalValueJson && isset($originalValueJson['entries']) && is_array($originalValueJson['entries'])) {
                foreach ($originalValueJson['entries'] as $entry) {
                    if (is_array($entry) && 
                        (($entry['doc_code'] ?? '') === ($doc['doc_code'] ?? '') ||
                         ($entry['titolo'] ?? '') === $documento)) {
                        $maxPagine = $entry['max_pagine'] ?? $entry['max_pages'] ?? null;
                        break;
                    }
                }
            }
            $maxPagine = $maxPagine ?? '';

            $tableData[] = [
                $documento,
                $tipo,
                $stato,
                $formato,
                $maxPagine
            ];

            // Costruisci entry per value_json
            $entries[] = [
                'documento' => $documento,
                'titolo' => $documento, // Alias per compatibilità
                'tipo' => $tipo,
                'tipo_documento' => $tipo, // Alias per compatibilità
                'stato' => $stato,
                'obbligatorieta' => $obbligatorieta, // Alias per compatibilità
                'formato' => $formato,
                'max_pagine' => $maxPagine,
                'max_pages' => $maxPagine, // Alias per compatibilità
            ];
        }

        $updatedAt = $this->getUpdatedAt($row);
        $citations = $row['citations'] ?? [];
        $valueJson = $this->buildValueJson($row, ['entries' => $entries], $citations);

        return [
            'id' => (int)$row['id'],
            'job_id' => (int)$row['job_id'],
            'tipo' => $row['type_code'],
            'tipo_display' => ExtractionConstants::getDisplayName($row['type_code']),
            'valore' => ['entries' => $entries],
            'is_json' => true,
            'display_value' => count($docsMap) . ' documento/i richiesto/i',
            'value_state' => 'table',
            'table' => [
                'headers' => $headers,
                'rows' => $tableData
            ],
            'valore_raw' => null,
            'valori_raw' => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE),
            'value_json' => $valueJson,
            'citations' => $citations,
            'file_name' => $row['file_name'],
            'confidence' => $row['confidence'],
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Costruisce estrazione da dati normalizzati ext_req_econ
     */
    public function buildFromNormalizedEcon(array $row, int $jobId, array $econMap, string $typeCode): ?array
    {
        if (empty($econMap)) {
            return null;
        }

        $updatedAt = $this->getUpdatedAt($row);
        $citations = $row['citations'] ?? [];

        // Per fatturato_globale
        if ($typeCode === 'fatturato_globale_n_minimo_anni') {
            $fatturato = $econMap['FATTURATO_GLOBALE'][0] ?? null;
            if (!$fatturato) {
                return null;
            }

            $displayValue = 'Fatturato globale minimo: ' . 
                ($fatturato['importo_minimo_formatted'] ?? 
                 number_format((float)$fatturato['importo_minimo'], 2, ',', '.') . ' ' . 
                 ($fatturato['valuta'] ?? 'EUR'));

            if ($fatturato['best_periods']) {
                $displayValue .= ' (migliori ' . $fatturato['best_periods'] . ' periodi su ' . 
                    ($fatturato['lookback_anni'] ?? 5) . ' anni)';
            }

            $valueJson = $this->buildValueJson($row, $fatturato, $citations);

            return [
                'id' => (int)$row['id'],
                'job_id' => (int)$row['job_id'],
                'tipo' => $row['type_code'],
                'tipo_display' => ExtractionConstants::getDisplayName($row['type_code']),
                'valore' => $fatturato,
                'is_json' => true,
                'display_value' => $displayValue,
                'value_state' => 'scalar',
                'valore_raw' => null,
                'valori_raw' => json_encode($fatturato, JSON_UNESCAPED_UNICODE),
                'value_json' => $valueJson,
                'citations' => $citations,
                'file_name' => $row['file_name'],
                'confidence' => $row['confidence'],
                'updated_at' => $updatedAt,
            ];
        }

        // Per requisiti_di_capacita_economica_finanziaria
        if ($typeCode === 'requisiti_di_capacita_economica_finanziaria') {
            $tableData = [];
            $headers = ['ID opere', 'Importo corrispettivo', 'Coefficiente moltiplicativo', 'Importo requisito', 'Importo posseduto'];
            $entries = [];

            // Recupera corrispettivi da gar_gara_corrispettivi_opere per questo job
            $corrispettiviMap = [];
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT id_opera, id_opera_raw, categoria_codice, importo_corrispettivo_eur 
                     FROM gar_gara_corrispettivi_opere 
                     WHERE job_id = :job_id"
                );
                $stmt->execute([':job_id' => $jobId]);
                $corrispettiviRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($corrispettiviRows as $corr) {
                    $idOpera = $corr['id_opera'] ?? $corr['id_opera_raw'] ?? $corr['categoria_codice'] ?? '';
                    if ($idOpera) {
                        $corrispettiviMap[$idOpera] = $corr['importo_corrispettivo_eur'] ?? null;
                    }
                }
            } catch (\PDOException $e) {
                // Se la tabella non esiste o errore, continua senza corrispettivi
            }

            foreach ($econMap as $tipo => $items) {
                // Escludi FATTURATO_GLOBALE (già mostrato separatamente)
                if ($tipo === 'FATTURATO_GLOBALE') {
                    continue;
                }

                foreach ($items as $item) {
                    // ID opere → categoria_codice
                    $idOpera = $item['categoria_codice'] ?? '';
                    
                    // Importo corrispettivo → da gar_gara_corrispettivi_opere o riferimento_valore_categoria
                    $importoCorrispettivoEur = null;
                    if ($idOpera && isset($corrispettiviMap[$idOpera])) {
                        $importoCorrispettivoEur = $corrispettiviMap[$idOpera];
                    } elseif (!empty($item['riferimento_valore_categoria'])) {
                        // Prova a parsare riferimento_valore_categoria se è un numero
                        $refVal = $item['riferimento_valore_categoria'];
                        if (is_numeric($refVal)) {
                            $importoCorrispettivoEur = (float)$refVal;
                        }
                    }
                    $importoCorrispettivoDisplay = $importoCorrispettivoEur !== null 
                        ? number_format($importoCorrispettivoEur, 2, ',', '.') . ' EUR'
                        : '';
                    
                    // Coefficiente moltiplicativo → moltiplicatore (formattato con 2 decimali)
                    $moltiplicatore = $item['moltiplicatore'] ?? null;
                    $coefficienteDisplay = $moltiplicatore !== null 
                        ? number_format((float)$moltiplicatore, 2, ',', '.')
                        : '';
                    
                    // Importo requisito → importo_minimo (con valuta)
                    $importoRequisitoEur = $item['importo_minimo'] ?? null;
                    $importoRequisitoDisplay = '';
                    if ($importoRequisitoEur !== null) {
                        $importoRequisitoDisplay = number_format((float)$importoRequisitoEur, 2, ',', '.') . ' ' . ($item['valuta'] ?? 'EUR');
                    } elseif ($importoCorrispettivoEur !== null && $moltiplicatore !== null) {
                        // Calcola solo se abbiamo entrambi i dati
                        $importoRequisitoEur = $importoCorrispettivoEur * $moltiplicatore;
                        $importoRequisitoDisplay = number_format($importoRequisitoEur, 2, ',', '.') . ' ' . ($item['valuta'] ?? 'EUR');
                    }
                    
                    // Importo posseduto → sempre vuoto per ora
                    $importoPossedutoEur = null;
                    $importoPossedutoDisplay = '';

                    $tableData[] = [
                        $idOpera,
                        $importoCorrispettivoDisplay,
                        $coefficienteDisplay,
                        $importoRequisitoDisplay,
                        $importoPossedutoDisplay
                    ];

                    // Costruisci entry per value_json
                    $entries[] = [
                        'id_opera' => $idOpera,
                        'categoria_codice' => $idOpera, // Alias per compatibilità
                        'importo_corrispettivo_eur' => $importoCorrispettivoEur,
                        'importo_corrispettivo_raw' => $importoCorrispettivoDisplay,
                        'coefficiente_moltiplicativo' => $moltiplicatore,
                        'moltiplicatore' => $moltiplicatore, // Alias per compatibilità
                        'importo_requisito_eur' => $importoRequisitoEur,
                        'importo_minimo_eur' => $importoRequisitoEur, // Alias per compatibilità
                        'importo_posseduto_eur' => $importoPossedutoEur,
                    ];
                }
            }

            $valueJson = $this->buildValueJson($row, ['entries' => $entries], $citations);

            return [
                'id' => (int)$row['id'],
                'job_id' => (int)$row['job_id'],
                'tipo' => $row['type_code'],
                'tipo_display' => ExtractionConstants::getDisplayName($row['type_code']),
                'valore' => ['entries' => $entries],
                'is_json' => true,
                'display_value' => count($tableData) . ' requisito/i economico/i',
                'value_state' => 'table',
                'table' => [
                    'headers' => $headers,
                    'rows' => $tableData
                ],
                'valore_raw' => null,
                'valori_raw' => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE),
                'value_json' => $valueJson,
                'citations' => $citations,
                'file_name' => $row['file_name'],
                'confidence' => $row['confidence'],
                'updated_at' => $updatedAt,
            ];
        }

        return null;
    }

    /**
     * Costruisce estrazione da dati normalizzati ext_req_roles
     */
    public function buildFromNormalizedRoles(array $row, int $jobId, array $rolesMap): ?array
    {
        if (empty($rolesMap)) {
            return null;
        }

        $tableData = [];
        $headers = ['Ruolo', 'Requisiti', 'Obbligatorio'];
        $entries = [];

        foreach ($rolesMap as $role) {
            $ruolo = $role['role_name'] ?? '';
            
            $requisitiText = '';
            if (!empty($role['requisiti']) && is_array($role['requisiti'])) {
                $reqList = [];
                foreach ($role['requisiti'] as $req) {
                    $reqDesc = $req['description'] ?? '';
                    if (!empty($req['min_years'])) {
                        $reqDesc .= ' (min. ' . $req['min_years'] . ' anni)';
                    }
                    $reqList[] = $reqDesc;
                }
                $requisitiText = implode('; ', $reqList);
            } elseif (!empty($role['requisiti_json'])) {
                // Fallback: prova a estrarre testo da requisiti_json se è una stringa
                if (is_string($role['requisiti_json'])) {
                    $requisitiText = $role['requisiti_json'];
                }
            }

            $isMinimum = $role['is_minimum'] ?? false;
            $obbligatorio = ($isMinimum === true || $isMinimum === 1 || $isMinimum === '1') ? 'Sì' : 'No';

            $tableData[] = [
                $ruolo,
                $requisitiText,
                $obbligatorio
            ];

            // Costruisci entry per value_json
            $entries[] = [
                'ruolo' => $ruolo,
                'role_name' => $ruolo, // Alias per compatibilità
                'requisiti' => $requisitiText,
                'description' => $requisitiText, // Alias per compatibilità
                'obbligatorio' => $obbligatorio,
                'is_minimum' => $isMinimum, // Valore booleano/numerico originale
            ];
        }

        $updatedAt = $this->getUpdatedAt($row);
        $citations = $row['citations'] ?? [];
        $valueJson = $this->buildValueJson($row, ['entries' => $entries], $citations);

        return [
            'id' => (int)$row['id'],
            'job_id' => (int)$row['job_id'],
            'tipo' => $row['type_code'],
            'tipo_display' => ExtractionConstants::getDisplayName($row['type_code']),
            'valore' => ['entries' => $entries],
            'is_json' => true,
            'display_value' => count($tableData) . ' ruolo/i',
            'value_state' => 'table',
            'table' => [
                'headers' => $headers,
                'rows' => $tableData
            ],
            'valore_raw' => null,
            'valori_raw' => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE),
            'value_json' => $valueJson,
            'citations' => $citations,
            'file_name' => $row['file_name'],
            'confidence' => $row['confidence'],
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Costruisce estrazione da gar_gara_importi_opere
     */
    public function buildImportiOpere(array $row, int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM gar_gara_importi_opere WHERE job_id = :job_id ORDER BY id ASC"
        );
        $stmt->execute([':job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return null;
        }

        $entries = [];
        foreach ($rows as $r) {
            $entries[] = [
                'id_opera' => $r['id_opera'] ?? '',
                'id_opera_normalized' => $r['id_opera'] ?? '',
                'categoria' => $r['categoria'] ?? '',
                'descrizione' => $r['descrizione'] ?? '',
                'amount_eur' => $r['amount_eur'] ?? null,
                'service_phase' => $r['service_phase'] ?? null,
                'page' => $r['page'] ?? null,
            ];
        }

        $updatedAt = $this->getUpdatedAt($row);
        $citations = $row['citations'] ?? [];

        return [
            'id' => (int)$row['id'],
            'job_id' => $jobId,
            'tipo' => 'importi_opere_per_categoria_id_opere',
            'tipo_display' => ExtractionConstants::getDisplayName('importi_opere_per_categoria_id_opere'),
            'valore' => $entries,
            'is_json' => true,
            'display_value' => count($entries) . ' opere',
            'value_state' => 'table',
            'value_json' => (($row['value_json'] ?? '') !== '' && ($row['value_json'] ?? '') !== '{}')
                ? $row['value_json']
                : json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE),
            'citations' => $citations,
            'file_name' => $row['file_name'],
            'confidence' => $row['confidence'],
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Costruisce estrazione da gar_gara_corrispettivi_opere
     */
    public function buildCorrispettiviOpere(array $row, int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM gar_gara_corrispettivi_opere WHERE job_id = :job_id ORDER BY id ASC"
        );
        $stmt->execute([':job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return null;
        }

        $entries = [];
        foreach ($rows as $r) {
            $entries[] = [
                'id_opera' => $r['id_opera'] ?? '',
                'categoria' => $r['categoria'] ?? '',
                'descrizione' => $r['descrizione'] ?? '',
                'complessita' => $r['complessita'] ?? null,
                'fee_amount' => $r['fee_amount'] ?? null,
                'page' => $r['page'] ?? null,
            ];
        }

        $updatedAt = $this->getUpdatedAt($row);
        $citations = $row['citations'] ?? [];

        return [
            'id' => (int)$row['id'],
            'job_id' => $jobId,
            'tipo' => 'importi_corrispettivi_categoria_id_opere',
            'tipo_display' => ExtractionConstants::getDisplayName('importi_corrispettivi_categoria_id_opere'),
            'valore' => $entries,
            'is_json' => true,
            'display_value' => count($entries) . ' corrispettivi',
            'value_state' => 'table',
            'value_json' => (($row['value_json'] ?? '') !== '' && ($row['value_json'] ?? '') !== '{}')
                ? $row['value_json']
                : json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE),
            'citations' => $citations,
            'file_name' => $row['file_name'],
            'confidence' => $row['confidence'],
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Costruisce estrazione da gar_gara_requisiti_tecnici
     */
    public function buildRequisitiTecnici(array $row, int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM gar_gara_requisiti_tecnici WHERE job_id = :job_id ORDER BY id ASC"
        );
        $stmt->execute([':job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return null;
        }

        $requirements = [];
        $headers = ['ID Opera', 'Categoria', 'Descrizione', 'Importo minimo'];
        $tableData = [];

        foreach ($rows as $r) {
            $requirements[] = [
                'id_opera' => $r['id_opera'] ?? '',
                'category_name' => $r['category_name'] ?? '',
                'description' => $r['description'] ?? '',
                'base_value_eur' => $r['base_value_eur'] ?? null,
                'minimum_amount_eur' => $r['minimum_amount_eur'] ?? null,
                'complexity' => $r['complexity'] ?? null,
            ];

            $amount = '—';
            if ($r['minimum_amount_eur'] !== null) {
                $amount = number_format((float)$r['minimum_amount_eur'], 2, ',', '.') . ' €';
            }

            $tableData[] = [
                $r['id_opera'] ?? '—',
                $r['category_name'] ?? '—',
                $r['description'] ?? '—',
                $amount
            ];
        }

        $updatedAt = $this->getUpdatedAt($row);
        $citations = $row['citations'] ?? [];

        return [
            'id' => (int)$row['id'],
            'job_id' => $jobId,
            'tipo' => 'importi_requisiti_tecnici_categoria_id_opere',
            'tipo_display' => ExtractionConstants::getDisplayName('importi_requisiti_tecnici_categoria_id_opere'),
            'valore' => $requirements,
            'is_json' => true,
            'display_value' => count($requirements) . ' requisiti',
            'value_state' => 'table',
            'table' => [
                'headers' => $headers,
                'rows' => $tableData
            ],
            'value_json' => (($row['value_json'] ?? '') !== '' && ($row['value_json'] ?? '') !== '{}')
                ? $row['value_json']
                : json_encode(['requirements' => $requirements], JSON_UNESCAPED_UNICODE),
            'citations' => $citations,
            'file_name' => $row['file_name'],
            'confidence' => $row['confidence'],
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Costruisce tabella da entries (per importi_opere_per_categoria)
     */
    public function buildTableFromImportiEntries(array $entries, string $typeCode): ?array
    {
        if (empty($entries)) {
            return null;
        }

        if ($typeCode === 'importi_corrispettivi_categoria_id_opere') {
            $headers = ['ID Opere', 'Categorie', 'Descrizione', 'Grado di complessità', 'Importo del corrispettivo'];
        } else {
            $headers = ['ID Opere', 'Categoria', 'Descrizione', 'Importo stimato dei lavori'];
        }

        // Raccogli id_opera
        $idOpereArray = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            $idOpera = $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? null;
            if ($idOpera) {
                $idOpereArray[] = $idOpera;
            }
        }

        $opereDm50Data = $this->getOpereDm50Data($idOpereArray);

        $rows = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;

            $idOpera = $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? '—';
            $categoria = '—';
            $descrizione = '—';
            $complessita = '—';

            if ($idOpera !== '—' && isset($opereDm50Data[$idOpera])) {
                $categoria = $opereDm50Data[$idOpera]['categoria'] ?? '—';
                $descrizione = $opereDm50Data[$idOpera]['identificazione_opera'] ?? '—';
                $complessita = $opereDm50Data[$idOpera]['complessita'] ?? '—';
            }

            if ($typeCode === 'importi_corrispettivi_categoria_id_opere') {
                $gradoComplessita = '—';
                if (isset($entry['complexity']) && $entry['complexity'] !== null) {
                    $gradoComplessita = is_numeric($entry['complexity']) 
                        ? number_format((float)$entry['complexity'], 2, ',', '.') 
                        : (string)$entry['complexity'];
                } elseif ($complessita !== '—') {
                    $gradoComplessita = $complessita;
                }

                $importo = '—';
                if (isset($entry['corrispettivo_eur']) && is_numeric($entry['corrispettivo_eur'])) {
                    $importo = number_format((float)$entry['corrispettivo_eur'], 2, ',', '.') . ' €';
                } elseif (isset($entry['amount_eur']) && is_numeric($entry['amount_eur'])) {
                    $importo = number_format((float)$entry['amount_eur'], 2, ',', '.') . ' €';
                }

                $rows[] = [$idOpera, $categoria, $descrizione, $gradoComplessita, $importo];
            } else {
                $amount = '—';
                if (isset($entry['amount_eur']) && is_numeric($entry['amount_eur'])) {
                    $amount = number_format((float)$entry['amount_eur'], 2, ',', '.') . ' €';
                }

                $rows[] = [$idOpera, $categoria, $descrizione, $amount];
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Costruisce tabella da fatturato (single requirement o per lotto)
     */
    public function buildFatturatoTable($decoded, $initialDisplayValue): ?array
    {
        if (is_array($decoded)) {
            // Cerca turnover_requirement
            $turnoverReq = $decoded['turnover_requirement'] ?? 
                          ($decoded['answer']['turnover_requirement'] ?? null);

            if ($turnoverReq !== null) {
                // Single requirement
                if (isset($turnoverReq['applies_to']) && 
                    $turnoverReq['applies_to'] === 'single_requirement' &&
                    isset($turnoverReq['single_requirement'])) {
                    return $this->buildSingleRequirementTable($turnoverReq['single_requirement']);
                }

                // Lot requirements
                if (isset($turnoverReq['lot_requirements'])) {
                    return $this->buildLotRequirementsTable($turnoverReq['lot_requirements']);
                }
            }

            // Fallback lot_requirements diretto
            if (!empty($decoded['lot_requirements'])) {
                return $this->buildLotRequirementsTable($decoded['lot_requirements']);
            }
        }

        return null;
    }

    /**
     * Tabella per single requirement di fatturato
     */
    private function buildSingleRequirementTable(array $singleReq): ?array
    {
        $amount = $this->formatAmount(
            $singleReq['normalized_value'] ?? $singleReq['minimum_amount'] ?? null
        );

        if ($amount === null) {
            $amount = '—';
        }

        $details = [];

        $temporal = $singleReq['temporal_calculation'] ?? [];
        if (!empty($temporal)) {
            $periods = $temporal['periods_to_select'] ?? null;
            $window = $temporal['lookback_window_years'] ?? null;
            if ($periods && $window) {
                $details[] = "Migliori {$periods} di {$window} anni";
            }
        }

        $calculation = $singleReq['calculation_method'] ?? null;
        if ($calculation) {
            $details[] = "Metodo: " . ucfirst($calculation);
        }

        $detail = implode(' | ', $details) ?: 'Fatturato globale minimo';

        return [
            'headers' => ['Lotto', 'Fatturato minimo', 'Dettagli'],
            'rows' => [['Lotto unico', $amount, $detail]],
        ];
    }

    /**
     * Tabella per lot requirements di fatturato
     */
    private function buildLotRequirementsTable(array $requirements): ?array
    {
        $rows = [];

        foreach ($requirements as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $lotId = $entry['lot_id'] ?? $entry['lot'] ?? $entry['id_lotto'] ?? null;
            if ($lotId === null) {
                continue;
            }

            $amount = $this->formatAmount(
                $entry['minimum_amount_eur'] ?? $entry['minimum_amount'] ?? null
            ) ?? '—';

            $rows[] = [(string)$lotId, $amount];
        }

        if (empty($rows)) {
            return null;
        }

        return [
            'headers' => ['Lotto', 'Fatturato minimo'],
            'rows' => $rows,
        ];
    }

    // ========== HELPER METHODS ==========

    /**
     * Recupera dati da gar_opere_dm50 (con cache)
     */
    private function getOpereDm50Data(array $idOpereArray): array
    {
        if (empty($idOpereArray)) {
            return [];
        }

        // Filtra ID da cache
        $toFetch = [];
        foreach ($idOpereArray as $id) {
            $id = (string)$id;
            if (!isset($this->opereDm50Cache[$id])) {
                $toFetch[] = $id;
            }
        }

        if (!empty($toFetch)) {
            $placeholders = implode(',', array_fill(0, count($toFetch), '?'));
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT id_opera, categoria, identificazione_opera, complessita 
                     FROM gar_opere_dm50 
                     WHERE id_opera IN ($placeholders)"
                );
                $stmt->execute($toFetch);
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $id = $row['id_opera'] ?? '';
                    if ($id) {
                        $this->opereDm50Cache[$id] = [
                            'categoria' => $row['categoria'] ?? '—',
                            'identificazione_opera' => $row['identificazione_opera'] ?? '—',
                            'complessita' => $row['complessita'] ?? null
                        ];
                    }
                }
            } catch (\PDOException $e) {
                // Tabella non esiste, cache rimane vuota
            }
        }

        // Ritorna quello che abbiamo in cache per questi ID
        $result = [];
        foreach ($idOpereArray as $id) {
            $id = (string)$id;
            if (isset($this->opereDm50Cache[$id])) {
                $result[$id] = $this->opereDm50Cache[$id];
            }
        }

        return $result;
    }

    /**
     * Formatta importo
     */
    private function formatAmount($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return '€ ' . number_format((float)$value, 2, ',', '.');
        }

        if (is_string($value)) {
            return trim($value) ?: null;
        }

        return null;
    }

    /**
     * Ottiene updated_at da row
     */
    private function getUpdatedAt(array $row): string
    {
        return $row['extraction_created_at'] 
            ?? $row['job_updated_at'] 
            ?? $row['job_completed_at'] 
            ?? $row['job_created_at'] 
            ?? date('Y-m-d H:i:s');
    }

    /**
     * Costruisce value_json con citazioni
     */
    private function buildValueJson(array $row, $answer, array $citations): string
    {
        $valueJson = $row['value_json'] ?? null;
        $decoded = null;

        if (!empty($valueJson)) {
            $decoded = json_decode($valueJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (!empty($citations)) {
                    $decoded['citations'] = $citations;
                    return json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        if (!empty($citations)) {
            return json_encode(['answer' => $answer, 'citations' => $citations], JSON_UNESCAPED_UNICODE);
        }

        return json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE);
    }
}