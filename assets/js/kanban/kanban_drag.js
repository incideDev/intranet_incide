// Importa la funzione AJAX per aggiornare il backend
import { sendAjaxRequest } from '../modules/ajax.js';

// Inizializza il Drag & Drop
export function initializeKanbanDragAndDrop() {
    console.log("🚀 Inizializzazione Drag & Drop Kanban");

    document.querySelectorAll('.kanban-column').forEach(column => {
        column.addEventListener('dragover', handleDragOver);
        column.addEventListener('drop', handleDrop);
    });

    document.querySelectorAll('.task').forEach(task => {
        if (!task.id) {
            console.warn("⚠️ Task senza ID rilevata! Assicurati che abbia un id='task-123'.", task);
            return;
        }
        task.setAttribute('draggable', 'true');
        task.addEventListener('dragstart', handleDragStart);
        task.addEventListener('dragend', handleDragEnd);
    });
}

// Inizio del Drag (salva l'ID dell'elemento trascinato)
export function handleDragStart(event) {
    let task = event.target.closest('.task'); // Trova il div corretto con classe "task"

    if (!task) {
        console.error("❌ ERRORE: Nessun elemento .task trovato per il drag.");
        return;
    }

    let taskId = task.getAttribute('id');
    
    if (!taskId || taskId.trim() === "") {
        console.error("❌ ERRORE: Nessun ID valido trovato sulla task. HTML:", task.outerHTML);
        return;
    }

    console.log(`🚀 Drag iniziato - Task ID: "${taskId}"`);
    event.dataTransfer.setData("text/plain", taskId);
}

// Fine del Drag (rimuove la classe "dragging")
export function handleDragEnd(event) {
    event.target.classList.remove('dragging');
}

// Permette il Drop su un'area valida
export function handleDragOver(event) {
    event.preventDefault();
    event.currentTarget.classList.add('drag-over');
}

// Gestisce il rilascio di una task in una nuova colonna
function handleDrop(event) {
    event.preventDefault();

    let taskId = event.dataTransfer.getData("text/plain");
    let targetColumn = event.currentTarget.querySelector('.task-container');
    let newStatusId = event.currentTarget.getAttribute('data-status-id');

    console.log(`📌 Drop Event - Task ID: "${taskId}", Nuovo Stato: ${newStatusId}`);

    if (!taskId || taskId.trim() === "") {
        console.error("❌ ERRORE: Task ID non valido nel drop.");
        taskId = event.target.closest('.task')?.getAttribute('id') || "NON TROVATO";
        console.log(`🔄 Tentativo alternativo: Task ID = ${taskId}`);
        if (taskId === "NON TROVATO") return;
    }

    let taskElement = document.getElementById(taskId);
    if (!taskElement) {
        console.error(`❌ ERRORE: La task con ID "${taskId}" non esiste nel DOM.`);
        return;
    }

    targetColumn.appendChild(taskElement);
    taskElement.setAttribute('data-status-id', newStatusId);

    let cleanedTaskId = taskId.replace(/\D/g, ''); // ✅ Rimuove qualsiasi carattere non numerico

    console.log(`📡 Invio richiesta API → Task ID: ${cleanedTaskId}, Nuovo Stato: ${newStatusId}`);

    fetch('/api/kanban/update_Task_Status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: cleanedTaskId, status: newStatusId })
    })
    .then(response => response.text()) // ✅ Cambiato da `.json()` a `.text()` per debug migliore
    .then(text => {
        console.log("📡 Risposta API ricevuta:", text); // ✅ Log completo della risposta

        try {
            let data = JSON.parse(text); // ✅ Tentativo di convertire in JSON
            if (data.success) {
                console.log(`✅ Task ${cleanedTaskId} spostata nello stato ${newStatusId}`);
            } else {
                console.error("❌ Errore aggiornamento stato:", data.message);
            }
        } catch (error) {
            console.error("❌ ERRORE API: Risposta non in formato JSON:", text);
        }
    })
    .catch(error => console.error("❌ Errore di connessione:", error));
}


// Aggiorna lo stato della task nel backend
function updateTaskStatusKanban(taskId, newStatusId) {
    if (!taskId || !newStatusId) {
        console.error("⚠️ Dati mancanti per aggiornare lo stato della task.");
        return;
    }

    fetch('/api/kanban/update_task_status.php', {  // ✅ Corretto percorso API
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: taskId.replace('task-', ''), status: newStatusId })  // ✅ Corretto JSON
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`✅ Stato aggiornato per Task ID: ${taskId} → Stato: ${newStatusId}`);
        } else {
            console.error("❌ Errore aggiornamento stato:", data.message);
        }
    })
    .catch(error => console.error("❌ Errore di connessione:", error));
}

// Assicuriamoci che il fix venga applicato quando la pagina è pronta
document.addEventListener('DOMContentLoaded', initializeKanbanDragAndDrop);
