/**
 * TASK ENGINE GLOBALE
 * 
 * Engine centralizzato per gestione task/kanban lato frontend.
 * Completamente riutilizzabile per qualsiasi contesto (commesse, gare, crm, hr, ecc).
 * 
 * ARCHITETTURA:
 * - Separazione motore (logica) / renderer (visualizzazione)
 * - Event delegation per performance
 * - Lazy load subtasks
 * - Drag & drop tra colonne
 * - Stato sincronizzato con backend
 */

window.TaskEngine = (function() {
    'use strict';

    // === CONFIGURAZIONE ===
    let config = {
        entityType: null,
        entityId: null,
        workflow: {},
        kanbanContainer: null,
        onTaskCreated: null,
        onTaskUpdated: null,
        onTaskMoved: null,
        onTaskDeleted: null
    };

    // === INIZIALIZZAZIONE ===

    /**
     * Inizializza il Task Engine
     * 
     * @param {Object} options Opzioni di configurazione
     * @param {string} options.entityType Tipo entità (es: 'commessa', 'gara')
     * @param {string} options.entityId ID entità
     * @param {Object} options.workflow Mappa stati [id => label]
     * @param {HTMLElement|string} options.container Container kanban
     * @param {Function} options.onTaskCreated Callback creazione task
     * @param {Function} options.onTaskUpdated Callback aggiornamento task
     * @param {Function} options.onTaskMoved Callback spostamento task
     * @param {Function} options.onTaskDeleted Callback eliminazione task
     */
    function init(options) {
        config.entityType = options.entityType || null;
        config.entityId = options.entityId || null;
        config.workflow = options.workflow || {};
        
        if (typeof options.container === 'string') {
            config.kanbanContainer = document.querySelector(options.container);
        } else {
            config.kanbanContainer = options.container;
        }
        
        if (!config.kanbanContainer) {
            console.error('TaskEngine: container non trovato');
            return;
        }
        
        config.onTaskCreated = options.onTaskCreated || null;
        config.onTaskUpdated = options.onTaskUpdated || null;
        config.onTaskMoved = options.onTaskMoved || null;
        config.onTaskDeleted = options.onTaskDeleted || null;
        
        // Setup event listeners
        setupEventListeners();
        
        // Setup drag & drop
        setupDragAndDrop();
        
        // Setup subtasks toggle
        setupSubtasksToggle();
    }

    // === EVENT LISTENERS ===

    /**
     * Setup event listeners con delegation
     */
    function setupEventListeners() {
        if (!config.kanbanContainer) return;
        
        // Double click per aprire dettagli (usa solo side panel TaskDetails)
        config.kanbanContainer.addEventListener('dblclick', function(e) {
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
        config.kanbanContainer.addEventListener('click', function(e) {
            if (e.target.closest('.kanban-subtasks-toggle')) {
                const toggle = e.target.closest('.kanban-subtasks-toggle');
                const parentId = toggle.getAttribute('data-parent-id');
                toggleSubtasks(parentId);
            }
            
            if (e.target.closest('.kanban-subtask-toggle-icon')) {
                const subtask = e.target.closest('.kanban-subtask');
                if (subtask) {
                    subtask.classList.toggle('expanded');
                }
            }
        });
    }

    /**
     * Setup drag & drop
     */
    function setupDragAndDrop() {
        if (!config.kanbanContainer) return;
        
        // Task drag start
        config.kanbanContainer.addEventListener('dragstart', function(e) {
            const taskEl = e.target.closest('.task');
            if (!taskEl || !taskEl.hasAttribute('draggable')) return;
            
            e.dataTransfer.setData('text/plain', taskEl.id);
            taskEl.classList.add('dragging');
        });
        
        // Task drag end
        config.kanbanContainer.addEventListener('dragend', function(e) {
            const taskEl = e.target.closest('.task');
            if (taskEl) {
                taskEl.classList.remove('dragging');
            }
        });
        
        // Column drag over
        const columns = config.kanbanContainer.querySelectorAll('.kanban-column');
        columns.forEach(column => {
            column.addEventListener('dragover', function(e) {
                e.preventDefault();
                column.classList.add('drag-over');
            });
            
            column.addEventListener('dragleave', function() {
                column.classList.remove('drag-over');
            });
            
            column.addEventListener('drop', function(e) {
                e.preventDefault();
                column.classList.remove('drag-over');
                
                const taskId = e.dataTransfer.getData('text/plain');
                const taskEl = document.getElementById(taskId);
                if (!taskEl) return;
                
                const taskContainer = column.querySelector('.task-container');
                if (!taskContainer) return;
                
                // Sposta nel DOM
                taskContainer.appendChild(taskEl);
                
                // Aggiorna stato
                const newStatus = parseInt(column.getAttribute('data-status-id'), 10);
                const context = getContextFromElement(taskEl);
                
                moveTask(taskId, newStatus, context);
            });
        });
    }

    /**
     * Setup toggle subtasks
     */
    function setupSubtasksToggle() {
        if (!config.kanbanContainer) return;
        
        // Lazy load subtasks quando si espande
        config.kanbanContainer.addEventListener('click', async function(e) {
            const toggle = e.target.closest('.kanban-subtasks-toggle');
            if (!toggle) return;
            
            const parentId = toggle.getAttribute('data-parent-id');
            if (!parentId) return;
            
            // Se le subtasks non sono ancora caricate, caricale
            const parentTask = toggle.closest('.task');
            if (!parentTask) return;
            
            const taskId = parseInt(parentTask.getAttribute('data-task-id'), 10);
            const context = getContextFromElement(parentTask);
            
            // Verifica se già caricate
            const subtasksContainer = parentTask.querySelector('.kanban-subtasks-container');
            if (!subtasksContainer || subtasksContainer.dataset.loaded === 'true') {
                return;
            }
            
            // Carica subtasks
            try {
                const subtasks = await loadSubTasks(taskId, context);
                renderSubtasks(parentTask, subtasks, context);
                subtasksContainer.dataset.loaded = 'true';
            } catch (error) {
                console.error('Errore caricamento subtasks:', error);
                if (typeof showToast === 'function') {
                    showToast('Errore caricamento sottotask', 'error');
                }
            }
        });
    }

    // === OPERAZIONI TASK ===

    /**
     * Crea una nuova task
     * 
     * @param {Object} taskData Dati task
     * @param {Object} context Contesto (entity_type, entity_id)
     * @returns {Promise<Object>} Risultato operazione
     */
    async function createTask(taskData, context) {
        const payload = {
            ...taskData,
            entity_type: context.entity_type || config.entityType,
            entity_id: context.entity_id || config.entityId
        };
        
        const res = await customFetch('task', 'createTask', payload);
        
        if (res.success) {
            if (config.onTaskCreated) {
                config.onTaskCreated(res);
            }
            
            // Ricarica kanban o aggiungi task dinamicamente
            if (typeof window.refreshKanban === 'function') {
                window.refreshKanban();
            } else {
                location.reload();
            }
        }
        
        return res;
    }

    /**
     * Aggiorna una task esistente
     * 
     * @param {number} taskId ID task
     * @param {Object} taskData Dati aggiornamento
     * @param {Object} context Contesto
     * @returns {Promise<Object>} Risultato operazione
     */
    async function updateTask(taskId, taskData, context) {
        const payload = {
            task_id: taskId,
            ...taskData,
            entity_type: context.entity_type || config.entityType,
            entity_id: context.entity_id || config.entityId
        };
        
        const res = await customFetch('task', 'updateTask', payload);
        
        if (res.success) {
            if (config.onTaskUpdated) {
                config.onTaskUpdated(taskId, res);
            }
            
            // Aggiorna UI
            updateTaskInUI(taskId, taskData);
        }
        
        return res;
    }

    /**
     * Sposta una task (cambio stato)
     * 
     * @param {string} taskIdStr ID task (formato task-tabella_id)
     * @param {number} newStatus Nuovo stato
     * @param {Object} context Contesto
     * @returns {Promise<Object>} Risultato operazione
     */
    async function moveTask(taskIdStr, newStatus, context) {
        // Parse task ID
        const parts = taskIdStr.replace('task-', '').split('_');
        const taskId = parseInt(parts.pop(), 10);
        
        const payload = {
            task_id: taskId,
            new_status: newStatus,
            entity_type: context.entity_type || config.entityType,
            entity_id: context.entity_id || config.entityId
        };
        
        const res = await customFetch('task', 'moveTask', payload);
        
        if (res.success) {
            if (config.onTaskMoved) {
                config.onTaskMoved(taskId, newStatus, res);
            }
            
            if (typeof showToast === 'function') {
                showToast('Stato aggiornato', 'success');
            }
        } else {
            if (typeof showToast === 'function') {
                showToast(res.message || 'Errore aggiornamento stato', 'error');
            }
            
            // Revert UI
            location.reload();
        }
        
        return res;
    }

    /**
     * Elimina una task
     * 
     * @param {number} taskId ID task
     * @param {Object} context Contesto
     * @returns {Promise<Object>} Risultato operazione
     */
    async function deleteTask(taskId, context) {
        if (!confirm('Vuoi eliminare questa task?')) {
            return { success: false, cancelled: true };
        }
        
        const payload = {
            task_id: taskId,
            entity_type: context.entity_type || config.entityType,
            entity_id: context.entity_id || config.entityId
        };
        
        const res = await customFetch('task', 'deleteTask', payload);
        
        if (res.success) {
            if (config.onTaskDeleted) {
                config.onTaskDeleted(taskId, res);
            }
            
            // Rimuovi dal DOM
            const taskEl = document.querySelector(`[data-task-id="${taskId}"]`);
            if (taskEl) {
                taskEl.remove();
            }
            
            if (typeof showToast === 'function') {
                showToast('Task eliminata', 'success');
            }
        } else {
            if (typeof showToast === 'function') {
                showToast(res.message || 'Errore eliminazione', 'error');
            }
        }
        
        return res;
    }

    /**
     * Carica subtasks di una task (lazy load)
     * 
     * @param {number} parentTaskId ID task padre
     * @param {Object} context Contesto
     * @returns {Promise<Array>} Array subtasks
     */
    async function loadSubTasks(parentTaskId, context) {
        const payload = {
            parent_task_id: parentTaskId,
            entity_type: context.entity_type || config.entityType,
            entity_id: context.entity_id || config.entityId
        };
        
        const res = await customFetch('task', 'loadSubTasks', payload);
        
        if (res.success && Array.isArray(res.data)) {
            return res.data;
        }
        
        return [];
    }

    // === UI HELPERS ===

    /**
     * Aggiorna task nell'UI senza reload
     * 
     * @param {number} taskId ID task
     * @param {Object} taskData Dati aggiornati
     */
    function updateTaskInUI(taskId, taskData) {
        const taskEl = document.querySelector(`[data-task-id="${taskId}"]`);
        if (!taskEl) {
            // Se la task non è nel DOM, ricarica la pagina o il kanban
            if (typeof window.refreshKanban === 'function') {
                window.refreshKanban();
            } else {
                location.reload();
            }
            return;
        }
        
        // Aggiorna campi visibili
        if (taskData.titolo || taskData.title) {
            const title = taskData.titolo || taskData.title;
            const titleEl = taskEl.querySelector('.title-field strong, .task-header strong, .title-field');
            if (titleEl) {
                if (titleEl.tagName === 'STRONG') {
                    titleEl.textContent = title;
                } else {
                    const strongEl = titleEl.querySelector('strong');
                    if (strongEl) strongEl.textContent = title;
                }
            }
        }
        
        if (taskData.priority) {
            const priorityEl = taskEl.querySelector('.priority-field span, .priority-field');
            if (priorityEl) {
                if (priorityEl.tagName === 'SPAN') {
                    priorityEl.textContent = taskData.priority;
                } else {
                    const spanEl = priorityEl.querySelector('span');
                    if (spanEl) spanEl.textContent = taskData.priority;
                }
            }
        }
        
        // Aggiorna stato visivamente (solo se kanbanContainer è disponibile)
        if ((taskData.status_id || taskData.stato) && config.kanbanContainer) {
            const statusId = taskData.status_id || taskData.stato;
            const column = config.kanbanContainer.querySelector(
                `.kanban-column[data-status-id="${statusId}"] .task-container`
            );
            if (column && taskEl.parentElement !== column) {
                column.appendChild(taskEl);
            }
        } else if (taskData.status_id || taskData.stato) {
            // Se kanbanContainer non è disponibile, usa document per cercare
            const statusId = taskData.status_id || taskData.stato;
            const column = document.querySelector(
                `.kanban-column[data-status-id="${statusId}"] .task-container`
            );
            if (column && taskEl.parentElement !== column) {
                column.appendChild(taskEl);
            }
        }
    }

    /**
     * Renderizza subtasks
     * 
     * @param {HTMLElement} parentTask Elemento task padre
     * @param {Array} subtasks Array subtasks
     * @param {Object} context Contesto
     */
    function renderSubtasks(parentTask, subtasks, context) {
        if (!subtasks || subtasks.length === 0) return;
        
        let container = parentTask.querySelector('.kanban-subtasks-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'kanban-subtasks-container';
            parentTask.appendChild(container);
        }
        
        container.innerHTML = '';
        
        subtasks.forEach(subtask => {
            const subtaskEl = document.createElement('div');
            subtaskEl.className = 'kanban-subtask collapsed';
            subtaskEl.setAttribute('data-subtask-id', subtask.id);
            subtaskEl.setAttribute('data-parent-id', parentTask.getAttribute('data-task-id'));
            
            // Usa KanbanRenderer se disponibile
            if (window.KanbanRenderer && typeof window.KanbanRenderer.renderTask === 'function') {
                const rendered = window.KanbanRenderer.renderTask(subtask, {
                    tabella: context.table || context.entity_id,
                    kanbanType: context.entity_type || 'generic'
                });
                subtaskEl.appendChild(rendered.taskElement);
            } else {
                // Fallback semplice
                subtaskEl.innerHTML = `
                    <div class="kanban-subtask-header">
                        <span class="kanban-subtask-toggle-icon">▶</span>
                        <strong>${escapeHtml(subtask.titolo || 'Senza titolo')}</strong>
                    </div>
                    <div class="kanban-subtask-body">
                        ${subtask.descrizione ? `<p>${escapeHtml(subtask.descrizione)}</p>` : ''}
                    </div>
                `;
            }
            
            container.appendChild(subtaskEl);
        });
    }

    /**
     * Toggle visibilità subtasks
     * 
     * @param {string} parentId ID task padre
     */
    function toggleSubtasks(parentId) {
        const parentTask = document.querySelector(`[data-task-id="${parentId}"]`);
        if (!parentTask) return;
        
        const container = parentTask.querySelector('.kanban-subtasks-container');
        if (!container) return;
        
        container.classList.toggle('expanded');
        
        const toggle = parentTask.querySelector('.kanban-subtasks-toggle');
        if (toggle) {
            const icon = toggle.querySelector('.toggle-icon');
            if (icon) {
                icon.textContent = container.classList.contains('expanded') ? '▲' : '▼';
            }
        }
    }

    /**
     * Estrae contesto da un elemento DOM
     * 
     * @param {HTMLElement} element Elemento
     * @returns {Object} Contesto
     */
    function getContextFromElement(element) {
        const tabella = element.getAttribute('data-tabella') || 
                       element.closest('.kanban-container')?.getAttribute('data-tabella') || '';
        
        // Estrai entity_type/entity_id da tabella o usa config
        let entityType = config.entityType;
        let entityId = config.entityId;
        
        if (tabella.startsWith('com_')) {
            entityType = 'commessa';
            entityId = tabella.replace('com_', '');
        } else if (tabella) {
            const parts = tabella.split('_');
            if (parts.length >= 2) {
                entityType = parts[0];
                entityId = parts.slice(1).join('_');
            }
        }
        
        return {
            entity_type: entityType,
            entity_id: entityId,
            table: tabella
        };
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

    // === PUBLIC API ===
    return {
        init,
        createTask,
        updateTask,
        moveTask,
        deleteTask,
        loadSubTasks,
        getContext: () => ({ entityType: config.entityType, entityId: config.entityId })
    };
})();

// Export per compatibilità
if (typeof module !== 'undefined' && module.exports) {
    module.exports = window.TaskEngine;
}
