<?php

namespace Services;

use Services\StorageManager;

/**
 * Servizio per la gestione anagrafica delle gare.
 * Contiene: stati, listGare, aggiornamenti campi, Nextcloud, normalizzazione.
 *
 * La logica di estrazione AI (upload, polling, risultati, tabelle, API proxy)
 * è delegata a ExtractionService. I wrapper pubblici garantiscono retrocompatibilità.
 *
 * @see ExtractionService per la logica di estrazione.
 */
class GareService
{
    // ========== STATI GARE (facilmente personalizzabili) ==========
    // Stati predefiniti per le gare (non dinamici, definiti qui)
    // Struttura consolidata: label e colore insieme
    private const GARE_STATUS_METADATA = [
        1 => ['label' => 'In valutazione', 'color' => '#f0b429'],
        2 => ['label' => 'In corso', 'color' => '#5b8def'],
        3 => ['label' => 'Consegnata', 'color' => '#17a2b8'],
        4 => ['label' => 'Aggiudicata', 'color' => '#63b365'],
        5 => ['label' => 'Non aggiudicata', 'color' => '#e74c3c'],
    ];

    // Mantenute per retrocompatibilità
    private const GARE_STATUS_MAP = [
        1 => 'In valutazione',
        2 => 'In corso',
        3 => 'Consegnata',
        4 => 'Aggiudicata',
        5 => 'Non aggiudicata',
    ];

    private const GARE_STATUS_COLORS = [
        1 => '#f0b429',
        2 => '#5b8def',
        3 => '#17a2b8',
        4 => '#63b365',
        5 => '#e74c3c',
    ];

    /**
     * Restituisce la label per uno stato gara (da status_id)
     */
    public static function getGaraStatusLabel(?int $statusId): string
    {
        $statusId = $statusId ?? 1; // Default a 1
        return self::GARE_STATUS_METADATA[$statusId]['label'] ?? self::GARE_STATUS_MAP[$statusId] ?? 'Sconosciuto';
    }

    /**
     * Restituisce il colore per uno stato gara
     */
    public static function getGaraStatusColor(?int $statusId): string
    {
        $statusId = $statusId ?? 1; // Default a 1
        return self::GARE_STATUS_METADATA[$statusId]['color'] ?? self::GARE_STATUS_COLORS[$statusId] ?? '#f0b429';
    }

    /**
     * Restituisce la mappa completa degli stati (per frontend)
     */
    public static function getGareStatusMap(): array
    {
        return self::GARE_STATUS_MAP;
    }

    /**
     * Restituisce la mappa completa dei colori (per frontend)
     */
    public static function getGareStatusColors(): array
    {
        return self::GARE_STATUS_COLORS;
    }

    // Costanti spostate in ExtractionFormatter






    /**
     * Elenco delle estrazioni con informazioni aggregate sui bandi.
     */
    public static function listGare(array $filters = []): array
    {
        global $database;
        self::ensureGaraColumnsAvailable();

        $pdo = $database->connection;

        // Filtro participation e archiviata
        // Usa COALESCE per gestire il caso in cui archiviata non esista ancora
        $participationFilter = '';
        if (isset($filters['archiviata']) && $filters['archiviata'] === true) {
            // Archivio: mostra solo gare archiviate
            $participationFilter = 'AND COALESCE(g.archiviata, 0) = 1';
        } elseif (isset($filters['participation']) && $filters['participation'] === true) {
            // Elenco Gare: participation=1 E archiviata=0
            $participationFilter = 'AND g.participation = 1 AND COALESCE(g.archiviata, 0) = 0';
        } elseif (isset($filters['participation']) && $filters['participation'] === false) {
            // Estrazione Bandi: participation=0 E archiviata=0
            $participationFilter = 'AND (g.participation = 0 OR g.participation IS NULL) AND COALESCE(g.archiviata, 0) = 0';
        }

        $sql = "SELECT
                    j.id AS job_id,
                    j.status,
                    j.progress_done,
                    j.progress_total,
                    j.ext_batch_id,
                    j.created_at,
                    j.updated_at,
                    j.completed_at,
                    j.status_id,
                    jf.original_name AS file_name,
                    g.participation,
                    COALESCE(g.archiviata, 0) AS archiviata,
                    g.status_id AS gara_status_id,
                    g.priorita,
                    g.assegnato_a,
                    g.note,
                    g.scadenza_custom,
                    g.business_unit,
                    g.codice_commessa,
                    MAX(CASE WHEN e.type_code = 'oggetto_appalto'
                             THEN COALESCE(e.value_text, JSON_UNQUOTE(JSON_EXTRACT(e.value_json, '$.answer'))) END) AS estr_titolo,
                    MAX(CASE WHEN e.type_code = 'stazione_appaltante'
                             THEN COALESCE(e.value_text, JSON_UNQUOTE(JSON_EXTRACT(e.value_json, '$.answer'))) END) AS estr_ente,
                    MAX(CASE WHEN e.type_code = 'luogo_provincia_appalto'
                             THEN COALESCE(e.value_text, JSON_UNQUOTE(JSON_EXTRACT(e.value_json, '$.answer'))) END) AS estr_luogo,
                    MAX(CASE WHEN e.type_code = 'data_uscita_gara_appalto'
                             THEN COALESCE(e.value_text, JSON_UNQUOTE(JSON_EXTRACT(e.value_json, '$.answer'))) END) AS estr_data_uscita,
                    MAX(CASE WHEN e.type_code = 'data_scadenza_gara_appalto'
                             THEN COALESCE(e.value_text, JSON_UNQUOTE(JSON_EXTRACT(e.value_json, '$.answer'))) END) AS estr_data_scadenza,
                    MAX(CASE WHEN e.type_code = 'settore_industriale_gara_appalto'
                             THEN COALESCE(e.value_text, JSON_UNQUOTE(JSON_EXTRACT(e.value_json, '$.answer'))) END) AS estr_settore,
                    MAX(CASE WHEN e.type_code IN ('tipologia_di_gara', 'tipologia_di_appalto')
                             THEN COALESCE(e.value_text, JSON_UNQUOTE(JSON_EXTRACT(e.value_json, '$.answer'))) END) AS estr_tipologia,
                    COALESCE(
                        (SELECT imp.id_opera 
                         FROM gar_gara_importi_opere imp 
                         WHERE imp.job_id = j.id 
                         AND imp.importo_lavori_eur IS NOT NULL 
                         ORDER BY imp.importo_lavori_eur DESC 
                         LIMIT 1),
                        (SELECT COALESCE(
                            JSON_UNQUOTE(JSON_EXTRACT(e2.value_json, '$.entries[0].category_id')),
                            JSON_UNQUOTE(JSON_EXTRACT(e2.value_json, '$.entries[0].id_opera')),
                            JSON_UNQUOTE(JSON_EXTRACT(e2.value_json, '$.entries[0].id_opera_normalized')),
                            JSON_UNQUOTE(JSON_EXTRACT(e2.value_json, '$.entries[0].id_opera_raw'))
                         )
                         FROM ext_extractions e2
                         WHERE e2.job_id = j.id 
                         AND e2.type_code = 'importi_opere_per_categoria_id_opere'
                         AND JSON_EXTRACT(e2.value_json, '$.entries[0]') IS NOT NULL
                         LIMIT 1)
                    ) AS primo_id_opera,
                    (SELECT dm50.identificazione_opera
                     FROM gar_opere_dm50 dm50
                     WHERE dm50.id_opera = COALESCE(
                        (SELECT imp.id_opera 
                         FROM gar_gara_importi_opere imp 
                         WHERE imp.job_id = j.id 
                         AND imp.importo_lavori_eur IS NOT NULL 
                         ORDER BY imp.importo_lavori_eur DESC 
                         LIMIT 1),
                        (SELECT COALESCE(
                            JSON_UNQUOTE(JSON_EXTRACT(e4.value_json, '$.entries[0].category_id')),
                            JSON_UNQUOTE(JSON_EXTRACT(e4.value_json, '$.entries[0].id_opera')),
                            JSON_UNQUOTE(JSON_EXTRACT(e4.value_json, '$.entries[0].id_opera_normalized')),
                            JSON_UNQUOTE(JSON_EXTRACT(e4.value_json, '$.entries[0].id_opera_raw'))
                         )
                         FROM ext_extractions e4
                         WHERE e4.job_id = j.id 
                         AND e4.type_code = 'importi_opere_per_categoria_id_opere'
                         AND JSON_EXTRACT(e4.value_json, '$.entries[0]') IS NOT NULL
                         LIMIT 1)
                     )
                     LIMIT 1
                    ) AS identificazione_opera
                FROM ext_jobs j
                LEFT JOIN ext_job_files jf ON jf.job_id = j.id
                LEFT JOIN ext_extractions e ON e.job_id = j.id
                LEFT JOIN ext_gare g ON g.job_id = j.id
                WHERE 1=1 {$participationFilter}
                GROUP BY j.id
                ORDER BY j.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $jobIds = array_column($rows ?: [], 'job_id');
        $importTotals = self::collectImportTotals($jobIds);
        $importLavoriTotals = self::collectImportLavoriTotals($jobIds);
        $importCorrispettiviTotals = self::collectImportCorrispettiviTotals($jobIds);

        // Raggruppa job per batch_id e recupera progress_percentage per ogni batch
        $batchProgressMap = [];
        $uniqueBatchIds = array_filter(array_unique(array_column($rows, 'ext_batch_id')));

        if (!empty($uniqueBatchIds)) {
            try {
                $env = \Services\ExtractionService::expandEnvPlaceholders(\Services\ExtractionService::loadEnvConfig());
                $apiBase = trim((string) ($env['AI_API_BASE'] ?? ''));
                $apiKey = trim((string) ($env['AI_API_KEY'] ?? ''));

                // Solo se API è configurata, recupera progress dal batch
                if ($apiBase !== '' && $apiKey !== '') {
                    foreach ($uniqueBatchIds as $batchId) {
                        if (empty($batchId))
                            continue;
                        try {
                            $statusRes = \Services\ExtractionService::externalBatchStatus($batchId, $env);
                            if (isset($statusRes['body']['progress_percentage'])) {
                                $batchProgressMap[$batchId] = max(0, min(100, (float) $statusRes['body']['progress_percentage']));
                            }
                        } catch (\Exception $e) {
                            // Ignora errori e usa fallback
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignora errori e usa fallback
            }
        }

        $data = array_map(function (array $row) use ($batchProgressMap, $importTotals, $importLavoriTotals, $importCorrispettiviTotals) {
            $status = strtolower($row['status'] ?? 'queued');
            $batchId = $row['ext_batch_id'] ?? null;
            $jobId = (int) $row['job_id'];

            // Inizializza sempre $done e $total
            $total = (int) ($row['progress_total'] ?? 0);
            $done = (int) ($row['progress_done'] ?? 0);

            // Prova prima con progress_percentage dal batch status
            $progressPercent = null;
            if ($batchId && isset($batchProgressMap[$batchId])) {
                $progressPercent = (int) round($batchProgressMap[$batchId]);
                // Se usiamo progress_percentage dal batch, calcola done e total da esso
                $total = 100;
                $done = $progressPercent;
            }

            // Fallback: calcola da progress_done/progress_total
            if ($progressPercent === null) {
                if ($total <= 0) {
                    $total = 100;
                }

                if (in_array($status, ['completed', 'done'], true)) {
                    $done = $total;
                } elseif ($done > $total) {
                    $done = $total;
                } elseif ($done < 0) {
                    $done = 0;
                }

                $progressPercent = (int) round(($done / max(1, $total)) * 100);
            }

            // Tutti i dati vengono estratti da ext_extractions (nessuna duplicazione)
            $titolo = $row['estr_titolo'] ?? null;
            $ente = $row['estr_ente'] ?? null;
            $luogo = $row['estr_luogo'] ?? null;
            $settore = $row['estr_settore'] ?? null;
            $tipologia = $row['estr_tipologia'] ?? null;

            $dataUscita = \Services\AIextraction\ExtractionFormatter::extractDateValue($row['estr_data_uscita'] ?? null);
            $dataScadenza = \Services\AIextraction\ExtractionFormatter::extractDateValue($row['estr_data_scadenza'] ?? null);

            // status_id ora è in ext_gare (gara_status_id), non più in ext_jobs
            $garaStatusId = isset($row['gara_status_id']) && $row['gara_status_id'] !== null
                ? (int) $row['gara_status_id']
                : 1; // Default a 1 (In valutazione)

            $importoGara = isset($importTotals[$jobId]) ? (float) $importTotals[$jobId] : null;
            $importoLavori = isset($importLavoriTotals[$jobId]) ? (float) $importLavoriTotals[$jobId] : null;
            $importoCorrispettivi = isset($importCorrispettiviTotals[$jobId]) ? (float) $importCorrispettiviTotals[$jobId] : null;

            return [
                'job_id' => $jobId,
                // Stato estrazione (da ext_jobs.status)
                'estrazione' => $row['status'] ?? 'queued',
                'estrazione_label' => \Services\ExtractionService::statusLabel($row['status'] ?? 'queued'),
                // Manteniamo 'status' per retrocompatibilità (deprecato, usa 'estrazione')
                'status' => $row['status'] ?? 'queued',
                'status_label' => \Services\ExtractionService::statusLabel($row['status'] ?? 'queued'),
                'progress_done' => $done,
                'progress_total' => $total,
                'progress_percent' => $progressPercent,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'completed_at' => $row['completed_at'],
                'id' => (int) $row['job_id'], // job_id diventa l'identificatore unico
                'gara_id' => (int) $row['job_id'], // Alias per retrocompatibilità (job_id = gara_id ora)
                'n_gara' => null, // Non più gestito (era in elenco_bandi_gare)
                'titolo' => $titolo,
                'ente' => $ente,
                'luogo' => $luogo,
                'settore' => $settore,
                'tipologia' => $tipologia,
                'data_uscita' => $dataUscita,
                'data_scadenza' => $dataScadenza,
                // Primo id_opera da gar_gara_importi_opere (per colonna Settore in Elenco Gare)
                'primo_id_opera' => !empty($row['primo_id_opera']) ? (string) $row['primo_id_opera'] : null,
                // Identificazione opera da gar_opere_dm50 (descrizione completa per colonna Settore)
                'identificazione_opera' => !empty($row['identificazione_opera']) ? (string) $row['identificazione_opera'] : null,
                // Dati da ext_gare
                'participation' => isset($row['participation']) ? (bool) $row['participation'] : false,
                'archiviata' => isset($row['archiviata']) ? (bool) $row['archiviata'] : false,
                'gara_status_id' => $garaStatusId,
                'priorita' => $row['priorita'] ?? null,
                'assegnato_a' => $row['assegnato_a'] ?? null,
                'note' => $row['note'] ?? null,
                'scadenza_custom' => $row['scadenza_custom'] ?? null,
                'business_unit' => $row['business_unit'] ?? null,
                'codice_commessa' => $row['codice_commessa'] ?? null,
                // Stato gara (alias per retrocompatibilità)
                'status_id' => $garaStatusId,
                'stato' => $garaStatusId, // Alias per retrocompatibilità
                'gara_status_label' => $garaStatusId ? self::getGaraStatusLabel($garaStatusId) : null, // Label stato gara
                'gara_status_color' => $garaStatusId ? self::getGaraStatusColor($garaStatusId) : null, // Colore stato gara
                // Nota: status_label e status_color (riga 436-437) si riferiscono allo stato del job di estrazione
                // gara_status_label e gara_status_color si riferiscono allo stato della gara
                'file_name' => $row['file_name'],
                'importo_gara' => $importoGara,
                'importo_gara_formatted' => $importoGara !== null ? self::formatEuro($importoGara) : null,
                'importo_lavori' => $importoLavori,
                'importo_lavori_formatted' => $importoLavori !== null ? self::formatEuro($importoLavori) : null,
                'importo_corrispettivi' => $importoCorrispettivi,
                'importo_corrispettivi_formatted' => $importoCorrispettivi !== null ? self::formatEuro($importoCorrispettivi) : null,
            ];
        }, $rows ?: []);

        return ['success' => true, 'data' => $data];
    }

    private static function collectImportTotals(array $jobIds): array
    {
        global $database;
        $ids = array_values(array_unique(array_filter(array_map(static function ($id) {
            $intId = (int) $id;
            return $intId > 0 ? $intId : null;
        }, $jobIds))));

        if (!$ids) {
            return [];
        }

        $pdo = $database->connection;
        $jobPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $typeCodes = [
            'importi_opere_per_categoria_id_opere',
            'importi_corrispettivi_categoria_id_opere',
            'importi_requisiti_tecnici_categoria_id_opere',
        ];
        $typePlaceholders = implode(',', array_fill(0, count($typeCodes), '?'));

        $sql = "SELECT e.job_id, tc.header, tc.cell_text
                FROM ext_extractions e
                INNER JOIN ext_table_cells tc ON tc.extraction_id = e.id
                WHERE e.job_id IN ($jobPlaceholders)
                  AND e.type_code IN ($typePlaceholders)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($ids, $typeCodes));

        $totals = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $header = isset($row['header']) ? mb_strtolower(trim($row['header'])) : '';
            if ($header === '') {
                continue;
            }

            if (
                mb_strpos($header, 'importo') === false &&
                mb_strpos($header, 'corrispett') === false &&
                mb_strpos($header, 'valore') === false
            ) {
                continue;
            }

            $amount = \Services\AIextraction\ExtractionFormatter::parseEuroAmount($row['cell_text'] ?? null);
            if ($amount === null || $amount <= 0) {
                continue;
            }

            $jobId = (int) $row['job_id'];
            if (!isset($totals[$jobId])) {
                $totals[$jobId] = 0.0;
            }
            $totals[$jobId] += $amount;
        }

        return $totals;
    }

    /**
     * Calcola la somma degli importi lavori per ogni job_id
     */
    private static function collectImportLavoriTotals(array $jobIds): array
    {
        global $database;
        $ids = array_values(array_unique(array_filter(array_map(static function ($id) {
            $intId = (int) $id;
            return $intId > 0 ? $intId : null;
        }, $jobIds))));

        if (!$ids) {
            return [];
        }

        $pdo = $database->connection;
        $jobPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $totals = [];

        // Prima prova con le tabelle normalizzate gar_gara_importi_opere
        try {
            // Verifica se la tabella esiste
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'gar_gara_importi_opere'");
            if ($tableCheck && $tableCheck->rowCount() > 0) {
                $sql = "SELECT job_id, SUM(importo_lavori_eur) as totale
                        FROM gar_gara_importi_opere
                        WHERE job_id IN ($jobPlaceholders)
                        AND importo_lavori_eur IS NOT NULL
                        GROUP BY job_id";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($ids);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $jobId = (int) $row['job_id'];
                    $totale = (float) ($row['totale'] ?? 0);
                    if ($totale > 0) {
                        $totals[$jobId] = $totale;
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignora errori e procedi con ext_extractions
        }

        // Se non ci sono dati normalizzati, usa ext_extractions
        if (empty($totals)) {
            $typeCode = 'importi_opere_per_categoria_id_opere';
            $sql = "SELECT e.job_id, tc.header, tc.cell_text
                    FROM ext_extractions e
                    INNER JOIN ext_table_cells tc ON tc.extraction_id = e.id
                    WHERE e.job_id IN ($jobPlaceholders)
                      AND e.type_code = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($ids, [$typeCode]));

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $header = isset($row['header']) ? mb_strtolower(trim($row['header'])) : '';
                if ($header === '') {
                    continue;
                }

                // Cerca colonne che contengono "importo" e ("lavori" o "opere") ma non "corrispettivo"
                $hasImporto = mb_strpos($header, 'importo') !== false;
                $hasLavori = mb_strpos($header, 'lavori') !== false;
                $hasOpere = mb_strpos($header, 'opere') !== false;
                $hasCorrispettivo = mb_strpos($header, 'corrispett') !== false;
                
                if (!$hasImporto || $hasCorrispettivo) {
                    continue;
                }
                
                // Deve contenere "lavori" o "opere" per essere un importo lavori
                if (!$hasLavori && !$hasOpere) {
                    continue;
                }

                $amount = \Services\AIextraction\ExtractionFormatter::parseEuroAmount($row['cell_text'] ?? null);
                if ($amount === null || $amount <= 0) {
                    continue;
                }

                $jobId = (int) $row['job_id'];
                if (!isset($totals[$jobId])) {
                    $totals[$jobId] = 0.0;
                }
                $totals[$jobId] += $amount;
            }
        }

        return $totals;
    }

    /**
     * Calcola la somma degli importi corrispettivi per ogni job_id
     */
    private static function collectImportCorrispettiviTotals(array $jobIds): array
    {
        global $database;
        $ids = array_values(array_unique(array_filter(array_map(static function ($id) {
            $intId = (int) $id;
            return $intId > 0 ? $intId : null;
        }, $jobIds))));

        if (!$ids) {
            return [];
        }

        $pdo = $database->connection;
        $jobPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $totals = [];

        // Prima prova con le tabelle normalizzate gar_gara_importi_corrispettivi
        try {
            // Verifica se la tabella esiste
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'gar_gara_importi_corrispettivi'");
            if ($tableCheck && $tableCheck->rowCount() > 0) {
                $sql = "SELECT job_id, SUM(importo_corrispettivo_eur) as totale
                        FROM gar_gara_importi_corrispettivi
                        WHERE job_id IN ($jobPlaceholders)
                        AND importo_corrispettivo_eur IS NOT NULL
                        GROUP BY job_id";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($ids);
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $jobId = (int) $row['job_id'];
                    $totale = (float) ($row['totale'] ?? 0);
                    if ($totale > 0) {
                        $totals[$jobId] = $totale;
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignora errori e procedi con ext_extractions
        }

        // Se non ci sono dati normalizzati, usa ext_extractions
        if (empty($totals)) {
            $typeCode = 'importi_corrispettivi_categoria_id_opere';
            $sql = "SELECT e.job_id, tc.header, tc.cell_text
                    FROM ext_extractions e
                    INNER JOIN ext_table_cells tc ON tc.extraction_id = e.id
                    WHERE e.job_id IN ($jobPlaceholders)
                      AND e.type_code = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($ids, [$typeCode]));

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $header = isset($row['header']) ? mb_strtolower(trim($row['header'])) : '';
                if ($header === '') {
                    continue;
                }

                // Cerca colonne che contengono "corrispettivo"
                if (mb_strpos($header, 'corrispett') === false) {
                    continue;
                }

                $amount = \Services\AIextraction\ExtractionFormatter::parseEuroAmount($row['cell_text'] ?? null);
                if ($amount === null || $amount <= 0) {
                    continue;
                }

                $jobId = (int) $row['job_id'];
                if (!isset($totals[$jobId])) {
                    $totals[$jobId] = 0.0;
                }
                $totals[$jobId] += $amount;
            }
        }

        return $totals;
    }

    private static function parseEuroAmount($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-' || $raw === '--') {
            return null;
        }

        $normalized = mb_strtolower($raw);
        $normalized = str_replace(['€', 'eur'], '', $normalized);
        $normalized = preg_replace('/[^\d.,-]/u', '', $normalized);
        if ($normalized === '' || $normalized === '-' || $normalized === '--') {
            return null;
        }

        if (strpos($normalized, ',') !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private static function formatEuro(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format($value, 2, ',', '.') . ' €';
    }



    













    /**
     * Garantisce la presenza delle colonne gara_id / progress negli ext_*.
     */
    public static function ensureGaraColumnsAvailable(): void
    {
        global $database;
        self::ensureGaraColumns($database->connection);
        self::ensureGaraTableColumns($database->connection);
    }

    /**
     * Garantisce che ext_gare abbia le colonne necessarie (participation, archiviata, ecc.).
     */
    private static function ensureGaraTableColumns(\PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        // Verifica prima che la tabella ext_gare esista
        try {
            $tableExists = $pdo->query("SHOW TABLES LIKE 'ext_gare'");
            if (!$tableExists || $tableExists->rowCount() === 0) {
                // Crea la tabella se non esiste
                $pdo->exec("CREATE TABLE IF NOT EXISTS ext_gare (
                    job_id INT(11) NOT NULL PRIMARY KEY,
                    participation TINYINT(1) NOT NULL DEFAULT 0,
                    archiviata TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Gara archiviata: 0=no, 1=sì',
                    status_id INT(11) NOT NULL DEFAULT 1,
                    priorita INT(11) DEFAULT NULL,
                    assegnato_a VARCHAR(255) DEFAULT NULL,
                    note TEXT DEFAULT NULL,
                    scadenza_custom DATE DEFAULT NULL,
                    business_unit VARCHAR(100) DEFAULT NULL,
                    codice_commessa VARCHAR(50) DEFAULT NULL,
                    nc_files TEXT DEFAULT NULL COMMENT 'JSON array file Nextcloud allegati',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_participation (participation),
                    INDEX idx_archiviata (archiviata),
                    INDEX idx_status_id (status_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                return; // Tabella creata, esci
            }
        } catch (\PDOException $e) {
            error_log('GareService ensureGaraTableColumns table check: ' . $e->getMessage());
        }

        // Verifica che ext_gare abbia il campo archiviata
        try {
            $col = $pdo->query("SHOW COLUMNS FROM ext_gare LIKE 'archiviata'");
            if (!$col || $col->rowCount() === 0) {
                $hasParticipation = $pdo->query("SHOW COLUMNS FROM ext_gare LIKE 'participation'");
                if ($hasParticipation && $hasParticipation->rowCount() > 0) {
                    $pdo->exec("ALTER TABLE ext_gare ADD COLUMN archiviata TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Gara archiviata: 0=no, 1=sì' AFTER participation");
                } else {
                    $pdo->exec("ALTER TABLE ext_gare ADD COLUMN archiviata TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Gara archiviata: 0=no, 1=sì'");
                }
            }
        } catch (\PDOException $e) {
            error_log('GareService ensureGaraTableColumns archiviata: ' . $e->getMessage());
        }

        // Verifica business_unit
        try {
            $col = $pdo->query("SHOW COLUMNS FROM ext_gare LIKE 'business_unit'");
            if (!$col || $col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ext_gare ADD COLUMN business_unit VARCHAR(100) DEFAULT NULL");
            }
        } catch (\PDOException $e) {
            error_log('GareService ensureGaraTableColumns business_unit: ' . $e->getMessage());
        }

        // Verifica codice_commessa
        try {
            $col = $pdo->query("SHOW COLUMNS FROM ext_gare LIKE 'codice_commessa'");
            if (!$col || $col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ext_gare ADD COLUMN codice_commessa VARCHAR(50) DEFAULT NULL");
            }
        } catch (\PDOException $e) {
            error_log('GareService ensureGaraTableColumns codice_commessa: ' . $e->getMessage());
        }

        // Verifica nc_files
        try {
            $col = $pdo->query("SHOW COLUMNS FROM ext_gare LIKE 'nc_files'");
            if (!$col || $col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ext_gare ADD COLUMN nc_files TEXT DEFAULT NULL COMMENT 'JSON array file Nextcloud allegati'");
            }
        } catch (\PDOException $e) {
            error_log('GareService ensureGaraTableColumns nc_files: ' . $e->getMessage());
        }
    }
















    /**
     * Array statico per accumulare i log di debug
     */
    private static $debugLogs = [];











    /**
     * Pulisce testo rimuovendo spazi multipli
     */































    public static function ensureGaraColumns(\PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        // Verifica che ext_jobs abbia status_id (stato gara)
        try {
            $col = $pdo->query("SHOW COLUMNS FROM ext_jobs LIKE 'status_id'");
            if (!$col || $col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ext_jobs ADD COLUMN status_id INT(11) NOT NULL DEFAULT 1 COMMENT 'Stato gara: 1=In valutazione, 2=In corso, 3=Consegnata, 4=Aggiudicata, 5=Non aggiudicata' AFTER ext_batch_id");

                // Crea indice se non esiste
                try {
                    $pdo->exec("CREATE INDEX idx_ext_jobs_status_id ON ext_jobs (status_id)");
                } catch (\PDOException $e) {
                    // Indice potrebbe già esistere, ignora errore
                }
            }
        } catch (\PDOException $e) {
            // Se la colonna esiste già o c'è un errore, ignora
            error_log('GareService ensureGaraColumns status_id: ' . $e->getMessage());
        }

        // Verifica colonne progress (necessarie)
        try {
            $progressCol = $pdo->query("SHOW COLUMNS FROM ext_jobs LIKE 'progress_done'");
            if (!$progressCol || $progressCol->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ext_jobs ADD COLUMN progress_done INT NULL DEFAULT 0 AFTER status");
                $pdo->exec("ALTER TABLE ext_jobs ADD COLUMN progress_total INT NULL DEFAULT 100 AFTER progress_done");
            }
        } catch (\PDOException $e) {
            error_log('GareService ensureGaraColumns progress: ' . $e->getMessage());
        }
    }

























































    // Metodo rimosso: usa ExtractionFormatter::displayNameForExtractionType

    /**
     * Garantisce che esista una riga in ext_gare per il job_id specificato.
     * Se non esiste, la crea.
     */
    private static function ensureGaraExists(int $jobId): void
    {
        global $database;
        $pdo = $database->connection;

        // Verifica se esiste già il campo archiviata
        try {
            $col = $pdo->query("SHOW COLUMNS FROM ext_gare LIKE 'archiviata'");
            if (!$col || $col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE ext_gare ADD COLUMN archiviata TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Gara archiviata: 0=no, 1=sì' AFTER participation");
            }
        } catch (\PDOException $e) {
            error_log('GareService ensureGaraExists archiviata: ' . $e->getMessage());
        }

        // Verifica se esiste già
        $stmt = $pdo->prepare("SELECT job_id FROM ext_gare WHERE job_id = :job_id LIMIT 1");
        $stmt->execute([':job_id' => $jobId]);
        if ($stmt->fetch()) {
            return; // Esiste già
        }

        // Crea la riga
        $stmt = $pdo->prepare("INSERT INTO ext_gare (job_id) VALUES (:job_id)");
        $stmt->execute([':job_id' => $jobId]);
    }

    /**
     * Metodo generico per aggiornare un campo di ext_gare.
     * @param int $jobId ID del job/gara
     * @param string $field Nome del campo (participation, status_id, priorita, assegnato_a, note, scadenza_custom)
     * @param mixed $value Valore da impostare
     * @return array Risultato dell'operazione
     */
    /**
     * Restituisce i campi di ext_gare per un job.
     */
    public static function getGaraMetadata(int $jobId): array
    {
        global $database;
        if ($jobId <= 0) {
            return ['success' => false, 'message' => 'job_id non valido'];
        }
        $pdo = $database->connection;
        $stmt = $pdo->prepare(
            "SELECT job_id, status_id, participation, priorita, assegnato_a, note,
                    scadenza_custom, business_unit, codice_commessa, nc_files, archiviata
             FROM ext_gare WHERE job_id = :job_id LIMIT 1"
        );
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => true, 'data' => ['job_id' => $jobId]];
        }
        return ['success' => true, 'data' => $row];
    }

    private const NC_GARE_ROOT = '/INTRANET/GARE/';

    /**
     * Lista file Nextcloud nella cartella della gara.
     */
    public static function listNcFolderGara(int $jobId): array
    {
        if (!userHasPermission('view_gare')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }
        if ($jobId <= 0) {
            return ['success' => false, 'message' => 'job_id non valido'];
        }
        try {
            \Services\Nextcloud\NextcloudService::init();
            $folder = self::NC_GARE_ROOT . $jobId . '/';
            \Services\Nextcloud\NextcloudService::ensureFolderExists($folder);
            $items = \Services\Nextcloud\NextcloudService::listFolder($folder);
            $files = array_values(array_filter($items, fn($i) => !($i['is_dir'] ?? false)));
            return ['success' => true, 'data' => $files, 'folder' => $folder];
        } catch (\Exception $e) {
            error_log('GareService::listNcFolderGara error (job_id=' . $jobId . '): ' . $e->getMessage());
            return ['success' => false, 'message' => 'Impossibile accedere alla cartella Nextcloud'];
        }
    }

    /**
     * Restituisce i file NC allegati alla gara (da ext_gare.nc_files).
     */
    public static function getNcFilesGara(int $jobId): array
    {
        if (!userHasPermission('view_gare')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }
        global $database;
        $pdo = $database->connection;
        $stmt = $pdo->prepare("SELECT nc_files FROM ext_gare WHERE job_id = :job_id LIMIT 1");
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $files = json_decode($row['nc_files'] ?? '[]', true) ?: [];
        return ['success' => true, 'data' => $files];
    }

    /**
     * Allega un file NC alla gara (aggiunge a ext_gare.nc_files, dedup per path).
     */
    public static function attachNcFileGara(int $jobId, array $fileInfo): array
    {
        if (!userHasPermission('edit_gare')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }
        if ($jobId <= 0 || empty($fileInfo['path'])) {
            return ['success' => false, 'message' => 'job_id e path obbligatori'];
        }
        // Validazione: path deve appartenere alla cartella NC della gara
        $path = str_replace('..', '', $fileInfo['path']);
        $allowedRoot = self::NC_GARE_ROOT . $jobId . '/';
        if (strpos($path, $allowedRoot) !== 0) {
            return ['success' => false, 'message' => 'Percorso file non valido per questa gara'];
        }
        $fileInfo['path'] = $path;
        global $database;
        self::ensureGaraExists($jobId);
        $pdo = $database->connection;
        $stmt = $pdo->prepare("SELECT nc_files FROM ext_gare WHERE job_id = :job_id LIMIT 1");
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $files = json_decode($row['nc_files'] ?? '[]', true) ?: [];
        foreach ($files as $f) {
            if ($f['path'] === $fileInfo['path']) {
                return ['success' => true, 'data' => $files, 'message' => 'File già allegato'];
            }
        }
        $files[] = [
            'path'          => $fileInfo['path'],
            'name'          => $fileInfo['name'] ?? basename($fileInfo['path']),
            'mime'          => $fileInfo['mime'] ?? 'application/octet-stream',
            'size'          => $fileInfo['size'] ?? 0,
            'last_modified' => date('Y-m-d H:i:s'),
        ];
        $pdo->prepare("UPDATE ext_gare SET nc_files = ?, updated_at = NOW() WHERE job_id = ?")
            ->execute([json_encode($files, JSON_UNESCAPED_UNICODE), $jobId]);
        return ['success' => true, 'data' => $files];
    }

    /**
     * Rimuove un file NC dalla gara (non cancella da Nextcloud).
     */
    public static function detachNcFileGara(int $jobId, string $path): array
    {
        if (!userHasPermission('edit_gare')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }
        if ($jobId <= 0 || !$path) {
            return ['success' => false, 'message' => 'job_id e path obbligatori'];
        }
        global $database;
        $pdo = $database->connection;
        $stmt = $pdo->prepare("SELECT nc_files FROM ext_gare WHERE job_id = :job_id LIMIT 1");
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $files = json_decode($row['nc_files'] ?? '[]', true) ?: [];
        $files = array_values(array_filter($files, fn($f) => $f['path'] !== $path));
        $pdo->prepare("UPDATE ext_gare SET nc_files = ?, updated_at = NOW() WHERE job_id = ?")
            ->execute([json_encode($files, JSON_UNESCAPED_UNICODE), $jobId]);
        return ['success' => true, 'data' => $files];
    }

    public static function updateGaraField(int $jobId, string $field, $value): array
    {
        global $database;

        if ($jobId <= 0) {
            return ['success' => false, 'message' => 'job_id non valido'];
        }

        $allowedFields = ['participation', 'status_id', 'priorita', 'assegnato_a', 'note', 'scadenza_custom', 'business_unit', 'codice_commessa', 'nc_files'];
        if (!in_array($field, $allowedFields, true)) {
            return ['success' => false, 'message' => 'Campo non valido'];
        }

        // Validazioni specifiche per campo
        if ($field === 'status_id') {
            if (!is_int($value) || $value < 1 || $value > 5) {
                return ['success' => false, 'message' => 'status_id non valido (deve essere 1-5)'];
            }
        }

        self::ensureGaraExists($jobId);

        $pdo = $database->connection;
        $params = [':job_id' => $jobId];
        $updates = [];

        // Gestione speciale per participation (aggiorna anche archiviata)
        if ($field === 'participation') {
            $participation = is_bool($value) ? $value : (bool) $value;
            $archiviata = $participation ? 0 : 1;
            $updates[] = 'participation = :participation';
            $updates[] = 'archiviata = :archiviata';
            $params[':participation'] = $participation ? 1 : 0;
            $params[':archiviata'] = $archiviata;
        } else {
            $updates[] = $field . ' = :' . $field;
            $params[':' . $field] = $value;
        }

        $sql = "UPDATE ext_gare SET " . implode(', ', $updates) . " WHERE job_id = :job_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $messages = [
            'participation' => 'Participation aggiornata',
            'status_id' => 'Stato gara aggiornato',
            'priorita' => 'Priorità aggiornata',
            'assegnato_a' => 'Assegnatario aggiornato',
            'note' => 'Note aggiornate',
            'scadenza_custom' => 'Scadenza personalizzata aggiornata',
            'business_unit' => 'Business unit aggiornata',
            'codice_commessa' => 'Commessa collegata aggiornata',
            'nc_files' => 'Documenti NC aggiornati',
        ];

        return ['success' => true, 'message' => $messages[$field] ?? 'Campo aggiornato'];
    }

    /**
     * Aggiorna il campo participation in ext_gare.
     * Se participation = true, allora archiviata = false (Sì -> va in elenco_gare)
     * Se participation = false, allora archiviata = true (No -> va in archivio)
     */
    public static function updateParticipation(int $jobId, bool $participation): array
    {
        return self::updateGaraField($jobId, 'participation', $participation);
    }

    /**
     * Aggiorna lo status_id della gara in ext_gare.
     */
    public static function updateGaraStatus(int $jobId, int $statusId): array
    {
        return self::updateGaraField($jobId, 'status_id', $statusId);
    }

    /**
     * Aggiorna la priorità in ext_gare.
     */
    public static function updatePriorita(int $jobId, ?int $priorita): array
    {
        return self::updateGaraField($jobId, 'priorita', $priorita);
    }

    /**
     * Aggiorna l'assegnatario in ext_gare.
     */
    public static function updateAssegnatoA(int $jobId, ?string $assegnatoA): array
    {
        return self::updateGaraField($jobId, 'assegnato_a', $assegnatoA);
    }

    /**
     * Aggiorna le note in ext_gare.
     */
    public static function updateNote(int $jobId, ?string $note): array
    {
        return self::updateGaraField($jobId, 'note', $note);
    }

    /**
     * Aggiorna la scadenza personalizzata in ext_gare.
     */
    public static function updateScadenzaCustom(int $jobId, ?string $scadenzaCustom): array
    {
        return self::updateGaraField($jobId, 'scadenza_custom', $scadenzaCustom);
    }

    /**
     * Aggiorna più campi di ext_gare in una sola chiamata.
     */
    public static function updateGaraFields(int $jobId, array $fields): array
    {
        global $database;
        if ($jobId <= 0) {
            return ['success' => false, 'message' => 'job_id non valido'];
        }

        self::ensureGaraExists($jobId);

        $pdo = $database->connection;
        $updates = [];
        $params = [':job_id' => $jobId];

        if (isset($fields['participation'])) {
            $updates[] = 'participation = :participation';
            $params[':participation'] = $fields['participation'] ? 1 : 0;
        }
        if (isset($fields['status_id'])) {
            $updates[] = 'status_id = :status_id';
            $params[':status_id'] = (int) $fields['status_id'];
        }
        if (isset($fields['priorita'])) {
            $updates[] = 'priorita = :priorita';
            $params[':priorita'] = $fields['priorita'] !== null ? (int) $fields['priorita'] : null;
        }
        if (isset($fields['assegnato_a'])) {
            $updates[] = 'assegnato_a = :assegnato_a';
            $params[':assegnato_a'] = $fields['assegnato_a'];
        }
        if (isset($fields['note'])) {
            $updates[] = 'note = :note';
            $params[':note'] = $fields['note'];
        }
        if (isset($fields['scadenza_custom'])) {
            $updates[] = 'scadenza_custom = :scadenza_custom';
            $params[':scadenza_custom'] = $fields['scadenza_custom'];
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => 'Nessun campo da aggiornare'];
        }

        $sql = "UPDATE ext_gare SET " . implode(', ', $updates) . " WHERE job_id = :job_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return ['success' => true, 'message' => 'Campi aggiornati'];
    }

    // ========== NORMALIZATION LAYER ==========
    // Layer di normalizzazione che popola le tabelle gar_gara_* 
    // a partire dai dati raw di ext_extractions

    /**
     * Normalizza i dati di una gara e popola tutte le tabelle gar_gara_*
     * Viene chiamato dopo il completamento del job di estrazione
     * Delega a GaraDataNormalizer
     * 
     * @param int $jobId ID del job da normalizzare
     * @return array ['success' => bool, 'message' => string]
     */
    public static function normalizeGara(int $jobId): array
    {
        global $database;
        $normalizer = new \Services\AIextraction\GaraDataNormalizer($database->connection);
        $result = $normalizer->normalizeAll($jobId);
        
        $logs = $normalizer->getDebugLogs();
        foreach ($logs as $log) {
            error_log($log);
        }
        
        return $result;
    }
    // ========== DELEGATION WRAPPERS (backward compatibility) ==========
    // Methods delegated to ExtractionService. External callers can still use GareService::method().

    public static function listJobsByGara(int $jobId): array
    {
        return \Services\ExtractionService::listJobsByGara($jobId);
    }

    public static function getEstrazioniGara(int $jobId): array
    {
        return \Services\ExtractionService::getEstrazioniGara($jobId);
    }

    public static function upload(array $fields, array $files): array
    {
        return \Services\ExtractionService::upload($fields, $files);
    }

    public static function jobPull(int $jobId): array
    {
        return \Services\ExtractionService::jobPull($jobId);
    }

    public static function jobShow(int $jobId): array
    {
        return \Services\ExtractionService::jobShow($jobId);
    }

    public static function expandEnvPlaceholders(array $env): array
    {
        return \Services\ExtractionService::expandEnvPlaceholders($env);
    }

    public static function getNormalizedDocs(int $jobId): array
    {
        return \Services\ExtractionService::getNormalizedDocs($jobId);
    }

    public static function getNormalizedEcon(int $jobId): array
    {
        return \Services\ExtractionService::getNormalizedEcon($jobId);
    }

    public static function getNormalizedRoles(int $jobId): array
    {
        return \Services\ExtractionService::getNormalizedRoles($jobId);
    }

    public static function jobResults(int $jobId): array
    {
        return \Services\ExtractionService::jobResults($jobId);
    }

    public static function getDebugLogs(): array
    {
        return \Services\ExtractionService::getDebugLogs();
    }

    public static function loadEnvConfig(): array
    {
        return \Services\ExtractionService::loadEnvConfig();
    }

    public static function checkQuota(array $input): array
    {
        return \Services\ExtractionService::checkQuota($input);
    }

    public static function getExtractionTypes(): array
    {
        return \Services\ExtractionService::getExtractionTypes();
    }

    public static function apiHealth(): array
    {
        return \Services\ExtractionService::apiHealth();
    }

    public static function getBatchUsageAction(array $input): array
    {
        return \Services\ExtractionService::getBatchUsageAction($input);
    }

    public static function listBatchesAction(array $input): array
    {
        return \Services\ExtractionService::listBatchesAction($input);
    }

    public static function downloadHighlightedPdf(array $input): void
    {
        \Services\ExtractionService::downloadHighlightedPdf($input);
    }

    public static function deleteRemoteJob(array $input): array
    {
        return \Services\ExtractionService::deleteRemoteJob($input);
    }

    public static function getRequisitiTecnici(int $jobId): array
    {
        return \Services\ExtractionService::getRequisitiTecnici($jobId);
    }

}
