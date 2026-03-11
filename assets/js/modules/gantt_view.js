// Gantt riusabile, zero dipendenze, CSP safe, tooltip via data-tooltip
// API:
//   GanttView.init({ containerId:'gantt-view', provider: async()=>Event[], range:'month'|'quarter'|'year' })
//   GanttView.setProvider(fn)
//   GanttView.refresh()
//
// Event shape atteso (stesso del calendario):
//   { id, title, start, end?, url?, color?, status?, meta? }
// NOTE date: accetta "YYYY-MM-DD" o "DD/MM/YYYY" (come calendar_view.js)

(function () {
    if (window.GanttView) return;

    // ---------- utils ----------
    const dce = (tag, cls) => { const el = document.createElement(tag); if (cls) el.className = cls; return el; };
    const pad2 = (n) => String(n).padStart(2, '0');

    const parseDate = (v) => {
        if (!v) return null;
        const s = String(v).trim();

        // ISO YYYY-MM-DD[ T]HH:mm[:ss]?
        let m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
        if (m) {
            const y = +m[1], mo = +m[2] - 1, d = +m[3];
            const hh = +(m[4] || 0), mm = +(m[5] || 0), ss = +(m[6] || 0);
            const dt = new Date(y, mo, d, hh, mm, ss, 0);
            return isNaN(dt.getTime()) ? null : dt;
        }

        // DD/MM/YYYY[ HH:mm[:ss]]?
        m = s.match(/^(\d{2})\/(\d{2})\/(\d{4})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
        if (m) {
            const d = +m[1], mo = +m[2] - 1, y = +m[3];
            const hh = +(m[4] || 0), mm = +(m[5] || 0), ss = +(m[6] || 0);
            const dt = new Date(y, mo, d, hh, mm, ss, 0);
            return isNaN(dt.getTime()) ? null : dt;
        }
        return null;
    };
    const fmtISO = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;

    const clampDate = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());

    function buildRange(anchorDate, range) {
        const a = clampDate(anchorDate instanceof Date ? anchorDate : new Date());
        let start, end, header;
        if (range === 'year') {
            start = new Date(a.getFullYear(), 0, 1);
            end = new Date(a.getFullYear(), 11, 31);
            header = 'Anno';
        } else if (range === 'quarter') {
            const qStartMonth = Math.floor(a.getMonth() / 3) * 3;
            start = new Date(a.getFullYear(), qStartMonth, 1);
            end = new Date(a.getFullYear(), qStartMonth + 3, 0);
            header = 'Trimestre';
        } else { // month
            start = new Date(a.getFullYear(), a.getMonth(), 1);
            end = new Date(a.getFullYear(), a.getMonth() + 1, 0);
            header = 'Mese';
        }
        return { start, end, header };
    }

    function daysDiff(a, b) {
        const ms = clampDate(b) - clampDate(a);
        return Math.floor(ms / 86400000);
    }

    function* daysIter(from, toInclusive) {
        const d = new Date(from);
        while (d <= toInclusive) {
            yield new Date(d);
            d.setDate(d.getDate() + 1);
        }
    }

    // debounce semplice per il resize
    function debounce(fn, wait = 100) {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
    }

    const isAdmin = () => (typeof window.isCurrentUserAdmin === 'function'
        ? window.isCurrentUserAdmin()
        : String(window.CURRENT_USER?.role_id) === '1');

    const getCurrentUserId = () => String(window.CURRENT_USER?.id || window.CURRENT_USER?.user_id || '');

    function canMoveEvent(ev) {
        const raw = ev?.meta?.raw || {};
        const uid = getCurrentUserId();
        const assegnato = String(raw.assegnato_a || '');
        const responsabile = String(raw.responsabile || '');
        const creatore = String(raw.submitted_by || raw.creato_da_id || '');
        return isAdmin() || [assegnato, responsabile, creatore].includes(uid);
    }

    async function moveEventDate(ev, newDateISO, state) {
        // Sposta l’inizio mantenendo la stessa durata (se ha end)
        try {
            const sOld = parseDate(ev.start);
            const eOld = ev.end ? parseDate(ev.end) : null;
            const duration = eOld ? (daysDiff(sOld, eOld)) : 0;

            const raw = ev?.meta?.raw || {};
            const payload = {
                form_id: String(raw.id ?? ev.id),
                table_name: raw.table_name || raw.table || undefined,
                field: 'data_scadenza',
                value: newDateISO
            };
            if (!payload.form_id || !payload.value) return { success: false, message: 'Payload non valido' };

            const res = await customFetch('forms', 'updateDate', payload);
            if (res?.success) {
                ev.start = newDateISO;
                if (duration > 0) {
                    const ns = parseDate(newDateISO);
                    const ne = new Date(ns); ne.setDate(ns.getDate() + duration);
                    ev.end = fmtISO(ne);
                }
                render(state);
                if (typeof window.showToast === 'function') showToast('Data aggiornata', 'success');
            } else {
                if (typeof window.showToast === 'function') showToast('Errore aggiornamento data', 'error');
            }
            return res;
        } catch (e) {
            if (typeof window.showToast === 'function') showToast('Errore di rete durante updateDate', 'error');
            return { success: false, message: e.message };
        }
    }

    // --- sync helpers (calendar <-> gantt) ---
    function mapCalendarViewToGanttRange(view) {
        if (view === 'year') return 'year';
        if (view === 'quarter') return 'quarter';
        if (view === 'month') return 'month';
        return null;
    }
    function applyCalendarStateToGantt(detail, state) {
        if (!detail || !state) return;
        if (detail.anchorISO) {
            const p = detail.anchorISO.split('-');
            if (p.length === 3) state.anchor = new Date(+p[0], +p[1] - 1, +p[2]);
        }
        if (detail.view) {
            const mapped = mapCalendarViewToGanttRange(detail.view);
            if (mapped) state.range = mapped; // cambia range solo per year/quarter
            // se mapped è null (month/week) lasciamo stare la range attuale
        }
    }

    function emitGanttState(state) {
        try {
            const anchor = state.anchor instanceof Date ? state.anchor : new Date();
            const detail = {
                anchorISO: `${anchor.getFullYear()}-${String(anchor.getMonth() + 1).padStart(2, '0')}-${String(anchor.getDate()).padStart(2, '0')}`,
                range: state.range || 'month'
            };
            window.dispatchEvent(new CustomEvent('gantt:state', { detail }));
        } catch { }
    }

    // ---------- rendering ----------
    function buildHeader(state) {
        const wrap = dce('div', 'gv-header');

        const btnPrev = dce('button', 'gv-btn'); btnPrev.type = 'button'; btnPrev.textContent = '‹'; btnPrev.setAttribute('data-tooltip', 'Periodo precedente');
        const btnToday = dce('button', 'gv-btn'); btnToday.type = 'button'; btnToday.textContent = 'Oggi'; btnToday.setAttribute('data-tooltip', 'Oggi');
        const btnNext = dce('button', 'gv-btn'); btnNext.type = 'button'; btnNext.textContent = '›'; btnNext.setAttribute('data-tooltip', 'Periodo successivo');

        const title = dce('div', 'gv-title');

        const switcher = dce('div', 'gv-switcher');
        const vMonth = dce('button', 'gv-btn'); vMonth.type = 'button'; vMonth.textContent = 'Mese'; vMonth.setAttribute('data-tooltip', 'Vista mese');
        const vQuart = dce('button', 'gv-btn'); vQuart.type = 'button'; vQuart.textContent = 'Trimestre'; vQuart.setAttribute('data-tooltip', 'Vista trimestre');
        const vYear = dce('button', 'gv-btn'); vYear.type = 'button'; vYear.textContent = 'Anno'; vYear.setAttribute('data-tooltip', 'Vista anno (12 mesi)');

        const setActive = () => {
            [vMonth, vQuart, vYear].forEach(b => b.classList.remove('active'));
            if (state.range === 'month') vMonth.classList.add('active');
            else if (state.range === 'quarter') vQuart.classList.add('active');
            else vYear.classList.add('active');
        };

        btnPrev.addEventListener('click', () => {
            if (state.range === 'month') state.anchor.setMonth(state.anchor.getMonth() - 1);
            else if (state.range === 'quarter') state.anchor.setMonth(state.anchor.getMonth() - 3);
            else state.anchor.setFullYear(state.anchor.getFullYear() - 1);
            render(state);
            emitGanttState(state);
        });
        btnToday.addEventListener('click', () => {
            state.anchor = new Date();
            render(state);
            emitGanttState(state);
        });
        btnNext.addEventListener('click', () => {
            if (state.range === 'month') state.anchor.setMonth(state.anchor.getMonth() + 1);
            else if (state.range === 'quarter') state.anchor.setMonth(state.anchor.getMonth() + 3);
            else state.anchor.setFullYear(state.anchor.getFullYear() + 1);
            render(state);
            emitGanttState(state);
        });

        vMonth.addEventListener('click', () => { state.range = 'month'; render(state); emitGanttState(state); });
        vQuart.addEventListener('click', () => { state.range = 'quarter'; render(state); emitGanttState(state); });
        vYear.addEventListener('click', () => { state.range = 'year'; render(state); emitGanttState(state); });

        switcher.append(vMonth, vQuart, vYear);
        wrap.append(btnPrev, btnToday, btnNext, title, switcher);
        return { el: wrap, titleEl: title, setActive };
    }

    // >>> COLONNE GANTT CONFIGURABILI (tabellare fisso)
    // Puoi sovrascrivere window.GanttColumnsMap PRIMA dell'init per mappare i tuoi nomi DB.
    // NOTA: l'ordine conta! Il primo campo trovato non vuoto viene usato.
    window.GanttColumnsMap = window.GanttColumnsMap || {
        id: ['codice_segnalazione', 'protocollo', 'codice', 'id'],
        titolo: ['titolo', 'form_name'], // titolo è il campo canonico per i moduli
        descrizione: ['descrizione', 'oggetto', 'note'], // descrizione è il campo canonico per i moduli
        assegnatario: ['assegnato_a_nome', 'assegnato_a'],
        responsabile: ['responsabile_nome', 'responsabile', 'creato_da', 'submitted_by']
    };

    // Configurazione colonne (sovrascrivibile per pagina)
    // Struttura: { key, title, width, type, isTitle?, transform? }
    window.GanttColumnsConfig = window.GanttColumnsConfig || null;

    // Colonne di default: id, titolo, descrizione, assegnatario, responsabile
    function resolveGanttColumns() {
        // Se esiste una config personalizzata, usala
        if (window.GanttColumnsConfig && Array.isArray(window.GanttColumnsConfig)) {
            return window.GanttColumnsConfig;
        }

        // Altrimenti usa le colonne di default
        return [
            { key: 'id', title: 'ID', width: 120, type: 'text' },
            { key: 'titolo', title: 'Titolo', width: 220, type: 'text', isTitle: true },
            { key: 'descrizione', title: 'Descrizione', width: 320, type: 'text' },
            { key: 'assegnatario', title: 'Assegnatario', width: 160, type: 'text' },
            { key: 'responsabile', title: 'Responsabile', width: 160, type: 'text' }
        ];
    }

    // ========== SORGENTE UNICA DELLE METRICHE (zero drift) ==========
    // TUTTE le larghezze della parte sinistra devono venire SOLO da questa funzione.
    // Header e corpo usano identici: widths[], template, total.
    //
    // TEST DI ACCETTAZIONE:
    // 1. A larghezze 1024/1366/1920px e DPI 1/1.25/1.5: bordi verticali header == bordi corpo
    // 2. Vista month: nessuno spazio vuoto a destra della timeline
    // 3. Vista quarter/year: scroll orizzontale regolare, colonne allineate
    // 4. Zoom 80%/110%: nessun disallineamento
    function getLeftGridMetrics(columns) {
        const widths = columns.map(c => Math.round(c.width ?? 120));
        const total = widths.reduce((sum, w) => sum + w, 0);
        const template = widths.map(px => `${px}px`).join(' ');
        return { widths, total, template };
    }

    // Legge il valore dal meta.raw usando il mapping, con fallback su ev/ev.title
    // Helper: legge un valore da un raw generico usando la mappa logica
    function getMappedFromRaw(raw, logicalKey) {
        const map = window.GanttColumnsMap || {};
        const candidates = map[logicalKey] || [];

        for (const k of candidates) {
            const v = raw?.[k];
            if (v !== undefined && v !== null && String(v).trim() !== '') {
                return String(v);
            }
        }

        if (logicalKey === 'id') return String(raw?.id ?? '');
        if (logicalKey === 'titolo') {
            return String(raw?.titolo || raw?.form_name || '');
        }
        return '';
    }

    // Legge il valore dal meta.raw dell'evento (fallback su ev/ev.title)
    function getMappedValue(ev, logicalKey, col) {
        const raw = ev?.meta?.raw || {};
        let v = getMappedFromRaw(raw, logicalKey);

        if (!v) {
            if (logicalKey === 'id') v = String(ev.id ?? '');
            else if (logicalKey === 'titolo') v = String(ev.title || '');
            else v = '';
        }

        // Applica funzione di trasformazione se definita
        if (col?.transform && typeof col.transform === 'function') {
            try {
                v = col.transform(v, raw, ev);
            } catch (e) {
                console.warn('Errore transform colonna:', logicalKey, e);
            }
        }

        return v;
    }

    function buildGrid(state, body) {
        body.innerHTML = '';
        const { start, end } = buildRange(state.anchor, state.range);

        // ========== SOLUZIONE PROFESSIONALE: ZERO SPAZIO VUOTO ==========
        // PROBLEMA: Timeline si espandeva oltre i giorni lasciando spazio bianco a destra
        // SOLUZIONE: Larghezze FISSE per ogni elemento (no auto, no flex-grow)
        //   1. Tabella: width = LEFT_TOTAL + timelinePx (ESATTA)
        //   2. Colgroup col: width/min/max = timelinePx (FISSA)
        //   3. TH/TD timeline: width/min/max = timelinePx (FISSA)
        //   4. Righe mesi/giorni: width/min/max = timelinePx (FISSA)
        //   5. Wrapper: inline-block (si adatta al contenuto, non si espande)
        // RISULTATO: Il Gantt finisce ESATTAMENTE dove finiscono i giorni. Zero pixel sprecati.

        const scroller = dce('div', 'gv-scroller');
        scroller.style.overflowX = 'auto';
        scroller.style.overflowY = 'visible';

        const wrapper = dce('div', 'gv-wrapper');
        wrapper.style.position = 'relative';
        wrapper.style.display = 'inline-block'; // NON flex column, così si adatta al contenuto
        wrapper.style.minWidth = '100%';

        // === METRICHE ===
        const columns = resolveGanttColumns();
        const { widths: colWidths, total: LEFT_TOTAL, template: gridTemplate } = getLeftGridMetrics(columns);

        const containerW = state.container?.clientWidth || state.container?.getBoundingClientRect()?.width || 1200;
        const { start: rangeStart, end: rangeEnd } = buildRange(state.anchor, state.range);
        const totalDays = 1 + daysDiff(rangeStart, rangeEnd);
        const avail = Math.max(1, containerW - LEFT_TOTAL - 2);

        let colWidth, timelinePx;
        if (state.range === 'month') {
            const dpr = Math.max(1, window.devicePixelRatio || 1);
            const raw = avail / totalDays;
            colWidth = Math.max(12, Math.round(raw * dpr) / dpr);
            timelinePx = colWidth * totalDays;
        } else {
            const MIN_COL = 20, MAX_COL = 48;
            colWidth = Math.min(MAX_COL, Math.max(MIN_COL, Math.floor(avail / totalDays) || MIN_COL));
            timelinePx = totalDays * colWidth;
        }

        state.container.style.setProperty('--gv-colw', `${colWidth}px`);

        // ========== TABELLA (garantisce allineamento perfetto) ==========
        const table = document.createElement('table');
        table.className = 'gv-table';
        table.style.borderCollapse = 'collapse';
        // Larghezza ESATTA: colonne sinistra + timeline (no espansione!)
        table.style.width = `${LEFT_TOTAL + timelinePx}px`;
        table.style.tableLayout = 'fixed';

        // COLGROUP: definisce larghezze colonne UNA VOLTA
        const colgroup = document.createElement('colgroup');
        colWidths.forEach(w => {
            const col = document.createElement('col');
            col.style.width = `${w}px`;
            colgroup.appendChild(col);
        });
        // Colonna timeline: LARGHEZZA FISSA = esattamente timelinePx (no auto!)
        const colTimeline = document.createElement('col');
        colTimeline.style.width = `${timelinePx}px`;
        colTimeline.style.minWidth = `${timelinePx}px`;
        colTimeline.style.maxWidth = `${timelinePx}px`;
        colgroup.appendChild(colTimeline);
        table.appendChild(colgroup);

        // THEAD: header con titoli
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        headerRow.className = 'gv-header-row';

        columns.forEach((col, idx) => {
            const th = document.createElement('th');
            th.textContent = col.title;
            th.style.padding = '8px 12px';
            th.style.textAlign = 'left';
            th.style.fontWeight = '600';
            th.style.borderRight = '1px solid #ddd';
            th.style.borderBottom = '1px solid #ddd';
            th.style.background = '#f7f7f7';
            th.style.whiteSpace = 'nowrap';
            th.style.overflow = 'hidden';
            th.style.textOverflow = 'ellipsis';
            headerRow.appendChild(th);
        });

        // Cella timeline header: LARGHEZZA FISSA
        const thTimeline = document.createElement('th');
        thTimeline.style.borderBottom = '1px solid #ddd';
        thTimeline.style.background = '#f7f7f7';
        thTimeline.style.padding = '0';
        thTimeline.style.position = 'relative';
        thTimeline.style.width = `${timelinePx}px`;
        thTimeline.style.minWidth = `${timelinePx}px`;
        thTimeline.style.maxWidth = `${timelinePx}px`;
        thTimeline.style.overflow = 'hidden';

        // Timeline header content (mesi/giorni): ESATTAMENTE timelinePx
        const timelineHeaderWrap = dce('div', 'gv-timeline-header');
        timelineHeaderWrap.style.width = `${timelinePx}px`;
        timelineHeaderWrap.style.minWidth = `${timelinePx}px`;
        timelineHeaderWrap.style.maxWidth = `${timelinePx}px`;
        timelineHeaderWrap.style.display = 'flex';
        timelineHeaderWrap.style.flexDirection = 'column';
        timelineHeaderWrap.style.overflow = 'hidden';

        // Riga mesi: LARGHEZZA ESATTA
        const monthsRow = dce('div', 'gv-months');
        monthsRow.style.display = 'flex';
        monthsRow.style.width = `${timelinePx}px`;
        monthsRow.style.minWidth = `${timelinePx}px`;
        monthsRow.style.maxWidth = `${timelinePx}px`;
        monthsRow.style.borderBottom = '1px solid #ddd';
        monthsRow.style.overflow = 'hidden';

        // Riga giorni: LARGHEZZA ESATTA
        const daysRow = dce('div', 'gv-days');
        daysRow.style.display = 'flex';
        daysRow.style.width = `${timelinePx}px`;
        daysRow.style.minWidth = `${timelinePx}px`;
        daysRow.style.maxWidth = `${timelinePx}px`;
        daysRow.style.overflow = 'hidden';

        let cursor = new Date(start.getFullYear(), start.getMonth(), 1);
        while (cursor <= end) {
            const mStart = new Date(cursor);
            const mEnd = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0);
            const segStart = mStart < start ? start : mStart;
            const segEnd = mEnd > end ? end : mEnd;
            const daysInSeg = 1 + daysDiff(segStart, segEnd);

            const monthBox = dce('div', 'gv-cell gv-month');
            monthBox.textContent = (new Date(cursor.getFullYear(), cursor.getMonth(), 1))
                .toLocaleString('it-IT', { month: 'long', year: 'numeric' });
            monthBox.style.flex = `0 0 ${daysInSeg * colWidth}px`;
            monthBox.style.padding = '4px 8px';
            monthBox.style.textAlign = 'center';
            monthBox.style.fontSize = '12px';
            monthBox.style.fontWeight = '600';
            monthsRow.appendChild(monthBox);

            for (const d of daysIter(segStart, segEnd)) {
                const dc = dce('div', 'gv-cell gv-day');
                dc.textContent = String(d.getDate());
                dc.style.flex = `0 0 ${colWidth}px`;
                dc.style.padding = '2px';
                dc.style.textAlign = 'center';
                dc.style.fontSize = '11px';
                dc.style.borderLeft = '1px solid rgba(0,0,0,0.05)';
                dc.dataset.date = fmtISO(d);
                if ((d.getDay() + 6) % 7 >= 5) {
                    dc.style.background = 'rgba(0,0,0,0.025)';
                    dc.classList.add('weekend');
                }
                daysRow.appendChild(dc);
            }
            cursor.setMonth(cursor.getMonth() + 1, 1);
        }

        timelineHeaderWrap.append(monthsRow, daysRow);
        thTimeline.appendChild(timelineHeaderWrap);
        headerRow.appendChild(thTimeline);
        thead.appendChild(headerRow);
        table.appendChild(thead);

        // ========== TBODY: righe task ==========
        const tbody = document.createElement('tbody');
        const today = clampDate(new Date());

        const mainTasks = [], subtasks = [];
        state.events.forEach(ev => (ev.meta?.taskType === 'subtask' ? subtasks : mainTasks).push(ev));

        const parentRawById = {};
        mainTasks.forEach(mt => { parentRawById[String(mt.id)] = (mt.meta?.raw || {}); });

        const subtasksByParent = {};
        subtasks.forEach(st => {
            const p = st.meta?.parentTaskId;
            if (!p) return;
            (subtasksByParent[p] ||= []).push(st);
        });

        const allTasks = [];
        mainTasks.forEach(mainTask => {
            allTasks.push(mainTask);
            (subtasksByParent[mainTask.id] || []).forEach(st => allTasks.push(st));
        });

        allTasks.forEach(ev => {
            const s = parseDate(ev.start);
            const e = ev.end ? parseDate(ev.end) : null;
            if (!s) return;
            const sClamped = s < start ? start : s;
            const eCalc = e || s;
            const eClamped = eCalc > end ? end : eCalc;
            if (eCalc < start || s > end) return;

            const isSubtask = ev.meta?.taskType === 'subtask';
            const hasSubtasks = !!(subtasksByParent[ev.id] && subtasksByParent[ev.id].length);

            // ROW (TR)
            const tr = document.createElement('tr');
            tr.className = isSubtask ? 'gv-subtask-row' : 'gv-main-task-row';
            if (isSubtask) {
                tr.setAttribute('data-parent-task-id', ev.meta?.parentTaskId || '');
                tr.setAttribute('data-subtask-id', ev.id);
            } else {
                tr.setAttribute('data-main-task-id', ev.id);
            }

            // Celle colonne fisse (TD) — garantisce che la label sia realmente nella colonna titolo
            columns.forEach((col, idx) => {
                const td = document.createElement('td');
                td.style.padding = '6px 12px';
                td.style.borderRight = '1px solid #eee';
                td.style.borderBottom = '1px solid #eee';
                td.style.whiteSpace = 'nowrap';
                td.style.overflow = 'hidden';
                td.style.textOverflow = 'ellipsis';

                let value = getMappedValue(ev, col.key, col);
                if (col.key === 'id' && isSubtask) {
                    const pId = ev.meta?.parentTaskId ? String(ev.meta.parentTaskId) : '';
                    const pRaw = pId ? parentRawById[pId] : null;
                    if (pRaw) value = getMappedFromRaw(pRaw, 'id') || String(pRaw.id ?? value ?? '');
                }

                if (col.isTitle) {
                    // Wrapper label *dentro la cella della colonna titolo* (allineata al TH)
                    const wrap = dce('div', 'gv-task-label');
                    wrap.style.display = 'flex';
                    wrap.style.alignItems = 'center';
                    wrap.style.gap = '6px';
                    wrap.style.minWidth = '0';
                    wrap.style.cursor = 'pointer';
                    // tooltip accessibile (usa sempre data-tooltip)
                    wrap.setAttribute('data-tooltip', String(ev.title || value || '').trim() || 'Dettagli task');

                    // Se manca il valore, fallback al title evento
                    if (!value) value = String(ev.title || '(Senza titolo)');

                    // Toggle (solo per main task con sottotask)
                    if (!isSubtask && hasSubtasks) {
                        const toggleIcon = dce('span', 'gv-toggle-icon');
                        toggleIcon.textContent = '▼';
                        toggleIcon.style.cursor = 'pointer';
                        toggleIcon.style.flexShrink = '0';
                        toggleIcon.style.fontSize = '12px';
                        toggleIcon.style.color = '#007bff';
                        toggleIcon.setAttribute('data-parent-id', ev.id);
                        toggleIcon.setAttribute('data-toggle-state', 'expanded');
                        wrap.appendChild(toggleIcon);
                    } else if (isSubtask) {
                        const indent = dce('span', 'gv-subtask-indent');
                        indent.textContent = '↳';
                        indent.style.flexShrink = '0';
                        wrap.appendChild(indent);
                    }

                    // Testo della label (ellissi controllata dentro la cella)
                    const titleEl = dce('span', 'gv-task-title');
                    titleEl.textContent = value;
                    titleEl.style.overflow = 'hidden';
                    titleEl.style.textOverflow = 'ellipsis';
                    titleEl.style.whiteSpace = 'nowrap';
                    titleEl.style.minWidth = '0';
                    wrap.appendChild(titleEl);

                    td.appendChild(wrap);
                } else {
                    td.textContent = (value && String(value).trim()) ? value : '—';
                }

                // Append cella alla riga
                tr.appendChild(td);
            });

            // Cella timeline (TD): LARGHEZZA FISSA
            const tdTimeline = document.createElement('td');
            tdTimeline.style.padding = '0';
            tdTimeline.style.borderBottom = '1px solid #eee';
            tdTimeline.style.position = 'relative';
            tdTimeline.style.width = `${timelinePx}px`;
            tdTimeline.style.minWidth = `${timelinePx}px`;
            tdTimeline.style.maxWidth = `${timelinePx}px`;
            tdTimeline.style.overflow = 'hidden';

            const lane = dce('div', 'gv-task-lane');
            lane.style.position = 'relative';
            lane.style.width = `${timelinePx}px`;
            lane.style.minWidth = `${timelinePx}px`;
            lane.style.maxWidth = `${timelinePx}px`;
            lane.style.height = '32px';
            lane.style.overflow = 'hidden';
            lane.style.background = `repeating-linear-gradient(to right,
                rgba(0,0,0,0.04) 0,
                rgba(0,0,0,0.04) 1px,
                transparent 1px,
                transparent ${colWidth}px)`;

            // Barra task
            const bar = dce('div', 'gv-bar');
            if (ev.color) bar.style.setProperty('--gv-bar-color', ev.color);
            const startISO = fmtISO(s), endISO = fmtISO(eCalc);
            const ttParts = [String(ev.title || '').trim()];
            if (s < start) ttParts.push(`Inizio: ${startISO} (prima del range)`);
            else ttParts.push(`Inizio: ${fmtISO(sClamped)}`);
            if (e) {
                if (eCalc > end) ttParts.push(`Fine: ${endISO} (dopo il range)`);
                else ttParts.push(`Fine: ${fmtISO(eClamped)}`);
            }
            bar.setAttribute('data-tooltip', ttParts.join(' • '));
            if (s < start) bar.classList.add('cut-left');
            if (eCalc > end) bar.classList.add('cut-right');

            const offsetDays = daysDiff(start, sClamped);
            const spanDays = Math.max(1, 1 + daysDiff(sClamped, eClamped));
            bar.style.position = 'absolute';
            bar.style.top = '5px';
            bar.style.left = (offsetDays * colWidth) + 'px';
            bar.style.width = (spanDays * colWidth - 4) + 'px';
            bar.style.height = '22px';

            if (ev.url) {
                bar.classList.add('gv-link');
                bar.addEventListener('click', () => { window.location.href = ev.url; });
            }

            // DnD
            if (canMoveEvent(ev)) {
                bar.setAttribute('draggable', 'true');
                bar.addEventListener('dragstart', (e2) => {
                    e2.dataTransfer.setData('application/x-ev', JSON.stringify({ id: String(ev.id) }));
                    e2.dataTransfer.setData('text/plain', '');
                    bar.classList.add('dragging');
                    document.body.classList.add('gv-dragging');
                });
                bar.addEventListener('dragend', () => {
                    bar.classList.remove('dragging');
                    document.body.classList.remove('gv-dragging');
                });
                document.querySelectorAll('.gv-days .gv-day').forEach(dayCell => {
                    dayCell.addEventListener('dragover', (e2) => {
                        if (e2.dataTransfer?.types?.includes('application/x-ev')) {
                            e2.preventDefault();
                            dayCell.classList.add('gv-drop');
                        }
                    });
                    dayCell.addEventListener('dragleave', () => dayCell.classList.remove('gv-drop'));
                    dayCell.addEventListener('drop', async (e2) => {
                        e2.preventDefault();
                        dayCell.classList.remove('gv-drop');
                        const json = e2.dataTransfer.getData('application/x-ev');
                        if (!json) return;
                        let data = null;
                        try { data = JSON.parse(json); } catch { }
                        if (!data?.id) return;
                        const targetISO = dayCell.dataset.date;
                        if (!targetISO) return;
                        if (String(ev.start).slice(0, 10) === String(targetISO)) return;
                        await moveEventDate(ev, targetISO, state);
                    });
                });
            }

            lane.appendChild(bar);
            tdTimeline.appendChild(lane);
            tr.appendChild(tdTimeline);
            tbody.appendChild(tr);
        });

        table.appendChild(tbody);

        // Linea "oggi" — sincronizzata con lo scroll orizzontale dello scroller
        if (today >= start && today <= end && state.showTodayLine !== false) {
            const todayWrapper = dce('div', 'gv-today-wrapper');
            todayWrapper.style.position = 'absolute';
            todayWrapper.style.top = '0';
            todayWrapper.style.left = '0';
            todayWrapper.style.width = '100%';
            todayWrapper.style.height = '100%';
            todayWrapper.style.pointerEvents = 'none';
            todayWrapper.style.zIndex = '10';

            const todayLine = dce('div', 'gv-today');
            const offs = daysDiff(start, today);
            const todayBaseLeft = LEFT_TOTAL + (offs * colWidth);

            // Posizione base (senza scroll)
            todayLine.style.left = `${todayBaseLeft}px`;
            todayWrapper.appendChild(todayLine);
            wrapper.appendChild(todayWrapper);

            // Sync con scroll: mantieni la linea esattamente sopra il giorno corrente
            const syncToday = () => {
                const sl = scroller.scrollLeft || 0;
                // compensiamo lo scroll muovendo la linea in senso opposto
                todayLine.style.transform = `translateX(${-sl}px)`;
            };
            // primo sync e listener
            syncToday();
            scroller.addEventListener('scroll', syncToday, { passive: true });
        }

        wrapper.appendChild(table);
        scroller.appendChild(wrapper);
        return scroller;
    }

    function render(state) {
        const { start, end, header } = buildRange(state.anchor, state.range);
        state.header.titleEl.textContent =
            `${header}: ${start.getDate()}/${pad2(start.getMonth() + 1)}/${start.getFullYear()} – ${end.getDate()}/${pad2(end.getMonth() + 1)}/${end.getFullYear()}`;
        state.header.setActive();

        state.body.innerHTML = '';

        if (state.loading) {
            const sp = dce('div', 'gv-loading');
            sp.textContent = 'Caricamento…';
            state.body.appendChild(sp);
            return;
        }

        const scroller = buildGrid(state, state.body);
        state.body.appendChild(scroller);

        if (!state.events || state.events.length === 0) {
            const hint = dce('div', 'gv-empty-hint');
            hint.textContent = 'Nessun elemento da mostrare con i filtri correnti.';
            state.body.appendChild(hint);
        }

        // Ripristina stati toggle dopo OGNI render (necessario perché ricostruiamo il DOM)
        setTimeout(() => window.restoreSubtaskStates?.('gantt'), 10);
    }

    async function loadEvents(state) {
        state.loading = true; render(state);
        try {
            const out = await state.provider();
            if (!Array.isArray(out)) throw new Error('Formato eventi non valido (atteso Array).');

            // normalizzazione minima
            const normalized = out.map(e => ({
                id: e?.id ?? null,
                title: String(e?.title || '').trim(),
                start: e?.start ?? null,
                end: e?.end ?? null,
                status: e?.status ?? null,
                url: e?.url ?? null,
                color: e?.color ?? null,
                meta: (typeof e?.meta === 'object' && e.meta) ? e.meta : {}
            }));

            // tieni solo eventi con start parseabile
            const parsed = normalized.filter(e => !!parseDate(e.start));
            // dedup per id
            const seen = new Set();
            state.events = parsed.filter(e => {
                const k = String(e.id);
                if (seen.has(k)) return false;
                seen.add(k);
                return true;
            });
        } catch (err) {
            state.events = [];
        } finally {
            state.loading = false;
            render(state);
        }
    }

    function mount(container, state) {
        container.innerHTML = '';
        const header = buildHeader(state);
        const body = dce('div', 'gv-body-wrap');
        container.append(header.el, body);
        state.header = header;
        state.body = body;
    }

    const GanttView = {
        _state: null,
        init(opts = {}) {
            const containerId = opts.containerId || 'gantt-view';
            const el = document.getElementById(containerId);
            if (!el) return;

            const provider =
                (typeof opts.provider === 'function' && opts.provider) ||
                (typeof window.calendarDataProvider === 'function' && window.calendarDataProvider) ||
                (async () => []); // se manca, niente crash

            const allowed = new Set(['month', 'quarter', 'year']);
            const range = allowed.has(opts.range) ? opts.range : 'month';

            const state = {
                container: el,
                provider,
                range,
                anchor: new Date(),
                events: [],
                loading: false,
                header: null,
                body: null,
                showTodayLine: opts.showTodayLine !== false
            };
            this._state = state;

            // 🔄 Ridisegna quando cambia la larghezza *del container* (più affidabile del window resize)
            if (!state._resizeObs) {
                const ro = new ResizeObserver(() => {
                    try { render(state); } catch (e) { console.warn('Resize render error', e); }
                });
                ro.observe(el);
                state._resizeObs = ro;
            }

            mount(el, state);
            loadEvents(state);
        },
        setProvider(fn) {
            if (typeof fn !== 'function') return;
            if (!this._state) this._state = { anchor: new Date(), range: 'month' };
            this._state.provider = fn;
        },
        refresh() {
            if (!this._state) return;
            loadEvents(this._state);
        }
    };

    window.addEventListener('calendar:state', (e) => {
        if (!e?.detail || !GanttView?._state) return;
        applyCalendarStateToGantt(e.detail, GanttView._state);
        render(GanttView._state);
    });
    window.GanttView = GanttView;

    document.addEventListener('dragend', () => document.body.classList.remove('gv-dragging'));

    // --- sync con filtri globali (lista/calendario) ---
    window.addEventListener('filters:changed', function () {
        const st = window.GanttView?._state;
        if (!st) return;

        try {
            const f = (window.getActiveFilters && window.getActiveFilters()) || {};
            if (f.from) {
                const p = f.from.split('-');
                if (p.length === 3) st.anchor = new Date(+p[0], +p[1] - 1, +p[2]);
            }
            if (f.from && f.to) {
                const pf = f.from.split('-'), pt = f.to.split('-');
                const from = new Date(+pf[0], +pf[1] - 1, +pf[2]);
                const to = new Date(+pt[0], +pt[1] - 1, +pt[2]);
                const span = Math.max(1, Math.floor((to - from) / 86400000) + 1);
                st.range = span > 250 ? 'year' : (span > 90 ? 'quarter' : 'month');
            }
        } catch { }

        if (typeof window.GanttView?.refresh === 'function') window.GanttView.refresh();
    });

    // Toggle subtasks usa toggleSubtasks globale da main_core.js
    window.GanttView = window.GanttView || {};
    window.GanttView.toggleSubtasks = (parentId) => window.toggleSubtasks(parentId, 'gantt');
    window.GanttView.restoreSubtaskStates = () => window.restoreSubtaskStates('gantt');

    // Listener globale per click su toggle/label (registrato UNA SOLA VOLTA)
    if (!window.__ganttToggleListenerAttached) {
        window.__ganttToggleListenerAttached = true;
        document.addEventListener('click', (e) => {
            const icon = e.target.closest('.gv-toggle-icon');
            const label = e.target.closest('.gv-task-label');
            if (!icon && !label) return;

            const parentId = icon?.getAttribute('data-parent-id') ||
                label?.querySelector('.gv-toggle-icon')?.getAttribute('data-parent-id');
            if (parentId) {
                e.stopPropagation();
                window.toggleSubtasks(parentId, 'gantt');
            }
        });
    }
})();
