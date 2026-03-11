<?php
if (!defined('HostDbDataConnector')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    exit('Unauthorized');
}

if (!checkPermissionOrWarn('view_segnalazioni'))
    return;
enforceFormVisibilityOrRedirect();

$formName = $_GET['form_name'] ?? null;
$pageTitle = $formName ? htmlspecialchars(ucwords(str_replace('_', ' ', $formName))) : "Gestione Segnalazioni";
$isKanban = (isset($_GET['kanban']) && $_GET['kanban'] === 'true');
$isCalendar = (isset($_GET['calendar']) && $_GET['calendar'] === 'true');
$isGantt = (isset($_GET['gantt']) && $_GET['gantt'] === 'true');

$statiMap = [1 => 'Aperta', 2 => 'In corso', 3 => 'Chiusa'];
$stateColors = [];
try {
    if ($formName) {
        $statesResp = \Services\PageEditorService::getFormStates($formName);
        if (!empty($statesResp['success']) && !empty($statesResp['states']) && is_array($statesResp['states'])) {
            $statiMap = [];
            $i = 1;
            foreach ($statesResp['states'] as $s) {
                $label = is_array($s) ? ($s['name'] ?? '') : (string) $s;
                if ($label === '')
                    continue;
                $statiMap[$i] = $label;
                if (is_array($s) && !empty($s['color']))
                    $stateColors[$i] = $s['color'];
                $i++;
            }
            if (empty($statiMap))
                $statiMap = [1 => 'Aperta', 2 => 'In corso', 3 => 'Chiusa'];
        }
    }
} catch (\Throwable $e) {
    // fallback ai default
}

?>

<div class="main-container page-gestione-segnalazioni">
    <?php renderPageTitle($pageTitle, '#C0392B'); ?>

    <!-- FILTRI DINAMICI (form_name, status_id, responsabile) -->
    <div id="filter-container" data-table="forms" data-filters='["form_name", "status_id", "responsabile"]'>
        <!-- Popolato da filters.js -->
    </div>

    <!-- FILTRI RAPIDI TEMPORALI -->
    <div id="time-filters" style="margin: 0px 0 20px 0;">
        <button class="btn btn-filter" data-days="0">Oggi</button>
        <button class="btn btn-filter" data-days="3">Ultimi 3 giorni</button>
        <button class="btn btn-filter" data-days="7">Ultimi 7 giorni</button>
        <button class="btn btn-filter" data-days="30">Ultimi 30 giorni</button>
        <button class="btn btn-filter active" data-days="all">Tutte</button>
    </div>

    <!-- VISTA ELENCO -->
    <div id="elenco-view" class="<?= ($isKanban || $isCalendar || $isGantt) ? 'hidden' : '' ?>">
        <table class="table table-filterable" id="segnalazioniTable">
            <thead>
                <tr>
                    <th class="azioni-colonna">Azioni</th>
                    <th class="protocollo-colonna">Protocollo</th>
                    <th class="oggetto-colonna">Oggetto</th>
                    <th class="utente-colonna">Creato da</th>
                    <th class="utente-colonna">Assegnato a</th>
                    <th class="utente-colonna">Responsabile</th>
                    <th class="data-colonna">Data di Apertura</th>
                    <th class="data-colonna">Data Scadenza</th>
                    <th class="stato-colonna">Stato</th>
                    <th class="priorita-colonna">Priorità</th>
                </tr>
            </thead>
            <tbody id="segnalazioni-list">
                <!-- Popolamento JS -->
            </tbody>
        </table>
    </div>

    <!--VISTA KANBAN -->
    <div id="kanban-view" class="<?= ($isKanban && !$isCalendar && !$isGantt) ? '' : 'hidden' ?>">
        <?php
        // Prepara parametri per il kanban template unificato
        $kanban_tasks = []; // Le task saranno popolate via JS
        $kanban_statimap = $statiMap;
        $kanban_tabella = 'segnalazioni';
        $kanban_type = 'segnalazioni';
        $kanban_showAddButton = false; // Non mostriamo il pulsante aggiungi perché le segnalazioni si creano altrove
        $kanban_dataAttributes = ['tipo' => 'segnalazione'];

        // Assegna le variabili con i nomi attesi dal template
        $statimap = $kanban_statimap;
        $tasks = $kanban_tasks;
        $tabella = $kanban_tabella;
        $kanbanType = $kanban_type;
        $showAddButton = $kanban_showAddButton;
        $dataAttributes = $kanban_dataAttributes;

        // Include il template unificato
        include __DIR__ . '/../../components/kanban_template.php';
        ?>
    </div>
    <!-- VISTA CALENDARIO -->
    <div id="calendar-view" class="<?= $isCalendar ? '' : 'hidden' ?>"><!-- il renderer JS popolerà --></div>
    <!-- VISTA GANTT -->
    <div id="gantt-view" class="<?= $isGantt ? '' : 'hidden' ?>"><!-- il renderer JS popolerà --></div>
</div>

<script>
    window.__SEGNALAZIONI_BOOTSTRAP__ = {
        STATI_MAP: <?= json_encode($statiMap, JSON_UNESCAPED_UNICODE) ?>,
        isKanban: <?= $isKanban ? 'true' : 'false' ?>,
        isCalendar: <?= $isCalendar ? 'true' : 'false' ?>,
        isGantt: <?= $isGantt ? 'true' : 'false' ?>,
        formName: <?= json_encode($formName) ?>
    };
</script>

<!-- moduli condivisi -->
<script src="/assets/js/modules/filters.js"></script>
<script src="/assets/js/modules/kanban_renderer.js"></script>
<script src="/assets/js/modules/calendar_view.js" defer></script>
<script src="/assets/js/modules/gantt_view.js" defer></script>
<!-- gestione_segnalazioni.js viene caricato automaticamente da main.php -->