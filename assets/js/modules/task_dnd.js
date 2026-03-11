/**
 * TASK DRAG & DROP GLOBALE
 * 
 * Modulo unificato per drag & drop task.
 * Supporta:
 * - Drag tra colonne (cambio stato)
 * - Drag per riordinamento (cambio posizione)
 * - Drag per reparent (trascina come subtask)
 * 
 * Unica implementazione drag&drop per tutti i kanban.
 */

window.TaskDnd = (function () {
    'use strict';

    let config = {
        container: null,
        onMove: null,
        onReorder: null,
        onReparent: null
    };

    let draggedTask = null;
    let draggedTaskId = null;
    let draggedTaskElement = null;

    /**
     * Inizializza drag & drop
     * 
     * @param {Object} options Configurazione
     * @param {HTMLElement|string} options.container Container kanban
     * @param {Function} options.onMove Callback spostamento colonna
     * @param {Function} options.onReorder Callback riordinamento
     * @param {Function} options.onReparent Callback reparent
     */
    function init(options) {
        if (typeof options.container === 'string') {
            config.container = document.querySelector(options.container);
        } else {
            config.container = options.container;
        }

        if (!config.container) {
            console.error('TaskDnd: container non trovato');
            return;
        }

        config.onMove = options.onMove || null;
        config.onReorder = options.onReorder || null;
        config.onReparent = options.onReparent || null;

        setupEventListeners();
    }

    /**
     * Setup event listeners con delegation
     */
    function setupEventListeners() {
        if (!config.container) return;

        // Drag start - su task
        config.container.addEventListener('dragstart', function (e) {
            const taskEl = e.target.closest('.task');
            if (!taskEl || !taskEl.hasAttribute('draggable')) return;

            draggedTask = taskEl;
            draggedTaskId = parseInt(taskEl.getAttribute('data-task-id'), 10);
            draggedTaskElement = taskEl;

            e.dataTransfer.setData('text/plain', taskEl.id);
            e.dataTransfer.effectAllowed = 'move';

            taskEl.classList.add('dragging');
        });

        // Drag end
        config.container.addEventListener('dragend', function (e) {
            if (draggedTaskElement) {
                draggedTaskElement.classList.remove('dragging');
            }
            draggedTask = null;
            draggedTaskId = null;
            draggedTaskElement = null;
        });

        // Drag over - su colonne e task container
        config.container.addEventListener('dragover', function (e) {
            const column = e.target.closest('.kanban-column');
            const taskContainer = e.target.closest('.task-container');
            const task = e.target.closest('.task');

            if (column || taskContainer || task) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';

                // Highlight colonna
                if (column) {
                    column.classList.add('drag-over');
                }
            }
        });

        // Drag leave
        config.container.addEventListener('dragleave', function (e) {
            const column = e.target.closest('.kanban-column');
            if (column) {
                column.classList.remove('drag-over');
            }
        });

        // Drop
        config.container.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const column = e.target.closest('.kanban-column');
            const taskContainer = e.target.closest('.task-container');
            const targetTask = e.target.closest('.task');
            const subtaskToggle = e.target.closest('.kanban-subtasks-toggle');

            // Rimuovi highlight
            config.container.querySelectorAll('.kanban-column').forEach(col => {
                col.classList.remove('drag-over');
            });

            if (!draggedTaskElement || !draggedTaskId) {
                return;
            }

            const taskId = draggedTaskId;

            // CASO 1: Drop su colonna (cambio stato)
            if (column) {
                // Se non siamo direttamente sul task-container, trovalo dentro la colonna
                const targetContainer = taskContainer || column.querySelector('.task-container');

                if (targetContainer) {
                    const newStatusId = parseInt(column.getAttribute('data-status-id'), 10);
                    const oldStatusId = parseInt(draggedTaskElement.getAttribute('data-status-id') || draggedTaskElement.closest('.kanban-column')?.getAttribute('data-status-id'), 10);

                    // Permetti il drop anche nella stessa colonna (per riordinamento se empty) o cambio colonna
                    if (newStatusId) {
                        // Se cambia stato OPPURE se siamo nella stessa colonna ma non su un'altra task (drop vuoto / fine lista)
                        // Per il riordinamento preciso (in mezzo alla lista) ci pensa il CASO 4, 
                        // ma qui gestiamo il caso "drop sull'header" o "drop in fondo"

                        if (newStatusId !== oldStatusId || !e.target.closest('.task')) {
                            // Sposta nel DOM
                            targetContainer.appendChild(draggedTaskElement);
                            draggedTaskElement.setAttribute('data-status-id', newStatusId);

                            // Calcola nuova posizione (fine colonna)
                            const tasksInColumn = targetContainer.querySelectorAll('.task:not(.kanban-subtask)');
                            const newPosition = tasksInColumn.length; // Posizione 1-based o 0-based a seconda della logica, qui usiamo length quindi in fondo

                            // Callback (chiamala solo se cambiato stato o se necessario salvare posizione)
                            if (config.onMove) {
                                config.onMove(taskId, oldStatusId, newStatusId, newPosition);
                            }
                        }
                    }
                    return; // Stop here, handled
                }
            }

            // CASO 2: Drop su task (reparent come subtask)
            if (targetTask && targetTask !== draggedTaskElement) {
                const parentTaskId = parseInt(targetTask.getAttribute('data-task-id'), 10);

                if (parentTaskId && parentTaskId !== taskId) {
                    // Verifica che non sia già una subtask
                    if (draggedTaskElement.classList.contains('kanban-subtask')) {
                        return; // Già subtask
                    }

                    // Sposta come subtask
                    const subtasksContainer = targetTask.querySelector('.kanban-subtasks-container') || createSubtasksContainer(targetTask);
                    subtasksContainer.appendChild(draggedTaskElement);
                    draggedTaskElement.classList.add('kanban-subtask');

                    // Callback
                    if (config.onReparent) {
                        config.onReparent(taskId, parentTaskId);
                    }
                }
                return;
            }

            // CASO 3: Drop su toggle subtasks (reparent)
            if (subtaskToggle) {
                const parentId = subtaskToggle.getAttribute('data-parent-id');
                if (parentId) {
                    const parentTask = config.container.querySelector(`[data-task-id="${parentId}"]`);
                    if (parentTask) {
                        const subtasksContainer = parentTask.querySelector('.kanban-subtasks-container') || createSubtasksContainer(parentTask);
                        subtasksContainer.appendChild(draggedTaskElement);
                        draggedTaskElement.classList.add('kanban-subtask');

                        if (config.onReparent) {
                            config.onReparent(taskId, parseInt(parentId, 10));
                        }
                    }
                }
                return;
            }

            // CASO 4: Drop su task container (riordinamento)
            if (taskContainer) {
                const afterTask = e.target.closest('.task');
                if (afterTask && afterTask !== draggedTaskElement) {
                    // Calcola nuova posizione
                    const tasks = Array.from(taskContainer.querySelectorAll('.task:not(.kanban-subtask)'));
                    const afterIndex = tasks.indexOf(afterTask);
                    const newPosition = afterIndex + 1;

                    // Sposta nel DOM
                    if (afterIndex >= 0) {
                        taskContainer.insertBefore(draggedTaskElement, afterTask.nextSibling);
                    } else {
                        taskContainer.appendChild(draggedTaskElement);
                    }

                    // Callback
                    if (config.onReorder) {
                        const statusId = parseInt(taskContainer.closest('.kanban-column')?.getAttribute('data-status-id'), 10);
                        config.onReorder(taskId, statusId, newPosition);
                    }
                }
            }
        });
    }

    /**
     * Crea container subtasks se non esiste
     */
    function createSubtasksContainer(parentTask) {
        const container = document.createElement('div');
        container.className = 'kanban-subtasks-container';
        container.dataset.loaded = 'true';
        parentTask.appendChild(container);
        return container;
    }

    /**
     * Abilita/disabilita drag & drop
     */
    function setEnabled(enabled) {
        if (!config.container) return;

        const tasks = config.container.querySelectorAll('.task');
        tasks.forEach(task => {
            task.setAttribute('draggable', enabled ? 'true' : 'false');
        });
    }

    // === PUBLIC API ===
    return {
        init,
        setEnabled
    };
})();

// Export per compatibilità
if (typeof module !== 'undefined' && module.exports) {
    module.exports = window.TaskDnd;
}
