<?php

namespace Services;

class CommessaCronoService
{
    /**
     * Recupera dati per widget "Cronoprogramma Stimato" (timeline semplificata)
     *
     * @param string $codiceCommessa Codice commessa (es. "3DY01")
     * @return array ['success' => bool, 'fasi' => array, 'mesi' => array]
     */
    public static function getCronoProgrammaStimato(string $codiceCommessa): array
    {
        global $database;

        if (empty($codiceCommessa)) {
            return ['success' => false, 'message' => 'Codice commessa mancante'];
        }

        // Sanitizzazione input (whitelist alfanumerici, underscore, trattino)
        if (!preg_match('/^[A-Z0-9_-]{2,30}$/i', $codiceCommessa)) {
            return ['success' => false, 'message' => 'Codice commessa non valido'];
        }

        try {
            // Lookup elenco_commesse con entrambi i candidati
            $lookupQuery = "SELECT codice, akeron_project_id FROM elenco_commesse WHERE codice = :codice LIMIT 1";
            $lookupStmt = $database->query($lookupQuery, [':codice' => $codiceCommessa], __FILE__);
            $row = $lookupStmt ? $lookupStmt->fetch(\PDO::FETCH_ASSOC) : null;

            if (!$row) {
                return ['success' => false, 'message' => 'Commessa non trovata'];
            }

            // Calcola due candidati projectId
            $candidate1 = strtoupper(trim($row['akeron_project_id'] ?? ''));
            $candidate2 = strtoupper(trim($row['codice'] ?? $codiceCommessa));

            // Probe query: quale candidato ha milestone reali?
            $projectIdForAkeronTables = null;

            // Test candidate1 (akeron_project_id)
            if (!empty($candidate1)) {
                $probeStmt = $database->query(
                    "SELECT 1 FROM project_milestone WHERE idProject = :id LIMIT 1",
                    [':id' => $candidate1],
                    __FILE__
                );
                if ($probeStmt && $probeStmt->fetch()) {
                    $projectIdForAkeronTables = $candidate1;
                }
            }

            // Fallback: test candidate2 (codice commessa)
            if (!$projectIdForAkeronTables && !empty($candidate2)) {
                $probeStmt = $database->query(
                    "SELECT 1 FROM project_milestone WHERE idProject = :id LIMIT 1",
                    [':id' => $candidate2],
                    __FILE__
                );
                if ($probeStmt && $probeStmt->fetch()) {
                    $projectIdForAkeronTables = $candidate2;
                }
            }

            // Se nessun candidato ha milestone, ritorna success=true con dati vuoti (non errore)
            if (!$projectIdForAkeronTables) {
                return ['success' => true, 'fasi' => [], 'mesi' => [], 'message' => 'Nessuna milestone trovata per questa commessa'];
            }

            $akeronProjectId = $projectIdForAkeronTables;

            // Query milestone con date
            $milestonesQuery = "
                SELECT
                    idProjectMilestone,
                    milestoneDescription,
                    expectedStartDate,
                    expectedEndDate,
                    effectiveStartDate,
                    effectiveEndDate,
                    projectMilestoneRow
                FROM project_milestone
                WHERE idProject = :akeronProjectId
                ORDER BY expectedStartDate IS NULL, expectedStartDate ASC, projectMilestoneRow ASC
            ";
            $milestonesStmt = $database->query($milestonesQuery, [':akeronProjectId' => $akeronProjectId], __FILE__);
            $milestones = $milestonesStmt ? $milestonesStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            if (empty($milestones)) {
                return ['success' => true, 'fasi' => [], 'mesi' => [], 'message' => 'Nessuna milestone trovata'];
            }

            // Filtra milestone con date valide e calcola range
            $validMilestones = [];
            $allDates = [];

            foreach ($milestones as $m) {
                $startDate = !empty($m['expectedStartDate']) ? $m['expectedStartDate'] : $m['effectiveStartDate'];
                $endDate = !empty($m['expectedEndDate']) ? $m['expectedEndDate'] : $m['effectiveEndDate'];

                if (!empty($startDate) && !empty($endDate)) {
                    $validMilestones[] = [
                        'label' => $m['milestoneDescription'] ?: 'Fase senza nome',
                        'startDate' => $startDate,
                        'endDate' => $endDate,
                        'row' => $m['projectMilestoneRow']
                    ];

                    $allDates[] = strtotime($startDate);
                    $allDates[] = strtotime($endDate);
                }
            }

            if (empty($validMilestones)) {
                return ['success' => true, 'fasi' => [], 'mesi' => [], 'message' => 'Nessuna milestone con date definite'];
            }

            // Calcola range totale
            $minTimestamp = min($allDates);
            $maxTimestamp = max($allDates);
            $rangeSeconds = $maxTimestamp - $minTimestamp;

            if ($rangeSeconds <= 0) {
                return ['success' => true, 'fasi' => [], 'mesi' => [], 'message' => 'Range date non valido'];
            }

            // Genera array fasi con percentuali
            $fasi = [];
            $colorClasses = ['fase-1', 'fase-2', 'fase-3', 'fase-4'];

            foreach ($validMilestones as $index => $milestone) {
                $startTimestamp = strtotime($milestone['startDate']);
                $endTimestamp = strtotime($milestone['endDate']);

                $startPercent = (($startTimestamp - $minTimestamp) / $rangeSeconds) * 100;
                $durationPercent = (($endTimestamp - $startTimestamp) / $rangeSeconds) * 100;

                $fasi[] = [
                    'label' => $milestone['label'],
                    'start' => round($startPercent, 1),
                    'width' => round(max(1, $durationPercent), 1),
                    'class' => $colorClasses[$index % count($colorClasses)]
                ];
            }

            // Genera array mesi (header timeline)
            $mesi = self::generateMonthsArray($minTimestamp, $maxTimestamp);

            return [
                'success' => true,
                'fasi' => $fasi,
                'mesi' => $mesi
            ];

        } catch (\Exception $e) {
            error_log("CommessaCronoService::getCronoProgrammaStimato Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nel caricamento dati'];
        }
    }

    /**
     * Genera array mesi per header timeline
     *
     * @param int $minTimestamp Timestamp inizio range
     * @param int $maxTimestamp Timestamp fine range
     * @return array Array mesi (es. ['Gen 2025', 'Feb 2025', ...])
     */
    private static function generateMonthsArray(int $minTimestamp, int $maxTimestamp): array
    {
        $mesi = [];
        $current = strtotime(date('Y-m-01', $minTimestamp)); // Primo giorno del mese iniziale

        // Mapping mesi IT (alternativa a strftime deprecato)
        $monthsIT = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

        while ($current <= $maxTimestamp) {
            $monthNum = (int)date('n', $current); // 1-12
            $year = date('Y', $current);
            $mesi[] = $monthsIT[$monthNum - 1] . ' ' . $year; // Es. "Gen 2025"
            $current = strtotime('+1 month', $current);
        }

        return $mesi;
    }

    /**
     * Recupera tutti i dati necessari per la pagina cronoprogramma
     *
     * @param string $idProject Codice commessa (es. "3DY01")
     * @return array Array con success, data (project, milestones, phases, installments)
     */
    public static function getPageData(string $idProject): array
    {
        global $database;

        if (empty($idProject)) {
            return ['success' => false, 'message' => 'ID Progetto mancante'];
        }

        try {
            error_log("CommessaCronoService::getPageData chiamato con idProject: " . $idProject);

            // Query 1 — Dati header commessa (da elenco_commesse) + recupero akeron_project_id
            $projectQuery = "
                SELECT
                    codice as IdProject,
                    oggetto as ProjectDesc,
                    stato as ProjectStatusCode,
                    stato as ProjectStatusDesc,
                    responsabile_commessa as ProjectManagerName,
                    responsabile_commessa as TeamLeaderName,
                    data_inizio_prevista as OpenDate,
                    data_fine_prevista as ExpecteDelivDate,
                    '' as ConfirmeDelivDate,
                    business_unit as IdBusinessUnit,
                    '' as IdProjectCategory,
                    cliente,
                    akeron_project_id
                FROM elenco_commesse
                WHERE codice = :idProject
                LIMIT 1
            ";
            $projectStmt = $database->query($projectQuery, [':idProject' => $idProject], __FILE__);
            $project = $projectStmt ? $projectStmt->fetch(\PDO::FETCH_ASSOC) : null;

            if (!$project) {
                error_log("CommessaCronoService::getPageData - Commessa non trovata per ID: " . $idProject);
                return ['success' => false, 'message' => 'Commessa non trovata'];
            }

            $akeronProjectId = $project['akeron_project_id'] ?? null;
            if (!$akeronProjectId) {
                error_log("CommessaCronoService::getPageData - akeron_project_id mancante per: " . $idProject);
                return ['success' => false, 'message' => 'ID progetto Akeron non trovato'];
            }

            error_log("CommessaCronoService::getPageData - Commessa trovata: " . json_encode($project));

            // Normalizza le date dal formato CSV dd/mm/yyyy a Y-m-d
            $project['OpenDate'] = self::normalizeDateFromCSV($project['OpenDate'] ?? '');
            $project['ExpecteDelivDate'] = self::normalizeDateFromCSV($project['ExpecteDelivDate'] ?? '');
            $project['ConfirmeDelivDate'] = self::normalizeDateFromCSV($project['ConfirmeDelivDate'] ?? '');

            // Query 2 — Fasi / Milestone (tabella canonica)
            $milestonesQuery = "
                SELECT
                    idProjectMilestone as IdProjectMilestone,
                    idProject as IdProject,
                    projectMilestoneRow as ProjectMilestoneRow,
                    milestoneStatus as MilestoneStatus,
                    milestoneDescription as MilestoneDescription,
                    milestoneManagerName as MilestoneManagerName,
                    idMilestoneManager as IdMilestoneManager,
                    expectedStartDate as ExpectedStartDate,
                    expectedEndDate as ExpectedEndDate,
                    milestoneValue as MilestoneValue,
                    quotExpectedHours as QuotExpectedHours,
                    hoursWorked as HoursWorked,
                    expectedHours as ExpectedHours,
                    quotLaborCost as QuotLaborCost,
                    laborCost as LaborCost,
                    percentage1Hour as Percentage1Hour,
                    accrual1Hour as Accrual1Hour
                FROM project_milestone
                WHERE idProject = :akeronProjectId
                  AND projectMilestoneRow > 0
                ORDER BY expectedStartDate IS NULL, expectedStartDate ASC, projectMilestoneRow ASC
            ";
            $milestonesStmt = $database->query($milestonesQuery, [':akeronProjectId' => $akeronProjectId], __FILE__);
            $milestones = $milestonesStmt ? $milestonesStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            // Normalizza date e valori per ogni milestone
            foreach ($milestones as &$milestone) {
                $milestone['ExpectedStartDate'] = self::normalizeDateFromCSV($milestone['ExpectedStartDate'] ?? '');
                $milestone['ExpectedEndDate'] = self::normalizeDateFromCSV($milestone['ExpectedEndDate'] ?? '');
                $milestone['MilestoneValue'] = self::normalizeDecimalFromCSV($milestone['MilestoneValue'] ?? '0');
                $milestone['QuotExpectedHours'] = self::normalizeDecimalFromCSV($milestone['QuotExpectedHours'] ?? '0');
                $milestone['HoursWorked'] = self::normalizeDecimalFromCSV($milestone['HoursWorked'] ?? '0');
                $milestone['QuotLaborCost'] = self::normalizeDecimalFromCSV($milestone['QuotLaborCost'] ?? '0');
                $milestone['LaborCost'] = self::normalizeDecimalFromCSV($milestone['LaborCost'] ?? '0');
            }
            unset($milestone);

            // Query 3 — Sotto-attività (tabella canonica)
            $phasesQuery = "
                SELECT
                    idProjectPhaseHR as IdProjectPhaseHR,
                    idProject as IdProject,
                    idProjectMilestone as IdProjectMilestone,
                    projectMilestoneRow as ProjectMilestoneRow,
                    phaseHRCode as PhaseHRCode,
                    phaseHRDescTable as PhaseHRDescTable,
                    phaseHRDescImpromptu as PhaseHRDescImpromptu,
                    statusCode as StatusCode,
                    statusDescription as StatusDescription,
                    hRoleDescription as HRoleDescription,
                    resourceName as ResourceName,
                    idHResource as IdHResource,
                    expectedStartDate as ExpectedStartDate,
                    expectedEndDate as ExpectedEndDate,
                    effectiveStartDate as EffectiveStartDate,
                    effectiveEndDate as EffectiveEndDate,
                    expectedHours as ExpectedHours,
                    expectedCostAmount as ExpectedCostAmount,
                    expectedHoursQuot as ExpectedHoursQuot,
                    expectedCostHoursQuot as ExpectedCostHoursQuot
                FROM project_phase_hr
                WHERE idProject = :akeronProjectId
                ORDER BY idProjectMilestone ASC, projectPhaseHRow ASC
            ";
            $phasesStmt = $database->query($phasesQuery, [':akeronProjectId' => $akeronProjectId], __FILE__);
            $phases = $phasesStmt ? $phasesStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            // Normalizza date per ogni phase
            foreach ($phases as &$phase) {
                $phase['ExpectedStartDate'] = self::normalizeDateFromCSV($phase['ExpectedStartDate'] ?? '');
                $phase['ExpectedEndDate'] = self::normalizeDateFromCSV($phase['ExpectedEndDate'] ?? '');
                $phase['EffectiveStartDate'] = self::normalizeDateFromCSV($phase['EffectiveStartDate'] ?? '');
                $phase['EffectiveEndDate'] = self::normalizeDateFromCSV($phase['EffectiveEndDate'] ?? '');
                $phase['ExpectedHours'] = self::normalizeDecimalFromCSV($phase['ExpectedHours'] ?? '0');
                $phase['ExpectedCostAmount'] = self::normalizeDecimalFromCSV($phase['ExpectedCostAmount'] ?? '0');
                $phase['ExpectedHoursQuot'] = self::normalizeDecimalFromCSV($phase['ExpectedHoursQuot'] ?? '0');
                $phase['ExpectedCostHoursQuot'] = self::normalizeDecimalFromCSV($phase['ExpectedCostHoursQuot'] ?? '0');
            }
            unset($phase);

            // Query 4 — Scadenze pagamento (tabella canonica)
            $installmentsQuery = "
                SELECT
                    pi.idProjectInstallment as IdProjectInstallment,
                    pi.idProject as IdProject,
                    pi.idProjectMilestone as IdProjectMilestone,
                    pi.installDate as InstallDate,
                    pi.installDesc as InstallDesc,
                    pi.expectedInstallValue as ExpectedInstallValue,
                    pi.installNetAmount as InstallNetAmount,
                    pi.statusCode as StatusCode,
                    pi.statusDesc as StatusDesc,
                    pi.isBilled as IsBilled,
                    pi.invoiceNumber as InvoiceNumber,
                    pi.invoiceDate as InvoiceDate
                FROM project_installment pi
                WHERE pi.idProject = :akeronProjectId
                ORDER BY pi.installDate IS NULL, pi.installDate ASC, pi.projectInstallmentRow ASC
            ";
            $installmentsStmt = $database->query($installmentsQuery, [':akeronProjectId' => $akeronProjectId], __FILE__);
            $installments = $installmentsStmt ? $installmentsStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            // Normalizza date e valori per ogni installment
            foreach ($installments as &$installment) {
                $installment['InstallDate'] = self::normalizeDateFromCSV($installment['InstallDate'] ?? '');
                $installment['InvoiceDate'] = self::normalizeDateFromCSV($installment['InvoiceDate'] ?? '');
                $installment['ExpectedInstallValue'] = self::normalizeDecimalFromCSV($installment['ExpectedInstallValue'] ?? '0');
                $installment['InstallNetAmount'] = self::normalizeDecimalFromCSV($installment['InstallNetAmount'] ?? '0');
            }
            unset($installment);

            return [
                'success' => true,
                'data' => [
                    'project' => $project,
                    'milestones' => $milestones,
                    'phases' => $phases,
                    'installments' => $installments
                ]
            ];
        } catch (\Exception $e) {
            error_log("CommessaCronoService::getPageData Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nel caricamento dei dati: ' . $e->getMessage()];
        }
    }

    /**
     * Normalizza date dal formato CSV (dd/mm/yyyy) a formato Y-m-d
     *
     * @param string $dateStr Data in formato dd/mm/yyyy o vuota
     * @return string Data in formato Y-m-d o stringa vuota
     */
    private static function normalizeDateFromCSV(string $dateStr): string
    {
        if (empty($dateStr) || $dateStr === '—' || $dateStr === '-') {
            return '';
        }

        // Se è già nel formato Y-m-d, restituiscila così com'è
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $dateStr;
        }

        // Formato dd/mm/yyyy
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateStr, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1]; // Y-m-d
        }

        return '';
    }

    /**
     * Normalizza valori decimali dal formato CSV (con virgola) a float
     *
     * @param string $value Valore in formato CSV (es. "12200,00")
     * @return float Valore convertito
     */
    private static function normalizeDecimalFromCSV(string $value): float
    {
        if (empty($value) || $value === '—' || $value === '-') {
            return 0.0;
        }

        // Rimuove eventuali spazi e sostituisce la virgola con il punto
        $value = str_replace(' ', '', $value);
        $value = str_replace(',', '.', $value);

        return (float) $value;
    }
}
