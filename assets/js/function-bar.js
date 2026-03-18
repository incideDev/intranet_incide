document.addEventListener("DOMContentLoaded", function () {
    const addButton = document.getElementById("addButton");
    if (addButton) addButton.setAttribute("data-tooltip", "Aggiungi");

    const archiveButton = document.getElementById("archiveButton");
    if (archiveButton) archiveButton.setAttribute("data-tooltip", "Archivio");

    const listButton = document.getElementById("listButton");
    if (listButton) listButton.setAttribute("data-tooltip", "vista tabella");

    const kanbanButton = document.getElementById("kanbanButton");
    if (kanbanButton) kanbanButton.setAttribute("data-tooltip", "vista kanban");

    const ganttButton = document.getElementById("ganttButton");
    if (ganttButton) ganttButton.setAttribute("data-tooltip", "vista Gantt");

    const dashboardButton = document.getElementById("dashboardButton");
    if (dashboardButton) dashboardButton.setAttribute("data-tooltip", "Dashboard");

    const calendarButton = document.getElementById("calendarButton");
    if (calendarButton) calendarButton.setAttribute("data-tooltip", "vista calendario");

    const momButton = document.getElementById("momButton");
    if (momButton) momButton.setAttribute("data-tooltip", "Verbale riunione (MOM)");

    const urlParams = new URLSearchParams(window.location.search);
    const currentPage = urlParams.get("page");
    const section = urlParams.get("section");

    if (currentPage === 'gare_dettaglio') {
        const printBtn = document.querySelector('.button-group button[data-tooltip="Stampa / Esporta"]');
        if (printBtn) {
            printBtn.classList.remove('disabled');
            const freshPrint = safeRebind(printBtn);
            freshPrint.setAttribute('data-tooltip', 'Stampa');
            freshPrint.removeAttribute('onclick');
            freshPrint.id = 'printButton';
            freshPrint.addEventListener('click', async (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                await preparePrintWorkflow();
                setTimeout(() => window.print(), 25);
            });
        }

        async function preparePrintWorkflow() {
            if (typeof window.requestPrintLayout === 'function') {
                await window.requestPrintLayout();
            } else if (typeof window.prepareGareDetailForPrint === 'function') {
                await window.prepareGareDetailForPrint();
            }
        }

        if (!window._garePrintHandlersAttached) {
            window._garePrintHandlersAttached = true;

            window.addEventListener('beforeprint', () => {
                if (typeof window.requestPrintLayout === 'function') {
                    window.requestPrintLayout();
                } else {
                    preparePrintWorkflow();
                }
            });
        }

        window.handleExportOrPrint = async function () {
            await preparePrintWorkflow();
            setTimeout(() => window.print(), 25);
        };
    }

    if (archiveButton) {
        const archivePages = [
            "gestione_segnalazioni",
            "form_viewer",
            "view_form",
            "segnalazioni_dashboard",
            "archivio_segnalazioni",
            "elenco_gare",
            "estrazione_bandi",
            "archivio_gare"
        ];

        if (archivePages.includes(currentPage)) {
            archiveButton.classList.remove("disabled");
            archiveButton.replaceWith(archiveButton.cloneNode(true));
            document.getElementById("archiveButton").addEventListener("click", () => {
                if (currentPage === "archivio_segnalazioni") {
                    window.location.reload();
                } else if (currentPage === "archivio_gare") {
                    window.location.reload();
                } else if (currentPage === "elenco_gare" || currentPage === "estrazione_bandi") {
                    window.location.href = "index.php?section=commerciale&page=archivio_gare";
                } else {
                    window.location.href = "index.php?section=collaborazione&page=archivio_segnalazioni";
                }
            });
        } else {
            archiveButton.classList.add("disabled");
            archiveButton.onclick = null;
        }
    }

    // helper safe (allinea allo stile del progetto)
    function safeRebind(el) {
        if (!el || !el.parentNode) return el || null;
        const fresh = el.cloneNode(true);
        el.parentNode.replaceChild(fresh, el);
        return fresh;
    }

    function showCalendarView() {
        const idsToHide = ["table-view", "kanban-view", "elenco-view", "org-tree-view", "org-table-view", "gantt-view", "mom-archivio", "mom-editor"];
        const cv = document.getElementById("calendar-view");

        if (!cv) {
            console.warn('Calendar container not found');
            return;
        }

        // Nascondi altre viste
        idsToHide.forEach(id => document.getElementById(id)?.classList.add("hidden"));

        // Mostra il container
        cv.classList.remove("hidden");

        // provider SELEZIONATO inline (minimo codice, niente helper)
        const provider = (window.calendarProviders && window.calendarProviders[currentPage])
            ? window.calendarProviders[currentPage]
            : (typeof window.calendarDataProvider === 'function' ? window.calendarDataProvider : null);

        // Funzione di inizializzazione interna
        let retryCount = 0;
        const maxRetries = 50; // 5 secondi max (50 * 100ms)
        const initCalendar = () => {
            if (retryCount >= maxRetries) {
                console.warn('[Calendar Init] Timeout - CalendarView or provider not available');
                console.warn('[Calendar Init] CalendarView:', !!window.CalendarView);
                console.warn('[Calendar Init] Provider:', !!provider);
                console.warn('[Calendar Init] Current page:', currentPage);
                return;
            }

            if (!window.CalendarView || typeof CalendarView.init !== "function") {
                // Se CalendarView non è ancora caricato, riprova dopo un breve delay
                retryCount++;
                if (retryCount % 10 === 0) console.log('[Calendar Init] Waiting for CalendarView...', retryCount);
                setTimeout(() => initCalendar(), 100);
                return;
            }

            // Se serve il provider ma non è disponibile, aspetta
            if (currentPage === 'elenco_gare') {
                // Verifica se il provider è disponibile (può essere nel registry o globale)
                const hasProvider = provider ||
                    (window.calendarProviders && (window.calendarProviders[currentPage] || window.calendarProviders['gare'])) ||
                    (typeof window.calendarDataProvider === 'function');

                if (!hasProvider && retryCount < maxRetries) {
                    retryCount++;
                    if (retryCount % 10 === 0) console.log('[Calendar Init] Waiting for provider...', retryCount);
                    setTimeout(() => initCalendar(), 100);
                    return;
                }

                // Usa il provider più appropriato (supporta sia 'elenco_gare' che 'gare' per retrocompatibilità)
                const finalProvider = provider ||
                    (window.calendarProviders && (window.calendarProviders[currentPage] || window.calendarProviders['gare'])) ||
                    window.calendarDataProvider;

                if (finalProvider) {
                    console.log('[Calendar Init] Initializing with provider for elenco_gare');
                    CalendarView.init({ containerId: "calendar-view", provider: finalProvider, view: "month" });
                    return;
                }
            }

            const notReady = !CalendarView._state || CalendarView._state.container !== cv;

            if (notReady) {
                console.log('[Calendar Init] Initializing CalendarView');
                CalendarView.init({ containerId: "calendar-view", provider, view: "month" });
            } else if (typeof CalendarView.refresh === "function") {
                // Se il provider è cambiato, reinizializza
                if (provider && CalendarView._state.provider !== provider) {
                    console.log('[Calendar Init] Provider changed, reinitializing');
                    CalendarView.init({ containerId: "calendar-view", provider, view: "month" });
                } else {
                    console.log('[Calendar Init] Refreshing existing CalendarView');
                    CalendarView.refresh();
                }
            }
        };

        // Avvia l'inizializzazione
        initCalendar();

        if (typeof updateButtons === "function") updateButtons();
    }

    function showGanttView() {
        const idsToHide = ["table-view", "kanban-view", "elenco-view", "org-tree-view", "org-table-view", "calendar-view"];
        const gv = document.getElementById("gantt-view");

        if (!gv) {
            console.warn('Gantt container not found');
            return;
        }

        // Nascondi altre viste
        idsToHide.forEach(id => document.getElementById(id)?.classList.add("hidden"));

        // Mostra il container
        gv.classList.remove("hidden");

        // provider SELEZIONATO inline (coerente con calendario)
        const provider = (window.calendarProviders && window.calendarProviders[currentPage])
            ? window.calendarProviders[currentPage]
            : (typeof window.calendarDataProvider === 'function' ? window.calendarDataProvider : null);

        // Funzione di inizializzazione interna
        let retryCount = 0;
        const maxRetries = 50; // 5 secondi max (50 * 100ms)
        const initGantt = () => {
            if (retryCount >= maxRetries) {
                console.warn('[Gantt Init] Timeout - GanttView or provider not available');
                console.warn('[Gantt Init] GanttView:', !!window.GanttView);
                console.warn('[Gantt Init] Provider:', !!provider);
                console.warn('[Gantt Init] Current page:', currentPage);
                return;
            }

            if (!window.GanttView || typeof GanttView.init !== "function") {
                // Se GanttView non è ancora caricato, riprova dopo un breve delay
                retryCount++;
                if (retryCount % 10 === 0) console.log('[Gantt Init] Waiting for GanttView...', retryCount);
                setTimeout(() => initGantt(), 100);
                return;
            }

            // Se serve il provider ma non è disponibile, aspetta
            if (currentPage === 'elenco_gare') {
                // Verifica se il provider è disponibile (può essere nel registry o globale)
                const hasProvider = provider ||
                    (window.calendarProviders && (window.calendarProviders[currentPage] || window.calendarProviders['gare'])) ||
                    (typeof window.calendarDataProvider === 'function');

                if (!hasProvider && retryCount < maxRetries) {
                    retryCount++;
                    if (retryCount % 10 === 0) console.log('[Gantt Init] Waiting for provider...', retryCount);
                    setTimeout(() => initGantt(), 100);
                    return;
                }

                // Usa il provider più appropriato (supporta sia 'elenco_gare' che 'gare' per retrocompatibilità)
                const finalProvider = provider ||
                    (window.calendarProviders && (window.calendarProviders[currentPage] || window.calendarProviders['gare'])) ||
                    window.calendarDataProvider;

                if (finalProvider) {
                    console.log('[Gantt Init] Initializing with provider for elenco_gare');
                    GanttView.init({ containerId: "gantt-view", provider: finalProvider, range: 'quarter' });
                    return;
                }
            }

            const notReady = !GanttView._state || GanttView._state.container !== gv;
            if (notReady) {
                console.log('[Gantt Init] Initializing GanttView');
                GanttView.init({ containerId: "gantt-view", provider, range: 'quarter' });
            } else if (typeof GanttView.refresh === "function") {
                // Se il provider è cambiato, reinizializza
                if (provider && GanttView._state.provider !== provider) {
                    console.log('[Gantt Init] Provider changed, reinitializing');
                    GanttView.init({ containerId: "gantt-view", provider, range: 'quarter' });
                } else {
                    console.log('[Gantt Init] Refreshing existing GanttView');
                    GanttView.refresh();
                }
            }
        };

        // Avvia l'inizializzazione
        initGantt();

        if (typeof updateButtons === "function") updateButtons();
    }

    function leaveCalendarPreferList() {
        const cv = document.getElementById("calendar-view");
        if (cv) cv.classList.add("hidden");
        document.getElementById("gantt-view")?.classList.add("hidden");

        if (document.getElementById("table-view")) {
            document.getElementById("table-view").classList.remove("hidden");
            document.getElementById("kanban-view")?.classList.add("hidden");
            document.getElementById("elenco-view")?.classList.add("hidden");
        } else if (document.getElementById("elenco-view")) {
            document.getElementById("elenco-view").classList.remove("hidden");
            document.getElementById("kanban-view")?.classList.add("hidden");
        } else if (document.getElementById("kanban-view")) {
            document.getElementById("kanban-view").classList.remove("hidden");
        } else if (currentPage === 'mom') {
            // Logica specifica MOM per ripristinare la vista corretta
            const urlParams = new URLSearchParams(window.location.search);
            const action = urlParams.get("action");
            const id = urlParams.get("id");
            const momArchivio = document.getElementById("mom-archivio");
            const momEditor = document.getElementById("mom-editor");

            if ((action === "edit" || action === "view") || (id && id > 0)) {
                if (momEditor) momEditor.classList.remove("hidden");
            } else {
                if (momArchivio) momArchivio.classList.remove("hidden");
            }
        }
        if (typeof updateButtons === "function") updateButtons();
    }

    function leaveGanttPreferList() {
        const gv = document.getElementById("gantt-view");
        if (gv) gv.classList.add("hidden");
        if (document.getElementById("table-view")) {
            document.getElementById("table-view").classList.remove("hidden");
            document.getElementById("kanban-view")?.classList.add("hidden");
            document.getElementById("elenco-view")?.classList.add("hidden");
        } else if (document.getElementById("elenco-view")) {
            document.getElementById("elenco-view").classList.remove("hidden");
            document.getElementById("kanban-view")?.classList.add("hidden");
        } else if (document.getElementById("kanban-view")) {
            document.getElementById("kanban-view").classList.remove("hidden");
        }
        if (typeof updateButtons === "function") updateButtons();
    }

    addButton.classList.add("disabled");

    const actions = {
        "estrazione_bandi": { fn: () => window.openGareUploadModal?.(), modalId: null },

        "task_management": { fn: () => toggleModal("addBoardModal", "open"), modalId: "addBoardModal" },

        "impostazioni_moduli": {
            fn: () => {
                window.location.href = "index.php?section=gestione_intranet&page=page_editor";
            },
            modalId: null
        }
    };

    if (actions[currentPage]) {
        const { fn, modalId } = actions[currentPage];

        const modalReady = modalId ? document.getElementById(modalId) : true;

        if (currentPage === "impostazioni_moduli" || modalReady) {
            addButton.classList.remove("disabled");
            addButton.replaceWith(addButton.cloneNode(true));
            document.getElementById("addButton").addEventListener("click", fn);
        }
    }

    if (
        currentPage === 'commessa' &&
        urlParams.get('view') === 'task'
    ) {

        const modalTask = document.getElementById('modal-dettagli-task');
        if (modalTask) {
            addButton.classList.remove("disabled");
            addButton.replaceWith(addButton.cloneNode(true));
            document.getElementById("addButton").addEventListener("click", () => {
                const form = document.getElementById('form-dettagli-task');
                if (form) {
                    form.reset();
                    if (form.querySelector("#dt-task-id")) form.querySelector("#dt-task-id").value = "";
                }
                const titolo = document.getElementById("modale-task-title");
                if (titolo) titolo.textContent = "Crea nuova Task";
                toggleModal('modal-dettagli-task', 'open');
            });
        }
    }

    // ——— COMMESSA KANBAN: il "+" apre la modale nuova task ———
    else if (currentPage === 'commessa_kanban') {
        const modalNuovaTask = document.getElementById('modal-nuova-task');
        if (modalNuovaTask) {
            addButton.classList.remove("disabled");
            addButton.replaceWith(addButton.cloneNode(true));
            document.getElementById("addButton").addEventListener("click", () => {
                const form = document.getElementById('form-nuova-task');
                if (form) form.reset();
                toggleModal('modal-nuova-task', 'open');
            });
        }
    }

    // ——— COMMESSA (dashboard): il "+" abilita subito 'gestione_cantiere' e aggiunge la card ———
    else if (currentPage === 'commessa' && !urlParams.get('view')) {
        const grid = document.querySelector('.commessa-grid');
        const tabella = (urlParams.get('tabella') || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();

        // helper: crea/mostra la card se non esiste
        const addOrShowCantiereCard = () => {
            if (!grid) return;

            const exists = grid.querySelector('a.commessa-card[data-key="gestione_cantiere"]');
            if (exists) {
                // già presente: evidenziala un attimo
                exists.classList.add('pulse');
                setTimeout(() => exists.classList.remove('pulse'), 800);
                return;
            }

            const titolo = urlParams.get('titolo') || 'Commessa';
            const href = `index.php?section=commesse&page=commessa&tabella=${encodeURIComponent(tabella)}&titolo=${encodeURIComponent(titolo)}&view=gestione_cantiere`;

            const a = document.createElement('a');
            a.className = 'commessa-card cantiere-card';
            a.dataset.key = 'gestione_cantiere';
            a.href = href;
            a.setAttribute('data-tooltip', 'Gestione cantiere');
            a.innerHTML = `
            <div class="commessa-card-title">Gestione cantiere</div>
            <div class="commessa-card-preview"></div>
        `;
            grid.appendChild(a);
        };

        const enableSectionOnServer = async () => {
            if (typeof window.customFetch !== 'function') {
                throw new Error('customFetch non disponibile: ajax.js non caricato');
            }
            return await customFetch('commesse', 'setSectionEnabled', {
                tabella: tabella,
                sectionKey: 'gestione_cantiere',
                enabled: 1
            });
        };

        // attiva il tasto +
        addButton.classList.remove('disabled');
        addButton.replaceWith(addButton.cloneNode(true));
        document.getElementById('addButton').addEventListener('click', async () => {
            try {
                addButton.classList.add('disabled');

                const out = await enableSectionOnServer();

                // se il router risponde con success:true, aggiungo la card
                if (out && out.success) {
                    addOrShowCantiereCard();
                    // opzionale: notifica
                    if (typeof window.showToast === 'function') {
                        showToast('Sezione "Gestione cantiere" abilitata.');
                    }
                } else {
                    // gestisci errori server
                    if (typeof window.showToast === 'function') {
                        showToast(out?.message || 'Impossibile abilitare la sezione.', 'error');
                    } else {
                        console.warn('setSectionEnabled fallita:', out);
                    }
                }
            } catch (e) {
                console.error(e);
                if (typeof window.showToast === 'function') {
                    showToast('Errore durante l’abilitazione.', 'error');
                }
            } finally {
                addButton.classList.remove('disabled');
            }
        });
    }

    else if (currentPage === "gestione_segnalazioni") {
        const formNameFromURL = urlParams.get("form_name");
        if (formNameFromURL) {
            addButton.classList.remove("disabled");
            addButton.replaceWith(addButton.cloneNode(true));
            document.getElementById("addButton").addEventListener("click", () => {
                const encodedName = encodeURIComponent(formNameFromURL);
                window.location.href = `index.php?section=collaborazione&page=view_form&form_name=${encodedName}`;
            });
        }
    } else if (currentPage === "mom" && section === "commerciale") {
        // Abilita il bottone + per creare nuovo MOM nella sezione commerciale
        addButton.classList.remove("disabled");
        addButton.replaceWith(addButton.cloneNode(true));
        document.getElementById("addButton").addEventListener("click", () => {
            window.location.href = "index.php?section=commerciale&page=mom&action=edit";
        });
    } else if (section === 'cv' || currentPage === 'cv_manager') {
        // CV Manager: Add Button opens custom upload modal
        addButton.classList.remove("disabled");
        addButton.replaceWith(addButton.cloneNode(true));
        document.getElementById("addButton").addEventListener("click", () => {
            // Trigger upload modal opening from global scope/cvManager
            if (typeof cvManager !== 'undefined' && typeof cvManager.openUploadModal === 'function') {
                cvManager.openUploadModal();
            } else {
                // If cvManager not loaded yet or in scope, fallback
                console.warn("cvManager.openUploadModal not found");
            }
        });
    } else if (currentPage === 'requisiti') {
        // Requisiti: Add Button opens Comprovanti modal
        addButton.classList.remove("disabled");
        addButton.replaceWith(addButton.cloneNode(true));
        document.getElementById("addButton").addEventListener("click", () => {
            if (typeof window.comprovantiOpenModalProgetto === 'function') {
                window.comprovantiOpenModalProgetto(null);
            } else {
                console.warn("comprovantiOpenModalProgetto not found");
            }
        });
    } else if (currentPage === "commessa" && urlParams.get("view") === "organigramma") {
        if (listButton) listButton.setAttribute("data-tooltip", "vista tabella");
        if (kanbanButton) kanbanButton.setAttribute("data-tooltip", "vista albero");

        const vtree = document.getElementById("org-tree-view");
        const vtable = document.getElementById("org-table-view");

        function showTable() {
            if (!vtree || !vtable) return;
            vtree.classList.add("hidden");
            vtable.classList.remove("hidden");
            if (typeof updateButtons === "function") updateButtons();
        }

        function showTree() {
            if (!vtree || !vtable) return;
            vtable.classList.add("hidden");
            vtree.classList.remove("hidden");
            if (typeof updateButtons === "function") updateButtons();
            if (typeof window.adaptTreeZoom === "function") setTimeout(window.adaptTreeZoom, 60);
        }

        function setOrgButtonsState() {
            // pulizia: clono entrambi per rimuovere eventuali vecchi listeners
            const freshList = listButton.cloneNode(true);
            const freshKanban = kanbanButton.cloneNode(true);
            listButton.parentNode.replaceChild(freshList, listButton);
            kanbanButton.parentNode.replaceChild(freshKanban, kanbanButton);

            const lb = document.getElementById("listButton");
            const kb = document.getElementById("kanbanButton");

            // di base abilito entrambi, poi disabilito quello della vista corrente
            lb.classList.remove("disabled");
            kb.classList.remove("disabled");
            lb.setAttribute("aria-disabled", "false");
            kb.setAttribute("aria-disabled", "false");

            // attacho il listener solo al bottone “opposto”
            if (vtable && !vtable.classList.contains("hidden")) {
                // siamo in tabella -> abilito kanban (albero), disabilito list
                lb.classList.add("disabled");
                lb.setAttribute("aria-disabled", "true");
                kb.addEventListener("click", showTree);
            } else {
                // siamo in albero -> abilito list (tabella), disabilito kanban
                kb.classList.add("disabled");
                kb.setAttribute("aria-disabled", "true");
                lb.addEventListener("click", showTable);
            }
        }

        // stato iniziale
        setOrgButtonsState();

    } else if (section === 'archivio' || section === 'qualita' || (window.documentArea && window.documentArea.id)) {
        // Aree documentali (archivio, qualità, formazione, ecc.) gestite da document_manager.js
        const _dmSection = (window.documentArea && window.documentArea.id) ? window.documentArea.id : section;

        // VISTA ELENCO / KANBAN
        listButton.classList.remove("disabled");
        kanbanButton.classList.remove("disabled");

        listButton.replaceWith(listButton.cloneNode(true));
        kanbanButton.replaceWith(kanbanButton.cloneNode(true));

        // Riferimenti ai contenitori in document_manager.php
        // Table view è dentro .table-wrapper
        // Grid view è #documenti-kanban

        let _dmViewInitialized = false;

        // Helper per stato bottoni
        function updateDMButtonsState() {
            const tableWrapper = document.querySelector('.table-wrapper');
            const documentiKanban = document.getElementById('documenti-kanban');
            const lb = document.getElementById("listButton");
            const kb = document.getElementById("kanbanButton");

            if (!lb || !kb) return;

            // Se siamo in Dashboard (tableWrapper o documentiKanban mancano), disabilita i pulsanti vista
            if (!tableWrapper || !documentiKanban) {
                kb.classList.add("disabled");
                lb.classList.add("disabled");
                kb.onclick = null;
                lb.onclick = null;
                return;
            }

            // Al primo caricamento, applica preferenza localStorage
            if (!_dmViewInitialized) {
                _dmViewInitialized = true;
                const viewPref = localStorage.getItem('dm_view_pref');
                const foldersGridInit = document.getElementById('dm-folders-grid');
                if (viewPref === 'table') {
                    tableWrapper.classList.remove("hidden");
                    documentiKanban.classList.add("hidden");
                    // Nascondi subito il grid: le cartelle sono nelle righe della tabella
                    if (foldersGridInit) foldersGridInit.style.display = 'none';
                } else {
                    // default = grid
                    tableWrapper.classList.add("hidden");
                    documentiKanban.classList.remove("hidden");
                    // Il grid è visibile in root (caricaCartelleDM lo popola dopo)
                    if (foldersGridInit) foldersGridInit.style.display = 'flex';
                }
            }

            const isKanbanVisible = !documentiKanban.classList.contains('hidden');

            // Riposiziona la paginazione in base alla vista attiva
            if (typeof window.placeDmPagination === 'function') {
                window.placeDmPagination(isKanbanVisible ? 'kanban' : 'table');
            }

            if (isKanbanVisible) {
                kb.classList.add("disabled", "active");
                kb.onclick = null;

                lb.classList.remove("disabled", "active");
                lb.onclick = () => {
                    localStorage.setItem('dm_view_pref', 'table');
                    documentiKanban.classList.add("hidden");
                    tableWrapper.classList.remove("hidden");

                    // Nascondi sempre il grid cartelle nella vista tabella
                    // (le cartelle sono già come righe nella tabella stessa)
                    const foldersGrid = document.getElementById('dm-folders-grid');
                    if (foldersGrid) foldersGrid.style.display = 'none';

                    // Riposiziona paginazione
                    if (typeof window.placeDmPagination === 'function') window.placeDmPagination('table');

                    // Ricarica tabella se necessario
                    const urlParams = new URLSearchParams(window.location.search);
                    const pageSlug = urlParams.get('page');
                    const effectiveSlug = window.currentSlug || pageSlug;
                    if (typeof window.caricaDocumentiDM === 'function' && effectiveSlug) {
                        const tbody = document.getElementById('documenti-list');
                        if (tbody && (tbody.children.length === 0 || window.__dmDocumentsDirty)) {
                            window.caricaDocumentiDM(_dmSection, effectiveSlug, 1, false);
                            window.__dmDocumentsDirty = false;
                        }
                    }
                    updateDMButtonsState();
                };
            } else {
                lb.classList.add("disabled", "active");
                lb.onclick = null;

                kb.classList.remove("disabled", "active");
                kb.onclick = () => {
                    localStorage.setItem('dm_view_pref', 'grid');
                    tableWrapper.classList.add("hidden");
                    documentiKanban.classList.remove("hidden");

                    const urlParams = new URLSearchParams(window.location.search);
                    const pageSlug = urlParams.get('page');
                    const effectiveSlug = window.currentSlug || pageSlug;

                    if (typeof window.caricaDocumentiKanban === 'function' && effectiveSlug) {
                        if (documentiKanban.children.length === 0 || window.__dmDocumentsDirty) {
                            window.caricaDocumentiKanban(_dmSection, effectiveSlug, window.kanbanCurrentPage || 1, false);
                            window.__dmDocumentsDirty = false;
                        }
                    }
                    updateDMButtonsState();
                };
            }
        }

        // Inizializza stato
        updateDMButtonsState();

        const mainDropzone = document.getElementById('mainDropzone'); // Questo ID non esiste in document_manager.php (è dropAreaDM), ma lo lasciamo per compatibilità custom se presente
        const dropAreaDM = document.getElementById('dropAreaDM');

        const fileInput = document.getElementById('fileInput'); // Nemmeno questo
        const uploadFilesDM = document.getElementById('uploadFilesDM');

        if (dropAreaDM && uploadFilesDM) {
            addButton.classList.remove("disabled");
            addButton.replaceWith(addButton.cloneNode(true));
            document.getElementById("addButton").addEventListener("click", () => {
                // Apre il modale di upload se siamo in una pagina
                // La logica è già in document_manager.js su #addButton click, 
                // ma qui stiamo rimpiazzando il bottone, quindi dobbiamo ricollegarlo o triggerare il modale.
                // In document_manager.js il listener è aggiunto a addButton se esiste.

                // Se rimpiazziamo il nodo, perdiamo il listener di document_manager.js!
                // Quindi dobbiamo richiamare la logica.

                // Controllo Dashboard vs Pagina
                const modalNuovaPagina = document.getElementById('modalNuovaPagina');
                const uploadModal = document.getElementById('uploadModalDM');
                const urlParams = new URLSearchParams(window.location.search);
                const page = urlParams.get('page');
                const isDashboard = (!page || page === _dmSection);

                if (isDashboard) {
                    if (modalNuovaPagina && typeof window.openModal === 'function') window.openModal('modalNuovaPagina');
                    else if (modalNuovaPagina) modalNuovaPagina.style.display = 'block';
                } else {
                    if (uploadModal && typeof window.openModal === 'function') window.openModal('uploadModalDM');
                    else if (uploadModal) uploadModal.style.display = 'block';
                }
            });
        } else if (mainDropzone && fileInput) {
            // Fallback per vecchio archivio se ancora usato
            addButton.classList.remove("disabled");
            addButton.replaceWith(addButton.cloneNode(true));
            document.getElementById("addButton").addEventListener("click", () => {
                mainDropzone.classList.add('dragover');
                fileInput.click();
                fileInput.addEventListener('change', () => {
                    mainDropzone.classList.remove('dragover');
                });
            });
        }
    }

    // opzionale: puoi rimuovere il commento sotto (non serve più)
    const childPagesOfDashboard = ["form", "form_view", "form_viewer", "view_form", "gestione_segnalazioni", "archivio_segnalazioni"];
    if (childPagesOfDashboard.includes(currentPage)) {
        dashboardButton.classList.remove("disabled");
        dashboardButton.addEventListener("click", () => {
            console.log(" Torno alla dashboard segnalazioni");
            window.location.href = "index.php?section=collaborazione&page=segnalazioni_dashboard";
        });
    }

    // ——— MOM (abilitato solo in sezione commerciale) ———
    if (momButton) {
        const momEnabled = section === 'commerciale';
        const freshMom = safeRebind(momButton);

        if (momEnabled) {
            freshMom.classList.remove("disabled");
            freshMom.setAttribute("data-tooltip", "Verbali (MOM)");
            freshMom.addEventListener("click", () => {
                window.location.href = "index.php?section=commerciale&page=mom";
            });
        } else {
            freshMom.classList.add("disabled");
        }
    }

    // ——— LIST ———
    if (listButton) {
        listButton.addEventListener("click", () => {
            if (
                currentPage === "commessa" &&
                urlParams.get("view") === "task"
            ) {
                const kanbanView = document.getElementById("kanban-view");
                const tableView = document.getElementById("table-view");
                if (kanbanView && tableView) {
                    kanbanView.classList.add("hidden");
                    tableView.classList.remove("hidden");
                    updateButtons();
                    return;
                }
            }

            // se sto in calendario, esco verso l’elenco “giusto”
            const calendarView = document.getElementById("calendar-view");
            if (calendarView && !calendarView.classList.contains("hidden")) {
                leaveCalendarPreferList();
                return;
            }

            // se sto in gantt, esco verso l’elenco “giusto”
            const ganttView = document.getElementById("gantt-view");
            if (ganttView && !ganttView.classList.contains("hidden")) {
                leaveGanttPreferList();
                return;
            }

            // Elenco segnalazioni (standard)
            if (currentPage === "segnalazioni_dashboard") {
                window.location.href = "index.php?section=collaborazione&page=gestione_segnalazioni";
            } else if (currentPage === "gestione_segnalazioni" && getCurrentView() === "kanban") {
                toggleView("elenco-view", "kanban-view");
            } else if (currentPage === "elenco_gare") {
                toggleView("table-view", "kanban-view");
            }
            // MOM: 
            else if (currentPage === "mom") {
                const urlParams = new URLSearchParams(window.location.search);
                const action = urlParams.get("action");

                // Se siamo in archivio (no action o action=archivio), usa switchView
                if (!action || action === 'archivio') {
                    if (window.momApp && typeof window.momApp.switchView === 'function') {
                        window.momApp.switchView('list');
                        updateButtons();
                        return;
                    }
                }

                // Logica esistente per dettaglio MOM (task view)
                const kanbanView = document.getElementById("kanban-view");
                const calendarView = document.getElementById("calendar-view");
                const momArchivio = document.getElementById("mom-archivio");
                const momEditor = document.getElementById("mom-editor");

                if (kanbanView) kanbanView.classList.add("hidden");
                if (calendarView) calendarView.classList.add("hidden");

                // Determina cosa mostrare in base allo stato dello script/URL
                const id = urlParams.get("id");

                if ((action === "edit" || action === "view") || (id && id > 0)) {
                    if (momEditor) momEditor.classList.remove("hidden");
                } else {
                    if (momArchivio) momArchivio.classList.remove("hidden");
                }

                updateButtons();
                return;
            }
            // Archivio: torna alla vista elenco (tabella)
            else if (currentPage === "archivio_segnalazioni") {
                const url = new URL(window.location.href);
                url.searchParams.delete("kanban");
                url.searchParams.delete("calendar"); // rimuovi eventuale flag calendario
                window.location.href = url.toString();
                return;
            }
        });
    }

    // ——— KANBAN ———
    if (kanbanButton) {
        kanbanButton.addEventListener("click", () => {
            if (
                currentPage === "commessa" &&
                urlParams.get("view") === "task"
            ) {
                const kanbanView = document.getElementById("kanban-view");
                const tableView = document.getElementById("table-view");
                if (kanbanView && tableView) {
                    tableView.classList.add("hidden");
                    kanbanView.classList.remove("hidden");
                    updateButtons();
                    return;
                }
            }

            // se sto in calendario, passo a kanban
            const calendarView = document.getElementById("calendar-view");
            if (calendarView && !calendarView.classList.contains("hidden")) {
                toggleView("kanban-view", "calendar-view");
                return;
            }

            // se sto in gantt, passo a kanban
            const ganttView = document.getElementById("gantt-view");
            if (ganttView && !ganttView.classList.contains("hidden")) {
                toggleView("kanban-view", "gantt-view");
                return;
            }

            // Segnalazioni: vista kanban
            if (currentPage === "segnalazioni_dashboard") {
                window.location.href = "index.php?section=collaborazione&page=gestione_segnalazioni&kanban=true";
            } else if (currentPage === "gestione_segnalazioni" && getCurrentView() === "segnalazioni-list") {
                toggleView("kanban-view", "elenco-view");
            } else if (currentPage === "elenco_gare") {
                toggleView("kanban-view", "table-view");
            }
            // MOM: passa alla vista kanban
            else if (currentPage === "mom") {
                const urlParams = new URLSearchParams(window.location.search);
                const action = urlParams.get("action");

                // Se siamo in archivio (no action o action=archivio), usa switchView Global
                if (!action || action === 'archivio') {
                    if (window.momApp && typeof window.momApp.switchView === 'function') {
                        window.momApp.switchView('kanban');
                        updateButtons();
                        return;
                    }
                }

                // Logica esistente per dettaglio MOM (task view locale)
                const kanbanView = document.getElementById("kanban-view");
                const momArchivio = document.getElementById("mom-archivio");
                const momEditor = document.getElementById("mom-editor");

                // Nascondi archivio e editor
                if (momArchivio) momArchivio.classList.add("hidden");
                if (momEditor) momEditor.classList.add("hidden");

                // Mostra kanban se presente
                if (kanbanView) {
                    kanbanView.classList.remove("hidden");
                    // Forza update del layout
                    window.dispatchEvent(new Event('resize'));
                }

                updateButtons();
                return;
            }
            // Archivio: passa alla vista kanban
            else if (currentPage === "archivio_segnalazioni") {
                const url = new URL(window.location.href);
                url.searchParams.set("kanban", "true");
                url.searchParams.delete("calendar"); // rimuovi eventuale flag calendario
                window.location.href = url.toString();
                return;
            }
        });
    }

    // ——— CALENDARIO (abilitato per segnalazioni + gare + MOM) ———
    if (calendarButton) {
        const calendarEnabled = (section === 'collaborazione' && (document.getElementById('table-view') || document.getElementById('kanban-view'))) || ["elenco_gare", "mom"].includes(currentPage);
        const freshCalendar = safeRebind(calendarButton);

        if (calendarEnabled) {
            freshCalendar.classList.remove("disabled");
            freshCalendar.setAttribute("data-tooltip", "vista calendario");
            freshCalendar.addEventListener("click", () => {
                // MOM Global Calendar
                if (currentPage === 'mom') {
                    const urlParams = new URLSearchParams(window.location.search);
                    const action = urlParams.get("action");
                    if (!action || action === 'archivio') {
                        if (window.momApp && typeof window.momApp.switchView === 'function') {
                            window.momApp.switchView('calendar');
                            updateButtons(); // Aggiorna stato active dei bottoni
                            return;
                        }
                    }
                }

                // Se c'è il container in pagina → toggle in-page
                if (document.getElementById("calendar-view")) {
                    showCalendarView();
                    return;
                }
                // Altrimenti navighiamo con flag calendar=true
                const url = new URL(window.location.href);
                url.searchParams.set("calendar", "true");
                window.location.href = url.toString();
            });
        } else {
            freshCalendar.classList.add("disabled");
        }
    }

    // ——— GANTT (abilitato ovunque è abilitato il CALENDARIO) ———
    if (ganttButton) {
        // riusiamo lo stesso criterio del calendario
        const calendarEnabledPages = ["segnalazioni_dashboard", "gestione_segnalazioni", "elenco_gare", "mom"];
        const ganttEnabled = calendarEnabledPages.includes(currentPage);
        const freshGantt = safeRebind(ganttButton);

        // helper: provider “coerente con pagina”
        function pickProviderForCurrentPage() {
            // 1) se hai un registry di provider per pagina
            if (window.calendarProviders && typeof currentPage === 'string' && window.calendarProviders[currentPage]) {
                return window.calendarProviders[currentPage];
            }
            // 2) fallback globale
            if (typeof window.calendarDataProvider === 'function') return window.calendarDataProvider;
            // 3) lascio null → i componenti useranno i propri default
            return null;
        }

        function openSplitCalGanttGeneric() {
            const cv = document.getElementById('calendar-view');
            const gv = document.getElementById('gantt-view');
            if (!cv || !gv) return;

            const provider = pickProviderForCurrentPage();

            // init calendario / gantt come prima
            if (window.CalendarView?.init) {
                if (!CalendarView._state || CalendarView._state.container !== cv) {
                    CalendarView.init({ containerId: 'calendar-view', provider, view: 'month' });
                } else { CalendarView.refresh?.(); }
            }
            if (window.GanttView?.init) {
                if (!GanttView._state || GanttView._state.container !== gv) {
                    GanttView.init({ containerId: 'gantt-view', provider, range: 'quarter' });
                } else { GanttView.refresh?.(); }
            }

            const parent = cv.parentElement;
            if (!parent) return;

            let wrap = parent.querySelector('.split-wrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.className = 'split-wrap';
                parent.insertBefore(wrap, cv);
            }

            // crea (una volta) il resizer
            let resizer = wrap.querySelector('.split-resizer');
            if (!resizer) {
                resizer = document.createElement('div');
                resizer.className = 'split-resizer';
                resizer.setAttribute('data-tooltip', 'Trascina per ridimensionare');
            }

            // inserisci ordine: calendar | resizer | gantt
            if (cv.parentElement !== wrap) wrap.appendChild(cv);
            if (resizer.parentElement !== wrap) wrap.appendChild(resizer);
            if (gv.parentElement !== wrap) wrap.appendChild(gv);

            // mostra split
            cv.classList.remove('hidden');
            gv.classList.remove('hidden');
            document.getElementById('table-view')?.classList.add('hidden');
            document.getElementById('kanban-view')?.classList.add('hidden');

            const pageRoot = wrap.closest('[class^="page-"]') || document.body;
            pageRoot.classList.add('split-calgantt');

            // --------- DRAG RESIZE ----------
            const STORAGE_KEY = 'splitCalGanttRatio:collaborazione_gestione_segnalazioni';
            const clampRatio = (r) => Math.min(0.85, Math.max(0.15, r));
            const loadRatio = () => {
                const v = parseFloat(localStorage.getItem(STORAGE_KEY));
                return isFinite(v) ? clampRatio(v) : 0.58;
            };
            const applyRatio = (r) => {
                const ratio = clampRatio(r);
                // usa flex-basis esplicito per evitare “salti”
                cv.style.flexGrow = gv.style.flexGrow = '0';
                cv.style.flexShrink = gv.style.flexShrink = '0';
                cv.style.flexBasis = (ratio * 100) + '%';
                gv.style.flexBasis = ((1 - ratio) * 100) + '%';
                cv.style.minWidth = gv.style.minWidth = '0';
            };

            // applica ratio salvato (una sola volta)
            applyRatio(loadRatio());

            const startDrag = (e) => {
                e.preventDefault();
                const wrapRect = wrap.getBoundingClientRect();
                const startX = (e.touches ? e.touches[0].clientX : e.clientX);

                // calcola la ratio EFFETTIVA dalle larghezze attuali (no flexBasis “auto”)
                const currentRatio = cv.getBoundingClientRect().width / wrapRect.width;
                let nextRatio = currentRatio;

                pageRoot.classList.add('is-resizing');

                let rafId = null;
                const onMove = (ev) => {
                    const x = (ev.touches ? ev.touches[0].clientX : ev.clientX);
                    const dx = x - startX;
                    nextRatio = clampRatio(currentRatio + (dx / wrapRect.width));
                    if (rafId) return;
                    rafId = requestAnimationFrame(() => {
                        rafId = null;
                        applyRatio(nextRatio);
                    });
                };
                const onUp = () => {
                    pageRoot.classList.remove('is-resizing');
                    cancelAnimationFrame(rafId);
                    localStorage.setItem(STORAGE_KEY, String(nextRatio));
                    window.removeEventListener('mousemove', onMove);
                    window.removeEventListener('mouseup', onUp);
                    window.removeEventListener('touchmove', onMove);
                    window.removeEventListener('touchend', onUp);
                    // ricalcola layout interni dopo resize
                    CalendarView?.refresh?.();
                    GanttView?.refresh?.();
                };

                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onUp);
                window.addEventListener('touchmove', onMove, { passive: false });
                window.addEventListener('touchend', onUp);
            };

            resizer.onmousedown = startDrag;
            resizer.ontouchstart = startDrag;

            if (typeof updateButtons === 'function') updateButtons();
        }

        if (ganttEnabled) {
            freshGantt.classList.remove("disabled");
            freshGantt.setAttribute("data-tooltip", "vista Gantt");

            freshGantt.addEventListener("click", () => {
                const gv = document.getElementById("gantt-view");

                // Sempre vista Gantt singola - niente split
                if (gv) {
                    showGanttView();
                    return;
                }

                // se manca il container → naviga con flag
                const url = new URL(window.location.href);
                url.searchParams.set("gantt", "true");
                window.location.href = url.toString();
            });
        } else {
            freshGantt.classList.add("disabled");
        }
    }

    function getCurrentView() {
        const kanbanView = document.getElementById("kanban-view");
        const tableView = document.getElementById("table-view");
        const segnalazioniList = document.getElementById("elenco-view");
        const calendarView = document.getElementById("calendar-view");
        const ganttView = document.getElementById("gantt-view");
        const momArchivio = document.getElementById("mom-archivio");
        const momEditor = document.getElementById("mom-editor");

        // MOM Global Views (archivio)
        const momArchiveWrapper = document.getElementById("mom-archive-view-wrapper");
        const momGlobalKanban = document.getElementById("mom-global-kanban");
        const momGlobalCalendar = document.getElementById("mom-global-calendar");

        if (ganttView && !ganttView.classList.contains("hidden")) return "gantt";
        if (calendarView && !calendarView.classList.contains("hidden")) return "calendar";

        // MOM: controlla prima le viste globali specifiche (archivio)
        if (currentPage === "mom") {
            // Viste globali dell'archivio MOM
            if (momGlobalCalendar && !momGlobalCalendar.classList.contains("hidden")) return "mom-calendar";
            if (momGlobalKanban && !momGlobalKanban.classList.contains("hidden")) return "mom-kanban";
            if (momArchiveWrapper && !momArchiveWrapper.classList.contains("hidden")) return "mom-list";
            // Dettaglio MOM (editor/viewer)
            if (momEditor && !momEditor.classList.contains("hidden")) return "mom-editor";
            if (momArchivio && !momArchivio.classList.contains("hidden")) return "mom-archivio";
        }

        if (kanbanView && !kanbanView.classList.contains("hidden")) return "kanban";
        if (tableView && !tableView.classList.contains("hidden")) return "list";
        if (segnalazioniList && !segnalazioniList.classList.contains("hidden")) return "segnalazioni-list";
        return "dashboard";
    }

    function toggleView(showId /*, hideId ignorato per robustezza */) {
        const ALL_VIEWS = [
            "table-view",
            "kanban-view",
            "elenco-view",
            "org-tree-view",
            "org-table-view",
            "calendar-view",
            "gantt-view",
            "mom-archivio",
            "mom-editor"
        ];
        ALL_VIEWS.forEach(id => {
            if (id !== showId) document.getElementById(id)?.classList.add("hidden");
        });
        document.getElementById(showId)?.classList.remove("hidden");
        if (typeof updateButtons === "function") updateButtons();
    }

    function updateButtons() {
        const currentView = getCurrentView();
        const validListPages = [
            "elenco_gare",
            "gestione_segnalazioni",
            "segnalazioni_dashboard",
            "commessa",
            "view_form",
            "form_viewer",
            "archivio_segnalazioni",
            "mom"
        ];

        // === RESET INIZIALE: disabilita tutti i bottoni ===
        if (archiveButton) archiveButton.classList.add("disabled");
        if (listButton) listButton.classList.add("disabled");
        if (kanbanButton) kanbanButton.classList.add("disabled");

        const cb = document.getElementById("calendarButton");
        if (cb) cb.classList.add("disabled");

        const gb = document.getElementById("ganttButton");
        if (gb) gb.classList.add("disabled");

        if (!validListPages.includes(currentPage)) return;

        // === CONFIGURAZIONE PAGINE ===
        const calendarAllowedPages = ["segnalazioni_dashboard", "gestione_segnalazioni", "elenco_gare", "mom"];
        const ganttAllowedPages = ["segnalazioni_dashboard", "gestione_segnalazioni", "elenco_gare"];

        const calendarAllowed = calendarAllowedPages.includes(currentPage);
        const ganttAllowed = ganttAllowedPages.includes(currentPage);

        // Controlla esistenza elementi DOM
        const hasTable = !!document.getElementById("table-view") || !!document.getElementById("elenco-view");
        const hasKanban = !!document.getElementById("kanban-view");
        const hasCalendar = !!document.getElementById("calendar-view");
        const hasGantt = !!document.getElementById("gantt-view");

        // MOM: contenitori specifici
        const hasMomList = !!document.getElementById("mom-archive-view-wrapper");
        const hasMomKanban = !!document.getElementById("mom-global-kanban");
        const hasMomCalendar = !!document.getElementById("mom-global-calendar");

        // === ABILITA BOTTONI (tranne quello della vista corrente) ===

        // TABELLA/LISTA: abilita se non sei su quella vista
        // MOM: abilita listButton solo se siamo su kanban o calendar (non quando siamo già su lista)
        if (listButton) {
            if (currentPage === "mom" && hasMomList) {
                // MOM: abilita listButton solo quando NON siamo sulla lista
                if (currentView === "mom-kanban" || currentView === "mom-calendar") {
                    listButton.classList.remove("disabled");
                    listButton.setAttribute("data-tooltip", "vista elenco");
                }
                // Se siamo su mom-list, il bottone rimane disabilitato (stato iniziale)
            } else if (hasTable && currentView !== "list" && currentView !== "segnalazioni-list") {
                listButton.classList.remove("disabled");
                listButton.setAttribute("data-tooltip", "vista tabella");
            }
        }

        // KANBAN: abilita se non sei su quella vista
        // Per elenco_gare e mom, abilita kanban se c'è il container o se siamo in list view
        // MOM: abilita kanbanButton solo se NON siamo su kanban
        if (kanbanButton) {
            if (currentPage === "mom" && (hasMomKanban || hasMomList)) {
                // MOM: abilita kanban se non siamo già su mom-kanban
                if (currentView !== "mom-kanban" && currentView !== "kanban") {
                    kanbanButton.classList.remove("disabled");
                    kanbanButton.setAttribute("data-tooltip", "vista kanban");
                }
            } else if ((hasKanban || currentPage === "elenco_gare") && currentView !== "kanban") {
                kanbanButton.classList.remove("disabled");
                kanbanButton.setAttribute("data-tooltip", "vista kanban");
            }
        }

        // CALENDARIO: abilita se permesso dalla pagina E non sei su quella vista
        // MOM: disabilita calendarButton quando siamo su mom-calendar
        if (cb && calendarAllowed && (hasCalendar || hasMomCalendar || currentPage === "segnalazioni_dashboard" || currentPage === "gestione_segnalazioni" || currentPage === "elenco_gare" || currentPage === "mom")) {
            if (currentPage === "mom") {
                // MOM: abilita calendario solo se NON siamo su mom-calendar
                if (currentView !== "mom-calendar" && currentView !== "calendar") {
                    cb.classList.remove("disabled");
                    cb.setAttribute("data-tooltip", "vista calendario");
                }
            } else if (currentView !== "calendar") {
                cb.classList.remove("disabled");
                cb.setAttribute("data-tooltip", "vista calendario");
            }
        }

        // GANTT: abilita se permesso dalla pagina E non sei su quella vista
        if (gb && ganttAllowed) {
            if (currentView !== "gantt") {
                gb.classList.remove("disabled");
                gb.setAttribute("data-tooltip", "vista Gantt");
            }
        }

        // --- commessa: organigramma ---
        if (currentPage === "commessa" && urlParams.get("view") === "organigramma") {
            const vtree = document.getElementById("org-tree-view");
            const vtable = document.getElementById("org-table-view");

            // leggi bottoni correnti; se non esistono, esci pulito
            let lb = document.getElementById("listButton");
            let kb = document.getElementById("kanbanButton");
            if (!lb || !kb) return;

            // rimuovi eventuali vecchi listener in modo sicuro
            lb = safeRebind(lb) || lb;
            kb = safeRebind(kb) || kb;

            // reset stato base + tooltip coerenti
            lb.classList.remove("disabled");
            kb.classList.remove("disabled");
            lb.setAttribute("aria-disabled", "false");
            kb.setAttribute("aria-disabled", "false");
            lb.setAttribute("data-tooltip", "vista tabella");
            kb.setAttribute("data-tooltip", "vista albero");

            const showTable = () => {
                if (!vtree || !vtable) return;
                vtree.classList.add("hidden");
                vtable.classList.remove("hidden");
                if (typeof updateButtons === "function") updateButtons();
            };
            const showTree = () => {
                if (!vtree || !vtable) return;
                vtable.classList.add("hidden");
                vtree.classList.remove("hidden");
                if (typeof window.adaptTreeZoom === "function") setTimeout(window.adaptTreeZoom, 60);
                if (typeof updateButtons === "function") updateButtons();
            };

            // abilita solo il bottone "opposto" alla vista attuale
            if (vtable && !vtable.classList.contains("hidden")) {
                // sei in tabella -> abilita albero
                lb.classList.add("disabled");
                lb.setAttribute("aria-disabled", "true");
                kb.addEventListener("click", showTree);
            } else {
                // sei in albero -> abilita tabella
                kb.classList.add("disabled");
                kb.setAttribute("aria-disabled", "true");
                lb.addEventListener("click", showTable);
            }
            return;
        }

        // --- COMMESSA: elenco documenti sicurezza (sic_elenco_doc)
        else if (currentPage === 'commessa' && urlParams.get('view') === 'sic_elenco_doc') {
            const modal = document.getElementById('modal-sic-doc');
            let btn = safeRebind(document.getElementById("addButton")) || document.getElementById("addButton");
            if (!btn) return;

            // attivo il + in modo esplicito
            btn.classList.remove("disabled");
            btn.removeAttribute("disabled"); // nel caso qualche css/js l’abbia messo
            btn.setAttribute("data-tooltip", "Aggiungi documento sicurezza");

            // pulisco eventuali handler residui clonando di nuovo (sicuro-idempotente)
            btn = safeRebind(btn) || document.getElementById("addButton");

            // handler robusto: prova toggleModal, poi forza le classi comunque
            btn.addEventListener("click", () => {
                try {
                    if (typeof toggleModal === "function") {
                        toggleModal("modal-sic-doc", "open");
                    }
                } catch (e) {
                    console.warn("toggleModal ha lanciato:", e);
                }

                // forza comunque lo stato 'aperto' per evitare discrep. tra implementazioni diverse
                const m = document.getElementById('modal-sic-doc');
                if (m) {
                    m.classList.remove("hidden");
                    m.classList.add("show");
                    m.setAttribute("aria-hidden", "false");
                } else {
                    console.warn("Modal #modal-sic-doc non presente nel DOM al click.");
                }
            });
        }

        if (["view_form", "form_viewer"].includes(currentPage)) {
            listButton.classList.remove("disabled");
            kanbanButton.classList.remove("disabled");

            listButton.onclick = null;
            kanbanButton.onclick = null;

            listButton.onclick = () => {
                const formName = urlParams.get("form_name");
                if (formName) {
                    window.location.href = `index.php?section=collaborazione&page=gestione_segnalazioni&form_name=${encodeURIComponent(formName)}`;
                } else {
                    window.location.href = `index.php?section=collaborazione&page=gestione_segnalazioni`;
                }
            };
            kanbanButton.onclick = () => {
                const formName = urlParams.get("form_name");
                if (formName) {
                    window.location.href = `index.php?section=collaborazione&page=gestione_segnalazioni&kanban=true&form_name=${encodeURIComponent(formName)}`;
                } else {
                    window.location.href = `index.php?section=collaborazione&page=gestione_segnalazioni&kanban=true`;
                }
            };
            return;
        }

        // --- commessa (task) ---
        if (currentPage === "commessa" && urlParams.get("view") === "task") {
            const kanbanView = document.getElementById("kanban-view");
            const tableView = document.getElementById("table-view");

            listButton.onclick = null;
            kanbanButton.onclick = null;

            if (kanbanView && !kanbanView.classList.contains("hidden")) {
                listButton.classList.remove("disabled");
                kanbanButton.classList.add("disabled");
                listButton.onclick = () => {
                    kanbanView.classList.add("hidden");
                    tableView.classList.remove("hidden");
                    updateButtons();
                };
                return;
            }
            if (tableView && !tableView.classList.contains("hidden")) {
                kanbanButton.classList.remove("disabled");
                listButton.classList.add("disabled");
                kanbanButton.onclick = () => {
                    tableView.classList.add("hidden");
                    kanbanView.classList.remove("hidden");
                    updateButtons();
                };
                return;
            }
            listButton.classList.remove("disabled");
            kanbanButton.classList.remove("disabled");
            return;
        }

        // --- ALTRE PAGINE: LOGICA ORIGINALE ---
        if (["kanban"].includes(currentView)) {
            // abilita "elenco" solo se stai davvero vedendo un kanban
            listButton.classList.remove("disabled");
        }
        if (
            ["segnalazioni_dashboard"].includes(currentPage) ||
            ["list", "segnalazioni-list"].includes(currentView)
        ) {
            kanbanButton.classList.remove("disabled");
        }
    }

    // Se l'URL chiede espressamente la vista Calendario / Gantt
    if (urlParams.get("calendar") === "true") {
        // se manca il container, crealo in modo minimale vicino alla tabella o al kanban
        if (!document.getElementById("calendar-view")) {
            const anchor = document.getElementById("table-view") || document.getElementById("kanban-view") || document.body;
            const cv = document.createElement("div");
            cv.id = "calendar-view";
            cv.className = ""; // visibile; showCalendarView gestisce le altre viste
            // inserisco prima dell'anchor per tenerlo “in alto” come su gare
            anchor.parentNode.insertBefore(cv, anchor);
        }
        showCalendarView();
    }



    // Gestione generica pulsante Stampa / Esporta
    // Se esiste un bottone "Stampa / Esporta" e non è stato già specializzato (es. con id specifico)
    const genericPrintBtn = document.querySelector('.button-group button[data-tooltip="Stampa / Esporta"]');
    if (genericPrintBtn && !genericPrintBtn.id) {
        genericPrintBtn.classList.remove('disabled');
        const freshPrint = safeRebind(genericPrintBtn);
        freshPrint.setAttribute('data-tooltip', 'Stampa / Esporta');
        freshPrint.addEventListener('click', async (e) => {
            e.preventDefault();
            // Priorità:
            // 1. window.handleExportOrPrint (definito dalla pagina specifica, es. MOM o Gare)
            // 2. window.printDocument (vecchia api)
            // 3. window.print() (browser nativo)
            if (typeof window.handleExportOrPrint === 'function') {
                await window.handleExportOrPrint();
            } else if (typeof window.printDocument === 'function') {
                window.printDocument();
            } else {
                window.print();
            }
        });
    }

    if (urlParams.get("gantt") === "true") {
        // se manca il container, crealo in modo minimale vicino alla tabella o al kanban
        if (!document.getElementById("gantt-view")) {
            const anchor = document.getElementById("table-view") || document.getElementById("kanban-view") || document.body;
            const gv = document.createElement("div");
            gv.id = "gantt-view";
            gv.className = ""; // visibile; showGanttView gestisce le altre viste
            // inserisco prima dell'anchor per coerenza layout/split
            anchor.parentNode.insertBefore(gv, anchor);
        }
        showGanttView();
    }

    window.updateButtons = updateButtons;
    updateButtons();
});
