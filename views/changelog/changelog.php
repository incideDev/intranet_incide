<?php
if ($Session->logged_in !== true) {
    header("Location: /index.php");
    exit;
}

$aggiornamenti = $database->query("SELECT * FROM changelog ORDER BY data DESC", [], __FILE__)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="main-container">
    <?php renderPageTitle("Novità e Miglioramenti", "#3498db"); ?>

    <?php if (count($aggiornamenti) === 0): ?>
        <div class="no-results">
            <p>Nessun aggiornamento disponibile al momento.</p>
        </div>
    <?php else: ?>
        <div class="notifiche-lista">
            <div class="changelog-timeline">
                <?php foreach ($aggiornamenti as $row): ?>
                    <div class="timeline-item">
                        <div class="timeline-marker"></div>
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <span class="timeline-date"><?= date("d/m/Y", strtotime($row['data'])) ?></span>
                                <?php if (!empty($row['sezione'])): ?>
                                    <span class="timeline-section"><?= htmlspecialchars($row['sezione']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="timeline-title"><?= htmlspecialchars($row['titolo']) ?></div>
                            <div class="timeline-description"><?= nl2br(htmlspecialchars($row['descrizione'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
