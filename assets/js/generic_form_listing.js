/**
 * GENERIC FORM LISTING - JavaScript universale per tutte le pagine del page_editor
 * 
 * Gestisce:
 * - Caricamento dati forms con subtasks
 * - Rendering tabella
 * - Rendering kanban
 * - Filtri temporali
 * - Switch vista
 */

document.addEventListener('DOMContentLoaded', async function () {
    console.log('🚀 Generic Form Listing loaded');

    const bootstrap = window.__FORM_LISTING_BOOTSTRAP__ || {};
    const STATI_MAP = bootstrap.STATI_MAP || {};
    const formName = bootstrap.formName;
    const formColor = bootstrap.formColor || '#007bff';

    if (!formName) {
        console.error('⚠️ Form name missing!');
        return;
    }

    let currentFilter = 'all';
    window.__isLoadingForms = false;

    // ========== CARICA DATI ==========
    async function loadForms() {
        if (window.__isLoadingForms) return;
        window.__isLoadingForms = true;

        try {
            const response = await window.customFetch('page_editor', 'getFilledForms', {
                form_name: formName
            });

            if (!response || !response.success) {
                console.error('Errore caricamento forms:', response);
                document.getElementById("forms-list").innerHTML = "<tr><td colspan='11'>Errore caricamento dati</td></tr>";
                return;
            }

            const forms = response.forms || [];
            console.log(`✅ Caricati ${forms.length} records`);

            renderTable(forms);
            renderKanban(forms);

            // Attacca event listeners per subtask toggle (anche per quelle renderizzate dal PHP)
            setTimeout(() => {
                document.querySelectorAll('.kanban-subtask').forEach(subtaskEl => {
                    attachSubtaskToggleListener(subtaskEl);
                });
            }, 100);

        } catch (error) {
            console.error('Errore:', error);
        } finally {
            window.__isLoadingForms = false;
        }
    }

    // ========== RENDER TABELLA ==========
    function renderTable(forms) {
        const tableBody = document.getElementById("forms-list");
        if (!tableBody) return;

        tableBody.innerHTML = '';

        if (!forms || forms.length === 0) {
            tableBody.innerHTML = "<tr><td colspan='11'>Nessun record trovato</td></tr>";
            return;
        }

        forms.forEach(form => {
            const statoString = STATI_MAP[form.stato] || `Stato ${form.stato}`;

            // RIGA PRINCIPALE
            const row = document.createElement("tr");
            row.setAttribute("data-id", form.id);
            row.setAttribute("data-status", form.stato);

            // Azioni
            const tdAzioni = document.createElement("td");
            tdAzioni.innerHTML = `
                <a href="index.php?section=${encodeURIComponent(form.section || 'collaborazione')}&page=view_form&form_name=${encodeURIComponent(form.form_name)}&id=${form.id}&edit=true" 
                   class="btn-icon" data-tooltip="Modifica">✏️</a>
                <a href="index.php?section=${encodeURIComponent(form.section || 'collaborazione')}&page=form_viewer&form_name=${encodeURIComponent(form.form_name)}&id=${form.id}" 
                   class="btn-icon" data-tooltip="Visualizza">👁️</a>
            `;
            row.appendChild(tdAzioni);

            // Protocollo
            const tdProtocollo = document.createElement("td");
            tdProtocollo.textContent = form.codice_segnalazione || '-';
            row.appendChild(tdProtocollo);

            // Titolo
            const tdTitolo = document.createElement("td");
            tdTitolo.textContent = form.titolo || '-';
            row.appendChild(tdTitolo);

            // Descrizione
            const tdDescrizione = document.createElement("td");
            const descPreview = (form.descrizione || '').substring(0, 50);
            tdDescrizione.textContent = descPreview + (descPreview.length >= 50 ? '...' : '');
            row.appendChild(tdDescrizione);

            // Creato da
            const tdCreatoDa = document.createElement("td");
            tdCreatoDa.textContent = form.creato_da || '-';
            row.appendChild(tdCreatoDa);

            // Assegnato a
            const tdAssegnatoA = document.createElement("td");
            tdAssegnatoA.textContent = form.assegnato_a || '-';
            row.appendChild(tdAssegnatoA);

            // Responsabile
            const tdResponsabile = document.createElement("td");
            tdResponsabile.textContent = form.responsabile_nome || '-';
            row.appendChild(tdResponsabile);

            // Data invio
            const tdDataInvio = document.createElement("td");
            tdDataInvio.textContent = form.data_invio || '-';
            row.appendChild(tdDataInvio);

            // Data scadenza
            const tdDataScadenza = document.createElement("td");
            tdDataScadenza.textContent = form.data_scadenza || '-';
            row.appendChild(tdDataScadenza);

            // Stato
            const tdStato = document.createElement("td");
            tdStato.innerHTML = `<span class="badge stato-${statoString.toLowerCase().replace(/\s+/g, '_')}">${statoString}</span>`;
            row.appendChild(tdStato);

            // Priorità
            const tdPriorita = document.createElement("td");
            tdPriorita.textContent = form.priority || '-';
            row.appendChild(tdPriorita);

            tableBody.appendChild(row);

            // SUBTASKS (se presenti) — riga semplificata, nessun campo ereditato dal parent
            if (form.subtasks && Array.isArray(form.subtasks) && form.subtasks.length > 0) {
                form.subtasks.forEach(subtask => {
                    const subtaskRow = document.createElement("tr");
                    subtaskRow.classList.add('subtask-row');
                    subtaskRow.setAttribute("data-parent-id", form.id);
                    subtaskRow.style.backgroundColor = "#f8f9fa";
                    subtaskRow.style.borderLeft = "4px solid " + (formColor || '#007bff');
                    subtaskRow.innerHTML = `
                        <td><span style="padding-left:20px;color:#888;">&#8627;</span></td>
                        <td colspan="10" style="padding-left:20px;font-size:0.9em;color:#666;">
                            <strong>${(subtask.scheda_label || 'Subtask').replace(/</g, '&lt;')}</strong>
                        </td>
                    `;
                    tableBody.appendChild(subtaskRow);
                });
            }
        });

        // Inizializza filtri tabella
        if (typeof initTableFilters === "function") {
            initTableFilters('genericFormTable');
        }
    }

    // ========== SUBTASK TOGGLE HANDLER ==========
    function attachSubtaskToggleListener(subtaskEl) {
        if (!subtaskEl) return;

        // Rimuovi listener precedenti se esistono (per evitare duplicati)
        const newSubtaskEl = subtaskEl.cloneNode(true);
        subtaskEl.parentNode.replaceChild(newSubtaskEl, subtaskEl);

        // Usa event delegation per gestire click su subtask o icona toggle
        newSubtaskEl.addEventListener('click', (e) => {
            e.stopPropagation(); // Evita che il click si propaghi alla task principale

            const isCollapsed = newSubtaskEl.classList.contains('collapsed');
            const toggleIcon = newSubtaskEl.querySelector('.kanban-subtask-toggle-icon');

            if (isCollapsed) {
                // Espandi
                newSubtaskEl.classList.remove('collapsed');
                newSubtaskEl.classList.add('expanded');
                if (toggleIcon) {
                    toggleIcon.textContent = '▼';
                }
            } else {
                // Contrai
                newSubtaskEl.classList.remove('expanded');
                newSubtaskEl.classList.add('collapsed');
                if (toggleIcon) {
                    toggleIcon.textContent = '▶';
                }
            }
        });
    }

    // ========== RENDER KANBAN ==========
    function renderKanban(forms) {
        // Pulisci kanban
        Object.keys(STATI_MAP).forEach(statoId => {
            const statoKey = STATI_MAP[statoId].toLowerCase().replace(/\s+/g, '_');
            const column = document.querySelector(`#kanban-${statoKey}`);
            if (column) column.innerHTML = '';
        });

        if (!forms || forms.length === 0) {
            console.warn("⚠️ Nessun form da mostrare nel kanban");
            return;
        }

        forms.forEach(form => {
            const statoString = STATI_MAP[form.stato] || `Stato ${form.stato}`;
            const statoKey = statoString.toLowerCase().replace(/\s+/g, '_');

            // Usa il renderer unificato
            if (window.KanbanRenderer && typeof window.KanbanRenderer.renderTask === 'function') {
                const renderResult = window.KanbanRenderer.renderTask(form, {
                    tabella: form.table_name || formName,
                    kanbanType: 'generic'
                });

                const kanbanColumn = document.querySelector(`#kanban-${statoKey}`);
                if (kanbanColumn) {
                    // Aggiungi task principale
                    kanbanColumn.appendChild(renderResult.taskElement);

                    // Aggiungi subtasks
                    if (renderResult.subtasksHtml && Array.isArray(renderResult.subtasksHtml)) {
                        renderResult.subtasksHtml.forEach(subtaskHtml => {
                            const subtaskWrapper = document.createElement('div');
                            subtaskWrapper.innerHTML = subtaskHtml;
                            const subtaskEl = subtaskWrapper.firstElementChild;
                            kanbanColumn.appendChild(subtaskEl);

                            // Aggiungi event listener per toggle sulla subtask
                            if (subtaskEl) {
                                attachSubtaskToggleListener(subtaskEl);
                            }
                        });
                    }
                } else {
                    console.warn(`⚠️ Colonna Kanban non trovata per stato "${statoString}"`);
                }
            } else {
                console.error('⚠️ KanbanRenderer non disponibile!');
            }
        });

        // Setup event delegation per Kanban interattivo
        setupKanbanInteractions();
    }

    /**
     * Gestisce le interazioni click/dblclick sulle card Kanban
     */
    function setupKanbanInteractions() {
        const kanbanBoard = document.querySelector('.kanban-container');
        if (!kanbanBoard || kanbanBoard.dataset.interactionsInit === 'true') return;

        kanbanBoard.dataset.interactionsInit = 'true';
        let clickTimer = null;

        kanbanBoard.addEventListener('click', (e) => {
            const taskEl = e.target.closest('.task');
            if (!taskEl) return;

            // Evita se si clicca su elementi interattivi
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('.action-icon') || e.target.closest('.kanban-subtask-toggle-icon')) {
                return;
            }

            const id = taskEl.getAttribute('data-task-id');
            const section = (window.__FORM_LISTING_BOOTSTRAP__ || {}).section || 'collaborazione';
            const url = `index.php?section=${encodeURIComponent(section)}&page=form_viewer&form_name=${encodeURIComponent(formName)}&id=${id}`;

            if (clickTimer) clearTimeout(clickTimer);
            clickTimer = setTimeout(() => {
                window.location.href = url;
                clickTimer = null;
            }, 250);
        });

        kanbanBoard.addEventListener('dblclick', (e) => {
            const taskEl = e.target.closest('.task');
            if (!taskEl) return;

            if (clickTimer) {
                clearTimeout(clickTimer);
                clickTimer = null;
            }

            // Evita se si clicca su elementi interattivi
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('.action-icon') || e.target.closest('.kanban-subtask-toggle-icon')) {
                return;
            }

            const id = taskEl.getAttribute('data-task-id');
            if (window.TaskDetails && typeof window.TaskDetails.open === 'function') {
                window.TaskDetails.open(id);
            }
        });
    }

    // ========== FILTRI TEMPORALI ==========
    document.querySelectorAll('#time-filters .btn-filter').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#time-filters .btn-filter').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentFilter = btn.getAttribute('data-days');
            loadForms();
        });
    });

    // Imposta filtro default
    const defaultFilterBtn = document.querySelector('#time-filters .btn-filter[data-days="all"]');
    if (defaultFilterBtn) defaultFilterBtn.classList.add('active');

    // Carica dati iniziali
    await loadForms();

    console.log('✅ Generic Form Listing inizializzato');
});

