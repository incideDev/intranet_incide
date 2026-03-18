<?php
/**
 * TAB TASK - Overview (NON include il kanban completo)
 * Mostra max 6 task recenti + CTA al kanban operativo
 */
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}

// Link CSS overview
echo '<link rel="stylesheet" href="/assets/css/commesse_detail_overview.css">';

// Recupera task dal DB (max 6 per overview)
global $database;
use Services\TaskService;

$tasks = [];
$nomeFisicoTabella = 'com_' . strtolower(preg_replace('/[^a-z0-9_]/', '_', $tabella));

// Usa TaskService se disponibile
if (class_exists('Services\TaskService')) {
    $context = [
        'contextType' => 'commessa',
        'contextId' => $tabella
    ];
    $boardResult = TaskService::loadBoard($context);
    if ($boardResult['success']) {
        $allTasks = $boardResult['tasks'] ?? [];
        // Prendi max 6 task più recenti (ordina per data_creazione desc)
        usort($allTasks, function($a, $b) {
            return strtotime($b['data_creazione'] ?? '1970-01-01') - strtotime($a['data_creazione'] ?? '1970-01-01');
        });
        $tasks = array_slice($allTasks, 0, 6);
    }
}

// Mappa stati
$statiMap = [
    1 => 'Da Definire',
    2 => 'Aperto',
    3 => 'In Corso',
    4 => 'Chiuso'
];

// Helper badge priorità
function getPriorityBadgeClass($priority) {
    return match(strtolower($priority ?? '')) {
        'alta' => 'danger',
        'media' => 'warning',
        'bassa' => 'success',
        default => 'secondary'
    };
}
?>

<div class="commessa-card">
    <div class="commessa-card-header">
        <div>
            <div class="commessa-card-title">Task Recenti</div>
            <div class="commessa-card-subtitle"><?= count($tasks) ?> task visualizzate</div>
        </div>
        <a href="index.php?section=commesse&page=commessa_kanban&tabella=<?= urlencode($tabella) ?>"
           class="commessa-cta">
            <img src="/assets/icons/kanban.png" alt="" class="commessa-cta-icon">
            Apri Kanban
        </a>
    </div>

    <?php if (!empty($tasks)): ?>
        <!-- Grid task cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px;">
            <?php foreach ($tasks as $task): ?>
                <?php
                $taskId = $task['id'] ?? 0;
                $titolo = $task['titolo'] ?? 'Senza titolo';
                $stato = $statiMap[$task['stato'] ?? $task['status_id'] ?? 1] ?? 'N/D';
                $priority = $task['priority'] ?? 'Media';
                $scadenza = !empty($task['data_scadenza']) ? date('d/m/Y', strtotime($task['data_scadenza'])) : null;
                $isCompleted = ($task['stato'] ?? $task['status_id'] ?? 1) == 4;
                ?>
                <div class="commessa-task-card">
                    <div class="commessa-task-card-header">
                        <div class="commessa-task-title"><?= htmlspecialchars($titolo) ?></div>
                        <input type="checkbox"
                               class="commessa-task-checkbox"
                               <?= $isCompleted ? 'checked' : '' ?>
                               disabled
                               data-tooltip="Solo visualizzazione">
                    </div>

                    <div style="display: flex; gap: 6px; margin-bottom: 8px;">
                        <span class="commessa-badge <?= getPriorityBadgeClass($priority) ?>">
                            <?= htmlspecialchars($priority) ?>
                        </span>
                        <span class="commessa-badge info">
                            <?= htmlspecialchars($stato) ?>
                        </span>
                    </div>

                    <?php if ($scadenza): ?>
                        <div class="commessa-task-meta">
                            <img src="/assets/icons/calendar.png" alt="">
                            <span>Scadenza: <?= htmlspecialchars($scadenza) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($task['assegnato_a_nome'])): ?>
                        <div class="commessa-task-meta">
                            <?php if (!empty($task['img_assegnato'])): ?>
                                <img src="<?= htmlspecialchars($task['img_assegnato']) ?>"
                                     alt=""
                                     style="width: 18px; height: 18px; border-radius: 50%;">
                            <?php endif; ?>
                            <span><?= htmlspecialchars($task['assegnato_a_nome']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Footer con link -->
        <div style="text-align: center; padding-top: 16px; border-top: 1px solid #e9ecef;">
            <a href="index.php?section=commesse&page=commessa_kanban&tabella=<?= urlencode($tabella) ?>"
               class="commessa-cta secondary">
                Vedi tutte le task
            </a>
        </div>

    <?php else: ?>
        <!-- Empty state -->
        <div class="commessa-empty">
            <h3>Nessuna task presente</h3>
            <p>Inizia creando la prima task per questa commessa.</p>
            <a href="index.php?section=commesse&page=commessa_kanban&tabella=<?= urlencode($tabella) ?>"
               class="commessa-cta">
                <img src="/assets/icons/plus.png" alt="" class="commessa-cta-icon">
                Crea prima task
            </a>
        </div>
    <?php endif; ?>
</div>
