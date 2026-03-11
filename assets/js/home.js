document.addEventListener("DOMContentLoaded", () => {

    // Funzione di sanificazione semplice
    function sanitize(str) {
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;");
    }

    // Cache DOM queries (evita query ripetute)
    const domCache = {
        newsContainer: document.getElementById("news-container"),
        communicationsContainer: document.getElementById("communications-container"),
        featuredSection: document.getElementById("featured-news-section"),
        adminOverlay: document.getElementById("featured-news-admin-overlay"),
        featuredContent: document.getElementById("featured-news-content"),
        calendarElement: document.querySelector('.calendar'),
        monthYearElement: document.getElementById('monthYear'),
        eventDetailsContainer: document.getElementById('eventDetailsContainer'),
        calendarContainer: document.getElementById('calendarContainer'),
        eventDetails: document.getElementById('eventDetails'),
        eventDateTitle: document.getElementById('eventDateTitle'),
        backToCalendar: document.getElementById('backToCalendar'),
        prevMonthButton: document.getElementById('prevMonth'),
        nextMonthButton: document.getElementById('nextMonth'),
        calendarWrapper: document.getElementById('calendarWrapper'),
        toggleButton: document.getElementById('toggleCalendarSize'),
        contextMenu: document.getElementById("featured-news-context-menu"),
        contextEdit: document.getElementById("context-edit-featured")
    };

    // Verifica elementi critici
    if (!domCache.newsContainer) {
        console.error(" Contenitore delle news non trovato!");
        return;
    }

    // Variabili globali per stato
    let featuredNewsData = null;
    let isAdminEditMode = false;
    let currentDate = new Date();
    let currentIsExpanded = false;
    let birthdaysCache = [];

    // Funzione helper per fetch birthdays (mantiene retrocompatibilità)
    async function fetchBirthdays() {
        if (birthdaysCache.length > 0) return birthdaysCache;
        try {
            const response = await customFetch('home', 'getAllBirthdays');
            birthdaysCache = response.success ? response.birthdays : [];
            return birthdaysCache;
        } catch (e) {
            console.warn(" Errore fetch compleanni:", e);
            return [];
        }
    }
    
    function renderCalendar(birthdays = [], date = new Date(), isExpanded = false) {
        if (!domCache.calendarElement || !domCache.monthYearElement) return;
        
        const year = date.getFullYear();
        const month = date.getMonth();
    
        const monthNames = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
        const dayNames = isExpanded
            ? ['Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica']
            : ['L', 'Ma', 'Me', 'G', 'V', 'S', 'D'];
    
        domCache.monthYearElement.textContent = `${monthNames[month]} ${year}`;
        
        // Usa DocumentFragment per batch insert
        const fragment = document.createDocumentFragment();
    
        dayNames.forEach(day => {
            const el = document.createElement('div');
            el.className = `calendar-day-name ${isExpanded ? 'expanded-day-name' : ''}`;
            el.textContent = day;
            fragment.appendChild(el);
        });
    
        const firstDay = (new Date(year, month, 1).getDay() || 7) - 1;
        const daysInMonth = new Date(year, month + 1, 0).getDate();
    
        const oggi = new Date();
        const isCurrentMonth = oggi.getFullYear() === year && oggi.getMonth() === month;
        const today = oggi.getDate();
    
        for (let i = 0; i < firstDay; i++) {
            const empty = document.createElement('div');
            empty.className = `calendar-day empty${isExpanded ? ' expanded-day' : ''}`;
            fragment.appendChild(empty);
        }
    
        for (let day = 1; day <= daysInMonth; day++) {
            const el = document.createElement('div');
            el.className = `calendar-day${isExpanded ? ' expanded-day' : ''}`;
            el.setAttribute('data-day', day);
            el.style.position = "relative"; // Serve per posizionare i figli
    
            // Ricerca compleanni per quel giorno
            const dateStr = `${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const todayBirthdays = birthdays.filter(b => b.Data_di_Nascita?.slice(5, 10) === dateStr);
    
            // Numero del giorno
            const dayNum = document.createElement('span');
            dayNum.textContent = day;
            dayNum.className = 'calendar-day-number';
            dayNum.style.position = 'absolute';
            dayNum.style.left = '50%';
            dayNum.style.top = '50%';
            dayNum.style.transform = 'translate(-50%, -50%)';
            dayNum.style.zIndex = '2';
            dayNum.style.pointerEvents = "none";
    
            // Evidenzia solo la cifra del giorno corrente
            if (isCurrentMonth && day === today) {
                dayNum.style.color = "rgba(var(--red-incide), 1)";
                dayNum.style.fontWeight = "bold";
            
                // Sfondo bianco soft se c’è compleanno
                if (todayBirthdays.length > 0) {
                    dayNum.style.background = "rgb(255 255 255 / 59%)";
                    dayNum.style.borderRadius = "10px";
                    dayNum.style.padding = "3px";

                } else {
                    dayNum.style.background = "transparent";
                    dayNum.style.padding = "0";
                    dayNum.style.boxShadow = "none";
                }
            } else {
                dayNum.style.color = "#fff";
                dayNum.style.fontWeight = "bold";

                dayNum.style.background = "transparent";
                dayNum.style.padding = "0";
                dayNum.style.boxShadow = "none";
            }
    
            el.appendChild(dayNum);
    
            // Aggiungi la/le icona/e compleanno sotto la cifra
            if (todayBirthdays.length > 0) {
                todayBirthdays.forEach(b => {
                    const pic = document.createElement('img');
                    pic.className = 'calendar-profile-icon';
                    pic.src = b.profile_picture.startsWith('data:') ? b.profile_picture : `/${b.profile_picture.replace(/^\/?/, '')}`;
                    pic.alt = b.username;
                    pic.onerror = () => {
                        if (!pic.classList.contains('error-applied')) {
                            pic.src = '/assets/images/default_profile.png';
                            pic.classList.add('error-applied');
                        }
                    };
                    // Posiziona l’icona esattamente sotto la cifra
                    pic.style.position = 'absolute';
                    pic.style.left = '50%';
                    pic.style.top = '50%';
                    pic.style.transform = 'translate(-50%, -50%)';
                    pic.style.zIndex = '1';
                    pic.style.width = '20px';
                    pic.style.height = '20px';
                    pic.style.opacity = '0.90';
                    el.appendChild(pic);
                });
    
                el.addEventListener('click', () => {
                    showEventDetails(dateStr, todayBirthdays);
                });
            }
    
            fragment.appendChild(el);
        }
        
        // Single DOM update
        domCache.calendarElement.innerHTML = "";
        domCache.calendarElement.appendChild(fragment);
    }
    
    function showEventDetails(dateStr, events = []) {
        if (!domCache.eventDateTitle || !domCache.eventDetails) return;
        
        domCache.eventDateTitle.textContent = `Eventi per il giorno: ${dateStr}`;
        
        // Usa DocumentFragment
        const fragment = document.createDocumentFragment();
    
        if (events.length > 0) {
            events.forEach(event => {
                const birthdayCard = document.createElement('div');
                birthdayCard.classList.add('birthday-card');

                const profileImg = document.createElement('img');
                profileImg.src = event.profile_picture.startsWith('data:') ? event.profile_picture : `/${event.profile_picture.replace(/^\/?/, '')}`;
                profileImg.alt = `${event.username} Profile Picture`;
                profileImg.classList.add('birthday-image');
                profileImg.onerror = function () {
                    this.src = '/assets/images/default_profile.png';
                };

                const info = document.createElement('div');
                info.classList.add('birthday-info');

                const nameEl = document.createElement('h4');
                nameEl.textContent = event.username;

                const birthDate = new Date(event.Data_di_Nascita);
                const textEl = document.createElement('p');
                textEl.textContent = `Compleanno il ${birthDate.toLocaleDateString('it-IT', { day: '2-digit', month: 'long' })}`;

                info.appendChild(nameEl);
                info.appendChild(textEl);
                birthdayCard.appendChild(profileImg);
                birthdayCard.appendChild(info);
                fragment.appendChild(birthdayCard);
            });
        } else {
            const emptyMsg = document.createElement('p');
            emptyMsg.textContent = 'Nessun evento per questa data.';
            fragment.appendChild(emptyMsg);
        }
    
        // Single DOM update
        domCache.eventDetails.innerHTML = '';
        domCache.eventDetails.appendChild(fragment);
        
        if (domCache.eventDetailsContainer) domCache.eventDetailsContainer.style.display = 'block';
        if (domCache.calendarContainer) domCache.calendarContainer.style.display = 'none';
    }
    
    // Rimuove hash # spuri
    if (window.location.hash === "#") {
        history.replaceState(null, "", window.location.pathname + window.location.search);
    }

    // Funzione per renderizzare news usando DocumentFragment (evita reflow multipli)
    function renderNews(data, container) {
        if (!container) return;
        
        // Filtra news in evidenza e immagini locali
        let filteredData = data || [];
        const featuredLink = featuredNewsData?.link;
        
        if (featuredLink) {
            filteredData = filteredData.filter(post => {
                const postLink = rtrim(post.link || '', '/');
                const currentLink = rtrim(featuredLink, '/');
                return postLink !== currentLink;
            });
        }

        // Filtra immagini locali (non news vere)
        filteredData = filteredData.filter(post => {
            const link = post.link || '';
            const image = post.image || '';
            const hasExternalLink = link && (link.startsWith('http://') || link.startsWith('https://'));
            const isLocalFeaturedImage = image.includes('uploads/featured_news/') || image.includes('featured_news_');
            return hasExternalLink && !isLocalFeaturedImage;
        });

        // Usa DocumentFragment per batch insert (evita reflow multipli)
        const fragment = document.createDocumentFragment();
        
        if (Array.isArray(filteredData) && filteredData.length > 0) {
            filteredData.forEach(post => {
                const imageUrl = post.image || post.jetpack_featured_media_url || 'assets/images/default-thumbnail.jpg';
                const title = (post.title?.rendered || post.title || 'Senza titolo').toString();
                const link = post.link || '#';

                const newsItem = document.createElement("div");
                newsItem.className = "news-item";
                newsItem.innerHTML = `
                    <div class="image-container">
                        <img src="${sanitize(imageUrl)}" alt="${sanitize(title)}" class="post-image">
                    </div>
                    <div class="post-content">
                        <h3>${sanitize(title)}</h3>
                    </div>
                    <div>
                        <a href="${sanitize(link)}" class="news-link" target="_blank" rel="noopener noreferrer" data-tooltip="Apri l'articolo in una nuova scheda">Leggi di più</a>
                    </div>
                `;
                fragment.appendChild(newsItem);
            });
        } else {
            const emptyMsg = document.createElement("p");
            emptyMsg.textContent = "Nessun articolo disponibile al momento.";
            fragment.appendChild(emptyMsg);
        }
        
        // Single DOM update (evita reflow multipli)
        container.innerHTML = "";
        container.appendChild(fragment);
    }

    // Helper per rtrim (compatibilità)
    function rtrim(str, char) {
        return String(str).replace(new RegExp(char + '+$'), '');
    }

    // Caricamento unificato dati home (riduce da 4+ a 1 chiamata AJAX)
    customFetch('home', 'getHomeData')
        .then(res => {
            if (!res.success) {
                throw new Error('Errore caricamento dati home');
            }

            // Featured news
            if (res.featuredNews) {
                featuredNewsData = res.featuredNews;
                renderFeaturedNews(res.featuredNews);
            } else {
                renderFeaturedNews(null);
            }

            // Cached news (già filtrate dal backend)
            renderNews(res.cachedNews || [], domCache.newsContainer);

            // Newsletter index
            const newsletterData = res.newsletterIndex || [];
            if (Array.isArray(newsletterData) && newsletterData.length > 0) {
                const fragment = document.createDocumentFragment();
                newsletterData.forEach(newsletter => {
                    fragment.appendChild(createNewsletterCommunication(newsletter));
                });
                domCache.communicationsContainer.innerHTML = "";
                domCache.communicationsContainer.appendChild(fragment);
            } else {
                domCache.communicationsContainer.innerHTML = `<p class="communication-message">Nessuna comunicazione recente.</p>`;
            }

            // Birthdays (per calendario)
            birthdaysCache = res.birthdays || [];
            if (domCache.calendarElement) {
                renderCalendar(birthdaysCache, currentDate, currentIsExpanded);
            }
        })
        .catch(error => {
            console.warn(" Errore caricamento dati home:", error);
            domCache.newsContainer.innerHTML = "<p>News temporaneamente non disponibili.</p>";
            if (domCache.communicationsContainer) {
                domCache.communicationsContainer.innerHTML = `<p class="communication-message">Errore nel caricamento delle comunicazioni.</p>`;
            }
            renderFeaturedNews(null);
        });
    
    // Setup calendario (solo se elementi esistono)
    if (domCache.backToCalendar) {
        domCache.backToCalendar.addEventListener('click', () => {
            if (domCache.eventDetailsContainer) domCache.eventDetailsContainer.style.display = 'none';
            if (domCache.calendarContainer) domCache.calendarContainer.style.display = 'grid';
        });
    }

    // Chiudi calendario espanso cliccando fuori
    document.addEventListener('click', (event) => {
        if (
            domCache.calendarWrapper &&
            domCache.calendarWrapper.classList.contains('expanded') &&
            !domCache.calendarWrapper.contains(event.target) &&
            domCache.toggleButton &&
            !domCache.toggleButton.contains(event.target)
        ) {
            domCache.calendarWrapper.classList.remove('expanded');
            document.body.classList.remove('calendar-expanded');
        }
    }, true);

    // Setup navigazione calendario (lazy: solo dopo caricamento birthdays)
    function setupCalendarNavigation() {
        if (!domCache.calendarElement) return;

        // Nav mese successivo
        if (domCache.nextMonthButton) {
            domCache.nextMonthButton.addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() + 1);
                renderCalendar(birthdaysCache, currentDate, currentIsExpanded);
            });
        }

        // Nav mese precedente
        if (domCache.prevMonthButton) {
            domCache.prevMonthButton.addEventListener('click', () => {
                currentDate.setMonth(currentDate.getMonth() - 1);
                renderCalendar(birthdaysCache, currentDate, currentIsExpanded);
            });
        }

        // Espandi / Riduci calendario
        if (domCache.toggleButton) {
            domCache.toggleButton.addEventListener('click', () => {
                if (typeof openModalCalendar === 'function') {
                    openModalCalendar();
                } else {
                    console.warn(" openModalCalendar non definita");
                }
            });
        }
    }

    // Setup calendario dopo caricamento dati
    if (domCache.calendarElement && birthdaysCache.length > 0) {
        setupCalendarNavigation();
    } else if (domCache.calendarElement) {
        // Se birthdays non ancora caricati, setup dopo
        fetchBirthdays().then(() => {
            if (domCache.calendarElement) {
                renderCalendar(birthdaysCache, currentDate, currentIsExpanded);
                setupCalendarNavigation();
            }
        });
    }

// Modale Newsletter: apertura
function openNewsletterModal(path) {
    if (!path || typeof path !== 'string') return;

    const modal = document.getElementById("newsletterModal");
    const frame = document.getElementById("newsletterFrame");

    if (!modal || !frame) return;

    const safePath = encodeURI(path) + "?nocache=" + Date.now();
    frame.src = safePath;
    modal.style.display = "block";

    frame.onload = function () {
        try {
            const doc = frame.contentDocument || frame.contentWindow.document;
            const style = doc.createElement("style");
            style.textContent = `
                td.mcnImageContent > img.mcnImage {
                    max-width: 100px !important;
                    height: auto !important;
                    width: auto !important;
                    display: block !important;
                    margin-left: auto !important;
                    margin-right: 0 !important;
                }
            `;
            doc.head.appendChild(style);
        } catch (e) {
            console.error("Errore nel tentativo di applicare stile forzato alla newsletter:", e);
        }
    };    
}

// Chiudi il modale cliccando fuori dal contenuto
document.addEventListener("click", function (e) {
    const modal = document.getElementById("newsletterModal");
    const modalContent = modal?.querySelector(".modal-content");

    if (
        modal?.style.display === "block" &&
        modalContent &&
        !modalContent.contains(e.target) &&
        !e.target.closest(".communication-link")
    ) {
        closeNewsletterModal();
    }
}, true);

// Chiusura modale newsletter
function closeNewsletterModal() {
    const modal = document.getElementById("newsletterModal");
    const frame = document.getElementById("newsletterFrame");

    if (!modal || !frame) return;

    frame.src = "";
    modal.style.display = "none";
}

// Crea card comunicazione newsletter
function createNewsletterCommunication(newsletter = {}) {
    const card = document.createElement("div");
    card.className = "communication-card";

    const img = document.createElement("img");
    img.src = sanitize(newsletter.img || "assets/images/default-thumbnail.jpg");
    img.className = "communication-image";
    img.alt = sanitize(newsletter.title || "Newsletter");

    const content = document.createElement("div");
    content.className = "communication-content";

    const title = document.createElement("h3");
    title.textContent = sanitize(newsletter.title || "Senza titolo");

    const date = document.createElement("p");
    date.className = "communication-date";
    date.textContent = sanitize(newsletter.date || "");

    const link = document.createElement("a");
    link.href = "#";
    link.className = "communication-link";
    link.textContent = "Leggi";

    link.addEventListener("click", e => {
        e.preventDefault();
        if (newsletter.file) {
            openNewsletterModal(sanitize(newsletter.file));
        }
    });

    content.appendChild(title);
    content.appendChild(date);
    content.appendChild(link);

    card.appendChild(img);
    card.appendChild(content);

    return card;
}

// Menu contestuale newsletter
let newsletterCardTarget = null;

function openNewsletterContextMenu(event) {
    event.preventDefault();

    const menu = document.getElementById("newsletter-context-menu");
    if (!menu) return;

    const card = event.target.closest(".communication-card");
    newsletterCardTarget = card;

    // "Elimina" visibile solo se click su una card specifica
    const deleteItem = document.getElementById("context-delete");
    if (deleteItem) {
        deleteItem.style.display = card ? "" : "none";
    }

    menu.style.top = `${event.pageY}px`;
    menu.style.left = `${event.pageX}px`;
    menu.style.display = "block";

    // Chiudi menu se clic fuori
    document.addEventListener("click", () => {
        menu.style.display = "none";
    }, { once: true });
}

window.openNewsletterContextMenu = openNewsletterContextMenu;

const uploadItem = document.getElementById("context-upload");
const deleteItem = document.getElementById("context-delete");

if (uploadItem) {
    uploadItem.addEventListener("click", () => {
        document.getElementById("newsletter-context-menu").style.display = "none";
        enableNewsletterDropzone();
    });
}

if (deleteItem) {
    deleteItem.addEventListener("click", () => {
        if (!newsletterCardTarget) return;
        const titleEl = newsletterCardTarget.querySelector("h3");
        const title = titleEl?.textContent?.trim();
        if (!title) {
            showToast("Titolo newsletter non trovato.", "error");
            return;
        }
        
        showConfirm(`Vuoi eliminare la newsletter "${title}"?`, function () {
            customFetch("home", "deleteNewsletter", { title })
                .then(res => {
                    if (res.success) {
                        newsletterCardTarget.remove();
                        showToast("Newsletter eliminata", "success");
                    } else {
                        showToast("Errore eliminazione: " + (res.message || "Errore sconosciuto"), "error");
                    }
                });
        });        
    });
}

// Attiva area drag & drop newsletter
function enableNewsletterDropzone() {
    const container = document.getElementById("communications-container");
    if (!container) return;

    newsletterDropActive = true;

    const dropContent = document.createElement("div");
    dropContent.className = "dropzone-active";
    dropContent.innerHTML = `
        Trascina qui la tua newsletter HTML oppure 
        <span role="button" tabindex="0" onclick="document.getElementById('newsletterFileInput')?.click()">clicca qui</span> per caricarla.
    `;

    container.innerHTML = "";
    container.appendChild(dropContent);
    container.classList.add("dropzone-style");

    // Attiva gestione drag
    setupDropListener();
}

let isUploading = false;

// Setup gestione drag & drop e input file
function setupDropListener() {
    const container = document.getElementById("communications-container");
    const fileInput = document.getElementById("newsletterFileInput");

    if (!container || !fileInput) return;

    // Blocca duplicazione listener
    if (container._dropListenerAttached) return;
    container._dropListenerAttached = true;

    container.addEventListener("dragover", (e) => {
        e.preventDefault();
        container.classList.add("drag-over");
    });

    container.addEventListener("dragleave", () => {
        container.classList.remove("drag-over");
    });

    container.addEventListener("drop", (e) => {
        e.preventDefault();
        container.classList.remove("drag-over");

        const files = e.dataTransfer?.files;
        if (files && files.length > 0) {
            handleNewsletterFiles(files);
        }
    });

    // Gestione file tramite <input type="file">
    if (!fileInput._newsletterHandlerAttached) {
        fileInput._newsletterHandler = (e) => {
            const files = e.target?.files;
            if (files && files.length > 0) {
                handleNewsletterFiles(files);
            }
        };
        fileInput.addEventListener("change", fileInput._newsletterHandler);
        fileInput._newsletterHandlerAttached = true;
    }
}

    async function handleNewsletterFiles(files) {
        if (isUploading || !files || files.length === 0) return;

        const file = files[0];
        if (!file.name.toLowerCase().endsWith(".html")) {
            showToast("Carica solo file HTML.", "error");
            return;
        }

        isUploading = true;

        try {
            // Step 1: upload file via customFetch (FormData)
            const uploadForm = new FormData();
            uploadForm.append("file", file);
            const uploadRes = await customFetch("home", "uploadNewsletter", uploadForm);

            if (!uploadRes.success || !uploadRes.filename) {
                throw new Error(uploadRes.message || uploadRes.error || "Upload fallito");
            }

            // Step 2: aggiorna indice newsletter
            const indexRes = await customFetch("home", "updateNewsletterIndex", {
                filename: uploadRes.filename,
                title: file.name.replace(/\.html?$/i, "")
            });

            if (!indexRes.success) {
                throw new Error(indexRes.message || "Aggiornamento indice fallito");
            }

            // Step 3: ricarica lista newsletter dal server
            restoreNewsletterView();

            setTimeout(async () => {
                try {
                    const nlRes = await customFetch("home", "getNewsletterIndex");
                    const index = nlRes.success ? nlRes.data : null;
                    const container = document.getElementById("communications-container");
                    if (!container || !Array.isArray(index)) return;

                    container.innerHTML = "";
                    index.forEach(newsletter => {
                        container.appendChild(createNewsletterCommunication(newsletter));
                    });
                } finally {
                    isUploading = false;
                }
            }, 300);
        } catch (err) {
            console.error("Errore upload:", err);
            showToast("Errore durante l'upload: " + err.message, "error");
            isUploading = false;
        }
    }

    function restoreNewsletterView() {
        newsletterDropActive = false;

        const container = document.getElementById("communications-container");
        if (!container) return;

        container.classList.remove("dropzone-style");
        container.innerHTML = `<p class="communication-message">Nessuna comunicazione recente.</p>`;
    }

    // Lazy load sidebar (changelog e notifiche) - carica solo quando visibile
    function loadSidebarData() {
        const changelogBox = document.getElementById("home-changelog-box");
        if (changelogBox && changelogBox.dataset.loaded !== 'true') {
            changelogBox.dataset.loaded = 'true';
            customFetch("changelog", "getLatest").then(res => {
                if (!changelogBox) return;

                const fragment = document.createDocumentFragment();
                if (!res.success || !Array.isArray(res.data) || res.data.length === 0) {
                    const emptyMsg = document.createElement('p');
                    emptyMsg.className = 'communication-message';
                    emptyMsg.textContent = 'Nessun aggiornamento recente.';
                    fragment.appendChild(emptyMsg);
                } else {
                    const changelogList = document.createElement('div');
                    changelogList.className = 'changelog-simple-list';

                    res.data.forEach(row => {
                        const item = document.createElement('div');
                        item.className = 'changelog-simple-item';

                        let versione = row.versione;
                        if (!versione && typeof row.versione_major !== 'undefined' && typeof row.versione_minor !== 'undefined') {
                            versione = `${row.versione_major}.${row.versione_minor}`;
                        }
                        if (versione && !/^v/i.test(versione)) versione = 'v' + versione;

                        item.innerHTML = `
                            <div class="changelog-simple-title-row" style="display:flex;align-items:center;justify-content:space-between;">
                                <span class="changelog-simple-title">${sanitize(row.titolo)}</span>
                                ${versione ? `<span class="changelog-mini-badge">${sanitize(versione)}</span>` : ''}
                            </div>
                            <div class="changelog-simple-description">${sanitize(row.descrizione).slice(0,80)}${row.descrizione.length > 80 ? '…' : ''}</div>
                            <div class="changelog-simple-footer" style="display:flex;align-items:center;justify-content:space-between;margin-top:2px;">
                                <span class="changelog-simple-date">${sanitize(row.data)}</span>
                                ${row.url ? `<a href="${row.url}" class="changelog-simple-link universal-link" data-tooltip="Vai alla sezione" target="_blank" rel="noopener noreferrer"><span class="arrow">&#8599;</span></a>` : ''}
                            </div>
                        `;
                        changelogList.appendChild(item);
                    });
                    fragment.appendChild(changelogList);
                }

                changelogBox.innerHTML = '';
                changelogBox.appendChild(fragment);
            }).catch(err => {
                console.warn("Errore caricamento changelog:", err);
                if (changelogBox) {
                    changelogBox.innerHTML = '<p class="communication-message">Errore nel caricamento.</p>';
                }
            });
        }
    }

    // Lazy load sidebar quando entra in viewport (IntersectionObserver)
    if ('IntersectionObserver' in window) {
        const sidebarObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    loadSidebarData();
                    sidebarObserver.unobserve(entry.target);
                }
            });
        }, { rootMargin: '50px' });

        const sidebar = document.querySelector('.home-sidebar');
        if (sidebar) {
            sidebarObserver.observe(sidebar);
        }
    } else {
        // Fallback: carica dopo 500ms
        setTimeout(loadSidebarData, 500);
    }

    // Funzione per mostrare la featured news (normale)
    function renderFeaturedNews(news) {
        if (!domCache.featuredContent) return;
        if (!news || (!news.image && !news.link)) {
            domCache.featuredContent.innerHTML = `
                <div class="featured-news-hero" style="background-image: url('/assets/images/default_home.png');">
                    <div class="featured-news-overlay"></div>
                    <div class="featured-news-content">
                        <h1 class="featured-news-title">Benvenuto nella intranet</h1>
                    </div>
                </div>
            `;
            return;
        }
        // Usa immagine se disponibile, altrimenti default
        const image = news.image || '/assets/images/default_home.png';
        const title = news.title || 'News in evidenza';
        
        // Verifica se è GIF (per supportare animazioni)
        const isGif = image.toLowerCase().endsWith('.gif');
        
        if (isGif) {
            // Per GIF: usa <img> invece di background-image per preservare animazione
            domCache.featuredContent.innerHTML = `
                <div class="featured-news-hero" style="position: relative; overflow: hidden;">
                    <img src="${sanitize(image)}" alt="${sanitize(title)}" style="width: 100%; height: 100%; object-fit: cover; position: absolute; top: 0; left: 0; z-index: 0;">
                    <div class="featured-news-overlay"></div>
                    <div class="featured-news-content">
                        <h1 class="featured-news-title">${sanitize(title)}</h1>
                        ${news.link ? `<a href="${sanitize(news.link)}" class="featured-news-btn" target="_blank" rel="noopener">Leggi la news</a>` : ""}
                    </div>
                </div>
            `;
        } else {
            // Per altri formati: usa background-image (più performante)
            domCache.featuredContent.innerHTML = `
                <div class="featured-news-hero" style="background-image: url('${sanitize(image)}');">
                    <div class="featured-news-overlay"></div>
                    <div class="featured-news-content">
                        <h1 class="featured-news-title">${sanitize(title)}</h1>
                        ${news.link ? `<a href="${sanitize(news.link)}" class="featured-news-btn" target="_blank" rel="noopener">Leggi la news</a>` : ""}
                    </div>
                </div>
            `;
        }
    }

    // Funzione per mostrare la modalità admin
    function renderFeaturedNewsAdmin(news) {
        if (!domCache.adminOverlay) return;
        domCache.adminOverlay.style.display = "block";
        if (domCache.featuredContent) domCache.featuredContent.style.display = "none";

        // Selettore tipo contenuto (Link o Immagine)
        const contentType = (news?.image && !news?.link) ? 'image' : 'link';
        
        domCache.adminOverlay.innerHTML = `
            <form id="featured-news-admin-form">
                <h2>Gestisci Contenuto</h2>
                
                <!-- Navigazione Tab -->
                <div class="tab">
                    <button type="button" class="tab-link ${contentType === 'link' ? 'active' : ''}" data-tab="tab-link-content">Da link</button>
                    <button type="button" class="tab-link ${contentType === 'image' ? 'active' : ''}" data-tab="tab-image-content">Carica immagine</button>
                </div>
                
                <!-- Contenuto Tab: Link -->
                <div id="tab-link-content" class="tabcontent ${contentType === 'link' ? 'active' : ''}">
                    <label>Link articolo WP
                        <input type="url" name="link" id="featured-link-input" value="${sanitize(news?.link || '')}" placeholder="https://www.incide.it/...">
                    </label>
                </div>
                
                <!-- Contenuto Tab: Immagine -->
                <div id="tab-image-content" class="tabcontent ${contentType === 'image' ? 'active' : ''}">
                    <label>Titolo (opzionale)
                        <input type="text" name="title" id="featured-title-input" value="${sanitize(news?.title || '')}" placeholder="News in evidenza">
                    </label>
                    <label style="margin-top: 15px; display: block;">Carica immagine
                        <input type="file" name="image" id="featured-image-input" accept="image/jpeg,image/png,image/webp,image/gif" data-tooltip="Formati supportati: JPEG, PNG, WebP, GIF (anche animate)">
                    </label>
                    <div id="image-preview-container" style="margin-top: 15px; ${news?.image && contentType === 'image' ? '' : 'display: none;'}">
                        <p style="font-size: 12px; color: #666; margin-bottom: 8px;">Anteprima:</p>
                        <img id="image-preview" src="${sanitize(news?.image || '')}" alt="Anteprima" style="max-width: 100%; max-height: 200px; border: 1px solid #ddd; border-radius: 4px;">
                    </div>
                </div>
                
                <div class="featured-news-admin-actions">
                    <button type="submit" class="button">Salva</button>
                    <button type="button" id="closeFeaturedAdmin" class="button">Annulla</button>
                </div>
            </form>
        `;
        
        // Gestione cambio tab (stile gestione_ruoli)
        const tabLinks = domCache.adminOverlay.querySelectorAll('.tab-link');
        tabLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                // Rimuovi active da tutte le tab
                domCache.adminOverlay.querySelectorAll('.tab-link').forEach(l => l.classList.remove('active'));
                domCache.adminOverlay.querySelectorAll('.tabcontent').forEach(tab => tab.classList.remove('active'));
                
                // Attiva tab selezionata
                this.classList.add('active');
                const tabId = this.getAttribute('data-tab');
                const tabContent = document.getElementById(tabId);
                if (tabContent) {
                    tabContent.classList.add('active');
                }
            });
        });
        
        const linkInput = document.getElementById('featured-link-input');
        const imageInput = document.getElementById('featured-image-input');
        const previewContainer = document.getElementById('image-preview-container');
        const previewImg = document.getElementById('image-preview');
        
        // Preview immagine quando viene selezionato un file
        if (imageInput) {
            imageInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Validazione tipo MIME
                    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    if (!allowedTypes.includes(file.type)) {
                        showToast('Formato file non supportato. Usa JPEG, PNG, WebP o GIF.', 'error');
                        e.target.value = '';
                        return;
                    }
                    
                    // Validazione dimensione (max 10MB)
                    const maxSize = 10 * 1024 * 1024; // 10MB
                    if (file.size > maxSize) {
                        showToast('File troppo grande. Dimensione massima: 10MB.', 'error');
                        e.target.value = '';
                        return;
                    }
                    
                    // Mostra preview (GIF animate funzioneranno correttamente con <img>)
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        previewImg.src = event.target.result;
                        previewContainer.style.display = 'block';
                        // Se è GIF, assicurati che l'animazione funzioni
                        if (file.type === 'image/gif') {
                            previewImg.style.imageRendering = 'auto';
                        }
                    };
                    reader.readAsDataURL(file);
                } else {
                    previewContainer.style.display = 'none';
                }
            });
        }

        // Evento salvataggio news evidenza
        document.getElementById("featured-news-admin-form").onsubmit = async function(e) {
            e.preventDefault();
            const form = e.target;
            // Determina contentType dalla tab attiva
            const activeTab = domCache.adminOverlay && domCache.adminOverlay.querySelector('.tab-link.active');
            const contentType = activeTab && activeTab.getAttribute('data-tab') === 'tab-image-content' ? 'image' : 'link';
            
            let saveRes;
            
            if (contentType === 'link') {
                // Salvataggio da link (comportamento esistente)
                const link = form.link.value.trim();
                if (!link) {
                    showToast('Link obbligatorio', 'error');
                    return;
                }
                
                saveRes = await customFetch('home', 'setFeaturedNews', { link });
            } else {
                // Salvataggio da upload immagine
                const imageFile = form.image.files[0];
                if (!imageFile) {
                    showToast('Seleziona un\'immagine', 'error');
                    return;
                }
                
                // Upload immagine con FormData
                const formData = new FormData();
                formData.append('image', imageFile);
                formData.append('section', 'home');
                formData.append('action', 'uploadFeaturedNewsImage');
                
                const csrfToken = document.querySelector('meta[name="token-csrf"]')?.getAttribute('content') || '';
                
                try {
                    const uploadRes = await fetch('ajax.php', {
                        method: 'POST',
                        headers: csrfToken ? { 'X-Csrf-Token': csrfToken } : {},
                        body: formData
                    });
                    
                    const uploadJson = await uploadRes.json();
                    
                    if (!uploadJson.success) {
                        showToast(uploadJson.message || 'Errore durante l\'upload', 'error');
                        return;
                    }
                    
                    // Dopo upload riuscito, salva i metadati (senza link quando si carica immagine)
                    const titleInput = form.querySelector('#featured-title-input');
                    saveRes = await customFetch('home', 'setFeaturedNews', { 
                        image: uploadJson.imagePath,
                        title: titleInput ? titleInput.value.trim() : 'News in evidenza',
                        link: '' // Nessun link quando si carica immagine dal computer
                    });
                } catch (error) {
                    console.error('Errore upload:', error);
                    showToast('Errore durante l\'upload: ' + error.message, 'error');
                    return;
                }
            }
            
            if (saveRes.success && saveRes.data) {
                isAdminEditMode = false;
                if (domCache.adminOverlay) domCache.adminOverlay.style.display = "none";
                if (domCache.featuredContent) domCache.featuredContent.style.display = "";
                featuredNewsData = saveRes.data;
                renderFeaturedNews(featuredNewsData);
                showToast("Contenuto aggiornato!", "success");

                // Aggiorna newsContainer SOLO se abbiamo salvato un link (non per immagini caricate)
                if (contentType === 'link') {
                    // Ricarica solo news (non tutto getHomeData per evitare overhead)
                    customFetch('home', 'getCachedNews').then(res => {
                        renderNews(res.data || [], domCache.newsContainer);
                    });
                }
                // Se contentType === 'image', NON aggiornare newsContainer (è solo un'immagine, non una news)
            } else {
                showToast(saveRes.message || "Errore nel salvataggio", "error");
            }
        };

        // Evento chiusura admin
        const closeBtn = document.getElementById("closeFeaturedAdmin");
        if (closeBtn) {
            closeBtn.onclick = () => {
                isAdminEditMode = false;
                if (domCache.adminOverlay) domCache.adminOverlay.style.display = "none";
                if (domCache.featuredContent) domCache.featuredContent.style.display = "";
                renderFeaturedNews(featuredNewsData);
            };
        }
    }

    // Setup context menu featured news (solo se elementi esistono)
    if (domCache.featuredSection && domCache.adminOverlay && domCache.featuredContent && domCache.contextMenu && domCache.contextEdit) {

        // Listener click destro su tutta la sezione
        domCache.featuredSection.addEventListener("contextmenu", function(e) {
            if (window.CURRENT_USER && (window.CURRENT_USER.role_id === 1 || window.CURRENT_USER.role_id === 0)) {
                e.preventDefault();
                
                // Calcola posizione menu vicino al cursore con clamp per viewport
                const menuWidth = 200;
                const menuHeight = 50;
                const padding = 10;
                
                let left = e.clientX;
                let top = e.clientY;
                
                // Clamp per evitare overflow
                if (left + menuWidth + padding > window.innerWidth) {
                    left = window.innerWidth - menuWidth - padding;
                }
                if (left < padding) {
                    left = padding;
                }
                
                if (top + menuHeight + padding > window.innerHeight) {
                    top = window.innerHeight - menuHeight - padding;
                }
                if (top < padding) {
                    top = padding;
                }
                
                domCache.contextMenu.style.left = left + "px";
                domCache.contextMenu.style.top = top + "px";
                domCache.contextMenu.style.display = "block";

                // Chiudi menu su click fuori, ESC o scroll
                function closeMenu() {
                    if (domCache.contextMenu) domCache.contextMenu.style.display = "none";
                    document.removeEventListener("click", closeMenu);
                    document.removeEventListener("keydown", escHandler);
                    window.removeEventListener("scroll", closeMenu, true);
                }
                
                function escHandler(ev) {
                    if (ev.key === "Escape") {
                        closeMenu();
                    }
                }
                
                setTimeout(() => {
                    document.addEventListener("click", closeMenu, { once: true });
                    document.addEventListener("keydown", escHandler);
                    window.addEventListener("scroll", closeMenu, { once: true, capture: true });
                }, 0);
            }
        });

        // Listener click sulla voce "Gestisci contenuto"
        domCache.contextEdit.onclick = function() {
            if (domCache.contextMenu) domCache.contextMenu.style.display = "none";
            renderFeaturedNewsAdmin(featuredNewsData);
        };
    }

});
