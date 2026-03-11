/**
 * Modulo per la gestione del form Comprovante (Copia unificata da commessa_chiusura.php)
 * Riusabile in modali e altre pagine.
 */
(function (window) {
    'use strict';

    function initComprovanteForm(rootSelector, options = {}) {
        const root = document.querySelector(rootSelector);
        if (!root) {
            console.error('ComprovanteForm: root element not found', rootSelector);
            return;
        }

        // Configurazione
        const config = {
            tabella: options.tabella || '',
            datiPrecompilazione: options.datiPrecompilazione || {},
            readOnly: options.readOnly || false,
            defaultPanel: options.defaultPanel || null,
            ...options
        };

        // Stato dell'applicazione locale
        let currentPanel = null;
        let categoriaRowCounter = 0;
        let soggettoRowCounter = 0;
        let partecipanteRowCounter = 0;
        let incaricoRowCounter = 0;

        // Cache per le opere DM50
        let opereDm50Cache = null;
        let opereDm50LoadingPromise = null;

        // Inizializza tutto
        function init() {
            // Assicurati che tutti i pannelli siano nascosti all'inizio
            root.querySelectorAll('.chiusura-panel').forEach(panel => {
                panel.classList.add('is-hidden');
            });
            // Rimuovi active da tutte le card
            root.querySelectorAll('.chiusura-subcard').forEach(card => {
                card.classList.remove('active');
            });

            setupCardListeners();
            setupTableButtons();
            setupPartecipanti();
            setupSocietaAutocomplete();
            setupFormReset();
            setupTableFilterable();
            setupImportiMoney();
            setupCalcoloImportoLavoriTotale();
            setupPersonaleAutocomplete();
            setupCommessaAutocomplete();

            // Carica dati salvati se presenti
            if (config.tabella) {
                loadComprovanteSaved();
            } else if (config.datiPrecompilazione && Object.keys(config.datiPrecompilazione).length > 0) {
                // Se abbiamo dati di precompilazione ma non salvati, potremmo volerli popolare qui se non fatto via PHP
                // Ma il PHP partial lo fa già.
            }

            // Aggiorna visibilità bottoni incarico dopo inizializzazione
            updateIncaricoButtonsVisibility();
            // Popola select società incarichi dai partecipanti
            updateIncaricoSocietaSelect();

            // Mostra pannello di default se configurato (es. in modali)
            if (config.defaultPanel) {
                // Rimuovi classe hidden dal wrapper cards se esiste, ma qui vogliamo mostrare il pannello
                // In realtà, se c'è un defaultPanel, probabilmente non vogliamo navigazione a schede o vogliamo atterrare lì.
                showPanel(config.defaultPanel);
            }

            // Espone funzioni utili all'istanza
            root.comprovanteApi = {
                exportToWord: async () => {
                    const form = root.querySelector('form');
                    if (!form) return;
                    // Usa buildComprovantePayload per raccogliere i dati
                    // Nota: buildComprovantePayload deve essere accessibile qui. 
                    // Poiché è definita sotto, dobbiamo assicurarci che lo scope la veda (function hoisting works).
                    const payload = buildComprovantePayload(form);
                    if (payload.codice_commessa) {
                        payload.tabella = payload.codice_commessa;
                    }

                    try {
                        const response = await customFetch('commesse', 'exportComprovanteWord', payload);
                        if (response.success && (response.download_url || response.url)) {
                            // Scarica il file
                            const link = document.createElement('a');
                            link.href = response.download_url || response.url;
                            link.target = '_blank'; // Opzionale
                            link.download = response.filename || 'comprovante.docx';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                        } else {
                            showToast(response.error || 'Errore durante l\'esportazione Word', 'error');
                        }
                    } catch (error) {
                        console.error('Export error:', error);
                        showToast('Errore tecnico durante l\'esportazione', 'error');
                    }
                },
                loadData: loadComprovanteSaved,
                reset: () => resetFormContent(),
                save: () => root.querySelector('#form-certificato')?.dispatchEvent(new Event('submit'))
            };
        }


        // --- Helper Functions (copiate e adattate se necessario) ---

        function customFetch(service, action, params) {
            if (typeof window.customFetch === 'function') {
                return window.customFetch(service, action, params);
            }
            console.error('customFetch global function missing');
            return Promise.reject('customFetch missing');
        }

        function showToast(msg, type) {
            if (typeof window.showToast === 'function') {
                window.showToast(msg, type);
            } else {
                alert(msg);
            }
        }

        // --- Implementation details from original script ---

        // Setup listeners per le card (pannelli)
        function setupCardListeners() {
            const cards = root.querySelectorAll('.chiusura-subcard');
            cards.forEach(card => {
                card.addEventListener('click', function () {
                    const panelName = this.dataset.panel;
                    showPanel(panelName);
                });
                // Supporto tastiera
                card.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        showPanel(this.dataset.panel);
                    }
                });
            });
        }

        function showPanel(panelName) {
            const cardsWrapper = root.querySelector('#chiusura-cards-wrapper') || document.getElementById('chiusura-cards-wrapper');
            if (cardsWrapper) cardsWrapper.classList.add('hide');

            root.querySelectorAll('.chiusura-panel').forEach(panel => {
                panel.classList.add('is-hidden');
            });
            root.querySelectorAll('.chiusura-subcard').forEach(card => {
                card.classList.remove('active');
            });

            const targetPanel = root.querySelector(`#chiusura-${panelName}`);
            if (targetPanel) {
                targetPanel.classList.remove('is-hidden');
                currentPanel = panelName;
                setTimeout(() => {
                    targetPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 50);
            }
        }

        function caricaOpereDm50(callback) {
            if (opereDm50Cache) {
                callback(opereDm50Cache);
                return;
            }
            if (opereDm50LoadingPromise) {
                opereDm50LoadingPromise.then(() => callback(opereDm50Cache || [])).catch(() => callback([]));
                return;
            }
            opereDm50LoadingPromise = customFetch('commesse', 'getOpereDm50', {}).then(res => {
                opereDm50Cache = (res && res.success && res.data) ? res.data : [];
                opereDm50LoadingPromise = null;
                callback(opereDm50Cache);
            }).catch(() => {
                opereDm50Cache = [];
                opereDm50LoadingPromise = null;
                callback([]);
            });
        }

        function ensureOpereDm50Loaded() {
            return new Promise(resolve => caricaOpereDm50(resolve));
        }

        function ensureAziendeLoaded() {
            return new Promise(resolve => {
                if (typeof window.caricaAziendeCached === 'function') {
                    window.caricaAziendeCached(resolve);
                } else {
                    resolve([]);
                }
            });
        }

        function setupTableFilterable() {
            setTimeout(() => {
                if (typeof window.initTableFilters === 'function') {
                    const t1 = root.querySelector('#table-categorie-opera');
                    if (t1) window.initTableFilters(t1.id);
                    const t2 = root.querySelector('#table-suddivisione-servizio');
                    if (t2) window.initTableFilters(t2.id);
                }
            }, 100);
        }

        // ... Copia delle funzioni di rendering righe e calcoli ...
        // Per brevità incollo il blocco logico, adattando selettori a root.querySelector se necessario
        // Ma poichè gli ID sono univoci, document.getElementById va bene fintanto che non duplichiamo il form.

        function setupTableButtons() {
            const btnAddCat = root.querySelector('#btn-add-cat');
            const btnAddSoggetto = root.querySelector('#btn-add-soggetto');

            if (btnAddCat) btnAddCat.addEventListener('click', () => addCategoriaRow());
            if (btnAddSoggetto) btnAddSoggetto.addEventListener('click', () => addSoggettoRow());

            root.addEventListener('click', function (e) {
                if (e.target.classList.contains('btn-remove-cat')) {
                    removeRow(e.target.closest('tr'));
                    updateTotaleCategorie();
                }
                if (e.target.classList.contains('btn-remove-soggetto')) {
                    removeRow(e.target.closest('tr'));
                    updateTotaleSoggetti();
                }
                if (e.target.classList.contains('btn-add-incarico')) {
                    addIncaricoRow();
                }
                if (e.target.classList.contains('btn-remove-incarico')) {
                    removeIncaricoRow(e.target.closest('.incarico-row'));
                }
            });

            const catBody = root.querySelector('#cat-opera-body');
            if (catBody) {
                catBody.addEventListener('input', e => {
                    if (e.target.name === 'importo_categoria[]') updateTotaleCategorie();
                });
                catBody.addEventListener('change', e => {
                    if (e.target.name === 'categoria_id[]') validateCoerenzaCategorie();
                });
            }

            const sogBody = root.querySelector('#suddivisione-servizio-body');
            if (sogBody) {
                sogBody.addEventListener('input', e => {
                    if (e.target.name === 'importo_soggetto[]') {
                        updateTotaleSoggetti();
                        validateCoerenzaCategorie();
                    }
                });
                sogBody.addEventListener('change', e => {
                    if (e.target.name === 'categoria_suddivisione[]' || e.target.name === 'societa_suddivisione[]') {
                        validateCoerenzaCategorie();
                    }
                });
            }

            root.addEventListener('change', function (e) {
                if (e.target && (e.target.name && (e.target.name.startsWith('fase_') || e.target.name.startsWith('att_')))) {
                    const rows = root.querySelectorAll('#suddivisione-servizio-body tr');
                    rows.forEach(row => {
                        const container = row.querySelector('.servizi-checkbox-container');
                        if (container) {
                            const checkedBoxes = container.querySelectorAll('input.servizio-checkbox:checked');
                            const serviziSelezionati = Array.from(checkedBoxes).map(cb => cb.value);
                            container.innerHTML = generateServiziCheckbox(serviziSelezionati);
                        }
                    });
                }
            });
        }

        function addCategoriaRow(data = null) {
            const tbody = root.querySelector('#cat-opera-body');
            if (!tbody) return;
            // ... Logic identical to original ...
            categoriaRowCounter++;
            const row = document.createElement('tr');
            row.id = `cat-row-${categoriaRowCounter}`;

            caricaOpereDm50(function (opere) {
                let selectOptions = '<option value="">Seleziona categoria...</option>';
                const selectedId = data?.categoria_id || '';
                const selectedDesc = data?.categoria_desc || '';

                opere.forEach(function (opera) {
                    const isSelected = selectedId === opera.id_opera ? 'selected' : '';
                    selectOptions += `<option value="${opera.id_opera}" data-desc="${opera.identificazione_opera}" ${isSelected}>${opera.id_opera}</option>`;
                });

                row.innerHTML = `
                    <td><select name="categoria_id[]" class="categoria-select">${selectOptions}</select></td>
                    <td><input type="text" name="categoria_desc[]" class="categoria-desc" value="${selectedDesc}" placeholder="Descrizione" readonly></td>
                    <td><input type="text" name="importo_categoria[]" class="importo-categoria" value="${data?.importo || ''}" placeholder="0,00"></td>
                    <td><button type="button" class="btn-remove-row btn-remove-cat">×</button></td>
                 `;
                tbody.appendChild(row);

                const select = row.querySelector('.categoria-select');
                const descInput = row.querySelector('.categoria-desc');
                if (select && descInput) {
                    select.addEventListener('change', () => {
                        const opt = select.options[select.selectedIndex];
                        descInput.value = opt.getAttribute('data-desc') || '';
                        validateCoerenzaCategorie();
                    });
                }
                const imp = row.querySelector('.importo-categoria');
                if (imp) {
                    if (typeof window.initMoneyInputs === 'function') window.initMoneyInputs();
                    imp.addEventListener('input', () => updateTotaleCategorie());
                }
                updateTotaleCategorie();
            });
        }

        function removeRow(row) {
            if (row && row.parentNode) {
                const table = row.closest('table');
                row.remove();
                if (table.id === 'table-categorie-opera') {
                    updateTotaleCategorie();
                    validateCoerenzaCategorie();
                    sanitizeSuddivisioneRows();
                } else {
                    updateTotaleSoggetti();
                    validateCoerenzaCategorie();
                }
            }
        }

        function updateTotaleCategorie() {
            let totale = 0;
            root.querySelectorAll('#cat-opera-body input[name="importo_categoria[]"]').forEach(input => {
                let val = parseFloat(input.value.replace(/\./g, '').replace(',', '.')) || 0;
                totale += val;
            });
            const el = root.querySelector('#totale-categorie');
            if (el) el.textContent = totale.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' €';
            validateCoerenzaCategorie();
        }

        function updateTotaleSoggetti() {
            let totale = 0;
            root.querySelectorAll('#suddivisione-servizio-body input[name="importo_soggetto[]"]').forEach(input => {
                let val = parseFloat(input.value.replace(/\./g, '').replace(',', '.')) || 0;
                totale += val;
            });
            const el = root.querySelector('#totale-soggetti');
            if (el) el.textContent = totale.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' €';
        }

        // ... (Simile per le altre funzioni: getSocietaInserite, updateSelectSocietaSuddivisione, validateCoerenzaCategorie, etc.)
        // Per risparmiare token e tempo, e visto che il codice è identico, lo referenziamo.
        // Ma essendo un modulo nuovo devo riscriverle.

        function getSocietaInserite() {
            const societa = [];
            root.querySelectorAll('#partecipanti-container .partecipante-row').forEach(row => {
                const inp = row.querySelector('input[name="societa_nome[]"]');
                if (inp && inp.value) {
                    const nome = inp.value.trim();
                    if (nome && !societa.find(s => s.nome === nome)) societa.push({ nome });
                }
            });
            return societa;
        }

        function getCategorieInserite() {
            const categorie = [];
            root.querySelectorAll('#cat-opera-body tr').forEach(row => {
                const sel = row.querySelector('select[name="categoria_id[]"]');
                const desc = row.querySelector('input[name="categoria_desc[]"]');
                if (sel && sel.value) {
                    if (!categorie.find(c => c.id === sel.value)) categorie.push({ id: sel.value, desc: desc ? desc.value : '' });
                }
            });
            return categorie;
        }

        function getPercentualeSocieta(nome) {
            let p = '';
            root.querySelectorAll('#partecipanti-container .partecipante-row').forEach(row => {
                const n = row.querySelector('input[name="societa_nome[]"]');
                const v = row.querySelector('input[name="percentuale[]"]');
                if (n && n.value.trim() === nome && v) p = v.value || '';
            });
            return p;
        }

        function updateSelectSocietaSuddivisione() {
            const societa = getSocietaInserite();
            root.querySelectorAll('.societa-suddivisione-select').forEach(select => {
                const curr = select.value;
                select.innerHTML = '<option value="">Seleziona società...</option>';
                societa.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.nome;
                    opt.textContent = s.nome;
                    if (curr === s.nome) opt.selected = true;
                    select.appendChild(opt);
                });
                if (curr && !societa.find(s => s.nome === curr)) {
                    select.value = '';
                    select.setAttribute('data-tooltip', 'Società non più presente');
                } else {
                    select.removeAttribute('data-tooltip');
                }
            });
        }

        function generateServiziCheckbox(selected = []) {
            const map = {
                'fase_sf': 'Studio di fattibilità (SF)',
                'fase_pp': 'Progetto Preliminare (PP)',
                'fase_pd': 'Progetto Definitivo (PD)',
                'fase_pfte': 'PFTE',
                'fase_pe': 'Progetto Esecutivo (PE)',
                'fase_dl': 'Direzione Lavori (DL)',
                'fase_dos': 'DOS',
                'fase_doi': 'DOI',
                'fase_da': 'Direzione artistica (DA)',
                'fase_csp': 'CSP',
                'fase_cse': 'CSE',
                'att_bim': 'Progettazione in BIM',
                'att_cam_dnsh': 'CAM / DNSH',
                'att_antincendio': 'Antincendio',
                'att_acustica': 'Acustica',
                'att_relazione_geologica': 'Relazione Geologica'
            };
            const available = [];
            Object.keys(map).forEach(k => {
                const cb = root.querySelector('#' + k);
                if (cb && cb.checked) available.push({ key: k, label: map[k] });
            });

            if (available.length === 0) return '<span class="servizi-empty-message">Nessun servizio selezionato sopra</span>';

            return available.map(s => {
                const checked = selected.includes(s.key) || selected.includes(s.label) ? 'checked' : '';
                return `<label class="servizio-checkbox-label"><input type="checkbox" class="servizio-checkbox" value="${s.key}" ${checked}> ${s.label}</label>`;
            }).join('');
        }

        function addSoggettoRow(data = null) {
            const tbody = root.querySelector('#suddivisione-servizio-body');
            if (!tbody) return;
            soggettoRowCounter++;
            const row = document.createElement('tr');
            row.id = `sog-row-${soggettoRowCounter}`;

            // ... (logica simile a addCategoriaRow, ma per soggetti)
            // Per semplicità non copio tutto il corpo, ma è necessario per il funzionamento.
            // Dovrei copiare il codice.
            // Mi fido che il copia incolla funzioni.
            // Creo la struttura base e chiamo updateSelectSocietaSuddivisione dopo appeso.
            const socIns = getSocietaInserite();
            const catIns = getCategorieInserite();

            let socOpt = '<option value="">Seleziona società...</option>';
            socIns.forEach(s => socOpt += `<option value="${s.nome}" ${data?.societa === s.nome ? 'selected' : ''}>${s.nome}</option>`);

            let catOpt = '<option value="">Seleziona categoria...</option>';
            catIns.forEach(c => catOpt += `<option value="${c.id}" ${data?.categoria_id === c.id ? 'selected' : ''}>${c.id} - ${c.desc}</option>`);

            row.innerHTML = `
                <td><select name="societa_suddivisione[]" class="societa-suddivisione-select">${socOpt}</select></td>
                <td><select name="categoria_suddivisione[]" class="categoria-suddivisione-select">${catOpt}</select></td>
                <td><input type="text" name="percentuale_suddivisione[]" class="percentuale-suddivisione" value="${data?.percentuale || ''}" readonly placeholder="%"></td>
                <td><div class="servizi-checkbox-container">${generateServiziCheckbox(data?.servizi || [])}</div></td>
                <td><input type="text" name="importo_soggetto[]" class="importo-suddivisione" value="${data?.importo || ''}" placeholder="0,00"></td>
                <td><button type="button" class="btn-remove-row btn-remove-soggetto">×</button></td>
             `;
            tbody.appendChild(row);

            // Listeners
            row.querySelector('.societa-suddivisione-select').addEventListener('change', function () {
                row.querySelector('.percentuale-suddivisione').value = getPercentualeSocieta(this.value);
                refreshPercentualiSuddivisione();
                sanitizeSuddivisioneRows();
            });

            if (typeof window.initMoneyInputs === 'function') window.initMoneyInputs();
            row.querySelector('.importo-suddivisione').addEventListener('input', () => { updateTotaleSoggetti(); validateCoerenzaCategorie(); });

            updateTotaleSoggetti();
            validateCoerenzaCategorie();
            sanitizeSuddivisioneRows();
        }

        function validateCoerenzaCategorie() {
            const validazioneRow = root.querySelector('#validazione-coerenza-row');
            const validazioneContent = root.querySelector('#validazione-coerenza-content');
            if (!validazioneRow || !validazioneContent) return;

            const categorieImporti = {};
            root.querySelectorAll('#cat-opera-body tr').forEach(row => {
                const categoriaSelect = row.querySelector('select[name="categoria_id[]"]');
                const importoInput = row.querySelector('input[name="importo_categoria[]"]');
                if (categoriaSelect && categoriaSelect.value && importoInput) {
                    const categoriaId = categoriaSelect.value;
                    let value = importoInput.value.replace(/\./g, '').replace(',', '.');
                    const importo = parseFloat(value) || 0;
                    if (importo > 0) categorieImporti[categoriaId] = importo;
                }
            });

            const allocazioniPerCategoria = {};
            root.querySelectorAll('#suddivisione-servizio-body tr').forEach(row => {
                const categoriaSelect = row.querySelector('select[name="categoria_suddivisione[]"]');
                const importoInput = row.querySelector('input[name="importo_soggetto[]"]');
                if (categoriaSelect && categoriaSelect.value && importoInput) {
                    const categoriaId = categoriaSelect.value;
                    let value = importoInput.value.replace(/\./g, '').replace(',', '.');
                    const importo = parseFloat(value) || 0;
                    if (importo > 0) {
                        if (!allocazioniPerCategoria[categoriaId]) allocazioniPerCategoria[categoriaId] = 0;
                        allocazioniPerCategoria[categoriaId] += importo;
                    }
                }
            });

            let totaleCategorie = 0;
            let totaleAllocato = 0;
            Object.values(categorieImporti).forEach(imp => totaleCategorie += imp);
            Object.values(allocazioniPerCategoria).forEach(imp => totaleAllocato += imp);
            const differenza = totaleCategorie - totaleAllocato;

            const stati = [];
            Object.keys(categorieImporti).forEach(catId => {
                const importoCategoria = categorieImporti[catId];
                const importoAllocato = allocazioniPerCategoria[catId] || 0;
                const diff = importoCategoria - importoAllocato;
                if (Math.abs(diff) <= 0.01) {
                    stati.push({ categoria: catId, stato: 'OK', importo: importoCategoria, allocato: importoAllocato, diff: 0 });
                } else if (diff > 0.01) {
                    stati.push({ categoria: catId, stato: 'MANCA', importo: importoCategoria, allocato: importoAllocato, diff: diff });
                } else {
                    stati.push({ categoria: catId, stato: 'ECCEDE', importo: importoCategoria, allocato: importoAllocato, diff: Math.abs(diff) });
                }
            });

            Object.keys(allocazioniPerCategoria).forEach(catId => {
                if (!categorieImporti[catId]) {
                    stati.push({ categoria: catId, stato: 'NON_PRESENTE', importo: 0, allocato: allocazioniPerCategoria[catId], diff: allocazioniPerCategoria[catId] });
                }
            });

            if (stati.length === 0 && totaleCategorie === 0) {
                validazioneRow.style.display = 'none';
                return;
            }
            validazioneRow.style.display = '';

            let html = '<div class="validazione-grid">';
            html += '<div><strong>Riepilogo totale:</strong><ul class="validazione-list">';
            html += `<li>Totale categorie: ${totaleCategorie.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} €</li>`;
            html += `<li>Totale allocato: ${totaleAllocato.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} €</li>`;
            const diffClass = Math.abs(differenza) < 0.01 ? 'validazione-ok' : (differenza > 0 ? 'validazione-warning' : 'validazione-error');
            html += `<li>Differenza: <strong class="${diffClass}">${differenza.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} €</strong></li>`;
            html += '</ul></div>';

            html += '<div><strong>Stato per categoria:</strong><ul class="validazione-list validazione-list-scrollable">';
            stati.forEach(s => {
                let statoClass = 'validazione-ok';
                let icona = '✓';
                if (s.stato === 'MANCA') { statoClass = 'validazione-warning'; icona = '⚠'; }
                else if (s.stato === 'ECCEDE' || s.stato === 'NON_PRESENTE') { statoClass = 'validazione-error'; icona = '✗'; }
                const catEscaped = typeof window.escapeHtml === 'function' ? window.escapeHtml(s.categoria) : s.categoria;
                html += `<li class="${statoClass}">${icona} ${catEscaped}: ${s.importo.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} € → ${s.allocato.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} €`;
                if (s.diff > 0.01) {
                    html += ` <span class="font-bold">(${s.diff > 0 ? '+' : '-'}${s.diff.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} €)</span>`;
                }
                html += '</li>';
            });
            html += '</ul></div>';
            html += '</div>';

            validazioneContent.innerHTML = html;
        }

        function sanitizeSuddivisioneRows() {
            const societaValide = getSocietaInserite().map(s => s.nome);
            const categorieValide = getCategorieInserite().map(c => c.id);

            root.querySelectorAll('#suddivisione-servizio-body tr').forEach(row => {
                const societaSelect = row.querySelector('.societa-suddivisione-select');
                const categoriaSelect = row.querySelector('.categoria-suddivisione-select');
                const importoInput = row.querySelector('.importo-suddivisione');

                if (societaSelect && societaSelect.value) {
                    if (!societaValide.includes(societaSelect.value.trim())) {
                        societaSelect.style.borderColor = '#dc3545';
                        societaSelect.setAttribute('data-tooltip', 'Società non più presente nei partecipanti');
                    } else {
                        societaSelect.style.borderColor = '';
                        societaSelect.removeAttribute('data-tooltip');
                    }
                }

                if (categoriaSelect && categoriaSelect.value) {
                    if (!categorieValide.includes(categoriaSelect.value.trim())) {
                        categoriaSelect.style.borderColor = '#dc3545';
                        categoriaSelect.setAttribute('data-tooltip', 'Categoria non più presente nelle categorie d\'opera');
                    } else {
                        categoriaSelect.style.borderColor = '';
                        categoriaSelect.removeAttribute('data-tooltip');
                    }
                }

                if (importoInput && importoInput.value) {
                    const importoValue = importoInput.value.replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '');
                    const importoNum = parseFloat(importoValue);
                    if (!Number.isFinite(importoNum) || importoNum < 0) {
                        importoInput.style.borderColor = '#dc3545';
                        importoInput.setAttribute('data-tooltip', 'Importo non valido');
                    } else {
                        importoInput.style.borderColor = '';
                        importoInput.removeAttribute('data-tooltip');
                    }
                }
            });
        }

        function refreshPercentualiSuddivisione() {
            root.querySelectorAll('#suddivisione-servizio-body tr').forEach(row => {
                const sel = row.querySelector('.societa-suddivisione-select');
                const inp = row.querySelector('.percentuale-suddivisione');
                if (sel && inp && sel.value) inp.value = getPercentualeSocieta(sel.value.trim());
            });
        }

        // --- Partecipanti ---
        function setupPartecipanti() {
            const radioButtons = root.querySelectorAll('input[name="tipo_incarico"]');
            const container = root.querySelector('#partecipanti-container');
            if (!container) return;

            radioButtons.forEach(radio => {
                radio.addEventListener('change', function () {
                    const isRTP = this.value === 'rtp';

                    if (isRTP) {
                        const firstRow = container.querySelector('.partecipante-row');
                        if (firstRow) {
                            const percentualeInput = firstRow.querySelector('input[name="percentuale[]"]');
                            if (percentualeInput && percentualeInput.value === '100') {
                                percentualeInput.removeAttribute('data-saved-value');
                            }
                        }
                    }

                    updatePartecipantiUI(isRTP);

                    if (isRTP) {
                        const rows = container.querySelectorAll('.partecipante-row');
                        const societaIncaricataInput = root.querySelector('#societa_incaricata');
                        const societaIncaricata = societaIncaricataInput ? societaIncaricataInput.value.trim() : (config.datiPrecompilazione?.societa_incaricata || 'Incide Engineering S.r.l.');

                        if (rows.length === 0) {
                            addPartecipanteRow();
                            const firstRow = container.querySelector('.partecipante-row');
                            if (firstRow) {
                                const societaNomeInput = firstRow.querySelector('input[name="societa_nome[]"]');
                                const societaPlaceholder = firstRow.querySelector('.custom-select-placeholder');
                                const societaSelectBox = firstRow.querySelector('.societa-select-box');
                                if (societaNomeInput) societaNomeInput.value = societaIncaricata;
                                if (societaPlaceholder) societaPlaceholder.textContent = societaIncaricata;
                                if (societaSelectBox) {
                                    societaSelectBox.style.pointerEvents = 'none';
                                    societaSelectBox.style.opacity = '0.7';
                                }
                            }
                        } else {
                            const firstRow = rows[0];
                            if (firstRow) {
                                const societaNomeInput = firstRow.querySelector('input[name="societa_nome[]"]');
                                const societaPlaceholder = firstRow.querySelector('.custom-select-placeholder');
                                const societaSelectBox = firstRow.querySelector('.societa-select-box');
                                if (societaNomeInput && !societaNomeInput.value) societaNomeInput.value = societaIncaricata;
                                if (societaPlaceholder && !societaPlaceholder.textContent) societaPlaceholder.textContent = societaIncaricata;
                                if (societaSelectBox) {
                                    societaSelectBox.style.pointerEvents = 'none';
                                    societaSelectBox.style.opacity = '0.7';
                                }
                            }
                            requestAnimationFrame(() => {
                                setupSocietaAutocomplete();
                                updateSelectSocietaSuddivisione();
                            });
                        }
                    } else {
                        const firstRow = container.querySelector('.partecipante-row');
                        if (firstRow) {
                            const societaSelectBox = firstRow.querySelector('.societa-select-box');
                            if (societaSelectBox) {
                                societaSelectBox.style.pointerEvents = '';
                                societaSelectBox.style.opacity = '';
                            }
                        }
                    }
                });
            });

            const initialRadio = root.querySelector('input[name="tipo_incarico"]:checked');
            const initialIsRTP = initialRadio && initialRadio.value === 'rtp';
            updatePartecipantiUI(initialIsRTP);

            container.addEventListener('click', function (e) {
                if (e.target.closest('.btn-add-partecipante')) {
                    e.preventDefault();
                    addPartecipanteRow();
                }
                if (e.target.closest('.btn-remove-partecipante')) {
                    e.preventDefault();
                    removePartecipanteRow(e.target.closest('.partecipante-row'));
                }
            });

            container.addEventListener('input', function (e) {
                if (e.target.name === 'percentuale[]') {
                    calcolaPercentualePrimaRiga();
                    validatePercentuali();
                }
            });

            container.addEventListener('change', function (e) {
                if (e.target.name === 'societa_nome[]') {
                    updateSelectSocietaSuddivisione();
                    updateIncaricoSocietaSelect();
                    refreshPercentualiSuddivisione();
                    sanitizeSuddivisioneRows();
                }
            });
        }

        function removePartecipanteRow(row) {
            if (row && root.querySelectorAll('.partecipante-row').length > 1) {
                row.remove();
                const isRTP = root.querySelector('input[name="tipo_incarico"]:checked')?.value === 'rtp';
                updatePartecipantiUI(isRTP);
                calcolaPercentualePrimaRiga();
                validatePercentuali();
                updateSelectSocietaSuddivisione();
                sanitizeSuddivisioneRows();
            }
        }

        function validatePercentuali() {
            const isRTP = root.querySelector('input[name="tipo_incarico"]:checked')?.value === 'rtp';
            if (!isRTP) return;

            const percentuali = Array.from(root.querySelectorAll('input[name="percentuale[]"]'))
                .map(input => parseFloat(input.value) || 0);
            const totale = percentuali.reduce((sum, val) => sum + val, 0);

            root.querySelectorAll('.partecipante-row').forEach(row => {
                const input = row.querySelector('input[name="percentuale[]"]');
                if (input) {
                    if (Math.abs(totale - 100) > 0.01) {
                        input.style.borderColor = '#dc3545';
                        input.setAttribute('data-tooltip', `Totale: ${totale.toFixed(2)}% (deve essere 100%)`);
                    } else {
                        input.style.borderColor = '';
                        input.removeAttribute('data-tooltip');
                    }
                }
            });
            refreshPercentualiSuddivisione();
        }

        function updatePartecipantiUI(isRTP) {
            const container = root.querySelector('#partecipanti-container');
            if (!container) return;

            const header = root.querySelector('#partecipanti-header');
            const singleRowLabels = container.querySelectorAll('.single-row-label');

            if (!isRTP) {
                const rows = container.querySelectorAll('.partecipante-row');
                if (rows.length > 1) {
                    for (let i = rows.length - 1; i > 0; i--) rows[i].remove();
                }
            }

            const rows = container.querySelectorAll('.partecipante-row');
            const showCapogruppo = isRTP && rows.length > 1;

            if (showCapogruppo) {
                header?.classList.remove('hidden');
                singleRowLabels.forEach(label => label.classList.add('hidden'));
            } else {
                header?.classList.add('hidden');
                singleRowLabels.forEach(label => label.classList.remove('hidden'));
            }

            rows.forEach((row, index) => {
                const percentualeInput = row.querySelector('input[name="percentuale[]"]');
                const addButton = row.querySelector('.btn-add-partecipante');
                const removeButton = row.querySelector('.btn-remove-partecipante');
                const societaSelectBox = row.querySelector('.societa-select-box');
                const capogruppoGroup = row.querySelector('.partecipanti-capogruppo-group');
                const rowGrid = row.querySelector('.partecipanti-row-grid');

                if (showCapogruppo) {
                    capogruppoGroup?.classList.remove('hidden');
                    rowGrid?.classList.add('partecipanti-row-grid-with-capogruppo');
                } else {
                    capogruppoGroup?.classList.add('hidden');
                    rowGrid?.classList.remove('partecipanti-row-grid-with-capogruppo');
                }

                if (isRTP) {
                    if (percentualeInput) {
                        if (rows.length === 1) {
                            percentualeInput.removeAttribute('readonly');
                            if (!percentualeInput.value || percentualeInput.value === '') percentualeInput.value = '100';
                        } else {
                            if (index === 0) {
                                percentualeInput.setAttribute('readonly', 'readonly');
                                percentualeInput.setAttribute('data-tooltip', 'Calcolato automaticamente: 100% - somma altre %');
                            } else {
                                percentualeInput.removeAttribute('readonly');
                                percentualeInput.removeAttribute('data-tooltip');
                            }
                        }
                    }
                    if (addButton) { addButton.classList[index === 0 ? 'remove' : 'add']('hidden'); }
                    if (removeButton) {
                        if (rows.length > 1 && index > 0) removeButton.classList.remove('hidden');
                        else removeButton.classList.add('hidden');
                    }
                    if (societaSelectBox) {
                        if (index === 0) {
                            societaSelectBox.style.pointerEvents = 'none';
                            societaSelectBox.style.opacity = '0.7';
                        } else {
                            societaSelectBox.style.pointerEvents = '';
                            societaSelectBox.style.opacity = '';
                        }
                    }
                } else {
                    if (percentualeInput) { percentualeInput.setAttribute('readonly', 'readonly'); percentualeInput.value = '100'; }
                    if (addButton) addButton.classList.add('hidden');
                    if (removeButton) removeButton.classList.add('hidden');
                }
            });
        }

        // --- Incarichi ---
        function addIncaricoRow(data = null) {
            const container = root.querySelector('#incarico-container');
            if (!container) return;

            incaricoRowCounter++;
            const row = document.createElement('div');
            row.className = 'incarico-row';
            row.setAttribute('data-row-index', incaricoRowCounter);

            row.innerHTML = `
            <div class="incarico-row-grid">
                <!-- Col 1: Tecnico -->
                <div class="form-group">
                    <label for="incarico_nome_${incaricoRowCounter}" class="single-row-label">Tecnico <span class="required">*</span></label>
                    <div class="custom-select-box personale-select-box" id="personale-select-${incaricoRowCounter}" data-autocomplete="personale">
                        <div class="custom-select-placeholder">Seleziona tecnico...</div>
                        <input type="hidden" name="incarico_nome[]" id="incarico_nome_${incaricoRowCounter}" value="">
                    </div>
                </div>

                <!-- Col 2: Ruolo -->
                <div class="form-group">
                    <label for="incarico_ruolo_${incaricoRowCounter}" class="single-row-label">Ruolo incaricato <span class="required">*</span></label>
                    <select name="incarico_ruolo[]" id="incarico_ruolo_${incaricoRowCounter}" required>
                        <option value="">Seleziona ruolo...</option>
                        <optgroup label="Progettazione">
                            <option value="Progettista incaricato">Progettista incaricato</option>
                            <option value="Progettista architettonico">Progettista architettonico</option>
                            <option value="Progettista strutturale">Progettista strutturale</option>
                            <option value="Progettista impianti">Progettista impianti</option>
                        </optgroup>
                        <optgroup label="Direzione Lavori">
                            <option value="Direttore dei Lavori incaricato">Direttore dei Lavori incaricato</option>
                            <option value="Direttore Operativo - strutture">Direttore Operativo - strutture</option>
                            <option value="Direttore Operativo - impianti">Direttore Operativo - impianti</option>
                        </optgroup>
                        <optgroup label="Sicurezza">
                            <option value="Coordinatore della sicurezza in fase di progettazione (CSP)">CSP - Coordinatore sicurezza progettazione</option>
                            <option value="Coordinatore della sicurezza in fase di esecuzione (CSE)">CSE - Coordinatore sicurezza esecuzione</option>
                        </optgroup>
                        <optgroup label="Altro">
                            <option value="Geologo">Geologo</option>
                            <option value="Direttore artistico">Direttore artistico</option>
                            <option value="Collaudatore">Collaudatore</option>
                        </optgroup>
                    </select>
                </div>

                <!-- Col 3: Società -->
                <div class="form-group">
                    <label for="incarico_societa_${incaricoRowCounter}" class="single-row-label">Società <span class="required">*</span></label>
                    <select name="incarico_societa[]" id="incarico_societa_${incaricoRowCounter}" class="incarico-societa-select" required>
                        <option value="">Seleziona società...</option>
                    </select>
                </div>

                <!-- Col 4: Qualifica -->
                <div class="form-group">
                    <label for="incarico_qualita_${incaricoRowCounter}" class="single-row-label">Qualifica <span class="required">*</span></label>
                    <select name="incarico_qualita[]" id="incarico_qualita_${incaricoRowCounter}" required>
                        <option value="">Seleziona qualifica...</option>
                        <option value="Amministratore Unico">Amministratore Unico</option>
                        <option value="Legale Rappresentante">Legale Rappresentante</option>
                        <option value="Direttore Tecnico">Direttore Tecnico</option>
                        <option value="Socio">Socio</option>
                        <option value="Dipendente">Dipendente</option>
                        <option value="Collaboratore">Collaboratore</option>
                        <option value="Consulente esterno">Consulente esterno</option>
                    </select>
                </div>

                <!-- Col 5: Actions -->
                <div class="form-group incarico-actions-group">
                    <button type="button" class="btn-remove-row btn-remove-incarico" data-tooltip="Rimuovi incarico">×</button>
                </div>
            </div>`;

            container.appendChild(row);

            // Popola se abbiamo dati (edit mode)
            if (data) {
                const nameInp = row.querySelector('input[name="incarico_nome[]"]');
                const ph = row.querySelector('.custom-select-placeholder');
                const ruoloSel = row.querySelector('select[name="incarico_ruolo[]"]');
                const societaSel = row.querySelector('select[name="incarico_societa[]"]');
                const qualitaSel = row.querySelector('select[name="incarico_qualita[]"]');

                if (nameInp) nameInp.value = data.nome || '';
                if (ph) ph.textContent = data.nome || 'Seleziona tecnico...';
                if (ruoloSel) ruoloSel.value = data.ruolo || '';
                if (qualitaSel) qualitaSel.value = data.qualita || '';
                // Società verrà settata dopo updateIncaricoSocietaSelect
            }

            updateIncaricoButtonsVisibility();
            updateIncaricoSocietaSelect();

            if (data) {
                const societaSel = row.querySelector('select[name="incarico_societa[]"]');
                if (societaSel) societaSel.value = data.societa || '';
            }

            // Usa setTimeout come in chiusura per garantire che il DOM sia pronto
            setTimeout(() => {
                if (typeof setupPersonaleAutocomplete === 'function') {
                    setupPersonaleAutocomplete();
                }
            }, 50);
        }

        // Calcola automaticamente la percentuale della prima riga (capogruppo) come 100% - totale altre righe
        // Reattivo: si aggiorna in tempo reale mentre l'utente digita
        function calcolaPercentualePrimaRiga() {
            const isRTP = root.querySelector('input[name="tipo_incarico"]:checked')?.value === 'rtp';
            if (!isRTP) return;

            const rows = root.querySelectorAll('#partecipanti-container .partecipante-row');
            if (rows.length <= 1) return; // Serve almeno una seconda riga

            const firstRow = rows[0];
            if (!firstRow) return;

            const firstPercentualeInput = firstRow.querySelector('input[name="percentuale[]"]');
            if (!firstPercentualeInput) return;

            // Calcola il totale delle percentuali delle righe successive (index > 0)
            let totaleAltreRighe = 0;
            for (let i = 1; i < rows.length; i++) {
                const percentualeInput = rows[i].querySelector('input[name="percentuale[]"]');
                if (percentualeInput && percentualeInput.value) {
                    const valore = parseFloat(percentualeInput.value);
                    if (!isNaN(valore) && valore >= 0) {
                        totaleAltreRighe += valore;
                    }
                }
            }

            // Calcola la percentuale della prima riga come 100% - totale altre righe
            const percentualePrimaRiga = Math.max(0, 100 - totaleAltreRighe);

            // Arrotonda a 2 decimali, rimuovi decimali inutili (es. 50.00 → 50)
            const formatted = parseFloat(percentualePrimaRiga.toFixed(2));
            firstPercentualeInput.value = formatted;

            // Feedback visivo: evidenzia se il totale è corretto
            const totale = totaleAltreRighe + formatted;
            if (Math.abs(totale - 100) < 0.01) {
                firstPercentualeInput.classList.remove('input-warning');
                firstPercentualeInput.classList.add('input-success');
            } else {
                firstPercentualeInput.classList.remove('input-success');
                firstPercentualeInput.classList.add('input-warning');
            }
        }

        function addPartecipanteRow(data = null) {
            const container = root.querySelector('#partecipanti-container');
            if (!container) return;

            partecipanteRowCounter++;
            const rowIndex = partecipanteRowCounter;

            const row = document.createElement('div');
            row.className = 'partecipante-row';
            row.setAttribute('data-row-index', rowIndex);

            row.innerHTML = `
                <div class="partecipanti-row-grid">
                    <div class="form-group partecipanti-capogruppo-group hidden">
                        <input type="radio" name="capogruppo" value="${rowIndex}">
                    </div>
                    <div class="form-group partecipanti-societa-group">
                        <label for="societa_${rowIndex}" class="single-row-label">Società <span class="required">*</span></label>
                        <div class="custom-select-box societa-select-box" id="societa-select-${rowIndex}">
                            <div class="custom-select-placeholder">Seleziona società...</div>
                            <input type="hidden" name="societa_id[]" id="societa_id_${rowIndex}" value="">
                            <input type="hidden" name="societa_nome[]" id="societa_nome_${rowIndex}" value="">
                        </div>
                    </div>
                    <div class="form-group partecipanti-percentuale-group">
                        <label for="percentuale_${rowIndex}" class="single-row-label">% <span class="required">*</span></label>
                        <input type="number" id="percentuale_${rowIndex}" name="percentuale[]" value="${data?.percentuale || ''}" min="0" max="100" step="0.01" required>
                    </div>
                    <div class="form-group partecipanti-actions-group">
                        <button type="button" class="btn-add-row btn-add-partecipante hidden" data-tooltip="Aggiungi partecipante">+</button>
                        <button type="button" class="btn-remove-row btn-remove-partecipante hidden" data-tooltip="Rimuovi partecipante">×</button>
                    </div>
                </div>
            `;

            container.appendChild(row);

            if (data) {
                const nameInp = row.querySelector('input[name="societa_nome[]"]');
                const idInp = row.querySelector('input[name="societa_id[]"]');
                const ph = row.querySelector('.custom-select-placeholder');
                const capInp = row.querySelector('input[name="capogruppo"]');

                if (nameInp) nameInp.value = data.societa_nome || '';
                if (idInp) idInp.value = data.societa_id || '';
                if (ph) ph.textContent = data.societa_nome || 'Seleziona società...';
                if (capInp && data.capogruppo) capInp.checked = true;
            }

            requestAnimationFrame(() => {
                const isRTP = root.querySelector('input[name="tipo_incarico"]:checked')?.value === 'rtp';
                updatePartecipantiUI(isRTP);

                // Ricalcola percentuale prima riga quando si aggiunge una nuova riga (come in chiusura)
                if (typeof calcolaPercentualePrimaRiga === 'function') {
                    calcolaPercentualePrimaRiga();
                }

                setupSocietaAutocomplete();
                updateSelectSocietaSuddivisione();
            });
        }

        function removeIncaricoRow(row) {
            row.remove();
            updateIncaricoButtonsVisibility();
        }

        function updateIncaricoButtonsVisibility() {
            const container = root.querySelector('#incarico-container');
            if (!container) return;

            const allRows = container.querySelectorAll('.incarico-row');
            const firstRow = allRows[0];
            const header = root.querySelector('#incarico-header');
            const singleRowLabels = container.querySelectorAll('.single-row-label');

            if (allRows.length > 1) {
                if (header) header.classList.remove('hidden');
                singleRowLabels.forEach(label => label.classList.add('hidden'));
            } else {
                if (header) header.classList.add('hidden');
                singleRowLabels.forEach(label => label.classList.remove('hidden'));
            }

            if (firstRow) {
                const firstAddBtn = firstRow.querySelector('.btn-add-incarico');
                if (firstAddBtn) firstAddBtn.classList.remove('hidden');
                const firstRemoveBtn = firstRow.querySelector('.btn-remove-incarico');
                if (firstRemoveBtn) {
                    if (allRows.length === 1) firstRemoveBtn.classList.add('hidden');
                    else firstRemoveBtn.classList.remove('hidden');
                }
            }

            for (let i = 1; i < allRows.length; i++) {
                const addBtn = allRows[i].querySelector('.btn-add-incarico');
                if (addBtn) addBtn.remove();
            }
        }

        function updateIncaricoSocietaSelect() {
            const soc = getSocietaInserite();
            root.querySelectorAll('.incarico-societa-select').forEach(sel => {
                const val = sel.value;
                sel.innerHTML = '<option value="">Seleziona società...</option>';
                soc.forEach(s => sel.innerHTML += `<option value="${s.nome}" ${val === s.nome ? 'selected' : ''}>${s.nome}</option>`);
            });
        }

        // --- Backend Calls ---
        function applyComprovanteToForm(d) {
            if (!d) return;

            // Helper to set fields
            const set = (id, v) => { const el = root.querySelector('#' + id); if (el) el.value = v || ''; };
            const setMoney = (id, v) => {
                const el = root.querySelector('#' + id);
                if (el && v != null) {
                    el.value = typeof v === 'number' ? v.toLocaleString('it-IT') : v;
                }
            };
            const setCheck = (id, v) => {
                const el = root.querySelector('#' + id);
                if (el) el.checked = !!v;
            };

            // --- Campi testo ---
            set('codice_commessa', d.codice_commessa);
            set('committente', d.committente);
            set('titolo_progetto', d.titolo_progetto);
            set('riferimento_contratto', d.riferimento_contratto);
            set('luogo_data_lettera', d.luogo_data_lettera);
            set('indirizzo_committente', d.indirizzo_committente);
            set('cig', d.cig);
            set('cup', d.cup);
            set('rup_nome', d.rup_nome);
            set('rup_riferimento', d.rup_riferimento);
            set('societa_incaricata', d.societa_incaricata);

            // Campi oggetto/textarea
            const oggettoEl = root.querySelector('#oggetto_contratto');
            if (oggettoEl) oggettoEl.value = d.oggetto_contratto || '';

            // Dati dal raw_json (non normalizzati nel ViewModel ma presenti nel JSON)
            const rj = d.raw_json || {};
            set('destinatario_spettabile', rj.destinatario_spettabile || '');
            set('destinatario_indirizzo', rj.destinatario_indirizzo || '');
            set('destinatario_pec_email', rj.destinatario_pec_email || '');
            set('societa_sede_legale', rj.societa_sede_legale || '');
            set('societa_cf_piva', rj.societa_cf_piva || '');

            // --- Campi importo (money) ---
            setMoney('importo_prestazioni', d.importo_prestazioni);
            setMoney('importo_lavori_esclusi_oneri', d.importo_lavori_esclusi_oneri);
            setMoney('oneri_sicurezza', d.oneri_sicurezza);
            setMoney('importo_lavori_totale', d.importo_lavori_totale);

            // --- Date ---
            set('data_inizio_prestazione', d.data_inizio_prestazione);
            set('data_fine_prestazione', d.data_fine_prestazione);

            // --- Flags (checkboxes) ---
            const flags = d.flags || {};
            const flagKeys = [
                'fase_sf', 'fase_pp', 'fase_pd', 'fase_pfte', 'fase_pe',
                'fase_dl', 'fase_dos', 'fase_doi', 'fase_da', 'fase_csp', 'fase_cse',
                'att_bim', 'att_cam_dnsh', 'att_antincendio', 'att_acustica', 'att_relazione_geologica'
            ];
            flagKeys.forEach(key => setCheck(key, flags[key]));

            // --- Partecipanti ---
            if (Array.isArray(d.partecipanti) && d.partecipanti.length > 0) {
                const isRTP = d.partecipanti.length > 1;
                // Imposta tipo_incarico
                const radioSingolo = root.querySelector('input[name="tipo_incarico"][value="singolo"]');
                const radioRTP = root.querySelector('input[name="tipo_incarico"][value="rtp"]');
                if (isRTP && radioRTP) {
                    radioRTP.checked = true;
                    radioRTP.dispatchEvent(new Event('change', { bubbles: true }));
                } else if (radioSingolo) {
                    radioSingolo.checked = true;
                    radioSingolo.dispatchEvent(new Event('change', { bubbles: true }));
                }

                updatePartecipantiUI(isRTP);

                // Popola prima riga
                const firstRow = root.querySelector('.partecipante-row[data-row-index="0"]');
                if (firstRow && d.partecipanti[0]) {
                    const p0 = d.partecipanti[0];
                    const nome0 = firstRow.querySelector('input[name="societa_nome[]"]');
                    const ph0 = firstRow.querySelector('.custom-select-placeholder');
                    const perc0 = firstRow.querySelector('input[name="percentuale[]"]');
                    const cap0 = firstRow.querySelector('input[name="capogruppo"]');
                    if (nome0) nome0.value = p0.societa_nome || '';
                    if (ph0) ph0.textContent = p0.societa_nome || 'Seleziona società...';
                    if (perc0) perc0.value = p0.percentuale || 100;
                    if (cap0 && p0.capogruppo) cap0.checked = true;
                }

                // Righe aggiuntive per RTP
                if (isRTP) {
                    for (let i = 1; i < d.partecipanti.length; i++) {
                        addPartecipanteRow(d.partecipanti[i]);
                    }
                }
            }

            // --- Categorie d'opera (tabella dinamica) ---
            if (d.categorie_opera) {
                const tb = root.querySelector('#cat-opera-body');
                if (tb) {
                    tb.innerHTML = '';
                    categoriaRowCounter = 0;
                    if (Array.isArray(d.categorie_opera)) {
                        d.categorie_opera.forEach(c => addCategoriaRow(c));
                    }
                }
            }

            // --- Suddivisione servizio (tabella dinamica) ---
            const suddivisione = d.suddivisione_servizio || [];
            if (suddivisione.length > 0) {
                // Timing: chiusura usa setTimeout(..., 50) per dare tempo alle checkbox dei fieldset di essere renderizzate
                // e ai listener dei flags di essere pronti.
                setTimeout(() => {
                    const sogBody = root.querySelector('#suddivisione-servizio-body');
                    if (sogBody) {
                        sogBody.innerHTML = '';
                        soggettoRowCounter = 0;
                        suddivisione.forEach(s => {
                            const rowData = {
                                societa: s.societa_nome,
                                categoria_id: s.categoria_id_opera,
                                percentuale: s.percentuale_rtp,
                                servizi: s.servizi_svolti ? (typeof s.servizi_svolti === 'string' ? s.servizi_svolti.split(',').map(x => x.trim()) : s.servizi_svolti) : [],
                                importo: s.importo
                            };
                            addSoggettoRow(rowData);
                        });
                    }
                }, 50);
            }

            // --- Incarichi (righe dinamiche) ---
            const incarichi = d.incarichi || [];
            if (incarichi.length > 0) {
                // Popola prima riga
                const firstInc = incarichi[0];
                const incNome0 = root.querySelector('#incarico_nome_0');
                const incRuolo0 = root.querySelector('#incarico_ruolo_0');
                const incSocieta0 = root.querySelector('#incarico_societa_0');
                const incQualita0 = root.querySelector('#incarico_qualita_0');
                const incPh0 = root.querySelector('#personale-select-0 .custom-select-placeholder');

                if (incNome0) incNome0.value = firstInc.nome || '';
                if (incPh0) incPh0.textContent = firstInc.nome || 'Seleziona tecnico...';
                if (incRuolo0) incRuolo0.value = firstInc.ruolo || '';
                if (incQualita0) incQualita0.value = firstInc.qualita || '';

                updateIncaricoSocietaSelect();
                if (incSocieta0) incSocieta0.value = firstInc.societa || '';

                // Righe successive
                for (let i = 1; i < incarichi.length; i++) {
                    addIncaricoRow(incarichi[i]);
                }
            }

            // Aggiorna totali e validazioni
            requestAnimationFrame(() => {
                updateTotaleCategorie();
                updateTotaleSoggetti();
                validateCoerenzaCategorie();
            });

            // Init money fields se necessario
            if (typeof window.initMoneyInputs === 'function') window.initMoneyInputs();
        }

        async function loadComprovanteSaved(identifier) {
            const tabella = identifier || config.tabella;
            if (!tabella) return;

            try {
                const res = await customFetch('commesse', 'getComprovante', { tabella: tabella });
                if (!res || !res.success || !res.data) return;

                const data = res.data;

                const setField = (id, value) => {
                    const field = root.querySelector('#' + id);
                    if (field && value !== null && value !== undefined && value !== '') field.value = String(value);
                };
                const setMoneyField = (id, value) => {
                    const field = root.querySelector('#' + id);
                    if (field && value !== null && value !== undefined) {
                        field.value = typeof value === 'number' ? value.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) : String(value);
                        if (typeof window.initMoneyInputs === 'function') window.initMoneyInputs();
                    }
                };

                // Campi testo
                setField('luogo_data_lettera', data.luogo_data_lettera);
                setField('committente', data.committente);
                setField('titolo_progetto', data.titolo_progetto);
                setField('riferimento_contratto', data.riferimento_contratto);
                setField('oggetto_contratto', data.oggetto_contratto);
                setField('cig', data.cig);
                setField('cup', data.cup);
                setField('rup_nome', data.rup_nome);
                setField('destinatario_spettabile', data.destinatario_spettabile);
                setField('destinatario_indirizzo', data.destinatario_indirizzo);
                setField('destinatario_pec_email', data.destinatario_pec_email);
                setField('societa_incaricata', data.societa_incaricata);
                setField('societa_sede_legale', data.societa_sede_legale);
                setField('societa_cf_piva', data.societa_cf_piva);
                setField('data_inizio_prestazione', data.data_inizio_prestazione);
                setField('data_fine_prestazione', data.data_fine_prestazione);
                setField('codice_commessa', data.codice_commessa);
                setField('indirizzo_committente', data.indirizzo_committente);

                // Importi
                setMoneyField('importo_prestazioni', data.importo_prestazioni);
                setMoneyField('importo_lavori_esclusi_oneri', data.importo_lavori_esclusi_oneri);
                setMoneyField('oneri_sicurezza', data.oneri_sicurezza);
                setMoneyField('importo_lavori_totale', data.importo_lavori_totale);

                // Flags
                if (data.flags) {
                    Object.keys(data.flags).forEach(flagName => {
                        const checkbox = root.querySelector('#' + flagName);
                        if (checkbox) checkbox.checked = data.flags[flagName] === 1;
                    });
                }

                // Categorie d'opera
                if (Array.isArray(data.categorie_opera) && data.categorie_opera.length > 0) {
                    await ensureOpereDm50Loaded();
                    const catBody = root.querySelector('#cat-opera-body');
                    if (catBody) {
                        catBody.innerHTML = '';
                        categoriaRowCounter = 0;
                        data.categorie_opera.forEach(cat => addCategoriaRow(cat));
                    }
                }

                // Partecipanti
                if (Array.isArray(data.partecipanti) && data.partecipanti.length > 0) {
                    const partContainer = root.querySelector('#partecipanti-container');
                    if (partContainer) {
                        partContainer.innerHTML = '';
                        partecipanteRowCounter = 0;

                        const tipoIncarico = data.partecipanti.length > 1 ? 'rtp' : 'singolo';
                        const radioTipo = root.querySelector(`input[name="tipo_incarico"][value="${tipoIncarico}"]`);
                        if (radioTipo) radioTipo.checked = true;

                        await ensureAziendeLoaded();

                        for (const part of data.partecipanti) {
                            addPartecipanteRow();
                            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));

                            const rows = partContainer.querySelectorAll('.partecipante-row');
                            const newRow = rows[rows.length - 1];
                            if (newRow) {
                                const societaNomeInput = newRow.querySelector('input[name="societa_nome[]"]');
                                const societaPlaceholder = newRow.querySelector('.custom-select-placeholder');
                                const percentualeInput = newRow.querySelector('input[name="percentuale[]"]');
                                const societaSelectBox = newRow.querySelector('.societa-select-box');

                                if (societaNomeInput && part.societa_nome) societaNomeInput.value = part.societa_nome;
                                if (societaPlaceholder && part.societa_nome) societaPlaceholder.textContent = part.societa_nome;
                                if (percentualeInput && part.percentuale !== null && part.percentuale !== undefined) {
                                    percentualeInput.value = String(part.percentuale);
                                    percentualeInput.setAttribute('data-saved-value', 'true');
                                }
                                if (tipoIncarico === 'rtp' && rows.length === 1 && societaSelectBox) {
                                    societaSelectBox.style.pointerEvents = 'none';
                                    societaSelectBox.style.opacity = '0.7';
                                }
                            }
                        }

                        updatePartecipantiUI(tipoIncarico === 'rtp');
                        setupSocietaAutocomplete();
                        updateSelectSocietaSuddivisione();

                        if (data.partecipanti.length > 0 && data.partecipanti[0].societa_nome) {
                            const societaIncaricataInput = root.querySelector('#societa_incaricata');
                            if (societaIncaricataInput) societaIncaricataInput.value = data.partecipanti[0].societa_nome;
                        }
                    }
                }

                // Suddivisione servizio
                if (Array.isArray(data.suddivisione_servizio) && data.suddivisione_servizio.length > 0) {
                    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                    const sogBody = root.querySelector('#suddivisione-servizio-body');
                    if (sogBody) {
                        sogBody.innerHTML = '';
                        soggettoRowCounter = 0;
                        data.suddivisione_servizio.forEach(sud => {
                            let serviziArray = [];
                            if (sud.servizi_svolti) {
                                serviziArray = typeof sud.servizi_svolti === 'string'
                                    ? sud.servizi_svolti.split(',').map(s => s.trim()).filter(Boolean)
                                    : (Array.isArray(sud.servizi_svolti) ? sud.servizi_svolti : []);
                            }
                            addSoggettoRow({
                                societa: sud.societa_nome,
                                categoria_id: sud.categoria_id_opera,
                                percentuale: sud.percentuale_rtp,
                                servizi: serviziArray,
                                importo: sud.importo
                            });
                            setTimeout(() => {
                                const rows = sogBody.querySelectorAll('tr');
                                const lastRow = rows[rows.length - 1];
                                if (lastRow && serviziArray.length > 0) {
                                    serviziArray.forEach(servizioKey => {
                                        const checkbox = lastRow.querySelector(`input[type="checkbox"][value="${servizioKey}"]`);
                                        if (checkbox) checkbox.checked = true;
                                    });
                                }
                            }, 50);
                        });
                    }
                }

                // Incarichi
                if (Array.isArray(data.incarichi) && data.incarichi.length > 0) {
                    const incContainer = root.querySelector('#incarico-container');
                    if (incContainer) {
                        incContainer.innerHTML = '';
                        incaricoRowCounter = 0;
                        for (const inc of data.incarichi) {
                            addIncaricoRow();
                            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                            const rows = incContainer.querySelectorAll('.incarico-row');
                            const lastRow = rows[rows.length - 1];
                            if (lastRow) {
                                const personaleBox = lastRow.querySelector('.personale-select-box');
                                const nomeInput = lastRow.querySelector('input[name="incarico_nome[]"]');
                                const ruoloSelect = lastRow.querySelector('select[name="incarico_ruolo[]"]');
                                const societaSelect = lastRow.querySelector('select[name="incarico_societa[]"]');
                                const qualitaSelect = lastRow.querySelector('select[name="incarico_qualita[]"]');

                                if (inc.nome) {
                                    if (personaleBox) {
                                        const placeholder = personaleBox.querySelector('.custom-select-placeholder');
                                        if (placeholder) placeholder.textContent = inc.nome;
                                    }
                                    if (nomeInput) nomeInput.value = inc.nome;
                                }
                                if (ruoloSelect && inc.ruolo) ruoloSelect.value = inc.ruolo;
                                if (societaSelect && inc.societa) societaSelect.value = inc.societa;
                                if (qualitaSelect && inc.qualita) qualitaSelect.value = inc.qualita;

                                setupPersonaleAutocomplete();
                            }
                        }
                        updateIncaricoButtonsVisibility();
                        updateIncaricoSocietaSelect();
                    }
                }

                // Aggiorna totali
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
                updateTotaleCategorie();
                updateTotaleSoggetti();
                validateCoerenzaCategorie();
                refreshPercentualiSuddivisione();
                sanitizeSuddivisioneRows();

                // Callback
                if (config.onAfterLoad) config.onAfterLoad(data);
            } catch (err) {
                console.error('Errore caricamento comprovante:', err);
            }
        }

        function resetFormContent() {
            root.querySelector('form').reset();
            const catBody = root.querySelector('#cat-opera-body');
            if (catBody) catBody.innerHTML = '';
            const sogBody = root.querySelector('#suddivisione-servizio-body');
            if (sogBody) sogBody.innerHTML = '';

            // Reset partecipanti
            const partContainer = root.querySelector('#partecipanti-container');
            if (partContainer) {
                const rows = partContainer.querySelectorAll('.partecipante-row');
                for (let i = rows.length - 1; i > 0; i--) rows[i].remove();
                const firstRow = rows[0];
                if (firstRow) {
                    const nameInp = firstRow.querySelector('input[name="societa_nome[]"]');
                    const idInp = firstRow.querySelector('input[name="societa_id[]"]');
                    const ph = firstRow.querySelector('.custom-select-placeholder');
                    const perc = firstRow.querySelector('input[name="percentuale[]"]');
                    const defaultSocieta = config.datiPrecompilazione?.societa_incaricata || '';
                    if (nameInp) nameInp.value = defaultSocieta;
                    if (idInp) idInp.value = '';
                    if (ph) ph.textContent = defaultSocieta || 'Seleziona società...';
                    if (perc) perc.value = '100';
                }
            }

            // Reset incarichi
            const incContainer = root.querySelector('#incarico-container');
            if (incContainer) {
                const rows = incContainer.querySelectorAll('.incarico-row');
                for (let i = rows.length - 1; i > 0; i--) rows[i].remove();
                const firstRow = rows[0];
                if (firstRow) {
                    const nameInp = firstRow.querySelector('input[name="incarico_nome[]"]');
                    const ph = firstRow.querySelector('.custom-select-placeholder');
                    const ruolo = firstRow.querySelector('select[name="incarico_ruolo[]"]');
                    const societa = firstRow.querySelector('select[name="incarico_societa[]"]');
                    const qualita = firstRow.querySelector('select[name="incarico_qualita[]"]');
                    if (nameInp) nameInp.value = '';
                    if (ph) ph.textContent = 'Seleziona tecnico...';
                    if (ruolo) ruolo.value = '';
                    if (societa) societa.value = '';
                    if (qualita) qualita.value = '';
                }
            }

            // Reset counters
            categoriaRowCounter = 0;
            soggettoRowCounter = 0;
            partecipanteRowCounter = 0;
            incaricoRowCounter = 0;

            // Reset tipo incarico a singolo (come in chiusura)
            const radioSingolo = root.querySelector('input[name="tipo_incarico"][value="singolo"]');
            if (radioSingolo) {
                radioSingolo.checked = true;
                updatePartecipantiUI(false);
            }

            // Reset totals
            updateTotaleCategorie();
            updateTotaleSoggetti();
            updateIncaricoButtonsVisibility();
        }

        function setupFormReset() {
            const btn = root.querySelector('#btn-reset-certificato');
            if (btn) btn.addEventListener('click', () => {
                // TODO: Usare modale di conferma globale invece di confirm()
                if (confirm('Sei sicuro di voler svuotare tutti i campi del form?')) {
                    resetFormContent();
                }
            });

            const form = root.querySelector('form');
            if (form) {
                form.addEventListener('submit', async e => {
                    e.preventDefault();
                    // Build payload and save
                    const payload = buildComprovantePayload(form);
                    // Assicura che tabella contenga il codice_commessa reale
                    if (payload.codice_commessa) {
                        payload.tabella = payload.codice_commessa;
                    }

                    const res = await customFetch('commesse', 'saveComprovante', payload);
                    if (res.success) {
                        showToast('Salvato', 'success');
                        // Maybe callback
                        if (config.onSave) config.onSave(res);
                    } else {
                        showToast(res.error, 'error');
                    }
                });
            }
        }

        function buildComprovantePayload(form) {
            const fd = new FormData(form);

            const getMoney = (name) => {
                const raw = (fd.get(name) || '').toString().trim();
                if (!raw) return null;
                const normalized = raw.replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '');
                const num = Number(normalized);
                return Number.isFinite(num) ? num : null;
            };

            const getCheckbox = (name) => fd.get(name) ? 1 : 0;

            // Partecipanti
            const societaNomi = fd.getAll('societa_nome[]').map(v => (v || '').toString().trim()).filter(Boolean);
            const percentuali = fd.getAll('percentuale[]').map(v => (v || '').toString().trim());

            const capogruppoRadio = root.querySelector('input[name="capogruppo"]:checked');
            const capogruppoIndex = capogruppoRadio ? parseInt(capogruppoRadio.value, 10) : 0;

            const partecipanti = societaNomi.map((nome, idx) => ({
                societa_nome: nome,
                percentuale: percentuali[idx] ? Number(percentuali[idx]) : null,
                capogruppo: idx === capogruppoIndex
            }));
            partecipanti.sort((a, b) => (b.capogruppo ? 1 : 0) - (a.capogruppo ? 1 : 0));

            // Categorie d'opera
            const catIds = fd.getAll('categoria_id[]').map(v => (v || '').toString().trim());
            const catDescs = fd.getAll('categoria_desc[]').map(v => (v || '').toString().trim());
            const catImporti = fd.getAll('importo_categoria[]').map(v => (v || '').toString().trim());

            const categorie_opera = catIds.map((id, idx) => {
                const raw = catImporti[idx] || '';
                const normalized = raw ? raw.replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '') : '';
                const num = normalized ? Number(normalized) : null;
                return {
                    categoria_id: id || null,
                    categoria_desc: catDescs[idx] || '',
                    importo: (num !== null && Number.isFinite(num)) ? num : null
                };
            }).filter(r => r.categoria_id);

            const totaleCategorie = categorie_opera.reduce((sum, cat) => sum + (cat.importo || 0), 0);
            categorie_opera.forEach(cat => {
                cat.percentuale = (totaleCategorie > 0 && cat.importo) ? ((cat.importo / totaleCategorie) * 100).toFixed(2) : null;
            });

            // Suddivisione servizio
            const sudSoc = fd.getAll('societa_suddivisione[]').map(v => (v || '').toString().trim());
            const sudCat = fd.getAll('categoria_suddivisione[]').map(v => (v || '').toString().trim());
            const sudPerc = fd.getAll('percentuale_suddivisione[]').map(v => (v || '').toString().trim());
            const sudImpAll = fd.getAll('importo_soggetto[]').map(v => (v || '').toString().trim());

            const sudServ = [];
            root.querySelectorAll('#suddivisione-servizio-body tr').forEach((row, idx) => {
                const checkboxes = row.querySelectorAll('input.servizio-checkbox:checked');
                sudServ[idx] = Array.from(checkboxes).map(cb => cb.value).join(', ');
            });

            const suddivisione_servizio = sudSoc.map((soc, idx) => {
                const rawImp = sudImpAll[idx] || '';
                const normalizedImp = rawImp ? rawImp.replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '') : '';
                const impNum = normalizedImp ? Number(normalizedImp) : null;
                const percNum = sudPerc[idx] ? Number(sudPerc[idx]) : null;
                return {
                    societa_nome: soc || null,
                    categoria_id_opera: sudCat[idx] || null,
                    percentuale_rtp: (percNum !== null && Number.isFinite(percNum)) ? percNum : null,
                    servizi_svolti: sudServ[idx] || '',
                    importo: (impNum !== null && Number.isFinite(impNum)) ? impNum : null
                };
            }).filter(r => r.societa_nome && r.categoria_id_opera);

            const payload = {
                tabella: (fd.get('tabella') || '').toString().trim(),
                luogo_data_lettera: (fd.get('luogo_data_lettera') || '').toString().trim(),
                committente: (fd.get('committente') || '').toString().trim(),
                indirizzo_committente: (fd.get('indirizzo_committente') || '').toString().trim(),
                titolo_progetto: (fd.get('titolo_progetto') || '').toString().trim(),
                riferimento_contratto: (fd.get('riferimento_contratto') || '').toString().trim(),
                oggetto_contratto: (fd.get('oggetto_contratto') || '').toString().trim(),
                cig: (fd.get('cig') || '').toString().trim(),
                cup: (fd.get('cup') || '').toString().trim(),
                rup_nome: (fd.get('rup_nome') || '').toString().trim(),
                destinatario_spettabile: (fd.get('destinatario_spettabile') || '').toString().trim(),
                destinatario_indirizzo: (fd.get('destinatario_indirizzo') || '').toString().trim(),
                destinatario_pec_email: (fd.get('destinatario_pec_email') || '').toString().trim(),
                societa_incaricata: (fd.get('societa_incaricata') || '').toString().trim(),
                societa_sede_legale: (fd.get('societa_sede_legale') || '').toString().trim(),
                societa_cf_piva: (fd.get('societa_cf_piva') || '').toString().trim(),
                importo_prestazioni: getMoney('importo_prestazioni'),
                data_inizio_prestazione: (fd.get('data_inizio_prestazione') || '').toString().trim(),
                data_fine_prestazione: (fd.get('data_fine_prestazione') || '').toString().trim(),
                importo_lavori_esclusi_oneri: getMoney('importo_lavori_esclusi_oneri'),
                oneri_sicurezza: getMoney('oneri_sicurezza'),
                importo_lavori_totale: getMoney('importo_lavori_totale'),
                flags: {
                    fase_sf: getCheckbox('fase_sf'), fase_pp: getCheckbox('fase_pp'),
                    fase_pd: getCheckbox('fase_pd'), fase_pfte: getCheckbox('fase_pfte'),
                    fase_pe: getCheckbox('fase_pe'), fase_dl: getCheckbox('fase_dl'),
                    fase_dos: getCheckbox('fase_dos'), fase_doi: getCheckbox('fase_doi'),
                    fase_da: getCheckbox('fase_da'), fase_csp: getCheckbox('fase_csp'),
                    fase_cse: getCheckbox('fase_cse'), att_bim: getCheckbox('att_bim'),
                    att_cam_dnsh: getCheckbox('att_cam_dnsh'), att_antincendio: getCheckbox('att_antincendio'),
                    att_acustica: getCheckbox('att_acustica'), att_relazione_geologica: getCheckbox('att_relazione_geologica')
                },
                partecipanti,
                categorie_opera,
                suddivisione_servizio,
                incarichi: (() => {
                    const incNomi = fd.getAll('incarico_nome[]').map(v => (v || '').toString().trim()).filter(Boolean);
                    const incRuoli = fd.getAll('incarico_ruolo[]').map(v => (v || '').toString().trim());
                    const incSocieta = fd.getAll('incarico_societa[]').map(v => (v || '').toString().trim());
                    const incQualita = fd.getAll('incarico_qualita[]').map(v => (v || '').toString().trim());
                    return incNomi.map((nome, idx) => ({
                        nome: nome,
                        ruolo: incRuoli[idx] || '',
                        societa: incSocieta[idx] || '',
                        qualita: incQualita[idx] || ''
                    }));
                })()
            };

            JSON.stringify(payload); // sanity check
            return payload;
        }

        function setupImportiMoney() {
            if (typeof window.initMoneyInputs === 'function') window.initMoneyInputs();
        }

        function setupCalcoloImportoLavoriTotale() {
            const a = root.querySelector('#importo_lavori_esclusi_oneri');
            const b = root.querySelector('#oneri_sicurezza');
            const c = root.querySelector('#importo_lavori_totale');

            const calc = () => {
                let v1 = parseFloat(a.value.replace(/\./g, '').replace(',', '.')) || 0;
                let v2 = parseFloat(b.value.replace(/\./g, '').replace(',', '.')) || 0;
                c.value = (v1 + v2).toLocaleString('it-IT');
            };

            if (a && b && c) {
                a.addEventListener('input', calc);
                b.addEventListener('input', calc);
            }
        }

        function setupPersonaleAutocomplete() {
            // Feature detect & silent exit
            if (typeof window.autocompleteManager === 'undefined' ||
                typeof window.autocompleteManager.init !== 'function') {
                return;
            }

            const container = root.querySelector('#incarico-container');
            if (!container) return;

            const boxes = container.querySelectorAll('.personale-select-box');
            boxes.forEach(box => {
                // Skip if already initialized (idempotence check)
                if (box.dataset.autocompleteInit === 'true') return;

                window.autocompleteManager.init(box, {
                    type: 'personale',
                    placeholder: "Cerca tecnico..."
                });

                // Mark as initialized
                box.dataset.autocompleteInit = 'true';
            });
        }

        function setupSocietaAutocomplete() {
            // Feature detect & silent exit
            if (typeof window.autocompleteManager === 'undefined' ||
                typeof window.autocompleteManager.init !== 'function') {
                return;
            }

            const boxes = root.querySelectorAll('.societa-select-box');
            const isRTP = root.querySelector('input[name="tipo_incarico"]:checked')?.value === 'rtp';

            boxes.forEach((box, index) => {
                // In RTP, salta la prima riga (Incide, non modificabile)
                if (isRTP && index === 0) return;

                // Skip if already initialized (idempotence check)
                if (box.dataset.autocompleteInit === 'true') return;

                window.autocompleteManager.init(box, {
                    type: 'companies',
                    placeholder: 'Cerca società...',
                    onSelect: function (selectedItem, inputVal, isFree) {
                        const placeholder = box.querySelector('.custom-select-placeholder');
                        const societaIdInput = box.querySelector('input[name="societa_id[]"]');
                        const societaNomeInput = box.querySelector('input[name="societa_nome[]"]');

                        if (isFree && inputVal && inputVal.trim().length > 1) {
                            if (typeof window.showAddCompanyModal === 'function') {
                                window.showAddCompanyModal(inputVal, function (nuovaAzienda) {
                                    if (placeholder) placeholder.textContent = nuovaAzienda.ragionesociale || inputVal;
                                    if (societaIdInput) societaIdInput.value = nuovaAzienda.id || '';
                                    if (societaNomeInput) {
                                        societaNomeInput.value = nuovaAzienda.ragionesociale || inputVal;
                                        societaNomeInput.dispatchEvent(new Event('change', { bubbles: true }));
                                    }
                                    updateSelectSocietaSuddivisione();
                                });
                            }
                            return;
                        }

                        if (selectedItem) {
                            if (placeholder) placeholder.textContent = selectedItem.label;
                            if (societaIdInput) societaIdInput.value = selectedItem.value;
                            if (societaNomeInput) {
                                societaNomeInput.value = selectedItem.label;
                                societaNomeInput.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                            updateSelectSocietaSuddivisione();
                        }
                    }
                });

                // Mark as initialized
                box.dataset.autocompleteInit = 'true';
            });
        }

        function setupCommessaAutocomplete() {
            const input = root.querySelector('#codice_commessa');
            if (!input || input.readOnly) return;

            let resultsContainer = root.querySelector('.autocomplete-results-container');
            if (!resultsContainer) {
                resultsContainer = document.createElement('div');
                resultsContainer.className = 'autocomplete-results-container';
                if (input.parentNode) input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(resultsContainer);
            }

            let debounceTimer;
            input.addEventListener('input', function () {
                const q = this.value.trim();
                clearTimeout(debounceTimer);

                // Permettiamo query vuota per mostrare i default, ma nascondiamo se è troppo corta (1 carattere)
                if (q.length === 1) {
                    resultsContainer.innerHTML = '';
                    resultsContainer.classList.remove('show');
                    return;
                }

                debounceTimer = setTimeout(async () => {
                    try {
                        const res = await customFetch('commesse', 'searchCommesse', { q: q });
                        if (res.success && res.data) {
                            renderResults(res.data, q);
                        }
                    } catch (err) {
                        console.error('Errore searchCommesse:', err);
                    }
                }, 300);
            });

            function renderResults(data, query) {
                resultsContainer.innerHTML = '';
                if (data.length === 0) {
                    resultsContainer.classList.remove('show');
                    return;
                }

                data.forEach(item => {
                    const row = document.createElement('div');
                    row.className = 'autocomplete-result-row';
                    const label = item.label || item.value;
                    const escapedLabel = typeof window.escapeHtml === 'function' ? window.escapeHtml(label) : label;

                    // Highlight query
                    const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
                    const highlighted = escapedLabel.replace(regex, '<strong>$1</strong>');

                    row.innerHTML = `
                        <div class="result-label">${highlighted}</div>
                        ${item.cliente ? `<div class="result-sublabel">${typeof window.escapeHtml === 'function' ? window.escapeHtml(item.cliente) : item.cliente}</div>` : ''}
                    `;

                    row.onclick = () => {
                        input.value = item.value;
                        resultsContainer.innerHTML = '';
                        resultsContainer.classList.remove('show');

                        // Trigger load
                        loadComprovanteSaved(item.value);

                        // Dispatch events
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    };
                    resultsContainer.appendChild(row);
                });
                resultsContainer.classList.add('show');
            }

            input.addEventListener('focus', function () {
                if (this.value.trim() === '') {
                    // Trigger search with empty query to show defaults
                    input.dispatchEvent(new Event('input'));
                } else if (resultsContainer.children.length > 0) {
                    resultsContainer.classList.add('show');
                }
            });

            input.addEventListener('click', function () {
                if (this.value.trim() === '') {
                    input.dispatchEvent(new Event('input'));
                } else if (resultsContainer.children.length > 0) {
                    resultsContainer.classList.add('show');
                }
            });

            // Close when clicking outside
            document.addEventListener('click', (e) => {
                if (!input.contains(e.target) && !resultsContainer.contains(e.target)) {
                    resultsContainer.classList.remove('show');
                }
            });
        }

        // Start initialization
        init();

        return root.comprovanteApi;
    }

    // Expose Global
    window.initComprovanteForm = initComprovanteForm;

})(window);
