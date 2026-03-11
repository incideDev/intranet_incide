// Importa moduli comuni
import { initializeTabs } from '../modules/tabs.js';
import { toggleModal } from '../modules/modals.js';
import { 
    populateResponsabiliDropdown, 
    updateResponsabiliIcons 
} from '../modules/responsabili.js';

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById("modalDetail")?.addEventListener("transitionend", () => {
        console.log("📌 Modale aperto, ora carico i dettagli della Task.");

        setTimeout(() => {
            const taskDetailsElement = document.getElementById('task-details');

            if (!taskDetailsElement) return; // ✅ Evitiamo l'errore se il Modale è ancora vuoto

            const taskId = taskDetailsElement.getAttribute('data-task-id');
            if (!taskId) {
                console.warn("⚠️ ID della task non trovato.");
                return;
            }

            fetchTaskDetails(taskId);
        }, 100);
    });
});

//  Funzione per recuperare i dettagli della task
function fetchTaskDetails(taskId) {
    fetch(`/api/tasks/getTaskDetails.php?task_id=${taskId}`)
        .then(response => response.json())
        .then(task => {
            if (task.error) {
                console.error("❌ Errore API:", task.error);
                return;
            }

            document.getElementById('task-title').textContent = task.titolo || 'Titolo non disponibile';
            document.getElementById('task-details').value = task.descrizione || '';
            document.getElementById('task-start-date').value = task.data_inizio || '';
            document.getElementById('task-end-date').value = task.data_fine || '';

            document.getElementById('task-priority').value = task.priorita || 'media';

            populateStatusDropdown(task);
        })
        .catch(error => console.error("❌ Errore caricamento task:", error));
}

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

//  Funzione per caricare le date della task
function loadTaskDates(task) {
    const startDateField = document.getElementById('task-start-date');
    const endDateField = document.getElementById('task-end-date');

    if (startDateField) {
        startDateField.value = task.data_inizio || '';
        startDateField.setAttribute('data-task-id', task.id);
    }

    if (endDateField) {
        endDateField.value = task.data_fine || '';
        endDateField.setAttribute('data-task-id', task.id);
    }
}

//  Funzione per aggiornare un campo della task
function updateTaskField(field, value) {
    const taskId = document.getElementById(`task-${field}`)?.getAttribute('data-task-id');
    if (!taskId) {
        console.error(" ID della task non trovato.");
        return;
    }

    fetch('/api/tasks/updateTaskField.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ task_id: taskId, field, value })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error(" Errore nell'aggiornamento del campo:", data.error);
        }
    })
    .catch(error => console.error(" Errore aggiornamento campo:", error));
}

//  Event listeners per aggiornamenti inline
document.getElementById('task-start-date')?.addEventListener('change', event => {
    updateTaskField('data_inizio', event.target.value);
});

document.getElementById('task-end-date')?.addEventListener('change', event => {
    updateTaskField('data_fine', event.target.value);
});

//  Funzione per aggiornare la priority della task
function updateTaskPriority(event) {
    const taskId = event.target.getAttribute('data-task-id');
    const priority = event.target.value;

    if (!taskId) {
        console.error(" ID della task non trovato.");
        return;
    }

    fetch('/api/tasks/updateTaskPriority.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: taskId, priority })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error(" Errore aggiornamento priority:", data.message);
        }
    })
    .catch(error => console.error(" Errore aggiornamento priority:", error));
}

// 🔹 Associa l'evento di cambio priority
document.getElementById('task-priority')?.addEventListener('change', updateTaskPriority);

console.log(" `task_details.js` caricato correttamente!");
