import { toggleModal } from '../modules/modals.js';
import { initializeTabs } from '../modules/tabs.js';
import { 
    updateTaskResponsabile, 
    toggleResponsabileMenu, 
    toggleResponsabile, 
    updateResponsabiliIcons, 
    populateResponsabiliDropdown 
} from '../modules/responsabili.js';
import { openDetailModal } from '../modules/modal_details.js';

// Esporta nel contesto globale
window.toggleResponsabile = toggleResponsabile;
window.toggleResponsabileMenu = toggleResponsabileMenu;
window.updateTaskStatus = updateTaskStatus;

// Inizializza i tab all'avvio
document.addEventListener('DOMContentLoaded', function () {
    initializeTabs();

    // Event listener per la selezione del responsabile
    document.querySelectorAll('.responsabile-select').forEach(select => {
        select.addEventListener('change', function () {
            updateTaskResponsabile(this);
        });
    });

    // Attiva l'editing inline sulle celle editabili
    document.querySelectorAll('.editable').forEach(cell => {
        cell.addEventListener('click', event => event.stopPropagation());
        cell.addEventListener('focus', () => (isEditing = true));
        cell.addEventListener('blur', function () {
            isEditing = false;
            saveTaskField(this);
        });
    });

    document.querySelectorAll('.task-item').forEach(task => {
        task.addEventListener('dblclick', function () {
            const taskId = this.getAttribute('data-task-id');

            if (!taskId) {
                console.error("❌ Nessun ID task trovato per il doppio click.");
                return;
            }

            console.log(`📌 Doppio click rilevato per task ID: ${taskId}`);
            openTaskDetailModal(taskId);
        });
    });
});

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll(".task").forEach(task => {
        task.addEventListener("dblclick", function () {
            let id = this.getAttribute("id").replace("task-", "");
            openDetailModal(id, "task");
        });
    });
});

//  Funzione per salvare i campi editabili delle task
function saveTaskField(element) {
    const taskElement = element.closest('.task');  // Trova l'elemento della task più vicino
    let taskId = taskElement ? taskElement.getAttribute('data-task-id') : null; // Cambiato a `let`
    const field = element.getAttribute('data-field');
    let value = element.textContent.trim();

    if (field === 'data_scadenza' && element.tagName === 'INPUT') {
        value = element.value; 
    }

    if (!taskId || taskId === "null") {
    if (taskElement && taskElement.id) {
        taskId = taskElement.id.replace('task-', '');
    }

    // ✅ Mostriamo l'avviso **solo se taskId rimane nullo**
    if (!taskId || taskId === "null") {
        console.warn("⚠️ `taskId` nullo, impossibile aggiornare il campo.");
        return; // Blocca la funzione se l'ID è ancora nullo
    }
}

    // **SE ANCORA NULL, BLOCCA L'ESECUZIONE**
    if (!taskId || taskId === "null") {
        console.error("❌ ERRORE: `taskId` non trovato! Nessun aggiornamento possibile.");
        return;
    }

    console.log(`🔍 Invio aggiornamento: taskId=${taskId}, field=${field}, value=${value}`);

    fetch('/api/tasks/updateTaskField.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: taskId, field: field, value: value }),
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`✅ Campo "${field}" aggiornato con successo per la task ${taskId}`);
        } else {
            console.error(`❌ Errore nel salvataggio del campo "${field}" per la task ${taskId}: ${data.error}`);
        }
    })
    .catch(error => console.error("❌ Errore durante il salvataggio:", error));
}

window.saveTaskField = saveTaskField;

//  Funzione per popolare i dettagli della task nel modal
function populateTaskDetails(taskData) {
    document.getElementById('task-title').innerText = taskData.titolo || 'Titolo non disponibile';
    document.getElementById('task-details').value = taskData.descrizione || '';

    document.getElementById('task-start-date').value = taskData.data_inizio || '';
    document.getElementById('task-end-date').value = taskData.data_fine || '';

    document.getElementById('task-priority').value = taskData.priorita || 'media';

    populateResponsabiliDropdown('task-responsabili', taskData);
    updateResponsabiliIcons(taskData.id, taskData.responsabili_info || []);
}

//  Funzione per aggiornare lo stato della task
function updateTaskStatus() {
    const statusDropdown = document.getElementById('task-status');
    const newStatusId = statusDropdown.value;
    const taskId = statusDropdown.getAttribute('data-task-id');

    if (!taskId || !newStatusId) {
        console.error(' Dati mancanti per aggiornare lo stato.');
        return;
    }

    fetch('/api/tasks/update_task_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: taskId, status: newStatusId }),
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(` Stato aggiornato con successo per la task ${taskId}`);
            moveTaskToColumn(taskId, newStatusId);
        } else {
            console.error(' Errore aggiornamento stato:', data.message);
        }
    })
    .catch(error => console.error(' Errore connessione:', error));
}

//  Funzione per popolare il dropdown degli stati
function populateStatusDropdown(taskData) {
    const statusDropdown = document.getElementById('task-status');
    statusDropdown.innerHTML = '';

    taskData.status_options.forEach(status => {
        const option = document.createElement('option');
        option.value = status.status_id;
        option.textContent = status.status_name;
        option.style.backgroundColor = status.status_color;
        if (status.status_id === taskData.status_id) option.selected = true;
        statusDropdown.appendChild(option);
    });
}

export function showInputForNewTask(statusId = null) {
    console.log("📌 Mostra input per nuova task, statusId:", statusId);

    const currentView = document.querySelector('.view-tab.active')?.getAttribute('data-view');
    console.log("📌 Vista attuale:", currentView);

    let newTaskElement;

    if (currentView === 'kanban') {
        newTaskElement = document.querySelector(`#new-task-${statusId}`);
        if (newTaskElement) {
            newTaskElement.style.display = 'block';
            console.log("✅ Nuova task mostrata in Kanban.");
        } else {
            console.warn("⚠️ Elemento per nuova task in Kanban non trovato.");
        }
    } else {
        console.warn("⚠️ Modalità attuale non supportata per inserire nuove task.");
    }
}

// Esporta anche come variabile globale nel browser
window.showInputForNewTask = showInputForNewTask;

function addNewKanbanColumn() {
    console.log("📌 Creazione di una nuova colonna inline...");

    const kanbanContainer = document.querySelector(".kanban-container");
    if (!kanbanContainer) {
        console.error("❌ Errore: Kanban container non trovato.");
        return;
    }

    // **Crea il container della nuova colonna**
    const newColumn = document.createElement("div");
    newColumn.classList.add("kanban-column", "new-column");
    newColumn.dataset.statusId = "new"; // Identificatore temporaneo

    newColumn.innerHTML = `
        <div class="kanban-header">
            <input type="text" class="new-column-input" placeholder="Nome Stato..." onblur="saveNewKanbanColumn(this)" onkeydown="handleColumnKeyPress(event, this)">
        </div>
        <div class="task-container"></div>
    `;

    // **Aggiungi la nuova colonna alla fine**
    kanbanContainer.appendChild(newColumn);

    // **Seleziona automaticamente l'input per iniziare la scrittura**
    newColumn.querySelector(".new-column-input").focus();
}

// **Salva il nuovo stato**
// **Gestisce il salvataggio del nuovo stato**
function saveNewKanbanColumn(input) {
    const columnName = input.value.trim();
    if (columnName === "") {
        console.warn("⚠️ Nome stato vuoto, rimuovo la colonna temporanea.");
        input.closest(".kanban-column").remove();
        return;
    }

    const color = getRandomColor();
    const kanbanColumn = input.closest(".kanban-column");
    const bachecaId = document.querySelector(".kanban-container").getAttribute("data-bacheca-id");

    if (!bachecaId) {
        console.error("❌ ERRORE: `bacheca_id` non trovato!");
        return;
    }

    console.log(`📡 Salvataggio nuovo stato: "${columnName}" con colore ${color}, bacheca_id=${bachecaId}`);

    fetch("/api/tasks/create_state.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name: columnName, color, bacheca_id: bachecaId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`✅ Stato "${columnName}" creato con ID ${data.status_id}`);
            kanbanColumn.dataset.statusId = data.status_id;
            input.replaceWith(document.createTextNode(columnName)); // **Rendi il nome fisso**

            // **Collega lo stato alla bacheca**
            linkStateToBoard(data.status_id, bachecaId);
        } else {
            console.error("❌ Errore nel salvataggio dello stato:", data.message);
            kanbanColumn.remove();
        }
    })
    .catch(error => console.error("❌ Errore di connessione:", error));
}


// **Permette di confermare premendo INVIO**
function handleColumnKeyPress(event, input) {
    if (event.key === "Enter") {
        input.blur();
    }
}

// **Genera un colore casuale**
function getRandomColor() {
    const colors = ["#ffcc00", "#007bff", "#17a2b8", "#28a745", "#dc3545", "#6f42c1"];
    return colors[Math.floor(Math.random() * colors.length)];
}

// **Chiamata per collegare stato e bacheca**
function linkStateToBoard(statusId, bachecaId) {
    console.log(`📡 Collegamento dello stato ${statusId} alla bacheca ${bachecaId}...`);

    fetch("/api/tasks/link_state_to_board.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ status_id: statusId, bacheca_id: bachecaId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`✅ Stato ${statusId} associato con successo alla bacheca ${bachecaId}`);
        } else {
            console.error("❌ Errore associazione stato-bacheca:", data.message);
        }
    })
    .catch(error => console.error("❌ Errore di connessione:", error));
}

// **Esporta le funzioni globalmente**
window.addNewKanbanColumn = addNewKanbanColumn;
window.saveNewKanbanColumn = saveNewKanbanColumn;
window.handleColumnKeyPress = handleColumnKeyPress;
