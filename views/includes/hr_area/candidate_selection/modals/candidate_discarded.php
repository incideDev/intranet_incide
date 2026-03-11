<h2>Dettagli Candidato Scartato</h2>

<!-- Dettagli del candidato -->
<div class="candidate-details">
    <p><strong>Nome:</strong> <?php echo htmlspecialchars($candidate['name'] ?? ''); ?></p>
    <p><strong>Email:</strong> <?php echo htmlspecialchars($candidate['email'] ?? ''); ?></p>
    <p><strong>Posizione:</strong> <?php echo htmlspecialchars($candidate['position_applied'] ?? ''); ?></p>
</div>

<!-- Record delle varie fasi -->
<div class="candidate-history">
    <h3>Dettagli Raccoglti Durante le Fasi</h3>
    <p><strong>Score Iniziale:</strong> <?php echo htmlspecialchars($candidate['score'] ?? 'N/A'); ?></p>
    <p><strong>Feedback Fase 1:</strong> <?php echo htmlspecialchars($candidate['feedback'] ?? 'N/A'); ?></p>
    <p><strong>Valutazione Fase 2:</strong> <?php echo htmlspecialchars($candidate['evaluation'] ?? 'N/A'); ?></p>
    <p><strong>Feedback Primo Colloquio:</strong> <?php echo htmlspecialchars($candidate['first_interview'] ?? 'N/A'); ?></p>
    <p><strong>Feedback Secondo Colloquio:</strong> <?php echo htmlspecialchars($candidate['second_interview'] ?? 'N/A'); ?></p>
    <p><strong>Note Proposta:</strong> <?php echo htmlspecialchars($candidate['notes'] ?? 'N/A'); ?></p>
    <p><strong>Proposta Stipendio Netto:</strong> €<?php echo htmlspecialchars($candidate['proposed_salary_net'] ?? 'N/A'); ?></p>
    <p><strong>Proposta Stipendio Lordo:</strong> €<?php echo htmlspecialchars($candidate['proposed_salary_gross'] ?? 'N/A'); ?></p>
    <p><strong>Data di Inizio Proposta:</strong> <?php echo htmlspecialchars($candidate['start_date'] ?? 'N/A'); ?></p>
</div>
