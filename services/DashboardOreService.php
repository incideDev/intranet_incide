<?php
/**
 * DashboardOreService.php
 * Service per la Dashboard Ore - analisi ore lavorate, straordinari, saturazione, anomalie.
 *
 * Fonte dati: project_time (tabella canonical sincronizzata da sync_project_time.php)
 * Colonne: projectTimeDate (DATE), workHours (DECIMAL), travelHours (DECIMAL), totalHours (DECIMAL),
 *          idBusinessUnit, idProject, idHResource, resourceDesc, hrRoleDesc, statusCode, statusDesc
 *
 * Nota: certStatus non supportato - project_time non ha campo certificazione.
 */

namespace Services;

class DashboardOreService
{
    /**
     * Collation standard per JOIN con akeron_project.
     * Definita UNA SOLA VOLTA per evitare duplicazioni.
     */
    private const COLLATE_STANDARD = 'utf8mb4_general_ci';

    /**
     * Helper: normalizza i filtri ore in input.
     * Accetta sia dateFrom/dateTo che year/month e restituisce sempre un array normalizzato.
     *
     * @param array $input Input raw dalla request
     * @return array Array normalizzato con chiavi: dateFrom, dateTo, year, month, buId, buCode, projectId, resourceId, pmId
     */
    private static function normalizeOreFilters(array $input): array
    {
        // Trim e sanitizzazione stringhe
        $dateFrom = trim($input['dateFrom'] ?? '');
        $dateTo = trim($input['dateTo'] ?? '');
        $year = trim($input['year'] ?? '');
        $month = trim($input['month'] ?? '');
        $buId = trim($input['buId'] ?? '');
        $buCode = trim($input['buCode'] ?? '');
        $projectId = trim($input['projectId'] ?? '');
        $resourceId = trim($input['resourceId'] ?? '');
        $pmId = trim($input['pmId'] ?? '');

        // Validazione year: deve essere numerico 4 cifre o vuoto
        if ($year !== '' && !preg_match('/^\d{4}$/', $year)) {
            $year = '';
        }

        // Validazione month: deve essere 01-12 o vuoto
        if ($month !== '') {
            $monthInt = intval($month);
            if ($monthInt < 1 || $monthInt > 12) {
                $month = '';
            } else {
                $month = str_pad($monthInt, 2, '0', STR_PAD_LEFT);
            }
        }

        // Validazione date: formato YYYY-MM-DD
        if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = '';
        }
        if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateTo = '';
        }

        // Conversione year/month → dateFrom/dateTo se date non specificate
        if ($dateFrom === '' && $dateTo === '' && $year !== '') {
            if ($month !== '') {
                // Anno + mese specifico
                $dateFrom = "{$year}-{$month}-01";
                $dateTo = date('Y-m-t', strtotime($dateFrom));
            } else {
                // Solo anno → intero anno
                $dateFrom = "{$year}-01-01";
                $dateTo = "{$year}-12-31";
            }
        }

        // Normalizza valori "0" a stringa vuota (convenzione: 0 = non filtrato)
        $buId = ($buId === '0') ? '' : $buId;
        $buCode = ($buCode === '0') ? '' : $buCode;
        $projectId = ($projectId === '0') ? '' : $projectId;
        $resourceId = ($resourceId === '0') ? '' : $resourceId;
        $pmId = ($pmId === '0') ? '' : $pmId;

        // buCode e buId: se uno è vuoto prendi l'altro (sono intercambiabili)
        if ($buId === '' && $buCode !== '') {
            $buId = $buCode;
        } elseif ($buCode === '' && $buId !== '') {
            $buCode = $buId;
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'year' => $year,
            'month' => $month,
            'buId' => $buId,
            'buCode' => $buCode,
            'projectId' => $projectId,
            'resourceId' => $resourceId,
            'pmId' => $pmId,
        ];
    }

    /**
     * Helper: genera frammento SQL per LEFT JOIN con akeron_project.
     * Gestisce il mismatch di collation in modo centralizzato.
     *
     * @param string $leftExpr   Espressione sinistra del JOIN (es. "ap.IdProject")
     * @param string $rightExpr  Espressione destra del JOIN (es. "pt.idProject")
     * @return string Frammento SQL completo per il LEFT JOIN
     *
     * Esempio output: "LEFT JOIN akeron_project ap ON ap.IdProject COLLATE utf8mb4_general_ci = pt.idProject COLLATE utf8mb4_general_ci"
     */
    private static function joinAkeronProjectSafe(string $leftExpr = 'ap.IdProject', string $rightExpr = 'pt.idProject'): string
    {
        $collate = self::COLLATE_STANDARD;
        return "LEFT JOIN akeron_project ap ON {$leftExpr} COLLATE {$collate} = {$rightExpr} COLLATE {$collate}";
    }

    /**
     * Helper: restituisce i campi SELECT per lo stato progetto da akeron_project.
     * Usa MAX() per compatibilità con GROUP BY.
     *
     * @param string $alias Alias della tabella akeron_project (default: "ap")
     * @return string Frammento SQL per SELECT
     */
    private static function selectProjectStatusFields(string $alias = 'ap'): string
    {
        return "MAX({$alias}.ProjectStatusDesc) AS projectStatusDesc, MAX({$alias}.ProjectStatusCode) AS projectStatusCode";
    }

    /**
     * Helper: costruisce WHERE clause con filtri data/BU/progetto/risorsa.
     * certStatus: se valorizzato, restituisce errore (non supportato).
     */
    private static function buildDateFilter(array $input): array
    {
        $dateFrom = trim($input['dateFrom'] ?? '');
        $dateTo = trim($input['dateTo'] ?? '');
        $buId = trim($input['buId'] ?? '');
        $projectId = trim($input['projectId'] ?? '');
        $resourceId = trim($input['resourceId'] ?? '');
        $certStatus = trim($input['certStatus'] ?? '');

        // certStatus non supportato
        if ($certStatus !== '') {
            return ['error' => 'certStatus non supportato: la certificazione ore non è disponibile in project_time'];
        }

        // Default: mese corrente
        if (!$dateFrom)
            $dateFrom = date('Y-m-01');
        if (!$dateTo)
            $dateTo = date('Y-m-t');

        // Filtro date: BETWEEN su colonna DATE
        $where = "pt.projectTimeDate BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];

        if ($buId !== '' && $buId !== '0') {
            $where .= " AND pt.idBusinessUnit = ?";
            $params[] = $buId;
        }
        if ($projectId !== '' && $projectId !== '0') {
            $where .= " AND pt.idProject = ?";
            $params[] = $projectId;
        }
        if ($resourceId !== '' && $resourceId !== '0') {
            $where .= " AND pt.idHResource = ?";
            $params[] = $resourceId;
        }

        return ['where' => $where, 'params' => $params, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo];
    }

    /**
     * Helper: calcola periodo precedente (stesso numero di giorni).
     */
    private static function shiftPeriod(string $dateFrom, string $dateTo): array
    {
        $from = new \DateTime($dateFrom);
        $to = new \DateTime($dateTo);
        $diff = $from->diff($to)->days + 1;
        $prevTo = (clone $from)->modify('-1 day');
        $prevFrom = (clone $prevTo)->modify("-{$diff} days")->modify('+1 day');
        return [$prevFrom->format('Y-m-d'), $prevTo->format('Y-m-d')];
    }

    /**
     * 1. getFilterOptions - Popola le select filtro (BU, Progetti, Risorse).
     */
    public static function getFilterOptions(): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        // Business Units
        $buResult = $database->query(
            "SELECT DISTINCT pt.idBusinessUnit AS IdBusinessUnit,
                    pt.idBusinessUnit AS DescrBusinessUnit
             FROM project_time pt
             WHERE pt.idBusinessUnit IS NOT NULL AND pt.idBusinessUnit != ''
             ORDER BY pt.idBusinessUnit",
            [],
            __FILE__
        );
        $businessUnits = $buResult->fetchAll(\PDO::FETCH_ASSOC);

        // Progetti (usa idProject + eventuale label se presente)
        $projResult = $database->query(
            "SELECT DISTINCT pt.idProject AS IdProject,
                    pt.idProject AS ProjectDesc
             FROM project_time pt
             WHERE pt.idProject IS NOT NULL AND pt.idProject != ''
             ORDER BY pt.idProject",
            [],
            __FILE__
        );
        $projects = $projResult->fetchAll(\PDO::FETCH_ASSOC);

        // Risorse (usa resourceDesc se presente)
        $resResult = $database->query(
            "SELECT DISTINCT pt.idHResource AS IdHResource,
                    COALESCE(pt.resourceDesc, CAST(pt.idHResource AS CHAR)) AS ResourceName
             FROM project_time pt
             WHERE pt.idHResource IS NOT NULL
             ORDER BY ResourceName",
            [],
            __FILE__
        );
        $resources = $resResult->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'data' => [
                'businessUnits' => $businessUnits,
                'projects' => $projects,
                'resources' => $resources,
            ],
        ];
    }

    /**
     * 2. getKPI - KPI principali: ore totali, straordinari, saturazione.
     * Nota: certificazione non disponibile.
     */
    public static function getKPI(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filter = self::buildDateFilter($input);
        if (isset($filter['error'])) {
            return ['success' => false, 'message' => $filter['error']];
        }

        $where = $filter['where'];
        $params = $filter['params'];
        $dateFrom = $filter['dateFrom'];
        $dateTo = $filter['dateTo'];

        $mainResult = $database->query(
            "SELECT
                SUM(pt.workHours) AS totalHours,
                SUM(pt.travelHours) AS travelHours,
                COUNT(DISTINCT pt.idHResource) AS resourceCount,
                COUNT(DISTINCT pt.projectTimeDate) AS workingDays
             FROM project_time pt
             WHERE {$where}",
            $params,
            __FILE__
        );
        $main = $mainResult->fetch(\PDO::FETCH_ASSOC);

        $totalHours = floatval($main['totalHours'] ?? 0);
        $travelHours = floatval($main['travelHours'] ?? 0);
        $resourceCount = intval($main['resourceCount'] ?? 0);
        $workingDays = intval($main['workingDays'] ?? 0);

        if ($totalHours == 0) {
            return ['success' => true, 'data' => ['totalHours' => 0]];
        }

        $avgHoursPerResource = $resourceCount > 0 ? round($totalHours / $resourceCount, 1) : 0;

        // Straordinari: ore > 8h/giorno per risorsa
        $otResult = $database->query(
            "SELECT SUM(GREATEST(day_total - 8.0, 0)) AS overtimeHours
             FROM (
                 SELECT pt.idHResource,
                        pt.projectTimeDate,
                        SUM(pt.workHours) AS day_total
                 FROM project_time pt
                 WHERE {$where}
                 GROUP BY pt.idHResource, pt.projectTimeDate
             ) daily",
            $params,
            __FILE__
        );
        $otRow = $otResult->fetch(\PDO::FETCH_ASSOC);
        $overtimeHours = round(floatval($otRow['overtimeHours'] ?? 0), 1);
        $overtimePct = $totalHours > 0 ? round($overtimeHours / $totalHours * 100, 1) : 0;

        // Saturazione approssimativa
        $dFrom = new \DateTime($dateFrom);
        $dTo = new \DateTime($dateTo);
        $totalDays = $dFrom->diff($dTo)->days + 1;
        $workableDays = intval($totalDays * 5 / 7);
        $expectedHours = $resourceCount * $workableDays * 8;
        $avgSaturation = $expectedHours > 0 ? round($totalHours / $expectedHours * 100, 1) : 0;
        $oversaturatedCount = 0;

        // Periodo precedente (per varianza)
        [$prevFrom, $prevTo] = self::shiftPeriod($dateFrom, $dateTo);
        $prevParams = $params;
        $prevParams[0] = $prevFrom;
        $prevParams[1] = $prevTo;

        $prevResult = $database->query(
            "SELECT SUM(pt.workHours) AS totalHours
             FROM project_time pt
             WHERE {$where}",
            $prevParams,
            __FILE__
        );
        $prevRow = $prevResult->fetch(\PDO::FETCH_ASSOC);
        $prevTotalHours = floatval($prevRow['totalHours'] ?? 0);

        $totalHoursDelta = $prevTotalHours > 0 ? round(($totalHours - $prevTotalHours) / $prevTotalHours * 100, 1) : 0;
        $totalHoursDeltaAbs = round(abs($totalHours - $prevTotalHours), 0);

        // Target configurabili
        $targetAvgHours = 184;
        $targetCertPct = 80;
        $targetHours = $resourceCount * $targetAvgHours;

        return [
            'success' => true,
            'data' => [
                'totalHours' => round($totalHours, 0),
                'totalHoursDelta' => $totalHoursDelta,
                'totalHoursDeltaAbs' => $totalHoursDeltaAbs,
                'resourceCount' => $resourceCount,
                'workingDays' => $workingDays,
                'targetHours' => $targetHours,
                'overtimeHours' => $overtimeHours,
                'overtimeDelta' => 0,
                'overtimePct' => $overtimePct,
                'avgHoursPerResource' => $avgHoursPerResource,
                'avgHoursPerResourceDelta' => 0,
                'targetAvgHours' => $targetAvgHours,
                'avgSaturation' => $avgSaturation,
                'avgSaturationDelta' => 0,
                'oversaturatedCount' => $oversaturatedCount,
                'travelHours' => round($travelHours, 0),
                'certPct' => 0,
                'certPctDelta' => 0,
                'targetCertPct' => $targetCertPct,
                'certNotSupported' => true,
            ],
        ];
    }

    /**
     * 3. getTrend - Trend giornaliero/settimanale ore lavorate.
     */
    public static function getTrend(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filter = self::buildDateFilter($input);
        if (isset($filter['error'])) {
            return ['success' => false, 'message' => $filter['error']];
        }

        $where = $filter['where'];
        $params = $filter['params'];

        $dayResult = $database->query(
            "SELECT
                work_date,
                SUM(day_total) AS total,
                SUM(GREATEST(day_total - 8.0, 0)) AS overtime
             FROM (
                 SELECT
                     pt.projectTimeDate AS work_date,
                     pt.idHResource,
                     SUM(pt.workHours) AS day_total
                 FROM project_time pt
                 WHERE {$where}
                 GROUP BY pt.projectTimeDate, pt.idHResource
             ) daily_per_res
             GROUP BY work_date
             ORDER BY work_date ASC",
            $params,
            __FILE__
        );
        $dailyRows = $dayResult->fetchAll(\PDO::FETCH_ASSOC);

        $days = array_map(function ($row) {
            $dt = new \DateTime($row['work_date']);
            return [
                'label' => $dt->format('d/m'),
                'total' => round(floatval($row['total']), 1),
                'overtime' => round(floatval($row['overtime']), 1),
            ];
        }, $dailyRows);

        // Aggregazione settimanale
        $weekBuckets = [];
        foreach ($dailyRows as $row) {
            $dt = new \DateTime($row['work_date']);
            $wKey = $dt->format('o-W');
            $wLbl = 'Sett. ' . $dt->format('W');
            if (!isset($weekBuckets[$wKey])) {
                $weekBuckets[$wKey] = ['label' => $wLbl, 'total' => 0, 'overtime' => 0];
            }
            $weekBuckets[$wKey]['total'] += floatval($row['total']);
            $weekBuckets[$wKey]['overtime'] += floatval($row['overtime']);
        }
        $weeks = array_values(array_map(function ($w) {
            return [
                'label' => $w['label'],
                'total' => round($w['total'], 1),
                'overtime' => round($w['overtime'], 1),
            ];
        }, $weekBuckets));

        return [
            'success' => true,
            'data' => ['days' => $days, 'weeks' => $weeks],
        ];
    }

    /**
     * 4. getHeatmap - Heatmap presenze per giorno della settimana.
     */
    public static function getHeatmap(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filter = self::buildDateFilter($input);
        if (isset($filter['error'])) {
            return ['success' => false, 'message' => $filter['error']];
        }

        $where = $filter['where'];
        $params = $filter['params'];

        $result = $database->query(
            "SELECT
                DAYOFWEEK(pt.projectTimeDate) AS dayOfWeek,
                WEEK(pt.projectTimeDate, 1) AS weekNo,
                DATE_FORMAT(pt.projectTimeDate, '%d/%m') AS shortLabel,
                SUM(pt.workHours) AS hours
             FROM project_time pt
             WHERE {$where}
             GROUP BY dayOfWeek, weekNo, shortLabel
             ORDER BY weekNo, dayOfWeek",
            $params,
            __FILE__
        );
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);

        $weeks = array_values(array_unique(array_column($rows, 'weekNo')));
        sort($weeks);

        $cells = array_map(function ($row) {
            return [
                'dayOfWeek' => intval($row['dayOfWeek']),
                'weekNo' => intval($row['weekNo']),
                'hours' => round(floatval($row['hours']), 1),
                'label' => $row['shortLabel'],
                'isHoliday' => false,
            ];
        }, $rows);

        return [
            'success' => true,
            'data' => ['cells' => $cells, 'weeks' => $weeks],
        ];
    }

    /**
     * 5. getCommesse - Top commesse per ore lavorate.
     * variancePct calcolato vs project_time_budget (se disponibile).
     * Alert priority: commessa_chiusa > budget > ore_eccessive
     */
    public static function getCommesse(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filter = self::buildDateFilter($input);
        if (isset($filter['error'])) {
            return ['success' => false, 'message' => $filter['error']];
        }

        $where = $filter['where'];
        $params = $filter['params'];
        $dateFrom = $filter['dateFrom'];
        $dateTo = $filter['dateTo'];

        // Estrai filtri opzionali per subquery budget
        $buId = trim($input['buId'] ?? '');
        $projectId = trim($input['projectId'] ?? '');
        $resourceId = trim($input['resourceId'] ?? '');

        // Subquery budget: aggrega per commessa nel periodo
        $budgetWhere = "b.budgetDate BETWEEN ? AND ?";
        $budgetParams = [$dateFrom, $dateTo];

        if ($buId !== '' && $buId !== '0') {
            $budgetWhere .= " AND b.idBusinessUnit = ?";
            $budgetParams[] = $buId;
        }
        if ($projectId !== '' && $projectId !== '0') {
            $budgetWhere .= " AND b.idProject = ?";
            $budgetParams[] = $projectId;
        }
        if ($resourceId !== '' && $resourceId !== '0') {
            $budgetWhere .= " AND b.idHResource = ?";
            $budgetParams[] = $resourceId;
        }

        // Query principale con LEFT JOIN su budget aggregato e akeron_project per stato
        // Nota: COALESCE per gestire assenza budget (tabella potrebbe non esistere o essere vuota)
        // BINARY usato nel JOIN budget per evitare mismatch collation
        // Usa joinAkeronProjectSafe() per JOIN con akeron_project
        $joinAkeron = self::joinAkeronProjectSafe();
        $sql = "
            SELECT
                pt.idProject AS IdProject,
                pt.idProject AS ProjectDesc,
                SUM(pt.totalHours) AS totalHours,
                SUM(pt.travelHours) AS travelHours,
                COALESCE(bAgg.budgetHours, 0) AS budgetHours,
                MAX(ap.ProjectStatusDesc) AS projectStatusDesc,
                MAX(ap.ProjectStatusCode) AS projectStatusCode
            FROM project_time pt
            LEFT JOIN (
                SELECT
                    b.idProject,
                    SUM(b.budgetTotalHours) AS budgetHours
                FROM project_time_budget b
                WHERE {$budgetWhere}
                GROUP BY b.idProject
            ) bAgg ON BINARY bAgg.idProject = BINARY pt.idProject
            {$joinAkeron}
            WHERE {$where}
            GROUP BY pt.idProject
            ORDER BY totalHours DESC
            LIMIT 20
        ";

        // Merge params: prima budget subquery, poi main where
        $allParams = array_merge($budgetParams, $params);

        try {
            $result = $database->query($sql, $allParams, __FILE__);
            $rows = $result->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Fallback se tabella budget non esiste: query senza JOIN budget ma con akeron_project per stato
            // ATTENZIONE: budget non disponibile - variancePct sarà sempre 0
            error_log("getCommesse: budget table unavailable (table missing or error). Fallback attivo. Error: " . $e->getMessage());
            $joinAkeronFallback = self::joinAkeronProjectSafe();
            $result = $database->query(
                "SELECT
                    pt.idProject AS IdProject,
                    pt.idProject AS ProjectDesc,
                    SUM(pt.totalHours) AS totalHours,
                    SUM(pt.travelHours) AS travelHours,
                    0 AS budgetHours,
                    MAX(ap.ProjectStatusDesc) AS projectStatusDesc,
                    MAX(ap.ProjectStatusCode) AS projectStatusCode
                 FROM project_time pt
                 {$joinAkeronFallback}
                 WHERE {$where}
                 GROUP BY pt.idProject
                 ORDER BY totalHours DESC
                 LIMIT 20",
                $params,
                __FILE__
            );
            $rows = $result->fetchAll(\PDO::FETCH_ASSOC);
        }

        $data = array_map(function ($row) {
            $totalHours = round(floatval($row['totalHours']), 0);
            $travelHours = round(floatval($row['travelHours'] ?? 0), 0);
            $budgetHours = round(floatval($row['budgetHours'] ?? 0), 0);
            $estimatedCost = $totalHours * 68;

            // Stato commessa da akeron_project
            $projectStatusDesc = $row['projectStatusDesc'] ?? null;
            $projectStatusCode = $row['projectStatusCode'] ?? null;
            $projectStatus = $projectStatusDesc ?: 'n/d';

            // Calcolo variancePct: (actual - budget) / budget * 100
            // Era hardcoded a 0 (linea 430 originale)
            $variancePct = 0;
            if ($budgetHours > 0) {
                $variancePct = round(($totalHours - $budgetHours) / $budgetHours * 100, 1);
            }

            // Alert logic - priority: commessa_chiusa > budget > ore_eccessive
            $hasAlert = false;
            $alertType = null;

            // Alert commessa_chiusa: se stato indica chiusura e ci sono ore imputate
            // ProjectStatusCode tipici: "CL" = Chiusa, "OP" = Aperta (dipende da configurazione Akeron)
            if ($projectStatusDesc && stripos($projectStatusDesc, 'Chius') !== false && $totalHours > 0) {
                $hasAlert = true;
                $alertType = 'commessa_chiusa';
            }

            // Alert budget: actual > budget (sforamento)
            if (!$hasAlert && $budgetHours > 0 && $totalHours > $budgetHours) {
                $hasAlert = true;
                $alertType = 'budget';
            }

            // Alert ore_eccessive: > 500h nel periodo (soglia indicativa)
            if (!$hasAlert && $totalHours > 500) {
                $hasAlert = true;
                $alertType = 'ore_eccessive';
            }

            return [
                'idProject' => $row['IdProject'],
                'projectCode' => $row['IdProject'],
                'projectDesc' => $row['ProjectDesc'],
                'projectStatus' => $projectStatus,
                'projectStatusDesc' => $projectStatusDesc,
                'projectStatusCode' => $projectStatusCode,
                'totalHours' => $totalHours,
                'travelHours' => $travelHours,
                'budgetHours' => $budgetHours,
                'billedHours' => 0,
                'estimatedCost' => $estimatedCost,
                'variancePct' => $variancePct,
                'hasAlert' => $hasAlert,
                'alertType' => $alertType,
            ];
        }, $rows);

        return ['success' => true, 'data' => $data];
    }

    /**
     * 6. getRisorse - Analisi per risorsa con budget, avanzamento, trend.
     */
    public static function getRisorse(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filter = self::buildDateFilter($input);
        if (isset($filter['error'])) {
            return ['success' => false, 'message' => $filter['error']];
        }

        $where = $filter['where'];
        $params = $filter['params'];
        $dateFrom = $filter['dateFrom'];
        $dateTo = $filter['dateTo'];

        // Estrai filtri opzionali per subquery budget
        $buId = trim($input['buId'] ?? '');
        $projectId = trim($input['projectId'] ?? '');

        // Dati actual per risorsa
        $result = $database->query(
            "SELECT
                pt.idHResource AS IdHResource,
                COALESCE(pt.resourceDesc, CAST(pt.idHResource AS CHAR)) AS ResourceName,
                MAX(pt.hrRoleDesc) AS roleDesc,
                pt.idBusinessUnit AS buDesc,
                SUM(pt.totalHours) AS totalHours,
                SUM(pt.travelHours) AS travelHours,
                COUNT(DISTINCT pt.idProject) AS projectCount
             FROM project_time pt
             WHERE {$where}
             GROUP BY pt.idHResource, pt.resourceDesc, pt.idBusinessUnit
             ORDER BY totalHours DESC",
            $params,
            __FILE__
        );
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);

        // Budget per risorsa (se tabella esiste)
        $budgetMap = [];
        try {
            $budgetWhere = "b.budgetDate BETWEEN ? AND ?";
            $budgetParams = [$dateFrom, $dateTo];

            if ($buId !== '' && $buId !== '0') {
                $budgetWhere .= " AND b.idBusinessUnit = ?";
                $budgetParams[] = $buId;
            }
            if ($projectId !== '' && $projectId !== '0') {
                $budgetWhere .= " AND b.idProject = ?";
                $budgetParams[] = $projectId;
            }

            $budgetResult = $database->query(
                "SELECT
                    b.idHResource,
                    SUM(b.budgetTotalHours) AS budgetHours
                 FROM project_time_budget b
                 WHERE {$budgetWhere}
                 GROUP BY b.idHResource",
                $budgetParams,
                __FILE__
            );
            foreach ($budgetResult->fetchAll(\PDO::FETCH_ASSOC) as $br) {
                $budgetMap[$br['idHResource']] = round(floatval($br['budgetHours']), 0);
            }
        } catch (\Exception $e) {
            error_log("getRisorse: budget table unavailable. " . $e->getMessage());
        }

        // Straordinari per risorsa
        $otResult = $database->query(
            "SELECT idHResource, SUM(GREATEST(day_total - 8.0, 0)) AS overtimeHours
             FROM (
                 SELECT pt.idHResource,
                        pt.projectTimeDate,
                        SUM(pt.workHours) AS day_total
                 FROM project_time pt
                 WHERE {$where}
                 GROUP BY pt.idHResource, pt.projectTimeDate
             ) d
             GROUP BY idHResource",
            $params,
            __FILE__
        );
        $otMap = [];
        foreach ($otResult->fetchAll(\PDO::FETCH_ASSOC) as $ot) {
            $otMap[$ot['idHResource']] = round(floatval($ot['overtimeHours']), 1);
        }

        // Trend 8 settimane
        $trendResult = $database->query(
            "SELECT
                pt.idHResource,
                WEEK(pt.projectTimeDate, 1) AS weekNo,
                SUM(pt.workHours) AS weekHours
             FROM project_time pt
             WHERE pt.projectTimeDate >= DATE_SUB(?, INTERVAL 8 WEEK)
             GROUP BY pt.idHResource, weekNo
             ORDER BY pt.idHResource, weekNo",
            [$dateTo],
            __FILE__
        );
        $trendMap = [];
        foreach ($trendResult->fetchAll(\PDO::FETCH_ASSOC) as $tr) {
            $trendMap[$tr['idHResource']][] = round(floatval($tr['weekHours']), 0);
        }

        // Saturazione approssimativa
        $dFrom = new \DateTime($dateFrom);
        $dTo = new \DateTime($dateTo);
        $totalDays = $dFrom->diff($dTo)->days + 1;
        $workableDays = intval($totalDays * 5 / 7);
        $expectedHoursPerResource = $workableDays * 8;

        $data = array_map(function ($row) use ($otMap, $trendMap, $budgetMap, $expectedHoursPerResource) {
            $resId = $row['IdHResource'];
            $totalHours = round(floatval($row['totalHours']), 0);
            $travelHours = round(floatval($row['travelHours'] ?? 0), 0);
            $budgetHours = $budgetMap[$resId] ?? 0;
            $projectCount = intval($row['projectCount'] ?? 0);

            // Avanzamento % vs budget (non più saturazione)
            $avanzPct = $budgetHours > 0
                ? round($totalHours / $budgetHours * 100, 0)
                : 0;

            $saturationPct = $expectedHoursPerResource > 0
                ? round($totalHours / $expectedHoursPerResource * 100, 0)
                : 0;

            $overtimeHours = $otMap[$resId] ?? 0;
            $weeklyTrend = $trendMap[$resId] ?? [];
            while (count($weeklyTrend) < 8)
                array_unshift($weeklyTrend, 0);
            $weeklyTrend = array_slice($weeklyTrend, -8);

            // Parsing nome da resourceDesc
            $resourceName = $row['ResourceName'] ?? '';
            $nameParts = explode(' ', $resourceName, 2);
            $firstname = $nameParts[0] ?? '';
            $surname = $nameParts[1] ?? '';

            // Immagine profilo (usa funzione globale se disponibile)
            $imagePath = '';
            if (function_exists('getProfileImage') && $resourceName) {
                $imagePath = getProfileImage($resourceName, 'nominativo');
            }

            return [
                'idHResource' => $resId,
                'firstname' => $firstname,
                'surname' => $surname,
                'buDesc' => $row['buDesc'] ?? '',
                'roleDesc' => $row['roleDesc'] ?? '',
                'totalHours' => $totalHours,
                'travelHours' => $travelHours,
                'budgetHours' => $budgetHours,
                'overtimeHours' => $overtimeHours,
                'avanzPct' => $avanzPct,
                'saturationPct' => $saturationPct,
                'projectCount' => $projectCount,
                'anomalyCount' => 0,
                'weeklyTrend' => $weeklyTrend,
                'imagePath' => $imagePath,
            ];
        }, $rows);

        return ['success' => true, 'data' => $data];
    }

    /**
     * 7. getAnomalies - Anomalie: giornate >10h, medie eccessive.
     * Nota: anomalie su commesse chiuse e certificazione non disponibili.
     */
    public static function getAnomalies(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filter = self::buildDateFilter($input);
        if (isset($filter['error'])) {
            return ['success' => false, 'message' => $filter['error']];
        }

        $where = $filter['where'];
        $params = $filter['params'];

        $anomalies = [];

        // A1: Risorse con >10h in un giorno (subquery per evitare HAVING su alias)
        $a1Result = $database->query(
            "SELECT * FROM (
                SELECT
                    pt.idHResource AS IdHResource,
                    COALESCE(pt.resourceDesc, CAST(pt.idHResource AS CHAR)) AS ResourceName,
                    pt.projectTimeDate AS work_date,
                    SUM(pt.workHours) AS day_total,
                    MAX(pt.idProject) AS sample_project
                FROM project_time pt
                WHERE {$where}
                GROUP BY pt.idHResource, pt.resourceDesc, pt.projectTimeDate
             ) daily_summary
             WHERE day_total > 10
             ORDER BY day_total DESC
             LIMIT 10",
            $params,
            __FILE__
        );
        foreach ($a1Result->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $dt = new \DateTime($r['work_date']);
            $anomalies[] = [
                'severity' => 'critical',
                'title' => "{$r['ResourceName']} - giornata >" . round($r['day_total'], 1) . "h",
                'meta' => $dt->format('d/m/Y') . ' - Progetto: ' . $r['sample_project'],
                'link' => 'Visualizza dettaglio',
                'linkHref' => '#',
            ];
        }

        // A2: Risorse con media giornaliera > 9h (subquery per evitare HAVING su alias)
        $a2Result = $database->query(
            "SELECT * FROM (
                SELECT
                    pt.idHResource AS IdHResource,
                    COALESCE(pt.resourceDesc, CAST(pt.idHResource AS CHAR)) AS ResourceName,
                    SUM(pt.workHours) AS totalHours,
                    COUNT(DISTINCT pt.projectTimeDate) AS daysWorked
                FROM project_time pt
                WHERE {$where}
                GROUP BY pt.idHResource, pt.resourceDesc
             ) resource_summary
             WHERE daysWorked > 0 AND (totalHours / daysWorked) > 9
             ORDER BY (totalHours / daysWorked) DESC
             LIMIT 5",
            $params,
            __FILE__
        );
        foreach ($a2Result->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $avg = round($r['totalHours'] / max(1, $r['daysWorked']), 1);
            $anomalies[] = [
                'severity' => 'warning',
                'title' => "Media alta: {$r['ResourceName']}",
                'meta' => "Media {$avg}h/giorno su {$r['daysWorked']} giorni",
                'link' => 'Dettagli',
                'linkHref' => '#',
            ];
        }

        if (empty($anomalies)) {
            $anomalies[] = [
                'severity' => 'info',
                'title' => 'Nessuna anomalia rilevata',
                'meta' => 'Tutti i dati nel periodo sono nella norma',
                'link' => '',
                'linkHref' => '#',
            ];
        }

        return ['success' => true, 'data' => $anomalies];
    }

    /**
     * 8. getResourceDetail - Dettaglio risorsa per drawer.
     */
    public static function getResourceDetail(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $resourceId = trim($input['resourceId'] ?? '');
        if ($resourceId === '') {
            return ['success' => false, 'message' => 'Risorsa non specificata'];
        }

        $filter = self::buildDateFilter($input);
        if (isset($filter['error'])) {
            return ['success' => false, 'message' => $filter['error']];
        }

        $where = $filter['where'];
        $params = $filter['params'];

        $result = $database->query(
            "SELECT
                pt.idProject AS IdProject,
                pt.idProject AS ProjectDesc,
                SUM(pt.workHours) AS hours,
                SUM(pt.travelHours) AS travelHours
             FROM project_time pt
             WHERE {$where}
             GROUP BY pt.idProject
             ORDER BY hours DESC",
            $params,
            __FILE__
        );
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);

        $commesse = array_map(function ($r) {
            return [
                'idProject' => $r['IdProject'],
                'projectCode' => $r['IdProject'],
                'projectDesc' => $r['ProjectDesc'],
                'hours' => round(floatval($r['hours']), 0),
                'travelHours' => round(floatval($r['travelHours'] ?? 0), 0),
            ];
        }, $rows);

        return [
            'success' => true,
            'data' => ['commesse' => $commesse],
        ];
    }

    /**
     * 9. sendCertReminder - Disabilitato (certificazione non disponibile).
     */
    public static function sendCertReminder(array $input): array
    {
        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        return [
            'success' => false,
            'message' => 'Funzione non disponibile: la certificazione ore non è gestita in project_time'
        ];
    }

    /**
     * 10. getProjectDailySeries - Serie giornaliera actual vs budget per commessa.
     * Per Chart.js drilldown.
     */
    public static function getProjectDailySeries(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = trim($input['idProject'] ?? '');
        if ($idProject === '') {
            return ['success' => false, 'message' => 'idProject richiesto'];
        }

        $dateFrom = trim($input['dateFrom'] ?? '');
        $dateTo = trim($input['dateTo'] ?? '');
        $buId = trim($input['buId'] ?? '');
        $resourceId = trim($input['resourceId'] ?? '');

        if (!$dateFrom)
            $dateFrom = date('Y-m-01');
        if (!$dateTo)
            $dateTo = date('Y-m-t');

        // Genera tutti i giorni nel range
        $allDays = [];
        $current = new \DateTime($dateFrom);
        $end = new \DateTime($dateTo);
        while ($current <= $end) {
            $allDays[$current->format('Y-m-d')] = ['date' => $current->format('Y-m-d'), 'actualHours' => 0, 'budgetHours' => 0];
            $current->modify('+1 day');
        }

        // Actual: da project_time
        $actualWhere = "pt.projectTimeDate BETWEEN ? AND ? AND BINARY pt.idProject = ?";
        $actualParams = [$dateFrom, $dateTo, $idProject];

        if ($buId !== '' && $buId !== '0') {
            $actualWhere .= " AND pt.idBusinessUnit = ?";
            $actualParams[] = $buId;
        }
        if ($resourceId !== '' && $resourceId !== '0') {
            $actualWhere .= " AND pt.idHResource = ?";
            $actualParams[] = $resourceId;
        }

        $actualResult = $database->query(
            "SELECT
                pt.projectTimeDate AS dayDate,
                SUM(pt.totalHours) AS dayHours
             FROM project_time pt
             WHERE {$actualWhere}
             GROUP BY pt.projectTimeDate
             ORDER BY pt.projectTimeDate ASC",
            $actualParams,
            __FILE__
        );
        foreach ($actualResult->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $d = $row['dayDate'];
            if (isset($allDays[$d])) {
                $allDays[$d]['actualHours'] = round(floatval($row['dayHours']), 1);
            }
        }

        // Budget: da project_time_budget (se esiste)
        try {
            $budgetWhere = "b.budgetDate BETWEEN ? AND ? AND BINARY b.idProject = ?";
            $budgetParams = [$dateFrom, $dateTo, $idProject];

            if ($buId !== '' && $buId !== '0') {
                $budgetWhere .= " AND b.idBusinessUnit = ?";
                $budgetParams[] = $buId;
            }
            if ($resourceId !== '' && $resourceId !== '0') {
                $budgetWhere .= " AND b.idHResource = ?";
                $budgetParams[] = $resourceId;
            }

            $budgetResult = $database->query(
                "SELECT
                    b.budgetDate AS dayDate,
                    SUM(b.budgetTotalHours) AS dayHours
                 FROM project_time_budget b
                 WHERE {$budgetWhere}
                 GROUP BY b.budgetDate
                 ORDER BY b.budgetDate ASC",
                $budgetParams,
                __FILE__
            );
            foreach ($budgetResult->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $d = $row['dayDate'];
                if (isset($allDays[$d])) {
                    $allDays[$d]['budgetHours'] = round(floatval($row['dayHours']), 1);
                }
            }
        } catch (\Exception $e) {
            error_log("getProjectDailySeries: budget table unavailable. " . $e->getMessage());
        }

        // Calcola cumulativi
        $days = array_values($allDays);
        $actualCum = 0;
        $budgetCum = 0;
        foreach ($days as &$d) {
            $actualCum += $d['actualHours'];
            $budgetCum += $d['budgetHours'];
            $d['actualCum'] = round($actualCum, 1);
            $d['budgetCum'] = round($budgetCum, 1);
        }
        unset($d);

        // Totali
        $totals = [
            'actualTotal' => $actualCum,
            'budgetTotal' => $budgetCum,
            'residuo' => round($budgetCum - $actualCum, 1),
            'avanzPct' => $budgetCum > 0 ? round(($actualCum / $budgetCum) * 100, 1) : 0,
        ];

        return [
            'success' => true,
            'data' => [
                'idProject' => $idProject,
                'days' => $days,
                'totals' => $totals,
            ],
        ];
    }

    /**
     * 11. smokeTest - Verifica dati nel range (debug/diagnostica).
     */
    public static function smokeTest(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filter = self::buildDateFilter($input);
        if (isset($filter['error'])) {
            return ['success' => false, 'message' => $filter['error']];
        }

        $where = $filter['where'];
        $params = $filter['params'];
        $dateFrom = $filter['dateFrom'];
        $dateTo = $filter['dateTo'];

        // Count totale righe
        $totalResult = $database->query(
            "SELECT COUNT(*) AS cnt FROM project_time",
            [],
            __FILE__
        );
        $totalRows = intval($totalResult->fetch(\PDO::FETCH_ASSOC)['cnt'] ?? 0);

        // Count righe nel range filtrato
        $countResult = $database->query(
            "SELECT COUNT(*) AS cnt FROM project_time pt WHERE {$where}",
            $params,
            __FILE__
        );
        $filteredRows = intval($countResult->fetch(\PDO::FETCH_ASSOC)['cnt'] ?? 0);

        // Min/Max date
        $rangeResult = $database->query(
            "SELECT MIN(projectTimeDate) AS minDate, MAX(projectTimeDate) AS maxDate FROM project_time",
            [],
            __FILE__
        );
        $range = $rangeResult->fetch(\PDO::FETCH_ASSOC);

        // Sample row
        $sampleResult = $database->query(
            "SELECT projectTimeDate, workHours, travelHours, totalHours, idHResource, idProject, idBusinessUnit, resourceDesc
             FROM project_time LIMIT 1",
            [],
            __FILE__
        );
        $sample = $sampleResult->fetch(\PDO::FETCH_ASSOC) ?: null;

        return [
            'success' => true,
            'data' => [
                'filterApplied' => [
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'whereClause' => $where,
                ],
                'totalRowsInTable' => $totalRows,
                'rowsInFilteredRange' => $filteredRows,
                'tableRange' => [
                    'minDate' => $range['minDate'] ?? null,
                    'maxDate' => $range['maxDate'] ?? null,
                ],
                'sampleRow' => $sample,
            ],
        ];
    }

    /**
     * 11. exportCSV - Esporta dati ore in formato CSV.
     * Output diretto (no JSON wrapper).
     * Nota: usa $_REQUEST per supportare GET (download diretto).
     */
    public static function exportCSV(array $input): void
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            http_response_code(403);
            echo 'Permesso negato';
            return;
        }

        // Legge da $input (POST) o $_REQUEST (GET) per supportare download diretto
        $dateFrom = trim($input['dateFrom'] ?? $_REQUEST['dateFrom'] ?? '');
        $dateTo = trim($input['dateTo'] ?? $_REQUEST['dateTo'] ?? '');
        $buId = trim($input['buId'] ?? $_REQUEST['buId'] ?? '');
        $projectId = trim($input['projectId'] ?? $_REQUEST['projectId'] ?? '');
        $resourceId = trim($input['resourceId'] ?? $_REQUEST['resourceId'] ?? '');

        // Default: mese corrente
        if (!$dateFrom)
            $dateFrom = date('Y-m-01');
        if (!$dateTo)
            $dateTo = date('Y-m-t');

        // Validazione formato date (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            http_response_code(400);
            echo 'Formato date non valido';
            return;
        }

        // Costruzione WHERE
        $where = "pt.projectTimeDate BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];

        if ($buId !== '' && $buId !== '0') {
            $where .= " AND pt.idBusinessUnit = ?";
            $params[] = $buId;
        }
        if ($projectId !== '' && $projectId !== '0') {
            $where .= " AND pt.idProject = ?";
            $params[] = $projectId;
        }
        if ($resourceId !== '' && $resourceId !== '0') {
            $where .= " AND pt.idHResource = ?";
            $params[] = $resourceId;
        }

        $result = $database->query(
            "SELECT
                pt.projectTimeDate,
                pt.idBusinessUnit,
                pt.idProject,
                pt.idHResource,
                COALESCE(pt.resourceDesc, '') AS resourceDesc,
                pt.workHours,
                pt.travelHours,
                pt.totalHours
             FROM project_time pt
             WHERE {$where}
             ORDER BY pt.projectTimeDate ASC, pt.resourceDesc ASC",
            $params,
            __FILE__
        );
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);

        // Pulisce qualsiasi output buffer precedente
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Headers CSV
        $filename = "dashboard_ore_{$dateFrom}_{$dateTo}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // BOM UTF-8 per compatibilità Excel
        fwrite($output, "\xEF\xBB\xBF");

        // Intestazioni colonne
        fputcsv($output, [
            'Data',
            'Business Unit',
            'Progetto',
            'ID Risorsa',
            'Risorsa',
            'Ore Lavoro',
            'Ore Viaggio',
            'Ore Totali'
        ], ';');

        // Dati
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['projectTimeDate'],
                $row['idBusinessUnit'],
                $row['idProject'],
                $row['idHResource'],
                $row['resourceDesc'],
                $row['workHours'],
                $row['travelHours'],
                $row['totalHours']
            ], ';');
        }

        fclose($output);
    }

    /**
     * getBusinessUnitData - Dati aggregati per la pagina Business Unit.
     * Ritorna: BUs, filtri (anni, mesi, PMs, progetti), rows per aggregazione.
     */
    public static function getBusinessUnitData(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        // Parametri filtro (sanificati)
        $year = filter_var($input['year'] ?? '', FILTER_SANITIZE_NUMBER_INT);
        $month = filter_var($input['month'] ?? '', FILTER_SANITIZE_NUMBER_INT);
        $pmId = trim(filter_var($input['pmId'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $projectId = trim(filter_var($input['projectId'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $buCode = trim(filter_var($input['buCode'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        // Lista BU disponibili
        $buResult = $database->query(
            "SELECT DISTINCT pt.idBusinessUnit AS code, pt.idBusinessUnit AS name
             FROM project_time pt
             WHERE pt.idBusinessUnit IS NOT NULL AND pt.idBusinessUnit != ''
             ORDER BY pt.idBusinessUnit",
            [],
            __FILE__
        );
        $bus = $buResult->fetchAll(\PDO::FETCH_ASSOC);

        // Anni disponibili
        $yearResult = $database->query(
            "SELECT DISTINCT YEAR(pt.projectTimeDate) AS y
             FROM project_time pt
             WHERE pt.projectTimeDate IS NOT NULL
             ORDER BY y DESC",
            [],
            __FILE__
        );
        $years = array_column($yearResult->fetchAll(\PDO::FETCH_ASSOC), 'y');

        // Mesi (fissi 01-12)
        $months = [];
        $monthNames = [
            '',
            'Gennaio',
            'Febbraio',
            'Marzo',
            'Aprile',
            'Maggio',
            'Giugno',
            'Luglio',
            'Agosto',
            'Settembre',
            'Ottobre',
            'Novembre',
            'Dicembre'
        ];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = ['value' => str_pad($m, 2, '0', STR_PAD_LEFT), 'label' => $monthNames[$m]];
        }

        // Project Managers (distinct da commesse aperte) - fallback a resourceDesc se PM non disponibile
        // Nota: project_time non ha PM direttamente, usiamo commesse se disponibile
        $pms = [];
        try {
            $pmResult = $database->query(
                "SELECT DISTINCT c.idProjectManager AS id, CONCAT(hr.nome, ' ', hr.cognome) AS name
                 FROM commesse c
                 LEFT JOIN hr ON c.idProjectManager = hr.id
                 WHERE c.idProjectManager IS NOT NULL AND c.Stato = 1
                 ORDER BY name",
                [],
                __FILE__
            );
            $pms = $pmResult->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // Tabella commesse non accessibile, skip PM
            error_log("getBusinessUnitData: cannot fetch PMs - " . $e->getMessage());
        }

        // Progetti disponibili (con BU e PM se possibile)
        $projResult = $database->query(
            "SELECT DISTINCT pt.idProject AS id, pt.idProject AS code, pt.idProject AS name, pt.idBusinessUnit AS bu
             FROM project_time pt
             WHERE pt.idProject IS NOT NULL AND pt.idProject != ''
             ORDER BY pt.idProject",
            [],
            __FILE__
        );
        $projects = $projResult->fetchAll(\PDO::FETCH_ASSOC);

        // Costruzione WHERE per rows
        $where = "1=1";
        $params = [];

        // Filtro anno/mese
        if ($year !== '' && $year !== '0') {
            $where .= " AND YEAR(pt.projectTimeDate) = ?";
            $params[] = $year;
        }
        if ($month !== '' && $month !== '0') {
            $where .= " AND MONTH(pt.projectTimeDate) = ?";
            $params[] = intval($month);
        }

        // Default: anno corrente se non specificato
        if ($year === '' || $year === '0') {
            $currentYear = date('Y');
            $where .= " AND YEAR(pt.projectTimeDate) = ?";
            $params[] = $currentYear;
        }

        if ($buCode !== '') {
            $where .= " AND pt.idBusinessUnit = ?";
            $params[] = $buCode;
        }
        if ($projectId !== '') {
            $where .= " AND pt.idProject = ?";
            $params[] = $projectId;
        }

        // Recupero rows: dati aggregati per BU/mese/progetto/risorsa
        // Join con akeron_project per stato commessa (usa joinAkeronProjectSafe per collation)
        $joinAkeron = self::joinAkeronProjectSafe();
        $result = $database->query(
            "SELECT
                pt.idBusinessUnit AS bu,
                DATE_FORMAT(pt.projectTimeDate, '%Y-%m') AS ym,
                pt.idProject AS projectId,
                pt.idProject AS projectCode,
                pt.idProject AS projectName,
                pt.idHResource AS resourceId,
                COALESCE(pt.resourceDesc, CAST(pt.idHResource AS CHAR)) AS resourceName,
                MAX(pt.hrRoleDesc) AS resourceRole,
                SUM(pt.workHours) AS wh,
                0 AS eh,
                MAX(ap.ProjectStatusDesc) AS projectStatusDesc,
                MAX(ap.ProjectStatusCode) AS projectStatusCode
             FROM project_time pt
             {$joinAkeron}
             WHERE {$where}
             GROUP BY pt.idBusinessUnit, ym, pt.idProject, pt.idHResource, pt.resourceDesc
             ORDER BY pt.idBusinessUnit, ym, pt.idProject",
            $params,
            __FILE__
        );
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);

        // Budget: se disponibile, aggrega per BU/mese/progetto/risorsa
        $budgetMap = [];
        try {
            $budgetWhere = "1=1";
            $budgetParams = [];

            if ($year !== '' && $year !== '0') {
                $budgetWhere .= " AND YEAR(b.budgetDate) = ?";
                $budgetParams[] = $year;
            } else {
                $budgetWhere .= " AND YEAR(b.budgetDate) = ?";
                $budgetParams[] = date('Y');
            }
            if ($month !== '' && $month !== '0') {
                $budgetWhere .= " AND MONTH(b.budgetDate) = ?";
                $budgetParams[] = intval($month);
            }
            if ($buCode !== '') {
                $budgetWhere .= " AND b.idBusinessUnit = ?";
                $budgetParams[] = $buCode;
            }
            if ($projectId !== '') {
                $budgetWhere .= " AND b.idProject = ?";
                $budgetParams[] = $projectId;
            }

            $budgetResult = $database->query(
                "SELECT
                    b.idBusinessUnit AS bu,
                    DATE_FORMAT(b.budgetDate, '%Y-%m') AS ym,
                    b.idProject AS projectId,
                    b.idHResource AS resourceId,
                    SUM(b.budgetTotalHours) AS eh
                 FROM project_time_budget b
                 WHERE {$budgetWhere}
                 GROUP BY b.idBusinessUnit, ym, b.idProject, b.idHResource",
                $budgetParams,
                __FILE__
            );
            foreach ($budgetResult->fetchAll(\PDO::FETCH_ASSOC) as $br) {
                $key = $br['bu'] . '|' . $br['ym'] . '|' . $br['projectId'] . '|' . $br['resourceId'];
                $budgetMap[$key] = round(floatval($br['eh']), 1);
            }
        } catch (\Exception $e) {
            error_log("getBusinessUnitData: budget table unavailable - " . $e->getMessage());
        }

        // Arricchisci rows con budget (eh) e stato progetto
        foreach ($rows as &$row) {
            $key = $row['bu'] . '|' . $row['ym'] . '|' . $row['projectId'] . '|' . $row['resourceId'];
            $row['eh'] = $budgetMap[$key] ?? 0;
            $row['wh'] = round(floatval($row['wh']), 1);

            // Stato da akeron_project (già nel risultato query)
            $row['projectStatus'] = $row['projectStatusDesc'] ?? 'n/d';
        }
        unset($row);

        return [
            'success' => true,
            'data' => [
                'bus' => $bus,
                'filters' => [
                    'years' => $years,
                    'months' => $months,
                    'pms' => $pms,
                    'projects' => $projects,
                ],
                'rows' => $rows,
            ],
        ];
    }

    /**
     * getBusinessUnitTrend - Trend mensile per BU (per grafico SVG).
     */
    public static function getBusinessUnitTrend(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_ore')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $year = filter_var($input['year'] ?? date('Y'), FILTER_SANITIZE_NUMBER_INT);
        $buCode = trim(filter_var($input['buCode'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        $where = "YEAR(pt.projectTimeDate) = ?";
        $params = [$year];

        if ($buCode !== '') {
            $where .= " AND pt.idBusinessUnit = ?";
            $params[] = $buCode;
        }

        // Ore imputate per mese
        $result = $database->query(
            "SELECT
                DATE_FORMAT(pt.projectTimeDate, '%Y-%m') AS ym,
                DATE_FORMAT(pt.projectTimeDate, '%b') AS label,
                SUM(pt.workHours) AS wh
             FROM project_time pt
             WHERE {$where}
             GROUP BY ym, label
             ORDER BY ym ASC",
            $params,
            __FILE__
        );
        $actual = $result->fetchAll(\PDO::FETCH_ASSOC);

        // Budget per mese (se disponibile)
        $budgetMap = [];
        try {
            $budgetWhere = "YEAR(b.budgetDate) = ?";
            $budgetParams = [$year];
            if ($buCode !== '') {
                $budgetWhere .= " AND b.idBusinessUnit = ?";
                $budgetParams[] = $buCode;
            }

            $budgetResult = $database->query(
                "SELECT
                    DATE_FORMAT(b.budgetDate, '%Y-%m') AS ym,
                    SUM(b.budgetTotalHours) AS eh
                 FROM project_time_budget b
                 WHERE {$budgetWhere}
                 GROUP BY ym
                 ORDER BY ym ASC",
                $budgetParams,
                __FILE__
            );
            foreach ($budgetResult->fetchAll(\PDO::FETCH_ASSOC) as $br) {
                $budgetMap[$br['ym']] = round(floatval($br['eh']), 0);
            }
        } catch (\Exception $e) {
            // Ignora
        }

        $trend = array_map(function ($row) use ($budgetMap) {
            return [
                'ym' => $row['ym'],
                'label' => $row['label'],
                'wh' => round(floatval($row['wh']), 0),
                'eh' => $budgetMap[$row['ym']] ?? 0,
            ];
        }, $actual);

        return [
            'success' => true,
            'data' => $trend,
        ];
    }

    /** @var bool Debug flag per logging dettagliato */
    private static $DEBUG_USER_DETAIL = true;

    /**
     * Helper: converte userId numerico in resourceCode (Cod_Operatore).
     * Se userId è già un codice risorsa (pattern alfanumerico), lo restituisce invariato.
     *
     * @param string $userIdOrCode - userId numerico (es. "2") o resourceCode (es. "AGA")
     * @param int|string|null $sessionUserId - userId dalla sessione per fallback
     * @return array ['resourceCode' => string|null, 'nominativo' => string|null, 'ruolo' => string|null]
     */
    private static function resolveResourceCode(string $userIdOrCode, $sessionUserId = null): array
    {
        global $database;

        $result = ['resourceCode' => null, 'nominativo' => null, 'ruolo' => null];

        if (self::$DEBUG_USER_DETAIL) {
            error_log("[getUserDetail DEBUG] resolveResourceCode called: userIdOrCode='$userIdOrCode', sessionUserId='$sessionUserId'");
        }

        // Se vuoto, usa sessionUserId
        if ($userIdOrCode === '') {
            if ($sessionUserId !== null && $sessionUserId !== '') {
                $userIdOrCode = (string) $sessionUserId;
            } else {
                if (self::$DEBUG_USER_DETAIL) {
                    error_log("[getUserDetail DEBUG] resolveResourceCode: empty input and no session fallback");
                }
                return $result;
            }
        }

        // Pattern: se è già un codice risorsa (2-10 caratteri alfanumerici, non puramente numerico)
        if (preg_match('/^[A-Z0-9]{2,10}$/i', $userIdOrCode) && !ctype_digit($userIdOrCode)) {
            if (self::$DEBUG_USER_DETAIL) {
                error_log("[getUserDetail DEBUG] resolveResourceCode: '$userIdOrCode' looks like resourceCode, returning as-is");
            }
            $result['resourceCode'] = strtoupper($userIdOrCode);

            // Prova a recuperare nominativo da personale
            try {
                $row = $database->query(
                    "SELECT Nominativo, Ruolo FROM personale WHERE Cod_Operatore = ? LIMIT 1",
                    [$result['resourceCode']],
                    __FILE__
                )->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $result['nominativo'] = $row['Nominativo'] ?? null;
                    $result['ruolo'] = $row['Ruolo'] ?? null;
                }
            } catch (\Exception $e) {
                // Ignora, useremo fallback da project_time
            }
            return $result;
        }

        // Se è numerico, cerca in personale
        if (ctype_digit($userIdOrCode)) {
            try {
                // Prima prova con attivo = 1
                $row = $database->query(
                    "SELECT Cod_Operatore, Nominativo, Ruolo FROM personale WHERE user_id = ? AND attivo = 1 LIMIT 1",
                    [(int) $userIdOrCode],
                    __FILE__
                )->fetch(\PDO::FETCH_ASSOC);

                // Fallback: senza filtro attivo
                if (!$row) {
                    $row = $database->query(
                        "SELECT Cod_Operatore, Nominativo, Ruolo FROM personale WHERE user_id = ? LIMIT 1",
                        [(int) $userIdOrCode],
                        __FILE__
                    )->fetch(\PDO::FETCH_ASSOC);
                    if (self::$DEBUG_USER_DETAIL && $row) {
                        error_log("[getUserDetail DEBUG] resolveResourceCode: found inactive user for user_id=$userIdOrCode");
                    }
                }

                if ($row && !empty($row['Cod_Operatore'])) {
                    $result['resourceCode'] = $row['Cod_Operatore'];
                    $result['nominativo'] = $row['Nominativo'] ?? null;
                    $result['ruolo'] = $row['Ruolo'] ?? null;
                    if (self::$DEBUG_USER_DETAIL) {
                        error_log("[getUserDetail DEBUG] resolveResourceCode: user_id=$userIdOrCode -> resourceCode={$result['resourceCode']}, nominativo={$result['nominativo']}");
                    }
                    return $result;
                }

                if (self::$DEBUG_USER_DETAIL) {
                    error_log("[getUserDetail DEBUG] resolveResourceCode: NO Cod_Operatore found for user_id=$userIdOrCode");
                }
            } catch (\Exception $e) {
                error_log("[getUserDetail ERROR] resolveResourceCode: query failed for user_id=$userIdOrCode - " . $e->getMessage());
            }
            return $result;
        }

        if (self::$DEBUG_USER_DETAIL) {
            error_log("[getUserDetail DEBUG] resolveResourceCode: unhandled format '$userIdOrCode'");
        }
        return $result;
    }

    /**
     * getUserDetailData - Dati aggregati per la pagina Dettaglio Utente.
     * Supporta vista "self" (utente vede solo se stesso) e vista "admin" (può vedere altri).
     */
    public static function getUserDetailData(array $input): array
    {
        global $database;

        // Check permessi: tutti gli utenti loggati possono accedere
        $sessionUserId = $_SESSION['user_id'] ?? '';
        $canViewOthers = userHasPermission('view_dashboard_ore');

        // Parametri filtro (sanificati)
        $requestedUserId = trim(filter_var($input['userId'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $dateFrom = trim(filter_var($input['dateFrom'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $dateTo = trim(filter_var($input['dateTo'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $year = filter_var($input['year'] ?? '', FILTER_SANITIZE_NUMBER_INT);
        $projectId = trim(filter_var($input['projectId'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        if (self::$DEBUG_USER_DETAIL) {
            error_log("[getUserDetail DEBUG] getUserDetailData called: requestedUserId='$requestedUserId', sessionUserId='$sessionUserId', year='$year', canViewOthers=" . ($canViewOthers ? 'true' : 'false'));
        }

        // Risolvi resourceCode per sessione e per richiesta
        $sessionResolved = self::resolveResourceCode((string) $sessionUserId, $sessionUserId);
        $sessionResourceCode = $sessionResolved['resourceCode'];

        $requestedResolved = $requestedUserId !== ''
            ? self::resolveResourceCode($requestedUserId, $sessionUserId)
            : $sessionResolved;
        $requestedResourceCode = $requestedResolved['resourceCode'];

        // Sicurezza: se non ha permessi, forza userId a self
        if (!$canViewOthers && $requestedUserId !== '' && $requestedUserId !== (string) $sessionUserId) {
            // Verifica anche se il requested è il codice risorsa del self
            if ($requestedResourceCode !== $sessionResourceCode) {
                return ['success' => false, 'message' => 'Accesso negato: puoi vedere solo i tuoi dati'];
            }
        }

        // Determina quale risoluzione usare
        $useRequested = $requestedUserId !== '' && ($canViewOthers || $requestedUserId === (string) $sessionUserId || $requestedResourceCode === $sessionResourceCode);
        $resolved = $useRequested ? $requestedResolved : $sessionResolved;
        $resourceCode = $resolved['resourceCode'];
        $resolvedNominativo = $resolved['nominativo'];
        $resolvedRuolo = $resolved['ruolo'];

        if (self::$DEBUG_USER_DETAIL) {
            error_log("[getUserDetail DEBUG] resolved: resourceCode='$resourceCode', nominativo='$resolvedNominativo', ruolo='$resolvedRuolo'");
        }

        if ($resourceCode === null || $resourceCode === '') {
            error_log("[getUserDetail ERROR] resource_not_mapped: sessionUserId='$sessionUserId', requestedUserId='$requestedUserId'");
            return ['success' => false, 'message' => 'Profilo non collegato a risorsa. Contattare l\'amministratore.'];
        }

        // Default date range: anno corrente se non specificato
        if ($year === '' || $year === '0') {
            $year = date('Y');
        }
        if ($dateFrom === '') {
            $dateFrom = $year . '-01-01';
        }
        if ($dateTo === '') {
            $dateTo = $year . '-12-31';
        }

        // Lista utenti (solo se canViewOthers) - usa personale.Nominativo per nomi leggibili
        $users = [];
        if ($canViewOthers) {
            try {
                // Join con personale per avere nomi leggibili
                $usersResult = $database->query(
                    "SELECT DISTINCT pt.idHResource AS id,
                            COALESCE(p.Nominativo, pt.resourceDesc, pt.idHResource) AS name
                     FROM project_time pt
                     LEFT JOIN personale p ON p.Cod_Operatore = pt.idHResource
                     WHERE pt.idHResource IS NOT NULL AND pt.idHResource != ''
                     ORDER BY name",
                    [],
                    __FILE__
                );
                $users = $usersResult->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                error_log("[getUserDetail ERROR] cannot fetch users - " . $e->getMessage());
            }
        }

        // Anni disponibili
        $yearResult = $database->query(
            "SELECT DISTINCT YEAR(pt.projectTimeDate) AS y
             FROM project_time pt
             WHERE pt.idHResource = ?
             ORDER BY y DESC",
            [$resourceCode],
            __FILE__
        );
        $years = array_column($yearResult->fetchAll(\PDO::FETCH_ASSOC), 'y');

        // Commesse dell'utente
        $projResult = $database->query(
            "SELECT DISTINCT pt.idProject AS id, pt.idProject AS code, pt.idBusinessUnit AS bu
             FROM project_time pt
             WHERE pt.idHResource = ?
             ORDER BY pt.idProject",
            [$resourceCode],
            __FILE__
        );
        $projects = $projResult->fetchAll(\PDO::FETCH_ASSOC);

        // Info utente: priorità a personale.Nominativo, fallback a project_time.resourceDesc
        $userInfo = [
            'id' => $resourceCode,
            'name' => $resolvedNominativo ?: $resourceCode,
            'role' => $resolvedRuolo ?: ''
        ];

        // Se non abbiamo nominativo da personale, prova project_time
        if (!$resolvedNominativo) {
            try {
                $ptInfo = $database->query(
                    "SELECT DISTINCT
                        COALESCE(pt.resourceDesc, pt.idHResource) AS name,
                        MAX(pt.hrRoleDesc) AS role
                     FROM project_time pt
                     WHERE pt.idHResource = ?
                     GROUP BY pt.resourceDesc",
                    [$resourceCode],
                    __FILE__
                )->fetch(\PDO::FETCH_ASSOC);
                if ($ptInfo) {
                    $userInfo['name'] = $ptInfo['name'] ?: $resourceCode;
                    if (!$userInfo['role']) {
                        $userInfo['role'] = $ptInfo['role'] ?: '';
                    }
                }
            } catch (\Exception $e) {
                // Ignora
            }
        }

        if (self::$DEBUG_USER_DETAIL) {
            error_log("[getUserDetail DEBUG] userInfo: id={$userInfo['id']}, name={$userInfo['name']}, role={$userInfo['role']}");
        }

        // Costruzione WHERE per rows
        $where = "pt.idHResource = ? AND pt.projectTimeDate BETWEEN ? AND ?";
        $params = [$resourceCode, $dateFrom, $dateTo];

        if ($projectId !== '') {
            $where .= " AND pt.idProject = ?";
            $params[] = $projectId;
        }

        // Recupero rows: dati aggregati per commessa/mese
        $result = $database->query(
            "SELECT
                pt.idProject AS projectId,
                pt.idProject AS projectCode,
                pt.idBusinessUnit AS bu,
                DATE_FORMAT(pt.projectTimeDate, '%Y-%m') AS ym,
                SUM(pt.workHours) AS wh
             FROM project_time pt
             WHERE {$where}
             GROUP BY pt.idProject, pt.idBusinessUnit, ym
             ORDER BY pt.idProject, ym",
            $params,
            __FILE__
        );
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);

        // Budget per utente/commessa (se disponibile)
        $budgetMap = [];
        try {
            $budgetWhere = "b.idHResource = ? AND b.budgetDate BETWEEN ? AND ?";
            $budgetParams = [$resourceCode, $dateFrom, $dateTo];

            if ($projectId !== '') {
                $budgetWhere .= " AND b.idProject = ?";
                $budgetParams[] = $projectId;
            }

            $budgetResult = $database->query(
                "SELECT
                    b.idProject AS projectId,
                    DATE_FORMAT(b.budgetDate, '%Y-%m') AS ym,
                    SUM(b.budgetTotalHours) AS eh
                 FROM project_time_budget b
                 WHERE {$budgetWhere}
                 GROUP BY b.idProject, ym",
                $budgetParams,
                __FILE__
            );
            foreach ($budgetResult->fetchAll(\PDO::FETCH_ASSOC) as $br) {
                $key = $br['projectId'] . '|' . $br['ym'];
                $budgetMap[$key] = round(floatval($br['eh']), 1);
            }
        } catch (\Exception $e) {
            error_log("getUserDetailData: budget table unavailable - " . $e->getMessage());
        }

        // Arricchisci rows con budget (eh)
        foreach ($rows as &$row) {
            $key = $row['projectId'] . '|' . $row['ym'];
            $row['eh'] = $budgetMap[$key] ?? 0;
            $row['wh'] = round(floatval($row['wh']), 1);
        }
        unset($row);

        if (self::$DEBUG_USER_DETAIL) {
            $rowsCount = count($rows);
            error_log("[getUserDetail DEBUG] RESULT: resourceCode='$resourceCode', dateFrom='$dateFrom', dateTo='$dateTo', rows_count=$rowsCount");
        }

        return [
            'success' => true,
            'data' => [
                'userInfo' => $userInfo,
                'resourceCode' => $resourceCode,  // resourceCode usato per le query
                'canViewOthers' => $canViewOthers,
                'filters' => [
                    'users' => $users,
                    'years' => $years,
                    'projects' => $projects,
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                ],
                'rows' => $rows,
            ],
        ];
    }

    /**
     * getUserDetailTrend - Trend mensile per utente (per grafico SVG).
     */
    public static function getUserDetailTrend(array $input): array
    {
        global $database;

        $sessionUserId = $_SESSION['user_id'] ?? '';
        $canViewOthers = userHasPermission('view_dashboard_ore');

        $requestedUserId = trim(filter_var($input['userId'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $year = filter_var($input['year'] ?? date('Y'), FILTER_SANITIZE_NUMBER_INT);

        // Risolvi resourceCode per sessione e richiesta
        $sessionResolved = self::resolveResourceCode((string) $sessionUserId, $sessionUserId);
        $sessionResourceCode = $sessionResolved['resourceCode'];

        $requestedResolved = $requestedUserId !== ''
            ? self::resolveResourceCode($requestedUserId, $sessionUserId)
            : $sessionResolved;
        $requestedResourceCode = $requestedResolved['resourceCode'];

        // Sicurezza
        if (!$canViewOthers && $requestedUserId !== '' && $requestedUserId !== (string) $sessionUserId) {
            if ($requestedResourceCode !== $sessionResourceCode) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }
        }

        // Determina quale risoluzione usare
        $useRequested = $requestedUserId !== '' && ($canViewOthers || $requestedUserId === (string) $sessionUserId || $requestedResourceCode === $sessionResourceCode);
        $resolved = $useRequested ? $requestedResolved : $sessionResolved;
        $resourceCode = $resolved['resourceCode'];

        if ($resourceCode === null || $resourceCode === '') {
            return ['success' => false, 'message' => 'Profilo non collegato a risorsa.'];
        }

        $where = "pt.idHResource = ? AND YEAR(pt.projectTimeDate) = ?";
        $params = [$resourceCode, $year];

        // Ore imputate per mese
        $result = $database->query(
            "SELECT
                DATE_FORMAT(pt.projectTimeDate, '%Y-%m') AS ym,
                DATE_FORMAT(pt.projectTimeDate, '%b') AS label,
                SUM(pt.workHours) AS wh
             FROM project_time pt
             WHERE {$where}
             GROUP BY ym, label
             ORDER BY ym ASC",
            $params,
            __FILE__
        );
        $actual = $result->fetchAll(\PDO::FETCH_ASSOC);

        // Budget per mese (se disponibile)
        $budgetMap = [];
        try {
            $budgetResult = $database->query(
                "SELECT
                    DATE_FORMAT(b.budgetDate, '%Y-%m') AS ym,
                    SUM(b.budgetTotalHours) AS eh
                 FROM project_time_budget b
                 WHERE b.idHResource = ? AND YEAR(b.budgetDate) = ?
                 GROUP BY ym
                 ORDER BY ym ASC",
                [$resourceCode, $year],
                __FILE__
            );
            foreach ($budgetResult->fetchAll(\PDO::FETCH_ASSOC) as $br) {
                $budgetMap[$br['ym']] = round(floatval($br['eh']), 0);
            }
        } catch (\Exception $e) {
            // Ignora
        }

        $trend = array_map(function ($row) use ($budgetMap) {
            return [
                'ym' => $row['ym'],
                'label' => $row['label'],
                'wh' => round(floatval($row['wh']), 0),
                'eh' => $budgetMap[$row['ym']] ?? 0,
            ];
        }, $actual);

        return [
            'success' => true,
            'data' => $trend,
        ];
    }
}
