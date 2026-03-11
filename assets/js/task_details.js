// Variabile globale per memorizzare il taskId corrente
let currentTaskId = null;

function openTaskDetailModal(taskId) {
    console.log("Apertura modal per task ID:", taskId);
    currentTaskId = taskId; // Salva il taskId nella variabile globale
    const csrfToken = document.querySelector('meta[name="token-csrf"]')?.content || '';
    // Carica il contenuto HTML di task_details.php solo se non è già presente
    if (!document.getElementById('task-title')) {
        fetch('views/includes/task_management/task_details.php', {
                headers: {
                  "X-Csrf-Token": csrfToken, // 👈👈👈 Set the token
                  "Content-Type": "application/json"
                },
                method: 'POST',
                credentials: "same-origin"
              })
            .then(response => response.text())
            .then(html => {
                document.getElementById('task-detail-content').innerHTML = html;
                fetchTaskData(taskId); // Popola i dati della task
            })
            .catch(error => console.error('Errore nel caricamento della vista del modal:', error));
    } else {
        fetchTaskData(taskId);
    }
}

// Funzione per recuperare i dati della task e popolare il modal
function fetchTaskData(taskId) {
    const csrfToken = document.querySelector('meta[name="token-csrf"]')?.content || '';
    fetch(`index.php?page=task_management&action=task_details&id=${taskId}`, {
          headers: {
            "X-Csrf-Token": csrfToken, // 👈👈👈 Set the token
            "Content-Type": "application/json"
          },
          method: 'POST',
          credentials: "same-origin"
        })
        .then(response => response.json())
        .then(taskData => {
            if (taskData.error) {
                console.error("Errore: ", taskData.error);
                return;
            }
            // Popola il modal con i dati della task
            document.getElementById('task-title').innerText = taskData.titolo || 'Titolo non disponibile';
            document.getElementById('task-description').innerText = taskData.descrizione || 'Descrizione non disponibile';
            document.getElementById('responsabile-name').innerText = taskData.responsabile_nominativo || 'N/A';
            document.getElementById('task-due-date').value = taskData.data_scadenza || '';
            document.getElementById('task-priority').value = taskData.priorita || 'media';
            document.getElementById('task-status').value = taskData.status || '';

            // Mostra il modal
            const modal = document.getElementById('taskDetailModal');
            modal.classList.add('large-modal');
            modal.style.display = 'block';
        })
        .catch(error => console.error('Errore nel recupero dei dettagli del task:', error));
}

// Funzione per chiudere il modal dei dettagli della task
function closeTaskDetailModal() {
    const modal = document.getElementById('taskDetailModal');
    modal.classList.remove('large-modal');
    modal.style.display = 'none';
}

// Gestione degli eventi per la chiusura del modal
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('closeTaskDetailModal')?.addEventListener('click', closeTaskDetailModal);
    window.addEventListener('click', (event) => {
        const modal = document.getElementById('taskDetailModal');
        if (modal && event.target === modal) {
            closeTaskDetailModal();
        }
    });
});

function closeTaskDetailModal() {
    const modal = document.getElementById('taskDetailModal');
    modal.classList.remove('large-modal');
    modal.style.display = 'none';
}

// Gestione degli eventi per la chiusura del modal
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('closeTaskDetailModal')?.addEventListener('click', closeTaskDetailModal);

    window.addEventListener('click', (event) => {
        const modal = document.getElementById('taskDetailModal');
        if (modal && event.target === modal) {
            closeTaskDetailModal();
        }
    });
});

function updateTaskDueDate() {
    const csrfToken = document.querySelector('meta[name="token-csrf"]')?.content || '';
    const dueDate = document.getElementById('task-due-date').value;
    fetch(`index.php?page=task_management&action=update_task_due_date`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', "X-Csrf-Token": csrfToken },
        body: `id=${encodeURIComponent(currentTaskId)}&due_date=${encodeURIComponent(dueDate)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log("Data di scadenza aggiornata");
            // Aggiorna la data visibile nella task del kanban
            const taskElement = document.getElementById(`task-${currentTaskId}`);
            if (taskElement) {
                const dueDateElement = taskElement.querySelector('.task-field[data-field="data_scadenza"] span'); // Modifica secondo la tua struttura
                if (dueDateElement) {
                    dueDateElement.innerText = dueDate; // Aggiorna il testo della data
                }
            }
        } else {
            console.error("Errore aggiornamento data di scadenza:", data.message);
        }
    })
    .catch(error => console.error('Errore aggiornamento data di scadenza:', error));
}



function updateTaskPriority() {
    const csrfToken = document.querySelector('meta[name="token-csrf"]')?.content || '';
    const priority = document.getElementById('task-priority').value;
    fetch(`index.php?page=task_management&action=update_task_priority`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', "X-Csrf-Token": csrfToken },
        body: `id=${encodeURIComponent(currentTaskId)}&priority=${encodeURIComponent(priority)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log("priority aggiornata");
        } else {
            console.error("Errore aggiornamento priority:", data.message);
        }
    })
    .catch(error => console.error('Errore aggiornamento priority:', error));
}

function updateTaskStatus() {
    const csrfToken = document.querySelector('meta[name="token-csrf"]')?.content || '';
    const status = document.getElementById('task-status').value;
    fetch(`index.php?page=task_management&action=update_task_status`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', "X-Csrf-Token": csrfToken },
        body: `id=${encodeURIComponent(currentTaskId)}&status=${encodeURIComponent(status)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log("Stato aggiornato");
        } else {
            console.error("Errore aggiornamento stato:", data.message);
        }
    })
    .catch(error => console.error('Errore aggiornamento stato:', error));
}
