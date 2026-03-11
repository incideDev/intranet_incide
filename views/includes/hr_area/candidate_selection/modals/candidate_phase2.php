<span class="close" onclick="closeModal()">&times;</span>
<h2>Dettagli Candidato - Fase 2: In Valutazione</h2>

<!-- Visualizza i dettagli di base del candidato come sola lettura -->
<div class="candidate-details">
    <p><strong>Nome:</strong> <?php echo htmlspecialchars($candidate['name'] ?? ''); ?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($candidate['email'] ?? ''); ?></p>
    <p><strong>Posizione Desiderata:</strong> <?php echo htmlspecialchars($candidate['position_applied'] ?? ''); ?></p>
</div>

<!-- Campo di valutazione -->
<div class="candidate-evaluation">
    <label for="evaluation">Valutazione:</label>
    <textarea id="evaluation" rows="4" required><?php echo htmlspecialchars($candidate['evaluation'] ?? ''); ?></textarea>
</div>

<!-- Opzioni per avanzare o scartare -->
<div class="candidate-action">
    <p><strong>Azioni:</strong></p>
    <label>
        <input type="radio" name="stage_action" value="advance" checked> Avanza allo stage successivo
    </label>
    <label>
        <input type="radio" name="stage_action" value="discard"> Scarta il candidato
    </label>
</div>

<!-- Pulsante per salvare i dati -->
<button onclick="savePhaseData(event, <?php echo $candidate['id']; ?>, 2)">Salva e procedi</button>
