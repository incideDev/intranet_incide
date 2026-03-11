window.mapContainer = window.mapContainer || null;
window.selectedElements = window.selectedElements || [];

window.toolStates = window.toolStates || {
    select: false,
    lockAreas: false,
    lockContacts: false
};

document.addEventListener('DOMContentLoaded', function () {
    // Configurazione iniziale
    const defaultFloor = 'Plan_PT';
    const imageMap = {
        'Plan_P3': `/assets/plan/Planimetrie Ufficio Padova-P3.png`,
        'Plan_PT': `/assets/plan/Planimetrie Ufficio Padova-PT.png`
    };
    
    // Elementi DOM
    const floorTabs = document.querySelectorAll('.floor-tab');
    const personnelContainer = document.getElementById('personnel-container');
    const planImage = document.getElementById('plan-image');
    window.mapContainer = document.getElementById('map-container');
    const mapContainer = window.mapContainer;

    mapContainer.addEventListener('dragover', function (e) {
        e.preventDefault();
    });

    let showArchived = false;
    let selectionStart = null;
    let selectionRect = null;

    const toggleArchivedButton = document.getElementById('show-all-btn');
    toggleArchivedButton.addEventListener('click', () => {
    showArchived = !showArchived;
    toggleArchivedButton.textContent = showArchived ? 'Mostra archiviati' : 'Mostra attivi';
    loadAvailableContacts(showArchived);
});

const searchInput = document.getElementById('contact-search');
searchInput.addEventListener('input', function () {
    const query = this.value.toLowerCase();
    const cards = document.querySelectorAll('.contact-card-sidebar');

    cards.forEach(card => {
        const name = card.querySelector('.contact-name')?.textContent.toLowerCase() || '';
        card.style.display = name.includes(query) ? 'flex' : 'none';
    });
});

mapContainer.addEventListener('mousedown', function (e) {
    if (!window.toolStates.select || e.button !== 0) return;

    const wrapper = document.getElementById('map-container');
    const rect = wrapper.getBoundingClientRect();

    selectionStart = { x: e.clientX - rect.left, y: e.clientY - rect.top };

    selectionRect = document.createElement('div');
    selectionRect.id = 'selection-rectangle';
    selectionRect.style.left = `${selectionStart.x}px`;
    selectionRect.style.top = `${selectionStart.y}px`;
    selectionRect.style.width = '0px';
    selectionRect.style.height = '0px';

    wrapper.appendChild(selectionRect);

    function onMouseMove(ev) {
        const currX = ev.clientX - rect.left;
        const currY = ev.clientY - rect.top;

        const width = Math.abs(currX - selectionStart.x);
        const height = Math.abs(currY - selectionStart.y);
        const left = Math.min(currX, selectionStart.x);
        const top = Math.min(currY, selectionStart.y);

        selectionRect.style.left = `${left}px`;
        selectionRect.style.top = `${top}px`;
        selectionRect.style.width = `${width}px`;
        selectionRect.style.height = `${height}px`;
    }

    function onMouseUp(ev) {
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);

        const selRect = selectionRect.getBoundingClientRect();
        window.selectedElements = [];

        document.querySelectorAll('.personnel-icon.selected').forEach(el => el.classList.remove('selected'));

        document.querySelectorAll('.personnel-icon').forEach(icon => {
            const iconRect = icon.getBoundingClientRect();
            if (
                iconRect.left >= selRect.left &&
                iconRect.right <= selRect.right &&
                iconRect.top >= selRect.top &&
                iconRect.bottom <= selRect.bottom
            ) {
                icon.classList.add('selected');
                window.selectedElements.push(icon);
            }
        });

        selectionRect.remove();
        selectionRect = null;
    }

    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
});

mapContainer.addEventListener('drop', function (e) {
    e.preventDefault();

    let draggedElement = document.querySelector('.dragging');
    if (!draggedElement) return;

    if (!draggedElement.classList.contains('contact-card-sidebar')) {
        draggedElement = draggedElement.closest('.contact-card-sidebar');
    }
    if (!draggedElement) {
        console.error("Nessuna card valida trovata.");
        return;
    }

    const userId = draggedElement.dataset.userId;
    if (!userId || userId.trim() === '') {
        console.warn(" Drop ignorato: user_id non valido.");
        return;
    }

    // Se l'utente è già sulla mappa, blocca il drop
    if (document.querySelector(`.personnel-icon[data-user-id="${userId}"]`)) {
        console.warn(` L'utente con ID ${userId} è già presente sulla mappa.`);
        return;
    } 

    const mapRect = mapContainer.getBoundingClientRect();
    const offsetX = e.clientX - mapRect.left;
    const offsetY = e.clientY - mapRect.top;
    const xPercent = (offsetX / mapRect.width) * 100;
    const yPercent = (offsetY / mapRect.height) * 100;

    const icon = document.createElement('div');
    icon.className = 'personnel-icon';
    icon.dataset.userId = userId;
    icon.style.left = `${xPercent}%`;
    icon.style.top = `${yPercent}%`;

    const name = draggedElement.querySelector('.contact-name')?.textContent || '';
    const imgSrc = draggedElement.querySelector('img')?.src || `assets/images/default_profile.png`;

    const nameLabel = document.createElement('span');
    nameLabel.className = 'name-label';
    nameLabel.textContent = name;
    icon.title = name;
    icon.appendChild(nameLabel);

    icon.style.backgroundImage = `url(${imgSrc})`;
    icon.style.backgroundSize = 'cover';
    icon.style.backgroundPosition = 'center';

    makeDraggable(icon, 'personnel');
    mapContainer.appendChild(icon);

    const floor = document.querySelector('.floor-tab.active')?.dataset.floor || 'Plan_PT';
    savePosition(userId, floor, xPercent, yPercent, null);

    draggedElement.classList.remove('dragging');
    draggedElement.remove();
});


// MENU CONTESTUALE UNIFICATO
document.getElementById('map-container').addEventListener('contextmenu', function (e) {
    e.preventDefault();

    // Rimuovi eventuali menu precedenti
    document.getElementById('unified-context-menu')?.remove();

    const target = e.target.closest('.personnel-icon, .postazione-area');
    const isIcon = target?.classList.contains('personnel-icon');
    const isArea = target?.classList.contains('postazione-area');
    const clickedElement = isIcon ? target : isArea ? target : null;

    const menu = document.createElement('div');
    menu.id = 'unified-context-menu';
    menu.className = 'context-menu';
    menu.style.left = `${e.pageX}px`;
    menu.style.top = `${e.pageY}px`;

    // === VOCI MENU ===
    const entries = [
        { label: '  Aggiungi area', action: addNewArea, enabled: !clickedElement },
        { 
            label: ' Elimina area',
            action: () => {
                const floor = document.querySelector('.floor-tab.active')?.dataset.floor;
                const interno = clickedElement.dataset.interno;
        
                if (!floor || !interno) {
                    showToast('Dati non validi per eliminare l’area.', "error");
                    return;
                }
                
                showConfirm("Vuoi eliminare quest'area?", function() {
                    customFetch('office_map', 'delete_postazione', { floor, interno })
                        .then(data => {
                            if (data.success) {
                                clickedElement.remove();
                                showToast("Area eliminata con successo.", "success");
                            } else {
                                showToast('Errore durante eliminazione: ' + (data.error || ''), "error");
                            }
                        })
                        .catch(err => {
                            console.error('Errore fetch:', err);
                            showToast('Errore durante la richiesta al server', "error");
                        });
                });                
            },
            enabled: isArea
        },
        { label: ' Modifica interno', action: () => {
            showEditInternoDialog(clickedElement);
        }, enabled: isArea },        
        { label: ' Sposta a Plan_P3', action: () => {
            if (clickedElement) changeFloor(clickedElement.dataset.userId, 'Plan_P3');
        }, enabled: isIcon },
        { label: ' Sposta a Plan_PT', action: () => {
            if (clickedElement) changeFloor(clickedElement.dataset.userId, 'Plan_PT');
        }, enabled: isIcon },
        { label: ' Archivia contatto', action: () => {
            if (!clickedElement) return;
            showConfirm("Vuoi archiviare questo contatto?", function() {
                customFetch('office_map', 'archive_contact', {
                    user_id: clickedElement.dataset.userId
                })
                .then(data => {
                    if (data.success) {
                        clickedElement.remove();
                        loadAvailableContacts();
                        showToast("Contatto archiviato con successo.", "success");
                    } else {
                        showToast('Errore archiviazione: ' + (data.error || ''), "error");
                    }
                })
                .catch(err => {
                    console.error('Errore durante archiviazione:', err);
                    showToast('Errore durante la richiesta al server', "error");
                });
            });               
        }, enabled: isIcon }
    ];

    entries.forEach(entry => {
        const option = document.createElement('div');
        option.className = 'menu-option';
        option.textContent = entry.label;
        if (!entry.enabled) {
            option.classList.add('disabled');
        } else {
            option.addEventListener('click', () => {
                entry.action();
                menu.remove();
            });
        }
        menu.appendChild(option);
    });

    document.body.appendChild(menu);
    document.addEventListener('click', () => menu.remove(), { once: true });
});

function addNewArea() {
    const floor = document.querySelector('.floor-tab.active')?.dataset.floor;
    if (!floor) return;

    const area = {
        interno: 'Nuova',
        x_position: 3,
        y_position: 5,
        width: 6,
        height: 5
    };

    const div = createPostazione(area, document.getElementById('personnel-container'));
    document.getElementById('personnel-container').appendChild(div);

    savePostazione(floor, area.interno, area.x_position, area.y_position, area.width, area.height);
}

function showEditInternoDialog(element) {
    const currentVal = element.dataset.interno || '';

    // Rimuovi eventuale dialog esistente
    document.getElementById('edit-interno-dialog')?.remove();

    // Overlay
    const overlay = document.createElement('div');
    overlay.id = 'edit-interno-dialog';
    overlay.style.position = 'fixed';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.width = '100vw';
    overlay.style.height = '100vh';
    overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.4)';
    overlay.style.display = 'flex';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    overlay.style.zIndex = '9999';

    // Box contenitore
    const box = document.createElement('div');
    box.style.background = 'white';
    box.style.padding = '20px';
    box.style.borderRadius = '8px';
    box.style.boxShadow = '0 0 10px rgba(0,0,0,0.3)';
    box.style.display = 'flex';
    box.style.flexDirection = 'column';
    box.style.gap = '10px';
    box.style.minWidth = '200px';

    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentVal;
    input.style.fontSize = '16px';
    input.style.padding = '6px';
    input.style.borderRadius = '4px';
    input.style.border = '1px solid #ccc';

    // Bottone salva (icona)
    const btnSave = document.createElement('button');
    btnSave.className = 'button';
    const saveIcon = document.createElement('img');
    saveIcon.src = 'assets/icons/save.png';
    saveIcon.alt = 'Salva';
    saveIcon.style.width = '20px';
    saveIcon.style.height = '20px';
    btnSave.appendChild(saveIcon);

    // Bottone annulla (icona)
    const btnCancel = document.createElement('button');
    btnCancel.className = 'button';
    const cancelIcon = document.createElement('img');
    cancelIcon.src = 'assets/icons/close.png';
    cancelIcon.alt = 'Annulla';
    cancelIcon.style.width = '20px';
    cancelIcon.style.height = '20px';
    btnCancel.appendChild(cancelIcon);

btnSave.addEventListener('click', () => {
    const nuovo = input.value.trim();
    const floor = document.querySelector('.floor-tab.active')?.dataset.floor;
    if (!floor) {
        showToast('Errore: piano non selezionato!', 'error');
        overlay.remove();
        return;
    }

    // Controllo validità nuovo interno
    if (nuovo === '') {
        showToast('Il campo interno non può essere vuoto.', 'error');
        overlay.remove();
        return;
    }

    // Blocco se non cambia nulla
    if (nuovo === currentVal) {
        overlay.remove();
        return;
    }

    // Controllo unicità (case-insensitive, senza il corrente)
    const esiste = Array.from(document.querySelectorAll('.postazione-area')).some(area =>
        area !== element && area.dataset.interno?.toLowerCase() === nuovo.toLowerCase()
    );
    if (esiste) {
        showToast('Esiste già una postazione con questo interno!', 'error');
        return;
    }

    // Salva nel DB PRIMA, così eviti doppioni e desincronizzazioni
    savePostazione(
        floor,
        nuovo,
        parseFloat(element.style.left),
        parseFloat(element.style.top),
        parseFloat(element.style.width),
        parseFloat(element.style.height),
        currentVal,
        element.dataset.id ? parseInt(element.dataset.id) : null
    );

    // Dopo il salvataggio aggiorna tutto il piano dal DB
    setTimeout(() => {
        loadPostazioni(floor);
        loadUserPositions(floor); // opzionale: aggiorna anche le icone
    }, 300);

    overlay.remove();

});

    btnCancel.addEventListener('click', () => overlay.remove());

    // Montaggio finale
    const buttons = document.createElement('div');
    buttons.style.display = 'flex';
    buttons.style.justifyContent = 'space-between';
    buttons.appendChild(btnSave);
    buttons.appendChild(btnCancel);


    box.appendChild(input);
    box.appendChild(buttons);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
}

    // Imposta il piano predefinito come attivo
    document.querySelector(`.floor-tab[data-floor="${defaultFloor}"]`)?.classList.add('active');

    // Imposta le variabili globali per altre funzioni
    window.config = {
        imageMap,
        personnelContainer
    };
    
    // Bottoni strumenti
    const selectBtn = document.getElementById('tool-select');
    const lockAreasBtn = document.getElementById('tool-lock-areas');
    const lockContactsBtn = document.getElementById('tool-lock-contacts');

    // Imposta le icone corrette in base allo stato iniziale
    selectBtn.querySelector('img').src = window.toolStates.select ? 'assets/icons/select_unlock.png' : 'assets/icons/select_lock.png';
    lockAreasBtn.querySelector('img').src = window.toolStates.lockAreas ? 'assets/icons/area_unlock.png' : 'assets/icons/area_lock.png';
    lockContactsBtn.querySelector('img').src = window.toolStates.lockContacts ? 'assets/icons/icon_unlock.png' : 'assets/icons/icon_lock.png';

    function toggleTool(toolKey, btnElement, iconPaths) {
        window.toolStates[toolKey] = !window.toolStates[toolKey];
        const imgPath = window.toolStates[toolKey] ? iconPaths.active : iconPaths.inactive;
        btnElement.querySelector('img').src = imgPath;
    }
    
    selectBtn.addEventListener('click', () => {
        toggleTool('select', selectBtn, {
            active: 'assets/icons/select_unlock.png',
            inactive: 'assets/icons/select_lock.png'
        });
    
        mapContainer.classList.toggle('map-select-mode', window.toolStates.select);
        console.log('Strumento selezione multipla:', window.toolStates.select ? 'attivo' : 'disattivo');
        // qui in futuro potrai attivare la logica di selezione multipla
    });
    
    lockAreasBtn.addEventListener('click', () => {
        toggleTool('lockAreas', lockAreasBtn, {
            active: 'assets/icons/area_unlock.png',
            inactive: 'assets/icons/area_lock.png'
        });
    
        const allAreas = document.querySelectorAll('.postazione-area');
        allAreas.forEach(area => {
            area.style.pointerEvents = window.toolStates.lockAreas ? 'auto' : 'none';
            area.style.opacity = window.toolStates.lockAreas ? '1' : '0.6';
        });
    
        console.log('Blocco aree:', window.toolStates.lockAreas ? 'sbloccate' : 'bloccate');
    });
    
    lockContactsBtn.addEventListener('click', () => {
        toggleTool('lockContacts', lockContactsBtn, {
            active: 'assets/icons/icon_unlock.png',
            inactive: 'assets/icons/icon_lock.png'
        });
    
        const allIcons = document.querySelectorAll('.personnel-icon');
        allIcons.forEach(icon => {
            icon.style.pointerEvents = window.toolStates.lockContacts ? 'auto' : 'none';
            icon.style.opacity = window.toolStates.lockContacts ? '1' : '0.6';
        });
    
        console.log('Blocco contatti:', window.toolStates.lockContacts ? 'sbloccati' : 'bloccati');
    });
    
    // Imposta il piano di default
    planImage.src = imageMap[defaultFloor];
    loadFloorData(defaultFloor);

    // Rimuovi eventuali menu contestuali rimasti
    document.getElementById('unified-context-menu')?.remove();
    
    // Carica i contatti disponibili nella sidebar
    loadAvailableContacts();
    
    // Event listener per i pulsanti del cambio piano
    floorTabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const selectedFloor = this.getAttribute('data-floor');

            // Cambia l'immagine della mappa
            planImage.src = imageMap[selectedFloor];

            // Aggiorna lo stato attivo dei pulsanti
            floorTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            // Carica i dati del piano selezionato
            loadFloorData(selectedFloor);

            // Rimuovi eventuali menu contestuali rimasti
            document.getElementById('unified-context-menu')?.remove();
        });
    });

    // Funzione principale per caricare le posizioni e le postazioni del piano
    function loadFloorData(floor) {
        clearPreviousElements();
        planImage.src = imageMap[floor];
        loadUserPositions(floor);
        loadPostazioni(floor);
    }

    // Pulisce gli elementi precedenti dal contenitore
    function clearPreviousElements() {
        personnelContainer.innerHTML = '';
    }

    // Carica le posizioni degli utenti
    function loadUserPositions(floor) {
        // RIMUOVI TUTTE LE ICONE prima di caricare!
        document.querySelectorAll('.personnel-icon').forEach(icon => icon.remove());

        customFetch('office_map', 'get_positions', { floor })
            .then(data => {
                if (!data.success) {
                    console.warn(' Errore nel caricamento posizioni:', data.message || data.error);
                    return;
                }

                const positions = data.positions || [];
                positions.forEach(person => {
                    const icon = createIcon(person);
                    personnelContainer.appendChild(icon);
                });
            })
            .catch(error => console.error('Errore durante il caricamento delle posizioni:', error));
    }

    // Carica le postazioni
    function loadPostazioni(floor) {
        // RIMUOVI TUTTE LE AREE prima di caricare!
        document.querySelectorAll('.postazione-area').forEach(area => area.remove());

        customFetch('office_map', 'get_postazioni', { floor })
            .then(data => {
                if (!data.success || !Array.isArray(data.postazioni)) {
                    console.warn(' Nessuna postazione valida caricata.', data);
                    return;
                }

                data.postazioni.forEach(postazione => {
                    const area = createPostazione(postazione, personnelContainer);
                    personnelContainer.appendChild(area);
                });
            })
            .catch(error => console.error('Errore durante il caricamento delle postazioni:', error));
    }

    // Crea un'icona per un utente
    function createIcon(person) {
        const icon = document.createElement('div');
        icon.className = 'personnel-icon';
        icon.style.left = `${person.x_position}%`;
        icon.style.top = `${person.y_position}%`;

        const imgUrl = person.profile_image && typeof person.profile_image === 'string'
        ? person.profile_image
        : '/assets/images/default_profile.png';
        icon.style.backgroundImage = `url(${imgUrl})`;
        
        const nameLabel = document.createElement('span');
        nameLabel.className = 'name-label';
        nameLabel.textContent = person.nominativo || 'N/A';
        icon.appendChild(nameLabel);

        icon.title = `${person.nominativo || 'N/A'} - Interno: ${person.interno || 'N/A'}`;
        icon.dataset.userId = person.user_id;

        makeDraggable(icon, 'personnel');

        return icon;
    }
});

    // Crea una postazione
    function createPostazione(postazione, personnelContainer) {
    // Rimuovi eventuali menu contestuali rimasti prima di creare una nuova area
    document.getElementById('unified-context-menu')?.remove();

        const area = document.createElement('div');
        area.className = 'postazione-area';
        area.dataset.id = postazione.id;
        // Se è una nuova area (es. interna non valido), posizionala lateralmente
        const x = (postazione.x_position === null || isNaN(postazione.x_position)) ? 5 : postazione.x_position;
        const y = (postazione.y_position === null || isNaN(postazione.y_position)) ? 10 : postazione.y_position;
    
        area.style.left = `${x}%`;
        area.style.top = `${y}%`;
        area.style.width = `${postazione.width || 10}%`;
        area.style.height = `${postazione.height || 10}%`;
        area.dataset.interno = postazione.interno;
        area.title = `Interno: ${postazione.interno}`;
    
        const internoLabel = document.createElement('span');
        internoLabel.className = 'interno-label';
        internoLabel.textContent = postazione.interno;
        area.appendChild(internoLabel);
    
        ['nw', 'ne', 'se', 'sw'].forEach(handleClass => {
            const handle = document.createElement('div');
            handle.className = `resize-handle ${handleClass}`;
            area.appendChild(handle);
        });
    
        makeDraggable(area, 'postazione');
        makeResizable(area, personnelContainer);
        return area;
    }    

    // Rende un elemento draggable
    function makeDraggable(element, type) {
        const personnelContainer = window.config.personnelContainer;
    element.addEventListener('mousedown', function (e) {
        if (window.toolStates.select && window.selectedElements.length > 0) {
            window.selectedElements.forEach(el => {
                el.dataset.initialLeft = parseFloat(el.style.left) || 0;
                el.dataset.initialTop = parseFloat(el.style.top) || 0;
            });
        }

        if (e.button !== 0) return;

        // Se tool selezione attivo e l'elemento non è selezionato, non far partire il drag
        if (window.toolStates.select && !element.classList.contains('selected')) return;

        if (!window.toolStates.select && window.toolStates.lockContacts && type === 'personnel') return;
        if (!window.toolStates.select && window.toolStates.lockAreas && type === 'postazione') return;

        e.preventDefault();

        const bounds = personnelContainer.getBoundingClientRect();
        const startX = e.clientX;
        const startY = e.clientY;
        const initialLeft = parseFloat(element.style.left) || 0;
        const initialTop = parseFloat(element.style.top) || 0;

        function moveAt(event) {
            const dx = ((event.clientX - startX) / bounds.width) * 100;
            const dy = ((event.clientY - startY) / bounds.height) * 100;

            element.style.left = `${Math.max(0, Math.min(initialLeft + dx, 100))}%`;
            element.style.top = `${Math.max(0, Math.min(initialTop + dy, 100))}%`;
            if (window.toolStates.select && window.selectedElements.length > 0 && selectedElements.includes(element)) {
                window.selectedElements.forEach(el => {
                    const il = parseFloat(el.dataset.initialLeft) || 0;
                    const it = parseFloat(el.dataset.initialTop) || 0;

                    el.style.left = `${Math.max(0, Math.min(il + dx, 100))}%`;
                    el.style.top = `${Math.max(0, Math.min(it + dy, 100))}%`;
                });
            } else {
                element.style.left = `${Math.max(0, Math.min(initialLeft + dx, 100))}%`;
                element.style.top = `${Math.max(0, Math.min(initialTop + dy, 100))}%`;
            }

            if (type === 'personnel') {
                checkCollision(element);
            }
        }

        function stopDrag() {
            document.removeEventListener('mousemove', moveAt);
            document.removeEventListener('mouseup', stopDrag);
            
            // Selezione multipla: rimuove evidenziazione al termine
            if (!window.toolStates.select && window.selectedElements.length > 0) {
                window.selectedElements.forEach(icon => icon.classList.remove('selected'));
                window.selectedElements = [];
            }

            // Verifica che esista un pulsante attivo
            const activeTab = document.querySelector('.floor-tab.active');
            if (!activeTab) {
                console.error('Nessun piano attivo trovato.');
                return; // Esce dalla funzione se non esiste un piano attivo
            }

            const floor = activeTab.getAttribute('data-floor');

            if (window.toolStates.select && selectedElements.length > 1 && selectedElements.includes(element)) {
                selectedElements.forEach(icon => {
                    savePosition(
                        icon.dataset.userId,
                        floor,
                        parseFloat(icon.style.left),
                        parseFloat(icon.style.top),
                        icon.dataset.interno || null
                    );
                });
            } else if (type === 'personnel') {
                savePosition(
                    element.dataset.userId,
                    floor,
                    parseFloat(element.style.left),
                    parseFloat(element.style.top),
                    element.dataset.interno || null
                );
            } else if (type === 'postazione') {
                element.dataset.oldInterno = element.dataset.interno;
                const oldInterno = element.dataset.oldInterno || element.dataset.interno;

                savePostazione(
                    floor,
                    element.dataset.interno,
                    parseFloat(element.style.left),
                    parseFloat(element.style.top),
                    parseFloat(element.style.width),
                    parseFloat(element.style.height),
                    oldInterno
                );                         
            }
        }

        document.addEventListener('mousemove', moveAt, { passive: false });
        document.addEventListener('mouseup', stopDrag, { once: true });   
    });
}

    // Cambia il piano di un utente
    function changeFloor(userId, newFloor) {
        customFetch('office_map', 'change_floor', {
            user_id: userId,
            new_floor: newFloor
        })
        .then(data => {
            if (data.success) {
                console.log('Contatto spostato con successo!');
                document.querySelector(`.floor-tab[data-floor="${newFloor}"]`).click();
            } else {
                console.error('Errore durante il cambio piano:', data.error);
            }
        })
        .catch(error => console.error('Errore durante il cambio piano:', error));
    }
    
    // Verifica collisioni tra utenti e postazioni
    function checkCollision(icon) {
        const iconRect = icon.getBoundingClientRect();
        const postazioni = document.querySelectorAll('.postazione-area');
        let assignedInterno = null;

        postazioni.forEach(postazione => {
            const postazioneRect = postazione.getBoundingClientRect();
            if (
                iconRect.left < postazioneRect.right &&
                iconRect.right > postazioneRect.left &&
                iconRect.top < postazioneRect.bottom &&
                iconRect.bottom > postazioneRect.top
            ) {
                assignedInterno = postazione.dataset.interno;
            }
        });

        icon.dataset.interno = assignedInterno || null;

        // Il titolo mostra nominativo + interno se presente
        const nominativo = icon.querySelector('.name-label') ? icon.querySelector('.name-label').textContent : '';
        icon.title = assignedInterno
            ? `${nominativo} - Interno: ${assignedInterno}`
            : `${nominativo} - Nessun interno assegnato`;
    }

    // Salva la posizione di un utente
    function savePosition(userId, floor, x, y, interno) {
        customFetch('office_map', 'save_position', {
            user_id: userId,
            floor,
            x_position: x,
            y_position: y,
            interno: interno || null
        })
        .then(data => {
            if (data.success) {
                console.log('Posizione utente salvata e utente riattivato.');
                loadAvailableContacts();
            } else {
                throw new Error(data.error || 'Errore salvataggio');
            }
        })
        .catch(error => {
            console.error('Errore durante il salvataggio della posizione:', error);
        });
    }

    // Salva una postazione
    function savePostazione(floor, interno, x, y, width, height, oldInterno = null) {
        customFetch('office_map', 'save_postazione', {
            floor,
            interno,
            x_position: x,
            y_position: y,
            width,
            height,
            old_interno: oldInterno
        })        
        .then(data => {
            if (data.success) {
                console.log('Postazione salvata con successo.');
            } else {
                console.error('Errore durante il salvataggio della postazione:', data.error);
            }
        })
        .catch(error => console.error('Errore durante il salvataggio della postazione:', error));
    }
    
    // Rende una postazione ridimensionabile
    function makeResizable(area) {
        const resizeHandles = area.querySelectorAll('.resize-handle');
        const personnelContainer = window.config.personnelContainer; // Recupera il contenitore dal contesto globale

        resizeHandles.forEach(handle => {
            handle.addEventListener('mousedown', function (e) {
                e.preventDefault();

                const startX = e.clientX;
                const startY = e.clientY;
                const bounds = personnelContainer.getBoundingClientRect();
                const initialWidth = parseFloat(area.style.width) || 0;
                const initialHeight = parseFloat(area.style.height) || 0;
                const initialLeft = parseFloat(area.style.left) || 0;
                const initialTop = parseFloat(area.style.top) || 0;

                const direction = handle.classList.contains('nw') ? 'nw' :
                                  handle.classList.contains('ne') ? 'ne' :
                                  handle.classList.contains('se') ? 'se' : 'sw';

                function resize(event) {
                    let dx = (event.clientX - startX) / bounds.width * 100;
                    let dy = (event.clientY - startY) / bounds.height * 100;

                    let newWidth = initialWidth;
                    let newHeight = initialHeight;
                    let newLeft = initialLeft;
                    let newTop = initialTop;

                    switch (direction) {
                        case 'nw':
                            newWidth = Math.max(4, initialWidth - dx);
                            newLeft = Math.max(0, initialLeft + dx);
                            newHeight = Math.max(4, initialHeight - dy);
                            newTop = Math.max(0, initialTop + dy);
                            break;
                        case 'ne':
                            newWidth = Math.max(4, initialWidth + dx);
                            newHeight = Math.max(4, initialHeight - dy);
                            newTop = Math.max(0, initialTop + dy);
                            break;
                        case 'sw':
                            newWidth = Math.max(4, initialWidth - dx);
                            newLeft = Math.max(0, initialLeft + dx);
                            newHeight = Math.max(4, initialHeight + dy);
                            break;
                        case 'se':
                            newWidth = Math.max(4, initialWidth + dx);
                            newHeight = Math.max(4, initialHeight + dy);
                            break;
                    }
                    
                    // Applica le nuove dimensioni e posizione
                    area.style.width = `${newWidth}%`;
                    area.style.height = `${newHeight}%`;
                    area.style.left = `${newLeft}%`;
                    area.style.top = `${newTop}%`;
                }

                function stopResize() {
                    document.removeEventListener('mousemove', resize);
                    document.removeEventListener('mouseup', stopResize);

                    const floor = document.querySelector('.floor-tab.active').getAttribute('data-floor');
                    savePostazione(
                        floor,
                        area.dataset.interno,
                        parseFloat(area.style.left),
                        parseFloat(area.style.top),
                        parseFloat(area.style.width),
                        parseFloat(area.style.height),
                        area.dataset.oldInterno || null,
                        area.dataset.id ? parseInt(area.dataset.id) : null
                    );
                }

                document.addEventListener('mousemove', resize);
                document.addEventListener('mouseup', stopResize);
            });
        });
    }

    // Funzione per caricare i contatti disponibili nella sidebar
    function loadAvailableContacts(showAll = false) {
        customFetch('office_map', 'get_available_contacts', { all: showAll ? 1 : 0 })
            .then(data => {
                if (data.success) {
                    const contacts = data.contacts || [];
                    populateSidebar(contacts, showAll);
                } else {
                    console.error('Errore nel caricamento dei contatti:', data.error);
                }
            })
            .catch(error => {
                console.error('Errore durante il caricamento dei contatti:', error);
            });
    }
    
    // Funzione per popolare la sidebar con le card dei contatti
    function populateSidebar(contacts, includeArchived = false) {
        const contactList = document.getElementById('contact-list');
        contactList.innerHTML = ''; // Pulisce la lista esistente

        contacts.forEach(contact => {
            const contactCard = createContactCard(contact, includeArchived);
            contactList.appendChild(contactCard);
        });        
    }

// Funzione per creare una singola card per ogni contatto
function createContactCard(contact, includeArchived = false) {
    const card = document.createElement('div');
    card.className = 'contact-card-sidebar';
    card.dataset.userId = contact.user_id;

    if (includeArchived && contact.attivo === 0) {
        card.classList.add('archiviato');
    }

    // Crea l'elemento immagine
    const img = document.createElement('img');
    img.alt = contact.Nominativo;
    img.src = contact.profile_image || '/assets/images/default_profile.png';

    const name = document.createElement('span');
    name.className = 'contact-name';
    name.textContent = contact.Nominativo;

    card.appendChild(img);
    card.appendChild(name);

    // Abilita il drag della card
    card.setAttribute('draggable', true);
    card.addEventListener('dragstart', handleDragStart);
    card.addEventListener('dragend', handleDragEnd);  // Gestisce il termine del drag

    return card;
}

// Funzione per gestire l'inizio del drag
function handleDragStart(event) {
    let draggedElement = event.target.closest('.contact-card-sidebar');
    if (!draggedElement) {
        console.warn(' DragStart fallito: nessuna card valida.');
        return;
    }

    draggedElement.classList.add('dragging');

    const profileImg = draggedElement.querySelector('img');
    if (!profileImg || !profileImg.src) {
        console.warn(' DragStart: immagine non valida.');
        return;
    }

    const clonedIcon = document.createElement('div');
    clonedIcon.style.width = '40px';
    clonedIcon.style.height = '40px';
    clonedIcon.style.borderRadius = '50%';
    clonedIcon.style.backgroundImage = `url(${profileImg.src})`;
    clonedIcon.style.backgroundSize = 'cover';
    clonedIcon.style.backgroundPosition = 'center';
    clonedIcon.style.position = 'absolute';
    clonedIcon.style.top = '-1000px';
    clonedIcon.style.left = '-1000px';
    document.body.appendChild(clonedIcon);

    try {
        event.dataTransfer.setDragImage(clonedIcon, 20, 20);
    } catch (err) {
        console.warn(' Impossibile settare dragImage:', err);
    }

    setTimeout(() => clonedIcon.remove(), 0);
}

// Funzione per gestire il termine del drag
function handleDragEnd(event) {
    const draggedElement = event.target;
    draggedElement.style.width = '';
    draggedElement.style.height = '';
    draggedElement.style.borderRadius = '';
    draggedElement.style.objectFit = '';
    draggedElement.classList.remove('dragging');
}

