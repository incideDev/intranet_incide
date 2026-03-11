<!--
⚠️ DEPRECATO: Questo file è stato sostituito dal template unificato.
    Usa invece: views/components/kanban_template.php
    
    Esempio di utilizzo:
    <?php
    $statimap = [1 => "Da fare", 2 => "In corso", 3 => "Fatto"];
    $tasks = [...]; // array delle tue task
    $tabella = 'nome_contesto';
    $kanbanType = 'generic';
    include __DIR__ . '/../../components/kanban_template.php';
    ?>
-->

<?php
// Compatibilità retroattiva: converti i vecchi parametri al nuovo formato
if (isset($statuses) && isset($items)) {
    $statimap = [];
    foreach ($statuses as $status) {
        $statimap[$status['id']] = $status['name'];
    }
    
    $tasks = [];
    foreach ($items as $item) {
        $tasks[] = [
            'id' => $item['id'],
            'titolo' => $item['titolo'] ?? '',
            'status_id' => $item['status_id'],
            'descrizione' => $item['descrizione'] ?? '',
            'submitted_by' => 0
        ];
    }
    
    $tabella = 'generic_board';
    $kanbanType = 'generic';
    
    // Include il template unificato
    include __DIR__ . '/../../components/kanban_template.php';
} else {
    echo "<div class='error'>⚠️ Parametri mancanti. Usa il nuovo formato con kanban_template.php</div>";
}
?>
