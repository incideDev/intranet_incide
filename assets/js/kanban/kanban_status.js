//  Importa il modulo per le richieste AJAX
import { sendAjaxRequest } from '../modules/ajax.js';

//  Aggiorna il colore di uno stato
export function updateColumnColor(statusId, color) {
    const column = document.querySelector(`.kanban-column[data-status-id="${statusId}"]`);
    if (!column) {
        console.error(` Colonna con statusId=${statusId} non trovata.`);
        return;
    }

    // Applica il colore aggiornato alla UI
    const rgbaColor = hexToRGBA(color, 0.1);
    column.style.setProperty('--column-color', rgbaColor);
    column.style.setProperty('--header-color', color);

    // Salva il colore nel database
    sendAjaxRequest('/api/kanban/update_status_color.php', { status_id: statusId, color })
        .then(data => {
            if (data.success) {
                console.log(" Colore aggiornato con successo.");
            } else {
                console.error(" Errore nell'aggiornamento del colore:", data.message);
            }
        })
        .catch(error => console.error(" Errore connessione:", error));
}

//  Apre il color picker per modificare il colore di uno stato
export function openColorPicker(statusId) {
    const colorInput = document.createElement('input');
    colorInput.type = 'color';
    colorInput.style.position = 'absolute';
    colorInput.style.visibility = 'hidden';

    colorInput.oninput = function () {
        updateColumnColor(statusId, colorInput.value);
    };

    document.body.appendChild(colorInput);
    colorInput.click();
    document.body.removeChild(colorInput);
}

//  Converte un colore HEX in RGBA con trasparenza
function hexToRGBA(hex, alpha) {
    const bigint = parseInt(hex.slice(1), 16);
    const r = (bigint >> 16) & 255;
    const g = (bigint >> 8) & 255;
    const b = bigint & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

//  Aggiunge un nuovo stato nel Kanban
export function addCustomState(category) {
    const stateName = prompt("Inserisci il nome dello stato:");
    if (!stateName) return;

    sendAjaxRequest('/api/kanban/addCustomStatus.php', { stateName, category })
        .then(data => {
            if (data.success) {
                console.log(" Stato aggiunto con successo:", data.statusId);
                renderNewState(category, stateName, data.statusId);
            } else {
                console.error(" Errore nell'aggiunta dello stato:", data.message);
            }
        })
        .catch(error => console.error(" Errore connessione:", error));
}

//  Renderizza un nuovo stato nel Kanban
function renderNewState(category, stateName, statusId) {
    const categoryList = document.getElementById(`${category}States`);
    if (!categoryList) {
        console.error(` Categoria ${category} non trovata.`);
        return;
    }

    const listItem = document.createElement('li');
    listItem.textContent = stateName;
    listItem.setAttribute('data-id', statusId);

    const removeButton = document.createElement('button');
    removeButton.textContent = 'Rimuovi';
    removeButton.onclick = function () {
        removeState(statusId, listItem);
    };

    listItem.appendChild(removeButton);
    categoryList.appendChild(listItem);
}

//  Rimuove uno stato dal Kanban
export function removeState(statusId, element) {
    sendAjaxRequest('/api/kanban/delete_status.php', { status_id: statusId })
        .then(data => {
            if (data.success) {
                console.log(" Stato rimosso con successo.");
                element.remove();
            } else {
                console.error(" Errore nella rimozione dello stato:", data.message);
            }
        })
        .catch(error => console.error(" Errore connessione:", error));
}
