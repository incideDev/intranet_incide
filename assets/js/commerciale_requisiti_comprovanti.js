(function () {
    'use strict';

    if (window.__comprovantiInitDone) return;
    window.__comprovantiInitDone = true;

    // Stato globale
    let currentProgettoId = null;
    let currentPage = 1;
    let currentFilters = {};
    let progettiData = [];
    const READ_ONLY = true;

    // Helper escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Helper format currency
    function formatCurrency(value) {
        if (value === null || value === undefined || value === '') return '—';
        return parseFloat(value).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    // Helper format date
    function formatDate(dateStr) {
        if (!dateStr) return '—';
        try {
            const date = new Date(dateStr + 'T00:00:00');
            return date.toLocaleDateString('it-IT');
        } catch (e) {
            return dateStr;
        }
    }

    // Helper truncate string
    function truncateString(str, num) {
        if (!str) return '';
        if (str.length <= num) return str;
        return str.slice(0, num) + '...';
    }

    // Helper per chiamate API
    async function apiCall(action, params = {}) {
        const fetchFn = window.customFetch || async function (service, action, params) {
            const csrfToken = sessionStorage.getItem('CSRFtoken') || '';
            const response = await customFetch('commerciale', 'requisiti_comprovanti', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    section: 'requisiti',
                    action: action,
                    ...params,
                    csrf_token: csrfToken
                })
            });
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return await response.json();
        };

        return await fetchFn('requisiti', action, params);
    }

    // Carica lista progetti
    async function loadProgettiList() {
        const container = document.getElementById('comprovanti-progetti-list');
        if (!container) return;

        container.innerHTML = '<p class="placeholder-text">Caricamento progetti...</p>';

        try {
            const params = {
                page: currentPage,
                per_page: 50,
                ...currentFilters
            };

            const response = await apiCall('listComprovantiProgetti', params);

            if (response.success && response.data) {
                progettiData = response.data.items || [];
                renderProgettiList(progettiData);
                renderPagination(response.data.pagination);
            } else {
                container.innerHTML = '<p class="error-text">Errore nel caricamento: ' +
                    (response.errors && response.errors.length > 0 ? response.errors.join(', ') : 'Errore sconosciuto') + '</p>';
            }
        } catch (error) {
            handleApiError(error, 'nel caricamento progetti');
            container.innerHTML = '<p class="error-text">Errore nella richiesta dei dati</p>';
        }
    }

    // Renderizza lista progetti
    function renderProgettiList(items) {
        const container = document.getElementById('comprovanti-progetti-list');
        if (!container) return;

        if (!items || items.length === 0) {
            container.innerHTML = '<p class="placeholder-text">Nessun progetto trovato</p>';
            return;
        }

        container.innerHTML = items.map(item => {
            const dateRange = item.data_inizio_servizi && item.data_fine_servizi
                ? `${formatDate(item.data_inizio_servizi)} - ${formatDate(item.data_fine_servizi)}`
                : (item.data_inizio_servizi ? `Dal ${formatDate(item.data_inizio_servizi)}` : '—');

            return `
                <div class="comprovanti-progetto-item" data-progetto-id="${item.id}">
                    <div class="comprovanti-progetto-header">
                        <div>
                            <div class="comprovanti-progetto-codice">
                                ${escapeHtml(item.codice_commessa)}
                                <span class="comprovanti-qualita-mini-badge comprovanti-qualita-${item.qualita || 'rosso'}" title="Qualità dati: ${item.qualita || 'incompleto'}"></span>
                            </div>
                            ${item.numero_comprovante ? `<div class="comprovanti-progetto-numero">N. ${item.numero_comprovante}</div>` : ''}
                        </div>
                        ${item.has_pdf ? '<span class="comprovanti-badge comprovanti-badge-pdf">PDF</span>' : ''}
                    </div>
                    <div class="comprovanti-progetto-committente">${escapeHtml(item.committente)}</div>
                    <div class="comprovanti-progetto-meta">
                        ${item.importo_complessivo_lavori ? `<span>Importo: ${formatCurrency(item.importo_complessivo_lavori)}</span>` : ''}
                        <span>Periodo: ${dateRange}</span>
                        <span class="comprovanti-badge">Cat: ${item.categorie_count || 0}</span>
                        <span class="comprovanti-badge">Suddiv: ${item.suddivisione_count || 0}</span>
                        <span class="comprovanti-badge">Part: ${item.partecipanti_count || 0}</span>
                        ${item.incarichi_count > 0 ? `<span class="comprovanti-badge">Inc: ${item.incarichi_count}</span>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        // Event listeners per selezione progetto
        container.querySelectorAll('.comprovanti-progetto-item').forEach(item => {
            item.addEventListener('click', function () {
                const progettoId = parseInt(this.dataset.progettoId);
                selectProgetto(progettoId);
            });
        });
    }

    // Renderizza paginazione
    function renderPagination(pagination) {
        const container = document.getElementById('comprovanti-pagination');
        if (!container || !pagination) return;

        if (pagination.total_pages <= 1) {
            container.innerHTML = '';
            return;
        }

        const prevDisabled = pagination.page <= 1;
        const nextDisabled = pagination.page >= pagination.total_pages;

        container.innerHTML = `
            <button class="comprovanti-pagination-btn" ${prevDisabled ? 'disabled' : ''} data-page="${pagination.page - 1}">‹ Precedente</button>
            <span class="comprovanti-pagination-info">Pagina ${pagination.page} di ${pagination.total_pages} (${pagination.total} totali)</span>
            <button class="comprovanti-pagination-btn" ${nextDisabled ? 'disabled' : ''} data-page="${pagination.page + 1}">Successiva ›</button>
        `;

        container.querySelectorAll('.comprovanti-pagination-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                if (!this.disabled) {
                    currentPage = parseInt(this.dataset.page);
                    loadProgettiList();
                }
            });
        });
    }

    // Seleziona progetto e carica dettaglio
    async function selectProgetto(progettoId) {
        currentProgettoId = progettoId;

        // Evidenzia selezione
        document.querySelectorAll('.comprovanti-progetto-item').forEach(item => {
            item.classList.remove('selected');
        });
        const selectedItem = document.querySelector(`[data-progetto-id="${progettoId}"]`);
        if (selectedItem) {
            selectedItem.classList.add('selected');
        }

        await loadProgettoDettaglio(progettoId);
    }

    // Carica dettaglio progetto
    async function loadProgettoDettaglio(progettoId) {
        const container = document.getElementById('comprovanti-dettaglio');
        if (!container) return;

        container.innerHTML = '<div class="comprovanti-dettaglio-placeholder"><p class="placeholder-text">Caricamento dettaglio...</p></div>';

        try {
            const response = await apiCall('getComprovanteDettaglio', { progetto_id: progettoId });

            if (response.success && response.data) {
                renderProgettoDettaglio(response.data);
            } else {
                container.innerHTML = '<div class="comprovanti-dettaglio-placeholder"><p class="error-text">Errore nel caricamento dettaglio</p></div>';
            }
        } catch (error) {
            handleApiError(error, 'nel caricamento dettaglio progetto');
            container.innerHTML = '<div class="comprovanti-dettaglio-placeholder"><p class="error-text">Errore nella richiesta</p></div>';
        }
    }

    // Renderizza dettaglio progetto completo
    function renderProgettoDettaglio(data) {
        const container = document.getElementById('comprovanti-dettaglio');
        if (!container) return;

        const progetto = data.progetto;
        const status = data.status;

        const qualitaClass = `comprovanti-qualita-${status.qualita}`;
        const qualitaText = status.qualita === 'verde' ? 'Completo' : (status.qualita === 'giallo' ? 'Parziale' : 'Incompleto');

        // MAPPING CAMPI NORMALIZZATI
        const importoTotale = progetto.importo_lavori_totale || progetto.importo_complessivo_lavori || null;
        const titolo = progetto.titolo_progetto || progetto.oggetto_contratto || progetto.descrizione_lavoro || '—';
        const dataInizio = progetto.data_inizio_prestazione || progetto.data_inizio_servizi || null;
        const dataFine = progetto.data_fine_prestazione || progetto.data_fine_servizi || null;

        // Recupera path PDF
        let pdfPath = progetto.comprovante_pdf;
        if (!pdfPath && progetto.raw_json && progetto.raw_json.comprovante_pdf) {
            pdfPath = progetto.raw_json.comprovante_pdf;
        }

        // Genera HTML bottoni PDF
        let pdfButtonsHtml = '';
        if (pdfPath) {
            let pdfUrl = pdfPath;
            if (!pdfUrl.startsWith('/') && !pdfUrl.startsWith('http')) {
                pdfUrl = '/' + pdfUrl;
            }
            pdfUrl += '?t=' + new Date().getTime();

            pdfButtonsHtml = `
                <a href="${encodeURI(pdfUrl)}" target="_blank" class="button btn-primary" title="Apri PDF" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                    <img src="assets/icons/pdf.png" alt="PDF" style="width: 16px; height: 16px;"> <span>Apri PDF</span>
                </a>
                <button class="button btn-secondary" onclick="comprovantiUploadPdf(${progetto.id})" title="Sostituisci PDF" style="display: inline-flex; align-items: center; justify-content: center; padding: 4px 8px;">
                    <img src="assets/icons/replace.png" alt="Sostituisci" style="width: 16px; height: 16px;">
                </button>
             `;
        } else {
            pdfButtonsHtml = `
                <button class="button btn-primary" onclick="comprovantiUploadPdf(${progetto.id})" title="Carica un nuovo PDF" style="display: inline-flex; align-items: center; justify-content: center; gap: 6px;">
                    <img src="assets/icons/upload.png" alt="Upload" style="width: 16px; height: 16px;"> <span>Carica PDF</span>
                </button>
            `;
        }

        const categorie = progetto.categorie_opera || [];
        const suddivisione = progetto.suddivisione_servizio || data.servizi || [];
        const partecipanti = progetto.partecipanti || [];
        const incarichi = progetto.incarichi || [];

        container.innerHTML = `
            <div class="comprovanti-dettaglio-header">
                <div class="comprovanti-dettaglio-title">
                    <h3>${escapeHtml(progetto.codice_commessa)}${progetto.protocollo_numero ? ' - Prot. ' + progetto.protocollo_numero : ''}</h3>
                    <span class="comprovanti-qualita-badge ${qualitaClass}">${qualitaText}</span>
                </div>
                <div class="comprovanti-dettaglio-actions">
                    <button class="button btn-export-word" onclick="comprovantiExportWord(${progetto.id}, '${progetto.codice_commessa}')" title="Esporta in Word">
                        <i class="fas fa-file-word"></i> Esporta Word
                    </button>

                    ${pdfButtonsHtml}

                    <button class="button btn-secondary" onclick="comprovantiEditProgetto(${progetto.id})" data-tooltip="Modifica comprovante" style="display: inline-flex; align-items: center; justify-content: center; padding: 4px 8px;">
                        <img src="assets/icons/edit.png" alt="Modifica" style="width: 16px; height: 16px;">
                    </button>
                    
                    ${!READ_ONLY ? `
                    <button class="button btn-danger" onclick="comprovantiDeleteProgetto(${progetto.id})" title="Elimina progetto">
                        <i class="fas fa-trash"></i>
                    </button>
                    ` : ''}
                </div>
            </div>
            
            <div class="comprovanti-dettaglio-info">
                <div class="comprovanti-info-item">
                    <span class="comprovanti-info-label">Committente</span>
                    <span class="comprovanti-info-value">${escapeHtml(progetto.committente)}</span>
                </div>
                <div class="comprovanti-info-item">
                    <span class="comprovanti-info-label">Indirizzo</span>
                    <span class="comprovanti-info-value">${escapeHtml(progetto.indirizzo_committente || progetto.indirizzo || '—')}</span>
                </div>
                <div class="comprovanti-info-item full-width">
                    <span class="comprovanti-info-label">Titolo/Oggetto Progetto</span>
                    <span class="comprovanti-info-value">${escapeHtml(titolo)}</span>
                </div>
                <div class="comprovanti-info-item">
                    <span class="comprovanti-info-label">Importo Complessivo Lavori</span>
                    <span class="comprovanti-info-value">${formatCurrency(importoTotale)}</span>
                </div>
                <div class="comprovanti-info-item">
                    <span class="comprovanti-info-label">Importo Prestazioni (Onorario)</span>
                    <span class="comprovanti-info-value">${formatCurrency(progetto.importo_prestazioni || progetto.importo_onorario)}</span>
                </div>
                <div class="comprovanti-info-item">
                    <span class="comprovanti-info-label">Data Inizio Prestazione</span>
                    <span class="comprovanti-info-value">${formatDate(dataInizio)}</span>
                </div>
                <div class="comprovanti-info-item">
                    <span class="comprovanti-info-label">Data Fine Prestazione</span>
                    <span class="comprovanti-info-value">${formatDate(dataFine)}</span>
                </div>
                <div class="comprovanti-info-item">
                    <span class="comprovanti-info-label">CIG</span>
                    <span class="comprovanti-info-value">${escapeHtml(progetto.cig || '—')}</span>
                </div>
                <div class="comprovanti-info-item">
                    <span class="comprovanti-info-label">CUP</span>
                    <span class="comprovanti-info-value">${escapeHtml(progetto.cup || '—')}</span>
                </div>
                <div class="comprovanti-info-item full-width">
                    <span class="comprovanti-info-label">RUP</span>
                    <span class="comprovanti-info-value">${escapeHtml(progetto.rup_nome || '—')} ${progetto.rup_riferimento ? '(' + progetto.rup_riferimento + ')' : ''}</span>
                </div>
            </div>
            
            <div class="comprovanti-tabs-nav">
                <button class="comprovanti-tab-btn active" data-tab="categorie">Categorie (${categorie.length})</button>
                <button class="comprovanti-tab-btn" data-tab="suddivisione">Suddivisione (${suddivisione.length})</button>
                <button class="comprovanti-tab-btn" data-tab="partecipanti">Partecipanti (${partecipanti.length})</button>
            </div>
            
            <div class="comprovanti-tab-content active" id="tab-categorie-content">
                ${renderCategorieTab(categorie)}
            </div>
            
            <div class="comprovanti-tab-content" id="tab-suddivisione-content">
                ${renderSuddivisioneTab(suddivisione)}
            </div>

            <div class="comprovanti-tab-content" id="tab-partecipanti-content">
                ${renderPartecipantiTab(partecipanti)}
            </div>

            ${incarichi.length > 0 ? renderIncarichiSection(incarichi) : ''}
        `;

        // Event listeners per tab navigation
        container.querySelectorAll('.comprovanti-tab-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const tabName = this.dataset.tab;
                container.querySelectorAll('.comprovanti-tab-btn').forEach(b => b.classList.remove('active'));
                container.querySelectorAll('.comprovanti-tab-content').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                document.getElementById(`tab-${tabName}-content`).classList.add('active');
            });
        });
    }

    // Renderizza tab Categorie d'opera
    function renderCategorieTab(categorie) {
        let totImporto = categorie.reduce((acc, current) => acc + (parseFloat(current.importo) || 0), 0);

        return `
            <div class="comprovanti-tab-header-info">
                <h4>Categorie d'opera</h4>
            </div>
            <table class="comprovanti-table">
                <thead>
                    <tr>
                        <th>Codice</th>
                        <th>Descrizione</th>
                        <th style="text-align:right;">Importo</th>
                        <th style="text-align:right;">%</th>
                    </tr>
                </thead>
                <tbody>
                    ${categorie.length === 0 ? '<tr><td colspan="4" style="text-align:center;color:#9ca3af;">Nessuna categoria definita</td></tr>' :
                categorie.map(c => {
                    const importo = parseFloat(c.importo) || 0;
                    const perc = totImporto > 0 ? (importo / totImporto * 100).toFixed(2) : '0.00';
                    return `
                        <tr>
                            <td><strong>${escapeHtml(c.categoria_id)}</strong></td>
                            <td title="${escapeHtml(c.categoria_desc)}">${escapeHtml(truncateString(c.categoria_desc, 60))}</td>
                            <td style="text-align:right;">${formatCurrency(importo)}</td>
                            <td style="text-align:right;">${perc}%</td>
                        </tr>
                    `;
                }).join('')}
                </tbody>
            </table>
        `;
    }

    // Renderizza tab Suddivisione servizio
    function renderSuddivisioneTab(rows) {
        return `
            <div class="comprovanti-tab-header-info">
                <h4>Suddivisione del servizio</h4>
            </div>
            <table class="comprovanti-table">
                <thead>
                    <tr>
                        <th>Società</th>
                        <th>Categoria</th>
                        <th style="text-align:right;">% RTP</th>
                        <th style="text-align:right;">Importo</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.length === 0 ? '<tr><td colspan="4" style="text-align:center;color:#9ca3af;">Nessuna suddivisione definita</td></tr>' :
                rows.map(s => `
                        <tr>
                            <td>${escapeHtml(s.societa_nome)}</td>
                            <td>${escapeHtml(s.categoria_id_opera)}</td>
                            <td style="text-align:right;">${s.percentuale_rtp ? s.percentuale_rtp + '%' : '—'}</td>
                            <td style="text-align:right;">${formatCurrency(s.importo)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    // Renderizza tab Partecipanti
    function renderPartecipantiTab(rows) {
        return `
            <div class="comprovanti-tab-header-info">
                <h4>Soggetti Partecipanti</h4>
            </div>
            <table class="comprovanti-table">
                <thead>
                    <tr>
                        <th>Società</th>
                        <th style="text-align:right;">Quota %</th>
                    </tr>
                </thead>
                <tbody>
                    ${rows.length === 0 ? '<tr><td colspan="2" style="text-align:center;color:#9ca3af;">Nessun partecipante definito</td></tr>' :
                rows.map(p => `
                        <tr>
                            <td>${escapeHtml(p.societa_nome)}</td>
                            <td style="text-align:right;">${p.percentuale ? p.percentuale + '%' : '—'}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;
    }

    // Renderizza sezione Incarichi (non tab)
    function renderIncarichiSection(incarichi) {
        return `
            <div class="comprovanti-section-separator" style="margin-top: 30px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                <h4 style="margin-bottom: 15px; color: #1e293b; font-size: 1.1rem; border-bottom: 2px solid #3b82f6; display: inline-block; padding-bottom: 4px;">
                    Personale / Incarichi (${incarichi.length})
                </h4>
                <table class="comprovanti-table" style="background: white;">
                    <thead>
                        <tr>
                            <th>Nominativo</th>
                            <th>Ruolo</th>
                            <th>Qualità/Titolo</th>
                            <th>Società</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${incarichi.map(p => `
                            <tr>
                                <td>${escapeHtml(p.nome)}</td>
                                <td>${escapeHtml(p.ruolo || '—')}</td>
                                <td>${escapeHtml(p.qualita || '—')}</td>
                                <td>${escapeHtml(p.societa || '—')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }


    // Funzioni globali per chiamate da HTML

    // Modale Unificato: Create/Edit
    window.comprovantiOpenModalProgetto = async function (progettoId) {
        const modal = document.getElementById('modal-comprovante-progetto');
        const title = document.getElementById('modal-progetto-title');

        if (!modal) return;

        // Assicurati che il manager sia inizializzato
        if (!window.comprovanteManager && typeof window.initComprovanteForm === 'function') {
            window.comprovanteManager = window.initComprovanteForm('#comprovante-form-container', {
                prefix: 'requisiti_comprovante_',
                defaultPanel: 'dati-generali',
                onSave: function (response) {
                    showMessage('success', 'Comprovante salvato con successo');
                    comprovantiCloseModal('modal-comprovante-progetto');
                    loadProgettiList();
                    if (response.progetto_id || currentProgettoId) {
                        const idToReload = currentProgettoId && progettoId ? currentProgettoId : (response.progetto_id || null);
                        if (idToReload) loadProgettoDettaglio(idToReload);
                    }
                }
            });
        }

        if (!window.comprovanteManager) {
            console.error("Impossibile inizializzare il modulo ComprovanteForm");
            return;
        }

        modal.classList.add('show');

        // Reset del form
        window.comprovanteManager.reset();

        if (progettoId) {
            // Edit Mode
            title.textContent = 'Modifica Comprovante';

            // Recupera il codice commessa
            let codiceCommessa = null;

            // Cerchiamo nel dataset locale se abbiamo i dati
            const progettoItem = progettiData.find(p => p.id == progettoId);
            if (progettoItem) {
                codiceCommessa = progettoItem.codice_commessa;
            }

            if (codiceCommessa) {
                // Imposta manualmente il codice nel campo (readonly in edit)
                const codiceInput = document.querySelector('#codice_commessa');
                if (codiceInput) {
                    codiceInput.value = codiceCommessa;
                    codiceInput.readOnly = true;
                }

                await window.comprovanteManager.loadData(codiceCommessa);
            } else {
                showMessage('error', 'Impossibile recuperare il codice commessa per il caricamento.');
            }

        } else {
            // Create Mode
            title.textContent = 'Nuovo Comprovante';
            // Il form è già resettato.
            // Sblocca input codice commessa se era readonly
            const codiceInput = document.querySelector('#codice_commessa');
            if (codiceInput) {
                codiceInput.readOnly = false;
                codiceInput.value = '';
            }
        }
    };

    window.comprovantiEditProgetto = async function (progettoId) {
        await comprovantiOpenModalProgetto(progettoId);
    };

    window.comprovantiExportWord = async function (progettoId, codiceCommessa) {
        if (!progettoId) return;

        // Se non abbiamo codiceCommessa passato, cerchiamolo
        if (!codiceCommessa) {
            const progettoItem = progettiData.find(p => p.id == progettoId);
            if (progettoItem) codiceCommessa = progettoItem.codice_commessa;
        }

        if (!codiceCommessa) {
            alert("Codice commessa mancante, impossibile esportare.");
            return;
        }

        // Utilizziamo il manager per fare l'export. 
        if (!window.comprovanteManager && typeof window.initComprovanteForm === 'function') {
            window.comprovanteManager = window.initComprovanteForm('#comprovante-form-container', {});
        }

        if (window.comprovanteManager) {
            const modal = document.getElementById('modal-comprovante-progetto');
            const wasVisible = modal && modal.classList.contains('show');

            showMessage('info', 'Richiesta export in elaborazione...');

            if (!wasVisible) {
                // Carica dati ed esporta
                await window.comprovanteManager.loadData(codiceCommessa);
                await window.comprovanteManager.exportToWord();
                window.comprovanteManager.reset();
            } else {
                // Se è già aperto, controlla coerenza
                const currentCode = document.querySelector('#codice_commessa')?.value;
                if (currentCode === codiceCommessa) {
                    await window.comprovanteManager.exportToWord();
                } else {
                    // Carica forzatamente quello richiesto
                    await window.comprovanteManager.loadData(codiceCommessa);
                    await window.comprovanteManager.exportToWord();
                }
            }
        } else {
            alert("Modulo ComprovanteForm non disponibile.");
        }
    };

    window.comprovantiDeleteProgetto = async function (progettoId) {
        if (!confirm('Sei sicuro di voler eliminare questo progetto? Questa azione eliminerà anche tutti i servizi e prestazioni collegati.')) {
            return;
        }

        try {
            const response = await apiCall('deleteComprovanteProgetto', { progetto_id: progettoId });
            if (response.success) {
                showMessage('success', 'Progetto eliminato con successo');
                currentProgettoId = null;
                loadProgettiList();
                document.getElementById('comprovanti-dettaglio').innerHTML =
                    '<div class="comprovanti-dettaglio-placeholder"><p class="placeholder-text">Seleziona un progetto per visualizzare i dettagli</p></div>';
            } else {
                handleApiResponse(response);
            }
        } catch (error) {
            handleApiError(error, 'nell\'eliminazione progetto');
        }
    };

    // Funzioni modali
    window.comprovantiCloseModal = function (modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('show');
            // Se stiamo chiudendo il modale principale, resetta
            if (modalId === 'modal-comprovante-progetto' && window.comprovanteManager) {
                window.comprovanteManager.reset();
            }
        }
    };

    /**
     * Gestisce errori API in modo centralizzato
     * 
     * @param {Error} error Errore catturato
     * @param {string} context Contesto dell'operazione
     */
    function handleApiError(error, context) {
        const errorMessage = error.message || 'Errore sconosciuto';
        showMessage('error', `Errore ${context}: ${errorMessage}`);
    }

    /**
     * Gestisce risposta API con validazione
     * 
     * @param {Object} response Risposta API
     * @param {string} successMessage Messaggio di successo
     * @returns {boolean} true se successo, false altrimenti
     */
    function handleApiResponse(response, successMessage) {
        if (response.success) {
            if (successMessage) {
                showMessage('success', successMessage);
            }
            return true;
        } else {
            const errorMsg = (response.errors && response.errors.length > 0)
                ? response.errors.join(', ')
                : 'Errore sconosciuto';
            showMessage('error', `Errore: ${errorMsg}`);
            return false;
        }
    }

    // Helper per mostrare messaggi
    function showMessage(type, message) {
        // Usa sistema di notifiche esistente se disponibile
        if (typeof window.showNotification === 'function') {
            window.showNotification(type, message);
        } else if (typeof window.showToast === 'function') {
            window.showToast(message, type);
        } else {
            alert(message);
        }
    }

    // Upload PDF comprovante
    window.comprovantiUploadPdf = function (progettoId) {
        if (!progettoId) return;

        // Crea input file temporaneo
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.pdf,application/pdf';

        const customFetchFn = window.customFetch || async function (service, action, body) {
            // Fallback se customFetch non esiste
            const csrfToken = sessionStorage.getItem('CSRFtoken') || '';
            // Nota: con FormData non settare Content-Type header manualmente!
            const response = await fetch('ajax.php', {
                method: 'POST',
                headers: {
                    'CSRF-Token': csrfToken
                },
                body: body
            });
            return await response.json();
        };


        input.addEventListener('change', async function () {
            const file = this.files[0];
            if (!file) return;

            if (file.type !== 'application/pdf') {
                showMessage('error', 'Formato non valido: è ammesso solo PDF');
                return;
            }
            if (file.size > 20 * 1024 * 1024) {
                showMessage('error', 'File troppo grande (max 20MB)');
                return;
            }

            const formData = new FormData();
            formData.append('section', 'requisiti');
            formData.append('action', 'uploadComprovantePdf');
            formData.append('comprovante_pdf', file);
            formData.append('progetto_id', progettoId);

            // Aggiungi CSRF token al body per sicurezza extra
            const csrfToken = sessionStorage.getItem('CSRFtoken') || '';
            formData.append('csrf_token', csrfToken);

            try {
                showMessage('info', 'Caricamento PDF in corso...');

                // IMPORTANTE: passo formData direttamente. customFetch gestirà gli header corretti
                const response = await customFetchFn('requisiti', 'uploadComprovantePdf', formData);

                if (response.success) {
                    showMessage('success', 'PDF caricato con successo');
                    // Ricarica il dettaglio per mostrare il link al PDF
                    loadProgettoDettaglio(progettoId);
                    // Aggiorna anche la lista (badge PDF)
                    loadProgettiList();
                } else {
                    const errorMsg = (response.errors && response.errors.length > 0)
                        ? response.errors.join(', ')
                        : 'Errore sconosciuto';
                    showMessage('error', `Errore upload: ${errorMsg}`);
                }
            } catch (error) {
                handleApiError(error, 'nel caricamento PDF');
            }
        });

        input.click();
    };

    // Inizializzazione
    window.initComprovanti = function () {
        if (window.comprovantiInitialized) return;
        window.comprovantiInitialized = true;

        // Manager inizializzato lazy on-demand (vedi comprovantiOpenModalProgetto e comprovantiExportWord)

        // Event listeners filtri
        const btnCerca = document.getElementById('comprovanti-btn-cerca');
        const btnReset = document.getElementById('comprovanti-btn-reset');
        const btnNuovo = document.getElementById('comprovanti-btn-nuovo-progetto');

        if (btnCerca) {
            btnCerca.addEventListener('click', function () {
                currentFilters = {
                    search: document.getElementById('comprovanti-search')?.value.trim() || '',
                    codice_commessa: document.getElementById('comprovanti-filter-codice')?.value.trim() || '',
                    committente: document.getElementById('comprovanti-filter-committente')?.value.trim() || '',
                    data_inizio: document.getElementById('comprovanti-filter-data-inizio')?.value || '',
                    data_fine: document.getElementById('comprovanti-filter-data-fine')?.value || ''
                };
                // Rimuovi chiavi vuote
                Object.keys(currentFilters).forEach(key => {
                    if (currentFilters[key] === '') {
                        delete currentFilters[key];
                    }
                });
                currentPage = 1;
                loadProgettiList();
            });
        }

        if (btnReset) {
            btnReset.addEventListener('click', function () {
                document.getElementById('comprovanti-search').value = '';
                document.getElementById('comprovanti-filter-codice').value = '';
                document.getElementById('comprovanti-filter-committente').value = '';
                document.getElementById('comprovanti-filter-data-inizio').value = '';
                document.getElementById('comprovanti-filter-data-fine').value = '';
                currentFilters = {};
                currentPage = 1;
                loadProgettiList();
            });
        }

        if (btnNuovo) {
            btnNuovo.addEventListener('click', function () {
                comprovantiOpenModalProgetto(null);
            });
        }

        // Carica lista iniziale
        loadProgettiList();
    };

})(); // End of IIFE
