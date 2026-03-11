import { initializeKanbanDragAndDrop, handleDragStart, handleDragOver } from "../kanban/kanban_drag.js";
import * as Responsabili from "../modules/responsabili.js";

export function loadGareKanban() {
    console.log("📌 Caricamento Kanban delle Gare...");

    fetch("/api/gare/get_gare_kanban.php")
        .then(response => response.text())
        .then(text => {
            try {
                return JSON.parse(text);
            } catch (error) {
                console.error("❌ Errore API: Risposta non in formato JSON:", text);
                throw error;
            }
        })
        .then(data => {
            if (data.kanban) {
                populateGareKanban(data.kanban);
            } else {
                console.error("❌ Errore Kanban Gare:", data.error);
            }
        })
        .catch(error => console.error("❌ Errore API:", error));
}

function populateGareKanban(kanbanData) {
    const kanbanContainer = document.querySelector(".kanban-container");
    if (!kanbanContainer) {
        console.error("❌ Kanban container non trovato.");
        return;
    }

    kanbanContainer.innerHTML = ""; // Pulisce il Kanban prima di inserire i nuovi dati

    Object.entries(kanbanData).forEach(([statusId, status]) => {
        let columnColor = status.color || "#f5f5f5"; // Usa il colore salvato nel DB
        let headerColor = columnColor;

        const column = document.createElement("div");
        column.classList.add("kanban-column");
        column.dataset.statusId = statusId;

        column.style.setProperty("--column-color", hexToRGBA(columnColor, 0.1));
        column.style.setProperty("--header-color", headerColor);

        column.innerHTML = `
            <div class="kanban-header">
                <span class="kanban-title">${status.status_name}</span>

                <input type="color" class="color-picker" value="${columnColor}" 
                   data-status-id="${statusId}" 
                   onchange="updateColumnColor(${statusId}, this.value)">
                            
                ${statusId == 1 ? `
                    <button class="add-task-btn" id="kanbanAddGara">
                        <img src="assets/icons/plus.png" alt="Aggiungi Gara" class="task-icon">
                    </button>
                ` : ''}
                
            </div>
            <div class="task-container"></div>
        `;

        const taskContainer = column.querySelector(".task-container");
        taskContainer.addEventListener("drop", (event) => handleDropGare(event, statusId));
        taskContainer.addEventListener("dragover", handleDragOver);

        if (!status.tasks || status.tasks.length === 0) {
            taskContainer.innerHTML = "<div class='kanban-empty'>Nessuna gara</div>";
        } else {
            status.tasks.forEach(task => {
                const taskElement = document.createElement("div");
                taskElement.classList.add("task");
                taskElement.id = `task-${task.id}`;
                taskElement.setAttribute("draggable", "true");
                taskElement.setAttribute("data-task-id", task.id);
                taskElement.setAttribute("data-status-id", statusId);
                taskElement.addEventListener("dragstart", handleDragStart);
            
                // ✅ Aggiungiamo il doppio click per aprire il dettaglio
                taskElement.addEventListener("dblclick", function () {
                    let id = taskElement.getAttribute("data-task-id");
                    console.log(`🔄 Navigazione alla scheda della Gara ID: ${id}`);
                    window.location.href = `index.php?section=gestione&page=gare_valutazione&id_gara=${id}`;
                });                

                taskElement.innerHTML = `
                    <div class="task-header">
                        <div class="gara-number"><strong>${task.n_gara ? task.n_gara : "N/D"}</strong></div>
                        <div class="title-field">${task.title}</div>
                    </div>
                    <div class="task-body">
                        <div class="date-field">
                            <img src="assets/icons/calendar.png" class="task-icon" alt="Scadenza">
                            ${task.scadenza.split(" ")[0]}
                        </div>
                        <div class="sector-field">
                            <img src="assets/icons/sector.png" class="task-icon" alt="Settore">
                            ${task.settore}
                        </div>
                        <div class="import-field">
                            <img src="assets/icons/money.png" class="task-icon" alt="Parcella Base">
                            ${task.parcella_base ? formatImportoLavori(task.parcella_base) : "N/A"}
                        </div>
                        <div class="ente-field">
                            <img src="assets/icons/building.png" class="task-icon" alt="Stazione Appaltante">
                            ${task.ente}
                        </div>
                        <div class="responsabili-icons" onclick="Responsabili.toggleResponsabileMenu(this)" data-task-id="${task.id}">
                            ${Responsabili.generateResponsabileIcons(task.responsabili_info)}
                            <span class="add-icon">+</span>
                        </div>

                    </div>
                `;

                taskContainer.appendChild(taskElement);
            });            
        }

        kanbanContainer.appendChild(column);
    });

    console.log("✅ Kanban popolato con successo!");
    initializeKanbanDragAndDrop();
}

window.updateColumnColor = function (statusId, newColor) {
    console.log(`🎨 Cambio colore per colonna ${statusId}: ${newColor}`);

    fetch("/api/gare/update_gara_color.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status_id: statusId, color: newColor })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`✅ Colore aggiornato con successo per Status ID ${statusId}`);

            const column = document.querySelector(`.kanban-column[data-status-id="${statusId}"]`);
            if (column) {
                column.style.setProperty("--header-color", newColor); // ✅ Aggiorna header
                column.style.setProperty("--column-color", hexToRGBA(newColor, 0.1)); // ✅ Sfondo più chiaro
            }
        } else {
            console.error("❌ Errore aggiornamento colore:", data.message);
        }
    })
    .catch(error => console.error("❌ Errore connessione API:", error));
};

// ✅ Funzione per formattare l'importo in Euro con 2 decimali
function formatImportoLavori(value) {
    if (!value || isNaN(value)) return "€0,00";
    return new Intl.NumberFormat("it-IT", {
        style: "currency",
        currency: "EUR",
        minimumFractionDigits: 2
    }).format(parseFloat(value));
}

function handleDropGare(event, newStatusId) {
    event.preventDefault();

    let taskId = event.dataTransfer.getData("text/plain");
    console.log(`📌 Drop Event - Task ID: "${taskId}", Nuovo Stato: ${newStatusId}`);

    if (!taskId || taskId.trim() === "") {
        console.error("❌ ERRORE: Task ID non valido nel drop.");
        return;
    }

    let taskElement = document.getElementById(taskId);
    if (!taskElement) {
        console.error(`❌ ERRORE: La task con ID "${taskId}" non esiste nel DOM.`);
        return;
    }

    let targetColumn = event.currentTarget.querySelector('.task-container');
    if (!targetColumn) {
        console.error("❌ ERRORE: Nessun contenitore trovato per questa colonna.");
        return;
    }

    let oldStatusId = taskElement.getAttribute("data-status-id");
    console.log(`🔄 Stato attuale: ${oldStatusId}, Nuovo Stato: ${newStatusId}`);

    if (oldStatusId === String(newStatusId)) {
        console.warn("⚠️ La task è già in questa colonna. Nessun aggiornamento necessario.");
        return;
    }

    targetColumn.appendChild(taskElement);
    taskElement.setAttribute('data-status-id', newStatusId);

    console.log("📡 Chiamata API in corso...");

    fetch("/api/gare/update_gara_status.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: taskId.replace('task-', ''), status_id: newStatusId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`✅ Stato aggiornato per la gara ${taskId} → ${newStatusId}`);
        } else {
            console.error("❌ Errore aggiornamento stato:", data.message);
        }
    })
    .catch(error => console.error("❌ Errore di connessione:", error));
}

// **Esegui il caricamento all'avvio una sola volta**
document.addEventListener("DOMContentLoaded", () => loadGareKanban());

function createNewGara() {
    console.log("📌 Creazione nuova Gara inline...");

    const bozzaColumn = document.querySelector('.kanban-column[data-status-id="1"] .task-container');

    if (!bozzaColumn) {
        console.error("❌ ERRORE: Colonna 'Bozza' non trovata.");
        return;
    }

    // ✅ Creiamo una nuova card vuota inline
    const newGara = document.createElement("div");
    newGara.classList.add("task", "new-gara");
    newGara.setAttribute("data-task-id", "new"); // Temporaneo finché non viene salvata nel DB

    newGara.innerHTML = `
        <!-- ✅ Numero Gara -->
        <div class="editable-field n-gara-field" contenteditable="true" data-placeholder="Numero Gara"></div>

        <!-- ✅ Titolo -->
        <div class="editable-field title-field" contenteditable="true" data-placeholder="Titolo della Gara"></div>

        <!-- ✅ Data Scadenza -->
        <div class="editable-field date-field">
            <input type="date">
        </div>

        <!-- ✅ Settore -->
        <div class="editable-field sector-field" contenteditable="true" data-placeholder="Settore"></div>

        <!-- ✅ Parcella Base -->
        <div class="editable-field import-field">
            <input type="text" data-field="parcella_base" placeholder="Parcella Base">
        </div>

        <!-- ✅ Stazione Appaltante -->
        <div class="editable-field ente-field" contenteditable="true" data-placeholder="Stazione Appaltante"></div>

        <!-- ✅ Responsabile Gara -->
        <div class="editable-field responsabile-field">
            <select class="responsabile-select"></select>
        </div>

        <!-- ✅ Bottone di salvataggio -->
        <button class="save-gara-btn" onclick="saveNewGara(this)">Salva Gara</button>
    `;

    bozzaColumn.appendChild(newGara);
    console.log("✅ Task inline creata con successo.");

    // ✅ Integrazione del selettore del responsabile gara
    const responsabileSelect = newGara.querySelector(".responsabile-select");
    if (responsabileSelect) {
        initializeResponsabileSelect(responsabileSelect);
        console.log("✅ Selettore responsabile inizializzato.");
    } else {
        console.error("❌ ERRORE: Impossibile trovare il selettore responsabile.");
    }

    // ✅ Placeholder per i campi editabili
    newGara.querySelectorAll(".editable-field").forEach(field => {
        if (!field.querySelector("input")) { // Ignora gli input
            const placeholderText = field.getAttribute("data-placeholder");

            field.textContent = placeholderText;
            field.classList.add("placeholder");

            field.addEventListener("focus", function () {
                if (field.classList.contains("placeholder")) {
                    field.textContent = "";
                    field.classList.remove("placeholder");
                }
            });

            field.addEventListener("blur", function () {
                if (field.textContent.trim() === "") {
                    field.textContent = placeholderText;
                    field.classList.add("placeholder");
                }
            });
        }
    });

    // ✅ Formattazione Importo Parcella
    const importoInput = newGara.querySelector('input[data-field="parcella_base"]');
    if (importoInput) {
        importoInput.addEventListener("input", function () {
            let value = this.value.replace(/\D/g, ""); // ✅ Rimuove tutto tranne i numeri
            let formattedValue = new Intl.NumberFormat("it-IT").format(value); // ✅ Aggiunge punti per le migliaia
            this.value = formattedValue;
        });
    }
}
window.createNewGara = createNewGara;

function saveNewGara(button) {
    const taskElement = button.closest('.task');

    // ✅ Recupera i valori PRIMA della chiamata API
    const garaNumberElement = taskElement.querySelector('.n-gara-field');
    const titleElement = taskElement.querySelector('.title-field');
    const sectorElement = taskElement.querySelector('.sector-field');
    const enteElement = taskElement.querySelector('.ente-field');
    const dueDateElement = taskElement.querySelector('input[type="date"]');
    const parcellaBaseElement = taskElement.querySelector('input[data-field="parcella_base"]');
    const responsabileSelect = taskElement.querySelector('.responsabile-select');

    if (!garaNumberElement || !titleElement || !sectorElement || !enteElement || !dueDateElement || !parcellaBaseElement || !responsabileSelect) {
        alert("⚠️ Compila tutti i campi obbligatori.");
        return;
    }

    const n_gara = garaNumberElement.textContent.trim();
    const title = titleElement.textContent.trim();
    const sector = sectorElement.textContent.trim();
    const ente = enteElement.textContent.trim();
    const dueDate = dueDateElement.value;
    let parcellaBase = parcellaBaseElement.value.trim();
    const responsabile = responsabileSelect.value; // ID del responsabile selezionato

    // ✅ Controllo campi obbligatori
    if (!n_gara || !title || !sector || !ente || !dueDate || !responsabile) {
        alert("⚠️ Tutti i campi sono obbligatori.");
        return;
    }

    // ✅ Normalizzazione Parcella Base (evita problemi con formattazione numerica)
    parcellaBase = parcellaBase.replace(/\./g, "").replace(",", ".");
    parcellaBase = parseFloat(parcellaBase);
    if (isNaN(parcellaBase)) {
        alert("⚠️ Il campo 'Parcella Base' deve contenere un numero valido.");
        return;
    }

    // ✅ Costruiamo l'oggetto dati da inviare al server
    const formData = {
        n_gara,
        title,
        sector,
        ente,
        dueDate,
        parcella_base: parcellaBase,
        status_id: 1,
        responsabile
    };

    console.log("📡 Invio dati al server:", formData);

    // ✅ Salva la gara nel database
    fetch('/api/gare/save_new_gara.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Errore HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            console.log("✅ Gara creata con successo:", data);
            alert("✅ Gara salvata correttamente!");

            // ✅ Aggiorna l'ID della task e chiude la modifica
            taskElement.setAttribute("data-task-id", data.id);
        } else {
            throw new Error(data.message || "Errore sconosciuto durante il salvataggio.");
        }
    })
    .catch(error => {
        console.error("❌ Errore nel salvataggio della gara:", error);
        alert("❌ Errore nel salvataggio della gara: " + error.message);
    });
}

// ✅ Rendi disponibile globalmente la funzione
window.saveNewGara = saveNewGara;

function updateColumnColor(statusId, color) {
    console.log(`🎨 Cambio colore per la colonna ${statusId}: ${color}`);

    const column = document.querySelector(`.kanban-column[data-status-id="${statusId}"]`);
    if (column) {
        column.style.setProperty("--header-color", color);
        column.style.setProperty("--column-color", hexToRGBA(color, 0.1));
    }

    // ✅ Salva il nuovo colore nel database
    fetch("/api/gare/update_gara_status_color.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status_id: statusId, color: color })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`✅ Colore aggiornato nel database per lo stato ${statusId}`);
        } else {
            console.error("❌ Errore aggiornamento colore:", data.message);
        }
    })
    .catch(error => console.error("❌ Errore connessione API:", error));
}

// ✅ Converti HEX in RGBA per il background
function hexToRGBA(hex, alpha = 1.0) {
    hex = hex.replace("#", "");
    let r = parseInt(hex.substring(0, 2), 16);
    let g = parseInt(hex.substring(2, 4), 16);
    let b = parseInt(hex.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

window.loadGareKanban = loadGareKanban;

document.addEventListener("DOMContentLoaded", function () {
    document.body.classList.add("page-gare");
});

function initializeKanbanEvents() {
    console.log("📌 Verifico e assegno eventi al bottone 'kanbanAddGara'...");

    // Aspettiamo che il Kanban sia stato generato nel DOM
    setTimeout(() => {
        const kanbanAddGaraBtn = document.getElementById("kanbanAddGara");

        if (kanbanAddGaraBtn) {
            kanbanAddGaraBtn.addEventListener("click", function () {
                console.log("📌 Apertura modale dalla colonna Bozza...");
                openGaraModal();
            });

            console.log("✅ Evento assegnato al bottone 'kanbanAddGara'.");
        } else {
            console.warn("⚠️ Nessun bottone 'kanbanAddGara' trovato nel DOM. Riprovo...");
            setTimeout(initializeKanbanEvents, 500); // 🔥 Riprova dopo 500ms se il bottone non esiste ancora
        }
    }, 500); // 🔥 Aspettiamo mezzo secondo per essere sicuri che il DOM sia aggiornato
}

// ✅ Eseguiamo la funzione dopo che il Kanban è stato popolato
document.addEventListener("DOMContentLoaded", function () {
    loadGareKanban();
    setTimeout(initializeKanbanEvents, 1000); // 🔥 Avviamo l'assegnazione dopo il caricamento del Kanban
});
