<?php
/**
 * KANBAN TEMPLATE UNIVERSALE
 *
 * Template riutilizzabile per tutti i kanban del sito.
 *
 * PARAMETRI RICHIESTI:
 * - $statimap: array degli stati [id => label] (es: [1 => "Aperta", 2 => "In corso"])
 * - $stateColors (opzionale): array colori [id => "#RRGGBB"]
 * - $tasks: array delle task/item da visualizzare
 *
 * PARAMETRI OPZIONALI:
 * - $tabella: identificatore della tabella/contesto (default: 'default')
 * - $titolo: titolo del kanban (default: 'Bacheca')
 * - $renderTaskHtml: callback per renderizzare custom HTML delle task
 * - $showAddButton: mostra pulsante aggiungi task (default: true)
 * - $kanbanType: tipo di kanban per logiche specifiche (default: 'generic')
 * - $dataAttributes: array di attributi data-* aggiuntivi per il container
 */

if (!defined('HostDbDataConnector') && !defined('AccessoFileInterni')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

// Parametri con fallback
if (!isset($tabella)) {
    $tabella = $_GET['tabella'] ?? 'default';
}
if (!isset($titolo)) {
    $titolo = $_GET['titolo'] ?? 'Bacheca';
}
if (!isset($showAddButton)) {
    $showAddButton = true;
}
if (!isset($kanbanType)) {
    $kanbanType = 'generic'; // generic, commesse, segnalazioni, hr, etc.
}
if (!isset($dataAttributes)) {
    $dataAttributes = [];
}

// Se tasks non è definito, prova a recuperarli automaticamente usando TaskService
if (!isset($tasks)) {
    // Supporto nuovo sistema globale (entity_type/entity_id o contextType/contextId)
    $contextType = $entity_type ?? null;
    $contextId = $entity_id ?? null;

    if ($contextType && $contextId) {
        $context = [
            'contextType' => $contextType,
            'contextId' => $contextId
        ];
        $boardResult = Services\TaskService::loadBoard($context);
        if ($boardResult['success']) {
            $tasks = $boardResult['tasks'] ?? [];
            // Costruisci $statimap dagli stati restituiti se non è già definito
            if (isset($boardResult['statuses']) && !isset($statimap)) {
                $statimap = [];
                foreach ($boardResult['statuses'] as $status) {
                    $statimap[(int) $status['id']] = $status['name'];
                }
                // Costruisci anche $stateColors se disponibile
                if (!isset($stateColors)) {
                    $stateColors = [];
                    foreach ($boardResult['statuses'] as $status) {
                        if (!empty($status['color'])) {
                            $stateColors[(int) $status['id']] = $status['color'];
                        }
                    }
                }
            }
        } else {
            $tasks = [];
        }
    }
    // Compatibilità retroattiva: commesse (legacy)
    elseif ($kanbanType === 'commesse' && isset($tabella)) {
        $nomeFisicoTabella = 'com_' . strtolower(preg_replace('/[^a-z0-9_]/', '_', $tabella));
        // Usa TaskService se disponibile, altrimenti fallback a CommesseService
        if (class_exists('Services\TaskService')) {
            $context = [
                'contextType' => 'commessa',
                'contextId' => str_replace('com_', '', $nomeFisicoTabella)
            ];
            $boardResult = Services\TaskService::loadBoard($context);
            if ($boardResult['success']) {
                $tasks = $boardResult['tasks'] ?? [];
                // Costruisci $statimap dagli stati restituiti se non è già definito
                if (isset($boardResult['statuses']) && !isset($statimap)) {
                    $statimap = [];
                    foreach ($boardResult['statuses'] as $status) {
                        $statimap[(int) $status['id']] = $status['name'];
                    }
                    // Costruisci anche $stateColors se disponibile
                    if (!isset($stateColors)) {
                        $stateColors = [];
                        foreach ($boardResult['statuses'] as $status) {
                            if (!empty($status['color'])) {
                                $stateColors[(int) $status['id']] = $status['color'];
                            }
                        }
                    }
                }
            } else {
                $tasks = [];
            }
        } else {
            $tasks = Services\CommesseService::gettasks($nomeFisicoTabella);
        }
    } else {
        $tasks = [];
    }
}

// Verifica parametri obbligatori DOPO il recupero automatico
if (!isset($statimap) || !is_array($statimap) || empty($statimap)) {
    // Se non ci sono stati, crea stati di default per il contesto
    $contextTypeForStatus = isset($contextType) ? $contextType : (isset($entity_type) ? $entity_type : 'generic');

    // Stati di default generici (se non esistono stati specifici)
    $statimap = [
        1 => 'Aperta',
        2 => 'In corso',
        3 => 'In attesa',
        4 => 'Completata',
        5 => 'Chiusa'
    ];

    // Se abbiamo un contextType specifico, prova a creare stati di default nel DB (solo se non esistono)
    /*
    // Commentata logica di creazione automatica stati per evitare conflitti o errori di connessione.
    // Gli stati devono essere gestiti via DB administration o script dedicati.
    if ($contextTypeForStatus !== 'generic' && class_exists('Services\TaskService')) {
         // ...
    }
    */
}

// Raggruppa task per stato
$byStato = [];
foreach ($tasks as $task) {
    $stato = $task['status_id'] ?? $task['stato'] ?? array_key_first($statimap);
    if (!isset($byStato[$stato])) {
        $byStato[$stato] = [];
    }
    $byStato[$stato][] = $task;
}

// Prepara attributi data per il container
$dataAttrsHtml = '';
foreach ($dataAttributes as $key => $value) {
    $dataAttrsHtml .= ' data-' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
}

?>

<div class="kanban-container" data-tabella="<?= htmlspecialchars($tabella) ?>"
    data-kanban-type="<?= htmlspecialchars($kanbanType) ?>" <?= isset($entity_type) ? 'data-entity-type="' . htmlspecialchars($entity_type) . '"' : '' ?> <?= isset($entity_id) ? 'data-entity-id="' . htmlspecialchars($entity_id) . '"' : '' ?> <?= $dataAttrsHtml ?>>
    <?php foreach ($statimap as $id => $label):
        $classHeader = strtolower(str_replace(' ', '_', $label));
        $headerStyle = '';
        if (isset($stateColors) && is_array($stateColors)) {
            $col = $stateColors[$id] ?? null;
            if (is_string($col) && preg_match('/^#?[0-9A-Fa-f]{6}$/', $col)) {
                if ($col[0] !== '#')
                    $col = '#' . $col;
                $headerStyle = 'style="border-bottom: 3px solid ' . htmlspecialchars($col) . '"';
            }
        }
        ?>
        <div class="kanban-column" data-status="<?= $classHeader ?>" data-status-id="<?= $id ?>">
            <div class="kanban-header <?= $classHeader ?>" <?= $headerStyle ?>>
                <span class="kanban-title"><?= htmlspecialchars($label) ?></span>
                <?php if ($showAddButton): ?>
                    <button class="add-task-btn" onclick="window.openNewKanbanTask && window.openNewKanbanTask()">＋</button>
                <?php endif; ?>
            </div>
            <div class="task-container" id="kanban-<?= $classHeader ?>">
                <?php foreach ($byStato[$id] ?? [] as $task): ?>
                    <!-- MAIN TASK -->
                    <div class="task" id="task-<?= htmlspecialchars($tabella) ?>_<?= $task['id'] ?>"
                        data-task-id="<?= $task['id'] ?>" data-tabella="<?= htmlspecialchars($tabella) ?>"
                        data-creato-da="<?= (int) ($task['submitted_by'] ?? 0) ?>" draggable="true">
                        <?php
                        if (isset($renderTaskHtml) && is_callable($renderTaskHtml)) {
                            echo $renderTaskHtml($task);
                        } else {
                            // Mostra comunque più info anche nel fallback!
                            ?>
                            <div class="task-header" style="display:flex;justify-content:space-between;align-items:center;">
                                <div class="title-field text-truncate" style="flex:1 1 auto;">
                                    <strong><?= htmlspecialchars($task['titolo'] ?? '-') ?></strong>
                                </div>
                                <?php if (!empty($task['specializzazione'])): ?>
                                    <span class="kanban-discipline-badge"
                                        data-tooltip="Disciplina: <?= htmlspecialchars($task['specializzazione']) ?>"
                                        style="margin-left:12px;flex-shrink:0;">
                                        <?= htmlspecialchars($task['specializzazione']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="task-body">
                                <div class="priority-field" style="margin-bottom: 4px;">
                                    <img src="assets/icons/status.png" class="task-icon" alt="Priorità"
                                        data-tooltip="Priorità: <?= !empty($task['priority']) ? htmlspecialchars($task['priority']) : '-' ?>">
                                    <span><?= !empty($task['priority']) ? htmlspecialchars($task['priority']) : '-' ?></span>
                                </div>

                                <div class="date-field" style="display:flex;gap:10px;font-size:11px;margin-bottom:5px;">
                                    <?php if (!empty($task['data_apertura'])): ?>
                                        <span>
                                            <img src="assets/icons/calendar.png" class="task-icon"
                                                style="width:14px;vertical-align:-2px;" alt="Apertura" data-tooltip="Data apertura">
                                            <?= date('d/m/Y', strtotime($task['data_apertura'])) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($task['data_scadenza'])): ?>
                                        <span>
                                            <img src="assets/icons/calendar.png" class="task-icon"
                                                style="width:14px;vertical-align:-2px;" alt="Scadenza" data-tooltip="Data scadenza">
                                            <?= date('d/m/Y', strtotime($task['data_scadenza'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="kanban-icons-col"
                                    style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                                    <?php if (!empty($task['creatore_nome']) && $task['creatore_nome'] !== '—'): ?>
                                        <div class="kanban-icon-wrap" style="display: flex; align-items: center;">
                                            <img src="assets/icons/user.png" class="task-icon" alt="Creato da"
                                                style="margin-right: 4px;"
                                                data-tooltip="Creato da: <?= htmlspecialchars($task['creatore_nome']) ?>">
                                            <img src="<?= htmlspecialchars($task['img_creatore'] ?? 'assets/images/default_profile.png') ?>"
                                                alt="<?= htmlspecialchars($task['creatore_nome']) ?>" class="profile-icon"
                                                style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover; margin-right: 2px;"
                                                data-tooltip="<?= htmlspecialchars($task['creatore_nome']) ?>">
                                            <span style="font-size:11px;"><?= htmlspecialchars($task['creatore_nome']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($task['assegnato_a_nome']) && $task['assegnato_a_nome'] !== '—'): ?>
                                        <div class="kanban-icon-wrap" style="display: flex; align-items: center;">
                                            <img src="assets/icons/two_users.png" class="task-icon" alt="Assegnato a"
                                                style="margin-right: 4px;"
                                                data-tooltip="Assegnato a: <?= htmlspecialchars($task['assegnato_a_nome']) ?>">
                                            <img src="<?= htmlspecialchars($task['img_assegnato'] ?? 'assets/images/default_profile.png') ?>"
                                                alt="<?= htmlspecialchars($task['assegnato_a_nome']) ?>" class="profile-icon"
                                                style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover; margin-right: 2px;"
                                                data-tooltip="<?= htmlspecialchars($task['assegnato_a_nome']) ?>">
                                            <span style="font-size:11px;"><?= htmlspecialchars($task['assegnato_a_nome']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php
                        }
                        ?>

                        <!-- Toggle Subtasks Button -->
                        <?php if (isset($task['subtasks']) && !empty($task['subtasks'])): ?>
                            <div class="kanban-subtasks-toggle" data-parent-id="<?= $task['id'] ?>"
                                data-tooltip="Clicca per mostrare/nascondere le subtask">
                                <span class="subtasks-count">📋 <?= count($task['subtasks']) ?>
                                    scheda<?= count($task['subtasks']) > 1 ? '' : '' ?></span>
                                <span class="toggle-icon">▼</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- SUBTASKS - Fuori dalla task principale, indentate -->
                    <?php if (isset($task['subtasks']) && !empty($task['subtasks'])): ?>
                        <?php
                        // Colore della task principale per le subtasks (con trasparenza)
                        $taskColor = $task['color'] ?? '#007bff';

                        // Converti hex in rgba con trasparenza 0.4
                        $hexColor = str_replace('#', '', $taskColor);
                        $r = hexdec(substr($hexColor, 0, 2));
                        $g = hexdec(substr($hexColor, 2, 2));
                        $b = hexdec(substr($hexColor, 4, 2));
                        $taskColorRgba = "rgba($r, $g, $b, 0.4)";
                        ?>
                        <?php foreach ($task['subtasks'] as $subtask): ?>
                            <div class="kanban-subtask collapsed" data-subtask-id="<?= $subtask['id'] ?>"
                                data-parent-id="<?= $task['id'] ?>" data-tabella="<?= htmlspecialchars($tabella) ?>"
                                style="border-left-color: <?= $taskColorRgba ?>;">
                                <div class="kanban-subtask-header">
                                    <?php if (!empty($subtask['scheda_label'])): ?>
                                        <div class="kanban-subtask-label">
                                            📋 <?= htmlspecialchars($subtask['scheda_label']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <span class="kanban-subtask-toggle-icon">▶</span>

                                    <?php
                                    // Decodifica i campi della scheda
                                    $schedaData = null;
                                    if (!empty($subtask['scheda_data'])) {
                                        $schedaData = is_string($subtask['scheda_data'])
                                            ? json_decode($subtask['scheda_data'], true)
                                            : $subtask['scheda_data'];
                                    }
                                    ?>

                                    <?php if ($schedaData && is_array($schedaData) && !empty($schedaData)): ?>
                                        <div class="kanban-subtask-fields">
                                            <?php foreach ($schedaData as $fieldName => $fieldValue): ?>
                                                <?php if (!empty($fieldValue)): ?>
                                                    <div class="kanban-subtask-field">
                                                        <span class="kanban-subtask-field-name"><?= htmlspecialchars($fieldName) ?>:</span>
                                                        <span class="kanban-subtask-field-value">
                                                            <?php
                                                            if (is_array($fieldValue)) {
                                                                echo htmlspecialchars(implode(', ', $fieldValue));
                                                            } else {
                                                                echo htmlspecialchars($fieldValue);
                                                            }
                                                            ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="kanban-subtask-body">
                                    <?php if (!empty($subtask['assegnato_a_nome']) && $subtask['assegnato_a_nome'] !== '—'): ?>
                                        <div class="kanban-subtask-assigned">
                                            <img src="assets/icons/two_users.png" class="task-icon" alt="Assegnato a">
                                            <img src="<?= htmlspecialchars($subtask['img_assegnato'] ?? 'assets/images/default_profile.png') ?>"
                                                alt="<?= htmlspecialchars($subtask['assegnato_a_nome']) ?>" class="profile-icon">
                                            <span><?= htmlspecialchars($subtask['assegnato_a_nome']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
    window.STATI_MAP = <?= json_encode($statimap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?>;

    // Inizializza TaskBoard se disponibile (NUOVO SISTEMA UNIFICATO)
    <?php if (isset($entity_type) && isset($entity_id)): ?>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof window.TaskBoard !== 'undefined') {
                let container = document.querySelector('.kanban-container') || document.querySelector('#kanban-view');
                if (container) {
                    window.TaskBoard.mount({
                        container: container,
                        contextType: '<?= htmlspecialchars($entity_type) ?>',
                        contextId: '<?= htmlspecialchars($entity_id) ?>',
                        onTaskCreated: function (res) {
                            if (typeof showToast === 'function') {
                                showToast('Task creata', 'success');
                            }
                        },
                        onTaskMoved: function (taskId, oldStatus, newStatus, res) {
                            if (typeof showToast === 'function') {
                                showToast('Task spostata', 'success');
                            }
                        },
                        onTaskDeleted: function (taskId, res) {
                            if (typeof showToast === 'function') {
                                showToast('Task eliminata', 'success');
                            }
                        }
                    });
                }
            } else if (typeof window.TaskEngine !== 'undefined') {
                // Fallback: usa TaskEngine (compatibilità retroattiva)
                let container = document.querySelector('.kanban-container');
                if (container) {
                    window.TaskEngine.init({
                        entityType: '<?= htmlspecialchars($entity_type) ?>',
                        entityId: '<?= htmlspecialchars($entity_id) ?>',
                        workflow: <?= json_encode($statimap, JSON_UNESCAPED_UNICODE) ?>,
                        container: container,
                        onTaskCreated: function (res) {
                            if (typeof showToast === 'function') {
                                showToast('Task creata', 'success');
                            }
                        },
                        onTaskMoved: function (taskId, newStatus, res) {
                            if (typeof showToast === 'function') {
                                showToast('Task spostata', 'success');
                            }
                        },
                        onTaskDeleted: function (taskId, res) {
                            if (typeof showToast === 'function') {
                                showToast('Task eliminata', 'success');
                            }
                        }
                    });
                }
            }
        });
    <?php endif; ?>
</script>

<!-- BASE COMUNE UNIFICATA - Tutti i kanban usano questi moduli -->
<link rel="stylesheet" href="/assets/css/global_drawer.css">
<script src="/assets/js/modules/task_api.js"></script>
<script src="/assets/js/modules/task_dnd.js"></script>
<script src="/assets/js/modules/task_board.js"></script>
<script src="/assets/js/modules/task_details.js"></script>

<!-- Compatibilità retroattiva -->
<script src="/assets/js/modules/task_engine.js"></script>
<script src="/assets/js/modules/kanban_renderer.js"></script>
<script src="/assets/js/modules/kanban.js"></script>