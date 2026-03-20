<?php
/**
 * DashboardEconomicaService.php
 * Service per la Dashboard Economica V2 - riepilogo economico completo.
 *
 * V2.2 - Integrazione hr_resource per nomi risorse completi
 *
 * Fonte dati finali interne (NON akeron_* raw):
 * - project_time: ore lavorate
 * - project_milestone: fasi/milestone economici
 * - project_installment: rate pagamento attive
 * - project_phase_hr: dettaglio fasi HR per risorsa
 * - elenco_commesse: anagrafica commesse
 * - hr_resource: anagrafica risorse HR (nomi, ruoli, reparti)
 *
 * - project_purchase, project_other_cost, project_overheads_cost, quotation_reimbursment
 *
 * - hr_absence: assenze risorse
 */

namespace Services;

class DashboardEconomicaService
{
    /**
     * Helper: normalizza filtri in input.
     */
    private static function normalizeFilters(array $input): array
    {
        $year = trim($input['year'] ?? '');
        $bu = trim($input['bu'] ?? '');
        $pm = trim($input['pm'] ?? '');
        $cliente = trim($input['cliente'] ?? '');

        // Validazione year
        if ($year !== '' && !preg_match('/^\d{4}$/', $year)) {
            $year = date('Y');
        }
        if ($year === '') {
            $year = date('Y');
        }

        // Normalizza "0" a vuoto
        $bu = ($bu === '0') ? '' : $bu;
        $pm = ($pm === '0') ? '' : $pm;
        $cliente = ($cliente === '0') ? '' : $cliente;

        return [
            'year' => $year,
            'bu' => $bu,
            'pm' => $pm,
            'cliente' => $cliente,
        ];
    }

    /**
     * Helper: normalizza valore decimale.
     * Semplificato: gestisce già numerici e virgola decimale.
     */
    private static function parseDecimal($value): float
    {
        if ($value === null || $value === '' || $value === '—' || $value === '-') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return floatval($value);
        }
        // Rimuovi spazi, converti virgola in punto
        $str = str_replace(' ', '', (string)$value);
        $str = str_replace(',', '.', $str);
        return floatval($str);
    }

    /**
     * Helper: costruisce WHERE e params per filtri comuni su elenco_commesse.
     */
    private static function buildCommesseFilters(string $bu, string $pm, string $cliente): array
    {
        $where = "1=1";
        $params = [];

        if ($bu !== '') {
            $where .= " AND ec.business_unit = ?";
            $params[] = $bu;
        }
        if ($pm !== '') {
            $where .= " AND ec.responsabile_commessa = ?";
            $params[] = $pm;
        }
        if ($cliente !== '') {
            $where .= " AND ec.cliente = ?";
            $params[] = $cliente;
        }

        return ['where' => $where, 'params' => $params];
    }

    /**
     * 1. getFilterOptions - Popola le select filtro (Anno, BU, PM, Cliente).
     */
    public static function getFilterOptions(): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_economica')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        // Anni disponibili (da project_time)
        $yearsResult = $database->query(
            "SELECT DISTINCT YEAR(projectTimeDate) AS year
             FROM project_time
             WHERE projectTimeDate IS NOT NULL
             ORDER BY year DESC",
            [],
            __FILE__
        );
        $years = [];
        while ($row = $yearsResult->fetch(\PDO::FETCH_ASSOC)) {
            if ($row['year']) {
                $years[] = ['value' => $row['year'], 'label' => $row['year']];
            }
        }

        // Business Units (da project_time)
        $buResult = $database->query(
            "SELECT DISTINCT idBusinessUnit AS value, idBusinessUnit AS label
             FROM project_time
             WHERE idBusinessUnit IS NOT NULL AND idBusinessUnit != ''
             ORDER BY idBusinessUnit",
            [],
            __FILE__
        );
        $businessUnits = $buResult->fetchAll(\PDO::FETCH_ASSOC);

        // Project Managers (da elenco_commesse)
        $pmResult = $database->query(
            "SELECT DISTINCT responsabile_commessa AS value, responsabile_commessa AS label
             FROM elenco_commesse
             WHERE responsabile_commessa IS NOT NULL AND responsabile_commessa != ''
             ORDER BY responsabile_commessa",
            [],
            __FILE__
        );
        $projectManagers = $pmResult->fetchAll(\PDO::FETCH_ASSOC);

        // Clienti (da elenco_commesse)
        $clientiResult = $database->query(
            "SELECT DISTINCT cliente AS value, cliente AS label
             FROM elenco_commesse
             WHERE cliente IS NOT NULL AND cliente != ''
             ORDER BY cliente",
            [],
            __FILE__
        );
        $clienti = $clientiResult->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'data' => [
                'years' => $years,
                'businessUnits' => $businessUnits,
                'projectManagers' => $projectManagers,
                'clienti' => $clienti,
            ],
        ];
    }

    /**
     * 2. getOverviewKpi - KPI Overview con filtri coerenti.
     *
     * FILTRI APPLICATI: anno, bu, pm, cliente (tutti coerenti)
     */
    public static function getOverviewKpi(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_economica')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filters = self::normalizeFilters($input);
        $year = $filters['year'];
        $bu = $filters['bu'];
        $pm = $filters['pm'];
        $cliente = $filters['cliente'];

        $dateFrom = "{$year}-01-01";
        $dateTo = "{$year}-12-31";

        // Costruisci filtri comuni per elenco_commesse
        $ecFilters = self::buildCommesseFilters($bu, $pm, $cliente);

        // === KPI 1: Ore lavorate anno ===
        // JOIN con elenco_commesse per applicare filtri PM/Cliente
        $whereTime = "pt.projectTimeDate BETWEEN ? AND ?";
        $paramsTime = [$dateFrom, $dateTo];

        // Se ci sono filtri PM/Cliente, devo joinare con elenco_commesse
        $joinEc = ($pm !== '' || $cliente !== '');
        $timeQuery = "SELECT
                SUM(pt.workHours) AS totalWorkHours,
                SUM(pt.travelHours) AS totalTravelHours,
                SUM(pt.totalHours) AS totalHours,
                COUNT(DISTINCT pt.idProject) AS projectCount,
                COUNT(DISTINCT pt.idHResource) AS resourceCount
             FROM project_time pt";

        if ($joinEc) {
            $timeQuery .= " JOIN elenco_commesse ec ON ec.akeron_project_id = pt.idProject";
            if ($pm !== '') {
                $whereTime .= " AND ec.responsabile_commessa = ?";
                $paramsTime[] = $pm;
            }
            if ($cliente !== '') {
                $whereTime .= " AND ec.cliente = ?";
                $paramsTime[] = $cliente;
            }
        }

        if ($bu !== '') {
            $whereTime .= " AND pt.idBusinessUnit = ?";
            $paramsTime[] = $bu;
        }

        $timeQuery .= " WHERE {$whereTime}";
        $hoursResult = $database->query($timeQuery, $paramsTime, __FILE__);
        $hours = $hoursResult->fetch(\PDO::FETCH_ASSOC);

        // === KPI 2: Milestone con pre-aggregazione e filtro anno ===
        // Subquery aggregata per evitare duplicazioni
        $whereMilestone = "YEAR(pm.expectedStartDate) = ?";
        $paramsMilestone = [$year];

        if ($bu !== '') {
            $whereMilestone .= " AND ec.business_unit = ?";
            $paramsMilestone[] = $bu;
        }
        if ($pm !== '') {
            $whereMilestone .= " AND ec.responsabile_commessa = ?";
            $paramsMilestone[] = $pm;
        }
        if ($cliente !== '') {
            $whereMilestone .= " AND ec.cliente = ?";
            $paramsMilestone[] = $cliente;
        }

        $milestoneResult = $database->query(
            "SELECT
                pm.idProject,
                pm.milestoneValue,
                pm.quotExpectedHours,
                pm.hoursWorked,
                pm.quotLaborCost,
                pm.laborCost,
                pm.percentage1Hour
             FROM project_milestone pm
             JOIN elenco_commesse ec ON ec.akeron_project_id = pm.idProject
             WHERE pm.projectMilestoneRow > 0 AND {$whereMilestone}",
            $paramsMilestone,
            __FILE__
        );
        $milestoneRows = $milestoneResult->fetchAll(\PDO::FETCH_ASSOC);

        // Aggrega in PHP
        $totalMilestoneValue = 0;
        $totalQuotHours = 0;
        $totalHoursWorked = 0;
        $totalQuotLaborCost = 0;
        $totalLaborCost = 0;
        $percentages = [];
        $projectIds = [];

        foreach ($milestoneRows as $row) {
            $totalMilestoneValue += self::parseDecimal($row['milestoneValue']);
            $totalQuotHours += self::parseDecimal($row['quotExpectedHours']);
            $totalHoursWorked += self::parseDecimal($row['hoursWorked']);
            $totalQuotLaborCost += self::parseDecimal($row['quotLaborCost']);
            $totalLaborCost += self::parseDecimal($row['laborCost']);
            $pct = self::parseDecimal($row['percentage1Hour']);
            if ($pct > 0) {
                $percentages[] = $pct;
            }
            $projectIds[$row['idProject']] = true;
        }

        $avgPercentage1Hour = count($percentages) > 0 ? array_sum($percentages) / count($percentages) : 0;
        $milestoneProjectCount = count($projectIds);

        // === KPI 3: Rate/Installments anno ===
        $whereInstall = "YEAR(pi.installDate) = ?";
        $paramsInstall = [$year];

        if ($bu !== '') {
            $whereInstall .= " AND ec.business_unit = ?";
            $paramsInstall[] = $bu;
        }
        if ($pm !== '') {
            $whereInstall .= " AND ec.responsabile_commessa = ?";
            $paramsInstall[] = $pm;
        }
        if ($cliente !== '') {
            $whereInstall .= " AND ec.cliente = ?";
            $paramsInstall[] = $cliente;
        }

        $installResult = $database->query(
            "SELECT
                pi.isBilled,
                pi.expectedInstallValue,
                pi.installNetAmount
             FROM project_installment pi
             JOIN elenco_commesse ec ON ec.akeron_project_id = pi.idProject
             WHERE {$whereInstall}",
            $paramsInstall,
            __FILE__
        );
        $installRows = $installResult->fetchAll(\PDO::FETCH_ASSOC);

        $totalInstallments = count($installRows);
        $billedCount = 0;
        $totalExpectedValue = 0;
        $totalNetAmount = 0;

        foreach ($installRows as $row) {
            if ($row['isBilled'] == 1 || $row['isBilled'] === '1') {
                $billedCount++;
            }
            $totalExpectedValue += self::parseDecimal($row['expectedInstallValue']);
            $totalNetAmount += self::parseDecimal($row['installNetAmount']);
        }

        // === KPI 4: Conteggio clienti e commesse ===
        $countResult = $database->query(
            "SELECT
                COUNT(DISTINCT ec.codice) AS totalCommesse,
                COUNT(DISTINCT ec.cliente) AS totalClienti
             FROM elenco_commesse ec
             WHERE {$ecFilters['where']}",
            $ecFilters['params'],
            __FILE__
        );
        $counts = $countResult->fetch(\PDO::FETCH_ASSOC);

        // Calcoli derivati
        $deltaCosto = $totalLaborCost - $totalQuotLaborCost;
        $deltaPercent = ($totalQuotLaborCost > 0) ? (($deltaCosto / $totalQuotLaborCost) * 100) : 0;
        $costoMedioOrario = ($totalHoursWorked > 0) ? ($totalLaborCost / $totalHoursWorked) : 0;

        return [
            'success' => true,
            'data' => [
                'year' => $year,
                'hours' => [
                    'totalWorkHours' => floatval($hours['totalWorkHours'] ?? 0),
                    'totalTravelHours' => floatval($hours['totalTravelHours'] ?? 0),
                    'totalHours' => floatval($hours['totalHours'] ?? 0),
                    'projectCount' => intval($hours['projectCount'] ?? 0),
                    'resourceCount' => intval($hours['resourceCount'] ?? 0),
                ],
                'milestones' => [
                    'totalValue' => $totalMilestoneValue,
                    'totalQuotHours' => $totalQuotHours,
                    'totalHoursWorked' => $totalHoursWorked,
                    'totalQuotLaborCost' => $totalQuotLaborCost,
                    'totalLaborCost' => $totalLaborCost,
                    'avgProgress' => $avgPercentage1Hour,
                    'projectCount' => $milestoneProjectCount,
                ],
                'installments' => [
                    'total' => $totalInstallments,
                    'billed' => $billedCount,
                    'expectedValue' => $totalExpectedValue,
                    'netAmount' => $totalNetAmount,
                ],
                'counts' => [
                    'totalCommesse' => intval($counts['totalCommesse'] ?? 0),
                    'totalClienti' => intval($counts['totalClienti'] ?? 0),
                ],
                'economics' => [
                    'deltaCosto' => $deltaCosto,
                    'deltaPercent' => $deltaPercent,
                    'costoMedioOrario' => $costoMedioOrario,
                ],
            ],
        ];
    }

    /**
     * 3. getHoursTrend - Trend ore mensile per anno.
     *
     * FILTRI: anno, bu, pm, cliente
     */
    public static function getHoursTrend(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_economica')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filters = self::normalizeFilters($input);
        $year = $filters['year'];
        $bu = $filters['bu'];
        $pm = $filters['pm'];
        $cliente = $filters['cliente'];

        $where = "YEAR(pt.projectTimeDate) = ?";
        $params = [$year];

        // Join con elenco_commesse se serve filtrare PM/Cliente
        $joinEc = ($pm !== '' || $cliente !== '');
        $query = "SELECT
                MONTH(pt.projectTimeDate) AS month,
                SUM(pt.workHours) AS workHours,
                SUM(pt.travelHours) AS travelHours,
                SUM(pt.totalHours) AS totalHours,
                COUNT(DISTINCT pt.idProject) AS projectCount
             FROM project_time pt";

        if ($joinEc) {
            $query .= " JOIN elenco_commesse ec ON ec.akeron_project_id = pt.idProject";
            if ($pm !== '') {
                $where .= " AND ec.responsabile_commessa = ?";
                $params[] = $pm;
            }
            if ($cliente !== '') {
                $where .= " AND ec.cliente = ?";
                $params[] = $cliente;
            }
        }

        if ($bu !== '') {
            $where .= " AND pt.idBusinessUnit = ?";
            $params[] = $bu;
        }

        $query .= " WHERE {$where} GROUP BY MONTH(pt.projectTimeDate) ORDER BY month";

        $result = $database->query($query, $params, __FILE__);
        $trend = $result->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($trend as &$row) {
            $row['month'] = intval($row['month']);
            $row['workHours'] = floatval($row['workHours']);
            $row['travelHours'] = floatval($row['travelHours']);
            $row['totalHours'] = floatval($row['totalHours']);
            $row['projectCount'] = intval($row['projectCount']);
        }

        return [
            'success' => true,
            'data' => $trend,
        ];
    }

    /**
     * 4. getProjectsEconomicSummary - Riepilogo economico per commessa.
     *
     * FILTRI: anno, bu, pm, cliente
     * Usa subquery aggregata per milestone (evita duplicazioni)
     */
    public static function getProjectsEconomicSummary(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_economica')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filters = self::normalizeFilters($input);
        $year = $filters['year'];
        $bu = $filters['bu'];
        $pm = $filters['pm'];
        $cliente = $filters['cliente'];

        // Filtri base su elenco_commesse
        $ecFilters = self::buildCommesseFilters($bu, $pm, $cliente);

        // Query con subquery aggregata per milestone dell'anno
        $result = $database->query(
            "SELECT
                ec.codice AS projectCode,
                ec.oggetto AS projectDesc,
                ec.cliente AS customerName,
                ec.stato AS projectStatus,
                ec.responsabile_commessa AS projectManager,
                ec.business_unit AS businessUnit,
                ec.akeron_project_id AS akeronId,
                COALESCE(ms.milestoneValue, 0) AS milestoneValue,
                COALESCE(ms.quotExpectedHours, 0) AS quotExpectedHours,
                COALESCE(ms.hoursWorked, 0) AS hoursWorked,
                COALESCE(ms.quotLaborCost, 0) AS quotLaborCost,
                COALESCE(ms.laborCost, 0) AS laborCost,
                COALESCE(ms.avgProgress, 0) AS avgProgress,
                COALESCE(ms.milestoneCount, 0) AS milestoneCount
             FROM elenco_commesse ec
             LEFT JOIN (
                SELECT
                    pm.idProject,
                    SUM(pm.milestoneValue) AS milestoneValue,
                    SUM(pm.quotExpectedHours) AS quotExpectedHours,
                    SUM(pm.hoursWorked) AS hoursWorked,
                    SUM(pm.quotLaborCost) AS quotLaborCost,
                    SUM(pm.laborCost) AS laborCost,
                    AVG(pm.percentage1Hour) AS avgProgress,
                    COUNT(*) AS milestoneCount
                FROM project_milestone pm
                WHERE pm.projectMilestoneRow > 0
                  AND YEAR(pm.expectedStartDate) = ?
                GROUP BY pm.idProject
             ) ms ON ms.idProject = ec.akeron_project_id
             WHERE {$ecFilters['where']}
               AND (ms.milestoneValue > 0 OR ms.milestoneCount > 0)
             ORDER BY ms.milestoneValue DESC",
            array_merge([$year], $ecFilters['params']),
            __FILE__
        );
        $rawRows = $result->fetchAll(\PDO::FETCH_ASSOC);

        // Normalizza e calcola scostamenti
        $projects = [];
        foreach ($rawRows as $row) {
            $milestoneValue = self::parseDecimal($row['milestoneValue']);
            $quotExpectedHours = self::parseDecimal($row['quotExpectedHours']);
            $hoursWorked = self::parseDecimal($row['hoursWorked']);
            $quotLaborCost = self::parseDecimal($row['quotLaborCost']);
            $laborCost = self::parseDecimal($row['laborCost']);

            $projects[] = [
                'projectCode' => $row['projectCode'],
                'projectDesc' => $row['projectDesc'],
                'customerName' => $row['customerName'],
                'projectStatus' => $row['projectStatus'],
                'projectManager' => $row['projectManager'],
                'businessUnit' => $row['businessUnit'],
                'akeronId' => $row['akeronId'],
                'milestoneValue' => $milestoneValue,
                'quotExpectedHours' => $quotExpectedHours,
                'hoursWorked' => $hoursWorked,
                'quotLaborCost' => $quotLaborCost,
                'laborCost' => $laborCost,
                'avgProgress' => self::parseDecimal($row['avgProgress']),
                'milestoneCount' => intval($row['milestoneCount']),
                'deltaHours' => $hoursWorked - $quotExpectedHours,
                'deltaCost' => $laborCost - $quotLaborCost,
                'deltaPercent' => ($quotLaborCost > 0) ? (($laborCost - $quotLaborCost) / $quotLaborCost * 100) : 0,
            ];
        }

        return [
            'success' => true,
            'data' => $projects,
        ];
    }

    /**
     * 5. getInstallments - Lista rate/scadenze (Tab Rate).
     *
     * FILTRI: anno, bu, pm, cliente
     */
    public static function getInstallments(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_economica')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filters = self::normalizeFilters($input);
        $year = $filters['year'];
        $bu = $filters['bu'];
        $pm = $filters['pm'];
        $cliente = $filters['cliente'];

        $where = "YEAR(pi.installDate) = ?";
        $params = [$year];

        if ($bu !== '') {
            $where .= " AND ec.business_unit = ?";
            $params[] = $bu;
        }
        if ($pm !== '') {
            $where .= " AND ec.responsabile_commessa = ?";
            $params[] = $pm;
        }
        if ($cliente !== '') {
            $where .= " AND ec.cliente = ?";
            $params[] = $cliente;
        }

        $result = $database->query(
            "SELECT
                pi.idProjectInstallment,
                pi.idProject,
                ec.codice AS projectCode,
                ec.oggetto AS projectDesc,
                ec.cliente AS customerName,
                ec.responsabile_commessa AS projectManager,
                pm.milestoneDescription,
                pi.installDate,
                pi.installDesc,
                pi.expectedInstallValue,
                pi.installNetAmount,
                pi.statusCode,
                pi.statusDesc,
                pi.isBilled,
                pi.invoiceNumber,
                pi.invoiceDate
             FROM project_installment pi
             JOIN elenco_commesse ec ON ec.akeron_project_id = pi.idProject
             LEFT JOIN project_milestone pm ON pm.idProjectMilestone = pi.idProjectMilestone
             WHERE {$where}
             ORDER BY pi.installDate ASC, ec.codice ASC",
            $params,
            __FILE__
        );
        $installments = $result->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($installments as &$row) {
            $row['expectedInstallValue'] = self::parseDecimal($row['expectedInstallValue']);
            $row['installNetAmount'] = self::parseDecimal($row['installNetAmount']);
            $row['isBilled'] = ($row['isBilled'] == 1 || $row['isBilled'] === '1');
        }

        return [
            'success' => true,
            'data' => $installments,
        ];
    }

    /**
     * 6. getCostsBreakdown - Breakdown costi per commessa (Tab Costi).
     *
     * FILTRI: anno, bu, pm, cliente
     * Usa subquery aggregata per evitare duplicazioni
     */
    public static function getCostsBreakdown(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_economica')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filters = self::normalizeFilters($input);
        $year = $filters['year'];
        $bu = $filters['bu'];
        $pm = $filters['pm'];
        $cliente = $filters['cliente'];

        // Filtri base su elenco_commesse
        $ecFilters = self::buildCommesseFilters($bu, $pm, $cliente);

        // Query con subquery aggregata per costi dell'anno
        // project_phase_hr ha expectedStartDate per filtrare per anno
        $result = $database->query(
            "SELECT
                ec.codice AS projectCode,
                ec.oggetto AS projectDesc,
                ec.cliente AS customerName,
                ec.responsabile_commessa AS projectManager,
                COALESCE(costs.quotLaborCost, 0) AS quotLaborCost,
                COALESCE(costs.actualLaborCost, 0) AS actualLaborCost,
                COALESCE(costs.quotHours, 0) AS quotHours,
                COALESCE(costs.actualHours, 0) AS actualHours,
                COALESCE(costs.resourceCount, 0) AS resourceCount
             FROM elenco_commesse ec
             LEFT JOIN (
                SELECT
                    phr.idProject,
                    SUM(phr.expectedCostHoursQuot) AS quotLaborCost,
                    SUM(phr.expectedCostAmount) AS actualLaborCost,
                    SUM(phr.expectedHoursQuot) AS quotHours,
                    SUM(phr.expectedHours) AS actualHours,
                    COUNT(DISTINCT phr.idHResource) AS resourceCount
                FROM project_phase_hr phr
                WHERE YEAR(phr.expectedStartDate) = ?
                GROUP BY phr.idProject
             ) costs ON costs.idProject = ec.akeron_project_id
             WHERE {$ecFilters['where']}
               AND (costs.quotLaborCost > 0 OR costs.actualLaborCost > 0)
             ORDER BY costs.actualLaborCost DESC",
            array_merge([$year], $ecFilters['params']),
            __FILE__
        );
        $rawRows = $result->fetchAll(\PDO::FETCH_ASSOC);

        // Normalizza e calcola scostamenti
        $laborCosts = [];
        foreach ($rawRows as $row) {
            $quotLaborCost = self::parseDecimal($row['quotLaborCost']);
            $actualLaborCost = self::parseDecimal($row['actualLaborCost']);

            $laborCosts[] = [
                'projectCode' => $row['projectCode'],
                'projectDesc' => $row['projectDesc'],
                'customerName' => $row['customerName'],
                'projectManager' => $row['projectManager'],
                'quotLaborCost' => $quotLaborCost,
                'actualLaborCost' => $actualLaborCost,
                'quotHours' => self::parseDecimal($row['quotHours']),
                'actualHours' => self::parseDecimal($row['actualHours']),
                'resourceCount' => intval($row['resourceCount']),
                'deltaCost' => $actualLaborCost - $quotLaborCost,
                'deltaPercent' => ($quotLaborCost > 0) ? (($actualLaborCost - $quotLaborCost) / $quotLaborCost * 100) : 0,
            ];
        }

        // Totali aggregati
        $totals = [
            'quotLaborCost' => array_sum(array_column($laborCosts, 'quotLaborCost')),
            'actualLaborCost' => array_sum(array_column($laborCosts, 'actualLaborCost')),
            'quotHours' => array_sum(array_column($laborCosts, 'quotHours')),
            'actualHours' => array_sum(array_column($laborCosts, 'actualHours')),
        ];
        $totals['deltaCost'] = $totals['actualLaborCost'] - $totals['quotLaborCost'];
        $totals['deltaPercent'] = ($totals['quotLaborCost'] > 0)
            ? (($totals['deltaCost'] / $totals['quotLaborCost']) * 100)
            : 0;

        // ── Acquisti (project_purchase) ──
        $purchaseResult = $database->query(
            "SELECT
                ec.codice AS projectCode,
                ec.oggetto AS projectDesc,
                pp.supplierName,
                pp.itemDescription,
                pp.documentNr,
                pp.projectPurchaseDate,
                pp.totalCost,
                pp.chkSubfornitura
             FROM project_purchase pp
             JOIN elenco_commesse ec ON ec.akeron_project_id = pp.idProject
             WHERE YEAR(pp.projectPurchaseDate) = ?
               AND pp.totalCost > 0
               AND {$ecFilters['where']}
             ORDER BY pp.totalCost DESC",
            array_merge([$year], $ecFilters['params']),
            __FILE__
        );
        $purchaseCosts = [];
        foreach ($purchaseResult->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $purchaseCosts[] = [
                'projectCode' => $row['projectCode'],
                'projectDesc' => $row['projectDesc'],
                'supplierName' => $row['supplierName'] ?? '',
                'itemDescription' => $row['itemDescription'] ?? '',
                'documentNr' => $row['documentNr'] ?? '',
                'date' => $row['projectPurchaseDate'],
                'totalCost' => self::parseDecimal($row['totalCost']),
                'isSubfornitura' => ($row['chkSubfornitura'] == 1),
            ];
        }

        // ── Altri Costi (project_other_cost) ──
        $otherResult = $database->query(
            "SELECT
                ec.codice AS projectCode,
                ec.oggetto AS projectDesc,
                poc.costTypeDesc,
                poc.dateCost,
                poc.expectedOtherCost,
                poc.effectiveOtherCost
             FROM project_other_cost poc
             JOIN elenco_commesse ec ON ec.akeron_project_id = poc.idProject
             WHERE YEAR(poc.dateCost) = ?
               AND (poc.expectedOtherCost > 0 OR poc.effectiveOtherCost > 0)
               AND {$ecFilters['where']}
             ORDER BY poc.effectiveOtherCost DESC",
            array_merge([$year], $ecFilters['params']),
            __FILE__
        );
        $otherCosts = [];
        foreach ($otherResult->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $expected = self::parseDecimal($row['expectedOtherCost']);
            $effective = self::parseDecimal($row['effectiveOtherCost']);
            $otherCosts[] = [
                'projectCode' => $row['projectCode'],
                'projectDesc' => $row['projectDesc'],
                'costType' => $row['costTypeDesc'] ?? '',
                'date' => $row['dateCost'],
                'expectedCost' => $expected,
                'effectiveCost' => $effective,
                'delta' => $effective - $expected,
            ];
        }

        // ── Overhead (project_overheads_cost) ──
        $overheadResult = $database->query(
            "SELECT
                ec.codice AS projectCode,
                ec.oggetto AS projectDesc,
                poh.costItemDesc,
                poh.creationDate,
                poh.quantity,
                poh.unitCost,
                poh.amountCost
             FROM project_overheads_cost poh
             JOIN elenco_commesse ec ON ec.akeron_project_id = poh.idProject
             WHERE YEAR(poh.creationDate) = ?
               AND poh.amountCost > 0
               AND {$ecFilters['where']}
             ORDER BY poh.amountCost DESC",
            array_merge([$year], $ecFilters['params']),
            __FILE__
        );
        $overheadCosts = [];
        foreach ($overheadResult->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $overheadCosts[] = [
                'projectCode' => $row['projectCode'],
                'projectDesc' => $row['projectDesc'],
                'costItem' => $row['costItemDesc'] ?? '',
                'date' => $row['creationDate'],
                'quantity' => self::parseDecimal($row['quantity']),
                'unitCost' => self::parseDecimal($row['unitCost']),
                'amountCost' => self::parseDecimal($row['amountCost']),
            ];
        }

        // ── Rimborsi (quotation_reimbursment) ──
        $reimbResult = $database->query(
            "SELECT
                ec.codice AS projectCode,
                ec.oggetto AS projectDesc,
                qr.reimbTypeDesc,
                qr.resourceName,
                qr.reimbursmentDate,
                qr.reimbursmentValue,
                qr.reimbValueAssigned,
                qr.isApproved
             FROM quotation_reimbursment qr
             JOIN elenco_commesse ec ON ec.akeron_project_id = qr.idProjectLink
             WHERE YEAR(qr.reimbursmentDate) = ?
               AND (qr.reimbursmentValue > 0 OR qr.reimbValueAssigned > 0)
               AND {$ecFilters['where']}
             ORDER BY qr.reimbursmentValue DESC",
            array_merge([$year], $ecFilters['params']),
            __FILE__
        );
        $reimbursements = [];
        foreach ($reimbResult->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $reimbursements[] = [
                'projectCode' => $row['projectCode'],
                'projectDesc' => $row['projectDesc'],
                'reimbType' => $row['reimbTypeDesc'] ?? '',
                'resourceName' => $row['resourceName'] ?? '',
                'date' => $row['reimbursmentDate'],
                'value' => self::parseDecimal($row['reimbursmentValue']),
                'assigned' => self::parseDecimal($row['reimbValueAssigned']),
                'isApproved' => ($row['isApproved'] == 1),
            ];
        }

        // ── Totali altri costi ──
        $altriTotals = [
            'totalPurchase' => array_sum(array_column($purchaseCosts, 'totalCost')),
            'totalOtherExpected' => array_sum(array_column($otherCosts, 'expectedCost')),
            'totalOtherEffective' => array_sum(array_column($otherCosts, 'effectiveCost')),
            'totalOverhead' => array_sum(array_column($overheadCosts, 'amountCost')),
            'totalReimbursements' => array_sum(array_column($reimbursements, 'value')),
        ];

        return [
            'success' => true,
            'data' => [
                'laborCosts' => $laborCosts,
                'totals' => $totals,
                'purchaseCosts' => $purchaseCosts,
                'otherCosts' => $otherCosts,
                'overheadCosts' => $overheadCosts,
                'reimbursements' => $reimbursements,
                'altriTotals' => $altriTotals,
            ],
        ];
    }

    /**
     * 7. getHrEconomicSummary - Riepilogo HR economico (Tab HR Economico).
     *
     * FILTRI: anno, bu, pm, cliente
     */
    public static function getHrEconomicSummary(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_economica')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filters = self::normalizeFilters($input);
        $year = $filters['year'];
        $bu = $filters['bu'];
        $pm = $filters['pm'];
        $cliente = $filters['cliente'];

        // === Ore lavorate per risorsa (con tutti i filtri) ===
        // JOIN con hr_resource per ottenere nomi completi delle risorse
        $whereTime = "YEAR(pt.projectTimeDate) = ?";
        $paramsTime = [$year];

        $joinEc = ($pm !== '' || $cliente !== '');
        $timeQuery = "SELECT
                pt.idHResource AS resourceId,
                COALESCE(CONCAT(hr.firstname, ' ', hr.surname), pt.resourceDesc) AS resourceName,
                pt.hrRoleDesc AS roleName,
                hr.department_desc AS department,
                SUM(pt.workHours) AS totalWorkHours,
                SUM(pt.travelHours) AS totalTravelHours,
                SUM(pt.totalHours) AS totalHours,
                COUNT(DISTINCT pt.idProject) AS projectCount
             FROM project_time pt
             LEFT JOIN hr_resource hr ON hr.id_hresource = pt.idHResource";

        if ($joinEc) {
            $timeQuery .= " JOIN elenco_commesse ec ON ec.akeron_project_id = pt.idProject";
            if ($pm !== '') {
                $whereTime .= " AND ec.responsabile_commessa = ?";
                $paramsTime[] = $pm;
            }
            if ($cliente !== '') {
                $whereTime .= " AND ec.cliente = ?";
                $paramsTime[] = $cliente;
            }
        }

        if ($bu !== '') {
            $whereTime .= " AND pt.idBusinessUnit = ?";
            $paramsTime[] = $bu;
        }

        $timeQuery .= " WHERE {$whereTime}
               AND pt.idHResource IS NOT NULL
               AND pt.idHResource != ''
             GROUP BY pt.idHResource, resourceName, roleName, hr.department_desc
             ORDER BY totalHours DESC";

        $timeResult = $database->query($timeQuery, $paramsTime, __FILE__);
        $resourceHours = $timeResult->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($resourceHours as &$row) {
            $row['totalWorkHours'] = floatval($row['totalWorkHours'] ?? 0);
            $row['totalTravelHours'] = floatval($row['totalTravelHours'] ?? 0);
            $row['totalHours'] = floatval($row['totalHours'] ?? 0);
            $row['projectCount'] = intval($row['projectCount'] ?? 0);
            $row['department'] = $row['department'] ?? '';
        }

        // === Costi previsto vs effettivo (con tutti i filtri) ===
        // JOIN con hr_resource per ottenere nomi completi delle risorse
        $ecFilters = self::buildCommesseFilters($bu, $pm, $cliente);

        $phaseResult = $database->query(
            "SELECT
                phr.idHResource AS resourceId,
                COALESCE(CONCAT(hr.firstname, ' ', hr.surname), phr.resourceName) AS resourceName,
                phr.hRoleDescription AS roleName,
                hr.department_desc AS department,
                SUM(phr.expectedHoursQuot) AS quotHours,
                SUM(phr.expectedHours) AS actualHours,
                SUM(phr.expectedCostHoursQuot) AS quotCost,
                SUM(phr.expectedCostAmount) AS actualCost,
                COUNT(DISTINCT phr.idProject) AS projectCount
             FROM project_phase_hr phr
             JOIN elenco_commesse ec ON ec.akeron_project_id = phr.idProject
             LEFT JOIN hr_resource hr ON hr.id_hresource = phr.idHResource
             WHERE YEAR(phr.expectedStartDate) = ?
               AND phr.idHResource IS NOT NULL
               AND phr.idHResource != ''
               AND {$ecFilters['where']}
             GROUP BY phr.idHResource, resourceName, roleName, hr.department_desc
             ORDER BY SUM(phr.expectedCostAmount) DESC",
            array_merge([$year], $ecFilters['params']),
            __FILE__
        );
        $rawCosts = $phaseResult->fetchAll(\PDO::FETCH_ASSOC);

        // Normalizza e calcola scostamenti
        $resourceCosts = [];
        foreach ($rawCosts as $row) {
            $quotCost = self::parseDecimal($row['quotCost']);
            $actualCost = self::parseDecimal($row['actualCost']);
            $quotHours = self::parseDecimal($row['quotHours']);
            $actualHours = self::parseDecimal($row['actualHours']);

            $resourceCosts[] = [
                'resourceId' => $row['resourceId'],
                'resourceName' => $row['resourceName'],
                'roleName' => $row['roleName'],
                'department' => $row['department'] ?? '',
                'quotHours' => $quotHours,
                'actualHours' => $actualHours,
                'quotCost' => $quotCost,
                'actualCost' => $actualCost,
                'projectCount' => intval($row['projectCount']),
                'deltaHours' => $actualHours - $quotHours,
                'deltaCost' => $actualCost - $quotCost,
                'deltaPercent' => ($quotCost > 0) ? (($actualCost - $quotCost) / $quotCost * 100) : 0,
            ];
        }

        // Totali aggregati
        $totals = [
            'totalHours' => array_sum(array_column($resourceHours, 'totalHours')),
            'totalWorkHours' => array_sum(array_column($resourceHours, 'totalWorkHours')),
            'totalTravelHours' => array_sum(array_column($resourceHours, 'totalTravelHours')),
            'quotHours' => array_sum(array_column($resourceCosts, 'quotHours')),
            'actualHours' => array_sum(array_column($resourceCosts, 'actualHours')),
            'quotCost' => array_sum(array_column($resourceCosts, 'quotCost')),
            'actualCost' => array_sum(array_column($resourceCosts, 'actualCost')),
            'resourceCount' => count($resourceHours),
        ];
        $totals['deltaCost'] = $totals['actualCost'] - $totals['quotCost'];
        $totals['deltaPercent'] = ($totals['quotCost'] > 0)
            ? (($totals['deltaCost'] / $totals['quotCost']) * 100)
            : 0;

        // === Assenze ===
        $absResult = $database->query(
            "SELECT
                ha.idHResource,
                COALESCE(CONCAT(hr.firstname, ' ', hr.surname), ha.idHResource) AS resourceName,
                ha.absenceDate,
                ha.absenceTypeCode,
                ha.absenceTypeDesc,
                ha.absenceStatusCode,
                ha.absenceStatusDesc,
                ha.absenceHours,
                ha.isApproved,
                ha.approveDate
             FROM hr_absence ha
             LEFT JOIN hr_resource hr ON hr.id_hresource = ha.idHResource
             WHERE YEAR(ha.absenceDate) = ?
             ORDER BY ha.absenceDate DESC",
            [$year],
            __FILE__
        );
        $absRows = $absResult->fetchAll(\PDO::FETCH_ASSOC);

        $absences = [];
        $totalAbsenceHours = 0;
        foreach ($absRows as $row) {
            $hours = self::parseDecimal($row['absenceHours']);
            $totalAbsenceHours += $hours;
            $absences[] = [
                'resourceName' => $row['resourceName'],
                'absenceDate' => $row['absenceDate'],
                'absenceType' => $row['absenceTypeDesc'] ?? $row['absenceTypeCode'] ?? '',
                'status' => $row['absenceStatusDesc'] ?? $row['absenceStatusCode'] ?? '',
                'hours' => $hours,
                'isApproved' => ($row['isApproved'] == 1),
                'approveDate' => $row['approveDate'],
            ];
        }

        $totals['totalAbsenceHours'] = $totalAbsenceHours;
        $totals['absenceCount'] = count($absences);

        return [
            'success' => true,
            'data' => [
                'resourceHours' => $resourceHours,
                'resourceCosts' => $resourceCosts,
                'totals' => $totals,
                'absences' => $absences,
            ],
        ];
    }

    /**
     * 8. getPipelineData - Pipeline opportunità da quotation_header.
     *
     * FILTRI: anno (su quotationDate), bu, pm, cliente
     */
    public static function getPipelineData(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_economica')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filters = self::normalizeFilters($input);
        $year = $filters['year'];

        // Solo ultime revisioni per evitare duplicati
        $result = $database->query(
            "SELECT
                qh.idQuotationHeader,
                qh.quotationNo,
                qh.quotationSubject,
                qh.quotationStatus,
                qh.quotationStatusDesc,
                qh.salesOperator,
                qh.techManager,
                qh.quotationDate,
                qh.expectedClosingDate,
                qh.effectiveClosingDate,
                qh.quotationAmount,
                qh.saleAmount,
                qh.progressPerc,
                qh.outcome,
                qh.outcomeDesc,
                qh.idProjectLink
             FROM quotation_header qh
             WHERE qh.isLastRevision = 1
               AND YEAR(qh.quotationDate) = ?
             ORDER BY qh.quotationAmount DESC",
            [$year],
            __FILE__
        );
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);

        $quotations = [];
        $totalAmount = 0;
        $wonCount = 0;
        $wonAmount = 0;
        $lostCount = 0;
        $openCount = 0;
        $openAmount = 0;

        foreach ($rows as $row) {
            $amount = self::parseDecimal($row['quotationAmount']);
            $saleAmount = self::parseDecimal($row['saleAmount']);
            $status = $row['quotationStatus'] ?? '';

            $totalAmount += $amount;

            if (stripos($status, 'CHIUSAPOS') !== false) {
                $wonCount++;
                $wonAmount += $saleAmount > 0 ? $saleAmount : $amount;
            } elseif (stripos($status, 'CHIUSANEG') !== false) {
                $lostCount++;
            } else {
                $openCount++;
                $openAmount += $amount;
            }

            $quotations[] = [
                'id' => $row['idQuotationHeader'],
                'quotationNo' => $row['quotationNo'] ?? '',
                'subject' => $row['quotationSubject'] ?? '',
                'status' => $row['quotationStatusDesc'] ?? $status,
                'statusCode' => $status,
                'salesOperator' => $row['salesOperator'] ?? '',
                'techManager' => $row['techManager'] ?? '',
                'quotationDate' => $row['quotationDate'],
                'expectedClosingDate' => $row['expectedClosingDate'],
                'amount' => $amount,
                'saleAmount' => $saleAmount,
                'progressPerc' => self::parseDecimal($row['progressPerc']),
                'outcome' => $row['outcomeDesc'] ?? $row['outcome'] ?? '',
                'projectLink' => $row['idProjectLink'] ?? '',
            ];
        }

        $totalDecided = $wonCount + $lostCount;
        $winRate = ($totalDecided > 0) ? ($wonCount / $totalDecided * 100) : 0;

        $totals = [
            'totalQuotations' => count($rows),
            'totalAmount' => $totalAmount,
            'wonCount' => $wonCount,
            'wonAmount' => $wonAmount,
            'lostCount' => $lostCount,
            'openCount' => $openCount,
            'openAmount' => $openAmount,
            'winRate' => $winRate,
        ];

        return [
            'success' => true,
            'data' => [
                'quotations' => $quotations,
                'totals' => $totals,
            ],
        ];
    }

    /**
     * 9. getInvoicesData - Fatture emesse da sales_invoice + detail.
     *
     * FILTRI: anno (invoiceYear), bu, cliente
     */
    public static function getInvoicesData(array $input): array
    {
        global $database;

        if (!userHasPermission('view_dashboard_economica')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $filters = self::normalizeFilters($input);
        $year = $filters['year'];
        $cliente = $filters['cliente'];

        $where = "si.invoiceYear = ?";
        $params = [$year];

        if ($cliente !== '') {
            $where .= " AND si.idCustomer = ?";
            $params[] = $cliente;
        }

        $result = $database->query(
            "SELECT
                si.idSalesInvoiceHeader,
                si.descInvoiceType,
                si.invoiceNumber,
                si.invoiceDate,
                si.idCustomer,
                si.paymentDesc,
                si.taxable,
                si.tax,
                si.amount
             FROM sales_invoice si
             WHERE {$where}
             ORDER BY si.invoiceDate DESC",
            $params,
            __FILE__
        );
        $rows = $result->fetchAll(\PDO::FETCH_ASSOC);

        $invoices = [];
        $totalTaxable = 0;
        $totalTax = 0;
        $totalAmount = 0;

        foreach ($rows as $row) {
            $taxable = self::parseDecimal($row['taxable']);
            $tax = self::parseDecimal($row['tax']);
            $amount = self::parseDecimal($row['amount']);
            $totalTaxable += $taxable;
            $totalTax += $tax;
            $totalAmount += $amount;

            $invoices[] = [
                'id' => $row['idSalesInvoiceHeader'],
                'invoiceType' => $row['descInvoiceType'] ?? '',
                'invoiceNumber' => $row['invoiceNumber'] ?? '',
                'invoiceDate' => $row['invoiceDate'],
                'customerId' => $row['idCustomer'] ?? '',
                'paymentDesc' => $row['paymentDesc'] ?? '',
                'taxable' => $taxable,
                'tax' => $tax,
                'amount' => $amount,
            ];
        }

        $totals = [
            'invoiceCount' => count($rows),
            'totalTaxable' => $totalTaxable,
            'totalTax' => $totalTax,
            'totalAmount' => $totalAmount,
        ];

        return [
            'success' => true,
            'data' => [
                'invoices' => $invoices,
                'totals' => $totals,
            ],
        ];
    }
}
