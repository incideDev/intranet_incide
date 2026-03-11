<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

if (!checkPermissionOrWarn('view_contatti')) return;
?>

<div class="main-container">
    <h1>Selezione Personale</h1>

    <?php
    // Prepara i dati per il kanban template unificato
    if (!empty($selectionStages)):
        // Converti le fasi di selezione in statimap
        $statimap = [];
        foreach ($selectionStages as $stage) {
            $statimap[$stage['id']] = $stage['name'];
        }
        
        // Converti i candidati in task
        $tasks = [];
        if (!empty($candidates)) {
            foreach ($candidates as $candidate) {
                $tasks[] = [
                    'id' => $candidate['id'],
                    'titolo' => $candidate['name'],
                    'descrizione' => $candidate['position_applied'] ?? '',
                    'status_id' => $candidate['stage_id'],
                    'submitted_by' => 0
                ];
            }
        }
        
        $tabella = 'hr_candidates';
        $kanbanType = 'hr';
        $showAddButton = false; // I candidati si aggiungono altrove
        $dataAttributes = [];
        
        // Funzione custom per renderizzare i candidati
        $renderTaskHtml = function($task) {
            return '<div class="candidate-info">
                        <h3>' . htmlspecialchars($task['titolo']) . '</h3>
                        <p>' . htmlspecialchars($task['descrizione']) . '</p>
                    </div>';
        };
        
        // Include il template unificato
        include __DIR__ . '/../../components/kanban_template.php';
    else:
        echo '<p>Nessuna fase trovata.</p>';
    endif;
    ?>
</div>
<script src="assets/js/candidate_selection.js"></script>