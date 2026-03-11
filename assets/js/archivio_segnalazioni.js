function apriViewer(formName, id) {
    const url = new URL('index.php', window.location.origin);
    url.searchParams.set('section', 'collaborazione');
    url.searchParams.set('page', 'form_viewer');
    url.searchParams.set('form_name', formName);
    url.searchParams.set('id', id);
    window.location.href = url.toString();
}

document.addEventListener("DOMContentLoaded", function () {
    const urlParams = new URLSearchParams(window.location.search);
    const apriKanban = urlParams.get("kanban") === "true";

    const listView = document.getElementById("elenco-view");
    const kanbanView = document.getElementById("kanban-view");

    if (apriKanban) {
        listView?.classList.add("hidden");
        kanbanView?.classList.remove("hidden");
    } else {
        listView?.classList.remove("hidden");
        kanbanView?.classList.add("hidden");
    }

    // Carica SOLO segnalazioni chiuse
    loadSegnalazioni({ archivio: true });

    setupKanbanEventListeners();

    // Attiva i bottoni come nelle altre pagine (se usi function-bar.js!)
    if (typeof updateButtons === 'function') updateButtons();
});

var statiMap = window.STATI_MAP || {
    1: "Aperta",
    2: "In corso",
    3: "Sospesa",
    4: "Rifiutata",
    5: "Chiusa"
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
    console.log("📡 Caricamento segnalazioni archiviate...");
    if (window.__isLoadingSegnalazioni) return;
    window.__isLoadingSegnalazioni = true;

    customFetch('page_editor', 'getFilledForms', filtri)
        .then(data => {
            const tableBody = document.getElementById("segnalazioni-list");
            tableBody.innerHTML = "";
            document.querySelectorAll(".task-container").forEach(container => container.innerHTML = "");

            if (data.success && Array.isArray(data.forms) && data.forms.length > 0) {
                data.forms.forEach(segnalazione => {
                    const statoString = statiMap[parseInt(segnalazione.stato)] || "Chiusa";
                    const statoKey = statoString.replace(/\s+/g, '_').toLowerCase();
                    const uid = `${segnalazione.table_name}_${segnalazione.id}`;

                    // RIGA TABELLA (SOLO VISUALIZZA)
                    const row = document.createElement("tr");

                    const tdAzioni = document.createElement("td");
                    tdAzioni.classList.add("azioni-colonna");
                    const viewIcon = document.createElement("img");
                    viewIcon.src = "assets/icons/show.png";
                    viewIcon.alt = "Visualizza";
                    viewIcon.classList.add("action-icon");
                    viewIcon.title = "Visualizza dettagli";
                    viewIcon.addEventListener("click", () => {
                        apriViewer(segnalazione.form_name, segnalazione.id);
                    });
                    tdAzioni.appendChild(viewIcon);

                    // --- Bottone Ripristina
                    const restoreIcon = document.createElement("img");
                    restoreIcon.src = "assets/icons/restore.png"; // Usa una tua icona restore se vuoi!
                    restoreIcon.alt = "Ripristina";
                    restoreIcon.classList.add("action-icon");
                    restoreIcon.title = "Ripristina segnalazione";
                    restoreIcon.style.cursor = "pointer";
                    restoreIcon.addEventListener("click", () => {
                        showConfirm("Vuoi davvero ripristinare questa segnalazione?", function () {
                            customFetch('forms', 'ripristinaSegnalazione', {
                                form_name: segnalazione.form_name,
                                record_id: segnalazione.id
                            }).then(resp => {
                                if (resp.success) {
                                    showToast("Segnalazione ripristinata.", "success");
                                    loadSegnalazioni({ archivio: true });
                                } else {
                                    showToast("Errore: " + (resp.message || "Ripristino fallito"), "error");
                                }
                            }).catch(err => {
                                showToast("Errore di rete: " + err, "error");
                            });
                        });
                    });
                    tdAzioni.appendChild(restoreIcon);

                    row.appendChild(tdAzioni);

                    const tdProtocollo = document.createElement("td");
                    tdProtocollo.classList.add("protocollo-colonna");
                    const codiceCompleto = segnalazione.codice_segnalazione || '-';
                    tdProtocollo.innerHTML = `
                        <div style="display: flex; align-items: center; min-height: 24px;">
                            <div style="width: 5px; height: 24px; background-color: ${segnalazione.color || '#ccc'}; margin-right: 6px; border-radius: 2px;"></div>
                            <span>${codiceCompleto}</span>
                        </div>
                    `;

                    const tdNome = document.createElement("td");
                    // Normalizza il nome del form sostituendo underscore con spazi
                    const formNameDisplay = (segnalazione.form_name || '').replace(/_/g, ' ');
                    tdNome.textContent = formNameDisplay || '—';
                    tdNome.classList.add("segnalazione-colonna");

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
                    tdAssegnatoA.textContent = segnalazione.assegnato_a_nome || segnalazione.assegnato_a || '—';
                    tdAssegnatoA.classList.add("utente-colonna");

                    const tdResponsabile = document.createElement("td");
                    tdResponsabile.textContent = segnalazione.responsabile_nome || segnalazione.responsabile || 'Non Assegnato';
                    tdResponsabile.classList.add("utente-colonna");

                    const tdDataInvio = document.createElement("td");
                    tdDataInvio.textContent = segnalazione.data_invio || '-';
                    tdDataInvio.classList.add("data-colonna");

                    const tdDataScadenza = document.createElement("td");
                    tdDataScadenza.textContent = segnalazione.data_scadenza || '-';
                    tdDataScadenza.classList.add("data-colonna");

                    const tdStato = document.createElement("td");
                    tdStato.innerHTML = `<span>${statoString}</span>`;
                    tdStato.classList.add("stato-colonna");

                    const tdPriorita = document.createElement("td");
                    tdPriorita.textContent = segnalazione["priority"] || "Media";
                    tdPriorita.classList.add("priorita-colonna");

                    row.appendChild(tdProtocollo);
                    row.appendChild(tdNome);
                    row.appendChild(tdArgomentazione);
                    row.appendChild(tdCreatoDa);
                    row.appendChild(tdAssegnatoA);
                    row.appendChild(tdResponsabile);
                    row.appendChild(tdDataInvio);
                    row.appendChild(tdDataScadenza);
                    row.appendChild(tdStato);
                    row.appendChild(tdPriorita);
                    tableBody.appendChild(row);

                    // KANBAN CARD (SOLO VISUALIZZA, NO DRAG&DROP) - Usa renderer unificato
                    if (window.KanbanRenderer && typeof window.KanbanRenderer.renderTask === 'function') {
                        // Usa il renderer unificato (ora restituisce {taskElement, subtasksHtml})
                        const renderResult = window.KanbanRenderer.renderTask(segnalazione, {
                            tabella: segnalazione.table_name,
                            kanbanType: 'segnalazioni'
                        });

                        // Disabilita drag per archivio
                        renderResult.taskElement.setAttribute("draggable", "false");

                        const kanbanColumn = document.querySelector(`#kanban-${statoKey}`);
                        if (kanbanColumn) {
                            // Aggiungi la task principale
                            kanbanColumn.appendChild(renderResult.taskElement);

                            // Aggiungi le subtasks (se esistono)
                            if (renderResult.subtasksHtml && Array.isArray(renderResult.subtasksHtml)) {
                                renderResult.subtasksHtml.forEach(subtaskHtml => {
                                    const subtaskWrapper = document.createElement('div');
                                    subtaskWrapper.innerHTML = subtaskHtml;
                                    const subtaskEl = subtaskWrapper.firstElementChild;
                                    subtaskEl.setAttribute("draggable", "false");
                                    kanbanColumn.appendChild(subtaskEl);
                                });
                            }
                        }
                    } else {
                        // Fallback se il renderer non è caricato
                        console.error('⚠️ KanbanRenderer non disponibile! Controlla che kanban_renderer.js sia caricato.');
                        const fallbackTask = document.createElement('div');
                        fallbackTask.className = 'task';
                        fallbackTask.id = `task-${uid}`;
                        fallbackTask.setAttribute('data-task-id', segnalazione.id);
                        fallbackTask.setAttribute('data-id', uid);
                        fallbackTask.setAttribute('draggable', 'false');
                        fallbackTask.innerHTML = `<strong>⚠️ FALLBACK: ${segnalazione.titolo || 'Task'}</strong>`;

                        const kanbanColumn = document.querySelector(`#kanban-${statoKey}`);
                        if (kanbanColumn) {
                            kanbanColumn.appendChild(fallbackTask);
                        }
                    }
                });
            } else {
                tableBody.innerHTML = "<tr><td colspan='11'>Nessuna segnalazione archiviata trovata</td></tr>";
            }
            if (typeof initTableFilters === "function") initTableFilters('segnalazioniTable');
        })
        .catch(error => {
            console.error("Errore durante la richiesta archivio:", error);
        })
        .finally(() => {
            window.__isLoadingSegnalazioni = false;
        });
}

// Archivio: Gestione click per apertura viewer
function setupKanbanEventListeners() {
    const kanbanContainer = document.getElementById('kanban-view');
    if (kanbanContainer) {
        kanbanContainer.addEventListener('click', function (e) {
            const taskEl = e.target.closest('.task');
            if (!taskEl) return;

            // Evita se si clicca su elementi interattivi
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('.action-icon')) {
                return;
            }

            const dataId = taskEl.getAttribute('data-id');
            const formName = taskEl.getAttribute('data-form-name');
            let recordId = null;

            if (dataId && dataId.includes('_')) {
                const parts = dataId.split('_');
                recordId = parts[parts.length - 1];
            } else {
                recordId = taskEl.getAttribute('data-task-id');
            }

            if (formName && recordId) {
                if (typeof window.apriViewer === 'function') {
                    window.apriViewer(formName, recordId);
                }
            }
        });
    }
}
