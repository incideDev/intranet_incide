<?php
$view = 'kanban';
?>

    <div class="view-selector">
        <div class="view-tabs">
            <button class="view-tab active" data-view="kanban" onclick="loadView('kanban')">Kanban</button>
            <button class="view-tab" data-view="pipeline" onclick="loadView('pipeline')">Pipeline</button>
        </div>
    </div>
    <div id="view-container">
        <?php include 'candidate_selection_kanban.php'; ?>
    </div>
    
<script src="assets/js/candidate_selection.js"></script>
