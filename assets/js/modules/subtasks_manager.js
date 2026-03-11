// ========== GESTIONE SUBTASKS UNIFICATA ==========
// Centralizza logica toggle/restore subtasks per tabella/kanban/gantt
// Zero duplicazione, comportamento consistente

(function() {
    'use strict';
    
    window.SubtasksManager = {
        
        // Configurazioni per contesto (tabella, kanban, gantt)
        contexts: {
            table: {
                toggleIconClass: 'subtask-toggle-icon',
                subtaskSelector: 'tr[data-parent-task-id="{id}"]',
                hideMethod: 'display',
                iconExpanded: '▼',
                iconCollapsed: '▶'
            },
            gantt: {
                toggleIconClass: 'gv-toggle-icon',
                subtaskSelector: '[data-parent-task-id="{id}"]',
                hideMethod: 'display',
                iconExpanded: '▼',
                iconCollapsed: '▶'
            },
            kanban: {
                toggleIconClass: 'kanban-subtasks-toggle',
                subtaskSelector: '.kanban-subtask[data-parent-id="{id}"]',
                hideMethod: 'class',
                iconExpanded: '▼',
                iconCollapsed: '▶'
            }
        },
        
        /**
         * Toggle visibility subtasks
         * @param {string} parentId - ID task principale
         * @param {string} context - 'table', 'gantt', o 'kanban'
         */
        toggle(parentId, context = 'table') {
            const config = this.contexts[context];
            if (!config) return;
            
            const storageKey = `${context}_subtasks_collapsed_${parentId}`;
            const toggleIcon = document.querySelector(`[data-parent-id="${parentId}"].${config.toggleIconClass}`);
            const selector = config.subtaskSelector.replace('{id}', parentId);
            const subtasks = document.querySelectorAll(selector);
            
            if (!toggleIcon || subtasks.length === 0) return;
            
            // Stato corrente
            const isCollapsed = localStorage.getItem(storageKey) === 'true';
            const newState = !isCollapsed; // true = expanded
            
            // Aggiorna icona
            toggleIcon.textContent = newState ? config.iconExpanded : config.iconCollapsed;
            if (toggleIcon.setAttribute) {
                toggleIcon.setAttribute('data-toggle-state', newState ? 'expanded' : 'collapsed');
            }
            
            // Toggle subtasks
            subtasks.forEach(el => {
                if (config.hideMethod === 'display') {
                    el.style.display = newState ? '' : 'none';
                } else if (config.hideMethod === 'class') {
                    if (newState) {
                        el.classList.remove('hidden');
                    } else {
                        el.classList.add('hidden');
                    }
                }
            });
            
            // Salva stato
            localStorage.setItem(storageKey, (!newState).toString());
        },
        
        /**
         * Ripristina stati salvati per un contesto
         * @param {string} context - 'table', 'gantt', o 'kanban'
         */
        restoreAll(context = 'table') {
            const config = this.contexts[context];
            if (!config) return;
            
            document.querySelectorAll(`.${config.toggleIconClass}[data-parent-id]`).forEach(toggleIcon => {
                const parentId = toggleIcon.getAttribute('data-parent-id');
                if (!parentId) return;
                
                const storageKey = `${context}_subtasks_collapsed_${parentId}`;
                const isCollapsed = localStorage.getItem(storageKey) === 'true';
                
                if (isCollapsed) {
                    const selector = config.subtaskSelector.replace('{id}', parentId);
                    const subtasks = document.querySelectorAll(selector);
                    
                    toggleIcon.textContent = config.iconCollapsed;
                    if (toggleIcon.setAttribute) {
                        toggleIcon.setAttribute('data-toggle-state', 'collapsed');
                    }
                    
                    subtasks.forEach(el => {
                        if (config.hideMethod === 'display') {
                            el.style.display = 'none';
                        } else if (config.hideMethod === 'class') {
                            el.classList.add('hidden');
                        }
                    });
                }
            });
        },
        
        /**
         * Setup event delegation globale per un contesto
         * @param {string} context - 'table', 'gantt', o 'kanban'
         */
        setupListeners(context = 'table') {
            const config = this.contexts[context];
            if (!config) return;
            
            // Event delegation su document
            document.addEventListener('click', (e) => {
                const icon = e.target.closest(`.${config.toggleIconClass}[data-parent-id]`);
                if (!icon) return;
                
                const label = e.target.closest('[data-has-subtasks]');
                const parentId = icon.getAttribute('data-parent-id') || 
                                label?.querySelector(`.${config.toggleIconClass}`)?.getAttribute('data-parent-id');
                
                if (parentId) {
                    e.stopPropagation();
                    this.toggle(parentId, context);
                }
            });
        }
    };
    
    // Esponi globalmente per retrocompatibilità
    window.toggleSubtasks = (parentId, context) => window.SubtasksManager.toggle(parentId, context);
    window.restoreSubtaskStates = (context) => window.SubtasksManager.restoreAll(context);
    
})();

