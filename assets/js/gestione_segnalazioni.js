// ==== BOOTSTRAP pagina (dati da PHP) ====

// Inizializza variabili globali
window.__isLoadingSegnalazioni = false;
window.__calendarCache = {
    data: null,
    timestamp: 0,
    ttl: 30000 // 30 secondi di cache
};

const BOOT = window.__SEGNALAZIONI_BOOTSTRAP__ || {};

// STATI_MAP ora viene da main_core.js (centralizzato)
// Se il bootstrap ha stati custom, sovrascrivili
if (BOOT.STATI_MAP && Object.keys(BOOT.STATI_MAP).length > 0) {
    window.STATI_MAP = BOOT.STATI_MAP;
    window.STATI_MAP_REVERSE = Object.fromEntries(Object.entries(BOOT.STATI_MAP).map(([k, v]) => [v, parseInt(k)]));
}

// Utility: usa parseDateToISO centralizzata (da main_core.js)
const toISO = (v) => window.utils?.parseDateToISO?.(v) || v || '';

// ==== EventBus per filtri/vista condivisi ====

window.FilterBus = window.FilterBus || {

    _em: new EventTarget(),

    on: (t, fn) => window.FilterBus._em.addEventListener(t, fn),

    emit: (t, detail) => window.FilterBus._em.dispatchEvent(new CustomEvent(t, { detail }))

};



// Stato filtri corrente + util

window.getActiveFilters = () => (window.__ACTIVE_FILTERS__ || {});

// Se vuoi forzare l'apertura della LISTA quando clicchi un filtro

const FORCE_LIST_ON_FILTER = false;



// Applica un filtro “ultimi N giorni” e notifica tutto (tabella, calendario, gantt)

window.applyFilterDays = function (days) {

    const today = new Date(); today.setHours(0, 0, 0, 0);

    let from = null, to = null;

    if (days !== 'all') {

        const n = Number(days) || 0;

        to = new Date(today);

        from = new Date(today); from.setDate(today.getDate() - n);

    }

    window.__ACTIVE_FILTERS__ = { from: from ? toISO(from) : null, to: to ? toISO(to) : null };



    if (FORCE_LIST_ON_FILTER) {

        document.getElementById('elenco-view')?.classList.remove('hidden');

        document.getElementById('calendar-view')?.classList.add('hidden');

        document.getElementById('gantt-view')?.classList.add('hidden');

        if (typeof updateButtons === 'function') updateButtons();

    }



    // notifica widget

    window.FilterBus.emit('filters:changed', window.__ACTIVE_FILTERS__);



    // refresh widgets

    if (window.CalendarView?.refresh) CalendarView.refresh();

    if (window.GanttView?.refresh) GanttView.refresh();



    // refresh tabella

    if (typeof window.reloadSegnalazioniTable === 'function') {

        window.reloadSegnalazioniTable(window.__ACTIVE_FILTERS__);

    }

};



// Wrapper del provider condiviso (una sola volta)

if (!window.__PROVIDER_WRAPPED__) {

    const orig = window.calendarDataProvider;

    if (typeof orig === 'function') {

        window.calendarDataProvider = async function (...args) {

            const data = await orig.apply(this, args);

            const f = window.getActiveFilters();

            if (!f || (!f.from && !f.to)) return data;



            const parse = (s) => {

                if (!s) return null;

                let m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);

                if (m) return new Date(+m[1], +m[2] - 1, +m[3]);

                m = String(s).match(/^(\d{2})\/(\d{2})\/(\d{4})/);

                if (m) return new Date(+m[3], +m[2] - 1, +m[1]);

                return null;

            };



            return (Array.isArray(data) ? data : []).filter(ev => {

                const s = parse(ev.start);

                const e = ev.end ? parse(ev.end) : s;

                if (!s) return false;

                if (f.from && e && e < parse(f.from)) return false;

                if (f.to && s && s > parse(f.to)) return false;

                return true;

            });

        };

        window.__PROVIDER_WRAPPED__ = true;

    }

}





function apriViewer(formName, id) {

    const currentSection = new URLSearchParams(location.search).get('section') || 'collaborazione';

    const url = new URL('index.php', window.location.origin);

    url.searchParams.set('section', currentSection);

    url.searchParams.set('page', 'form_viewer');

    url.searchParams.set('form_name', formName);

    url.searchParams.set('id', id);

    window.location.href = url.toString();

}



function apriViewerEsito(formName, id) {

    const currentSection = new URLSearchParams(location.search).get('section') || 'collaborazione';

    const url = new URL('index.php', window.location.origin);

    url.searchParams.set('section', currentSection);

    url.searchParams.set('page', 'form_viewer');

    url.searchParams.set('form_name', formName);

    url.searchParams.set('id', id);

    url.hash = 'esito';

    window.location.href = url.toString();

}



function apriEditor(formName, id) {

    const currentSection = new URLSearchParams(location.search).get('section') || 'collaborazione';

    const url = new URL('index.php', window.location.origin);

    url.searchParams.set('section', currentSection);

    url.searchParams.set('page', 'form');

    url.searchParams.set('form_name', formName);

    url.searchParams.set('id', id);

    url.searchParams.set('edit', 'true');

    window.location.href = url.toString();

}



// Init pagina: filtri rapidi, vista iniziale, prima fetch, kanban

document.addEventListener('DOMContentLoaded', () => {

    // Listener per filtri dinamici (filters.js): reagisce solo al proprio container
    window.addEventListener('filters:applied', (e) => {
        const detail = e.detail || {};
        const containerId = (detail.state && detail.state.containerId)
                         || (detail.meta && detail.meta.containerId);
        if (containerId !== 'filter-container') return;
        // Preferisci state.filters, fallback a detail.filters (backward compat)
        const filters = (detail.state && detail.state.filters) || detail.filters || {};
        loadSegnalazioni(filters);
    });

    const timeFilters = document.getElementById('time-filters');

    if (timeFilters) {

        timeFilters.querySelectorAll('button.btn-filter').forEach(btn => {

            btn.addEventListener('click', () => {

                timeFilters.querySelectorAll('button.btn-filter').forEach(b => b.classList.remove('active'));

                btn.classList.add('active');

                window.applyFilterDays(btn.getAttribute('data-days') || 'all');

                loadSegnalazioni(window.__ACTIVE_FILTERS__ || {});

            });

        });

    }



    const listView = document.getElementById('elenco-view');

    const kanbanView = document.getElementById('kanban-view');

    const urlParams = new URLSearchParams(location.search);



    if (urlParams.get('kanban') === 'true') {

        listView?.classList.add('hidden');

        kanbanView?.classList.remove('hidden');

    }



    if (typeof window.__ACTIVE_FILTERS__ === 'undefined') {

        const f = {};

        ['form_name', 'responsabile', 'status_id'].forEach(k => { if (urlParams.has(k)) f[k] = urlParams.get(k); });

        window.__ACTIVE_FILTERS__ = f;

    }



    // Carica prima gli utenti assegnabili, poi le segnalazioni
    loadUtentiAssegnabili().then(() => {
        loadSegnalazioni(window.__ACTIVE_FILTERS__ || {});
    });

    setupKanbanEventListeners();

});



// statiMap ora viene da status_maps.js (centralizzato)
var statiMap = window.STATI_MAP;

// Lista utenti assegnabili (caricata all'avvio)
var utentiAssegnabili = [];

// Carica lista utenti assegnabili
async function loadUtentiAssegnabili() {
    try {
        const data = await customFetch('forms', 'getUtentiList');
        if (data.success && Array.isArray(data.data)) {
            utentiAssegnabili = data.data;
        }
    } catch (e) {
        console.error('Errore caricamento utenti assegnabili:', e);
    }
}



// ========== AVATAR + PICKER (usa funzioni globali da main_core.js) ==========
function resolveUsers(userIds) {
    const userMap = {};
    utentiAssegnabili.forEach(u => { userMap[String(u.user_id)] = u; });
    return userIds.map(id => userMap[String(id)]).filter(Boolean);
}

function renderAssigneeAvatars(container, userIds) {
    const users = resolveUsers(userIds);
    window.renderAvatarsOverlap(container, users);
}

function handleAssigneeClick(e) {
    e.stopPropagation();
    const cell = e.currentTarget;
    const formId = cell.dataset.formId;
    const formNameVal = cell.dataset.formName;
    const tableName = cell.dataset.tableName;
    const currentIds = window.normalizeIdList(cell.dataset.currentIds);

    window.openMultiUserPicker({
        users: utentiAssegnabili,
        selectedIds: currentIds,
        max: 6,
        onConfirm: async function (selectedIds) {
            const newValue = selectedIds.join(',');
            try {
                const res = await customFetch('forms', 'aggiornaAssegnatoA', {
                    form_id: formId,
                    form_name: formNameVal,
                    table_name: tableName,
                    assegnato_a: newValue
                });
                if (res.success) {
                    showToast('Assegnatari aggiornati.', 'success');
                    cell.dataset.currentIds = newValue;
                    const row = cell.closest('tr');
                    if (row) row.setAttribute('data-assegnato_a', newValue);
                    const newIds = window.normalizeIdList(newValue);
                    renderAssigneeAvatars(cell, newIds);
                } else {
                    showToast(res.message || 'Errore aggiornamento.', 'error');
                }
            } catch (err) {
                console.error('Errore salvataggio assegnatari:', err);
                showToast('Errore di rete.', 'error');
            }
        }
    });
}

window.isCurrentUserAdmin = function () {

    return String(window.CURRENT_USER?.role_id) === '1';

};



// Usa la funzione hexToRgba dal KanbanRenderer se disponibile

function hexToRgba(hex, alpha = 1) {

    if (window.KanbanRenderer && window.KanbanRenderer.hexToRgba) {

        return window.KanbanRenderer.hexToRgba(hex, alpha);

    }

    // Fallback

    if (!hex) return `rgba(204,204,204,${alpha})`;

    const bigint = parseInt(hex.replace('#', ''), 16);

    const r = (bigint >> 16) & 255;

    const g = (bigint >> 8) & 255;

    const b = bigint & 255;

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;

}



function loadSegnalazioni(filtri = {}) {
    // Inizializza se undefined
    if (window.__isLoadingSegnalazioni === undefined) {
        window.__isLoadingSegnalazioni = false;
    }

    if (window.__isLoadingSegnalazioni) return;

    window.__isLoadingSegnalazioni = true;

    // Combina filtri dinamici con filtri temporali esistenti
    const appliedState = (window.__APPLIED_FILTERS__ && window.__APPLIED_FILTERS__['filter-container']) || {};
    const appliedFilters = appliedState.filters || appliedState;
    const combinedFilters = {
        ...(window.__ACTIVE_FILTERS__ || {}),
        ...appliedFilters,
        ...filtri
    };

    customFetch('page_editor', 'getFilledForms', combinedFilters)

        .then(data => {

            if (data.success && Array.isArray(data.forms) && data.forms.length > 0) {

                const tableBody = document.getElementById("segnalazioni-list");

                tableBody.innerHTML = "";



                document.querySelectorAll(".task-container").forEach(container => container.innerHTML = "");



                data.forms.forEach(segnalazione => {

                    const statoString = statiMap[parseInt(segnalazione.stato)] || "Da Definire";

                    const statoKey = statoString.replace(/\s+/g, '_').toLowerCase();

                    const uid = `${segnalazione.table_name}_${segnalazione.id}`;



                    const row = document.createElement("tr");

                    row.setAttribute("data-assegnato_a", segnalazione.assegnato_a || "");

                    row.setAttribute("data-responsabile", segnalazione.responsabile || "");

                    row.setAttribute("data-submitted_by", segnalazione.submitted_by || "");

                    row.setAttribute("data-form-name", segnalazione.form_name || "");

                    row.setAttribute("data-table-name", segnalazione.table_name || "");

                    // Aggiungi attributi per identificare la task principale
                    row.setAttribute("data-task-id", segnalazione.id);
                    row.setAttribute("data-task-type", "main");



                    const tdAzioni = document.createElement("td");

                    tdAzioni.classList.add("azioni-colonna");



                    const creatorId = String(segnalazione.submitted_by);

                    const currentUserId = String(window.CURRENT_USER?.id || window.CURRENT_USER?.user_id || '');



                    if (creatorId === currentUserId) {

                        const editBtn = document.createElement("button");

                        editBtn.classList.add("action-icon");

                        editBtn.setAttribute("data-tooltip", "Modifica segnalazione");

                        editBtn.innerHTML = '<img src="assets/icons/edit.png" alt="Modifica">';

                        editBtn.addEventListener("click", () => {

                            apriEditor(segnalazione.form_name, segnalazione.id);

                        });

                        tdAzioni.appendChild(editBtn);



                        const deleteBtn = document.createElement("button");

                        deleteBtn.classList.add("action-icon");

                        deleteBtn.setAttribute("data-tooltip", "Elimina segnalazione");

                        deleteBtn.innerHTML = '<img src="assets/icons/delete.png" alt="Elimina">';

                        deleteBtn.addEventListener("click", () => {

                            showConfirm("Sei sicuro di voler eliminare questa segnalazione?", function () {

                                customFetch('forms', 'deleteFormEntry', {

                                    form_name: segnalazione.form_name,

                                    record_id: segnalazione.id

                                })

                                    .then(response => {

                                        if (response.success) {

                                            showToast("Segnalazione eliminata con successo.", "success");

                                            loadSegnalazioni(window.__ACTIVE_FILTERS__ || {});

                                        } else {

                                            showToast("Errore: " + (response.message || "Eliminazione fallita"), "error");

                                        }

                                    })

                                    .catch(err => showToast("Errore di rete: " + err, "error"));

                            });

                        });

                        tdAzioni.appendChild(deleteBtn);

                    }



                    const assegnatoAId = String(segnalazione.assegnato_a || "");

                    const responsabileId = String(segnalazione.responsabile || "");



                    if (

                        ((currentUserId === assegnatoAId || currentUserId === responsabileId) || isCurrentUserAdmin())

                        && !segnalazione.archiviata

                    ) {

                        const archiveBtn = document.createElement("button");

                        archiveBtn.classList.add("action-icon");

                        archiveBtn.setAttribute("data-tooltip", "Archivia segnalazione");

                        archiveBtn.innerHTML = '<img src="assets/icons/folder.png" alt="Archivia">';

                        archiveBtn.addEventListener("click", () => {

                            showConfirm("Sei sicuro di voler archiviare questa segnalazione? Verrà spostata nell'archivio.", function () {

                                customFetch('forms', 'archiviaSegnalazione', {

                                    form_name: segnalazione.form_name,

                                    record_id: segnalazione.id

                                })

                                    .then(response => {

                                        if (response.success) {

                                            showToast("Segnalazione archiviata!", "success");

                                            loadSegnalazioni(window.__ACTIVE_FILTERS__ || {});

                                        } else {

                                            showToast("Errore: " + (response.message || "Archiviazione fallita"), "error");

                                        }

                                    })

                                    .catch(err => showToast("Errore di rete: " + err, "error"));

                            });

                        });

                        tdAzioni.appendChild(archiveBtn);

                    }



                    row.appendChild(tdAzioni);



                    const tdProtocollo = document.createElement("td");

                    tdProtocollo.classList.add("protocollo-colonna");

                    const codiceCompleto = segnalazione.codice_segnalazione || '-';

                    // Aggiungi toggle se ci sono subtask
                    const hasSubtasks = segnalazione.subtasks && Array.isArray(segnalazione.subtasks) && segnalazione.subtasks.length > 0;
                    const toggleIcon = hasSubtasks ? '<span class="subtask-toggle-icon" style="margin-right: 8px; cursor: pointer; font-size: 12px; color: #007bff;">▼</span>' : '';

                    tdProtocollo.innerHTML = `

                        <div style="display: flex; align-items: center; min-height: 24px;">

                            <div style="width: 5px; height: 24px; background-color: ${segnalazione.color || '#ccc'}; margin-right: 6px; border-radius: 2px;"></div>

                            ${toggleIcon}<span>${codiceCompleto}</span>

                        </div>

                    `;

                    // Aggiungi event listener per il toggle se ci sono subtask
                    if (hasSubtasks) {
                        tdProtocollo.setAttribute('data-has-subtasks', 'true');
                        tdProtocollo.setAttribute('data-task-id', segnalazione.id);
                        tdProtocollo.style.cursor = 'pointer';
                    }




                    const tdArgomentazione = document.createElement("td");

                    tdArgomentazione.classList.add("oggetto-colonna");



                    if (segnalazione.titolo && segnalazione.id && segnalazione.form_name) {

                        const link = document.createElement("a");

                        link.href = "#";

                        link.textContent = segnalazione.titolo;

                        link.style.cursor = "pointer";

                        link.style.textDecoration = "underline";

                        link.style.color = "#2980b9";

                        link.addEventListener("click", (e) => {

                            e.preventDefault();

                            apriViewer(segnalazione.form_name, segnalazione.id);

                        });

                        tdArgomentazione.appendChild(link);

                    } else {

                        tdArgomentazione.textContent = '—';

                    }



                    const tdCreatoDa = document.createElement("td");

                    tdCreatoDa.textContent = segnalazione.creato_da || 'N/D';

                    tdCreatoDa.classList.add("utente-colonna");



                    const tdAssegnatoA = document.createElement("td");
                    tdAssegnatoA.classList.add("utente-colonna", "assegnato-colonna");

                    // Solo admin, responsabile o assegnatario possono modificare l'assegnatario
                    const isAdmin = isCurrentUserAdmin();
                    const responsabiliList = window.normalizeIdList(segnalazione.responsabile);
                    const isResponsabileForm = responsabiliList.includes(String(currentUserId));
                    const assegnatoIds = window.normalizeIdList(segnalazione.assegnato_a).map(String);
                    const isAssegnatarioAttuale = assegnatoIds.includes(String(currentUserId));
                    const canEditAssegnatario = isAdmin || isResponsabileForm || isAssegnatarioAttuale;

                    // Render avatar sovrapposti (usa globale renderAvatarsOverlap via wrapper)
                    renderAssigneeAvatars(tdAssegnatoA, assegnatoIds);

                    if (canEditAssegnatario && utentiAssegnabili.length > 0) {
                        tdAssegnatoA.style.cursor = 'pointer';
                        tdAssegnatoA.classList.add('assignee-avatars-cell');
                        tdAssegnatoA.dataset.formId = segnalazione.id;
                        tdAssegnatoA.dataset.formName = segnalazione.form_name;
                        tdAssegnatoA.dataset.tableName = segnalazione.table_name;
                        tdAssegnatoA.dataset.currentIds = assegnatoIds.join(',');
                        tdAssegnatoA.addEventListener('click', handleAssigneeClick);
                    }



                    const tdResponsabile = document.createElement("td");
                    tdResponsabile.classList.add("utente-colonna");
                    
                    if (segnalazione.responsabili && Array.isArray(segnalazione.responsabili) && segnalazione.responsabili.length > 0) {
                        // Render avatar sovrapposti per i responsabili
                        window.renderAvatarsOverlap(tdResponsabile, segnalazione.responsabili);
                    } else if (segnalazione.responsabile_nome) {
                        tdResponsabile.textContent = segnalazione.responsabile_nome;
                    } else {
                        tdResponsabile.textContent = 'Non Assegnato';
                    }



                    const tdDataInvio = document.createElement("td");

                    tdDataInvio.textContent = segnalazione.data_invio || '-';

                    tdDataInvio.classList.add("data-colonna");



                    const tdDataScadenza = document.createElement("td");

                    tdDataScadenza.textContent = segnalazione.data_scadenza || '-';

                    tdDataScadenza.classList.add("data-colonna");



                    const tdPriorita = document.createElement("td");

                    tdPriorita.textContent = segnalazione["priority"] || "Media";

                    tdPriorita.classList.add("priorita-colonna");



                    const tdStato = document.createElement("td");

                    tdStato.innerHTML = `

                        <select class="stato-select" data-form-id="${segnalazione.id}" data-table="${segnalazione.table_name}" data-uid="${uid}">

                            ${Object.entries(statiMap).map(([key, value]) =>

                        `<option value="${key}" ${parseInt(segnalazione.stato) === parseInt(key) ? 'selected' : ''}>${value}</option>`

                    ).join('')}

                        </select>

                    `;



                    const selectEl = tdStato.querySelector('.stato-select');

                    const classiColori = ["aperta", "in_corso", "sospesa", "rifiutata", "chiusa"];

                    if (selectEl) {

                        const statoAttuale = statiMap[parseInt(segnalazione.stato)]?.toLowerCase().replace(/ /g, "_") || "aperta";

                        selectEl.classList.add(statoAttuale);

                        selectEl.setAttribute("data-prev-value", selectEl.value);



                        selectEl.addEventListener("change", function () {

                            classiColori.forEach(c => selectEl.classList.remove(c));

                            const nuovaClasse = statiMap[parseInt(this.value)]?.toLowerCase().replace(/ /g, "_") || "aperta";

                            selectEl.classList.add(nuovaClasse);

                        });

                    }



                    tdStato.classList.add("stato-colonna");



                    row.appendChild(tdProtocollo);


                    row.appendChild(tdArgomentazione);

                    row.appendChild(tdCreatoDa);

                    row.appendChild(tdAssegnatoA);

                    row.appendChild(tdResponsabile);

                    row.appendChild(tdDataInvio);

                    row.appendChild(tdDataScadenza);

                    row.appendChild(tdStato);

                    row.appendChild(tdPriorita);

                    tableBody.appendChild(row);



                    // MOSTRA SUBTASKS NELLA TABELLA

                    if (segnalazione.subtasks && Array.isArray(segnalazione.subtasks) && segnalazione.subtasks.length > 0) {

                        segnalazione.subtasks.forEach(subtask => {

                            const subRow = document.createElement("tr");
                            subRow.classList.add("subtask-row");
                            subRow.setAttribute("data-parent-task-id", segnalazione.id);
                            subRow.setAttribute("data-task-type", "subtask");
                            subRow.setAttribute("data-subtask-label", subtask.scheda_label || '');
                            subRow.style.backgroundColor = "#f8f9fa";
                            subRow.style.borderLeft = "4px solid #007bff";

                            // Colonna azioni: indicatore subtask + icona edit Esito (solo se Esito e canEditAssegnatario)
                            const subTdAzioni = document.createElement("td");
                            subTdAzioni.style.paddingLeft = '20px';
                            const arrowSpan = document.createElement("span");
                            arrowSpan.style.cssText = 'color:#888;';
                            arrowSpan.textContent = '\u21B3';
                            subTdAzioni.appendChild(arrowSpan);
                            const isEsito = String(subtask.scheda_label || '').toLowerCase() === 'esito';
                            if (isEsito && canEditAssegnatario) {
                                const editEsitoBtn = document.createElement("button");
                                editEsitoBtn.classList.add("action-icon");
                                editEsitoBtn.setAttribute("data-tooltip", "Modifica Esito");
                                editEsitoBtn.innerHTML = '<img src="assets/icons/edit.png" alt="Modifica Esito">';
                                editEsitoBtn.style.marginLeft = '6px';
                                editEsitoBtn.addEventListener("click", (e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    apriViewerEsito(segnalazione.form_name, segnalazione.id);
                                });
                                subTdAzioni.appendChild(editEsitoBtn);
                            }
                            subRow.appendChild(subTdAzioni);

                            // Info subtask (occupa tutte le colonne rimanenti)
                            const subTdInfo = document.createElement("td");
                            subTdInfo.colSpan = 9;
                            subTdInfo.style.cssText = "padding-left:20px;font-size:0.9em;color:#666;";
                            const labelEl = document.createElement("strong");
                            labelEl.textContent = subtask.scheda_label || 'Subtask';
                            subTdInfo.appendChild(labelEl);
                            subRow.appendChild(subTdInfo);

                            tableBody.appendChild(subRow);

                        });

                    }



                    tdStato.querySelector(".stato-select").addEventListener("change", function (e) {

                        const selectElement = this;



                        if (selectElement.dataset.skipUpdate === "true") {

                            selectElement.dataset.skipUpdate = "false";

                            return;

                        }



                        const row = selectElement.closest("tr");

                        const assegnatoA = String(row?.getAttribute("data-assegnato_a") || "");

                        const responsabile = String(row?.getAttribute("data-responsabile") || "");

                        const currentUserId = String(window.CURRENT_USER?.id || "");

                        const nuovoStatoId = selectElement.value;

                        const prevStatoId = selectElement.getAttribute("data-prev-value");



                        const aggiornaSelect = (statoId) => {

                            selectElement.dataset.skipUpdate = "true";

                            selectElement.value = statoId;

                            selectElement.setAttribute("data-prev-value", statoId);

                            const classiColori = ["aperta", "in_corso", "sospesa", "rifiutata", "chiusa"];

                            classiColori.forEach(c => selectElement.classList.remove(c));

                            const nuovaClasse = statiMap[parseInt(statoId)]?.toLowerCase().replace(/ /g, "_") || "aperta";

                            selectElement.classList.add(nuovaClasse);



                            setTimeout(() => selectElement.dataset.skipUpdate = "false", 0);

                        };



                        const cambiaStatoEffettivo = () => {

                            aggiornaSelect(nuovoStatoId);

                            aggiornaStato(selectElement.getAttribute("data-form-id"), nuovoStatoId, "table", selectElement.getAttribute("data-table"))

                                .finally(() => {

                                    selectElement.dataset.skipUpdate = "false";

                                });

                        };



                        if (currentUserId !== assegnatoA && currentUserId !== responsabile) {

                            if (isCurrentUserAdmin()) {

                                aggiornaSelect(prevStatoId);



                                showConfirm(
                                    "Stai per modificare lo stato come amministratore.<br>Non sei né responsabile né assegnatario.<br>Vuoi procedere?",
                                    () => {
                                        cambiaStatoEffettivo();
                                    },
                                    { allowHtml: true }
                                );

                            } else {

                                showToast("Solo l'assegnatario, il responsabile o l'amministratore possono cambiare lo stato.", "error");

                                aggiornaSelect(prevStatoId);

                            }

                        } else {

                            cambiaStatoEffettivo();

                        }

                    });

                    // CARD KANBAN - Usa il renderer unificato

                    if (window.KanbanRenderer && typeof window.KanbanRenderer.renderTask === 'function') {

                        // Usa il renderer unificato (ora restituisce {taskElement, subtasksHtml})

                        const renderResult = window.KanbanRenderer.renderTask(segnalazione, {

                            tabella: segnalazione.table_name,

                            kanbanType: 'segnalazioni'

                        });




                        const kanbanColumn = document.querySelector(`#kanban-${statoKey}`);

                        if (kanbanColumn) {

                            // Aggiungi la task principale

                            kanbanColumn.appendChild(renderResult.taskElement);



                            // Aggiungi le subtasks (se esistono)

                            if (renderResult.subtasksHtml && Array.isArray(renderResult.subtasksHtml)) {

                                renderResult.subtasksHtml.forEach(subtaskHtml => {

                                    const subtaskWrapper = document.createElement('div');

                                    subtaskWrapper.innerHTML = subtaskHtml;

                                    kanbanColumn.appendChild(subtaskWrapper.firstElementChild);

                                });

                            }

                        } else {

                            console.warn(` Colonna Kanban non trovata per stato "${statoString}"`);

                        }

                    } else {

                        // Fallback se il renderer non è caricato

                        console.error('⚠️ KanbanRenderer non disponibile! Controlla che kanban_renderer.js sia caricato.');

                        const fallbackTask = document.createElement('div');

                        fallbackTask.className = 'task';

                        fallbackTask.id = `task-${uid}`;

                        fallbackTask.setAttribute('data-task-id', segnalazione.id);

                        fallbackTask.setAttribute('data-id', uid);

                        fallbackTask.setAttribute('draggable', 'true');

                        fallbackTask.innerHTML = `<strong>⚠️ FALLBACK: ${segnalazione.titolo || 'Task'}</strong>`;



                        const kanbanColumn = document.querySelector(`#kanban-${statoKey}`);

                        if (kanbanColumn) {

                            kanbanColumn.appendChild(fallbackTask);

                        }

                    }

                });



            } else {

                console.warn(" Nessuna segnalazione trovata.");

                document.getElementById("segnalazioni-list").innerHTML = "<tr><td colspan='6'>Nessun modulo trovato</td></tr>";

            }

            initTableFilters('segnalazioniTable');

            // Setup listeners DOPO che tutte le task sono nel DOM
            setupKanbanEventListeners();

            // Setup event delegation per drag and drop
            setupDragAndDropDelegation();

            // Garantisce l'ordine corretto di tutte le task e subtask
            ensureCorrectTaskOrder();

            // Setup toggle per subtask nella tabella
            setupTableSubtasksToggle();

        })

        .catch(error => {

            console.error(" Errore durante la richiesta:", error);

        }).finally(() => {
            window.__isLoadingSegnalazioni = false;
            // Invalida la cache del calendario quando i dati cambiano
            window.__calendarCache.data = null;
            window.__calendarCache.timestamp = 0;
        });

}



function setupKanbanEventListeners() {

    document.querySelectorAll('.kanban-column').forEach(column => {

        const newColumn = column.cloneNode(true);

        column.replaceWith(newColumn);

    });

    // Variabile per gestire il delay tra click e dblclick
    let segnalazioniClickTimer = null;

    // Click singolo sulle task delle segnalazioni - apre la segnalazione completa
    const kanbanContainer = document.querySelector('.kanban-container') || document.querySelector('#kanban-view');
    if (kanbanContainer) {
        kanbanContainer.addEventListener('click', function (e) {
            const taskEl = e.target.closest('.task');
            if (!taskEl) return;

            // Evita di aprire se si clicca su elementi interattivi (bottoni, link, action-icon, ecc.)
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('.action-icon') || e.target.closest('.kanban-subtasks-toggle')) {
                return;
            }

            // Verifica se è una task di segnalazione (ha data-form-name)
            const formName = taskEl.getAttribute('data-form-name');
            if (!formName) return;

            const dataId = taskEl.getAttribute('data-id');
            let recordId = null;

            if (dataId && dataId.includes('_')) {
                const parts = dataId.split('_');
                recordId = parts[parts.length - 1];
            } else {
                recordId = taskEl.getAttribute('data-task-id');
            }

            // Gestione Timer per distinguere Click da Double Click
            if (segnalazioniClickTimer) {
                clearTimeout(segnalazioniClickTimer);
            }

            segnalazioniClickTimer = setTimeout(() => {
                if (formName && recordId) {
                    if (typeof window.apriViewer === 'function') {
                        window.apriViewer(formName, recordId);
                    }
                }
                segnalazioniClickTimer = null;
            }, 250); // Ritardo di 250ms per attendere eventuale secondo click
        });

        // Doppio click sulle task - apre l'editor della segnalazione
        kanbanContainer.addEventListener('dblclick', function (e) {
            const taskEl = e.target.closest('.task');
            if (!taskEl) return;

            // Annulla il redirect del click singolo
            if (segnalazioniClickTimer) {
                clearTimeout(segnalazioniClickTimer);
                segnalazioniClickTimer = null;
            }

            // Evita se si clicca su elementi interattivi
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('.action-icon') || e.target.closest('.kanban-subtasks-toggle')) {
                return;
            }

            const taskId = taskEl.getAttribute('data-task-id');
            const formName = taskEl.getAttribute('data-form-name');

            if (taskId && formName) {
                if (window.TaskDetails && typeof window.TaskDetails.open === 'function') {
                    window.TaskDetails.open(taskId, { type: 'segnalazione', formName: formName });
                } else {
                    apriEditor(formName, taskId);
                }
            }
        });
    }

    document.querySelectorAll('.kanban-column').forEach(column => {

        column.addEventListener('dragover', (event) => {

            event.preventDefault();

            column.classList.add("drag-over");

        });



        column.addEventListener('dragleave', () => {

            column.classList.remove("drag-over");

        });



        column.addEventListener('drop', function (event) {

            event.preventDefault();

            column.classList.remove("drag-over");

            if (window.__isLoadingSegnalazioni) return;



            const taskId = event.dataTransfer.getData("text/plain") || window.__draggedTaskId || '';

            const newStatusId = this.getAttribute('data-status-id');

            // Usa un metodo più sicuro per trovare l'elemento
            let taskElement = document.querySelector(`[data-id="${taskId}"]`);

            // Se non trova l'elemento, prova a cercare tutti gli elementi con data-id e confronta
            if (!taskElement) {
                const allTasks = document.querySelectorAll('[data-id]');
                for (let el of allTasks) {
                    if (el.getAttribute('data-id') === taskId) {
                        taskElement = el;
                        break;
                    }
                }
            }

            if (!taskElement) return;

            if (window.__isLoadingSegnalazioni) return;



            // Permessi

            const assegnatoA = String(taskElement.getAttribute("data-assegnato_a") || taskElement.dataset.assegnato_a || "");

            const responsabile = String(taskElement.getAttribute("data-responsabile") || taskElement.dataset.responsabile || "");

            const currentUserId = String(window.CURRENT_USER?.id || "");



            if (currentUserId !== assegnatoA && currentUserId !== responsabile) {

                if (isCurrentUserAdmin()) {

                    showConfirm(
                        "Stai per modificare lo stato come amministratore.<br>Non sei né responsabile né assegnatario.<br>Vuoi procedere?",
                        () => {
                            const prevColumn = taskElement.parentNode;
                            const targetContainer = column.querySelector('.task-container');

                            taskElement.dataset.status = newStatusId;

                            // Sposta la task principale
                            targetContainer.appendChild(taskElement);

                            // Trova e sposta tutte le subtask associate DOPO la task principale
                            const parentTaskId = taskElement.getAttribute('data-id');
                            const subtasks = document.querySelectorAll(`.kanban-subtask[data-parent-id="${CSS.escape(parentTaskId)}"]`);

                            // Ordina le subtask per mantenere l'ordine originale
                            const sortedSubtasks = Array.from(subtasks).sort((a, b) => {
                                const aLabel = a.getAttribute('data-scheda-label') || '';
                                const bLabel = b.getAttribute('data-scheda-label') || '';
                                return aLabel.localeCompare(bLabel);
                            });

                            // Inserisci le subtask in ordine DOPO la task principale
                            let insertAfter = taskElement;
                            sortedSubtasks.forEach(subtask => {
                                targetContainer.insertBefore(subtask, insertAfter.nextSibling);
                                insertAfter = subtask; // Aggiorna il punto di inserimento
                            });

                            const idNumerico = taskId.split("_").pop();

                            const tableName = taskId.split("_").slice(0, -1).join("_");

                            aggiornaStato(idNumerico, newStatusId, "kanban", tableName)

                                .then(() => {
                                    // Invalida la cache del calendario dopo il drag and drop
                                    window.__calendarCache.data = null;
                                    window.__calendarCache.timestamp = 0;
                                    // Garantisce l'ordine corretto dopo il drag and drop
                                    ensureCorrectTaskOrder();
                                })

                                .catch(() => {

                                    showToast('Errore nel salvataggio, spostamento annullato.', 'error');

                                    // Ripristina la task principale
                                    prevColumn.appendChild(taskElement);
                                    // Ripristina le subtask
                                    subtasks.forEach(subtask => {
                                        prevColumn.appendChild(subtask);
                                    });

                                });

                        },
                        { allowHtml: true }
                    );

                } else {

                    showToast("Solo l'assegnatario, il responsabile o l'amministratore possono spostare questa segnalazione.", "error");

                }

                return;

            }



            const prevColumn = taskElement.parentNode;
            const targetContainer = this.querySelector('.task-container');

            taskElement.dataset.status = newStatusId;

            // Sposta la task principale
            targetContainer.appendChild(taskElement);

            // Trova e sposta tutte le subtask associate DOPO la task principale
            const parentTaskId = taskElement.getAttribute('data-id');
            const subtasks = document.querySelectorAll(`.kanban-subtask[data-parent-id="${CSS.escape(parentTaskId)}"]`);

            // Ordina le subtask per mantenere l'ordine originale
            const sortedSubtasks = Array.from(subtasks).sort((a, b) => {
                const aLabel = a.getAttribute('data-scheda-label') || '';
                const bLabel = b.getAttribute('data-scheda-label') || '';
                return aLabel.localeCompare(bLabel);
            });

            // Inserisci le subtask in ordine DOPO la task principale
            let insertAfter = taskElement;
            sortedSubtasks.forEach(subtask => {
                targetContainer.insertBefore(subtask, insertAfter.nextSibling);
                insertAfter = subtask; // Aggiorna il punto di inserimento
            });

            const idNumerico = taskId.split("_").pop();

            const tableName = taskId.split("_").slice(0, -1).join("_");

            aggiornaStato(idNumerico, newStatusId, "kanban", tableName)

                .then(() => {
                    // Invalida la cache del calendario dopo il drag and drop
                    window.__calendarCache.data = null;
                    window.__calendarCache.timestamp = 0;
                    // Garantisce l'ordine corretto dopo il drag and drop
                    ensureCorrectTaskOrder();
                })

                .catch(() => {

                    showToast('Errore nel salvataggio, spostamento annullato.', 'error');

                    // Ripristina la task principale
                    prevColumn.appendChild(taskElement);
                    // Ripristina le subtask
                    subtasks.forEach(subtask => {
                        prevColumn.appendChild(subtask);
                    });

                });

        });



    });

    // Setup toggle subtasks DOPO aver configurato il drag&drop
    setupSubtasksToggle();

}

function setupTableSubtasksToggle() {
    document.querySelectorAll('td[data-has-subtasks="true"]').forEach(toggleCell => {
        const taskId = toggleCell.getAttribute('data-task-id');
        toggleCell.style.cursor = 'pointer';

        toggleCell.addEventListener('click', (e) => {
            e.stopPropagation();
            window.toggleSubtasks(taskId, 'table');
        });
    });

    // Ripristina stati
    window.restoreSubtaskStates('table');
}

function ensureCorrectTaskOrder() {
    // Garantisce che in ogni colonna kanban le subtask siano sempre sotto la task principale
    document.querySelectorAll('.kanban-column .task-container').forEach(container => {
        const tasks = Array.from(container.children);
        const mainTasks = tasks.filter(task => !task.classList.contains('kanban-subtask'));
        const subtasks = tasks.filter(task => task.classList.contains('kanban-subtask'));

        // Raggruppa le subtask per task principale
        const subtasksByParent = {};
        subtasks.forEach(subtask => {
            const parentId = subtask.getAttribute('data-parent-id');
            if (parentId) {
                if (!subtasksByParent[parentId]) {
                    subtasksByParent[parentId] = [];
                }
                subtasksByParent[parentId].push(subtask);
            }
        });

        // Per ogni task principale, inserisci le sue subtask subito dopo
        mainTasks.forEach(mainTask => {
            const taskId = mainTask.getAttribute('data-id');
            const taskSubtasks = subtasksByParent[taskId] || [];

            if (taskSubtasks.length > 0) {
                // Ordina le subtask per nome
                taskSubtasks.sort((a, b) => {
                    const aLabel = a.getAttribute('data-scheda-label') || '';
                    const bLabel = b.getAttribute('data-scheda-label') || '';
                    return aLabel.localeCompare(bLabel);
                });

                // Inserisci le subtask dopo la task principale
                let insertAfter = mainTask;
                taskSubtasks.forEach(subtask => {
                    container.insertBefore(subtask, insertAfter.nextSibling);
                    insertAfter = subtask;
                });
            }
        });
    });
}

function setupDragAndDropDelegation() {
    // Event delegation per drag and drop
    // Attacca event listeners ai contenitori delle colonne kanban

    document.querySelectorAll('.kanban-column').forEach(column => {
        // Drag start - gestito a livello di contenitore
        column.addEventListener('dragstart', (e) => {
            const taskEl = e.target.closest('.task');
            if (!taskEl) return;

            if (window.__isLoadingSegnalazioni) return;

            const taskId = taskEl.getAttribute('data-id');
            if (!taskId) return;

            // Usa variabile globale come fallback
            window.__draggedTaskId = taskId;
            e.dataTransfer.setData('text/plain', taskId);
            taskEl.classList.add('dragging');
        });

        // Drag end
        column.addEventListener('dragend', (e) => {
            const taskEl = e.target.closest('.task');
            if (!taskEl) return;

            if (window.__isLoadingSegnalazioni) return;
            taskEl.classList.remove('dragging');
        });
    });
}

function setupSubtasksToggle() {
    document.querySelectorAll('.kanban-subtasks-toggle').forEach(toggle => {
        const parentId = toggle.getAttribute('data-parent-id');

        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            window.toggleSubtasks(parentId, 'kanban');
        });
    });

    // Ripristina stati
    window.restoreSubtaskStates('kanban');
}



function aggiornaStato(taskId, nuovoStato, origine, tableName) {

    console.log(`Aggiorno stato: Task ${taskId} → ${statiMap[nuovoStato]} (Origine: ${origine})`);



    if (!tableName) {

        console.error(" Errore: tableName mancante in aggiornaStato()");

        return;

    }



    return customFetch('forms', 'updateFormStatus', {

        form_id: taskId,

        stato: nuovoStato,

        table_name: tableName

    })

        .then(data => {

            if (data.success) {

                showToast("Stato aggiornato con successo.", "success");



                aggiornaTuttiISelectEDomKanban(taskId, nuovoStato, tableName);



            } else {

                showToast("Errore nell'aggiornamento stato: " + (data.error || data.message), "error");

            }

        })

        .catch(error => {

            console.error(" Errore di connessione:", error);

        });

}



function aggiornaTuttiISelectEDomKanban(taskId, nuovoStato, tableName) {

    document.querySelectorAll(`.stato-select[data-form-id="${taskId}"][data-table="${tableName}"]`).forEach(select => {

        select.dataset.skipUpdate = "true";

        select.value = nuovoStato;

        select.setAttribute("data-prev-value", nuovoStato);



        const classiColori = ["aperta", "in_corso", "sospesa", "rifiutata", "chiusa"];

        classiColori.forEach(c => select.classList.remove(c));

        const nuovaClasse = statiMap[parseInt(nuovoStato)]?.toLowerCase().replace(/ /g, "_") || "aperta";

        select.classList.add(nuovaClasse);



        setTimeout(() => {

            select.dataset.skipUpdate = "false";

        }, 0);

    });



    const taskElement = document.querySelector(`#task-${tableName}_${taskId}`);

    const newColumn = document.querySelector(`#kanban-${statiMap[nuovoStato].replace(/\s+/g, "_").toLowerCase()}`);

    if (taskElement && newColumn) {

        newColumn.appendChild(taskElement);

        taskElement.setAttribute("data-status-id", nuovoStato);

    }

}



// ======== CALENDAR PROVIDER (riusa la stessa fonte dati della tabella/kanban) ========

registerCalendarProvider('gestione_segnalazioni', async function () {
    // Se la vista calendario non è attiva, non caricare i dati
    const calendarView = document.getElementById('calendar-view');
    if (!calendarView || calendarView.classList.contains('hidden')) {
        // Se abbiamo dati in cache, restituiscili, altrimenti array vuoto
        const cache = window.__calendarCache;
        return cache.data || [];
    }

    const now = Date.now();
    const cache = window.__calendarCache;

    // Controlla se abbiamo dati in cache ancora validi
    if (cache.data && (now - cache.timestamp) < cache.ttl) {
        return cache.data;
    }

    const params = (typeof window.__ACTIVE_FILTERS__ === 'object' && window.__ACTIVE_FILTERS__) ? { ...window.__ACTIVE_FILTERS__ } : {};

    const res = await customFetch('page_editor', 'getFilledForms', params);

    const rows = Array.isArray(res?.forms) ? res.forms : [];

    // Popola __USERS_MAP__ con i dati ricevuti
    populateUsersMap(rows);

    const calendarData = rows.map(s => {

        const statoLabel = (window.STATI_MAP && window.STATI_MAP[parseInt(s.stato, 10)]) || '';

        let color = s.color || '#5b8def';

        if (/chiusa|completato/i.test(statoLabel)) color = '#63b365';

        else if (/in corso|attesa|revisione/i.test(statoLabel)) color = '#f0b429';

        else if (/rifiutata|rifiutato/i.test(statoLabel)) color = '#e55353';

        // Task principale (usa toISO già definita sopra)
        const mainTask = {
            id: `${s.table_name}:${s.id}`,
            title: String(s.titolo || (s.form_name ? s.form_name.replace(/_/g, ' ') : '') || 'Segnalazione').trim(),
            start: toISO(s.data_scadenza || s.data_invio),
            end: null,
            status: s.stato,
            url: `index.php?section=collaborazione&page=form_viewer&form_name=${encodeURIComponent(s.form_name)}&id=${encodeURIComponent(s.id)}`,
            color,
            meta: {
                raw: s,
                taskType: 'main',
                hasSubtasks: s.subtasks && Array.isArray(s.subtasks) && s.subtasks.length > 0,
                subtasksCount: s.subtasks ? s.subtasks.length : 0,
                originalId: s.id // Aggiungi l'ID originale per le subtask
            },
            // Stili personalizzati per il calendario
            className: 'calendar-main-task',
            extendedProps: {
                taskType: 'main',
                formName: s.form_name,
                priority: s.priority || 'Media',
                assignedTo: s.assegnato_a_nome || s.assegnato_a || 'Non assegnato',
                responsible: s.responsabile_nome || s.responsabile || 'Non assegnato',
                subtasksCount: s.subtasks ? s.subtasks.length : 0
            }
        };

        const tasks = [mainTask];

        // Aggiungi subtask se esistono
        if (s.subtasks && Array.isArray(s.subtasks) && s.subtasks.length > 0) {
            s.subtasks.forEach((subtask, index) => {
                const subtaskTask = {
                    id: `${s.table_name}:${s.id}:subtask:${index}`,
                    title: `↳ ${subtask.scheda_label || 'Subtask'}`,
                    start: toISO(s.data_scadenza || s.data_invio), // Stessa data della task principale
                    end: null,
                    status: s.stato, // Eredita lo stato dalla task principale
                    url: `index.php?section=collaborazione&page=form_viewer&form_name=${encodeURIComponent(s.form_name)}&id=${encodeURIComponent(s.id)}`,
                    color: color + '80', // Versione più trasparente del colore principale
                    meta: {
                        raw: subtask,
                        taskType: 'subtask',
                        parentTaskId: `${s.table_name}:${s.id}`, // Usa l'ID completo della task principale
                        parentTaskTitle: s.titolo || (s.form_name ? s.form_name.replace(/_/g, ' ') : '')
                    },
                    className: 'calendar-subtask',
                    extendedProps: {
                        taskType: 'subtask',
                        parentTaskId: `${s.table_name}:${s.id}`, // Usa l'ID completo della task principale
                        subtaskLabel: subtask.scheda_label || 'Subtask',
                        subtaskData: subtask.scheda_data || {}
                    }
                };
                tasks.push(subtaskTask);
            });
        }

        return tasks;

    }).flat().filter(e => !!e.start);

    // Salva nella cache
    cache.data = calendarData;
    cache.timestamp = now;

    // Aggiungi tooltip personalizzati dopo il rendering
    setTimeout(() => {
        addCalendarTooltips();
        detectScrollableDays();
        addTaskCounters();
        forceCalendarDayHeight();
    }, 100);

    return calendarData;

});

// Funzione per aggiungere tooltip informativi alle task del calendario
function addCalendarTooltips() {
    // Rimuovi tooltip esistenti
    document.querySelectorAll('.calendar-tooltip').forEach(tooltip => tooltip.remove());

    // Aggiungi tooltip alle task principali
    document.querySelectorAll('.calendar-main-task').forEach(taskElement => {
        const taskData = taskElement._extendedProps || {};

        if (taskData.taskType === 'main') {
            // Aggiungi attributi per il contatore subtask
            if (taskData.subtasksCount > 0) {
                taskElement.setAttribute('data-has-subtasks', 'true');
                taskElement.setAttribute('data-subtasks-count', taskData.subtasksCount);
            }

            const tooltip = document.createElement('div');
            tooltip.className = 'calendar-tooltip';
            tooltip.innerHTML = `
                <div class="tooltip-content">
                    <div class="tooltip-header">
                        <strong>📋 ${taskData.formName || 'Task'}</strong>
                        ${taskData.subtasksCount > 0 ? `<span style="color: #ffd700; font-size: 10px;"> (${taskData.subtasksCount} subtask)</span>` : ''}
                    </div>
                    <div class="tooltip-body">
                        <div><strong>Priorità:</strong> ${taskData.priority || 'Media'}</div>
                        <div><strong>Assegnato a:</strong> ${taskData.assignedTo || 'Non assegnato'}</div>
                        <div><strong>Responsabile:</strong> ${taskData.responsible || 'Non assegnato'}</div>
                    </div>
                </div>
            `;

            taskElement.appendChild(tooltip);

            // Event listeners per mostrare/nascondere tooltip
            taskElement.addEventListener('mouseenter', () => {
                tooltip.style.display = 'block';
            });

            taskElement.addEventListener('mouseleave', () => {
                tooltip.style.display = 'none';
            });
        }
    });

    // Aggiungi tooltip alle subtask
    document.querySelectorAll('.calendar-subtask').forEach(subtaskElement => {
        const subtaskData = subtaskElement._extendedProps || {};

        if (subtaskData.taskType === 'subtask') {
            const tooltip = document.createElement('div');
            tooltip.className = 'calendar-tooltip subtask-tooltip';
            tooltip.innerHTML = `
                <div class="tooltip-content">
                    <div class="tooltip-header">
                        <strong>↳ ${subtaskData.subtaskLabel || 'Subtask'}</strong>
                    </div>
                    <div class="tooltip-body">
                        <div><strong>Task principale:</strong> ${subtaskData.parentTaskId || 'N/A'}</div>
                        <div><strong>Tipo:</strong> Scheda personalizzata</div>
                    </div>
                </div>
            `;

            subtaskElement.appendChild(tooltip);

            // Event listeners per mostrare/nascondere tooltip
            subtaskElement.addEventListener('mouseenter', () => {
                tooltip.style.display = 'block';
            });

            subtaskElement.addEventListener('mouseleave', () => {
                tooltip.style.display = 'none';
            });
        }
    });
}

// Calendario -> Gantt: allinea l'anchor

(() => {
    // Usa toISO già definita sopra (centralizzata)

    const minVisibleDay = () => {

        const q = [...document.querySelectorAll('#calendar-view .cv-day[data-date]')].map(el => el.dataset.date).filter(Boolean).sort();

        return q[0] || null;

    };

    const emit = () => {

        const s = CalendarView?._state || {};

        const view = s.view || 'month';

        const anchorISO = (view === 'month' ? (minVisibleDay() || toISO(s.anchor || new Date())) : toISO(s.anchor || new Date()));

        window.dispatchEvent(new CustomEvent('calendar:state', { detail: { anchorISO, view } }));

    };



    document.addEventListener('DOMContentLoaded', () => {

        const cv = document.getElementById('calendar-view');

        if (!cv) return;

        emit();

        if (CalendarView?.render) {

            const r = CalendarView.render;

            CalendarView.render = (...a) => { const out = r.apply(CalendarView, a); try { emit(); } catch { } return out; };

        }

        new MutationObserver(() => { try { emit(); } catch { } }).observe(cv, { childList: true, subtree: true });

        cv.addEventListener('click', () => setTimeout(emit, 0), true);

    });



    window.addEventListener('calendar:state', (e) => {

        const iso = e.detail?.anchorISO;

        if (!iso || !GanttView?._state) return;

        const [y, m, d] = iso.split('-').map(Number);

        const next = new Date(y, (m || 1) - 1, d || 1);

        if (isFinite(next)) GanttView._state.anchor = next;

        GanttView.refresh && GanttView.refresh();

    });

})();



// ======== BOOTSTRAP CALENDARIO ========

document.addEventListener('DOMContentLoaded', function () {

    const hasCalendar = !!document.getElementById('calendar-view');

    if (!hasCalendar) return;



    // Se il modulo calendario è già carico, assicurati che usi il nostro provider

    if (window.CalendarView && typeof CalendarView.setProvider === 'function') {

        CalendarView.setProvider(window.calendarDataProvider);

    }



    // Se atterro con ?calendar=true, la vista è già mostrata via PHP.

    // Faccio solo un refresh dei dati (dopo init automatico del modulo).

    const isCalendar = new URLSearchParams(location.search).get('calendar') === 'true';



    // piccolo delay: garantisce che calendar_view.js abbia completato l'auto-init

    setTimeout(() => {

        if (window.CalendarView && typeof CalendarView.refresh === 'function') {

            // Mantieni coerenza coi filtri iniziali già applicati

            if (typeof window.__ACTIVE_FILTERS__ === 'undefined') window.__ACTIVE_FILTERS__ = {};

            CalendarView.refresh();

        }

    }, 0);

});

// Funzione per rilevare giorni con scroll
function detectScrollableDays() {
    document.querySelectorAll('.fc-daygrid-day-events').forEach(dayEvents => {
        // Rimuovi classe esistente
        dayEvents.classList.remove('has-scroll');

        // Controlla se ha scroll
        if (dayEvents.scrollHeight > dayEvents.clientHeight) {
            dayEvents.classList.add('has-scroll');
        }

        // Aggiungi listener per scroll dinamico
        dayEvents.addEventListener('scroll', () => {
            if (dayEvents.scrollTop > 0) {
                dayEvents.classList.add('has-scroll');
            } else {
                dayEvents.classList.remove('has-scroll');
            }
        });
    });
}

// Funzione per aggiungere contatori task ai giorni
function addTaskCounters() {
    document.querySelectorAll('.fc-daygrid-day').forEach(dayElement => {
        const dayNumber = dayElement.querySelector('.fc-daygrid-day-number');
        const dayEvents = dayElement.querySelector('.fc-daygrid-day-events');

        if (dayNumber && dayEvents) {
            // Conta le task nel giorno
            const taskCount = dayEvents.querySelectorAll('.calendar-main-task, .calendar-subtask').length;

            if (taskCount > 0) {
                dayNumber.setAttribute('data-task-count', taskCount);
                dayNumber.classList.add('has-tasks');
            } else {
                dayNumber.removeAttribute('data-task-count');
                dayNumber.classList.remove('has-tasks');
            }
        }
    });
}

// Funzione per forzare l'altezza dei giorni del calendario
function forceCalendarDayHeight() {
    // Trova tutti i giorni del calendario
    const dayElements = document.querySelectorAll('.fc-daygrid-day, .fc-daygrid-day-frame');

    dayElements.forEach((dayElement) => {
        // Forza l'altezza con JavaScript
        dayElement.style.minHeight = '180px';
        dayElement.style.height = '180px';
        dayElement.style.maxHeight = '180px';
        dayElement.style.aspectRatio = '1';
        dayElement.style.display = 'flex';
        dayElement.style.flexDirection = 'column';
    });

    // Forza anche l'altezza delle aree eventi
    const eventAreas = document.querySelectorAll('.fc-daygrid-day-events');
    eventAreas.forEach((eventArea) => {
        eventArea.style.height = '150px';
        eventArea.style.minHeight = '150px';
        eventArea.style.maxHeight = '150px';
        eventArea.style.flex = '1';
        eventArea.style.overflowY = 'auto';
        eventArea.style.overflowX = 'hidden';
    });

    // Forza anche l'altezza degli header dei giorni
    const dayTops = document.querySelectorAll('.fc-daygrid-day-top');
    dayTops.forEach((dayTop) => {
        dayTop.style.height = '30px';
        dayTop.style.minHeight = '30px';
        dayTop.style.maxHeight = '30px';
        dayTop.style.flexShrink = '0';
    });

    // Aggiungi observer per modifiche future del DOM (solo una volta)
    if (!window.__calendarHeightObserverAttached) {
        window.__calendarHeightObserverAttached = true;

        const observer = new MutationObserver((mutations) => {
            let shouldForceHeight = false;
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType === 1 && (
                            node.classList?.contains('fc-daygrid-day') ||
                            node.querySelector?.('.fc-daygrid-day')
                        )) {
                            shouldForceHeight = true;
                        }
                    });
                }
            });

            if (shouldForceHeight) {
                setTimeout(() => forceCalendarDayHeight(), 50);
            }
        });

        // Osserva il calendario per cambiamenti
        const calendarElement = document.querySelector('.fc-daygrid-body') || document.querySelector('.fc-view-harness');
        if (calendarElement) {
            observer.observe(calendarElement, {
                childList: true,
                subtree: true
            });
        }
    }
}

// ======== CONFIGURAZIONE GANTT PER GESTIONE SEGNALAZIONI ========

// getUserNameById ora è in main_core.js (già globale)

// Popola __USERS_MAP__ dai dati
function populateUsersMap(rows) {
    if (!Array.isArray(rows)) return;
    rows.forEach(s => {
        if (s.submitted_by && s.creato_da) window.__USERS_MAP__[String(s.submitted_by)] = String(s.creato_da);
        if (s.assegnato_a && s.assegnato_a_nome) window.__USERS_MAP__[String(s.assegnato_a)] = String(s.assegnato_a_nome);
        if (s.responsabile && s.responsabile_nome) window.__USERS_MAP__[String(s.responsabile)] = String(s.responsabile_nome);
    });
}

// Configurazione colonne Gantt per gestione_segnalazioni
window.GanttColumnsConfig = [
    {
        key: 'id',
        title: 'ID',
        width: 120,
        type: 'text'
    },
    {
        key: 'titolo',
        title: 'Titolo',
        width: 220,
        type: 'text',
        isTitle: true
    },
    {
        key: 'descrizione',
        title: 'Descrizione',
        width: 320,
        type: 'text'
    },
    {
        key: 'assegnatario',
        title: 'Assegnatario',
        width: 160,
        type: 'text'
    },
    {
        key: 'responsabile',
        title: 'Compilato da',
        width: 160,
        type: 'text',
        // Trasforma submitted_by (user_id) in nome utente
        transform: (value, raw, ev) => {
            // Se è già un nome (creato_da), usalo
            if (raw.creato_da && String(raw.creato_da).trim()) {
                return String(raw.creato_da);
            }

            // Altrimenti converti submitted_by
            if (raw.submitted_by) {
                return getUserNameById(raw.submitted_by);
            }

            return value || '—';
        }
    }
];

// Bootstrap Gantt (stesso provider del calendario)

document.addEventListener('DOMContentLoaded', () => {

    const hasGantt = !!document.getElementById('gantt-view');

    if (!hasGantt || !window.GanttView?.init) return;

    // Inizializza Gantt (UserManager si popola automaticamente dal calendar provider)
    setTimeout(() => {
        GanttView.init({ containerId: 'gantt-view', provider: window.calendarDataProvider });
        setTimeout(() => { GanttView.refresh && GanttView.refresh(); }, 0);
    }, 100);

});
