<h2>Dettagli Candidato - Fase 4: Secondo Colloquio</h2>

<!-- Dettagli del candidato -->
<div class="candidate-details">
    <p><strong>Nome:</strong> <?php echo htmlspecialchars($candidate['name'] ?? ''); ?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($candidate['email'] ?? ''); ?></p>
    <p><strong>Posizione:</strong> <?php echo htmlspecialchars($candidate['position_applied'] ?? ''); ?></p>
    <p><strong>Feedback Primo Colloquio:</strong> <?php echo htmlspecialchars($candidate['first_interview'] ?? ''); ?></p>
</div>

<!-- Feedback del secondo colloquio -->
<div class="candidate-feedback">
    <h3>Feedback Secondo Colloquio</h3>
    <textarea id="secondInterview" rows="4" required><?php echo htmlspecialchars($candidate['second_interview'] ?? ''); ?></textarea>
</div>

<!-- Azioni -->
<div class="candidate-actions">
    <h3>Azioni</h3>
    <label>
        <input type="radio" name="stage_action" value="advance" checked> Avanza alla Fase 5
    </label>
    <label>
        <input type="radio" name="stage_action" value="discard"> Scarta il candidato
    </label>
</div>

<button onclick="savePhaseData(event, <?php echo $candidate['id']; ?>, 4)">Salva e procedi</button>
