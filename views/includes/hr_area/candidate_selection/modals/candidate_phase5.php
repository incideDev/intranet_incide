<h2>Dettagli Candidato - Fase 5: Proposta</h2>

<!-- Dettagli del candidato -->
<div class="candidate-details">
    <p><strong>Nome:</strong> <?php echo htmlspecialchars($candidate['name'] ?? ''); ?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($candidate['email'] ?? ''); ?></p>
    <p><strong>Posizione:</strong> <?php echo htmlspecialchars($candidate['position_applied'] ?? ''); ?></p>
    <p><strong>Feedback Secondo Colloquio:</strong> <?php echo htmlspecialchars($candidate['second_interview'] ?? ''); ?></p>
</div>

<!-- Proposta -->
<div class="candidate-proposal">
    <h3>Proposta</h3>
    <label for="notes">Note:</label>
    <textarea id="notes" rows="4"><?php echo htmlspecialchars($candidate['notes'] ?? ''); ?></textarea>

    <label for="proposedSalaryNet">Proposta Stipendio Netto (€):</label>
    <input type="number" id="proposedSalaryNet" step="0.01" placeholder="Inserisci lo stipendio netto" value="<?php echo htmlspecialchars($candidate['proposed_salary_net'] ?? ''); ?>" oninput="calculateGrossSalary()">

    <label for="proposedSalaryGross">Proposta Stipendio Lordo (€):</label>
    <input type="number" id="proposedSalaryGross" step="0.01" placeholder="Stipendio lordo calcolato" value="<?php echo htmlspecialchars($candidate['proposed_salary_gross'] ?? ''); ?>" readonly>

    <label for="startDate">Data di inizio:</label>
    <input type="date" id="startDate" value="<?php echo htmlspecialchars($candidate['start_date'] ?? ''); ?>">

    <label for="decision">Esito:</label>
    <select id="decision">
        <option value="accept">Accetta</option>
        <option value="reject">Rifiuta</option>
    </select>
</div>

<!-- Azioni -->
<div class="candidate-actions">
    <h3>Azioni</h3>
    <label>
        <input type="radio" name="stage_action" value="advance" checked> Avanza alla Fase 6
    </label>
    <label>
        <input type="radio" name="stage_action" value="discard"> Scarta il candidato
    </label>
</div>

<button onclick="savePhaseData(event, <?php echo $candidate['id']; ?>, 5)">Salva e procedi</button>
