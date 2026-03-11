// Calendario riusabile, zero dipendenze, CSP safe, tooltip via data-tooltip
// API:
//   CalendarView.init({ containerId: 'calendar-view', provider: async()=>Event[], view: 'month'|'week'|'list' })
//   CalendarView.setProvider(fn)
//   CalendarView.refresh()

(function () {
    if (window.CalendarView) return; // evita doppie init

    // --------- utils ----------
    const dce = (tag, cls) => {
        const el = document.createElement(tag);
        if (cls) el.className = cls;
        return el;
    };
    const fmtDate = (d) => {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const da = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${da}`;
    };
    const parseDate = (v) => {
        if (!v) return null;
        const s = String(v).trim();

        // YYYY-MM-DD[ T]HH:mm[:ss]?
        let m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
        if (m) {
            // Costruiamo SEMPRE in locale, evitando il parser nativo (UTC) per le date senza timezone.
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

        // Altre forme non supportate → null (meglio che interpretazioni errate)
        return null;
    };
    const TODAY_KEY = fmtDate(new Date());
    const getCurrentUserId = () => String(window.CURRENT_USER?.id || window.CURRENT_USER?.user_id || '');
    const isAdmin = () => (typeof window.isCurrentUserAdmin === 'function'
        ? window.isCurrentUserAdmin()
        : String(window.CURRENT_USER?.role_id) === '1');

    function canMoveEvent(ev) {
        const raw = ev?.meta?.raw || {};
        const uid = getCurrentUserId();
        const assegnato = String(raw.assegnato_a || '');
        const responsabile = String(raw.responsabile || '');
        const creatore = String(raw.submitted_by || raw.creato_da_id || '');
        return isAdmin() || [assegnato, responsabile, creatore].includes(uid);
    }
    function isoFromDate(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const da = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${da}`;
    }

    function openEvent(ev) {
        console.log('openEvent called', ev);
        const kind = ev.extendedProps?.kind || ev.meta?.kind;
        const raw = ev.meta || ev.extendedProps || {};

        console.log('openEvent kind:', kind);
        console.log('openEvent raw:', raw);
        console.log('MomDetails available:', !!window.MomDetails);

        // Dispatcher
        if (kind === 'mom') {
            const momId = raw.mom_id; // MomService sends mom_id
            console.log('openEvent momId:', momId);
            if (momId && window.MomDetails) {
                console.log('Opening MomDetails...');
                window.MomDetails.open(momId);
                return;
            } else {
                console.warn('MomDetails not opened. Missing ID or Component.');
            }
        } else if (kind === 'task') {
            // Task context
            const taskId = raw.id || ev.id;
            if (taskId && window.TaskDetails) {
                window.TaskDetails.open(taskId);
                return;
            }
        }

        // Check URL for fallback inference
        if (!kind && ev.url) {
            console.log('Attempting to infer kind from URL:', ev.url);
            if (ev.url.includes('page=mom') && ev.url.includes('action=view')) {
                const match = ev.url.match(/id=(\d+)/);
                if (match && match[1]) {
                    console.log('Inferred MOM event, ID:', match[1]);
                    if (window.MomDetails) {
                        window.MomDetails.open(match[1]);
                        return;
                    }
                }
            }
            // Add other inferences here if needed (e.g. for Tasks)
        }

        // Fallback
        if (ev.url) {
            console.log('Falling back to URL navigation');
            window.location.href = ev.url;
        }
    }
    async function moveEventDate(ev, newDateISO, state, { refreshAfter = true } = {}) {
        try {
            const raw = ev?.meta?.raw || {};
            const payload = {
                // usa l'id numerico originale dal server (NON l'id globalizzato del client)
                form_id: String(raw.id ?? ev.id),
                table_name: raw.table_name || raw.table || undefined,
                field: 'data_scadenza',
                value: newDateISO
            };
            if (!payload.form_id || !payload.value) return { success: false, message: 'Payload non valido' };

            const res = await customFetch('forms', 'updateDate', payload);
            if (res?.success) {
                // update ottimistico locale
                ev.start = newDateISO;
                if (state) {
                    state.eventsByDay = indexEventsByDay(state.events || []);
                    render(state);
                }
                if (refreshAfter && typeof CalendarView?.refresh === 'function') {
                    // piccolo debounce per evitare flicker
                    setTimeout(() => CalendarView.refresh(), 50);
                }
                if (typeof window.showToast === 'function') showToast('Scadenza aggiornata', 'success');
            } else {
                if (typeof window.showToast === 'function') showToast('Errore aggiornamento data', 'error');
            }
            return res;
        } catch (e) {
            if (typeof window.showToast === 'function') showToast('Errore di rete durante updateDate', 'error');
            return { success: false, message: e.message };
        }
    }

    // --------- header ----------
    function buildHeader(state) {
        const wrap = dce('div', 'cv-header');

        // PREV
        const btnPrev = dce('button', 'cv-btn');
        btnPrev.type = 'button';
        btnPrev.textContent = '‹';
        btnPrev.setAttribute('data-tooltip', 'Periodo precedente');
        btnPrev.addEventListener('click', () => {
            if (state.view === 'month') {
                const d = new Date(state.currentDate);
                d.setDate(1);            // fix rollover
                d.setMonth(d.getMonth() - 1);
                state.currentDate = d;
            } else if (state.view === 'week') {
                state.currentDate.setDate(state.currentDate.getDate() - 7);
            }
            render(state);
        });

        // TODAY
        const btnToday = dce('button', 'cv-btn');
        btnToday.type = 'button';
        btnToday.textContent = 'Oggi';
        btnToday.setAttribute('data-tooltip', 'Oggi');
        btnToday.addEventListener('click', () => {
            state.currentDate = new Date();
            render(state);
            try {
                const key = fmtDate(state.currentDate);
                state.container?.querySelector?.(`[data-date="${key}"]`)
                    ?.scrollIntoView?.({ block: 'nearest', inline: 'center' });
            } catch { }
        });

        // NEXT
        const btnNext = dce('button', 'cv-btn');
        btnNext.type = 'button';
        btnNext.textContent = '›';
        btnNext.setAttribute('data-tooltip', 'Periodo successivo');
        btnNext.addEventListener('click', () => {
            if (state.view === 'month') {
                const d = new Date(state.currentDate);
                d.setDate(1);            // fix rollover
                d.setMonth(d.getMonth() + 1);
                state.currentDate = d;
            } else if (state.view === 'week') {
                state.currentDate.setDate(state.currentDate.getDate() + 7);
            }
            render(state);
        });

        const title = dce('div', 'cv-title');
        const switcher = dce('div', 'cv-switcher');

        const vWeek = dce('button', 'cv-btn'); vWeek.type = 'button'; vWeek.textContent = 'Settimana';
        vWeek.setAttribute('data-tooltip', 'Vista settimana');
        const vMonth = dce('button', 'cv-btn'); vMonth.type = 'button'; vMonth.textContent = 'Mese';
        vMonth.setAttribute('data-tooltip', 'Vista mese');
        const vYear = dce('button', 'cv-btn'); vYear.type = 'button'; vYear.textContent = 'Anno';
        vYear.setAttribute('data-tooltip', 'Vista anno (12 mesi)');
        const vList = dce('button', 'cv-btn'); vList.type = 'button'; vList.textContent = 'Lista';
        vList.setAttribute('data-tooltip', 'Vista lista');

        const setActive = () => {
            [vWeek, vMonth, vYear, vList].forEach(b => b.classList.remove('active'));
            if (state.view === 'week') vWeek.classList.add('active');
            else if (state.view === 'month') vMonth.classList.add('active');
            else if (state.view === 'year') vYear.classList.add('active');
            else vList.classList.add('active');
        };

        vWeek.addEventListener('click', () => { state.view = 'week'; render(state); });
        vMonth.addEventListener('click', () => { state.view = 'month'; render(state); });
        vYear.addEventListener('click', () => { state.view = 'year'; render(state); });
        vList.addEventListener('click', () => { state.view = 'list'; render(state); });

        switcher.append(vWeek, vMonth, vYear, vList);

        // Quick filters (invariato)
        const quickWrap = dce('div', 'cv-quick-filters');
        const quickSel = dce('select', 'cv-select');
        quickSel.id = 'calendar-quick-filter';
        quickSel.setAttribute('data-tooltip', 'Filtra eventi');
        [['all', 'Mostra: Tutti'], ['assigned', 'Solo Assegnati a me'], ['responsabile', 'Solo Responsabile'], ['open', 'Solo Stato Aperto']]
            .forEach(([val, label]) => { const opt = dce('option'); opt.value = val; opt.textContent = label; quickSel.appendChild(opt); });
        const initialQuick = String((window.appliedFilters && window.appliedFilters.quickFilter) || 'all');
        quickSel.value = initialQuick;
        quickSel.addEventListener('change', () => {
            const filtri = (typeof window.appliedFilters === 'object' && window.appliedFilters) ? { ...window.appliedFilters } : {};
            filtri.quickFilter = quickSel.value;
            window.appliedFilters = filtri;
            if (typeof CalendarView?.refresh === 'function') CalendarView.refresh();
            if (typeof window.loadSegnalazioni === 'function') loadSegnalazioni(filtri);
        });
        quickWrap.appendChild(quickSel);

        wrap.append(btnPrev, btnToday, btnNext, title, switcher, quickWrap);
        return { el: wrap, titleEl: title, setActive };
    }

    // --------- month ribbon (12 mesi) ----------
    function buildMonthsBar(state) {
        const bar = dce('div', 'cv-months');
        const year = state.currentDate.getFullYear();
        const months = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

        // timer + indice del mese attualmente "hovered" in drag
        let monthHoverTimer = null;
        let hoveredIdx = null;

        const isDragging = () => document.body.classList.contains('cv-dragging');

        const startSwitchTimer = (idx, el) => {
            if (monthHoverTimer) clearTimeout(monthHoverTimer);
            monthHoverTimer = setTimeout(() => {
                // possiamo aver smesso di trascinare nel frattempo
                if (!isDragging()) return;
                const d = new Date(state.currentDate);
                d.setMonth(idx);
                d.setDate(1);
                state.currentDate = d;
                render(state);
            }, 800); // ~0.8s reattivo ma non nervoso
            el.classList.add('cv-drop');
        };

        const clearSwitchTimer = (el) => {
            if (monthHoverTimer) { clearTimeout(monthHoverTimer); monthHoverTimer = null; }
            if (el) el.classList.remove('cv-drop');
            hoveredIdx = null;
        };

        months.forEach((lbl, idx) => {
            const m = dce('div', 'cv-month');
            m.textContent = lbl;
            m.dataset.month = String(idx);
            m.setAttribute('data-tooltip', `Vai a ${lbl} ${year}`);
            if (idx === state.currentDate.getMonth()) m.classList.add('active');

            // click normale: cambio mese e rerender
            m.addEventListener('click', () => {
                if (isDragging()) return; // durante drag ignoriamo i click
                const d = new Date(state.currentDate);
                d.setMonth(idx);
                d.setDate(1);
                state.currentDate = d;
                render(state);
            });

            // Durante drag: usiamo DRAGOVER (mouseenter non sempre scatta in drag)
            m.addEventListener('dragover', (e) => {
                if (!isDragging()) return;
                e.preventDefault(); // evita cursore "vietato"
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';

                // se sto passando da un mese all'altro, resetto il timer
                if (hoveredIdx !== idx) {
                    // rimuovi highlight dal vecchio
                    if (hoveredIdx !== null) {
                        const old = bar.querySelector(`.cv-month[data-month="${hoveredIdx}"]`);
                        if (old) old.classList.remove('cv-drop');
                    }
                    hoveredIdx = idx;

                    // se è già il mese corrente non serve timer
                    if (idx === state.currentDate.getMonth()) {
                        clearSwitchTimer(); // nessun highlight
                    } else {
                        startSwitchTimer(idx, m);
                    }
                }
            });

            // se esco dal mese con il drag
            m.addEventListener('dragleave', () => {
                // se sto lasciando proprio questo mese, pulisco
                if (hoveredIdx === idx) clearSwitchTimer(m);
            });

            bar.appendChild(m);
        });

        // cleanup generale quando finisce un drag (ovunque)
        document.addEventListener('dragend', () => {
            clearSwitchTimer(bar.querySelector(`.cv-month[data-month="${hoveredIdx}"]`));
            bar.querySelectorAll('.cv-month.cv-drop').forEach(el => el.classList.remove('cv-drop'));
        });

        return bar;
    }

    // --------- view renderers ----------
    function renderMonth(state, body) {
        body.innerHTML = '';
        if (!state.eventsByDay || typeof state.eventsByDay !== 'object') {
            state.eventsByDay = indexEventsByDay(state.events || []);
        }

        const year = state.currentDate.getFullYear();
        const month = state.currentDate.getMonth();
        const first = new Date(year, month, 1);
        const startDay = (first.getDay() + 6) % 7; // lun=0 ... dom=6
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        // ribbon mesi sopra i giorni
        const monthsBar = buildMonthsBar(state);
        body.appendChild(monthsBar);

        // header giorni
        const dow = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
        const head = dce('div', 'cv-grid cv-head');
        dow.forEach(d => {
            const c = dce('div', 'cv-cell cv-dow');
            c.textContent = d;
            head.appendChild(c);
        });
        body.appendChild(head);

        // griglia giorni
        const grid = dce('div', 'cv-grid cv-days');

        for (let i = 0; i < startDay; i++) grid.appendChild(dce('div', 'cv-cell cv-empty'));

        for (let day = 1; day <= daysInMonth; day++) {
            const dateObj = new Date(year, month, day);
            const thisKey = fmtDate(dateObj);
            const cell = dce('div', 'cv-cell cv-day');
            cell.setAttribute('data-date', thisKey);
            if (thisKey === TODAY_KEY) cell.classList.add('cv-today');

            cell.addEventListener('dragover', (e) => {
                if (e.dataTransfer?.types?.includes('application/x-ev')) { e.preventDefault(); cell.classList.add('cv-drop'); }
            });
            cell.addEventListener('dragleave', () => cell.classList.remove('cv-drop'));
            cell.addEventListener('drop', async (e) => {
                e.preventDefault();
                cell.classList.remove('cv-drop');

                // data effettiva della cella dove è avvenuto il drop
                const targetCell = e.currentTarget || e.target.closest('.cv-cell');
                const targetKey = (targetCell && targetCell.getAttribute('data-date')) ? targetCell.getAttribute('data-date') : thisKey;

                const json = e.dataTransfer.getData('application/x-ev'); if (!json) return;
                let data = null; try { data = JSON.parse(json); } catch { }
                if (!data?.id) return;
                const ev = state.events.find(x => String(x.id) === String(data.id)); if (!ev) return;

                if (!canMoveEvent(ev)) {
                    if (isAdmin() && typeof window.showConfirm === 'function') {
                        return showConfirm(
                            'Stai per modificare una scadenza come amministratore.<br>Non sei né assegnatario né responsabile.<br>Vuoi procedere?',
                            () => moveEventDate(ev, targetKey, state, { refreshAfter: true }),
                            { allowHtml: true }
                        );
                    }
                    if (typeof window.showToast === 'function') showToast('Non hai i permessi per spostare questo evento.', 'error');
                    return;
                }
                if (String(ev.start).slice(0, 10) === String(targetKey)) return; // nessuna modifica

                await moveEventDate(ev, targetKey, state, { refreshAfter: true });
            });

            const label = dce('div', 'cv-day-label'); label.textContent = String(day); cell.appendChild(label);

            const bucket = state.eventsByDay[thisKey] || [];
            const list = dce('ul', 'cv-ev-list');

            bucket.slice(0, 3).forEach(ev => {
                const li = dce('li', 'cv-ev');
                li.dataset.id = String(ev.id);
                if (ev.color) li.style.setProperty('--cv-ev-color', ev.color);
                li.textContent = ev.title;
                li.setAttribute('data-tooltip', ev.title);

                if (ev.url || ev.extendedProps?.kind || ev.meta?.kind) {
                    li.addEventListener('click', () => openEvent(ev));
                    li.classList.add('cv-ev-link');
                }

                if (canMoveEvent(ev)) {
                    li.setAttribute('draggable', 'true');
                    li.addEventListener('dragstart', (e) => {
                        // payload custom + compat Firefox
                        e.dataTransfer.setData('application/x-ev', JSON.stringify({ id: String(ev.id) }));
                        e.dataTransfer.setData('text/plain', ''); // <— compat Firefox richiede almeno un tipo di testo
                        e.dataTransfer.effectAllowed = 'move';
                        li.classList.add('dragging');
                        document.body.classList.add('cv-dragging');
                    });
                    li.addEventListener('dragend', () => {
                        li.classList.remove('dragging');
                        document.body.classList.remove('cv-dragging');
                    });
                }

                list.appendChild(li);
            });

            if (bucket.length > 3) {
                const more = dce('div', 'cv-more');
                more.textContent = `+${bucket.length - 3} altro/i`;
                more.setAttribute('data-tooltip', 'Mostra tutti');
                more.addEventListener('click', () => openDayModal(state, thisKey, bucket));
                cell.appendChild(more);
            }

            cell.appendChild(list);
            grid.appendChild(cell);
        }
        body.appendChild(grid);
    }

    function renderWeek(state, body) {
        body.innerHTML = '';

        if (!state.eventsByDay || typeof state.eventsByDay !== 'object') {
            state.eventsByDay = indexEventsByDay(state.events || []);
        }

        // Calcolo settimana corrente (lun-dom)
        const ref = new Date(state.currentDate);
        const theDay = (ref.getDay() + 6) % 7; // lun=0
        const monday = new Date(ref); monday.setDate(ref.getDate() - theDay);

        // Wrapper verticale scrollabile (non sfora nel Gantt)
        const wrap = dce('div', 'cv-week'); // usiamo stessa classe ma con nuovo layout in CSS
        wrap.setAttribute('role', 'list');

        for (let i = 0; i < 7; i++) {
            const d = new Date(monday); d.setDate(monday.getDate() + i);
            const key = fmtDate(d);

            // Card giorno verticale
            const dayCard = dce('section', 'cv-week-col');
            dayCard.setAttribute('data-date', key);
            dayCard.setAttribute('role', 'listitem');

            if (key === TODAY_KEY) dayCard.classList.add('cv-today');

            // Header giorno
            const head = dce('div', 'cv-week-head');
            const weekday = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'][i];
            head.textContent = `${weekday} ${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
            head.setAttribute('data-tooltip', `Giorno: ${head.textContent}`);
            dayCard.appendChild(head);

            // Drop target sul contenitore del giorno
            dayCard.addEventListener('dragover', (e) => {
                if (e.dataTransfer?.types?.includes('application/x-ev')) {
                    e.preventDefault();
                    dayCard.classList.add('cv-drop');
                }
            });
            dayCard.addEventListener('dragleave', () => dayCard.classList.remove('cv-drop'));
            dayCard.addEventListener('drop', async (e) => {
                e.preventDefault();
                dayCard.classList.remove('cv-drop');

                const target = e.currentTarget || e.target.closest('.cv-week-col');
                const targetKey = target?.getAttribute('data-date') || key;

                const json = e.dataTransfer.getData('application/x-ev'); if (!json) return;
                let data = null; try { data = JSON.parse(json); } catch { }
                if (!data?.id) return;

                const ev = state.events.find(x => String(x.id) === String(data.id)); if (!ev) return;

                if (!canMoveEvent(ev)) {
                    if (isAdmin() && typeof window.showConfirm === 'function') {
                        return showConfirm(
                            'Stai per modificare una scadenza come amministratore.<br>Non sei né assegnatario né responsabile.<br>Vuoi procedere?',
                            () => moveEventDate(ev, targetKey, state, { refreshAfter: true }),
                            { allowHtml: true }
                        );
                    }
                    if (typeof window.showToast === 'function') showToast('Non hai i permessi per spostare questo evento.', 'error');
                    return;
                }
                if (String(ev.start).slice(0, 10) === String(targetKey)) return;

                await moveEventDate(ev, targetKey, state, { refreshAfter: true });
            });

            // Lista eventi del giorno
            const items = state.eventsByDay[key] || [];
            if (!items.length) {
                const empty = dce('div', 'cv-week-ev cv-week-empty');
                empty.textContent = '— Nessun evento —';
                empty.style.opacity = '0.65';
                dayCard.appendChild(empty);
            } else {
                items.forEach(ev => {
                    const it = dce('div', 'cv-week-ev');
                    if (ev.color) it.style.setProperty('--cv-ev-color', ev.color);
                    it.textContent = ev.title;
                    it.setAttribute('data-tooltip', ev.title);

                    if (ev.url || ev.extendedProps?.kind || ev.meta?.kind) {
                        it.addEventListener('click', () => openEvent(ev));
                        it.classList.add('cv-ev-link');
                    }

                    if (canMoveEvent(ev)) {
                        it.setAttribute('draggable', 'true');
                        it.addEventListener('dragstart', (e) => {
                            e.dataTransfer.setData('application/x-ev', JSON.stringify({ id: String(ev.id) }));
                            e.dataTransfer.setData('text/plain', ''); // compat Firefox
                            e.dataTransfer.effectAllowed = 'move';
                            it.classList.add('dragging');
                            document.body.classList.add('cv-dragging');
                        });
                        it.addEventListener('dragend', () => {
                            it.classList.remove('dragging');
                            document.body.classList.remove('cv-dragging');
                        });
                    }

                    // hard cap visivo: ellissi già da CSS, qui assicuriamo no-wrap
                    it.style.whiteSpace = 'nowrap';
                    it.style.overflow = 'hidden';
                    it.style.textOverflow = 'ellipsis';

                    dayCard.appendChild(it);
                });
            }

            wrap.appendChild(dayCard);
        }

        body.appendChild(wrap);
    }

    function renderList(state, body) {
        body.innerHTML = '';
        const list = dce('div', 'cv-list');
        const keys = Object.keys(state.eventsByDay).sort();
        if (!keys.length) {
            const empty = dce('div', 'cv-empty-hint'); empty.textContent = 'Nessun evento.'; list.appendChild(empty);
        }
        keys.forEach(k => {
            const dayBox = dce('div', 'cv-list-day');
            const head = dce('div', 'cv-list-head');
            const d = parseDate(k);
            head.textContent = d ? `${d.getDate()}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}` : k;
            dayBox.appendChild(head);

            (state.eventsByDay[k] || []).forEach(ev => {
                const row = dce('div', 'cv-list-ev'); if (ev.color) row.style.setProperty('--cv-ev-color', ev.color);
                const time = dce('div', 'cv-list-time');
                const s = parseDate(ev.start); const e = ev.end ? parseDate(ev.end) : null;
                time.textContent = s ? (e ? `${String(s.getHours()).padStart(2, '0')}:${String(s.getMinutes()).padStart(2, '0')}–${String(e.getHours()).padStart(2, '0')}:${String(e.getMinutes()).padStart(2, '0')}` : `${String(s.getHours()).padStart(2, '0')}:${String(s.getMinutes()).padStart(2, '0')}`) : '';
                const title = dce('div', 'cv-list-title'); title.textContent = ev.title; title.setAttribute('data-tooltip', ev.title);
                row.append(time, title);
                if (ev.url || ev.extendedProps?.kind || ev.meta?.kind) {
                    row.classList.add('cv-ev-link');
                    row.addEventListener('click', () => openEvent(ev));
                }
                dayBox.appendChild(row);
            });
            list.appendChild(dayBox);
        });
        body.appendChild(list);
    }

    function renderYear(state, body) {
        body.innerHTML = '';

        // indicizza eventi per giorno (già lo facciamo)
        if (!state.eventsByDay || typeof state.eventsByDay !== 'object') {
            state.eventsByDay = indexEventsByDay(state.events || []);
        }

        const year = state.currentDate.getFullYear();
        const wrap = dce('div', 'cv-year');

        // 12 mesi
        for (let month = 0; month < 12; month++) {
            const box = dce('div', 'cv-ym');
            const head = dce('div', 'cv-ym-head');

            const monthName = new Date(year, month, 1).toLocaleString('it-IT', { month: 'long' });
            const title = dce('div', 'cv-ym-title');
            title.textContent = monthName.charAt(0).toUpperCase() + monthName.slice(1);
            title.setAttribute('data-tooltip', 'Clicca per aprire il mese');

            // clic sul titolo: vai alla vista mese centrata su quel mese
            title.addEventListener('click', () => {
                state.view = 'month';
                state.currentDate = new Date(year, month, 1);
                render(state);
            });

            head.appendChild(title);
            box.appendChild(head);

            // griglia giorni (tipo mini-month)
            const grid = dce('div', 'cv-ym-grid');

            // header giorni
            ['L', 'M', 'M', 'G', 'V', 'S', 'D'].forEach(lbl => {
                const dow = dce('div', 'cv-ym-dow');
                dow.textContent = lbl;
                grid.appendChild(dow);
            });

            const first = new Date(year, month, 1);
            const startDay = (first.getDay() + 6) % 7; // lun=0 ... dom=6
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            // empties
            for (let i = 0; i < startDay; i++) {
                grid.appendChild(dce('div', 'cv-ym-empty'));
            }

            // giorni
            for (let day = 1; day <= daysInMonth; day++) {
                const d = new Date(year, month, day);
                const key = fmtDate(d);

                const cell = dce('div', 'cv-ym-day');
                cell.setAttribute('data-date', key);
                if (key === TODAY_KEY) cell.classList.add('cv-today');

                // label giorno
                const lab = dce('div', 'cv-ym-day-label');
                lab.textContent = String(day);
                cell.appendChild(lab);

                // badge eventi (conteggio)
                const items = state.eventsByDay[key] || [];
                if (items.length) {
                    cell.classList.add('has-events');
                    const badge = dce('div', 'cv-ym-badge');
                    badge.textContent = String(items.length);
                    badge.setAttribute('data-tooltip', `${items.length} evento/i`);
                    cell.appendChild(badge);
                }

                // drop su giorno della vista anno -> sposta evento su quella data
                cell.addEventListener('dragover', (e) => {
                    if (e.dataTransfer?.types?.includes('application/x-ev')) { e.preventDefault(); cell.classList.add('cv-drop'); }
                });
                cell.addEventListener('dragleave', () => cell.classList.remove('cv-drop'));
                cell.addEventListener('drop', async (e) => {
                    e.preventDefault();
                    cell.classList.remove('cv-drop');

                    const json = e.dataTransfer.getData('application/x-ev'); if (!json) return;
                    let data = null; try { data = JSON.parse(json); } catch { }
                    if (!data?.id) return;

                    const ev = state.events.find(x => String(x.id) === String(data.id)); if (!ev) return;
                    if (!canMoveEvent(ev)) {
                        if (typeof window.showToast === 'function') showToast('Non hai i permessi per spostare questo evento.', 'error');
                        return;
                    }
                    const targetKey = cell.getAttribute('data-date');
                    if (!targetKey) return;
                    if (String(ev.start).slice(0, 10) === targetKey) return;

                    await moveEventDate(ev, targetKey, state, { refreshAfter: true });
                });

                grid.appendChild(cell);
            }

            box.appendChild(grid);
            wrap.appendChild(box);
        }

        body.appendChild(wrap);
    }

    function openDayModal(state, dayKey, items) {
        const overlay = dce('div', 'cv-modal-overlay');
        const modal = dce('div', 'cv-modal');
        const head = dce('div', 'cv-modal-head');
        const title = dce('div', 'cv-modal-title');
        const d = parseDate(dayKey);
        title.textContent = d ? `Eventi del ${d.getDate()}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}` : dayKey;
        const close = dce('button', 'cv-btn cv-close'); close.type = 'button'; close.textContent = '✕'; close.setAttribute('data-tooltip', 'Chiudi');
        close.addEventListener('click', () => document.body.removeChild(overlay));
        head.append(title, close);

        const body = dce('div', 'cv-modal-body');
        items.forEach(ev => {
            const row = dce('div', 'cv-modal-ev'); if (ev.color) row.style.setProperty('--cv-ev-color', ev.color);
            const t = dce('div', 'cv-modal-ev-title'); t.textContent = ev.title;
            const when = dce('div', 'cv-modal-ev-when');
            const s = parseDate(ev.start); const e = ev.end ? parseDate(ev.end) : null;
            when.textContent = s ? (e ? `${String(s.getHours()).padStart(2, '0')}:${String(s.getMinutes()).padStart(2, '0')}–${String(e.getHours()).padStart(2, '0')}:${String(e.getMinutes()).padStart(2, '0')}` : `${String(s.getHours()).padStart(2, '0')}:${String(s.getMinutes()).padStart(2, '0')}`) : '';
            row.append(t, when);
            if (ev.url) { row.classList.add('cv-ev-link'); row.addEventListener('click', () => { window.location.href = ev.url; }); }
            body.appendChild(row);
        });

        modal.append(head, body);
        overlay.appendChild(modal);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) document.body.removeChild(overlay); });
        document.body.appendChild(overlay);
    }

    function indexEventsByDay(events) {
        const map = {};
        events.forEach(ev => {
            const s = parseDate(ev.start);
            const e = ev.end ? parseDate(ev.end) : null;
            if (!s) return;
            const end = e || s;
            const cursor = new Date(s.getFullYear(), s.getMonth(), s.getDate());
            const last = new Date(end.getFullYear(), end.getMonth(), end.getDate());
            while (cursor <= last) {
                const key = fmtDate(cursor);
                (map[key] ||= []).push(ev);
                cursor.setDate(cursor.getDate() + 1);
            }
        });
        return map;
    }

    function render(state) {
        const y = state.currentDate.getFullYear();
        const m = state.currentDate.toLocaleString('it-IT', { month: 'long' });
        if (state.view === 'week') {
            state.header.titleEl.textContent = `Settimana di ${fmtDate(state.currentDate)}`;
        } else if (state.view === 'year') {
            state.header.titleEl.textContent = `Anno ${y}`;
        } else {
            state.header.titleEl.textContent = `${m.charAt(0).toUpperCase() + m.slice(1)} ${y}`;
        }

        state.header.setActive();

        state.body.innerHTML = '';
        if (state.loading) {
            const sp = dce('div', 'cv-loading'); sp.textContent = 'Caricamento…'; state.body.appendChild(sp); return;
        }

        state.eventsByDay = indexEventsByDay(state.events || []);

        // render sempre la vista (anche 0 eventi)
        if (state.view === 'month') renderMonth(state, state.body);
        else if (state.view === 'week') renderWeek(state, state.body);
        else if (state.view === 'list') renderList(state, state.body);
        else renderYear(state, state.body);

        if (!state.events || state.events.length === 0) {
            const hint = dce('div', 'cv-empty-hint');
            hint.textContent = 'Nessun evento da mostrare con i filtri correnti.';
            state.body.appendChild(hint);
        }
    }

    async function loadEvents(state) {
        state.loading = true;
        render(state);
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
            // dedup per id globalizzato
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
        const body = dce('div', 'cv-body');
        container.append(header.el, body);
        state.header = header;
        state.body = body;
    }

    // ======== PROVIDER DI DEFAULT ========
    async function defaultCalendarProvider() {
        // NON inviamo quickFilter al server
        const params = (typeof window.appliedFilters === 'object' && window.appliedFilters) ? { ...window.appliedFilters } : {};
        delete params.quickFilter;

        let res;
        try {
            res = await customFetch('page_editor', 'getFilledForms', params);
        } catch {
            return [];
        }
        let rows = Array.isArray(res?.forms) ? res.forms : (Array.isArray(res) ? res : []);
        if (!Array.isArray(rows)) rows = [];

        // Quick filter locale
        const quick = String((window.appliedFilters && window.appliedFilters.quickFilter) || 'all');
        const me = String(window.CURRENT_USER?.id || window.CURRENT_USER?.user_id || '');
        if (quick === 'assigned') {
            rows = rows.filter(s => {
                const ids = String(s.assegnato_a || '').split(',').map(x => x.trim()).filter(Boolean);
                return ids.includes(me);
            });
        } else if (quick === 'responsabile') {
            rows = rows.filter(s => String(s.responsabile || '') === me);
        } else if (quick === 'open') {
            rows = rows.filter(s => ![5, 6].includes(parseInt(s.stato, 10)));
        }

        // normalizza data: server manda DD/MM/YYYY -> convertiamo a YYYY-MM-DD
        const toISODate = (str) => {
            if (!str) return null;
            const m = String(str).match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
            if (m) return `${m[3]}-${m[2]}-${m[1]}`;
            return str; // fallback: lasciamo stare, il parser gestisce ISO
        };

        return rows.map(s => {
            const statoLabel = (window.STATI_MAP && window.STATI_MAP[parseInt(s.stato, 10)]) || '';
            let color = s.color || '#5b8def';
            if (/chiusa|completato/i.test(statoLabel)) color = '#63b365';
            else if (/in corso|attesa|revisione/i.test(statoLabel)) color = '#f0b429';
            else if (/rifiutata|rifiutato/i.test(statoLabel)) color = '#e55353';

            // normalizza a ISO locale-safe
            const start = toISODate(s.data_scadenza || s.data_invio || null);

            // <<< ID UNIVOCO GLOBALE >>>  (tabella:id)
            const globalId = `${s.table_name}:${s.id}`;

            return {
                id: globalId,
                title: String(s.titolo || s.form_name || 'Segnalazione').trim(),
                start,
                end: null,
                status: s.stato,
                url: `index.php?section=collaborazione&page=form_viewer&form_name=${encodeURIComponent(s.form_name)}&id=${encodeURIComponent(s.id)}`,
                color,
                meta: { raw: s } // contiene id numerico e table_name originali
            };
        }).filter(e => !!e.start);
    }

    // --------- API ----------
    const CalendarView = {
        _state: null,
        init(opts = {}) {
            const containerId = opts.containerId || 'calendar-view';
            const el = document.getElementById(containerId);
            if (!el) return;

            if (typeof window.appliedFilters !== 'object' || !window.appliedFilters) {
                window.appliedFilters = {};
            }

            const provider =
                (typeof opts.provider === 'function' && opts.provider) ||
                (typeof window.calendarDataProvider === 'function' && window.calendarDataProvider) ||
                defaultCalendarProvider;

            const allowedViews = new Set(['month', 'week', 'list', 'year']);
            const view = allowedViews.has(opts.view) ? opts.view : 'month';

            const state = {
                container: el,
                provider,
                view,
                currentDate: new Date(),
                events: [],
                eventsByDay: {},
                loading: false,
                header: null,
                body: null
            };

            this._state = state;
            mount(el, state);
            loadEvents(state);
        },
        setProvider(fn) {
            if (typeof fn !== 'function') return;
            if (!this._state) this._state = { currentDate: new Date(), view: 'month' };
            this._state.provider = fn;
        },
        refresh() {
            if (!this._state) return;
            loadEvents(this._state);
        },
        goToday() {
            if (!this._state) return;
            this._state.currentDate = new Date();
            if (typeof render === 'function') render(this._state);

            try {
                const key = fmtDate(this._state.currentDate);
                const el = this._state.container?.querySelector?.(`[data-date="${key}"]`);
                el?.scrollIntoView?.({ block: 'nearest', inline: 'center' });
            } catch { }
        }
    };

    window.CalendarView = CalendarView;

    // Rete di sicurezza: se per qualche motivo la classe resta appesa dopo un drag cancellato
    document.addEventListener('dragend', () => document.body.classList.remove('cv-dragging'));

    // Auto-bootstrap
    document.addEventListener('DOMContentLoaded', function () {
        const el = document.getElementById('calendar-view');
        if (el) CalendarView.init({ containerId: 'calendar-view' });
    });
})();
