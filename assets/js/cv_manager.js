/**
 * CV Manager Module
 * Handles UI interactions for CV management
 */

var cvManager = {
    // State
    candidates: [],
    selectedFiles: [],
    isLoading: false,

    // Config
    endpoints: {
        list: 'ajax.php?section=cv&action=list',
        detail: 'ajax.php?section=cv&action=detail',
        upload: 'ajax.php', // POST with action=upload
        statistics: 'ajax.php?section=cv&action=statistics',
        professions: 'ajax.php?section=cv&action=professions',
        updateStatus: 'ajax.php?section=cv&action=update_status',
        compare: 'ajax.php?section=cv&action=compare'
    },

    // Initialization
    init: function () {
        this.bindEvents();
        this.loadList();
        this.loadProfessions(); // For filter
    },

    bindEvents: function () {
        // Navigation / Tabs
        document.querySelectorAll('.tab-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                // If it's a real tab switch, handle style
                // Note: The new layout uses class 'tab-link' inside 'tab-links'
                this.switchTab(e.currentTarget.dataset.target);
            });
        });

        // Search & Filters inputs - auto reload on change/typing
        const searchInput = document.getElementById('cv-search-input');
        if (searchInput) {
            let debounceTimer;
            searchInput.addEventListener('keyup', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => this.loadList(), 300);
            });
        }

        // Auto-filter on dropdown change
        ['cv-filter-profession', 'cv-filter-status', 'cv-filter-score'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', () => this.loadList());
        });

        const resetBtn = document.getElementById('btn-reset-filters');
        if (resetBtn) resetBtn.addEventListener('click', () => this.resetFilters());

        // Upload Zone events
        const uploadZone = document.getElementById('uploadZone');
        if (uploadZone) {
            uploadZone.addEventListener('dragover', (e) => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
            uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
            uploadZone.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadZone.classList.remove('drag-over');
                this.handleFiles(e.dataTransfer.files);
            });
            // Make the whole zone clickable for file selection
            uploadZone.addEventListener('click', (e) => {
                // Prevent trigger if clicking inner elements that might handle own click (though zone is main actor here)
                document.getElementById('fileInput').click();
            });
        }

        const fileInput = document.getElementById('fileInput');
        if (fileInput) fileInput.addEventListener('change', (e) => this.handleFiles(e.target.files));

        const uploadBtn = document.getElementById('uploadButton');
        if (uploadBtn) uploadBtn.addEventListener('click', () => this.uploadFiles());

        const clearBtn = document.getElementById('clearButton');
        if (clearBtn) clearBtn.addEventListener('click', () => this.clearFiles());

        // Export
        const exportBtn = document.getElementById('btn-export-excel');
        if (exportBtn) exportBtn.addEventListener('click', () => this.exportExcel());
    },

    // API Helper - UPDATED to robustly handle POST/GET and params
    apiCall: async function (endpoint, options = {}) {
        const tokenMeta = document.querySelector('meta[name="token-csrf"]');
        const token = tokenMeta ? tokenMeta.content : '';

        // Default to POST if not specified to allow body payload
        const defaultOptions = {
            method: 'POST', // Default POST
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        };

        const finalOptions = { ...defaultOptions, ...options };

        if (token) {
            finalOptions.headers['Csrf-Token'] = token;
        }

        let url = endpoint;

        // Handle Request Body or URL Params
        if (finalOptions.method === 'POST') {
            if (finalOptions.body && finalOptions.body instanceof FormData) {
                // FormData
                if (!finalOptions.body.has('csrf_token') && token) {
                    finalOptions.body.append('csrf_token', token);
                }
                // Do NOT set Content-Type header for FormData, browser does it with boundary
                if (finalOptions.headers['Content-Type']) delete finalOptions.headers['Content-Type'];
            } else {
                // JSON Body
                finalOptions.headers['Content-Type'] = 'application/json';
                let bodyObj = {};
                if (finalOptions.body) {
                    bodyObj = (typeof finalOptions.body === 'string') ? JSON.parse(finalOptions.body) : finalOptions.body;
                }
                if (!bodyObj.csrf_token && token) {
                    bodyObj.csrf_token = token;
                }
                finalOptions.body = JSON.stringify(bodyObj);
            }
        } else {
            // GET request
            // Append token to URL
            if (token) {
                const separator = url.includes('?') ? '&' : '?';
                url += `${separator}csrf_token=${token}`;
            }
        }

        try {
            const res = await fetch(url, finalOptions);
            const data = await res.json();
            if (!data.success && data.message === 'Utente non autenticato') {
                window.location.reload();
                return;
            }
            return data;
        } catch (err) {
            console.error('API Error:', err);
            this.showToast('Errore di connessione', 'danger');
            return { success: false, error: 'Errore di connessione' };
        }
    },

    // Tabs
    switchTab: function (targetId) {
        // Toggle view sections
        document.querySelectorAll('.cv-view-section').forEach(el => el.classList.add('d-none'));
        const targetEl = document.getElementById(targetId);
        if (targetEl) targetEl.classList.remove('d-none');

        // Toggle Active Class on Links
        document.querySelectorAll('.tab-link').forEach(el => el.classList.remove('active'));
        const activeLink = document.querySelector(`.tab-link[data-target="${targetId}"]`);
        if (activeLink) activeLink.classList.add('active');

        if (targetId === 'cv-view-stats') {
            this.loadStats();
        }
        if (targetId === 'cv-view-compare') {
            this.populateComparisonSelect();
        }
    },

    resetFilters: function () {
        document.getElementById('cv-search-input').value = '';
        document.getElementById('cv-filter-profession').value = '';
        document.getElementById('cv-filter-status').value = '';
        document.getElementById('cv-filter-score').value = '0';
        this.loadList();
    },

    // --- LIST & FILTERS ---
    loadList: async function () {
        if (this.isLoading) return;
        this.isLoading = true;

        const container = document.getElementById('cv-list-container');
        // Keep existing content or show spinner if empty
        if (!container.innerHTML.trim() || this.candidates.length === 0) {
            container.innerHTML = '<div class="cv-flex-center" style="grid-column: 1 / -1; padding: 40px;"><div class="cv-spinner"></div><p class="cv-text-muted mt-2">Caricamento...</p></div>';
        }

        const filters = {
            search: document.getElementById('cv-search-input').value,
            profession: document.getElementById('cv-filter-profession').value,
            status: document.getElementById('cv-filter-status').value,
            min_score: document.getElementById('cv-filter-score').value
        };

        // Send filters via POST body
        const result = await this.apiCall(this.endpoints.list, {
            method: 'POST',
            body: filters
        });

        this.isLoading = false;

        if (result.success) {
            this.candidates = result.data;
            this.renderList(this.candidates);
            this.populateComparisonSelect();
        } else {
            container.innerHTML = `<div style="grid-column: 1/-1;" class="cv-alert cv-alert-danger">${result.error || result.message || 'Errore'}</div>`;
            this.showToast(result.message || 'Errore caricamento lista', 'danger');
        }
    },

    renderList: function (list) {
        const container = document.getElementById('cv-list-container');
        if (!list || list.length === 0) {
            container.innerHTML = '<div style="grid-column: 1/-1; padding: 20px;" class="cv-alert cv-alert-info">Nessun candidato trovato con questi filtri.</div>';
            return;
        }

        let html = '';
        list.forEach(c => {
            const hasWarnings = c.warnings && c.warnings.length > 0;
            const warningBadge = hasWarnings
                ? `<span class="cv-badge-warn" data-warnings="1">⚠ Da verificare</span>`
                : '';

            html += `
            <div class="cv-card">
                <div class="cv-card-body">
                    <div class="cv-flex-between mb-2" style="margin-bottom: 10px;">
                        <h5 class="cv-card-title">${c.nome || '<span class="cv-badge-muted">Non rilevato</span>'} ${c.cognome || ''}</h5>
                        <span class="cv-badge ${this.getScoreBadgeClass(c.score_totale)}">${c.score_totale}</span>
                    </div>
                    <h6 class="cv-card-subtitle">${c.professionalita || '<span class="cv-badge-muted">Non rilevato</span>'}</h6>
                    <p class="cv-card-text">
                        <i class="bi bi-envelope"></i> ${(typeof c.email === 'string' && c.email.trim() !== '') ? c.email : '<span class="cv-badge-muted">Non rilevato</span>'}<br>
                        <i class="bi bi-telephone"></i> ${(typeof c.telefono === 'string' && c.telefono.trim() !== '') ? c.telefono : '<span class="cv-badge-muted">Non rilevato</span>'}<br>
                        <i class="bi bi-geo-alt"></i> ${(typeof c.citta === 'string' && c.citta.trim() !== '') ? c.citta : '<span class="cv-badge-muted">Non rilevato</span>'}
                    </p>
                    <div style="margin-top: 10px;">
                        <span class="cv-badge ${this.getStatusBadgeClass(c.stato)}">${this.formatStatus(c.stato)}</span>
                        ${warningBadge}
                    </div>
                </div>
                <div class="cv-card-footer">
                    <button class="button button-sm" onclick="cvManager.loadDetail(${c.id})">
                        <i class="bi bi-eye"></i> Dettagli
                    </button>
                    <button class="button button-sm" onclick="showPdf('download_cv.php?id=${c.id}')">
                        <i class="bi bi-file-pdf"></i> CV
                    </button>
                </div>
            </div>`;
        });

        container.innerHTML = html;

        container.querySelectorAll('[data-warnings="1"]').forEach((el, idx) => {
            // Recupera candidato corrispondente: stesso ordine del loop
            const c = list[idx];
            const warnings = Array.isArray(c && c.warnings) ? c.warnings : [];
            // Tooltip safe: niente HTML, solo testo
            el.title = warnings.map(w => String(w)).join('\n');
        });
    },

    loadProfessions: async function () {
        const res = await this.apiCall(this.endpoints.professions);
        if (res.success) {
            const sel = document.getElementById('cv-filter-profession');
            if (!sel) return;
            // Keep first option "Tutte"
            const firstOpt = sel.options[0];
            sel.innerHTML = '';
            sel.appendChild(firstOpt);

            res.data.forEach(p => {
                sel.innerHTML += `<option value="${p}">${p}</option>`;
            });
        }
    },

    // --- DETAIL ---
    loadDetail: async function (id) {
        // Logic: switch to detail view container. 
        // We use view switching logic manually here to handle history or just simple hiding

        document.getElementById('cv-view-list').classList.add('d-none');
        document.getElementById('cv-view-stats').classList.add('d-none');
        document.getElementById('cv-view-compare').classList.add('d-none');


        const detailView = document.getElementById('cv-view-detail');
        detailView.classList.remove('d-none');

        const content = document.getElementById('cv-detail-content');
        content.innerHTML = '<div class="cv-flex-center" style="padding:50px;"><div class="cv-spinner"></div></div>';

        // Use POST to ensure ID is passed in body
        const res = await this.apiCall(this.endpoints.detail, {
            method: 'POST',
            body: { id: id }
        });

        if (res.success) {
            this.renderDetail(res.data);
        } else {
            this.showToast('Errore caricamento dettaglio', 'danger');
            this.backToList();
        }
    },

    backToList: function () {
        document.getElementById('cv-view-detail').classList.add('d-none');
        // Restore active tab. Since 'Elenco' is the main one, we usually go back to List.
        // We can check which tab was active or just default to list.
        this.switchTab('cv-view-list');
    },

    renderDetail: function (c) {
        const hasWarnings = c.warnings && c.warnings.length > 0;
        const hasProjects = c.progetti && c.progetti.length > 0;

        // Safe text escaping for XSS prevention
        const esc = (str) => {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        };

        // Build HTML for detail
        let html = `
        <div class="cv-row" style="align-items: flex-start;">
            <div class="cv-col" style="flex: 2;">
                <div class="cv-card" style="margin-bottom: 20px;">
                    <div class="cv-card-body">
                        <div class="cv-flex-between">
                            <h1>${esc(c.nome) || '<span class="cv-badge-muted">Non rilevato</span>'} ${esc(c.cognome) || ''}</h1>
                            <div class="cv-text-center">
                                <div class="cv-badge ${this.getScoreBadgeClass(c.score_totale)}" style="font-size: 1.2rem;">${c.score_totale}</div>
                                <div class="small cv-text-muted mt-1">Score</div>
                            </div>
                        </div>
                        <h4 class="cv-text-primary" style="margin-top:5px;">${esc(c.professionalita) || '<span class="cv-badge-muted">Non rilevato</span>'}</h4>

                        ${c.professional_profile ? `
                        <div class="cv-alert cv-alert-info" style="margin-top: 15px; font-style: italic;">
                            ${esc(c.professional_profile)}
                        </div>
                        ` : ''}

                        <div class="cv-row" style="margin-top: 15px;">
                            <div class="cv-col">
                                <p><i class="bi bi-envelope"></i> ${(typeof c.email === 'string' && c.email.trim() !== '') ? esc(c.email) : '<span class="cv-badge-muted">Non rilevato</span>'}</p>
                                <p><i class="bi bi-telephone"></i> ${(typeof c.telefono === 'string' && c.telefono.trim() !== '') ? esc(c.telefono) : '<span class="cv-badge-muted">Non rilevato</span>'}</p>
                            </div>
                            <div class="cv-col">
                                <p><i class="bi bi-geo-alt"></i> ${(typeof c.citta === 'string' && c.citta.trim() !== '') ? esc(c.citta) : '<span class="cv-badge-muted">Non rilevato</span>'} ${c.provincia ? `(${esc(c.provincia)})` : ''}</p>
                                <p><i class="bi bi-calendar3"></i> Inserito: ${c.data_inserimento ? c.data_inserimento.split(' ')[0] : '-'}</p>
                            </div>
                        </div>
                        <div style="margin-top: 10px;">
                             Stato: <span class="cv-badge ${this.getStatusBadgeClass(c.stato)}">${this.formatStatus(c.stato)}</span>
                             ${hasWarnings ? `<span class="cv-badge-warn cv-collapsible-toggle" onclick="cvManager.toggleWarnings()">⚠ Da verificare (${c.warnings.length})</span>` : ''}
                        </div>

                        ${hasWarnings ? `
                        <div id="cv-warnings-box" class="cv-collapsible" style="display:none; margin-top: 15px;">
                            <div class="cv-alert cv-alert-warning">
                                <strong>Da verificare:</strong>
                                <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                                    ${c.warnings.map(w => `<li>${esc(w)}</li>`).join('')}
                                </ul>
                            </div>
                        </div>
                        ` : ''}

                        ${c.note ? `<div class="cv-alert cv-alert-secondary" style="margin-top: 15px;"><strong>Note:</strong> ${esc(c.note)}</div>` : ''}
                    </div>
                </div>

                <!-- Esperienze (Job History Only) -->
                ${this.renderExperienceSection(c.esperienze, esc)}

                <!-- Istruzione -->
                ${this.renderSection('Istruzione', c.istruzione, (i) => `
                    <strong>${esc(i.titolo)}</strong> - <em>${esc(i.istituto)}</em><br>
                    <small class="cv-text-muted">${esc(i.tipo)}</small>
                `, esc)}

                <!-- Progetti / Referenze (NEW V2) -->
                ${hasProjects ? this.renderProjectsSection(c.progetti, esc) : ''}
            </div>

            <div class="cv-col" style="flex: 1;">
                <div class="cv-card" style="margin-bottom: 20px;">
                    <div class="cv-card-body">
                        <h5 class="cv-card-title">Azioni</h5>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <a href="download_cv.php?id=${c.id}" target="_blank" class="button button-primary" style="text-align:center;"><i class="bi bi-download"></i> Scarica CV</a>
                            <hr style="border-top:1px solid #eee; width:100%;">
                            <label class="text-label">Aggiorna Stato</label>
                            <select class="select-box" onchange="cvManager.updateStatus(${c.id}, this.value)" style="width:100%;">
                                <option value="nuovo" ${c.stato === 'nuovo' ? 'selected' : ''}>Nuovo</option>
                                <option value="in_valutazione" ${c.stato === 'in_valutazione' ? 'selected' : ''}>In Valutazione</option>
                                <option value="colloquio" ${c.stato === 'colloquio' ? 'selected' : ''}>Colloquio</option>
                                <option value="assunto" ${c.stato === 'assunto' ? 'selected' : ''}>Assunto</option>
                                <option value="scartato" ${c.stato === 'scartato' ? 'selected' : ''}>Scartato</option>
                            </select>
                            <hr style="border-top:1px solid #eee; width:100%;">
                             <button class="button button-secondary" style="width:100%;" onclick="cvManager.openCompareModal(${c.id}, '${esc(c.nome)} ${esc(c.cognome)}')">
                                <i class="bi bi-layout-split"></i> Confronta con...
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Skills -->
                 <div class="cv-card" style="margin-bottom: 20px;">
                    <div class="cv-card-body">
                        <h5 class="cv-card-title">Competenze</h5>
                        <div style="display:flex; flex-wrap:wrap; gap:5px;">
                            ${(c.competenze || []).map(s => `<span class="cv-badge bg-secondary">${esc(s.nome)}</span>`).join('')}
                            ${(!c.competenze || c.competenze.length === 0) ? '<span class="cv-badge-muted">Nessuna rilevata</span>' : ''}
                        </div>
                    </div>
                </div>

                <!-- Lingue -->
                <div class="cv-card" style="margin-bottom: 20px;">
                    <div class="cv-card-body">
                        <h5 class="cv-card-title">Lingue</h5>
                        ${(c.lingue && c.lingue.length > 0) ? c.lingue.map(l => `
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">
                                <span>${esc(l.lingua)}</span>
                                <span class="cv-badge ${l.livello === 'madrelingua' || l.livello === 'C2' || l.livello === 'C1' ? 'bg-success' : 'bg-info'}">${esc(l.livello)}</span>
                            </div>
                        `).join('') : '<span class="cv-badge-muted">Nessuna rilevata</span>'}
                    </div>
                </div>

                <!-- Certificazioni -->
                <div class="cv-card">
                    <div class="cv-card-body">
                        <h5 class="cv-card-title">Certificazioni</h5>
                        ${(c.certificazioni && c.certificazioni.length > 0) ? c.certificazioni.map(cert => `
                            <div style="margin-bottom:8px; padding:8px; background:#f8fafc; border-radius:6px;">
                                <strong style="font-size:0.9rem;">${esc(cert.nome)}</strong>
                                ${cert.data_rilascio ? `<br><small class="cv-text-muted">${cert.data_rilascio.split('-')[0]}</small>` : ''}
                            </div>
                        `).join('') : '<span class="cv-badge-muted">Nessuna rilevata</span>'}
                    </div>
                </div>
            </div>
        </div>`;

        document.getElementById('cv-detail-content').innerHTML = html;
    },

    // Toggle warnings box visibility
    toggleWarnings: function() {
        const box = document.getElementById('cv-warnings-box');
        if (box) {
            box.style.display = box.style.display === 'none' ? 'block' : 'none';
        }
    },

    // Render Esperienze with collapsible descriptions
    renderExperienceSection: function(esperienze, esc) {
        if (!esperienze || esperienze.length === 0) {
            return '';
        }

        let itemsHtml = esperienze.map((e, idx) => {
            const hasLongDesc = e.descrizione && e.descrizione.length > 240;
            const descId = `exp-desc-${idx}`;

            let descHtml = '';
            if (e.descrizione) {
                if (hasLongDesc) {
                    const shortDesc = e.descrizione.substring(0, 240) + '...';
                    descHtml = `
                        <div class="cv-collapsible-desc">
                            <small id="${descId}-short">${esc(shortDesc)}</small>
                            <small id="${descId}-full" style="display:none;">${esc(e.descrizione)}</small>
                            <a href="#" class="cv-desc-toggle" onclick="cvManager.toggleExpDesc('${descId}'); return false;">Mostra tutto</a>
                        </div>
                    `;
                } else {
                    descHtml = `<br><small>${esc(e.descrizione)}</small>`;
                }
            }

            return `
                <li class="cv-list-item">
                    <strong>${esc(e.posizione) || '<span class="cv-badge-muted">Ruolo non rilevato</span>'}</strong>
                    ${e.azienda ? ` presso <em>${esc(e.azienda)}</em>` : ''}<br>
                    <small class="cv-text-muted">${e.data_inizio || '?'} - ${e.data_fine || (e.in_corso ? 'In Corso' : '?')}</small>
                    ${descHtml}
                </li>
            `;
        }).join('');

        return `
        <div class="cv-card" style="margin-bottom: 20px;">
            <div class="cv-card-body">
                <h5 class="cv-card-title" style="border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 10px;">
                    <i class="bi bi-briefcase"></i> Esperienze Lavorative
                </h5>
                <ul class="cv-list-group">${itemsHtml}</ul>
            </div>
        </div>`;
    },

    // Toggle experience description
    toggleExpDesc: function(descId) {
        const shortEl = document.getElementById(descId + '-short');
        const fullEl = document.getElementById(descId + '-full');
        const toggle = shortEl.parentElement.querySelector('.cv-desc-toggle');

        if (shortEl.style.display !== 'none') {
            shortEl.style.display = 'none';
            fullEl.style.display = 'inline';
            toggle.textContent = 'Nascondi';
        } else {
            shortEl.style.display = 'inline';
            fullEl.style.display = 'none';
            toggle.textContent = 'Mostra tutto';
        }
    },

    // Render Projects Section (NEW V2)
    renderProjectsSection: function(progetti, esc) {
        if (!progetti || progetti.length === 0) {
            return '';
        }

        const formatImporto = (val) => {
            if (!val) return null;
            const num = parseInt(val);
            if (isNaN(num)) return null;
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M €';
            }
            if (num >= 1000) {
                return (num / 1000).toFixed(0) + 'K €';
            }
            return num.toLocaleString('it-IT') + ' €';
        };

        let projectsHtml = progetti.map((p, idx) => {
            const descId = `proj-desc-${idx}`;
            const hasLongDesc = p.descrizione_breve && p.descrizione_breve.length > 150;

            // Tags
            const tags = (p.tags && Array.isArray(p.tags)) ? p.tags : [];
            const tagsHtml = tags.length > 0
                ? `<div class="cv-project-tags">${tags.map(t => `<span class="cv-badge bg-light">${esc(t)}</span>`).join('')}</div>`
                : '';

            // Year range
            let yearRange = '';
            if (p.anno_inizio) {
                yearRange = p.anno_inizio.toString();
                if (p.anno_fine && p.anno_fine !== p.anno_inizio) {
                    yearRange += ' - ' + p.anno_fine;
                }
            }

            // Importo
            const importoStr = formatImporto(p.importo_euro);

            // Description (collapsible if long)
            let descHtml = '';
            if (p.descrizione_breve) {
                if (hasLongDesc) {
                    const shortDesc = p.descrizione_breve.substring(0, 150) + '...';
                    descHtml = `
                        <div class="cv-project-desc">
                            <span id="${descId}-short">${esc(shortDesc)}</span>
                            <span id="${descId}-full" style="display:none;">${esc(p.descrizione_breve)}</span>
                            <a href="#" class="cv-desc-toggle" onclick="cvManager.toggleProjDesc('${descId}'); return false;">Dettagli</a>
                        </div>
                    `;
                } else {
                    descHtml = `<div class="cv-project-desc">${esc(p.descrizione_breve)}</div>`;
                }
            }

            return `
            <div class="cv-project-card">
                <div class="cv-project-header">
                    <strong class="cv-project-title">${esc(p.nome) || 'Progetto senza nome'}</strong>
                    ${yearRange ? `<span class="cv-project-year">${yearRange}</span>` : ''}
                </div>
                <div class="cv-project-meta">
                    ${p.ruolo ? `<span><i class="bi bi-person-badge"></i> ${esc(p.ruolo)}</span>` : ''}
                    ${p.luogo ? `<span><i class="bi bi-geo-alt"></i> ${esc(p.luogo)}</span>` : ''}
                    ${importoStr ? `<span><i class="bi bi-currency-euro"></i> ${importoStr}</span>` : ''}
                    ${p.committente ? `<span><i class="bi bi-building"></i> ${esc(p.committente)}</span>` : ''}
                </div>
                ${descHtml}
                ${tagsHtml}
            </div>
            `;
        }).join('');

        return `
        <div class="cv-card" style="margin-bottom: 20px;">
            <div class="cv-card-body">
                <h5 class="cv-card-title" style="border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 10px;">
                    <i class="bi bi-folder2-open"></i> Progetti / Referenze (${progetti.length})
                </h5>
                <div class="cv-projects-grid">
                    ${projectsHtml}
                </div>
            </div>
        </div>`;
    },

    // Toggle project description
    toggleProjDesc: function(descId) {
        const shortEl = document.getElementById(descId + '-short');
        const fullEl = document.getElementById(descId + '-full');
        const toggle = shortEl.parentElement.querySelector('.cv-desc-toggle');

        if (shortEl.style.display !== 'none') {
            shortEl.style.display = 'none';
            fullEl.style.display = 'inline';
            toggle.textContent = 'Nascondi';
        } else {
            shortEl.style.display = 'inline';
            fullEl.style.display = 'none';
            toggle.textContent = 'Dettagli';
        }
    },

    renderSection: function (title, items, renderItemFn, escFn) {
        if (!items || items.length === 0) return '';
        // escFn is optional escape function for title (content is handled by renderItemFn)
        const safeTitle = escFn ? escFn(title) : title;
        return `
        <div class="cv-card" style="margin-bottom: 20px;">
            <div class="cv-card-body">
                <h5 class="cv-card-title" style="border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 10px;">${safeTitle}</h5>
                <ul class="cv-list-group">
                    ${items.map(item => `<li class="cv-list-item">${renderItemFn(item)}</li>`).join('')}
                </ul>
            </div>
        </div>`;
    },

    updateStatus: async function (id, status) {
        const res = await this.apiCall(this.endpoints.updateStatus, {
            method: 'POST',
            body: { id, status }
        });
        if (res.success) {
            this.showToast('Stato aggiornato', 'success');
            // We don't verify update in list immediately to avoid jitter, but could.
        } else {
            this.showToast('Errore aggiornamento', 'danger');
        }
    },

    // --- UPLOAD ---
    handleFiles: function (files) {
        this.selectedFiles = [...this.selectedFiles, ...Array.from(files)];
        this.renderFileList();
    },

    renderFileList: function () {
        const container = document.getElementById('fileListContainer');
        if (!container) return;

        if (this.selectedFiles.length === 0) {
            container.innerHTML = '';
            return;
        }

        let html = '<ul class="cv-list-group">';
        this.selectedFiles.forEach((f, idx) => {
            html += `
            <li class="cv-list-item">
                <span>${f.name} <small class="cv-text-muted">(${(f.size / 1024).toFixed(1)} KB)</small></span>
                <button class="button cv-btn-sm" onclick="cvManager.removeFile(${idx})"><i class="bi bi-x"></i></button>
            </li>`;
        });
        html += '</ul>';
        container.innerHTML = html;
        document.getElementById('uploadButtonContainer').classList.remove('d-none');
    },

    removeFile: function (idx) {
        this.selectedFiles.splice(idx, 1);
        this.renderFileList();
        if (this.selectedFiles.length === 0) {
            document.getElementById('uploadButtonContainer').classList.add('d-none');
        }
    },

    clearFiles: function () {
        this.selectedFiles = [];
        this.renderFileList();
        document.getElementById('uploadButtonContainer').classList.add('d-none');
        document.getElementById('uploadResults').innerHTML = '';
    },

    uploadFiles: function () {
        if (this.selectedFiles.length === 0) return;

        const formData = new FormData();
        formData.append('section', 'cv');
        formData.append('action', 'upload');

        this.selectedFiles.forEach(f => {
            formData.append('cv_files[]', f);
        });

        // Use standard apiCall for consistency? apiCall supports fetch, not XHR for progress easily.
        // We'll stick to XHR for upload progress, but we must manually handle CSRF
        const tokenMeta = document.querySelector('meta[name="token-csrf"]');
        if (tokenMeta) formData.append('csrf_token', tokenMeta.content);

        // UI Feedback
        const progressContainer = document.getElementById('progressContainer');
        const progressBar = document.getElementById('progressBar');
        progressContainer.classList.remove('d-none');
        progressBar.style.width = '0%';
        document.getElementById('uploadResults').innerHTML = '';

        const xhr = new XMLHttpRequest();
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = percent + '%';
            }
        });

        xhr.onload = () => {
            progressContainer.classList.add('d-none');
            if (xhr.status === 200) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    this.renderUploadResults(res);
                } catch (e) {
                    this.showToast('Errore parsing risposta server', 'danger');
                }
            } else {
                this.showToast('Errore upload', 'danger');
            }
        };

        xhr.onerror = () => {
            progressContainer.classList.add('d-none');
            this.showToast('Errore di rete', 'danger');
        };

        xhr.open('POST', this.endpoints.upload);
        xhr.send(formData);
    },

    renderUploadResults: function (res) {
        const container = document.getElementById('uploadResults');
        if (res.success || res.results) { // CvService returns success boolean and results array
            let html = `<div class="cv-alert cv-alert-success">Upload completato. Processati: ${res.processed}</div>`;
            if (res.results && res.results.length > 0) {
                html += '<div class="cv-list-group">';
                res.results.forEach(r => {
                    const icon = r.success ? '<i class="bi bi-check-circle cv-text-success"></i>' : '<i class="bi bi-exclamation-circle cv-text-danger"></i>';
                    html += `<div class="cv-list-item">
                        <span>${icon} <strong>${r.file || 'File'}</strong></span>
                        <span>${r.success ? 'OK - ' + r.nome : 'Errore - ' + r.error}</span>
                     </div>`;
                });
                html += '</div>';
            }
            container.innerHTML = html;
            this.clearFiles(); // Just clear array
            this.loadList(); // Refresh main list
        } else {
            container.innerHTML = `<div class="cv-alert cv-alert-danger">${res.message || 'Errore sconosciuto'}</div>`;
        }
    },

    // --- COMPARE WITH MODAL ---
    currentCompareId: null,
    compareSelection: [],

    openCompareModal: function (id, name) {
        this.currentCompareId = id;
        this.compareSelection = []; // Reset sub-selection

        let modal = document.getElementById('cvCompareModal');
        if (!modal) {
            this.createCompareModal();
            modal = document.getElementById('cvCompareModal');
        }

        // Update Title
        document.getElementById('compareModalTitle').textContent = `Confronta ${name} con...`;

        // Render List (exclude current ID)
        this.renderCompareList();

        modal.style.display = 'flex';
    },

    closeCompareModal: function () {
        const modal = document.getElementById('cvCompareModal');
        if (modal) modal.style.display = 'none';
        this.currentCompareId = null;
        this.compareSelection = [];
    },

    renderCompareList: function (filter = '') {
        const listContainer = document.getElementById('compareListContainer');
        const candidates = this.candidates.filter(c => c.id != this.currentCompareId);

        const filtered = filter
            ? candidates.filter(c => (c.nome + ' ' + c.cognome).toLowerCase().includes(filter.toLowerCase()))
            : candidates;

        if (filtered.length === 0) {
            listContainer.innerHTML = '<div class="cv-text-muted" style="padding:10px;">Nessun candidato disponibile.</div>';
            return;
        }

        let html = '<div class="cv-list-group" style="max-height: 400px; overflow-y: auto;">';
        filtered.forEach(c => {
            const isSelected = this.compareSelection.includes(c.id);
            html += `
            <div class="cv-list-item cv-flex-between" onclick="cvManager.toggleCompareSelect(${c.id})" style="cursor:pointer; background: ${isSelected ? '#f0f4ff' : 'transparent'}; border-left: ${isSelected ? '3px solid #667eea' : '3px solid transparent'};">
                <div>
                     <strong>${c.nome} ${c.cognome}</strong><br>
                     <small class="cv-text-muted">${c.professionalita} (${c.score_totale})</small>
                </div>
                <div class="cv-checkbox-wrapper">
                     ${isSelected ? '<i class="bi bi-check-circle-fill cv-text-primary" style="font-size:1.2rem;"></i>' : '<i class="bi bi-circle cv-text-muted" style="font-size:1.2rem;"></i>'}
                </div>
            </div>`;
        });
        html += '</div>';
        listContainer.innerHTML = html;

        // Disable button if 0 selected
        const btn = document.getElementById('btnStartCompare');
        if (btn) btn.disabled = this.compareSelection.length === 0;
    },

    toggleCompareSelect: function (id) {
        if (this.compareSelection.includes(id)) {
            this.compareSelection = this.compareSelection.filter(x => x !== id);
        } else {
            if (this.compareSelection.length >= 4) { // Limit: Current + 4 = 5 max
                this.showToast('Massimo 4 candidati aggiuntivi', 'warning');
                return;
            }
            this.compareSelection.push(id);
        }

        // Get search value to preserve filter
        const searchVal = document.getElementById('compareSearchInput').value;
        this.renderCompareList(searchVal);
    },

    startDetailedComparison: async function () {
        if (this.compareSelection.length === 0) return;

        const ids = [this.currentCompareId, ...this.compareSelection];

        // Reuse compareSelected logic but with specific IDs
        const res = await this.apiCall(this.endpoints.compare, {
            method: 'POST',
            body: { ids: ids.join(',') }
        });

        if (res.success) {
            this.renderComparison(res.data);
            this.closeCompareModal();
            this.switchTab('cv-view-compare');
        } else {
            this.showToast(res.error || 'Errore confronto', 'danger');
        }
    },

    createCompareModal: function () {
        const html = `
        <div id="cvCompareModal" class="cv-modal" style="display:none;">
            <div class="modal-content" style="max-width:600px; display: flex; flex-direction: column; max-height: 85vh;">
                <!-- Header -->
                <div style="padding: 20px; border-bottom: 1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
                     <h3 id="compareModalTitle" style="margin:0;">Confronta...</h3>
                     <span onclick="cvManager.closeCompareModal()" style="color:#aaa; font-size:28px; font-weight:bold; cursor:pointer;">&times;</span>
                </div>
                
                <!-- Search -->
                <div style="padding: 15px; background: #fafafa; border-bottom: 1px solid #eee;">
                    <input type="text" id="compareSearchInput" class="select-box" style="width:100%;" placeholder="Cerca collega da affiancare..." onkeyup="cvManager.renderCompareList(this.value)">
                </div>

                <!-- List -->
                <div id="compareListContainer" style="padding: 0; overflow-y: auto; flex: 1;">
                    <!-- Items -->
                </div>

                <!-- Footer -->
                <div style="padding: 20px; border-top: 1px solid #eee; text-align: right; background: #fff; border-radius: 0 0 12px 12px;">
                     <button class="button button-secondary" onclick="cvManager.closeCompareModal()" style="margin-right:10px;">Annulla</button>
                     <button id="btnStartCompare" class="button button-primary" onclick="cvManager.startDetailedComparison()" disabled>Avvia Confronto</button>
                </div>
            </div>
        </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
    },

    // --- STATS ---
    loadStats: async function () {
        const res = await this.apiCall(this.endpoints.statistics, { method: 'POST' });
        if (res.success) {
            // Render basic stats
            const stats = res.data;
            const container = document.getElementById('stats-container');
            container.innerHTML = `
            <div class="cv-row" style="text-align: center; gap: 20px;">
                <div class="cv-col"><div class="cv-stat-card"><h3>${stats.total}</h3><small>Totali</small></div></div>
                <div class="cv-col"><div class="cv-stat-card"><h3>${stats.new_candidates}</h3><small>Nuovi</small></div></div>
                <div class="cv-col"><div class="cv-stat-card"><h3>${stats.hired}</h3><small>Assunti</small></div></div>
                <div class="cv-col"><div class="cv-stat-card"><h3>${stats.avg_score}</h3><small>Score Medio</small></div></div>
            </div>
            `;

            // Render Charts
            if (stats.by_profession && document.getElementById('professionChart')) {
                this.createProfessionChart(stats.by_profession);
            }
            if (stats.score_distribution && document.getElementById('scoreChart')) {
                this.createScoreChart(stats.score_distribution);
            }
        }
    },

    createProfessionChart: function (data) {
        const ctx = document.getElementById('professionChart');
        // Destroy existing if any (to avoid overlay)
        if (this.professionChartInstance) this.professionChartInstance.destroy();

        if (typeof Chart === 'undefined') {
            console.error('Chart.js not loaded');
            return;
        }

        this.professionChartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(data),
                datasets: [{
                    data: Object.values(data),
                    backgroundColor: [
                        '#667eea', '#764ba2', '#f093fb', '#4facfe',
                        '#43e97b', '#fa709a', '#fee140', '#30cfd0',
                        '#ff9966', '#ff5e62', '#a18cd1', '#fbc2eb'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    }
                }
            }
        });
    },

    createScoreChart: function (data) {
        const ctx = document.getElementById('scoreChart');
        if (this.scoreChartInstance) this.scoreChartInstance.destroy();

        if (typeof Chart === 'undefined') return;

        const ranges = ['0-20', '21-40', '41-60', '61-80', '81-100'];
        const values = ranges.map(range => data[range] || 0);

        this.scoreChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ranges,
                datasets: [{
                    label: 'Numero Candidati',
                    data: values,
                    backgroundColor: '#667eea',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    },

    // Utils
    showToast: function (msg, type = 'info') {
        const alertClass = type === 'danger' ? 'cv-alert-danger' : (type === 'success' ? 'cv-alert-success' : 'cv-alert-info');
        const container = document.getElementById('cv-manager-app');
        const div = document.createElement('div');
        div.className = `cv-alert ${alertClass}`;
        div.style.position = 'fixed';
        div.style.top = '20px';
        div.style.right = '20px';
        div.style.zIndex = '9999';
        div.style.boxShadow = '0 4px 6px rgba(0,0,0,0.1)';
        div.innerHTML = `${msg} <button onclick="this.parentElement.remove()" style="background:none;border:none;float:right;cursor:pointer;font-weight:bold;">&times;</button>`;
        document.body.appendChild(div);
        setTimeout(() => div.remove(), 4000);
    },

    getScoreBadgeClass: function (score) {
        if (score >= 80) return 'bg-success';
        if (score >= 60) return 'bg-info';
        if (score >= 40) return 'bg-warning';
        return 'bg-danger';
    },

    getStatusBadgeClass: function (status) {
        const map = {
            'nuovo': 'bg-info',
            'in_valutazione': 'bg-warning',
            'colloquio': 'bg-primary',
            'assunto': 'bg-success',
            'scartato': 'bg-dark'
        };
        return map[status] || 'bg-secondary';
    },

    formatStatus: function (status) {
        if (!status) return '';
        return status.replace(/_/g, ' ').toUpperCase();
    },

    // --- COMPARISON ---
    populateComparisonSelect: function () {
        const sel = document.getElementById('comparisonSelect');
        if (!sel) return;

        // Preserve selection or re-render? Re-render simple for now.
        const currentSelected = Array.from(sel.selectedOptions).map(o => o.value);

        sel.innerHTML = '';
        this.candidates.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.text = `${c.nome} ${c.cognome} (${c.professionalita} - Score: ${c.score_totale})`;
            if (currentSelected.includes(c.id.toString())) opt.selected = true;
            sel.appendChild(opt);
        });
    },

    compareSelected: async function () {
        const sel = document.getElementById('comparisonSelect');
        const selected = Array.from(sel.selectedOptions).map(opt => opt.value);

        if (selected.length < 2) {
            this.showToast('Seleziona almeno 2 candidati', 'warning');
            return;
        }
        if (selected.length > 5) {
            this.showToast('Massimo 5 candidati', 'warning');
            return;
        }

        const res = await this.apiCall(this.endpoints.compare, {
            method: 'POST',
            body: { ids: selected.join(',') }
        });

        if (res.success) {
            this.renderComparison(res.data);
        } else {
            this.showToast(res.error || 'Errore confronto', 'danger');
        }
    },

    renderComparison: function (data) {
        if (!data || data.length === 0) return;
        const container = document.getElementById('comparisonContainer');

        // Helper: Initials
        const getInitials = (n, c) => `${(n || '').charAt(0)}${(c || '').charAt(0)}`.toUpperCase();

        // Helper: Find Max for highlighting
        const scores = data.map(c => parseFloat(c.score_totale) || 0);
        const maxScore = Math.max(...scores);

        const expMonths = data.map(c => parseInt(c.mesi_totali_esperienza) || 0);
        const maxExp = Math.max(...expMonths);

        // Helper: Format Experience
        const formatExp = (m) => {
            if (!m) return '-';
            const y = Math.floor(m / 12);
            const mo = m % 12;
            return y > 0 ? `${y} anni ${mo > 0 ? mo + ' mesi' : ''}` : `${mo} mesi`;
        };

        let html = '<div class="cv-compare-container"><table class="cv-compare-table">';

        // --- HEADER ---
        html += '<thead><tr>';
        html += '<th style="vertical-align: middle; text-align: left;"><h4 style="margin:0; color:#4a5568;">Confronto</h4><small class="cv-text-muted">Analisi dettagliata</small></th>';

        data.forEach(c => {
            const isWinner = (parseFloat(c.score_totale) || 0) === maxScore;
            html += `<th>
                <div class="cv-candidate-header">
                    <div class="cv-avatar-initials">${getInitials(c.nome, c.cognome)}</div>
                    <div class="cv-candidate-name">${c.nome} ${c.cognome}</div>
                    <div class="cv-candidate-role">${c.professionalita}</div>
                    <div class="cv-score-badge-lg ${this.getScoreBadgeClass(c.score_totale)}" 
                         style="${isWinner ? 'box-shadow: 0 0 15px rgba(251, 191, 36, 0.5); border: 2px solid #fbbf24;' : ''}">
                        ${c.score_totale}
                    </div>
                </div>
            </th>`;
        });
        html += '</tr></thead><tbody>';

        // --- ROWS Configuration ---
        const rows = [
            {
                label: 'Esperienza',
                render: (c) => formatExp(c.mesi_totali_esperienza),
                isWinner: (c) => (parseInt(c.mesi_totali_esperienza) || 0) === maxExp && maxExp > 0
            },
            {
                label: 'Livello Studio',
                render: (c) => `<div class="cv-text-center"><strong>${c.num_titoli_studio}</strong><br><small class="cv-text-muted">Titoli conseguiti</small></div>`
            },
            {
                label: 'Competenze',
                render: (c) => {
                    let tags = '';
                    if (c.competenze_detail && c.competenze_detail.length > 0) {
                        tags = '<div class="cv-skills-grid">';
                        c.competenze_detail.slice(0, 8).forEach(s => {
                            tags += `<span class="cv-badge bg-secondary" style="font-size:0.75rem;">${s.nome}</span>`;
                        });
                        if (c.competenze_detail.length > 8) tags += `<span class="cv-badge bg-secondary">+${c.competenze_detail.length - 8}</span>`;
                        tags += '</div>';
                    } else {
                        tags = '-';
                    }
                    return tags;
                }
            },
            {
                label: 'Lingue',
                render: (c) => {
                    let list = '';
                    if (c.lingue_detail && c.lingue_detail.length > 0) {
                        // Order by level relevance roughly
                        c.lingue_detail.forEach(l => {
                            let badgeClass = 'bg-secondary';
                            if (['C2', 'C1', 'Madrelingua'].includes(l.livello)) badgeClass = 'bg-success text-white';
                            else if (['B2'].includes(l.livello)) badgeClass = 'bg-info text-white';

                            list += `<div style="margin-bottom:4px; display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:4px 8px; border-radius:6px;">
                                <span>${l.lingua}</span>
                                <span class="cv-badge ${badgeClass}" style="font-size:0.7rem;">${l.livello}</span>
                             </div>`;
                        });
                    } else {
                        list = '-';
                    }
                    return list;
                }
            },
            {
                label: 'Stato Attuale',
                render: (c) => this.formatStatus(c.stato)
            }
        ];

        // --- RENDER ROWS ---
        rows.forEach(r => {
            html += `<tr><td>${r.label}</td>`;
            data.forEach(c => {
                const isWin = r.isWinner ? r.isWinner(c) : false;
                html += `<td class="${isWin ? 'cv-cell-winner' : ''}">${r.render(c)}</td>`;
            });
            html += '</tr>';
        });

        html += '</tbody></table></div>';

        // Add footer for actions (like "Hire", "Archive", etc - placeholder)
        html += `<div style="margin-top:20px; text-align:right;">
            <button class="button button-secondary" onclick="cvManager.switchTab('cv-view-list')"><i class="bi bi-arrow-left"></i> Torna alla lista</button>
        </div>`;

        container.innerHTML = html;
    },

    // --- FILE UPLOAD HANDLERS ---
    handleFiles: function (files) {
        this.selectedFiles = Array.from(files);
        this.displayFileList();
    },

    clearFiles: function () {
        this.selectedFiles = [];
        const fileInput = document.getElementById('fileInput');
        if (fileInput) fileInput.value = '';
        this.displayFileList();
        const resultsContainer = document.getElementById('uploadResults');
        if (resultsContainer) resultsContainer.innerHTML = '';
    },

    displayFileList: function () {
        const container = document.getElementById('fileListContainer');
        const buttonContainer = document.getElementById('uploadButtonContainer');

        if (!container) return;

        if (this.selectedFiles.length === 0) {
            container.innerHTML = '';
            if (buttonContainer) buttonContainer.classList.add('d-none');
            return;
        }

        if (buttonContainer) buttonContainer.classList.remove('d-none');

        let html = '<h5 style="margin-bottom: 10px;">File selezionati (' + this.selectedFiles.length + '):</h5>';
        this.selectedFiles.forEach((file, idx) => {
            const sizeMB = (file.size / 1024 / 1024).toFixed(2);
            html += `
                <div style="display:flex; justify-content:space-between; align-items:center; padding:8px; background:#f8fafc; border-radius:6px; margin-bottom:6px;">
                    <div>
                        <i class="bi bi-file-earmark-pdf"></i>
                        <strong>${file.name}</strong>
                        <span class="cv-text-muted" style="margin-left:10px;">${sizeMB} MB</span>
                    </div>
                    <button class="button" style="background:#ef4444; color:white; padding:6px 12px;" onclick="cvManager.removeFile(${idx})">
                        <i class="bi bi-trash"></i> Rimuovi
                    </button>
                </div>
            `;
        });

        container.innerHTML = html;
    },

    removeFile: function (index) {
        this.selectedFiles.splice(index, 1);
        this.displayFileList();
    },

    uploadFiles: async function () {
        if (this.selectedFiles.length === 0) {
            this.showToast('Seleziona almeno un file', 'warning');
            return;
        }

        const formData = new FormData();
        this.selectedFiles.forEach(file => {
            formData.append('cv_files[]', file);
        });

        // Add section/action for routing
        formData.append('section', 'cv');
        formData.append('action', 'upload');

        // Show progress
        const progressContainer = document.getElementById('progressContainer');
        const progressBar = document.getElementById('progressBar');
        const resultsContainer = document.getElementById('uploadResults');

        if (progressContainer) progressContainer.classList.remove('d-none');
        if (progressBar) progressBar.style.width = '0%';
        if (resultsContainer) resultsContainer.innerHTML = '';

        // Disable upload button
        const uploadBtn = document.getElementById('uploadButton');
        if (uploadBtn) {
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Elaborazione...';
        }

        try {
            // Simulate progress (since we can't track real upload progress with fetch)
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += 10;
                if (progress <= 90 && progressBar) {
                    progressBar.style.width = progress + '%';
                }
            }, 200);

            const response = await this.apiCall('ajax.php', {
                method: 'POST',
                body: formData,
                isFormData: true
            });

            clearInterval(progressInterval);
            if (progressBar) progressBar.style.width = '100%';

            // Hide progress after short delay
            setTimeout(() => {
                if (progressContainer) progressContainer.classList.add('d-none');
            }, 500);

            // Display results
            this.displayUploadResults(response);

            // Reload list to show new candidates
            this.loadList();

        } catch (error) {
            console.error('Upload error:', error);
            if (resultsContainer) {
                resultsContainer.innerHTML = '<div class="cv-alert cv-alert-danger">Errore durante l\'upload. Riprova.</div>';
            }
        } finally {
            // Re-enable button
            if (uploadBtn) {
                uploadBtn.disabled = false;
                uploadBtn.innerHTML = '<i class="bi bi-play-circle"></i> Avvia Analisi';
            }
        }
    },

    displayUploadResults: function (response) {
        const container = document.getElementById('uploadResults');
        if (!container) return;

        if (!response || !response.success) {
            container.innerHTML = `
                <div class="cv-alert cv-alert-danger">
                    <strong>Errore!</strong> ${response?.error || response?.message || 'Errore sconosciuto'}
                </div>
            `;
            return;
        }

        let html = `
            <div class="cv-alert cv-alert-success">
                <strong>✓ Elaborazione completata!</strong><br>
                File processati: ${response.processed || 0}/${response.total_files || 0}
                ${response.failed > 0 ? `<br><span style="color:#dc2626;">File con errori: ${response.failed}</span>` : ''}
            </div>
        `;

        if (response.results && response.results.length > 0) {
            html += '<h5 style="margin-top:15px;">Risultati:</h5>';
            response.results.forEach(result => {
                if (result.success) {
                    html += `
                        <div style="padding:10px; background:#f0fdf4; border-left:3px solid #10b981; border-radius:6px; margin-bottom:8px;">
                            <strong style="color:#047857;"><i class="bi bi-check-circle-fill"></i> ${result.nome || result.file}</strong><br>
                            <small class="cv-text-muted">Professionalità: ${result.professionalita || 'N/D'} | Score: ${result.score || 0}</small>
                        </div>
                    `;
                } else {
                    html += `
                        <div style="padding:10px; background:#fef2f2; border-left:3px solid #ef4444; border-radius:6px; margin-bottom:8px;">
                            <strong style="color:#dc2626;"><i class="bi bi-x-circle-fill"></i> ${result.file}</strong><br>
                            <small>${result.error || 'Errore sconosciuto'}</small>
                        </div>
                    `;
                }
            });
        }

        html += `
            <div style="text-align:right; margin-top:15px;">
                <button class="button button-primary" onclick="cvManager.closeUploadModal()">
                    <i class="bi bi-check-lg"></i> Chiudi
                </button>
            </div>
        `;

        container.innerHTML = html;

        // Clear selected files
        this.selectedFiles = [];
        const fileInput = document.getElementById('fileInput');
        if (fileInput) fileInput.value = '';
        const fileListContainer = document.getElementById('fileListContainer');
        if (fileListContainer) fileListContainer.innerHTML = '';
        const buttonContainer = document.getElementById('uploadButtonContainer');
        if (buttonContainer) buttonContainer.classList.add('d-none');
    },

    // --- MODAL UPLOAD ---
    openUploadModal: function () {
        let modal = document.getElementById('cvUploadModal');
        if (!modal) {
            this.createUploadModal();
            modal = document.getElementById('cvUploadModal');
        }
        modal.style.display = 'flex'; // Centering requires flex

        // Reset state
        this.clearFiles();
    },

    closeUploadModal: function () {
        const modal = document.getElementById('cvUploadModal');
        if (modal) modal.style.display = 'none';
        this.clearFiles();
    },

    createUploadModal: function () {
        const html = `
        <div id="cvUploadModal" class="cv-modal" style="display:none;">
            <div class="cv-modal-content">
                <span class="close" onclick="cvManager.closeUploadModal()" style="color:#aaa; float:right; font-size:28px; font-weight:bold; cursor:pointer;">&times;</span>
                <h2><i class="bi bi-cloud-upload"></i> Carica nuovi CV</h2>
                <hr style="margin:10px 0 20px 0;">
                
                <div id="uploadZone" style="border: 2px dashed #ccc; border-radius: 8px; padding: 40px; text-align: center; background: #fafafa; cursor: pointer; transition: all 0.2s;">
                    <i class="bi bi-cloud-arrow-up" style="font-size: 3rem; color: #667eea;"></i>
                    <h3 style="margin-top: 10px;">Trascina qui i tuoi CV</h3>
                    <p class="cv-text-muted">Supporta PDF, DOCX, DOC, MSG</p>
                    <input type="file" id="fileInput" class="d-none" multiple accept=".pdf,.doc,.docx,.msg">
                    <button class="button" style="margin-top: 15px;" onclick="document.getElementById('fileInput').click()">Seleziona File</button>
                </div>

                <div id="fileListContainer" style="margin-top: 20px;"></div>

                <div id="uploadButtonContainer" class="d-none cv-text-right" style="margin-top: 20px; text-align:right;">
                    <button id="clearButton" class="button button-secondary" style="margin-right: 10px;">Pulisci</button>
                    <button id="uploadButton" class="button button-primary"><i class="bi bi-play-circle"></i> Avvia Analisi</button>
                </div>

                <div id="progressContainer" class="cv-progress d-none" style="margin-top:20px; background-color: #f3f3f3; border-radius: 13px; padding: 3px;">
                    <div id="progressBar" class="cv-progress-bar" style="width: 0%; background-color: #667eea; height: 20px; border-radius: 10px; transition: width 0.3s;"></div>
                </div>

                <div id="uploadResults" style="margin-top: 20px;"></div>
            </div>
        </div>
        `;
        document.body.insertAdjacentHTML('beforeend', html);

        // Re-bind events for the new modal elements
        const uploadZone = document.getElementById('uploadZone');
        uploadZone.addEventListener('dragover', (e) => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
        uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('drag-over');
            this.handleFiles(e.dataTransfer.files);
        });

        document.getElementById('fileInput').addEventListener('change', (e) => this.handleFiles(e.target.files));
        document.getElementById('uploadButton').addEventListener('click', () => this.uploadFiles());
        document.getElementById('clearButton').addEventListener('click', () => this.clearFiles());
    }
};

// Auto init
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('cv-manager-app')) {
        cvManager.init();
    }
});
