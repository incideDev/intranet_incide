<h2>Dettagli Candidato - Fase 6: Integrazione e Onboarding</h2>

<!-- Informazioni di base del candidato -->
<div class="candidate-details">
    <p><strong>Nome:</strong> <?php echo htmlspecialchars($candidate['name'] ?? 'N/A'); ?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($candidate['email'] ?? 'N/A'); ?></p>
    <p><strong>Posizione Desiderata:</strong> <?php echo htmlspecialchars($candidate['position_applied'] ?? 'N/A'); ?></p>
</div>

<!-- Informazioni raccolte nelle fasi precedenti -->
<div class="candidate-history">
    <h3>Storico delle Valutazioni</h3>
    <p><strong>Score Iniziale:</strong> <?php echo htmlspecialchars($candidate['score'] ?? 'N/A'); ?></p>
    <p><strong>Feedback Iniziale:</strong> <?php echo htmlspecialchars($candidate['feedback'] ?? 'N/A'); ?></p>

    <p><strong>Valutazione Fase 2:</strong> <?php echo htmlspecialchars($candidate['evaluation'] ?? 'N/A'); ?></p>
    <p><strong>Feedback Primo Colloquio:</strong> <?php echo htmlspecialchars($candidate['first_interview'] ?? 'N/A'); ?></p>
    <p><strong>Feedback Secondo Colloquio:</strong> <?php echo htmlspecialchars($candidate['second_interview'] ?? 'N/A'); ?></p>

    <h3>Proposta di Assunzione</h3>
    <p><strong>Decisione Finale:</strong> <?php echo htmlspecialchars($candidate['final_decision'] ?? 'N/A'); ?></p>
    <p><strong>Score Finale:</strong> <?php echo htmlspecialchars($candidate['final_score'] ?? 'N/A'); ?></p>
    <p><strong>Note Proposta:</strong> <?php echo htmlspecialchars($candidate['notes'] ?? 'N/A'); ?></p>
    <p><strong>Stipendio Netto Proposto:</strong> €<?php echo htmlspecialchars($candidate['proposed_salary_net'] ?? 'N/A'); ?></p>
    <p><strong>Stipendio Lordo Proposto:</strong> €<?php echo htmlspecialchars($candidate['proposed_salary_gross'] ?? 'N/A'); ?></p>
    <p><strong>Data di Inizio:</strong> <?php echo htmlspecialchars($candidate['start_date'] ?? 'N/A'); ?></p>
</div>

<!-- Documentazione e checklist di Onboarding -->
<div class="candidate-onboarding">
    <h3>Documentazione e Checklist Onboarding</h3>
    <form id="onboardingForm">
        <label for="onboardingDocs">Documentazione Completa:</label>
        <select id="onboardingDocs" name="onboardingDocs" required>
            <option value="Completato" <?php echo (isset($candidate['onboarding_docs']) && $candidate['onboarding_docs'] === 'Completato') ? 'selected' : ''; ?>>Completato</option>
            <option value="Incompleto" <?php echo (isset($candidate['onboarding_docs']) && $candidate['onboarding_docs'] === 'Incompleto') ? 'selected' : ''; ?>>Incompleto</option>
        </select>

        <label for="onboardingNotes">Note Onboarding:</label>
        <textarea name="onboardingNotes" id="onboardingNotes" rows="4"><?php echo htmlspecialchars($candidate['onboarding_notes'] ?? ''); ?></textarea>

        <button type="button" onclick="savePhaseData(event, <?php echo $candidate['id']; ?>, 6)">Salva Stato Onboarding</button>
    </form>
</div>
