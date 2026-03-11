/**
 * TASK API GLOBALE
 * 
 * Modulo unificato per tutte le chiamate API task.
 * Usa ajax.php con section='tasks' e action.
 * Gestisce CSRF, referer, sanitization, error handling.
 * 
 * Unica fonte per tutte le chiamate API task nel sistema.
 */

window.TaskApi = (function () {
    'use strict';

    /**
     * Esegue una chiamata API POST a ajax.php
     * 
     * @param {string} action Azione (loadBoard, createTask, updateTask, moveTask, ecc)
     * @param {Object} payload Dati da inviare
     * @returns {Promise<Object>} Risultato
     */
    async function call(action, payload) {
        try {
            return await customFetch('tasks', action, payload);
        } catch (error) {
            console.error(`Errore API tasks.${action}:`, error);
            return {
                success: false,
                message: error.message || 'Errore di connessione'
            };
        }
    }

    /**
     * Carica board (statuses + tasks top-level)
     * 
     * @param {string} contextType Tipo contesto
     * @param {string} contextId ID contesto
     * @returns {Promise<Object>} {success: true, statuses: [], tasks: []}
     */
    async function loadBoard(contextType, contextId) {
        return await call('loadBoard', {
            contextType: contextType,
            contextId: contextId
        });
    }

    /**
     * Carica subtasks di una task (lazy)
     * 
     * @param {number} taskId ID task padre
     * @returns {Promise<Array>} Array subtasks
     */
    async function loadChildren(taskId) {
        const res = await call('loadChildren', {
            taskId: taskId
        });
        return res.success ? (res.tasks || []) : [];
    }

    /**
     * Crea una nuova task
     * 
     * @param {Object} taskData Dati task
     * @returns {Promise<Object>} Risultato con task_id
     */
    async function createTask(taskData) {
        return await call('createTask', taskData);
    }

    /**
     * Aggiorna una task esistente
     * 
     * @param {number} taskId ID task
     * @param {Object|FormData} taskData Dati aggiornamento (può essere FormData per upload file)
     * @returns {Promise<Object>} Risultato
     */
    async function updateTask(taskId, taskData) {
        // Se è FormData, passa direttamente a customFetch
        if (taskData instanceof FormData) {
            taskData.append('taskId', taskId);
            return await customFetch('tasks', 'updateTask', taskData);
        }

        // Altrimenti usa il metodo normale
        return await call('updateTask', {
            taskId: taskId,
            ...taskData
        });
    }

    /**
     * Sposta una task (cambio stato e/o posizione)
     * 
     * @param {number} taskId ID task
     * @param {number} newStatusId Nuovo stato
     * @param {number|null} newPosition Nuova posizione (opzionale)
     * @returns {Promise<Object>} Risultato
     */
    async function moveTask(taskId, newStatusId, newPosition = null) {
        const payload = {
            taskId: taskId,
            statusId: newStatusId
        };
        if (newPosition !== null) {
            payload.position = newPosition;
        }
        return await call('moveTask', payload);
    }

    /**
     * Riassegna una task come subtask (reparent)
     * 
     * @param {number} taskId ID task
     * @param {number|null} parentId ID task padre (null per rimuovere)
     * @param {number|null} position Posizione (opzionale)
     * @returns {Promise<Object>} Risultato
     */
    async function reparentTask(taskId, parentId, position = null) {
        const payload = {
            taskId: taskId,
            parentId: parentId
        };
        if (position !== null) {
            payload.position = position;
        }
        return await call('reparentTask', payload);
    }

    /**
     * Elimina una task (soft delete)
     * 
     * @param {number} taskId ID task
     * @returns {Promise<Object>} Risultato
     */
    async function deleteTask(taskId) {
        return await call('deleteTask', {
            taskId: taskId
        });
    }

    /**
     * Ottiene dettagli di una task
     * 
     * @param {number} taskId ID task
     * @returns {Promise<Object>} Dettagli task
     */
    async function getTaskDetails(taskId) {
        const res = await call('getTaskDetails', {
            taskId: taskId
        });
        return res.success ? (res.data || null) : null;
    }

    /**
     * Ottiene i contatori delle subtasks (totali e completate)
     * 
     * @param {number} taskId ID task padre
     * @returns {Promise<Object>} Contatori {total, completed}
     */
    async function getSubtaskCounts(taskId) {
        const res = await call('getSubtaskCounts', {
            taskId: taskId
        });
        return res.success ? { total: res.total || 0, completed: res.completed || 0 } : { total: 0, completed: 0 };
    }

    /**
     * Carica l'activity log di una task
     * 
     * @param {number} taskId ID task
     * @param {number} limit Limite risultati (default 50)
     * @returns {Promise<Array>} Array attività
     */
    async function loadActivity(taskId, limit = 50) {
        const res = await call('loadActivity', {
            taskId: taskId,
            limit: limit
        });
        return res.success ? (res.activities || []) : [];
    }

    /**
     * Aggiorna la checklist di una task
     * 
     * @param {number} taskId ID task
     * @param {Array} checklist Array items checklist
     * @returns {Promise<Object>} Risultato
     */
    async function updateChecklist(taskId, checklist) {
        return await call('updateChecklist', {
            taskId: taskId,
            checklist: checklist
        });
    }

    // === PUBLIC API ===
    return {
        loadBoard,
        loadChildren,
        createTask,
        updateTask,
        moveTask,
        reparentTask,
        deleteTask,
        getTaskDetails,
        getSubtaskCounts,
        loadActivity,
        updateChecklist,
        call // Per chiamate custom
    };
})();

// Export per compatibilità
if (typeof module !== 'undefined' && module.exports) {
    module.exports = window.TaskApi;
}
