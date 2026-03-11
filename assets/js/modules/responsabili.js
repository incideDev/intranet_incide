// Importa moduli riutilizzabili
// Importa moduli riutilizzabili
import { sendAjaxRequest } from '../modules/ajax.js';
import { showInputForNewTask } from '../tasks/task.js';

// ✅ Determina automaticamente se stiamo lavorando su TASK o GARE
const isGarePage = window.location.href.includes("page=gare") || window.location.href.includes("page=elenco_gare") || window.location.href.includes("page=estrazione_bandi");

// Popola il menu a tendina dei responsabili 
export function populateResponsabiliDropdown(containerId, taskData) {
    console.log('🎯 populateResponsabiliDropdown chiamato con:', taskData);

    const dropdown = document.getElementById(containerId);
    if (!dropdown) {
        console.error(`❌ Errore: Elemento con ID "${containerId}" non trovato.`);
        return;
    }

    dropdown.innerHTML = ''; // Svuota il dropdown

    if (Array.isArray(taskData.responsabili_info)) {
        taskData.responsabili_info.forEach(responsabile => {
            const userId = responsabile.user_id;
            const nominativo = responsabile.nominativo;
            const imagePath = responsabile.imagePath || 'assets/images/default_profile.png';

            const option = document.createElement('div');
            option.classList.add('responsabile-option');
            option.setAttribute('data-id', userId);
            option.setAttribute('data-task-id', taskData.id);
            option.innerHTML = `
                <img src="${imagePath}" alt="${nominativo}" class="profile-icon">
                <span>${nominativo}</span>
                <span class="selection-indicator">✔️</span>
            `;

            if (taskData.assigned_responsabili?.includes(userId)) {
                option.classList.add('selected');
            }

            option.addEventListener('click', () => toggleResponsabile(option, taskData.id));
            dropdown.appendChild(option);
        });
    } else {
        console.warn('⚠️ Nessun responsabile trovato in taskData.');
    }
}

//  Inizializza il dropdown dei responsabili
export function initializeResponsabili() {
    document.querySelectorAll('.responsabili-dropdown-container').forEach(container => {
        initResponsabiliDropdown(container);
    });
}

//  Funzione per aggiornare i responsabili associati a una task
export function updateTaskResponsabile(selectElement) {
    const taskId = selectElement.getAttribute('.task');
    const responsabileId = selectElement.value;

    if (!taskId || !responsabileId) {
        console.error("⚠️ Dati mancanti per aggiornare il responsabile.", { taskId, responsabileId });
        return;
    }

    console.log(`🔍 Aggiornamento responsabile: taskId=${taskId}, responsabileId=${responsabileId}`);

    fetch('/api/tasks/updateTaskResponsabile.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ taskId, responsabileId }),
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Errore HTTP: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            console.log("✅ Responsabile aggiornato con successo.");
        } else {
            console.error("❌ Errore durante l'aggiornamento del responsabile:", data.message);
        }
    })
    .catch(error => console.error("❌ Errore di connessione:", error));
}

export function toggleResponsabileMenu(triggerElement) {
    console.log('📌 toggleResponsabileMenu richiamato:', triggerElement);

    const taskElement = triggerElement.closest('.task');
    if (!taskElement) {
        console.error("❌ Errore: Nessun elemento .task trovato per determinare taskId.");
        return;
    }

    const taskId = taskElement.getAttribute('data-task-id');
    console.log(`📌 Task ID trovato: ${taskId}`);

    // Chiude tutti gli altri dropdown prima di aprirne uno nuovo
    document.querySelectorAll('.responsabile-menu').forEach(menu => {
        menu.remove();
    });

    let menu = taskElement.querySelector('.responsabile-menu');

    if (!menu) {
        console.warn(`⚠️ Nessun menu trovato per il task ${taskId}, creazione in corso...`);

        menu = document.createElement("div");
        menu.classList.add("responsabile-menu");
        menu.dataset.taskId = taskId;
        menu.innerHTML = `
            <div class="responsabile-menu-header">
                <input type="text" class="responsabile-search" placeholder="Cerca responsabile..." oninput="filterResponsabili(event)">
                <div class="responsabile-list"></div>
            </div>`;

        taskElement.appendChild(menu);
    }

    // ✅ Imposta la posizione corretta
    menu.style.position = "absolute";
    menu.style.top = "100%";
    menu.style.left = "0";
    menu.style.zIndex = "9999";
    menu.style.display = "block"; // Forza la visibilità

    console.log(`📌 Mostrando il menu responsabili per il task ${taskId}`);

    // ✅ Carica i responsabili disponibili e quelli già assegnati
    fetch(`/api/gare/get_gara_responsabili.php?id_gara=${taskId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error("❌ Errore nel recupero dei responsabili:", data.message);
                return;
            }

            console.log("✅ Responsabili caricati:", data.responsabili);

            const listContainer = menu.querySelector('.responsabile-list');
            listContainer.innerHTML = ''; // Pulisce la lista prima di riempirla

            data.responsabili.forEach(responsabile => {
                let option = document.createElement('div');
                option.classList.add('responsabile-option');
                option.dataset.id = responsabile.Cod_Operatore;
                option.innerHTML = `
                    <img src="${responsabile.imagePath}" class="profile-icon" alt="${responsabile.Nominativo}">
                    <span>${responsabile.Nominativo}</span>
                    <span class="selection-indicator ${responsabile.assegnato ? '' : 'hidden'}">✔️</span>`;

                // **Evidenzia i responsabili già assegnati**
                if (responsabile.assegnato) {
                    option.classList.add('selected');
                }

                option.addEventListener("click", () => toggleResponsabile(option, taskId));

                listContainer.appendChild(option);
            });

            // ✅ Aggiunge il listener per chiudere il menu al click fuori
            setTimeout(() => {
                document.addEventListener("click", closeDropdownOutside);
            }, 50);
        })
        .catch(error => console.error("❌ Errore di connessione:", error));

    // Funzione per chiudere il menu se si clicca fuori
    function closeDropdownOutside(event) {
        if (!menu.contains(event.target) && event.target !== triggerElement) {
            menu.remove();
            document.removeEventListener("click", closeDropdownOutside);
        }
    }
}

export function toggleResponsabile(optionElement, taskId) {
    console.log('📌 toggleResponsabile chiamato per taskId:', taskId);

    taskId = String(taskId);
    const responsabileId = optionElement.getAttribute('data-id');
    const isSelected = optionElement.classList.contains('selected');

    if (!taskId || taskId.startsWith("new-task-")) {
        console.log("⏳ Creazione della nuova task prima di assegnare il responsabile...");

        const taskElement = document.getElementById(taskId);
        const titolo = taskElement.querySelector('[data-field="titolo"]')?.textContent.trim() || "Nuova Task";
        const descrizione = taskElement.querySelector('[data-field="descrizione"]')?.textContent.trim() || "";
        const dataScadenza = taskElement.querySelector('[data-field="data_scadenza"] input')?.value || null;
        const statusId = taskElement.closest('.kanban-column')?.getAttribute('data-status-id');
        const bachecaId = document.querySelector('[name="bacheca_id"]')?.value || document.querySelector('.kanban-board')?.getAttribute('data-bacheca-id');

        if (!statusId || !bachecaId) {
            console.error("❌ Errore: Dati mancanti per creare la task.");
            return;
        }

        fetch('/api/tasks/store_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ titolo, descrizione, data_scadenza: dataScadenza, status_id: statusId, bacheca_id: bachecaId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log(`✅ Task creata con successo! ID reale: ${data.taskId}`);

                // **Aggiorna il taskId nella UI**
                taskElement.id = `task-${data.taskId}`;
                taskElement.querySelectorAll('[data-task-id]').forEach(el => el.setAttribute('data-task-id', data.taskId));
                
                // **Aggiorna il riferimento della variabile taskId**
                taskId = data.taskId;

                // **Aggiorna il dropdown e le icone**
                optionElement.classList.toggle('selected');
                updateResponsabiliIcons(taskId, data.responsabili);

                // **Assegna il responsabile dopo la creazione**
                assignResponsabile(taskId, responsabileId, !isSelected, optionElement);
            } else {
                console.error("❌ Errore durante la creazione della task:", data.message);
            }
        })
        .catch(error => console.error("❌ Errore di connessione:", error));

        return;
    }

    // **Se la task esiste già, assegna o rimuove il responsabile**
    assignResponsabile(taskId, responsabileId, !isSelected, optionElement);
}

// **Funzione per aggiungere/rimuovere un responsabile e aggiornare il dropdown**
function assignResponsabile(taskId, responsabileId, isSelected, optionElement) {
    console.log(`📌 Assegno responsabile: taskId=${taskId}, responsabileId=${responsabileId}`);

    const endpoint = isGarePage
        ? (isSelected ? '/api/gare/add_gara_responsabile.php' 
                      : '/api/gare/remove_gara_responsabile.php')
        : (isSelected ? '/api/tasks/add_responsabile.php' 
                      : '/api/tasks/remove_responsabile.php');

    const requestBody = isGarePage 
        ? { id_gara: String(taskId), id_responsabile: responsabileId } 
        : { taskId: String(taskId), responsabileId };

    fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(requestBody)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`✅ Responsabile ${isSelected ? 'aggiunto' : 'rimosso'} con successo.`);

            // **Aggiorna il dropdown immediatamente**
            optionElement.classList.toggle('selected');

            // **Forza l'aggiornamento delle icone sopra la task**
            setTimeout(() => refreshResponsabiliIcons(taskId), 200);
        } else {
            console.error("❌ Errore aggiornamento responsabili:", data.message);
        }
    })
    .catch(error => console.error("❌ Errore connessione:", error));
}

// Aggiorna le icone dei responsabili nella task
export function updateResponsabiliIcons(taskId, responsabili) {
    const taskElement = document.getElementById(`task-${taskId}`);
    if (!taskElement) {
        console.error(`❌ Task con ID "task-${taskId}" non trovata.`);
        return;
    }

    const iconsContainer = taskElement.querySelector('.responsabili-icons');
    if (!iconsContainer) {
        console.error(`❌ Contenitore icone per task "${taskId}" non trovato.`);
        return;
    }

    // Svuota le icone precedenti
    iconsContainer.innerHTML = '';

    // **Aggiunge dinamicamente le icone dei responsabili**
    if (responsabili && responsabili.length > 0) {
        responsabili.forEach(responsabile => {
            const img = document.createElement('img');
            img.src = responsabile.imagePath || 'assets/images/default_profile.png';
            img.alt = responsabile.nominativo || 'Sconosciuto';
            img.classList.add('profile-icon');
            img.setAttribute('title', responsabile.nominativo);
            iconsContainer.appendChild(img);
        });
    }

    // **Aggiunge l'icona "+" sempre visibile per assegnare nuovi responsabili**
    const addIcon = document.createElement('span');
    addIcon.classList.add('add-icon');
    addIcon.textContent = '+';
    iconsContainer.appendChild(addIcon);
}

export function loadTaskResponsabili(taskId) {
    console.log(`🔄 Carico i responsabili della task ID ${taskId}...`);

    const fetchEndpoint = isGarePage 
        ? `/api/gare/get_gara_responsabili.php?id_gara=${taskId}`
        : `/api/tasks/get_task_responsabili.php?taskId=${taskId}`;

    fetch(fetchEndpoint)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error("❌ Errore nel caricamento dei responsabili:", data.message);
                return;
            }

            console.log("✅ Responsabili caricati:", data.responsabili);

            // **Aggiorna il dropdown con le spunte**
            updateResponsabileDropdown(taskId, data.responsabili);
        })
        .catch(error => console.error("❌ Errore di connessione:", error));
}

export function openResponsabileDropdown(taskId) {
    console.log(`📌 Apri dropdown responsabili per task ID: ${taskId}`);

    fetch(`/api/tasks/get_task_responsabili.php?taskId=${taskId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log("✅ Responsabili caricati:", data.responsabili);
                
                document.querySelectorAll(`.responsabile-option[data-task-id="${taskId}"]`).forEach(option => {
                    const responsabileId = option.getAttribute('data-id');
                    if (data.responsabili.some(r => r.user_id === responsabileId)) {
                        option.classList.add('selected');
                    } else {
                        option.classList.remove('selected');
                    }
                });
            } else {
                console.error("❌ Errore nel caricamento dei responsabili:", data.message);
            }
        })
        .catch(error => console.error("❌ Errore connessione:", error));
}

//  Sincronizza la UI con i responsabili aggiornati
function syncResponsabili(taskId, responsabiliIds) {
    updateResponsabiliIcons(taskId, responsabiliIds);
    updateResponsabiliModal(taskId, responsabiliIds);
}

// Aggiorna i responsabili nel modal
export function updateResponsabiliModal(taskId, responsabili) {
    const modalContainer = document.querySelector('#task-responsabili .responsabili-icons');
    if (!modalContainer) return;

    modalContainer.innerHTML = ''; // Svuota le icone

    responsabili.forEach(responsabile => {
        const img = document.createElement('img');
        img.src = responsabile.imagePath || 'assets/images/default_profile.png';
        img.alt = responsabile.nominativo || 'Sconosciuto';
        img.classList.add('profile-icon');
        modalContainer.appendChild(img);
    });
}

export function toggleDropdown(triggerElement) {
    let dropdownMenu = triggerElement.closest('.responsabili-container')?.querySelector('.dropdown-menu');

    if (!dropdownMenu) {
        console.error("❌ Nessun menu dropdown trovato accanto all'elemento trigger.", triggerElement);
        return;
    }

    // Chiudi tutti gli altri dropdown aperti
    document.querySelectorAll('.dropdown-menu').forEach(menu => {
        if (menu !== dropdownMenu) menu.classList.remove('visible');
    });

    dropdownMenu.classList.toggle('visible');

    document.addEventListener('click', function handleClickOutside(event) {
        if (!dropdownMenu.contains(event.target) && event.target !== triggerElement) {
            dropdownMenu.classList.remove('visible');
            document.removeEventListener('click', handleClickOutside);
        }
    });
}

export function filterDropdownOptions(event) {
    const query = event.target.value.toLowerCase();
    const menu = event.target.closest('.dropdown-menu');
    const options = menu.querySelectorAll('.dropdown-option');

    options.forEach(option => {
        const name = option.textContent.toLowerCase();
        option.style.display = name.includes(query) ? 'block' : 'none';
    });
}

function filterResponsabili(event) {
    const query = event.target.value.toLowerCase();
    const menu = event.target.closest('.responsabile-menu');

    if (!menu) {
        console.error("❌ Errore: impossibile trovare il menu dei responsabili.");
        return;
    }

    const options = menu.querySelectorAll('.responsabile-option');

    options.forEach(option => {
        const name = option.querySelector('span')?.textContent.toLowerCase() || '';
        option.style.display = name.includes(query) ? "flex" : "none"; 
    });
}

// ✅ Rendi la funzione disponibile globalmente
window.filterResponsabili = filterResponsabili;

export function generateResponsabileIcons(responsabiliInfo) {
    if (!responsabiliInfo) return "";

    let iconsHTML = "";
    let responsabili = responsabiliInfo.split(',');

    responsabili.forEach(responsabileData => {
        let [nominativo, , imagePath] = responsabileData.split('|'); // ignora userId se non serve
        imagePath = imagePath && imagePath !== "undefined" ? imagePath : "assets/images/default_profile.png";

        iconsHTML += `
            <img src="${imagePath}" alt="${nominativo}" class="profile-icon">
        `;
    });

    return iconsHTML;
}

window.Responsabili = {
    generateResponsabileIcons,
    toggleResponsabileMenu,
    populateResponsabiliDropdown,
    updateTaskResponsabile
};

function refreshResponsabiliIcons(taskId) {
    console.log(`🔄 Refresh icone responsabili per la gara ID ${taskId}...`);

    fetch(`/api/gare/get_gara_responsabili.php?id_gara=${taskId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.error("❌ Errore nel caricamento dei responsabili:", data.message);
                return;
            }

            console.log(`✅ Responsabili aggiornati per la gara ${taskId}:`, data.responsabili);

            const taskElement = document.getElementById(`task-${taskId}`);
            if (!taskElement) {
                console.error(`❌ Task con ID "task-${taskId}" non trovata.`);
                return;
            }

            const iconsContainer = taskElement.querySelector('.responsabili-icons');
            if (!iconsContainer) {
                console.error(`❌ Contenitore icone per task "${taskId}" non trovato.`);
                return;
            }

            // **Pulisce il contenitore delle icone**
            iconsContainer.innerHTML = '';

            // **Filtra solo i responsabili effettivamente assegnati a questa gara**
            const responsabiliAssegnati = data.responsabili.filter(r => r.assegnato);
            
            // **Aggiunge solo i responsabili effettivamente assegnati**
            responsabiliAssegnati.forEach(responsabile => {
                const img = document.createElement('img');
                img.src = responsabile.imagePath || 'assets/images/default_profile.png';
                img.alt = responsabile.Nominativo || 'Sconosciuto';
                img.classList.add('profile-icon');
                img.setAttribute('title', responsabile.Nominativo);
                iconsContainer.appendChild(img);
            });

            // **Aggiunge l'icona "+" per assegnare nuovi responsabili**
            const addIcon = document.createElement('span');
            addIcon.classList.add('add-icon');
            addIcon.textContent = '+';
            iconsContainer.appendChild(addIcon);
        })
        .catch(error => console.error("❌ Errore di connessione:", error));
}
