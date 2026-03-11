/**
 * KANBAN RENDERER - Modulo per rendering uniforme delle card kanban
 * 
 * Questo modulo garantisce che tutte le card kanban abbiano lo stesso HTML,
 * sia che vengano generate server-side (PHP) che client-side (JavaScript).
 */

window.KanbanRenderer = (function () {
    'use strict';

    /**
     * Helper per ottenere immagine utente (da UserManager o fallback)
     */
    function getUserImage(userId, fallbackUrl) {
        if (typeof window.UserManager !== 'undefined' && userId) {
            const img = window.UserManager.getImage(userId);
            if (img && img !== '/assets/images/default_profile.png') return img;
        }
        return fallbackUrl || '/assets/images/default_profile.png';
    }

    /**
     * Helper per ottenere nome utente (da UserManager o fallback)
     */
    function getUserName(userId, fallbackName) {
        if (typeof window.UserManager !== 'undefined' && userId) {
            const name = window.UserManager.getName(userId);
            if (name && name !== '—') return name;
        }
        return fallbackName || '—';
    }

    /**
     * Crea chip utente standard (icona + avatar + nome)
     */
    function renderUserChip(userId, userName, userImg, roleLabel, iconType = 'user') {
        const finalImg = getUserImage(userId, userImg);
        const finalName = getUserName(userId, userName);

        // Se non abbiamo né nome né ID, non mostriamo nulla
        if ((!finalName || finalName === '—') && (!userName)) return '';

        // Icona ruolo: 'user' (responsabile/assegnatario), 'two_users' (creato da)
        const roleIcon = iconType === 'two_users' ? 'assets/icons/two_users.png' : 'assets/icons/user.png';
        const roleText = roleLabel ? escapeHtml(roleLabel) : '';
        const tooltip = roleText ? `${roleText}: ${escapeHtml(finalName)}` : escapeHtml(finalName);

        // Stile del chip
        return `
            <div class="kanban-icon-wrap" data-tooltip="${tooltip}" style="display: flex; align-items: center; margin-top:2px;">
                 <img src="${roleIcon}" class="task-icon" alt="${roleLabel || 'User'}" style="margin-right: 4px; width:12px; opacity:0.7;">
                 <img src="${escapeHtml(finalImg)}" 
                      alt="${escapeHtml(finalName)}" 
                      class="profile-icon" 
                      style="width: 18px; height: 18px; border-radius: 50%; object-fit: cover; margin-right:4px; border:1px solid #eee;">
                 <span style="font-size:11px; color:#444;">${escapeHtml(finalName)}</span>
            </div>
        `;
    }

    /**
     * Renderizza una card kanban standard
     * @param {Object} task - Oggetto task con tutti i dati
     * @param {Object} options - Opzioni di rendering
     * @returns {HTMLElement} - Elemento DOM della card
     */
    function renderTask(task, options = {}) {
        const {
            tabella = 'default',
            kanbanType = 'generic',
            customRenderer = null
        } = options;

        // Se c'è un renderer custom, usalo
        if (typeof customRenderer === 'function') {
            const customHtml = customRenderer(task);
            const wrapper = document.createElement('div');
            wrapper.innerHTML = customHtml;
            const subtasksHtml = renderSubtasks(task, tabella);
            return {
                taskElement: wrapper.firstElementChild,
                subtasksHtml: subtasksHtml
            };
        }

        // Crea l'elemento task
        const taskEl = document.createElement('div');
        taskEl.className = 'task';
        taskEl.id = `task-${tabella}_${task.id}`;
        taskEl.setAttribute('data-task-id', task.id);
        taskEl.setAttribute('data-tabella', tabella);
        taskEl.setAttribute('data-creato-da', task.submitted_by || 0);
        taskEl.setAttribute('draggable', 'true');

        // Aggiungi attributi specifici per tipo
        if (kanbanType === 'segnalazioni') {
            taskEl.setAttribute('data-assegnato_a', task.assegnato_a || '');
            taskEl.setAttribute('data-responsabile', task.responsabile || '');
            taskEl.setAttribute('data-status-id', task.status_id || task.stato || 1);
            taskEl.setAttribute('data-id', `${task.table_name || tabella}_${task.id}`);
            taskEl.setAttribute('data-table', task.table_name || tabella);
            if (task.form_name) {
                taskEl.setAttribute('data-form-name', task.form_name);
            }

            // Bordo colorato per segnalazioni
            if (task.color) {
                const rgbaColor = hexToRgba(task.color, 1.0);
                taskEl.style.setProperty('border-left', `4px solid ${rgbaColor}`, 'important');
                taskEl.style.setProperty('border-radius', '6px', 'important');
            }
        } else if (kanbanType === 'mom') {
            // Bordo specifico per MOM se non è generic
            taskEl.style.setProperty('border-left', `4px solid #3498DB`, 'important');
        }

        // Renderizza il contenuto in base al tipo
        if (kanbanType === 'segnalazioni') {
            taskEl.innerHTML = renderSegnalazioneContent(task);
        } else if (kanbanType === 'commesse') {
            taskEl.innerHTML = renderCommesseContent(task);
        } else if (kanbanType === 'hr') {
            taskEl.innerHTML = renderHRContent(task);
        } else if (kanbanType === 'mom') {
            taskEl.innerHTML = renderMomContent(task);
        } else {
            taskEl.innerHTML = renderGenericContent(task);
        }

        // Aggiungi event listeners standard
        attachTaskEventListeners(taskEl, task, kanbanType);

        // Renderizza anche le subtasks come elementi separati
        const subtasksHtml = renderSubtasks(task, tabella);

        return {
            taskElement: taskEl,
            subtasksHtml: subtasksHtml
        };
    }

    /**
     * Crea chip con avatar sovrapposti per più utenti
     */
    function renderUsersOverlapHTML(users, roleLabel) {
        if (!Array.isArray(users) || users.length === 0) return '';
        
        let html = '<div class="kanban-avatars-overlap" style="display: flex; align-items: center; margin-left: 10px;">';
        const limit = 3;
        users.slice(0, limit).forEach((u, i) => {
            const name = u.name || u.nome || u.nominativo || u.label || '—';
            const img = u.img || '/assets/images/default_profile.png';
            html += `
                <div class="avatar-item" style="margin-left: -8px; position: relative; z-index: ${10 - i}; border-radius:50%;" data-tooltip="${roleLabel ? escapeHtml(roleLabel) + ': ' : ''}${escapeHtml(name)}">
                    <img src="${escapeHtml(img)}" 
                         alt="${escapeHtml(name)}" 
                         class="profile-icon" 
                         style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover; border: 1.5px solid #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                </div>
            `;
        });
        if (users.length > limit) {
            html += `<div style="margin-left: 4px; font-size: 10px; color: #777; font-weight: 600;">+${users.length - limit}</div>`;
        }
        html += '</div>';

        return `
            <div class="kanban-icon-wrap" style="display: flex; align-items: center; margin-top:4px;">
                 <img src="assets/icons/user.png" class="task-icon" alt="${roleLabel || 'Users'}" style="margin-right: 4px; width:12px; opacity:0.7;">
                 ${html}
            </div>
        `;
    }

    /**
     * Rendering specifico per segnalazioni
     */
    function renderSegnalazioneContent(task) {
        const codiceCompleto = task.codice_segnalazione || '-';
        const hasSubtasks = task.subtasks && Array.isArray(task.subtasks) && task.subtasks.length > 0;
        const subtasksCount = hasSubtasks ? task.subtasks.length : 0;

        return `
            <div class="task-header" style="display: flex; justify-content: space-between; align-items: flex-start; gap:6px; margin-bottom: 4px;">
                <div class="protocollo" style="font-size: 9px; color: #777; flex:1;">
                    ${escapeHtml(codiceCompleto)}
                </div>
                <div class="form-name" style="font-size: 10px; color: #2980b9; font-weight: 600; text-align: right;">
                    ${escapeHtml(task.form_name || 'Senza Nome')}
                </div>
            </div>
            ${task.titolo ? `<div class="argomentazione-field text-truncate" style="font-weight: 600; font-size: 13px; text-transform: capitalize; margin-bottom: 6px;">${escapeHtml(task.titolo)}</div>` : ''}
            <div class="task-body">
                <div class="date-field" style="font-size: 11px; margin-bottom: 6px;">
                    <img src="assets/icons/calendar.png" class="task-icon" alt="Scadenza">
                    ${escapeHtml(task.data_scadenza || '-')}
                </div>
                <div class="kanban-icons-col" style="display: flex; flex-direction: column; gap: 4px; align-items: flex-start;">
                    <!-- Responsabili (Multipli) -->
                    ${task.responsabili && Array.isArray(task.responsabili) && task.responsabili.length > 0 
                        ? renderUsersOverlapHTML(task.responsabili, 'Responsabili')
                        : renderUserChip(task.responsabile, task.responsabile_nome, task.responsabile_img, 'Responsabile', 'user')}
                    
                    <!-- Creato da -->
                    ${renderUserChip(task.creato_da, task.creato_da_nome, task.creato_da_img, 'Creato da', 'two_users')}
                </div>
            </div>
            ${hasSubtasks ? `
                <div class="kanban-subtasks-toggle" 
                     data-parent-id="${task.table_name || 'segnalazioni'}_${task.id}" 
                     data-tooltip="${subtasksCount} sottoattività">
                    <span class="toggle-icon">▼</span>
                    <span class="subtasks-count">${subtasksCount}</span>
                </div>
            ` : ''}
        `;
    }

    /**
     * Rendering specifico per Verbali (MOM)
     */
    function renderMomContent(task) {
        return `
            <div class="task-header" style="display:flex; justify-content:space-between; align-items:flex-start;">
                <div class="title-field" style="width:100%;">
                    <div style="font-size:0.75rem; color:#666; margin-bottom:2px;">
                        ${escapeHtml(task.protocollo || `MOM ${task.id}`)}
                    </div>
                    <strong>${escapeHtml(task.titolo || 'Senza titolo')}</strong>
                </div>
            </div>
            
            <div class="task-body">
                <div class="date-field">
                    <img src="assets/icons/calendar.png" class="task-icon" alt="Data" style="width:14px; vertical-align:middle;">
                    <span>${task.data_meeting ? formatDate(task.data_meeting) : '-'}</span>
                </div>
                
                <div class="description-field" style="margin-top:4px; font-size:0.9em;">
                   ${task.luogo ? `📍 ${escapeHtml(task.luogo)}` : ''}
                </div>
                
                 <div class="kanban-icons-col" style="margin-top:6px; display:flex; flex-direction:column; gap:4px;">
                    ${renderUserChip(task.creato_da, task.creato_da_nome, null, 'Creato da', 'two_users')}
                </div>
            </div>
        `;
    }

    /**
     * Rendering specifico per commesse
     */
    function renderCommesseContent(task) {
        return `
            <div class="task-header" style="display:flex;justify-content:space-between;align-items:center;">
                <div class="title-field text-truncate" style="flex:1 1 auto;">
                    <strong>${escapeHtml(task.titolo || '-')}</strong>
                </div>
                ${task.specializzazione ? `
                    <span class="kanban-discipline-badge"
                        data-tooltip="Disciplina: ${escapeHtml(task.specializzazione)}"
                        style="margin-left:12px;flex-shrink:0;">
                        ${escapeHtml(task.specializzazione)}
                    </span>
                ` : ''}
            </div>
            <div class="task-body">
                <div class="priority-field" style="margin-bottom: 4px;">
                    <img src="assets/icons/status.png"
                        class="task-icon"
                        alt="Priorità"
                        data-tooltip="Priorità: ${escapeHtml(task.priority || '-')}">
                    <span>${escapeHtml(task.priority || '-')}</span>
                </div>

                <div class="date-field" style="display:flex;gap:10px;font-size:11px;margin-bottom:5px;">
                    ${task.data_apertura ? `
                        <span>
                            <img src="assets/icons/calendar.png" class="task-icon" style="width:14px;vertical-align:-2px;" alt="Apertura" data-tooltip="Data apertura">
                            ${formatDate(task.data_apertura)}
                        </span>
                    ` : ''}
                    ${task.data_scadenza ? `
                        <span>
                            <img src="assets/icons/calendar.png" class="task-icon" style="width:14px;vertical-align:-2px;" alt="Scadenza" data-tooltip="Data scadenza">
                            ${formatDate(task.data_scadenza)}
                        </span>
                    ` : ''}
                </div>

                <div class="kanban-icons-col" style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                    ${renderUserChip(task.pm_id, task.pm_nome, task.pm_img, 'Project Manager', 'user')}
                    ${renderUserChip(task.creato_da, task.creatore_nome, task.img_creatore, 'Creato da', 'two_users')}
                    ${renderUserChip(task.assegnato_a, task.assegnato_a_nome, task.img_assegnato, 'Assegnato a', 'user')}
                </div>
            </div>
        `;
    }

    /**
     * Rendering specifico per HR
     */
    function renderHRContent(task) {
        return `
            <div class="candidate-info">
                <h3>${escapeHtml(task.titolo || task.name || '')}</h3>
                <p>${escapeHtml(task.descrizione || task.position_applied || '')}</p>
            </div>
        `;
    }

    /**
     * Rendering generico per altri tipi
     */
    function renderGenericContent(task) {
        return `
            <div class="task-header">
                <strong>${escapeHtml(task.titolo || 'Senza titolo')}</strong>
            </div>
            ${task.descrizione ? `
                <div class="task-body">
                    <div class="description-field">
                        ${escapeHtml(task.descrizione)}
                    </div>
                </div>
            ` : ''}
             <div class="kanban-icons-col" style="margin-top:5px; display: flex; flex-direction: column; gap: 4px;">
                  ${renderUserChip(task.creato_da, task.creato_da_nome, null, 'Creato da', 'two_users')}
                  ${renderUserChip(task.assegnato_a, task.assegnato_a_nome, null, 'Assegnato a', 'user')}
            </div>
        `;
    }

    /**
     * Allega event listeners standard alle card
     */
    function attachTaskEventListeners(taskEl, task, kanbanType) {
        // Gli event listeners vengono ora gestiti interamente via event delegation
        // nei rispettivi moduli di pagina (gestione_segnalazioni.js, mom.js, ecc.)
        // per evitare conflitti tra click singolo e doppio click.

        if (kanbanType === 'segnalazioni' && task.form_name && task.id) {
            taskEl.style.cursor = 'pointer';
        }
    }

    /**
     * Renderizza le subtasks come elementi separati (non dentro la task principale)
     * Restituisce un array di HTML strings, uno per ogni subtask
     */
    function renderSubtasks(task, tabella) {
        if (!task.subtasks || !Array.isArray(task.subtasks) || task.subtasks.length === 0) {
            return [];
        }

        // Colore della task principale per le subtasks (con leggera trasparenza)
        const taskColor = task.color || '#007bff';
        const taskColorRgba = hexToRgba(taskColor, 0.7);

        return task.subtasks.map(subtask => {
            // Decodifica scheda_data se è una stringa JSON
            let schedaData = null;
            if (subtask.scheda_data) {
                try {
                    schedaData = typeof subtask.scheda_data === 'string'
                        ? JSON.parse(subtask.scheda_data)
                        : subtask.scheda_data;
                } catch (e) {
                    console.warn('Errore parsing scheda_data:', e);
                }
            }

            // Renderizza i campi della scheda
            let schedaFieldsHtml = '';
            if (schedaData && typeof schedaData === 'object' && Object.keys(schedaData).length > 0) {
                schedaFieldsHtml = '<div class="kanban-subtask-fields">';
                for (const [fieldName, fieldValue] of Object.entries(schedaData)) {
                    if (fieldValue) {
                        const displayValue = Array.isArray(fieldValue)
                            ? fieldValue.join(', ')
                            : fieldValue;
                        schedaFieldsHtml += `
                            <div class="kanban-subtask-field">
                                <span class="kanban-subtask-field-name">${escapeHtml(fieldName)}:</span>
                                <span class="kanban-subtask-field-value">${escapeHtml(displayValue)}</span>
                            </div>
                        `;
                    }
                }
                schedaFieldsHtml += '</div>';
            }

            return `
                <div class="kanban-subtask collapsed" 
                    data-subtask-id="${subtask.id}"
                    data-parent-id="${task.table_name || tabella}_${task.id}"
                    data-tabella="${escapeHtml(tabella)}"
                    style="border-left-color: ${taskColorRgba};">
                    <div class="kanban-subtask-header">
                        ${subtask.scheda_label ? `
                            <div class="kanban-subtask-label">
                                📋 ${escapeHtml(subtask.scheda_label)}
                            </div>
                        ` : ''}
                        <span class="kanban-subtask-toggle-icon">▶</span>
                        
                        ${schedaFieldsHtml}
                    </div>
                    <div class="kanban-subtask-body">
                        ${renderUserChip(subtask.assegnato_a, subtask.assegnato_a_nome, subtask.img_assegnato, 'Assegnato a', 'two_users')}
                    </div>
                </div>
            `;
        });
    }

    // === UTILITY FUNCTIONS ===

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

    function hexToRgba(hex, alpha = 1) {
        if (!hex) return `rgba(204,204,204,${alpha})`;
        const bigint = parseInt(hex.replace('#', ''), 16);
        const r = (bigint >> 16) & 255;
        const g = (bigint >> 8) & 255;
        const b = bigint & 255;
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return dateStr;
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
    }

    // === PUBLIC API ===
    return {
        renderTask,
        renderSubtasks,
        escapeHtml,
        hexToRgba,
        formatDate
    };
})();

// Export per compatibilità
if (typeof module !== 'undefined' && module.exports) {
    module.exports = window.KanbanRenderer;
}
