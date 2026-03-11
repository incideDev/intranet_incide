document.addEventListener('DOMContentLoaded', function () {
    if (window.officeMapPublicLoaded) return;
    window.officeMapPublicLoaded = true;
    
    const sidebarCard = document.getElementById('sidebar-user-card');
    const userFullname = document.getElementById('user-fullname');
    const userRole = document.getElementById('user-role');
    const userInterno = document.getElementById('user-interno');
    const userEmail = document.getElementById('user-email');
    const userTelefono = document.getElementById('user-telefono');
    const userAvatar = document.querySelector('#sidebar-user-card .user-avatar');

    const personnelContainer = document.getElementById('personnel-container');
    const planImage = document.getElementById('plan-image');
    const floorTabs = document.querySelectorAll('.floor-tab');
    const imageMap = {
        'Plan_PT': 'assets/plan/Planimetrie Ufficio Padova-PT.png',
        'Plan_P3': 'assets/plan/Planimetrie Ufficio Padova-P3.png'
    };

    // Piano di default
    const defaultFloor = 'Plan_PT';

    // Aggiungi evento click ai pulsanti per cambiare piano
    floorTabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const selectedFloor = this.getAttribute('data-floor');

            // Cambia l'immagine della mappa
            planImage.src = imageMap[selectedFloor];

            // Rimuovi la classe "active" da tutti i tab e aggiungila al tab selezionato
            floorTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            // Carica i dati del piano selezionato
            loadFloorData(selectedFloor);
        });
    });

    // Funzione per caricare i dati del piano selezionato
    function loadFloorData(floor) {
        personnelContainer.innerHTML = ''; // Resetta le icone

        customFetch('office_map', 'get_positions', { floor })
            .then(data => {
                if (!data.success) {
                    console.warn('⚠️ Errore nel caricamento posizioni pubbliche:', data.message || data.error);
                    return;
                }

                const positions = data.positions || [];
                if (positions.length === 0) {
                    console.warn(`⚠️ Nessuna posizione trovata per il piano ${floor}`);
                    return;
                }

                positions.forEach(person => {
                    const icon = document.createElement('div');
                    icon.className = 'personnel-icon';
                    icon.style.left = `${person.x_position}%`;
                    icon.style.top = `${person.y_position}%`;
                    icon.dataset.userId = person.user_id;
                    icon.title = `${person.nominativo || 'N/A'} - Interno: ${person.interno || 'N/A'}`;

                    const imgSrc = person.profile_image || '/assets/images/default_profile.png';
                    icon.style.backgroundImage = `url(${imgSrc})`;
                    icon.style.backgroundSize = 'cover';
                    icon.style.backgroundPosition = 'center';
                    icon.style.backgroundRepeat = 'no-repeat';

                    // Solo il primo nome per l'etichetta sulla mappa
                    let soloNome = (person.nominativo || 'N/A').split(' ')[0];

                    const nameLabel = document.createElement('span');
                    nameLabel.className = 'name-label';
                    nameLabel.textContent = soloNome;
                    icon.appendChild(nameLabel);

                    icon.addEventListener('click', () => {
                        sidebarCard.style.display = 'block';
                        userFullname.textContent = person.nominativo || 'N/A'; // Qui resta il nome completo
                        userRole.textContent = person.ruolo || '-';
                        userInterno.textContent = person.interno || '-';
                        userEmail.textContent = person.email_aziendale || '-';
                        userTelefono.textContent = person.cellulare_aziendale || '-';
                        userAvatar.src = imgSrc;
                    });

                    personnelContainer.appendChild(icon);
                });
            })
            .catch(error => {
                console.error('Errore durante il caricamento delle posizioni pubbliche:', error);
            });
    }

document.querySelector(`.floor-tab[data-floor="${defaultFloor}"]`)?.click();
});

let scale = 1;
let translateX = 0;
let translateY = 0;
const mapContainer = document.getElementById('map-container');
const mapWrapper = document.getElementById('map-inner-wrapper');

// Applica la trasformazione di scala e traslazione
function applyTransform() {
    const transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
    mapWrapper.style.transform = transform;
    mapWrapper.style.transformOrigin = 'top left';
}

// Zoom con la rotella
mapContainer.addEventListener('wheel', function (e) {
    e.preventDefault();
    const delta = e.deltaY > 0 ? -0.1 : 0.1;
    scale = Math.min(Math.max(0.5, scale + delta), 2); // Limita da 0.5x a 2x
    applyTransform();
});

let isDragging = false;
let startX, startY;

mapContainer.addEventListener('mousedown', function (e) {
    if (e.button !== 0) return;
    isDragging = true;
    startX = e.clientX;
    startY = e.clientY;
    mapContainer.classList.add('grabbing');
});

document.addEventListener('mousemove', function (e) {
    if (!isDragging) return;
    const dx = e.clientX - startX;
    const dy = e.clientY - startY;
    startX = e.clientX;
    startY = e.clientY;

    translateX += dx;
    translateY += dy;
    applyTransform();
});

document.addEventListener('mouseup', function () {
    isDragging = false;
    mapContainer.classList.remove('grabbing');
});
