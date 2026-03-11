document.addEventListener('DOMContentLoaded', function () {
    const sidebarMenu = document.getElementById('sidebar-menu');
    const sidebar = document.querySelector('.fixed-sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const mainContainer = document.querySelector('.main-container');
    const urlParamsTop = new URLSearchParams(window.location.search);
    const section = urlParamsTop.get('section') || 'archivio';

    const isNextcloud = (section === 'archivio' && urlParamsTop.get('page') === 'nextcloud');
    if (isNextcloud) {
        window.sidebarMode = 'nextcloud';
        sidebarMenu.classList.add('sidebar-nextcloud');
        console.log("[SIDEBAR] Mode: Nextcloud Active");
    } else {
        sidebarMenu.classList.remove('sidebar-nextcloud');
        window.sidebarMode = 'legacy';
    }

    if (!sidebarMenu || !mainContainer) {
        console.error("Sidebar o contenitore principale non trovati.");
        return;
    }

    // Configurazione
    const MAX_DEPTH = 8; // Aumentato per alberature profonde
    const STORAGE_KEY_SIDEBAR = 'sidebarState';
    const STORAGE_KEY_MENU_EXPANDED = 'sidebarMenuExpanded';

    // Helper: Gestione stato espanso (localStorage)
    function getExpandedState() {
        try {
            const stored = localStorage.getItem(STORAGE_KEY_MENU_EXPANDED);
            const parsed = stored ? JSON.parse(stored) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            console.warn("Errore lettura stato menu:", e);
            return [];
        }
    }

    function setExpandedState(navId, isOpen) {
        if (!navId) return;
        try {
            let state = getExpandedState();
            if (isOpen) {
                if (!state.includes(navId)) state.push(navId);
            } else {
                state = state.filter(id => id !== navId);
            }
            localStorage.setItem(STORAGE_KEY_MENU_EXPANDED, JSON.stringify(state));
        } catch (e) {
            console.warn("Errore scrittura stato menu:", e);
        }
    }

    function loadSidebarMenu(section) {
        if (window.sidebarMenuData && window.sidebarMenuData.success && Array.isArray(window.sidebarMenuData.menus)) {
            window.sidebarMenuData.section = section;
            renderMenu(window.sidebarMenuData.menus, sidebarMenu, section);
            return;
        }
        fetchSidebarMenu(section);
    }

    function fetchSidebarMenu(section) {
        sidebarMenu.innerHTML = '<li class="loading-message">Caricamento in corso...</li>';

        // Uso customFetch se disponibile, altrimenti fetch
        if (typeof customFetch === 'function') {
            customFetch('sidebar', 'getSidebarMenu', { targetSection: section })
                .then(data => {
                    if (!data.success || !Array.isArray(data.menus)) throw new Error("Dati menu non validi.");
                    window.sidebarMenuData = { ...data, section };
                    renderMenu(data.menus, sidebarMenu, section);
                })
                .catch(handleLoadError);
        } else {
            // Fallback Fetch
            fetch(`ajax.php?section=sidebar&action=getSidebarMenu&targetSection=${section}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !Array.isArray(data.menus)) throw new Error("Dati menu non validi.");
                    window.sidebarMenuData = { ...data, section };
                    renderMenu(data.menus, sidebarMenu, section);
                })
                .catch(handleLoadError);
        }
    }

    function handleLoadError(err) {
        console.error("Errore caricamento sidebar:", err);
        if (window.sidebarMenuData && Array.isArray(window.sidebarMenuData.menus)) {
            renderMenu(window.sidebarMenuData.menus, sidebarMenu, section); // Fallback su cache PHP
        } else {
            sidebarMenu.innerHTML = '<li class="error-message">Errore nel caricamento del menu.</li>';
        }
    }

    window.refreshSidebarMenu = function (section = null) {
        const targetSection = section || urlParamsTop.get('section') || 'archivio';
        if (!document.getElementById('sidebar-menu')) return;
        fetchSidebarMenu(targetSection);
    };

    // --- LOGICA ACTIVE STATE (Legacy & Nextcloud) ---
    function isItemActive(menu, urlParams, currentNavId, currentSection) {
        // A) STRICT MODE NAV_ID
        if (currentNavId && menu.nav_id === currentNavId) return true;

        // B) NEXTCLOUD UI ACTIVE (path check)
        if (window.sidebarMode === 'nextcloud' && menu.type === 'folder' && menu.link) {
            /* Link: index.php...?path=ENCODED
               Se current URL ha path=ENCODED, match. 
               Decodifichiamo per confronto robusto.
            */
            try {
                const currentPath = decodeURIComponent(urlParams.get('path') || '');
                const urlObj = new URL(menu.link, window.location.origin);
                const menuPath = decodeURIComponent(new URLSearchParams(urlObj.search).get('path') || '');

                // Active solo se path sono identici (strict active)
                // O se currentPath inizia con menuPath (parent active)? Di solito sidebar attiva solo la foglia o tutto il ramo.
                // Qui attiviamo se è ESATTAMENTE la cartella corrente visualizzata
                if (currentPath && menuPath && currentPath === menuPath) {
                    return true;
                }
            } catch (e) { }
        }

        // C) LEGACY LOGIC
        if (menu.link) {
            try {
                const currentTab = urlParams.get('tab');
                const currentTabella = urlParams.get('tabella');
                const currentFormName = urlParams.get('form_name');
                const currentPage = urlParams.get('page');

                const parsedLink = new URL(menu.link, window.location.origin);
                const parsedParams = new URLSearchParams(parsedLink.search);
                const linkSection = parsedParams.get('section');
                const linkPage = parsedParams.get('page');
                const linkTab = parsedParams.get('tab');
                const linkTabella = parsedParams.get('tabella');
                const linkFormName = parsedParams.get('form_name');

                function normalize(s) {
                    if (!s) return '';
                    try { return decodeURIComponent(s).replace(/\+/g, ' ').toLowerCase().trim(); }
                    catch (e) { return s.toLowerCase().trim(); }
                }
                const isSameTabella = (!linkTabella && !currentTabella) || (linkTabella === currentTabella);
                const isSameFormName = (!linkFormName && !currentFormName) || (normalize(linkFormName) === normalize(currentFormName));
                const tabMatches = (!linkTab && !currentTab) || (linkTab === currentTab);

                const pagineForm = ['view_form', 'form_viewer', 'gestione_segnalazioni'];

                if (linkFormName || currentFormName) {
                    if (linkSection === currentSection && pagineForm.includes(currentPage) && pagineForm.includes(linkPage) && isSameFormName) {
                        return true;
                    }
                } else {
                    if (linkSection === currentSection && linkPage === currentPage && isSameTabella && tabMatches) {
                        return true;
                    }
                }
            } catch (e) { }
        }
        return false;
    }


    // --- RENDER RECURSIVE ---
    function renderMenu(menus, container, currentSectionKey) {
        if (!container || !Array.isArray(menus)) return;
        container.innerHTML = '';

        const urlParams = new URLSearchParams(window.location.search);
        const currentNavId = urlParams.get('nav_id');
        const currentSection = urlParams.get('section');

        // Wrapper
        createMenuTree(menus, container, 0, urlParams, currentNavId, currentSection);

        // Reflow
        void container.offsetHeight;
    }

    function createMenuTree(items, container, depth, urlParams, currentNavId, currentSection) {
        if (depth > MAX_DEPTH) return false;

        let hasActiveChild = false;
        const expandedState = getExpandedState();
        const csrfToken = document.querySelector('meta[name="token-csrf"]')?.content || '';

        items.forEach(menu => {
            const li = document.createElement('li');

            // Classi base: legacy 'menu-item'/'submenu-item', e nuove classi helper
            const isRoot = (depth === 0);
            li.classList.add(isRoot ? 'menu-item' : 'submenu-item');
            if (menu.nav_id) li.dataset.navId = menu.nav_id;
            li.classList.add('sb-node'); // Classe generica per nodi (utile per CSS futuro)
            li.dataset.depth = depth;

            // Se Folder/File (Nextcloud) o standard
            const isFolder = (menu.type === 'folder' || (menu.submenus && menu.submenus.length > 0) || !!menu.api);
            if (menu.type) li.classList.add('sb-node--' + menu.type);

            // Container Link
            const a = document.createElement('a');
            a.classList.add('menu');

            // Indentazione: padding-left dinamico. 
            // Livello 0: default css. Livello 1+: calcolato.
            // Explorer style: riduciamo padding base e usiamo step più fissi ma piccoli
            if (depth > 0) {
                // Estremamente compatto: Base 0.4rem + 0.4rem per livello.
                // Esempio: L1=0.8rem (~13px), L2=1.2rem (~19px), L5=2.4rem (~38px)
                a.style.paddingLeft = (0.4 + (depth * 0.4)) + 'rem';
            }

            const spanContent = document.createElement('span');
            spanContent.classList.add('menu-content');

            // Icona (Folder/File o custom)
            let iconHtml = '';
            if (menu.icon_class === 'sb-icon-folder') {
                // Folder icon
                iconHtml = '<span class="sb-icon">📁</span> '; // Placeholder emoji, CSS sostituirà con background-image
            } else if (menu.icon_class === 'sb-icon-file') {
                iconHtml = '<span class="sb-icon">📄</span> ';
            }
            // Se non c'è type specifico, non mettiamo icona (comportamento legacy) o mettiamo generic?
            // Legacy non ha icone. Manteniamo legacy pulito.

            const spanText = document.createElement('span');
            spanText.classList.add('menu-text');
            spanText.innerHTML = iconHtml + menu.title; // innerHTML per inserire icona se stringa
            spanContent.appendChild(spanText);

            // Arrow (present only if children or API logic allows sub-loading)
            const hasChildren = (menu.submenus && Array.isArray(menu.submenus) && menu.submenus.length > 0);
            const isApi = !!menu.api;
            let arrow = null;

            if (hasChildren || isApi) {
                arrow = document.createElement('span');
                arrow.classList.add('menu-arrow');
                arrow.innerHTML = '▶';
                // Se folder style, mettiamo arrow PRE text o POST? 
                // Sidebar legacy mette arrow a destra (float right o flex end).
                // Explorer style solitamente mette arrow a sinistra.
                // L'utente chiede indentazione e stile explorer. Proviamo a lasciarla a destra per ora per coerenza,
                // ma se richiesto explorer style, dovrebbe stare a sx. 
                // Vincolo: "Sidebar globale... UI sidebar non è stile esplora risorse" -> OBIETTIVO: FIX UI stile explorer.
                // Modifichiamo ordine: Arrow a sinistra del testo?
                // Per retrocompatibilità NON rompiamo visualizzazione legacy degli altri menu.
                // Quindi: Se menu.type è definito (nextcloud), usiamo stile explorer (arrow left).
                // Altrimenti legacy (arrow right).

                if (window.sidebarMode === 'nextcloud') {
                    arrow.classList.add('sb-caret-left'); // Classe per CSS
                    // Inserisci PRIMA del testo
                    spanContent.insertBefore(arrow, spanText);
                    arrow.innerHTML = '▶';
                    arrow.style.marginRight = '5px';
                    arrow.style.display = 'inline-block';
                    arrow.style.width = '12px';
                } else {
                    // Legacy Right
                    spanContent.appendChild(arrow);
                }
            } else {
                // Spacer per allineamento explorer se foglia
                if (window.sidebarMode === 'nextcloud') {
                    const spacer = document.createElement('span');
                    spacer.style.width = '17px'; // match arrow + margin
                    spacer.style.display = 'inline-block';
                    spanContent.insertBefore(spacer, spanText);
                }
            }

            a.appendChild(spanContent);

            // Link handling
            if (menu.link) {
                a.href = menu.link;
            } else {
                a.href = 'javascript:void(0);';
            }

            li.appendChild(a);

            // Recursion Submenu Container
            let ul = null;
            let childActive = false;

            // Se abbiamo già children statici
            if (hasChildren) {
                ul = document.createElement('ul');
                ul.classList.add('submenu');
                childActive = createMenuTree(menu.submenus, ul, depth + 1, urlParams, currentNavId, currentSection);
                li.appendChild(ul);
            }

            // Active Check
            const selfActive = isItemActive(menu, urlParams, currentNavId, currentSection);
            const isActive = selfActive || childActive;

            if (isActive) {
                if (selfActive) li.classList.add('active'); // Highlight self
                hasActiveChild = true;
            }

            // Expansion & Toggle Logic
            const toggleMenu = (expand) => {
                const wasOpen = (ul && ul.classList.contains('submenu-open')) || li.classList.contains('menu-open');
                const willOpen = (expand !== undefined) ? expand : !wasOpen;

                // ACCORDION BEHAVIOR: Close siblings if opening
                if (willOpen) {
                    const siblings = Array.from(container.children);
                    siblings.forEach(sibling => {
                        if (sibling !== li && sibling.classList.contains('menu-open')) {
                            sibling.classList.remove('menu-open');
                            const sibUl = sibling.querySelector('.submenu');
                            if (sibUl) sibUl.classList.remove('submenu-open');
                            const sibArrow = sibling.querySelector('.menu-arrow');
                            if (sibArrow) sibArrow.style.transform = 'rotate(0deg)';

                            // Update Storage
                            if (sibling.dataset.navId) {
                                setExpandedState(sibling.dataset.navId, false);
                            }
                        }
                    });
                }

                // Se dobbiamo aprire e non c'è UL ma c'è API -> Load
                if (willOpen && isApi && !ul) {
                    loadApiChildren(() => {
                        // After load, recursive call sets classes, but we ensure open here
                        // Il loadApiChildren appende ul. Dobbiamo ritrovarlo.
                        const newUl = li.querySelector('.submenu');
                        if (newUl) {
                            newUl.classList.add('submenu-open');
                            li.classList.add('menu-open');
                            if (arrow) arrow.style.transform = 'rotate(90deg)'; // Explorer style rotation
                        }
                    });
                    // Set expanded state subito (optimistic)
                    if (menu.nav_id) setExpandedState(menu.nav_id, true);
                    return;
                }

                if (ul) ul.classList.toggle('submenu-open', willOpen);
                li.classList.toggle('menu-open', willOpen);

                if (arrow) {
                    arrow.style.transform = willOpen ? 'rotate(90deg)' : 'rotate(0deg)';
                }

                if (menu.nav_id) setExpandedState(menu.nav_id, willOpen);
            };

            // Init State (Open if stored or active child or forced self active for context)
            const isExpandedStored = menu.nav_id && expandedState.includes(menu.nav_id);
            // Se c'è un figlio attivo, apri. Se io sono attivo, apri (per mostrare context? no, se io sono attivo, i miei figli si vedono se aperti)
            // Explorer logic: Se io sono attivo (cartella corrente), di solito la seleziono ma non espando forza, ma spesso si espande per vedere file dentro.
            // Manteniamo: expand se childActive OR stored.
            if (isExpandedStored || childActive) {
                if (ul) {
                    ul.classList.add('submenu-open');
                    li.classList.add('menu-open');
                    if (arrow) arrow.style.transform = 'rotate(90deg)';
                } else if (isApi) {
                    // Lazy load init (se era aperto)
                    // Non lo facciamo automatico per tutti per evitare storm di richieste.
                    // Solo se childActive (che non può essere true se non ho children caricati...)
                    // Se stored aperto: Lazy load now?
                    // Sì, restore state.
                    if (isExpandedStored) {
                        loadApiChildren(() => {
                            const newUl = li.querySelector('.submenu');
                            if (newUl) {
                                newUl.classList.add('submenu-open');
                                li.classList.add('menu-open');
                                if (arrow) arrow.style.transform = 'rotate(90deg)';
                            }
                        });
                    }
                }
            }

            // Event Handlers
            if (hasChildren || isApi) {
                a.addEventListener('click', (e) => {
                    const isArrowClick = e.target.closest('.menu-arrow');

                    if (window.sidebarMode === 'nextcloud') {
                        // Explorer Mode
                        if (isArrowClick) {
                            e.preventDefault(); e.stopPropagation();
                            toggleMenu();
                        } else {
                            // Click Label
                            if (menu.link && menu.link !== 'javascript:void(0);') {
                                // Navigate
                            } else {
                                // Toggle only if no link
                                e.preventDefault();
                                toggleMenu();
                            }
                        }
                    } else {
                        // Legacy Mode (Arrow Right)
                        if (isArrowClick) {
                            e.preventDefault(); e.stopPropagation();
                            toggleMenu();
                        } else {
                            if (menu.link && menu.link !== 'javascript:void(0);') { /** Navigate */ }
                            else { e.preventDefault(); toggleMenu(); }
                        }
                    }
                });
            }

            // API Loader
            function loadApiChildren(callback) {
                if (li.dataset.loading === 'true') return;
                li.dataset.loading = 'true';
                if (arrow) arrow.style.opacity = '0.5';

                // Fetch con CSRF
                const hdrs = {
                    'X-Requested-With': 'XMLHttpRequest'
                };
                if (csrfToken) {
                    hdrs['Csrf-Token'] = csrfToken; // Header custom standard nel progetto
                    hdrs['X-CSRF-TOKEN'] = csrfToken; // Laravel style fallback
                }

                if (window.sidebarMode === 'nextcloud') console.log("[SIDEBAR] Fetching children for:", menu.title, menu.api);

                fetch(menu.api, { headers: hdrs })
                    .then(r => r.json())
                    .then(data => {
                        li.dataset.loading = 'false';
                        if (arrow) arrow.style.opacity = '1';

                        if (window.sidebarMode === 'nextcloud') console.log("[SIDEBAR] Fetched:", data);

                        if (!data.success || !Array.isArray(data.menus)) {
                            console.error("API Error tree", data);
                            return;
                        }

                        // Create UL
                        let newUl = li.querySelector('.submenu');
                        if (!newUl) {
                            newUl = document.createElement('ul');
                            newUl.classList.add('submenu');
                            li.appendChild(newUl);
                            ul = newUl;
                        }

                        // Render children recursive
                        createMenuTree(data.menus, newUl, depth + 1, urlParams, currentNavId, currentSection);

                        if (callback) callback();
                    })
                    .catch(err => {
                        console.error(err);
                        li.dataset.loading = 'false';
                        if (arrow) arrow.style.opacity = '1';
                    });
            }


            container.appendChild(li);
        });

        return hasActiveChild;
    }

    // --- APPLY SIDEBAR STATE ---
    function applySidebarState() {
        let isOpen = true;
        try { isOpen = localStorage.getItem(STORAGE_KEY_SIDEBAR) !== 'closed'; } catch (e) { }

        requestAnimationFrame(() => {
            if (isOpen) {
                sidebar.classList.remove('closed');
                mainContainer.classList.remove('sidebar-closed');
                const fb = document.querySelector('.function-bar');
                if (fb) fb.classList.remove('sidebar-closed');
            } else {
                sidebar.classList.add('closed');
                mainContainer.classList.add('sidebar-closed');
                const fb = document.querySelector('.function-bar');
                if (fb) fb.classList.add('sidebar-closed');
            }
        });
    }

    // Init
    try { loadSidebarMenu(section); } catch (e) { console.error(e); }
    applySidebarState();

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            const isClosed = sidebar.classList.contains('closed');
            localStorage.setItem(STORAGE_KEY_SIDEBAR, isClosed ? 'open' : 'closed');
            applySidebarState();
        });
    }
});
