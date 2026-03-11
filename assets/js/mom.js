/**
 * MOM APP - Gestione Verbali Riunione
 * 
 * Gestisce archivio, editor, viewer, upload allegati, export PDF
 */

window.momApp = (function () {
    'use strict';

    let currentMomId = 0;
    let isEditMode = false;
    let pendingFiles = []; // File in attesa di essere caricati al salvataggio
    let currentView = 'list'; // 'list', 'kanban', 'calendar'

    /**
     * Helper check permessi frontend
     */
    function userHasPermission(perm) {
        // Usa la funzione globale definita in main.php se disponibile
        if (typeof window.userHasPermission === 'function') {
            return window.userHasPermission(perm);
        }

        // Fallback locale robusto (copia logica main.php)
        const user = window.CURRENT_USER;
        if (!user) return false;

        // Admin
        if (user.is_admin === true || user.is_admin === '1' || user.is_admin === 1) return true;

        // Role ID 1 (Admin Legacy)
        const roleId = user.role_id || user.roleId;
        if (roleId === 1 || roleId === '1') return true;

        // Permessi espliciti o wildcard
        const perms = user.permissions || [];
        return Array.isArray(perms) && (perms.includes(perm) || perms.includes('*'));
    }

    /**
     * Assicura che i dati utente siano caricati
     */
    async function ensureUserLoaded() {
        if (window.CURRENT_USER) return true;

        try {
            const response = await customFetch('mom', 'getUser');
            if (response.success && response.data) {
                window.CURRENT_USER = response.data;
                // Aggiorna anche variabile globale permessi se necessario
                if (response.data.permissions) {
                    window.CURRENT_USER_PERMISSIONS = response.data.permissions;
                }
                console.log('[MOM] User loaded via API:', window.CURRENT_USER);
                return true;
            }
        } catch (e) {
            console.error('[MOM] Error loading user:', e);
        }
        return false;
    }

    /**
     * Inizializza archivio
     */
    function initArchivio(contextType, contextId) {
        // Imposta filtri context se passati come parametri
        if (contextType && contextId) {
            let filtroContextType = document.getElementById('filtro-context-type');
            let filtroContextId = document.getElementById('filtro-context-id');

            // Se i campi non esistono, creali
            if (!filtroContextType) {
                filtroContextType = document.createElement('input');
                filtroContextType.type = 'hidden';
                filtroContextType.id = 'filtro-context-type';
                const filtriContainer = document.querySelector('.row.half-width') || document.getElementById('mom-archivio');
                if (filtriContainer) {
                    filtriContainer.appendChild(filtroContextType);
                }
            }

            if (!filtroContextId) {
                filtroContextId = document.createElement('input');
                filtroContextId.type = 'hidden';
                filtroContextId.id = 'filtro-context-id';
                const filtriContainer = document.querySelector('.row.half-width') || document.getElementById('mom-archivio');
                if (filtriContainer) {
                    filtriContainer.appendChild(filtroContextId);
                }
            }

            // Imposta i valori (assicurati che siano stringhe)
            filtroContextType.value = String(contextType);
            filtroContextId.value = String(contextId);

            console.log('[MOM] initArchivio - contextType:', filtroContextType.value, 'contextId:', filtroContextId.value);
        }

        // Event listeners per filtri
        const filtroTesto = document.getElementById('filtro-testo');
        if (filtroTesto) {
            filtroTesto.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    caricaArchivio();
                }
            });
        }

        // Filtri select: applica subito al cambio selezione
        // Area e Codice → filtro client-side (no richiesta server)
        ['filtro-area', 'filtro-codice'].forEach(function (id) {
            var sel = document.getElementById(id);
            if (sel) {
                sel.addEventListener('change', function () {
                    applyClientFilters();
                });
            }
        });

        // Stato → richiesta server (il backend legge 'stato')
        var filtroStato = document.getElementById('filtro-stato');
        if (filtroStato) {
            filtroStato.addEventListener('change', function () {
                caricaArchivio();
            });
        }
    }

    /**
     * Ottiene la sezione corrente dalla URL o dal campo hidden
     */
    function getCurrentSection() {
        // Prima prova dal campo hidden
        const hiddenSection = document.getElementById('filtro-section')?.value ||
            document.getElementById('mom-section')?.value;
        if (hiddenSection) {
            return hiddenSection;
        }
        // Fallback: leggi dalla URL
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('section') || 'collaborazione';
    }

    /**
     * Costruisce l'oggetto filtri MOM leggendo i campi DOM.
     * Single source of truth per caricaArchivio e loadGlobalView.
     */
    function buildMomFilters() {
        return {
            stato: document.getElementById('filtro-stato')?.value || '',
            dataDa: document.getElementById('filtro-data-da')?.value || '',
            dataA: document.getElementById('filtro-data-a')?.value || '',
            testo: document.getElementById('filtro-testo')?.value || '',
            filterSection: getCurrentSection(),
            contextType: document.getElementById('filtro-context-type')?.value || '',
            contextId: document.getElementById('filtro-context-id')?.value || ''
        };
    }

    /**
     * Carica archivio con filtri
     * @param {Object} [overrideFilters] - filtri opzionali che sovrascrivono quelli DOM
     */
    async function caricaArchivio(overrideFilters) {
        // Se non siamo in vista lista, delega a loadGlobalView
        if (currentView !== 'list') {
            loadGlobalView(currentView);
            return;
        }

        const filtri = { ...buildMomFilters(), ...(overrideFilters || {}) };

        // Debug: verifica valori
        console.log('[MOM] Caricamento archivio:', {
            currentSection: filtri.filterSection,
            filtri: filtri
        });



        await ensureUserLoaded();

        try {
            const response = await customFetch('mom', 'getArchivio', filtri);

            // DEBUG: mostra info di debug dal server
            if (response._debug) {
                console.log('[MOM] DEBUG risposta server:', response._debug);
            }

            if (!response.success) {
                showToast(response.message || 'Errore nel caricamento archivio', 'error');
                return;
            }

            const tbody = document.getElementById('mom-archivio-tbody');
            if (!tbody) return;

            if (response.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center">Nessun MOM trovato</td></tr>';
                return;
            }

            tbody.innerHTML = response.data.map(mom => {
                const dataFormatted = mom.dataMeeting ? new Date(mom.dataMeeting + 'T00:00:00').toLocaleDateString('it-IT') : '-';
                const protocollo = mom.progressivoCompleto || (mom.progressivo + '/' + mom.anno);

                // Stati disponibili per il select
                const statiMom = {
                    'bozza': 'Bozza',
                    'in_revisione': 'In Revisione',
                    'chiuso': 'Chiuso'
                };

                // Classi colore per gli stati
                const statoClassMap = {
                    'bozza': 'bozza',
                    'in_revisione': 'in_corso',
                    'chiuso': 'chiusa'
                };

                let azioni = '';
                // Edit
                const canEdit = (userHasPermission('edit_mom') || window.CURRENT_USER?.is_admin);
                if (canEdit) {
                    azioni += `<button class="action-icon" type="button" onclick="momApp.apriMom(${mom.id}, 'edit')" data-tooltip="Modifica verbale">
                        <img src="assets/icons/edit.png" alt="Modifica">
                    </button>`;
                }

                // Clone (richiede permesso edit per creare copia)
                if (canEdit) {
                    azioni += `<button class="action-icon clone-btn" type="button" onclick="momApp.clonaMom(${mom.id})" data-tooltip="Duplica verbale">
                        <img src="assets/icons/copy.png" alt="Duplica">
                    </button>`;
                }

                // Delete
                if (userHasPermission('delete_mom') || window.CURRENT_USER?.is_admin) {
                    azioni += `<button class="action-icon" type="button" onclick="momApp.eliminaMom(${mom.id})" data-tooltip="Elimina verbale">
                        <img src="assets/icons/delete.png" alt="Elimina">
                    </button>`;
                }

                // Crea link per il titolo
                let titoloCell;
                if (mom.titolo && mom.id) {
                    titoloCell = `<a href="#" onclick="momApp.apriMom(${mom.id}, 'view'); return false;" style="cursor: pointer; text-decoration: underline; color: #2980b9;">${escapeHtml(mom.titolo)}</a>`;
                } else {
                    titoloCell = escapeHtml(mom.titolo || '-');
                }

                // Colonna stato: select se può editare, altrimenti badge
                let statoCell;
                const currentUserId = String(window.CURRENT_USER?.id || window.CURRENT_USER?.user_id || '');
                const isCreatore = mom.creatore_id && String(mom.creatore_id) === currentUserId;
                const canChangeStatus = canEdit || isCreatore;

                if (canChangeStatus) {
                    const statoAttuale = mom.stato || 'bozza';
                    const statoClass = statoClassMap[statoAttuale] || 'bozza';
                    const options = Object.entries(statiMom).map(([value, label]) =>
                        `<option value="${value}" ${statoAttuale === value ? 'selected' : ''}>${label}</option>`
                    ).join('');
                    statoCell = `<select class="stato-select ${statoClass}" data-mom-id="${mom.id}" data-prev-value="${statoAttuale}">${options}</select>`;
                } else {
                    const statoLabel = statiMom[mom.stato] || mom.stato || 'Bozza';
                    const statoClass = statoClassMap[mom.stato] || 'bozza';
                    statoCell = `<span class="badge badge-${statoClass}">${statoLabel}</span>`;
                }

                return `
                    <tr data-mom-id="${mom.id}" data-area="${escapeHtml(mom.contextType || '')}" data-codice="${escapeHtml(mom.codice || '')}">
                        <td>${azioni}</td>
                        <td>${escapeHtml(protocollo)}</td>
                        <td>${escapeHtml(mom.contextType || '-')}</td>
                        <td>${escapeHtml(mom.codice || '-')}</td>
                        <td>${titoloCell}</td>
                        <td>${dataFormatted}</td>
                        <td>${escapeHtml(mom.luogo || '-')}</td>
                        <td class="stato-colonna">${statoCell}</td>
                        <td>${escapeHtml(mom.creatoreNome || '-')}</td>
                    </tr>
                `;
            }).join('');

            // Aggiungi event listeners per i select stato
            tbody.querySelectorAll('select.stato-select').forEach(select => {
                select.addEventListener('change', async function () {
                    const momId = this.getAttribute('data-mom-id');
                    const nuovoStato = this.value;
                    const prevStato = this.getAttribute('data-prev-value');

                    // Aggiorna classe colore immediatamente
                    const statoClassMap = {
                        'bozza': 'bozza',
                        'in_revisione': 'in_corso',
                        'chiuso': 'chiusa'
                    };
                    ['bozza', 'in_corso', 'chiusa'].forEach(c => this.classList.remove(c));
                    this.classList.add(statoClassMap[nuovoStato] || 'bozza');

                    try {
                        const result = await customFetch('mom', 'updateMomStatus', {
                            momId: momId,
                            stato: nuovoStato
                        });

                        if (result.success) {
                            this.setAttribute('data-prev-value', nuovoStato);
                            showToast('Stato aggiornato', 'success');
                        } else {
                            // Rollback
                            this.value = prevStato;
                            ['bozza', 'in_corso', 'chiusa'].forEach(c => this.classList.remove(c));
                            this.classList.add(statoClassMap[prevStato] || 'bozza');
                            showToast(result.message || 'Errore aggiornamento stato', 'error');
                        }
                    } catch (err) {
                        // Rollback
                        this.value = prevStato;
                        ['bozza', 'in_corso', 'chiusa'].forEach(c => this.classList.remove(c));
                        this.classList.add(statoClassMap[prevStato] || 'bozza');
                        showToast('Errore di rete', 'error');
                    }
                });
            });

            // Popola dropdown Area e Codice dai dati ricevuti
            populateClientFilterDropdowns(response.data);
            // Applica eventuali filtri client già selezionati
            applyClientFilters();

        } catch (error) {
            console.error('Errore caricamento archivio:', error);
            showToast('Errore nel caricamento archivio', 'error');
        }
    }

    /**
     * Popola i <select> filtro-area e filtro-codice con i valori distinti
     * presenti nei dati, mantenendo la selezione corrente se ancora valida.
     */
    function populateClientFilterDropdowns(data) {
        var areaSelect = document.getElementById('filtro-area');
        var codiceSelect = document.getElementById('filtro-codice');
        if (!areaSelect || !codiceSelect) return;

        // Raccogli valori distinti non vuoti
        var areeSet = new Set();
        var codiciSet = new Set();
        (data || []).forEach(function (mom) {
            if (mom.contextType) areeSet.add(mom.contextType);
            if (mom.codice) codiciSet.add(mom.codice);
        });

        // Salva selezione corrente
        var prevArea = areaSelect.value;
        var prevCodice = codiceSelect.value;

        // Ricostruisci opzioni — Area
        areaSelect.innerHTML = '<option value="">Tutte</option>';
        Array.from(areeSet).sort().forEach(function (val) {
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val;
            areaSelect.appendChild(opt);
        });

        // Ricostruisci opzioni — Codice
        codiceSelect.innerHTML = '<option value="">Tutti</option>';
        Array.from(codiciSet).sort().forEach(function (val) {
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val;
            codiceSelect.appendChild(opt);
        });

        // Ripristina selezione se ancora valida
        if (prevArea && areeSet.has(prevArea)) areaSelect.value = prevArea;
        if (prevCodice && codiciSet.has(prevCodice)) codiceSelect.value = prevCodice;
    }

    /**
     * Filtra le righe della tabella archivio in base ai select Area e Codice.
     * Nasconde le <tr> che non corrispondono.
     */
    function applyClientFilters() {
        var areaVal = (document.getElementById('filtro-area')?.value || '').toLowerCase();
        var codiceVal = (document.getElementById('filtro-codice')?.value || '').toLowerCase();

        var tbody = document.getElementById('mom-archivio-tbody');
        if (!tbody) return;

        tbody.querySelectorAll('tr[data-mom-id]').forEach(function (tr) {
            var rowArea = (tr.getAttribute('data-area') || '').toLowerCase();
            var rowCodice = (tr.getAttribute('data-codice') || '').toLowerCase();

            var matchArea = !areaVal || rowArea === areaVal;
            var matchCodice = !codiceVal || rowCodice === codiceVal;

            tr.style.display = (matchArea && matchCodice) ? '' : 'none';
        });
    }

    /**
     * Reset filtri
     */
    function resetFiltri() {
        var el;
        el = document.getElementById('filtro-area');     if (el) el.value = '';
        el = document.getElementById('filtro-codice');   if (el) el.value = '';
        el = document.getElementById('filtro-stato');    if (el) el.value = '';
        el = document.getElementById('filtro-data-da');  if (el) el.value = '';
        el = document.getElementById('filtro-data-a');   if (el) el.value = '';
        el = document.getElementById('filtro-testo');    if (el) el.value = '';
        caricaArchivio();
    }

    /**
     * Apri MOM in modalità view o edit
     */
    function apriMom(momId, mode) {
        const url = new URL(window.location);
        const currentSection = getCurrentSection();
        url.searchParams.set('section', currentSection);
        url.searchParams.set('page', 'mom');
        url.searchParams.set('id', momId);
        url.searchParams.set('action', mode);

        // Preserva contextType e contextId se presenti
        const contextType = document.getElementById('filtro-context-type')?.value;
        const contextId = document.getElementById('filtro-context-id')?.value;
        if (contextType && contextId) {
            url.searchParams.set('contextType', contextType);
            url.searchParams.set('contextId', contextId);
        }

        window.location.href = url.toString();
    }

    /**
     * Nuovo MOM
     */
    function nuovoMom() {
        const url = new URL(window.location);
        const currentSection = getCurrentSection();
        url.searchParams.set('section', currentSection);
        url.searchParams.set('page', 'mom');
        url.searchParams.delete('id');
        url.searchParams.set('action', 'edit');

        // Preserva contextType e contextId se presenti
        const contextType = document.getElementById('filtro-context-type')?.value;
        const contextId = document.getElementById('filtro-context-id')?.value;
        if (contextType && contextId) {
            url.searchParams.set('contextType', contextType);
            url.searchParams.set('contextId', contextId);
        }

        window.location.href = url.toString();
    }

    /**
     * Torna all'archivio
     */
    function tornaArchivio() {
        // Nascondi BottomBar uscendo dall'editor
        aggiornaBottomBar(false);

        const url = new URL(window.location);
        const currentSection = getCurrentSection();
        url.searchParams.set('section', currentSection);
        url.searchParams.set('page', 'mom');
        url.searchParams.delete('id');
        url.searchParams.set('action', 'archivio');

        // Preserva contextType e contextId se presenti (dal form o dall'URL corrente)
        const contextType = document.getElementById('mom-context-type')?.value ||
            document.getElementById('filtro-context-type')?.value ||
            url.searchParams.get('contextType');
        const contextId = document.getElementById('mom-context-id')?.value ||
            document.getElementById('filtro-context-id')?.value ||
            url.searchParams.get('contextId');
        if (contextType && contextId) {
            url.searchParams.set('contextType', contextType);
            url.searchParams.set('contextId', contextId);
        }

        window.location.href = url.toString();
    }

    /**
     * Carica dettaglio MOM
     */
    async function caricaDettaglio(momId, editMode) {
        currentMomId = momId;
        isEditMode = editMode;

        try {
            const response = await customFetch('mom', 'getDettaglio', { momId: momId });

            if (!response.success) {
                showToast(response.message || 'Errore nel caricamento dettaglio', 'error');
                return;
            }

            const mom = response.data;

            // Popola form
            document.getElementById('mom-id').value = mom.id;
            document.getElementById('mom-titolo').value = mom.titolo || '';
            document.getElementById('mom-data-meeting').value = mom.dataMeeting || '';
            document.getElementById('mom-ora-inizio').value = mom.oraInizio || '';
            document.getElementById('mom-ora-fine').value = mom.oraFine || '';
            document.getElementById('mom-luogo').value = mom.luogo || '';
            document.getElementById('mom-note').value = mom.note || '';
            document.getElementById('mom-stato-select').value = mom.stato || 'bozza';

            // Aggiorna context type e context id dal database
            if (mom.contextType) {
                document.getElementById('mom-context-type').value = mom.contextType;
            }
            if (mom.contextId) {
                document.getElementById('mom-context-id').value = mom.contextId;
            }

            // Protocollo display
            const protocolloEl = document.getElementById('mom-progressivo-display');
            if (protocolloEl) {
                if (mom.progressivoCompleto) {
                    protocolloEl.value = mom.progressivoCompleto;
                } else if (mom.codice && mom.anno) {
                    const progressivoStr = String(mom.progressivo || 0).padStart(3, '0');
                    const annoShort = String(mom.anno).slice(-2);
                    // Formato: MOM_{codice}_{progressivo}_{anno}
                    protocolloEl.value = `MOM_${mom.codice}_${progressivoStr}_${annoShort}`;
                } else {
                    protocolloEl.value = (mom.progressivo || 0) + '/' + (mom.anno || new Date().getFullYear());
                }
            }

            // Area e Codice
            if (mom.codice || mom.area) {
                const area = mom.area || 'commessa'; // Default a commessa se non specificato
                const codice = mom.codice || '';

                // Imposta subito il valore nel campo hidden
                const codiceInput = document.getElementById('mom-codice-protocollo-value');
                if (codiceInput && codice) {
                    codiceInput.value = codice;
                }

                // Imposta flag di inizializzazione per prevenire apertura automatica dropdown
                if (typeof setupDropdownCodice.setInitializing === 'function') {
                    setupDropdownCodice.setInitializing(true);
                }

                const areaSelect = document.getElementById('mom-area');
                if (areaSelect) {
                    areaSelect.value = area;
                    // Trigger change per aggiornare dropdown codice (ma non aprirlo)
                    areaSelect.dispatchEvent(new Event('change'));
                }

                // Aggiorna dropdown codice dopo un breve delay per permettere il caricamento delle opzioni
                setTimeout(() => {
                    if (codice && typeof updateCodiceDisplay === 'function') {
                        updateCodiceDisplay(codice, area);
                    }
                    // Rimuovi flag di inizializzazione dopo il caricamento
                    if (typeof setupDropdownCodice.setInitializing === 'function') {
                        setupDropdownCodice.setInitializing(false);
                    }
                }, 300);
            }

            // Aggiorna badge stato
            const statoBadge = document.getElementById('mom-stato-badge');
            if (statoBadge) {
                const statoClass = {
                    'bozza': 'badge-secondary',
                    'in_revisione': 'badge-warning',
                    'chiuso': 'badge-success'
                }[mom.stato] || 'badge-secondary';

                const statoLabel = {
                    'bozza': 'Bozza',
                    'in_revisione': 'In Revisione',
                    'chiuso': 'Chiuso'
                }[mom.stato] || mom.stato;

                statoBadge.className = 'badge ' + statoClass;
                statoBadge.textContent = statoLabel;
            }

            // Disabilita campi: editabile se in edit mode E (è bozza O ha permesso edit_mom O è admin)
            const canEdit = editMode && (mom.stato === 'bozza' || userHasPermission('edit_mom') || window.CURRENT_USER?.is_admin);
            const inputs = document.querySelectorAll('#mom-form input, #mom-form textarea, #mom-form select');
            inputs.forEach(input => {
                if (input.id !== 'mom-id' && input.id !== 'mom-context-type' && input.id !== 'mom-context-id') {
                    if (!canEdit) {
                        // Impedisce modifica ma permette selezione/copia per campi testo
                        if (input.tagName === 'SELECT' || input.type === 'checkbox' || input.type === 'radio') {
                            input.style.pointerEvents = 'none';
                        } else {
                            input.readOnly = true;
                        }
                    } else {
                        input.disabled = false;
                        input.readOnly = false;
                        input.style.pointerEvents = '';
                    }
                }
            });

            // Gestione custom select in testata
            const customSelects = document.querySelectorAll('.custom-select-box');
            customSelects.forEach(cs => {
                if (!canEdit) cs.style.pointerEvents = 'none';
                else cs.style.pointerEvents = '';
            });

            // Inizializza blocchi ripetibili
            initRepeatableBlocks(mom, canEdit, mom.itemStatuses);

            // Gestione BottomBar: mostra solo se l'utente può effettivamente modificare
            if (canEdit) {
                aggiornaBottomBar(true);
            } else {
                aggiornaBottomBar(false);
            }

            // Carica allegati
            caricaAllegati(mom.allegati || []);

            // Aggiorna titolo pagina (solo se verbale esistente con protocollo)
            if (typeof window.updateMomPageTitle === 'function') {
                window.updateMomPageTitle();
            }

            // Esporta PDF: mostra l'icona solo se il verbale è salvato
            const titleRow = document.getElementById('mom-page-title');
            if (titleRow && mom.id) {
                titleRow.classList.add('mom-page-title-visible');
                titleRow.classList.remove('mom-page-title-hidden');
            }

        } catch (error) {
            console.error('Errore caricamento dettaglio:', error);
            showToast('Errore nel caricamento dettaglio', 'error');
        }
    }

    /**
     * Inizializza blocchi ripetibili (ModQC06)
     */
    function initRepeatableBlocks(mom, canEdit, itemStatuses = []) {
        // Partecipanti (5 colonne: Società/Partecipante/Email/Telefono/Presente)
        repeatableTable.create({
            rootEl: document.getElementById('mom-partecipanti-container'),
            columns: [
                { label: 'Società', field: 'societa', type: 'customSelect', boxClass: 'mom-societa-box custom-select-box-table', placeholder: 'Seleziona società...', tooltip: 'Società del partecipante' },
                { label: 'Partecipante', field: 'partecipante', type: 'customSelect', boxClass: 'mom-partecipante-box custom-select-box-table', placeholder: 'Seleziona partecipante...', required: true, tooltip: 'Nome del partecipante' },
                { label: 'Email', field: 'email', type: 'readonly', tooltip: 'Email (da anagrafica)' },
                { label: 'Telefono', field: 'telefono', type: 'readonly', tooltip: 'Telefono (da anagrafica)' },
                { label: 'Presente', field: 'presente', type: 'checkbox', tooltip: 'Partecipante presente' }
            ],
            initialRows: (mom.partecipanti || []).map(p => ({
                id: p.id,
                societa: p.societa || '',
                partecipante: p.partecipante || '',
                email: p.email || '',
                telefono: p.telefono || '',
                presente: (p.copia_a === 1 || p.copia_a === '1' || p.copiaA === true) ? true : false,
                ordinamento: p.ordinamento || 0
            })),
            allowDelete: canEdit,
            externalAddButton: true
        });

        // Inizializza dropdown partecipanti (dopo che la tabella è nel DOM)
        setTimeout(function () {
            initPartecipantiDropdowns();
        }, 150);

        // Prepara options per gli stati degli items dal database
        const statoOptions = [{ value: '', label: 'Seleziona stato...' }];
        if (itemStatuses && itemStatuses.length > 0) {
            itemStatuses.forEach(status => {
                // Converti il nome dello stato in data-status (minuscolo con underscore)
                const dataStatus = status.name.toLowerCase().replace(/\s+/g, '_');
                statoOptions.push({
                    value: dataStatus,
                    label: status.name
                });
            });
        }

        // Items (tabella unica: AI/OBS/EVE)
        repeatableTable.create({
            rootEl: document.getElementById('mom-items-container'),
            columns: [
                {
                    label: 'Tipo', field: 'itemType', type: 'select', required: true, tooltip: 'Tipo item', options: [
                        { value: '', label: 'Seleziona tipo...' },
                        { value: 'AI', label: 'AI' },
                        { value: 'OBS', label: 'OBS' },
                        { value: 'EVE', label: 'EVE' }
                    ], boxClass: 'custom-select-box-table'
                },
                { label: 'Item', field: 'itemCode', type: 'link', tooltip: 'Codice item generato automaticamente' },
                { label: 'Titolo', field: 'titolo', type: 'text', required: true, tooltip: 'Titolo breve' },
                { label: 'Descrizione', field: 'descrizione', type: 'textarea', required: false, tooltip: 'Descrizione item' },
                { label: 'Responsabile', field: 'responsabile', type: 'participantSelect', tooltip: 'Responsabile' },
                { label: 'Data Target', field: 'dataTarget', type: 'date', tooltip: 'Data target/scadenza' },
                {
                    label: 'Stato', field: 'stato', type: 'select', tooltip: 'Stato dell\'item (sincronizzato con task se collegata)',
                    options: statoOptions,
                    boxClass: 'custom-select-box-table'
                }
            ],
            initialRows: (mom.items || []).map(item => ({
                id: item.id,
                itemType: item.item_type || 'AI',
                itemCode: item.item_code || '',
                titolo: item.titolo || '',
                descrizione: item.descrizione || '',
                responsabile: item.responsabile || '',
                dataTarget: item.data_target || '',
                stato: item.stato || 'Aperta',
                taskId: item.task_id || null,
                ordinamento: item.ordinamento || 0
            })),
            allowDelete: canEdit,
            externalAddButton: true
        });

        // Setup generazione automatica codici items
        const itemsContainer = document.getElementById('mom-items-container');
        const partecipantiContainer = document.getElementById('mom-partecipanti-container');

        if (typeof repeatableTable.setupItemCodeGeneration === 'function') {
            repeatableTable.setupItemCodeGeneration(itemsContainer);
        }

        if (typeof repeatableTable.setupParticipantSelectUpdates === 'function') {
            repeatableTable.setupParticipantSelectUpdates(itemsContainer, partecipantiContainer);
        }

        // Setup rendering condizionale data (OBS)
        setupObsDateRendering(itemsContainer);

        // Setup sincronizzazione stati con task collegate
        setupItemsTaskSync(itemsContainer);

        // Aggiungi legenda sotto la tabella items
        const legendDiv = document.createElement('div');
        legendDiv.className = 'items-legend';
        legendDiv.style.cssText = 'font-size: 0.85rem; color: #666; margin-top: 8px; text-align: center;';
        legendDiv.textContent = 'OBS = Osservazione | AI = Azione | EVE = Evento';
        itemsContainer.appendChild(legendDiv);

        // Collega bottoni header alle funzioni di aggiunta riga
        const partecipantiAddBtn = document.getElementById('mom-partecipanti-add-btn');
        if (partecipantiAddBtn) {
            partecipantiAddBtn.style.display = canEdit ? '' : 'none';
            partecipantiAddBtn.addEventListener('click', function () {
                repeatableTable.addRowExternal(document.getElementById('mom-partecipanti-container'));
            });
        }

        const itemsAddBtn = document.getElementById('mom-items-add-btn');
        if (itemsAddBtn) {
            itemsAddBtn.style.display = canEdit ? '' : 'none';
            // Rimuovi eventuali event listener esistenti
            const newBtn = itemsAddBtn.cloneNode(true);
            newBtn.style.display = canEdit ? '' : 'none';
            itemsAddBtn.parentNode.replaceChild(newBtn, itemsAddBtn);

            newBtn.addEventListener('click', function () {
                repeatableTable.addRowExternal(document.getElementById('mom-items-container'));
            });
        }

        // Blocca interattività dei campi nelle tabelle se in sola lettura
        if (!canEdit) {
            const containers = [
                document.getElementById('mom-partecipanti-container'),
                document.getElementById('mom-items-container')
            ];
            containers.forEach(container => {
                if (!container) return;
                const innerInputs = container.querySelectorAll('input, select, textarea');
                innerInputs.forEach(input => {
                    if (input.tagName === 'SELECT' || input.type === 'checkbox') {
                        input.style.pointerEvents = 'none';
                    } else {
                        input.readOnly = true;
                    }
                });
                // Blocca anche i customSelect all'interno delle tabelle
                container.querySelectorAll('.custom-select-box').forEach(cs => {
                    cs.style.pointerEvents = 'none';
                });
            });
        }

        // Inizializza gestione file temporanei
        initFileSelection();

        // Inizializza drag & drop per items
        if (canEdit) {
            setTimeout(() => {
                initItemsDragDrop();
            }, 200);
        }
    }

    /**
     * Inizializza dropdown per la tabella partecipanti
     * Carica aziende e contatti, setup click handlers
     */
    function initPartecipantiDropdowns() {
        if (!window.autocompleteManager || !window.showCustomDropdownInputLibero) {
            return;
        }

        // Carica aziende
        window.autocompleteManager.load('aziende', {}, function (opzioniAziende) {
            setupSocietaDropdowns(opzioniAziende);
        });

        // Osserva nuove righe aggiunte dinamicamente
        const container = document.getElementById('mom-partecipanti-container');
        if (container) {
            const observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1 && node.tagName === 'TR') {
                            window.autocompleteManager.load('aziende', {}, function (opzioniAziende) {
                                setupRowDropdowns(node, opzioniAziende);
                            });
                        }
                    });
                });
            });
            observer.observe(container, { childList: true, subtree: true });
        }
    }

    /**
     * Setup rendering condizionale data per OBS
     * Nasconde input data e mostra placeholder se tipo è OBS
     */
    function setupObsDateRendering(itemsContainer) {
        if (!itemsContainer) return;

        function updateRow(row) {
            const typeSelect = row.querySelector('[data-field="itemType"]');
            const dateInput = row.querySelector('[data-field="dataTarget"]');
            if (!typeSelect || !dateInput) return;

            const isObs = typeSelect.value === 'OBS';
            const cell = dateInput.closest('td');
            if (!cell) return;

            let placeholder = cell.querySelector('.obs-date-placeholder');
            if (!placeholder) {
                placeholder = document.createElement('div');
                placeholder.className = 'obs-date-placeholder';
                placeholder.textContent = '—';
                placeholder.setAttribute('data-tooltip', 'Osservazione: non prevede scadenza');
                placeholder.style.cssText = 'color: #999; text-align: center; padding: 4px; display: none;';
                cell.appendChild(placeholder);
            }

            if (isObs) {
                dateInput.style.display = 'none';
                placeholder.style.display = 'block';
            } else {
                dateInput.style.display = '';
                placeholder.style.display = 'none';
            }
        }

        // 1. Initial check
        itemsContainer.querySelectorAll('tbody tr').forEach(updateRow);

        // 2. Listener change type
        itemsContainer.addEventListener('change', function (e) {
            if (e.target.matches('[data-field="itemType"]')) {
                updateRow(e.target.closest('tr'));
            }
        });

        // 3. Observer new rows
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(node => {
                    if (node.tagName === 'TR') updateRow(node);
                });
            });
        });
        const tbody = itemsContainer.querySelector('tbody');
        if (tbody) observer.observe(tbody, { childList: true });
    }

    /**
     * Setup dropdown società per tutte le righe esistenti
     */
    function setupSocietaDropdowns(opzioniAziende) {
        document.querySelectorAll('#mom-partecipanti-container .mom-societa-box').forEach(box => {
            setupSocietaBox(box, opzioniAziende);
        });
    }

    /**
     * Setup dropdown per una singola riga
     */
    function setupRowDropdowns(row, opzioniAziende) {
        const societaBox = row.querySelector('.mom-societa-box');
        if (societaBox) {
            setupSocietaBox(societaBox, opzioniAziende);
        }
    }

    /**
     * Setup sincronizzazione stati item con task collegate
     * Disabilita i select stato per items con task collegata
     */
    function setupItemsTaskSync(itemsContainer) {
        if (!itemsContainer) return;

        function updateRowSync(row) {
            const statoSelect = row.querySelector('[data-field="stato"]');
            if (!statoSelect) return;

            // Verifica se questa riga ha una task collegata controllando gli input hidden
            const taskIdInput = row.querySelector('input[name*="[taskId]"], input[name*="[task_id]"]');
            const hasTask = taskIdInput && taskIdInput.value && parseInt(taskIdInput.value) > 0;

            if (hasTask) {
                // Disabilita il select e aggiungi tooltip informativo
                statoSelect.disabled = true;
                statoSelect.style.opacity = '0.7';
                statoSelect.style.cursor = 'not-allowed';
                statoSelect.title = 'Stato sincronizzato con la task collegata - modifiche non permesse da qui';

                // Prevenzione cambio valore per items con task collegata
                statoSelect.addEventListener('change', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    showToast('Stato sincronizzato con la task collegata - modifica lo stato dalla task', 'warning');
                    // Ripristina il valore originale
                    const originalValue = this.getAttribute('data-original-value') || this.value;
                    this.value = originalValue;
                    return false;
                });

                // Salva valore originale per ripristino
                statoSelect.setAttribute('data-original-value', statoSelect.value);
            } else {
                // Abilita il select per items senza task
                statoSelect.disabled = false;
                statoSelect.style.opacity = '1';
                statoSelect.style.cursor = 'pointer';
                statoSelect.title = 'Seleziona lo stato dell\'item';
            }
        }

        // Applica a righe esistenti
        itemsContainer.querySelectorAll('tbody tr').forEach(updateRowSync);

        // Osserva nuove righe aggiunte
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1 && node.tagName === 'TR') {
                        // Piccolo delay per permettere al repeatableTable di popolare i dati
                        setTimeout(() => updateRowSync(node), 50);
                    }
                });
            });
        });

        const tbody = itemsContainer.querySelector('tbody');
        if (tbody) {
            observer.observe(tbody, { childList: true });
        }
    }

    /**
     * Setup singolo box società
     */
    function setupSocietaBox(box, opzioniAziende) {
        box.onclick = function (e) {
            e.stopPropagation();
            const hiddenInput = box.querySelector('input[type="hidden"]');
            const placeholder = box.querySelector('.custom-select-placeholder');

            window.showCustomDropdownInputLibero(box, opzioniAziende, {
                placeholder: "Cerca società...",
                valoreIniziale: hiddenInput ? hiddenInput.value : '',
                onSelect: function (opt, inputVal, isFree) {
                    if (isFree && inputVal && inputVal.trim().length > 1) {
                        // Input libero - apri modale per aggiungere nuova società
                        window.showAddCompanyModal(inputVal, function (nuovaAzienda) {
                            if (nuovaAzienda) {
                                // Aggiorna il placeholder e l'input con la nuova azienda
                                if (placeholder) placeholder.textContent = nuovaAzienda.ragionesociale;
                                if (hiddenInput) hiddenInput.value = nuovaAzienda.ragionesociale;

                                // Ricarica lista aziende e setup partecipante
                                const row = box.closest('tr');
                                if (row && nuovaAzienda.id) {
                                    setupPartecipanteForSocieta(row, nuovaAzienda.id);
                                }

                                // Aggiorna cache opzioni aziende per le altre righe
                                window.autocompleteManager.clearCache('companies');
                                window.autocompleteManager.load('companies', {}, function (newOptions) {
                                    opzioniAziende.length = 0;
                                    opzioniAziende.push(...newOptions);
                                });
                            }
                        });
                        return;
                    }
                    if (opt) {
                        if (placeholder) placeholder.textContent = opt.label;
                        if (hiddenInput) hiddenInput.value = opt.label; // Salva il nome società

                        // Reset e setup partecipante per questa società
                        const row = box.closest('tr');
                        if (row) {
                            setupPartecipanteForSocieta(row, opt.value);
                        }
                    }
                }
            });
        };
    }

    /**
     * Setup dropdown partecipante basato sulla società selezionata
     */
    function setupPartecipanteForSocieta(row, aziendaId) {
        const partBox = row.querySelector('.mom-partecipante-box');
        if (!partBox) return;

        const partPlaceholder = partBox.querySelector('.custom-select-placeholder');
        const partInput = partBox.querySelector('input[type="hidden"]');

        // Reset
        if (partPlaceholder) partPlaceholder.textContent = 'Seleziona partecipante...';
        if (partInput) partInput.value = '';

        // Carica contatti per questa azienda
        window.autocompleteManager.load('contattiByAzienda', { azienda_id: aziendaId, azienda: aziendaId }, function (opzioniContatti) {
            partBox.onclick = function (e) {
                e.stopPropagation();

                window.showCustomDropdownInputLibero(partBox, opzioniContatti, {
                    placeholder: "Cerca contatto...",
                    valoreIniziale: partInput ? partInput.value : '',
                    onSelect: function (opt, inputVal, isFree) {
                        const emailInput = row.querySelector('input[data-field="email"]');
                        const telInput = row.querySelector('input[data-field="telefono"]');

                        if (isFree && inputVal && inputVal.trim().length > 1) {
                            // Input libero - apri modale per aggiungere nuovo contatto
                            window.showAddContactModal(inputVal, aziendaId, '', function (nuovoContatto) {
                                if (nuovoContatto) {
                                    // Aggiorna il placeholder e l'input con il nuovo contatto
                                    if (partPlaceholder) partPlaceholder.textContent = nuovoContatto.nomeCompleto || (nuovoContatto.cognome + ' ' + nuovoContatto.nome);
                                    if (partInput) partInput.value = nuovoContatto.nomeCompleto || (nuovoContatto.cognome + ' ' + nuovoContatto.nome);

                                    // Popola Email e Telefono
                                    if (emailInput) {
                                        emailInput.value = nuovoContatto.email || '';
                                        emailInput.setAttribute('title', nuovoContatto.email || '');
                                    }
                                    if (telInput) {
                                        let tel = nuovoContatto.telefono || nuovoContatto.cellulare || '';
                                        if (nuovoContatto.telefono && nuovoContatto.cellulare && nuovoContatto.telefono !== nuovoContatto.cellulare) {
                                            tel = `${nuovoContatto.telefono} / ${nuovoContatto.cellulare}`;
                                        }
                                        telInput.value = tel;
                                        telInput.setAttribute('title', tel);
                                    }

                                    // Ricarica opzioni contatti per questa azienda
                                    window.autocompleteManager.clearCache('contattiByAzienda');
                                    window.autocompleteManager.load('contattiByAzienda', { azienda_id: aziendaId, azienda: aziendaId }, function (newOptions) {
                                        opzioniContatti.length = 0;
                                        opzioniContatti.push(...newOptions);
                                    });
                                }
                            });
                            return;
                        }
                        if (opt) {
                            if (partPlaceholder) partPlaceholder.textContent = opt.label;
                            if (partInput) partInput.value = opt.label; // Salva il nome contatto

                            // Popola Email e Telefono (real-time)
                            if (emailInput) {
                                emailInput.value = opt.email || '';
                                emailInput.setAttribute('title', opt.email || ''); // Tooltip
                            }
                            if (telInput) {
                                // Tenta di recuperare telefono da _raw o usa quello combinato se disponibile
                                // Nota: autocompleteManager contattiByAzienda mappa _raw all'item originale
                                let tel = '';
                                if (opt._raw) {
                                    tel = opt._raw.telefono || opt._raw.interno || opt._raw.cellulare || '';
                                    // Se abbiamo sia telefono che cellulare nel raw, combinali come fa il backend?
                                    // Il backend getCompanyContacts ritorna già 'telefono' (o interno)
                                    // Ma se è CRM ritorna telefono E cellulare.
                                    if (opt._raw.cellulare && opt._raw.telefono && opt._raw.cellulare !== opt._raw.telefono) {
                                        tel = `${opt._raw.telefono} / ${opt._raw.cellulare}`;
                                    } else if (opt._raw.cellulare && !opt._raw.telefono) {
                                        tel = opt._raw.cellulare;
                                    }
                                }
                                telInput.value = tel;
                                telInput.setAttribute('title', tel);
                            }
                        }
                    }
                });
            };
        });
    }

    /**
     * Salva MOM
     */
    async function salvaMom() {
        const form = document.getElementById('mom-form');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        // Estrai dati form
        const codice = document.getElementById('mom-codice-protocollo-value')?.value || '';
        if (!codice && currentMomId === 0) {
            showToast('Seleziona area e codice prima di salvare', 'error');
            return;
        }

        const data = {
            id: currentMomId > 0 ? currentMomId : null,
            intranetSection: document.getElementById('mom-section')?.value || getCurrentSection(),
            contextType: document.getElementById('mom-context-type').value,
            contextId: document.getElementById('mom-context-id').value,
            codice: codice,
            titolo: document.getElementById('mom-titolo').value.trim(),
            dataMeeting: document.getElementById('mom-data-meeting').value,
            oraInizio: document.getElementById('mom-ora-inizio').value || null,
            oraFine: document.getElementById('mom-ora-fine').value || null,
            luogo: document.getElementById('mom-luogo').value.trim() || null,
            stato: document.getElementById('mom-stato-select').value || 'bozza',
            note: document.getElementById('mom-note').value.trim() || null
        };

        // Estrai blocchi ripetibili
        const partecipanti = repeatableTable.getValue(document.getElementById('mom-partecipanti-container'));
        const items = repeatableTable.getValue(document.getElementById('mom-items-container'));

        // Aggiungi ordinamento agli items basato sulla posizione nella tabella
        data.partecipanti = partecipanti.rows.map((row, index) => ({
            ...row,
            ordinamento: index + 1
        }));

        data.items = items.rows.map((row, index) => ({
            ...row,
            ordinamento: index + 1
        }));

        try {
            const response = await customFetch('mom', 'saveMom', data);

            if (!response.success) {
                showToast(response.message || 'Errore nel salvataggio', 'error');
                return;
            }

            // Carica eventuali file temporanei
            if (pendingFiles.length > 0) {
                showToast('Caricamento file allegati...', 'info');
                try {
                    const uploadResult = await caricaFileTemporanei(currentMomId === 0 ? response.momId || currentMomId : currentMomId);
                    if (uploadResult.successCount > 0) {
                        showToast(`${uploadResult.successCount} allegati caricati con successo`, 'success');
                    }
                    if (uploadResult.errorCount > 0) {
                        showToast(`${uploadResult.errorCount} allegati non caricati`, 'warning');
                    }
                } catch (error) {
                    console.error('Errore caricamento allegati:', error);
                    showToast('Errore nel caricamento degli allegati', 'error');
                }
            }

            showToast('MOM salvato con successo', 'success');

            // Aggiorna kanban se visibile (sposta task nelle colonne corrette)
            const kanbanContainer = document.getElementById('kanban-view');
            if (kanbanContainer && !kanbanContainer.classList.contains('hidden')) {
                // Usa i dati degli items salvati per aggiornare le posizioni
                if (data.items) {
                    updateKanbanTaskPositions({ items: data.items });
                }
            }

            // Ricarica pagina con nuovo ID
            if (currentMomId === 0 && response.momId) {
                setTimeout(() => {
                    apriMom(response.momId, 'edit');
                }, 1000);
            } else {
                // Ricarica dettaglio
                caricaDettaglio(currentMomId, true);
            }

        } catch (error) {
            console.error('Errore salvataggio:', error);
            showToast('Errore nel salvataggio', 'error');
        }
    }


    /**
     * Elimina MOM
     */
    async function eliminaMom(momId) {
        window.showConfirm('Sei sicuro di voler eliminare questo MOM? L\'operazione non può essere annullata.', async () => {
            try {
                const response = await customFetch('mom', 'deleteMom', { momId: momId });

                if (!response.success) {
                    showToast(response.message || 'Errore nell\'eliminazione', 'error');
                    return;
                }

                showToast('MOM eliminato con successo', 'success');
                caricaArchivio();

            } catch (error) {
                console.error('Errore eliminazione:', error);
                showToast('Errore nell\'eliminazione', 'error');
            }
        });
    }


    /**
     * Clona (duplica) un MOM esistente
     * Crea una copia completa con titolo univoco e nuovo progressivo
     */
    async function clonaMom(momId) {
        window.showConfirm('Vuoi creare una copia di questo verbale?\nVerrà generato un nuovo MOM con titolo "_copia" e tutti i dati duplicati.', async () => {
            // Trova e disabilita il bottone per evitare doppio click
            const cloneBtn = document.querySelector(`button.clone-btn[onclick*="clonaMom(${momId})"]`);
            if (cloneBtn) {
                cloneBtn.disabled = true;
                cloneBtn.style.opacity = '0.5';
            }

            try {
                const response = await customFetch('mom', 'cloneMom', { momId: momId });

                if (!response.success) {
                    showToast(response.message || 'Errore nella duplicazione', 'error');
                    return;
                }

                showToast('Verbale duplicato con successo', 'success');

                // Apri il nuovo verbale in edit
                if (response.data && response.data.newMomId) {
                    apriMom(response.data.newMomId, 'edit');
                } else {
                    // Fallback: ricarica archivio
                    caricaArchivio();
                }

            } catch (error) {
                console.error('Errore clonazione:', error);
                showToast('Errore nella duplicazione del verbale', 'error');
            } finally {
                // Riabilita il bottone
                if (cloneBtn) {
                    cloneBtn.disabled = false;
                    cloneBtn.style.opacity = '1';
                }
            }
        });
    }


    /**
     * Carica allegati
     */
    function caricaAllegati(allegati) {
        const container = document.getElementById('mom-allegati-lista');
        if (!container) return;

        if (allegati.length === 0) {
            container.innerHTML = '<p>Nessun allegato</p>';
            return;
        }

        container.innerHTML = allegati.map(allegato => {
            // Determina se è un allegato temporaneo (mom_id null)
            const isTemporary = allegato.mom_id === null;
            const canDelete = isEditMode || isTemporary;

            const deleteBtn = canDelete
                ? `<button class="button button-danger button-small" onclick="momApp.eliminaAllegato(${allegato.id})" data-tooltip="Elimina allegato">Elimina</button>`
                : '';

            const size = allegato.dimensione ? formatBytes(allegato.dimensione) : '';

            // Pulsante download/visualizza
            const downloadBtn = `<button class="button button-secondary button-small" onclick="momApp.downloadAllegato(${allegato.id})" data-tooltip="Scarica allegato">
                    <img src="assets/icons/download.png" alt="Download" style="width: 14px; height: 14px;">
                </button>`;

            // Mostra indicatore per allegati temporanei
            const tempIndicator = isTemporary ? '<span style="color: #f59e0b; font-size: 0.8em; margin-left: 8px;">(temporaneo)</span>' : '';

            return `
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px; border-bottom: 1px solid #eee;">
                        <div style="flex: 1;">
                            <span>${escapeHtml(allegato.nome_file)}</span>
                            <span style="color: #888; margin-left: 8px;">${size}</span>
                            ${tempIndicator}
                        </div>
                        <div style="display: flex; gap: 8px;">
                            ${downloadBtn}
                            ${deleteBtn}
                        </div>
                    </div>
                `;
        }).join('');
    }

    /**
     * Upload allegato/i
     */
    /**
     * Gestisci selezione file (non carica, solo memorizza temporaneamente)
     */
    function gestisciSelezioneFile() {
        const input = document.getElementById('mom-allegato-upload');
        if (!input || !input.files || input.files.length === 0) {
            return;
        }

        // Aggiungi i file selezionati alla lista temporanea
        const nuoviFile = Array.from(input.files);
        pendingFiles.push(...nuoviFile);

        // Aggiorna l'interfaccia per mostrare i file selezionati
        aggiornaVistaFileTemporanei();

        // Resetta l'input
        input.value = '';

        showToast(`${nuoviFile.length} file selezionato/i. Verranno caricati al salvataggio del MOM.`, 'info');
    }

    /**
     * Aggiorna la vista dei file temporanei selezionati
     */
    function aggiornaVistaFileTemporanei() {
        // Rimuovi eventuali container precedenti
        const existingContainer = document.getElementById('mom-temporary-files');
        if (existingContainer) {
            existingContainer.remove();
        }

        if (pendingFiles.length === 0) {
            return; // Non ci sono file temporanei
        }

        // Trova il container degli allegati
        const allegatiContainer = document.getElementById('mom-allegati-lista');

        // Crea un elemento per i file temporanei
        const tempContainer = document.createElement('div');
        tempContainer.id = 'mom-temporary-files';
        tempContainer.style.cssText = 'margin-top: 16px; padding: 12px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 4px;';

        // Mostra i file temporanei
        const fileList = pendingFiles.map((file, index) => `
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 4px 0; border-bottom: 1px solid #fbbf24;">
                <span style="color: #92400e;">📎 ${escapeHtml(file.name)} (${formatBytes(file.size)})</span>
                <button type="button" onclick="momApp.rimuoviFileTemporaneo(${index})" style="background: none; border: none; color: #dc2626; cursor: pointer; font-size: 16px; padding: 0 4px;" title="Rimuovi file">✕</button>
            </div>
        `).join('');

        tempContainer.innerHTML = `
            <div style="font-weight: bold; color: #92400e; margin-bottom: 8px;">📋 File selezionati (verranno caricati al salvataggio):</div>
            ${fileList}
        `;

        // Inserisci dopo la lista allegati esistente
        if (allegatiContainer && allegatiContainer.parentNode) {
            allegatiContainer.parentNode.insertBefore(tempContainer, allegatiContainer.nextSibling);
        }
    }

    /**
     * Rimuovi un file temporaneo dalla lista
     */
    function rimuoviFileTemporaneo(index) {
        if (index >= 0 && index < pendingFiles.length) {
            pendingFiles.splice(index, 1);
            aggiornaVistaFileTemporanei();
            showToast('File rimosso dalla lista', 'info');
        }
    }

    /**
     * Carica tutti i file temporanei (chiamato durante il salvataggio)
     */
    async function caricaFileTemporanei(momId) {
        if (pendingFiles.length === 0 || momId <= 0) {
            return { success: true, count: 0 };
        }

        let successCount = 0;
        let errorCount = 0;

        for (const file of pendingFiles) {
            // Controlla dimensione massima
            const maxSize = 10 * 1024 * 1024; // 10MB
            if (file.size > maxSize) {
                console.error(`File troppo grande: ${file.name}`);
                errorCount++;
                continue;
            }

            const formData = new FormData();
            formData.append('file', file);
            formData.append('momId', momId);

            try {
                const response = await customFetch('mom', 'uploadAllegato', formData);
                if (response.success) {
                    successCount++;
                } else {
                    console.error('Errore upload file:', file.name, response.message);
                    errorCount++;
                }
            } catch (error) {
                console.error('Errore upload file:', file.name, error);
                errorCount++;
            }
        }

        // Pulisci la lista dei file temporanei
        pendingFiles = [];

        // Rimuovi la vista temporanea
        const tempContainer = document.getElementById('mom-temporary-files');
        if (tempContainer) {
            tempContainer.remove();
        }

        return { success: successCount > 0, successCount, errorCount };
    }

    async function uploadAllegato() {
        // Invece di caricare, gestisci la selezione temporanea
        gestisciSelezioneFile();
    }

    // Funzione originale disabilitata
    async function uploadAllegatoOld(files = null) {
        const input = document.getElementById('mom-allegato-upload');
        let fileList = files;

        // Se non sono stati passati file specifici, usa quelli dell'input
        if (!fileList) {
            if (!input || !input.files || input.files.length === 0) {
                showToast('Seleziona uno o più file', 'warning');
                return;
            }
            fileList = Array.from(input.files);
        }

        if (fileList.length === 0) {
            showToast('Nessun file selezionato', 'warning');
            return;
        }

        // Per MOM nuovi (momId = 0), usa 0 come ID temporaneo

        // Controlla dimensione massima (10MB per file)
        const maxSize = 10 * 1024 * 1024; // 10MB
        for (const file of fileList) {
            if (file.size > maxSize) {
                showToast(`Il file "${file.name}" supera i 10MB`, 'error');
                return;
            }
        }

        // Carica tutti i file
        let successCount = 0;
        let errorCount = 0;

        for (const file of fileList) {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('momId', currentMomId);

            try {
                const response = await customFetch('mom', 'uploadAllegato', formData);

                if (response.success) {
                    successCount++;
                } else {
                    console.error('Errore upload file:', file.name, response.message);
                    errorCount++;
                }
            } catch (error) {
                console.error('Errore upload file:', file.name, error);
                errorCount++;
            }
        }

        // Mostra messaggio di risultato
        if (successCount > 0) {
            showToast(`${successCount} file caricato/i con successo${errorCount > 0 ? `, ${errorCount} errori` : ''}`, successCount === fileList.length ? 'success' : 'warning');
        } else {
            showToast('Errore nel caricamento dei file', 'error');
        }

        // Resetta input
        if (input) input.value = '';

        // Ricarica dettaglio per aggiornare lista allegati
        caricaDettaglio(currentMomId, isEditMode);
    }

    /**
     * Inizializza gestione selezione file temporanea
     */
    function initFileSelection() {
        const input = document.getElementById('mom-allegato-upload');
        const btn = document.getElementById('mom-upload-btn');
        if (!input || !btn) return;

        // Gestisci il click del bottone per caricare i file selezionati
        btn.addEventListener('click', function () {
            gestisciSelezioneFile();
        });
    }

    /**
     * Elimina allegato
     */
    async function eliminaAllegato(allegatoId) {
        window.showConfirm('Sei sicuro di voler eliminare questo allegato?', async () => {
            try {
                const response = await customFetch('mom', 'deleteAllegato', { allegatoId: allegatoId });

                if (!response.success) {
                    showToast(response.message || 'Errore nell\'eliminazione', 'error');
                    return;
                }

                showToast('Allegato eliminato con successo', 'success');

                // Ricarica dettaglio per aggiornare lista allegati
                caricaDettaglio(currentMomId, isEditMode);

            } catch (error) {
                console.error('Errore eliminazione:', error);
                showToast('Errore nell\'eliminazione', 'error');
            }
        });
    }

    /**
     * Download allegato
     */
    async function downloadAllegato(allegatoId) {
        try {
            // Crea URL per download
            const url = 'service_router.php?action=mom&method=downloadAllegato&allegatoId=' + allegatoId;

            // Apri in nuova finestra/tab per download diretto
            window.open(url, '_blank');

        } catch (error) {
            console.error('Errore download:', error);
            showToast('Errore nel download dell\'allegato', 'error');
        }
    }

    /**
     * Export PDF
     */
    async function exportPdf() {
        if (currentMomId === 0) {
            showToast('Nessun MOM selezionato', 'warning');
            return;
        }

        try {
            const response = await customFetch('mom', 'exportPdf', { momId: currentMomId });

            if (!response.success) {
                showToast(response.message || 'Errore nella generazione PDF', 'error');
                return;
            }

            // Forza download del file
            const link = document.createElement('a');
            link.href = '/' + response.pdfPath;
            link.download = response.pdfPath.split('/').pop(); // Nome file dall'URL
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showToast(response.message || 'Documento generato con successo', 'success');

        } catch (error) {
            console.error('Errore export PDF:', error);
            showToast('Errore nella generazione PDF', 'error');
        }
    }

    /**
     * Utility: escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Utility: format bytes
     */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    /**
     * Inizializza form vuoto per nuovo MOM
     */
    async function initNuovoMom() {
        currentMomId = 0;
        isEditMode = true;

        // Aggiorna badge stato
        const statoBadge = document.getElementById('mom-stato-badge');
        if (statoBadge) {
            statoBadge.className = 'badge badge-secondary';
            statoBadge.textContent = 'Bozza';
        }

        // Abilita campi
        const inputs = document.querySelectorAll('#mom-form input, #mom-form textarea, #mom-form select');
        inputs.forEach(input => {
            if (input.id !== 'mom-id' && input.id !== 'mom-context-type' && input.id !== 'mom-context-id') {
                input.disabled = false;
            }
        });

        // Inizializza blocchi vuoti
        initRepeatableBlocks({ partecipanti: [], items: [] }, true);
        setTimeout(function () {
            initPartecipantiDropdowns();
        }, 150);
        caricaAllegati([]);

        // Inizializza gestione file temporanei
        setTimeout(function () {
            initFileSelection();
        }, 200);

        // Mostra BottomBar per nuovo verbale
        aggiornaBottomBar(true);
    }

    /**
     * Setup dropdown codice (area + commessa/generale)
     */
    function setupDropdownCodice() {
        const selectbox = document.getElementById('mom-codice-select');
        const area = document.getElementById('mom-area');
        if (!selectbox || !area) return;

        let isInitializing = false; // Flag per prevenire apertura automatica durante inizializzazione

        function loadOptionsAndShow(force = false, openDropdown = false) {
            const areaVal = area.value;
            let opzioni = [];

            const setupDropdown = (opzioniArray) => {
                // Se openDropdown è false, non aprire il dropdown, solo preparare le opzioni
                if (openDropdown) {
                    window.showCustomDropdown(selectbox, opzioniArray, {
                        placeholder: "Cerca per codice o descrizione...",
                        valoreIniziale: document.getElementById('mom-codice-protocollo-value')?.value || '',
                        onSelect: (opt) => {
                            selectbox.querySelector('.custom-select-placeholder').textContent =
                                `${opt.code} | ${opt.label}`;
                            document.getElementById('mom-codice-protocollo-value').value = opt.value;
                            // Aggiorna contextType e contextId in base all'area selezionata
                            const areaValue = area.value;
                            document.getElementById('mom-context-type').value = areaValue || 'generale';
                            document.getElementById('mom-context-id').value = opt.value;
                            updateProgressivo();
                        }
                    });
                } else {
                    // Solo aggiorna il placeholder se c'è un valore iniziale, senza aprire il dropdown
                    const codiceValue = document.getElementById('mom-codice-protocollo-value')?.value || '';
                    if (codiceValue) {
                        const item = opzioniArray.find(opt => opt.value === codiceValue);
                        if (item) {
                            selectbox.querySelector('.custom-select-placeholder').textContent =
                                `${item.code} | ${item.label}`;
                        }
                    }
                }
            };

            if (areaVal === 'commessa') {
                customFetch('mom', 'getCommesse').then(response => {
                    const dati = response.success && Array.isArray(response.data) ? response.data : [];
                    opzioni = dati.map(d => ({
                        value: d.codice,
                        label: d.oggetto,
                        code: d.codice
                    }));
                    setupDropdown(opzioni);
                });
            } else if (areaVal === 'generale') {
                const generaleData = [
                    { codice_commessa: 'GAR', descrizione: 'Gare' },
                    { codice_commessa: 'AMM', descrizione: 'Amministrazione' },
                    { codice_commessa: 'OFF', descrizione: 'Offerte' },
                    { codice_commessa: 'ACQ', descrizione: 'Acquisti' },
                    { codice_commessa: 'HRR', descrizione: 'Risorse umane' },
                    { codice_commessa: 'SQQ', descrizione: 'Qualità' },
                    { codice_commessa: 'GCO', descrizione: 'Gestione commesse' },
                    { codice_commessa: 'CON', descrizione: 'Contratti' },
                    { codice_commessa: 'COM', descrizione: 'Commerciale' }
                ].sort((a, b) => a.codice_commessa.localeCompare(b.codice_commessa));
                opzioni = generaleData.map(d => ({
                    value: d.codice_commessa,
                    label: d.descrizione,
                    code: d.codice_commessa
                }));
                setupDropdown(opzioni);
            }
        }

        selectbox.onclick = function (e) {
            e.stopPropagation();
            loadOptionsAndShow(false, true); // Apri solo quando clicchi
        };

        area.addEventListener('change', function () {
            // Se stiamo inizializzando, non aprire il dropdown
            if (isInitializing) {
                loadOptionsAndShow(false, false);
                return;
            }
            document.getElementById('mom-codice-protocollo-value').value = '';
            selectbox.querySelector('.custom-select-placeholder').textContent = 'Seleziona un codice';
            loadOptionsAndShow(true, true); // Apri quando cambi area manualmente
        });

        // Esponi flag per controllo esterno
        setupDropdownCodice.setInitializing = (val) => { isInitializing = val; };
    }

    /**
     * Aggiorna display codice
     */
    function updateCodiceDisplay(codice, area) {
        const selectbox = document.getElementById('mom-codice-select');
        const codiceInput = document.getElementById('mom-codice-protocollo-value');
        if (!selectbox || !codice) return;

        // Imposta il valore nel campo hidden
        if (codiceInput) {
            codiceInput.value = codice;
        }

        if (area === 'commessa') {
            customFetch('mom', 'getCommesse').then(response => {
                const dati = response.success && Array.isArray(response.data) ? response.data : [];
                const item = dati.find(d => d.codice === codice);
                if (item) {
                    selectbox.querySelector('.custom-select-placeholder').textContent =
                        `${item.codice} | ${item.oggetto}`;
                }
            });
        } else if (area === 'generale') {
            const generaleData = [
                { codice_commessa: 'GAR', descrizione: 'Gare' },
                { codice_commessa: 'AMM', descrizione: 'Amministrazione' },
                { codice_commessa: 'OFF', descrizione: 'Offerte' },
                { codice_commessa: 'ACQ', descrizione: 'Acquisti' },
                { codice_commessa: 'HRR', descrizione: 'Risorse umane' },
                { codice_commessa: 'SQQ', descrizione: 'Qualità' },
                { codice_commessa: 'GCO', descrizione: 'Gestione commesse' },
                { codice_commessa: 'CON', descrizione: 'Contratti' }
            ];
            const item = generaleData.find(d => d.codice_commessa === codice);
            if (item) {
                selectbox.querySelector('.custom-select-placeholder').textContent =
                    `${item.codice_commessa} | ${item.descrizione}`;
            }
        }
    }

    /**
     * Aggiorna preview protocollo
     */
    async function updateProgressivo() {
        const codice = document.getElementById('mom-codice-protocollo-value')?.value || '';
        const protocolloEl = document.getElementById('mom-progressivo-display');

        if (!codice || !protocolloEl || currentMomId > 0) {
            // Se è un MOM esistente, non aggiornare
            return;
        }

        try {
            const response = await customFetch('mom', 'getPreviewProgressivo', { codice: codice });
            if (response.success && response.progressivo) {
                protocolloEl.value = response.progressivo;
            } else {
                protocolloEl.value = '';
            }
        } catch (error) {
            console.error('Errore aggiornamento protocollo:', error);
        }
    }

    /**
     * Inizializza drag & drop per items
     */
    function initItemsDragDrop() {
        const itemsContainer = document.getElementById('mom-items-container');
        if (!itemsContainer) return;

        const tbody = itemsContainer.querySelector('tbody');
        if (!tbody) return;

        let draggedRow = null;
        let currentDropTarget = null;

        // Aggiungi attributo draggable alle righe
        function makeRowsDraggable() {
            const rows = tbody.querySelectorAll('tr');
            rows.forEach((row, index) => {
                row.setAttribute('draggable', 'true');
                row.style.cursor = 'move';

                // Rimuovi event listener esistenti per evitare duplicati
                row.removeEventListener('dragstart', handleDragStart);
                row.removeEventListener('dragover', handleDragOver);
                row.removeEventListener('drop', handleDrop);
                row.removeEventListener('dragend', handleDragEnd);

                // Aggiungi event listener
                row.addEventListener('dragstart', handleDragStart);
                row.addEventListener('dragover', handleDragOver);
                row.addEventListener('drop', handleDrop);
                row.addEventListener('dragend', handleDragEnd);
            });
        }

        function handleDragStart(e) {
            draggedRow = this;
            this.style.opacity = '0.5';
            this.style.backgroundColor = '#f0f0f0';
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', this.innerHTML);
        }

        function handleDragOver(e) {
            if (e.preventDefault) {
                e.preventDefault();
            }

            e.dataTransfer.dropEffect = 'move';

            // Trova la riga più vicina (non elementi figli)
            let targetRow = e.target;
            while (targetRow && targetRow.tagName !== 'TR') {
                targetRow = targetRow.parentElement;
            }

            // Se abbiamo una riga valida e non è quella trascinata
            if (targetRow && targetRow !== draggedRow && targetRow.parentElement === tbody) {
                // Rimuovi highlight dalla riga precedente
                if (currentDropTarget && currentDropTarget !== targetRow) {
                    currentDropTarget.style.backgroundColor = '';
                    currentDropTarget.style.borderTop = '';
                    currentDropTarget.style.borderBottom = '';
                }

                // Aggiungi highlight alla riga corrente
                currentDropTarget = targetRow;

                // Determina se inserire sopra o sotto
                const rect = targetRow.getBoundingClientRect();
                const midpoint = rect.top + rect.height / 2;

                if (e.clientY < midpoint) {
                    // Inserisci sopra
                    targetRow.style.borderTop = '3px solid #3498DB';
                    targetRow.style.borderBottom = '';
                    targetRow.style.backgroundColor = 'rgba(52, 152, 219, 0.1)';
                } else {
                    // Inserisci sotto
                    targetRow.style.borderTop = '';
                    targetRow.style.borderBottom = '3px solid #3498DB';
                    targetRow.style.backgroundColor = 'rgba(52, 152, 219, 0.1)';
                }
            }

            return false;
        }

        function handleDrop(e) {
            if (e.stopPropagation) {
                e.stopPropagation();
            }

            e.preventDefault();

            // Trova la riga target
            let targetRow = e.target;
            while (targetRow && targetRow.tagName !== 'TR') {
                targetRow = targetRow.parentElement;
            }

            if (targetRow && draggedRow !== targetRow && targetRow.parentElement === tbody) {
                const rect = targetRow.getBoundingClientRect();
                const midpoint = rect.top + rect.height / 2;

                // Riordina nel DOM
                if (e.clientY < midpoint) {
                    // Inserisci sopra
                    targetRow.parentNode.insertBefore(draggedRow, targetRow);
                } else {
                    // Inserisci sotto
                    targetRow.parentNode.insertBefore(draggedRow, targetRow.nextSibling);
                }

                // Aggiorna item_code immediatamente nell'UI (feedback istantaneo)
                updateItemCodesInUIImmediate();

                // Salva nuovo ordine sul server
                saveItemsOrder();
            }

            return false;
        }

        function handleDragEnd(e) {
            this.style.opacity = '1';
            this.style.backgroundColor = '';

            // Rimuovi tutti gli highlight
            const rows = tbody.querySelectorAll('tr');
            rows.forEach(row => {
                row.style.borderTop = '';
                row.style.borderBottom = '';
                row.style.backgroundColor = '';
            });

            currentDropTarget = null;
        }

        // Inizializza righe esistenti
        makeRowsDraggable();

        // Osserva nuove righe aggiunte
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    makeRowsDraggable();
                }
            });
        });

        observer.observe(tbody, { childList: true });
    }

    /**
     * Salva ordine items sul server e aggiorna item_code nell'UI
     */
    async function saveItemsOrder() {
        if (currentMomId <= 0) {
            showToast('Salva prima il verbale per riordinare gli items', 'warning');
            return;
        }

        const itemsContainer = document.getElementById('mom-items-container');
        if (!itemsContainer) return;

        const tbody = itemsContainer.querySelector('tbody');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr'));
        const items = rows.map((row, index) => {
            const idInput = row.querySelector('input[name*="[id]"]');
            const itemTypeSelect = row.querySelector('[data-field="itemType"]');
            const id = idInput ? parseInt(idInput.value) : 0;
            const itemType = itemTypeSelect ? itemTypeSelect.value : '';

            return {
                id: id,
                ordinamento: index + 1,
                itemType: itemType,
                row: row
            };
        }).filter(item => item.id > 0);

        if (items.length === 0) {
            return;
        }

        try {
            const response = await customFetch('mom', 'saveItemsOrder', {
                momId: currentMomId,
                items: items.map(item => ({ id: item.id, ordinamento: item.ordinamento }))
            });

            if (response.success) {
                // Aggiorna item_code nell'UI immediatamente
                updateItemCodesInUI(items);

                showToast('Ordine salvato', 'success');

                // Ricarica dettaglio in background per sincronizzare tutto
                setTimeout(() => {
                    caricaDettaglio(currentMomId, isEditMode);
                }, 1000);
            } else {
                showToast(response.message || 'Errore nel salvataggio ordine', 'error');
                // Fallback: ricarica pagina
                location.reload();
            }
        } catch (error) {
            console.error('Errore salvataggio ordine:', error);
            showToast('Errore nel salvataggio ordine', 'error');
            // Fallback: ricarica pagina
            location.reload();
        }
    }

    /**
     * Aggiorna i codici item nell'UI immediatamente (legge dal DOM)
     * Usato durante drag & drop per feedback istantaneo
     */
    function updateItemCodesInUIImmediate() {
        const itemsContainer = document.getElementById('mom-items-container');
        if (!itemsContainer) return;

        const tbody = itemsContainer.querySelector('tbody');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr'));
        const items = rows.map((row, index) => {
            const itemTypeSelect = row.querySelector('[data-field="itemType"]');
            const itemType = itemTypeSelect ? itemTypeSelect.value : '';

            return {
                itemType: itemType,
                row: row
            };
        });

        // Chiama la funzione esistente con i dati dal DOM
        updateItemCodesInUI(items);
    }

    /**
     * Aggiorna i codici item nell'UI dopo riordinamento
     */
    function updateItemCodesInUI(items) {
        // Raggruppa items per tipo
        const itemsByType = {
            'AI': [],
            'OBS': [],
            'EVE': []
        };

        items.forEach(item => {
            if (item.itemType && itemsByType[item.itemType]) {
                itemsByType[item.itemType].push(item);
            }
        });

        // Rigenera codici per ogni tipo
        Object.keys(itemsByType).forEach(itemType => {
            const typeItems = itemsByType[itemType];
            typeItems.forEach((item, index) => {
                const newCode = `${itemType}_${String(index + 1).padStart(3, '0')}`;

                // Trova il campo item_code nella riga e aggiornalo
                const itemCodeInput = item.row.querySelector('[data-field="itemCode"]');
                if (itemCodeInput) {
                    itemCodeInput.value = newCode;
                }
            });
        });
    }

    /**
     * Aggiorna stato item quando una task viene spostata nel kanban
     */
    async function updateItemFromTaskMove(taskId) {
        try {
            const result = await customFetch('mom', 'updateItemStatusFromTask', { taskId: taskId });
            if (result.success) {
                // Ricarica il dettaglio per riflettere il cambiamento nel form
                if (typeof caricaDettaglio === 'function') {
                    caricaDettaglio(currentMomId, true);
                }
            }
            return result;
        } catch (error) {
            console.error('Errore aggiornamento stato item:', error);
            return { success: false, message: 'Errore di connessione' };
        }
    }

    /**
     * Aggiorna posizioni task nel kanban dopo salvataggio
     */
    function updateKanbanTaskPositions(savedData) {
        if (!savedData || !savedData.items) return;

        savedData.items.forEach(item => {
            if (item.task_id) {
                // Trova la task nel kanban
                const taskElement = document.querySelector(`[data-task-id="${item.task_id}"]`);
                if (taskElement) {
                    // Determina la colonna corretta basata sullo stato dell'item
                    const statusMap = {
                        'aperta': 'aperta',
                        'in_corso': 'in_corso',
                        'in_attesa': 'in_attesa',
                        'completata': 'completata',
                        'chiusa': 'chiusa'
                    };

                    const targetColumn = statusMap[item.stato];
                    if (targetColumn) {
                        // Trova la colonna target
                        const targetContainer = document.querySelector(`#kanban-${targetColumn}`);
                        if (targetContainer && !targetContainer.contains(taskElement)) {
                            // Sposta la task nella colonna corretta
                            targetContainer.appendChild(taskElement);
                        }
                    }
                }
            }
        });
    }

    /**
     * Cambia vista archivio (List / Kanban / Calendar)
     */
    function switchView(viewName) {
        if (!['list', 'kanban', 'calendar'].includes(viewName)) return;

        currentView = viewName;

        // UI Update: Containers
        const listWrapper = document.getElementById('mom-archive-view-wrapper');
        const kanbanContainer = document.getElementById('mom-global-kanban');
        const calendarContainer = document.getElementById('mom-global-calendar');

        // Hide all
        if (listWrapper) listWrapper.classList.add('hidden');
        if (kanbanContainer) kanbanContainer.classList.add('hidden');
        if (calendarContainer) calendarContainer.classList.add('hidden');

        // Show active
        if (viewName === 'list') {
            if (listWrapper) listWrapper.classList.remove('hidden');
            caricaArchivio();
        } else if (viewName === 'kanban') {
            if (kanbanContainer) kanbanContainer.classList.remove('hidden');
            loadGlobalView('kanban');
        } else if (viewName === 'calendar') {
            if (calendarContainer) calendarContainer.classList.remove('hidden');
            loadGlobalView('calendar');
        }

        // Aggiorna stato bottoni function-bar
        if (typeof window.updateButtons === 'function') {
            window.updateButtons();
        }
    }

    /**
     * Carica dati per vista globale (Kanban/Calendar)
     */
    async function loadGlobalView(viewType) {
        const filtri = buildMomFilters();

        try {
            if (viewType === 'kanban') {
                // KANBAN: Mostra VERBALI (non items)
                // Nota: filtro 'stato' è gestito dalle colonne del kanban stesso
                const response = await customFetch('mom', 'getArchivio', filtri);

                if (!response.success) {
                    showToast(response.message || 'Errore caricamento verbali', 'error');
                    return;
                }

                const moms = response.data || [];
                const container = document.getElementById('mom-global-kanban');

                if (window.MomGlobalViews && window.MomGlobalViews.renderMomKanban) {
                    window.MomGlobalViews.renderMomKanban(moms, container);
                } else {
                    console.error('MomGlobalViews o renderMomKanban non disponibile');
                }

            } else if (viewType === 'calendar') {
                // CALENDAR: Mostra ITEMS (Eventi/Scadenze)
                const response = await customFetch('mom', 'getGlobalItems', filtri);

                if (!response.success) {
                    showToast(response.message || 'Errore caricamento items globali', 'error');
                    return;
                }

                const items = response.data || [];
                const container = document.getElementById('mom-global-calendar');

                if (window.MomGlobalViews && window.MomGlobalViews.renderGlobalCalendar) {
                    window.MomGlobalViews.renderGlobalCalendar(items, container);
                }
            }

        } catch (e) {
            console.error('Errore loadGlobalView:', e);
            showToast('Errore caricamento vista globale', 'error');
        }
    }

    /**
     * Aggiorna visualizzazione BottomBar per azioni rapide (Salva/Annulla)
     */
    function aggiornaBottomBar(visible) {
        if (typeof BottomBar === 'undefined') return;

        if (!visible) {
            BottomBar.hide();
            return;
        }

        BottomBar.setConfig({
            actions: [
                {
                    id: 'back-to-archive',
                    label: 'Annulla',
                    className: 'button-secondary',
                    tooltip: 'Torna all\'elenco senza salvare'
                },
                {
                    id: 'save-mom',
                    label: 'Salva Verbale',
                    className: 'button-primary',
                    tooltip: 'Salva tutte le modifiche'
                }
            ]
        });
    }

    // Listener per eventi BottomBar: intercetta le azioni emesse dal componente
    document.addEventListener('bottomBar:action', function (e) {
        if (e.detail.actionId === 'save-mom') {
            salvaMom();
        } else if (e.detail.actionId === 'back-to-archive') {
            tornaArchivio();
        }
    });

    return {
        initArchivio: initArchivio,
        caricaArchivio: caricaArchivio,
        resetFiltri: resetFiltri,
        apriMom: apriMom,
        nuovoMom: nuovoMom,
        tornaArchivio: tornaArchivio,
        caricaDettaglio: caricaDettaglio,
        initNuovoMom: initNuovoMom,
        salvaMom: salvaMom,
        eliminaMom: eliminaMom,
        clonaMom: clonaMom,
        uploadAllegato: uploadAllegato,
        eliminaAllegato: eliminaAllegato,
        downloadAllegato: downloadAllegato,
        exportPdf: exportPdf,
        setupDropdownCodice: setupDropdownCodice,
        updateProgressivo: updateProgressivo,
        updateCodiceDisplay: updateCodiceDisplay,
        gestisciSelezioneFile: gestisciSelezioneFile,
        rimuoviFileTemporaneo: rimuoviFileTemporaneo,
        caricaFileTemporanei: caricaFileTemporanei,
        initItemsDragDrop: initItemsDragDrop,
        saveItemsOrder: saveItemsOrder,
        switchView: switchView,
        updateItemFromTaskMove: updateItemFromTaskMove,
        updateKanbanTaskPositions: updateKanbanTaskPositions,
        aggiornaBottomBar: aggiornaBottomBar
    };
})();

// ======== CALENDAR PROVIDER per eventi EVE MOM ========
// Registra provider calendario per pagina 'mom' (eventi EVE)
if (typeof window.registerCalendarProvider === 'function') {
    window.registerCalendarProvider('mom', async function () {
        try {
            // Carica tutti i MOM con eventi EVE
            const response = await customFetch('mom', 'getArchivio', {
                stato: '',
                dataDa: '',
                dataA: '',
                testo: '',
                contextType: '',
                contextId: ''
            });

            if (!response.success || !Array.isArray(response.data)) {
                return [];
            }

            // Raccogli tutti gli eventi EVE da tutti i MOM
            const events = [];

            for (const mom of response.data) {
                // Carica dettaglio per ottenere items
                const dettaglio = await customFetch('mom', 'getDettaglio', { momId: mom.id });

                if (dettaglio.success && dettaglio.data && Array.isArray(dettaglio.data.items)) {
                    dettaglio.data.items.forEach(item => {
                        // Eventi EVE (o decisioni con scadenza) con data
                        const itemType = item.item_type || '';
                        const dataEvento = item.data_target || item.data_scadenza || item.scadenza || null;

                        if ((itemType === 'EVE' || itemType === '') && dataEvento) {
                            const descrizione = item.descrizione || item.titolo || '';

                            events.push({
                                id: `mom-eve-${item.id}`,
                                title: item.item_code ? `${item.item_code}: ${descrizione.substring(0, 50)}` : descrizione.substring(0, 50),
                                start: dataEvento,
                                end: dataEvento,
                                status: 'evento',
                                url: `index.php?section=${mom.section || 'collaborazione'}&page=mom&id=${mom.id}&action=view`,
                                color: '#3498DB',
                                meta: {
                                    momId: mom.id,
                                    momTitolo: mom.titolo,
                                    itemId: item.id,
                                    itemCode: item.item_code || '',
                                    descrizione: descrizione,
                                    responsabile: item.responsabile || ''
                                }
                            });
                        }
                    });
                }
            }

            return events;
        } catch (error) {
            console.error('[MOM Calendar Provider] Errore:', error);
            return [];
        }
    });
}

// === MOM CALENDAR INTEGRATION ===
(function () {
    // 1. Function to fetch MOM events
    async function fetchMomEvents(info) {
        try {
            // FullCalendar passes start/end in info (ISO strings)
            // CalendarView might pass them differently or not at all if custom
            // CalendarView chiama provider() senza argomenti (vedi line 758 di calendar_view.js)
            // FullCalendar passerebbe info object. Gestiamo entrambi i casi.
            const infoObj = info || {};
            const start = infoObj.startStr || (infoObj.start ? new Date(infoObj.start).toISOString() : '');
            const end = infoObj.endStr || (infoObj.end ? new Date(infoObj.end).toISOString() : '');

            const filters = {
                start: start,
                end: end,
                filterSection: (window.momApp && momApp.getCurrentSection) ? momApp.getCurrentSection() : 'commerciale'
            };

            const response = await customFetch('mom', 'getEvents', filters);

            if (response.success && Array.isArray(response.data)) {
                return response.data.map(evt => {
                    // Client-side coloring
                    if (evt.extendedProps && evt.extendedProps.item_type === 'EVE') {
                        evt.backgroundColor = '#28a745'; // Green for events
                        evt.borderColor = '#1e7e34';
                        evt.textColor = '#ffffff';
                    }
                    return evt;
                });
            }
            return [];
        } catch (e) {
            console.error("[MOM Calendar] Error fetching events:", e);
            return [];
        }
    }

    // 2. Combined Provider (MOM + potentially others)
    // Note: CalendarView expects a function that returns a Promise resolving to an array of events
    async function combinedMomProvider(info, successCallback, failureCallback) {
        // Se chiamato da CalendarView custom, info potrebbe essere undefined
        const events = await fetchMomEvents(info);

        // If there were a default provider, we would merge results here.
        // For now, we return MOM events.

        if (typeof successCallback === 'function') successCallback(events);
        return events;
    }

    // 3. Register Provider (PR1 logic: set global provider + update view if active)
    // "niente registerCalendarProvider; usare combinedProvider + CalendarView.setProvider() + refresh()"

    // Expose for initialization
    window.calendarDataProvider = combinedMomProvider;

    // Direct update if CalendarView is already loaded AND initialized
    // (avoid calling refresh() if init() hasn't created DOM elements yet)
    if (window.CalendarView && typeof window.CalendarView.setProvider === 'function') {
        window.CalendarView.setProvider(combinedMomProvider);

        // Check if CalendarView is fully initialized (has state and DOM elements)
        // Accessing internal _state is risky but necessary here to avoid crash
        if (window.CalendarView._state && window.CalendarView._state.header && typeof window.CalendarView.refresh === 'function') {
            window.CalendarView.refresh();
        }
    }

})();

// Esposizione per pulsante generico print/export
if (window.momApp && window.momApp.exportPdf) {
    window.handleExportOrPrint = window.momApp.exportPdf;
}

// ======== TASK-KANBAN SYNC INTEGRATION ========
(function () {
    // Intercetta TaskApi.moveTask per aggiornare automaticamente gli stati degli items
    document.addEventListener('DOMContentLoaded', function () {
        const originalMoveTask = window.TaskApi?.moveTask;
        if (originalMoveTask) {
            window.TaskApi.moveTask = async function (taskId, newStatusId, newPosition) {
                console.log('Intercepting moveTask:', taskId, newStatusId, newPosition);

                // Prima chiama la funzione originale
                const result = await originalMoveTask.call(this, taskId, newStatusId, newPosition);

                // Poi aggiorna lo stato dell'item corrispondente
                if (result && result.success) {
                    try {
                        console.log('Calling updateItemStatusFromTask for taskId:', taskId);
                        const updateResult = await customFetch('mom', 'updateItemStatusFromTask', { taskId: taskId });
                        console.log('Update result:', updateResult);

                        if (updateResult.success) {
                            // Ricarica il dettaglio per riflettere il cambiamento
                            // Recupera ID dal form hidden field per evitare fallback e errori
                            const currentId = document.getElementById('mom-id')?.value || window.momId;

                            if (typeof momApp !== 'undefined' && momApp.caricaDettaglio && currentId) {
                                momApp.caricaDettaglio(currentId, true);
                            }
                        }
                    } catch (error) {
                        console.error('Errore aggiornamento stato item:', error);
                    }
                }

                return result;
            };
        }

        /**
         * Aggiorna il titolo della pagina dinamicamente
         * Formato: [Protocollo] - [Titolo]
         * Visibile solo se protocollo esiste (verbale salvato)
         */
        function updatePageTitle() {
            const titleContainer = document.getElementById('mom-page-title');
            const titleText = document.getElementById('mom-page-title-text');
            const protocollo = document.getElementById('mom-progressivo-display')?.value || '';
            const titolo = document.getElementById('mom-titolo')?.value || '';

            if (!titleContainer || !titleText) return;

            // Mostra solo se c'è un protocollo (verbale già salvato)
            if (protocollo && protocollo.trim()) {
                const fullTitle = titolo ? `${protocollo} - ${titolo}` : protocollo;
                titleText.textContent = fullTitle;
                titleContainer.classList.remove('mom-page-title-hidden');
                titleContainer.classList.add('mom-page-title-visible');
            } else {
                // Nascondi per verbali nuovi
                titleContainer.classList.remove('mom-page-title-visible');
                titleContainer.classList.add('mom-page-title-hidden');
            }
        }

        // Aggiorna titolo anche quando cambiano i campi input
        document.addEventListener('DOMContentLoaded', function () {
            const protocolloField = document.getElementById('mom-progressivo-display');
            const titoloField = document.getElementById('mom-titolo');

            if (protocolloField) {
                protocolloField.addEventListener('change', updatePageTitle);
            }
            if (titoloField) {
                titoloField.addEventListener('input', updatePageTitle);
            }
        });

        // Gestione doppio click per apertura drawer task (come nel Kanban)
        const itemsContainer = document.getElementById('mom-items-container');
        if (itemsContainer && !itemsContainer.hasAttribute('data-task-drawer-init')) {
            itemsContainer.setAttribute('data-task-drawer-init', 'true');

            // CSS dinamico per feedback visivo sulle righe cliccabili
            const style = document.createElement('style');
            style.textContent = `
                #mom-items-container tr { cursor: pointer; }
                #mom-items-container tr input[readonly] { cursor: pointer !important; }
            `;
            document.head.appendChild(style);

            itemsContainer.addEventListener('dblclick', function (e) {
                const tr = e.target.closest('tr');
                if (!tr || e.target.closest('a:not(.mom-item-link-trigger), button, textarea, select, [data-no-row-open]')) return;

                // Permetti il click se non è un input, o se lo è ma è readonly (come la colonna Item)
                const isInput = e.target.closest('input');
                const isLinkWrapper = e.target.closest('.mom-item-link-trigger');
                if (isInput && !isInput.readOnly && !isLinkWrapper) return;

                const itemId = tr.querySelector('input.row-id')?.value?.trim();
                if (itemId && window.MomItemDetails) {
                    window.MomItemDetails.open(itemId);
                }
            });

            itemsContainer.addEventListener('click', function (e) {
                const link = e.target.closest('.mom-item-link-trigger');
                if (!link) return;
                
                e.preventDefault();
                const tr = link.closest('tr');
                if (!tr) return;

                const itemId = tr.querySelector('input.row-id')?.value?.trim();
                if (itemId && window.MomItemDetails) {
                    window.MomItemDetails.open(itemId);
                }
            });
        }

        // Listener per sincronizzazione selettiva riga dopo modifiche nel drawer (checklist, etc)
        document.addEventListener('mom:itemUpdated', async function (e) {
            const itemId = e.detail?.itemId;
            if (!itemId) return;

            console.log('[MOM] Selective refresh for itemId:', itemId);

            try {
                const res = await window.customFetch('mom', 'getItemDetails', { itemId });
                if (!res.success || !res.data) return;

                const item = res.data;
                const container = document.getElementById('mom-items-container');
                if (!container) return;

                // Trova la riga corrispondente
                const row = Array.from(container.querySelectorAll('tr')).find(tr => {
                    const idInput = tr.querySelector('input.row-id');
                    return idInput && idInput.value == itemId;
                });

                if (row) {
                    console.log('[MOM] Updating row UI for item:', itemId);
                    // Aggiorna i campi (se presenti)
                    const descrizione = row.querySelector('[data-field="descrizione"]');
                    if (descrizione && item.descrizione !== undefined) descrizione.value = item.descrizione;

                    const responsabile = row.querySelector('[data-field="responsabile"]');
                    if (responsabile && item.responsabile !== undefined) responsabile.value = item.responsabile;

                    const dataTarget = row.querySelector('[data-field="dataTarget"]');
                    if (dataTarget && item.data_target !== undefined) {
                        dataTarget.value = item.data_target ? item.data_target.split(' ')[0] : '';
                    }

                    const stato = row.querySelector('[data-field="stato"]');
                    if (stato && item.stato !== undefined) {
                        stato.value = item.stato;
                        // Trigger change per eventuali stili associati allo stato
                        stato.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            } catch (err) {
                console.error('[MOM] Errore durante il selective refresh:', err);
            }
        });

        // Esponi updatePageTitle globalmente per essere chiamata dopo il salvataggio
        window.updateMomPageTitle = updatePageTitle;
    });
})();
