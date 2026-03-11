document.addEventListener('DOMContentLoaded', function () {
    function toggleModal(modalId, action) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = action === 'open' ? 'block' : 'none';
        }
    }

    function getBoardIdFromUrl() {
        const query = window.location.search.substring(1);
        const vars = query.split("&");
        for (let i = 0; i < vars.length; i++) {
            const pair = vars[i].split("=");
            if (pair[0] === "board" && !isNaN(pair[1])) {
                return parseInt(pair[1], 10);
            }
        }
        return null;
    }

    const boardId = getBoardIdFromUrl();

    document.getElementById('openModalBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        toggleModal('taskModal', 'open');
    });

    document.getElementById('closeModal')?.addEventListener('click', () => {
        toggleModal('taskModal', 'close');
    });

    document.getElementById('addNewBoardBtn')?.addEventListener('click', () => {
        toggleModal('addBoardModal', 'open');
    });

    document.getElementById('closeBoardModal')?.addEventListener('click', () => {
        toggleModal('addBoardModal', 'close');
    });

    window.addEventListener('click', (event) => {
        ['taskModal', 'addBoardModal'].forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal && event.target === modal) {
                modal.style.display = 'none';
            }
        });
    });

    // Selezione degli stati standard
    const statusSelect = document.getElementById('statusSelect');
    const statusList = document.getElementById('statusList');
    const selectedStatuses = new Set();

    window.addStateToList = function () {
        const selectedOption = statusSelect.options[statusSelect.selectedIndex];
        const statusId = selectedOption.value;
        const statusName = selectedOption.text;

        if (statusId && !selectedStatuses.has(statusId)) {
            selectedStatuses.add(statusId);

            const listItem = document.createElement('li');
            listItem.textContent = statusName;
            listItem.setAttribute('data-id', statusId);

            const removeButton = document.createElement('button');
            removeButton.textContent = 'Rimuovi';
            removeButton.onclick = function () {
                selectedStatuses.delete(statusId);
                listItem.remove();
            };

            listItem.appendChild(removeButton);
            statusList.appendChild(listItem);
        }
    };

    if (statusSelect) {
        statusSelect.addEventListener('change', window.addStateToList);
    }

    // Definisce gli stati personalizzati
    const customStatuses = {
        notStarted: [],
        active: [],
        done: [],
        closed: []
    };

    // Funzione per aggiungere stati personalizzati
    window.addState = function (category, event) {
        event.preventDefault();
        const stateName = prompt("Inserisci il nome dello stato:");
        if (stateName) {
            const categoryList = document.getElementById(`${category}States`);

            const listItem = document.createElement('li');
            listItem.textContent = stateName;

            const removeButton = document.createElement('button');
            removeButton.textContent = 'Rimuovi';
            removeButton.onclick = function () {
                customStatuses[category] = customStatuses[category].filter(s => s !== stateName);
                listItem.remove();
            };

            listItem.appendChild(removeButton);
            categoryList.appendChild(listItem);

            customStatuses[category].push(stateName);
        }
    };

    // Funzione per il salvataggio dello stato personalizzato nel database
    function saveCustomStatus(stateName, category) {
        fetch('/api/tasks/addCustomStatus.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ stateName: stateName, category: category, bachecaId: null })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                customStatuses[category].push(data.statusId);
            } else {
                alert(`Errore nel salvataggio dello stato: ${data.message}`);
            }
        })
        .catch(error => console.error('Errore durante il salvataggio dello stato:', error));
    }

    // Submit del form di creazione della bacheca
    const newBoardForm = document.getElementById('newBoardForm');
    newBoardForm?.addEventListener('submit', function (event) {
        event.preventDefault();

        const formData = new FormData(newBoardForm);
        formData.append('selectedStatuses', JSON.stringify(Array.from(selectedStatuses)));
        formData.append('customStatuses', JSON.stringify(customStatuses));

        fetch('/api/tasks/addBoard.php', {
            method: 'POST',
            body: formData
        })

        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Bacheca creata con successo!");
                location.reload();
            } else {
                alert(`Errore nella creazione della bacheca: ${data.message}`);
            }
        })
        .catch(error => {
            console.error('Errore durante la creazione della bacheca:', error);
        });
    });

    function initKanbanDragAndDrop() {
    const tasks = document.querySelectorAll('.kanban-column .task');
    const columns = document.querySelectorAll('.kanban-column');

    tasks.forEach(task => {
        task.addEventListener('dragstart', handleDragStart);
        task.addEventListener('dragend', handleDragEnd);
    });

    columns.forEach(column => {
        const taskContainer = column.querySelector('.task-container');
        if (taskContainer) {
            column.addEventListener('dragover', handleDragOver);
            column.addEventListener('drop', handleDrop);
        }
    });
}

function handleDragStart(event) {
    const targetElement = event.target;
    event.dataTransfer.setData('text/plain', targetElement.id);

    // Controlla che l'elemento sia valido prima di passarlo a setDragImage
    if (targetElement instanceof Element) {
        event.dataTransfer.setDragImage(targetElement, 0, 0);
        console.log("Immagine di trascinamento impostata con successo.");
    } else {
        console.error("Target non è un elemento HTML valido:", targetElement);
    }

    // Aggiunge una classe per lo stile dragging
    targetElement.classList.add('dragging');
}

function handleDragEnd(event) {
    // Rimuove lo stile dragging
    document.body.classList.remove('dragging');
    event.target.classList.remove('dragging');
}

function handleDragOver(event) {
    event.preventDefault();
    event.currentTarget.classList.add('drag-over');
}

function handleDrop(event) {
    event.preventDefault();
    event.currentTarget.classList.remove('drag-over');
    const taskId = event.dataTransfer.getData('text/plain');
    const task = document.getElementById(taskId);
    const targetContainer = event.currentTarget.querySelector('.task-container');
    const newStatusId = event.currentTarget.getAttribute('data-status-id');

    if (targetContainer && newStatusId) {
        // Sposta la task nella nuova colonna
        targetContainer.appendChild(task);
        
        // Aggiorna visivamente lo stato
        task.setAttribute('data-status-id', newStatusId);

        // Salva i dati aggiornati nel backend
        updateTaskStatusKanban(taskId.split('-')[1], newStatusId);
    } else {
        console.error("Dati mancanti per il drop.");
    }
}

    function updateTaskStatusKanban(taskId, newStatusId) {
        fetch('controllers/update_task_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `id=${encodeURIComponent(taskId)}&status=${encodeURIComponent(newStatusId)}`
        })
        .then(response => {
            if (response.ok) {
                console.log('Task aggiornata con successo');
            }
        })
        .catch(error => {
            console.error('Errore di connessione:', error);
        });
    }

    initKanbanDragAndDrop();
});

    function createTask(event, statusId) {
    if (event.key === 'Enter') {
        const bachecaId = document.querySelector('input[name="bacheca_id"]').value;

        let taskData = { status_id: statusId, bacheca_id: bachecaId };
        const taskContainer = event.target.closest('.task');

        if (taskContainer && taskContainer.classList.contains('new-task')) {
            const fields = taskContainer.querySelectorAll('.editable');
            fields.forEach(field => {
                taskData[field.getAttribute('data-field')] = field.innerText.trim();
            });
        } else {
            const inputField = event.target;
            taskData.titolo = inputField.value.trim();
        }

        if (taskData.titolo && taskData.bacheca_id) {
            fetch('/api/tasks/store_task.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(taskData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const newTaskDiv = document.createElement('div');
                    newTaskDiv.className = 'task';
                    newTaskDiv.id = `task-${data.taskId}`;
                    newTaskDiv.setAttribute('draggable', 'true');
                    newTaskDiv.style.borderLeftColor = document.querySelector(`.kanban-column[data-status-id="${statusId}"] .kanban-header`).style.borderColor;

                    newTaskDiv.innerHTML = `<h3>${taskData.titolo}</h3><p>${taskData.descrizione || ''}</p><p>${taskData.data_scadenza || ''}</p>`;

                    const column = document.querySelector(`.kanban-column[data-status-id="${statusId}"] .task-container`);
                    if (column) column.appendChild(newTaskDiv);

                    taskContainer.style.display = 'none';
                    initKanbanDragAndDrop();
                } else {
                    alert('Errore nella creazione della task: ' + data.message);
                }
            })
            .catch(error => console.error('Errore durante la creazione della task:', error));
        } else {
            alert("Titolo o bacheca_id mancanti");
        }
    }
}

function openColorPicker(statusId) {
    const colorInput = document.createElement('input');
    colorInput.type = 'color';
    colorInput.style.position = 'absolute';
    colorInput.style.visibility = 'hidden';

    colorInput.oninput = function () {
        const newColor = colorInput.value;
        document.querySelector(`.kanban-column[data-status-id="${statusId}"] .color-bar`).style.backgroundColor = newColor;

        fetch('/api/tasks/update_color.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status_id: statusId, color: newColor })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Colore aggiornato con successo!');
            } else {
                console.error('Errore nell\'aggiornamento del colore:', data.message);
            }
        })
        .catch(error => console.error('Errore di connessione:', error));
    };

    document.body.appendChild(colorInput);
    colorInput.click();
    document.body.removeChild(colorInput);
}

function updateColumnColor(statusId, color) {
    const column = document.querySelector(`.kanban-column[data-status-id="${statusId}"]`);
    if (column) {
        // Aggiorna i colori dinamici
        const rgbaColor = hexToRGBA(color, 0.1); // 10% opacità per il background
        column.style.setProperty('--column-color', rgbaColor);
        column.style.setProperty('--header-color', color);
    }

    // Salva il colore nel database
    fetch('/api/tasks/update_color.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ status_id: statusId, color: color }),
    })
        .then((response) => response.json())
        .then((data) => {
            if (data.success) {
                console.log('Colore aggiornato con successo!');
            } else {
                console.error('Errore durante l\'aggiornamento del colore:', data.message);
            }
        })
        .catch((error) => console.error('Errore di connessione:', error));
}

// Funzione per convertire HEX in RGBA con trasparenza
function hexToRGBA(hex, alpha) {
    const bigint = parseInt(hex.slice(1), 16);
    const r = (bigint >> 16) & 255;
    const g = (bigint >> 8) & 255;
    const b = bigint & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function showInputForNewTask(statusId = null) {
    document.querySelectorAll('.new-task').forEach(task => {
        task.style.display = 'none';
    });

    const currentView = document.querySelector('.view-tab.active').getAttribute('data-view');
    let newTaskDiv;

    if (currentView === 'kanban') {
        newTaskDiv = document.querySelector(`#new-task-${statusId}`);
        if (newTaskDiv) newTaskDiv.style.display = 'block';
    } else if (currentView === 'table') {
        newTaskDiv = document.getElementById('new-task-row');
        if (newTaskDiv) newTaskDiv.style.display = 'table-row';
    } else if (currentView === 'list') {
        newTaskDiv = document.getElementById('new-task-item');
        if (newTaskDiv) newTaskDiv.style.display = 'block';
    } else if (currentView === 'calendar') {
        console.log("Modalità calendario non implementata per l'inserimento di task inline.");
    }
}
window.showInputForNewTask = showInputForNewTask;

function makeEditable(element) {
    element.contentEditable = true;
    element.focus();
    element.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            element.contentEditable = false;
        }
    });
}

function saveNewTask(statusId) {
    const taskDiv = document.querySelector(`#new-task-${statusId}`);
    const titolo = taskDiv.querySelector('[data-field="titolo"]').innerText.trim();
    const descrizione = taskDiv.querySelector('[data-field="descrizione"]').innerText.trim();
    const data_scadenza = taskDiv.querySelector('input[type="date"]').value;

    // Recupera i responsabili selezionati
    const responsabili = [];
    const responsabileOptions = taskDiv.querySelectorAll('.responsabile-option.selected');
    responsabileOptions.forEach(option => {
        responsabili.push(option.getAttribute('data-id'));
    });

    const bachecaId = document.querySelector('input[name="bacheca_id"]').value;

    // Costruzione della richiesta
    const payload = {
        titolo: titolo,
        descrizione: descrizione,
        data_scadenza: data_scadenza,
        status_id: statusId,
        bacheca_id: bachecaId,
        responsabile: responsabili.length ? responsabili.join(',') : null // Consentire valori nulli
    };

    // Assicurati che tutti i dati siano definiti
    if (!payload.titolo || !payload.bacheca_id) {
        console.error('Dati mancanti: titolo o bacheca_id non definiti.');
        alert('Errore: titolo o ID bacheca mancante.');
        return;
    }

    // Invio della richiesta
    fetch('/api/tasks/store_task.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Task creata con successo:', data);
            location.reload(); // Ricarica la pagina per aggiornare la vista
        } else {
            console.error('Errore durante la creazione della task:', data.message);
            alert(`Errore: ${data.message}`);
        }
    })
    .catch(error => {
        console.error('Errore durante la creazione della task:', error);
    });
}


    window.showInputForNewTask = showInputForNewTask;
    window.saveNewTask = saveNewTask;


// Funzione per aprire il modal
function openStateModal() {
    document.getElementById('stateModal').style.display = 'block';
}

// Funzione per chiudere il modal
function closeStateModal() {
    document.getElementById('stateModal').style.display = 'none';
}

// Chiudi il modal quando clicchi fuori dal contenuto
window.onclick = function(event) {
    const modal = document.getElementById('stateModal');
    if (event.target === modal) {
        closeStateModal();
    }
}

document.getElementById('createStateForm').addEventListener('submit', async function(event) { 
    event.preventDefault();

    const formData = new FormData(this);

    try {
        const response = await fetch('/api/tasks/create_state.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            alert(result.message);
            closeStateModal();
            location.reload(); // Ricarica la pagina per aggiornare la vista
        } else {
            alert(result.message);
        }
    } catch (error) {
        console.error('Errore nella creazione dello stato:', error);
    }
});

    // Mostra o Nasconde il menu contestuale
function toggleContextMenu(button) {
    const menu = button.nextElementSibling;
    menu.classList.toggle('visible');
}

// Chiudi il menu cliccando fuori
window.addEventListener('click', function(event) {
    if (!event.target.closest('.menu-button')) {
        document.querySelectorAll('.context-menu.visible').forEach(menu => {
            menu.classList.remove('visible');
        });
    }
});

    document.addEventListener('DOMContentLoaded', function () {
    function changeView(view) {
        const url = new URL(window.location.href);
        url.searchParams.set('view', view);
        window.history.pushState({}, '', url);

        // Ricarica il contenuto della vista selezionata
        fetch(url)
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newView = doc.querySelector('#view-container');
                document.getElementById('view-container').innerHTML = newView.innerHTML;

                // Reinizializza il calendario se necessario
                if (view === 'calendar') {
                    if (typeof initializeCalendar === 'function') {
                        initializeCalendar();
                    } else {
                        const script = document.createElement('script');
                        script.src = 'assets/js/calendar.js';
                        script.defer = true;
                        script.onload = () => initializeCalendar();
                        document.body.appendChild(script);
                    }
                }
            })
            .catch(error => console.error('Errore nel cambio di vista:', error));
    }

    document.querySelectorAll('.view-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            const view = this.getAttribute('data-view');
            changeView(view);
        });
    });
});
