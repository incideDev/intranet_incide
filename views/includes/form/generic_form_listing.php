<?php
/**
 * TEMPLATE GENERICO PER LISTING/KANBAN DI TUTTE LE PAGINE CREATE DA PAGE_EDITOR
 * 
 * Questo template sostituisce la necessità di creare file PHP specifici per ogni form.
 * Supporta:
 * - Vista tabella
 * - Vista kanban con subtasks
 * - Vista calendario
 * - Vista gantt
 * 
 * Parametri GET richiesti:
 * - form_name: nome del form da visualizzare
 * 
 * Parametri GET opzionali:
 * - kanban=true: mostra vista kanban
 * - calendar=true: mostra vista calendario
 * - gantt=true: mostra vista gantt
 */

if (!defined('HostDbDataConnector')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    exit('Unauthorized');
}

// Rimosso controllo permessi generale - l'accesso è controllato da enforceFormVisibilityOrRedirect()

// Parametri
$formName = $_GET['form_name'] ?? null;
if (!$formName) {
    echo "<div class='error'>Nome form mancante</div>";
    return;
}

// Carica metadati del form
try {
    $formMeta = \Services\PageEditorService::getForm($formName);
    if (!$formMeta || !$formMeta['success']) {
        echo "<div class='error'>Form non trovato</div>";
        return;
    }
    $form = $formMeta['form'] ?? [];
    $formColor = $form['color'] ?? '#007bff';
    // Usa display_name se presente, altrimenti usa name o formName, normalizzando underscore con spazi
    $rawTitle = $form['display_name'] ?? $form['name'] ?? $formName;
    $formTitle = str_replace('_', ' ', $rawTitle);
    $formDescription = $form['description'] ?? '';
} catch (\Exception $e) {
    echo "<div class='error'>Errore caricamento form: " . htmlspecialchars($e->getMessage()) . "</div>";
    return;
}

// Controllo permessi specifico del form
enforceFormVisibilityOrRedirect();

// Viste
$isKanban   = (isset($_GET['kanban']) && $_GET['kanban'] === 'true');
$isCalendar = (isset($_GET['calendar']) && $_GET['calendar'] === 'true');
$isGantt    = (isset($_GET['gantt']) && $_GET['gantt'] === 'true');

// Stati dal DB (fallback ai default)
$statiMap = [1 => 'Aperta', 2 => 'In corso', 3 => 'Sospesa', 4 => 'Rifiutata', 5 => 'Chiusa'];
$stateColors = [];
try {
    $statesResp = \Services\PageEditorService::getFormStates($formName);
    if (!empty($statesResp['success']) && !empty($statesResp['states']) && is_array($statesResp['states'])) {
        $statiMap = [];
        $i = 1;
        foreach ($statesResp['states'] as $s) {
            $label = is_array($s) ? ($s['name'] ?? '') : (string)$s;
            if ($label === '') continue;
            $statiMap[$i] = $label;
            // Colore associato (se presente)
            if (is_array($s) && !empty($s['color'])) {
                $stateColors[$i] = $s['color'];
            }
            $i++;
        }
        if (empty($statiMap)) $statiMap = [1 => 'Aperta', 2 => 'In corso', 3 => 'Chiusa'];
    }
} catch (\Throwable $e) {
    // mantieni default silenziosamente
}

?>

<div class="main-container page-generic-form-listing" data-form-name="<?= htmlspecialchars($formName) ?>">
    <?php renderPageTitle($formTitle, $formColor); ?>
    
    <?php if ($formDescription): ?>
        <p class="form-description" style="margin: 10px 0; color: #666;">
            <?= htmlspecialchars($formDescription) ?>
        </p>
    <?php endif; ?>

    <!-- FILTRI RAPIDI TEMPORALI -->
    <div id="time-filters" style="margin: 15px 0 10px 0;">
        <button class="btn btn-filter" data-days="0">Oggi</button>
        <button class="btn btn-filter" data-days="3">Ultimi 3 giorni</button>
        <button class="btn btn-filter" data-days="7">Ultimi 7 giorni</button>
        <button class="btn btn-filter" data-days="30">Ultimi 30 giorni</button>
        <button class="btn btn-filter" data-days="all">Tutte</button>
    </div>

    <!-- VISTA ELENCO (TABELLA) -->
    <div id="elenco-view" class="<?= ($isKanban || $isCalendar || $isGantt) ? 'hidden' : '' ?>">
        <table class="table table-filterable" id="genericFormTable">
            <thead>
                <tr>
                    <th class="azioni-colonna">Azioni</th>
                    <th class="protocollo-colonna">Protocollo</th>
                    <th class="segnalazione-colonna">Titolo</th>
                    <th class="oggetto-colonna">Descrizione</th>
                    <th class="utente-colonna">Creato da</th>
                    <th class="utente-colonna">Assegnato a</th>
                    <th class="utente-colonna">Responsabile</th>
                    <th class="data-colonna">Data Invio</th>
                    <th class="data-colonna">Data Scadenza</th>
                    <th class="stato-colonna">Stato</th>
                    <th class="priorita-colonna">Priorità</th>
                </tr>
            </thead>
            <tbody id="forms-list">
                <!-- Popolamento JS -->
            </tbody>
        </table>
    </div>

    <!-- VISTA KANBAN -->
    <div id="kanban-view" class="<?= ($isKanban && !$isCalendar && !$isGantt) ? '' : 'hidden' ?>">
        <?php
        // Prepara parametri per il kanban template unificato
        $statimap = $statiMap;
        $tasks = []; // Popolate via JS
        $tabella = $formName;
        $kanbanType = 'generic';
        $showAddButton = false;
        $dataAttributes = ['form-name' => $formName];
        
        // Include il template unificato
        include __DIR__ . '/../../components/kanban_template.php';
        ?>
    </div>

    <!-- VISTA CALENDARIO -->
    <div id="calendar-view" class="<?= $isCalendar ? '' : 'hidden' ?>">
        <!-- Il renderer JS popolerà -->
    </div>

    <!-- VISTA GANTT -->
    <div id="gantt-view" class="<?= $isGantt ? '' : 'hidden' ?>">
        <!-- Il renderer JS popolerà -->
    </div>
</div>

<script>
  window.__FORM_LISTING_BOOTSTRAP__ = {
    STATI_MAP: <?= json_encode($statiMap, JSON_UNESCAPED_UNICODE) ?>,
    isKanban:   <?= $isKanban   ? 'true' : 'false' ?>,
    isCalendar: <?= $isCalendar ? 'true' : 'false' ?>,
    isGantt:    <?= $isGantt    ? 'true' : 'false' ?>,
    formName:   <?= json_encode($formName) ?>,
    formColor:  <?= json_encode($formColor) ?>
  };
</script>

<!-- moduli condivisi -->
<script src="/assets/js/modules/kanban_renderer.js"></script>
<script src="/assets/js/modules/calendar_view.js" defer></script>
<script src="/assets/js/modules/gantt_view.js" defer></script>
<script src="/assets/js/generic_form_listing.js"></script>

