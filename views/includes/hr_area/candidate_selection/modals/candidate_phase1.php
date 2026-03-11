<h2>Dettagli Candidato - Fase 1: Raccolta delle Candidature</h2>

<?php if (isset($candidate) && !empty($candidate)): ?>
    <div class="candidate-details">
        <p><strong>Nome:</strong> <?= htmlspecialchars($candidate['name'] ?? 'Non disponibile'); ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($candidate['email'] ?? 'Non disponibile'); ?></p>
        <p><strong>Posizione Desiderata:</strong> <?= htmlspecialchars($candidate['position_applied'] ?? 'Non disponibile'); ?></p>
        <p><strong>Campagna:</strong> <?= htmlspecialchars($candidate['campaign_title'] ?? 'Non disponibile'); ?></p>
    </div>

    <!-- CV e Lettera di presentazione -->
    <div class="candidate-documents">
        <h3>Documenti</h3>
        <?php if (!empty($candidate['cv'])): ?>
            <p><a href="uploads/cv/<?= htmlspecialchars($candidate['cv']); ?>" target="_blank">Visualizza CV</a></p>
        <?php else: ?>
            <p>CV non disponibile</p>
        <?php endif; ?>

        <?php if (!empty($candidate['cover_letter'])): ?>
            <p><a href="uploads/cover_letters/<?= htmlspecialchars($candidate['cover_letter']); ?>" target="_blank">Visualizza Lettera di Presentazione</a></p>
        <?php else: ?>
            <p>Lettera di presentazione non disponibile</p>
        <?php endif; ?>
    </div>

    <!-- Valutazione Iniziale -->
    <div class="candidate-evaluation">
        <h3>Valutazione Iniziale</h3>
        <form id="phaseForm" onsubmit="savePhaseData(event, <?= htmlspecialchars($candidate['id']); ?>, 1)">
            <label for="score">Score:</label>
            <input type="number" name="score" id="score" min="0" max="100" required 
                   value="<?= htmlspecialchars($candidate['score'] ?? ''); ?>">

            <label for="feedback">Feedback:</label>
            <textarea name="feedback" id="feedback" rows="4" required><?= htmlspecialchars($candidate['feedback'] ?? ''); ?></textarea>

            <button type="submit">Salva</button>
        </form>
    </div>
<?php else: ?>
    <p>Errore: Dettagli candidato mancanti o non disponibili.</p>
<?php endif; ?>
