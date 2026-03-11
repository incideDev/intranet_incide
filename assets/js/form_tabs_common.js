/**
 * Funzioni comuni per gestione schede/tab nei form
 * Utilizzate da view_form.php e form_viewer.php
 *
 * MAPPA STRUTTURA SCHEDE (FASE 1 - Documentazione)
 * 
 * DOVE VIVONO I META DELLE SCHEDE (lato client):
 * - Vengono caricati da PageEditorService::getForm() via customFetch
 * - Struttura: { tabs: { "NomeScheda": { fields: [...], visibility_roles: [...], edit_roles: [...] } } }
 * - I meta sono già decodificati dal JSON in forms.tabs_config
 * 
 * COME VIENE DECISA VISIBILITÀ E MODIFICABILITÀ (lato client):
 * - calculatePageVisibilityJS() (questa funzione) calcola visibilità/editabilità per ogni scheda
 * - processTabsVisibilityJS() filtra le schede non visibili e aggiunge flag __visibility
 * - view_form.php e form_viewer.php usano processTabsVisibilityJS() per filtrare schede
 * 
 * COSTANTI TIPI CONDIZIONE (devono corrispondere a PageEditorService.php)
 */
const WORKFLOW_CONDITIONS = {
    ALWAYS: 'always',
    AFTER_STEP_SAVED: 'after_step_saved',
    AFTER_STEP_SUBMITTED: 'after_step_submitted',
    AFTER_ALL_PREVIOUS_SUBMITTED: 'after_all_previous_submitted'
};

/**
 * Calcola se una scheda è visibile e/o disabilitata
 * 
 * FUNZIONE CENTRALE PER VISIBILITÀ E MODIFICABILITÀ (FASE 2 - MVP)
 * 
 * Implementa la stessa logica di PageEditorService::calculateSchedaVisibility() lato PHP.
 * Logica MVP:
 * - Admin: vede e modifica sempre tutto
 * - Utente normale: vede solo schede "utente", editabili prima del submit
 * - Responsabile/Assegnatario: vede tutte le schede, modifica solo quelle "responsabile"
 * 
 * @param {Object} page - Configurazione della scheda (da tabs_config)
 * @param {Object} visibilityData - Dati utente e form {currentUserId, currentRoleId, formResponsabileId, formAssegnatariIds, isNewRecord, recordSubmittedBy}
 * @param {Object} context - Contesto aggiuntivo {schedeStatus, allTabs, currentTabKey}
 * @returns {Object} { visible: boolean, disabled: boolean, editable: boolean, reason: string }
 */
function calculatePageVisibilityJS(page, visibilityData, context = {}) {
    const currentUserId = visibilityData.currentUserId || 0;
    const currentRoleId = visibilityData.currentRoleId || 0;
    const formResponsabileId = visibilityData.formResponsabileId || 0;
    const formAssegnatariIds = visibilityData.formAssegnatariIds || [];
    const isNewRecord = visibilityData.isNewRecord !== false;

    const schedeStatus = context.schedeStatus || {};
    const allTabs = context.allTabs || {};
    const currentTabKey = (context.currentTabKey || '').toLowerCase();

    // Dichiarazione variabili ruolo (necessarie per la logica di chiusura)
    const isAdmin = (currentRoleId === 1);
    const isResponsabile = (String(currentUserId) === String(formResponsabileId));
    const isAssegnatario = Array.isArray(formAssegnatariIds) && formAssegnatariIds.some(id => String(id) === String(currentUserId));
    const isCreator = isNewRecord || (String(currentUserId) === String(visibilityData.recordSubmittedBy));

    // 1) GESTIONE SPECIALE SCHEDA CHIUSURA (ESITO)
    const isClosure = (page.scheda_type === 'chiusura' || page.isClosureTab);
    if (isClosure) {
        const thisStatus = (schedeStatus[currentTabKey]?.status || '').toLowerCase();
        const canManage = (isAdmin || isResponsabile || isAssegnatario);

        // 1a. Se già submitted → visibile a tutti in sola lettura
        if (thisStatus === 'submitted') {
            return { visible: true, disabled: false, editable: false, reason: 'closure_completed' };
        }

        // 1b. Verifica unlock: Struttura (o tab precedente) deve essere submitted
        if (page.unlock_after_submit_prev) {
            const strutturaKey = 'struttura';
            const strutturaStatus = (schedeStatus[strutturaKey]?.status || 'not_started').toLowerCase();
            if (strutturaStatus !== 'submitted') {
                // Struttura non ancora submitted → closure non sbloccata
                if (canManage) {
                    return { visible: true, disabled: true, editable: false, locked: true, reason: 'closure_locked_struttura_not_submitted' };
                }
                return { visible: false, disabled: false, editable: false, reason: 'closure_locked_struttura_not_submitted' };
            }
        }

        // 1c. Sbloccata e non submitted: managers possono compilare
        if (canManage) {
            return { visible: true, disabled: false, editable: true, reason: 'manager_closure_compilation' };
        }

        // 1d. Utente normale → non vede la closure finché non è submitted
        return { visible: false, disabled: false, editable: false, reason: 'closure_not_yet_submitted' };
    }

    // Admin può sempre fare tutto: vede e modifica qualsiasi scheda (come se avesse tutti i ruoli)
    if (isAdmin) {
        return { visible: true, disabled: false, editable: true, reason: 'admin_full_access' };
    }

    // FASE 2 - MVP: Verifica visibilità usando logica centralizzata
    const defaultRoles = ['utente', 'responsabile', 'assegnatario', 'admin'];
    const visibilityRoles = page.visibility_roles || defaultRoles;

    // Determina il ruolo dell'utente corrente
    let userRole = 'utente';
    if (isResponsabile) {
        userRole = 'responsabile';
    } else if (isAssegnatario) {
        userRole = 'assegnatario';
    }

    // WORKFLOW SEGNALAZIONI: canViewTab usando scheda_type
    const scheda_type = page.scheda_type || 'utente';

    // WORKFLOW: Scheda utente = sempre visibile a tutti
    if (scheda_type === 'utente') {
        // Continua con il resto della logica (visibilità sempre true)
    }
    // WORKFLOW: Scheda responsabile - rispetta visibility_roles ma con workflow aggiuntivo
    else if (scheda_type === 'responsabile') {
        // Controlla lo stato di QUESTA specifica scheda
        const thisSchedaStatus = schedeStatus[currentTabKey]?.status || 'not_started';

        // Se la scheda è stata submitte, è sempre visibile (readonly) a chi ha i permessi
        if (thisSchedaStatus === 'submitted') {
            // Continua con il resto della logica (rispetta visibility_roles)
        }
        // Altrimenti, applica restrizioni workflow: visibile solo a responsabile/assegnatario
        // MA solo se le visibility_roles non permettono già la visibilità all'utente
        else if (userRole === 'utente' && !visibilityRoles.includes('utente')) {
            return { visible: false, disabled: false, editable: false, reason: 'workflow_restricted_until_submitted' };
        }
    }

    // Retrocompatibilità: se scheda_type non esiste, usa visibility_roles
    if (!page.scheda_type) {
        let canSee = false;
        if ((page.visibility_mode || 'all') === 'responsabile') {
            canSee = (isResponsabile || isAssegnatario);
        } else {
            const isDefaultConfig = (visibilityRoles.length === defaultRoles.length &&
                defaultRoles.every(r => visibilityRoles.includes(r)) &&
                visibilityRoles.every(r => defaultRoles.includes(r)));
            canSee = isDefaultConfig || visibilityRoles.includes(userRole);
        }
        if (!canSee) {
            return { visible: false, disabled: false, editable: false, reason: 'role_not_allowed' };
        }
    }

    // 2) Verifica condizione di visibilità (NUOVA LOGICA)
    // IMPORTANTE: Questa verifica viene fatta SOLO se l'utente ha i permessi per vedere la scheda
    const condition = page.visibility_condition || { type: WORKFLOW_CONDITIONS.ALWAYS };
    const conditionType = condition.type || WORKFLOW_CONDITIONS.ALWAYS;

    let conditionMet = true;
    let reason = 'condition_met';

    switch (conditionType) {
        case WORKFLOW_CONDITIONS.ALWAYS:
            conditionMet = true;
            break;

        case WORKFLOW_CONDITIONS.AFTER_STEP_SAVED:
            if (isNewRecord) {
                conditionMet = false;
                reason = 'new_record';
            } else {
                const dependsOn = (condition.depends_on || '').toLowerCase();
                if (dependsOn && schedeStatus[dependsOn]) {
                    const depStatus = schedeStatus[dependsOn].status || 'not_started';
                    conditionMet = (depStatus === 'draft' || depStatus === 'submitted');
                } else {
                    conditionMet = checkPreviousTabStatusJS(currentTabKey, allTabs, schedeStatus, ['draft', 'submitted']);
                }
                if (!conditionMet) reason = 'previous_not_saved';
            }
            break;

        case WORKFLOW_CONDITIONS.AFTER_STEP_SUBMITTED:
            if (isNewRecord) {
                conditionMet = false;
                reason = 'new_record';
            } else {
                const dependsOn = (condition.depends_on || '').toLowerCase();
                if (dependsOn && schedeStatus[dependsOn]) {
                    const depStatus = schedeStatus[dependsOn].status || 'not_started';
                    conditionMet = (depStatus === 'submitted');
                } else {
                    conditionMet = checkPreviousTabStatusJS(currentTabKey, allTabs, schedeStatus, ['submitted']);
                }
                if (!conditionMet) reason = 'previous_not_submitted';
            }
            break;

        case WORKFLOW_CONDITIONS.AFTER_ALL_PREVIOUS_SUBMITTED:
            if (isNewRecord) {
                conditionMet = false;
                reason = 'new_record';
            } else {
                conditionMet = checkAllPreviousTabsStatusJS(currentTabKey, allTabs, schedeStatus, 'submitted');
                if (!conditionMet) reason = 'not_all_previous_submitted';
            }
            break;

        default:
            // Retrocompatibilità: unlock_after_submit_prev
            if (page.unlock_after_submit_prev) {
                if (isNewRecord) {
                    conditionMet = false;
                    reason = 'new_record';
                } else {
                    conditionMet = checkPreviousTabStatusJS(currentTabKey, allTabs, schedeStatus, ['submitted']);
                    if (!conditionMet) reason = 'previous_not_submitted';
                }
            }
    }

    // Se la condizione non è soddisfatta, la scheda è disabilitata ma ancora visibile
    // (l'utente può vedere che esiste ma non può accedervi finché la condizione non è soddisfatta)
    if (!conditionMet) {
        return { visible: true, disabled: true, editable: false, reason: reason, locked: true };
    }

    // GESTIONE SEPARATA PER SCHEDA: Verifica modificabilità usando scheda_type + stato singola scheda
    // scheda_type è già dichiarato sopra (linea 75), riusiamolo
    // Se scheda_type non esiste, deducilo da edit_roles (retrocompatibilità)
    let actualSchedaType = scheda_type || 'utente'; // Default a 'utente' se non specificato
    if (!page.scheda_type) {
        const editRoles = page.edit_roles || visibilityRoles;
        // Se edit_roles include solo responsabile/assegnatario (non utente), è scheda responsabile
        if (!editRoles.includes('utente') && (editRoles.includes('responsabile') || editRoles.includes('assegnatario'))) {
            actualSchedaType = 'responsabile';
        } else {
            actualSchedaType = 'utente';
        }
    }

    // IMPORTANTE: Controlla lo stato di QUESTA specifica scheda
    const thisSchedaStatus = schedeStatus[currentTabKey]?.status || 'not_started';

    // NOTA: Admin già gestito sopra con accesso completo (admin_full_access)

    // Schede "utente" sono readonly dopo il submit per utenti NON admin
    if (actualSchedaType === 'utente' && thisSchedaStatus === 'submitted') {
        return { visible: true, disabled: false, editable: false, reason: 'scheda_utente_submitted_readonly' };
    }

    // Regola 1: Chi ha creato (o sta creando) il record ha diritti di scrittura sulla Scheda Utente finché non fa submit
    if (isCreator && actualSchedaType === 'utente') {
        if (thisSchedaStatus === 'submitted') {
            return { visible: true, disabled: false, editable: false, reason: 'already_submitted_by_user' };
        }
        return { visible: true, disabled: false, editable: true, reason: reason };
    }

    // Regola 2: Utente normale + Scheda responsabile = mai editabile
    if (userRole === 'utente' && actualSchedaType === 'responsabile') {
        return { visible: true, disabled: false, editable: false, reason: 'role_no_edit_permission' };
    }

    // Regola 3: Responsabile/Assegnatario (che NON è il creatore) + Scheda utente = mai editabile
    if ((userRole === 'responsabile' || userRole === 'assegnatario') && !isCreator && actualSchedaType === 'utente') {
        return { visible: true, disabled: false, editable: false, reason: 'scheda_utente_readonly' };
    }

    // Regola 4: Responsabile/Assegnatario + Scheda responsabile = editabile solo se non è stata submitte
    // WORKFLOW: Dopo submit, la scheda responsabile diventa readonly per tutti (costituisce la risposta finale)
    if ((userRole === 'responsabile' || userRole === 'assegnatario') && actualSchedaType === 'responsabile') {
        if (thisSchedaStatus === 'submitted') {
            return { visible: true, disabled: false, editable: false, reason: 'already_submitted_by_responsabile' };
        }
        return { visible: true, disabled: false, editable: true, reason: reason };
    }

    // Fallback: se ha permessi per ruolo, può modificare (solo se non è scheda utente submitte)
    const editRoles = page.edit_roles || visibilityRoles;
    const isDefaultEditConfig = (editRoles.length === defaultRoles.length &&
        defaultRoles.every(r => editRoles.includes(r)) &&
        editRoles.every(r => defaultRoles.includes(r)));
    if (isDefaultEditConfig || editRoles.includes(userRole)) {
        // IMPORTANTE: Anche nel fallback, rispetta la regola: schede utente submitte sono sempre readonly
        if (actualSchedaType === 'utente' && thisSchedaStatus === 'submitted') {
            return { visible: true, disabled: false, editable: false, reason: 'scheda_utente_submitted_readonly' };
        }
        return { visible: true, disabled: false, editable: true, reason: reason };
    }

    return { visible: true, disabled: false, editable: false, reason: 'role_no_edit_permission' };
}

/**
 * Helper: verifica lo stato della scheda precedente
 */
function checkPreviousTabStatusJS(currentKey, allTabs, schedeStatus, validStatuses) {
    const tabKeys = Object.keys(allTabs).map(k => k.toLowerCase());
    const currentIndex = tabKeys.indexOf(currentKey);

    if (currentIndex <= 0) {
        return true; // Prima scheda: sempre sbloccata
    }

    const prevKey = tabKeys[currentIndex - 1];
    const prevStatus = schedeStatus[prevKey]?.status || 'not_started';

    return validStatuses.includes(prevStatus);
}

/**
 * Helper: verifica che TUTTE le schede precedenti abbiano un certo stato
 */
function checkAllPreviousTabsStatusJS(currentKey, allTabs, schedeStatus, requiredStatus) {
    const tabKeys = Object.keys(allTabs).map(k => k.toLowerCase());
    const currentIndex = tabKeys.indexOf(currentKey);

    if (currentIndex <= 0) {
        return true; // Prima scheda
    }

    for (let i = 0; i < currentIndex; i++) {
        const tabKey = tabKeys[i];
        const status = schedeStatus[tabKey]?.status || 'not_started';
        if (status !== requiredStatus) {
            return false;
        }
    }

    return true;
}

/**
 * Processa le schede filtrando quelle non visibili e aggiungendo flag __disabled
 * @param {Object} tabs - Oggetto con tutte le schede
 * @param {Object} visibilityData - Dati utente e form
 * @param {Object} schedeStatus - Stato delle schede dal server (opzionale)
 * @returns {Object} Schede processate (solo quelle visibili)
 */
function processTabsVisibilityJS(tabs, visibilityData, schedeStatus = {}) {
    const processedTabs = {};
    const tabNames = Object.keys(tabs);

    tabNames.forEach(tabName => {
        const tabData = tabs[tabName];

        // Estrai configurazione completa (incluse nuove proprietà workflow)
        let pageConfig = {};
        if (Array.isArray(tabData)) {
            pageConfig = {
                is_main: (tabName === 'Struttura' ? 1 : 0),
                visibility_mode: 'all',
                unlock_after_submit_prev: 0,
                visibility_roles: ['utente', 'responsabile', 'assegnatario', 'admin'],
                edit_roles: ['utente', 'responsabile', 'assegnatario', 'admin'],
                visibility_condition: { type: 'always' },
                redirect_after_submit: false,
                // GESTIONE SEPARATA PER SCHEDA: Default a 'utente' se non specificato
                scheda_type: null
            };
        } else if (tabData && Array.isArray(tabData.fields)) {
            pageConfig = {
                is_main: (tabData.is_main === 1 || tabData.is_main === true || (tabData.is_main === undefined && tabName === 'Struttura')),
                visibility_mode: (tabData.visibility_mode || 'all'),
                unlock_after_submit_prev: (tabData.unlock_after_submit_prev === 1 || tabData.unlock_after_submit_prev === true),
                // Nuove proprietà workflow
                visibility_roles: tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'],
                edit_roles: tabData.edit_roles || tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'],
                visibility_condition: tabData.visibility_condition || { type: 'always' },
                redirect_after_submit: tabData.redirect_after_submit || false,
                // GESTIONE SEPARATA PER SCHEDA: Aggiungi scheda_type se presente
                scheda_type: tabData.scheda_type || null,
                isClosureTab: !!(tabData.isClosureTab)
            };
        } else {
            pageConfig = {
                is_main: (tabName === 'Struttura' ? 1 : 0),
                visibility_mode: 'all',
                unlock_after_submit_prev: 0,
                visibility_roles: ['utente', 'responsabile', 'assegnatario', 'admin'],
                edit_roles: ['utente', 'responsabile', 'assegnatario', 'admin'],
                visibility_condition: { type: 'always' },
                redirect_after_submit: false,
                // GESTIONE SEPARATA PER SCHEDA: Default a 'utente' se non specificato
                scheda_type: (tabData && tabData.scheda_type) ? tabData.scheda_type : null,
                isClosureTab: !!(tabData && tabData.isClosureTab)
            };
        }

        // Calcola visibilità con contesto completo
        const context = {
            schedeStatus: schedeStatus,
            allTabs: tabs,
            currentTabKey: tabName.toLowerCase()
        };
        const visibility = calculatePageVisibilityJS(pageConfig, visibilityData, context);

        // DEBUG: Log dettagliato per ogni scheda (attivo con APP_DEBUG o DEBUG_TABS_VISIBILITY)
        if (window.APP_DEBUG || window.DEBUG_TABS_VISIBILITY) {
            const userRole = visibilityData.currentUserId === visibilityData.formResponsabileId ? 'responsabile' :
                (visibilityData.formAssegnatariIds.includes(visibilityData.currentUserId) ? 'assegnatario' : 'utente');
            console.log(`[TABS] Scheda "${tabName}":`, {
                scheda_type: pageConfig.scheda_type,
                isClosureTab: pageConfig.isClosureTab,
                canSee: visibility.visible,
                canEdit: visibility.editable,
                isUnlocked: !visibility.locked && !visibility.disabled,
                isSubmitted: (schedeStatus[context.currentTabKey]?.status === 'submitted'),
                reason: visibility.reason,
                userRole: userRole,
                schedeStatus: schedeStatus[context.currentTabKey] || null
            });
        }

        // IMPORTANTE: Nascondi completamente le schede non visibili (non solo disabilitate)
        if (!visibility.visible) {
            if (window.DEBUG_TABS_VISIBILITY) {
                console.log(`[TABS] Scheda "${tabName}" nascosta - motivo: ${visibility.reason}`);
            }
            return; // Salta schede non visibili - non vengono aggiunte all'array
        }

        // Aggiungi flag __disabled e __visibility se necessario
        const processedTab = Array.isArray(tabData) ? { fields: tabData } : { ...tabData };

        // Se la scheda è disabilitata (condizione non soddisfatta ma visibile), aggiungi il flag
        if (visibility.disabled || visibility.locked) {
            processedTab.__disabled = true;
        }

        // Salva sempre le informazioni di visibilità per debug e uso futuro
        processedTab.__visibility = visibility;

        processedTabs[tabName] = processedTab;
    });

    return processedTabs;
}

/**
 * Trova la scheda iniziale (isMain tra quelle visibili e non disabilitate, o prima visibile non disabilitata)
 * @param {Object} tabs - Oggetto con tutte le schede (già filtrate per visibilità)
 * @param {Object} visibilityData - Dati utente e form
 * @param {Object} schedeStatus - Stato delle schede dal server (opzionale)
 * @returns {string} Nome della scheda iniziale
 */
function findStartTabJS(tabs, visibilityData, schedeStatus = {}) {
    const visibleTabs = [];
    const tabNames = Object.keys(tabs);

    tabNames.forEach(tabName => {
        const tabData = tabs[tabName];

        // Estrai configurazione completa
        let pageConfig = {};
        if (Array.isArray(tabData)) {
            pageConfig = {
                is_main: (tabName === 'Struttura' ? 1 : 0),
                visibility_mode: 'all',
                unlock_after_submit_prev: 0,
                visibility_roles: ['utente', 'responsabile', 'assegnatario', 'admin'],
                visibility_condition: { type: 'always' }
            };
        } else if (tabData && Array.isArray(tabData.fields)) {
            pageConfig = {
                is_main: (tabData.is_main === 1 || tabData.is_main === true || (tabData.is_main === undefined && tabName === 'Struttura')),
                visibility_mode: (tabData.visibility_mode || 'all'),
                unlock_after_submit_prev: (tabData.unlock_after_submit_prev === 1 || tabData.unlock_after_submit_prev === true),
                visibility_roles: tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'],
                visibility_condition: tabData.visibility_condition || { type: 'always' }
            };
        } else {
            pageConfig = {
                is_main: (tabName === 'Struttura' ? 1 : 0),
                visibility_mode: 'all',
                unlock_after_submit_prev: 0,
                visibility_roles: ['utente', 'responsabile', 'assegnatario', 'admin'],
                visibility_condition: { type: 'always' }
            };
        }

        // Calcola visibilità con contesto
        const context = {
            schedeStatus: schedeStatus,
            allTabs: tabs,
            currentTabKey: tabName.toLowerCase()
        };
        const visibility = calculatePageVisibilityJS(pageConfig, visibilityData, context);

        if (visibility.visible) {
            visibleTabs.push({
                name: tabName,
                config: pageConfig,
                disabled: visibility.disabled,
                editable: visibility.editable
            });
        }
    });

    // Cerca la scheda principale tra quelle visibili e NON disabilitate
    for (const tab of visibleTabs) {
        if (tab.config.is_main && !tab.disabled) {
            return tab.name;
        }
    }

    // Fallback: prima scheda visibile NON disabilitata
    for (const tab of visibleTabs) {
        if (!tab.disabled) {
            return tab.name;
        }
    }

    // Ultimo fallback: prima scheda visibile (anche se disabilitata)
    if (visibleTabs.length > 0) {
        return visibleTabs[0].name;
    }

    return 'Struttura'; // Default
}

/**
 * Calcola la prossima scheda disponibile dopo quella corrente
 * @param {string} currentTabKey - Chiave della scheda corrente
 * @param {Object} tabs - Tutte le schede
 * @param {Object} visibilityData - Dati utente
 * @param {Object} schedeStatus - Stato schede
 * @returns {string|null} Nome della prossima scheda o null se non ce ne sono
 */
function findNextAvailableTabJS(currentTabKey, tabs, visibilityData, schedeStatus = {}) {
    const tabNames = Object.keys(tabs);
    const currentIndex = tabNames.findIndex(name => name.toLowerCase() === currentTabKey.toLowerCase());

    if (currentIndex === -1) return null;

    // Cerca dalla scheda successiva
    for (let i = currentIndex + 1; i < tabNames.length; i++) {
        const tabName = tabNames[i];
        const tabData = tabs[tabName];

        let pageConfig = {};
        if (tabData && Array.isArray(tabData.fields)) {
            pageConfig = {
                visibility_mode: (tabData.visibility_mode || 'all'),
                unlock_after_submit_prev: (tabData.unlock_after_submit_prev === 1 || tabData.unlock_after_submit_prev === true),
                visibility_roles: tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'],
                edit_roles: tabData.edit_roles || tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'],
                visibility_condition: tabData.visibility_condition || { type: 'always' }
            };
        } else {
            pageConfig = {
                visibility_mode: 'all',
                unlock_after_submit_prev: 0,
                visibility_roles: ['utente', 'responsabile', 'assegnatario', 'admin'],
                edit_roles: ['utente', 'responsabile', 'assegnatario', 'admin'],
                visibility_condition: { type: 'always' }
            };
        }

        const context = {
            schedeStatus: schedeStatus,
            allTabs: tabs,
            currentTabKey: tabName.toLowerCase()
        };
        const visibility = calculatePageVisibilityJS(pageConfig, visibilityData, context);

        if (visibility.visible && visibility.editable && !visibility.disabled) {
            return tabName;
        }
    }

    return null; // Nessuna scheda successiva disponibile
}

