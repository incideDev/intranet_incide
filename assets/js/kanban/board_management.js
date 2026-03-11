//  Importa moduli comuni
import { initializeTabs } from '../modules/tabs.js';
import { toggleModal } from '../modules/modals.js';
import { sendAjaxRequest } from '../modules/ajax.js';
import { initializeKanbanDragAndDrop } from '../kanban/kanban_drag.js';

//  Inizializza le schede nella vista della bacheca
document.addEventListener('DOMContentLoaded', function () {
    initializeTabs();
    initializeKanbanDragAndDrop();
    
    document.getElementById('openModalBtn')?.addEventListener('click', () => toggleModal('taskModal', 'open'));
    document.getElementById('closeModal')?.addEventListener('click', () => toggleModal('taskModal', 'close'));
    document.getElementById('addNewBoardBtn')?.addEventListener('click', () => toggleModal('addBoardModal', 'open'));
    document.getElementById('closeBoardModal')?.addEventListener('click', () => toggleModal('addBoardModal', 'close'));

    //  Recupera la lista delle bacheche dal server
    loadBoards();
});

//  Funzione per ottenere l'ID della bacheca dall'URL
function getBoardIdFromUrl() {
    const params = new URLSearchParams(window.location.search);
    return params.get('board') ? parseInt(params.get('board'), 10) : null;
}

//  Carica le bacheche disponibili
function loadBoards() {
    fetch('/api/kanban/getBoards.php')
        .then(response => {
            if (!response.ok) {
                throw new Error(`Errore HTTP: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                console.log("✅ Bacheche caricate:", data.boards);
                // QUI PUOI AGGIUNGERE IL CODICE PER VISUALIZZARE LE BACHECHE
            } else {
                console.error(" Errore nel caricamento delle bacheche:", data.message);
            }
        })
        .catch(error => console.error(" Errore connessione:", error));
}


//  Selezione degli stati standard
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

//  Funzione per salvare uno stato personalizzato
function saveCustomStatus(stateName, category) {
    sendAjaxRequest('/api/kanban/addCustomStatus.php', { stateName, category, bachecaId: getBoardIdFromUrl() })
        .then(data => {
            if (data.success) {
                console.log(" Stato personalizzato salvato:", data.statusId);
            } else {
                console.error(" Errore nel salvataggio dello stato:", data.message);
            }
        })
        .catch(error => console.error(" Errore connessione:", error));
}

//  Submit del form di creazione della bacheca
const newBoardForm = document.getElementById('newBoardForm');
newBoardForm?.addEventListener('submit', function (event) {
    event.preventDefault();

    const formData = new FormData(newBoardForm);
    formData.append('selectedStatuses', JSON.stringify(Array.from(selectedStatuses)));

    sendAjaxRequest('/api/kanban/addBoard.php', Object.fromEntries(formData))
        .then(data => {
            if (data.success) {
                alert(" Bacheca creata con successo!");
                location.reload();
            } else {
                alert(` Errore nella creazione della bacheca: ${data.message}`);
            }
        })
        .catch(error => console.error(" Errore creazione bacheca:", error));
});

document.querySelectorAll('.task input[data-field="data_scadenza"]').forEach(input => {
    input.setAttribute('type', 'date'); // Assicura che sia un campo data
    input.addEventListener('focus', function () {
        this.showPicker(); // Mostra il Date Picker quando il campo viene selezionato
    });
});
