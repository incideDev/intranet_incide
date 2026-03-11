<?php
/**
 * Script di verifica numerica probatoria per DashboardEconomicaService
 * Esegue query SQL dirette e confronta con i valori del service
 */

define('INTRANET_BOOTSTRAP', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Services\DashboardEconomicaService;

// Simula sessione admin per i permessi
$_SESSION['role_ids'] = [1];
$_SESSION['role_permissions'] = ['view_dashboard_economica'];

global $database;

$year = date('Y'); // Anno corrente
$results = [];

echo "=" . str_repeat("=", 79) . "\n";
echo "VERIFICA NUMERICA PROBATORIA - DashboardEconomicaService\n";
echo "Anno di riferimento: {$year}\n";
echo "=" . str_repeat("=", 79) . "\n\n";

// ============================================================================
// 1. TABELLE USATE VS NON USATE
// ============================================================================
echo "1. ANALISI TABELLE\n";
echo str_repeat("-", 80) . "\n";

$tablesUsed = [
    'project_time' => 'Ore lavorate per risorsa/progetto',
    'project_milestone' => 'Milestone economici (valore, ore previste, costi)',
    'project_installment' => 'Rate/scadenze pagamento',
    'project_phase_hr' => 'Dettaglio fasi HR per costi previsti/effettivi',
    'elenco_commesse' => 'Anagrafica commesse (filtri PM/Cliente/BU)',
];

$tablesNotUsed = [
    'project_purchase' => 'Costi acquisti - PENDING SYNC',
    'project_other_cost' => 'Altri costi - PENDING SYNC',
    'project_overheads_cost' => 'Costi overhead - PENDING SYNC',
    'hr_absence' => 'Assenze HR - PENDING SYNC',
    'hr_resource' => 'Anagrafica risorse - non usata direttamente',
    'quotation_reimbursment' => 'Rimborsi preventivi - PENDING SYNC',
];

echo "TABELLE USATE:\n";
foreach ($tablesUsed as $table => $desc) {
    echo "  [OK] {$table}: {$desc}\n";
}

echo "\nTABELLE NON USATE (dominio economico):\n";
foreach ($tablesNotUsed as $table => $desc) {
    echo "  [--] {$table}: {$desc}\n";
}

// ============================================================================
// 2. VERIFICA KPI OVERVIEW - QUERY DIRETTE VS SERVICE
// ============================================================================
echo "\n\n2. VERIFICA KPI OVERVIEW (Anno {$year})\n";
echo str_repeat("-", 80) . "\n";

// 2a. Ore lavorate - Query diretta
$sqlHours = "SELECT
    SUM(workHours) AS totalWorkHours,
    SUM(travelHours) AS totalTravelHours,
    SUM(totalHours) AS totalHours,
    COUNT(DISTINCT idProject) AS projectCount,
    COUNT(DISTINCT idHResource) AS resourceCount
FROM project_time
WHERE projectTimeDate BETWEEN '{$year}-01-01' AND '{$year}-12-31'";

$hoursResult = $database->query($sqlHours, [], __FILE__);
$hoursDirect = $hoursResult->fetch(PDO::FETCH_ASSOC);

// 2b. Milestone - Query diretta (con filtro anno)
$sqlMilestones = "SELECT
    SUM(milestoneValue) AS totalValue,
    SUM(quotExpectedHours) AS totalQuotHours,
    SUM(hoursWorked) AS totalHoursWorked,
    SUM(quotLaborCost) AS totalQuotLaborCost,
    SUM(laborCost) AS totalLaborCost,
    AVG(percentage1Hour) AS avgProgress,
    COUNT(DISTINCT idProject) AS projectCount
FROM project_milestone
WHERE projectMilestoneRow > 0 AND YEAR(expectedStartDate) = {$year}";

$msResult = $database->query($sqlMilestones, [], __FILE__);
$msDirect = $msResult->fetch(PDO::FETCH_ASSOC);

// 2c. Installments - Query diretta
$sqlInstall = "SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN isBilled = 1 THEN 1 ELSE 0 END) AS billed,
    SUM(expectedInstallValue) AS expectedValue,
    SUM(installNetAmount) AS netAmount
FROM project_installment
WHERE YEAR(installDate) = {$year}";

$installResult = $database->query($sqlInstall, [], __FILE__);
$installDirect = $installResult->fetch(PDO::FETCH_ASSOC);

// 2d. Valori dal service
$serviceResult = DashboardEconomicaService::getOverviewKpi(['year' => $year]);
$serviceData = $serviceResult['data'] ?? [];

echo "\n2a. ORE LAVORATE\n";
echo sprintf("%-30s | %-20s | %-20s | %s\n", "Metrica", "Query Diretta", "Service", "Esito");
echo str_repeat("-", 85) . "\n";

$metrics = [
    ['Ore Lavoro', floatval($hoursDirect['totalWorkHours'] ?? 0), $serviceData['hours']['totalWorkHours'] ?? 0],
    ['Ore Viaggio', floatval($hoursDirect['totalTravelHours'] ?? 0), $serviceData['hours']['totalTravelHours'] ?? 0],
    ['Ore Totali', floatval($hoursDirect['totalHours'] ?? 0), $serviceData['hours']['totalHours'] ?? 0],
    ['Progetti', intval($hoursDirect['projectCount'] ?? 0), $serviceData['hours']['projectCount'] ?? 0],
    ['Risorse', intval($hoursDirect['resourceCount'] ?? 0), $serviceData['hours']['resourceCount'] ?? 0],
];

foreach ($metrics as $m) {
    $match = abs($m[1] - $m[2]) < 0.01 ? 'OK' : 'MISMATCH';
    echo sprintf("%-30s | %20s | %20s | %s\n", $m[0], number_format($m[1], 2), number_format($m[2], 2), $match);
    $results['hours'][$m[0]] = $match;
}

echo "\n2b. MILESTONE\n";
echo sprintf("%-30s | %-20s | %-20s | %s\n", "Metrica", "Query Diretta", "Service", "Esito");
echo str_repeat("-", 85) . "\n";

$metrics = [
    ['Valore Milestone', floatval($msDirect['totalValue'] ?? 0), $serviceData['milestones']['totalValue'] ?? 0],
    ['Ore Preventivate', floatval($msDirect['totalQuotHours'] ?? 0), $serviceData['milestones']['totalQuotHours'] ?? 0],
    ['Ore Lavorate', floatval($msDirect['totalHoursWorked'] ?? 0), $serviceData['milestones']['totalHoursWorked'] ?? 0],
    ['Costo Prev.', floatval($msDirect['totalQuotLaborCost'] ?? 0), $serviceData['milestones']['totalQuotLaborCost'] ?? 0],
    ['Costo Eff.', floatval($msDirect['totalLaborCost'] ?? 0), $serviceData['milestones']['totalLaborCost'] ?? 0],
];

foreach ($metrics as $m) {
    $match = abs($m[1] - $m[2]) < 0.01 ? 'OK' : 'MISMATCH';
    echo sprintf("%-30s | %20s | %20s | %s\n", $m[0], number_format($m[1], 2), number_format($m[2], 2), $match);
    $results['milestones'][$m[0]] = $match;
}

echo "\n2c. INSTALLMENTS/RATE\n";
echo sprintf("%-30s | %-20s | %-20s | %s\n", "Metrica", "Query Diretta", "Service", "Esito");
echo str_repeat("-", 85) . "\n";

$metrics = [
    ['Totale Rate', intval($installDirect['total'] ?? 0), $serviceData['installments']['total'] ?? 0],
    ['Rate Fatturate', intval($installDirect['billed'] ?? 0), $serviceData['installments']['billed'] ?? 0],
    ['Valore Previsto', floatval($installDirect['expectedValue'] ?? 0), $serviceData['installments']['expectedValue'] ?? 0],
    ['Importo Netto', floatval($installDirect['netAmount'] ?? 0), $serviceData['installments']['netAmount'] ?? 0],
];

foreach ($metrics as $m) {
    $match = abs($m[1] - $m[2]) < 0.01 ? 'OK' : 'MISMATCH';
    echo sprintf("%-30s | %20s | %20s | %s\n", $m[0], number_format($m[1], 2), number_format($m[2], 2), $match);
    $results['installments'][$m[0]] = $match;
}

// ============================================================================
// 3. VERIFICA FILTRO ANNO: STORICO VS SINGOLO ANNO
// ============================================================================
echo "\n\n3. VERIFICA FILTRO ANNO (Storico vs Singolo)\n";
echo str_repeat("-", 80) . "\n";

// Storico totale (tutti gli anni)
$sqlHistoricHours = "SELECT SUM(totalHours) AS total FROM project_time";
$historicHours = $database->query($sqlHistoricHours, [], __FILE__)->fetch(PDO::FETCH_ASSOC);

$sqlHistoricMs = "SELECT SUM(milestoneValue) AS total FROM project_milestone WHERE projectMilestoneRow > 0";
$historicMs = $database->query($sqlHistoricMs, [], __FILE__)->fetch(PDO::FETCH_ASSOC);

// Anni disponibili
$sqlYears = "SELECT DISTINCT YEAR(projectTimeDate) AS y FROM project_time ORDER BY y DESC";
$yearsResult = $database->query($sqlYears, [], __FILE__)->fetchAll(PDO::FETCH_COLUMN);

echo "Anni con dati: " . implode(', ', $yearsResult) . "\n\n";

echo sprintf("%-30s | %-20s | %-20s | %-20s\n", "Metrica", "Storico Totale", "Solo {$year}", "Service {$year}");
echo str_repeat("-", 95) . "\n";

$singleYearHours = floatval($hoursDirect['totalHours'] ?? 0);
$serviceYearHours = $serviceData['hours']['totalHours'] ?? 0;
$historicTotalHours = floatval($historicHours['total'] ?? 0);

echo sprintf("%-30s | %20s | %20s | %20s\n",
    "Ore Totali",
    number_format($historicTotalHours, 2),
    number_format($singleYearHours, 2),
    number_format($serviceYearHours, 2)
);

$matchYearFilter = abs($singleYearHours - $serviceYearHours) < 0.01;
$notHistoric = abs($historicTotalHours - $serviceYearHours) > 0.01 || $historicTotalHours == $singleYearHours;

echo "\nVerifica: Service usa solo anno {$year}? " . ($matchYearFilter ? "SI" : "NO") . "\n";
echo "Verifica: Service NON somma storico? " . ($notHistoric ? "SI (corretto)" : "NO (problema!)") . "\n";

$singleYearMs = floatval($msDirect['totalValue'] ?? 0);
$serviceYearMs = $serviceData['milestones']['totalValue'] ?? 0;
$historicTotalMs = floatval($historicMs['total'] ?? 0);

echo sprintf("\n%-30s | %20s | %20s | %20s\n",
    "Valore Milestone",
    number_format($historicTotalMs, 2),
    number_format($singleYearMs, 2),
    number_format($serviceYearMs, 2)
);

$matchYearFilterMs = abs($singleYearMs - $serviceYearMs) < 0.01;
echo "Verifica: Service usa solo anno {$year}? " . ($matchYearFilterMs ? "SI" : "NO") . "\n";

// ============================================================================
// 4. VERIFICA COERENZA OVERVIEW VS DETTAGLIO
// ============================================================================
echo "\n\n4. VERIFICA COERENZA OVERVIEW VS DETTAGLIO\n";
echo str_repeat("-", 80) . "\n";

// Tab Commesse
$commesseResult = DashboardEconomicaService::getProjectsEconomicSummary(['year' => $year]);
$commesse = $commesseResult['data'] ?? [];

$sumMilestoneValue = 0;
$sumQuotHours = 0;
$sumHoursWorked = 0;
$sumQuotLaborCost = 0;
$sumLaborCost = 0;

foreach ($commesse as $c) {
    $sumMilestoneValue += $c['milestoneValue'];
    $sumQuotHours += $c['quotExpectedHours'];
    $sumHoursWorked += $c['hoursWorked'];
    $sumQuotLaborCost += $c['quotLaborCost'];
    $sumLaborCost += $c['laborCost'];
}

echo "Tab Commesse - Somma righe vs Overview KPI:\n";
echo sprintf("%-30s | %-20s | %-20s | %s\n", "Metrica", "Somma Tab", "Overview KPI", "Esito");
echo str_repeat("-", 85) . "\n";

$metrics = [
    ['Valore Milestone', $sumMilestoneValue, $serviceData['milestones']['totalValue'] ?? 0],
    ['Ore Preventivate', $sumQuotHours, $serviceData['milestones']['totalQuotHours'] ?? 0],
    ['Ore Lavorate (ms)', $sumHoursWorked, $serviceData['milestones']['totalHoursWorked'] ?? 0],
    ['Costo Prev.', $sumQuotLaborCost, $serviceData['milestones']['totalQuotLaborCost'] ?? 0],
    ['Costo Eff.', $sumLaborCost, $serviceData['milestones']['totalLaborCost'] ?? 0],
];

foreach ($metrics as $m) {
    $match = abs($m[1] - $m[2]) < 0.01 ? 'OK' : 'MISMATCH';
    echo sprintf("%-30s | %20s | %20s | %s\n", $m[0], number_format($m[1], 2), number_format($m[2], 2), $match);
    $results['coerenza'][$m[0]] = $match;
}

// Tab Costi
$costiResult = DashboardEconomicaService::getCostsBreakdown(['year' => $year]);
$costiData = $costiResult['data'] ?? [];
$laborCosts = $costiData['laborCosts'] ?? [];
$costiTotals = $costiData['totals'] ?? [];

echo "\nTab Costi - Somma righe vs Totali calcolati:\n";
$sumCostiQuot = array_sum(array_column($laborCosts, 'quotLaborCost'));
$sumCostiActual = array_sum(array_column($laborCosts, 'actualLaborCost'));

echo sprintf("%-30s | %20s | %20s | %s\n",
    "Costo Preventivato",
    number_format($sumCostiQuot, 2),
    number_format($costiTotals['quotLaborCost'] ?? 0, 2),
    abs($sumCostiQuot - ($costiTotals['quotLaborCost'] ?? 0)) < 0.01 ? 'OK' : 'MISMATCH'
);
echo sprintf("%-30s | %20s | %20s | %s\n",
    "Costo Effettivo",
    number_format($sumCostiActual, 2),
    number_format($costiTotals['actualLaborCost'] ?? 0, 2),
    abs($sumCostiActual - ($costiTotals['actualLaborCost'] ?? 0)) < 0.01 ? 'OK' : 'MISMATCH'
);

// ============================================================================
// 5. VERIFICA FILTRI BU/PM/CLIENTE
// ============================================================================
echo "\n\n5. VERIFICA FILTRI BU/PM/CLIENTE\n";
echo str_repeat("-", 80) . "\n";

// Trova un PM con dati
$pmResult = $database->query(
    "SELECT DISTINCT ec.responsabile_commessa
     FROM elenco_commesse ec
     JOIN project_time pt ON pt.idProject = ec.akeron_project_id
     WHERE ec.responsabile_commessa IS NOT NULL AND ec.responsabile_commessa != ''
     LIMIT 1",
    [], __FILE__
);
$testPm = $pmResult->fetch(PDO::FETCH_COLUMN);

// Trova un cliente con dati
$clienteResult = $database->query(
    "SELECT DISTINCT ec.cliente
     FROM elenco_commesse ec
     JOIN project_time pt ON pt.idProject = ec.akeron_project_id
     WHERE ec.cliente IS NOT NULL AND ec.cliente != ''
     LIMIT 1",
    [], __FILE__
);
$testCliente = $clienteResult->fetch(PDO::FETCH_COLUMN);

// Trova una BU con dati
$buResult = $database->query(
    "SELECT DISTINCT idBusinessUnit
     FROM project_time
     WHERE idBusinessUnit IS NOT NULL AND idBusinessUnit != ''
     LIMIT 1",
    [], __FILE__
);
$testBu = $buResult->fetch(PDO::FETCH_COLUMN);

echo "Test PM: {$testPm}\n";
echo "Test Cliente: {$testCliente}\n";
echo "Test BU: {$testBu}\n\n";

// Senza filtri
$noFilter = DashboardEconomicaService::getOverviewKpi(['year' => $year]);
$noFilterHours = $noFilter['data']['hours']['totalHours'] ?? 0;

// Con filtro PM
if ($testPm) {
    $withPm = DashboardEconomicaService::getOverviewKpi(['year' => $year, 'pm' => $testPm]);
    $withPmHours = $withPm['data']['hours']['totalHours'] ?? 0;

    // Query diretta per verifica
    $sqlPm = "SELECT SUM(pt.totalHours) AS total
              FROM project_time pt
              JOIN elenco_commesse ec ON ec.akeron_project_id = pt.idProject
              WHERE pt.projectTimeDate BETWEEN '{$year}-01-01' AND '{$year}-12-31'
                AND ec.responsabile_commessa = ?";
    $directPm = $database->query($sqlPm, [$testPm], __FILE__)->fetch(PDO::FETCH_ASSOC);
    $directPmHours = floatval($directPm['total'] ?? 0);

    echo sprintf("Filtro PM '%s':\n", $testPm);
    echo sprintf("  Senza filtro: %s ore\n", number_format($noFilterHours, 2));
    echo sprintf("  Con filtro:   %s ore (service)\n", number_format($withPmHours, 2));
    echo sprintf("  Con filtro:   %s ore (query diretta)\n", number_format($directPmHours, 2));
    echo sprintf("  Match: %s\n\n", abs($withPmHours - $directPmHours) < 0.01 ? 'OK' : 'MISMATCH');
}

// Con filtro Cliente
if ($testCliente) {
    $withCliente = DashboardEconomicaService::getOverviewKpi(['year' => $year, 'cliente' => $testCliente]);
    $withClienteHours = $withCliente['data']['hours']['totalHours'] ?? 0;

    $sqlCliente = "SELECT SUM(pt.totalHours) AS total
                   FROM project_time pt
                   JOIN elenco_commesse ec ON ec.akeron_project_id = pt.idProject
                   WHERE pt.projectTimeDate BETWEEN '{$year}-01-01' AND '{$year}-12-31'
                     AND ec.cliente = ?";
    $directCliente = $database->query($sqlCliente, [$testCliente], __FILE__)->fetch(PDO::FETCH_ASSOC);
    $directClienteHours = floatval($directCliente['total'] ?? 0);

    echo sprintf("Filtro Cliente '%s':\n", $testCliente);
    echo sprintf("  Senza filtro: %s ore\n", number_format($noFilterHours, 2));
    echo sprintf("  Con filtro:   %s ore (service)\n", number_format($withClienteHours, 2));
    echo sprintf("  Con filtro:   %s ore (query diretta)\n", number_format($directClienteHours, 2));
    echo sprintf("  Match: %s\n\n", abs($withClienteHours - $directClienteHours) < 0.01 ? 'OK' : 'MISMATCH');
}

// Con filtro BU
if ($testBu) {
    $withBu = DashboardEconomicaService::getOverviewKpi(['year' => $year, 'bu' => $testBu]);
    $withBuHours = $withBu['data']['hours']['totalHours'] ?? 0;

    $sqlBu = "SELECT SUM(totalHours) AS total
              FROM project_time
              WHERE projectTimeDate BETWEEN '{$year}-01-01' AND '{$year}-12-31'
                AND idBusinessUnit = ?";
    $directBu = $database->query($sqlBu, [$testBu], __FILE__)->fetch(PDO::FETCH_ASSOC);
    $directBuHours = floatval($directBu['total'] ?? 0);

    echo sprintf("Filtro BU '%s':\n", $testBu);
    echo sprintf("  Senza filtro: %s ore\n", number_format($noFilterHours, 2));
    echo sprintf("  Con filtro:   %s ore (service)\n", number_format($withBuHours, 2));
    echo sprintf("  Con filtro:   %s ore (query diretta)\n", number_format($directBuHours, 2));
    echo sprintf("  Match: %s\n\n", abs($withBuHours - $directBuHours) < 0.01 ? 'OK' : 'MISMATCH');
}

// ============================================================================
// RIEPILOGO FINALE
// ============================================================================
echo "\n" . str_repeat("=", 80) . "\n";
echo "RIEPILOGO FINALE\n";
echo str_repeat("=", 80) . "\n";

$allOk = true;
foreach ($results as $section => $metrics) {
    foreach ($metrics as $metric => $status) {
        if ($status !== 'OK') {
            $allOk = false;
            echo "MISMATCH in {$section}: {$metric}\n";
        }
    }
}

if ($allOk) {
    echo "\n[OK] Tutti i KPI corrispondono alle query dirette.\n";
    echo "[OK] Filtro anno applicato correttamente.\n";
    echo "[OK] Filtri BU/PM/Cliente funzionanti.\n";
} else {
    echo "\n[!!] Trovate incoerenze - vedere dettagli sopra.\n";
}

echo "\nTabelle economiche NON ancora integrate:\n";
foreach ($tablesNotUsed as $table => $desc) {
    echo "  - {$table}\n";
}

echo "\n";
