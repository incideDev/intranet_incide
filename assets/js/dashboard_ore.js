/**
 * Dashboard Ore — Intra_Incide
 * Dipendenze: Chart.js (chart.umd.js), customFetch (main_core.js), oreHelpers.js
 */

document.addEventListener('DOMContentLoaded', function () {

    // Import helpers dal modulo condiviso
    // NOTA: showToast usa window.showToast del core (main_core.js)
    const { getEl, getEls, formatNum, formatDelta, formatDateISO, htmlEsc } = window.oreHelpers || {};

    // Debug flag (set true per log diagnostici)
    const DEBUG = false;
    function debugLog(...args) {
        if (DEBUG) console.log('[dashboard_ore]', ...args);
    }

    // Chart.js global defaults
    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family  = 'var(--font1, "Segoe UI", system-ui, sans-serif)';
        Chart.defaults.font.size    = 12;
        Chart.defaults.color        = '#94a3b8';
        Chart.defaults.plugins.legend.display              = false;
        Chart.defaults.plugins.tooltip.backgroundColor     = '#1e293b';
        Chart.defaults.plugins.tooltip.titleColor          = '#f1f5f9';
        Chart.defaults.plugins.tooltip.bodyColor           = '#cbd5e1';
        Chart.defaults.plugins.tooltip.padding             = 10;
        Chart.defaults.plugins.tooltip.cornerRadius        = 6;
        Chart.defaults.plugins.tooltip.boxPadding          = 4;
    }

    // State
    const state = {
        view:        'skeleton',
        period:      'month',
        dateFrom:    '',
        dateTo:      '',
        buId:        '',
        projectId:   '',
        resourceId:  '',
        compare:     false,
        gran:        'day',
        commFilter:  'all',
        anomFilter:  'critical',
        resTab:      'all',
        selResId:    null,
        kpiData:     null,
        trendData:   null,
        commData:    null,
        anomData:    null,
        resData:     null,
        // Dedupe state
        isLoading:     false,
        lastFilterKey: '',
    };

    let trendChartInstance   = null;
    let projectChartInstance = null;

    // Init
    async function initPage() {
        setDefaultDates();
        await loadFilterOptions();
        await applyFilters();
    }

    function setDefaultDates() {
        const now  = new Date();
        const year = now.getFullYear();
        const mon  = now.getMonth();
        const from = new Date(year, mon, 1);
        const to   = new Date(year, mon + 1, 0);
        state.dateFrom = formatDateISO(from);
        state.dateTo   = formatDateISO(to);
        getEl('filterDateFrom').value = state.dateFrom;
        getEl('filterDateTo').value   = state.dateTo;
    }

    async function loadFilterOptions() {
        try {
            const res = await window.customFetch('dashboard_ore', 'getFilterOptions', {}, { showLoader: false });
            if (!res.success) return;

            populateSelect(getEl('filterBU'),       res.data.businessUnits, 'IdBusinessUnit', 'DescrBusinessUnit', 'Tutte le BU');
            populateSelect(getEl('filterProject'),  res.data.projects,     'IdProject',      'ProjectDesc',       'Tutte');
            populateSelect(getEl('filterResource'), res.data.resources,    'IdHResource',    'ResourceName',      'Tutte');
        } catch (e) {
            console.error('[dashboard_ore] loadFilterOptions error:', e);
        }
    }

    function populateSelect(selectEl, items, valKey, labelKey, placeholder) {
        if (!selectEl || !Array.isArray(items)) return;
        selectEl.innerHTML = '';
        const blank = document.createElement('option');
        blank.value = '';
        blank.textContent = placeholder;
        selectEl.appendChild(blank);
        items.forEach(item => {
            const opt = document.createElement('option');
            opt.value       = item[valKey];
            opt.textContent = item[labelKey];
            selectEl.appendChild(opt);
        });
    }

    // Period toggle
    getEl('periodToggle').addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-period]');
        if (!btn) return;
        getEls('#periodToggle button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        state.period = btn.dataset.period;
        getEl('customDateRange').style.display = state.period === 'custom' ? '' : 'none';
        if (state.period !== 'custom') computePeriodDates();
    });

    function computePeriodDates() {
        const now  = new Date();
        const year = now.getFullYear();
        const mon  = now.getMonth();
        if (state.period === 'month') {
            state.dateFrom = formatDateISO(new Date(year, mon, 1));
            state.dateTo   = formatDateISO(new Date(year, mon + 1, 0));
        } else if (state.period === 'quarter') {
            const q    = Math.floor(mon / 3);
            state.dateFrom = formatDateISO(new Date(year, q * 3, 1));
            state.dateTo   = formatDateISO(new Date(year, q * 3 + 3, 0));
        }
        getEl('filterDateFrom').value = state.dateFrom;
        getEl('filterDateTo').value   = state.dateTo;
    }

    // Apply / Reset
    getEl('btnApply').addEventListener('click', applyFilters);
    getEl('btnReset').addEventListener('click', function () {
        getEl('filterBU').value       = '';
        getEl('filterProject').value  = '';
        getEl('filterResource').value = '';
        getEls('#periodToggle button').forEach(b => b.classList.remove('active'));
        getEl('periodToggle').querySelector('[data-period="month"]').classList.add('active');
        state.period  = 'month';
        state.compare = false;
        // Invalida lastFilterKey per forzare nuova chiamata
        state.lastFilterKey = '';
        getEl('customDateRange').style.display = 'none';
        getEl('compareBanner').classList.add('hidden');
        computePeriodDates();
        applyFilters();
    });
    getEl('btnResetEmpty') && getEl('btnResetEmpty').addEventListener('click', () => getEl('btnReset').click());
    getEl('btnRetry')      && getEl('btnRetry').addEventListener('click', () => applyFilters(true));

    // Compare
    getEl('btnCompare').addEventListener('click', toggleCompare);
    getEl('btnCompareClose').addEventListener('click', function () {
        state.compare = false;
        getEl('compareBanner').classList.add('hidden');
    });

    // Gran toggle
    getEl('granToggle').addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-gran]');
        if (!btn) return;
        getEls('#granToggle button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        state.gran = btn.dataset.gran;
        renderTrend();
    });

    // Comm filter pills
    document.addEventListener('click', function (e) {
        const pill = e.target.closest('[data-commfilter]');
        if (!pill) return;
        getEls('[data-commfilter]').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        state.commFilter = pill.dataset.commfilter;
        renderCommesse();
    });

    // Anom filter pills
    document.addEventListener('click', function (e) {
        const pill = e.target.closest('[data-anomfilter]');
        if (!pill) return;
        getEls('[data-anomfilter]').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        state.anomFilter = pill.dataset.anomfilter;
        renderAnomalies();
    });

    // Resource tabs
    document.addEventListener('click', function (e) {
        const tab = e.target.closest('[data-restab]');
        if (!tab) return;
        getEls('[data-restab]').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        state.resTab = tab.dataset.restab;
        renderResources();
    });

    // Export CSV (download diretto)
    getEl('btnExportCSV').addEventListener('click', function () {
        const params = buildFilterParams();
        // Nota: layout usa "token-csrf" non "csrf-token"
        const csrfToken = document.querySelector('meta[name="token-csrf"]')?.content || '';
        const baseUrl = '';

        const queryParams = new URLSearchParams({
            section: 'dashboard_ore',
            action: 'exportCSV',
            dateFrom: params.dateFrom,
            dateTo: params.dateTo,
            buId: params.buId || '',
            projectId: params.projectId || '',
            resourceId: params.resourceId || '',
            csrf_token: csrfToken
        });

        window.location.href = baseUrl + 'ajax.php?' + queryParams.toString();
    });

    getEl('btnSaveView').addEventListener('click', function () {
        if (window.showToast) window.showToast('Vista salvata', 'success');
    });

    // Resource Drawer close
    getEl('btnDrawerClose').addEventListener('click', closeResourceDrawer);

    // Apply filters (con dedupe)
    async function applyFilters(forceRefresh = false) {
        if (state.period === 'custom') {
            state.dateFrom = getEl('filterDateFrom').value;
            state.dateTo   = getEl('filterDateTo').value;
        }
        state.buId       = getEl('filterBU').value;
        state.projectId  = getEl('filterProject').value;
        state.resourceId = getEl('filterResource').value;

        const params = buildFilterParams();
        const filterKey = JSON.stringify(params);

        // Dedupe guard: skip se già in loading o stessi filtri (a meno che non sia forceRefresh)
        if (state.isLoading) {
            debugLog('applyFilters: skip - already loading');
            return;
        }
        if (!forceRefresh && state.lastFilterKey !== '' && filterKey === state.lastFilterKey) {
            debugLog('applyFilters: skip - same filters (dedupe)');
            return;
        }

        state.isLoading = true;
        state.lastFilterKey = filterKey;
        setView('skeleton');

        try {
            const [kpiRes, trendRes, commRes, anomRes, resRes] = await Promise.all([
                window.customFetch('dashboard_ore', 'getKPI',        params, { showLoader: false }),
                window.customFetch('dashboard_ore', 'getTrend',      { ...params, gran: state.gran }, { showLoader: false }),
                window.customFetch('dashboard_ore', 'getCommesse',   params, { showLoader: false }),
                window.customFetch('dashboard_ore', 'getAnomalies',  params, { showLoader: false }),
                window.customFetch('dashboard_ore', 'getRisorse',    params, { showLoader: false }),
            ]);

            if (!kpiRes.success && kpiRes.message !== 'empty') {
                setView('error');
                return;
            }

            const hasData = kpiRes.data && kpiRes.data.totalHours > 0;
            if (!hasData) {
                setView('empty');
                return;
            }

            state.kpiData   = kpiRes.data;
            state.trendData = trendRes.data || {};
            state.commData  = commRes.data  || [];
            state.anomData  = anomRes.data  || [];
            state.resData   = resRes.data   || [];

            updatePeriodLabel();
            setView('data');
            renderAll();

        } catch (err) {
            console.error('[dashboard_ore] applyFilters error:', err);
            setView('error');
        } finally {
            state.isLoading = false;
        }
    }

    function buildFilterParams() {
        return {
            dateFrom:   state.dateFrom,
            dateTo:     state.dateTo,
            buId:       state.buId,
            projectId:  state.projectId,
            resourceId: state.resourceId,
        };
    }

    function updatePeriodLabel() {
        const fmt  = d => new Date(d).toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' });
        getEl('dashPeriodLabel').textContent = `Analisi ore - ${fmt(state.dateFrom)} - ${fmt(state.dateTo)}`;
    }

    function toggleCompare() {
        state.compare = !state.compare;
        if (state.compare) {
            getEl('compareBannerText').innerHTML = 'Confronto attivo: periodo selezionato vs periodo precedente';
            getEl('compareBanner').classList.remove('hidden');
        } else {
            getEl('compareBanner').classList.add('hidden');
        }
    }

    // View state
    function setView(v) {
        state.view = v;
        getEl('dashSkeleton').classList.toggle('hidden', v !== 'skeleton');
        getEl('dashEmpty').classList.toggle('hidden',    v !== 'empty');
        getEl('dashError').classList.toggle('hidden',    v !== 'error');
        getEl('dashData').classList.toggle('hidden',     v !== 'data');
    }

    // Render all
    function renderAll() {
        renderKPIs();
        renderTrend();
        renderCommesse();
        renderAnomalies();
        renderResources();
    }

    // KPI Cards
    function renderKPIs() {
        const d = state.kpiData;
        if (!d) return;

        const colorMap = { blue: '#2563eb', green: '#16a34a', amber: '#d97706', purple: '#7c3aed', teal: '#0891b2', red: '#dc2626' };

        const cards = [
            {
                label: 'Ore totali',
                value: formatNum(d.totalHours),
                delta: formatDelta(d.totalHoursDelta),
                dir:   d.totalHoursDelta >= 0 ? 'up' : 'down',
                hint:  `${d.resourceCount} risorse - ${d.workingDays} giorni`,
                color: 'blue',
                prog:  Math.min(Math.round(d.totalHours / (d.targetHours || 1) * 100), 100),
            },
            {
                label: 'Straordinari',
                value: formatNum(d.overtimeHours),
                delta: formatDelta(d.overtimeDelta),
                dir:   d.overtimeDelta <= 0 ? 'up' : 'down',
                hint:  `${d.overtimePct}% del totale`,
                color: 'amber',
                prog:  Math.min(Math.round(d.overtimePct * 4), 100),
            },
            {
                label: 'Ore medie / risorsa',
                value: formatNum(d.avgHoursPerResource, 1),
                delta: formatDelta(d.avgHoursPerResourceDelta),
                dir:   d.avgHoursPerResourceDelta >= 0 ? 'up' : 'down',
                hint:  `Obiettivo: ${d.targetAvgHours}h`,
                color: 'purple',
                prog:  Math.min(Math.round(d.avgHoursPerResource / (d.targetAvgHours || 1) * 100), 100),
            },
            {
                label: '% Saturazione media',
                value: `${d.avgSaturation}%`,
                delta: formatDelta(d.avgSaturationDelta, 'pp'),
                dir:   d.avgSaturationDelta >= 0 ? 'up' : 'down',
                hint:  `${d.oversaturatedCount} risorse oltre 100%`,
                color: 'teal',
                prog:  Math.min(d.avgSaturation, 100),
            },
            {
                label: 'Ore viaggio',
                value: formatNum(d.travelHours),
                delta: '',
                dir:   'up',
                hint:  'Totale periodo',
                color: 'green',
                prog:  Math.min(Math.round((d.travelHours || 0) / (d.totalHours || 1) * 100), 100),
            },
            {
                label: 'Scostamento vs prec.',
                value: formatDelta(d.totalHoursDelta),
                delta: `${Math.abs(d.totalHoursDeltaAbs)}h`,
                dir:   d.totalHoursDelta >= 0 ? 'up' : 'down',
                hint:  'vs periodo precedente',
                color: d.totalHoursDelta >= 0 ? 'blue' : 'red',
                prog:  Math.min(Math.abs(d.totalHoursDelta), 100),
            },
        ];

        getEl('kpiGrid').innerHTML = cards.map(k => `
            <div class="dboard-kpi-card">
                <div class="dboard-kpi-top">
                    <span class="dboard-kpi-label">${k.label}</span>
                    <div class="dboard-kpi-icon dboard-kpi-icon--${k.color}"></div>
                </div>
                <div class="dboard-kpi-value">${k.value}</div>
                <div class="dboard-kpi-foot">
                    <span class="dboard-delta dboard-delta--${k.dir}">${k.delta}</span>
                    <span class="muted">${k.hint}</span>
                </div>
                <div class="dboard-kpi-bar">
                    <div class="dboard-kpi-bar-fill" style="width:${k.prog}%;background:${colorMap[k.color]}"></div>
                </div>
            </div>
        `).join('');
    }

    // Trend Chart
    function renderTrend() {
        const src = state.gran === 'week'
            ? (state.trendData && state.trendData.weeks   || [])
            : (state.trendData && state.trendData.days    || []);

        // Destroy chart precedente (pattern robusto: variabile + Chart.getChart)
        if (trendChartInstance) {
            trendChartInstance.destroy();
            trendChartInstance = null;
        }

        if (!src.length || typeof Chart === 'undefined') return;

        const canvas = getEl('trendChart');
        if (!canvas) return;

        // Destroy sicuro: Chart.getChart copre casi edge (variabile out of sync)
        const existingChart = Chart.getChart(canvas);
        if (existingChart) {
            existingChart.destroy();
        }

        const ctx = canvas.getContext('2d');

        const grad = ctx.createLinearGradient(0, 0, 0, 220);
        grad.addColorStop(0, 'rgba(37,99,235,0.16)');
        grad.addColorStop(1, 'rgba(37,99,235,0)');

        const target = state.gran === 'week'
            ? (state.kpiData && state.kpiData.targetAvgHours || 184) * 5
            : (state.kpiData && state.kpiData.targetAvgHours || 184);

        trendChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: src.map(d => d.label),
                datasets: [
                    {
                        label:            'Ore lavorate',
                        data:             src.map(d => d.total),
                        borderColor:      '#2563eb',
                        backgroundColor:  grad,
                        borderWidth:      2.5,
                        fill:             true,
                        tension:          0.35,
                        pointRadius:      state.gran === 'week' ? 5 : 2,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#2563eb',
                    },
                    {
                        label:            'Straordinari',
                        data:             src.map(d => d.overtime || 0),
                        borderColor:      '#f59e0b',
                        backgroundColor:  'transparent',
                        borderWidth:      2,
                        fill:             false,
                        tension:          0.35,
                        pointRadius:      state.gran === 'week' ? 5 : 2,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#f59e0b',
                        borderDash:       [4, 3],
                    },
                    {
                        label:       'Obiettivo',
                        data:        src.map(() => target),
                        borderColor: '#e2e8f0',
                        borderWidth: 1.5,
                        fill:        false,
                        tension:     0,
                        pointRadius: 0,
                        borderDash:  [6, 4],
                    },
                ],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: {
                        grid:  { color: '#f8fafc' },
                        ticks: { maxTicksLimit: state.gran === 'week' ? 6 : 10, maxRotation: 0, font: { size: 11 } },
                    },
                    y: {
                        grid:       { color: '#f8fafc' },
                        ticks:      { font: { size: 11 }, maxTicksLimit: 6 },
                        beginAtZero: true,
                    },
                },
                plugins: {
                    legend:  { display: false },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y}h` } },
                },
            },
        });
    }

    // Commesse Table
    function renderCommesse() {
        const all  = state.commData || [];
        const data = state.commFilter === 'alert' ? all.filter(c => c.hasAlert) : all;
        const tot  = all.reduce((s, c) => s + parseFloat(c.totalHours || 0), 0);

        getEl('commSub').textContent = `${data.length} commesse - ${formatNum(tot)} ore tot.`;

        const alertEmoji = { ore_eccessive: '!', budget: '#', commessa_chiusa: 'X' };
        const statusBadge = s => {
            if (s === 'aperta') return `<span class="badge badge-success">aperta</span>`;
            if (s === 'chiusa') return `<span class="badge">chiusa</span>`;
            return `<span class="badge badge-warning">${htmlEsc(s)}</span>`;
        };

        getEl('commBody').innerHTML = data.map(c => {
            const budget   = c.budgetHours ?? 0;
            const actual   = c.totalHours ?? 0;
            const residuo  = budget - actual;
            const avanzPct = budget > 0 ? Math.round((actual / budget) * 100) : 0;
            const progW    = Math.min(avanzPct, 100);
            const progCls  = avanzPct > 100 ? 'dboard-pf--red' : avanzPct > 85 ? 'dboard-pf--amber' : 'dboard-pf--green';
            const residuoCol = residuo < 0 ? '#dc2626' : '#16a34a';

            return `<tr class="table-row-clickable dboard-comm-row" data-projectid="${htmlEsc(c.idProject)}">
                <td>
                    <div class="cell-stack">
                        <span class="cell-primary">${htmlEsc(c.projectCode)}</span>
                        <span class="cell-secondary">${htmlEsc(c.projectDesc)}</span>
                    </div>
                </td>
                <td class="text-right">${formatNum(actual)}h</td>
                <td class="text-right">${budget > 0 ? formatNum(budget) + 'h' : '<span class="muted">-</span>'}</td>
                <td class="text-right" style="color:${residuoCol}">${budget > 0 ? (residuo > 0 ? '+' : '') + formatNum(residuo) + 'h' : '<span class="muted">-</span>'}</td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px">
                        <div style="width:64px;height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden">
                            <div class="dboard-pf ${progCls}" style="width:${progW}%"></div>
                        </div>
                        <span class="badge ${avanzPct > 100 ? 'badge-disciplina' : avanzPct > 85 ? 'badge-warning' : ''}" style="font-size:11px">${avanzPct}%</span>
                    </div>
                </td>
                <td class="text-center">
                    ${c.hasAlert
                        ? `<span data-tooltip="${htmlEsc(c.alertType)}" class="badge badge-disciplina">${alertEmoji[c.alertType] || '!'}</span>`
                        : `<span style="color:#e2e8f0">-</span>`}
                </td>
                <td>${statusBadge(c.projectStatus)}</td>
            </tr>`;
        }).join('');
    }

    // Anomalies
    function renderAnomalies() {
        const all  = state.anomData || [];
        const data = state.anomFilter === 'critical' ? all.filter(a => a.severity === 'critical') : all;
        getEl('anomSub').textContent = `${data.length} eventi`;

        const sevClass  = { critical: 'dboard-ai--crit', warning: 'dboard-ai--warn', info: 'dboard-ai--info' };
        const badgeCls  = { critical: 'badge-disciplina', warning: 'badge-warning',   info: 'badge-info' };
        const badgeLbl  = { critical: 'Critica',          warning: 'Attenzione',      info: 'Info' };

        getEl('anomList').innerHTML = data.length === 0
            ? `<p class="muted" style="padding:20px 0;text-align:center">Nessuna anomalia</p>`
            : data.map(a => `
                <div class="dboard-anom">
                    <div class="dboard-ai ${sevClass[a.severity] || 'dboard-ai--info'}"></div>
                    <div class="dboard-anom-body">
                        <div class="dboard-anom-title">${htmlEsc(a.title)}</div>
                        <div class="muted dboard-anom-meta">${htmlEsc(a.meta)}</div>
                        ${a.link ? `<a href="${htmlEsc(a.linkHref || '#')}" class="dboard-anom-link">${htmlEsc(a.link)} &rarr;</a>` : ''}
                    </div>
                    <div class="dboard-anom-badge">
                        <span class="badge ${badgeCls[a.severity] || 'badge-info'}">${badgeLbl[a.severity] || 'Info'}</span>
                    </div>
                </div>`
            ).join('');
    }

    // Resources Table
    function renderResources() {
        let data = [...(state.resData || [])].sort((a, b) => b.totalHours - a.totalHours);
        if (state.resTab === 'over')  data = data.filter(r => r.avanzPct > 100 || r.saturationPct > 100);
        if (state.resTab === 'anom')  data = data.filter(r => r.anomalyCount > 0);

        const totRes = (state.resData || []).length;
        const totH   = (state.resData || []).reduce((s, r) => s + (r.totalHours || 0), 0);
        getEl('resSub').textContent = `${totRes} risorse - ${formatNum(totH)} ore tot.`;

        getEl('resBody').innerHTML = data.map(r => {
            const avanz   = r.avanzPct ?? 0;
            const budget  = r.budgetHours ?? 0;
            const avanzCol = avanz > 100 ? '#dc2626' : avanz > 85 ? '#d97706' : '#16a34a';
            const progCls  = avanz > 100 ? 'dboard-pf--red' : avanz > 85 ? 'dboard-pf--amber' : 'dboard-pf--green';
            const progW    = Math.min(avanz, 100);
            const spark    = renderSparkline(r.weeklyTrend || []);
            const fullName = (r.firstname || '') + ' ' + (r.surname || '');
            const buShort  = (r.buDesc || '').replace('BU-', '');
            const selected = state.selResId === r.idHResource ? 'dboard-res-selected' : '';

            // Immagine profilo con fallback a iniziali
            const imageSrc = r.imagePath || window.generateInitialsAvatar(fullName);
            const avatarHtml = `<img class="table-avatar" src="${htmlEsc(imageSrc)}" alt="${htmlEsc(fullName)}" onerror="this.src=window.generateInitialsAvatar('${htmlEsc(fullName)}')">`;

            return `<tr class="table-row-clickable ${selected} dboard-res-row" data-resid="${r.idHResource}">
                <td>
                    <div class="table-person">
                        ${avatarHtml}
                        <div class="cell-stack">
                            <span class="cell-primary">${htmlEsc(fullName)}</span>
                            <span class="cell-secondary">${htmlEsc(r.roleDesc || '')}</span>
                        </div>
                    </div>
                </td>
                <td class="text-center"><span class="table-pill table-pill--info">${htmlEsc(buShort)}</span></td>
                <td class="text-right">${formatNum(r.totalHours)}h</td>
                <td class="text-right">${budget > 0 ? formatNum(budget) + 'h' : '<span class="muted">-</span>'}</td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px">
                        <div style="width:70px;height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden">
                            <div class="dboard-pf ${progCls}" style="width:${progW}%"></div>
                        </div>
                        <span class="badge ${avanz > 100 ? 'badge-disciplina' : avanz > 85 ? 'badge-warning' : ''}" style="font-size:11px">${budget > 0 ? avanz + '%' : '-'}</span>
                    </div>
                </td>
                <td class="text-center">${r.projectCount ?? 0}</td>
                <td>${spark}</td>
                <td><button class="button button--sm">Dettaglio</button></td>
            </tr>`;
        }).join('');
    }

    function renderSparkline(vals) {
        if (!vals || !vals.length) return '';
        const max = Math.max(...vals) || 1;
        const w = 52, h = 22;
        const pts = vals.map((v, i) => `${i * (w / (vals.length - 1))},${h - (v / max) * h}`).join(' ');
        return `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" style="display:block">
            <polyline points="${pts}" fill="none" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>`;
    }

    // Resource row click -> drawer
    document.addEventListener('click', async function (e) {
        const row = e.target.closest('.dboard-res-row');
        if (!row) return;
        const resId = row.dataset.resid;
        if (!resId) return;
        await openResourceDrawer(resId);
    });

    // Commessa row click -> project drawer
    document.addEventListener('click', async function (e) {
        const row = e.target.closest('.dboard-comm-row');
        if (!row) return;
        const projId = row.dataset.projectid;
        if (!projId) return;
        await openProjectDrawer(projId);
    });

    // Project drawer
    async function openProjectDrawer(projId) {
        const c = (state.commData || []).find(x => String(x.idProject) === String(projId));
        if (!c) return;

        getEl('projectDrawerTitle').textContent = c.projectCode;
        getEl('projectDrawerSub').textContent   = c.projectDesc || projId;
        getEl('projectDrawerBody').innerHTML    = `<div class="dboard-skeleton" style="height:220px;border-radius:8px"></div>`;

        getEl('projectDrawer').classList.remove('hidden');

        try {
            const res = await window.customFetch('dashboard_ore', 'getProjectDailySeries', {
                ...buildFilterParams(),
                idProject: projId,
            }, { showLoader: false });

            if (!res.success) {
                getEl('projectDrawerBody').innerHTML = `<p class="muted">Impossibile caricare i dati.</p>`;
                return;
            }

            renderProjectDrawerBody(c, res.data);
        } catch (err) {
            console.error('[dashboard_ore] openProjectDrawer error:', err);
            getEl('projectDrawerBody').innerHTML = `<p class="muted">Errore durante il caricamento.</p>`;
        }
    }

    function renderProjectDrawerBody(c, data) {
        const totals = data.totals || {};
        const days   = data.days   || [];
        const residuoCol = totals.residuo < 0 ? '#dc2626' : '#16a34a';
        const avanzCol   = totals.avanzPct > 100 ? '#dc2626' : totals.avanzPct > 85 ? '#d97706' : '#16a34a';

        // Verifica se ci sono dati da visualizzare (actual O budget)
        const hasChartData = days.length > 0 && days.some(d =>
            (d.actualHours || 0) > 0 || (d.actualCum || 0) > 0 ||
            (d.budgetHours || 0) > 0 || (d.budgetCum || 0) > 0
        );

        // Costruisci HTML body con canvas DIRETTO nel wrapper (no canvas persistente)
        let chartHtml = '';
        if (typeof Chart === 'undefined') {
            chartHtml = `<p class="muted" style="text-align:center;padding:40px 0;">Grafico non disponibile (Chart.js non caricato)</p>`;
        } else if (!hasChartData) {
            chartHtml = `<p class="muted" style="text-align:center;padding:40px 0;">Nessun dato nel periodo selezionato</p>`;
        } else {
            // Canvas creato direttamente nel wrapper (stili in CSS)
            chartHtml = `<div id="projectChartWrap" class="project-chart-wrap">
                <canvas id="projectDailyChart"></canvas>
            </div>`;
        }

        getEl('projectDrawerBody').innerHTML = `
            <div class="dboard-stat-row"><span class="muted">Ore Imputate</span><span class="dboard-stat-val">${formatNum(totals.actualTotal)}h</span></div>
            <div class="dboard-stat-row"><span class="muted">Budget</span><span class="dboard-stat-val">${totals.budgetTotal > 0 ? formatNum(totals.budgetTotal) + 'h' : '-'}</span></div>
            <div class="dboard-stat-row"><span class="muted">Residuo</span><span class="dboard-stat-val" style="color:${residuoCol}">${totals.budgetTotal > 0 ? (totals.residuo > 0 ? '+' : '') + formatNum(totals.residuo) + 'h' : '-'}</span></div>
            <div class="dboard-stat-row"><span class="muted">Avanzamento</span><span class="dboard-stat-val" style="color:${avanzCol}">${totals.avanzPct}%</span></div>
            <div style="margin-top:20px">
                <div class="dboard-dw-section-label">Andamento cumulativo Actual vs Budget</div>
                ${chartHtml}
            </div>
        `;

        // Destroy chart precedente (pattern robusto)
        if (projectChartInstance) {
            projectChartInstance.destroy();
            projectChartInstance = null;
        }

        // Early exit se no Chart.js o no dati
        if (typeof Chart === 'undefined') {
            debugLog('Chart.js NOT LOADED');
            return;
        }
        if (!hasChartData) {
            return;
        }

        // Prendi canvas appena creato nel DOM
        const canvas = getEl('projectDailyChart');
        if (!canvas) {
            debugLog('projectDailyChart canvas NOT FOUND');
            return;
        }

        // Destroy sicuro (pattern Chart.getChart)
        const existingChart = Chart.getChart(canvas);
        if (existingChart) existingChart.destroy();

        // Inizializza chart dopo reflow DOM
        requestAnimationFrame(() => initProjectChart(canvas, days));
    }

    function initProjectChart(canvas, days) {
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            debugLog('Cannot get 2d context');
            return;
        }

        // Retry se canvas ha dimensioni zero (drawer in animazione)
        const rect = canvas.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) {
            debugLog('Canvas zero dimensions, retrying...');
            setTimeout(() => initProjectChart(canvas, days), 100);
            return;
        }

        const gradActual = ctx.createLinearGradient(0, 0, 0, 180);
        gradActual.addColorStop(0, 'rgba(37,99,235,0.18)');
        gradActual.addColorStop(1, 'rgba(37,99,235,0)');

        projectChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: days.map(d => {
                    const dt = new Date(d.date);
                    return dt.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit' });
                }),
                datasets: [
                    {
                        label:           'Actual (cum.)',
                        data:            days.map(d => d.actualCum),
                        borderColor:     '#2563eb',
                        backgroundColor: gradActual,
                        borderWidth:     2.5,
                        fill:            true,
                        tension:         0.3,
                        pointRadius:     days.length > 20 ? 0 : 3,
                        pointHoverRadius: 5,
                    },
                    {
                        label:           'Budget (cum.)',
                        data:            days.map(d => d.budgetCum),
                        borderColor:     '#f59e0b',
                        backgroundColor: 'transparent',
                        borderWidth:     2,
                        fill:            false,
                        tension:         0.3,
                        borderDash:      [5, 3],
                        pointRadius:     days.length > 20 ? 0 : 3,
                        pointHoverRadius: 5,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: {
                        grid:  { color: '#f1f5f9' },
                        ticks: { maxTicksLimit: 8, maxRotation: 0, font: { size: 10 } },
                    },
                    y: {
                        grid:       { color: '#f1f5f9' },
                        ticks:      { font: { size: 10 }, maxTicksLimit: 5 },
                        beginAtZero: true,
                    },
                },
                plugins: {
                    legend:  { display: true, position: 'top', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y}h` } },
                },
            },
        });
    }

    function closeProjectDrawer() {
        getEl('projectDrawer').classList.add('hidden');

        // Destroy chart
        if (projectChartInstance) {
            projectChartInstance.destroy();
            projectChartInstance = null;
        }
    }

    getEl('btnProjectDrawerClose').addEventListener('click', closeProjectDrawer);

    // Chiudi drawer cliccando fuori
    document.addEventListener('click', function(e) {
        const resourceDrawer = getEl('resourceDrawer');
        const projectDrawer = getEl('projectDrawer');

        // Se click fuori dal resource drawer (e drawer è aperto)
        if (resourceDrawer && !resourceDrawer.classList.contains('hidden')) {
            if (!resourceDrawer.contains(e.target) && !e.target.closest('.dboard-res-row')) {
                closeResourceDrawer();
            }
        }

        // Se click fuori dal project drawer (e drawer è aperto)
        if (projectDrawer && !projectDrawer.classList.contains('hidden')) {
            if (!projectDrawer.contains(e.target) && !e.target.closest('.dboard-comm-row')) {
                closeProjectDrawer();
            }
        }
    });

    // Resource drawer
    async function openResourceDrawer(resId) {
        state.selResId = resId;
        renderResources();

        const r = (state.resData || []).find(x => String(x.idHResource) === String(resId));
        if (!r) return;

        getEl('drawerName').textContent = `${r.firstname} ${r.surname}`;
        getEl('drawerRole').textContent = `${r.roleDesc || '-'} - ${r.buDesc || '-'}`;
        getEl('drawerBody').innerHTML   = `<div class="dboard-skeleton" style="height:180px;border-radius:8px"></div>`;

        getEl('resourceDrawer').classList.remove('hidden');

        try {
            const res = await window.customFetch('dashboard_ore', 'getResourceDetail', {
                ...buildFilterParams(),
                resourceId: resId,
            }, { showLoader: false });

            if (!res.success) {
                getEl('drawerBody').innerHTML = `<p class="muted">Impossibile caricare il dettaglio.</p>`;
                return;
            }

            renderResourceDrawerBody(r, res.data);
        } catch (e) {
            console.error('[dashboard_ore] openResourceDrawer error:', e);
            getEl('drawerBody').innerHTML = `<p class="muted">Errore durante il caricamento.</p>`;
        }
    }

    function renderResourceDrawerBody(r, detail) {
        const satCol  = r.saturationPct > 100 ? '#dc2626' : r.saturationPct > 85 ? '#d97706' : '#16a34a';
        const totH    = (detail.commesse || []).reduce((s, c) => s + parseFloat(c.hours || 0), 0);

        getEl('drawerBody').innerHTML = `
            <div>
                <div class="dboard-dw-section-label">Riepilogo periodo</div>
                <div class="dboard-stat-row"><span class="muted">Ore lavorate</span><span class="dboard-stat-val">${formatNum(r.totalHours)}h</span></div>
                <div class="dboard-stat-row"><span class="muted">Straordinari</span><span class="dboard-stat-val" style="color:#d97706">${formatNum(r.overtimeHours)}h</span></div>
                <div class="dboard-stat-row"><span class="muted">Saturazione</span><span class="dboard-stat-val" style="color:${satCol}">${r.saturationPct}%</span></div>
                <div class="dboard-stat-row"><span class="muted">Anomalie</span><span class="dboard-stat-val">${r.anomalyCount > 0 ? `<span class="badge badge-disciplina">${r.anomalyCount}</span>` : '-'}</span></div>
            </div>
            <div>
                <div class="dboard-dw-section-label">Distribuzione per commessa</div>
                <div class="dboard-mb-list">
                    ${(detail.commesse || []).map(c => {
                        const pct = totH > 0 ? Math.round(c.hours / totH * 100) : 0;
                        return `<div class="dboard-mb">
                            <div class="dboard-mb-label" title="${htmlEsc(c.projectDesc)}">${htmlEsc(c.projectCode)}</div>
                            <div class="dboard-mb-track"><div class="dboard-mb-fill" style="width:${pct}%"></div></div>
                            <div class="dboard-mb-val">${formatNum(c.hours)}h</div>
                        </div>`;
                    }).join('')}
                </div>
            </div>
            <div>
                <div class="dboard-dw-section-label">Azioni</div>
                <div style="display:flex;flex-direction:column;gap:8px">
                    <a href="index.php?section=hr&page=contacts&id=${r.idHResource}" class="button">Apri scheda risorsa</a>
                </div>
            </div>`;
    }

    function closeResourceDrawer() {
        state.selResId = null;
        getEl('resourceDrawer').classList.add('hidden');
        renderResources();
    }

    // Start
    initPage();
});
