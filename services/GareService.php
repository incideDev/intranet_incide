<?php

namespace Services;

use Services\StorageManager;

/**
 * Servizio unico per la gestione delle estrazioni AI collegate alle gare.
 * Accorpa il vecchio GareService e GareExtractionService mantenendo solo le funzionalità attive.
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

    // Pattern regex per identificare keyword di sopralluogo/deadline
    // Sostituisce l'array SOPRALLUOGO_DEADLINE_KEYS (34 elementi -> 1 pattern)
    private const SOPRALLUOGO_DEADLINE_PATTERN = '/(?:sopralluogo|site[_\s]?visit|visita|inspection|mandatory[_\s]?site[_\s]?visit|visit).*(?:deadline|scadenza|termine|due|date|required[_\s]?by|must[_\s]?be[_\s]?(?:completed|done)[_\s]?by)|deadline|termine|scadenza|due[_\s]?date|due|entro/i';

    private const EXTRACTION_SORT_ORDER = [
        'oggetto_appalto' => 10,
        'luogo_provincia_appalto' => 20,
        'data_scadenza_gara_appalto' => 30,
        'data_uscita_gara_appalto' => 40,
        'settore_industriale_gara_appalto' => 50,
        'sopralluogo_obbligatorio' => 60,
        'stazione_appaltante' => 70,
        'tipologia_di_appalto' => 80,
        'tipologia_di_gara' => 90,
        'link_portale_stazione_appaltante' => 100,
        'importi_opere_per_categoria_id_opere' => 200,
        'importi_corrispettivi_categoria_id_opere' => 210,
        'importi_requisiti_tecnici_categoria_id_opere' => 220,
        'settore_industriale_gara_appalto_tecnico' => 225,
        'documentazione_richiesta_tecnica' => 230,
        'requisiti_tecnico_professionali' => 300,
        'fatturato_globale_n_minimo_anni' => 310,
        'requisiti_di_capacita_economica_finanziaria' => 320,
        'requisiti_idoneita_professionale_gruppo_lavoro' => 330,
    ];

    /**
     * Strutture standardizzate delle tabelle per ogni type_code.
     * Definisce le colonne standard che devono essere sempre presenti per ogni tipo di estrazione.
     * 
     * Struttura:
     * - 'headers': array di nomi colonne standard (sempre in questo ordine)
     * - 'column_mapping': mappa da possibili nomi colonne nel PDF a colonne standard
     *   (chiave: pattern/nome colonna PDF, valore: indice colonna standard)
     */
    private const STANDARD_TABLE_STRUCTURES = [
        'importi_opere_per_categoria_id_opere' => [
            'headers' => [
                'ID opere',
                'Categoria',
                'Descrizione',
                'Importo stimato dei lavori',
            ],
            'column_mapping' => [
                // ID opere
                'id_opere' => 0,
                'id opera' => 0,
                'id opere' => 0,
                'id' => 0,
                'codice' => 0,
                'codice opera' => 0,
                'codice opere' => 0,
                'numero' => 0,
                'numero opera' => 0,
                'n' => 0,
                'n opera' => 0,
                // Categoria (popolata anche da normativa)
                'category_name' => 1,
                'categoria' => 1,
                'settore' => 1,
                // Descrizione (fallback se non troviamo la normativa)
                'identificazione delle opere' => 2,
                'identificazione opera' => 2,
                'identificazione' => 2,
                'descrizione' => 2,
                'descrizione opera' => 2,
                'descrizione delle opere' => 2,
                'opera' => 2,
                'opere' => 2,
                'lavori' => 2,
                'lavoro' => 2,
                'prestazione' => 2,
                'titolo' => 2,
                // Importo stimato dei lavori
                'importo stimato dei lavori' => 3,
                'importo stimato lavori' => 3,
                'importo stimato' => 3,
                'valore stimato' => 3,
                'valore stimato lavori' => 3,
                'importo lavori' => 3,
                'valore lavori' => 3,
                'importo base' => 3,
                'valore base' => 3,
                'v' => 3,
                'importo' => 3,
            ],
        ],
        'importi_corrispettivi_categoria_id_opere' => [
            'headers' => [
                'ID opere',
                'Categoria',
                'Descrizione',
                'Grado di complessità',
                'Importo del corrispettivo',
            ],
            'column_mapping' => [
                // ID opere
                'id_opere' => 0,
                'id opera' => 0,
                'id opere' => 0,
                'id' => 0,
                'codice' => 0,
                'codice opera' => 0,
                'codice opere' => 0,
                'numero' => 0,
                'numero opera' => 0,
                'n' => 0,
                'n opera' => 0,
                // Categoria
                'category_name' => 1,
                'categoria' => 1,
                'settore' => 1,
                'disciplina' => 1,
                // Descrizione
                'identificazione delle opere' => 2,
                'identificazione opera' => 2,
                'identificazione' => 2,
                'descrizione' => 2,
                'descrizione opera' => 2,
                'descrizione delle opere' => 2,
                'opera' => 2,
                'opere' => 2,
                'lavori' => 2,
                'lavoro' => 2,
                'prestazione' => 2,
                'titolo' => 2,
                // Grado di complessità
                'grado di complessità' => 3,
                'grado complessità' => 3,
                'complessità' => 3,
                'grado' => 3,
                'classe' => 3,
                'categoria complessità' => 3,
                'livello complessità' => 3,
                'g' => 3,
                // Importo del corrispettivo
                'importo corrispettivo' => 4,
                'corrispettivo' => 4,
                'importo' => 4,
                'importo totale' => 4,
                'totale' => 4,
                'valore totale' => 4,
            ],
        ],
        'importi_requisiti_tecnici_categoria_id_opere' => [
            'headers' => [
                'ID opere',
                'Importo corrispettivo',
                'Coefficiente moltiplicativo',
                'Importo requisito',
                'Importo posseduto',
                'Check requisiti',
            ],
            'column_mapping' => [
                'id_opera' => 0,
                'id_opere' => 0,
                'id opera' => 0,
                'id' => 0,
                'codice' => 0,
                'category_code' => 0,
                'importo corrispettivo' => 1,
                'corrispettivo' => 1,
                'valore base' => 1,
                'importo base' => 1,
                'base value' => 1,
                'coefficiente moltiplicativo' => 2,
                'coefficiente' => 2,
                'moltiplicativo' => 2,
                'coeff' => 2,
                'importo requisito' => 3,
                'importo minimo' => 3,
                'minimum amount' => 3,
                'importo richiesto' => 3,
                'importo posseduto' => 4,
                'importo disponibile' => 4,
                'importo detenuto' => 4,
                'possesso' => 4,
                'check' => 5,
                'esito' => 5,
                'soddisfa' => 5,
                'conforme' => 5,
            ],
        ],
    ];

    private const NORMATIVE_COLUMN_CONFIG = [
        'importi_opere_per_categoria_id_opere' => [
            'id_column' => 0,
            'category_column' => 1,
            'description_column' => 2,
            'combine_description' => false,
        ],
        'importi_corrispettivi_categoria_id_opere' => [
            'id_column' => 0,
            'category_column' => 1,
            'description_column' => 2,
            'combine_description' => false,
        ],
    ];


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
                $env = self::expandEnvPlaceholders(self::loadEnvConfig());
                $apiBase = trim((string) ($env['AI_API_BASE'] ?? ''));
                $apiKey = trim((string) ($env['AI_API_KEY'] ?? ''));

                // Solo se API è configurata, recupera progress dal batch
                if ($apiBase !== '' && $apiKey !== '') {
                    foreach ($uniqueBatchIds as $batchId) {
                        if (empty($batchId))
                            continue;
                        try {
                            $statusRes = self::externalBatchStatus($batchId, $env);
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
                'estrazione_label' => self::statusLabel($row['status'] ?? 'queued'),
                // Manteniamo 'status' per retrocompatibilità (deprecato, usa 'estrazione')
                'status' => $row['status'] ?? 'queued',
                'status_label' => self::statusLabel($row['status'] ?? 'queued'),
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
     * Elenco dei job per una singola gara.
     */
    public static function listJobsByGara(int $jobId): array
    {
        global $database;
        if ($jobId <= 0) {
            return ['success' => false, 'message' => 'job_id non valido'];
        }

        self::ensureGaraColumnsAvailable();

        $pdo = $database->connection;
        // Ora usa direttamente job_id (gara_id = job_id)
        $stmt = $pdo->prepare(
            "SELECT j.*, jf.original_name AS file_name,
                    g.participation,
                    g.status_id AS gara_status_id,
                    g.priorita,
                    g.assegnato_a,
                    g.note,
                    g.scadenza_custom
             FROM ext_jobs j
             LEFT JOIN ext_job_files jf ON jf.job_id = j.id
             LEFT JOIN ext_gare g ON g.job_id = j.id
             WHERE j.id = :job_id
             ORDER BY j.created_at DESC"
        );
        $stmt->execute([':job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data = array_map(function (array $row) {
            $status = strtolower($row['status'] ?? 'queued');
            $total = (int) ($row['progress_total'] ?? 0);
            $done = (int) ($row['progress_done'] ?? 0);

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

            return [
                'job_id' => (int) $row['id'],
                'status' => $row['status'] ?? 'queued',
                'status_label' => self::statusLabel($row['status'] ?? 'queued'),
                'file_name' => $row['file_name'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'completed_at' => $row['completed_at'],
                'progress_done' => $done,
                'progress_total' => $total,
                'gara_id' => (int) $row['id'], // job_id = gara_id
                // Dati da ext_gare
                'participation' => isset($row['participation']) ? (bool) $row['participation'] : false,
                'gara_status_id' => isset($row['gara_status_id']) && $row['gara_status_id'] !== null ? (int) $row['gara_status_id'] : 1,
                'priorita' => $row['priorita'] ?? null,
                'assegnato_a' => $row['assegnato_a'] ?? null,
                'note' => $row['note'] ?? null,
                'scadenza_custom' => $row['scadenza_custom'] ?? null,
            ];
        }, $rows ?: []);

        return ['success' => true, 'data' => $data];
    }

    /**
     * Estrazioni consolidate per un job (gara_id = job_id ora).
     */
    public static function getEstrazioniGara(int $jobId): array
    {
        global $database;
        $jobId = filter_var($jobId, FILTER_SANITIZE_NUMBER_INT);
        if (!$jobId) {
            return ['success' => false, 'message' => 'ID Job non valido.'];
        }

        self::ensureGaraColumnsAvailable();

        // Carica dati normalizzati una volta per tutto il job
        $normalizedDocs = self::getNormalizedDocs($jobId);
        $normalizedEcon = self::getNormalizedEcon($jobId);
        $normalizedRoles = self::getNormalizedRoles($jobId);

        // Crea mappe per accesso rapido
        $docsMap = [];
        foreach ($normalizedDocs['data'] ?? [] as $doc) {
            $docsMap[$doc['doc_code']] = $doc;
        }

        $econMap = [];
        foreach ($normalizedEcon['data'] ?? [] as $econ) {
            $key = $econ['tipo'] . ($econ['categoria_codice'] ? '_' . $econ['categoria_codice'] : '');
            if (!isset($econMap[$econ['tipo']])) {
                $econMap[$econ['tipo']] = [];
            }
            $econMap[$econ['tipo']][] = $econ;
        }

        $rolesMap = [];
        foreach ($normalizedRoles['data'] ?? [] as $role) {
            $rolesMap[$role['role_code'] ?? ''] = $role;
        }

        // Debug: log quanti dati normalizzati abbiamo trovato
        self::addDebugLog("Job {$jobId}: getEstrazioniGara - Dati normalizzati: " .
            count($docsMap) . " docs, " .
            count($econMap) . " tipi econ (" . array_sum(array_map('count', $econMap)) . " totali), " .
            count($rolesMap) . " ruoli");

        $sql = "SELECT e.id,
                       e.job_id,
                       e.type_code,
                       e.value_text,
                       e.value_json,
                       e.confidence,
                       e.created_at AS extraction_created_at,
                       j.updated_at AS job_updated_at,
                       j.completed_at AS job_completed_at,
                       j.created_at AS job_created_at,
                       jf.original_name AS file_name
                FROM ext_extractions e
                INNER JOIN ext_jobs j ON j.id = e.job_id
                LEFT JOIN ext_job_files jf ON jf.job_id = e.job_id
                WHERE e.job_id = :job_id
                -- ORDINAMENTO RIMOSSO: l'ordinamento viene fatto in PHP usando DETTAGLIO_GARA_ORDER
                -- per garantire un ordine fisso e deterministico indipendente dall'ordine nel DB";

        $stmt = $database->connection->prepare($sql);
        $stmt->execute([':job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!$rows) {
            return ['success' => true, 'data' => []];
        }

        // Precarica tutte le citazioni per tutte le estrazioni
        $citationsByExtractionId = [];
        $extractionIds = array_map('intval', array_column($rows, 'id'));
        if (!empty($extractionIds)) {
            $citationSql = "SELECT extraction_id, page_number, snippet, highlight_rel_path 
                            FROM ext_citations 
                            WHERE extraction_id IN (" . implode(',', $extractionIds) . ")";
            $citationStmt = $database->connection->prepare($citationSql);
            $citationStmt->execute();
            $allCitations = $citationStmt->fetchAll(\PDO::FETCH_ASSOC);

            self::addDebugLog("Job {$jobId}: getEstrazioniGara - Trovate " . count($allCitations) . " citazioni dal database per " . count($extractionIds) . " estrazioni");

            // Raggruppa citazioni per extraction_id
            foreach ($allCitations as $cit) {
                $extId = (int) $cit['extraction_id'];
                if (!isset($citationsByExtractionId[$extId])) {
                    $citationsByExtractionId[$extId] = [];
                }
                // Formatta la citazione come si aspetta il frontend
                // Il frontend si aspetta: page_number, page, text (array o string), snippet, reason_for_relevance (opzionale)
                // IMPORTANTE: text deve essere un array non vuoto, altrimenti collectCitations lo filtra via
                $snippet = trim($cit['snippet'] ?? '');
                if (empty($snippet)) {
                    // Se snippet è vuoto, salta questa citazione (non ha senso mostrarla)
                    continue;
                }
                $citationsByExtractionId[$extId][] = [
                    'page_number' => $cit['page_number'] ?? null,
                    'page' => $cit['page_number'] ?? null,
                    'text' => [$snippet], // Array con snippet come testo
                    'snippet' => $snippet,
                    'highlight_rel_path' => $cit['highlight_rel_path'] ?? null,
                    'reason_for_relevance' => null, // Non disponibile dal database, ma il frontend lo accetta
                ];
            }
        }

        $estrazioni = [];
        foreach ($rows as $row) {
            // Aggiungi citazioni alla riga
            $extractionId = (int) $row['id'];
            $row['citations'] = $citationsByExtractionId[$extractionId] ?? [];

            self::addDebugLog("Job {$jobId}: getEstrazioniGara - Estrazione {$extractionId} ({$row['type_code']}): " . count($row['citations']) . " citazioni dal database");

            // Estrai citazioni anche da value_json se presente
            if (!empty($row['value_json'])) {
                $decodedJson = json_decode($row['value_json'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedJson)) {
                    if (!empty($decodedJson['citations']) && is_array($decodedJson['citations'])) {
                        // Normalizza citazioni da value_json per assicurarsi che abbiano il formato corretto
                        foreach ($decodedJson['citations'] as $cit) {
                            if (is_array($cit)) {
                                // Assicurati che text sia un array (il frontend si aspetta array)
                                if (isset($cit['text']) && !is_array($cit['text'])) {
                                    $cit['text'] = [$cit['text']];
                                }
                                // Se text non esiste ma c'è snippet, usa snippet come text
                                if (empty($cit['text']) && !empty($cit['snippet'])) {
                                    $cit['text'] = is_array($cit['snippet']) ? $cit['snippet'] : [$cit['snippet']];
                                }
                                // Se text è ancora vuoto, salta questa citazione (non ha senso mostrarla)
                                if (empty($cit['text']) || (is_array($cit['text']) && empty(array_filter($cit['text'])))) {
                                    continue;
                                }
                                // Assicurati che text sia un array non vuoto
                                if (!is_array($cit['text'])) {
                                    $cit['text'] = [$cit['text']];
                                }
                                // Aggiungi page se non presente ma c'è page_number
                                if (!isset($cit['page']) && isset($cit['page_number'])) {
                                    $cit['page'] = $cit['page_number'];
                                }
                                // Assicurati che page_number sia presente se c'è page
                                if (!isset($cit['page_number']) && isset($cit['page'])) {
                                    $cit['page_number'] = $cit['page'];
                                }
                                $row['citations'][] = $cit;
                            }
                        }
                    }
                }
            }

            self::addDebugLog("Job {$jobId}: getEstrazioniGara - Estrazione {$extractionId} ({$row['type_code']}): Totale " . count($row['citations']) . " citazioni dopo merge con value_json");
            $typeCode = $row['type_code'] ?? '';

            // PRIORITÀ: usa dati dalle tabelle normalizzate gar_gara_* se disponibili
            // Verifica prima se esistono dati normalizzati per questo tipo

            // Dati anagrafici (dati scalari)
            if (
                in_array($typeCode, [
                    'oggetto_appalto',
                    'stazione_appaltante',
                    'data_uscita_gara_appalto',
                    'data_scadenza_gara_appalto',
                    'link_portale_stazione_appaltante',
                    'luogo_provincia_appalto',
                    'settore_industriale_gara_appalto',
                    'tipologia_di_gara',
                    'tipologia_di_appalto',
                    'sopralluogo_obbligatorio'
                ])
            ) {
                $estrazione = self::buildEstrazioneFromNormalizedAnagrafica($row, $jobId);
                if ($estrazione) {
                    $estrazioni[] = $estrazione;
                    continue;
                }
            }

            // Importi opere
            if ($typeCode === 'importi_opere_per_categoria_id_opere') {
                $estrazione = self::buildEstrazioneFromNormalizedImportiOpere($row, $jobId);
                if ($estrazione) {
                    $estrazioni[] = $estrazione;
                    continue;
                }
            }

            // Corrispettivi opere
            // IMPORTANTE: Per importi_corrispettivi_categoria_id_opere, usa ESCLUSIVAMENTE dati normalizzati
            // Se non ci sono dati normalizzati, NON fare fallback ai dati raw (ignora completamente)
            if ($typeCode === 'importi_corrispettivi_categoria_id_opere') {
                $estrazione = self::buildEstrazioneFromNormalizedCorrispettiviOpere($row, $jobId);
                if ($estrazione) {
                    $estrazioni[] = $estrazione;
                    continue;
                }
                // Se non ci sono dati normalizzati, salta completamente questa estrazione
                // Non fare fallback ai dati raw dall'AI (contengono fallback in inglese)
                continue;
            }

            // Requisiti tecnici
            if ($typeCode === 'importi_requisiti_tecnici_categoria_id_opere') {
                $estrazione = self::buildEstrazioneFromNormalizedRequisitiTecnici($row, $jobId);
                if ($estrazione) {
                    $estrazioni[] = $estrazione;
                    continue;
                }
            }

            // Fatturato minimo
            if ($typeCode === 'fatturato_globale_n_minimo_anni') {
                $estrazione = self::buildEstrazioneFromNormalizedFatturatoMinimo($row, $jobId);
                if ($estrazione) {
                    $estrazioni[] = $estrazione;
                    continue;
                }
            }

            // Requisiti tecnico-professionali (testo generale)
            if ($typeCode === 'requisiti_tecnico_professionali') {
                $requisitiData = self::getRequisitiTecnici($jobId);
                
                // Costruisci l'estrazione dalla struttura restituita
                $updatedAt = $row['extraction_created_at']
                    ?? $row['job_updated_at']
                    ?? $row['job_completed_at']
                    ?? $row['job_created_at'];
                
                $entries = $requisitiData['value_json']['entries'] ?? [];
                $displayValue = count($entries) > 0 
                    ? count($entries) . ' requisito/i tecnico-professionale/i'
                    : 'Nessun requisito disponibile';
                
                $estrazione = [
                    'id' => (int) ($row['id'] ?? 0),
                    'job_id' => $jobId,
                    'tipo' => 'requisiti_tecnico_professionali',
                    'type_code' => 'requisiti_tecnico_professionali',
                    'type_display' => $requisitiData['type_display'],
                    'value_text' => $displayValue,
                    'display_value' => $displayValue,
                    'value_state' => count($entries) > 0 ? 'table' : 'no_data',
                    'table' => $requisitiData['table'],
                    'value_json' => json_encode($requisitiData['value_json'], JSON_UNESCAPED_UNICODE),
                    'confidence' => $row['confidence'] ?? null,
                    'citations' => $row['citations'] ?? [],
                    'updated_at' => $updatedAt,
                ];
                
                $estrazioni[] = $estrazione;
                continue;
            }

            // Idoneità professionale
            if ($typeCode === 'requisiti_idoneita_professionale_gruppo_lavoro') {
                $estrazione = self::buildEstrazioneFromNormalizedIdoneitaProfessionale($row, $jobId);
                if ($estrazione) {
                    $estrazioni[] = $estrazione;
                    continue;
                }
            }

            // Se ci sono dati normalizzati da processNormalizedRequirements, usali per costruire la risposta
            if ($typeCode === 'documentazione_richiesta_tecnica' && !empty($docsMap)) {
                self::addDebugLog("Job {$jobId}: getEstrazioniGara - Uso dati normalizzati per documentazione_richiesta_tecnica (" . count($docsMap) . " documenti)");
                $estrazione = self::buildEstrazioneFromNormalizedDocs($row, $jobId, $docsMap);
                if ($estrazione) {
                    $estrazioni[] = $estrazione;
                    continue;
                }
            } elseif (in_array($typeCode, ['fatturato_globale_n_minimo_anni', 'requisiti_di_capacita_economica_finanziaria']) && !empty($econMap)) {
                self::addDebugLog("Job {$jobId}: getEstrazioniGara - Uso dati normalizzati per {$typeCode}");
                $estrazione = self::buildEstrazioneFromNormalizedEcon($row, $jobId, $econMap, $typeCode);
                if ($estrazione) {
                    $estrazioni[] = $estrazione;
                    continue;
                }
            } elseif ($typeCode === 'requisiti_idoneita_professionale_gruppo_lavoro' && !empty($rolesMap)) {
                self::addDebugLog("Job {$jobId}: getEstrazioniGara - Uso dati normalizzati per requisiti_idoneita_professionale_gruppo_lavoro (" . count($rolesMap) . " ruoli)");
                $estrazione = self::buildEstrazioneFromNormalizedRoles($row, $jobId, $rolesMap);
                if ($estrazione) {
                    $estrazioni[] = $estrazione;
                    continue;
                }
            }

            // Fallback: usa dati grezzi come prima
            // IMPORTANTE: Per importi_corrispettivi_categoria_id_opere, NON fare fallback ai dati raw
            // (contengono fallback in inglese che non devono essere mostrati)
            if ($typeCode === 'importi_corrispettivi_categoria_id_opere') {
                // Salta completamente questa estrazione se non ci sono dati normalizzati
                continue;
            }

            $decoded = null;
            $isJson = false;
            if (!empty($row['value_json'])) {
                $decoded = json_decode($row['value_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $isJson = is_array($decoded);
                } else {
                    $decoded = null;
                }
            }

            $updatedAt = $row['extraction_created_at']
                ?? $row['job_updated_at']
                ?? $row['job_completed_at']
                ?? $row['job_created_at'];

            $requirementsDetails = ($row['type_code'] ?? '') === 'requisiti_tecnico_professionali'
                ? self::parseRequirementDetails(
                    $decoded,
                    $row['value_json'] ?? null,
                    $row['value_text'] ?? null
                )
                : ['entries' => [], 'display' => null, 'table' => null, 'raw_block' => null];

            $isSopralluogo = ($row['type_code'] ?? '') === 'sopralluogo_obbligatorio';
            $sopralluogoDetails = $isSopralluogo
                ? self::extractSopralluogoDetails(
                    $decoded,
                    $row['value_text'] ?? null,
                    $row['value_json'] ?? null
                )
                : null;

            $displayValue = $requirementsDetails['display']
                ?? ($sopralluogoDetails['status_label'] ?? null)
                ?? \Services\AIextraction\ExtractionFormatter::buildExtractionDisplay($row, $decoded)
                ?? \Services\AIextraction\ExtractionFormatter::stringifyExtractionValue($decoded ?? $row['value_json'] ?? $row['value_text'] ?? null);

            if ($isSopralluogo && $sopralluogoDetails !== null && $sopralluogoDetails['status_label'] !== null) {
                $displayValue = $sopralluogoDetails['status_label'];
            }
            $locationPayload = \Services\AIextraction\ExtractionFormatter::extractLocationData($decoded);

            $displayValueForReason = $displayValue;
            $emptyReason = \Services\AIextraction\ExtractionFormatter::deriveEmptyReason(
                $row['type_code'] ?? '',
                $displayValueForReason,
                $requirementsDetails,
                $decoded,
                $row['value_text'] ?? null,
                $row['value_json'] ?? null
            );

            $existingTable = !empty($requirementsDetails['table']) ? $requirementsDetails['table'] : null;
            $classification = self::classifyExtractionPayload(
                $row['type_code'] ?? null,
                $displayValue,
                $decoded,
                $row['value_text'] ?? null,
                $row['value_json'] ?? null,
                $existingTable
            );

            $displayValue = $classification['display'];
            $valueState = $classification['state'];
            $tableFromAnswer = $classification['table'] ?? null;

            // Prepara citazioni e value_json
            $citations = $row['citations'] ?? [];
            $valueJson = $row['value_json'] ?? null;

            // Se c'è un value_json decodificato, unisci le citazioni
            if ($decoded !== null && is_array($decoded)) {
                // Se ci sono citazioni nel decoded, uniscile con quelle dal database
                if (!empty($decoded['citations']) && is_array($decoded['citations'])) {
                    $citations = array_merge($citations, $decoded['citations']);
                }
                // Aggiungi le citazioni al decoded se non ci sono già
                if (!empty($citations) && (empty($decoded['citations']) || !is_array($decoded['citations']))) {
                    $decoded['citations'] = $citations;
                    $valueJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }

            $estrazione = [
                'id' => (int) $row['id'],
                'job_id' => (int) $row['job_id'],
                'tipo' => $row['type_code'],
                'tipo_display' => \Services\AIextraction\ExtractionFormatter::displayNameForExtractionType($row['type_code']),
                'valore' => $decoded ?? $row['value_text'],
                'is_json' => $isJson,
                'display_value' => $displayValue,
                'location' => $locationPayload,
                'valore_raw' => $row['value_text'],
                'valori_raw' => $row['value_json'],
                'value_json' => $valueJson,
                'citations' => $citations,
                'file_name' => $row['file_name'],
                'confidence' => $row['confidence'],
                'updated_at' => $updatedAt,
            ];

            if ($valueState !== null) {
                $estrazione['value_state'] = $valueState;
            }

            if ($isSopralluogo && $sopralluogoDetails !== null) {
                $estrazione['sopralluogo_details'] = $sopralluogoDetails;
                if (!empty($sopralluogoDetails['deadline_display'])) {
                    $estrazione['sopralluogo_deadline'] = $sopralluogoDetails['deadline_display'];
                } elseif (!empty($sopralluogoDetails['deadline_text'])) {
                    $estrazione['sopralluogo_deadline_text'] = $sopralluogoDetails['deadline_text'];
                }
                if (!empty($sopralluogoDetails['deadline_iso'])) {
                    $estrazione['sopralluogo_deadline_iso'] = $sopralluogoDetails['deadline_iso'];
                }
                if (!empty($sopralluogoDetails['deadline_label'])) {
                    $estrazione['sopralluogo_deadline_label'] = $sopralluogoDetails['deadline_label'];
                }
            }

            if (!empty($requirementsDetails['entries'])) {
                $estrazione['requirements'] = $requirementsDetails['entries'];
            }
            if (!empty($requirementsDetails['table'])) {
                $estrazione['table'] = $requirementsDetails['table'];
            } elseif (!empty($tableFromAnswer)) {
                $estrazione['table'] = $tableFromAnswer;
            }

            // Se ci sono entries nel value_json (anche se vuoti), assicurati che siano disponibili nel value_json finale
            // Questo include anche il caso di entries vuoto ma con citazioni
            if ($decoded !== null && is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])) {
                // Per importi_opere_per_categoria_id_opere, importi_corrispettivi_categoria_id_opere e importi_requisiti_tecnici_categoria_id_opere, 
                // arricchisci entries/requirements con dati da gar_opere_dm50
                $typeCode = $row['type_code'] ?? '';
                $importiTypes = ['importi_opere_per_categoria_id_opere', 'importi_corrispettivi_categoria_id_opere'];
                if (in_array($typeCode, $importiTypes, true) && !empty($decoded['entries'])) {
                    // Nuova struttura: category_id (con fallback per retrocompatibilità)
                    $idOpereArray = array_filter(array_map(function ($entry) {
                        if (!is_array($entry))
                            return null;
                        // Nuova struttura: category_id, fallback a id_opera* per retrocompatibilità
                        return $entry['category_id'] ?? $entry['id_opera'] ?? $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? null;
                    }, $decoded['entries']), function ($id) {
                        return $id !== null && $id !== '';
                    });

                    if (!empty($idOpereArray)) {
                        $opereDm50Data = self::getOpereDm50Data($idOpereArray);

                        // Arricchisci ogni entry con categoria, descrizione e complessita
                        foreach ($decoded['entries'] as &$entry) {
                            if (!is_array($entry))
                                continue;
                            // Nuova struttura: category_id, fallback a id_opera* per retrocompatibilità
                            $idOpera = $entry['category_id'] ?? $entry['id_opera'] ?? $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? null;
                            if ($idOpera && isset($opereDm50Data[$idOpera])) {
                                $entry['categoria'] = $opereDm50Data[$idOpera]['categoria'] ?? '—';
                                $entry['descrizione'] = $opereDm50Data[$idOpera]['identificazione_opera'] ?? '—';
                                // Aggiungi complessita solo se non è già presente nell'entry
                                if (!isset($entry['complessita']) && isset($opereDm50Data[$idOpera]['complessita'])) {
                                    $entry['complessita'] = $opereDm50Data[$idOpera]['complessita'];
                                }
                            }
                        }
                        unset($entry);
                    }
                }

                // Per importi_requisiti_tecnici_categoria_id_opere, arricchisci requirements con dati da gar_opere_dm50
                if ($typeCode === 'importi_requisiti_tecnici_categoria_id_opere' && !empty($decoded['requirements']) && is_array($decoded['requirements'])) {
                    // Raccogli tutti gli id_opera dai requirements
                    $idOpereArray = array_filter(array_map(function ($req) {
                        if (!is_array($req))
                            return null;
                        return $req['id_opera'] ?? null;
                    }, $decoded['requirements']), function ($id) {
                        return $id !== null && $id !== '';
                    });

                    if (!empty($idOpereArray)) {
                        $opereDm50Data = self::getOpereDm50Data($idOpereArray);

                        // Arricchisci ogni requirement con categoria e descrizione
                        foreach ($decoded['requirements'] as &$req) {
                            if (!is_array($req))
                                continue;
                            $idOpera = $req['id_opera'] ?? null;
                            if ($idOpera && isset($opereDm50Data[$idOpera])) {
                                // Popola categoria e descrizione da gar_opere_dm50
                                $req['categoria'] = $opereDm50Data[$idOpera]['categoria'] ?? ($req['category_name'] ?? '—');
                                $req['descrizione'] = $opereDm50Data[$idOpera]['identificazione_opera'] ?? ($req['description'] ?? '—');
                            }
                        }
                        unset($req);
                    }
                }

                // Aggiorna value_json con entries/requirements arricchiti
                $estrazione['value_json'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }

            if ($emptyReason !== null) {
                $estrazione['empty_reason'] = $emptyReason;
            }

            $estrazioni[] = $estrazione;
        }

        usort($estrazioni, [\Services\AIextraction\ExtractionFormatter::class, 'compareExtractionSort']);

        return ['success' => true, 'data' => $estrazioni];
    }

    /**
     * Costruisce estrazione da dati normalizzati ext_req_docs
     * Mantiene lo stesso formato di risposta del codice esistente
     */
    private static function buildEstrazioneFromNormalizedDocs(array $row, int $jobId, array $docsMap): ?array {
        global $database;
        $builder = new ExtractionBuilder($database->connection);
        return $builder->buildFromNormalizedDocs($row, $jobId, $docsMap);
    }
    
    private static function buildEstrazioneFromNormalizedEcon(array $row, int $jobId, array $econMap, string $typeCode): ?array {
        global $database;
        $builder = new ExtractionBuilder($database->connection);
        return $builder->buildFromNormalizedEcon($row, $jobId, $econMap, $typeCode);
    }
    /**
     * Costruisce estrazione da dati normalizzati ext_req_roles
     * Mantiene lo stesso formato di risposta del codice esistente
     */
    private static function buildEstrazioneFromNormalizedRoles(array $row, int $jobId, array $rolesMap): ?array
    {
        global $database;
        $builder = new ExtractionBuilder($database->connection);
        return $builder->buildFromNormalizedRoles($row, $jobId, $rolesMap);
    }



    /**
     * Avvia l'analisi di uno o più PDF.
     */
    public static function upload(array $fields, array $files): array
    {
        $database = $GLOBALS['database'] ?? null;
        $pdo = $database ? ($database->connection ?? null) : null;

        if (!$pdo) {
            throw new \RuntimeException('Connessione al database non disponibile');
        }

        $types = self::normalizeExtractionTypes($fields['extraction_types'] ?? null);
        if (!$types) {
            throw new \RuntimeException('extraction_types mancante o vuoto');
        }

        if (empty($files['file'])) {
            throw new \RuntimeException('file PDF mancante');
        }

        $normalizedFiles = self::normalizeFilesArray($files['file']);
        if (empty($normalizedFiles)) {
            throw new \RuntimeException('Nessun file valido');
        }

        // Carica l'env e espande i placeholder tipo ${AI_API_BASE}
        $env = self::expandEnvPlaceholders(self::loadEnvConfig());
        $isLocal = (($env['APP_ENV'] ?? 'prod') === 'local');
        $apiBase = trim((string) ($env['AI_API_BASE'] ?? ''));
        $apiKey  = trim((string) ($env['AI_API_KEY'] ?? ''));

        // Se l'API non è configurata logghiamo solo l'essenziale;
        // il dettaglio viene comunque salvato in error_message più sotto.
        if ($apiBase === '' || $apiKey === '') {
            $missing = [];
            if ($apiBase === '') {
                $missing[] = 'AI_API_BASE';
            }
            if ($apiKey === '') {
                $missing[] = 'AI_API_KEY';
            }

            error_log(sprintf(
                'GareService::upload - API esterna non configurata: mancano %s',
                implode(', ', $missing)
            ));
        }

        $jobs   = [];
        $debugs = [];

        $force = !empty($fields['force']) && filter_var($fields['force'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

        foreach ($normalizedFiles as $fileIndex => $file) {
            $jobId = null;
            $extJobId = null;
            $extBatchId = null;
            $debug = null;

            try {
                $fileSha = hash_file('sha256', $file['tmp_name']);
                if ($fileSha === false) {
                    throw new \RuntimeException('Impossibile calcolare hash file');
                }

                $existing = self::findFileBySha($fileSha);
                if ($existing) {
                    if ($force && !empty($existing['job_id'])) {
                        self::purgeJob((int) $existing['job_id']);
                        if (!empty($existing['rel_path'])) {
                            self::deleteStoredFile($existing['rel_path']);
                        }
                    } else {
                        $jobs[] = [
                            'job_id' => $existing['job_id'] ?? null,
                            'ext_job_id' => $existing['ext_job_id'] ?? null,
                            'ext_batch_id' => $existing['ext_batch_id'] ?? null,
                            'file_name' => $file['name'] ?? null,
                            'error' => 'File già caricato (job #' . ($existing['job_id'] ?? 'n/d') . ')',
                            'duplicate' => true,
                        ];
                        continue;
                    }
                }

                $jobId = self::persistJobSkeleton($types);
                $meta = self::saveUploadedPdf($file, $fileSha);

                // Ora job_id = gara_id, non serve più linkJobToGara
                // Verifica solo che status_id sia impostato se fornito
                if (!empty($fields['status_id']) && is_numeric($fields['status_id'])) {
                    $pdo->prepare("UPDATE ext_jobs SET status_id = :sid, updated_at = NOW() WHERE id = :id")
                        ->execute([':sid' => (int) $fields['status_id'], ':id' => $jobId]);
                }

                try {
                    self::attachFileToJob($jobId, $meta);
                } catch (\PDOException $pdoEx) {
                    $isDup = ($pdoEx->getCode() === '23000') || (($pdoEx->errorInfo[1] ?? null) == 1062);
                    if ($isDup) {
                        self::deleteStoredFile($meta['rel_path']);
                        self::deleteJob($jobId);
                        $existing = self::findFileBySha($meta['sha256']);
                        if ($existing && $force && !empty($existing['job_id'])) {
                            self::purgeJob((int) $existing['job_id']);
                            if (!empty($existing['rel_path'])) {
                                self::deleteStoredFile($existing['rel_path']);
                            }
                            $jobId = self::persistJobSkeleton($types);
                            $meta = self::saveUploadedPdf($file, $fileSha);
                            // Nota: job_id = gara_id ora, non serve più linkJobToGara
                            self::attachFileToJob($jobId, $meta);
                        } else {
                            $jobs[] = [
                                'job_id' => $existing['job_id'] ?? null,
                                'ext_job_id' => $existing['ext_job_id'] ?? null,
                                'ext_batch_id' => $existing['ext_batch_id'] ?? null,
                                'file_name' => $file['name'] ?? null,
                                'error' => 'File già caricato (job #' . ($existing['job_id'] ?? 'n/d') . ')',
                                'duplicate' => true,
                            ];
                            continue;
                        }
                    }
                    throw $pdoEx;
                }

                if ($apiBase !== '' && $apiKey !== '') {
                    try {
                        $resp = self::externalAnalyzeSingle(
                            [
                                'extraction_types'   => $types,
                                'notification_email' => self::defaultNotificationEmail($fields),
                                'file_name'          => $file['name'] ?? 'document.pdf',
                            ],
                            [
                                'tmp_name' => self::absoluteStoragePath($meta['rel_path']),
                                'type'     => $meta['mime_type'],
                                'name'     => $meta['original_name'],
                            ],
                            $env
                        );

                        self::logAnalyzeResponse($jobId, $resp);

                        $extBatchId = $resp['body']['batch_id'] ?? null;
                        $extJobId   = $resp['body']['job_id'] ?? null;

                        // Se l'API ha risposto ma non ci ha dato gli ID, logga il problema
                        if ($extBatchId === null && $extJobId === null) {
                            $msg = sprintf('API_OK_NO_IDS | HTTP=%s', $resp['status'] ?? 'n/d');
                            $pdo->prepare("
                                UPDATE ext_jobs
                                   SET error_message = :msg,
                                       updated_at    = NOW()
                                 WHERE id = :id
                            ")->execute([
                                ':msg' => substr($msg, 0, 255),
                                ':id'  => $jobId,
                            ]);
                        }

                        self::markExternalIds($jobId, $extJobId, $extBatchId);
                        self::updateJobStatus($jobId, 'queued', null, ['done' => 0, 'total' => 100]);

                        if ($isLocal) {
                            $debug = [
                                'env' => [
                                    'APP_ENV'          => $env['APP_ENV'] ?? null,
                                    'AI_API_BASE'      => $apiBase,
                                    'AI_API_START_URL' => $env['AI_API_START_URL'] ?? null,
                                ],
                                'request' => [
                                    'extraction_types' => $types,
                                    'file_name'        => $file['name'] ?? null,
                                    'file_type'        => $file['type'] ?? null,
                                ],
                                'api' => [
                                    'status' => $resp['status'] ?? null,
                                    'body'   => $resp['body'] ?? null,
                                    'raw'    => $resp['raw'] ?? null,
                                ],
                            ];
                        }
                    } catch (\Throwable $apiEx) {
                        // Logga l'errore API nel job
                        try {
                            $msg = sprintf('API_EXCEPTION | %s', $apiEx->getMessage());
                            $pdo->prepare("
                                UPDATE ext_jobs
                                   SET error_message = :msg,
                                       updated_at    = NOW()
                                 WHERE id = :id
                            ")->execute([
                                ':msg' => substr($msg, 0, 255),
                                ':id'  => $jobId,
                            ]);
                        } catch (\Throwable $logEx) {
                            error_log('GareService::upload - impossibile salvare error_message per job '
                                . $jobId . ': ' . $logEx->getMessage());
                        }

                        self::updateJobStatus($jobId, 'queued', null, ['done' => 0, 'total' => 100]);

                        if ($isLocal) {
                            $debug = [
                                'error'   => 'external_call_failed',
                                'message' => $apiEx->getMessage(),
                                'env'     => [
                                    'AI_API_BASE'        => $apiBase,
                                    'AI_API_START_URL'   => $env['AI_API_START_URL'] ?? null,
                                    'AI_API_KEY_present' => $apiKey !== '',
                                ],
                            ];
                        }
                    }
                } else {
                    // API non configurata: logga solo l'essenziale
                    try {
                        $missing = [];
                        if ($apiBase === '') $missing[] = 'AI_API_BASE';
                        if ($apiKey === '') $missing[] = 'AI_API_KEY';
                        
                        $msg = sprintf('API_DISABLED | Missing: %s', implode(',', $missing));
                        $pdo->prepare("
                            UPDATE ext_jobs
                               SET error_message = :msg,
                                   updated_at    = NOW()
                             WHERE id = :id
                        ")->execute([
                            ':msg' => substr($msg, 0, 255),
                            ':id'  => $jobId,
                        ]);
                    } catch (\Throwable $logEx) {
                        error_log('GareService::upload - impossibile salvare error_message (API_DISABLED) per job '
                            . $jobId . ': ' . $logEx->getMessage());
                    }

                    self::updateJobStatus($jobId, 'queued', null, ['done' => 0, 'total' => 100]);

                    if ($isLocal) {
                        $debug = [
                            'note' => 'API esterna non configurata',
                            'env'  => [
                                'AI_API_BASE'        => $apiBase,
                                'AI_API_KEY_present' => $apiKey !== '',
                            ]
                        ];
                    }
                }

                $jobs[] = [
                    'job_id' => $jobId,
                    'ext_job_id' => $extJobId,
                    'ext_batch_id' => $extBatchId,
                    'file_name' => $file['name'] ?? null,
                ];

                if ($isLocal && $debug) {
                    $debugs[] = $debug;
                }
            } catch (\Throwable $e) {
                $jobs[] = [
                    'job_id' => null,
                    'ext_job_id' => null,
                    'ext_batch_id' => null,
                    'file_name' => $file['name'] ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $out = [
            'ok' => true,
            'jobs' => $jobs,
            'count' => count($jobs),
        ];

        if (count($jobs) === 1) {
            $out['job_id'] = $jobs[0]['job_id'];
            $out['ext_job_id'] = $jobs[0]['ext_job_id'];
            $out['ext_batch_id'] = $jobs[0]['ext_batch_id'];
        }

        if (!empty($debugs)) {
            $out['debug'] = $debugs;
        }

        return $out;
    }

    /**
     * Sincronizza stato e risultati con l'API esterna.
     */
    public static function jobPull(int $jobId): array
    {
        $timeTotalStart = microtime(true);

        $job = self::fetchJob($jobId);
        if (!$job) {
            return ['ok' => false, 'error' => 'Job not found'];
        }

        // Carica l'env e espande i placeholder tipo ${AI_API_BASE}
        $env = self::expandEnvPlaceholders(self::loadEnvConfig());
        $apiBase = trim((string) ($env['AI_API_BASE'] ?? ''));
        $apiKey  = trim((string) ($env['AI_API_KEY'] ?? ''));

        if ($apiBase === '' || $apiKey === '') {
            return [
                'ok'         => true,
                'status'     => $job['status'] ?? 'queued',
                'local_only' => true,
            ];
        }

        $batchId = $job['ext_batch_id'] ?: $job['ext_job_id'];
        if (!$batchId) {
            return [
                'ok'         => true,
                'status'     => $job['status'] ?? 'queued',
                'local_only' => true,
                'debug'      => [
                    'reason' => 'Nessun batch_id trovato',
                    'ext_batch_id' => $job['ext_batch_id'] ?? 'NULL',
                    'ext_job_id' => $job['ext_job_id'] ?? 'NULL',
                    'job_data' => [
                        'id' => $job['id'] ?? null,
                        'status' => $job['status'] ?? null,
                        'error_message' => $job['error_message'] ?? null,
                    ],
                    'note' => 'Il batch_id non è stato salvato durante l\'upload. Probabilmente l\'API ha restituito un errore (404, ecc.). Controlla i log dell\'upload.',
                ],
            ];
        }

        $timeStart = microtime(true);
        $statusRes = self::externalBatchStatus($batchId, $env);
        $timeStatus = microtime(true) - $timeStart;
        self::addDebugLog("Job " . ($jobId ?? 'unknown') . ": externalBatchStatus completato (tempo: " . round($timeStatus * 1000, 2) . "ms)");

        $httpStatus = (int) ($statusRes['status'] ?? 0);
        
        // Gestione errori HTTP PRIMA di normalizzare lo status
        if ($httpStatus >= 400) {
            $errorMsg = $statusRes['body']['detail'] ?? $statusRes['body']['message'] ?? $statusRes['raw'] ?? 'Errore API';
            $errorMsg = substr($errorMsg, 0, 500);
            
            // Se è un 404, il batch non esiste più - marca come failed
            if ($httpStatus === 404) {
                self::updateJobStatus($jobId, 'failed', 'Batch non trovato (404): ' . $errorMsg, ['done' => 0, 'total' => 100]);
                self::logBatchStatus($jobId, $batchId, $statusRes);
                return [
                    'ok' => false,
                    'status' => 'failed',
                    'progress' => ['done' => 0, 'total' => 100],
                    'http_status' => $httpStatus,
                    'batch_id' => $batchId,
                    'error' => 'Batch non trovato (404): ' . $errorMsg,
                    'debug' => [
                        'raw_response' => substr($statusRes['raw'] ?? '', 0, 500),
                    ],
                ];
            }
            
            // Se è un 429 (Too Many Requests) o 503 (Service Unavailable), mantieni in coda invece di fallire
            if ($httpStatus === 429 || $httpStatus === 503) {
                // Non aggiornare lo status, mantieni quello attuale (queued/processing)
                $currentStatus = $job['status'] ?? 'queued';
                $currentProgress = self::extractProgress($statusRes['body'] ?? []) ?: ['done' => 0, 'total' => 100];
                self::logBatchStatus($jobId, $batchId, $statusRes);
                return [
                    'ok' => true,
                    'status' => $currentStatus, // Mantieni lo status attuale
                    'progress' => $currentProgress,
                    'http_status' => $httpStatus,
                    'batch_id' => $batchId,
                    'warning' => 'API temporaneamente non disponibile (' . $httpStatus . '). Riproverà al prossimo polling.',
                ];
            }
            
            // Per altri errori HTTP >= 500 (errori server), mantieni in coda invece di fallire immediatamente
            if ($httpStatus >= 500) {
                $currentStatus = $job['status'] ?? 'queued';
                $currentProgress = self::extractProgress($statusRes['body'] ?? []) ?: ['done' => 0, 'total' => 100];
                self::logBatchStatus($jobId, $batchId, $statusRes);
                return [
                    'ok' => true,
                    'status' => $currentStatus, // Mantieni lo status attuale
                    'progress' => $currentProgress,
                    'http_status' => $httpStatus,
                    'batch_id' => $batchId,
                    'warning' => 'Errore server API (' . $httpStatus . '). Riproverà al prossimo polling.',
                ];
            }
            
            // Per altri errori HTTP (400-499 esclusi 404, 429), marca come failed
            self::updateJobStatus($jobId, 'failed', 'Errore API (' . $httpStatus . '): ' . $errorMsg, ['done' => 0, 'total' => 100]);
            self::logBatchStatus($jobId, $batchId, $statusRes);
            return [
                'ok' => false,
                'status' => 'failed',
                'progress' => ['done' => 0, 'total' => 100],
                'http_status' => $httpStatus,
                'batch_id' => $batchId,
                'error' => 'Errore API (' . $httpStatus . '): ' . $errorMsg,
                'debug' => [
                    'raw_response' => substr($statusRes['raw'] ?? '', 0, 500),
                ],
            ];
        }

        $normalizedStatus = self::normalizeExternalStatus($statusRes['body'] ?? []);
        $progress = self::extractProgress($statusRes['body'] ?? []);

        self::logBatchStatus($jobId, $batchId, $statusRes);

        $finalStatuses = ['completed', 'done'];

        if (!in_array($normalizedStatus, $finalStatuses, true)) {
            self::updateJobStatus($jobId, $normalizedStatus, null, $progress);
            return [
                'ok' => true,
                'status' => $normalizedStatus,
                'progress' => $progress,
                'http_status' => $statusRes['status'] ?? null,
                'batch_id' => $batchId,
            ];
        }

        $progress = ['done' => max(95, (int) ($progress['done'] ?? 0)), 'total' => 100];

        $timeStart = microtime(true);
        $results = self::externalBatchResults($batchId, $env);
        $timeResults = microtime(true) - $timeStart;
        self::addDebugLog("Job {$jobId}: externalBatchResults completato (tempo: " . round($timeResults * 1000, 2) . "ms)");

        $resultsStatus = (int) ($results['status'] ?? 0);

        if ($resultsStatus >= 400) {
            $maxRetries = 3;
            for ($attempt = 0; $attempt < $maxRetries && $resultsStatus >= 400; $attempt++) {
                usleep(500000); // 0.5 second between retries
                $results = self::externalBatchResults($batchId, $env);
                $resultsStatus = (int) ($results['status'] ?? 0);
            }
        }

        if ($resultsStatus >= 400) {
            $progress['done'] = min(99, $progress['done']);
            self::updateJobStatus($jobId, 'processing', null, $progress);
            self::logBatchResultsError($batchId, $results);
            return [
                'ok' => true,
                'status' => 'processing',
                'progress' => $progress,
                'http_status' => $resultsStatus,
                'batch_id' => $batchId,
                'pending_results' => true,
                'error' => $results['body']['detail'] ?? $results['raw'] ?? null,
            ];
        }

        // NOTA: normalizeGara() viene chiamato DOPO aver salvato le estrazioni (vedi riga 1472)
        // Non chiamarlo qui perché le estrazioni non sono ancora state salvate in ext_extractions
        self::logBatchResults($batchId, $results['body'] ?? null);

        $timeStart = microtime(true);
        $answers = self::mapExternalAnswersFromBatch($results['body'] ?? []);
        $timeMap = microtime(true) - $timeStart;
        self::addDebugLog("Job {$jobId}: jobPull - answers mappati: " . count($answers) . " (tempo: " . round($timeMap * 1000, 2) . "ms)");

        if (!empty($answers)) {
            $timeStart = microtime(true);
            self::upsertExtractions($jobId, $answers);
            $timeUpsert = microtime(true) - $timeStart;
            self::addDebugLog("Job {$jobId}: Estrazioni salvate in ext_extractions (tempo: " . round($timeUpsert * 1000, 2) . "ms), ora processo requisiti normalizzati");

            // Processa e popola le tabelle normalizzate (solo se ci sono estrazioni valide)
            try {
                $timeStart = microtime(true);
                self::processNormalizedRequirements($jobId);
                $timeProcess = microtime(true) - $timeStart;
                self::addDebugLog("Job {$jobId}: processNormalizedRequirements completato (tempo: " . round($timeProcess * 1000, 2) . "ms)");
            } catch (\Throwable $e) {
                // Log errore ma non bloccare il flusso principale
                $errorMsg = "Errore processamento requisiti normalizzati per job {$jobId}: " . $e->getMessage();
                self::addDebugLog($errorMsg);
                self::addDebugLog("Stack trace: " . $e->getTraceAsString());
                error_log($errorMsg);
                error_log("Stack trace: " . $e->getTraceAsString());
            }

            // ============================================================
            // NORMALIZZAZIONE TABELLE gar_gara_*
            // ============================================================
            // IMPORTANTE: Questa chiamata avviene DOPO aver salvato le estrazioni
            // in ext_extractions (riga 1448: upsertExtractions).
            // 
            // GaraDataNormalizer::normalizeAll() legge da ext_extractions e popola:
            // - gar_gare_anagrafica (dati scalari: oggetto, stazione appaltante, ecc.)
            // - gar_gara_importi_opere (importi lavori per categoria/id_opera)
            // - gar_gara_corrispettivi_opere (onorari per categoria/id_opera)
            // - gar_gara_requisiti_tecnici (testo generale) + gar_gara_requisiti_tecnici_categoria
            // - gar_gara_fatturato_minimo (fatturato minimo globale)
            // - gar_gara_capacita_econ_fin (requisiti capacità economico-finanziaria)
            // - gar_gara_idoneita_professionale (requisiti idoneità professionale)
            //
            // Se le tabelle gar_gara_* non esistono, la normalizzazione viene
            // saltata silenziosamente (vedi GaraDataNormalizer::tableExists()).
            // ============================================================
            try {
                $timeStart = microtime(true);
                $normalizeResult = self::normalizeGara($jobId);
                $timeNormalize = microtime(true) - $timeStart;
                if ($normalizeResult['success']) {
                    self::addDebugLog("Job {$jobId}: normalizeGara completato (tempo: " . round($timeNormalize * 1000, 2) . "ms)");
                } else {
                    self::addDebugLog("Job {$jobId}: normalizeGara fallito: " . ($normalizeResult['message'] ?? 'errore sconosciuto'));
                }
            } catch (\Throwable $e) {
                // Log errore ma non bloccare il flusso principale
                // Il frontend usa ancora ext_*, quindi la normalizzazione può fallire senza rompere la UI
                $errorMsg = "Errore normalizzazione gara per job {$jobId}: " . $e->getMessage();
                self::addDebugLog($errorMsg);
                self::addDebugLog("Stack trace: " . $e->getTraceAsString());
                error_log($errorMsg);
                error_log("Stack trace: " . $e->getTraceAsString());
            }
        } else {
            // Log quando non ci sono risposte dalle API
            $msg = "Nessuna risposta valida dalle API per job {$jobId} - processNormalizedRequirements NON verrà chiamato";
            self::addDebugLog($msg);
            error_log($msg);
        }

        self::updateJobStatus($jobId, 'completed', null, ['done' => 100, 'total' => 100]);
        self::finalizeJob($jobId);

        $timeTotal = microtime(true) - $timeTotalStart;
        self::addDebugLog("Job {$jobId}: jobPull TOTALE completato (tempo totale: " . round($timeTotal * 1000, 2) . "ms)");

        $response = [
            'ok' => true,
            'status' => 'completed',
            'progress' => ['done' => 100, 'total' => 100],
            'http_status' => $resultsStatus,
            'batch_id' => $batchId,
        ];

        // Aggiungi i log di debug alla risposta
        $debugLogs = self::getDebugLogs();
        if (!empty($debugLogs)) {
            $response['debug_logs'] = $debugLogs;
        }

        return $response;
    }

    /**
     * Ritorna i dettagli del job.
     */
    public static function jobShow(int $jobId): array
    {
        $row = self::fetchJob($jobId);
        if (!$row) {
            return ['ok' => false, 'error' => 'Not found'];
        }
        return ['ok' => true, 'data' => $row];
    }

    /**
     * Espande i placeholder ${VAR} nei valori dell'array env.
     * Esempio: se AI_API_BASE="http://host" e
     * AI_API_START_URL="${AI_API_BASE}/api/batch/analyze",
     * questo metodo sostituisce il placeholder con il valore reale.
     */
    public static function expandEnvPlaceholders(array $env): array
    {
        if (empty($env)) {
            return $env;
        }

        // Facciamo un paio di passaggi per gestire dipendenze incrociate semplici
        $maxPasses = 2;

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            foreach ($env as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }

                $env[$key] = preg_replace_callback(
                    '/\$\{([A-Z0-9_]+)\}/',
                    static function (array $m) use ($env) {
                        $varName = $m[1] ?? '';
                        if ($varName === '' || !array_key_exists($varName, $env)) {
                            // Se non troviamo la variabile, lasciamo il placeholder com'è
                            return $m[0];
                        }
                        return (string) $env[$varName];
                    },
                    $value
                );
            }
        }

        return $env;
    }

    /**
     * Recupera documenti normalizzati da ext_req_docs per un job
     */
    public static function getNormalizedDocs(int $jobId): array
    {
        global $database;
        $pdo = $database->connection;

        $sql = "SELECT id, job_id, doc_code, titolo, tipo_documento, obbligatorieta, 
                       condizione_tipo, condizione_valore, condizione_descrizione, 
                       note, page_ref, section_ref
                FROM ext_req_docs 
                WHERE job_id = :job_id 
                ORDER BY id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'data' => $rows ?: [],
            'count' => count($rows)
        ];
    }

    /**
     * Recupera requisiti economici normalizzati da ext_req_econ per un job
     */
    public static function getNormalizedEcon(int $jobId): array
    {
        global $database;
        $pdo = $database->connection;

        $sql = "SELECT id, job_id, tipo, importo_minimo, valuta, soglia_direzione,
                       best_periods, lookback_anni, tipo_periodo, scope,
                       iva_esclusa, oneri_previd_esclusi, regola_compendiata,
                       ancora_riferimento, rti_regola, categoria_codice, categoria_tipo,
                       categoria_descrizione, window_anni, moltiplicatore,
                       riferimento_valore_categoria, rti_regola_sp
                FROM ext_req_econ 
                WHERE job_id = :job_id 
                ORDER BY tipo ASC, categoria_codice ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Formatta importi per la visualizzazione
        foreach ($rows as &$row) {
            if (isset($row['importo_minimo']) && $row['importo_minimo'] !== null) {
                $row['importo_minimo_formatted'] = number_format((float) $row['importo_minimo'], 2, ',', '.') . ' ' . ($row['valuta'] ?? 'EUR');
            }
        }
        unset($row);

        return [
            'success' => true,
            'data' => $rows ?: [],
            'count' => count($rows)
        ];
    }

    /**
     * Recupera ruoli e requisiti normalizzati da ext_req_roles per un job
     */
    public static function getNormalizedRoles(int $jobId): array
    {
        global $database;
        $pdo = $database->connection;

        $sql = "SELECT id, job_id, role_code, role_name, is_minimum, ordine, 
                       requisiti_json, note
                FROM ext_req_roles 
                WHERE job_id = :job_id 
                ORDER BY ordine ASC, id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':job_id' => $jobId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Decodifica requisiti_json
        foreach ($rows as &$row) {
            if (!empty($row['requisiti_json'])) {
                $decoded = json_decode($row['requisiti_json'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row['requisiti'] = $decoded;
                } else {
                    $row['requisiti'] = [];
                }
            } else {
                $row['requisiti'] = [];
            }
        }
        unset($row);

        return [
            'success' => true,
            'data' => $rows ?: [],
            'count' => count($rows)
        ];
    }

    /**
     * Ritorna risultati normalizzati da ext_extractions / ext_citations / ext_table_cells.
     * Usa i dati normalizzati quando disponibili (ext_req_docs, ext_req_econ, ext_req_roles).
     */
    public static function jobResults(int $jobId): array
    {
        // Usa getEstrazioniGara che già integra i dati normalizzati
        $result = self::getEstrazioniGara($jobId);

        // Adatta il formato di risposta per compatibilità con il frontend
        // getEstrazioniGara ritorna ['success' => true, 'data' => ...]
        // jobResults deve ritornare ['ok' => true, 'data' => ...]
        if (isset($result['success']) && $result['success']) {
            return [
                'ok' => true,
                'data' => $result['data'] ?? [],
            ];
        } else {
            return [
                'ok' => false,
                'error' => $result['message'] ?? 'Errore nel recupero delle estrazioni',
                'data' => [],
            ];
        }
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

    private static function persistJobSkeleton(array $extractionTypes): int
    {
        global $database;
        $pdo = $database->connection;

        $stmt = $pdo->prepare("INSERT INTO ext_jobs (extraction_types) VALUES (:t)");
        $stmt->execute([':t' => json_encode(array_values($extractionTypes), JSON_UNESCAPED_UNICODE)]);
        return (int) $pdo->lastInsertId();
    }

    private static function attachFileToJob(int $jobId, array $meta): void {
        global $database;
        $storage = new StorageManager($database->connection);
        $storage->attachFileToJob($jobId, $meta);
    }

    private static function findFileBySha(string $sha): ?array
    {
        global $database;
        $pdo = $database->connection;

        $sql = "SELECT f.job_id, f.original_name, f.mime_type, f.size_bytes, f.rel_path, f.sha256,
                       j.ext_job_id, j.ext_batch_id, j.status
                FROM ext_job_files f
                LEFT JOIN ext_jobs j ON j.id = f.job_id
                WHERE f.sha256 = :sha
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':sha' => $sha]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function deleteJob(int $jobId): void
    {
        global $database;
        $pdo = $database->connection;

        $pdo->prepare("DELETE FROM ext_job_files WHERE job_id = :id")->execute([':id' => $jobId]);
        $pdo->prepare("DELETE FROM ext_jobs WHERE id = :id")->execute([':id' => $jobId]);
    }

    private static function purgeJob(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }

        global $database;
        $pdo = $database->connection;

        $files = [];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT rel_path FROM ext_job_files WHERE job_id = :id");
            $stmt->execute([':id' => $jobId]);
            $files = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            $stmt = $pdo->prepare("SELECT id FROM ext_extractions WHERE job_id = :id");
            $stmt->execute([':id' => $jobId]);
            $extractionIds = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            if ($extractionIds) {
                $placeholders = implode(',', array_fill(0, count($extractionIds), '?'));
                $pdo->prepare("DELETE FROM ext_citations WHERE extraction_id IN ($placeholders)")->execute($extractionIds);
                $pdo->prepare("DELETE FROM ext_table_cells WHERE extraction_id IN ($placeholders)")->execute($extractionIds);
            }

            $pdo->prepare("DELETE FROM ext_extractions WHERE job_id = :id")->execute([':id' => $jobId]);
            $pdo->prepare("DELETE FROM ext_job_files WHERE job_id = :id")->execute([':id' => $jobId]);
            $pdo->prepare("DELETE FROM ext_jobs WHERE id = :id")->execute([':id' => $jobId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        foreach ($files as $relPath) {
            if ($relPath) {
                self::deleteStoredFile($relPath);
            }
        }
    }

    private static function markExternalIds(int $jobId, ?string $extJobId, ?string $extBatchId): void
    {
        global $database;
        $pdo = $database->connection;

        $stmt = $pdo->prepare("SELECT ext_job_id, ext_batch_id FROM ext_jobs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['ext_job_id' => null, 'ext_batch_id' => null];

        $oldExtJob = $row['ext_job_id'];
        $oldExtBatch = $row['ext_batch_id'];

        $newExtJob = $oldExtJob;
        $newExtBatch = $oldExtBatch;

        if ($extJobId) {
            $newExtJob = $extJobId;
        }
        if ($extBatchId) {
            $newExtBatch = $extBatchId;
        }

        if (!$newExtBatch && $newExtJob && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $newExtJob)) {
            $newExtBatch = $newExtJob;
        }

        if ($newExtJob !== $oldExtJob || $newExtBatch !== $oldExtBatch) {
            $stmt = $pdo->prepare("UPDATE ext_jobs SET ext_job_id = :j, ext_batch_id = :b, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':j' => $newExtJob, ':b' => $newExtBatch, ':id' => $jobId]);
        }
    }

    private static function updateJobStatus(int $jobId, string $status, ?string $error = null, ?array $progress = null): void
    {
        global $database;
        $pdo = $database->connection;

        self::ensureGaraColumns($pdo);

        $sql = "UPDATE ext_jobs SET status=:s, error_message=:e, updated_at=NOW(),
                completed_at = CASE WHEN :s IN('completed','failed','error') THEN NOW() ELSE completed_at END";
        $params = [':s' => $status, ':e' => $error, ':id' => $jobId];

        if ($progress && isset($progress['done'], $progress['total'])) {
            $sql .= ", progress_done = :pd, progress_total = :pt";
            $params[':pd'] = (int) $progress['done'];
            $params[':pt'] = max(1, (int) $progress['total']);
        }

        $sql .= " WHERE id=:id";
        $pdo->prepare($sql)->execute($params);
    }

    private static function fetchJob(int $jobId): ?array
    {
        global $database;
        $pdo = $database->connection;

        // Seleziona esplicitamente i campi necessari per evitare problemi
        $stmt = $pdo->prepare("SELECT id, status, ext_job_id, ext_batch_id, status_id, error_message, created_at, updated_at, completed_at FROM ext_jobs WHERE id=:id");
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $job ?: null;
    }

    private static function upsertExtractions(int $jobId, array $answers): void
    {
        global $database;
        $pdo = $database->connection;

        try {
            $pdo->beginTransaction();

            $existingStmt = $pdo->prepare("SELECT id FROM ext_extractions WHERE job_id = :job");
            $existingStmt->execute([':job' => $jobId]);
            $existingIds = $existingStmt->fetchAll(\PDO::FETCH_COLUMN);

            if (!empty($existingIds)) {
                $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
                $pdo->prepare("DELETE FROM ext_table_cells WHERE extraction_id IN ($placeholders)")->execute($existingIds);
                $pdo->prepare("DELETE FROM ext_citations WHERE extraction_id IN ($placeholders)")->execute($existingIds);
                $pdo->prepare("DELETE FROM ext_extractions WHERE id IN ($placeholders)")->execute($existingIds);
            }

            foreach ($answers as $answer) {
                $extractionId = self::saveExtraction($pdo, $jobId, $answer);

                if (!empty($answer['citations']) && is_array($answer['citations'])) {
                    self::saveCitations($pdo, $extractionId, $answer['citations']);
                }

                $valueJson = $answer['value_json'] ?? null;
                if (is_string($valueJson)) {
                    $valueJson = json_decode($valueJson, true);
                }

                $typeCode = $answer['type_code'] ?? 'unknown';

                // Gestione tipi tabellari specifici: entries[] o requirements[]
                $tableProcessed = false;
                if (is_array($valueJson)) {
                    // importi_opere_per_categoria_id_opere: usa entries[]
                    if ($typeCode === 'importi_opere_per_categoria_id_opere' && !empty($valueJson['entries']) && is_array($valueJson['entries'])) {
                        $headers = ['id_opera', 'category_name', 'description', 'amount_eur', 'service_phase', 'page'];
                        $rows = [];
                        foreach ($valueJson['entries'] as $entry) {
                            if (!is_array($entry)) {
                                continue;
                            }
                            $rows[] = [
                                $entry['id_opera'] ?? $entry['id_opera_normalized'] ?? '',
                                $entry['category_name'] ?? '',
                                $entry['description'] ?? (is_array($entry['work_types'] ?? null) ? implode(', ', $entry['work_types']) : ''),
                                isset($entry['amount_eur']) ? number_format((float) $entry['amount_eur'], 2, ',', '.') : '',
                                $entry['service_phase'] ?? '',
                                $entry['page'] ?? $entry['source_page'] ?? ''
                            ];
                        }
                        if (!empty($rows)) {
                            self::saveTableCells($pdo, $extractionId, $headers, $rows);
                            $tableProcessed = true;
                        }
                    }
                    // importi_requisiti_tecnici_categoria_id_opere: usa requirements[] con lookup gar_opere_dm50
                    elseif ($typeCode === 'importi_requisiti_tecnici_categoria_id_opere' && !empty($valueJson['requirements']) && is_array($valueJson['requirements'])) {
                        // Raccogli tutti gli id_opera per fare il lookup in gar_opere_dm50
                        $idOpereArray = [];
                        foreach ($valueJson['requirements'] as $req) {
                            if (!is_array($req)) {
                                continue;
                            }
                            $idOpera = $req['id_opera'] ?? null;
                            if ($idOpera !== null && $idOpera !== '') {
                                $idOpereArray[] = (string) $idOpera;
                            }
                        }

                        // Recupera dati da gar_opere_dm50
                        $opereDm50Data = self::getOpereDm50Data($idOpereArray);

                        // Headers corretti per la visualizzazione
                        $headers = ['ID Opera', 'Categoria', 'Descrizione', 'Importo minimo'];
                        $rows = [];
                        foreach ($valueJson['requirements'] as $req) {
                            if (!is_array($req)) {
                                continue;
                            }

                            $idOpera = $req['id_opera'] ?? '';
                            $idOperaStr = (string) $idOpera;

                            // Recupera categoria e descrizione da gar_opere_dm50
                            $categoria = '';
                            $descrizione = '';
                            if ($idOperaStr !== '' && isset($opereDm50Data[$idOperaStr])) {
                                $categoria = $opereDm50Data[$idOperaStr]['categoria'] ?? '';
                                $descrizione = $opereDm50Data[$idOperaStr]['identificazione_opera'] ?? '';
                            }

                            // Se non trovato in gar_opere_dm50, usa i valori dal JSON come fallback
                            if ($categoria === '' && isset($req['category_name']) && $req['category_name'] !== '') {
                                $categoria = $req['category_name'];
                            }
                            if ($descrizione === '' && isset($req['description']) && $req['description'] !== '') {
                                $descrizione = $req['description'];
                            }

                            // Importo minimo: usa minimum_amount_eur
                            $minimumAmountEur = $req['minimum_amount_eur'] ?? null;
                            $amountFormatted = '';
                            if ($minimumAmountEur !== null && is_numeric($minimumAmountEur)) {
                                $amountFormatted = number_format((float) $minimumAmountEur, 2, ',', '.') . ' €';
                            }

                            $rows[] = [
                                $idOperaStr !== '' ? $idOperaStr : '—',
                                $categoria !== '' ? $categoria : '—',
                                $descrizione !== '' ? $descrizione : '—',
                                $amountFormatted !== '' ? $amountFormatted : '—'
                            ];
                        }
                        if (!empty($rows)) {
                            self::saveTableCells($pdo, $extractionId, $headers, $rows);
                            $tableProcessed = true;
                        }
                    }
                    // importi_corrispettivi_categoria_id_opere: usa entries[] (se presente)
                    elseif ($typeCode === 'importi_corrispettivi_categoria_id_opere' && !empty($valueJson['entries']) && is_array($valueJson['entries'])) {
                        // Raccogli tutti gli id_opera per fare il lookup in gar_opere_dm50
                        // Nuova struttura: category_id (con fallback per retrocompatibilità)
                        $idOpereArray = [];
                        foreach ($valueJson['entries'] as $entry) {
                            if (!is_array($entry)) {
                                continue;
                            }
                            // Nuova struttura: category_id, fallback a id_opera* per retrocompatibilità
                            $idOpera = $entry['category_id'] ?? $entry['id_opera'] ?? $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? null;
                            if ($idOpera !== null && $idOpera !== '') {
                                $idOpereArray[] = (string) $idOpera;
                            }
                        }

                        // Recupera dati da gar_opere_dm50
                        $opereDm50Data = self::getOpereDm50Data($idOpereArray);

                        // Headers corretti per la visualizzazione
                        $headers = ['ID Opere', 'Categorie', 'Descrizione', 'Grado di complessità', 'Importo del corrispettivo'];
                        $rows = [];
                        foreach ($valueJson['entries'] as $entry) {
                            if (!is_array($entry)) {
                                continue;
                            }

                            // Nuova struttura: category_id, fallback a id_opera* per retrocompatibilità
                            $idOpera = $entry['category_id'] ?? $entry['id_opera'] ?? $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? '';
                            $idOperaStr = (string) $idOpera;

                            // Recupera categoria, descrizione e complessita da gar_opere_dm50
                            $categoria = '';
                            $descrizione = '';
                            $complessita = '';
                            if ($idOperaStr !== '' && isset($opereDm50Data[$idOperaStr])) {
                                $categoria = $opereDm50Data[$idOperaStr]['categoria'] ?? '';
                                $descrizione = $opereDm50Data[$idOperaStr]['identificazione_opera'] ?? '';
                                $complessita = isset($opereDm50Data[$idOperaStr]['complessita']) ? (string) $opereDm50Data[$idOperaStr]['complessita'] : '';
                            }

                            // Nuova struttura: amount_eur, fallback a fee_amount per retrocompatibilità
                            $amountEur = $entry['amount_eur'] ?? $entry['fee_amount'] ?? null;
                            $amountFormatted = '';
                            if ($amountEur !== null && is_numeric($amountEur)) {
                                $amountFormatted = number_format((float) $amountEur, 2, ',', '.') . ' €';
                            }

                            $rows[] = [
                                $idOperaStr !== '' ? $idOperaStr : '—',
                                $categoria !== '' ? $categoria : '—',
                                $descrizione !== '' ? $descrizione : '—',
                                $complessita !== '' ? $complessita : '—',
                                $amountFormatted !== '' ? $amountFormatted : '—'
                            ];
                        }
                        if (!empty($rows)) {
                            self::saveTableCells($pdo, $extractionId, $headers, $rows);
                            $tableProcessed = true;
                        }
                    }
                }

                // Gestione tabelle generiche con headers/rows (se non già processata)
                if (!$tableProcessed) {
                    $headers = null;
                    $rows = null;

                    if (is_array($valueJson)) {
                        // Prima controlla direttamente in valueJson
                        if (isset($valueJson['headers']) && is_array($valueJson['headers'])) {
                            // Struttura diretta: valueJson contiene headers (rows può essere vuoto o non esistere)
                            $headers = $valueJson['headers'];
                            // Usa array_key_exists per verificare se la chiave esiste, anche se è null
                            if (array_key_exists('rows', $valueJson)) {
                                $rows = is_array($valueJson['rows']) ? $valueJson['rows'] : [];
                            } else {
                                $rows = [];
                            }
                        } elseif (isset($valueJson['answer'])) {
                            if (is_array($valueJson['answer']) && isset($valueJson['answer']['headers']) && is_array($valueJson['answer']['headers'])) {
                                // Nuova struttura API: headers e rows sono dentro answer
                                $headers = $valueJson['answer']['headers'];
                                if (array_key_exists('rows', $valueJson['answer'])) {
                                    $rows = is_array($valueJson['answer']['rows']) ? $valueJson['answer']['rows'] : [];
                                } else {
                                    $rows = [];
                                }
                            } elseif (is_string($valueJson['answer']) && $valueJson['answer'] === '' && isset($valueJson['headers']) && is_array($valueJson['headers'])) {
                                // answer è vuoto ma headers esiste nello stesso livello
                                $headers = $valueJson['headers'];
                                if (array_key_exists('rows', $valueJson)) {
                                    $rows = is_array($valueJson['rows']) ? $valueJson['rows'] : [];
                                } else {
                                    $rows = [];
                                }
                            }
                        }
                    }

                    // Salva anche tabelle vuote (solo headers, nessuna riga)
                    if ($headers !== null && is_array($headers) && count($headers) > 0) {
                        if (!is_array($rows)) {
                            $rows = [];
                        }
                        self::saveTableCells($pdo, $extractionId, $headers, $rows);
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Estrae una risposta "pulita" da un array di dati AI.
     * 
     * Regole:
     * - Accetta solo campi esplicitamente di risposta: answer, value, text, result, response, url
     * - NON accetta mai: chain_of_thought, empty_reason, reason, explanation, raw_block, citations, debug, logs
     * - Se non trova una risposta pulita, restituisce NULL
     * 
     * @param array|mixed $data Dati dalla risposta AI
     * @return string|null Risposta pulita o NULL se non trovata
     */
    private static function extractCleanAnswer($data): ?string
    {
        if (!is_array($data)) {
            // Se è uno scalare (stringa, numero, bool), accettalo solo se è una stringa breve e sensata
            if (is_string($data)) {
                $trimmed = trim($data);
                // Rifiuta JSON grezzo, testi troppo lunghi (probabilmente reasoning), o contenuti sospetti
                if ($trimmed !== '' && 
                    strlen($trimmed) < 500 && 
                    !preg_match('/^\s*[\{\[]/', $trimmed) && 
                    stripos($trimmed, 'chain_of_thought') === false &&
                    stripos($trimmed, 'reasoning') === false) {
                    return $trimmed;
                }
            } elseif (is_scalar($data)) {
                return (string) $data;
            }
            return null;
        }

        // Campi accettabili come risposta (in ordine di priorità)
        $acceptableFields = ['answer', 'value', 'text', 'result', 'response', 'url'];
        
        foreach ($acceptableFields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];
                
                // Se è una stringa, verifica che non sia JSON grezzo o reasoning
                if (is_string($value)) {
                    $trimmed = trim($value);
                    if ($trimmed !== '' && 
                        strlen($trimmed) < 500 && 
                        !preg_match('/^\s*[\{\[]/', $trimmed) &&
                        stripos($trimmed, 'chain_of_thought') === false &&
                        stripos($trimmed, 'reasoning') === false) {
                        return $trimmed;
                    }
                } elseif (is_scalar($value)) {
                    return (string) $value;
                }
            }
        }

        // Se c'è una location, formattala
        if (isset($data['location']) && is_array($data['location'])) {
            $formatted = \Services\AIextraction\ExtractionFormatter::formatLocationValue($data['location']);
            if ($formatted !== null && $formatted !== '') {
                return $formatted;
            }
        }

        // Se c'è una data, formattala
        if (isset($data['date']) && is_array($data['date'])) {
            $date = $data['date'];
            $year = $date['year'] ?? null;
            $month = $date['month'] ?? null;
            $day = $date['day'] ?? null;
            if ($year && $month && $day) {
                $hour = $date['hour'] ?? null;
                $minute = $date['minute'] ?? null;
                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                if ($hour !== null && $minute !== null) {
                    $dateStr .= sprintf(' %02d:%02d:00', $hour, $minute);
                }
                return $dateStr;
            }
        }

        // Niente risposta pulita trovata
        return null;
    }

    /**
     * Rimuove campi di debug dal value_json prima di salvare
     */
    private static function cleanValueJson($valueJson): ?array
    {
        if (!is_array($valueJson)) {
            return $valueJson;
        }

        // Rimuovi campi di debug/metadati che non devono essere salvati
        $debugFields = [
            'chain_of_thought', 'chainOfThought', 'reasoning', 
            'processing_time', 'error', 'error_details',
            'empty_reason', 'reason', 'message', 'note', 'explanation',
            'raw_block', 'raw_text', 'debug', 'logs'
        ];
        foreach ($debugFields as $field) {
            unset($valueJson[$field]);
        }

        return $valueJson;
    }

    private static function saveExtraction(\PDO $pdo, int $jobId, array $rec): int
    {
        // Pulisci value_json rimuovendo campi di debug
        $cleanValueJson = null;
        if (isset($rec['value_json'])) {
            $cleanValueJson = self::cleanValueJson($rec['value_json']);
        }

        $stmt = $pdo->prepare("INSERT INTO ext_extractions (job_id,type_code,value_text,value_json,confidence)
                               VALUES (:j,:t,:vt,:vj,:c)");
        $stmt->execute([
            ':j' => $jobId,
            ':t' => $rec['type_code'],
            ':vt' => $rec['value_text'] ?? null,
            ':vj' => $cleanValueJson !== null ? json_encode($cleanValueJson, JSON_UNESCAPED_UNICODE) : null,
            ':c' => $rec['confidence'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private static function saveCitations(\PDO $pdo, int $extractionId, array $citations): void {
        $storage = new StorageManager($pdo);
        $storage->saveCitations($extractionId, $citations);
    }

    private static function saveTableCells(\PDO $pdo, int $extractionId, array $headers, array $rows): void {
        $storage = new StorageManager($pdo);
        $storage->saveTableCells($extractionId, $headers, $rows);
    }

    /**
     * Array statico per accumulare i log di debug
     */
    private static $debugLogs = [];

    /**
     * Aggiunge un log al buffer
     */
    private static function addDebugLog(string $message): void
    {
        self::$debugLogs[] = date('Y-m-d H:i:s') . ' - ' . $message;
        error_log($message); // Mantieni anche error_log per retrocompatibilità
    }

    /**
     * Restituisce e resetta i log di debug
     */
    public static function getDebugLogs(): array
    {
        $logs = self::$debugLogs;
        self::$debugLogs = [];
        return $logs;
    }

    /**
     * Processa le estrazioni e popola le tabelle normalizzate (ext_req_docs, ext_req_econ, ext_req_roles)
     * 
     * NOTA: Richiede le seguenti chiavi uniche nel database:
     * - ext_req_docs: UNIQUE KEY (job_id, doc_code)
     * - ext_req_econ: UNIQUE KEY (job_id, tipo) per FATTURATO_GLOBALE
     *                  UNIQUE KEY (job_id, tipo, categoria_codice) per SERVIZI_PUNTA
     * - ext_req_roles: UNIQUE KEY (job_id, role_code)
     */
    private static function processNormalizedRequirements(int $jobId): void
    {
        global $database;
        $normalizer = new \Services\AIextraction\ExtractionNormalizer($database->connection);
        $normalizer->processAll($jobId);
        
        // Recupera debug logs se serve
        $logs = $normalizer->getDebugLogs();
        foreach ($logs as $log) {
            error_log($log);
        }
    }

    /**
     * Processa documentazione_richiesta_tecnica e popola ext_req_docs
     */
    private static function processDocumentazioneRichiesta(\PDO $pdo, int $jobId, array $extractions): void
    {
        self::addDebugLog("Job {$jobId}: processDocumentazioneRichiesta - " . count($extractions) . " estrazioni");

        foreach ($extractions as $ext) {
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson)) {
                self::addDebugLog("Job {$jobId}: value_json non è un array. Tipo: " . gettype($valueJson) . ", valore: " . substr(var_export($valueJson, true), 0, 200));
                continue;
            }

            // Debug: mostra le chiavi principali di value_json
            $keys = array_keys($valueJson);
            self::addDebugLog("Job {$jobId}: value_json chiavi: " . implode(', ', $keys));

            // Cerca documents array - prima direttamente in value_json
            $documents = $valueJson['documents'] ?? null;
            self::addDebugLog("Job {$jobId}: documents da value_json - tipo: " . gettype($documents) . ", è array: " . (is_array($documents) ? 'sì' : 'no') . ", count: " . (is_array($documents) ? count($documents) : 'N/A'));

            if (!is_array($documents) || empty($documents)) {
                // Prova anche answer.documents
                $answer = self::smartDecode($valueJson['answer'] ?? null, null);
                if (is_array($answer)) {
                    $documents = $answer['documents'] ?? null;
                    self::addDebugLog("Job {$jobId}: documents da answer - tipo: " . gettype($documents) . ", è array: " . (is_array($documents) ? 'sì' : 'no') . ", count: " . (is_array($documents) ? count($documents) : 'N/A'));
                }
            }

            if (!is_array($documents) || empty($documents)) {
                self::addDebugLog("Job {$jobId}: documents non trovato o vuoto. Tipo documents: " . gettype($documents) . ", answer type: " . (isset($valueJson['answer']) ? gettype($valueJson['answer']) : 'N/A'));
                // Debug: mostra un sample del contenuto
                if (isset($valueJson['answer'])) {
                    $answerSample = is_string($valueJson['answer']) ? substr($valueJson['answer'], 0, 200) : var_export($valueJson['answer'], true);
                    self::addDebugLog("Job {$jobId}: answer sample: " . substr($answerSample, 0, 200));
                }
                // Debug: mostra anche documents_text se presente
                if (isset($valueJson['documents_text'])) {
                    self::addDebugLog("Job {$jobId}: documents_text presente: " . substr($valueJson['documents_text'], 0, 200));
                }
                continue;
            }

            self::addDebugLog("Job {$jobId}: Trovati " . count($documents) . " documenti");

            foreach ($documents as $docIdx => $doc) {
                if (!is_array($doc)) {
                    continue;
                }

                $title = $doc['title'] ?? '';
                if (empty($title)) {
                    self::addDebugLog("Job {$jobId}: Documento #{$docIdx} senza titolo, salto");
                    continue;
                }

                $description = $doc['description'] ?? '';
                $notes = $doc['notes'] ?? '';
                $conditionalLogic = $doc['conditional_logic'] ?? [];
                $condDesc = is_array($conditionalLogic) ? ($conditionalLogic['description'] ?? '') : '';

                // Crea un testo completo per la ricerca
                $fullText = $title . ' ' . $description . ' ' . $notes . ' ' . $condDesc;

                self::addDebugLog("Job {$jobId}: Processo documento #{$docIdx}: " . substr($title, 0, 80));

                // Determina se è il documento sui segreti tecnici
                $isSegretiTecnici = (
                    stripos($fullText, 'segreti tecnici') !== false ||
                    stripos($fullText, 'segreti commerciali') !== false ||
                    stripos($fullText, 'non ostensibilità') !== false ||
                    stripos($fullText, 'ostensibilità') !== false ||
                    stripos($fullText, 'riservatezza') !== false ||
                    stripos($fullText, 'secretare') !== false ||
                    stripos($fullText, 'riservato') !== false ||
                    stripos($fullText, 'confidenziale') !== false ||
                    (stripos($fullText, 'segreti') !== false && stripos($fullText, 'giustificare') !== false) ||
                    (stripos($fullText, 'segreti') !== false && stripos($fullText, 'ostensibilità') !== false) ||
                    (stripos($fullText, 'riservatezza') !== false && stripos($fullText, 'offerta') !== false) ||
                    (stripos($fullText, 'dettagli') !== false && stripos($fullText, 'riservatezza') !== false) ||
                    (stripos($fullText, 'coperti') !== false && stripos($fullText, 'riservatezza') !== false)
                );

                // Genera doc_code: se è segreti tecnici usa 'SEGRETI_TECNICI', altrimenti genera da titolo
                $docCode = $isSegretiTecnici ? 'SEGRETI_TECNICI' : self::generateDocCode($title);

                $requirementStatus = $doc['requirement_status'] ?? 'obbligatorio';
                $sourceLocation = $doc['source_location'] ?? [];
                $docNotes = $doc['notes'] ?? null;

                $condizioneTipo = 'none';
                $condizioneValore = null;
                $condizioneDescrizione = null;

                if (is_array($conditionalLogic)) {
                    $rawCondizioneTipo = $conditionalLogic['condition_type'] ?? 'none';
                    // Valida che sia uno dei valori permessi dall'ENUM: 'none', 'rti', 'other'
                    $allowedCondizioneTipi = ['none', 'rti', 'other'];
                    if (in_array($rawCondizioneTipo, $allowedCondizioneTipi, true)) {
                        $condizioneTipo = $rawCondizioneTipo;
                    } else {
                        // Se non è valido, mappa a 'other' se sembra una condizione custom, altrimenti 'none'
                        if (!empty($rawCondizioneTipo) && $rawCondizioneTipo !== 'none') {
                            $condizioneTipo = 'other';
                            $condizioneDescrizione = $rawCondizioneTipo; // Salva il valore originale nella descrizione
                        } else {
                            $condizioneTipo = 'none';
                        }
                    }

                    if ($condizioneTipo === 'other') {
                        $condizioneValore = $conditionalLogic['condition_value'] ?? null;
                        if (empty($condizioneDescrizione)) {
                            $condizioneDescrizione = $conditionalLogic['description'] ?? null;
                        }
                    }
                }

                $pageRef = null;
                $sectionRef = null;
                if (is_array($sourceLocation)) {
                    $pageRef = $sourceLocation['page'] ?? null;
                    $sectionRef = $sourceLocation['section'] ?? null;
                }

                // Traduci obbligatorietà
                $obbligatorieta = 'obbligatorio';
                if ($requirementStatus === 'conditional' || $requirementStatus === 'condizionale') {
                    $obbligatorieta = 'condizionale';
                } elseif ($requirementStatus === 'optional' || $requirementStatus === 'facoltativo') {
                    $obbligatorieta = 'facoltativo';
                }

                // Determina tipo_documento (campo VARCHAR libero, suggeriamo valori comuni)
                $tipoDocumento = 'altro'; // Default
                $titleLower = strtolower($title);

                // Ordine di priorità: controlla prima le corrispondenze più specifiche
                if (stripos($titleLower, 'relazione') !== false) {
                    $tipoDocumento = 'relazione';
                } elseif (
                    stripos($titleLower, 'modello') !== false ||
                    stripos($titleLower, 'template') !== false ||
                    stripos($titleLower, 'schema') !== false
                ) {
                    $tipoDocumento = 'modello';
                } elseif (
                    stripos($titleLower, 'offerta tecnica') !== false ||
                    (stripos($titleLower, 'offerta') !== false && stripos($titleLower, 'tecnica') !== false)
                ) {
                    $tipoDocumento = 'offerta_tecnica';
                } elseif (stripos($titleLower, 'dichiarazione') !== false) {
                    $tipoDocumento = 'dichiarazione';
                } elseif (stripos($titleLower, 'allegat') !== false) {
                    $tipoDocumento = 'allegato';
                } elseif (stripos($titleLower, 'progetto') !== false) {
                    $tipoDocumento = 'progetto';
                } elseif (stripos($titleLower, 'elaborato') !== false) {
                    $tipoDocumento = 'elaborato';
                }
                // Altrimenti resta 'altro' (default)

                // Limita la lunghezza a 50 caratteri (come definito nel VARCHAR)
                if (strlen($tipoDocumento) > 50) {
                    $tipoDocumento = substr($tipoDocumento, 0, 50);
                }

                // Pulisci e traduce il testo in italiano
                $condizioneDescrizione = self::cleanItalianText($condizioneDescrizione);
                $docNotes = $docNotes ? self::cleanItalianText($docNotes) : null;

                // Verifica se esiste già (usa doc_code come chiave unica insieme a job_id)
                $checkStmt = $pdo->prepare("SELECT id FROM ext_req_docs WHERE job_id = :job_id AND doc_code = :doc_code");
                $checkStmt->execute([':job_id' => $jobId, ':doc_code' => $docCode]);
                $existing = $checkStmt->fetch();

                if ($existing) {
                    self::addDebugLog("Job {$jobId}: UPDATE ext_req_docs per {$docCode} (id: {$existing['id']})");
                    $stmt = $pdo->prepare("
                        UPDATE ext_req_docs SET
                        titolo = :titolo,
                        tipo_documento = :tipo_documento,
                        obbligatorieta = :obbligatorieta,
                        condizione_tipo = :condizione_tipo,
                        condizione_valore = :condizione_valore,
                        condizione_descrizione = :condizione_descrizione,
                        note = :note,
                        page_ref = :page_ref,
                        section_ref = :section_ref
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $existing['id'],
                        ':titolo' => self::cleanItalianText($title),
                        ':tipo_documento' => $tipoDocumento,
                        ':obbligatorieta' => $obbligatorieta,
                        ':condizione_tipo' => $condizioneTipo,
                        ':condizione_valore' => $condizioneValore,
                        ':condizione_descrizione' => $condizioneDescrizione,
                        ':note' => $docNotes,
                        ':page_ref' => $pageRef,
                        ':section_ref' => $sectionRef,
                    ]);
                } else {
                    self::addDebugLog("Job {$jobId}: INSERT ext_req_docs per {$docCode}: " . substr($title, 0, 50));
                    $stmt = $pdo->prepare("
                        INSERT INTO ext_req_docs 
                        (job_id, doc_code, titolo, tipo_documento, obbligatorieta, condizione_tipo, condizione_valore, condizione_descrizione, note, page_ref, section_ref)
                        VALUES 
                        (:job_id, :doc_code, :titolo, :tipo_documento, :obbligatorieta, :condizione_tipo, :condizione_valore, :condizione_descrizione, :note, :page_ref, :section_ref)
                    ");
                    $stmt->execute([
                        ':job_id' => $jobId,
                        ':doc_code' => $docCode,
                        ':titolo' => self::cleanItalianText($title),
                        ':tipo_documento' => $tipoDocumento,
                        ':obbligatorieta' => $obbligatorieta,
                        ':condizione_tipo' => $condizioneTipo,
                        ':condizione_valore' => $condizioneValore,
                        ':condizione_descrizione' => $condizioneDescrizione,
                        ':note' => $docNotes,
                        ':page_ref' => $pageRef,
                        ':section_ref' => $sectionRef,
                    ]);
                }
                self::addDebugLog("Job {$jobId}: Record ext_req_docs salvato con successo per {$docCode}");
            }
        }
    }

    /**
     * Processa fatturato_globale_n_minimo_anni e popola ext_req_econ
     */
    private static function processFatturatoGlobale(\PDO $pdo, int $jobId, array $extractions): void
    {
        self::addDebugLog("Job {$jobId}: processFatturatoGlobale - " . count($extractions) . " estrazioni");

        foreach ($extractions as $ext) {
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson)) {
                self::addDebugLog("Job {$jobId}: value_json non è un array in processFatturatoGlobale");
                continue;
            }

            // Estrai dati dal JSON - supporta diverse strutture
            $answer = self::smartDecode($valueJson['answer'] ?? $valueJson, $valueJson);

            if (!is_array($answer)) {
                self::addDebugLog("Job {$jobId}: answer non è un array dopo decode. Tipo: " . gettype($answer) . ", sample: " . substr(var_export($answer, true), 0, 200));
                continue;
            }

            // Debug: mostra le chiavi principali
            $answerKeys = array_keys($answer);
            self::addDebugLog("Job {$jobId}: answer chiavi in processFatturatoGlobale: " . implode(', ', $answerKeys));

            // Prova struttura turnover_requirement (nuova struttura API)
            $turnoverReq = $answer['turnover_requirement'] ?? null;
            if (is_array($turnoverReq) && isset($turnoverReq['single_requirement'])) {
                self::addDebugLog("Job {$jobId}: Trovata struttura turnover_requirement");
                $singleReq = $turnoverReq['single_requirement'];
                // Cerca importo in vari campi possibili
                $importoMinimo = $singleReq['normalized_value'] ??
                    $singleReq['minimum_amount'] ??
                    $singleReq['raw_value'] ??
                    $singleReq['value'] ??
                    $singleReq['amount'] ??
                    null;

                // Se è ancora null, prova a cercare in raw o text
                if ($importoMinimo === null) {
                    $raw = $singleReq['raw'] ?? $singleReq['text'] ?? null;
                    if (is_string($raw) && $raw !== '') {
                        $importoMinimo = $raw;
                    }
                }

                // Se normalized_value è già un numero, usalo direttamente
                if (is_numeric($importoMinimo)) {
                    $importoMinimo = (float) $importoMinimo;
                }

                self::addDebugLog("Job {$jobId}: Importo estratto da turnover_requirement: " . var_export($importoMinimo, true) . " (tipo: " . gettype($importoMinimo) . ")");

                $valuta = $singleReq['currency'] ?? 'EUR';
                $temporal = $singleReq['temporal_calculation'] ?? [];
                $bestPeriods = $temporal['periods_to_select'] ?? 3;
                $lookbackAnni = $temporal['lookback_window_years'] ?? 5;
                $tipoPeriodo = ($temporal['period_type'] ?? 'generic_years') === 'calendar_years' ? 'calendar_years' : 'fiscal_years';
                $taxExclusion = $singleReq['tax_exclusion_scope'] ?? '';
                $ivaEsclusa = (stripos($taxExclusion, 'vat') !== false || stripos($taxExclusion, 'iva') !== false || stripos($taxExclusion, 'all_fiscal') !== false) ? 1 : 1;
                $oneriPrevidEsclusi = (stripos($taxExclusion, 'pension') !== false || stripos($taxExclusion, 'previdenziali') !== false || stripos($taxExclusion, 'all_fiscal') !== false) ? 1 : 1;
                $rtiRegola = null;
                $ancoraRiferimento = $temporal['anchor_date_reference'] ?? null;
                self::addDebugLog("Job {$jobId}: Da turnover_requirement - importo: " . var_export($importoMinimo, true) . ", best_periods: {$bestPeriods}, lookback: {$lookbackAnni}");
            } else {
                self::addDebugLog("Job {$jobId}: Nessuna struttura turnover_requirement, uso struttura semplice");
                // Struttura semplice (vecchia struttura)
                $importoMinimo = $answer['minimum_amount'] ?? $answer['importo_minimo'] ?? null;
                $valuta = $answer['currency'] ?? $answer['valuta'] ?? 'EUR';
                $bestPeriods = $answer['best_periods'] ?? $answer['numero_esercizi_migliori'] ?? $answer['n_esercizi'] ?? 3;
                $lookbackAnni = $answer['lookback_years'] ?? $answer['finestra_temporale'] ?? $answer['anni'] ?? 5;
                $tipoPeriodo = $answer['period_type'] ?? $answer['tipo_periodo'] ?? 'fiscal_years';
                $ivaEsclusa = isset($answer['vat_excluded']) ? (int) $answer['vat_excluded'] : (isset($answer['iva_esclusa']) ? (int) $answer['iva_esclusa'] : 1);
                $oneriPrevidEsclusi = isset($answer['social_charges_excluded']) ? (int) $answer['social_charges_excluded'] : (isset($answer['oneri_previd_esclusi']) ? (int) $answer['oneri_previd_esclusi'] : 1);
                $rtiRegola = $answer['rti_rule'] ?? $answer['regola_rti'] ?? null;
                $ancoraRiferimento = $answer['reference_anchor'] ?? $answer['ancora_riferimento'] ?? null;
                self::addDebugLog("Job {$jobId}: Da struttura semplice - importo: " . var_export($importoMinimo, true) . ", best_periods: {$bestPeriods}, lookback: {$lookbackAnni}");
            }

            // Pulisci importo minimo se è una stringa con simboli
            if (is_string($importoMinimo)) {
                // Rimuovi simboli di valuta e spazi
                $importoMinimo = preg_replace('/[^\d.,]/', '', $importoMinimo);
                // Se ha virgola, assume formato italiano (1.500.000,00)
                if (strpos($importoMinimo, ',') !== false) {
                    $importoMinimo = str_replace('.', '', $importoMinimo); // Rimuovi separatori migliaia
                    $importoMinimo = str_replace(',', '.', $importoMinimo); // Virgola diventa punto decimale
                } else {
                    // Formato inglese o senza decimali
                    $importoMinimo = str_replace(',', '', $importoMinimo); // Rimuovi virgole
                }
                $importoMinimo = $importoMinimo !== '' ? (float) $importoMinimo : null;
            }

            if ($importoMinimo === null || $importoMinimo <= 0) {
                self::addDebugLog("Job {$jobId}: importoMinimo non valido: " . var_export($importoMinimo, true));
                continue;
            }

            self::addDebugLog("Job {$jobId}: Dati fatturato globale - importo: {$importoMinimo}, best_periods: {$bestPeriods}, lookback: {$lookbackAnni}");

            // Costruisci regola compendiata in italiano
            $regolaCompendiata = sprintf(
                "Fatturato globale minimo maturato nei migliori %d esercizi degli ultimi %d antecedenti alla data di pubblicazione del bando.",
                (int) $bestPeriods,
                (int) $lookbackAnni
            );

            // Pulisci testi in italiano
            $rtiRegola = $rtiRegola ? self::cleanItalianText($rtiRegola) : "Il requisito relativo al fatturato globale deve essere soddisfatto dal raggruppamento nel suo complesso.";
            $ancoraRiferimento = $ancoraRiferimento ? self::cleanItalianText($ancoraRiferimento) : null;

            // Normalizza tipo periodo
            if ($tipoPeriodo !== 'fiscal_years' && $tipoPeriodo !== 'calendar_years') {
                $tipoPeriodo = 'fiscal_years';
            }

            // Verifica se esiste già
            $checkStmt = $pdo->prepare("SELECT id FROM ext_req_econ WHERE job_id = :job_id AND tipo = 'FATTURATO_GLOBALE'");
            $checkStmt->execute([':job_id' => $jobId]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                self::addDebugLog("Job {$jobId}: UPDATE ext_req_econ per FATTURATO_GLOBALE (id: {$existing['id']})");
                $stmt = $pdo->prepare("
                    UPDATE ext_req_econ SET
                    importo_minimo = :importo_minimo,
                    valuta = :valuta,
                    soglia_direzione = 'almeno',
                    best_periods = :best_periods,
                    lookback_anni = :lookback_anni,
                    tipo_periodo = :tipo_periodo,
                    scope = 'truly_global',
                    iva_esclusa = :iva_esclusa,
                    oneri_previd_esclusi = :oneri_previd_esclusi,
                    regola_compendiata = :regola_compendiata,
                    ancora_riferimento = :ancora_riferimento,
                    rti_regola = :rti_regola
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $existing['id'],
                    ':importo_minimo' => (float) $importoMinimo,
                    ':valuta' => strtoupper(substr($valuta, 0, 3)),
                    ':best_periods' => (int) $bestPeriods,
                    ':lookback_anni' => (int) $lookbackAnni,
                    ':tipo_periodo' => $tipoPeriodo,
                    ':iva_esclusa' => $ivaEsclusa,
                    ':oneri_previd_esclusi' => $oneriPrevidEsclusi,
                    ':regola_compendiata' => $regolaCompendiata,
                    ':ancora_riferimento' => $ancoraRiferimento,
                    ':rti_regola' => $rtiRegola,
                ]);
            } else {
                self::addDebugLog("Job {$jobId}: INSERT ext_req_econ per FATTURATO_GLOBALE");
                $stmt = $pdo->prepare("
                    INSERT INTO ext_req_econ 
                    (job_id, tipo, importo_minimo, valuta, soglia_direzione, best_periods, lookback_anni, tipo_periodo, scope, iva_esclusa, oneri_previd_esclusi, regola_compendiata, ancora_riferimento, rti_regola)
                    VALUES 
                    (:job_id, 'FATTURATO_GLOBALE', :importo_minimo, :valuta, 'almeno', :best_periods, :lookback_anni, :tipo_periodo, 'truly_global', :iva_esclusa, :oneri_previd_esclusi, :regola_compendiata, :ancora_riferimento, :rti_regola)
                ");
                $stmt->execute([
                    ':job_id' => $jobId,
                    ':importo_minimo' => (float) $importoMinimo,
                    ':valuta' => strtoupper(substr($valuta, 0, 3)),
                    ':best_periods' => (int) $bestPeriods,
                    ':lookback_anni' => (int) $lookbackAnni,
                    ':tipo_periodo' => $tipoPeriodo,
                    ':iva_esclusa' => $ivaEsclusa,
                    ':oneri_previd_esclusi' => $oneriPrevidEsclusi,
                    ':regola_compendiata' => $regolaCompendiata,
                    ':ancora_riferimento' => $ancoraRiferimento,
                    ':rti_regola' => $rtiRegola,
                ]);
            }
            self::addDebugLog("Job {$jobId}: Record ext_req_econ FATTURATO_GLOBALE salvato con successo");
        }
    }

    /**
     * Processa requisiti_di_capacita_economica_finanziaria e popola ext_req_econ
     */
    private static function processRequisitiEconomici(\PDO $pdo, int $jobId, array $extractions): void
    {
        self::addDebugLog("Job {$jobId}: processRequisitiEconomici - " . count($extractions) . " estrazioni");

        foreach ($extractions as $ext) {
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson)) {
                self::addDebugLog("Job {$jobId}: value_json non è un array in processRequisitiEconomici");
                continue;
            }

            // Estrai dati dal JSON - supporta diverse strutture
            $answer = self::smartDecode($valueJson['answer'] ?? $valueJson, $valueJson);

            if (!is_array($answer)) {
                self::addDebugLog("Job {$jobId}: answer non è un array dopo decode. Tipo: " . gettype($answer) . ", sample: " . substr(var_export($answer, true), 0, 200));
                continue;
            }

            // Debug: mostra le chiavi principali
            $answerKeys = array_keys($answer);
            self::addDebugLog("Job {$jobId}: answer chiavi: " . implode(', ', $answerKeys));

            $requirements = $answer['requirements'] ?? [];
            if (!is_array($requirements) || empty($requirements)) {
                self::addDebugLog("Job {$jobId}: requirements non trovato o vuoto. Tipo: " . gettype($requirements) . ", valore: " . var_export($requirements, true));
                continue;
            }

            self::addDebugLog("Job {$jobId}: Trovati " . count($requirements) . " requisiti economici");

            // Cerca fatturato globale e servizi di punta (possono essere in ordine diverso)
            foreach ($requirements as $idx => $req) {
                if (!is_array($req)) {
                    continue;
                }

                $reqText = ($req['requirement_text'] ?? '') . ' ' . ($req['description'] ?? '');
                $reqType = $req['type'] ?? '';
                $minimumAmount = $req['minimum_amount'] ?? [];

                self::addDebugLog("Job {$jobId}: Requisito economico #{$idx} - type: {$reqType}, minimum_amount count: " . (is_array($minimumAmount) ? count($minimumAmount) : 'N/A') . ", text: " . substr($reqText, 0, 100));

                // Debug: mostra struttura minimum_amount se è array
                if (is_array($minimumAmount) && !empty($minimumAmount)) {
                    self::addDebugLog("Job {$jobId}: Requisito #{$idx} - minimum_amount struttura: " . json_encode(array_slice($minimumAmount, 0, 2), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
                }

                // Debug: mostra anche se ci sono categorie o altri campi
                if (isset($req['categories'])) {
                    self::addDebugLog("Job {$jobId}: Requisito #{$idx} - ha campo 'categories': " . (is_array($req['categories']) ? count($req['categories']) : 'N/A'));
                }

                // Identifica fatturato globale
                if (
                    stripos($reqText, 'fatturato globale') !== false ||
                    stripos($reqType, 'global') !== false ||
                    (is_array($minimumAmount) && count($minimumAmount) === 1 && ($minimumAmount[0]['category'] ?? null) === null)
                ) {

                    self::addDebugLog("Job {$jobId}: Requisito #{$idx} identificato come FATTURATO_GLOBALE");
                    $checkStmt = $pdo->prepare("SELECT id FROM ext_req_econ WHERE job_id = :job_id AND tipo = 'FATTURATO_GLOBALE'");
                    $checkStmt->execute([':job_id' => $jobId]);
                    if (!$checkStmt->fetch()) {
                        // Estrai dati dal requisito
                        $minAmt = $minimumAmount[0]['value'] ?? $minimumAmount[0]['raw'] ?? null;
                        // Se è già un numero, usalo direttamente
                        if (is_numeric($minAmt)) {
                            $minAmt = (float) $minAmt;
                        } elseif (is_string($minAmt)) {
                            // Rimuovi simboli di valuta e spazi
                            $minAmt = preg_replace('/[^\d.,]/', '', $minAmt);
                            // Se ha virgola, assume formato italiano (1.500.000,00)
                            if (strpos($minAmt, ',') !== false) {
                                $minAmt = str_replace('.', '', $minAmt); // Rimuovi separatori migliaia
                                $minAmt = str_replace(',', '.', $minAmt); // Virgola diventa punto decimale
                            } else {
                                // Formato inglese o senza decimali
                                $minAmt = str_replace(',', '', $minAmt); // Rimuovi virgole
                            }
                            $minAmt = $minAmt !== '' ? (float) $minAmt : null;
                        }

                        if ($minAmt !== null && $minAmt > 0) {
                            $timeframe = $req['timeframe'] ?? [];
                            $bestPeriods = $timeframe['selected_count'] ?? 3;
                            $lookbackAnni = $timeframe['total_window'] ?? 5;
                            $tipoPeriodo = ($timeframe['unit'] ?? 'anni') === 'calendar_years' ? 'calendar_years' : 'fiscal_years';

                            $rtiAlloc = $req['rti_allocation'] ?? [];
                            $rtiRegola = $rtiAlloc['distribution_rules'] ?? "Il requisito relativo al fatturato globale deve essere soddisfatto dal raggruppamento nel suo complesso.";

                            $regolaCompendiata = sprintf(
                                "Fatturato globale minimo maturato nei migliori %d esercizi degli ultimi %d antecedenti alla data di pubblicazione del bando.",
                                (int) $bestPeriods,
                                (int) $lookbackAnni
                            );

                            $stmt = $pdo->prepare("
                                INSERT INTO ext_req_econ 
                                (job_id, tipo, importo_minimo, valuta, soglia_direzione, best_periods, lookback_anni, tipo_periodo, scope, iva_esclusa, oneri_previd_esclusi, regola_compendiata, rti_regola)
                                VALUES 
                                (:job_id, 'FATTURATO_GLOBALE', :importo_minimo, 'EUR', 'almeno', :best_periods, :lookback_anni, :tipo_periodo, 'truly_global', 1, 1, :regola_compendiata, :rti_regola)
                                ON DUPLICATE KEY UPDATE
                                importo_minimo = VALUES(importo_minimo),
                                best_periods = VALUES(best_periods),
                                lookback_anni = VALUES(lookback_anni),
                                tipo_periodo = VALUES(tipo_periodo),
                                regola_compendiata = VALUES(regola_compendiata),
                                rti_regola = VALUES(rti_regola)
                            ");
                            $stmt->execute([
                                ':job_id' => $jobId,
                                ':importo_minimo' => (float) $minAmt,
                                ':best_periods' => (int) $bestPeriods,
                                ':lookback_anni' => (int) $lookbackAnni,
                                ':tipo_periodo' => $tipoPeriodo,
                                ':regola_compendiata' => $regolaCompendiata,
                                ':rti_regola' => self::cleanItalianText($rtiRegola),
                            ]);
                        }
                    }
                }

                // Identifica servizi di punta
                // Criteri: 1) minimum_amount array con più elementi E category, oppure
                //          2) testo contiene "servizi di punta" o "punta" (anche con 1 elemento)
                //          3) campo 'categories' presente
                $isServiziPunta = false;
                $reason = '';

                // Controlla se il testo contiene "servizi di punta" o "punta"
                $containsPunta = (stripos($reqText, 'servizi di punta') !== false ||
                    stripos($reqText, 'servizi \"di punta\"') !== false ||
                    stripos($reqText, '\'di punta\'') !== false ||
                    (stripos($reqText, 'punta') !== false && stripos($reqText, 'servizi') !== false));

                if (is_array($minimumAmount) && count($minimumAmount) > 1) {
                    self::addDebugLog("Job {$jobId}: Requisito #{$idx} ha minimum_amount con " . count($minimumAmount) . " elementi - controllo se sono servizi di punta");
                    $hasCategories = false;
                    foreach ($minimumAmount as $amtIdx => $amt) {
                        if (isset($amt['category']) && is_array($amt['category'])) {
                            $hasCategories = true;
                            self::addDebugLog("Job {$jobId}: Requisito #{$idx}, minimum_amount[{$amtIdx}] ha category: " . var_export($amt['category'], true));
                            break;
                        }
                    }

                    if ($hasCategories || $containsPunta) {
                        $isServiziPunta = true;
                        $reason = "minimum_amount con " . count($minimumAmount) . " elementi" . ($hasCategories ? " e category" : "") . ($containsPunta ? " e testo contiene 'punta'" : "");
                    }
                } elseif (is_array($minimumAmount) && count($minimumAmount) === 1) {
                    // Anche con 1 elemento, se il testo parla di "servizi di punta", potrebbe essere servizi di punta
                    // Controlla se l'elemento ha una category o se ci sono categories separate
                    $hasCategoryInSingle = isset($minimumAmount[0]['category']) && is_array($minimumAmount[0]['category']);
                    $hasCategoriesField = isset($req['categories']) && is_array($req['categories']) && !empty($req['categories']);

                    if ($containsPunta || $hasCategoryInSingle || $hasCategoriesField) {
                        $isServiziPunta = true;
                        $reason = "minimum_amount con 1 elemento ma " .
                            ($containsPunta ? "testo contiene 'punta'" : "") .
                            ($hasCategoryInSingle ? " ha category nell'elemento" : "") .
                            ($hasCategoriesField ? " ha campo 'categories'" : "");
                    } else {
                        self::addDebugLog("Job {$jobId}: Requisito #{$idx} ha minimum_amount con 1 solo elemento e non sembra servizi di punta");
                    }
                } elseif (isset($req['categories']) && is_array($req['categories']) && !empty($req['categories'])) {
                    // Ha campo categories separato
                    $isServiziPunta = true;
                    $reason = "ha campo 'categories' con " . count($req['categories']) . " elementi";
                } else {
                    self::addDebugLog("Job {$jobId}: Requisito #{$idx} ha minimum_amount non array o vuoto - tipo: " . gettype($minimumAmount));
                }

                if ($isServiziPunta) {
                    self::addDebugLog("Job {$jobId}: Requisito #{$idx} identificato come SERVIZI_PUNTA ({$reason}) - chiamo processServiziPunta");
                    self::processServiziPunta($pdo, $jobId, $req);
                } elseif ($containsPunta) {
                    self::addDebugLog("Job {$jobId}: Requisito #{$idx} contiene 'punta' nel testo ma non è stato identificato come SERVIZI_PUNTA - struttura minimum_amount: " . (is_array($minimumAmount) ? json_encode($minimumAmount, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : gettype($minimumAmount)));
                }
            }
        }
    }

    /**
     * Processa servizi di punta per categoria
     */
    private static function processServiziPunta(\PDO $pdo, int $jobId, array $requirement): void
    {
        self::addDebugLog("Job {$jobId}: processServiziPunta chiamato");

        // Prova diverse strutture
        $categories = $requirement['categories'] ?? $requirement['categorie'] ?? [];
        $minimumAmount = $requirement['minimum_amount'] ?? [];
        $timeframe = $requirement['timeframe'] ?? [];
        $formula = $requirement['formula'] ?? [];
        $rtiAlloc = $requirement['rti_allocation'] ?? [];

        // Se minimum_amount è un array con category, usa quello
        if (is_array($minimumAmount) && !empty($minimumAmount)) {
            self::addDebugLog("Job {$jobId}: processServiziPunta - uso minimum_amount array (" . count($minimumAmount) . " elementi)");
            $windowAnni = $timeframe['selected_count'] ?? $timeframe['total_window'] ?? 10;
            $moltiplicatore = $formula['multiplier'] ?? 0.50;
            $riferimentoValoreCategoria = sprintf("%.2f volte il valore della categoria", $moltiplicatore);
            $rtiRegolaSp = $rtiAlloc['distribution_rules'] ?? "Per ciascuna categoria/ID il requisito può essere soddisfatto da un solo componente del raggruppamento, oppure da due componenti diversi per un importo complessivo pari a quello richiesto, fermo restando che il singolo servizio non è frazionabile.";

            foreach ($minimumAmount as $amt) {
                if (!is_array($amt)) {
                    continue;
                }

                $cat = $amt['category'] ?? null;
                $categoriaCodice = $cat['id'] ?? $amt['id_opera'] ?? null;
                $categoriaTipo = $cat['type'] ?? $amt['category_name'] ?? null;
                $categoriaDescrizione = $cat['name'] ?? $amt['description'] ?? null;
                $importoMinimo = $amt['value'] ?? $amt['raw'] ?? null;

                // Se è già un numero, usalo direttamente
                if (is_numeric($importoMinimo)) {
                    $importoMinimo = (float) $importoMinimo;
                } elseif (is_string($importoMinimo)) {
                    // Rimuovi simboli di valuta e spazi
                    $importoMinimo = preg_replace('/[^\d.,]/', '', $importoMinimo);
                    // Se ha virgola, assume formato italiano (1.500.000,00)
                    if (strpos($importoMinimo, ',') !== false) {
                        $importoMinimo = str_replace('.', '', $importoMinimo); // Rimuovi separatori migliaia
                        $importoMinimo = str_replace(',', '.', $importoMinimo); // Virgola diventa punto decimale
                    } else {
                        // Formato inglese o senza decimali
                        $importoMinimo = str_replace(',', '', $importoMinimo); // Rimuovi virgole
                    }
                    $importoMinimo = $importoMinimo !== '' ? (float) $importoMinimo : null;
                }

                if ($categoriaCodice === null || $importoMinimo === null || $importoMinimo <= 0) {
                    self::addDebugLog("Job {$jobId}: Salto categoria - codice: " . var_export($categoriaCodice, true) . ", importo: " . var_export($importoMinimo, true));
                    continue;
                }

                $categoriaTipo = $categoriaTipo ? self::cleanItalianText($categoriaTipo) : null;
                $categoriaDescrizione = $categoriaDescrizione ? self::cleanItalianText($categoriaDescrizione) : null;

                self::addDebugLog("Job {$jobId}: Salvo SERVIZI_PUNTA per categoria {$categoriaCodice}, importo: {$importoMinimo}");

                $stmt = $pdo->prepare("
                    INSERT INTO ext_req_econ 
                    (job_id, tipo, categoria_codice, categoria_tipo, categoria_descrizione, importo_minimo, valuta, soglia_direzione, window_anni, moltiplicatore, riferimento_valore_categoria, rti_regola_sp)
                    VALUES 
                    (:job_id, 'SERVIZI_PUNTA', :categoria_codice, :categoria_tipo, :categoria_descrizione, :importo_minimo, 'EUR', 'almeno', :window_anni, :moltiplicatore, :riferimento_valore_categoria, :rti_regola_sp)
                    ON DUPLICATE KEY UPDATE
                    categoria_tipo = VALUES(categoria_tipo),
                    categoria_descrizione = VALUES(categoria_descrizione),
                    importo_minimo = VALUES(importo_minimo),
                    window_anni = VALUES(window_anni),
                    moltiplicatore = VALUES(moltiplicatore),
                    riferimento_valore_categoria = VALUES(riferimento_valore_categoria),
                    rti_regola_sp = VALUES(rti_regola_sp)
                ");

                $stmt->execute([
                    ':job_id' => $jobId,
                    ':categoria_codice' => (string) $categoriaCodice,
                    ':categoria_tipo' => $categoriaTipo,
                    ':categoria_descrizione' => $categoriaDescrizione,
                    ':importo_minimo' => (float) $importoMinimo,
                    ':window_anni' => (int) $windowAnni,
                    ':moltiplicatore' => (float) $moltiplicatore,
                    ':riferimento_valore_categoria' => $riferimentoValoreCategoria,
                    ':rti_regola_sp' => self::cleanItalianText($rtiRegolaSp),
                ]);
                self::addDebugLog("Job {$jobId}: Record ext_req_econ SERVIZI_PUNTA salvato per categoria {$categoriaCodice}");
            }
            self::addDebugLog("Job {$jobId}: processServiziPunta completato - salvate " . count($minimumAmount) . " categorie");
            return;
        }

        // Fallback: struttura categories array
        if (!is_array($categories) || empty($categories)) {
            return;
        }

        $windowAnni = $requirement['window_years'] ?? $requirement['anni'] ?? 10;
        $moltiplicatore = $requirement['multiplier'] ?? $requirement['moltiplicatore'] ?? 0.50;
        $riferimentoValoreCategoria = sprintf("%.2f volte il valore della categoria", $moltiplicatore);
        $rtiRegolaSp = "Per ciascuna categoria/ID il requisito può essere soddisfatto da un solo componente del raggruppamento, oppure da due componenti diversi per un importo complessivo pari a quello richiesto, fermo restando che il singolo servizio non è frazionabile.";

        foreach ($categories as $cat) {
            if (!is_array($cat)) {
                continue;
            }

            $categoriaCodice = $cat['code'] ?? $cat['codice'] ?? $cat['id'] ?? null;
            $categoriaTipo = $cat['type'] ?? $cat['tipo'] ?? null;
            $categoriaDescrizione = $cat['description'] ?? $cat['descrizione'] ?? null;
            $importoMinimo = $cat['minimum_amount'] ?? $cat['importo'] ?? $cat['amount'] ?? null;

            if ($categoriaCodice === null || $importoMinimo === null) {
                continue;
            }

            $categoriaTipo = $categoriaTipo ? self::cleanItalianText($categoriaTipo) : null;
            $categoriaDescrizione = $categoriaDescrizione ? self::cleanItalianText($categoriaDescrizione) : null;

            $stmt = $pdo->prepare("
                INSERT INTO ext_req_econ 
                (job_id, tipo, categoria_codice, categoria_tipo, categoria_descrizione, importo_minimo, valuta, soglia_direzione, window_anni, moltiplicatore, riferimento_valore_categoria, rti_regola_sp)
                VALUES 
                (:job_id, 'SERVIZI_PUNTA', :categoria_codice, :categoria_tipo, :categoria_descrizione, :importo_minimo, 'EUR', 'almeno', :window_anni, :moltiplicatore, :riferimento_valore_categoria, :rti_regola_sp)
                ON DUPLICATE KEY UPDATE
                categoria_tipo = VALUES(categoria_tipo),
                categoria_descrizione = VALUES(categoria_descrizione),
                importo_minimo = VALUES(importo_minimo),
                window_anni = VALUES(window_anni),
                moltiplicatore = VALUES(moltiplicatore),
                riferimento_valore_categoria = VALUES(riferimento_valore_categoria),
                rti_regola_sp = VALUES(rti_regola_sp)
            ");

            $stmt->execute([
                ':job_id' => $jobId,
                ':categoria_codice' => (string) $categoriaCodice,
                ':categoria_tipo' => $categoriaTipo,
                ':categoria_descrizione' => $categoriaDescrizione,
                ':importo_minimo' => (float) $importoMinimo,
                ':window_anni' => (int) $windowAnni,
                ':moltiplicatore' => (float) $moltiplicatore,
                ':riferimento_valore_categoria' => $riferimentoValoreCategoria,
                ':rti_regola_sp' => $rtiRegolaSp,
            ]);
        }
    }

    /**
     * Processa requisiti_idoneita_professionale_gruppo_lavoro e popola ext_req_roles
     */
    private static function processRequisitiRuoli(\PDO $pdo, int $jobId, array $extractions): void
    {
        self::addDebugLog("Job {$jobId}: processRequisitiRuoli - " . count($extractions) . " estrazioni");

        foreach ($extractions as $ext) {
            $valueJson = $ext['value_json'] ?? null;
            if (!is_array($valueJson)) {
                self::addDebugLog("Job {$jobId}: value_json non è un array in processRequisitiRuoli");
                continue;
            }

            // Estrai dati dal JSON - supporta diverse strutture
            $answer = self::smartDecode($valueJson['answer'] ?? $valueJson, $valueJson);

            if (!is_array($answer)) {
                self::addDebugLog("Job {$jobId}: answer non è un array dopo decode. Tipo: " . gettype($answer) . ", sample: " . substr(var_export($answer, true), 0, 200));
                continue;
            }

            // Debug: mostra le chiavi principali
            $answerKeys = array_keys($answer);
            self::addDebugLog("Job {$jobId}: answer chiavi: " . implode(', ', $answerKeys));

            $roles = $answer['roles'] ?? [];
            $requirements = $answer['requirements'] ?? [];
            $generalRules = $answer['general_rules'] ?? $answer['regole_generali'] ?? [];

            // Debug: mostra anche altri campi possibili
            if (empty($roles) && isset($answer['roles_text'])) {
                self::addDebugLog("Job {$jobId}: roles vuoto ma roles_text presente: " . substr($answer['roles_text'], 0, 200));
            }
            if (empty($requirements) && isset($answer['requirements_text'])) {
                self::addDebugLog("Job {$jobId}: requirements vuoto ma requirements_text presente: " . substr($answer['requirements_text'], 0, 200));
            }

            if (!is_array($roles) || !is_array($requirements)) {
                self::addDebugLog("Job {$jobId}: roles o requirements non sono array. roles: " . (is_array($roles) ? count($roles) : 'N/A') . ", requirements: " . (is_array($requirements) ? count($requirements) : 'N/A'));
                continue;
            }

            self::addDebugLog("Job {$jobId}: Trovati " . count($roles) . " ruoli e " . count($requirements) . " requisiti");

            // Se roles è vuoto, prova a vedere se c'è is_minimum_composition o altri indicatori
            if (empty($roles)) {
                self::addDebugLog("Job {$jobId}: roles è vuoto. Controllo altri campi...");
                if (isset($answer['is_minimum_composition'])) {
                    self::addDebugLog("Job {$jobId}: is_minimum_composition: " . var_export($answer['is_minimum_composition'], true));
                }
                // Non continuare se non ci sono ruoli da processare
                if (empty($roles)) {
                    self::addDebugLog("Job {$jobId}: Nessun ruolo trovato, salto il processamento");
                    // Ma salva comunque le regole generali se presenti
                    if (!empty($generalRules)) {
                        self::addDebugLog("Job {$jobId}: Trovate regole generali, le processo");
                        // Processa regole generali (sovrapposizione ruoli)
                        $overlapRule = null;
                        foreach ($generalRules as $rule) {
                            if (is_array($rule)) {
                                $ruleText = $rule['text'] ?? $rule['testo'] ?? $rule['description'] ?? '';
                                if (stripos($ruleText, 'sovrapposizione') !== false || stripos($ruleText, 'coincidere') !== false) {
                                    $overlapRule = self::cleanItalianText($ruleText);
                                    break;
                                }
                            } elseif (is_string($rule)) {
                                if (stripos($rule, 'sovrapposizione') !== false || stripos($rule, 'coincidere') !== false) {
                                    $overlapRule = self::cleanItalianText($rule);
                                    break;
                                }
                            }
                        }

                        if ($overlapRule === null) {
                            $overlapRule = "È possibile che più professionalità coincidano nello stesso professionista, purché in possesso delle necessarie qualifiche, abilitazioni e certificazioni. Prima della stipula del contratto va indicato un referente unico verso la Stazione Appaltante.";
                        }

                        // Salva regola sovrapposizione con nuova struttura
                        $stmt = $pdo->prepare("
                            INSERT INTO ext_req_roles 
                            (job_id, role_code, role_name, is_minimum, ordine, requisiti_json)
                            VALUES 
                            (:job_id, 'META_OVERLAP_RULE', 'Regola sovrapposizione ruoli', 0, 999, :requisiti_json)
                            ON DUPLICATE KEY UPDATE
                            role_name = VALUES(role_name),
                            requisiti_json = VALUES(requisiti_json)
                        ");

                        $stmt->execute([
                            ':job_id' => $jobId,
                            ':requisiti_json' => json_encode([
                                'qualifications' => [
                                    [
                                        'category' => 'normativa_specifica',
                                        'requirements' => [
                                            ['label' => $overlapRule]
                                        ]
                                    ]
                                ],
                                'meta' => [
                                    'overlap_allowed' => true
                                ]
                            ], JSON_UNESCAPED_UNICODE),
                        ]);
                        self::addDebugLog("Job {$jobId}: Salvata regola META_OVERLAP_RULE");
                    }
                    continue;
                }
            }

            // Processa ogni ruolo
            $ordine = 1;
            foreach ($roles as $role) {
                if (!is_array($role)) {
                    continue;
                }

                $roleId = $role['id'] ?? null;
                $roleName = $role['name'] ?? $role['nome'] ?? '';
                $roleCode = self::generateRoleCode($roleName);

                // Costruisci requisiti_json per questo ruolo usando la nuova struttura con qualifications
                $qualificationsByCategory = [];
                $pageRef = null;

                foreach ($requirements as $req) {
                    if (!is_array($req)) {
                        continue;
                    }

                    $appliesTo = $req['applies_to_role_ids'] ?? $req['applies_to'] ?? [];
                    if (!is_array($appliesTo)) {
                        $appliesTo = [$appliesTo];
                    }

                    if (in_array($roleId, $appliesTo, true)) {
                        // Estrai page_ref se presente
                        if ($pageRef === null) {
                            $pageRef = $req['page_ref'] ?? $req['page_number'] ?? $req['source_location']['page'] ?? null;
                        }

                        // Prova prima la struttura qualifications (nuova struttura API)
                        $qualifications = $req['qualifications'] ?? [];
                        if (is_array($qualifications) && !empty($qualifications)) {
                            foreach ($qualifications as $qual) {
                                if (!is_array($qual)) {
                                    continue;
                                }

                                $category = $qual['category'] ?? 'normativa_specifica';
                                $label = $qual['label'] ?? $qual['description'] ?? '';
                                $normRef = $qual['norm_ref'] ?? null;

                                if (empty($label)) {
                                    continue;
                                }

                                if (!isset($qualificationsByCategory[$category])) {
                                    $qualificationsByCategory[$category] = [];
                                }

                                $requirement = ['label' => self::cleanItalianText($label)];
                                if ($normRef !== null) {
                                    $requirement['norm_ref'] = self::cleanItalianText($normRef);
                                }

                                $qualificationsByCategory[$category][] = $requirement;
                            }
                        } else {
                            // Fallback: struttura semplice - mappa a categoria appropriata
                            $reqType = $req['type'] ?? 'meta';
                            $reqDesc = $req['description'] ?? $req['descrizione'] ?? $req['original_text'] ?? '';

                            if (empty($reqDesc)) {
                                continue;
                            }

                            // Determina categoria basandosi sul tipo o sul contenuto
                            $category = 'normativa_specifica'; // default

                            $reqDescLower = strtolower($reqDesc);
                            if (
                                stripos($reqDescLower, 'laurea') !== false ||
                                stripos($reqDescLower, 'diploma') !== false ||
                                stripos($reqDescLower, 'titolo di studio') !== false ||
                                stripos($reqDescLower, 'titoli') !== false ||
                                stripos($reqDescLower, 'istruzione') !== false
                            ) {
                                $category = 'titoli_studio_e_abilitazione';
                            } elseif (
                                stripos($reqDescLower, 'abilitazione') !== false ||
                                stripos($reqDescLower, 'abilitato') !== false ||
                                stripos($reqDescLower, 'esame di stato') !== false
                            ) {
                                $category = 'abilitazione_professionale';
                            } elseif (
                                stripos($reqDescLower, 'iscrizione') !== false ||
                                stripos($reqDescLower, 'ordine') !== false ||
                                stripos($reqDescLower, 'albo') !== false ||
                                stripos($reqDescLower, 'collegio') !== false
                            ) {
                                $category = 'iscrizione_albo';
                            } elseif (
                                stripos($reqDescLower, 'art.') !== false ||
                                stripos($reqDescLower, 'd.lgs.') !== false ||
                                stripos($reqDescLower, 'd.l.') !== false ||
                                stripos($reqDescLower, 'legge') !== false ||
                                stripos($reqDescLower, 'decreto') !== false
                            ) {
                                $category = 'normativa_specifica';
                            }

                            if (!isset($qualificationsByCategory[$category])) {
                                $qualificationsByCategory[$category] = [];
                            }

                            $requirement = ['label' => self::cleanItalianText($reqDesc)];

                            // Estrai riferimento normativo se presente nel testo
                            if (preg_match('/(D\.Lgs\.|D\.L\.|Legge|Art\.)\s*[\d\/]+/i', $reqDesc, $matches)) {
                                $requirement['norm_ref'] = $matches[0];
                            }

                            $qualificationsByCategory[$category][] = $requirement;
                        }
                    }
                }

                // Costruisci la struttura finale con qualifications e meta
                $requisitiJson = [
                    'qualifications' => [],
                    'meta' => [
                        'overlap_allowed' => true,
                    ]
                ];

                // Aggiungi page_ref se presente
                if ($pageRef !== null) {
                    $requisitiJson['meta']['page_ref'] = (int) $pageRef;
                }

                // Converti le categorie in array ordinato
                foreach ($qualificationsByCategory as $category => $requirements) {
                    $requisitiJson['qualifications'][] = [
                        'category' => $category,
                        'requirements' => $requirements
                    ];
                }

                // Salva ruolo
                $stmt = $pdo->prepare("
                    INSERT INTO ext_req_roles 
                    (job_id, role_code, role_name, is_minimum, ordine, requisiti_json)
                    VALUES 
                    (:job_id, :role_code, :role_name, 1, :ordine, :requisiti_json)
                    ON DUPLICATE KEY UPDATE
                    role_name = VALUES(role_name),
                    is_minimum = VALUES(is_minimum),
                    ordine = VALUES(ordine),
                    requisiti_json = VALUES(requisiti_json)
                ");

                $stmt->execute([
                    ':job_id' => $jobId,
                    ':role_code' => $roleCode,
                    ':role_name' => self::cleanItalianText($roleName),
                    ':ordine' => $ordine++,
                    ':requisiti_json' => json_encode($requisitiJson, JSON_UNESCAPED_UNICODE),
                ]);
                self::addDebugLog("Job {$jobId}: Salvato ruolo {$roleCode} ({$roleName}) con " . count($requisitiJson) . " requisiti");
            }

            self::addDebugLog("Job {$jobId}: Salvati " . ($ordine - 1) . " ruoli in ext_req_roles");

            // Processa regole generali (sovrapposizione ruoli)
            self::addDebugLog("Job {$jobId}: Processo regole generali (generalRules count: " . count($generalRules) . ")");
            $overlapRule = null;
            foreach ($generalRules as $ruleIdx => $rule) {
                if (is_array($rule)) {
                    $ruleText = $rule['text'] ?? $rule['testo'] ?? $rule['description'] ?? '';
                    if (stripos($ruleText, 'sovrapposizione') !== false || stripos($ruleText, 'coincidere') !== false) {
                        $overlapRule = self::cleanItalianText($ruleText);
                        self::addDebugLog("Job {$jobId}: Trovata regola sovrapposizione in generalRules[{$ruleIdx}]: " . substr($ruleText, 0, 100));
                        break;
                    }
                } elseif (is_string($rule)) {
                    if (stripos($rule, 'sovrapposizione') !== false || stripos($rule, 'coincidere') !== false) {
                        $overlapRule = self::cleanItalianText($rule);
                        self::addDebugLog("Job {$jobId}: Trovata regola sovrapposizione in generalRules[{$ruleIdx}]: " . substr($rule, 0, 100));
                        break;
                    }
                }
            }

            if ($overlapRule === null) {
                $overlapRule = "È possibile che più professionalità coincidano nello stesso professionista, purché in possesso delle necessarie qualifiche, abilitazioni e certificazioni. Prima della stipula del contratto va indicato un referente unico verso la Stazione Appaltante.";
                self::addDebugLog("Job {$jobId}: Nessuna regola sovrapposizione trovata, uso default");
            }

            // Salva regola sovrapposizione con nuova struttura
            self::addDebugLog("Job {$jobId}: Salvo regola META_OVERLAP_RULE");
            $stmt = $pdo->prepare("
                INSERT INTO ext_req_roles 
                (job_id, role_code, role_name, is_minimum, ordine, requisiti_json)
                VALUES 
                (:job_id, 'META_OVERLAP_RULE', 'Regola sovrapposizione ruoli', 0, 999, :requisiti_json)
                ON DUPLICATE KEY UPDATE
                role_name = VALUES(role_name),
                requisiti_json = VALUES(requisiti_json)
            ");

            $stmt->execute([
                ':job_id' => $jobId,
                ':requisiti_json' => json_encode([
                    'qualifications' => [
                        [
                            'category' => 'normativa_specifica',
                            'requirements' => [
                                ['label' => $overlapRule]
                            ]
                        ]
                    ],
                    'meta' => [
                        'overlap_allowed' => true
                    ]
                ], JSON_UNESCAPED_UNICODE),
            ]);
            self::addDebugLog("Job {$jobId}: Regola META_OVERLAP_RULE salvata con successo");

            // Cerca requisito "giovane professionista" (id 18 o simile)
            self::addDebugLog("Job {$jobId}: Cerco requisito 'giovane professionista' in " . count($requirements) . " requisiti");
            $foundGiovaneProf = false;
            foreach ($requirements as $reqIdx => $req) {
                if (!is_array($req)) {
                    continue;
                }

                $reqId = $req['id'] ?? null;
                $reqDesc = $req['description'] ?? $req['descrizione'] ?? '';

                if ($reqId == 18 || stripos($reqDesc, 'giovane professionista') !== false || stripos($reqDesc, 'art. 39') !== false) {
                    self::addDebugLog("Job {$jobId}: Trovato requisito 'giovane professionista' in requirements[{$reqIdx}] (id: {$reqId})");
                    $foundGiovaneProf = true;
                    $stmt = $pdo->prepare("
                        INSERT INTO ext_req_roles 
                        (job_id, role_code, role_name, is_minimum, ordine, requisiti_json)
                        VALUES 
                        (:job_id, 'META_GIOVANE_PROF_RTI', 'Giovane professionista (solo per RTI)', 0, 998, :requisiti_json)
                        ON DUPLICATE KEY UPDATE
                        role_name = VALUES(role_name),
                        requisiti_json = VALUES(requisiti_json)
                    ");

                    $stmt->execute([
                        ':job_id' => $jobId,
                        ':requisiti_json' => json_encode([
                            'qualifications' => [
                                [
                                    'category' => 'normativa_specifica',
                                    'requirements' => [
                                        ['label' => self::cleanItalianText($reqDesc)]
                                    ]
                                ]
                            ],
                            'meta' => [
                                'overlap_allowed' => false
                            ]
                        ], JSON_UNESCAPED_UNICODE),
                    ]);
                    self::addDebugLog("Job {$jobId}: Regola META_GIOVANE_PROF_RTI salvata con successo");
                    break;
                }
            }
            if (!$foundGiovaneProf) {
                self::addDebugLog("Job {$jobId}: Nessun requisito 'giovane professionista' trovato");
            }
        }
    }

    /**
     * Genera un role_code da un role_name
     */
    private static function generateRoleCode(string $roleName): string
    {
        // Rimuovi caratteri speciali e converti in UPPER_SNAKE_CASE
        $code = preg_replace('/[^a-zA-Z0-9\s]/', '', $roleName);
        $code = preg_replace('/\s+/', '_', trim($code));
        $code = strtoupper($code);

        // Limita la lunghezza
        if (strlen($code) > 50) {
            $code = substr($code, 0, 50);
        }

        return $code ?: 'ROLE_' . uniqid();
    }

    /**
     * Genera un doc_code da un titolo documento
     */
    private static function generateDocCode(string $title): string
    {
        // Rimuovi caratteri speciali e converti in UPPER_SNAKE_CASE
        $code = preg_replace('/[^a-zA-Z0-9\s]/', '', $title);
        $code = preg_replace('/\s+/', '_', trim($code));
        $code = strtoupper($code);

        // Limita la lunghezza
        if (strlen($code) > 50) {
            $code = substr($code, 0, 50);
        }

        // Se il codice è vuoto o troppo corto, usa un hash
        if (strlen($code) < 3) {
            $code = 'DOC_' . strtoupper(substr(md5($title), 0, 8));
        }

        return $code;
    }

    /**
     * Pulisce testo rimuovendo spazi multipli
     */
    /**
     * Helper per decodificare JSON in modo intelligente.
     * Gestisce stringhe "null", JSON valido, e fallback.
     * @param mixed $value Valore da decodificare (stringa JSON o già decodificato)
     * @param mixed $fallback Valore di fallback se la decodifica fallisce
     * @return mixed Valore decodificato o fallback
     */
    private static function smartDecode($value, $fallback = null)
    {
        if ($value === null) {
            return $fallback;
        }

        if (!is_string($value)) {
            return $value; // Già decodificato o non stringa
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strtolower($trimmed) === 'null') {
            return $fallback;
        }

        $decoded = json_decode($trimmed, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $fallback; // JSON non valido
        }

        return $decoded;
    }

    private static function cleanItalianText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private static function externalAnalyzeSingle(array $fields, array $file, array $env): array
    {
        $client = new \Services\AIextraction\ExternalApiClient($env);
        return $client->analyzeSingleFile($fields, $file);
    }
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

    private static function normalizeExternalStatus(array $body): string
    {
        $raw = strtolower((string) ($body['status'] ?? $body['state'] ?? 'processing'));
        if (in_array($raw, ['completed', 'done', 'finished'], true)) {
            return 'completed';
        }
        if (in_array($raw, ['failed', 'error'], true)) {
            return 'error';
        }
        if (in_array($raw, ['queued'], true)) {
            return 'queued';
        }
        return 'processing';
    }

    private static function mapExternalAnswersFromBatch(array $payload): array
    {
        $answers = [];

        if (!empty($payload['results']) && is_array($payload['results'])) {
            foreach ($payload['results'] as $r) {
                $mapped = self::mapSingleAnswer($r);
                if ($mapped) {
                    $answers[] = $mapped;
                }
            }
        }

        if (!empty($payload['files']) && is_array($payload['files'])) {
            foreach ($payload['files'] as $file) {
                $extractions = $file['extractions'] ?? [];
                if (!is_array($extractions)) {
                    continue;
                }
                foreach ($extractions as $ex) {
                    $mapped = self::mapSingleAnswer($ex);
                    if ($mapped) {
                        $answers[] = $mapped;
                    }
                }
            }
        }

        if (!empty($payload['jobs']) && is_array($payload['jobs'])) {
            foreach ($payload['jobs'] as $job) {
                $results = $job['results'] ?? [];
                if (!is_array($results)) {
                    continue;
                }
                foreach ($results as $result) {
                    $mapped = self::mapSingleAnswer($result, $job);
                    if ($mapped) {
                        $answers[] = $mapped;
                    }
                }
            }
        }

        return array_values(array_filter($answers));
    }

    private static function mapSingleAnswer(array $record, array $context = []): ?array
    {
        $type = $record['extraction_type'] ?? $record['type'] ?? 'unknown';

        // Nuova struttura API: i dati sono sempre in 'data'
        $data = $record['data'] ?? null;
        $ans = null;
        $valueJson = null;

        if ($data !== null && is_array($data)) {
            // Per le date: converte data.date in formato compatibile
            if (isset($data['date']) && is_array($data['date'])) {
                $date = $data['date'];
                $year = $date['year'] ?? null;
                $month = $date['month'] ?? null;
                $day = $date['day'] ?? null;
                $hour = $date['hour'] ?? null;
                $minute = $date['minute'] ?? null;

                if ($year && $month && $day) {
                    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    if ($hour !== null && $minute !== null) {
                        $dateStr .= sprintf(' %02d:%02d:00', $hour, $minute);
                    }
                    $ans = $dateStr;
                    $valueJson = $data; // Mantieni tutta la struttura data per citazioni e altri campi
                } else {
                    $ans = $data['answer'] ?? $data['url'] ?? null;
                    $valueJson = $data;
                }
            }
            // Per URL: estrae data.url
            elseif (isset($data['url']) && is_string($data['url'])) {
                $ans = $data['url'];
                $valueJson = $data;
            }
            // Per entries (importi_opere_per_categoria_id_opere, ecc.): mantiene solo la struttura utile
            // IMPORTANTE: valueJson deve contenere 'entries' direttamente, non annidato in 'data'
            elseif (isset($data['entries']) && is_array($data['entries'])) {
                $ans = null; // Non c'è un valore testuale semplice
                // Mantieni solo la struttura utile (entries, citations) - i campi di debug verranno rimossi da cleanValueJson()
                $valueJson = $data;
            }
            // Per requirements (requisiti_tecnico_professionali, ecc.): mantiene tutta la struttura
            elseif (isset($data['requirements']) && is_array($data['requirements'])) {
                $ans = $data['answer'] ?? $data['requirements_text'] ?? null;
                $valueJson = $data;
            }
            // Per location: estrae data.location
            elseif (isset($data['location']) && is_array($data['location'])) {
                $loc = $data['location'];
                $city = $loc['city'] ?? '';
                $district = $loc['district'] ?? '';
                if ($city) {
                    $ans = $district ? "{$city} ({$district})" : $city;
                } else {
                    $ans = $loc['raw_text'] ?? $data['answer'] ?? null;
                }
                $valueJson = $data;
            }
            // Per answer generico in data
            elseif (isset($data['answer'])) {
                $ans = $data['answer'];
                $valueJson = $data;
            }
            // Se non c'è struttura specifica, usa data come valueJson
            else {
                $ans = null;
                $valueJson = $data;
            }
        }

        // Se non ci sono dati estratti, usa status not_found
        if ($valueJson === null || (is_array($valueJson) && empty($valueJson))) {
            // Verifica se c'è uno status esplicito di "not_found" o "empty" nei dati
            $hasExplicitNotFound = false;
            if (is_array($data) && isset($data['status'])) {
                $status = strtolower((string) $data['status']);
                if (in_array($status, ['not_found', 'empty', 'none', 'null'], true)) {
                    $hasExplicitNotFound = true;
                }
            }

            // Se non c'è risposta valida o è esplicitamente not_found
            if ($hasExplicitNotFound || $ans === null || $ans === '' || (is_array($ans) && empty($ans))) {
                $valueJson = [
                    'status' => 'not_found',
                    'message' => 'Nessuna estrazione disponibile'
                ];
            }
        }

        $valueText = null;
        $citations = [];

        // Estrai valueText usando extractCleanAnswer() - SOLO risposte pulite, niente fallback con campi di debug
        if (is_array($ans)) {
            $valueJson = $ans;

            // Per tabelle (headers/rows): valueText può essere null, la tabella verrà gestita da classifyExtractionPayload
            if (isset($ans['headers']) || isset($ans['rows'])) {
                // Se c'è un answer pulito, usalo, altrimenti lascia null
                $valueText = self::extractCleanAnswer($ans);
            } else {
                // Per altri casi, estrai solo risposte pulite
                $valueText = self::extractCleanAnswer($ans);
            }

            $citations = self::extractCitationsFromValueJson($valueJson);
        } else {
            // Per valori scalari, verifica che siano puliti
            $valueText = self::extractCleanAnswer($ans);
            
            // Se abbiamo valueJson (da data), estrai citazioni anche da lì
            if ($valueJson !== null) {
                $citations = self::extractCitationsFromValueJson($valueJson);
            }
        }
        
        // Se valueText è ancora null, prova a estrarre da valueJson (se è un array)
        if ($valueText === null && is_array($valueJson)) {
            $valueText = self::extractCleanAnswer($valueJson);
        }

        // Se non ci sono citazioni in valueJson, prova a estrarle da data.citations
        if (empty($citations) && !empty($data['citations']) && is_array($data['citations'])) {
            foreach ($data['citations'] as $c) {
                if (!is_array($c)) {
                    continue;
                }

                $pageNumber = $c['page_number'] ?? $c['page'] ?? null;
                $textArray = null;
                $snippet = null;

                // Gestisci text come array (formato API)
                if (isset($c['text'])) {
                    if (is_array($c['text'])) {
                        $textArray = $c['text'];
                        $snippet = implode(' ', array_filter($c['text']));
                    } else {
                        $textArray = [(string) $c['text']];
                        $snippet = (string) $c['text'];
                    }
                } elseif (isset($c['snippet'])) {
                    if (is_array($c['snippet'])) {
                        $textArray = $c['snippet'];
                        $snippet = implode(' ', array_filter($c['snippet']));
                    } else {
                        $textArray = [(string) $c['snippet']];
                        $snippet = (string) $c['snippet'];
                    }
                }

                if (empty($snippet) && empty($textArray)) {
                    continue;
                }

                if (empty($textArray) && !empty($snippet)) {
                    $textArray = [$snippet];
                }

                $citations[] = [
                    'page_number' => $pageNumber !== null ? (int) $pageNumber : null,
                    'page' => $pageNumber !== null ? (int) $pageNumber : null,
                    'snippet' => $snippet,
                    'text' => $textArray,
                    'highlight_rel_path' => $c['highlight_rel_path'] ?? null,
                    'reason_for_relevance' => $c['reason_for_relevance'] ?? null,
                ];
            }
        }

        // Se non ci sono citazioni ancora, prova a estrarle dal record stesso
        if (empty($citations) && !empty($record['citations']) && is_array($record['citations'])) {
            foreach ($record['citations'] as $c) {
                if (!is_array($c)) {
                    continue;
                }

                $pageNumber = $c['page_number'] ?? $c['page'] ?? null;
                $textArray = null;
                $snippet = null;

                // Gestisci text come array (formato API)
                if (isset($c['text'])) {
                    if (is_array($c['text'])) {
                        $textArray = $c['text'];
                        $snippet = implode(' ', array_filter($c['text']));
                    } else {
                        $textArray = [(string) $c['text']];
                        $snippet = (string) $c['text'];
                    }
                } elseif (isset($c['snippet'])) {
                    if (is_array($c['snippet'])) {
                        $textArray = $c['snippet'];
                        $snippet = implode(' ', array_filter($c['snippet']));
                    } else {
                        $textArray = [(string) $c['snippet']];
                        $snippet = (string) $c['snippet'];
                    }
                }

                if (empty($snippet) && empty($textArray)) {
                    continue;
                }

                if (empty($textArray) && !empty($snippet)) {
                    $textArray = [$snippet];
                }

                $citations[] = [
                    'page_number' => $pageNumber !== null ? (int) $pageNumber : null,
                    'page' => $pageNumber !== null ? (int) $pageNumber : null,
                    'snippet' => $snippet,
                    'text' => $textArray,
                    'highlight_rel_path' => $c['highlight_rel_path'] ?? null,
                    'reason_for_relevance' => $c['reason_for_relevance'] ?? null,
                ];
            }
        }

        return [
            'type_code' => $type,
            'value_text' => $valueText,
            'value_json' => $valueJson,
            'confidence' => $record['confidence'] ?? null,
            'citations' => $citations,
            'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    private static function extractCitationsFromValueJson($valueJson): array
    {
        if (empty($valueJson)) {
            return [];
        }

        if (is_string($valueJson)) {
            $decoded = json_decode($valueJson, true);
            if (!is_array($decoded)) {
                return [];
            }
            $valueJson = $decoded;
        }

        if (!is_array($valueJson)) {
            return [];
        }

        $citations = [];
        $citationsData = $valueJson['citations'] ?? [];

        if (is_array($citationsData)) {
            foreach ($citationsData as $c) {
                if (!is_array($c)) {
                    continue;
                }

                $pageNumber = $c['page_number'] ?? $c['page'] ?? null;
                $snippet = null;
                $textArray = null;

                // Preserva il formato originale: text come array
                if (isset($c['text'])) {
                    if (is_array($c['text'])) {
                        $textArray = $c['text'];
                        $snippet = implode(' ', array_filter($c['text']));
                    } else {
                        $textArray = [(string) $c['text']];
                        $snippet = (string) $c['text'];
                    }
                } elseif (isset($c['snippet'])) {
                    if (is_array($c['snippet'])) {
                        $textArray = $c['snippet'];
                        $snippet = implode(' ', array_filter($c['snippet']));
                    } else {
                        $textArray = [(string) $c['snippet']];
                        $snippet = (string) $c['snippet'];
                    }
                }

                // Salta citazioni senza testo
                if (empty($snippet) && empty($textArray)) {
                    continue;
                }

                // Se non abbiamo textArray ma abbiamo snippet, crealo
                if (empty($textArray) && !empty($snippet)) {
                    $textArray = [$snippet];
                }

                $citations[] = [
                    'page_number' => $pageNumber !== null ? (int) $pageNumber : null,
                    'page' => $pageNumber !== null ? (int) $pageNumber : null,
                    'snippet' => $snippet,
                    'text' => $textArray, // Preserva come array per il frontend
                    'highlight_rel_path' => $c['highlight_rel_path'] ?? null,
                    'reason_for_relevance' => $c['reason_for_relevance'] ?? null, // Preserva se presente
                ];
            }
        }

        return $citations;
    }

    private static function hydrateTableFromCells(array $cells, ?string $valueJson): array
    {
        $decoded = $valueJson ? json_decode($valueJson, true) : null;
        $headers = [];
        $rows = [];

        foreach ($cells as $cell) {
            $r = (int) ($cell['r'] ?? 0);
            $c = (int) ($cell['c'] ?? 0);
            if (!isset($rows[$r])) {
                $rows[$r] = [];
            }
            $rows[$r][$c] = $cell['cell_text'];
            if ($r === 0 && !empty($cell['header'])) {
                $headers[$c] = $cell['header'];
            }
        }

        if ($headers) {
            ksort($headers);
            $headers = array_values($headers);
        } elseif (isset($decoded['headers']) && is_array($decoded['headers'])) {
            $headers = array_values($decoded['headers']);
        } else {
            $maxCols = 0;
            foreach ($rows as $cols) {
                $maxCols = max($maxCols, count($cols));
            }
            for ($i = 0; $i < $maxCols; $i++) {
                $headers[] = "Colonna " . ($i + 1);
            }
        }

        ksort($rows);
        $rowsFormatted = [];
        foreach ($rows as $rIndex => $cols) {
            ksort($cols);
            $rowsFormatted[] = array_values($cols);
        }

        $result = [
            'headers' => $headers,
            'rows' => $rowsFormatted,
        ];

        if (!empty($decoded['path']) && is_string($decoded['path'])) {
            $result['source_csv'] = $decoded['path'];
        } elseif (!empty($decoded['source_csv']) && is_string($decoded['source_csv'])) {
            $result['source_csv'] = $decoded['source_csv'];
        }

        return $result;
    }

    private static function extractProgress(array $body): array
    {
        $done = 0;
        $total = 100;

        // PRIORITÀ 1: Usa progress_percentage se disponibile (es. 66.67)
        if (isset($body['progress_percentage'])) {
            $p = max(0, min(100, (float) $body['progress_percentage']));
            $done = (int) round($p);
            $total = 100;
            return ['done' => $done, 'total' => $total];
        }

        // PRIORITÀ 2: Usa completed_jobs/total_jobs se disponibile
        if (isset($body['completed_jobs']) && isset($body['total_jobs'])) {
            $done = max(0, (int) $body['completed_jobs']);
            $total = max(1, (int) $body['total_jobs']);
            return ['done' => $done, 'total' => $total];
        }

        // FALLBACK: Logica esistente
        if (!empty($body['progress']) && is_array($body['progress'])) {
            $done = (int) ($body['progress']['done'] ?? $done);
            $total = (int) ($body['progress']['total'] ?? $total);
        } elseif (isset($body['percent'])) {
            $p = max(0, min(100, (int) $body['percent']));
            $done = $p;
            $total = 100;
        } elseif (isset($body['progress_percent'])) {
            $p = max(0, min(100, (int) $body['progress_percent']));
            $done = $p;
            $total = 100;
        } elseif (isset($body['progress']) && is_numeric($body['progress'])) {
            $p = max(0, min(1, (float) $body['progress']));
            $done = (int) round($p * 100);
            $total = 100;
        } elseif (isset($body['completed']) && isset($body['total'])) {
            $done = (int) $body['completed'];
            $total = max(1, (int) $body['total']);
        }

        if ($total <= 0) {
            $total = 100;
        }
        if ($done < 0) {
            $done = 0;
        }
        if ($done > $total) {
            $done = $total;
        }

        return ['done' => $done, 'total' => $total];
    }

    private static function normalizeExtractionTypes($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        if (is_string($value)) {
            $tmp = json_decode($value, true);
            if (is_array($tmp)) {
                return array_values(array_filter(array_map('strval', $tmp)));
            }
        }
        return [];
    }

    private static function normalizeFilesArray(array $files): array
    {
        if (isset($files['name']) && is_array($files['name'])) {
            $normalized = [];
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $normalized[] = [
                    'name' => $files['name'][$i] ?? '',
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $files['error'][$i] ?? UPLOAD_ERR_OK,
                    'size' => $files['size'][$i] ?? 0,
                ];
            }
            return $normalized;
        }

        return [$files];
    }

    public static function loadEnvConfig(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $env = [];
        $debugSteps = [];

        // 1) Leggi da config/.env (configurazione intranet standard)
        $configEnvFile = __DIR__ . '/../config/.env';
        if (is_file($configEnvFile)) {
            $count = 0;
            foreach (file($configEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                if (!isset($env[$k])) {
                    $env[$k] = $v;
                    $count++;
                }
            }
            $debugSteps[] = "config/.env loaded $count vars";
        } else {
            $debugSteps[] = 'config/.env NOT_FOUND';
        }

        // 4) Da $_ENV (variabili di ambiente del server)
        foreach ($_ENV as $k => $v) {
            if (!isset($env[$k])) {
                $env[$k] = $v;
            }
        }

        // 6) Da getenv() (priorità più alta, sovrascrive tutto)
        $envVars = [
            'AI_API_BASE', 'AI_API_KEY', 'AI_API_START_URL', 'AI_API_VERSION',
            'AI_AUTH_BEARER', 'AI_AUTH_BASIC', 'AI_DNS_RESOLVE',
            'AI_TLS_INSECURE', 'AI_FORCE_HOST_HEADER', 'AI_API_HOST',
            'APP_ENV', 'AI_NOTIFICATION_EMAIL'
        ];
        $getenvCount = 0;
        foreach ($envVars as $var) {
            $val = getenv($var);
            if ($val !== false) {
                $env[$var] = $val;
                $getenvCount++;
            } elseif (!isset($env[$var])) {
                $env[$var] = null;
            }
        }
        if ($getenvCount > 0) {
            $debugSteps[] = "getenv() loaded $getenvCount vars";
        }

        // Log solo se API non configurata (per debug)
        $finalApiBase = trim((string) ($env['AI_API_BASE'] ?? ''));
        $finalApiKey = trim((string) ($env['AI_API_KEY'] ?? ''));
        if ($finalApiBase === '' || $finalApiKey === '') {
            error_log('GareService::loadEnvConfig - API non configurata: ' . implode(' | ', $debugSteps));
        }

        return $cache = $env;
    }

    // ========== API PROXY ACTIONS ==========

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

    public static function apiHealth(): array
    {
        $env = self::expandEnvPlaceholders(self::loadEnvConfig());
        $client = new \Services\AIextraction\ExternalApiClient($env);
        return $client->healthCheck();
    }

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

    public static function listBatchesAction(array $input): array
    {
        $status = !empty($input['status']) ? trim($input['status']) : null;
        $limit  = min(100, max(1, (int) ($input['limit'] ?? 20)));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $env = self::expandEnvPlaceholders(self::loadEnvConfig());
        $client = new \Services\AIextraction\ExternalApiClient($env);
        return $client->listBatches($status, $limit, $offset);
    }

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

    private static function defaultNotificationEmail(array $fields = []): string
    {
        if (!empty($fields['notification_email']) && is_string($fields['notification_email'])) {
            return trim($fields['notification_email']);
        }
        $env = self::expandEnvPlaceholders(self::loadEnvConfig());
        if (!empty($env['AI_NOTIFICATION_EMAIL'])) {
            return $env['AI_NOTIFICATION_EMAIL'];
        }
        return 'noreply@piattaforma-bandi.local';
    }

    private static function saveUploadedPdf(array $file, ?string $forcedSha = null): array
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error');
        }
        $name = $file['name'];
        $tmp = $file['tmp_name'];
        $type = mime_content_type($tmp) ?: 'application/pdf';
        if ($type !== 'application/pdf') {
            throw new \RuntimeException('Solo PDF');
        }
        $bytes = filesize($tmp);
        $sha = $forcedSha ?: hash_file('sha256', $tmp);
        $dir = self::storagePath('pdf_ai/' . date('Y/m/d'));

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true)) {
                $errorMsg = error_get_last()['message'] ?? 'Unknown error';
                throw new \RuntimeException('Impossibile creare directory di storage: ' . $errorMsg);
            }
            @chmod($dir, 0775);
        }

        if (!is_writable($dir)) {
            @chmod($dir, 0775);
            if (!is_writable($dir)) {
                throw new \RuntimeException('Directory di storage non scrivibile: ' . $dir);
            }
        }

        $safe = preg_replace('/[^\w\-.]+/u', '_', $name);
        $dest = $dir . '/' . $sha . '_' . $safe;

        $error = null;
        set_error_handler(function ($errno, $errstr) use (&$error) {
            $error = $errstr;
            return true;
        });

        $moved = @move_uploaded_file($tmp, $dest);

        restore_error_handler();

        if (!$moved) {
            $lastError = error_get_last();
            $errorMsg = $error ?? ($lastError['message'] ?? 'Move failed');
            throw new \RuntimeException('Move failed: ' . $errorMsg);
        }

        return [
            'original_name' => $name,
            'mime_type' => $type,
            'size_bytes' => $bytes,
            'rel_path' => self::relativeStoragePath($dest),
            'absolute_path' => $dest,
            'sha256' => $sha,
        ];
    }

    private static function deleteStoredFile(string $relPath): void
    {
        if ($relPath === '') {
            return;
        }
        $fullPath = self::absoluteStoragePath($relPath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    private static function storagePath(string $suffix = ''): string
    {
        return rtrim(self::storageBase() . '/' . ltrim($suffix, '/'), '/');
    }

    private static function storageBase(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }

        $root = dirname(__DIR__);
        $uploads = $root . '/uploads';
        if (!is_dir($uploads)) {
            @mkdir($uploads, 0775, true);
        }
        $base = $uploads . '/gare_ai';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        return $base;
    }

    private static function relativeStoragePath(string $absolute): string
    {
        $base = str_replace('\\', '/', rtrim(self::storageBase(), '/\\')) . '/';
        $path = str_replace('\\', '/', $absolute);
        if (strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }
        return ltrim($path, '/');
    }

    private static function absoluteStoragePath(string $rel): string
    {
        return rtrim(self::storageBase(), '/') . '/' . ltrim($rel, '/');
    }

    private static function logBatchResults(string $batchId, $body): void
    {
        try {
            $logDir = self::storagePath('logs');
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }
            $logLine = sprintf(
                "[%s] batch_id=%s\n%s\n\n",
                date('c'),
                $batchId,
                json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            @file_put_contents($logDir . '/batch_results.log', $logLine, FILE_APPEND);
        } catch (\Throwable $e) {
            error_log('GareService log error: ' . $e->getMessage());
        }
    }

    private static function logBatchResultsError(string $batchId, $response): void
    {
        try {
            $logDir = self::storagePath('logs');
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }
            $logLine = sprintf(
                "[%s] batch_id=%s error_http=%s body=%s\n",
                date('c'),
                $batchId,
                (string) ($response['status'] ?? 'n/a'),
                isset($response['raw']) ? (string) $response['raw'] : json_encode($response, JSON_UNESCAPED_UNICODE)
            );
            @file_put_contents($logDir . '/batch_results.log', $logLine, FILE_APPEND);
        } catch (\Throwable $e) {
            error_log('GareService log error (results error): ' . $e->getMessage());
        }
    }

    private static function logBatchStatus(int $jobId, string $batchId, array $statusRes): void
    {
        try {
            $logDir = self::storagePath('logs');
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }
            $logLine = sprintf(
                "[%s] job_id=%d batch_id=%s http=%s body=%s\n",
                date('c'),
                $jobId,
                $batchId,
                (string) ($statusRes['status'] ?? 'n/a'),
                json_encode($statusRes['body'] ?? null, JSON_UNESCAPED_UNICODE)
            );
            @file_put_contents($logDir . '/batch_status.log', $logLine, FILE_APPEND);
        } catch (\Throwable $e) {
            error_log('GareService status log error: ' . $e->getMessage());
        }
    }

    private static function logAnalyzeResponse(int $jobId, array $resp): void
    {
        try {
            $logDir = self::storagePath('logs');
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }
            $logLine = sprintf(
                "[%s] job_id=%d analyze status=%s body=%s\n",
                date('c'),
                $jobId,
                (string) ($resp['status'] ?? 'n/a'),
                json_encode($resp['body'] ?? $resp, JSON_UNESCAPED_UNICODE)
            );
            @file_put_contents($logDir . '/analyze.log', $logLine, FILE_APPEND);
        } catch (\Throwable $e) {
            error_log('GareService analyze log error: ' . $e->getMessage());
        }
    }

    private static function finalizeJob(int $jobId): void
    {
        try {
            global $database;
            $pdo = $database->connection;

            // Assicurati che il job abbia status_id (default 1 = In valutazione)
            $stmt = $pdo->prepare("SELECT status_id FROM ext_jobs WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $jobId]);
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($job && (!isset($job['status_id']) || $job['status_id'] === null)) {
                $pdo->prepare("UPDATE ext_jobs SET status_id = 1 WHERE id = :id")->execute([':id' => $jobId]);
            }

            // Non serve più chiamare createPlaceholderFromJob o promoteExtractionToGara
            // I dati vengono estratti dinamicamente da ext_extractions
        } catch (\Throwable $e) {
            error_log('GareService finalizeJob error: ' . $e->getMessage());
        }
    }

    private static function ensureGaraColumns(\PDO $pdo): void
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

    private static function statusLabel(string $status): string
    {
        $map = [
            'queued' => 'In coda',
            'processing' => 'In elaborazione',
            'in_progress' => 'In elaborazione',
            'completed' => 'Completato',
            'done' => 'Completato',
            'failed' => 'Fallito',
            'error' => 'Errore',
        ];
        $status = strtolower($status);
        return $map[$status] ?? ucfirst($status ?: '—');
    }

    private static function extractDateValue($value): ?string
    {
        if (!$value) {
            return null;
        }
        if (is_array($value)) {
            // Nuova struttura: date con year, month, day direttamente
            if (isset($value['year']) && isset($value['month']) && isset($value['day'])) {
                $y = $value['year'];
                $m = $value['month'];
                $d = $value['day'];
                if ($y && $m && $d) {
                    return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
                }
            }
            // Struttura annidata: date.date
            if (isset($value['date']) && is_array($value['date'])) {
                $y = $value['date']['year'] ?? null;
                $m = $value['date']['month'] ?? null;
                $d = $value['date']['day'] ?? null;
                if ($y && $m && $d) {
                    return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
                }
            }
            if (isset($value['answer'])) {
                return \Services\AIextraction\ExtractionFormatter::extractDateValue($value['answer']);
            }
        }
        if (is_string($value)) {
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $value, $m)) {
                return $m[0];
            }
            if (preg_match('/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $value, $m)) {
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
                $year = $m[3];
                if (strlen($year) === 2) {
                    $year = '20' . $year;
                }
                return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
            }
        }
        return null;
    }

    private static function stringifyExtractionValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (array_key_exists('answer', $value)) {
                return \Services\AIextraction\ExtractionFormatter::stringifyExtractionValue($value['answer']);
            }
            if (array_key_exists('value', $value)) {
                return \Services\AIextraction\ExtractionFormatter::stringifyExtractionValue($value['value']);
            }
            if (isset($value['location']) && is_array($value['location'])) {
                return \Services\AIextraction\ExtractionFormatter::formatLocationValue($value['location']);
            }
            if (isset($value['url'])) {
                return (string) $value['url'];
            }
            if (isset($value['text']) && is_string($value['text'])) {
                return trim($value['text']);
            }
            if (isset($value['name']) && is_string($value['name'])) {
                return trim($value['name']);
            }
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? 'si' : 'no';
        }

        return trim((string) $value);
    }

    private static function formatDateDisplay($valueText, $decoded): ?string
    {
        $candidates = [];

        if (is_array($decoded)) {
            // Nuova struttura: data.date con year, month, day
            if (isset($decoded['date']) && is_array($decoded['date'])) {
                $date = $decoded['date'];
                $year = $date['year'] ?? null;
                $month = $date['month'] ?? null;
                $day = $date['day'] ?? null;
                if ($year && $month && $day) {
                    $hour = $date['hour'] ?? null;
                    $minute = $date['minute'] ?? null;
                    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    if ($hour !== null && $minute !== null) {
                        $dateStr .= sprintf(' %02d:%02d:00', $hour, $minute);
                    }
                    $candidates[] = $dateStr;
                } else {
                    $candidates[] = $decoded['date'];
                }
            }
            if (isset($decoded['answer'])) {
                $candidates[] = $decoded['answer'];
            }
            if (isset($decoded['value'])) {
                $candidates[] = $decoded['value'];
            }
        }

        if ($valueText !== null && $valueText !== '') {
            $candidates[] = $valueText;
        }

        foreach ($candidates as $candidate) {
            $iso = \Services\AIextraction\ExtractionFormatter::extractDateValue($candidate);
            if ($iso) {
                $dt = \DateTime::createFromFormat('Y-m-d', $iso);
                if ($dt instanceof \DateTime) {
                    return $dt->format('d-m-Y');
                }
            }

            if (is_string($candidate) && preg_match('/\d{4}-\d{2}-\d{2}/', $candidate)) {
                try {
                    $dateTime = new \DateTime($candidate);
                    return $dateTime->format('d-m-Y');
                } catch (\Exception $e) {
                    // ignore parse errors
                }
            }
        }

        return null;
    }

    private static function decodeExtractionJson($value): ?array
    {
        if (empty($value)) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private static function extractLocationData($decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['location']) && is_array($decoded['location'])) {
            return $decoded['location'];
        }

        if (isset($decoded['answer']) && is_array($decoded['answer']) && isset($decoded['answer']['location']) && is_array($decoded['answer']['location'])) {
            return $decoded['answer']['location'];
        }

        return null;
    }

    // Metodo rimosso: usa ExtractionFormatter::buildExtractionDisplay

    private static function normalizeDisplayCandidate($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'si' : 'no';
        }
        if (is_array($value)) {
            $string = \Services\AIextraction\ExtractionFormatter::stringifyExtractionValue($value);
            return \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($string);
        }
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private static function classifyExtractionPayload(
        ?string $typeCode,
        $initialDisplayValue,
        $decoded,
        ?string $rawText,
        ?string $rawJson,
        ?array $existingTable = null
    ): array {
        $display = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($initialDisplayValue);
        $state = $display !== null ? 'present' : 'no_data';
        $table = null;

        // NON costruire rawString da JSON grezzo - non deve essere usato come fallback
        // rawString viene usato solo per pattern matching specifici e brevi, non per JSON completo
        $rawString = null;
        // Usa solo rawText se è una stringa breve e non JSON
        if (is_string($rawText) && trim($rawText) !== '' && strlen(trim($rawText)) < 200 && !preg_match('/^\s*[\{\[]/', trim($rawText))) {
            $rawString = trim($rawText);
        }

        if ($state !== 'table' && is_array($decoded) && array_key_exists('bool_answer', $decoded)) {
            $bool = \Services\AIextraction\ExtractionFormatter::boolFromExtraction(['bool_answer' => $decoded['bool_answer']]);
            if ($bool !== null) {
                $display = $bool ? 'si' : 'no';
                $state = 'present';
            }
        }

        // Nuova struttura: gestisci sopralluogo_status
        if ($state !== 'table' && is_array($decoded) && isset($decoded['sopralluogo_status'])) {
            $status = $decoded['sopralluogo_status'];
            if ($status === 'required' || $status === 'obbligatorio') {
                $display = 'Sì';
            } elseif ($status === 'not_required' || $status === 'non_obbligatorio') {
                $display = 'No';
            } else {
                $display = ucfirst($status);
            }
            $state = 'present';
        }

        if ($state !== 'table' && $display === null && is_array($decoded) && isset($decoded['url']) && is_string($decoded['url']) && trim($decoded['url']) !== '') {
            $display = trim($decoded['url']);
            $state = 'present';
        }

        if ($state !== 'table' && is_array($decoded) && isset($decoded['location']['city'])) {
            $city = trim((string) $decoded['location']['city']);
            if ($city !== '') {
                $district = isset($decoded['location']['district']) ? trim((string) $decoded['location']['district']) : '';
                $display = $district !== '' ? sprintf('%s (%s)', $city, $district) : $city;
                $state = 'present';
            }
        }

        // PRIORITÀ 1: Cerca tabella prima di qualsiasi altra cosa (anche se ci sono citazioni)
        if ($state !== 'table' && is_array($decoded)) {
            // Cerca headers e rows direttamente in decoded
            $tableData = null;

            // Prima controlla direttamente in decoded
            if (isset($decoded['headers']) && is_array($decoded['headers'])) {
                // Accetta anche se rows è vuoto o non esiste (tabella vuota)
                $tableData = $decoded;
            }
            // Oppure cerca dentro answer (nuova struttura API)
            elseif (isset($decoded['answer'])) {
                if (is_array($decoded['answer']) && isset($decoded['answer']['headers']) && is_array($decoded['answer']['headers'])) {
                    // answer contiene direttamente headers
                    $tableData = $decoded['answer'];
                }
                // Se answer è una stringa vuota ma ci sono headers nello stesso livello, ignoriamo answer
                elseif (is_string($decoded['answer']) && $decoded['answer'] === '' && isset($decoded['headers']) && is_array($decoded['headers'])) {
                    // answer è vuoto ma headers esiste, usa decoded direttamente
                    $tableData = $decoded;
                }
            }

            if ($tableData !== null) {
                $table = $existingTable ?: self::buildTableFromAnswer($tableData, $typeCode);
                // Accetta anche tabelle vuote (solo headers, nessuna riga)
                if ($table !== null && isset($table['headers']) && is_array($table['headers']) && count($table['headers']) > 0) {
                    $state = 'table';
                    $display = '';
                }
            }
        }

        // Se non abbiamo ancora una tabella, controlla answer
        if ($state !== 'table' && $display === null && is_array($decoded) && isset($decoded['answer'])) {
            $displayCandidate = $decoded['answer'];
            // Usa answer solo se è una stringa non vuota e non ci sono headers (tabella ha priorità)
            if (!is_array($displayCandidate) && is_string($displayCandidate) && trim($displayCandidate) !== '' && !isset($decoded['headers'])) {
                $display = trim($displayCandidate);
                if (\Services\AIextraction\ExtractionFormatter::isDateType($typeCode)) {
                    $formatted = \Services\AIextraction\ExtractionFormatter::formatDateDisplay($rawText, $decoded);
                    if ($formatted !== null) {
                        $display = $formatted;
                    }
                }
                $state = 'present';
            }
        }

        // NON usare rawString/JSON grezzo come fallback - solo pattern specifici e brevi
        if ($state !== 'table' && $display === null && $rawString !== null && strlen($rawString) < 200) {
            // Solo pattern molto specifici e brevi (non JSON grezzo)
            if (stripos($rawString, 'Disciplinare di Gara') !== false) {
                $display = 'Vedi disciplinare di gara';
                $state = 'see_disciplinare';
            } elseif (
                preg_match('/Giorno\s+\.+\s+ore\s+\.+/i', $rawString)
                || stripos($rawString, 'verranno fissate durante la procedura telematica') !== false
                || stripos($rawString, 'verranno fissate durante la procedura') !== false
            ) {
                $display = 'Non definito nel bando (placeholder)';
                $state = 'placeholder';
            }
            // NON usare rawString se contiene JSON, chain_of_thought, reasoning, ecc.
            elseif (preg_match('/^\s*[\{\[]/', $rawString) || 
                    stripos($rawString, 'chain_of_thought') !== false ||
                    stripos($rawString, 'reasoning') !== false ||
                    stripos($rawString, 'empty_reason') !== false) {
                // Ignora completamente - non è una risposta pulita
            }
        }

        // Se non abbiamo ancora trovato nulla, imposta no_data
        if ($display === null && $state !== 'table') {
            $state = 'no_data';
        }

        if ($typeCode === 'fatturato_globale_n_minimo_anni') {
            $specialTable = self::buildFatturatoGlobaleTable($decoded, $initialDisplayValue, $table ?? $existingTable ?? null);
            if ($specialTable !== null) {
                $table = $specialTable;
                // Determina il titolo in base alla struttura (single_requirement o lot_requirements)
                $isSingleReq = false;
                if (is_array($decoded)) {
                    $turnoverReq = $decoded['turnover_requirement'] ?? $decoded['answer']['turnover_requirement'] ?? null;
                    if (is_array($turnoverReq) && isset($turnoverReq['applies_to']) && $turnoverReq['applies_to'] === 'single_requirement') {
                        $isSingleReq = true;
                    }
                }
                $display = $isSingleReq ? 'Requisito di fatturato globale' : 'Requisiti di fatturato per lotto';
                $state = 'table';
            }
        }

        if ($typeCode === 'requisiti_di_capacita_economica_finanziaria') {
            $econTable = self::buildCapacitaEconomicaTable($decoded, $initialDisplayValue, $table ?? $existingTable ?? null);
            if ($econTable !== null) {
                $table = $econTable;
                $display = 'Requisiti economico-finanziari';
                $state = 'table';
            }
        }

        // Gestione speciale per documentazione_richiesta_tecnica: usa documents array
        if ($typeCode === 'documentazione_richiesta_tecnica' && is_array($decoded)) {
            $documents = null;
            $documentsText = null;

            if (isset($decoded['documents']) && is_array($decoded['documents']) && count($decoded['documents']) > 0) {
                $documents = $decoded['documents'];
                $documentsText = $decoded['documents_text'] ?? null;
            }

            if ($documents !== null && is_array($documents) && count($documents) > 0) {
                // Costruisci una tabella dai documents (sovrascrive la tabella esistente se presente)
                $docTable = self::buildDocumentazioneTecnicaTable($documents, $decoded);
                if ($docTable !== null) {
                    $table = $docTable;
                    // Usa documents_text se disponibile, altrimenti titolo generico
                    if ($documentsText && is_string($documentsText) && trim($documentsText) !== '') {
                        $display = trim($documentsText);
                    } else {
                        $display = 'Documentazione tecnica richiesta';
                    }
                    $state = 'table';
                }
            }
        }

        // Gestione speciale per requisiti_tecnico_professionali: usa requirements array
        if ($typeCode === 'requisiti_tecnico_professionali' && is_array($decoded)) {
            $requirements = null;

            if (isset($decoded['requirements']) && is_array($decoded['requirements']) && count($decoded['requirements']) > 0) {
                $requirements = $decoded['requirements'];
            }

            if ($requirements !== null && is_array($requirements) && count($requirements) > 0) {
                // Costruisci una tabella dai requirements (sovrascrive la tabella esistente se presente)
                $reqTable = self::buildRequisitiTecnicoProfessionaliTable($requirements, $decoded);
                if ($reqTable !== null) {
                    $table = $reqTable;
                    $display = 'Requisiti tecnico-professionali';
                    $state = 'table';
                }
            }
        }

        // Gestione speciale per importi_opere_per_categoria_id_opere e tipi simili: usa entries array
        $importiTypes = [
            'importi_opere_per_categoria_id_opere',
            'importi_corrispettivi_categoria_id_opere'
        ];
        if (in_array($typeCode, $importiTypes, true) && is_array($decoded)) {
            $entries = null;
            $hasEntriesKey = false;

            // Cerca entries nella nuova struttura API (data.entries viene salvato come entries)
            if (isset($decoded['entries']) && is_array($decoded['entries'])) {
                $hasEntriesKey = true;
                if (count($decoded['entries']) > 0) {
                    $entries = $decoded['entries'];
                }
            }

            // Se entries esiste ma è vuoto, verifica se ci sono citazioni da mostrare
            if ($hasEntriesKey && (empty($entries) || count($entries) === 0)) {
                // Se ci sono citazioni, non impostare no_data ma lascia che il frontend gestisca le citazioni
                $hasCitations = false;
                if (is_array($decoded)) {
                    $hasCitations = !empty($decoded['citations']) && is_array($decoded['citations']) && count($decoded['citations']) > 0;
                }

                if (!$hasCitations) {
                    $display = 'Nessun dato disponibile per questa estrazione';
                    $state = 'no_data';
                } else {
                    // Ci sono citazioni, lascia che il frontend le mostri
                    // Non impostare display o state, verranno gestiti dal frontend
                }
            }
            // Se entries ha dati, costruisci la tabella
            elseif ($entries !== null && is_array($entries) && count($entries) > 0) {
                $importiTable = self::buildImportiTableFromEntries($entries, $typeCode);
                if ($importiTable !== null) {
                    $table = $importiTable;
                    $display = \Services\AIextraction\ExtractionFormatter::displayNameForExtractionType($typeCode) ?: 'Importi per categoria';
                    $state = 'table';
                }
            }
        }

        // Gestione speciale per importi_requisiti_tecnici_categoria_id_opere: usa requirements array
        if ($typeCode === 'importi_requisiti_tecnici_categoria_id_opere' && is_array($decoded)) {
            $requirements = null;

            if (isset($decoded['requirements']) && is_array($decoded['requirements']) && count($decoded['requirements']) > 0) {
                $requirements = $decoded['requirements'];
            }

            if ($requirements !== null && is_array($requirements) && count($requirements) > 0) {
                // Costruisci una tabella dai requirements
                $reqTable = self::buildRequisitiTecniciTableFromRequirements($requirements);
                if ($reqTable !== null) {
                    $table = $reqTable;
                    $display = 'Importi requisiti tecnici per categoria';
                    $state = 'table';
                }
            }
        }

        // Gestione speciale per requisiti_idoneita_professionale_gruppo_lavoro: usa requirements e roles arrays
        // Priorità: requirements/roles hanno la precedenza anche se esiste già una tabella (potrebbe essere incompleta)
        if ($typeCode === 'requisiti_idoneita_professionale_gruppo_lavoro') {
            // Cerca requirements e roles in varie posizioni possibili (l'API può salvare in modi diversi)
            $requirements = null;
            $roles = null;

            if (is_array($decoded)) {
                // Priorità 1: requirements e roles direttamente in decoded (il JSON salvato è già l'oggetto answer)
                if (isset($decoded['requirements']) && is_array($decoded['requirements']) && count($decoded['requirements']) > 0) {
                    $requirements = $decoded['requirements'];
                }
                if (isset($decoded['roles']) && is_array($decoded['roles']) && count($decoded['roles']) > 0) {
                    $roles = $decoded['roles'];
                }

                // Priorità 2: answer.requirements e answer.roles (se decoded contiene answer come oggetto annidato)
                if ($requirements === null && isset($decoded['answer']['requirements']) && is_array($decoded['answer']['requirements']) && count($decoded['answer']['requirements']) > 0) {
                    $requirements = $decoded['answer']['requirements'];
                }
                if ($roles === null && isset($decoded['answer']['roles']) && is_array($decoded['answer']['roles']) && count($decoded['answer']['roles']) > 0) {
                    $roles = $decoded['answer']['roles'];
                }
            }

            if ($requirements !== null && is_array($requirements) && count($requirements) > 0) {
                // Costruisci una tabella dai requirements e roles (sovrascrive la tabella esistente se presente)
                $idTable = self::buildIdoneitaProfessionaleTableFromStructured($requirements, $roles, $decoded);
                if ($idTable !== null) {
                    $table = $idTable;
                    $display = 'Requisiti professionali del gruppo';
                    $state = 'table';
                }
            }

            // FALLBACK: Se non abbiamo trovato requirements, usa la funzione esistente
            if ($table === null || $state !== 'table') {
                $professionTable = self::buildIdoneitaProfessionaleTable($decoded, $initialDisplayValue, $table ?? $existingTable ?? null);
                if ($professionTable !== null) {
                    $table = $professionTable;
                    $display = 'Requisiti professionali del gruppo';
                    $state = 'table';
                }
            }
        }

        if ($display === null) {
            if ($state === 'see_disciplinare') {
                $display = 'Vedi disciplinare di gara';
            } elseif ($state === 'placeholder') {
                $display = 'Non definito nel bando (placeholder)';
            } elseif ($state === 'no_data') {
                $display = 'Dato non presente nel bando';
            }
        }

        return [
            'display' => $display,
            'state' => $state,
            'table' => $table,
        ];
    }

    private static function buildCapacitaEconomicaTable($decoded, $initialDisplayValue, $currentTable = null): ?array
    {
        if (is_array($decoded)) {
            // Cerca requirements nella nuova struttura API
            $requirements = null;

            if (isset($decoded['requirements']) && is_array($decoded['requirements']) && count($decoded['requirements']) > 0) {
                $requirements = $decoded['requirements'];
            }

            // PRIORITÀ 1: Se ci sono requirements dalla nuova struttura API, usa quelli (sovrascrive tabella esistente)
            if ($requirements !== null) {
                $table = self::buildCapacitaTableFromStructured($requirements);
                if ($table !== null && !empty($table['rows'])) {
                    return $table;
                }
            }

            // Se non ci sono requirements, non costruire la tabella
            if ($requirements === null) {
                return null;
            }

            // Costruisci tabella da requirements strutturati
            $table = self::buildCapacitaTableFromStructured($requirements);
            if ($table !== null && !empty($table['rows'])) {
                return $table;
            }
        }

        return null;
    }

    private static function buildCapacitaTableFromStructured(array $requirements): ?array
    {
        $rows = [];

        foreach ($requirements as $item) {
            if (!is_array($item)) {
                continue;
            }

            // Nuova struttura API: requirements array con oggetti requirement
            $label = isset($item['label']) ? (string) $item['label'] : null;
            $requirementText = $item['requirement_text'] ?? null;

            // Gestisci minimum_amount (può essere array di oggetti o valore singolo)
            $minimumAmounts = [];
            if (isset($item['minimum_amount'])) {
                if (is_array($item['minimum_amount'])) {
                    // Nuova struttura: array di oggetti con raw, value, description, category
                    foreach ($item['minimum_amount'] as $amt) {
                        if (is_array($amt)) {
                            $raw = $amt['raw'] ?? null;
                            $value = $amt['value'] ?? null;
                            $description = $amt['description'] ?? null;
                            $category = $amt['category'] ?? null;

                            $amountStr = $raw ?? \Services\AIextraction\ExtractionFormatter::formatEuroAmount($value);
                            if ($category && is_array($category)) {
                                $catId = $category['id'] ?? null;
                                $catName = $category['name'] ?? null;
                                if ($catId && $catName) {
                                    $amountStr .= " ({$catId}: {$catName})";
                                } elseif ($catId) {
                                    $amountStr .= " ({$catId})";
                                }
                            }
                            if ($description && $description !== ($raw ?? '')) {
                                $amountStr .= " - {$description}";
                            }

                            if ($amountStr) {
                                $minimumAmounts[] = $amountStr;
                            }
                        } else {
                            // Valore singolo (retrocompatibilità)
                            $formatted = \Services\AIextraction\ExtractionFormatter::formatEuroAmount($amt);
                            if ($formatted) {
                                $minimumAmounts[] = $formatted;
                            }
                        }
                    }
                } else {
                    // Valore singolo (retrocompatibilità)
                    $formatted = \Services\AIextraction\ExtractionFormatter::formatEuroAmount($item['minimum_amount']);
                    if ($formatted) {
                        $minimumAmounts[] = $formatted;
                    }
                }
            }

            // Formula (nuova struttura: oggetto con multiplier, base_reference, ecc.)
            $formulaStr = null;
            if (isset($item['formula']) && is_array($item['formula'])) {
                $multiplier = $item['formula']['multiplier'] ?? null;
                $baseRef = $item['formula']['base_reference'] ?? null;
                if ($multiplier !== null && $baseRef !== null) {
                    $formulaStr = "Moltiplicatore: {$multiplier}x ({$baseRef})";
                } elseif (isset($item['formula']['description'])) {
                    $formulaStr = $item['formula']['description'];
                }
            } elseif (isset($item['formula']) && is_string($item['formula'])) {
                $formulaStr = $item['formula'];
            }

            // Timeframe (nuova struttura: oggetto strutturato)
            $timeframeStr = null;
            if (isset($item['timeframe']) && is_array($item['timeframe'])) {
                $tf = $item['timeframe'];
                $selectedCount = $tf['selected_count'] ?? null;
                $totalWindow = $tf['total_window'] ?? null;
                $unit = $tf['unit'] ?? 'anni';
                $selectionMethod = $tf['selection_method'] ?? null;

                if ($selectedCount && $totalWindow) {
                    if ($selectionMethod === 'best_of') {
                        $timeframeStr = "Migliori {$selectedCount} di {$totalWindow} {$unit}";
                    } elseif ($selectionMethod === 'recent') {
                        $timeframeStr = "Ultimi {$totalWindow} {$unit}";
                    } else {
                        $timeframeStr = "{$selectedCount} di {$totalWindow} {$unit}";
                    }
                } elseif ($totalWindow) {
                    $timeframeStr = "{$totalWindow} {$unit}";
                }
            } elseif (isset($item['timeframe']) && is_string($item['timeframe'])) {
                $timeframeStr = $item['timeframe'];
            }

            // RTI allocation (nuova struttura: oggetto con distribution_rules)
            $rtiStr = null;
            if (isset($item['rti_allocation']) && is_array($item['rti_allocation'])) {
                $rti = $item['rti_allocation'];
                if (isset($rti['distribution_rules']) && is_string($rti['distribution_rules'])) {
                    $rtiStr = $rti['distribution_rules'];
                } elseif (isset($rti['type']) && $rti['type']) {
                    $rtiStr = "Tipo: {$rti['type']}";
                }
            } elseif (isset($item['rti_allocation']) && is_string($item['rti_allocation'])) {
                $rtiStr = $item['rti_allocation'];
            }

            // Calculation rule
            $calculationRule = isset($item['calculation_rule']) && is_string($item['calculation_rule'])
                ? trim($item['calculation_rule'])
                : null;

            // Costruisci il titolo del requisito
            $rowTitle = $label ? "Requisito {$label}" : 'Requisito';

            // Costruisci i dettagli combinando tutti gli elementi
            $detailParts = [];

            // Importi minimi (possono esserci più importi per requirement)
            if (!empty($minimumAmounts)) {
                $amountsText = implode('; ', $minimumAmounts);
                $detailParts[] = $amountsText;
            }

            // Formula
            if ($formulaStr) {
                $detailParts[] = $formulaStr;
            }

            // Timeframe
            if ($timeframeStr) {
                $detailParts[] = "Periodo: {$timeframeStr}";
            }

            // Calculation rule
            if ($calculationRule) {
                $detailParts[] = $calculationRule;
            }

            // RTI
            if ($rtiStr) {
                $detailParts[] = "RTI: {$rtiStr}";
            }

            // Testo completo del requisito (se disponibile e non troppo lungo)
            if ($requirementText && strlen($requirementText) < 500) {
                $detailParts[] = $requirementText;
            }

            $rowDetail = implode(' | ', $detailParts);

            // Se non ci sono dettagli ma c'è requirement_text, usalo
            if ($rowDetail === '' && $requirementText) {
                $rowDetail = strlen($requirementText) > 300 ? substr($requirementText, 0, 300) . '...' : $requirementText;
            }

            if ($rowDetail === '') {
                continue;
            }

            $rows[] = [$rowTitle, $rowDetail];
        }

        if (!$rows) {
            return null;
        }

        return [
            'headers' => ['Requisito', 'Dettagli'],
            'rows' => $rows,
        ];
    }

    private static function buildCapacitaTableFromText(array $lines): ?array
    {
        if (!$lines) {
            return null;
        }

        $map = [
            'fatturato' => [
                'keywords' => ['fatturato', 'capacità economico-finanziaria'],
                'title' => 'Fatturato globale minimo',
            ],
            'servizi' => [
                'keywords' => [
                    'servizi di punta',
                    'servizi di ingegneria per opere analoghe',
                    'elenco di servizi di ingegneria',
                    'servizi di ingegneria e di architettura'
                ],
                'title' => 'Servizi di punta richiesti',
            ],
            'rti' => [
                'keywords' => ['raggruppamento', 'rti', 'mandataria', 'mandanti'],
                'title' => 'Regole RTI / Consorzi',
            ],
            'base' => [
                'keywords' => ['importo a base di gara'],
                'title' => 'Importo a base di gara',
            ],
        ];

        $collected = [];

        foreach ($lines as $line) {
            $normalized = mb_strtolower($line);
            foreach ($map as $key => $info) {
                foreach ($info['keywords'] as $kw) {
                    if (mb_strpos($normalized, mb_strtolower($kw)) !== false) {
                        $collected[$key] = trim(($collected[$key] ?? '') . ' ' . $line);
                        break 2;
                    }
                }
            }
        }

        if (!$collected) {
            return null;
        }

        $rows = [];
        foreach ($map as $key => $info) {
            if (empty($collected[$key])) {
                continue;
            }
            $detail = trim(preg_replace('/\s+/', ' ', $collected[$key]));

            // Estrai importo dal testo se presente
            $importo = '';
            if (preg_match('/(?:€|euro|EUR)\s*([0-9.,]+)/i', $detail, $matches)) {
                $importo = '€ ' . $matches[1];
            }

            // Determina tipo in base alla chiave
            $tipo = 'Servizi di Punta';
            if ($key === 'fatturato') {
                $tipo = 'Fatturato';
            } elseif ($key === 'rti') {
                $tipo = 'RTI';
            } elseif ($key === 'base') {
                $tipo = 'Base di Gara';
            }

            $rows[] = [
                $info['title'], // Categoria
                $detail, // Descrizione
                $importo !== '' ? $importo : '—', // Importo
                $tipo // Tipo
            ];
        }

        $categoryRows = self::extractCapacitaCategoryRows($lines);
        if ($categoryRows) {
            foreach ($categoryRows as $catRow) {
                $categoria = ($catRow['id'] ?? '') . ' – ' . ($catRow['category'] ?? '');
                $descrizione = sprintf(
                    'Valore opere %s; minimo richiesto %s',
                    $catRow['value'] ?? '',
                    $catRow['minimum'] ?? ''
                );
                $importo = $catRow['minimum'] ?? '—';

                $rows[] = [
                    $categoria !== '' ? $categoria : '—', // Categoria
                    $descrizione, // Descrizione
                    $importo !== '—' ? $importo : '—', // Importo
                    'Servizi di Punta' // Tipo
                ];
            }
        }

        if (!$rows) {
            return null;
        }

        return [
            'headers' => ['Categoria', 'Descrizione', 'Importo', 'Tipo'],
            'rows' => $rows,
            'type_code' => 'requisiti_di_capacita_economica_finanziaria',
            'keep_all_columns' => true,
        ];
    }

    private static function extractCapacitaCategoryRows(array $lines): array
    {
        $tokens = [];
        $capture = false;

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (!$capture) {
                if (preg_match('/^CATEGORIA\b/i', $trim)) {
                    $capture = true;
                }
                continue;
            }

            if (preg_match('/^(STAZIONE|IL REQUISITO|PER LA PECULIARITÀ|AI SENSI|Nota)/iu', $trim)) {
                break;
            }

            if (preg_match('/^[0-9]+\s*:/', $trim)) {
                break;
            }

            if (
                preg_match('/^[A-ZÀ-Ú][\p{L}\s\'’\.\-&,]+$/u', $trim)
                || preg_match('/^[A-Z0-9][A-Z0-9\.\-]*$/', $trim)
                || preg_match('/^[0-9\.,]+$/', $trim)
            ) {
                $tokens[] = $trim;
            }
        }

        $rows = [];
        for ($i = 0; $i + 3 < count($tokens); $i += 4) {
            $category = $tokens[$i] ?? null;
            $id = $tokens[$i + 1] ?? null;
            $value = $tokens[$i + 2] ?? null;
            $minimum = $tokens[$i + 3] ?? null;

            if ($category === null || $id === null || $value === null || $minimum === null) {
                continue;
            }

            $rows[] = [
                'category' => $category,
                'id' => $id,
                'value' => $value,
                'minimum' => $minimum,
            ];
        }

        return $rows;
    }

    private static function buildIdoneitaProfessionaleTableFromStructured(array $requirements, ?array $roles = null, array $decoded = []): ?array
    {
        if (empty($requirements) || !is_array($requirements)) {
            return null;
        }

        // Crea una mappa dei ruoli per facile accesso
        $rolesMap = [];
        if (is_array($roles) && !empty($roles)) {
            foreach ($roles as $role) {
                if (is_array($role) && isset($role['id']) && isset($role['name'])) {
                    $rolesMap[(int) $role['id']] = $role['name'];
                }
            }
        }

        $rows = [];

        foreach ($requirements as $req) {
            if (!is_array($req)) {
                continue;
            }

            $reqId = isset($req['id']) ? (int) $req['id'] : null;
            $originalText = isset($req['original_text']) && is_string($req['original_text']) ? trim($req['original_text']) : null;
            $appliesToRoleIds = isset($req['applies_to_role_ids']) && is_array($req['applies_to_role_ids']) ? $req['applies_to_role_ids'] : [];
            $appliesToAllRoles = isset($req['applies_to_all_roles']) ? (bool) $req['applies_to_all_roles'] : false;
            $isMandatory = isset($req['is_mandatory']) ? (bool) $req['is_mandatory'] : true;

            // Determina il ruolo o la descrizione
            $roleName = null;
            if ($appliesToAllRoles) {
                $roleName = 'Tutti i ruoli';
            } elseif (!empty($appliesToRoleIds)) {
                $roleNames = [];
                foreach ($appliesToRoleIds as $roleId) {
                    if (isset($rolesMap[(int) $roleId])) {
                        $roleNames[] = $rolesMap[(int) $roleId];
                    } else {
                        $roleNames[] = "Ruolo ID {$roleId}";
                    }
                }
                $roleName = implode(', ', $roleNames);
            } else {
                $roleName = 'Requisito generale';
            }

            // Costruisci i dettagli
            $detailParts = [];

            // Obbligatorio
            $detailParts[] = $isMandatory ? 'Obbligatorio: Sì' : 'Obbligatorio: No';

            // Qualifiche
            $qualifications = isset($req['qualifications']) && is_array($req['qualifications']) ? $req['qualifications'] : [];
            if (!empty($qualifications)) {
                $qualDesc = [];
                foreach ($qualifications as $qual) {
                    if (is_array($qual) && isset($qual['type']) && isset($qual['description'])) {
                        $typeLabel = ucfirst($qual['type']);
                        $qualDesc[] = "{$typeLabel}: {$qual['description']}";
                    }
                }
                if (!empty($qualDesc)) {
                    $detailParts[] = "Qualifiche: " . implode(' | ', $qualDesc);
                }
            }

            // Requisiti di esperienza
            $expReq = isset($req['experience_requirements']) && is_array($req['experience_requirements']) ? $req['experience_requirements'] : null;
            if ($expReq !== null) {
                $minYears = $expReq['minimum_years'] ?? null;
                if ($minYears !== null) {
                    $detailParts[] = "Esperienza minima: {$minYears} anni";
                    $measuredFrom = $expReq['measured_from'] ?? null;
                    if ($measuredFrom) {
                        $fromLabels = [
                            'role_practice_start' => 'dall\'inizio dell\'esercizio della professione',
                            'practice_start' => 'dall\'inizio dell\'esercizio',
                        ];
                        $fromLabel = $fromLabels[$measuredFrom] ?? $measuredFrom;
                        $detailParts[] = "Misurata: {$fromLabel}";
                    }
                }
                $expNotes = $expReq['notes'] ?? null;
                if ($expNotes) {
                    $detailParts[] = $expNotes;
                }
            }

            // Riferimenti normativi
            $legalCitations = isset($req['legal_citations']) && is_array($req['legal_citations']) ? $req['legal_citations'] : [];
            if (!empty($legalCitations)) {
                $detailParts[] = "Riferimenti normativi: " . implode(', ', $legalCitations);
            }

            // Vincoli di staff
            $staffingConstraints = isset($req['role_staffing_constraints']) && is_array($req['role_staffing_constraints']) ? $req['role_staffing_constraints'] : null;
            if ($staffingConstraints !== null) {
                $overlapAllowed = $staffingConstraints['overlap_allowed'] ?? null;
                if ($overlapAllowed === true) {
                    $detailParts[] = 'Sovrapposizione ruoli: consentita';
                }
                $constraintNotes = $staffingConstraints['notes'] ?? null;
                if ($constraintNotes) {
                    $detailParts[] = $constraintNotes;
                }
            }

            // Note aggiuntive
            $additionalNotes = isset($req['additional_notes']) && is_string($req['additional_notes']) ? trim($req['additional_notes']) : null;
            if ($additionalNotes) {
                $detailParts[] = $additionalNotes;
            }

            // Testo originale (se non troppo lungo)
            if ($originalText && strlen($originalText) < 300) {
                $detailParts[] = $originalText;
            } elseif ($originalText) {
                $detailParts[] = substr($originalText, 0, 300) . '...';
            }

            // Pagina di origine
            $sourceLocation = $req['source_location'] ?? null;
            if (is_array($sourceLocation) && isset($sourceLocation['page'])) {
                $page = $sourceLocation['page'];
                $section = $sourceLocation['section'] ?? null;
                $details = $sourceLocation['details'] ?? null;
                $pageInfo = "Pag. {$page}";
                if ($section) {
                    $pageInfo .= " (Sez. {$section})";
                }
                if ($details) {
                    $pageInfo .= " - {$details}";
                }
                $detailParts[] = $pageInfo;
            }

            $rowDetail = implode(' | ', $detailParts);

            // Se non ci sono dettagli ma c'è original_text, usalo
            if ($rowDetail === '' && $originalText) {
                $rowDetail = strlen($originalText) > 500 ? substr($originalText, 0, 500) . '...' : $originalText;
            }

            if ($rowDetail === '') {
                continue;
            }

            $rows[] = [$roleName, $rowDetail];
        }

        if (empty($rows)) {
            return null;
        }

        return [
            'headers' => ['Ruolo', 'Requisiti'],
            'rows' => $rows,
        ];
    }

    private static function buildIdoneitaProfessionaleTable($decoded, $initialDisplayValue, $currentTable = null): ?array
    {
        if ($currentTable && !empty($currentTable['rows'])) {
            return $currentTable;
        }

        $roles = [];
        $generalRules = [];

        if (is_array($decoded)) {
            if (!empty($decoded['citations']) && is_array($decoded['citations'])) {
                foreach ($decoded['citations'] as $citation) {
                    if (!isset($citation['text'])) {
                        continue;
                    }

                    $text = $citation['text'];
                    if (is_array($text)) {
                        self::parseProfessionalLines($text, $roles, $generalRules);
                    } elseif (is_string($text)) {
                        self::parseProfessionalLines([$text], $roles, $generalRules);
                    }
                }
            }

            if (!empty($decoded['general_rules']) && is_array($decoded['general_rules'])) {
                foreach ($decoded['general_rules'] as $rule) {
                    $ruleText = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($rule);
                    if ($ruleText !== null && $ruleText !== '') {
                        $generalRules[] = $ruleText;
                    }
                }
            }
        }

        $lineSources = [];
        if (is_string($initialDisplayValue) && trim($initialDisplayValue) !== '') {
            $lineSources[] = trim($initialDisplayValue);
        }
        if (!empty($decoded['answer'])) {
            if (is_string($decoded['answer'])) {
                $lineSources[] = $decoded['answer'];
            } elseif (is_array($decoded['answer'])) {
                foreach ($decoded['answer'] as $value) {
                    if (is_string($value)) {
                        $lineSources[] = $value;
                    }
                }
            }
        }

        foreach ($lineSources as $line) {
            self::parseProfessionalLines([$line], $roles, $generalRules);
        }

        if (!$roles) {
            return null;
        }

        $rows = [];
        foreach ($roles as $roleName => $parts) {
            $detail = implode(' — ', array_unique($parts));
            $rows[] = [$roleName, $detail !== '' ? $detail : '—'];
        }

        if ($generalRules) {
            $rows[] = [
                'Note generali',
                implode("\n", array_unique($generalRules)),
            ];
        }

        return [
            'headers' => ['Figura professionale', 'Requisiti'],
            'rows' => $rows,
        ];
    }

    private static function parseProfessionalLines(array $lines, array &$roles, array &$generalRules): void
    {
        $currentRole = null;

        foreach ($lines as $line) {
            $normalized = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($line);
            if ($normalized === null || $normalized === '') {
                continue;
            }

            $lineLower = mb_strtolower($normalized);

            if (preg_match('/^figura\s+richiesta/i', $normalized)) {
                $currentRole = null;
                continue;
            }

            if (preg_match('/ingegnere\/architetto\s+responsabile dell\'esecuzione per la categoria\s+([A-Z0-9.\-]+)/iu', $normalized, $matches)) {
                $code = $matches[1] ?? '';
                $roleName = 'Ingegnere/Architetto responsabile per categoria ' . $code;
                $currentRole = $roleName;
                self::appendRoleDetail($roles, $roleName, $normalized);
                continue;
            }

            if (preg_match('/^il\s+"?gruppo di lavoro/i', $normalized)) {
                continue;
            }

            if (preg_match('/^professionista/i', $normalized) && mb_strpos($lineLower, 'incarico') !== false) {
                $currentRole = 'Professionista responsabile dell\'incarico';
                self::appendRoleDetail($roles, $currentRole, $normalized);
                continue;
            }

            if (preg_match('/project manager|bim manager/i', $normalized)) {
                $currentRole = 'Coordinatore della progettazione / BIM Manager';
                self::appendRoleDetail($roles, $currentRole, $normalized);
                continue;
            }

            if (preg_match('/coordinatore della sicurezza/i', $normalized)) {
                $currentRole = 'Coordinatore sicurezza in progettazione';
                self::appendRoleDetail($roles, $currentRole, $normalized);
                continue;
            }

            if (preg_match('/tecnico responsabile della progettazione degli impianti/i', $normalized)) {
                $currentRole = 'Tecnico responsabile impianti (IA.01-IA.03)';
                self::appendRoleDetail($roles, $currentRole, $normalized);
                continue;
            }

            if (preg_match('/tecnico competente in acustica/i', $normalized)) {
                $currentRole = 'Tecnico competente in acustica';
                self::appendRoleDetail($roles, $currentRole, $normalized);
                continue;
            }

            if (preg_match('/professionista antincendio/i', $normalized)) {
                $currentRole = 'Professionista antincendio';
                self::appendRoleDetail($roles, $currentRole, $normalized);
                continue;
            }

            if (preg_match('/giovane professionista/i', $normalized)) {
                $currentRole = 'Giovane professionista';
                self::appendRoleDetail($roles, $currentRole, $normalized);
                continue;
            }

            if ($currentRole !== null) {
                self::appendRoleDetail($roles, $currentRole, $normalized);
                continue;
            }

            if (mb_strpos($lineLower, 'è possibile che') !== false && mb_strpos($lineLower, 'coincidano') !== false) {
                $generalRules[] = $normalized;
            }
        }
    }

    private static function appendRoleDetail(array &$roles, string $roleName, string $detail): void
    {
        if (!isset($roles[$roleName])) {
            $roles[$roleName] = [];
        }
        if (!in_array($detail, $roles[$roleName], true)) {
            $roles[$roleName][] = $detail;
        }
    }

    private static function compareExtractionSort(array $a, array $b): int
    {
        $orderA = \Services\AIextraction\ExtractionFormatter::sortKeyForType($a['tipo'] ?? null);
        $orderB = \Services\AIextraction\ExtractionFormatter::sortKeyForType($b['tipo'] ?? null);
        if ($orderA === $orderB) {
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        }
        return $orderA <=> $orderB;
    }

    private static function compareExtractionSortRow(array $a, array $b): int
    {
        $typeA = $a['type_code'] ?? ($a['type'] ?? null);
        $typeB = $b['type_code'] ?? ($b['type'] ?? null);
        $orderA = \Services\AIextraction\ExtractionFormatter::sortKeyForType($typeA);
        $orderB = \Services\AIextraction\ExtractionFormatter::sortKeyForType($typeB);
        if ($orderA === $orderB) {
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        }
        return $orderA <=> $orderB;
    }

    // Metodo rimosso: usa ExtractionFormatter::sortKeyForType


    private static function buildFatturatoGlobaleTable($decoded, $initialDisplayValue, $currentTable = null): ?array
    {
        if (is_array($decoded)) {
            // PRIORITÀ 1: Cerca turnover_requirement dalla nuova struttura API
            $turnoverReq = null;

            // Priorità 1.1: turnover_requirement direttamente in decoded (JSON salvato è già l'oggetto answer)
            if (isset($decoded['turnover_requirement']) && is_array($decoded['turnover_requirement'])) {
                $turnoverReq = $decoded['turnover_requirement'];
            }
            // Priorità 1.2: answer.turnover_requirement (se decoded contiene answer come oggetto annidato)
            elseif (isset($decoded['answer']['turnover_requirement']) && is_array($decoded['answer']['turnover_requirement'])) {
                $turnoverReq = $decoded['answer']['turnover_requirement'];
            }

            if ($turnoverReq !== null) {
                // Gestisci single_requirement (unico requisito, non per lotti)
                if (isset($turnoverReq['applies_to']) && $turnoverReq['applies_to'] === 'single_requirement') {
                    if (isset($turnoverReq['single_requirement']) && is_array($turnoverReq['single_requirement'])) {
                        $singleReq = $turnoverReq['single_requirement'];
                        $table = self::buildFatturatoTableFromSingleRequirement($singleReq);
                        if ($table !== null) {
                            return $table;
                        }
                    }
                }

                // Gestisci lot_requirements (requisiti per ogni lotto)
                if (isset($turnoverReq['lot_requirements']) && is_array($turnoverReq['lot_requirements']) && count($turnoverReq['lot_requirements']) > 0) {
                    $table = self::buildFatturatoTableFromRequirements($turnoverReq['lot_requirements']);
                    if ($table !== null) {
                        return $table;
                    }
                }
            }

            // FALLBACK: Cerca lot_requirements direttamente (vecchia struttura)
            if (!empty($decoded['lot_requirements']) && is_array($decoded['lot_requirements'])) {
                $table = self::buildFatturatoTableFromRequirements($decoded['lot_requirements']);
                if ($table !== null) {
                    return $table;
                }
            }
        }

        if (is_array($decoded) && isset($decoded['answer']) && is_string($decoded['answer'])) {
            $table = self::parseFatturatoString($decoded['answer']);
            if ($table !== null) {
                return $table;
            }
        }

        if (is_string($initialDisplayValue) && trim($initialDisplayValue) !== '') {
            $table = self::parseFatturatoString($initialDisplayValue);
            if ($table !== null) {
                return $table;
            }
        }

        if ($currentTable && !empty($currentTable['rows'])) {
            return $currentTable;
        }

        return null;
    }

    private static function buildFatturatoTableFromSingleRequirement(array $singleReq): ?array
    {
        // Estrai l'importo minimo
        $amount = \Services\AIextraction\ExtractionFormatter::formatEuroAmount(
            $singleReq['normalized_value'] ?? $singleReq['minimum_amount'] ?? null
        );
        if ($amount === null && isset($singleReq['minimum_amount'])) {
            $amount = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($singleReq['minimum_amount']);
        }
        if ($amount === null) {
            $amount = '—';
        }

        // Costruisci i dettagli
        $detailParts = [];

        // Regola di calcolo
        $calculationRule = isset($singleReq['calculation_rule']) && is_string($singleReq['calculation_rule'])
            ? trim($singleReq['calculation_rule'])
            : null;
        if ($calculationRule) {
            $detailParts[] = $calculationRule;
        }

        // Calcolo temporale
        $temporalCalc = $singleReq['temporal_calculation'] ?? null;
        if (is_array($temporalCalc)) {
            $periodsToSelect = $temporalCalc['periods_to_select'] ?? null;
            $lookbackWindow = $temporalCalc['lookback_window_years'] ?? null;
            $selectionMethod = $temporalCalc['selection_method'] ?? null;
            $periodType = $temporalCalc['period_type'] ?? null;
            $anchorDateRef = $temporalCalc['anchor_date_reference'] ?? null;

            $temporalDesc = [];
            if ($periodsToSelect !== null && $lookbackWindow !== null) {
                $methodLabel = ($selectionMethod === 'best') ? 'migliori' : (($selectionMethod === 'recent') ? 'ultimi' : '');
                $periodLabel = ($periodType === 'fiscal_years') ? 'esercizi' : 'anni';
                if ($methodLabel) {
                    $temporalDesc[] = "{$methodLabel} {$periodsToSelect} {$periodLabel} degli ultimi {$lookbackWindow}";
                } else {
                    $temporalDesc[] = "{$periodsToSelect} {$periodLabel} degli ultimi {$lookbackWindow}";
                }
            }
            if ($anchorDateRef) {
                $temporalDesc[] = $anchorDateRef;
            }
            if (!empty($temporalDesc)) {
                $detailParts[] = 'Periodo: ' . implode(' — ', $temporalDesc);
            }
        }

        // Metodo di calcolo
        $calculationMethod = $singleReq['calculation_method'] ?? null;
        if ($calculationMethod) {
            $methodLabels = [
                'cumulative_total' => 'Totale cumulativo',
                'average' => 'Media',
                'annual_minimum' => 'Minimo annuo',
            ];
            $methodLabel = $methodLabels[$calculationMethod] ?? ucfirst($calculationMethod);
            $detailParts[] = "Metodo: {$methodLabel}";
        }

        // Esclusione fiscale
        $taxExclusionScope = $singleReq['tax_exclusion_scope'] ?? null;
        $taxExclusionDetails = $singleReq['tax_exclusion_details'] ?? null;
        if ($taxExclusionDetails) {
            $detailParts[] = $taxExclusionDetails;
        } elseif ($taxExclusionScope) {
            $exclusionLabels = [
                'all_fiscal_and_pension' => 'Esclusa IVA ed oneri previdenziali',
                'vat_only' => 'Esclusa IVA',
                'none' => 'Nessuna esclusione',
            ];
            $exclusionLabel = $exclusionLabels[$taxExclusionScope] ?? ucfirst($taxExclusionScope);
            $detailParts[] = $exclusionLabel;
        }

        // Ambito servizio
        $serviceScopeType = $singleReq['service_scope_type'] ?? null;
        $serviceScopeDesc = $singleReq['service_scope_description'] ?? null;
        if ($serviceScopeDesc) {
            $detailParts[] = "Ambito: {$serviceScopeDesc}";
        } elseif ($serviceScopeType) {
            $scopeLabels = [
                'truly_global' => 'Fatturato globale',
                'service_specific' => 'Fatturato per servizio',
            ];
            $scopeLabel = $scopeLabels[$serviceScopeType] ?? ucfirst($serviceScopeType);
            $detailParts[] = "Ambito: {$scopeLabel}";
        }

        // Direzione soglia
        $thresholdDirection = $singleReq['threshold_direction_phrase'] ?? null;
        if ($thresholdDirection) {
            $detailParts[] = "Soglia: {$thresholdDirection}";
        }

        // Spiegazione importo (se presente)
        $amountExplanation = $singleReq['amount_explanation'] ?? null;
        if ($amountExplanation) {
            $detailParts[] = $amountExplanation;
        }

        $detail = implode(' | ', $detailParts);

        // Se non ci sono dettagli, usa la regola di calcolo o una descrizione generica
        if ($detail === '' && $calculationRule) {
            $detail = $calculationRule;
        } elseif ($detail === '') {
            $detail = 'Fatturato globale minimo richiesto';
        }

        $rows = [
            ['Lotto unico', $amount, $detail]
        ];

        return [
            'headers' => ['Lotto', 'Fatturato minimo', 'Dettagli'],
            'rows' => $rows,
        ];
    }

    private static function buildFatturatoTableFromRequirements(array $requirements): ?array
    {
        $rows = [];
        $details = [];

        foreach ($requirements as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $lotId = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($entry['lot_id'] ?? $entry['lot'] ?? $entry['id_lotto'] ?? null);
            if ($lotId === null || $lotId === '') {
                continue;
            }

            $amount = \Services\AIextraction\ExtractionFormatter::formatEuroAmount(
                $entry['minimum_amount_eur'] ?? $entry['minimum_amount'] ?? $entry['amount'] ?? $entry['minimum_amount_text'] ?? null
            );
            if ($amount === null && isset($entry['minimum_amount_formatted'])) {
                $amount = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($entry['minimum_amount_formatted']);
            }
            if ($amount === null) {
                $amount = '-';
            }

            $scope = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($entry['service_scope_description'] ?? null);
            $rule = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($entry['calculation_rule'] ?? null);
            $note = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($entry['notes'] ?? null);

            $detailParts = array_filter([$scope, $rule, $note], static function ($value) {
                return $value !== null && $value !== '';
            });
            $detail = implode(' — ', $detailParts);

            $rows[] = [$lotId, $amount, $detail];
            $details[] = $detail;
        }

        if (!$rows) {
            return null;
        }

        $hasDetails = array_filter($details, static function ($value) {
            return $value !== null && $value !== '';
        });

        if (!$hasDetails) {
            $rows = array_map(static function ($row) {
                return [$row[0], $row[1]];
            }, $rows);
            return [
                'headers' => ['Lotto', 'Fatturato minimo'],
                'rows' => $rows,
            ];
        }

        return [
            'headers' => ['Lotto', 'Fatturato minimo', 'Dettagli'],
            'rows' => $rows,
        ];
    }

    private static function parseFatturatoString(?string $text): ?array
    {
        if (!is_string($text)) {
            return null;
        }

        $clean = trim($text);
        if ($clean === '') {
            return null;
        }

        $clean = preg_replace('/^Lot Requirements:\s*/i', '', $clean);
        $segments = preg_split('/;\s*/', $clean);
        $rows = [];
        $details = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $parsedValue = null;
            if (preg_match('/\[Parsed:\s*([^\]]+)\]/i', $segment, $pm)) {
                $parsedValue = trim($pm[1]);
                $segment = preg_replace('/\[Parsed:[^\]]+\]/i', '', $segment);
            }

            if (preg_match('/^\s*(\d+)\s*:\s*([0-9\.\,\s€]+)(?:\s*\(([^)]*)\))?/u', $segment, $matches)) {
                $lot = trim($matches[1]);
                $amount = trim($matches[2]);
                $description = isset($matches[3]) ? trim($matches[3]) : '';

                $detailParts = [];
                if ($description !== '') {
                    $detailParts[] = $description;
                }
                if ($parsedValue !== null && $parsedValue !== '' && stripos($parsedValue, $amount) === false) {
                    $detailParts[] = $parsedValue;
                }

                $detail = implode(' — ', $detailParts);

                $rows[] = [$lot, $amount, $detail];
                $details[] = $detail;
            }
        }

        if (!$rows) {
            return null;
        }

        $hasDetails = array_filter($details, static function ($value) {
            return $value !== null && $value !== '';
        });

        if (!$hasDetails) {
            $rows = array_map(static function ($row) {
                return [$row[0], $row[1]];
            }, $rows);
            return [
                'headers' => ['Lotto', 'Fatturato minimo'],
                'rows' => $rows,
            ];
        }

        return [
            'headers' => ['Lotto', 'Fatturato minimo', 'Dettagli'],
            'rows' => $rows,
        ];
    }

    private static function formatEuroAmount($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_numeric($value)) {
            return '€ ' . number_format((float) $value, 2, ',', '.');
        }
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') {
                return null;
            }
            return $trim;
        }
        return null;
    }

    /**
     * Mappa una colonna del PDF a una colonna standard usando pattern fuzzy matching.
     * Usa matching più intelligente per trovare colonne anche con nomi molto diversi.
     * 
     * @param string $header Nome colonna dal PDF
     * @param array $columnMapping Mappa pattern => indice colonna standard
     * @return int|null Indice colonna standard o null se non trovato
     */
    private static function mapColumnToStandard(string $header, array $columnMapping): ?int
    {
        $headerNormalized = strtolower(trim($header));

        // Rimuovi caratteri speciali e normalizza spazi
        $headerClean = preg_replace('/[«»""\'()\[\]]/', '', $headerNormalized);
        $headerClean = preg_replace('/\s+/', ' ', $headerClean);
        $headerClean = trim($headerClean);

        // Rimuovi anche underscore e trattini per matching più flessibile
        $headerWords = preg_split('/[\s_\-]+/', $headerClean);
        $headerWords = array_filter($headerWords, function ($w) {
            return strlen($w) > 0; });

        // Cerca match esatto (con e senza caratteri speciali)
        if (isset($columnMapping[$headerNormalized])) {
            return $columnMapping[$headerNormalized];
        }
        if (isset($columnMapping[$headerClean])) {
            return $columnMapping[$headerClean];
        }

        // Cerca match parziale (contiene) - ordina per lunghezza pattern (più specifici prima)
        $patterns = array_keys($columnMapping);
        usort($patterns, function ($a, $b) {
            return strlen($b) - strlen($a); // Più lunghi prima
        });

        foreach ($patterns as $pattern) {
            $patternNormalized = strtolower(trim($pattern));
            $patternClean = preg_replace('/[«»""\'()\[\]]/', '', $patternNormalized);
            $patternClean = preg_replace('/\s+/', ' ', $patternClean);
            $patternClean = trim($patternClean);

            // Match esatto
            if ($headerNormalized === $patternNormalized || $headerClean === $patternClean) {
                return $columnMapping[$pattern];
            }

            // Match se il pattern è contenuto nell'header (pattern più specifico)
            if (strlen($patternNormalized) >= 3 && strpos($headerNormalized, $patternNormalized) !== false) {
                return $columnMapping[$pattern];
            }

            // Match se l'header è contenuto nel pattern (header più specifico)
            if (strlen($headerNormalized) >= 3 && strpos($patternNormalized, $headerNormalized) !== false) {
                return $columnMapping[$pattern];
            }

            // Match per parole chiave: se almeno 2 parole del pattern sono nell'header
            $patternWords = preg_split('/[\s_\-]+/', $patternClean);
            $patternWords = array_filter($patternWords, function ($w) {
                return strlen($w) > 2; }); // Solo parole > 2 caratteri
            if (count($patternWords) >= 2) {
                $matchedWords = 0;
                foreach ($patternWords as $pWord) {
                    foreach ($headerWords as $hWord) {
                        if (strpos($hWord, $pWord) !== false || strpos($pWord, $hWord) !== false) {
                            $matchedWords++;
                            break;
                        }
                    }
                }
                // Se almeno 2 parole corrispondono, considera un match
                if ($matchedWords >= 2) {
                    return $columnMapping[$pattern];
                }
            }
        }

        return null;
    }

    /**
     * Applica struttura standardizzata a una tabella estratta.
     * SEMPRE mostra tutte le colonne standard, anche se vuote.
     * 
     * @param array $headers Headers originali dal PDF
     * @param array $rows Righe originali dal PDF
     * @param string $typeCode Type code dell'estrazione
     * @return array|null Tabella normalizzata con struttura standard o null
     */
    private static function applyStandardTableStructure(array $headers, array $rows, string $typeCode): ?array
    {
        // Verifica se esiste una struttura standard per questo type_code
        if (!isset(self::STANDARD_TABLE_STRUCTURES[$typeCode])) {
            return null;
        }

        $structure = self::STANDARD_TABLE_STRUCTURES[$typeCode];
        $standardHeaders = $structure['headers'];
        $columnMapping = $structure['column_mapping'];

        // Crea mappa: indice colonna PDF => indice colonna standard
        // Supporta mapping multipli (più colonne PDF possono mappare alla stessa colonna standard)
        $pdfToStandardMap = [];
        $standardToPdfMap = []; // Mappa inversa per debug

        foreach ($headers as $pdfIdx => $pdfHeader) {
            $standardIdx = self::mapColumnToStandard($pdfHeader, $columnMapping);
            if ($standardIdx !== null) {
                $pdfToStandardMap[$pdfIdx] = $standardIdx;
                if (!isset($standardToPdfMap[$standardIdx])) {
                    $standardToPdfMap[$standardIdx] = [];
                }
                $standardToPdfMap[$standardIdx][] = $pdfIdx;
            }
        }

        // IMPORTANTE: Anche se non abbiamo mappato tutte le colonne, 
        // restituiamo sempre la struttura standard con tutte le colonne
        // Le colonne non mappate rimarranno vuote, ma la struttura sarà sempre completa

        // Crea array per colonne standard (inizialmente vuote)
        $standardColumnCount = count($standardHeaders);
        $standardRows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Inizializza riga standard con tutte le colonne vuote
            $standardRow = array_fill(0, $standardColumnCount, '');

            // Mappa i valori dalle colonne PDF alle colonne standard
            foreach ($pdfToStandardMap as $pdfIdx => $standardIdx) {
                if (isset($row[$pdfIdx])) {
                    $cell = $row[$pdfIdx];
                    $cellValue = '';

                    if (is_array($cell)) {
                        $cellValue = $cell['value'] ?? ($cell['raw'] ?? null);
                    } else {
                        $cellValue = $cell;
                    }

                    $normalizedValue = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($cellValue) ?? '';

                    // Se la colonna standard è già popolata, concatena (per gestire colonne duplicate)
                    if (!empty($standardRow[$standardIdx])) {
                        $standardRow[$standardIdx] .= ' | ' . $normalizedValue;
                    } else {
                        $standardRow[$standardIdx] = $normalizedValue;
                    }
                }
            }

            $standardRows[] = $standardRow;
        }

        // Se non ci sono righe, crea almeno una riga vuota per mostrare la struttura
        if (empty($standardRows)) {
            $standardRows = [array_fill(0, $standardColumnCount, '')];
        }

        if (isset(self::NORMATIVE_COLUMN_CONFIG[$typeCode])) {
            self::enrichRowsWithNormativeData(
                $standardRows,
                self::NORMATIVE_COLUMN_CONFIG[$typeCode]
            );
        }

        return [
            'headers' => $standardHeaders,
            'rows' => $standardRows,
            'type_code' => $typeCode,
            'keep_all_columns' => true,
        ];
    }

    /**
     * Arricchisce le righe standard con categoria/prestazione dalla tabella normativa.
     */
    private static function enrichRowsWithNormativeData(array &$standardRows, array $config): void
    {
        $database = $GLOBALS['database'] ?? null;
        $pdo = $database ? ($database->connection ?? null) : null;

        if (!$pdo) {
            return;
        }

        $idColumn = $config['id_column'] ?? 0;
        $categoryColumn = $config['category_column'] ?? null;
        $descriptionColumn = $config['description_column'] ?? null;
        $combineDescription = !empty($config['combine_description']);

        $codes = [];
        foreach ($standardRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = isset($row[$idColumn]) ? trim((string) $row[$idColumn]) : '';
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        if (empty($codes)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $pdo->prepare(
            "SELECT cod, categoria, prestazione
             FROM gar_prestazioni_normative
             WHERE cod IN ($placeholders)"
        );
        $stmt->execute(array_keys($codes));
        $normativeRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($normativeRows)) {
            return;
        }

        $normByCod = [];
        foreach ($normativeRows as $normRow) {
            $cod = isset($normRow['cod']) ? trim((string) $normRow['cod']) : '';
            if ($cod !== '') {
                $normByCod[$cod] = $normRow;
            }
        }

        if (empty($normByCod)) {
            return;
        }

        foreach ($standardRows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            $code = isset($row[$idColumn]) ? trim((string) $row[$idColumn]) : '';
            if ($code === '' || !isset($normByCod[$code])) {
                continue;
            }

            $norm = $normByCod[$code];
            $categoria = isset($norm['categoria']) ? trim((string) $norm['categoria']) : '';
            $prestazione = isset($norm['prestazione']) ? trim((string) $norm['prestazione']) : '';

            if ($categoryColumn !== null && array_key_exists($categoryColumn, $row) && $categoria !== '') {
                $row[$categoryColumn] = $categoria;
            }

            if ($descriptionColumn !== null && array_key_exists($descriptionColumn, $row)) {
                if ($combineDescription) {
                    $parts = [];
                    if ($categoria !== '') {
                        $parts[] = $categoria;
                    }
                    if ($prestazione !== '') {
                        $parts[] = $prestazione;
                    }
                    if (!empty($parts)) {
                        $row[$descriptionColumn] = implode(' - ', $parts);
                    }
                } elseif ($prestazione !== '') {
                    $row[$descriptionColumn] = $prestazione;
                }
            }
        }
        unset($row);
    }

    private static function buildTableFromAnswer($decoded, ?string $typeCode = null): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        // Gestisce sia la struttura diretta che quella annidata in 'answer'
        $headers = $decoded['headers'] ?? null;
        $rows = $decoded['rows'] ?? null;

        // Se non trovati direttamente, cerca in answer (nuova struttura API)
        if (!is_array($headers)) {
            if (isset($decoded['answer']) && is_array($decoded['answer'])) {
                $headers = $decoded['answer']['headers'] ?? null;
                $rows = $decoded['answer']['rows'] ?? null;
            }
        }

        // Requisito minimo: deve avere headers (rows può essere vuoto o null)
        if (!is_array($headers)) {
            return null;
        }

        // Se rows non è un array, usa un array vuoto (tabella vuota ma con headers)
        if (!is_array($rows)) {
            $rows = [];
        }

        // Se abbiamo un type_code e una struttura standardizzata, applicala SEMPRE
        // Questo garantisce che tutte le colonne standard vengano sempre mostrate
        if ($typeCode !== null && isset(self::STANDARD_TABLE_STRUCTURES[$typeCode])) {
            $standardizedTable = self::applyStandardTableStructure($headers, $rows, $typeCode);
            // Restituisci sempre la struttura standardizzata se esiste (anche se alcune colonne sono vuote)
            if ($standardizedTable !== null) {
                return $standardizedTable;
            }
        }

        // Comportamento standard per altri tipi (senza struttura standardizzata)
        $normalizedHeaders = [];
        foreach ($headers as $header) {
            $normalizedHeaders[] = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($header) ?? '';
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $normalizedRows[] = [\Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($row) ?? ''];
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                if (is_array($cell)) {
                    // La nuova struttura ha oggetti con 'value' e 'raw'
                    // Preferisce 'value' se disponibile, altrimenti 'raw'
                    $cellValue = $cell['value'] ?? ($cell['raw'] ?? null);
                    $cells[] = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($cellValue) ?? '';
                } else {
                    $cells[] = \Services\AIextraction\ExtractionFormatter::normalizeDisplayCandidate($cell) ?? '';
                }
            }
            $normalizedRows[] = $cells;
        }

        // Restituisce la tabella anche se è vuota (solo headers, nessuna riga)
        // Questo permette di visualizzare la struttura della tabella anche quando non ci sono dati
        return [
            'headers' => $normalizedHeaders,
            'rows' => $normalizedRows,
        ];
    }

    /**
     * Recupera dati da gar_opere_dm50 per id_opera
     * @param array $idOpereArray Array di id_opera da cercare
     * @return array Mappa id_opera => ['categoria' => ..., 'identificazione_opera' => ...]
     */
    private static function getOpereDm50Data(array $idOpereArray): array
    {
        global $database;
        $pdo = $database->connection;

        if (empty($idOpereArray)) {
            return [];
        }

        // Verifica che la tabella esista
        try {
            $tableExists = $pdo->query("SHOW TABLES LIKE 'gar_opere_dm50'");
            if (!$tableExists || $tableExists->rowCount() === 0) {
                return [];
            }
        } catch (\Exception $e) {
            return [];
        }

        // Pulisci e filtra gli id_opera validi
        $cleanIds = array_filter(array_map(function ($id) {
            return is_string($id) ? trim($id) : (is_numeric($id) ? (string) $id : null);
        }, $idOpereArray), function ($id) {
            return $id !== null && $id !== '';
        });

        if (empty($cleanIds)) {
            return [];
        }

        // Crea placeholders per la query IN
        $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
        $sql = "SELECT id_opera, categoria, identificazione_opera, complessita 
                FROM gar_opere_dm50 
                WHERE id_opera IN ($placeholders)";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($cleanIds);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $idOpera = $row['id_opera'] ?? '';
                if ($idOpera) {
                    $result[$idOpera] = [
                        'categoria' => $row['categoria'] ?? '—',
                        'identificazione_opera' => $row['identificazione_opera'] ?? '—',
                        'complessita' => $row['complessita'] ?? null
                    ];
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function buildImportiTableFromEntries(array $entries, string $typeCode): ?array
    {
        if (empty($entries) || !is_array($entries)) {
            return null;
        }

        // Struttura diversa per importi_opere_per_categoria_id_opere vs importi_corrispettivi_categoria_id_opere
        if ($typeCode === 'importi_corrispettivi_categoria_id_opere') {
            // Struttura per corrispettivi: ID Opere, Categorie, Descrizione, Grado di complessità, Importo del corrispettivo
            $headers = ['ID Opere', 'Categorie', 'Descrizione', 'Grado di complessità', 'Importo del corrispettivo'];
        } else {
            // Struttura per importi opere: ID Opere, Categoria, Descrizione, Importo stimato dei lavori
            $headers = ['ID Opere', 'Categoria', 'Descrizione', 'Importo stimato dei lavori'];
        }

        $rows = [];

        // Raccogli tutti gli id_opera per fare una query batch al DB
        $idOpereArray = [];
        foreach ($entries as $entry) {
            if (!is_array($entry))
                continue;
            $idOpera = $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? null;
            if ($idOpera) {
                $idOpereArray[] = $idOpera;
            }
        }

        // Recupera dati da gar_opere_dm50
        $opereDm50Data = self::getOpereDm50Data($idOpereArray);

        foreach ($entries as $entry) {
            if (!is_array($entry))
                continue;

            // ID Opera: usa id_opera_normalized (o id_opera_raw come fallback)
            $idOpera = $entry['id_opera_normalized'] ?? $entry['id_opera_raw'] ?? '—';

            // Categoria e Descrizione: da gar_opere_dm50
            $categoria = '—';
            $descrizione = '—';
            $complessita = '—';
            if ($idOpera !== '—' && isset($opereDm50Data[$idOpera])) {
                $categoria = $opereDm50Data[$idOpera]['categoria'] ?? '—';
                $descrizione = $opereDm50Data[$idOpera]['identificazione_opera'] ?? '—';
                $complessita = $opereDm50Data[$idOpera]['complessita'] ?? '—';
            }

            if ($typeCode === 'importi_corrispettivi_categoria_id_opere') {
                // Per corrispettivi: Grado di complessità dall'API (priorità) o da DB (fallback)
                $gradoComplessita = '—';
                if (isset($entry['complexity']) && ($entry['complexity'] !== null && $entry['complexity'] !== '')) {
                    $gradoComplessita = is_numeric($entry['complexity'])
                        ? number_format((float) $entry['complexity'], 2, ',', '.')
                        : (string) $entry['complexity'];
                } elseif (isset($entry['complessita']) && ($entry['complessita'] !== null && $entry['complessita'] !== '')) {
                    $gradoComplessita = is_numeric($entry['complessita'])
                        ? number_format((float) $entry['complessita'], 2, ',', '.')
                        : (string) $entry['complessita'];
                } elseif ($complessita !== '—') {
                    $gradoComplessita = $complessita;
                }

                // Importo del corrispettivo: usa corrispettivo_eur, amount_eur o amount_raw
                $importoCorrispettivo = '—';
                if (isset($entry['corrispettivo_eur']) && is_numeric($entry['corrispettivo_eur'])) {
                    $importoCorrispettivo = number_format((float) $entry['corrispettivo_eur'], 2, ',', '.') . ' €';
                } elseif (isset($entry['amount_eur']) && is_numeric($entry['amount_eur'])) {
                    $importoCorrispettivo = number_format((float) $entry['amount_eur'], 2, ',', '.') . ' €';
                } elseif (isset($entry['amount_raw']) && is_string($entry['amount_raw']) && trim($entry['amount_raw']) !== '') {
                    $importoCorrispettivo = trim($entry['amount_raw']);
                } elseif (isset($entry['corrispettivo_raw']) && is_string($entry['corrispettivo_raw']) && trim($entry['corrispettivo_raw']) !== '') {
                    $importoCorrispettivo = trim($entry['corrispettivo_raw']);
                }

                $rows[] = [$idOpera, $categoria, $descrizione, $gradoComplessita, $importoCorrispettivo];
            } else {
                // Per importi opere: Importo stimato dei lavori
                $amount = '—';
                if (isset($entry['amount_eur']) && is_numeric($entry['amount_eur'])) {
                    $amount = number_format((float) $entry['amount_eur'], 2, ',', '.') . ' €';
                } elseif (isset($entry['amount_raw']) && is_string($entry['amount_raw']) && trim($entry['amount_raw']) !== '') {
                    $amount = trim($entry['amount_raw']);
                }

                $rows[] = [$idOpera, $categoria, $descrizione, $amount];
            }
        }

        if (empty($rows)) {
            return null;
        }

        return [
            'headers' => $headers,
            'rows' => $rows
        ];
    }

    private static function buildRequisitiTecniciTableFromRequirements(array $requirements): ?array
    {
        if (empty($requirements) || !is_array($requirements)) {
            return null;
        }

        // Raccogli tutti gli id_opera per fare il lookup in gar_opere_dm50
        $idOpereArray = [];
        foreach ($requirements as $req) {
            if (!is_array($req))
                continue;
            $idOpera = $req['id_opera'] ?? null;
            if ($idOpera !== null && $idOpera !== '') {
                $idOpereArray[] = (string) $idOpera;
            }
        }

        // Recupera dati da gar_opere_dm50
        $opereDm50Data = self::getOpereDm50Data($idOpereArray);

        $headers = ['ID Opera', 'Categoria', 'Descrizione', 'Importo minimo'];
        $rows = [];

        foreach ($requirements as $req) {
            if (!is_array($req))
                continue;

            $id = $req['id_opera'] ?? '—';
            $idStr = (string) $id;

            // Recupera categoria e descrizione da gar_opere_dm50
            $cat = '—';
            $desc = '—';
            if ($idStr !== '' && isset($opereDm50Data[$idStr])) {
                $cat = $opereDm50Data[$idStr]['categoria'] ?? '—';
                $desc = $opereDm50Data[$idStr]['identificazione_opera'] ?? '—';
            }

            // Se non trovato in gar_opere_dm50, usa i valori dal JSON come fallback
            if ($cat === '—' && isset($req['category_name']) && $req['category_name'] !== '') {
                $cat = $req['category_name'];
            }
            if ($cat === '—' && isset($req['categoria']) && $req['categoria'] !== '—') {
                $cat = $req['categoria'];
            }
            if ($desc === '—' && isset($req['description']) && $req['description'] !== '') {
                $desc = $req['description'];
            }
            if ($desc === '—' && isset($req['description_translated']) && $req['description_translated'] !== '') {
                $desc = $req['description_translated'];
            }
            if ($desc === '—' && isset($req['descrizione']) && $req['descrizione'] !== '—') {
                $desc = $req['descrizione'];
            }

            $amount = '—';
            if (isset($req['minimum_amount_eur']) && is_numeric($req['minimum_amount_eur'])) {
                $amount = number_format((float) $req['minimum_amount_eur'], 2, ',', '.') . ' €';
            } elseif (isset($req['base_value_eur']) && is_numeric($req['base_value_eur'])) {
                $amount = number_format((float) $req['base_value_eur'], 2, ',', '.') . ' €';
            }

            $rows[] = [$idStr !== '' ? $idStr : '—', $cat, $desc, $amount];
        }

        if (empty($rows)) {
            return null;
        }

        return [
            'headers' => $headers,
            'rows' => $rows
        ];
    }

    private static function buildRequisitiTecnicoProfessionaliTable(array $requirements, array $decoded = []): ?array
    {
        if (empty($requirements) || !is_array($requirements)) {
            return null;
        }

        $rows = [];

        foreach ($requirements as $req) {
            if (!is_array($req)) {
                continue;
            }

            $id = isset($req['id']) ? (int) $req['id'] : null;
            $title = isset($req['title']) && is_string($req['title']) ? trim($req['title']) : null;
            $description = isset($req['description']) && is_string($req['description']) ? trim($req['description']) : null;
            $requirementType = isset($req['requirement_type']) ? (string) $req['requirement_type'] : null;
            $isMandatory = isset($req['is_mandatory']) ? (bool) $req['is_mandatory'] : false;
            $legalReference = isset($req['legal_reference']) && is_string($req['legal_reference']) ? trim($req['legal_reference']) : null;

            // Costruisci il titolo del requisito
            $reqTitle = $title ?: 'Requisito';
            if ($id !== null) {
                $reqTitle = "Requisito {$id}: {$reqTitle}";
            } else {
                $reqTitle = "Requisito: {$reqTitle}";
            }

            // Costruisci i dettagli combinando tutti gli elementi
            $detailParts = [];

            // Tipo di requisito (traduci in italiano se necessario)
            $typeLabels = [
                'registration' => 'Registrazione/Abilitazione',
                'team_composition' => 'Composizione del Team',
                'soa_qualification' => 'Qualificazione SOA',
                'certification' => 'Certificazione',
                'young_professional' => 'Giovane Professionista',
                'experience' => 'Esperienza',
                'technical_director' => 'Direttore Tecnico',
            ];
            $typeLabel = $typeLabels[$requirementType] ?? ucfirst($requirementType ?? '—');
            $detailParts[] = "Tipo: {$typeLabel}";

            // Obbligatorio
            $detailParts[] = $isMandatory ? 'Obbligatorio: Sì' : 'Obbligatorio: No';

            // Descrizione
            if ($description) {
                $detailParts[] = $description;
            }

            // Dettagli del team (se presente)
            if (!empty($req['team_details']) && is_array($req['team_details'])) {
                $teamDetails = $req['team_details'];
                $requiredRoles = $teamDetails['required_roles'] ?? null;
                if (is_array($requiredRoles) && !empty($requiredRoles)) {
                    $roleCount = count($requiredRoles);
                    $detailParts[] = "Composizione Team: {$roleCount} ruoli richiesti";
                    // Aggiungi i primi 3 ruoli come esempio
                    $roleTitles = [];
                    foreach (array_slice($requiredRoles, 0, 3) as $role) {
                        if (isset($role['role_title']) && is_string($role['role_title'])) {
                            $roleTitles[] = $role['role_title'];
                        }
                    }
                    if (!empty($roleTitles)) {
                        $detailParts[] = "Ruoli principali: " . implode(', ', $roleTitles);
                    }
                    if ($roleCount > 3) {
                        $remainingRoles = $roleCount - 3;
                        $detailParts[] = "... e altri {$remainingRoles} ruoli";
                    }
                }
            }

            // Dettagli esperienza (se presente)
            if (!empty($req['experience_details']) && is_array($req['experience_details'])) {
                $expDetails = $req['experience_details'];
                $timePeriod = $expDetails['time_period_years'] ?? null;
                $categories = $expDetails['categories'] ?? null;
                if ($timePeriod !== null) {
                    $detailParts[] = "Periodo di riferimento: {$timePeriod} anni";
                }
                if (is_array($categories) && !empty($categories)) {
                    $categoryCount = count($categories);
                    $detailParts[] = "Categorie richieste: {$categoryCount}";
                    // Aggiungi le prime 3 categorie come esempio
                    $categoryNames = [];
                    foreach (array_slice($categories, 0, 3) as $cat) {
                        if (isset($cat['category_code']) && isset($cat['category_name'])) {
                            $categoryNames[] = "{$cat['category_code']} ({$cat['category_name']})";
                        }
                    }
                    if (!empty($categoryNames)) {
                        $detailParts[] = "Esempi categorie: " . implode(', ', $categoryNames);
                    }
                    if ($categoryCount > 3) {
                        $remainingCategories = $categoryCount - 3;
                        $detailParts[] = "... e altre {$remainingCategories} categorie";
                    }
                }
            }

            // Dettagli giovane professionista (se presente)
            if (!empty($req['young_professional_details']) && is_array($req['young_professional_details'])) {
                $ypDetails = $req['young_professional_details'];
                $minimumCount = $ypDetails['minimum_count'] ?? null;
                $mustBeDesigner = $ypDetails['must_be_designer'] ?? null;
                if ($minimumCount !== null) {
                    $detailParts[] = "Giovane professionista: richiesto almeno {$minimumCount}";
                }
                if ($mustBeDesigner === true) {
                    $detailParts[] = "Deve essere progettista";
                }
            }

            // Dettagli SOA (se presente)
            if (!empty($req['soa_details']) && is_array($req['soa_details'])) {
                $soaDetails = $req['soa_details'];
                $categories = $soaDetails['categories'] ?? null;
                if (is_array($categories) && !empty($categories)) {
                    $categoryCodes = [];
                    foreach ($categories as $cat) {
                        if (isset($cat['category_code']) && is_string($cat['category_code'])) {
                            $categoryCodes[] = $cat['category_code'];
                        }
                    }
                    if (!empty($categoryCodes)) {
                        $detailParts[] = "Categorie SOA: " . implode(', ', $categoryCodes);
                    }
                }
            }

            // Riferimento normativo
            if ($legalReference) {
                $detailParts[] = "Riferimento normativo: {$legalReference}";
            }

            // Pagina di origine
            $sourceLocation = $req['source_location'] ?? null;
            if (is_array($sourceLocation) && isset($sourceLocation['page'])) {
                $page = $sourceLocation['page'];
                $section = $sourceLocation['section'] ?? null;
                $detailParts[] = "Pagina: {$page}" . ($section ? " (Sezione {$section})" : '');
            }

            $rowDetail = implode(' | ', $detailParts);

            // Se non ci sono dettagli ma c'è description, usalo
            if ($rowDetail === '' && $description) {
                $rowDetail = strlen($description) > 500 ? substr($description, 0, 500) . '...' : $description;
            }

            if ($rowDetail === '') {
                continue;
            }

            $rows[] = [$reqTitle, $rowDetail];
        }

        if (empty($rows)) {
            return null;
        }

        return [
            'headers' => ['Requisito', 'Dettagli'],
            'rows' => $rows,
        ];
    }

    private static function buildDocumentazioneTecnicaTable(array $documents, array $decoded = []): ?array
    {
        if (empty($documents) || !is_array($documents)) {
            return null;
        }

        // Header della tabella
        $headers = ['Documento', 'Tipo', 'Stato', 'Max Pagine', 'Formato', 'Pagina'];

        $rows = [];
        foreach ($documents as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $title = $doc['title'] ?? '—';
            $docType = $doc['document_type'] ?? '—';

            // Stato: obbligatorio, condizionale, opzionale
            $requirementStatus = $doc['requirement_status'] ?? '—';
            $statusLabel = '';
            if ($requirementStatus === 'obbligatorio') {
                $statusLabel = 'Obbligatorio';
            } elseif ($requirementStatus === 'condizionale') {
                $conditionalLogic = $doc['conditional_logic'] ?? null;
                if (is_array($conditionalLogic) && isset($conditionalLogic['description'])) {
                    $statusLabel = 'Condizionale: ' . $conditionalLogic['description'];
                } else {
                    $statusLabel = 'Condizionale';
                }
            } else {
                $statusLabel = ucfirst($requirementStatus ?? '—');
            }

            // Max pagine
            $maxPages = '—';
            if (isset($doc['formatting_requirements']['max_pages'])) {
                $maxPages = (string) $doc['formatting_requirements']['max_pages'];
            }

            // Formato (A4, A3, ecc.)
            $format = '—';
            $formattingReqs = $doc['formatting_requirements'] ?? null;
            if (is_array($formattingReqs)) {
                $pageSize = $formattingReqs['page_size'] ?? null;
                $fontSizeMin = $formattingReqs['typography']['font_size_min'] ?? null;
                if ($pageSize) {
                    $format = $pageSize;
                    if ($fontSizeMin) {
                        $format .= " (font min: {$fontSizeMin}pt)";
                    }
                }
            }

            // Pagina di origine
            $pageNum = '—';
            $sourceLocation = $doc['source_location'] ?? null;
            if (is_array($sourceLocation) && isset($sourceLocation['page'])) {
                $pageNum = (string) $sourceLocation['page'];
            }

            $rows[] = [$title, $docType, $statusLabel, $maxPages, $format, $pageNum];
        }

        if (empty($rows)) {
            return null;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private static function buildCitationPreview($citations): ?string
    {
        if (!is_array($citations) || !$citations) {
            return null;
        }

        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }
            $texts = $citation['text'] ?? null;
            $snippet = '';
            if (is_array($texts) && $texts) {
                $snippet = trim(implode(' ', array_map('trim', $texts)));
            } elseif (is_string($texts)) {
                $snippet = trim($texts);
            }

            if ($snippet === '') {
                continue;
            }

            $snippet = preg_replace('/\s+/', ' ', $snippet);
            if (mb_strlen($snippet) > 160) {
                $snippet = mb_substr($snippet, 0, 157) . '…';
            }

            $page = isset($citation['page_number']) ? trim((string) $citation['page_number']) : '';
            $prefix = 'Vedi sezione';
            if ($page !== '') {
                $prefix .= " (pag. {$page})";
            }

            return "{$prefix}: {$snippet}";
        }

        return null;
    }

    private static function parseRequirementDetails($decoded, $rawJson = null, $rawText = null): array
    {
        $entries = self::collectRequirementEntries($decoded);

        if (!$entries && is_string($rawJson) && $rawJson !== '') {
            $decodedRaw = json_decode($rawJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $entries = self::collectRequirementEntries($decodedRaw);
            } else {
                $entries = self::collectRequirementEntriesFromString($rawJson);
            }
        }

        if (!$entries && is_string($rawText) && $rawText !== '') {
            $entries = self::collectRequirementEntriesFromString($rawText);
        }

        $entries = self::deduplicateRequirements($entries);

        if (!$entries) {
            return [
                'entries' => [],
                'display' => null,
                'table' => null,
                'raw_block' => self::reconstructRequirementsBlock($decoded, $rawJson, $rawText),
            ];
        }

        return [
            'entries' => $entries,
            'display' => self::formatRequirementsDisplay($entries),
            'table' => self::formatRequirementsTable($entries),
            'raw_block' => self::reconstructRequirementsBlock($decoded, $rawJson, $rawText),
        ];
    }
    private static function reconstructRequirementsBlock($decoded, $rawJson, $rawText): ?string
    {
        foreach ([$decoded, $rawJson, $rawText] as $candidate) {
            if (is_string($candidate)) {
                $clean = trim($candidate);
                if ($clean !== '') {
                    return $clean;
                }
            } elseif (is_array($candidate)) {
                $json = json_encode($candidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if ($json !== false && trim($json) !== '') {
                    return $json;
                }
            }
        }
        return null;
    }

    private static function collectRequirementEntries($source): array
    {
        if ($source === null) {
            return [];
        }

        if (is_string($source)) {
            return self::collectRequirementEntriesFromString($source);
        }

        if (!is_array($source)) {
            return [];
        }

        $entries = [];

        if (self::isSequentialArray($source)) {
            foreach ($source as $item) {
                $entries = array_merge($entries, self::collectRequirementEntries($item));
            }
            return $entries;
        }

        $normalized = self::normalizeRequirementArray($source);
        if ($normalized !== null) {
            $entries[] = $normalized;
        }

        foreach (['answer', 'answers', 'requirements', 'items', 'values', 'value', 'text', 'content'] as $key) {
            if (array_key_exists($key, $source)) {
                $entries = array_merge($entries, self::collectRequirementEntries($source[$key]));
            }
        }

        // NON usare chain_of_thought per estrarre entries - se non ci sono entries strutturati, restituisci array vuoto
        // chain_of_thought è solo per debug, non per costruire dati

        // Se ancora non abbiamo entries ma ci sono citations, prova a estrarre da quelle
        if (empty($entries) && isset($source['citations']) && is_array($source['citations'])) {
            foreach ($source['citations'] as $citation) {
                if (isset($citation['reason_for_relevance']) && is_string($citation['reason_for_relevance'])) {
                    $citationEntries = self::extractRequirementsFromChainOfThought($citation['reason_for_relevance']);
                    if (!empty($citationEntries)) {
                        $entries = array_merge($entries, $citationEntries);
                    }
                }
                if (isset($citation['text']) && is_array($citation['text'])) {
                    $textContent = implode(' ', $citation['text']);
                    $textEntries = self::extractRequirementsFromChainOfThought($textContent);
                    if (!empty($textEntries)) {
                        $entries = array_merge($entries, $textEntries);
                    }
                }
            }
        }

        return $entries;
    }

    private static function extractRequirementsFromChainOfThought(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $entries = [];

        // Prova a estrarre liste di requisiti dal testo
        // Pattern per liste numerate o puntate
        if (preg_match_all('/(?:^|\n)(?:\d+[\.)]|\-|\*)\s*([^\n]+)/i', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $clean = trim($match);
                if (strlen($clean) > 10) { // Ignora voci troppo corte
                    $entries[] = [
                        'title' => $clean,
                        'description' => null,
                        'reference' => null,
                    ];
                }
            }
        }

        // Se non abbiamo trovato nulla, prova a estrarre frasi chiave
        if (empty($entries)) {
            // Cerca frasi che contengono parole chiave dei requisiti
            $keywords = [
                'requirement',
                'requisito',
                'condition',
                'condizione',
                'obbligatorio',
                'obbligatoria',
                'pena di esclusione',
                'necessary',
                'richiesto',
                'required',
                'document',
                'documento'
            ];
            $sentences = preg_split('/[.!?]\s+/', $text);
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                foreach ($keywords as $keyword) {
                    if (stripos($sentence, $keyword) !== false && strlen($sentence) > 20 && strlen($sentence) < 500) {
                        $entries[] = [
                            'title' => $sentence,
                            'description' => null,
                            'reference' => null,
                        ];
                        break; // Una frase per volta
                    }
                }
            }
            // Limita a max 10 voci per evitare output troppo lungo
            $entries = array_slice($entries, 0, 10);
        }

        return $entries;
    }

    private static function collectRequirementEntriesFromString(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $entries = [];

        if (preg_match_all('/-\s*\[Requirement[^]]*\]:(.*?)(?=^-\s*\[Requirement[^]]*\]:|\z)/ims', $text, $matches)) {
            foreach ($matches[1] as $block) {
                $entry = self::parseRequirementBlock($block);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        } else {
            $lines = preg_split('/\r\n|\r|\n/', $text);
            foreach ($lines as $line) {
                $cleanLine = self::cleanRequirementText($line);
                if ($cleanLine === null) {
                    continue;
                }
                $entries[] = [
                    'title' => null,
                    'description' => $cleanLine,
                    'reference' => null,
                ];
            }
        }

        return $entries;
    }

    private static function parseRequirementBlock(string $block): ?array
    {
        $title = self::extractRequirementField($block, ['Title/Name', 'Titolo', 'Nome']);
        $description = self::extractRequirementField($block, ['Description', 'Descrizione']);
        $reference = self::extractRequirementField($block, ['Legal Reference', 'Riferimento', 'Reference']);

        if ($description === null) {
            $cleaned = trim(preg_replace('/^\s*-\s*/m', '', $block));
            $description = $cleaned !== '' ? $cleaned : null;
        }

        return self::normalizeRequirementArray([
            'title' => $title,
            'description' => $description,
            'reference' => $reference,
        ]);
    }

    private static function extractRequirementField(string $block, array $labels): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/^\s*-\s*' . preg_quote($label, '/') . '\s*:\s*(.+)$/mi';
            if (preg_match($pattern, $block, $matches)) {
                return trim($matches[1]);
            }
        }
        return null;
    }

    private static function normalizeRequirementArray($item): ?array
    {
        if (!is_array($item)) {
            if (is_string($item)) {
                $text = self::cleanRequirementText($item);
                if ($text === null) {
                    return null;
                }
                return ['title' => null, 'description' => $text, 'reference' => null];
            }
            return null;
        }

        $title = self::cleanRequirementText($item['title'] ?? $item['name'] ?? $item['label'] ?? null);
        $description = self::cleanRequirementText($item['description'] ?? $item['summary'] ?? $item['details'] ?? null);
        if ($description === null && isset($item['text']) && is_string($item['text'])) {
            $description = self::cleanRequirementText($item['text']);
        }
        $reference = self::cleanRequirementText($item['legal_reference'] ?? $item['reference'] ?? null);

        if ($title === null && $description === null && $reference === null) {
            return null;
        }

        return [
            'title' => $title,
            'description' => $description,
            'reference' => $reference,
        ];
    }

    private static function deduplicateRequirements(array $entries): array
    {
        $seen = [];
        $result = [];
    
        foreach ($entries as $entry) {
            // Normalizza whitespace prima di creare la chiave
            $normalize = function($str) {
                return trim(preg_replace('/\s+/', ' ', (string) ($str ?? '')));
            };
            
            $key = $normalize($entry['title'] ?? '') . '|' .
                   $normalize($entry['description'] ?? '') . '|' .
                   $normalize($entry['reference'] ?? '');
            
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $entry;
        }
    
        return $result;
    }

    private static function isSequentialArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        $expectedKeys = range(0, count($array) - 1);
        return array_keys($array) === $expectedKeys;
    }

    private static function formatRequirementsDisplay(array $requirements): string
    {
        $lines = [];
        foreach ($requirements as $index => $requirement) {
            $parts = [];
            $title = isset($requirement['title']) ? trim((string) $requirement['title']) : '';
            $description = isset($requirement['description']) ? trim((string) $requirement['description']) : '';
            $reference = isset($requirement['reference']) ? trim((string) $requirement['reference']) : '';

            if ($title !== '') {
                $parts[] = $title;
            }
            if ($description !== '') {
                $parts[] = $description;
            }
            if ($reference !== '') {
                $parts[] = 'Rif: ' . $reference;
            }

            $line = implode(' — ', array_filter($parts));
            if ($line === '') {
                continue;
            }

            $lines[] = ($index + 1) . '. ' . $line;
        }

        return implode(' | ', $lines);
    }

    private static function formatRequirementsTable(array $requirements): ?array
    {
        if (empty($requirements)) {
            return null;
        }

        $hasTitle = false;
        $hasReference = false;
        foreach ($requirements as $req) {
            if (!$hasTitle && !empty($req['title'])) {
                $hasTitle = true;
            }
            if (!$hasReference && !empty($req['reference'])) {
                $hasReference = true;
            }
            if ($hasTitle && $hasReference) {
                break;
            }
        }

        $headers = ['#'];
        if ($hasTitle) {
            $headers[] = 'Titolo';
        }
        $headers[] = 'Descrizione';
        if ($hasReference) {
            $headers[] = 'Riferimento';
        }

        $rows = [];
        foreach ($requirements as $index => $req) {
            $row = [(string) ($index + 1)];
            if ($hasTitle) {
                $row[] = !empty($req['title']) ? $req['title'] : '—';
            }
            $row[] = !empty($req['description']) ? $req['description'] : '—';
            if ($hasReference) {
                $row[] = !empty($req['reference']) ? $req['reference'] : '—';
            }
            $rows[] = $row;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private static function cleanRequirementText($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || !is_string($text)) {
            return null;
        }

        // Usa \x{2022} invece di \u2022 per compatibilità PCRE2
        $text = preg_replace('/^[-•\x{2022}]+\s*/u', '', $text);
        $text = preg_replace('/^\[requirement\s*#?[^\]]*\]\s*:\s*/iu', '', $text ?? '');

        return ($text === '' || $text === null) ? null : $text;
    }

    private static function deriveEmptyReason(
        ?string $typeCode,
        $displayValue,
        array $requirementsDetails,
        $decoded,
        $valueText,
        $rawJson = null
    ): ?string {
        $hasDisplay = is_string($displayValue) && trim($displayValue) !== '';
        $hasRequirements = !empty($requirementsDetails['entries']);
        $hasTable = !empty($requirementsDetails['table']) && !empty($requirementsDetails['table']['rows']);

        if ($hasDisplay || $hasRequirements || $hasTable) {
            return null;
        }

        if (is_string($valueText) && trim($valueText) !== '') {
            return null;
        }

        $messages = [];

        if (!empty($requirementsDetails['raw_block']) && is_string($requirementsDetails['raw_block'])) {
            $messages[] = $requirementsDetails['raw_block'];
        }

        if (is_array($decoded)) {
            if (!empty($decoded['answer']) && is_string($decoded['answer']) && trim($decoded['answer']) !== '') {
                return null;
            }

            // NON usare chain_of_thought, empty_reason, reason, explanation come messaggi
            // Questi campi sono solo per debug/log, non per display all'utente
            // Se non c'è una risposta vera, non mostrare niente (return null)
            
            // Rimuoviamo completamente l'uso di questi campi come fallback
            // deriveEmptyReason() può restituire null se non c'è una risposta vera
        } elseif (is_string($decoded) && trim($decoded) !== '') {
            // Verifica che non sia JSON grezzo o reasoning
            $trimmed = trim($decoded);
            if (strlen($trimmed) < 200 && !preg_match('/^\s*[\{\[]/', $trimmed) && 
                stripos($trimmed, 'chain_of_thought') === false &&
                stripos($trimmed, 'reasoning') === false) {
                $messages[] = $trimmed;
            }
        }
        // NON usare rawJson come fallback - è JSON grezzo, non una risposta pulita

        foreach ($messages as $message) {
            $summary = \Services\AIextraction\ExtractionFormatter::summarizeEmptyReason($message);
            if ($summary !== '') {
                return $summary;
            }
        }

        return null;
    }

    private static function summarizeEmptyReason(string $text): string
    {
        $raw = trim(strip_tags($text));
        if ($raw === '' || preg_match('/^\s*[\{\[]/', $raw)) {
            return '';
        }

        $clean = trim(preg_replace('/\s+/', ' ', $raw));
        if ($clean === '') {
            return '';
        }

        $clean = ltrim($clean, '-•: ');
        $sentences = preg_split('/(?<=[.?!])\s+/', $clean);
        $summary = $sentences[0] ?? $clean;

        if (mb_strlen($summary) > 240) {
            $summary = mb_substr($summary, 0, 237) . '…';
        }

        return $summary;
    }

    private static function formatLocationValue(array $location): ?string
    {
        $segments = [];

        $name = trim((string) ($location['entity_name'] ?? $location['organization'] ?? ''));
        if ($name !== '') {
            $entityType = trim((string) ($location['entity_type'] ?? ''));
            if ($entityType !== '') {
                if (stripos($name, $entityType) === false) {
                    $name .= ' (' . $entityType . ')';
                }
            }
            $segments[] = $name;
        }

        $addressSegments = [];

        $streetParts = [];
        if (!empty($location['street'])) {
            $streetParts[] = trim((string) $location['street']);
        }
        if (!empty($location['house_number'])) {
            $streetParts[] = trim((string) $location['house_number']);
        }
        if ($streetParts) {
            $addressSegments[] = implode(' ', $streetParts);
        }

        $cityLineParts = [];
        if (!empty($location['postal_code'])) {
            $cityLineParts[] = trim((string) $location['postal_code']);
        }
        if (!empty($location['city'])) {
            $city = trim((string) $location['city']);
            if (!empty($location['district'])) {
                $city .= ' (' . trim((string) $location['district']) . ')';
            }
            $cityLineParts[] = $city;
        }
        if ($cityLineParts) {
            $addressSegments[] = implode(' ', $cityLineParts);
        }

        foreach (['province', 'region', 'state'] as $key) {
            if (!empty($location[$key])) {
                $value = trim((string) $location[$key]);
                if ($value !== '') {
                    $addressSegments[] = $value;
                }
            }
        }

        if (!empty($location['country'])) {
            $country = trim((string) $location['country']);
            if ($country !== '') {
                $addressSegments[] = $country;
            }
        }

        if ($addressSegments) {
            $segments[] = implode(', ', $addressSegments);
        }

        $extras = [];
        if (!empty($location['nuts_code'])) {
            $extras[] = 'NUTS ' . strtoupper(trim((string) $location['nuts_code']));
        }
        if (!empty($location['scope'])) {
            $extras[] = trim((string) $location['scope']);
        }
        if (!empty($location['location_type'])) {
            $extras[] = trim((string) $location['location_type']);
        }

        if (!$segments && !empty($location['raw_text'])) {
            $segments[] = trim((string) $location['raw_text']);
        }

        if ($extras) {
            $segments[] = implode(', ', $extras);
        }

        $segments = array_values(array_filter(array_map(static function ($segment) {
            return trim(preg_replace('/\s+/', ' ', (string) $segment));
        }, $segments)));

        if (!$segments) {
            return null;
        }

        return implode(' – ', $segments);
    }

    private static function extractSopralluogoDetails($decoded, $rawText = null, $rawJson = null): array
    {
        $statusBool = null;

        // Nuova struttura: gestisci bool_answer e sopralluogo_status
        if (is_array($decoded)) {
            if (isset($decoded['bool_answer'])) {
                $statusBool = (bool) $decoded['bool_answer'];
            } elseif (isset($decoded['sopralluogo_status'])) {
                $status = $decoded['sopralluogo_status'];
                if ($status === 'required' || $status === 'obbligatorio') {
                    $statusBool = true;
                } elseif ($status === 'not_required' || $status === 'non_obbligatorio') {
                    $statusBool = false;
                }
            }
        }

        if ($statusBool === null) {
            $statusBool = \Services\AIextraction\ExtractionFormatter::boolFromExtraction($decoded);
        }

        if ($statusBool === null && is_string($rawText)) {
            $statusBool = \Services\AIextraction\ExtractionFormatter::boolFromExtraction($rawText);
        }

        if ($statusBool === null && is_string($rawJson)) {
            $decodedRaw = json_decode($rawJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $statusBool = \Services\AIextraction\ExtractionFormatter::boolFromExtraction($decodedRaw);
            }
        }

        // Nuova struttura: gestisci deadlines array e offer_submission_deadline
        $deadline = null;
        if (is_array($decoded)) {
            // Priorità 1: offer_submission_deadline (nuova struttura)
            if (isset($decoded['offer_submission_deadline']) && is_array($decoded['offer_submission_deadline'])) {
                $deadlineDate = $decoded['offer_submission_deadline'];
                $year = $deadlineDate['year'] ?? null;
                $month = $deadlineDate['month'] ?? null;
                $day = $deadlineDate['day'] ?? null;
                if ($year && $month && $day) {
                    $iso = sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
                    $deadline = [
                        'iso' => $iso,
                        'display' => \Services\AIextraction\ExtractionFormatter::formatIsoDateToItalian($iso),
                        'text' => $deadlineDate['source_text'] ?? null,
                    ];
                }
            }
            // Priorità 2: deadlines array (nuova struttura)
            elseif (isset($decoded['deadlines']) && is_array($decoded['deadlines']) && !empty($decoded['deadlines'])) {
                $firstDeadline = $decoded['deadlines'][0];
                if (is_array($firstDeadline)) {
                    $deadline = self::detectSopralluogoDeadline($firstDeadline);
                }
            }
        }

        if ($deadline === null) {
            $deadline = self::detectSopralluogoDeadline($decoded);
        }
        if ($deadline === null && is_string($rawJson)) {
            $decodedRaw = json_decode($rawJson, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $deadline = self::detectSopralluogoDeadline($decodedRaw);
            }
        }
        if ($deadline === null && is_string($rawText)) {
            $deadline = self::detectSopralluogoDeadline($rawText);
        }

        return [
            'status_bool' => $statusBool,
            'status_label' => $statusBool === null ? null : ($statusBool ? 'si' : 'no'),
            'deadline_iso' => $deadline['iso'] ?? null,
            'deadline_display' => $deadline['display'] ?? null,
            'deadline_text' => $deadline['text'] ?? null,
            'deadline_label' => self::formatSopralluogoDeadlineLabel($deadline, $rawText, $rawJson),
        ];
    }

    private static function detectSopralluogoDeadline($source): ?array
    {
        if ($source === null) {
            return null;
        }

        if (is_string($source)) {
            $iso = \Services\AIextraction\ExtractionFormatter::extractDateValue($source);
            if ($iso !== null) {
                return [
                    'iso' => $iso,
                    'display' => \Services\AIextraction\ExtractionFormatter::formatIsoDateToItalian($iso),
                    'text' => trim($source),
                ];
            }

            $normalized = trim($source);
            if ($normalized === '') {
                return null;
            }

            // Usa pattern regex invece di array di keyword
            if (preg_match(self::SOPRALLUOGO_DEADLINE_PATTERN, $normalized)) {
                return [
                    'iso' => null,
                    'display' => null,
                    'text' => $normalized,
                ];
            }

            return null;
        }

        if (!is_array($source)) {
            return null;
        }

        foreach ($source as $key => $value) {
            if (is_string($key)) {
                $keyNormalized = strtolower($key);
                // Usa pattern regex invece di array di keyword
                if (preg_match(self::SOPRALLUOGO_DEADLINE_PATTERN, $keyNormalized)) {
                    $textValue = is_scalar($value)
                        ? (string) $value
                        : \Services\AIextraction\ExtractionFormatter::stringifyExtractionValue($value);

                    $iso = \Services\AIextraction\ExtractionFormatter::extractDateValue($value);
                    if ($iso === null && $textValue !== null) {
                        $iso = \Services\AIextraction\ExtractionFormatter::extractDateValue($textValue);
                    }

                    if ($iso !== null) {
                        return [
                            'iso' => $iso,
                            'display' => \Services\AIextraction\ExtractionFormatter::formatIsoDateToItalian($iso),
                            'text' => is_string($value) ? trim($value) : ($textValue !== null ? trim($textValue) : null),
                        ];
                    }

                    if ($textValue !== null && trim($textValue) !== '') {
                        return [
                            'iso' => null,
                            'display' => null,
                            'text' => trim($textValue),
                        ];
                    }
                }
            }

            $nested = self::detectSopralluogoDeadline($value);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private static function formatSopralluogoDeadlineLabel(?array $deadline, $rawText = null, $rawJson = null): ?string
    {
        $candidates = [];

        if ($deadline !== null) {
            if (!empty($deadline['display'])) {
                $candidates[] = $deadline['display'];
            }
            if (!empty($deadline['iso'])) {
                $candidates[] = \Services\AIextraction\ExtractionFormatter::formatIsoDateToItalian($deadline['iso']);
            }
            if (!empty($deadline['text'])) {
                $candidates[] = $deadline['text'];
            }
        }

        foreach ([$rawText, $rawJson] as $raw) {
            if (is_string($raw) && trim($raw) !== '') {
                $candidates[] = trim($raw);
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $normalized = trim(preg_replace('/\s+/', ' ', $candidate));
            if ($normalized === '') {
                continue;
            }
            if ($normalized[0] === '{' || $normalized[0] === '[') {
                continue;
            }
            if (preg_match('/^(termine|entro)/i', $normalized)) {
                return ucfirst($normalized);
            }
            return 'Entro ' . ltrim($normalized);
        }

        return null;
    }

    private static function formatIsoDateToItalian(string $iso): string
    {
        try {
            $dt = new \DateTime($iso);
            return $dt->format('d-m-Y');
        } catch (\Throwable $e) {
            return $iso;
        }
    }

    private static function formatSopralluogoValue($value): ?string
    {
        $bool = \Services\AIextraction\ExtractionFormatter::boolFromExtraction($value);
        if ($bool === null) {
            return null;
        }
        return $bool ? 'si' : 'no';
    }

    private static function boolFromExtraction($value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_array($value)) {
            if (array_key_exists('bool_answer', $value)) {
                return (bool) $value['bool_answer'];
            }
            if (array_key_exists('answer', $value)) {
                return \Services\AIextraction\ExtractionFormatter::boolFromExtraction($value['answer']);
            }
        }
        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }
            if (in_array($normalized, ['1', 'true', 'si', 'sì', 'yes', 'obbligatorio'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'non', 'facoltativo'], true)) {
                return false;
            }
        }
        return null;
    }

    private static function resolveTextField($primary, $fallback = null): ?string
    {
        $value = $primary;
        if (self::isInvalidTextValue($value)) {
            $value = $fallback;
        }
        return \Services\AIextraction\ExtractionFormatter::stringifyExtractionValue($value);
    }

    private static function isInvalidTextValue($value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '' || strcasecmp($trim, 'array') === 0) {
                return true;
            }
        }
        return false;
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

    /**
     * Helper: estrae un valore scalare da value_text o value_json
     */
    private static function extractScalarValue(?string $valueText, ?array $valueJson, string $typeCode): ?string
    {
        // Priorità 1: value_text
        if (!empty($valueText) && trim($valueText) !== '' && strtolower(trim($valueText)) !== 'null') {
            return trim($valueText);
        }

        // Priorità 2: value_json con chiavi comuni
        if (is_array($valueJson)) {
            $candidates = ['answer', 'value', 'result', 'response', 'text', 'display_value'];
            foreach ($candidates as $key) {
                if (isset($valueJson[$key])) {
                    $val = $valueJson[$key];
                    if (is_string($val) && trim($val) !== '') {
                        return trim($val);
                    } elseif (is_scalar($val) && $val !== null && $val !== '') {
                        return (string) $val;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Helper: normalizza una data da stringa a formato DATE SQL
     */
    private static function normalizeDate(?string $dateValue): ?string
    {
        if (empty($dateValue)) {
            return null;
        }

        // Prova a parsare la data
        $timestamp = strtotime($dateValue);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Helper: normalizza un valore booleano
     */
    private static function normalizeBoolean(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['si', 'sì', 'yes', 'true', '1', 'obbligatorio'], true)) {
            return 1;
        }
        if (in_array($normalized, ['no', 'false', '0', 'facoltativo'], true)) {
            return 0;
        }

        return null;
    }

    // ========== METODI DI LETTURA DA TABELLE NORMALIZZATE ==========
    // Costruiscono estrazioni in formato compatibile con la UI partendo dalle tabelle gar_gara_*

    /**
     * Costruisce estrazione da gar_gare_anagrafica (dati scalari)
     */
    /**
     * Costruisce estrazione da gar_gare_anagrafica (fonte principale per dati anagrafici)
     * Usa le colonne corrette dalla tabella normalizzata
     */
    private static function buildEstrazioneFromNormalizedAnagrafica(array $row, int $jobId): ?array
    {
        global $database;
        $pdo = $database->connection;

        try {
            $stmt = $pdo->prepare("SELECT * FROM gar_gare_anagrafica WHERE job_id = :job_id LIMIT 1");
            $stmt->execute([':job_id' => $jobId]);
            $anagrafica = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Tabella non esiste o errore, fallback ai dati raw
            return null;
        }

        if (!$anagrafica) {
            return null; // Fallback ai dati raw
        }

        $typeCode = $row['type_code'] ?? '';
        $displayValue = null;
        $valueJson = null;
        $sopralluogoDetails = null;

        // Mappa type_code alle colonne corrette della tabella gar_gare_anagrafica
        switch ($typeCode) {
            case 'oggetto_appalto':
                $displayValue = $anagrafica['oggetto_appalto'] ?? null;
                break;
            case 'stazione_appaltante':
                $displayValue = $anagrafica['stazione_appaltante'] ?? null;
                break;
            case 'data_uscita_gara_appalto':
                $displayValue = $anagrafica['data_uscita'] ?? null;
                // Formatta come data ISO se presente
                if ($displayValue && preg_match('/^\d{4}-\d{2}-\d{2}/', $displayValue)) {
                    $valueJson = ['date' => ['year' => substr($displayValue, 0, 4), 'month' => substr($displayValue, 5, 2), 'day' => substr($displayValue, 8, 2)]];
                }
                break;
            case 'data_scadenza_gara_appalto':
                $displayValue = $anagrafica['data_scadenza'] ?? null;
                // Formatta come data ISO se presente
                if ($displayValue && preg_match('/^\d{4}-\d{2}-\d{2}/', $displayValue)) {
                    $valueJson = ['date' => ['year' => substr($displayValue, 0, 4), 'month' => substr($displayValue, 5, 2), 'day' => substr($displayValue, 8, 2)]];
                }
                break;
            case 'link_portale_stazione_appaltante':
                $displayValue = $anagrafica['link_portale'] ?? null;
                break;
            case 'luogo_provincia_appalto':
                // Combina luogo e provincia
                $luogo = $anagrafica['luogo'] ?? '';
                $provincia = $anagrafica['provincia'] ?? '';
                if ($luogo || $provincia) {
                    $displayValue = trim(($luogo ?: '') . ($provincia ? ' (' . $provincia . ')' : ''));
                    $valueJson = [
                        'location' => [
                            'city' => $luogo,
                            'district' => $provincia,
                            'raw_text' => $displayValue
                        ]
                    ];
                }
                break;
            case 'settore_industriale_gara_appalto':
                // Leggi il primo id_opera da gar_gara_importi_opere ordinato per importo decrescente
                // Poi fai lookup in gar_opere_dm50 per ottenere la categoria
                global $database;
                $pdo = $database->connection;
                try {
                    $stmt = $pdo->prepare("SELECT id_opera 
                        FROM gar_gara_importi_opere 
                        WHERE job_id = :job_id 
                        AND importo_lavori_eur IS NOT NULL 
                        ORDER BY importo_lavori_eur DESC 
                        LIMIT 1");
                    $stmt->execute([':job_id' => $jobId]);
                    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($result && !empty($result['id_opera'])) {
                        $idOpera = $result['id_opera'];
                        // Fai lookup in gar_opere_dm50 per ottenere la categoria
                        $opereDm50Data = self::getOpereDm50Data([$idOpera]);
                        if (!empty($opereDm50Data[$idOpera]) && !empty($opereDm50Data[$idOpera]['categoria'])) {
                            $categoria = $opereDm50Data[$idOpera]['categoria'];
                            $displayValue = $idOpera . ' ' . $categoria;
                        } else {
                            // Se non trovato in gar_opere_dm50, mostra solo id_opera
                            $displayValue = $idOpera;
                        }
                    } else {
                        // Fallback: usa settore da anagrafica se non ci sono importi
                        $displayValue = $anagrafica['settore'] ?? null;
                    }
                } catch (\PDOException $e) {
                    // Fallback: usa settore da anagrafica in caso di errore
                    $displayValue = $anagrafica['settore'] ?? null;
                }
                break;
            case 'tipologia_di_gara':
                $displayValue = $anagrafica['tipologia_gara'] ?? null;
                break;
            case 'tipologia_di_appalto':
                $displayValue = $anagrafica['tipologia_appalto'] ?? null;
                break;
            case 'sopralluogo_obbligatorio':
                $sopralluogoObbligatorio = $anagrafica['sopralluogo_obbligatorio'] ?? null;
                $sopralluogoDeadline = $anagrafica['sopralluogo_deadline'] ?? null;
                $sopralluogoDeadlineRaw = $anagrafica['sopralluogo_deadline_raw'] ?? null;
                $sopralluogoNote = $anagrafica['sopralluogo_note'] ?? null;
                
                // Converti boolean (0/1) in true/false
                $isObbligatorio = ($sopralluogoObbligatorio === 1 || $sopralluogoObbligatorio === '1' || $sopralluogoObbligatorio === true);
                $displayValue = $isObbligatorio ? 'Sì' : 'No';
                
                $valueJson = ['bool_answer' => $isObbligatorio];
                $sopralluogoDetails = [
                    'required' => $isObbligatorio,
                    'deadline_display' => $sopralluogoDeadline,
                    'deadline_iso' => $sopralluogoDeadline ? (preg_match('/^\d{4}-\d{2}-\d{2}/', $sopralluogoDeadline) ? $sopralluogoDeadline : null) : null,
                    'deadline_text' => $sopralluogoDeadlineRaw,
                    'deadline_label' => $sopralluogoDeadline,
                    'note' => $sopralluogoNote,
                ];
                break;
        }

        // Se non abbiamo il valore, fallback
        if ($displayValue === null && $displayValue !== '0' && $displayValue !== 0) {
            return null;
        }

        $updatedAt = $row['extraction_created_at']
            ?? $row['job_updated_at']
            ?? $row['job_completed_at']
            ?? $row['job_created_at'];

        $estrazione = [
            'id' => $row['id'] ?? 0,
            'job_id' => $jobId,
            'tipo' => $typeCode,
            'type_code' => $typeCode,
            'type_display' => \Services\AIextraction\ExtractionFormatter::displayNameForExtractionType($typeCode),
            'value_text' => $displayValue !== null ? (string) $displayValue : null,
            'display_value' => $displayValue !== null ? (string) $displayValue : null,
            'value_json' => $valueJson ? json_encode($valueJson, JSON_UNESCAPED_UNICODE) : json_encode(['answer' => $displayValue], JSON_UNESCAPED_UNICODE),
            'confidence' => $row['confidence'] ?? null,
            'citations' => $row['citations'] ?? [],
            'value_state' => 'scalar',
            'updated_at' => $updatedAt,
        ];

        if ($sopralluogoDetails !== null) {
            $estrazione['sopralluogo_details'] = $sopralluogoDetails;
            if (!empty($sopralluogoDetails['deadline_display'])) {
                $estrazione['sopralluogo_deadline'] = $sopralluogoDetails['deadline_display'];
            }
            if (!empty($sopralluogoDetails['deadline_iso'])) {
                $estrazione['sopralluogo_deadline_iso'] = $sopralluogoDetails['deadline_iso'];
            }
            if (!empty($sopralluogoDetails['deadline_text'])) {
                $estrazione['sopralluogo_deadline_text'] = $sopralluogoDetails['deadline_text'];
            }
            if (!empty($sopralluogoDetails['deadline_label'])) {
                $estrazione['sopralluogo_deadline_label'] = $sopralluogoDetails['deadline_label'];
            }
        }

        return $estrazione;
    }

    /**
     * Costruisce estrazione da gar_gara_importi_opere
     * Usa le colonne corrette: id_opera, categoria, identificazione_opera, complessita_dm50, importo_lavori_eur
     * Completa con join a gar_opere_dm50 se categoria/identificazione_opera/complessita_dm50 sono NULL
     */
    private static function buildEstrazioneFromNormalizedImportiOpere(array $row, int $jobId): ?array
    {
        global $database;
        $pdo = $database->connection;

        try {
            // Query con LEFT JOIN a gar_opere_dm50 per completare dati mancanti
            $sql = "SELECT 
                        imp.id,
                        imp.job_id,
                        imp.extraction_id,
                        imp.id_opera,
                        imp.id_opera_raw,
                        imp.gar_opera_id,
                        COALESCE(imp.categoria, dm50.categoria) AS categoria,
                        COALESCE(imp.identificazione_opera, dm50.identificazione_opera) AS identificazione_opera,
                        COALESCE(imp.complessita_dm50, dm50.complessita) AS complessita_dm50,
                        imp.importo_lavori_eur,
                        imp.importo_lavori_raw,
                        imp.note
                    FROM gar_gara_importi_opere imp
                    LEFT JOIN gar_opere_dm50 dm50 ON dm50.id_opera = imp.id_opera
                    WHERE imp.job_id = :job_id
                    ORDER BY imp.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':job_id' => $jobId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null; // Fallback ai dati raw
        }

        if (empty($rows)) {
            return null; // Fallback ai dati raw
        }

        // Costruisce entries dal formato tabella normalizzata
        $entries = [];
        foreach ($rows as $r) {
            $entries[] = [
                'id_opera' => $r['id_opera'] ?? '',
                'id_opera_normalized' => $r['id_opera'] ?? '',
                'id_opera_raw' => $r['id_opera_raw'] ?? $r['id_opera'] ?? '',
                'category_id' => $r['id_opera'] ?? '', // Per compatibilità con nuovo formato
                'category_name' => $r['categoria'] ?? '',
                'categoria' => $r['categoria'] ?? '',
                'identificazione_opera' => $r['identificazione_opera'] ?? '',
                'descrizione' => $r['identificazione_opera'] ?? '', // Alias per compatibilità
                'complessita' => $r['complessita_dm50'] ?? null,
                'amount_eur' => $r['importo_lavori_eur'] ?? null,
                'amount_raw' => $r['importo_lavori_raw'] ?? null,
                'source_page' => null, // Non disponibile in gar_gara_importi_opere
            ];
        }

        $updatedAt = $row['extraction_created_at']
            ?? $row['job_updated_at']
            ?? $row['job_completed_at']
            ?? $row['job_created_at'];

        return [
            'id' => $row['id'] ?? 0,
            'job_id' => $jobId,
            'tipo' => 'importi_opere_per_categoria_id_opere',
            'type_code' => 'importi_opere_per_categoria_id_opere',
            'type_display' => \Services\AIextraction\ExtractionFormatter::displayNameForExtractionType('importi_opere_per_categoria_id_opere'),
            'value_text' => count($entries) . ' elementi',
            'display_value' => count($entries) . ' elementi',
            'value_json' => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE),
            'confidence' => $row['confidence'] ?? null,
            'citations' => $row['citations'] ?? [],
            'value_state' => 'table',
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Costruisce estrazione da gar_gara_corrispettivi_opere e gar_gara_corrispettivi_opere_fasi
     * Usa ESCLUSIVAMENTE i dati normalizzati dal database, ignorando completamente i dati raw dall'AI
     * Colonne fisse: ID opere, Categoria, Descrizione, Grado di complessità, Importo del corrispettivo
     */
    private static function buildEstrazioneFromNormalizedCorrispettiviOpere(array $row, int $jobId): ?array
    {
        global $database;
        $pdo = $database->connection;

        $headers = ['ID opere', 'Categoria', 'Descrizione', 'Grado di complessità', 'Importo del corrispettivo'];
        $tableRows = [];
        $entries = [];

        // Prima prova a recuperare da gar_gara_corrispettivi_opere (totali per categoria)
        try {
            $sql = "SELECT 
                        corr.id,
                        corr.job_id,
                        corr.extraction_id,
                        corr.id_opera,
                        corr.id_opera_raw,
                        corr.gar_opera_id,
                        COALESCE(corr.categoria, dm50.categoria) AS categoria,
                        COALESCE(corr.identificazione_opera, dm50.identificazione_opera) AS identificazione_opera,
                        COALESCE(corr.grado_complessita, dm50.complessita) AS grado_complessita,
                        corr.grado_complessita_raw,
                        corr.importo_corrispettivo_eur,
                        corr.importo_corrispettivo_raw,
                        corr.note
                    FROM gar_gara_corrispettivi_opere corr
                    LEFT JOIN gar_opere_dm50 dm50 ON dm50.id_opera = corr.id_opera
                    WHERE corr.job_id = :job_id
                    ORDER BY corr.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':job_id' => $jobId]);
            $rowsTotali = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $rowsTotali = [];
        }

        // Poi recupera da gar_gara_corrispettivi_opere_fasi (dettaglio per fase)
        $rowsFasi = [];
        try {
            $sqlFasi = "SELECT 
                        fasi.id,
                        fasi.job_id,
                        fasi.extraction_id,
                        fasi.id_opera,
                        fasi.id_opera_raw,
                        fasi.gar_opera_id,
                        COALESCE(fasi.categoria, dm50.categoria) AS categoria,
                        COALESCE(fasi.identificazione_opera, dm50.identificazione_opera) AS identificazione_opera,
                        COALESCE(fasi.grado_complessita, dm50.complessita) AS grado_complessita,
                        fasi.grado_complessita_raw,
                        fasi.service_phase,
                        fasi.importo_corrispettivo_eur,
                        fasi.importo_corrispettivo_raw,
                        fasi.source_page,
                        fasi.note
                    FROM gar_gara_corrispettivi_opere_fasi fasi
                    LEFT JOIN gar_opere_dm50 dm50 ON dm50.id_opera = fasi.id_opera
                    WHERE fasi.job_id = :job_id
                    ORDER BY fasi.id_opera ASC, fasi.id ASC";
            $stmtFasi = $pdo->prepare($sqlFasi);
            $stmtFasi->execute([':job_id' => $jobId]);
            $rowsFasi = $stmtFasi->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Tabella fasi non esiste o errore, continua solo con totali
        }

        // Se ci sono fasi, usale (mostra righe separate per fase)
        // Altrimenti usa i totali
        if (!empty($rowsFasi)) {
            foreach ($rowsFasi as $r) {
                $idOpera = $r['id_opera'] ?? '';
                $categoria = $r['categoria'] ?? '—';
                $descrizione = $r['identificazione_opera'] ?? '—';
                $gradoComplessita = $r['grado_complessita'] ?? null;
                $gradoComplessitaDisplay = $gradoComplessita !== null ? (string) $gradoComplessita : '—';
                $importoEur = $r['importo_corrispettivo_eur'] ?? null;
                $importoDisplay = $importoEur !== null 
                    ? number_format((float) $importoEur, 2, ',', '.') . ' €'
                    : '—';
                
                // Se c'è service_phase, aggiungilo alla descrizione
                $servicePhase = $r['service_phase'] ?? '';
                if ($servicePhase) {
                    $phaseLabels = [
                        'executive_project' => 'Progettazione esecutiva',
                        'site_supervision' => 'Direzione lavori',
                        'safety_coordination' => 'Coordinamento sicurezza',
                    ];
                    $phaseLabel = $phaseLabels[$servicePhase] ?? $servicePhase;
                    $descrizione .= ' (' . $phaseLabel . ')';
                }

                $tableRows[] = [
                    $idOpera ?: '—',
                    $categoria,
                    $descrizione,
                    $gradoComplessitaDisplay,
                    $importoDisplay
                ];

                $entries[] = [
                    'id_opera' => $idOpera,
                    'categoria' => $categoria,
                    'identificazione_opera' => $r['identificazione_opera'] ?? '',
                    'descrizione' => $descrizione,
                    'grado_complessita' => $gradoComplessita,
                    'importo_corrispettivo_eur' => $importoEur,
                    'importo_corrispettivo_raw' => $r['importo_corrispettivo_raw'] ?? null,
                    'service_phase' => $servicePhase,
                ];
            }
        } elseif (!empty($rowsTotali)) {
            // Usa i totali se non ci sono fasi
            foreach ($rowsTotali as $r) {
                $idOpera = $r['id_opera'] ?? '';
                $categoria = $r['categoria'] ?? '—';
                $descrizione = $r['identificazione_opera'] ?? '—';
                $gradoComplessita = $r['grado_complessita'] ?? null;
                $gradoComplessitaDisplay = $gradoComplessita !== null ? (string) $gradoComplessita : '—';
                $importoEur = $r['importo_corrispettivo_eur'] ?? null;
                $importoDisplay = $importoEur !== null 
                    ? number_format((float) $importoEur, 2, ',', '.') . ' €'
                    : '—';

                $tableRows[] = [
                    $idOpera ?: '—',
                    $categoria,
                    $descrizione,
                    $gradoComplessitaDisplay,
                    $importoDisplay
                ];

                $entries[] = [
                    'id_opera' => $idOpera,
                    'categoria' => $categoria,
                    'identificazione_opera' => $r['identificazione_opera'] ?? '',
                    'descrizione' => $descrizione,
                    'grado_complessita' => $gradoComplessita,
                    'importo_corrispettivo_eur' => $importoEur,
                    'importo_corrispettivo_raw' => $r['importo_corrispettivo_raw'] ?? null,
                ];
            }
        } else {
            // Nessun dato normalizzato disponibile
            return null;
        }

        $updatedAt = $row['extraction_created_at']
            ?? $row['job_updated_at']
            ?? $row['job_completed_at']
            ?? $row['job_created_at'];

        return [
            'id' => $row['id'] ?? 0,
            'job_id' => $jobId,
            'tipo' => 'importi_corrispettivi_categoria_id_opere',
            'type_code' => 'importi_corrispettivi_categoria_id_opere',
            'type_display' => \Services\AIextraction\ExtractionFormatter::displayNameForExtractionType('importi_corrispettivi_categoria_id_opere'),
            'value_text' => count($entries) . ' elementi',
            'display_value' => count($entries) . ' elementi',
            'value_state' => 'table',
            'table' => [
                'headers' => $headers,
                'rows' => $tableRows
            ],
            'value_json' => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE),
            'confidence' => $row['confidence'] ?? null,
            'citations' => $row['citations'] ?? [],
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Costruisce estrazione da gar_gara_requisiti_tecnici_categoria (per categoria/id_opera)
     * e gar_gara_requisiti_tecnici (per testo sintetico/completo)
     * Usa le colonne corrette: id_opera, categoria, identificazione_opera, importo_minimo_eur, importo_minimo_punta_eur
     */
    private static function buildEstrazioneFromNormalizedRequisitiTecnici(array $row, int $jobId): ?array
    {
        global $database;
        $pdo = $database->connection;

        try {
            // Query per requisiti per categoria con LEFT JOIN a gar_opere_dm50
            $sqlCategoria = "SELECT 
                        reqcat.id,
                        reqcat.job_id,
                        reqcat.extraction_id,
                        reqcat.id_opera,
                        reqcat.id_opera_raw,
                        reqcat.gar_opera_id,
                        COALESCE(reqcat.categoria, dm50.categoria) AS categoria,
                        COALESCE(reqcat.identificazione_opera, dm50.identificazione_opera) AS identificazione_opera,
                        reqcat.importo_minimo_eur,
                        reqcat.importo_minimo_raw,
                        reqcat.importo_minimo_punta_eur,
                        reqcat.importo_minimo_punta_raw,
                        reqcat.note
                    FROM gar_gara_requisiti_tecnici_categoria reqcat
                    LEFT JOIN gar_opere_dm50 dm50 ON dm50.id_opera = reqcat.id_opera
                    WHERE reqcat.job_id = :job_id
                    ORDER BY reqcat.id ASC";
            $stmtCategoria = $pdo->prepare($sqlCategoria);
            $stmtCategoria->execute([':job_id' => $jobId]);
            $rowsCategoria = $stmtCategoria->fetchAll(\PDO::FETCH_ASSOC);

            // Query per testo sintetico/completo
            $sqlTesto = "SELECT 
                        testo_sintetico,
                        testo_completo,
                        raw_json,
                        note
                    FROM gar_gara_requisiti_tecnici
                    WHERE job_id = :job_id
                    LIMIT 1";
            $stmtTesto = $pdo->prepare($sqlTesto);
            $stmtTesto->execute([':job_id' => $jobId]);
            $rowTesto = $stmtTesto->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null; // Fallback ai dati raw
        }

        if (empty($rowsCategoria)) {
            return null; // Fallback ai dati raw
        }

        // Costruisce requirements array
        $requirements = [];
        foreach ($rowsCategoria as $r) {
            $requirements[] = [
                'id_opera' => $r['id_opera'] ?? '',
                'id_opera_raw' => $r['id_opera_raw'] ?? $r['id_opera'] ?? '',
                'category_name' => $r['categoria'] ?? '',
                'categoria' => $r['categoria'] ?? '',
                'identificazione_opera' => $r['identificazione_opera'] ?? '',
                'description' => $r['identificazione_opera'] ?? '', // Alias per compatibilità
                'descrizione' => $r['identificazione_opera'] ?? '', // Alias per compatibilità
                'importo_minimo_eur' => $r['importo_minimo_eur'] ?? null,
                'importo_minimo_raw' => $r['importo_minimo_raw'] ?? null,
                'importo_minimo_punta_eur' => $r['importo_minimo_punta_eur'] ?? null,
                'importo_minimo_punta_raw' => $r['importo_minimo_punta_raw'] ?? null,
                'base_value_eur' => $r['importo_minimo_eur'] ?? null, // Alias per compatibilità
                'minimum_amount_eur' => $r['importo_minimo_eur'] ?? null, // Alias per compatibilità
            ];
        }

        // Costruisce value_json con requirements e testo
        $valueJson = ['requirements' => $requirements];
        if ($rowTesto) {
            if (!empty($rowTesto['raw_json'])) {
                $decodedRaw = json_decode($rowTesto['raw_json'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedRaw)) {
                    $valueJson = array_merge($valueJson, $decodedRaw);
                }
            }
            if (!empty($rowTesto['testo_completo'])) {
                $valueJson['testo_completo'] = $rowTesto['testo_completo'];
            }
            if (!empty($rowTesto['testo_sintetico'])) {
                $valueJson['testo_sintetico'] = $rowTesto['testo_sintetico'];
            }
        }

        $displayValue = $rowTesto['testo_sintetico'] ?? (count($requirements) . ' requisiti');

        // Costruisce tabella con colonne fisse: ID Opera, Categoria, Descrizione, Importo minimo
        $headers = ['ID Opera', 'Categoria', 'Descrizione', 'Importo minimo'];
        $tableRows = [];
        foreach ($rowsCategoria as $r) {
            $idOpera = $r['id_opera'] ?? '—';
            $categoria = $r['categoria'] ?? '—';
            $descrizione = $r['identificazione_opera'] ?? '—';
            
            // Importo minimo: formatta da importo_minimo_eur
            $importoMinimo = '—';
            if (isset($r['importo_minimo_eur']) && $r['importo_minimo_eur'] !== null && is_numeric($r['importo_minimo_eur'])) {
                $importoMinimo = number_format((float) $r['importo_minimo_eur'], 2, ',', '.') . ' €';
            } elseif (!empty($r['importo_minimo_raw'])) {
                $importoMinimo = $r['importo_minimo_raw'];
            }
            
            $tableRows[] = [
                $idOpera !== '' ? $idOpera : '—',
                $categoria !== '' ? $categoria : '—',
                $descrizione !== '' ? $descrizione : '—',
                $importoMinimo
            ];
        }

        $updatedAt = $row['extraction_created_at']
            ?? $row['job_updated_at']
            ?? $row['job_completed_at']
            ?? $row['job_created_at'];

        return [
            'id' => $row['id'] ?? 0,
            'job_id' => $jobId,
            'tipo' => 'importi_requisiti_tecnici_categoria_id_opere',
            'type_code' => 'importi_requisiti_tecnici_categoria_id_opere',
            'type_display' => \Services\AIextraction\ExtractionFormatter::displayNameForExtractionType('importi_requisiti_tecnici_categoria_id_opere'),
            'value_text' => $displayValue,
            'display_value' => $displayValue,
            'value_json' => json_encode($valueJson, JSON_UNESCAPED_UNICODE),
            'requirements' => $requirements, // Per compatibilità con frontend
            'table' => [
                'headers' => $headers,
                'rows' => $tableRows
            ],
            'confidence' => $row['confidence'] ?? null,
            'citations' => $row['citations'] ?? [],
            'value_state' => 'table',
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Costruisce estrazione da gar_gara_fatturato_minimo
     * Usa le colonne corrette: anni_minimi, importo_minimo_eur, importo_minimo_raw, note
     * Integra con ext_req_econ se servono dettagli per categoria
     */
    private static function buildEstrazioneFromNormalizedFatturatoMinimo(array $row, int $jobId): ?array
    {
        global $database;
        $pdo = $database->connection;

        try {
            $stmt = $pdo->prepare("SELECT 
                        anni_minimi,
                        importo_minimo_eur,
                        importo_minimo_raw,
                        note
                    FROM gar_gara_fatturato_minimo 
                    WHERE job_id = :job_id 
                    LIMIT 1");
            $stmt->execute([':job_id' => $jobId]);
            $fatturato = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null; // Fallback ai dati raw
        }

        if (!$fatturato) {
            return null; // Fallback ai dati raw
        }

        // Se non c'è un requisito reale (tutti i campi NULL), mostra "Non richiesto"
        $anniMinimi = $fatturato['anni_minimi'] ?? null;
        $importoMinimoEur = $fatturato['importo_minimo_eur'] ?? null;
        $importoMinimoRaw = $fatturato['importo_minimo_raw'] ?? null;
        $note = $fatturato['note'] ?? null;

        if ($anniMinimi === null && $importoMinimoEur === null && $importoMinimoRaw === null) {
            $displayValue = 'Non richiesto';
            $valueJson = ['answer' => 'Non richiesto', 'note' => $note];
        } else {
            // Costruisce display value
            $parts = [];
            if ($importoMinimoEur !== null) {
                $parts[] = '€ ' . number_format((float) $importoMinimoEur, 2, ',', '.');
            } elseif ($importoMinimoRaw !== null) {
                $parts[] = $importoMinimoRaw;
            }
            if ($anniMinimi !== null) {
                $parts[] = 'per ' . $anniMinimi . ' ' . ($anniMinimi == 1 ? 'anno' : 'anni');
            }
            $displayValue = !empty($parts) ? implode(' ', $parts) : 'Non specificato';

            // Costruisce value_json
            $valueJson = [
                'importo_minimo' => $importoMinimoEur,
                'importo_minimo_raw' => $importoMinimoRaw,
                'n_anni' => $anniMinimi,
                'anni_minimi' => $anniMinimi, // Alias
                'valuta' => 'EUR',
                'note' => $note,
            ];
        }

        $updatedAt = $row['extraction_created_at']
            ?? $row['job_updated_at']
            ?? $row['job_completed_at']
            ?? $row['job_created_at'];

        return [
            'id' => $row['id'] ?? 0,
            'job_id' => $jobId,
            'tipo' => 'fatturato_globale_n_minimo_anni',
            'type_code' => 'fatturato_globale_n_minimo_anni',
            'type_display' => \Services\AIextraction\ExtractionFormatter::displayNameForExtractionType('fatturato_globale_n_minimo_anni'),
            'value_text' => $displayValue,
            'display_value' => $displayValue,
            'value_json' => json_encode($valueJson, JSON_UNESCAPED_UNICODE),
            'confidence' => $row['confidence'] ?? null,
            'citations' => $row['citations'] ?? [],
            'value_state' => 'scalar',
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Mappa requirement_type a label italiana per testo_sintetico
     */
    private static function labelForRequirementType(string $type): string
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
     * Sincronizza i requisiti tecnici dall'estrazione AI alla tabella gar_gara_requisiti_tecnici
     * Estrae solo dati italiani (citations[].text) e ignora testi inglesi
     */
    private static function syncRequisitiTecniciFromExtraction(int $jobId, array $extractionRow): void
    {
        global $database;
        $pdo = $database->connection;

        $extractionId = (int) ($extractionRow['id'] ?? 0);
        if ($extractionId <= 0) {
            return;
        }

        $valueJson = $extractionRow['value_json'] ?? null;
        if (empty($valueJson)) {
            return;
        }

        $decoded = json_decode($valueJson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return;
        }

        // Estrai data.requirements dalla struttura AI
        $requirements = null;
        if (isset($decoded['data']['requirements']) && is_array($decoded['data']['requirements'])) {
            $requirements = $decoded['data']['requirements'];
        } elseif (isset($decoded['requirements']) && is_array($decoded['requirements'])) {
            $requirements = $decoded['requirements'];
        }

        if (empty($requirements) || !is_array($requirements)) {
            return;
        }

        // Elimina righe esistenti per questo job_id + extraction_id
        try {
            $deleteStmt = $pdo->prepare("DELETE FROM gar_gara_requisiti_tecnici WHERE job_id = :job_id AND extraction_id = :extraction_id");
            $deleteStmt->execute([
                ':job_id' => $jobId,
                ':extraction_id' => $extractionId
            ]);
        } catch (\PDOException $e) {
            // Ignora errori di cancellazione
        }

        // Inserisci ogni requirement come riga separata
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }

            // Costruisci requisito da requirement_type (NON da title che è in inglese)
            $requirementType = $requirement['requirement_type'] ?? '';
            $requisito = self::labelForRequirementType($requirementType);

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
                $insertStmt = $pdo->prepare("INSERT INTO gar_gara_requisiti_tecnici 
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
                // Ignora errori di inserimento per questa riga
                continue;
            }
        }
    }

    /**
     * Legge i requisiti tecnici dalla tabella gar_gara_requisiti_tecnici
     * Se non esistono, sincronizza dall'estrazione AI e poi rilegge
     */
    public static function getRequisitiTecnici(int $jobId): array
    {
        global $database;
        $pdo = $database->connection;

        // Controlla se esistono già righe in gar_gara_requisiti_tecnici
        try {
            $checkStmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM gar_gara_requisiti_tecnici WHERE job_id = :job_id");
            $checkStmt->execute([':job_id' => $jobId]);
            $checkResult = $checkStmt->fetch(\PDO::FETCH_ASSOC);
            $hasRows = ($checkResult['cnt'] ?? 0) > 0;
        } catch (\PDOException $e) {
            $hasRows = false;
        }

        // Se NON esistono, sincronizza dall'estrazione AI
        if (!$hasRows) {
            try {
                $extractionStmt = $pdo->prepare("SELECT id, value_json 
                    FROM ext_extractions 
                    WHERE job_id = :job_id AND type_code = 'requisiti_tecnico_professionali' 
                    LIMIT 1");
                $extractionStmt->execute([':job_id' => $jobId]);
                $extractionRow = $extractionStmt->fetch(\PDO::FETCH_ASSOC);

                if ($extractionRow) {
                    self::syncRequisitiTecniciFromExtraction($jobId, $extractionRow);
                }
            } catch (\PDOException $e) {
                // Ignora errori di sincronizzazione
            }
        }

        // Leggi le righe dalla tabella
        try {
            $selectStmt = $pdo->prepare("SELECT 
                id,
                job_id,
                extraction_id,
                testo_sintetico,
                testo_completo,
                raw_json,
                note
            FROM gar_gara_requisiti_tecnici
            WHERE job_id = :job_id
            ORDER BY id ASC");
            $selectStmt->execute([':job_id' => $jobId]);
            $rows = $selectStmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $rows = [];
        }

        // Costruisci la struttura per la UI
        $headers = ['Requisito', 'Descrizione', 'Obbligatorio'];
        $tableRows = [];
        $entries = [];

        foreach ($rows as $row) {
            $requisito = $row['testo_sintetico'] ?? '—';
            $descrizione = $row['testo_completo'] ?? '—';
            
            // Estrai is_mandatory da raw_json
            $isMandatory = null;
            $rawJson = $row['raw_json'] ?? null;
            if ($rawJson) {
                $decoded = json_decode($rawJson, true);
                if (is_array($decoded) && isset($decoded['is_mandatory'])) {
                    $isMandatory = (bool) $decoded['is_mandatory'];
                }
            }
            $obbligatorio = $isMandatory === true ? 'Sì' : ($isMandatory === false ? 'No' : '—');

            $tableRows[] = [$requisito, $descrizione, $obbligatorio];

            $entries[] = [
                'requisito' => $requisito,
                'descrizione' => $descrizione,
                'obbligatorio' => $obbligatorio,
            ];
        }

        return [
            'type_code' => 'requisiti_tecnico_professionali',
            'type_display' => 'Requisiti tecnico-professionali',
            'table' => [
                'headers' => $headers,
                'rows' => $tableRows,
            ],
            'value_json' => [
                'entries' => $entries,
            ],
        ];
    }

    /**
     * Costruisce estrazione da gar_gara_requisiti_tecnici
     * Per i requisiti tecnico-professionali, mostra una tabella con: Requisito, Descrizione, Riferimento
     * Legge i requirements dal raw_json salvato nella tabella gar_gara_requisiti_tecnici
     * @deprecated Usare getRequisitiTecnici() invece
     */
    private static function buildEstrazioneFromNormalizedRequisitiTecniciTesto(array $row, int $jobId): ?array
    {
        global $database;
        $pdo = $database->connection;

        try {
            $stmt = $pdo->prepare("SELECT 
                        id,
                        job_id,
                        extraction_id,
                        testo_sintetico,
                        testo_completo,
                        raw_json
                    FROM gar_gara_requisiti_tecnici
                    WHERE job_id = :job_id
                    LIMIT 1");
            $stmt->execute([':job_id' => $jobId]);
            $rowData = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null; // Fallback ai dati raw
        }

        if (empty($rowData) || empty($rowData['raw_json'])) {
            return null; // Fallback ai dati raw
        }

        // Decodifica il raw_json per estrarre i requirements
        $rawJson = json_decode($rowData['raw_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($rawJson)) {
            return null; // Fallback ai dati raw
        }

        // Estrai i requirements dal raw_json
        $requirements = [];
        if (isset($rawJson['requirements']) && is_array($rawJson['requirements'])) {
            $requirements = $rawJson['requirements'];
        } elseif (isset($rawJson['answer']['requirements']) && is_array($rawJson['answer']['requirements'])) {
            $requirements = $rawJson['answer']['requirements'];
        }

        if (empty($requirements)) {
            return null; // Fallback ai dati raw
        }

        // Costruisce tabella con colonne fisse: Requisito, Descrizione, Riferimento
        $headers = ['Requisito', 'Descrizione', 'Riferimento'];
        $tableRows = [];
        $entries = [];

        foreach ($requirements as $req) {
            if (!is_array($req)) continue;

            // Estrai i dati del requisito
            $requisito = $req['requisito'] ?? $req['requirement'] ?? $req['ruolo'] ?? $req['role'] ?? '—';
            $descrizione = $req['descrizione'] ?? $req['description'] ?? $req['requisiti'] ?? $req['requirements'] ?? '—';
            
            // Riferimento: estrai da legal_citations, legal_reference o reference
            $riferimento = '—';
            if (!empty($req['legal_citations']) && is_array($req['legal_citations'])) {
                $citations = array_filter(array_map(function($cit) {
                    if (is_string($cit)) return $cit;
                    if (is_array($cit) && isset($cit['text'])) return $cit['text'];
                    if (is_array($cit) && isset($cit['reference'])) return $cit['reference'];
                    return null;
                }, $req['legal_citations']));
                if (!empty($citations)) {
                    $riferimento = implode('; ', array_slice($citations, 0, 3)); // Max 3 riferimenti
                }
            } elseif (!empty($req['legal_reference'])) {
                $riferimento = is_string($req['legal_reference']) 
                    ? $req['legal_reference'] 
                    : (is_array($req['legal_reference']) ? implode('; ', $req['legal_reference']) : '—');
            } elseif (!empty($req['reference'])) {
                $riferimento = is_string($req['reference']) 
                    ? $req['reference'] 
                    : (is_array($req['reference']) ? implode('; ', $req['reference']) : '—');
            }

            $tableRows[] = [
                $requisito,
                $descrizione,
                $riferimento
            ];

            // Costruisci entry per value_json
            $entries[] = [
                'requisito' => $requisito,
                'ruolo' => $requisito, // Alias per compatibilità
                'descrizione' => $descrizione,
                'requisiti' => $descrizione, // Alias per compatibilità
                'riferimento' => $riferimento,
                'obbligatorio' => ($req['obbligatorio'] ?? $req['mandatory'] ?? false),
                'extra_json' => $req,
            ];
        }

        $displayValue = count($entries) . ' requisito/i tecnico-professionale/i';

        $updatedAt = $row['extraction_created_at']
            ?? $row['job_updated_at']
            ?? $row['job_completed_at']
            ?? $row['job_created_at'];

        return [
            'id' => $row['id'] ?? 0,
            'job_id' => $jobId,
            'tipo' => 'requisiti_tecnico_professionali',
            'type_code' => 'requisiti_tecnico_professionali',
            'type_display' => \Services\AIextraction\ExtractionFormatter::displayNameForExtractionType('requisiti_tecnico_professionali'),
            'value_text' => $displayValue,
            'display_value' => $displayValue,
            'value_state' => 'table',
            'table' => [
                'headers' => $headers,
                'rows' => $tableRows
            ],
            'value_json' => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE),
            'confidence' => $row['confidence'] ?? null,
            'citations' => $row['citations'] ?? [],
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * Costruisce estrazione da gar_gara_idoneita_professionale
     * Usa le colonne corrette: ruolo, requisiti, obbligatorio, extra_json
     */
    private static function buildEstrazioneFromNormalizedIdoneitaProfessionale(array $row, int $jobId): ?array
    {
        global $database;
        $pdo = $database->connection;

        try {
            $stmt = $pdo->prepare("SELECT 
                        id,
                        job_id,
                        extraction_id,
                        ruolo,
                        requisiti,
                        obbligatorio,
                        extra_json
                    FROM gar_gara_idoneita_professionale
                    WHERE job_id = :job_id
                    ORDER BY id ASC");
            $stmt->execute([':job_id' => $jobId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null; // Fallback ai dati raw
        }

        if (empty($rows)) {
            return null; // Fallback ai dati raw
        }

        // Costruisce tabella con colonne fisse: Requisito, Descrizione, Riferimento
        $headers = ['Requisito', 'Descrizione', 'Riferimento'];
        $tableRows = [];
        $entries = [];

        foreach ($rows as $r) {
            $requisito = $r['ruolo'] ?? '—';
            $descrizione = $r['requisiti'] ?? '—';
            
            // Riferimento: estrai da extra_json (legal_citations o simili)
            $riferimento = '—';
            if (!empty($r['extra_json'])) {
                $decoded = json_decode($r['extra_json'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // Cerca legal_citations o riferimenti normativi
                    if (!empty($decoded['legal_citations']) && is_array($decoded['legal_citations'])) {
                        $citations = array_filter(array_map(function($cit) {
                            if (is_string($cit)) return $cit;
                            if (is_array($cit) && isset($cit['text'])) return $cit['text'];
                            if (is_array($cit) && isset($cit['reference'])) return $cit['reference'];
                            return null;
                        }, $decoded['legal_citations']));
                        if (!empty($citations)) {
                            $riferimento = implode('; ', array_slice($citations, 0, 3)); // Max 3 riferimenti
                        }
                    } elseif (!empty($decoded['legal_reference'])) {
                        $riferimento = is_string($decoded['legal_reference']) 
                            ? $decoded['legal_reference'] 
                            : (is_array($decoded['legal_reference']) ? implode('; ', $decoded['legal_reference']) : '—');
                    } elseif (!empty($decoded['reference'])) {
                        $riferimento = is_string($decoded['reference']) 
                            ? $decoded['reference'] 
                            : (is_array($decoded['reference']) ? implode('; ', $decoded['reference']) : '—');
                    }
                }
            }

            $tableRows[] = [
                $requisito,
                $descrizione,
                $riferimento
            ];

            // Costruisci entry per value_json
            $entries[] = [
                'requisito' => $requisito,
                'ruolo' => $requisito, // Alias per compatibilità
                'descrizione' => $descrizione,
                'requisiti' => $descrizione, // Alias per compatibilità
                'riferimento' => $riferimento,
                'obbligatorio' => ($r['obbligatorio'] === 1 || $r['obbligatorio'] === '1' || $r['obbligatorio'] === true),
                'extra_json' => !empty($r['extra_json']) ? json_decode($r['extra_json'], true) : null,
            ];
        }

        $displayValue = count($entries) . ' requisito/i';

        $updatedAt = $row['extraction_created_at']
            ?? $row['job_updated_at']
            ?? $row['job_completed_at']
            ?? $row['job_created_at'];

        return [
            'id' => $row['id'] ?? 0,
            'job_id' => $jobId,
            'tipo' => 'requisiti_idoneita_professionale_gruppo_lavoro',
            'type_code' => 'requisiti_idoneita_professionale_gruppo_lavoro',
            'type_display' => \Services\AIextraction\ExtractionFormatter::displayNameForExtractionType('requisiti_idoneita_professionale_gruppo_lavoro'),
            'value_text' => $displayValue,
            'display_value' => $displayValue,
            'value_state' => 'table',
            'table' => [
                'headers' => $headers,
                'rows' => $tableRows
            ],
            'value_json' => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE),
            'confidence' => $row['confidence'] ?? null,
            'citations' => $row['citations'] ?? [],
            'updated_at' => $updatedAt,
        ];
    }
}


