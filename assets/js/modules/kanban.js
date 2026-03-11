document.addEventListener("DOMContentLoaded", () => {

    const kanban = document.querySelector(".kanban-container");
    if (!kanban) return;

    // Task listeners (drag & drop e doppio click per dettagli)
    document.querySelectorAll(".task").forEach(task => {
        task.addEventListener("dragstart", e => {
            e.dataTransfer.setData("text/plain", task.id);
        });

        // Doppio click per dettagli - rimosso in favore della delegation nei moduli specifici
        // (mom.js, gestione_segnalazioni.js, task_board.js, ecc.)
    });

    // Kanban column listeners (drop per cambio stato)
    document.querySelectorAll(".kanban-column").forEach(column => {
        column.addEventListener("dragover", e => e.preventDefault());
        column.addEventListener("drop", e => {
            e.preventDefault();
            const taskId = e.dataTransfer.getData("text/plain");
            const task = document.getElementById(taskId);
            if (!task) return;
            column.querySelector(".task-container").appendChild(task);

            const newStatus = column.dataset.statusId;
            const [table, id] = parseTaskId(taskId);

            if (typeof window.onKanbanTaskMoved === "function") {
                window.onKanbanTaskMoved({ id, table, newStatus });
            }
        });
    });

    // === REGISTRA IL MENU CONTESTUALE UNICO USANDO main_core.js ===
    if (typeof registerContextMenu === "function") {
        registerContextMenu('.task', [
            {
                label: 'Elimina',
                visible: function (taskEl) {
                    // Mostra solo se utente è il creatore o responsabile bacheca
                    const creatoreId = parseInt(taskEl.getAttribute("data-creato-da"), 10);
                    const userId = window.__userId;
                    const isResponsabile = window.__userIsResponsabile === true || window.__userIsResponsabile === "true";
                    const isTaskOwner = creatoreId === userId;
                    return isTaskOwner || isResponsabile;
                },
                action: function (taskEl) {
                    const taskId = taskEl.getAttribute('data-task-id');
                    const tabella = taskEl.getAttribute('data-tabella');
                    if (typeof window.onKanbanTaskDelete === "function") {
                        window.onKanbanTaskDelete(taskId, tabella);
                    }
                }
            }
        ]);
    }

    // Parsing standard ID task (formato task-tabella_id)
    function parseTaskId(taskId) {
        const parts = taskId.replace("task-", "").split("_");
        const id = parts.pop();
        const table = parts.join("_");
        return [table, id];
    }

    // Espone funzioni globali per creare task dinamicamente
    window.addKanbanTask = function (taskHtml, statusId) {
        const column = document.querySelector(`.kanban-column[data-status-id="${statusId}"] .task-container`);
        if (column) {
            column.insertAdjacentHTML("beforeend", taskHtml);
        }
    };

    // (opzionale) Funzione di refresh hookabile da ogni modulo
    window.refreshKanban = function () {
        if (typeof window.onKanbanRefresh === "function") {
            window.onKanbanRefresh();
        } else {
            location.reload();
        }
    };

    // ========= GESTIONE SUBTASKS =========

    /**
     * Aggiungi una nuova subtask
     */
    window.addSubtask = async function (parentTaskId, tabella) {
        const titolo = prompt("Inserisci il titolo della sottoattività:");
        if (!titolo || titolo.trim() === '') return;

        const descrizione = prompt("Descrizione (opzionale):");

        try {
            const result = await customFetch('commesse', 'createSubtask', {
                parent_task_id: parentTaskId,
                tabella: tabella,
                titolo: titolo.trim(),
                descrizione: descrizione ? descrizione.trim() : '',
                status_id: 1 // DA DEFINIRE
            });

            if (result.success) {
                if (typeof showToast === 'function') {
                    showToast('Sottoattività creata con successo', 'success');
                }
                window.refreshKanban();
            } else {
                if (typeof showToast === 'function') {
                    showToast(result.message || 'Errore durante la creazione', 'error');
                }
            }
        } catch (error) {
            console.error('Errore creazione subtask:', error);
            if (typeof showToast === 'function') {
                showToast('Errore di connessione', 'error');
            }
        }
    };

    /**
     * Modifica una subtask esistente
     */
    window.editSubtask = async function (subtaskId, tabella) {
        const titolo = prompt("Nuovo titolo della sottoattività:");
        if (!titolo || titolo.trim() === '') return;

        try {
            const result = await customFetch('commesse', 'updateSubtask', {
                subtask_id: subtaskId,
                tabella: tabella,
                titolo: titolo.trim()
            });

            if (result.success) {
                if (typeof showToast === 'function') {
                    showToast('Sottoattività aggiornata', 'success');
                }
                window.refreshKanban();
            } else {
                if (typeof showToast === 'function') {
                    showToast(result.message || 'Errore durante l\'aggiornamento', 'error');
                }
            }
        } catch (error) {
            console.error('Errore aggiornamento subtask:', error);
            if (typeof showToast === 'function') {
                showToast('Errore di connessione', 'error');
            }
        }
    };

    /**
     * Elimina una subtask
     */
    window.deleteSubtask = async function (subtaskId, tabella) {
        if (!confirm('Vuoi eliminare questa sottoattività?')) return;

        try {
            const result = await customFetch('commesse', 'deleteSubtask', {
                subtask_id: subtaskId,
                tabella: tabella
            });

            if (result.success) {
                if (typeof showToast === 'function') {
                    showToast('Sottoattività eliminata', 'success');
                }
                // Rimuovi l'elemento dal DOM
                const subtaskEl = document.querySelector(`.kan-subtask[data-subtask-id="${subtaskId}"]`);
                if (subtaskEl) {
                    subtaskEl.remove();
                }
            } else {
                if (typeof showToast === 'function') {
                    showToast(result.message || 'Errore durante l\'eliminazione', 'error');
                }
            }
        } catch (error) {
            console.error('Errore eliminazione subtask:', error);
            if (typeof showToast === 'function') {
                showToast('Errore di connessione', 'error');
            }
        }
    };

    /**
     * Crea subtasks da schede compilate di un form
     * Questa funzione può essere chiamata dopo aver compilato un form con schede multiple
     */
    window.createSubtasksFromFormTabs = async function (parentTaskId, tabella, formName, recordId) {
        try {
            const result = await customFetch('commesse', 'createSubtasksFromFormTabs', {
                parent_task_id: parentTaskId,
                tabella: tabella,
                form_name: formName,
                record_id: recordId
            });

            if (result.success) {
                if (typeof showToast === 'function') {
                    showToast(result.message || 'Sottoattività create con successo', 'success');
                }
                window.refreshKanban();
                return result;
            } else {
                if (typeof showToast === 'function') {
                    showToast(result.message || 'Errore durante la creazione', 'error');
                }
                return result;
            }
        } catch (error) {
            console.error('Errore creazione subtasks da form:', error);
            if (typeof showToast === 'function') {
                showToast('Errore di connessione', 'error');
            }
            return { success: false, message: error.message };
        }
    };

});
