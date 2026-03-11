/**
 * Contacts Page - Intra_Incide
 * Gestisce overlay profilo isolato (contacts-overlay) e lista contatti
 */

(function() {
    'use strict';

    // === CONTACTS PROFILE OVERLAY ===
    const ContactsProfileOverlay = {
        overlay: null,
        mainContainer: null,
        currentContactId: null,
        isOpen: false,
        resizeObserver: null,

        init() {
            this.overlay = document.getElementById('contacts-profile-overlay');
            this.mainContainer = document.querySelector('.main-container');
            if (!this.overlay || !this.mainContainer) return;

            // Bind close button
            const closeBtn = document.getElementById('contacts-overlay-close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => this.close());
            }

            // ESC key closes overlay
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isOpen) {
                    this.close();
                }
            });

            // Handle browser back button
            window.addEventListener('popstate', (e) => {
                if (this.isOpen && (!e.state || !e.state.profileId)) {
                    this.close(false); // false = don't push state
                }
            });

            // Aggiorna posizione overlay quando la finestra cambia dimensione
            // Usa requestAnimationFrame per calcolare dopo reflow completo
            window.addEventListener('resize', () => {
                if (this.isOpen) {
                    requestAnimationFrame(() => {
                        this.updateOverlayPosition();
                    });
                }
            });

            // Osserva cambiamenti al main-container (es. sidebar toggle, media query breakpoint)
            // Usa border-box per catturare anche margin/padding changes
            this.resizeObserver = new ResizeObserver(() => {
                if (this.isOpen) {
                    this.updateOverlayPosition();
                }
            });
            this.resizeObserver.observe(this.mainContainer, { box: 'border-box' });
        },

        /**
         * Calcola e applica la posizione dell'overlay per coprire esattamente il main-container
         */
        updateOverlayPosition() {
            if (!this.overlay || !this.mainContainer) return;

            const rect = this.mainContainer.getBoundingClientRect();

            this.overlay.style.top = rect.top + 'px';
            this.overlay.style.left = rect.left + 'px';
            this.overlay.style.width = rect.width + 'px';

            // Calcola bottom invece di height fissa per permettere scroll completo
            const bottom = window.innerHeight - rect.bottom;
            this.overlay.style.bottom = bottom + 'px';
            this.overlay.style.height = 'auto';
        },

        open(contact) {
            if (!this.overlay || !contact) return;

            this.currentContactId = contact.user_id;
            this.isOpen = true;

            // Posiziona l'overlay sopra il main-container
            this.updateOverlayPosition();

            // Show overlay
            this.overlay.classList.remove('contacts-overlay--hidden');
            this.overlay.classList.add('contacts-overlay--visible');

            // Blocca lo scroll del body
            document.body.style.overflow = 'hidden';

            // Update URL with profile ID
            const url = new URL(window.location);
            url.searchParams.set('profile', contact.user_id);
            history.pushState({ profileId: contact.user_id }, '', url);

            // Load profile data
            this.loadProfile(contact);
        },

        close(pushState = true) {
            if (!this.overlay) return;

            this.isOpen = false;
            this.currentContactId = null;

            // Hide overlay
            this.overlay.classList.add('contacts-overlay--hidden');
            this.overlay.classList.remove('contacts-overlay--visible');

            // Ripristina lo scroll del body
            document.body.style.overflow = '';

            // Reset CV state
            const cvFrame = document.getElementById('cp-pdf-preview');
            const cvBtn = document.getElementById('cp-toggle-cv-btn');
            if (cvFrame) {
                cvFrame.src = '';
                cvFrame.classList.add('contacts-profile__cv-frame--hidden');
            }
            if (cvBtn) {
                cvBtn.textContent = 'Mostra Curriculum';
            }

            // Update URL
            if (pushState) {
                const url = new URL(window.location);
                url.searchParams.delete('profile');
                history.pushState({}, '', url);
            }
        },

        async loadProfile(contact) {
            const userId = contact.user_id;
            const nominativo = contact.Nominativo;

            // Update header title
            const titleEl = document.getElementById('contacts-overlay-title');
            if (titleEl) titleEl.textContent = nominativo || 'Profilo';

            // Reset all fields
            this.resetFields();

            try {
                const [profileData, profileImageData, competencesData, rolesData, projectsData, coworkersData] = await Promise.all([
                    customFetch('contacts', 'getProfileData', { id: userId }),
                    customFetch('contacts', 'getProfileImage', { name: nominativo }),
                    customFetch('contacts', 'getUserCompetences', { id: userId }),
                    customFetch('contacts', 'getProfileRoles', { id: userId }),
                    customFetch('contacts', 'getProfileActiveProjects', { id: userId }),
                    customFetch('contacts', 'getProfileCoworkers', { id: userId })
                ]);

                // Populate profile data
                if (profileData.success && profileData.data) {
                    this.populateProfileData(profileData.data);
                }

                // Populate image
                if (profileImageData.status === 'success' && profileImageData.image) {
                    const imgEl = document.getElementById('cp-image');
                    if (imgEl) imgEl.src = profileImageData.image;
                }

                // Populate skills
                this.populateSkills(competencesData);

                // Populate roles
                this.populateRoles(rolesData);

                // Populate organization (from profileData)
                if (profileData.success && profileData.data) {
                    this.populateOrganization(profileData.data);
                }

                // Populate projects
                this.populateProjects(projectsData);

                // Populate coworkers
                this.populateCoworkers(coworkersData);

                // Setup CV
                this.setupCurriculum(userId);

            } catch (error) {
                console.error('Errore caricamento profilo:', error);
                if (window.showToast) {
                    showToast('Errore nel caricamento del profilo', 'error');
                }
            }
        },

        resetFields() {
            const fields = ['cp-fullname', 'cp-company', 'cp-email', 'cp-phone', 'cp-mobile',
                           'cp-birthdate', 'cp-birthplace', 'cp-department', 'cp-bio',
                           'cp-work-duration', 'cp-hire-date'];
            fields.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.textContent = '—';
            });

            const imgEl = document.getElementById('cp-image');
            if (imgEl) imgEl.src = '/assets/images/default_profile.png';

            // Reset containers
            ['cp-roles-container', 'cp-organization-container', 'cp-projects-container',
             'cp-coworkers-container', 'cp-skills-container'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.innerHTML = '';
            });
        },

        populateProfileData(data) {
            const setField = (id, value, fallback = '—') => {
                const el = document.getElementById(id);
                if (el) el.textContent = value || fallback;
            };

            setField('cp-fullname', data.Nominativo);
            setField('cp-company', data.Company);

            // Email: make clickable with mailto
            const emailSpan = document.getElementById('cp-email');
            const emailContainer = document.getElementById('cp-email-container');
            if (emailSpan && emailContainer) {
                const email = data.Email_Aziendale || '—';
                emailSpan.textContent = email;
                if (email !== '—' && email.includes('@')) {
                    emailContainer.style.cursor = 'pointer';
                    emailContainer.onclick = () => window.location.href = `mailto:${email}`;
                } else {
                    emailContainer.style.cursor = 'default';
                    emailContainer.onclick = null;
                }
            }

            // Mobile: make clickable with tel
            const mobileSpan = document.getElementById('cp-mobile');
            const mobileContainer = document.getElementById('cp-mobile-container');
            if (mobileSpan && mobileContainer) {
                const mobile = data.Cellulare_Aziendale?.trim() || '—';
                mobileSpan.textContent = mobile;
                if (mobile !== '—') {
                    mobileContainer.style.cursor = 'pointer';
                    mobileContainer.onclick = () => window.location.href = `tel:${mobile.replace(/\s/g, '')}`;
                } else {
                    mobileContainer.style.cursor = 'default';
                    mobileContainer.onclick = null;
                }
            }

            // Phone: make clickable with tel
            const phoneSpan = document.getElementById('cp-phone');
            const phoneContainer = document.getElementById('cp-phone-container');
            if (phoneSpan && phoneContainer) {
                const phone = data.interno?.trim() || '—';
                phoneSpan.textContent = phone;
                if (phone !== '—') {
                    phoneContainer.style.cursor = 'pointer';
                    phoneContainer.onclick = () => window.location.href = `tel:${phone.replace(/\s/g, '')}`;
                } else {
                    phoneContainer.style.cursor = 'default';
                    phoneContainer.onclick = null;
                }
            }
            setField('cp-birthdate', data.Data_di_Nascita);
            setField('cp-birthplace', data.Luogo_di_Nascita);
            setField('cp-department', data.Reparto);
            setField('cp-bio', data.bio, 'Nessuna bio disponibile');

            // Calculate work duration
            if (data.Data_Assunzione) {
                const hireDate = new Date(data.Data_Assunzione);
                const today = new Date();
                let years = today.getFullYear() - hireDate.getFullYear();
                const m = today.getMonth() - hireDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < hireDate.getDate())) {
                    years--;
                }

                const hireLabel = (data.Genere && data.Genere.toLowerCase() === 'femmina')
                    ? 'Assunta dal: ' : 'Assunto dal: ';

                setField('cp-hire-date', hireLabel + hireDate.toLocaleDateString('it-IT'));
                setField('cp-work-duration', `Lavora con noi da: ${years} ${years === 1 ? 'anno' : 'anni'}`);
            }
        },

        populateRoles(rolesData) {
            const container = document.getElementById('cp-roles-container');
            if (!container) return;

            container.innerHTML = '';

            if (!rolesData.success || !rolesData.data || !rolesData.data.main) {
                container.textContent = '—';
                return;
            }

            const mainRole = rolesData.data.main;
            const otherRoles = rolesData.data.others || [];
            const allRoles = [mainRole, ...otherRoles];

            // Mostra tutti i ruoli (non più solo i primi 2)
            allRoles.forEach((role, index) => {
                const roleEl = document.createElement('div');
                roleEl.className = index === 0 ? 'cp-role cp-role--primary' : 'cp-role cp-role--secondary';
                roleEl.textContent = role.hr_role_desc;

                // Solo ruoli MOLTO lunghi (>60 caratteri): occupano tutta la riga
                // Per il resto lascia che flexbox gestisca il wrapping naturale
                if (role.hr_role_desc && role.hr_role_desc.length > 60) {
                    roleEl.classList.add('is-wide');
                }

                if (role.relev_role_perc) {
                    roleEl.title = `Rilevanza: ${role.relev_role_perc}%`;
                }
                container.appendChild(roleEl);
            });
        },

        populateOrganization(data) {
            const container = document.getElementById('cp-organization-container');
            if (!container) return;

            container.innerHTML = '';

            const items = [];
            if (data.Reparto) items.push({ label: 'Reparto', value: data.Reparto });
            if (data.area_desc) items.push({ label: 'Area', value: data.area_desc });
            if (data.business_unit_desc) items.push({ label: 'Business Unit', value: data.business_unit_desc });

            if (items.length === 0) {
                container.textContent = '—';
                return;
            }

            items.forEach(item => {
                const div = document.createElement('div');
                div.className = 'cp-org-item';
                div.innerHTML = `<span class="cp-org-label">${item.label}:</span> ${item.value}`;
                container.appendChild(div);
            });
        },

        populateProjects(projectsData) {
            const container = document.getElementById('cp-projects-container');
            if (!container) return;

            container.innerHTML = '';

            if (!projectsData.success || !Array.isArray(projectsData.data) || projectsData.data.length === 0) {
                container.textContent = 'Nessuna commessa attiva.';
                return;
            }

            const ul = document.createElement('ul');
            projectsData.data.forEach(project => {
                const li = document.createElement('li');
                li.className = 'cp-project-item';
                li.innerHTML = `
                    <span class="cp-project-code">${project.code || '—'}</span>
                    <span class="cp-project-name">${project.name || '—'}</span>
                `;
                ul.appendChild(li);
            });
            container.appendChild(ul);
        },

        populateCoworkers(coworkersData) {
            const container = document.getElementById('cp-coworkers-container');
            if (!container) return;

            container.innerHTML = '';

            if (!coworkersData.success || !Array.isArray(coworkersData.data) || coworkersData.data.length === 0) {
                container.textContent = 'Nessun collaboratore frequente.';
                return;
            }

            const grid = document.createElement('div');
            grid.className = 'cp-coworkers-grid';

            coworkersData.data.forEach(coworker => {
                const card = document.createElement('div');
                card.className = 'cp-coworker-card';
                card.style.cursor = 'pointer';
                card.setAttribute('data-user-id', coworker.idPersonale);

                // Click handler: carica profilo del coworker
                card.addEventListener('click', () => {
                    if (coworker.idPersonale) {
                        // Trova la contact card con questo user_id per avere tutti i dati
                        const contactCard = document.querySelector(`.contact-card[data-contact*='"user_id":${coworker.idPersonale}']`) ||
                                           document.querySelector(`.contact-card[data-contact*='"user_id":"${coworker.idPersonale}"']`);

                        if (contactCard) {
                            // Usa handleCardClick esistente per riutilizzare la logica
                            try {
                                const contact = JSON.parse(contactCard.getAttribute('data-contact'));
                                window.ContactsProfileOverlay.open(contact);
                            } catch (e) {
                                console.error('Errore parsing contact data:', e);
                            }
                        } else {
                            // Fallback: aggiorna URL direttamente se non troviamo la card
                            const url = new URL(window.location);
                            url.searchParams.set('profile', coworker.idPersonale);
                            window.location.href = url.toString();
                        }
                    }
                });

                // Avatar quadrato (riusa pattern table-avatar)
                const avatarImg = document.createElement('img');
                avatarImg.className = 'table-avatar table-avatar--sm';
                avatarImg.loading = 'lazy';
                avatarImg.decoding = 'async';
                avatarImg.alt = coworker.fullname;

                // Usa customFetch per ottenere l'immagine profilo (come nel rendering card)
                customFetch('contacts', 'getProfileImage', { name: coworker.fullname })
                    .then(data => {
                        if (data.status === 'success' && data.image) {
                            avatarImg.src = data.image.startsWith('data:') || data.image.startsWith('/')
                                ? data.image
                                : '/' + data.image;
                        } else {
                            avatarImg.src = '/assets/images/default_profile.png';
                        }
                    })
                    .catch(() => {
                        avatarImg.src = '/assets/images/default_profile.png';
                    });

                // Nome
                const nameEl = document.createElement('div');
                nameEl.className = 'cp-coworker-name';
                nameEl.textContent = coworker.fullname;

                // Conteggio commesse
                const countEl = document.createElement('div');
                countEl.className = 'cp-coworker-count';
                const projectsLabel = coworker.shared_projects === 1 ? 'commessa' : 'commesse';
                countEl.textContent = `(${coworker.shared_projects} ${projectsLabel})`;

                card.appendChild(avatarImg);
                card.appendChild(nameEl);
                card.appendChild(countEl);
                grid.appendChild(card);
            });

            container.appendChild(grid);
        },

        populateSkills(competencesData) {
            const container = document.getElementById('cp-skills-container');
            if (!container) return;

            container.innerHTML = '';

            if (!competencesData.success || !Array.isArray(competencesData.data) || competencesData.data.length === 0) {
                container.textContent = 'Nessuna competenza assegnata.';
                return;
            }

            competencesData.data.forEach(skill => {
                const tag = document.createElement('div');
                tag.className = 'cp-skill-tag';
                tag.innerHTML = `
                    ${skill.competenza_nome}
                    <span class="cp-skill-area">(${skill.area_nome})</span>
                `;
                container.appendChild(tag);
            });
        },

        async setupCurriculum(userId) {
            const cvBtn = document.getElementById('cp-toggle-cv-btn');
            const cvFrame = document.getElementById('cp-pdf-preview');
            const cvMessage = document.getElementById('cp-no-cv-message');

            if (!cvBtn || !cvFrame || !cvMessage) return;

            cvBtn.style.display = 'none';
            cvFrame.classList.add('contacts-profile__cv-frame--hidden');
            cvMessage.classList.add('contacts-profile__cv-message--hidden');

            try {
                const result = await customFetch('contacts', 'checkCurriculumExistence', {
                    filename: `${userId}_cv.pdf`
                });

                if (result.success) {
                    cvBtn.style.display = 'block';
                    const pdfPath = `/uploads/cv/${userId}_cv.pdf`;

                    cvBtn.onclick = () => {
                        const isHidden = cvFrame.classList.contains('contacts-profile__cv-frame--hidden');
                        if (isHidden) {
                            cvFrame.src = '';
                            setTimeout(() => {
                                cvFrame.src = pdfPath + "#toolbar=0&navpanes=0&scrollbar=0";
                            }, 100);
                            cvFrame.classList.remove('contacts-profile__cv-frame--hidden');
                            cvBtn.textContent = 'Nascondi Curriculum';
                        } else {
                            cvFrame.classList.add('contacts-profile__cv-frame--hidden');
                            cvFrame.src = '';
                            cvBtn.textContent = 'Mostra Curriculum';
                        }
                    };
                } else {
                    cvMessage.classList.remove('contacts-profile__cv-message--hidden');
                }
            } catch (e) {
                cvMessage.textContent = 'Errore nel caricamento del curriculum.';
                cvMessage.classList.remove('contacts-profile__cv-message--hidden');
            }
        }
    };

    // === CONTACTS LIST ===
    function handleCardClick(element) {
        try {
            const contact = JSON.parse(element.getAttribute('data-contact'));
            ContactsProfileOverlay.open(contact);
        } catch (e) {
            console.error('Errore parsing contact:', e);
        }
    }

    function filterContacts(filters = {}) {
        const contactCards = document.querySelectorAll('.contact-card');

        contactCards.forEach(card => {
            try {
                const contact = JSON.parse(card.getAttribute('data-contact'));
                let visible = true;

                // Filtro search (nome o email)
                if (filters.search && filters.search.trim() !== '') {
                    const searchLower = filters.search.toLowerCase();
                    const fullName = (contact.Nominativo || '').toLowerCase();
                    const email = (contact.Email_Aziendale || '').toLowerCase();
                    if (!fullName.includes(searchLower) && !email.includes(searchLower)) {
                        visible = false;
                    }
                }

                // Filtro reparto
                if (filters.department && filters.department !== '') {
                    if (contact.Reparto !== filters.department) {
                        visible = false;
                    }
                }

                // Filtro ruoli (AND logic) - da implementare con chiamata asincrona
                // Per ora skip, richiede chiamata API per ottenere ruoli utente

                // Filtro area/business unit
                if (filters.area && filters.area !== '') {
                    // Cerca in area_desc o business_unit_desc - da implementare se disponibili nei dati contact
                    // Per ora skip
                }

                // Filtro commessa - da implementare con chiamata asincrona
                // Per ora skip

                // Filtro anzianità
                if (filters.seniority && filters.seniority !== '') {
                    const hireDate = contact.Data_Assunzione;
                    if (hireDate) {
                        const years = calculateYearsFromHireDate(hireDate);
                        if (!matchesSeniorityRange(years, filters.seniority)) {
                            visible = false;
                        }
                    }
                }

                card.style.display = visible ? '' : 'none';
            } catch (e) {
                console.error('Errore filtro contatto:', e);
                card.style.display = '';
            }
        });
    }

    function calculateYearsFromHireDate(hireDateStr) {
        try {
            // Formato italiano: DD/MM/YYYY
            const parts = hireDateStr.split('/');
            if (parts.length !== 3) return 0;
            const hireDate = new Date(parts[2], parts[1] - 1, parts[0]);
            const today = new Date();
            let years = today.getFullYear() - hireDate.getFullYear();
            const monthDiff = today.getMonth() - hireDate.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < hireDate.getDate())) {
                years--;
            }
            return years;
        } catch (e) {
            return 0;
        }
    }

    function matchesSeniorityRange(years, range) {
        switch (range) {
            case '0-1': return years < 1;
            case '1-3': return years >= 1 && years < 3;
            case '3-5': return years >= 3 && years < 5;
            case '5-10': return years >= 5 && years < 10;
            case '10+': return years >= 10;
            default: return true;
        }
    }

    function refreshProfileImages() {
        const images = document.querySelectorAll('img[data-nominativo]');
        const TTL = 7 * 24 * 60 * 60 * 1000; // 7 giorni

        images.forEach(img => {
            const name = img.dataset.nominativo;
            if (!name) return;

            const cacheKey = "img_v2_" + name.toLowerCase().replace(/\s+/g, '_');
            const cached = localStorage.getItem(cacheKey);

            if (cached) {
                try {
                    const parsed = JSON.parse(cached);
                    if (Date.now() - parsed.ts < TTL && parsed.img) {
                        img.src = parsed.img.startsWith('data:') || parsed.img.startsWith('/') ? parsed.img : '/' + parsed.img;
                        return;
                    }
                } catch (e) {
                    console.warn("Cache corrotta per", name);
                }
            }

            customFetch('contacts', 'getProfileImage', { name })
                .then(data => {
                    if (data.status === 'success' && data.image) {
                        const imagePath = data.image.startsWith('data:') || data.image.startsWith('/') ? data.image : '/' + data.image;
                        img.src = imagePath;
                        localStorage.setItem(cacheKey, JSON.stringify({ img: data.image, ts: Date.now() }));
                    } else {
                        img.src = '/assets/images/default_profile.png';
                    }
                })
                .catch(() => {
                    img.src = '/assets/images/default_profile.png';
                });
        });
    }

    // === INIT ===
    /**
     * Init token input per filtro ruoli con badge removibili
     * Storage canonico: <select multiple hidden>
     */
    function initRolesMultiSelect() {
        const rolesSelect = document.getElementById('roles');
        const searchInput = document.getElementById('roles-search');
        const toggleBtn = document.querySelector('.roles-token-toggle');
        const dropdown = document.getElementById('roles-dropdown');
        const badgesContainer = document.getElementById('selected-roles-badges');

        if (!rolesSelect || !searchInput || !dropdown || !badgesContainer) return;

        let allRoles = [];
        let isOpen = false;

        // Init: carica tutti i ruoli dal select
        function init() {
            allRoles = Array.from(rolesSelect.options).map(opt => ({
                value: opt.value,
                text: opt.textContent
            }));
            renderBadges();
        }

        // Render dropdown con filtro
        function renderDropdown(filter = '') {
            const filterLower = filter.toLowerCase();
            const selectedValues = getSelectedValues();

            const filteredRoles = allRoles.filter(role => {
                const matchesFilter = !filter || role.text.toLowerCase().includes(filterLower);
                return matchesFilter;
            });

            if (filteredRoles.length === 0) {
                dropdown.innerHTML = '<div class="roles-dropdown__empty">Nessun ruolo trovato</div>';
                return;
            }

            dropdown.innerHTML = filteredRoles.map(role => {
                const isSelected = selectedValues.includes(role.value);
                const className = isSelected ? 'roles-dropdown__item roles-dropdown__item--selected' : 'roles-dropdown__item';
                return `<div class="${className}" data-value="${escapeAttr(role.value)}">${escapeText(role.text)}</div>`;
            }).join('');

            // Event delegation per click items
            dropdown.querySelectorAll('.roles-dropdown__item').forEach(item => {
                item.addEventListener('click', () => {
                    const value = item.getAttribute('data-value');
                    toggleRoleSelection(value);
                });
            });
        }

        // Render badges da select hidden
        function renderBadges() {
            const selectedOptions = Array.from(rolesSelect.selectedOptions);

            if (selectedOptions.length === 0) {
                badgesContainer.innerHTML = '';
                return;
            }

            badgesContainer.innerHTML = selectedOptions.map(option => {
                return `
                    <span class="role-badge">
                        <span class="role-badge-text">${escapeText(option.textContent)}</span>
                        <button
                            type="button"
                            class="role-badge-remove"
                            data-role-id="${escapeAttr(option.value)}"
                            data-tooltip="Rimuovi"
                            aria-label="Rimuovi ${escapeAttr(option.textContent)}">
                            ✕
                        </button>
                    </span>
                `;
            }).join('');

            // Event delegation per X badge
            badgesContainer.querySelectorAll('.role-badge-remove').forEach(btn => {
                btn.addEventListener('click', () => {
                    const roleId = btn.getAttribute('data-role-id');
                    deselectRole(roleId);
                });
            });
        }

        // Toggle selection (select/deselect)
        function toggleRoleSelection(value) {
            const option = rolesSelect.querySelector(`option[value="${CSS.escape(value)}"]`);
            if (!option) return;

            option.selected = !option.selected;
            renderBadges();
            renderDropdown(searchInput.value); // Refresh dropdown per aggiornare stato
        }

        // Deselect role
        function deselectRole(value) {
            const option = rolesSelect.querySelector(`option[value="${CSS.escape(value)}"]`);
            if (option) {
                option.selected = false;
                renderBadges();
                renderDropdown(searchInput.value);
            }
        }

        // Get selected values
        function getSelectedValues() {
            return Array.from(rolesSelect.selectedOptions).map(opt => opt.value);
        }

        // Open/Close dropdown
        function openDropdown() {
            isOpen = true;
            dropdown.hidden = false;
            searchInput.setAttribute('aria-expanded', 'true');
            renderDropdown(searchInput.value);
        }

        function closeDropdown() {
            isOpen = false;
            dropdown.hidden = true;
            searchInput.setAttribute('aria-expanded', 'false');
            searchInput.value = '';
        }

        // Events: input focus/click
        searchInput.addEventListener('focus', openDropdown);
        searchInput.addEventListener('click', openDropdown);

        // Events: toggle button
        if (toggleBtn) {
            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (isOpen) {
                    closeDropdown();
                } else {
                    searchInput.focus();
                    openDropdown();
                }
            });
        }

        // Events: input keyup (filter)
        searchInput.addEventListener('input', () => {
            if (isOpen) {
                renderDropdown(searchInput.value);
            }
        });

        // Events: keydown ESC
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isOpen) {
                closeDropdown();
                searchInput.blur();
            }
        });

        // Events: click outside
        document.addEventListener('click', (e) => {
            if (isOpen && !searchInput.contains(e.target) && !dropdown.contains(e.target) && !toggleBtn?.contains(e.target)) {
                closeDropdown();
            }
        });

        // Escape helpers (usano DOM invece di regex)
        function escapeText(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function escapeAttr(text) {
            return text.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        init();
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Init overlay
        ContactsProfileOverlay.init();

        // Init roles multi-select with badges
        initRolesMultiSelect();

        // Refresh images
        refreshProfileImages();

        // Event delegation for contact cards
        const contactsContainer = document.getElementById('contacts-container');
        if (contactsContainer) {
            contactsContainer.addEventListener('click', function(e) {
                const card = e.target.closest('.contact-card');
                if (card) {
                    handleCardClick(card);
                }
            });
        }

        // Advanced filters toggle
        const toggleBtn = document.getElementById("advanced-filters-toggle");
        const advancedFilters = document.getElementById("advanced-filters");
        if (toggleBtn && advancedFilters) {
            toggleBtn.addEventListener("click", function() {
                const isHidden = advancedFilters.classList.contains("hidden");
                advancedFilters.classList.toggle("hidden", !isHidden);
                toggleBtn.textContent = isHidden ? "Nascondi Filtri Avanzati" : "Filtri Avanzati";
            });
        }

        // Filter form
        const filterForm = document.getElementById('filters-form-full');
        if (filterForm && contactsContainer) {
            filterForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                const formData = new FormData(filterForm);

                // Raccogli ruoli multipli
                const roles = formData.getAll('roles[]');

                try {
                    const result = await customFetch('contacts', 'getFilteredContacts', {
                        search: formData.get('search') || '',
                        department: formData.get('department') || '',
                        roles: roles,
                        area: formData.get('area') || '',
                        project: formData.get('project') || '',
                        seniority: formData.get('seniority') || ''
                    });

                    contactsContainer.innerHTML = '';

                    if (result.success && Array.isArray(result.data) && result.data.length > 0) {
                        result.data.forEach(contact => {
                            const card = document.createElement('div');
                            card.className = 'contact-card';
                            card.setAttribute('data-contact', JSON.stringify(contact));

                            card.innerHTML = `
                                <div class="contact-icon">
                                    <img src="${contact.profile_picture ? (contact.profile_picture.startsWith('data:') ? contact.profile_picture : '/' + contact.profile_picture.replace(/^\/+/, '').replace(/\.(jpg|jpeg|png)$/i, '.webp')) : '/assets/images/default_profile.png'}"
                                        data-nominativo="${contact.Nominativo}"
                                        class="profile-img"
                                        width="50" height="50"
                                        alt="Immagine di ${contact.Nominativo}"
                                        loading="lazy">
                                </div>
                                <div class="contact-details">
                                    <h3><img src="assets/icons/contact.png" class="icon"> ${contact.Nominativo}</h3>
                                    <p><img src="assets/icons/mail.png" class="icon"> ${contact.Email_Aziendale ?? 'N/D'}</p>
                                    <p><img src="assets/icons/telefono.png" class="icon"> ${contact.phone ?? 'N/A'}</p>
                                </div>
                            `;

                            contactsContainer.appendChild(card);
                        });
                        refreshProfileImages();
                    } else {
                        contactsContainer.innerHTML = '<p>Nessun contatto trovato.</p>';
                    }
                } catch (error) {
                    console.error('Errore nel recupero contatti filtrati:', error);
                    contactsContainer.innerHTML = '<p>Errore durante la richiesta dei contatti.</p>';
                }
            });

            // Reset filters button
            const resetBtn = document.getElementById('reset-filters');
            if (resetBtn) {
                resetBtn.addEventListener('click', () => {
                    filterForm.reset();
                    filterForm.dispatchEvent(new Event('submit'));
                });
            }
        }

        // Check URL for profile param on load
        const urlParams = new URLSearchParams(window.location.search);
        const profileId = urlParams.get('profile');
        if (profileId) {
            // Find contact card with this ID and open it
            const card = document.querySelector(`.contact-card[data-contact*='"user_id":${profileId}']`) ||
                         document.querySelector(`.contact-card[data-contact*='"user_id":"${profileId}"']`);
            if (card) {
                handleCardClick(card);
            }
        }
    });

    // Export for external use if needed
    window.ContactsProfileOverlay = ContactsProfileOverlay;
    window.handleCardClick = handleCardClick;
    window.filterContacts = filterContacts;
})();
