document.addEventListener('DOMContentLoaded', () => {
    initSicElencoDoc().catch(e => console.error('[sic_elenco_doc] init error:', e));
});

function parseISODate(v) {
    if (!v) return null;
    try {
        // Se è già Date, ritorna direttamente
        if (v instanceof Date) return v;
        // Se stringa tipo "2025-09-27"
        const d = new Date(v);
        return isNaN(d.getTime()) ? null : d;
    } catch {
        return null;
    }
}

async function initSicElencoDoc() {
    const tabella = (window._tabellaCommessa || '').replace(/[^a-z0-9_]/gi, '').toLowerCase();
    const codiceCommessa = (window._tabellaCommessa || '').toUpperCase();
    if (!tabella) return;

    // igiene iniziale: evita che .show rimanga da render precedenti
    document.getElementById('modal-sic-doc')?.classList.remove('show');

    const table = document.getElementById('docs-table');
    const tbody = table?.querySelector('tbody');
    if (!tbody) return;

    // === FILTRI (client-side) ===
    const filterState = {
        azienda: '',
        tipo: '',
        persona: '',
        da: '',
        a: '',
        entro: '' // 30|60|90 giorni
    };

    // crea la barra filtri sopra la tabella (se non esiste)
    let filterBar = document.getElementById('sic-filter-bar');
    if (!filterBar) {
        filterBar = document.createElement('div');
        filterBar.id = 'sic-filter-bar';
        filterBar.className = 'sic-filter-bar';
        filterBar.style.width = '100%'; // <-- forza ampiezza

        const wrap = table.parentElement || table;        // .table-wrap
        // inserisci la barra subito PRIMA del wrapper, non “dentro”
        wrap.insertAdjacentElement('beforebegin', filterBar);
    }

    // struttura controlli
    filterBar.innerHTML = `
    <div class="sic-field">
        <label class="sic-label">Azienda</label>
        <select id="f-azienda" class="sic-control"><option value="">Tutte</option></select>
    </div>
    <div class="sic-field">
        <label class="sic-label">Tipo documento</label>
        <select id="f-tipo" class="sic-control"><option value="">Tutti</option></select>
    </div>
    <div class="sic-field">
        <label class="sic-label">Personale (contiene)</label>
        <input id="f-persona" class="sic-control" type="text" placeholder="es. Rossi">
    </div>
    <div class="sic-field">
        <label class="sic-label">Scadenza da</label>
        <input id="f-da" class="sic-control" type="date">
    </div>
    <div class="sic-field">
        <label class="sic-label">Scadenza a</label>
        <input id="f-a" class="sic-control" type="date">
    </div>
    <div class="sic-field">
        <label class="sic-label">Solo in scadenza entro</label>
        <select id="f-entro" class="sic-control">
        <option value="">—</option>
        <option value="30">30 giorni</option>
        <option value="60">60 giorni</option>
        <option value="90">90 giorni</option>
        </select>
    </div>
    `;

    // bind controlli
    const fAzienda = filterBar.querySelector('#f-azienda');
    const fTipo = filterBar.querySelector('#f-tipo');
    const fPersona = filterBar.querySelector('#f-persona');
    const fDa = filterBar.querySelector('#f-da');
    const fA = filterBar.querySelector('#f-a');
    const fEntro = filterBar.querySelector('#f-entro');

    [fAzienda, fTipo, fPersona, fDa, fA, fEntro].forEach(el => {
        el?.addEventListener('input', applyFiltersAndRerender);
        el?.addEventListener('change', applyFiltersAndRerender);
    });

    // popolazione dinamica di Azienda/Tipo dopo il primo load
    function populateFilterOptions({ aziende, tipi }) {
        if (fAzienda && fAzienda.options.length <= 1) {
            fAzienda.innerHTML = `<option value="">Tutte</option>` + Array.from(aziende).sort((a, b) => a.localeCompare(b)).map(
                a => `<option value="${escAttr(a)}">${esc(a)}</option>`
            ).join('');
        }
        if (fTipo && fTipo.options.length <= 1) {
            fTipo.innerHTML = `<option value="">Tutti</option>` + Array.from(tipi).sort((a, b) => a.localeCompare(b)).map(
                t => `<option value="${escAttr(t)}">${esc(t)}</option>`
            ).join('');
        }
    }

    function readFilterState() {
        filterState.azienda = fAzienda?.value || '';
        filterState.tipo = fTipo?.value || '';
        filterState.persona = (fPersona?.value || '').trim();
        filterState.da = fDa?.value || '';
        filterState.a = fA?.value || '';
        filterState.entro = fEntro?.value || '';
    }

    async function applyFiltersAndRerender() {
        readFilterState();
        await renderRows();
    }

    // stato “personale” nel modale
    const persone = [];

    // hook modal UI
    bindModalUI();

    // boot dropdowns
    await Promise.all([populateAziendeSelect(), populateTipiSelect()]);

    // primo render
    await renderRows();

    /* =================== RENDER TABELLA (solo documenti esistenti) =================== */
    /* =================== RENDER TABELLA (solo documenti esistenti) =================== */
    async function renderRows() {
        tbody.innerHTML = `<tr><td colspan="7">Caricamento…</td></tr>`;

        try {
            const org = await apiGetOrganigrammaImprese(tabella);
            const aziendaIds = Array.from(new Set(flattenAziende(org)));
            if (!aziendaIds.length) {
                tbody.innerHTML = `<tr><td colspan="7">Nessuna impresa presente nell’organigramma della commessa.</td></tr>`;
                afterRender();
                return;
            }

            // carica dettagli per ogni azienda e raccogli SOLO docs “has:true”
            const rows = [];
            const maxParallel = 6;
            for (let i = 0; i < aziendaIds.length; i += maxParallel) {
                const chunk = aziendaIds.slice(i, i + maxParallel);
                const batch = await Promise.all(chunk.map(id => apiImpresaDettagli(tabella, id).catch(() => null)));
                batch.forEach((det, idx) => {
                    const azId = chunk[idx];
                    if (!det || det.success !== true) return;
                    const aziendaLabel = det.impresa?.label || `Impresa #${azId}`;
                    const docs = Array.isArray(det.docs) ? det.docs : [];
                    docs
                        .filter(d => d && d.has)
                        .forEach(d => {
                            const fileUrl = d.file_url || '';
                            const personeList = Array.isArray(d.personale) ? d.personale.filter(Boolean) : [];
                            rows.push({
                                aziendaId: azId,
                                aziendaLabel,
                                personale: personeList.length ? personeList.join(', ') : '—',
                                fileUrl,
                                fileName: fileUrl ? basename(fileUrl) : '—',
                                tipo: String(d.codice || '').toUpperCase(),
                                scadenza: d.scadenza || null,
                                stato: '—'
                            });
                        });
                });
            }

            // Popola i menu (solo la prima volta)
            const setAziende = new Set(rows.map(r => r.aziendaLabel).filter(Boolean));
            const setTipi = new Set(rows.map(r => r.tipo).filter(Boolean));
            populateFilterOptions({ aziende: setAziende, tipi: setTipi });

            // Applica filtri client-side usando i controlli già creati sopra
            readFilterState(); // aggiorna filterState da f-azienda, f-tipo, f-persona, f-da, f-a, f-entro
            const now = new Date();

            const filtered = rows.filter(r => {
                // Azienda
                if (filterState.azienda && r.aziendaLabel !== filterState.azienda) return false;

                // Tipo
                if (filterState.tipo && r.tipo !== filterState.tipo) return false;

                // Personale: "contains" case-insensitive
                if (filterState.persona) {
                    const hay = (r.personale || '').toLowerCase();
                    if (!hay.includes(filterState.persona.toLowerCase())) return false;
                }

                // Scadenza intervallo
                if (filterState.da || filterState.a) {
                    const d = parseISODate(r.scadenza);
                    if (!d) return false;
                    if (filterState.da) {
                        const da = parseISODate(filterState.da);
                        if (da && d < da) return false;
                    }
                    if (filterState.a) {
                        const a = parseISODate(filterState.a);
                        if (a && d > a) return false;
                    }
                }

                // Entro N giorni
                if (filterState.entro) {
                    const days = parseInt(filterState.entro, 10);
                    if (Number.isFinite(days)) {
                        const d = parseISODate(r.scadenza);
                        if (!d) return false;
                        const diff = Math.ceil((d - now) / (1000 * 60 * 60 * 24));
                        if (diff < 0 || diff > days) return false;
                    }
                }

                return true;
            });

            // ordina e calcola progressivo DSC (sull’elenco filtrato!)
            filtered.sort((a, b) => {
                const az = a.aziendaLabel.localeCompare(b.aziendaLabel);
                if (az !== 0) return az;
                return a.tipo.localeCompare(b.tipo);
            });

            let prog = 1;
            const html = filtered.map(m => {
                const progressivo = String(prog++).padStart(3, '0');
                const codiceDSC = `${codiceCommessa}_DSC_${progressivo}`;
                return `
            <tr data-az="${esc(m.aziendaId)}" data-tipo="${esc(m.tipo)}">
                <td class="azioni-colonna">
                    ${m.fileUrl ? `
                        <button class="action-icon" data-action="view" data-url="${escAttr(m.fileUrl)}" data-tooltip="Visualizza">
                            <img src="assets/icons/show.png" alt="Visualizza">
                        </button>` : ''}
                    <button class="action-icon" data-action="delete" data-az="${escAttr(m.aziendaId)}" data-tipo="${escAttr(m.tipo)}" data-tooltip="Elimina">
                        <img src="assets/icons/delete.png" alt="Elimina">
                    </button>
                </td>
                <td class="col-codice">${esc(codiceDSC)}</td>
                <td class="col-azienda">${esc(m.aziendaLabel)}</td>
                <td class="col-personale">${esc(m.personale)}</td>
                <td class="col-file">${m.fileUrl ? `<a href="${escAttr(m.fileUrl)}" target="_blank" rel="noopener">${esc(m.fileName)}</a>` : '—'}</td>
                <td class="col-tipo">${esc(m.tipo)}</td>
                <td class="col-stato">${esc(m.stato)}</td>
            </tr>`;
            });

            tbody.innerHTML = html.length ? html.join('') :
                `<tr><td colspan="7">Nessun documento caricato per le imprese dell’organigramma.</td></tr>`;

            // NB: non bindiamo qui gli handler; li hai già in afterRender() con delega + guard.
        } catch (err) {
            console.error('[sic_elenco_doc] render error:', err);
            tbody.innerHTML = `<tr><td colspan="7">Errore durante il caricamento.</td></tr>`;
        }

        afterRender();
    }

    function afterRender() {
        if (typeof initTableFilters === 'function') initTableFilters('docs-table');
        if (typeof updateButtons === 'function') updateButtons();
        // Bind azioni della colonna (delegato, una sola volta)
        if (!tbody.__sicActionsBound) {
            tbody.addEventListener('click', (ev) => {
                const btn = ev.target.closest('button.action-icon');
                if (!btn) return;

                const action = btn.dataset.action;

                if (action === 'view') {
                    const url = btn.dataset.url;
                    if (url) {
                        window.open(url, '_blank');
                    } else {
                        showToast('Nessun file disponibile', 'warning');
                    }
                    return;
                }

                if (action === 'delete') {
                    const az = Number(btn.dataset.az || 0);
                    const tipo = String(btn.dataset.tipo || '').toUpperCase();
                    if (!az || !tipo) return;

                    const doDelete = async () => {
                        try {
                            const resp = await callCommesse('deleteDocumentoSicurezza', {
                                tabella,
                                azienda_id: az,
                                tipo
                            });
                            if (resp && resp.success) {
                                showToast('Documento eliminato', 'success');
                                await renderRows();
                            } else {
                                showToast('Errore eliminazione: ' + (resp?.message || 'operazione fallita'), 'error');
                            }
                        } catch (e) {
                            showToast('Errore di rete', 'error');
                        }
                    };

                    // usa showConfirm se esiste, altrimenti conferma nativa
                    if (typeof window.showConfirm === 'function') {
                        window.showConfirm('Vuoi eliminare questo documento?', doDelete);
                    } else if (confirm('Vuoi eliminare questo documento?')) {
                        doDelete();
                    }
                }
            });
            tbody.__sicActionsBound = true;
        }
    }

    /* =================== MODALE =================== */
    function bindModalUI() {
        const modalId = 'modal-sic-doc';
        const modalEl = document.getElementById(modalId);
        if (!modalEl) return;

        const form = document.getElementById('form-sic-doc');
        if (!form) return;

        // Guardiano: chiunque provi ad aggiungere "show", la togliamo immediatamente
        const classGuard = new MutationObserver(() => {
            if (modalEl.classList.contains('show')) {
                modalEl.classList.remove('show');
                // non bloccare altre classi: togli solo questa
            }
        });
        classGuard.observe(modalEl, { attributes: true, attributeFilter: ['class'] });

        // Stato iniziale pulito
        modalEl.classList.remove('show');

        const openModal = () => {
            modalEl.classList.remove('show');        // MAI usare show per aprire
            window.toggleModal?.(modalId, 'open');   // il core gestisce display
        };

        const closeModal = () => {
            window.toggleModal?.(modalId, 'close');  // chiusura standard
            modalEl.classList.remove('show');        // kill residui
            modalEl.style.removeProperty('display'); // igiene
            modalEl.style.display = 'none';          // anti CSS aggressivi
        };

        // Espone apertura pubblica (toolbar)
        window.openSicDocModal = openModal;

        // X “di sito” (se manca, la creo)
        if (!modalEl.querySelector('.close')) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'close';
            btn.setAttribute('aria-label', 'Chiudi');
            btn.setAttribute('data-tooltip', 'Chiudi');
            btn.innerHTML = '&times;';
            const mc = modalEl.querySelector('.modal-content');
            if (mc) mc.insertBefore(btn, mc.firstChild);
            btn.addEventListener('click', () => closeModal());
        }

        // === Tipi per persona (multi) ===
        const tipiPerPersona = new Set(['CRD', 'VRS', 'VRR']); // modifica liberamente

        function isTipoPerPersona(cod) {
            return tipiPerPersona.has(String(cod || '').toUpperCase());
        }

        function toggleSezionePersonale() {
            const tipoSel = (document.getElementById('sic-tipo-select')?.value || '').trim();
            const box = document.getElementById('sic-pers-list')?.closest('.form-group');
            if (!box) return;
            const on = isTipoPerPersona(tipoSel);
            box.style.display = on ? '' : 'none';

            // se non per-persona → pulisco eventuali chip
            if (!on) {
                persone.splice(0, persone.length);
                const inp = document.getElementById('sic-pers-input');
                if (inp) inp.value = '';
                const list = document.getElementById('sic-pers-list');
                if (list) list.innerHTML = '';
            }
        }

        // evento sul select tipo
        document.getElementById('sic-tipo-select')?.addEventListener('change', toggleSezionePersonale);

        // stato iniziale coerente
        toggleSezionePersonale();

        // --- Upload ---
        const btnUpload = document.getElementById('sic-upload-btn');
        if (btnUpload) {
            btnUpload.addEventListener('click', async () => {
                try {
                    btnUpload.disabled = true;

                    const az = (document.getElementById('sic-az-select')?.value || '').trim();
                    const tipo = (document.getElementById('sic-tipo-select')?.value || '').trim();
                    const file = document.getElementById('sic-file')?.files?.[0];
                    const scadenza = (document.getElementById('sic-scadenza')?.value || '').trim();

                    if (!az || !tipo || !file) {
                        showToast('Compila i campi obbligatori (Azienda, Tipo, File).', 'error');
                        return;
                    }
                    if (isTipoPerPersona(tipo) && (!persone || persone.length === 0)) {
                        showToast('Aggiungi almeno una persona per questo tipo documento.', 'error');
                        return;
                    }

                    await uploadDocumento({
                        tabella,
                        azienda_id: Number(az),
                        tipo: tipo.toUpperCase(),
                        scadenza: scadenza || null,
                        persone: [...persone],
                        file
                    });

                    showToast('Documento caricato.', 'success');

                    // reset form + chip
                    form.reset();
                    persone.splice(0, persone.length);
                    renderPersoneChips?.();

                    // chiudi certa + refresh tabella
                    closeModal();
                    await renderRows();
                } catch (e) {
                    console.error(e);
                    showToast('Errore durante l’upload.', 'error');
                } finally {
                    btnUpload.disabled = false;
                }
            });
        }

        // --- Gestione chip persone ---
        const addBtn = document.getElementById('sic-pers-add');
        const inPers = document.getElementById('sic-pers-input');
        addBtn?.addEventListener('click', () => addPersona());
        inPers?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); addPersona(); }
        });

        function addPersona() {
            const inp = document.getElementById('sic-pers-input');
            const v = (inp?.value || '').trim();
            if (!v) return;
            persone.push(v);
            if (inp) inp.value = '';
            renderPersoneChips();
        }

        function renderPersoneChips() {
            const list = document.getElementById('sic-pers-list');
            if (!list) return;
            list.innerHTML = persone.map((p, i) =>
                `<span class="chip">${esc(p)}<button type="button" class="chip-x" data-i="${i}" aria-label="Rimuovi">×</button></span>`
            ).join('');
            list.querySelectorAll('.chip-x').forEach(btn => {
                btn.addEventListener('click', () => {
                    const i = Number(btn.dataset.i);
                    if (!Number.isNaN(i)) { persone.splice(i, 1); renderPersoneChips(); }
                });
            });
        }
    }

    /* =================== POPOLAMENTO SELECT =================== */
    async function populateAziendeSelect() {
        const sel = document.getElementById('sic-az-select');
        if (!sel) return;

        const org = await apiGetOrganigrammaImprese(tabella);
        const ids = Array.from(new Set(flattenAziende(org)));
        if (!ids.length) return;

        // prendo l’elenco anagrafiche e filtro solo quelle dell’org
        const all = await apiGetImpreseList(''); // {id,label,piva}
        const usable = all.filter(x => ids.includes(Number(x.id)));

        sel.innerHTML = `<option value="">— Seleziona azienda —</option>` +
            usable.map(x => `<option value="${escAttr(x.id)}">${esc(x.label)}${x.piva ? ' • ' + esc(x.piva) : ''}</option>`).join('');
    }

    async function populateTipiSelect() {
        const sel = document.getElementById('sic-tipo-select');
        if (!sel) return;

        const tipi = await apiListDocTypes(); // [{codice, nome}]
        sel.innerHTML = `<option value="">— Seleziona tipo —</option>` +
            tipi.map(t => `<option value="${escAttr(t.codice)}">${esc(t.codice)} — ${esc(t.nome)}</option>`).join('');
    }

    /* =================== UPLOAD =================== */
    async function uploadDocumento({ tabella, azienda_id, tipo, scadenza, persone, file }) {
        const meta = document.querySelector('meta[name="token-csrf"]');
        const csrf = meta?.content || '';

        const fd = new FormData();
        fd.append('tabella', tabella);
        fd.append('azienda_id', String(azienda_id));
        fd.append('tipo', tipo);
        if (scadenza) fd.append('scadenza', scadenza);
        (persone || []).forEach(p => fd.append('personale[]', p));
        fd.append('file', file);
        if (csrf) fd.append('csrf_token', csrf);

        const json = await customFetch('commesse', 'uploadDocumentoSicurezza', fd);
        if (!json || json.success !== true) {
            const msg = (json && (json.message || json.error)) || 'Upload fallito';
            throw new Error(String(msg));
        }
        return json;
    }

    /* =================== API helpers =================== */
    async function callCommesse(action, payload) {
        // usa customFetch se disponibile (coerente con il resto del progetto)
        if (typeof customFetch === 'function') return await customFetch('commesse', action, payload || {});
        const res = await fetch('/ajax/commesse.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ section: 'commesse', action, ...(payload || {}) })
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return await res.json();
    }

    async function apiGetOrganigrammaImprese(tabella) {
        const json = await callCommesse('getOrganigrammaImprese', { tabella });
        if (!json || json.success !== true || typeof json.data !== 'object') {
            if (json && json.azienda_id !== undefined) return json; // legacy
            return { azienda_id: null, children: [] };
        }
        return json.data;
    }

    async function apiGetImpreseList(q) {
        const json = await callCommesse('getImpreseAnagrafiche', { q: q || '' });
        return (json && json.success && Array.isArray(json.items)) ? json.items : [];
    }

    async function apiListDocTypes() {
        const json = await callCommesse('listSettings', { type: 'sic_docs' });
        if (!json || json.success !== true || !Array.isArray(json.rows)) {
            throw new Error(json?.error || 'Errore lettura tipi documento');
        }
        return json.rows.map(r => ({
            codice: String(r.codice || ''),
            nome: String(r.nome || '')
        }));
    }

    async function apiImpresaDettagli(tab, azienda_id) {
        return await callCommesse('getImpresaDettagli', { tabella: tab, azienda_id });
    }

    /* =================== UTIL =================== */
    function flattenAziende(node) {
        const out = [];
        (function visit(n) {
            if (!n || typeof n !== 'object') return;
            if (n.azienda_id) out.push(Number(n.azienda_id));
            if (Array.isArray(n.children)) n.children.forEach(visit);
        })(node);
        return out.filter(x => Number.isFinite(x) && x > 0);
    }

    function basename(url) {
        try {
            const clean = url.split('#')[0].split('?')[0];
            const p = clean.split('/');
            return p[p.length - 1] || url;
        } catch { return url; }
    }

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[m]);
    }
    function escAttr(s) { return String(s ?? '').replace(/"/g, '&quot;'); }

    function showToast(msg, type) {
        if (typeof window.showToast === 'function') return window.showToast(msg, type);
        alert(msg);
    }

}
