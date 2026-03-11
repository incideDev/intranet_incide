/**
 * TASK BOARD GLOBALE
 * 
 * Controller principale per gestione board kanban.
 * Unifica: state, rendering, API, drag&drop.
 * 
 * USO:
 * TaskBoard.mount({
 *   container: '#task-board',
 *   contextType: 'commessa',
 *   contextId: 'ADR06G'
 * });
 */

window.TaskBoard = (function () {
    'use strict';

    // === STATE ===
    let state = {
        contextType: null,
        contextId: null,
        statuses: [],
        tasks: [],
        subtasks: {}, // {parentId: [subtasks]}
        container: null,
        workflow: {}
    };

    // === CONFIGURAZIONE ===
    let config = {
        onTaskCreated: null,
        onTaskUpdated: null,
        onTaskMoved: null,
        onTaskDeleted: null,
        renderTask: null // Custom renderer opzionale
    };

    /**
     * Monta il board kanban
     * 
     * @param {Object} options Opzioni
     * @param {HTMLElement|string} options.container Container DOM
     * @param {string} options.contextType Tipo contesto
     * @param {string} options.contextId ID contesto
     * @param {Function} options.onTaskCreated Callback
     * @param {Function} options.onTaskUpdated Callback
     * @param {Function} options.onTaskMoved Callback
     * @param {Function} options.onTaskDeleted Callback
     * @param {Function} options.renderTask Custom renderer
     */
    async function mount(options) {
        // Setup state
        state.contextType = options.contextType;
        state.contextId = options.contextId;

        if (typeof options.container === 'string') {
            state.container = document.querySelector(options.container);
        } else {
            state.container = options.container;
        }

        if (!state.container) {
            console.error('TaskBoard: container non trovato');
            return;
        }

        // Setup config
        config.onTaskCreated = options.onTaskCreated || null;
        config.onTaskUpdated = options.onTaskUpdated || null;
        config.onTaskMoved = options.onTaskMoved || null;
        config.onTaskDeleted = options.onTaskDeleted || null;
        config.renderTask = options.renderTask || null;

        // Verifica se il container ha già contenuto HTML renderizzato dal PHP
        const hasExistingContent = state.container.querySelector('.task') !== null ||
            state.container.querySelector('.kanban-column') !== null;

        if (hasExistingContent) {
            // Il contenuto è già stato renderizzato dal PHP
            // NON ricaricare via AJAX, solo inizializzare drag&drop e event listeners
            console.log('TaskBoard: contenuto già presente, inizializzo solo drag&drop');

            // Estrai gli stati dal DOM esistente per popolare state.statuses
            const statusColumns = state.container.querySelectorAll('.kanban-column[data-status-id]');
            state.statuses = Array.from(statusColumns).map(col => {
                const statusId = parseInt(col.getAttribute('data-status-id'), 10);
                const statusName = col.querySelector('.kanban-title')?.textContent?.trim() || '';
                const headerStyle = col.querySelector('.kanban-header')?.style?.borderBottom || '';
                const colorMatch = headerStyle.match(/solid\s+([^;]+)/);
                const color = colorMatch ? colorMatch[1].trim() : '#007bff';
                return { id: statusId, name: statusName, color: color, position: statusId };
            });

            // Estrai le task dal DOM esistente per popolare state.tasks
            const taskElements = state.container.querySelectorAll('.task[data-task-id]');
            state.tasks = Array.from(taskElements).map(taskEl => {
                const taskId = parseInt(taskEl.getAttribute('data-task-id'), 10);
                const statusId = parseInt(taskEl.closest('.kanban-column')?.getAttribute('data-status-id') || '0', 10);
                return {
                    id: taskId,
                    statusId: statusId,
                    title: taskEl.querySelector('.title-field strong')?.textContent?.trim() || '',
                    // Altri campi possono essere estratti se necessario
                };
            });
        } else {
            // Nessun contenuto esistente, carica via AJAX
            await loadBoard();
        }

        // Setup drag & drop (sempre, sia per contenuto esistente che nuovo)
        if (window.TaskDnd) {
            window.TaskDnd.init({
                container: state.container,
                onMove: handleMove,
                onReorder: handleReorder,
                onReparent: handleReparent
            });
        }

        // Setup event listeners
        setupEventListeners();
    }

    /**
     * Carica board (statuses + tasks)
     * 
     * @param {boolean} forceRender Forza il rendering anche se c'è contenuto esistente
     */
    async function loadBoard(forceRender = false) {
        if (!window.TaskApi) {
            console.error('TaskBoard: TaskApi non disponibile');
            return;
        }

        try {
            const res = await window.TaskApi.loadBoard(
                state.contextType,
                state.contextId
            );

            if (res.success) {
                state.statuses = res.statuses || [];
                state.tasks = res.tasks || [];

                // Costruisci workflow map
                state.workflow = {};
                state.statuses.forEach(status => {
                    state.workflow[status.id] = status.name;
                });

                // Render solo se forceRender=true o se non c'è contenuto esistente
                const hasExistingContent = state.container && (
                    state.container.querySelector('.task') !== null ||
                    state.container.querySelector('.kanban-column') !== null
                );

                if (forceRender || !hasExistingContent) {
                    render();
                }
            } else {
                console.error('Errore caricamento board:', res.message);
                if (typeof showToast === 'function') {
                    showToast('Errore caricamento board', 'error');
                }
            }
        } catch (error) {
            console.error('Errore caricamento board:', error);
        }
    }

    /**
     * Renderizza il board
     */
    function render() {
        if (!state.container) return;

        // Usa KanbanRenderer se disponibile
        if (window.KanbanRenderer && typeof window.KanbanRenderer.renderTask === 'function') {
            renderWithRenderer();
        } else {
            renderSimple();
        }
    }

    /**
     * Render con KanbanRenderer
     */
    function renderWithRenderer() {
        const html = `
            <div class="kanban-container" 
                 data-context-type="${escapeHtml(state.contextType)}"
                 data-context-id="${escapeHtml(state.contextId)}">
                ${state.statuses.map(status => `
                    <div class="kanban-column" 
                         data-status-id="${status.id}"
                         data-status="${status.name.toLowerCase().replace(/\s+/g, '_')}">
                        <div class="kanban-header" style="border-bottom: 3px solid ${status.color || '#007bff'}">
                            <span class="kanban-title">${escapeHtml(status.name)}</span>
                            <span class="kanban-count">${getTaskCountForStatus(status.id)}</span>
                            <button class="add-task-btn" onclick="TaskBoard.openCreateTaskModal(${status.id})">＋</button>
                        </div>
                        <div class="task-container" id="kanban-${status.name.toLowerCase().replace(/\s+/g, '_')}">
                            ${renderTasksForStatus(status.id)}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;

        state.container.innerHTML = html;
    }

    /**
     * Render semplice (fallback)
     */
    function renderSimple() {
        renderWithRenderer(); // Per ora stesso metodo
    }

    /**
     * Renderizza task per uno status
     */
    function renderTasksForStatus(statusId) {
        const tasks = state.tasks.filter(t => t.statusId === statusId);

        return tasks.map(task => {
            if (config.renderTask && typeof config.renderTask === 'function') {
                return config.renderTask(task);
            }

            // Render standard
            if (window.KanbanRenderer) {
                const rendered = window.KanbanRenderer.renderTask(task, {
                    tabella: `${state.contextType}_${state.contextId}`,
                    kanbanType: state.contextType
                });
                return rendered.taskElement.outerHTML;
            }

            // Fallback minimale
            return `
                <div class="task" 
                     id="task-${task.id}"
                     data-task-id="${task.id}"
                     data-status-id="${statusId}"
                     draggable="true">
                    <div class="task-header">
                        <strong>${escapeHtml(task.title || 'Senza titolo')}</strong>
                    </div>
                    ${task.description ? `<div class="task-body">${escapeHtml(task.description)}</div>` : ''}
                </div>
            `;
        }).join('');
    }

    /**
     * Ottiene conteggio task per status
     */
    function getTaskCountForStatus(statusId) {
        return state.tasks.filter(t => t.statusId === statusId).length;
    }

    /**
     * Setup event listeners
     */
    function setupEventListeners() {
        if (!state.container) return;

        // Double click per dettagli (apre solo side panel TaskDetails)
        state.container.addEventListener('dblclick', function (e) {
            const taskEl = e.target.closest('.task');
            if (!taskEl) return;

            const taskId = parseInt(taskEl.getAttribute('data-task-id'), 10);
            if (!taskId) return;

            // Usa solo TaskDetails side panel (no modale legacy)
            if (window.TaskDetails && typeof window.TaskDetails.open === 'function') {
                window.TaskDetails.open(taskId);
            } else {
                console.warn('TaskDetails non disponibile. Assicurati che task_details.js sia caricato.');
            }
        });

        // Click su toggle subtasks
        state.container.addEventListener('click', function (e) {
            const toggle = e.target.closest('.kanban-subtasks-toggle');
            if (toggle) {
                const parentId = toggle.getAttribute('data-parent-id');
                if (parentId) {
                    toggleSubtasks(parseInt(parentId, 10));
                }
            }
        });
    }

    /**
     * Toggle subtasks (lazy load)
     */
    async function toggleSubtasks(parentTaskId) {
        const parentTask = state.container.querySelector(`[data-task-id="${parentTaskId}"]`);
        if (!parentTask) return;

        let container = parentTask.querySelector('.kanban-subtasks-container');

        // Se già caricate, toggle visibilità
        if (container && container.dataset.loaded === 'true') {
            container.classList.toggle('expanded');
            return;
        }

        // Carica subtasks
        if (!window.TaskApi) return;

        try {
            const subtasks = await window.TaskApi.loadChildren(parentTaskId);

            if (!container) {
                container = document.createElement('div');
                container.className = 'kanban-subtasks-container';
                parentTask.appendChild(container);
            }

            container.innerHTML = subtasks.map(subtask => {
                if (window.KanbanRenderer) {
                    const rendered = window.KanbanRenderer.renderTask(subtask, {
                        tabella: `${state.contextType}_${state.contextId}`,
                        kanbanType: state.contextType
                    });
                    return rendered.taskElement.outerHTML;
                }
                return `<div class="kanban-subtask" data-task-id="${subtask.id}">${escapeHtml(subtask.title)}</div>`;
            }).join('');

            container.dataset.loaded = 'true';
            container.classList.add('expanded');
        } catch (error) {
            console.error('Errore caricamento subtasks:', error);
        }
    }

    /**
     * Handler spostamento task (cambio colonna)
     */
    async function handleMove(taskId, oldStatusId, newStatusId, newPosition) {
        if (!window.TaskApi) return;

        try {
            const res = await window.TaskApi.moveTask(taskId, newStatusId, newPosition);

            if (res.success) {
                // Aggiorna state
                const task = state.tasks.find(t => t.id === taskId);
                if (task) {
                    task.statusId = newStatusId;
                    task.position = newPosition;
                }

                // Callback
                if (config.onTaskMoved) {
                    config.onTaskMoved(taskId, oldStatusId, newStatusId, res);
                }

                if (typeof showToast === 'function') {
                    showToast('Task spostata', 'success');
                }
            } else {
                // Revert UI
                location.reload();
                if (typeof showToast === 'function') {
                    showToast(res.message || 'Errore spostamento', 'error');
                }
            }
        } catch (error) {
            console.error('Errore spostamento task:', error);
            location.reload();
        }
    }

    /**
     * Handler riordinamento task
     */
    async function handleReorder(taskId, statusId, newPosition) {
        if (!window.TaskApi) return;

        try {
            const res = await window.TaskApi.moveTask(taskId, statusId, newPosition);

            if (res.success) {
                const task = state.tasks.find(t => t.id === taskId);
                if (task) {
                    task.position = newPosition;
                }
            }
        } catch (error) {
            console.error('Errore riordinamento:', error);
        }
    }

    /**
     * Handler reparent task (diventa subtask)
     */
    async function handleReparent(taskId, parentId) {
        if (!window.TaskApi) return;

        try {
            const res = await window.TaskApi.reparentTask(taskId, parentId);

            if (res.success) {
                const task = state.tasks.find(t => t.id === taskId);
                if (task) {
                    task.parentId = parentId;
                }

                if (typeof showToast === 'function') {
                    showToast('Task spostata come subtask', 'success');
                }
            } else {
                location.reload();
                if (typeof showToast === 'function') {
                    showToast(res.message || 'Errore spostamento', 'error');
                }
            }
        } catch (error) {
            console.error('Errore reparent:', error);
            location.reload();
        }
    }

    /**
     * Apre modale creazione task
     */
    function openCreateTaskModal(statusId) {
        // Da implementare con modale globale
        if (typeof window.openNewTaskModal === 'function') {
            window.openNewTaskModal(statusId, {
                contextType: state.contextType,
                contextId: state.contextId
            });
        } else {
            const title = prompt('Titolo task:');
            if (title) {
                createTask({
                    title: title,
                    statusId: statusId
                });
            }
        }
    }

    /**
     * Crea una nuova task
     */
    async function createTask(taskData) {
        if (!window.TaskApi) return;

        taskData.contextType = state.contextType;
        taskData.contextId = state.contextId;

        try {
            const res = await window.TaskApi.createTask(taskData);

            if (res.success) {
                // Ricarica board (forza rendering per aggiornare UI)
                await loadBoard(true);

                // Callback
                if (config.onTaskCreated) {
                    config.onTaskCreated(res);
                }

                if (typeof showToast === 'function') {
                    showToast('Task creata', 'success');
                }
            } else {
                if (typeof showToast === 'function') {
                    showToast(res.message || 'Errore creazione', 'error');
                }
            }
        } catch (error) {
            console.error('Errore creazione task:', error);
        }
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    /**
     * Funzione test per creare task rapidamente (solo per debug)
     * 
     * @param {string} title Titolo task
     * @param {number} statusId Status ID (opzionale)
     */
    async function createQuickTask(title, statusId = null) {
        if (!state.contextType || !state.contextId) {
            console.error('TaskBoard: contextType/contextId non impostati. Chiama mount() prima.');
            return;
        }

        const taskData = {
            title: title,
            contextType: state.contextType,
            contextId: state.contextId
        };

        if (statusId !== null) {
            taskData.statusId = statusId;
        } else if (state.statuses.length > 0) {
            // Usa il primo status disponibile
            taskData.statusId = state.statuses[0].id;
        }

        await createTask(taskData);
    }

    // === PUBLIC API ===
    /**
     * Ottiene gli stati disponibili per il contesto corrente
     */
    function getStatuses() {
        return state.statuses || [];
    }

    /**
     * Apre il pannello dettagli per una task
     */
    function openDetailsPanel(taskId) {
        if (window.TaskDetails && typeof window.TaskDetails.open === 'function') {
            window.TaskDetails.open(taskId);
        }
    }

    return {
        mount,
        loadBoard,
        createTask,
        openCreateTaskModal,
        openDetailsPanel,
        getStatuses,
        getState: () => ({ ...state }),
        getConfig: () => ({ ...config }),
        createQuickTask // Funzione test
    };
})();

// Export per compatibilità
if (typeof module !== 'undefined' && module.exports) {
    module.exports = window.TaskBoard;
}
