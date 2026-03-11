/**
 * TASK DETAILS VIEW for GlobalRightDrawer
 * Supports both standard Tasks and Segnalazioni (Forms)
 */

(function () {
    'use strict';

    if (!window.GlobalDrawer) return;

    // === STATE ===
    let state = {
        currentTaskId: null,
        context: { type: 'task' }, // default
        taskData: null,
        debounceTimers: {},
        pasteListener: null
    };

    // === HTML TEMPLATES ===
    function getHtmlTemplate() {
        if (state.context.type === 'segnalazione') {
            return getSegnalazioneTemplate();
        }
        return getTaskTemplate();
    }

    function getTaskTemplate() {
        return `
            <div class="task-details-tabs">
                <button class="task-details-tab active" data-tab="details">Dettagli</button>
                <button class="task-details-tab" data-tab="checklist">Checklist</button>
                <button class="task-details-tab" data-tab="activity">Attività</button>
            </div>
            
            <div class="task-details-tab-content" data-tab-content="details">
                <div class="task-details-section-title">Dati generali</div>
                
                <div class="task-details-field">
                    <label>Titolo</label>
                    <input type="text" class="task-details-input" id="task-details-title" placeholder="Titolo task" required>
                </div>
                
                <div class="task-details-field">
                    <label>Creato da</label>
                    <input type="text" class="task-details-input" id="task-details-creator" disabled>
                </div>
                
                <div class="task-details-field">
                    <label>Stato</label>
                    <select class="task-details-select" id="task-details-status" required>
                        <option value="">Caricamento...</option>
                    </select>
                </div>
                
                <div class="task-details-field">
                    <label>Disciplina</label>
                    <select class="task-details-select" id="task-details-specializzazione" required>
                        <option value="">—</option>
                        <option value="GEN">GEN</option>
                        <option value="ARC">ARC</option>
                        <option value="CIV">CIV</option>
                        <option value="STR">STR</option>
                        <option value="ELE">ELE</option>
                        <option value="MEC">MEC</option>
                        <option value="VVF">VVF</option>
                        <option value="SIC">SIC</option>
                    </select>
                </div>
                
                <div class="task-details-field">
                    <label>Fase</label>
                    <select class="task-details-select" id="task-details-fase-doc">
                        <option value="">—</option>
                        <option value="PFTE">PFTE</option>
                        <option value="DEFINITIVO">DEFINITIVO</option>
                        <option value="ESECUTIVO">ESECUTIVO</option>
                        <option value="DIR. LAVORI">DIR. LAVORI</option>
                        <option value="OFFERTA">OFFERTA</option>
                        <option value="GARA">GARA</option>
                    </select>
                </div>
                
                <div class="task-details-field">
                    <label>Priorità</label>
                    <select class="task-details-select" id="task-details-priority" required>
                        <option value="Bassa">Bassa</option>
                        <option value="Media">Media</option>
                        <option value="Alta">Alta</option>
                        <option value="Critica">Critica</option>
                    </select>
                </div>
                
                <div class="task-details-section-title">Assegnazione e Scadenze</div>
                
                <div class="task-details-field">
                    <label>Assegna a</label>
                    <select class="task-details-select" id="task-details-assignee">
                        <option value="">—</option>
                    </select>
                </div>
                
                <div class="task-details-field">
                    <label>Data Scadenza</label>
                    <input type="date" class="task-details-input" id="task-details-due-date">
                </div>
                
                <div class="task-details-field">
                    <label>Data apertura</label>
                    <input type="date" class="task-details-input" id="task-details-data-apertura" disabled>
                </div>
                
                <div class="task-details-field">
                    <label>Data chiusura</label>
                    <input type="date" class="task-details-input" id="task-details-data-chiusura" disabled>
                </div>
                
                <div class="task-details-section-title">Dettagli</div>
                
                <div class="task-details-field" style="grid-column: span 2;">
                    <label>Descrizione azione</label>
                    <textarea class="task-details-textarea" id="task-details-descrizione-azione" placeholder="Descrizione azione dettagliata" rows="4"></textarea>
                </div>
                
                <div class="task-details-field" style="grid-column: span 2;">
                    <label>Percorso documento</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="text" class="task-details-input" id="task-details-path-allegato" placeholder="Es: \\server\\documenti\\commessa123" style="flex: 1;">
                        <img src="assets/icons/copy.png" alt="Copia percorso" id="task-details-copy-path" title="Copia negli appunti" style="cursor: pointer; width: 20px; height: 20px; opacity: 0.7;">
                    </div>
                </div>
                
                <div class="task-details-field" style="grid-column: span 2;">
                    <label>Screenshot (immagini)</label>
                    <div id="task-details-image-preview" style="margin-bottom: 10px; display: flex; flex-wrap: wrap; gap: 8px;"></div>
                    <div class="dropzone-upload" id="task-details-dropzone">
                        <div class="upload-preview"></div>
                        <div class="dropzone-helper-text">Trascina qui, clicca o incolla uno screenshot (CTRL+V)</div>
                        <input type="file" name="screenshots" accept="image/jpeg,image/png" style="display:none;" multiple>
                        <button type="button" class="upload-remove-btn" style="display:none;">Rimuovi immagine</button>
                        <div class="upload-info">Formati accettati: JPG, PNG. Max 5MB.</div>
                    </div>
                </div>
                
                <div class="task-details-field" style="grid-column: span 2; text-align: right; margin-top: 16px;">
                    <button type="button" class="task-details-save-btn" id="task-details-save-btn">Salva</button>
                </div>
            </div>
            
            <div class="task-details-tab-content" data-tab-content="checklist" style="display: none;">
                <div class="task-details-checklist">
                    <div class="checklist-header">
                        <h3>Checklist</h3>
                        <div class="checklist-progress" id="checklist-progress">0/0</div>
                    </div>
                    <div class="checklist-items" id="checklist-items"></div>
                    <div class="checklist-add">
                        <input type="text" class="checklist-add-input" id="checklist-add-input" placeholder="Aggiungi elemento...">
                        <button class="checklist-add-btn" id="checklist-add-btn">Aggiungi</button>
                    </div>
                </div>
            </div>
            
            <div class="task-details-tab-content" data-tab-content="activity" style="display: none;">
                <div class="task-details-activity" id="task-details-activity">
                    <div class="activity-loading">Caricamento attività...</div>
                </div>
            </div>
        `;
    }

    function getSegnalazioneTemplate() {
        return `
            <div class="task-details-tabs">
                <button class="task-details-tab active" data-tab="details">Dettagli</button>
                <button class="task-details-tab" data-tab="checklist">Checklist</button>
            </div>
            
            <div class="task-details-tab-content" data-tab-content="details">
                <div class="task-details-section-title">Dati Segnalazione</div>
                
                <div class="task-details-field">
                    <label>Protocollo</label>
                    <input type="text" class="task-details-input" id="task-details-protocollo" disabled style="background-color: #f8f9fa; font-weight: bold;">
                </div>

                <div class="task-details-field">
                    <label>Stato</label>
                    <select class="task-details-select" id="task-details-status" required>
                        <option value="">Caricamento...</option>
                    </select>
                </div>
                
                <div class="task-details-field" style="grid-column: span 2;">
                    <label>Titolo</label>
                    <input type="text" class="task-details-input" id="task-details-titolo" placeholder="Titolo segnalazione">
                </div>

                <div class="task-details-field">
                    <label>Priorità</label>
                    <select class="task-details-select" id="task-details-priority" required>
                        <option value="Bassa">Bassa</option>
                        <option value="Media">Media</option>
                        <option value="Alta">Alta</option>
                        <option value="Critica">Critica</option>
                    </select>
                </div>

                 <div class="task-details-field">
                    <label>Scadenza</label>
                    <input type="date" class="task-details-input" id="task-details-deadline">
                </div>

                <div class="task-details-section-title">Persone</div>

                 <div class="task-details-field">
                    <label>Richiedente</label>
                     <div id="task-details-creato-da-container" style="display: flex; align-items: center; min-height: 38px;"></div>
                </div>
                
                 <div class="task-details-field">
                    <label>Responsabile</label>
                     <div id="task-details-responsabile-container" style="display: flex; align-items: center; min-height: 38px;"></div>
                </div>

                <div class="task-details-field" style="grid-column: span 2;">
                    <label>Assegnato a</label>
                    <div id="task-details-assignee-container" style="display: flex; flex-wrap: wrap; gap: 5px; min-height: 38px; align-items: center;"></div>
                    <!-- Hidden select for compatibility if needed, or just use the container for display -->
                </div>

                <div class="task-details-section-title">Descrizione</div>
                
                <div class="task-details-field" style="grid-column: span 2;">
                    <label>Descrizione dettagliata</label>
                    <textarea class="task-details-textarea" id="task-details-descrizione" placeholder="Dettagli..." rows="6"></textarea>
                </div>
                
                 <div class="task-details-field" style="grid-column: span 2; text-align: right; margin-top: 16px;">
                    <button type="button" class="task-details-save-btn" id="task-details-save-btn">Salva</button>
                    <button type="button" class="task-details-save-btn" id="task-details-open-full" style="background-color: #6c757d; margin-right: 10px;">Apri Scheda Completa</button>
                </div>
            </div>

            <div class="task-details-tab-content" data-tab-content="checklist" style="display: none;">
                 <div class="task-details-checklist">
                    <div class="checklist-header">
                        <h3>Checklist</h3>
                        <div class="checklist-progress" id="checklist-progress">0/0</div>
                    </div>
                    <div class="checklist-items" id="checklist-items"></div>
                    <div class="checklist-add">
                        <input type="text" class="checklist-add-input" id="checklist-add-input" placeholder="Aggiungi elemento...">
                        <button class="checklist-add-btn" id="checklist-add-btn">Aggiungi</button>
                    </div>
                </div>
            </div>
        `;
    }

    // === REGISTER VIEW ===
    window.GlobalDrawer.registerView("taskDetails", (payload) => {
        let taskId, context;
        // Handle payload: can be just ID (legacy) or object { taskId, context }
        if (typeof payload === 'object' && payload !== null && (payload.taskId || payload.id)) {
            taskId = payload.taskId || payload.id;
            context = payload.context;
        } else {
            taskId = payload;
            context = null;
        }

        state.currentTaskId = taskId;
        state.context = context || { type: 'task' };

        return {
            title: state.context.type === 'segnalazione' ? 'Dettagli Segnalazione' : 'Dettagli Task',
            html: getHtmlTemplate(),
            onReady: (container) => {
                setupEventListeners(container);
                if (state.context.type === 'segnalazione') {
                    loadSegnalazioneData(taskId, state.context.formName, container);
                } else {
                    loadTaskData(taskId, container);
                }
            },
            onClose: () => {
                flushDebounce();
                state.currentTaskId = null;
                state.taskData = null;
                if (state.pasteListener) {
                    document.removeEventListener('paste', state.pasteListener);
                }
            }
        };
    });

    // === PRIVATE LOGIC ===
    function setupEventListeners(container) {
        if (container.getAttribute('data-listeners-attached') === 'true') return;
        container.setAttribute('data-listeners-attached', 'true');

        // Tab switching
        const tabs = container.querySelectorAll('.task-details-tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabName = tab.getAttribute('data-tab');
                switchTab(tabName, container);
            });
        });

        const find = (id) => container.querySelector(id);

        if (state.context.type === 'segnalazione') {
            setupSegnalazioneListeners(container, find);
        } else {
            setupTaskListeners(container, find);
        }

        // Common listeners
        const saveBtn = find('#task-details-save-btn');
        if (saveBtn) saveBtn.addEventListener('click', () => saveAllFields(container));

        // Checklist logic is shared
        setupChecklistListeners(container, find);
    }

    function setupTaskListeners(container, find) {
        // Inputs
        const titleInput = find('#task-details-title');
        if (titleInput) {
            titleInput.addEventListener('input', () => debounceSave('title', () => saveField('title', titleInput.value)));
        }

        const statusSelect = find('#task-details-status');
        if (statusSelect) {
            statusSelect.addEventListener('change', () => saveField('statusId', parseInt(statusSelect.value, 10)));
        }

        const prioritySelect = find('#task-details-priority');
        if (prioritySelect) {
            prioritySelect.addEventListener('change', () => {
                const priorityMap = { 'Bassa': 0, 'Media': 1, 'Alta': 2, 'Critica': 3 };
                saveField('priority', priorityMap[prioritySelect.value] || 1);
            });
        }

        const dueDateInput = find('#task-details-due-date');
        if (dueDateInput) {
            dueDateInput.addEventListener('change', () => saveField('dueDate', dueDateInput.value));
        }

        const assigneeSelect = find('#task-details-assignee');
        if (assigneeSelect) {
            assigneeSelect.addEventListener('change', () => {
                const assigneeId = assigneeSelect.value ? parseInt(assigneeSelect.value, 10) : null;
                saveField('assigneeUserId', assigneeId);
            });
        }

        setupDropzone(container);
    }

    function setupSegnalazioneListeners(container, find) {
        const openFullBtn = find('#task-details-open-full');
        if (openFullBtn) {
            openFullBtn.addEventListener('click', () => {
                const formName = state.context.formName || state.taskData?.form_name;
                const id = state.currentTaskId;
                if (formName && id) {
                    window.location.href = `index.php?section=collaborazione&page=form_viewer&form_name=${formName}&id=${id}`;
                }
            });
        }

        // Auto-save listeners
        const titoloInp = find('#task-details-titolo');
        if (titoloInp) titoloInp.addEventListener('input', () => debounceSave('titolo', () => saveField('titolo', titoloInp.value)));

        const descText = find('#task-details-descrizione');
        if (descText) descText.addEventListener('input', () => debounceSave('descrizione', () => saveField('descrizione', descText.value)));

        const prioritySel = find('#task-details-priority');
        if (prioritySel) prioritySel.addEventListener('change', () => saveField('priority', prioritySel.value));

        const deadlineInp = find('#task-details-deadline');
        if (deadlineInp) deadlineInp.addEventListener('change', () => saveField('deadline', deadlineInp.value));

        const statusSel = find('#task-details-status');
        if (statusSel) statusSel.addEventListener('change', () => saveField('status_id', statusSel.value));
    }

    function setupChecklistListeners(container, find) {
        const checklistAddInput = find('#checklist-add-input');
        const checklistAddBtn = find('#checklist-add-btn');
        if (checklistAddInput && checklistAddBtn) {
            checklistAddBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                const label = checklistAddInput.value.trim();
                if (!label) return;

                checklistAddBtn.disabled = true;
                try {
                    const entityType = state.context.type === 'segnalazione' ? 'segnalazione' : 'task';

                    const res = await window.customFetch('checklists', 'add', {
                        entityType: entityType,
                        entityId: state.currentTaskId, // ID logic assumes checklists table supports different entityTypes with same ID space or combined.
                        // Assuming 'segnalazione' uses forms table ID.
                        label
                    });
                    if (res.success) {
                        checklistAddInput.value = '';
                        loadChecklist(container);
                    }
                } catch (e) { console.error(e); }
                checklistAddBtn.disabled = false;
            });
            checklistAddInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    checklistAddBtn.click();
                }
            });
        }
    }

    function switchTab(tabName, container) {
        const tabs = container.querySelectorAll('.task-details-tab');
        tabs.forEach(tab => tab.classList.toggle('active', tab.getAttribute('data-tab') === tabName));

        const contents = container.querySelectorAll('.task-details-tab-content');
        contents.forEach(content => content.style.display = content.getAttribute('data-tab-content') === tabName ? 'block' : 'none');

        if (tabName === 'activity' && state.currentTaskId && state.context.type === 'task') loadActivity(container);
    }

    // === TASK LOADING ===
    async function loadTaskData(taskId, container) {
        if (!window.TaskApi) return;
        try {
            const taskData = await window.TaskApi.getTaskDetails(taskId);
            if (taskData) {
                state.taskData = taskData;
                state.context.checklistType = 'task';
                renderTaskData(taskData, container);
            }
        } catch (error) { console.error('Errore caricamento task:', error); }
    }

    // === SEGNALAZIONE LOADING ===
    async function loadSegnalazioneData(id, formName, container) {
        try {
            // Proattivamente carica gli utenti se UserManager è vuoto o incompleto
            // Questo aiuta a risolvere i nomi/avatar nel renderUserWidget
            await ensureUsersLoaded();

            // Fetch entry data
            const entryPromise = window.customFetch('forms', 'getFormEntry', { form_name: formName, record_id: id });

            const entryRes = await entryPromise;

            if (entryRes && entryRes.success) {
                state.taskData = entryRes.data;
                // Add meta info like responsabile from root to data for easier access
                state.taskData.responsabile_form = entryRes.responsabile;
                state.taskData.form_name = formName; // Ensure form_name is available

                state.context.checklistType = 'segnalazione';
                renderSegnalazioneData(entryRes, container);
            } else {
                console.error('Errore caricamento dati segnalazione:', entryRes);
                if (typeof window.showToast === 'function') window.showToast('Errore caricamento dati', 'error');
            }
        } catch (error) { console.error('Errore caricamento segnalazione:', error); }
    }

    async function ensureUsersLoaded() {
        if (window.UserManager && Object.keys(window.UserManager._cache || {}).length > 5) return;

        try {
            const res = await window.customFetch('user', 'getAllMinified');
            if (res.success && Array.isArray(res.data)) {
                window.UserManager.populate(res.data);
            }
        } catch (e) { console.warn('UserManager preload failed', e); }
    }

    async function renderTaskData(taskData, container) {
        const find = (id) => container.querySelector(id);
        if (find('#task-details-title')) find('#task-details-title').value = taskData.title || '';
        if (find('#task-details-creator')) find('#task-details-creator').value = taskData.creatorNome || 'Utente sconosciuto';

        const statusSelect = find('#task-details-status');
        if (statusSelect && window.TaskBoard) {
            const statuses = window.TaskBoard.getStatuses();
            statusSelect.innerHTML = '<option value="">Seleziona stato</option>' +
                statuses.map(s => `<option value="${s.id}" ${taskData.statusId == s.id ? 'selected' : ''}>${s.name}</option>`).join('');
        }

        if (find('#task-details-specializzazione')) find('#task-details-specializzazione').value = taskData.specializzazione || '';
        if (find('#task-details-fase-doc')) find('#task-details-fase-doc').value = taskData.faseDoc || '';

        const prioritySelect = find('#task-details-priority');
        if (prioritySelect) {
            const priorityMap = { 0: 'Bassa', 1: 'Media', 2: 'Alta', 3: 'Critica' };
            prioritySelect.value = priorityMap[taskData.priority] || 'Media';
        }

        if (find('#task-details-due-date') && taskData.dueDate) find('#task-details-due-date').value = taskData.dueDate.split(' ')[0];
        if (find('#task-details-data-apertura') && taskData.createdAt) find('#task-details-data-apertura').value = taskData.createdAt.split(' ')[0];
        if (find('#task-details-data-chiusura') && taskData.dataChiusura) find('#task-details-data-chiusura').value = taskData.dataChiusura.split(' ')[0];

        await populateAssigneeSelect(taskData.assigneeUserId, container);
        if (find('#task-details-descrizione-azione')) find('#task-details-descrizione-azione').value = taskData.descrizioneAzione || '';
        if (find('#task-details-path-allegato')) find('#task-details-path-allegato').value = taskData.pathAllegato || '';

        renderScreenshots(container, taskData.screenshot || '');
        loadChecklist(container);
    }

    // Helper per renderizzare utente con avatar
    function renderUserWidget(target, userId) {
        if (!target) return;

        target.innerHTML = '';
        if (!userId) {
            target.innerHTML = '<span style="color:#999;font-style:italic;">—</span>';
            return;
        }

        const um = window.UserManager;
        // Usa le API di UserManager per fallbacks corretti
        const name = um ? um.getName(userId) : 'Utente ' + userId;
        let img = um ? um.getImage(userId) : 'assets/images/default_profile.png';

        // Fix path se necessario (alcuni ritornano path relativi diversi)
        if (img && !img.startsWith('http') && !img.startsWith('/')) {
            // img = '/' + img; // Opzionale, dipende dal setup
        }

        const el = document.createElement('div');
        el.className = 'user-widget-mini';
        el.style.display = 'flex';
        el.style.alignItems = 'center';
        el.style.gap = '8px';
        el.style.background = '#f1f3f5';
        el.style.padding = '2px 8px';
        el.style.borderRadius = '16px';
        el.style.fontSize = '0.9em';

        const imgEl = document.createElement('img');
        imgEl.src = img;
        imgEl.style.width = '24px';
        imgEl.style.height = '24px';
        imgEl.style.borderRadius = '50%';
        imgEl.style.objectFit = 'cover';
        imgEl.style.border = '1px solid #dee2e6';

        const span = document.createElement('span');
        span.textContent = name === '—' ? 'Utente ' + userId : name;
        span.style.whiteSpace = 'nowrap';

        el.appendChild(imgEl);
        el.appendChild(span);
        target.appendChild(el);
    }

    async function renderSegnalazioneData(res, container) {
        const data = res.data || {};
        const find = (id) => container.querySelector(id);

        if (find('#task-details-protocollo')) find('#task-details-protocollo').value = data.codice_segnalazione || data.id || '-';
        if (find('#task-details-titolo')) find('#task-details-titolo').value = data.titolo || '';
        if (find('#task-details-descrizione')) find('#task-details-descrizione').value = data.descrizione || '';

        // Status Select
        const statusSelect = find('#task-details-status');
        if (statusSelect) {
            let options = '';
            // Usa STATI_MAP globale se disponibile
            if (window.STATI_MAP) {
                options = Object.entries(window.STATI_MAP).map(([id, label]) =>
                    `<option value="${id}" ${data.status_id == id ? 'selected' : ''}>${label}</option>`
                ).join('');
            } else {
                // Fallback
                const defaults = { 1: 'Aperta', 2: 'In corso', 3: 'Chiusa', 4: 'Annullata', 5: 'Sospesa' };
                options = Object.entries(defaults).map(([k, v]) => `<option value="${k}" ${data.status_id == k ? 'selected' : ''}>${v}</option>`).join('');
            }
            statusSelect.innerHTML = options;
        }

        if (find('#task-details-priority')) find('#task-details-priority').value = (data.priority || 'Media');
        if (find('#task-details-deadline')) find('#task-details-deadline').value = data.deadline_raw || '';

        // POPOLA UTENTI CON AVATAR
        // Richiedente
        renderUserWidget(find('#task-details-creato-da-container'), data.submitted_by);

        // Responsabile
        renderUserWidget(find('#task-details-responsabile-container'), res.responsabile);

        // Assegnato A (multipli)
        const assigneeContainer = find('#task-details-assignee-container');
        if (assigneeContainer) {
            assigneeContainer.innerHTML = '';
            if (data.assegnato_a) {
                const ids = String(data.assegnato_a).split(',').map(s => s.trim()).filter(Boolean);
                if (ids.length > 0) {
                    ids.forEach(uid => {
                        const wrap = document.createElement('div');
                        wrap.style.display = 'inline-block';
                        wrap.style.marginRight = '5px';
                        assigneeContainer.appendChild(wrap);
                        renderUserWidget(wrap, uid);
                    });
                } else {
                    assigneeContainer.innerHTML = '<span style="color:#999;font-style:italic;">—</span>';
                }
            } else {
                assigneeContainer.innerHTML = '<span style="color:#999;font-style:italic;">—</span>';
            }
        }

        loadChecklist(container);
    }


    function renderScreenshots(container, screenshotStr) {
        const preview = container.querySelector('#task-details-image-preview');
        if (!preview) return;
        preview.innerHTML = (screenshotStr || '').split(',').filter(s => s.trim()).map(path => {
            return `<img src="${path.trim()}" style="max-width:120px; max-height:90px; border-radius:5px; cursor:pointer;" onclick="window.showImageModal ? showImageModal('${path.trim()}') : window.open('${path.trim()}', '_blank')">`;
        }).join('');
    }

    function setupDropzone(container) {
        const dropzone = container.querySelector('#task-details-dropzone');
        if (!dropzone) return;
        const fileInput = dropzone.querySelector('input[type="file"]');

        dropzone.onclick = (e) => { if (e.target.tagName !== 'INPUT') fileInput.click(); };
        fileInput.onchange = (e) => { if (e.target.files.length) handleFileUpload(container, e.target.files[0]); };

        const pasteHandler = (e) => {
            if (!state.currentTaskId) return;
            const items = (e.clipboardData || e.originalEvent.clipboardData).items;
            for (let item of items) {
                if (item.type.indexOf('image') !== -1) handleFileUpload(container, item.getAsFile());
            }
        };
        state.pasteListener = pasteHandler;
        document.addEventListener('paste', pasteHandler);
    }

    async function handleFileUpload(container, file) {
        const formData = new FormData();
        formData.append('taskId', state.currentTaskId);
        formData.append('screenshots[]', file);
        await window.TaskApi.updateTask(state.currentTaskId, formData);
        loadTaskData(state.currentTaskId, container);
    }

    async function loadChecklist(container) {
        if (!state.currentTaskId) return;

        try {
            const entityType = state.context.checklistType || 'task';

            const res = await window.customFetch('checklists', 'list', {
                entityType: entityType,
                entityId: state.currentTaskId
            });

            if (res.success) {
                renderChecklist(container, res.data);
            }
        } catch (e) { console.error(e); }
    }

    function renderChecklist(container, items) {
        const itemsContainer = container.querySelector('#checklist-items');
        if (!itemsContainer) return;

        itemsContainer.innerHTML = '';
        const list = items || [];

        list.forEach(item => {
            const div = document.createElement('div');
            div.className = 'checklist-item';
            div.setAttribute('data-id', item.id);
            div.innerHTML = `
                <input type="checkbox" class="checklist-item-checkbox" ${item.is_done ? 'checked' : ''}>
                <span class="checklist-item-text" style="${item.is_done ? 'text-decoration: line-through; opacity: 0.7;' : ''}">${item.label}</span>
                <button class="checklist-item-delete" title="Elimina">×</button>
            `;

            const checkbox = div.querySelector('.checklist-item-checkbox');
            checkbox.onchange = async () => {
                const res = await window.customFetch('checklists', 'toggle', { id: item.id });
                if (res.success) loadChecklist(container);
                else checkbox.checked = !checkbox.checked; // Revert on error
            };

            const delBtn = div.querySelector('.checklist-item-delete');
            delBtn.onclick = async () => {
                if (!confirm('Eliminare voce?')) return;
                const res = await window.customFetch('checklists', 'delete', { id: item.id });
                if (res.success) loadChecklist(container);
            };

            itemsContainer.appendChild(div);
        });

        const progress = container.querySelector('#checklist-progress');
        const done = list.filter(i => i.is_done).length;
        if (progress) progress.textContent = `${done}/${list.length}`;
    }

    async function loadActivity(container) {
        const activityContainer = container.querySelector('#task-details-activity');
        try {
            const activities = await window.TaskApi.loadActivity(state.currentTaskId);
            activityContainer.innerHTML = activities.length ? activities.map(a => `
                <div class="activity-item">
                    <div class="activity-type">${a.type}</div>
                    <div class="activity-user">${a.userNome}</div>
                    <div class="activity-date">${new Date(a.createdAt).toLocaleString()}</div>
                </div>
            `).join('') : '<div class="activity-empty">Nessuna attività recente.</div>';
        } catch (e) { activityContainer.innerHTML = 'Errore caricamento.'; }
    }

    async function saveField(field, value) {
        if (!state.currentTaskId) return;
        if (state.context.type === 'segnalazione') {
            const formData = new FormData();
            formData.append('form_name', state.context.formName || state.taskData?.form_name);
            formData.append('record_id', state.currentTaskId);
            formData.append(field, value);

            // Handle status_id separately if it's the specific status field
            if (field === 'status_id') {
                return window.customFetch('forms', 'updateFormStatus', {
                    form_name: state.context.formName || state.taskData?.form_name,
                    record_id: state.currentTaskId,
                    status_id: value
                });
            }

            return window.customFetch('forms', 'update', formData);
        }
        await window.TaskApi.updateTask(state.currentTaskId, { [field]: value });
    }

    async function saveAllFields(container) {
        if (state.context.type === 'segnalazione') {
            await saveSegnalazioneFields(container);
        } else {
            await saveTaskFields(container);
        }
    }

    async function saveTaskFields(container) {
        const data = {
            title: container.querySelector('#task-details-title').value,
            statusId: container.querySelector('#task-details-status').value,
            specializzazione: container.querySelector('#task-details-specializzazione').value,
            faseDoc: container.querySelector('#task-details-fase-doc').value,
            descrizioneAzione: container.querySelector('#task-details-descrizione-azione').value,
            pathAllegato: container.querySelector('#task-details-path-allegato').value
        };
        await window.TaskApi.updateTask(state.currentTaskId, data);
        if (window.showToast) window.showToast('Salvato', 'success');
    }

    async function saveSegnalazioneFields(container) {
        try {
            const titolo = container.querySelector('#task-details-titolo').value;
            const descrizione = container.querySelector('#task-details-descrizione').value;
            const priority = container.querySelector('#task-details-priority').value;
            const deadline = container.querySelector('#task-details-deadline').value;
            const statusId = container.querySelector('#task-details-status').value;

            const formName = state.context.formName || state.taskData?.form_name;

            const formData = new FormData();
            formData.append('form_name', formName);
            formData.append('record_id', state.currentTaskId);
            formData.append('titolo', titolo);
            formData.append('descrizione', descrizione);
            formData.append('priority', priority);
            formData.append('deadline', deadline);
            formData.append('status_id', statusId);

            const res = await window.customFetch('forms', 'update', formData);

            // Also update status specifically if needed for triggers
            await window.customFetch('forms', 'updateFormStatus', {
                form_name: formName,
                record_id: state.currentTaskId,
                status_id: statusId
            });

            if (res.success) {
                if (window.showToast) window.showToast('Segnalazione salvata', 'success');
            } else {
                if (window.showToast) window.showToast('Errore salvataggio: ' + (res.message || ''), 'error');
            }
        } catch (e) {
            console.error(e);
            if (window.showToast) window.showToast('Errore di rete', 'error');
        }
    }

    async function populateAssigneeSelect(currentAssigneeId, container) {
        const assigneeSelect = container.querySelector('#task-details-assignee');

        let utenti = [];
        try {
            const res = await window.customFetch('user', 'getAllMinified');
            if (res.success && Array.isArray(res.data)) {
                utenti = res.data;
                // Popola UserManager per garantire che nomi/avatar siano disponibili ovunque
                if (window.UserManager) window.UserManager.populate(utenti);
            }
        } catch (e) { console.warn(e); }

        if (assigneeSelect) {
            assigneeSelect.innerHTML = '<option value="">—</option>' +
                utenti.map(u => `<option value="${u.id}" ${currentAssigneeId == u.id ? 'selected' : ''}>${u.nominativo || u.Nominativo || u.nome_completo || u.username || u.name || 'Utente'}</option>`).join('');
        }

        return utenti;
    }

    function debounceSave(key, fn) {
        if (state.debounceTimers[key]) clearTimeout(state.debounceTimers[key]);
        state.debounceTimers[key] = setTimeout(fn, 1000);
    }

    function flushDebounce() {
        Object.values(state.debounceTimers).forEach(clearTimeout);
        state.debounceTimers = {};
    }

    // === PUBLIC API (BACKWARD COMPATIBILITY) ===
    window.TaskDetails = {
        open: (taskId, context) => window.GlobalDrawer.openView("taskDetails", { taskId, context }),
        close: () => window.GlobalDrawer.close()
    };

})();
