document.addEventListener('DOMContentLoaded', function () {
    console.log("task.js è stato caricato correttamente.");

    // Funzione per aprire il modal dei dettagli della task
    function openTaskDetailModal(taskId) {
        fetch(`/api/tasks/task_details.php?id=${taskId}`)
            .then(response => response.json())
            .then(data => {
                if (data) {
                    console.log(data);
                } else {
                    console.error('Task non trovata');
                }
            })
            .catch(error => {
                console.error('Errore durante il recupero dei dettagli della task:', error);
            });
    }

    // Funzione per chiudere il modal dei dettagli della task
    const closeTaskDetailModalBtn = document.getElementById('closeTaskDetailModal');
    if (closeTaskDetailModalBtn) {
        closeTaskDetailModalBtn.addEventListener('click', function () {
            document.getElementById('taskDetailModal').style.display = 'none';
        });
    }

    window.addEventListener('click', function (event) {
        const taskDetailModal = document.getElementById('taskDetailModal');
        if (event.target === taskDetailModal) {
            taskDetailModal.style.display = 'none';
        }
    });

    function updateTaskStatusKanban(taskId, newStatusId) {
        fetch('/api/tasks/update_task_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: taskId, status: newStatusId })
        })
        .then(response => {
            if (response.ok) {
                console.log('Task aggiornata con successo');
            } else {
                console.error('Errore durante l\'aggiornamento del task');
            }
        })
        .catch(error => {
            console.error('Errore di connessione:', error);
        });
    }

    function updateTaskResponsabile(selectElement) {
        const taskId = selectElement.getAttribute('data-task-id');
        const responsabileId = selectElement.value;

        fetch('/api/tasks/update_task_responsabile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ taskId, responsabileId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Responsabile aggiornato con successo');
            } else {
                console.error('Errore durante l\'aggiornamento del responsabile:', data.message);
            }
        })
        .catch(error => console.error('Errore di connessione:', error));
    }
    window.updateTaskResponsabile = updateTaskResponsabile;

    document.querySelectorAll('.responsabile-select').forEach(select => {
        select.addEventListener('change', function () {
            updateTaskResponsabile(this);
        });
    });

    document.querySelectorAll('.editable').forEach(cell => {
        cell.addEventListener('blur', function () {
            const taskId = this.getAttribute('data-task-id');
            const field = this.getAttribute('data-field');
            const value = this.textContent.trim();

            if (!taskId || !field || !value) {
                console.error("Mancano i dati necessari per l'aggiornamento");
                return;
            }

            fetch('/api/tasks/updateTaskField.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: taskId, field: field, value: value })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Modifica salvata con successo');
                } else {
                    console.error('Errore durante l\'aggiornamento del task:', data.message);
                }
            })
            .catch(error => {
                console.error('Errore di connessione:', error);
            });
        });
    });

    function saveTaskField(element) {
    const taskId = element.getAttribute('data-task-id');
    const field = element.getAttribute('data-field');
    let value = element.textContent.trim();

    if (field === 'data_scadenza' && element.type === 'date') {
        value = element.value;
    }

    console.log({
        id: taskId,
        field: field,
        value: value
    });

    if (!taskId || !field || value === '') {
        console.error('Dati mancanti o valore vuoto.');
        return;
    }

    fetch('/api/tasks/updateTaskField.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: taskId, field: field, value: value })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`Campo "${field}" aggiornato con successo per la task ${taskId}`);
        } else {
            console.error(`Errore nel salvataggio del campo "${field}" per la task ${taskId}: ${data.message}`);
        }
    })
    .catch(error => console.error('Errore durante il salvataggio:', error));
}
window.saveTaskField = saveTaskField;



    function toggleResponsabileMenu(triggerElement) {
        console.log("toggleResponsabileMenu è stato richiamato:", triggerElement);
        const menu = triggerElement.nextElementSibling;

        if (!menu) {
            console.error('Menu non trovato per il trigger:', triggerElement);
            return;
        }

        if (!menu.querySelector('.responsabile-search')) {
            const searchBar = document.createElement('input');
            searchBar.type = 'text';
            searchBar.placeholder = 'Cerca...';
            searchBar.classList.add('responsabile-search');
            searchBar.addEventListener('input', filterResponsabili);
            menu.prepend(searchBar);
        }

        document.querySelectorAll('.responsabile-menu').forEach(otherMenu => {
            if (otherMenu !== menu) {
                otherMenu.classList.remove('visible');
            }
        });

        menu.classList.toggle('visible');

        if (menu.classList.contains('visible')) {
            document.addEventListener('click', closeMenuOnOutsideClick);
        }
    }
    window.toggleResponsabileMenu = toggleResponsabileMenu;

    function filterResponsabili(event) {
        const query = event.target.value.toLowerCase();
        const menu = event.target.closest('.responsabile-menu');
        const options = menu.querySelectorAll('.responsabile-option');

        options.forEach(option => {
            const name = option.querySelector('span').textContent.toLowerCase();
            option.style.display = name.includes(query) ? 'flex' : 'none';
        });
    }

    function closeMenuOnOutsideClick(event) {
        const menus = document.querySelectorAll('.responsabile-menu.visible');

        menus.forEach(menu => {
            if (!menu.contains(event.target) && !menu.previousElementSibling.contains(event.target)) {
                menu.classList.remove('visible');
            }
        });

        if (document.querySelectorAll('.responsabile-menu.visible').length === 0) {
            document.removeEventListener('click', closeMenuOnOutsideClick);
        }
    }

    function toggleResponsabile(optionElement, taskId) {
    console.log('toggleResponsabile chiamato per taskId:', taskId);
    const isSelected = !optionElement.classList.contains('selected');
    const responsabileId = optionElement.getAttribute('data-id');
    const indicator = optionElement.querySelector('.selection-indicator');

    // Aggiorna la selezione visiva
    if (isSelected) {
        optionElement.classList.add('selected');
        indicator.classList.remove('hidden');
    } else {
        optionElement.classList.remove('selected');
        indicator.classList.add('hidden');
    }

    // Aggiorna i responsabili sul server
    const endpoint = isSelected ? 'add_responsabile.php' : 'remove_responsabile.php';
    fetch(`/api/tasks/${endpoint}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ taskId, responsabileId })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Errore nella richiesta: ${response.statusText}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            console.log(`Responsabile ${isSelected ? 'aggiunto' : 'rimosso'} con successo.`);
            updateResponsabiliIcons(taskId, data.responsabili);
        } else {
            console.error('Errore nell\'aggiornamento dei responsabili:', data.message);
        }
    })
    .catch(error => {
        console.error('Errore di connessione o JSON non valido:', error);
    });
}

    window.toggleResponsabile = toggleResponsabile;

    function updateResponsabiliIcons(taskId, responsabili) {
        const taskElement = document.getElementById(`task-${taskId}`);
        const iconsContainer = taskElement.querySelector('.responsabili-icons');

        iconsContainer.innerHTML = '';

        if (responsabili && responsabili.length > 0) {
            responsabili.forEach(responsabile => {
                const img = document.createElement('img');
                img.src = responsabile.imagePath || 'assets/images/default_profile.png';
                img.alt = responsabile.nominativo || 'Sconosciuto';
                img.classList.add('profile-icon');
                iconsContainer.appendChild(img);
            });
        }

        const addIcon = document.createElement('span');
        addIcon.classList.add('add-icon');
        addIcon.textContent = '+';
        iconsContainer.appendChild(addIcon);
    }

        document.querySelectorAll('.date-field').forEach(field => {
        const dateInput = field.querySelector('input[type="date"]');
        const icon = field.querySelector('img.icon');

        icon.addEventListener('click', (event) => {
            event.stopPropagation(); // Assicura che l'evento sia considerato user gesture
            dateInput.showPicker();
        });

        field.addEventListener('click', (event) => {
            event.stopPropagation(); // Assicura che l'evento sia considerato user gesture
            dateInput.showPicker();
        });
    });
});