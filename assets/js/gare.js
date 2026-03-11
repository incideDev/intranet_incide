document.addEventListener("DOMContentLoaded", function () {
    // Se siamo in modalità iframe, non eseguire questo codice
    const isIframeMode = document.getElementById("gare-frame") !== null;
    const hasArchiveTable = document.getElementById("gare-list") !== null;
    if (isIframeMode || !hasArchiveTable) {
        return;
    }
    
    // Listener per filtri dinamici (payload strutturato: {state, filters, meta})
    window.addEventListener('filters:applied', (e) => {
        const detail = e.detail || {};
        const filters = (detail.state && detail.state.filters) || detail.filters || {};
        caricaArchivioGare(filters);
    });
    
    caricaArchivioGare();

    // — Minimize: chiude solo il modale e salva un draft “scratch” lato tab (opzionale)
    const minimizeBtn = document.getElementById('modalGaraMinimizeBtn');
    if (minimizeBtn) {
        minimizeBtn.addEventListener('click', () => {
            chiudiModaleGara();
            if (typeof window.showToast === 'function') {
                showToast('Modale minimizzato. Puoi seguire i job dalla dock.');
            }
        });
    }

    // quando la Dock segnala un completamento, aggiorna la tabella e la lista modale
    document.addEventListener('gare:estrazione:completed', () => {
        try { window.caricaArchivioGare && window.caricaArchivioGare(); } catch { }
        showToast?.('Estrazione completata');
    });

    document.getElementById("breadcrumb-archivio-gare")?.addEventListener("click", function (event) {
        event.preventDefault();
        toggleArchivioGare();
    });

    window.addEventListener("click", function (event) {
        const modal = document.getElementById("modalAddGara");
        if (event.target === modal) {
            chiudiModaleGara();
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            chiudiModaleGara();
        }
    });

    // --- ESTRAI DATI DA PDF CON IA (multi) ---
    const pdfInput = document.getElementById('modal-gara-pdf');
    const estraiBtn = document.getElementById('modal-gara-pdf-btn');
    const statusSpan = document.getElementById('modal-gara-pdf-status');
    const selectedEl = document.getElementById('modal-gara-selected');

    function renderSelectedFiles(files) {
        if (!selectedEl) return;
        const arr = Array.from(files || []);
        if (!arr.length) { selectedEl.innerHTML = ''; return; }
        selectedEl.innerHTML = `
            <ul class="file-selected-list">
                ${arr.map(f => `<li title="${f.name}">${f.name} <span class="help-dim">(${Math.ceil(f.size / 1024)} KB)</span></li>`).join('')}
            </ul>
        `;
    }

    if (pdfInput) {
        pdfInput.addEventListener('change', () => {
            renderSelectedFiles(pdfInput.files);
        });
    }

    if (pdfInput && estraiBtn) {
        estraiBtn.addEventListener('click', async function () {
            const files = Array.from(pdfInput.files || []);
            if (!files.length) { statusSpan.textContent = "Seleziona uno o più PDF"; return; }

            const batch = files.slice(0, 6);
            if (files.length > 6) {
                showToast?.('Considero solo i primi 6 PDF', 'error');
            }
            statusSpan.textContent = `Avvio estrazione (${batch.length})…`;

            // Tipi scelti (gli stessi che avevi prima)
            const types = [
                'oggetto_appalto',
                'stazione_appaltante',
                'data_scadenza_gara_appalto',
                'data_uscita_gara_appalto',
                'tipologia_di_gara',
                'tipologia_di_appalto',
                'luogo_provincia_appalto',
                'sopralluogo_obbligatorio',
                'link_portale_stazione_appaltante'
            ];

            try {
                const runExtraction = async (options) => {
                    if (options) {
                        return window.GareDock.startConcurrent(batch, types, options);
                    }
                    return window.GareDock.startConcurrent(batch, types);
                };

                const handleSuccess = async (jobs, messagePrefix = 'Avviate') => {
                    const started = Array.isArray(jobs) ? jobs : [];
                    statusSpan.textContent = `${messagePrefix} ${started.length} estrazioni.`;

                    for (const j of started) {
                        try {
                            const phRes = await customFetch('gare', 'createPlaceholderFromJob', {
                                job_id: j.job_id,
                                original_filename: j.file_name
                            }, { timeoutMs: 10000 });

                            if (phRes?.success) {
                                // Ora gara_id = job_id, non serve più linkRow
                                // window.GareDock?.linkRow?.(j.job_id, phRes.gara_id || j.job_id);
                            } else {
                                console.warn('Placeholder non creato:', phRes?.error || phRes);
                            }
                        } catch (e) {
                            console.warn('Errore createPlaceholderFromJob:', e);
                        }

                        trackJobAndRefresh?.(j.job_id, j.file_name);
                    }

                    renderModalJobs();
                };

                const res = await runExtraction();

                if (res?.success) {
                    await handleSuccess(res.jobs);
                    pdfInput.value = '';
                    renderSelectedFiles([]);
                    return;
                }

                const duplicateCodes = new Set([
                    'already_exists',
                    'already_processed',
                    'already_present',
                    'duplicate'
                ]);
                let isDuplicate = false;

                const code = (res?.code || res?.error || '').toString().toLowerCase();
                if (code && duplicateCodes.has(code)) {
                    isDuplicate = true;
                }
                if (!isDuplicate && typeof res?.already_processed === 'boolean') {
                    isDuplicate = res.already_processed;
                }
                if (!isDuplicate) {
                    const message = (res?.message || '').toString().toLowerCase();
                    if (message.includes('già') && (message.includes('caricato') || message.includes('elaborato'))) {
                        isDuplicate = true;
                    }
                }

                if (!isDuplicate) {
                    statusSpan.textContent = res?.message || 'Errore avvio estrazioni.';
                    return;
                }

                renderModalJobs([]);
                pdfInput.value = '';
                renderSelectedFiles([]);
                statusSpan.innerHTML = `
                    <span class="warning">
                        File già elaborato.
                        <button class="refresh-existing" id="refresh-existing-btn" title="Riesegui estrazione">
                            <img src="assets/icons/refresh.png" alt="Aggiorna" />
                        </button>
                    </span>
                `;

                const button = document.getElementById('refresh-existing-btn');
                if (button) {
                    button.addEventListener('click', async () => {
                        statusSpan.textContent = 'Riavvio estrazione in corso…';
                        try {
                            const forced = await runExtraction({ force: true });
                            if (!forced?.success) {
                                statusSpan.textContent = forced?.message || 'Errore durante il riavvio.';
                                return;
                            }
                            await handleSuccess(forced.jobs, 'Riavviate');
                        } catch (err) {
                            console.error('Errore riavvio estrazione:', err);
                            statusSpan.textContent = 'Errore durante il riavvio.';
                        }
                    }, { once: true });
                }
            } catch (err) {
                statusSpan.textContent = 'Errore imprevisto durante l’avvio.';
            }
        });
    }
});

function raccogliDraftForm() {
    const byId = (id) => document.getElementById(id);
    return {
        titolo: byId('garaOggetto')?.value || '',
        tipo_lavori: byId('garaTipologia')?.value || '',
        tipologia_appalto: byId('garaTipologiaAppalto')?.value || '',
        ente: byId('garaEnte')?.value || '',

        data_uscita: window.utils.parseDateToISO(byId('garaDataUscita')?.value || ''),
        data_scadenza: window.utils.parseDateToISO(byId('garaDataScadenza')?.value || ''),
        luogo: byId('garaLuogo')?.value || '',
        sopralluogo: byId('garaSopralluogo')?.value || 'No',
        link_portale: byId('garaLinkPortale')?.value || ''
    };
}

// Evento dalla dock: apri la BOZZA nella STESSA scheda con URL corto (token server-side)
document.addEventListener('gare:draft:open', async (ev) => {
    try {
        const src = ev?.detail?.draft || {};
        // normalizza date e valori fondamentali
        const draft = {
            titolo: src.titolo || src.oggetto_appalto || '',
            tipo_lavori: src.tipo_lavori || src.tipologia_di_gara || '',
            tipologia_appalto: src.tipologia_appalto || src.tipologia_di_appalto || '',
            ente: src.ente || src.stazione_appaltante || '',
            data_uscita: window.utils.parseDateToISO(src.data_uscita || src.data_uscita_gara_appalto || ''),
            data_scadenza: window.utils.parseDateToISO(src.data_scadenza || src.data_scadenza_gara_appalto || ''),
            luogo: src.luogo || src.luogo_provincia_appalto || '',
            sopralluogo: mapSopralluogoSelectValue(src.sopralluogo ?? src.sopralluogo_obbligatorio),
            link_portale: src.link_portale || src.link_portale_stazione_appaltante || ''
        };

        // 1) Stocca lato server e ottieni token
        const res = await customFetch('gare', 'stashDraft', draft, { timeoutMs: 10000 });
        if (!res?.success || !res?.token) throw new Error(res?.error || 'stashDraft failed');

        // 2) Costruisci URL in modo sicuro (stessa scheda)
        const url = new URL('index.php', window.location.origin);
        url.searchParams.set('section', 'gestione');
        url.searchParams.set('page', 'gare_dettaglio');
        url.searchParams.set('mode', 'new');
        url.searchParams.set('draft', res.token);

        // 3) Naviga nella stessa scheda
        window.location.assign(url.toString());
    } catch (e) {
        console.error('Errore apertura bozza:', e);
        if (typeof window.showToast === 'function') showToast('Errore apertura scheda', 'error');

        // --- Fallback “no server token”: sessionStorage + URL minimale (senza dati sensibili) ---
        try {
            const src = ev?.detail?.draft || {};
            const key = 'GARA_BOOT_DRAFT';
            sessionStorage.setItem(key, JSON.stringify(src)); // stesso-tab → persiste per la successiva navigazione
            const url = new URL('index.php', window.location.origin);
            url.searchParams.set('section', 'gestione');
            url.searchParams.set('page', 'gare_dettaglio');
            url.searchParams.set('mode', 'new');
            window.location.assign(url.toString());
        } catch { }
    }
});

// --- RESPONSABILI ---
function caricaResponsabili() {
    const select = document.getElementById("garaResponsabili");
    if (!select) return;

    customFetch('gare', 'getResponsabili')
        .then(data => {
            if (!data.success) {
                console.error(" Errore nel caricamento dei responsabili:", data.message || data.error);
                return;
            }

            select.innerHTML = "";
            select.setAttribute("multiple", "multiple");

            (data.responsabili || data.data || []).forEach(responsabile => {
                let option = document.createElement("option");
                option.value = responsabile.id || responsabile.Cod_Operatore;
                option.textContent = responsabile.Nominativo
                    ? `${responsabile.Nominativo} (${responsabile.id || responsabile.Cod_Operatore})`
                    : (responsabile.nome || responsabile.id);
                select.appendChild(option);
            });

        })
        .catch(error => console.error(" Errore di rete nel recupero dei responsabili:", error));
}

function openGaraModal() {
    const modal = document.getElementById("modalAddGara");
    if (!modal) return;
    modal.style.display = "block";
    renderModalJobs();
}

window.openGaraModal = openGaraModal;

function chiudiModaleGara() {
    const modal = document.getElementById("modalAddGara");
    if (modal) {
        modal.style.display = "none";
    }
}

window.caricaArchivioGare = function (filtri = {}) {
    // Combina filtri dinamici con altri filtri esistenti
    const appliedState = (window.__APPLIED_FILTERS__ && window.__APPLIED_FILTERS__['filter-container']) || {};
    const appliedFilters = appliedState.filters || appliedState;
    const combinedFilters = {
        ...appliedFilters,
        ...filtri
    };
    
    customFetch('gare', 'getGare', combinedFilters)
        .then(data => {
            const tbody = document.getElementById("gare-list");
            if (!tbody) {
                // Se l'elemento non esiste, probabilmente siamo in modalità iframe
                // Non fare nulla, il contenuto è gestito dall'iframe
                return;
            }
            tbody.innerHTML = "";
            if (!data.success || !data.data || !Array.isArray(data.data)) {
                tbody.innerHTML = "<tr><td colspan='9'>Nessuna gara trovata</td></tr>";
                return;
            }

            data.data.forEach(row => {
                const garaId = row.gara_id ?? row.id ?? row.ID;
                const nGara = row.n_gara || '—';
                const ente = row.ente || "N/A";
                const titolo = row.titolo || "N/A";
                const tipologia = row.tipologia || "N/A";
                const luogo = row.luogo || "N/A";
                const duISO = row.data_uscita ? window.utils.parseDateToISO(row.data_uscita) : "";
                const dsISO = row.data_scadenza ? window.utils.parseDateToISO(row.data_scadenza) : "";
                const duFmt = duISO ? window.formatDate(duISO) : "N/A";
                const dsFmt = dsISO ? window.formatDate(dsISO) : "N/A";
                // NB: getGare() mappa già status_id -> stringa ("Bozza", "Pubblicata", …)
                const stato = row.status_id || "N/A";

                const tr = document.createElement("tr");
                tr.innerHTML = `
                    <td style="text-align:center;">
                        <img src="assets/icons/show.png"
                             alt="Visualizza"
                             class="action-icon"
                             data-tooltip="Visualizza dettagli gara"
                             onclick="window.location.href='index.php?section=commerciale&page=gare_dettaglio&gara_id=${garaId}'">
                    </td>
                    <td>${nGara}</td>
                    <td>${ente}</td>
                    <td>${titolo}</td>
                    <td>${tipologia}</td>
                    <td>${luogo}</td>
                    <td>${duFmt}</td>
                    <td>${dsFmt}</td>
                    <td>${stato}</td>
                `;
                tbody.appendChild(tr);
            });
        })
        .catch(() => {
            const tbody = document.getElementById("gare-list");
            if (tbody) {
                tbody.innerHTML = "<tr><td colspan='9'>Errore di caricamento!</td></tr>";
            }
        });
};


window.caricaArchivioGare = caricaArchivioGare;
window.loadGare = caricaArchivioGare; // Alias per compatibilità filtri dinamici
window.chiudiModaleGara = chiudiModaleGara;
window.openGaraModal = openGaraModal;

window.openTaskDetails = function (taskId, tabella) {
    window.location.href = `index.php?section=commerciale&page=gare_dettaglio&gara_id=${taskId}`;
};

// Provider calendario per la pagina "gare"
(function registerGareProvider() {
    if (!window.calendarProviders) window.calendarProviders = {};

    window.calendarProviders['gare'] = async function () {
        console.log('[Gare Calendar Provider] Called');
        const gareState = (window.__APPLIED_FILTERS__ && window.__APPLIED_FILTERS__['filter-container']) || {};
        const gareFilters = gareState.filters || gareState;
        const params = {
            participation: true, // Solo gare con participation=1 per calendario/gantt
            ...(window.appliedFilters || {}),
            ...gareFilters
        };
        let rows = [];

        // 2) usa getGare direttamente
        try {
            const res2 = await customFetch('gare', 'getGare', params);
            rows = Array.isArray(res2?.data) ? res2.data : (Array.isArray(res2) ? res2 : []);
            console.log('[Gare Calendar Provider] Got', rows.length, 'rows from getGare');
        } catch (err) {
            console.error('[Gare Calendar Provider] Error:', err);
            rows = [];
        }

        // Usa toISO centralizzata da main_core.js
        const toISO = (s) => window.utils?.parseDateToISO?.(s) || s || '';

        // mappa sia numeri che stringhe ("Bozza", "Pubblicata", ecc.)
        const normStatus = (s) => {
            if (s == null) return NaN;
            if (typeof s === 'number') return s;
            if (typeof s === 'string') {
                const map = {
                    'bozza': 1,
                    'pubblicata': 2,
                    'in valutazione': 3,
                    'aggiudicata': 4,
                    'archiviata': 5
                };
                const key = s.trim().toLowerCase();
                // se è un numero in stringa, parseInt lo gestisce
                const num = parseInt(key, 10);
                if (!Number.isNaN(num)) return num;
                return map[key] ?? NaN;
            }
            return NaN;
        };

        const stateColor = (status) => {
            const n = normStatus(status);
            return GARE_STATUS_COLORS[n] || '#8e44ad'; // Default: Bozza
        };

        const items = rows.map(r => {
            const id = r.id ?? r.gara_id ?? r.ID ?? null;
            if (!id) return null; // Salta se non ha ID
            
            const titolo = String(r.titolo || r.n_gara || 'Gara').trim();
            if (!titolo) return null; // Salta se non ha titolo

            // Le date da listGare() sono già in formato ISO YYYY-MM-DD, ma verifichiamo
            let startISO = r.data_uscita || r.start || r.data_inizio || null;
            let endISO = r.data_scadenza || r.end || r.data_fine || null;

            // Se sono già in formato ISO, usale direttamente
            // Altrimenti prova a convertirle
            if (startISO && typeof startISO === 'string' && !startISO.match(/^\d{4}-\d{2}-\d{2}/)) {
                startISO = toISO(startISO);
            }
            if (endISO && typeof endISO === 'string' && !endISO.match(/^\d{4}-\d{2}-\d{2}/)) {
                endISO = toISO(endISO);
            }

            // Se non ha start, usa end come start
            if (!startISO && endISO) {
                startISO = endISO;
            }
            // Se non ha end, crea end = start + 1 giorno
            if (!endISO && startISO) {
                const d = new Date(startISO);
                if (!isNaN(d.getTime())) {
                    d.setDate(d.getDate() + 1);
                    endISO = d.toISOString().slice(0, 10);
                }
            }

            // Verifica che startISO sia valida
            if (!startISO || !startISO.match(/^\d{4}-\d{2}-\d{2}/)) {
                return null; // Salta se non ha una data valida
            }

            // Verifica che endISO non sia prima di startISO
            if (endISO && startISO && endISO < startISO) {
                endISO = startISO;
            }

            // Usa gara_status_id (stato gara) per calendario/gantt, non status estrazione
            const statusVal = r.gara_status_id ?? r.status_id ?? r.stato ?? 1;

            return {
                id: `ext_jobs:${id}`, // Ora usiamo ext_jobs invece di elenco_bandi_gare
                title: titolo,
                start: startISO,
                end: endISO || startISO, // Assicurati che end esista
                status: statusVal,
                url: (id ? `index.php?section=commerciale&page=gare_dettaglio&gara_id=${encodeURIComponent(id)}` : null),
                color: stateColor(statusVal),
                meta: { raw: { ...r, table_name: 'ext_jobs', id, gara_status_id: r.gara_status_id || r.status_id } } // Ora usiamo ext_jobs e gara_status_id
            };
        }).filter(Boolean);

        console.log('[Gare Calendar Provider] Returning', items.length, 'items');
        return items;
    };

    // compat: esponi subito il provider globale (Gantt lo usa se non gli passi provider)
    window.calendarDataProvider = window.calendarProviders['gare'];
    
    // Registra anche per 'elenco_gare' per supporto diretto
    if (window.calendarProviders && window.calendarProviders['gare'] && !window.calendarProviders['elenco_gare']) {
        window.calendarProviders['elenco_gare'] = window.calendarProviders['gare'];
    }

    // segnala (se serve) che il provider è pronto
    const page = url.searchParams.get('page') || (window.location.pathname.split('/').pop() || '').replace('.php', '');
    const ev = new CustomEvent('calendar-provider-ready', { detail: { page: page === 'elenco_gare' ? 'elenco_gare' : 'gare' } });
    window.dispatchEvent(ev);
})();

// --- BOOTSTRAP CALENDARIO + GANTT PER "elenco_gare" ---
document.addEventListener('DOMContentLoaded', function () {
    const url = new URL(window.location.href);
    const page = url.searchParams.get('page') || (window.location.pathname.split('/').pop() || '').replace('.php', '');
    // Solo per elenco_gare, non per estrazione_bandi
    const isElencoGare = (page === 'elenco_gare');
    if (!isElencoGare) return;

    function getProvider() {
        // Supporta sia 'elenco_gare' che 'gare' per retrocompatibilità
        const page = url.searchParams.get('page') || (window.location.pathname.split('/').pop() || '').replace('.php', '');
        return (window.calendarProviders && (window.calendarProviders[page] || window.calendarProviders['gare']))
            ? (window.calendarProviders[page] || window.calendarProviders['gare'])
            : (typeof window.calendarDataProvider === 'function' ? window.calendarDataProvider : null);
    }

    // --- dedup containers ---
    function dedupContainer(id) {
        const all = Array.from(document.querySelectorAll(`#${id}`));
        if (all.length <= 1) return document.getElementById(id);
        const last = all[all.length - 1];
        all.forEach((el, i) => {
            if (el === last) return;
            el.id = `${id}__dup__${i + 1}`;
            el.classList.add('hidden');
        });
        last.id = id;
        return last;
    }
    const calContainer = dedupContainer('calendar-view');
    const ganttContainer = dedupContainer('gantt-view');

    function ensureCalendar(provider) {
        if (!calContainer || !provider) return;
        window.calendarDataProvider = provider; // compat con entrambi
        if (window.CalendarView?.init) {
            CalendarView.init({ containerId: 'calendar-view', provider, view: 'month' });
        } else {
            setTimeout(() => ensureCalendar(provider), 0);
        }
    }
    function ensureGantt(provider) {
        if (!ganttContainer || !provider) return;
        if (window.GanttView?.init) {
            // range più largo: se le date sono “lontane”, non vedi vuoto
            GanttView.init({ containerId: 'gantt-view', provider, range: 'quarter' });
        } else {
            setTimeout(() => ensureGantt(provider), 0);
        }
    }

    const provider = getProvider();
    ensureCalendar(provider);
    ensureGantt(provider);

    // se il provider arriva dopo
    window.addEventListener('calendar-provider-ready', (ev) => {
        const page = url.searchParams.get('page') || (window.location.pathname.split('/').pop() || '').replace('.php', '');
        if (ev?.detail?.page === 'gare' || ev?.detail?.page === 'elenco_gare' || page === 'elenco_gare') {
            const p = getProvider();
            ensureCalendar(p);
            ensureGantt(p);
        }
    });

    // Toggle viste da query (?calendar=true | ?gantt=true)
    const wantCal = url.searchParams.get('calendar') === 'true';
    const wantGant = url.searchParams.get('gantt') === 'true';

    if (wantCal || wantGant) {
        const cv = document.getElementById('calendar-view');
        const gv = document.getElementById('gantt-view');
        const tv = document.getElementById('table-view');
        const kv = document.getElementById('kanban-view');

        if (wantCal) { cv?.classList.remove('hidden'); gv?.classList.add('hidden'); }
        if (wantGant) { gv?.classList.remove('hidden'); cv?.classList.add('hidden'); }

        tv?.classList.add('hidden');
        kv?.classList.add('hidden');
        if (typeof window.updateButtons === 'function') updateButtons();
    }
});

function stripDiacritics(value) {
    if (typeof value !== 'string') {
        return '';
    }
    if (value.normalize) {
        return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    return value;
}

function normalizeSopralluogoStatus(value) {
    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value === 'boolean') {
        return value ? 'si' : 'no';
    }
    const raw = String(value).trim();
    if (!raw) {
        return '';
    }
    const normalized = stripDiacritics(raw).toLowerCase();
    if (['1', 'true', 'si', 'sì', 'yes', 'obbligatorio'].includes(normalized)) {
        return 'si';
    }
    if (['0', 'false', 'no', 'non', 'facoltativo'].includes(normalized)) {
        return 'no';
    }
    return raw;
}

function mapSopralluogoSelectValue(value) {
    const normalized = normalizeSopralluogoStatus(value);
    if (normalized === 'si') return 'Sì';
    if (normalized === 'no') return 'No';
    return 'No';
}

