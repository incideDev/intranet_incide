<div class="pipeline-container">
    <?php if (!empty($selectionStages) && is_array($selectionStages)): ?>
        <div class="pipeline">
            <?php foreach ($selectionStages as $index => $stage): ?>
                <div class="pipeline-phase" data-stage-id="<?php echo htmlspecialchars($stage['id']); ?>">
                    <div class="phase-header" style="background-color: <?php echo htmlspecialchars($stage['color']); ?>;">
                        <h3><?php echo ($index + 1) . ". " . htmlspecialchars($stage['name']); ?></h3>
                    </div>
                    <div class="candidates-list">
                        <?php
                        // Filtra i candidati per lo stage corrente
                        $candidatesForStage = array_filter($candidates ?? [], function ($candidate) use ($stage) {
                            return isset($candidate['stage_id']) && $candidate['stage_id'] == $stage['id'];
                        });
                        ?>
                        <?php if (!empty($candidatesForStage)): ?>
                            <?php foreach ($candidatesForStage as $candidate): ?>
                                <div class="candidate-card" id="candidate-<?php echo htmlspecialchars($candidate['id']); ?>" draggable="true">
                                    <div class="candidate-info">
                                        <h4><?php echo htmlspecialchars($candidate['name']); ?></h4>
                                        <p>Posizione: <?php echo htmlspecialchars($candidate['position_applied']); ?></p>
                                    </div>
                                    <?php if ($index < count($selectionStages) - 1): ?>
                                        <!-- Bottone per avanzare alla fase successiva -->
                                        <button onclick="moveToNextStage(<?php echo $candidate['id']; ?>, <?php echo $selectionStages[$index + 1]['id']; ?>)">Avanza</button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="no-candidates-message">Nessun candidato in questa fase.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>No selection stages found.</p>
    <?php endif; ?>
</div>


<script>
function moveToNextStage(candidateId, nextStageId) {
    const formData = new FormData();
    formData.append('candidate_id', candidateId);
    formData.append('new_stage_id', nextStageId);

    fetch('index.php?page=hr_area&section=candidate_selection&candidate_action=update_stage', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Stage aggiornato con successo');
            // Ricarica la vista per aggiornare la posizione del candidato
            loadView('pipeline');
        } else {
            console.error('Errore nell\'aggiornamento dello stage');
        }
    })
    .catch(error => {
        console.error('Errore nella richiesta AJAX', error);
    });
}
</script>
