<h2>Dettagli Candidato - Fase 3: Primo Colloquio</h2>

<!-- Dettagli del candidato -->
<div class="candidate-details">
    <p><strong>Nome:</strong> <?php echo htmlspecialchars($candidate['name'] ?? ''); ?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($candidate['email'] ?? ''); ?></p>
    <p><strong>Posizione:</strong> <?php echo htmlspecialchars($candidate['position_applied'] ?? ''); ?></p>
    <!-- Mostra dati delle fasi precedenti -->
    <p><strong>Valutazione:</strong> <?php echo htmlspecialchars($candidate['evaluation'] ?? ''); ?></p>
</div>

<!-- Feedback del primo colloquio -->
<div class="candidate-feedback">
    <h3>Feedback Primo Colloquio</h3>
    <textarea id="firstInterview" rows="4" required><?php echo htmlspecialchars($candidate['first_interview'] ?? ''); ?></textarea>
</div>

<!-- Azioni -->
<div class="candidate-actions">
    <h3>Azioni</h3>
    <label>
        <input type="radio" name="stage_action" value="advance" checked> Avanza alla Fase 4
    </label>
    <label>
        <input type="radio" name="stage_action" value="discard"> Scarta il candidato
    </label>
</div>

<button onclick="savePhaseData(event, <?php echo $candidate['id']; ?>, 3)">Salva e procedi</button>
