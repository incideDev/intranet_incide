<?php
if (!defined('hostdbdataconnector'))
    define('hostdbdataconnector', true);
if (!defined('accessofileinterni'))
    define('accessofileinterni', true);

if ($Session->logged_in !== true) {
    header("Location: /index");
    exit;
}

if (!checkPermissionOrWarn('view_gare'))
    return;
?>
<link rel="stylesheet" href="/assets/css/gare.css">

<div class="main-container page-requisiti">
    <?php renderPageTitle('Requisiti', '#3498DB'); ?>

    <div class="requisiti-container">
        <!-- Tab Navigation -->
        <div class="requisiti-tabs">
            <button class="requisiti-tab active" data-tab="fatturato">
                Fatturato
            </button>
            <button class="requisiti-tab" data-tab="comprovanti">
                Comprovanti
            </button>
            <button class="requisiti-tab" data-tab="personale">
                Personale
            </button>
        </div>

        <!-- Tab Content -->
        <div class="requisiti-tab-content">
            <!-- Scheda Fatturato -->
            <div id="tab-fatturato" class="requisiti-tab-pane active">
                <div class="requisiti-tab-header">
                    <h2>Fatturato</h2>
                    <button id="btn-add-fatturato" class="button btn-add">+ Aggiungi Anno</button>
                </div>

                <div class="requisiti-tab-body">
                    <div class="fatturato-layout">
                        <div class="fatturato-main">
                            <div class="table-container">
                                <table id="fatturatoTable" class="table table-filterable"
                                    data-columns='[{"key":"anno","label":"Anno","filter":true,"sortable":true},{"key":"fatturato","label":"Fatturato","filter":true,"sortable":true}]'
                                    data-default-sort="anno" data-default-dir="desc" data-page-size="10">
                                    <thead>
                                        <tr>
                                            <th class="anno-colonna">Anno</th>
                                            <th class="importo-colonna">Fatturato</th>
                                            <th class="azioni-colonna">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Dati caricati via AJAX -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <aside class="fatturato-sidebar">
                            <div class="fatturato-panel">
                                <div class="panel-header">
                                    <h3 class="panel-title">RIEPILOGO FATTURATO</h3>
                                </div>
                                <p class="panel-description">
                                    Calcoli automatici basati sulla tabella a sinistra
                                </p>

                                <!-- Tre righe FATTURATO ULTIMI -->
                                <div class="fatturato-summary-row">
                                    <div class="summary-label">FATTURATO ULTIMI</div>
                                    <div class="summary-select-disabled">
                                        <span>10</span>
                                        <span class="select-caret">▾</span>
                                    </div>
                                    <div class="summary-value" id="sum-last-10">—</div>
                                </div>

                                <div class="fatturato-summary-row">
                                    <div class="summary-label">FATTURATO ULTIMI</div>
                                    <div class="summary-select-disabled">
                                        <span>5</span>
                                        <span class="select-caret">▾</span>
                                    </div>
                                    <div class="summary-value" id="sum-last-5">—</div>
                                </div>

                                <div class="fatturato-summary-row">
                                    <div class="summary-label">FATTURATO ULTIMI</div>
                                    <div class="summary-select-disabled">
                                        <span>3</span>
                                        <span class="select-caret">▾</span>
                                    </div>
                                    <div class="summary-value" id="sum-last-3">—</div>
                                </div>

                                <!-- Separatore -->
                                <hr class="fatturato-separator" />

                                <!-- Sezione Fatturato migliori -->
                                <div class="fatturato-best-section">
                                    <div class="fatturato-best-header-wrap">
                                        <div class="fatturato-input-group flex-1">
                                            <label class="input-label">MIGLIORI ANNI</label>
                                            <div class="fatturato-select-wrapper">
                                                <select id="best-anni" class="fatturato-select">
                                                    <option value="3">3</option>
                                                    <option value="5">5</option>
                                                    <option value="10">10</option>
                                                </select>
                                                <span class="select-caret">▾</span>
                                            </div>
                                        </div>

                                        <div class="summary-separator-text">
                                            su
                                        </div>

                                        <div class="fatturato-input-group flex-1">
                                            <label class="input-label">ULTIMI ANNI</label>
                                            <input type="number" id="best-progetti" class="fatturato-input" value="3"
                                                min="1" max="20" />
                                        </div>
                                    </div>

                                    <div class="fatturato-best-result">
                                        <div class="best-result-text" id="best-result-text">—</div>
                                        <div class="best-result-years" id="best-result-years"></div>
                                    </div>
                                </div>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>

            <!-- Scheda Comprovanti -->
            <div id="tab-comprovanti" class="requisiti-tab-pane">
                <div class="requisiti-tab-header">
                    <h2>Comprovanti</h2>
                </div>
                <div class="requisiti-tab-body">
                    <div class="comprovanti-layout">
                        <!-- COLONNA SINISTRA: Lista progetti -->
                        <div class="comprovanti-left">
                            <div class="comprovanti-filters">
                                <div class="comprovanti-filter-row">
                                    <input type="text" id="comprovanti-search" class="comprovanti-input"
                                        placeholder="Cerca per committente o descrizione...">
                                </div>
                                <div class="comprovanti-filter-row">
                                    <input type="text" id="comprovanti-filter-codice" class="comprovanti-input"
                                        placeholder="Codice commessa">
                                    <input type="text" id="comprovanti-filter-committente" class="comprovanti-input"
                                        placeholder="Committente">
                                </div>
                                <div class="comprovanti-filter-row">
                                    <input type="date" id="comprovanti-filter-data-inizio" class="comprovanti-input"
                                        placeholder="Data inizio">
                                    <input type="date" id="comprovanti-filter-data-fine" class="comprovanti-input"
                                        placeholder="Data fine">
                                    <button id="comprovanti-btn-cerca" class="button btn-primary">Cerca</button>
                                    <button id="comprovanti-btn-reset" class="button btn-secondary">Reset</button>
                                </div>
                            </div>
                            <div class="comprovanti-list-header">
                                <h3>Progetti Comprovanti</h3>
                                <button id="comprovanti-btn-nuovo-progetto" class="button btn-add">+ Nuovo
                                    Progetto</button>
                            </div>
                            <div id="comprovanti-progetti-list" class="comprovanti-progetti-list">
                                <p class="placeholder-text">Caricamento progetti...</p>
                            </div>
                            <div id="comprovanti-pagination" class="comprovanti-pagination"></div>
                        </div>

                        <!-- COLONNA DESTRA: Dettaglio progetto -->
                        <div class="comprovanti-right">
                            <div id="comprovanti-dettaglio" class="comprovanti-dettaglio">
                                <div class="comprovanti-dettaglio-placeholder">
                                    <p class="placeholder-text">Seleziona un progetto per visualizzare i dettagli</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scheda Personale -->
            <div id="tab-personale" class="requisiti-tab-pane">
                <div class="requisiti-tab-header">
                    <h2>Personale</h2>
                </div>
                <div class="requisiti-tab-body">
                    <div class="requisiti-personale-layout">
                        <div class="personale-main">
                            <div class="personale-gara-selector">
                                <label for="select-gara" class="gara-selector-label">Seleziona una gara:</label>
                                <select id="select-gara" class="gara-select">
                                    <option value="">-- Seleziona una gara --</option>
                                    <!-- Opzioni caricate via AJAX -->
                                </select>
                            </div>
                            <h3 class="personale-section-title">Requisiti di personale dal bando</h3>
                            <div id="requisiti-personale-list" class="requisiti-personale-list">
                                <p class="placeholder-text">
                                    Seleziona una gara per visualizzare i requisiti di personale.
                                </p>
                            </div>
                        </div>
                        <aside class="personale-sidebar">
                            <div class="personale-panel">
                                <div class="panel-header">
                                    <h3 class="panel-title">PERSONALE INTERNO</h3>
                                </div>
                                <p class="panel-description">
                                    Dati provenienti dalla tabella personale
                                </p>

                                <!-- Riepilogo numerico -->
                                <div class="personale-summary">
                                    <div class="summary-item">
                                        <span class="summary-label">Totale persone attive:</span>
                                        <span class="summary-value" id="personale-totale">—</span>
                                    </div>
                                    <div class="summary-item">
                                        <span class="summary-label">Filtrate:</span>
                                        <span class="summary-value" id="personale-filtrate">—</span>
                                    </div>
                                </div>

                                <!-- Filtri -->
                                <div class="personale-filters">
                                    <div class="filter-group">
                                        <label class="filter-label">Reparto</label>
                                        <select id="filter-reparto" class="personale-select">
                                            <option value="">Tutti</option>
                                        </select>
                                    </div>
                                    <div class="filter-group">
                                        <label class="filter-label">Ruolo</label>
                                        <select id="filter-ruolo" class="personale-select">
                                            <option value="">Tutti</option>
                                        </select>
                                    </div>
                                    <div class="filter-group">
                                        <label class="filter-label">Stabilimento</label>
                                        <select id="filter-stabilimento" class="personale-select">
                                            <option value="">Tutti</option>
                                        </select>
                                    </div>
                                    <div class="filter-group">
                                        <label class="filter-label">Cerca nominativo</label>
                                        <input type="text" id="filter-search" class="personale-input"
                                            placeholder="Cerca...">
                                    </div>
                                </div>

                                <!-- Elenco personale -->
                                <div id="personale-list" class="personale-list">
                                    <!-- Dati caricati via AJAX -->
                                </div>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tabs = document.querySelectorAll('.requisiti-tab');
        const panes = document.querySelectorAll('.requisiti-tab-pane');

        tabs.forEach(tab => {
            tab.addEventListener('click', function () {
                const targetTab = this.dataset.tab;

                // Rimuovi active da tutti i tab e panes
                tabs.forEach(t => t.classList.remove('active'));
                panes.forEach(p => p.classList.remove('active'));

                // Aggiungi active al tab e pane selezionati
                this.classList.add('active');
                const targetPane = document.getElementById('tab-' + targetTab);
                if (targetPane) {
                    targetPane.classList.add('active');

                    // Carica i dati quando la scheda diventa attiva
                    if (targetTab === 'fatturato' && !window.fatturatoTableInitialized) {
                        loadFatturatoTable();
                    } else if (targetTab === 'comprovanti' && !window.comprovantiInitialized) {
                        if (typeof window.initComprovanti === 'function') {
                            window.initComprovanti();
                        }
                    } else if (targetTab === 'personale' && !window.personaleInitialized) {
                        loadPersonaleData();
                    }
                }
            });
        });

        // Carica la tabella fatturato se la scheda è già attiva
        if (document.getElementById('tab-fatturato').classList.contains('active')) {
            loadFatturatoTable();
        }

        // Gestione Modal
        window.comprovantiOpenModal = function (modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.classList.add('show');
        };

        window.comprovantiCloseModal = function (modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.classList.remove('show');
        };

        // Gestione Fatturato CRUD
        const btnAdd = document.getElementById('btn-add-fatturato');
        if (btnAdd) {
            btnAdd.addEventListener('click', () => {
                document.getElementById('modal-fatturato-title').textContent = 'Aggiungi Fatturato';
                document.getElementById('fatturato-input-anno').value = new Date().getFullYear();
                document.getElementById('fatturato-input-anno').readOnly = false;
                document.getElementById('fatturato-input-importo').value = '';
                comprovantiOpenModal('modal-fatturato');
            });
        }

        const formFatturato = document.getElementById('form-fatturato');
        if (formFatturato) {
            formFatturato.addEventListener('submit', function (e) {
                e.preventDefault();
                const formData = new FormData(this);
                const data = Object.fromEntries(formData.entries());

                const fetchFn = window.customFetch || function () { };
                fetchFn('requisiti', 'saveFatturato', data)
                    .then(response => {
                        if (response.success) {
                            comprovantiCloseModal('modal-fatturato');
                            window.fatturatoTableInitialized = false;
                            loadFatturatoTable();
                        } else {
                            alert('Errore: ' + response.message);
                        }
                    });
            });
        }

        window.editFatturato = function (anno, fatturato) {
            document.getElementById('modal-fatturato-title').textContent = 'Modifica Fatturato';
            document.getElementById('fatturato-input-anno').value = anno;
            document.getElementById('fatturato-input-anno').readOnly = true;
            document.getElementById('fatturato-input-importo').value = fatturato;
            comprovantiOpenModal('modal-fatturato');
        };

        window.deleteFatturato = function (anno) {
            if (confirm(`Sei sicuro di voler eliminare il fatturato dell'anno ${anno}?`)) {
                const fetchFn = window.customFetch || function () { };
                fetchFn('requisiti', 'deleteFatturato', { anno: anno })
                    .then(response => {
                        if (response.success) {
                            window.fatturatoTableInitialized = false;
                            loadFatturatoTable();
                        } else {
                            alert('Errore: ' + response.message);
                        }
                    });
            }
        };


        function loadFatturatoTable() {
            if (window.fatturatoTableInitialized) return;

            const table = document.getElementById('fatturatoTable');
            if (!table) return;

            const fetchFn = window.customFetch || function (service, action, params) {
                return fetch('service_router.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'CSRF-Token': '<?= $_SESSION['CSRFtoken'] ?? '' ?>'
                    },
                    body: JSON.stringify({
                        section: service,
                        action: action,
                        ...params,
                        csrf_token: '<?= $_SESSION['CSRFtoken'] ?? '' ?>'
                    })
                }).then(r => r.json());
            };

            fetchFn('requisiti', 'getFatturatoAnnuale', {})
                .then(response => {
                    if (response.success && response.data) {
                        window.fatturatoTableInitialized = true;

                        const tbody = table.querySelector('tbody');
                        if (tbody) {
                            tbody.innerHTML = '';
                            response.data.forEach(row => {
                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>${row.anno}</td>
                                    <td class="fatturato-importo-valore">${row.fatturato_formatted}</td>
                                    <td class="comprovanti-actions-cell">
                                        <button class="comprovanti-btn-icon" onclick="editFatturato(${row.anno}, ${row.fatturato})" title="Modifica">
                                            <img src="assets/icons/edit.png" class="action-icon-small">
                                        </button>
                                        <button class="comprovanti-btn-icon delete" onclick="deleteFatturato(${row.anno})" title="Elimina">
                                            <img src="assets/icons/delete.png" class="action-icon-small">
                                        </button>
                                    </td>
                                `;
                                tbody.appendChild(tr);
                            });

                            if (typeof initTableFilters === 'function') {
                                initTableFilters('fatturatoTable');
                            }

                            if (typeof window.initClientSidePagination === 'function') {
                                window.initClientSidePagination(table);
                            }

                            if (typeof window.updateFatturatoSummary === 'function') {
                                setTimeout(window.updateFatturatoSummary, 100);
                            }
                        }
                    } else {
                        console.error('Errore nel caricamento dati:', response.message || 'Errore sconosciuto');
                    }
                })
                .catch(error => {
                    console.error('Errore nella richiesta:', error);
                });
        }

        // Funzioni per la gestione del personale
        let personaleData = [];
        let requisitiPersonaleData = [];

        function loadPersonaleData() {
            if (window.personaleInitialized) return;

            // Funzione helper per le chiamate AJAX
            function makeFetch(service, action, params) {
                // Se customFetch è disponibile, usalo (gestisce già CSRF token)
                if (window.customFetch) {
                    return window.customFetch(service, action, params);
                }

                // Altrimenti usa fetch standard
                const csrfToken = '<?= $_SESSION['CSRFtoken'] ?? '' ?>';
                return fetch('service_router.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        section: service,
                        action: action,
                        ...params,
                        csrf_token: csrfToken
                    })
                }).then(r => {
                    if (!r.ok) {
                        throw new Error(`HTTP error! status: ${r.status}`);
                    }
                    return r.json();
                }).catch(error => {
                    console.error('Errore nella fetch:', error);
                    return { success: false, message: 'Errore nella richiesta: ' + error.message };
                });
            }

            // Carica elenco gare per il dropdown
            makeFetch('requisiti', 'getElencoGare', {})
                .then(response => {
                    if (response.success && response.data) {
                        const selectGara = document.getElementById('select-gara');
                        if (selectGara) {
                            response.data.forEach(gara => {
                                const option = document.createElement('option');
                                option.value = gara.job_id;
                                option.textContent = gara.label;
                                selectGara.appendChild(option);
                            });

                            // Recupera job_id dall'URL se disponibile e seleziona la gara
                            const urlParams = new URLSearchParams(window.location.search);
                            const jobIdFromUrl = urlParams.get('job_id') ? parseInt(urlParams.get('job_id'), 10) : null;
                            if (jobIdFromUrl && jobIdFromUrl > 0) {
                                selectGara.value = jobIdFromUrl;
                                loadRequisitiPersonale(jobIdFromUrl);
                            }

                            // Listener per cambio selezione
                            selectGara.addEventListener('change', function () {
                                console.log('Gara selezionata, valore:', this.value);
                                const selectedJobId = this.value ? parseInt(this.value, 10) : null;
                                console.log('Job ID parsato:', selectedJobId);
                                if (selectedJobId && selectedJobId > 0) {
                                    console.log('Chiamata loadRequisitiPersonale con job_id:', selectedJobId);
                                    loadRequisitiPersonale(selectedJobId);
                                } else {
                                    console.log('Nessun job_id valido, reset lista');
                                    document.getElementById('requisiti-personale-list').innerHTML =
                                        '<p class="placeholder-container">Seleziona una gara per visualizzare i requisiti di personale.</p>';
                                }
                            });
                        }
                    } else {
                        console.error('Errore nel caricamento elenco gare:', response.message || 'Errore sconosciuto');
                    }
                })
                .catch(error => {
                    console.error('Errore nella richiesta elenco gare:', error);
                });

            // Funzione per caricare i requisiti di personale
            function loadRequisitiPersonale(jobId) {
                console.log('loadRequisitiPersonale chiamata con jobId:', jobId);
                if (!jobId || jobId <= 0) {
                    console.warn('jobId non valido:', jobId);
                    return;
                }

                // Mostra un indicatore di caricamento
                const container = document.getElementById('requisiti-personale-list');
                if (container) {
                    container.innerHTML = '<p class="placeholder-container-dark">Caricamento requisiti...</p>';
                }

                // Usa la stessa funzione makeFetch definita sopra
                makeFetch('requisiti', 'getRequisitiPersonale', { job_id: jobId })
                    .then(response => {
                        console.log('Risposta getRequisitiPersonale:', response);
                        if (response.success && Array.isArray(response.data)) {
                            requisitiPersonaleData = response.data;
                            if (requisitiPersonaleData.length > 0) {
                                renderRequisitiPersonale(requisitiPersonaleData);
                            } else {
                                console.warn('Nessun requisito personale trovato per job_id:', jobId);
                                document.getElementById('requisiti-personale-list').innerHTML =
                                    '<p class="placeholder-container">Nessun requisito di personale trovato per questa gara.</p>';
                            }
                        } else {
                            console.warn('Risposta non valida o errore per job_id:', jobId, response);
                            document.getElementById('requisiti-personale-list').innerHTML =
                                '<p class="placeholder-container">Nessun requisito di personale trovato per questa gara.</p>';
                        }
                    })
                    .catch(error => {
                        console.error('Errore nel caricamento requisiti personale:', error);
                        document.getElementById('requisiti-personale-list').innerHTML =
                            '<p class="error-text">Errore nel caricamento dei requisiti di personale</p>';
                    });
            }

            // Carica personale attivo
            makeFetch('requisiti', 'getPersonaleAttivo', {})
                .then(response => {
                    if (response.success && response.data) {
                        personaleData = response.data;
                        window.personaleInitialized = true;
                        buildPersonaleFilters(personaleData);
                        renderPersonaleList(personaleData);
                        updatePersonaleSummary(personaleData, personaleData.length);
                    } else {
                        console.error('Errore nel caricamento personale:', response.message || 'Errore sconosciuto');
                        document.getElementById('personale-list').innerHTML =
                            '<p class="error-text">Errore nel caricamento del personale: ' +
                            (response.message || 'Errore sconosciuto') + '</p>';
                    }
                })
                .catch(error => {
                    console.error('Errore nella richiesta personale:', error);
                    document.getElementById('personale-list').innerHTML =
                        '<p class="error-text">Errore nella richiesta dei dati</p>';
                });
        }

        /**
         * Renderizza l'elenco dei requisiti di personale dal bando
         * 
         * @param {Array} data Array di oggetti con id, ruolo, requisiti, obbligatorio
         */
        function renderRequisitiPersonale(data) {
            const container = document.getElementById('requisiti-personale-list');
            if (!container) {
                console.error('Container requisiti-personale-list non trovato');
                return;
            }

            if (!data || !Array.isArray(data) || data.length === 0) {
                container.innerHTML = '<p class="placeholder-container">Nessun requisito di personale trovato per questa gara.</p>';
                return;
            }

            const escapeHtml = window.escapeHtml || ((text) => {
                if (!text) return '';
                return String(text).replace(/[&<>"']/g, (c) => {
                    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                    return map[c] || c;
                });
            });

            container.innerHTML = data.map(item => {
                const ruolo = escapeHtml(item.ruolo || 'Ruolo non specificato');
                const requisiti = escapeHtml(item.requisiti || 'Nessuna descrizione disponibile.');
                const obbligatorio = item.obbligatorio === 1;
                const badgeClass = obbligatorio ? 'req-badge-obbligatorio' : 'req-badge-facoltativo';
                const badgeText = obbligatorio ? 'Obbligatorio' : 'Facoltativo';

                return `
                <div class="req-personale-item">
                    <div class="req-personale-header">
                        <span class="req-role">${ruolo}</span>
                        <span class="req-badge ${badgeClass}">${badgeText}</span>
                    </div>
                    <div class="req-personale-body">
                        <p class="req-descr">${requisiti}</p>
                    </div>
                    <div class="req-personale-footer">
                        <!-- Placeholder per futuro: riepilogo matching personale -->
                    </div>
                </div>
            `;
            }).join('');
        }

        function buildPersonaleFilters(data) {
            if (!data || data.length === 0) return;

            // Estrai valori distinti
            const reparti = [...new Set(data.map(p => p.Reparto).filter(Boolean))].sort();
            const ruoli = [...new Set(data.map(p => p.Ruolo).filter(Boolean))].sort();
            const stabilimenti = [...new Set(data.map(p => p.Stabilimento).filter(Boolean))].sort();

            // Popola select Reparto
            const selectReparto = document.getElementById('filter-reparto');
            if (selectReparto) {
                reparti.forEach(reparto => {
                    const option = document.createElement('option');
                    option.value = reparto;
                    option.textContent = reparto;
                    selectReparto.appendChild(option);
                });
            }

            // Popola select Ruolo
            const selectRuolo = document.getElementById('filter-ruolo');
            if (selectRuolo) {
                ruoli.forEach(ruolo => {
                    const option = document.createElement('option');
                    option.value = ruolo;
                    option.textContent = ruolo;
                    selectRuolo.appendChild(option);
                });
            }

            // Popola select Stabilimento
            const selectStabilimento = document.getElementById('filter-stabilimento');
            if (selectStabilimento) {
                stabilimenti.forEach(stabilimento => {
                    const option = document.createElement('option');
                    option.value = stabilimento;
                    option.textContent = stabilimento;
                    selectStabilimento.appendChild(option);
                });
            }
        }

        function filterPersonale(data, filters) {
            if (!data || data.length === 0) return [];

            return data.filter(persona => {
                // Filtro Reparto
                if (filters.reparto && persona.Reparto !== filters.reparto) {
                    return false;
                }

                // Filtro Ruolo
                if (filters.ruolo && persona.Ruolo !== filters.ruolo) {
                    return false;
                }

                // Filtro Stabilimento
                if (filters.stabilimento && persona.Stabilimento !== filters.stabilimento) {
                    return false;
                }

                // Filtro ricerca nominativo
                if (filters.search) {
                    const searchLower = filters.search.toLowerCase();
                    const nominativo = (persona.Nominativo || '').toLowerCase();
                    if (!nominativo.includes(searchLower)) {
                        return false;
                    }
                }

                return true;
            });
        }

        function renderPersonaleList(filteredData) {
            const container = document.getElementById('personale-list');
            if (!container) return;

            if (!filteredData || filteredData.length === 0) {
                container.innerHTML = '<p class="placeholder-container">Nessun personale trovato con i filtri selezionati.</p>';
                return;
            }

            const escapeHtml = window.escapeHtml || ((text) => String(text || '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c] || c)));

            container.innerHTML = filteredData.map(persona => {
                const reparto = escapeHtml(persona.Reparto || '—');
                const ruolo = escapeHtml(persona.Ruolo || '—');
                const titoloStudio = escapeHtml(persona.Titolo_di_Studio || '—');
                const stabilimento = escapeHtml(persona.Stabilimento || '—');
                const dataAssunzione = escapeHtml(persona.Data_Assunzione || '—');

                return `
                <div class="personale-list-item">
                    <div class="personale-item-header">
                        <span class="personale-item-nome">${escapeHtml(persona.Nominativo || '—')}</span>
                        <span class="personale-item-reparto">${reparto}</span>
                    </div>
                    <div class="personale-item-details">
                        <div class="personale-item-detail">
                            <span class="personale-item-detail-label">Ruolo:</span>
                            <span>${ruolo}</span>
                        </div>
                        <div class="personale-item-detail">
                            <span class="personale-item-detail-label">Titolo:</span>
                            <span>${titoloStudio}</span>
                        </div>
                        <div class="personale-item-detail">
                            <span class="personale-item-detail-label">Stabilimento:</span>
                            <span>${stabilimento}</span>
                        </div>
                        <div class="personale-item-detail">
                            <span class="personale-item-detail-label">Assunzione:</span>
                            <span>${dataAssunzione}</span>
                        </div>
                    </div>
                </div>
            `;
            }).join('');
        }

        function updatePersonaleSummary(filteredData, total) {
            const totaleEl = document.getElementById('personale-totale');
            const filtrateEl = document.getElementById('personale-filtrate');

            if (totaleEl) {
                totaleEl.textContent = total || 0;
            }

            if (filtrateEl) {
                filtrateEl.textContent = filteredData ? filteredData.length : 0;
            }
        }

        // Event listeners per i filtri
        const filterReparto = document.getElementById('filter-reparto');
        const filterRuolo = document.getElementById('filter-ruolo');
        const filterStabilimento = document.getElementById('filter-stabilimento');
        const filterSearch = document.getElementById('filter-search');

        function applyFilters() {
            if (!window.personaleInitialized || !personaleData) return;

            const filters = {
                reparto: filterReparto ? filterReparto.value : '',
                ruolo: filterRuolo ? filterRuolo.value : '',
                stabilimento: filterStabilimento ? filterStabilimento.value : '',
                search: filterSearch ? filterSearch.value.trim() : ''
            };

            const filtered = filterPersonale(personaleData, filters);
            renderPersonaleList(filtered);
            updatePersonaleSummary(filtered, personaleData.length);
        }

        if (filterReparto) filterReparto.addEventListener('change', applyFilters);
        if (filterRuolo) filterRuolo.addEventListener('change', applyFilters);
        if (filterStabilimento) filterStabilimento.addEventListener('change', applyFilters);
        if (filterSearch) filterSearch.addEventListener('input', applyFilters);
    });
</script>

<!-- Modali Comprovanti -->
<!-- Modale Progetto Comprovante -->

<!-- Modale Comprovante (Unificato) -->
<div id="modal-comprovante-progetto" class="comprovanti-modal">
    <div class="comprovanti-modal-content modal-large-content">
        <div class="comprovanti-modal-header">
            <h3 id="modal-progetto-title">Dettaglio Comprovante</h3>
            <button type="button" class="comprovanti-modal-close"
                onclick="comprovantiCloseModal('modal-comprovante-progetto')">&times;</button>
        </div>
        <div class="comprovanti-modal-body" id="comprovante-form-container">
            <?php
            // Inizializza variabili per il partial
            $tabella = 'gar_comprovanti_progetti'; // O tabella appropriata
            $datiPrecompilazione = [];
            $commessa = [];
            // Includi il form parziale
            include __DIR__ . '/partials/comprovanti/form.php';
            ?>
        </div>
    </div>
</div>


<!-- Modale Fatturato -->
<div id="modal-fatturato" class="comprovanti-modal">
    <div class="comprovanti-modal-content modal-small-content">
        <div class="comprovanti-modal-header">
            <h3 id="modal-fatturato-title">Gestione Fatturato</h3>
            <button type="button" class="comprovanti-modal-close"
                onclick="comprovantiCloseModal('modal-fatturato')">&times;</button>
        </div>
        <div class="comprovanti-modal-body">
            <form id="form-fatturato">
                <div class="comprovanti-form-group required">
                    <label>Anno</label>
                    <input type="number" name="anno" id="fatturato-input-anno" min="1990" max="2100" required>
                </div>
                <div class="comprovanti-form-group required">
                    <label>Importo Fatturato (€)</label>
                    <input type="number" name="fatturato" id="fatturato-input-importo" step="0.01" required>
                </div>

                <div class="comprovanti-modal-actions">
                    <button type="button" class="button secondary"
                        onclick="comprovantiCloseModal('modal-fatturato')">Annulla</button>
                    <button type="submit" class="button primary">Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/modules/comprovante_form.js"></script>
<script src="/assets/js/requisiti_fatturato.js"></script>
<script src="/assets/js/commerciale_requisiti_comprovanti.js"></script>