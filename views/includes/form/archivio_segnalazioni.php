<?php
if (!defined('HostDbDataConnector')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    exit('Unauthorized');
}

if (!checkPermissionOrWarn('view_segnalazioni') && !checkPermissionOrWarn('view_moduli')) return;

// Sanifica GET
$isKanban = (isset($_GET['kanban']) && $_GET['kanban'] === 'true');

// Stati usati anche in JS
$statiMap = [1 => 'Aperta', 2 => 'In corso', 3 => 'Sospesa', 4 => 'Rifiutata', 5 => 'Chiusa'];
$stateColors = [];
try {
    $formName = $_GET['form_name'] ?? null;
    if ($formName) {
        $statesResp = \Services\PageEditorService::getFormStates($formName);
        if (!empty($statesResp['success']) && !empty($statesResp['states']) && is_array($statesResp['states'])) {
            $statiMap = [];
            $i = 1;
            foreach ($statesResp['states'] as $s) {
                $label = is_array($s) ? ($s['name'] ?? '') : (string)$s;
                if ($label === '') continue;
                $statiMap[$i] = $label;
                if (is_array($s) && !empty($s['color'])) $stateColors[$i] = $s['color'];
                $i++;
            }
            if (empty($statiMap)) $statiMap = [1 => 'Aperta', 2 => 'In corso', 3 => 'Chiusa'];
        }
    }
} catch (\Throwable $e) {}
?>

<div class="main-container page-archivio-segnalazioni">
    <?php renderPageTitle('Archivio Segnalazioni', '#C0392B');?>

    <!-- VISTA ELENCO -->
    <div id="elenco-view" class="<?= $isKanban ? 'hidden' : '' ?>">
        <table class="table table-filterable" id="segnalazioniTable">
            <thead>
                <tr>
                    <th class="azioni-colonna">Azioni</th>
                    <th class="protocollo-colonna">Protocollo</th>
                    <th class="segnalazione-colonna">Segnalazione</th>
                    <th class="oggetto-colonna">Oggetto</th>
                    <th class="utente-colonna">Creato da</th>
                    <th class="utente-colonna">Assegnato a</th>
                    <th class="utente-colonna">Responsabile</th>
                    <th class="data-colonna">Data Invio</th>
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

    <!-- VISTA KANBAN -->
    <div id="kanban-view" class="<?= $isKanban ? '' : 'hidden' ?>">
        <?php
        // Prepara parametri per il kanban template unificato
        $statimap = $statiMap;
        $tasks = []; // Le task saranno popolate via JS
        $tabella = 'archivio_segnalazioni';
        $kanbanType = 'segnalazioni';
        $showAddButton = false; // Non mostriamo il pulsante aggiungi nell'archivio
        $dataAttributes = ['tipo' => 'segnalazione'];
        
        // Include il template unificato
        include __DIR__ . '/../../components/kanban_template.php';
        ?>
    </div>
</div>

<script src="/assets/js/modules/kanban_renderer.js"></script>
<script>
    window.STATI_MAP = <?= json_encode($statiMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?>;
    window.STATI_MAP_REVERSE = Object.fromEntries(Object.entries(window.STATI_MAP).map(([k, v]) => [v, parseInt(k)]));
</script>
<!-- archivio_segnalazioni.js viene caricato automaticamente da main.php -->

