/**
 * Elenco Documenti - JavaScript Module
 * Document list management for Commesse
 */

const ElencoDoc = (function() {
    'use strict';

    // ================================
    // STATE
    // ================================
    let idProject = null;
    let sections = [];
    let submittals = [];
    let lookups = {
        fase: [],
        zona: [],
        disc: [],
        tipo: [],
        resp: [],
        output: []
    };
    let propDocId = null;
    let activePopup = null;
    let subSel = new Set();
    let editSubId = null;
    let currentLtrSubId = null;
    let docIdCounter = 1000;
    let _ncBrowserFiles = [];

    // Status configuration
    const STATUS_CFG = {
        'PIANIFICATO': { cls: 'st-planned', dot: '#0369a1', icon: '○' },
        'IN CORSO': { cls: 'st-wip', dot: '#b45309', icon: '◑' },
        'EMESSO': { cls: 'st-issued', dot: '#15803d', icon: '●' },
        'IN REVISIONE': { cls: 'st-revision', dot: '#b91c1c', icon: '↺' }
    };

    const REV_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    // ================================
    // UTILITIES
    // ================================
    function genId() {
        return 'd' + Date.now() + '_' + (docIdCounter++);
    }

    // Returns true for temp (client-side) IDs like 'd1709...' or 's1709...'
    function isTempId(id) {
        return id == null || (typeof id === 'string' && /^[ds]/.test(id));
    }

    function allDocs() {
        return sections.flatMap(s => s.docs || []);
    }

    // Use loose equality to handle string/number ID coercion from HTML templates
    function findDoc(id) {
        // eslint-disable-next-line eqeqeq
        return allDocs().find(d => d.id == id);
    }

    function findSection(id) {
        // eslint-disable-next-line eqeqeq
        return sections.find(s => s.id == id);
    }

    function docSection(docId) {
        // eslint-disable-next-line eqeqeq
        return sections.find(s => (s.docs || []).some(d => d.id == docId));
    }

    function fmtNum(n) {
        return String(n).padStart(4, '0');
    }

    function isoToDisp(iso) {
        if (!iso || iso === '—') return '—';
        try {
            const d = new Date(iso);
            return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: '2-digit' });
        } catch (e) {
            return iso;
        }
    }

    function pc(v) {
        return v === 0 ? 'c0' : v < 40 ? 'clo' : v < 80 ? 'cmi' : 'chi';
    }

    function codeStr(doc) {
        const projCode = document.getElementById('projectBadge')?.textContent || 'PRJ';
        return `${projCode}-${doc.fase}-${doc.zona}-${doc.disc}-${doc.tipo}-${fmtNum(doc.num)}-${doc.rev}`;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function respDisplay(code) {
        if (!code) return '—';
        const entry = (lookups.resp || []).find(r => r.c === String(code));
        return entry ? escapeHtml(entry.d) : escapeHtml(String(code));
    }

    // ================================
    // REVISION LOGIC
    // ================================
    function parseRev(r) {
        if (!r || r === '—') return { type: 'letter', val: 0 };
        const m = r.match(/^R([A-Z])$/i);
        if (m) return { type: 'letter', val: REV_LETTERS.indexOf(m[1].toUpperCase()) };
        const n = r.match(/^R(\d+)$/);
        if (n) return { type: 'number', val: parseInt(n[1]) };
        return { type: 'letter', val: 0 };
    }

    function revForStatus(currentRev, newStatus) {
        const p = parseRev(currentRev);
        if (newStatus === 'PIANIFICATO' || newStatus === 'IN CORSO') {
            if (p.type === 'number') {
                return 'R' + REV_LETTERS[p.val + 1];
            }
            return currentRev || 'RA';
        }
        if (newStatus === 'EMESSO') {
            if (p.type === 'letter') return 'R' + p.val;
            return 'R' + (p.val + 1);
        }
        if (newStatus === 'IN REVISIONE') {
            if (p.type === 'number') return 'R' + REV_LETTERS[p.val + 1];
            if (p.type === 'letter') return 'R' + REV_LETTERS[Math.min(p.val + 1, REV_LETTERS.length - 1)];
        }
        return currentRev || 'RA';
    }

    function nextRevForDup(currentRev) {
        return revForStatus(currentRev, 'IN REVISIONE');
    }

    // ================================
    // DATA NORMALIZATION (backend → JS state)
    // ================================

    // Convert backend doc fields (snake_case) → JS fields (camelCase short)
    function normalizeDoc(d) {
        return {
            id: parseInt(d.id),
            idSection: parseInt(d.id_section),
            fase: d.seg_fase || '',
            zona: d.seg_zona || '',
            disc: d.seg_disc || '',
            tipo: d.seg_tipo || '',
            num: d.seg_numero || 1,
            title: d.titolo || '',
            sub: d.tipo_documento || '',
            resp: d.responsabile || '',
            output: d.output_software || '',
            prog: d.avanzamento_pct || 0,
            dateStart: d.data_inizio || '',
            dateEnd: d.data_fine_prev || '',
            dateEmission: d.data_emissione || '',
            rev: d.revisione || 'RA',
            status: d.stato || 'PIANIFICATO',
            files: d.nc_files || [],
            notes: d.note || '',
            idSubmittal: d.id_submittal || null
        };
    }

    // Convert backend lookups format → JS lookups format
    function normalizeLookups(l) {
        return {
            fase: (l.fasi || []).map(f => ({ c: f, d: f })),
            zona: (l.zone || []).map(z => ({ c: z, d: z })),
            disc: (l.discipline || []).map(d => ({ c: d, d: d })),
            tipo: (l.tipi_documento || []).map(t => ({ c: t.cod, d: t.desc || t.cod })),
            resp: lookups.resp || [],   // loaded separately via getRisorse
            output: ['Word', 'Revit', 'AutoCAD', 'Excel', 'PDF', 'InDesign', 'Primus'].map(s => ({ c: s, d: s }))
        };
    }

    // Convert JS doc → backend payload for saveDocumento
    function denormalizeDoc(doc, secId) {
        return {
            idProject,
            docId: !isTempId(doc.id) ? doc.id : null,
            idSection: secId || doc.idSection,
            segFase: doc.fase,
            segZona: doc.zona,
            segDisc: doc.disc,
            segTipo: doc.tipo,
            segNumero: doc.num,
            titolo: doc.title,
            tipoDocumento: doc.sub,
            responsabile: doc.resp,
            outputSoftware: doc.output,
            avanzamentoPct: doc.prog || 0,
            stato: doc.status,
            revisione: doc.rev,
            dataInizio: doc.dateStart || null,
            dataFinePrev: doc.dateEnd || null,
            dataEmissione: doc.dateEmission || null,
            note: doc.notes || ''
        };
    }

    // ================================
    // AJAX
    // ================================
    async function sendRequest(action, params = {}) {
        const csrfToken = document.querySelector('meta[name="token-csrf"]')?.content || '';
        try {
            const response = await fetch('/ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Csrf-Token': csrfToken
                },
                body: JSON.stringify({
                    section: 'elenco_documenti',
                    action: action,
                    ...params
                })
            });
            if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('Request error:', error);
            return { success: false, message: error.message };
        }
    }

    // ================================
    // DATA LOADING
    // ================================
    async function loadCommessaData() {
        const result = await sendRequest('getCommessaData', { idProject });
        if (result.success && result.data) {
            document.getElementById('projectBadge').textContent = result.data.codice || 'N/A';
        }
    }

    async function loadRisorse() {
        const result = await sendRequest('getRisorse', {});
        if (result.success && result.data) {
            lookups.resp = result.data.map(r => ({
                c: String(r.user_id),
                d: r.Nominativo
            }));
            populateFilterDropdowns();
        }
    }

    async function loadDocumenti() {
        const result = await sendRequest('getDocumenti', { idProject });
        if (result.success && result.data) {
            // Normalize backend sections → JS state
            sections = (result.data.sections || []).map(item => ({
                id: parseInt(item.section.id),
                name: item.section.nome,
                rangeFrom: item.section.range_num_da,
                rangeTo: item.section.range_num_a,
                docs: (item.docs || []).map(normalizeDoc)
            }));
            // Normalize lookups
            const prevResp = lookups.resp;
            lookups = normalizeLookups(result.data.lookups || {});
            lookups.resp = prevResp; // keep already-loaded risorse
            populateFilterDropdowns();
            renderSections();
            updateStats();
        } else {
            document.getElementById('seccont').innerHTML =
                '<div class="ed-empty-state">Nessun documento trovato</div>';
        }
    }

    // Normalize backend submittal fields → JS state
    function normalizeSubmittal(s) {
        return {
            id: s.id,
            code: s.codice || '',
            segTipo: s.seg_tipo || 'TR',
            segLettera: s.seg_lettera || 'A',
            oggetto: s.oggetto || '',
            dest: s.destinatario || '',
            cc: s.cc || '',
            scopo: s.scopo || 'email',
            dataConsegna: s.data_consegna || '',
            date: s.data_consegna || '',          // alias for rendering
            status: s.stato || 'Pianificato',
            note: s.note || '',
            docIds: s.docIds || [],
            docCount: s.doc_count || 0
        };
    }

    async function loadSubmittals() {
        const result = await sendRequest('getSubmittals', { idProject });
        if (result.success && result.data) {
            submittals = (result.data || []).map(normalizeSubmittal);
            document.getElementById('submittalCount').textContent = submittals.length;
            document.getElementById('smgrTot').textContent = submittals.length;
        }
    }

    function populateFilterDropdowns() {
        const discSelect = document.getElementById('filter-disc');
        if (discSelect && lookups.disc?.length) {
            discSelect.innerHTML = '<option value="">Tutte</option>';
            lookups.disc.forEach(d => {
                discSelect.innerHTML += `<option value="${d.c}">${d.c} — ${d.d}</option>`;
            });
        }

        const respSelect = document.getElementById('filter-resp');
        if (respSelect && lookups.resp?.length) {
            respSelect.innerHTML = '<option value="">Tutti</option>';
            lookups.resp.forEach(r => {
                respSelect.innerHTML += `<option value="${r.c}">${escapeHtml(r.d)}</option>`;
            });
        }

        const destSelect = document.getElementById('sub-dest');
        if (destSelect && lookups.resp?.length) {
            destSelect.innerHTML = '<option value="">— Seleziona —</option>';
            lookups.resp.forEach(r => {
                destSelect.innerHTML += `<option value="${r.c}">${escapeHtml(r.d)}</option>`;
            });
        }
    }

    // ================================
    // RENDERING
    // ================================
    function renderSections() {
        const cont = document.getElementById('seccont');
        if (!cont) return;
        cont.innerHTML = '';

        if (sections.length === 0) {
            cont.innerHTML = '<div class="ed-empty-state">Nessuna sezione. Clicca "Nuova Sezione" per iniziare.</div>';
            return;
        }

        const fStato = document.getElementById('filter-stato')?.value || '';
        const fDisc = document.getElementById('filter-disc')?.value || '';
        const fResp = document.getElementById('filter-resp')?.value || '';
        const fText = (document.getElementById('filter-text')?.value || '').toLowerCase();

        sections.forEach(sec => {
            const filtDocs = (sec.docs || []).filter(d => {
                if (fStato && d.status !== fStato) return false;
                if (fDisc && d.disc !== fDisc) return false;
                if (fResp && d.resp !== fResp) return false;
                if (fText && !d.title.toLowerCase().includes(fText) && !codeStr(d).toLowerCase().includes(fText)) return false;
                return true;
            });

            if (filtDocs.length === 0 && (fStato || fDisc || fResp || fText)) return;

            const secDiv = document.createElement('div');
            secDiv.className = 'ed-section';
            secDiv.innerHTML = `
                <div class="ed-section-header" onclick="ElencoDoc.toggleSec('${sec.id}', this)">
                    <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                    <span class="ed-section-title">${escapeHtml(sec.name)}</span>
                    <span class="ed-section-badge" onclick="event.stopPropagation();ElencoDoc.openRangePicker(event,'${sec.id}')">${sec.rangeFrom || 0}–${sec.rangeTo || 999}</span>
                    <span class="ed-section-count">${filtDocs.length} documenti</span>
                    ${window.userHasPermission && window.userHasPermission('edit_commessa') ? `
                    <div class="ed-section-actions">
                        <button class="ed-section-btn" onclick="event.stopPropagation();ElencoDoc.renameSection('${sec.id}')" title="Rinomina">✏</button>
                        <button class="ed-section-btn" onclick="event.stopPropagation();ElencoDoc.deleteSectionConfirm('${sec.id}')" title="Elimina">🗑</button>
                    </div>
                    ` : ''}
                </div>
                <div class="ed-section-body" id="body-${sec.id}">
                    <table class="ed-table">
                        <thead>
                            <tr>
                                <th style="width:180px">Codice</th>
                                <th>Titolo</th>
                                <th style="width:100px">Stato</th>
                                <th style="width:60px">Rev</th>
                                <th style="width:80px">%</th>
                                <th style="width:80px">Emissione</th>
                                <th style="width:80px">Resp.</th>
                                <th style="width:60px"></th>
                            </tr>
                        </thead>
                        <tbody id="tb-${sec.id}">
                            ${filtDocs.map(d => buildRowHtml(d)).join('')}
                            ${window.userHasPermission && window.userHasPermission('edit_commessa') ? `
                            <tr class="ed-add-row">
                                <td colspan="8">
                                    <button class="ed-add-btn" onclick="ElencoDoc.addDocToSection('${sec.id}')">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12">
                                            <line x1="12" y1="5" x2="12" y2="19"/>
                                            <line x1="5" y1="12" x2="19" y2="12"/>
                                        </svg>
                                        Aggiungi documento
                                    </button>
                                </td>
                            </tr>
                            ` : ''}
                        </tbody>
                    </table>
                </div>
            `;
            cont.appendChild(secDiv);
        });
    }

    function buildRowHtml(doc) {
        const cfg = STATUS_CFG[doc.status] || STATUS_CFG['PIANIFICATO'];
        const today = new Date();
        const emDate = doc.dateEmission ? new Date(doc.dateEmission) : null;
        const dateCls = !emDate ? 'empty' : (emDate < today ? 'late' : 'ok');
        const canEdit = window.userHasPermission && window.userHasPermission('edit_commessa');

        return `
            <tr data-id="${doc.id}">
                <td class="ed-code-cell" id="codetd-${doc.id}" title="${codeStr(doc)}">${codeStr(doc)}</td>
                <td class="ed-title-cell" onclick="ElencoDoc.openProps('${doc.id}')" style="cursor:pointer">
                    <div class="ed-doc-title">${escapeHtml(doc.title)}</div>
                    <div class="ed-doc-sub">${escapeHtml(doc.sub || '')}</div>
                </td>
                <td class="ed-status-cell">
                    <span class="ed-status-badge ${cfg.cls}" onclick="ElencoDoc.openStatusPop(event, this, '${doc.id}')">
                        <span class="ed-status-dot" style="background:${cfg.dot}"></span>
                        ${doc.status}
                    </span>
                </td>
                <td><span class="ed-rev-badge">${doc.rev || '—'}</span></td>
                <td class="ed-progress-cell" onclick="ElencoDoc.openProg(event, this, '${doc.id}')">
                    <div class="ed-progress-bar">
                        <div class="ed-progress-fill ${pc(doc.prog || 0)}" id="pf-${doc.id}" style="width:${doc.prog || 0}%"></div>
                    </div>
                    <div class="ed-progress-label" id="pl-${doc.id}">${doc.prog || 0}%</div>
                </td>
                <td class="ed-date-cell" onclick="ElencoDoc.openDatePop(event, this, '${doc.id}', 'dateEmission')">
                    <span class="ed-date-display ${dateCls}" id="dateEmissiond-${doc.id}">${isoToDisp(doc.dateEmission)}</span>
                </td>
                <td class="ed-resp-cell">${respDisplay(doc.resp)}</td>
                <td class="ed-actions-cell">
                    ${canEdit && doc.status === 'EMESSO' ? `
                    <button class="ed-action-btn dup" onclick="ElencoDoc.dupRevision('${doc.id}')" title="Nuova revisione">↻</button>
                    ` : ''}
                    ${canEdit ? `
                    <button class="ed-action-btn danger" onclick="ElencoDoc.deleteDoc('${doc.id}')" title="Elimina">×</button>
                    ` : ''}
                </td>
            </tr>
        `;
    }

    function updateStats() {
        const docs = allDocs();
        document.getElementById('tot-count').textContent = docs.length;
        const avg = docs.length ? Math.round(docs.reduce((a, d) => a + (d.prog || 0), 0) / docs.length) : 0;
        document.getElementById('avg-prog').textContent = avg + '%';
        const iss = docs.filter(d => d.status === 'EMESSO').length;
        document.getElementById('issued-count').textContent = iss;
    }

    // ================================
    // POPUP MANAGEMENT
    // ================================
    function closeAP() {
        if (activePopup) {
            activePopup.remove();
            activePopup = null;
        }
        document.querySelectorAll('.ed-status-badge.on').forEach(s => s.classList.remove('on'));
    }

    function openStatusPop(e, el, docId) {
        if (!window.userHasPermission || !window.userHasPermission('edit_commessa')) return;
        e.stopPropagation();
        closeAP();
        const doc = findDoc(docId);
        if (!doc) return;

        el.classList.add('on');
        el.style.position = 'relative';

        const dd = document.createElement('div');
        dd.className = 'ed-popup ed-status-popup';
        dd.style.cssText = 'left:0;top:100%;margin-top:4px';

        Object.keys(STATUS_CFG).forEach(k => {
            const cfg = STATUS_CFG[k];
            dd.innerHTML += `
                <div class="ed-status-option ${k === doc.status ? 'active' : ''}" data-status="${k}">
                    <span class="ed-status-dot" style="background:${cfg.dot}"></span>
                    ${k}
                </div>
            `;
        });

        el.appendChild(dd);
        activePopup = dd;

        dd.querySelectorAll('.ed-status-option').forEach(opt => {
            opt.addEventListener('click', async () => {
                const newStatus = opt.dataset.status;
                doc.rev = revForStatus(doc.rev, newStatus);
                doc.status = newStatus;
                reRenderRow(docId);
                closeAP();
                flashSave();
                updateStats();
                await saveOneDoc(doc, doc.idSection);
            });
        });

        dd.addEventListener('click', ev => ev.stopPropagation());
    }

    function openProg(e, cell, docId) {
        if (!window.userHasPermission || !window.userHasPermission('edit_commessa')) return;
        e.stopPropagation();
        closeAP();
        const doc = findDoc(docId);
        if (!doc) return;

        cell.style.position = 'relative';
        const pp = document.createElement('div');
        pp.className = 'ed-popup ed-prog-popup';
        pp.innerHTML = `
            <label>Avanzamento</label>
            <div class="pv" id="ppv-${docId}">${doc.prog || 0}%</div>
            <input type="range" min="0" max="100" step="5" value="${doc.prog || 0}" id="ppr-${docId}">
            <div class="ps"><span>0</span><span>25</span><span>50</span><span>75</span><span>100%</span></div>
        `;
        cell.appendChild(pp);
        activePopup = pp;

        const rng = pp.querySelector(`#ppr-${docId}`);
        const pvd = pp.querySelector(`#ppv-${docId}`);

        rng.addEventListener('input', () => {
            const v = parseInt(rng.value);
            pvd.textContent = v + '%';
            document.getElementById(`pf-${docId}`).style.width = v + '%';
            document.getElementById(`pf-${docId}`).className = `ed-progress-fill ${pc(v)}`;
            document.getElementById(`pl-${docId}`).textContent = v + '%';
            doc.prog = v;
            updateStats();
        });

        rng.addEventListener('change', async () => {
            closeAP();
            flashSave();
            await saveOneDoc(doc, doc.idSection);
        });

        pp.addEventListener('click', ev => ev.stopPropagation());
    }

    function openDatePop(e, cell, docId, field) {
        if (!window.userHasPermission || !window.userHasPermission('edit_commessa')) return;
        e.stopPropagation();
        closeAP();
        const doc = findDoc(docId);
        if (!doc) return;

        const labels = { dateStart: 'Data Inizio', dateEnd: 'Fine Prevista', dateEmission: 'Data Emissione' };
        cell.style.position = 'relative';

        const dp = document.createElement('div');
        dp.className = 'ed-popup ed-date-popup';
        dp.innerHTML = `<label>${labels[field] || field}</label><input type="date" value="${doc[field] || ''}" id="dp-${field}-${docId}">`;
        cell.appendChild(dp);
        activePopup = dp;

        const inp = dp.querySelector('input');
        inp.focus();

        inp.addEventListener('change', async () => {
            doc[field] = inp.value;
            const disp = document.getElementById(`${field}d-${docId}`);
            if (disp) {
                const today = new Date();
                const dv = inp.value ? new Date(inp.value) : null;
                const cls = !dv ? 'empty' : (dv < today && field !== 'dateStart' ? 'late' : 'ok');
                disp.textContent = isoToDisp(inp.value);
                disp.className = `ed-date-display ${cls}`;
            }
            closeAP();
            flashSave();
            await saveOneDoc(doc, doc.idSection);
        });

        dp.addEventListener('click', ev => ev.stopPropagation());
    }

    function openRangePicker(e, secId) {
        if (!window.userHasPermission || !window.userHasPermission('edit_commessa')) return;
        e.stopPropagation();
        closeAP();
        const sec = findSection(secId);
        if (!sec) return;

        const badge = e.currentTarget;
        badge.style.position = 'relative';

        const rp = document.createElement('div');
        rp.className = 'ed-popup';
        rp.style.cssText = 'width:200px';
        rp.innerHTML = `
            <h4 style="margin:0 0 12px;font-size:13px">Range numerazione</h4>
            <div style="display:flex;gap:8px;margin-bottom:12px">
                <div class="ed-form-group" style="flex:1"><label>Da</label><input type="number" class="ed-input" value="${sec.rangeFrom || 0}" id="rf-from-${secId}" min="0" max="9999"></div>
                <div class="ed-form-group" style="flex:1"><label>A</label><input type="number" class="ed-input" value="${sec.rangeTo || 999}" id="rf-to-${secId}" min="0" max="9999"></div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button class="btn btn-secondary" style="font-size:11px;padding:4px 10px" onclick="ElencoDoc.closeAP()">Annulla</button>
                <button class="btn btn-primary" style="font-size:11px;padding:4px 10px" onclick="ElencoDoc.saveRange('${secId}')">Applica</button>
            </div>
        `;
        badge.appendChild(rp);
        activePopup = rp;
        rp.addEventListener('click', ev => ev.stopPropagation());
    }

    async function saveRange(secId) {
        const sec = findSection(secId);
        if (!sec) return;
        const f = parseInt(document.getElementById(`rf-from-${secId}`).value);
        const t = parseInt(document.getElementById(`rf-to-${secId}`).value);
        if (!isNaN(f) && !isNaN(t) && f < t) {
            sec.rangeFrom = f;
            sec.rangeTo = t;

            // Persist range if section is in DB
            if (!isTempId(sec.id)) {
                await sendRequest('saveSection', {
                    idProject,
                    sectionId: sec.id,
                    nome: sec.name,
                    ordine: sections.indexOf(sec),
                    rangeNumDa: f,
                    rangeNumA: t
                });
            }
        }
        closeAP();
        renderSections();
        flashSave();
    }

    // ================================
    // DOCUMENT OPERATIONS
    // ================================
    async function addDocToSection(secId) {
        const sec = findSection(secId);
        if (!sec) return;

        // Ensure section exists in DB before adding a document
        if (isTempId(sec.id)) {
            const res = await sendRequest('saveSection', {
                idProject,
                nome: sec.name,
                ordine: sections.indexOf(sec),
                rangeNumDa: sec.rangeFrom,
                rangeNumA: sec.rangeTo
            });
            if (res.success && res.sectionId) {
                sec.id = res.sectionId; // already int from PHP
            } else {
                alert(res.message || 'Errore nel salvataggio della sezione');
                return;
            }
        }

        sec.docs = sec.docs || [];

        const lastDoc = sec.docs.length ? sec.docs[sec.docs.length - 1] : null;
        let nextNum = sec.rangeFrom || 1;
        if (lastDoc) {
            nextNum = lastDoc.num + 1;
            if (nextNum > (sec.rangeTo || 999)) nextNum = sec.rangeTo || 999;
        }

        const newDoc = {
            id: genId(),
            idSection: sec.id,
            fase: lastDoc?.fase || (lookups.fase[0]?.c || 'PD'),
            zona: lastDoc?.zona || (lookups.zona[0]?.c || '00'),
            disc: lastDoc?.disc || (lookups.disc[0]?.c || 'GE'),
            tipo: lastDoc?.tipo || (lookups.tipo[0]?.c || 'RT'),
            num: nextNum,
            title: 'NUOVO DOCUMENTO',
            sub: lastDoc?.sub || '',
            resp: lastDoc?.resp || '',
            output: lastDoc?.output || '',
            prog: 0,
            dateStart: lastDoc?.dateStart || '',
            dateEnd: lastDoc?.dateEnd || '',
            dateEmission: lastDoc?.dateEmission || '',
            rev: 'RA',
            status: 'PIANIFICATO',
            files: [],
            notes: ''
        };

        sec.docs.push(newDoc);
        renderSections();
        updateStats();
        flashSave();

        setTimeout(() => openProps(newDoc.id), 150);
    }

    async function dupRevision(docId) {
        const doc = findDoc(docId);
        if (!doc || doc.status !== 'EMESSO') return;

        // If doc has a real DB id, use backend revision logic
        if (!isTempId(doc.id)) {
            const result = await sendRequest('createRevision', { docId: doc.id });
            if (result.success) {
                await loadDocumenti(); // reload to get the new doc from DB
                flashSave();
            } else {
                alert(result.message || 'Errore nella creazione revisione');
            }
            return;
        }

        // Fallback for temp docs (not yet in DB)
        const sec = docSection(docId);
        if (!sec) return;

        const newRev = nextRevForDup(doc.rev);
        const newDoc = {
            ...JSON.parse(JSON.stringify(doc)),
            id: genId(),
            rev: newRev,
            prog: 0,
            status: 'IN REVISIONE',
            files: []
        };

        const idx = sec.docs.indexOf(doc);
        sec.docs.splice(idx + 1, 0, newDoc);

        renderSections();
        updateStats();
        flashSave();
    }

    async function deleteDoc(docId) {
        if (!confirm('Eliminare questo documento?')) return;

        // If the doc has a real DB id, delete from DB
        const doc = findDoc(docId);
        if (doc && !isTempId(doc.id)) {
            const result = await sendRequest('deleteDocumento', { docId: doc.id });
            if (!result.success) {
                alert(result.message || 'Errore durante l\'eliminazione');
                return;
            }
        }

        for (const sec of sections) {
            // eslint-disable-next-line eqeqeq
            const i = (sec.docs || []).findIndex(d => d.id == docId);
            if (i >= 0) {
                sec.docs.splice(i, 1);
                break;
            }
        }

        renderSections();
        updateStats();
        flashSave();
    }

    async function deleteSectionConfirm(secId) {
        const sec = findSection(secId);
        if (!sec) return;
        if (!confirm(`Eliminare la sezione "${sec.name}" con ${(sec.docs || []).length} documenti?`)) return;

        // If the section has a real DB id, delete from DB
        if (!isTempId(sec.id)) {
            const result = await sendRequest('deleteSection', { sectionId: sec.id });
            if (!result.success) {
                alert(result.message || 'Impossibile eliminare la sezione. Verificare che non contenga documenti.');
                return;
            }
        }

        sections.splice(sections.indexOf(sec), 1);
        renderSections();
        flashSave();
    }

    async function renameSection(secId) {
        const sec = findSection(secId);
        if (!sec) return;
        const n = prompt('Nuovo nome sezione:', sec.name);
        if (n && n.trim()) {
            sec.name = n.trim().toUpperCase();

            // Persist if section is in DB
            if (!isTempId(sec.id)) {
                await sendRequest('saveSection', {
                    idProject,
                    sectionId: sec.id,
                    nome: sec.name,
                    ordine: sections.indexOf(sec),
                    rangeNumDa: sec.rangeFrom,
                    rangeNumA: sec.rangeTo
                });
            }

            renderSections();
            flashSave();
        }
    }

    function reRenderRow(docId) {
        const doc = findDoc(docId);
        if (!doc) return;
        const row = document.querySelector(`tr[data-id="${docId}"]`);
        if (!row) return;
        row.outerHTML = buildRowHtml(doc);
    }

    function toggleSec(id, hd) {
        const body = document.getElementById('body-' + id);
        const chev = hd.querySelector('.chev');
        const hidden = body.style.display === 'none';
        body.style.display = hidden ? '' : 'none';
        if (chev) chev.classList.toggle('col', !hidden);
        hd.classList.toggle('col', !hidden);
    }

    // ================================
    // PROPERTIES PANEL
    // ================================
    function openProps(docId) {
        const doc = findDoc(docId);
        if (!doc) return;
        propDocId = docId;

        document.getElementById('pp-code-disp').textContent = codeStr(doc);
        document.getElementById('pp-title-disp').textContent = doc.title;

        const btnRev = document.getElementById('btn-revisione');
        if (btnRev) btnRev.style.display = doc.status === 'EMESSO' ? 'inline-flex' : 'none';

        closeRevDialog();

        const canEdit = window.userHasPermission && window.userHasPermission('edit_commessa');
        const disabled = canEdit ? '' : 'disabled';

        document.getElementById('pp-body').innerHTML = `
            <div class="ed-pp-section">Codice documento</div>
            <div class="ed-pprow c3">
                <div class="ed-form-group"><label>Fase</label><select class="ed-select" id="pp-fase" ${disabled}>${(lookups.fase || []).map(x => `<option${x.c === doc.fase ? ' selected' : ''}>${x.c}</option>`).join('')}</select></div>
                <div class="ed-form-group"><label>Zona</label><select class="ed-select" id="pp-zona" ${disabled}>${(lookups.zona || []).map(x => `<option${x.c === doc.zona ? ' selected' : ''}>${x.c}</option>`).join('')}</select></div>
                <div class="ed-form-group"><label>Disciplina</label><select class="ed-select" id="pp-disc" ${disabled}>${(lookups.disc || []).map(x => `<option${x.c === doc.disc ? ' selected' : ''}>${x.c}</option>`).join('')}</select></div>
            </div>
            <div class="ed-pprow c3">
                <div class="ed-form-group"><label>Tipo doc</label><select class="ed-select" id="pp-tipo" ${disabled}>${(lookups.tipo || []).map(x => `<option${x.c === doc.tipo ? ' selected' : ''}>${x.c} — ${x.d}</option>`).join('')}</select></div>
                <div class="ed-form-group"><label>Numero</label><input type="number" class="ed-input" id="pp-num" value="${doc.num}" min="0" max="9999" ${disabled}></div>
                <div class="ed-form-group"><label>Revisione</label><input type="text" class="ed-input ro" id="pp-rev" value="${doc.rev}" readonly></div>
            </div>
            <div class="ed-divider"></div>
            <div class="ed-pp-section">Informazioni</div>
            <div class="ed-pprow"><div class="ed-form-group" style="width:100%"><label>Titolo *</label><input type="text" class="ed-input" id="pp-title" value="${escapeHtml(doc.title)}" ${disabled}></div></div>
            <div class="ed-pprow"><div class="ed-form-group" style="width:100%"><label>Tipo documento (descrizione)</label>
                <select class="ed-select" id="pp-sub" ${disabled}>${(lookups.tipo || []).map(x => `<option${x.d === doc.sub ? ' selected' : ''}>${x.d}</option>`).join('')}</select></div></div>
            <div class="ed-pprow c2">
                <div class="ed-form-group"><label>Responsabile</label><select class="ed-select" id="pp-resp" ${disabled}><option value=""></option>${(lookups.resp || []).map(x => `<option value="${x.c}"${x.c === doc.resp ? ' selected' : ''}>${x.d}</option>`).join('')}</select></div>
                <div class="ed-form-group"><label>Software output</label><select class="ed-select" id="pp-output" ${disabled}>${(lookups.output || []).map(x => `<option${x.c === doc.output ? ' selected' : ''}>${x.c}</option>`).join('')}</select></div>
            </div>
            <div class="ed-divider"></div>
            <div class="ed-pp-section">Stato & Avanzamento</div>
            <div class="ed-pprow c2">
                <div class="ed-form-group"><label>Stato</label><select class="ed-select" id="pp-status" ${disabled}>${Object.keys(STATUS_CFG).map(k => `<option${k === doc.status ? ' selected' : ''}>${k}</option>`).join('')}</select></div>
                <div class="ed-form-group"><label>Avanzamento %</label><input type="range" id="pp-prog" min="0" max="100" step="5" value="${doc.prog || 0}" style="margin-top:12px;accent-color:#6366f1;width:100%" ${disabled}><div style="text-align:center;font-size:12px;font-weight:700;color:#6366f1" id="pp-prog-lbl">${doc.prog || 0}%</div></div>
            </div>
            <div class="ed-divider"></div>
            <div class="ed-pp-section">Pianificazione</div>
            <div class="ed-pprow c3">
                <div class="ed-form-group"><label>Data inizio</label><input type="date" class="ed-input" id="pp-dateStart" value="${doc.dateStart || ''}" ${disabled}></div>
                <div class="ed-form-group"><label>Fine prevista</label><input type="date" class="ed-input" id="pp-dateEnd" value="${doc.dateEnd || ''}" ${disabled}></div>
                <div class="ed-form-group"><label>Data emissione</label><input type="date" class="ed-input" id="pp-dateEmission" value="${doc.dateEmission || ''}" ${disabled}></div>
            </div>
            <div class="ed-divider"></div>
            <div class="ed-pp-section">Note</div>
            <div class="ed-pprow"><div class="ed-form-group" style="width:100%"><label>Note interne</label><textarea class="ed-textarea" id="pp-notes" rows="3" ${disabled}>${escapeHtml(doc.notes || '')}</textarea></div></div>
            <div class="ed-divider"></div>
            <div class="ed-pp-section">File allegati (Nextcloud)</div>
            <div id="pp-files-list" class="ed-files-list">${renderFileList(doc.files || [])}</div>
            ${canEdit ? `
            <div class="ed-files-actions">
                <label class="ed-btn-file" title="Carica file su Nextcloud e allega a questo documento">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Carica file
                    <input type="file" id="pp-file-input" multiple style="display:none" onchange="ElencoDoc.uploadNcFiles(this)">
                </label>
                <button class="ed-btn-nc-browse" onclick="ElencoDoc.openNcBrowser()" title="Sfoglia cartella Nextcloud del progetto e allega file esistenti">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                    Sfoglia NC
                </button>
            </div>` : ''}
        `;

        // Live progress label
        const progInput = document.getElementById('pp-prog');
        if (progInput) {
            progInput.addEventListener('input', () => {
                document.getElementById('pp-prog-lbl').textContent = progInput.value + '%';
            });
        }

        // Live code preview
        ['pp-fase', 'pp-zona', 'pp-disc', 'pp-tipo', 'pp-num'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', updatePPCode);
                el.addEventListener('input', updatePPCode);
            }
        });

        document.getElementById('propsPanel').classList.add('on');
    }

    function updatePPCode() {
        const f = document.getElementById('pp-fase')?.value || 'PD';
        const z = document.getElementById('pp-zona')?.value || '00';
        const d = document.getElementById('pp-disc')?.value || 'GE';
        const t = (document.getElementById('pp-tipo')?.value || 'RT').split(' ')[0];
        const n = fmtNum(parseInt(document.getElementById('pp-num')?.value) || 0);
        const doc = findDoc(propDocId);
        const projCode = document.getElementById('projectBadge')?.textContent || 'PRJ';
        document.getElementById('pp-code-disp').textContent = `${projCode}-${f}-${z}-${d}-${t}-${n}-${doc?.rev || 'RA'}`;
    }

    function closeProps() {
        document.getElementById('propsPanel').classList.remove('on');
        closeRevDialog();
        propDocId = null;
    }

    function _applyPropsToDoc(doc) {
        if (!doc) return;
        doc.fase = document.getElementById('pp-fase')?.value || doc.fase;
        doc.zona = document.getElementById('pp-zona')?.value || doc.zona;
        doc.disc = document.getElementById('pp-disc')?.value || doc.disc;
        doc.tipo = (document.getElementById('pp-tipo')?.value || doc.tipo).split(' ')[0];
        doc.num = parseInt(document.getElementById('pp-num')?.value) || doc.num;
        doc.title = document.getElementById('pp-title')?.value || doc.title;
        doc.sub = document.getElementById('pp-sub')?.value || doc.sub;
        doc.resp = document.getElementById('pp-resp')?.value ?? doc.resp;
        doc.output = document.getElementById('pp-output')?.value || doc.output;
        const newStatus = document.getElementById('pp-status')?.value || doc.status;
        if (newStatus !== doc.status) doc.rev = revForStatus(doc.rev, newStatus);
        doc.status = newStatus;
        doc.prog = parseInt(document.getElementById('pp-prog')?.value) || doc.prog;
        doc.dateStart = document.getElementById('pp-dateStart')?.value ?? doc.dateStart;
        doc.dateEnd = document.getElementById('pp-dateEnd')?.value ?? doc.dateEnd;
        doc.dateEmission = document.getElementById('pp-dateEmission')?.value ?? doc.dateEmission;
        doc.notes = document.getElementById('pp-notes')?.value || '';
    }

    async function saveProps() {
        const doc = findDoc(propDocId);
        if (!doc) return;
        _applyPropsToDoc(doc);
        reRenderRow(propDocId);
        updateStats();
        closeProps();
        flashSave();

        // Persist to DB
        const result = await saveOneDoc(doc, doc.idSection);
        if (!result.success) {
            console.error('saveProps error:', result.message);
        }
    }

    // ================================
    // REVISION DIALOG
    // ================================
    function openRevDialog() {
        const doc = findDoc(propDocId);
        if (!doc || doc.status !== 'EMESSO') return;
        const newRev = nextRevForDup(doc.rev);
        document.getElementById('rdb-code').textContent = codeStr(doc) + ' → ' + newRev;
        document.getElementById('rdb-newrev-desc').textContent = `Nuovo documento con stato IN REVISIONE e revisione ${newRev}`;
        document.getElementById('revDialog').style.display = 'flex';
    }

    function closeRevDialog() {
        const d = document.getElementById('revDialog');
        if (d) d.style.display = 'none';
    }

    async function confirmRevision() {
        const doc = findDoc(propDocId);
        if (!doc) return;
        closeRevDialog();

        // First save current doc state
        _applyPropsToDoc(doc);
        if (!isTempId(doc.id)) {
            await saveOneDoc(doc, doc.idSection);
        }

        // If doc has a real DB id, use backend revision logic
        if (!isTempId(doc.id)) {
            const result = await sendRequest('createRevision', { docId: doc.id });
            if (result.success) {
                closeProps();
                await loadDocumenti(); // reload to get the new doc from DB
                flashSave();
                if (result.newDocId) {
                    setTimeout(() => openProps(result.newDocId), 120);
                }
            } else {
                alert(result.message || 'Errore nella creazione revisione');
            }
            return;
        }

        // Fallback for temp docs
        const sec = docSection(doc.id);
        if (!sec) return;

        const newRev = nextRevForDup(doc.rev);
        const newDoc = {
            ...JSON.parse(JSON.stringify(doc)),
            id: genId(),
            rev: newRev,
            prog: 0,
            status: 'IN REVISIONE',
            files: []
        };

        const idx = sec.docs.indexOf(doc);
        sec.docs.splice(idx + 1, 0, newDoc);

        closeProps();
        renderSections();
        flashSave();

        setTimeout(() => openProps(newDoc.id), 120);
    }

    // ================================
    // SUBMITTAL
    // ================================
    function openSub() {
        editSubId = null;
        subSel = new Set();
        const nextNum = String(submittals.length + 1).padStart(3, '0');
        const ni = document.getElementById('cb-num');
        if (ni) ni.value = nextNum;
        updateSubCode();
        renderSubDocList();
        updateSubRight();
        document.getElementById('subPanel').classList.add('on');
    }

    function closeSub() {
        editSubId = null;
        document.getElementById('subPanel').classList.remove('on');
    }

    function updateSubCode() {
        const tp = document.getElementById('cb-type')?.value || 'TR';
        const nm = document.getElementById('cb-num')?.value || '001';
        const rv = document.getElementById('cb-rev')?.value || 'A';
        const projCode = document.getElementById('projectBadge')?.textContent || 'PRJ';
        const code = `${projCode}-${tp}-${nm}-${rv}`;
        const preview = document.getElementById('subCodePreview');
        if (preview) preview.textContent = code;
    }

    function renderSubDocList() {
        const list = document.getElementById('subDocList');
        if (!list) return;
        list.innerHTML = '';

        sections.forEach(sec => {
            const sh = document.createElement('div');
            sh.style.cssText = 'font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;padding:7px 14px 3px;background:#f8f9fb;border-bottom:1px solid #f0f1f5';
            sh.textContent = sec.name;
            list.appendChild(sh);

            (sec.docs || []).forEach(doc => {
                const item = document.createElement('div');
                item.className = 'ed-sub-item' + (subSel.has(doc.id) ? ' sel' : '');
                item.innerHTML = `
                    <input type="checkbox" ${subSel.has(doc.id) ? 'checked' : ''}>
                    <div style="min-width:0">
                        <div style="font-family:'Courier New',monospace;font-size:9px;font-weight:700;color:#374151">${codeStr(doc)}</div>
                        <div style="font-size:11px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(doc.title)}</div>
                        <div style="font-size:10px;color:#6b7280">${respDisplay(doc.resp)} · ${doc.rev} · ${doc.prog || 0}%</div>
                    </div>
                `;

                item.querySelector('input').addEventListener('change', ev => {
                    ev.stopPropagation();
                    if (ev.target.checked) subSel.add(doc.id);
                    else subSel.delete(doc.id);
                    item.classList.toggle('sel', ev.target.checked);
                    updateSubRight();
                });

                item.addEventListener('click', ev => {
                    if (ev.target.tagName === 'INPUT') return;
                    const cb = item.querySelector('input');
                    cb.checked = !cb.checked;
                    cb.dispatchEvent(new Event('change'));
                });

                list.appendChild(item);
            });
        });
    }

    function updateSubRight() {
        document.getElementById('subCount').innerHTML = `<strong>${subSel.size}</strong> documenti selezionati`;
        const sl = document.getElementById('subSelList');
        if (!sl) return;

        if (subSel.size === 0) {
            sl.innerHTML = '<div class="ed-empty-state">Nessun documento selezionato</div>';
            return;
        }

        sl.innerHTML = '';
        subSel.forEach(id => {
            const doc = findDoc(id);
            if (!doc) return;
            const item = document.createElement('div');
            item.className = 'ed-sub-sel-item';
            item.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" width="11" height="11">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <div style="min-width:0;flex:1">
                    <div style="font-family:'Courier New',monospace;font-size:9px;font-weight:700;color:#374151">${codeStr(doc)}</div>
                    <div style="font-size:11px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escapeHtml(doc.title)}</div>
                </div>
                <button class="rm" onclick="ElencoDoc.removeSubDoc('${id}')">×</button>
            `;
            sl.appendChild(item);
        });
    }

    function removeSubDoc(id) {
        subSel.delete(id);
        renderSubDocList();
        updateSubRight();
    }

    async function saveSub(emit) {
        if (subSel.size === 0) {
            alert('Seleziona almeno un documento.');
            return;
        }

        const code = document.getElementById('subCodePreview')?.textContent || '';
        const date = document.getElementById('sub-date')?.value || '';
        if (!date) {
            alert('Inserisci la data di emissione.');
            return;
        }

        const submittalData = {
            idProject,
            codice: code,
            segTipo: document.getElementById('cb-type')?.value || 'TR',
            segLettera: document.getElementById('cb-rev')?.value || 'A',
            dataConsegna: date,
            stato: emit ? 'Emesso' : 'Pianificato',
            destinatario: document.getElementById('sub-dest')?.value || '',
            scopo: document.getElementById('sub-scopo')?.value === 'PEC' ? 'PEC'
                   : document.getElementById('sub-scopo')?.value === 'Portale committente' ? 'portale'
                   : 'email',
            oggetto: document.getElementById('sub-oggetto')?.value || '',
            note: document.getElementById('sub-note')?.value || '',
            docIds: [...subSel]
        };

        if (editSubId) {
            submittalData.id = editSubId;
        }

        const result = await sendRequest('saveSubmittal', submittalData);
        if (result.success) {
            closeSub();
            loadSubmittals();
            flashSave();
        } else {
            alert(result.message || 'Errore durante il salvataggio');
        }
    }

    // ================================
    // SUBMITTAL MANAGER
    // ================================
    function openSmgr() {
        renderSmgrList();
        document.getElementById('smgrPanel').classList.add('on');
    }

    function closeSmgr() {
        document.getElementById('smgrPanel').classList.remove('on');
    }

    function renderSmgrList() {
        const body = document.getElementById('smgrBody');
        if (!body) return;

        if (submittals.length === 0) {
            body.innerHTML = '<div class="ed-empty-state">Nessun submittal registrato. Crea il primo dal pannello documenti.</div>';
            return;
        }

        body.innerHTML = submittals.map(sub => `
            <div class="ed-smgr-item" onclick="ElencoDoc.openLtr('${sub.id}')">
                <div class="ed-smgr-item-code">${escapeHtml(sub.code)}</div>
                <div class="ed-smgr-item-info">
                    <div class="ed-smgr-item-title">${escapeHtml(sub.oggetto || 'Trasmissione')}</div>
                    <div class="ed-smgr-item-meta">${isoToDisp(sub.date)} · ${respDisplay(sub.dest)} · ${sub.docIds?.length || 0} doc</div>
                </div>
                <span class="ed-smgr-item-status ${sub.status}">${sub.status}</span>
            </div>
        `).join('');
    }

    // ================================
    // TRANSMISSION LETTER
    // ================================
    function openLtr(subId) {
        const sub = submittals.find(s => s.id === subId);
        if (!sub) return;

        currentLtrSubId = subId;
        const projCode = document.getElementById('projectBadge')?.textContent || 'PRJ';
        const docs = (sub.docIds || []).map(id => findDoc(id)).filter(Boolean);

        const ltrDoc = document.getElementById('ltrDoc');
        if (ltrDoc) {
            ltrDoc.innerHTML = `
                <div style="text-align:center;margin-bottom:32px">
                    <h2 style="margin:0">LETTERA DI TRASMISSIONE</h2>
                    <div style="font-size:14px;color:#6b7280;margin-top:8px">${escapeHtml(sub.code)}</div>
                </div>
                <table style="width:100%;border-collapse:collapse;margin-bottom:24px">
                    <tr><td style="width:120px;font-weight:600;padding:6px 0">Commessa:</td><td>${projCode}</td></tr>
                    <tr><td style="font-weight:600;padding:6px 0">Data:</td><td>${isoToDisp(sub.date)}</td></tr>
                    <tr><td style="font-weight:600;padding:6px 0">Destinatario:</td><td>${respDisplay(sub.dest)}</td></tr>
                    <tr><td style="font-weight:600;padding:6px 0">Scopo:</td><td>${escapeHtml(sub.scopo || '—')}</td></tr>
                    <tr><td style="font-weight:600;padding:6px 0">Modalità:</td><td>${escapeHtml(sub.modalita || '—')}</td></tr>
                </table>
                <div style="font-weight:600;margin-bottom:8px">Oggetto: ${escapeHtml(sub.oggetto || '')}</div>
                <table style="width:100%;border-collapse:collapse;margin-top:16px;border:1px solid #e2e4e8">
                    <thead>
                        <tr style="background:#f8f9fb">
                            <th style="padding:8px;text-align:left;border:1px solid #e2e4e8;font-size:11px">#</th>
                            <th style="padding:8px;text-align:left;border:1px solid #e2e4e8;font-size:11px">Codice</th>
                            <th style="padding:8px;text-align:left;border:1px solid #e2e4e8;font-size:11px">Descrizione</th>
                            <th style="padding:8px;text-align:left;border:1px solid #e2e4e8;font-size:11px">Rev</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${docs.map((d, i) => `
                            <tr>
                                <td style="padding:8px;border:1px solid #e2e4e8">${i + 1}</td>
                                <td style="padding:8px;border:1px solid #e2e4e8;font-family:monospace;font-size:10px">${codeStr(d)}</td>
                                <td style="padding:8px;border:1px solid #e2e4e8">${escapeHtml(d.title)}</td>
                                <td style="padding:8px;border:1px solid #e2e4e8">${d.rev}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
                ${sub.note ? `<div style="margin-top:24px"><strong>Note:</strong><br>${escapeHtml(sub.note)}</div>` : ''}
            `;
        }

        document.getElementById('ltrPanel').classList.add('on');
    }

    function closeLtr() {
        document.getElementById('ltrPanel').classList.remove('on');
        currentLtrSubId = null;
    }

    function downloadLtrPdf() {
        if (!currentLtrSubId) return;
        const csrf = document.querySelector('meta[name="token-csrf"]')?.content || '';
        // Apre il download in una nuova tab tramite form POST (necessario per stream binario)
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/ajax.php';
        form.target = '_blank';
        const fields = {
            section:     'elenco_documenti',
            action:      'generatePdf',
            idProject:   idProject,
            submittalId: String(currentLtrSubId),
            csrf_token:  csrf
        };
        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = name;
            input.value = value;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    // ================================
    // MAIL
    // ================================
    function sendSubmittalMail() {
        const sub = submittals.find(s => s.id === currentLtrSubId);
        if (!sub) return;

        document.getElementById('mailTo').value = '';
        document.getElementById('mailCc').value = '';
        document.getElementById('mailSubject').value = `Trasmissione ${sub.code} - ${sub.oggetto || ''}`;
        document.getElementById('mailBody').value = `Gentile Cliente,\n\nIn allegato la lettera di trasmissione ${sub.code}.\n\nCordiali saluti`;
        document.getElementById('mailStatus').textContent = '';
        document.getElementById('mailStatus').className = 'ed-mail-status';

        document.getElementById('mailPanel').classList.add('on');
    }

    function closeMailPanel() {
        document.getElementById('mailPanel').classList.remove('on');
    }

    async function dispatchMail() {
        const to = document.getElementById('mailTo')?.value || '';
        const cc = document.getElementById('mailCc')?.value || '';
        const subject = document.getElementById('mailSubject')?.value || '';
        const body = document.getElementById('mailBody')?.value || '';

        if (!to) {
            alert('Inserisci un destinatario');
            return;
        }

        const status = document.getElementById('mailStatus');
        status.textContent = 'Invio in corso...';
        status.className = 'ed-mail-status sending';

        const result = await sendRequest('sendMail', {
            idProject,
            submittalId: currentLtrSubId,
            to,
            cc,
            subject,
            body
        });

        if (result.success) {
            status.textContent = 'Email inviata con successo';
            status.className = 'ed-mail-status success';
            setTimeout(() => closeMailPanel(), 2000);
        } else {
            status.textContent = result.message || 'Errore durante l\'invio';
            status.className = 'ed-mail-status error';
        }
    }

    // ================================
    // SAVE
    // ================================

    // Save a single doc to DB; if doc.id is a temp string, creates new record and updates local id
    async function saveOneDoc(doc, secId) {
        const payload = denormalizeDoc(doc, secId);
        const result = await sendRequest('saveDocumento', payload);
        if (result.success && result.docId) {
            doc.id = parseInt(result.docId); // replace temp id with real DB int id
        }
        return result;
    }

    // Save all sections and their documents
    async function saveDocumenti() {
        for (const sec of sections) {
            // Persist section if it has a temp string id (not yet in DB)
            if (isTempId(sec.id)) {
                const res = await sendRequest('saveSection', {
                    idProject,
                    nome: sec.name,
                    ordine: sections.indexOf(sec),
                    rangeNumDa: sec.rangeFrom,
                    rangeNumA: sec.rangeTo
                });
                if (res.success && res.sectionId) {
                    sec.id = parseInt(res.sectionId);
                }
            }

            // Save each doc
            for (const doc of (sec.docs || [])) {
                const r = await saveOneDoc(doc, sec.id);
                if (!r.success) {
                    console.error('Doc save error:', doc.title, r.message);
                }
            }
        }
    }

    let flashTimeout;
    function flashSave() {
        const f = document.getElementById('sf');
        if (!f) return;
        f.classList.add('on');
        clearTimeout(flashTimeout);
        flashTimeout = setTimeout(() => f.classList.remove('on'), 1600);
    }

    async function addSection() {
        const n = prompt('Nome nuova sezione:');
        if (!n || !n.trim()) return;

        const fr = parseInt(prompt('Range DA (numero):', '300') || '300');
        const to = parseInt(prompt('Range A (numero):', '399') || '399');

        const nome = n.trim().toUpperCase();
        const ordine = sections.length;

        // Persist to DB first
        const result = await sendRequest('saveSection', {
            idProject,
            nome,
            ordine,
            rangeNumDa: fr,
            rangeNumA: to
        });

        const newId = (result.success && result.sectionId) ? parseInt(result.sectionId) : ('s' + Date.now());

        sections.push({
            id: newId,
            name: nome,
            rangeFrom: fr,
            rangeTo: to,
            docs: []
        });

        renderSections();
        flashSave();
    }

    // ================================
    // INITIALIZATION
    // ================================
    async function init() {
        idProject = (document.getElementById('idProject')?.value || '').trim();
        if (!idProject) {
            console.error('No project ID');
            return;
        }

        // Load data
        await loadCommessaData();
        await loadRisorse();
        await loadDocumenti();
        await loadSubmittals();

        // Setup event listeners
        document.addEventListener('click', closeAP);

        document.getElementById('filter-stato')?.addEventListener('change', renderSections);
        document.getElementById('filter-disc')?.addEventListener('change', renderSections);
        document.getElementById('filter-resp')?.addEventListener('change', renderSections);
        document.getElementById('filter-text')?.addEventListener('input', debounce(renderSections, 300));

        document.getElementById('btnSubmittalMgr')?.addEventListener('click', openSmgr);
        document.getElementById('btnAddSection')?.addEventListener('click', addSection);

        // Close panels on overlay click
        document.getElementById('subPanel')?.addEventListener('click', e => {
            if (e.target === document.getElementById('subPanel')) closeSub();
        });
        document.getElementById('smgrPanel')?.addEventListener('click', e => {
            if (e.target === document.getElementById('smgrPanel')) closeSmgr();
        });
        document.getElementById('ltrPanel')?.addEventListener('click', e => {
            if (e.target === document.getElementById('ltrPanel')) closeLtr();
        });
        document.getElementById('mailPanel')?.addEventListener('click', e => {
            if (e.target === document.getElementById('mailPanel')) closeMailPanel();
        });
    }

    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // ================================
    // PUBLIC API
    // ================================
    return {
        init,
        openProps,
        closeProps,
        saveProps,
        openRevDialog,
        closeRevDialog,
        confirmRevision,
        toggleSec,
        openStatusPop,
        openProg,
        openDatePop,
        openRangePicker,
        saveRange,
        addDocToSection,
        dupRevision,
        deleteDoc,
        deleteSectionConfirm,
        renameSection,
        openSub,
        closeSub,
        saveSub,
        updateSubCode,
        removeSubDoc,
        openSmgr,
        closeSmgr,
        openLtr,
        closeLtr,
        sendSubmittalMail,
        closeMailPanel,
        dispatchMail,
        downloadLtrPdf,
        closeAP,
        saveDocumenti,
        uploadNcFiles,
        openNcBrowser,
        closeNcBrowser,
        attachNcFileFromBrowser,
        detachNcFile
    };

    // ================================
    // NEXTCLOUD
    // ================================

    const NC_DOWNLOAD_BASE = '/ajax.php';

    function ncDownloadUrl(path) {
        const csrf = document.querySelector('meta[name="token-csrf"]')?.content || '';
        return `${NC_DOWNLOAD_BASE}?section=nextcloud&action=file&path=${encodeURIComponent(path)}&_csrf=${encodeURIComponent(csrf)}`;
    }

    function renderFileList(files) {
        if (!files || files.length === 0) {
            return '<div class="ed-files-empty">Nessun file allegato</div>';
        }
        return files.map(f => `
            <div class="ed-file-item" data-path="${escapeHtml(f.path)}">
                <span class="ed-file-icon">${fileIcon(f.mime)}</span>
                <a class="ed-file-name" href="${ncDownloadUrl(f.path)}" target="_blank" title="${escapeHtml(f.path)}">${escapeHtml(f.name)}</a>
                <span class="ed-file-size">${fmtSize(f.size)}</span>
                ${window.userHasPermission && window.userHasPermission('edit_commessa') ? `
                <button class="ed-file-detach" onclick="ElencoDoc.detachNcFile('${escapeHtml(f.path)}')" title="Rimuovi allegato (non cancella il file)">×</button>` : ''}
            </div>
        `).join('');
    }

    function fileIcon(mime) {
        if (!mime) return '📄';
        if (mime.includes('pdf')) return '📕';
        if (mime.includes('image')) return '🖼️';
        if (mime.includes('spreadsheet') || mime.includes('excel')) return '📗';
        if (mime.includes('word') || mime.includes('document')) return '📘';
        if (mime.includes('zip') || mime.includes('compressed')) return '🗜️';
        return '📄';
    }

    function fmtSize(bytes) {
        if (!bytes) return '';
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    async function uploadNcFiles(input) {
        const doc = findDoc(propDocId);
        if (!doc || !input.files.length) return;

        const filesList = document.getElementById('pp-files-list');
        if (filesList) filesList.innerHTML = '<div class="ed-files-empty">Caricamento...</div>';

        for (const file of input.files) {
            const formData = new FormData();
            formData.append('section', 'elenco_documenti');
            formData.append('action', 'uploadNcFile');
            formData.append('idProject', idProject);
            formData.append('docId', String(doc.id));
            formData.append('file', file);

            const csrf = document.querySelector('meta[name="token-csrf"]')?.content || '';
            try {
                const resp = await fetch('/ajax.php', {
                    method: 'POST',
                    headers: { 'X-Csrf-Token': csrf },
                    body: formData
                });
                const result = await resp.json();
                if (result.success) {
                    doc.files = result.data;
                } else {
                    alert('Errore upload: ' + (result.message || 'Errore sconosciuto'));
                }
            } catch (e) {
                alert('Errore di rete durante l\'upload');
            }
        }

        // Reset input e aggiorna lista
        input.value = '';
        if (filesList) filesList.innerHTML = renderFileList(doc.files || []);
    }

    async function detachNcFile(path) {
        const doc = findDoc(propDocId);
        if (!doc) return;
        if (!confirm('Rimuovere l\'allegato? Il file resterà su Nextcloud.')) return;

        const result = await sendRequest('detachNcFile', { idProject, docId: doc.id, path });
        if (result.success) {
            doc.files = result.data;
            const filesList = document.getElementById('pp-files-list');
            if (filesList) filesList.innerHTML = renderFileList(doc.files);
        } else {
            alert(result.message || 'Errore');
        }
    }

    // ── Browser Nextcloud ─────────────────────────────────────────

    async function openNcBrowser() {
        const modal = document.getElementById('ncBrowserModal');
        if (!modal) return;
        modal.classList.add('on');
        document.getElementById('ncb-list').innerHTML = '<div class="ed-files-empty">Caricamento...</div>';
        document.getElementById('ncb-attach-btn').disabled = true;

        const result = await sendRequest('listNcFolder', { idProject });
        if (result.success) {
            _ncBrowserFiles = result.data || [];
            renderNcBrowser(_ncBrowserFiles);
            document.getElementById('ncb-folder').textContent = result.folder || '';
        } else {
            document.getElementById('ncb-list').innerHTML = `<div class="ed-files-empty" style="color:#ef4444">${escapeHtml(result.message)}</div>`;
        }
    }

    function renderNcBrowser(files) {
        const list = document.getElementById('ncb-list');
        if (!files.length) {
            list.innerHTML = '<div class="ed-files-empty">Cartella vuota</div>';
            return;
        }
        list.innerHTML = files.map((f, i) => `
            <label class="ncb-item">
                <input type="checkbox" data-idx="${i}">
                <span class="ed-file-icon">${fileIcon(f.mime)}</span>
                <span class="ncb-name">${escapeHtml(f.name)}</span>
                <span class="ed-file-size">${fmtSize(f.size)}</span>
            </label>
        `).join('');
        list.querySelectorAll('input[type=checkbox]').forEach(cb => {
            cb.addEventListener('change', () => {
                const any = !!list.querySelector('input:checked');
                document.getElementById('ncb-attach-btn').disabled = !any;
            });
        });
    }

    function closeNcBrowser() {
        document.getElementById('ncBrowserModal')?.classList.remove('on');
    }

    async function attachNcFileFromBrowser() {
        const doc = findDoc(propDocId);
        if (!doc) return;
        const checked = document.querySelectorAll('#ncb-list input[type=checkbox]:checked');
        if (!checked.length) return;

        document.getElementById('ncb-attach-btn').disabled = true;

        for (const cb of checked) {
            const idx = parseInt(cb.dataset.idx);
            const f = _ncBrowserFiles[idx];
            if (!f) continue;
            const result = await sendRequest('attachNcFile', {
                idProject, docId: doc.id,
                path: f.path, name: f.name, mime: f.mime, size: f.size
            });
            if (result.success) {
                doc.files = result.data;
            }
        }

        const filesList = document.getElementById('pp-files-list');
        if (filesList) filesList.innerHTML = renderFileList(doc.files || []);
        closeNcBrowser();
    }

})();

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', ElencoDoc.init);
