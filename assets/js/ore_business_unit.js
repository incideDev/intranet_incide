/**
 * Ore Business Unit — Intra_Incide
 * Dipendenze: customFetch (main_core.js), oreHelpers.js
 */

document.addEventListener('DOMContentLoaded', function () {

    // Import helpers dal modulo condiviso (renderPie locale per CSS specifici)
    const { getEl, getEls, formatNum, htmlEsc, PIE_COLORS } = window.oreHelpers || {};

    const DEBUG = true;  // TEMP: attivo per debug
    function debugLog(...args) {
        if (DEBUG) console.log('[ore_bu]', ...args);
    }

    // State
    const state = {
        view: 'skeleton',
        year: '',
        month: '',
        pmId: '',
        projectId: '',
        buCode: '',
        bus: [],
        filters: {},
        rows: [],
        isLoading: false,           // Guard contro chiamate duplicate
        lastDataKey: '',            // Dedupe key per getBusinessUnitData (stringa vuota = primo load passa)
        lastTrendKey: '',           // Dedupe key per getBusinessUnitTrend (stringa vuota = primo load passa)
    };

    // Init
    async function initPage() {
        await loadData();
        setupEventListeners();
    }

    function setupEventListeners() {
        const btnApply = getEl('btnApply');
        const btnReset = getEl('btnReset');
        const btnResetEmpty = getEl('btnResetEmpty');
        const btnRetry = getEl('btnRetry');
        const btnExportCSV = getEl('btnExportCSV');
        const buTabs = getEl('buTabs');

        if (btnApply) btnApply.addEventListener('click', applyFilters);
        if (btnReset) btnReset.addEventListener('click', resetFilters);
        if (btnResetEmpty) btnResetEmpty.addEventListener('click', resetFilters);
        if (btnRetry) btnRetry.addEventListener('click', () => loadData());
        if (btnExportCSV) btnExportCSV.addEventListener('click', exportCSV);

        // BU tabs delegation
        if (buTabs) {
            buTabs.addEventListener('click', function (e) {
                const tab = e.target.closest('.orebu-tab');
                if (!tab) return;
                getEls('.orebu-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                state.buCode = tab.dataset.bu || '';
                renderAll();
            });
        }
    }

    async function loadData() {
        // Guard contro chiamate concurrent
        if (state.isLoading) {
            debugLog('loadData skipped - already loading');
            return;
        }

        const params = {
            year: state.year,
            month: state.month,
            pmId: state.pmId,
            projectId: state.projectId,
            buCode: state.buCode,
        };
        const dataKey = JSON.stringify(params);

        // Dedupe: evita chiamata se parametri identici E non è il primo load
        // Primo load: lastDataKey è stringa vuota, quindi passa sempre
        if (state.lastDataKey !== '' && dataKey === state.lastDataKey) {
            debugLog('loadData skipped - same params, key:', dataKey);
            return;
        }

        state.isLoading = true;
        showView('skeleton');

        debugLog('loadData START - params:', params);

        try {
            const res = await window.customFetch('dashboard_ore', 'getBusinessUnitData', params, { showLoader: false });

            debugLog('loadData response:', res);

            if (!res.success) {
                console.error('[ore_bu] loadData failed:', res.message || 'unknown error');
                showView('error');
                return;
            }

            // Aggiorna key solo dopo successo
            state.lastDataKey = dataKey;

            state.bus     = res.data.bus || [];
            state.filters = res.data.filters || {};
            state.rows    = res.data.rows || [];

            debugLog('loadData OK - rows:', state.rows.length, 'bus:', state.bus.length);

            if (state.rows.length === 0) {
                populateFilters();
                renderBuTabs();
                showView('empty');
                return;
            }

            populateFilters();
            renderBuTabs();
            renderAll();
            showView('data');

        } catch (e) {
            console.error('[ore_bu] loadData error:', e);
            showView('error');
        } finally {
            state.isLoading = false;
        }
    }

    function applyFilters() {
        const yearEl = getEl('filterYear');
        const monthEl = getEl('filterMonth');
        const pmEl = getEl('filterPM');
        const projEl = getEl('filterProject');

        state.year      = yearEl ? yearEl.value : '';
        state.month     = monthEl ? monthEl.value : '';
        state.pmId      = pmEl ? pmEl.value : '';
        state.projectId = projEl ? projEl.value : '';

        // Reset dedupe keys per forzare nuova chiamata
        state.lastDataKey = '';
        state.lastTrendKey = '';
        loadData();
    }

    function resetFilters() {
        state.year      = '';
        state.month     = '';
        state.pmId      = '';
        state.projectId = '';
        state.buCode    = '';

        const yearEl = getEl('filterYear');
        const monthEl = getEl('filterMonth');
        const pmEl = getEl('filterPM');
        const projEl = getEl('filterProject');

        if (yearEl) yearEl.value = '';
        if (monthEl) monthEl.value = '';
        if (pmEl) pmEl.value = '';
        if (projEl) projEl.value = '';

        getEls('.orebu-tab').forEach(t => t.classList.remove('active'));
        const allTab = document.querySelector('.orebu-tab[data-bu=""]');
        if (allTab) allTab.classList.add('active');

        // Reset dedupe keys per forzare nuova chiamata
        state.lastDataKey = '';
        state.lastTrendKey = '';
        loadData();
    }

    function populateFilters() {
        const f = state.filters;

        // Anno
        const yearSel = getEl('filterYear');
        if (yearSel) {
            yearSel.innerHTML = '<option value="">Tutti</option>';
            (f.years || []).forEach(y => {
                const opt = document.createElement('option');
                opt.value = y;
                opt.textContent = y;
                if (String(y) === String(state.year)) opt.selected = true;
                yearSel.appendChild(opt);
            });

            // Se nessun anno selezionato, default anno corrente
            if (!state.year && f.years && f.years.length > 0) {
                state.year = f.years[0];
                yearSel.value = state.year;
            }
        }

        // Mese
        const monthSel = getEl('filterMonth');
        if (monthSel) {
            monthSel.innerHTML = '<option value="">Tutti</option>';
            (f.months || []).forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.value;
                opt.textContent = m.label;
                if (m.value === state.month) opt.selected = true;
                monthSel.appendChild(opt);
            });
        }

        // PM
        const pmSel = getEl('filterPM');
        if (pmSel) {
            pmSel.innerHTML = '<option value="">Tutti</option>';
            (f.pms || []).forEach(pm => {
                const opt = document.createElement('option');
                opt.value = pm.id;
                opt.textContent = pm.name || pm.id;
                if (String(pm.id) === String(state.pmId)) opt.selected = true;
                pmSel.appendChild(opt);
            });
        }

        // Progetti
        const projSel = getEl('filterProject');
        if (projSel) {
            projSel.innerHTML = '<option value="">Tutte</option>';
            (f.projects || []).forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.code || p.id;
                if (String(p.id) === String(state.projectId)) opt.selected = true;
                projSel.appendChild(opt);
            });
        }

        // Label periodo
        updatePeriodLabel();
    }

    function updatePeriodLabel() {
        const f = state.filters;
        const yearLabel = state.year || (f.years && f.years[0]) || new Date().getFullYear();
        const monthLabel = state.month
            ? (f.months || []).find(m => m.value === state.month)?.label || state.month
            : 'Tutti i mesi';
        const el = getEl('buPeriodLabel');
        if (el) el.textContent = `${monthLabel} ${yearLabel}`;
    }

    function renderBuTabs() {
        const tabs = getEl('buTabs');
        if (!tabs) return;
        let html = '<button class="orebu-tab' + (!state.buCode ? ' active' : '') + '" data-bu="">Tutte le BU</button>';
        state.bus.forEach(bu => {
            const active = state.buCode === bu.code ? ' active' : '';
            html += `<button class="orebu-tab${active}" data-bu="${htmlEsc(bu.code)}">${htmlEsc(bu.name || bu.code)}</button>`;
        });
        tabs.innerHTML = html;
    }

    function renderAll() {
        const filtered = filterRows();
        renderKPIs(filtered);
        renderPieCommesse(filtered);
        renderPieRisorse(filtered);
        renderTrend();
        renderTableCommesse(filtered);
        renderTableRisorse(filtered);

        // Aggiorna label BU
        const buLabel = state.buCode || 'Tutte le BU';
        const tBU = getEl('tBU');
        const trendBU = getEl('trendBU');
        if (tBU) tBU.textContent = buLabel;
        if (trendBU) trendBU.textContent = buLabel;
    }

    function filterRows() {
        let rows = state.rows;

        if (state.buCode) {
            rows = rows.filter(r => r.bu === state.buCode);
        }

        return rows;
    }

    // KPIs
    function renderKPIs(rows) {
        const totalWH = rows.reduce((sum, r) => sum + (r.wh || 0), 0);
        const totalEH = rows.reduce((sum, r) => sum + (r.eh || 0), 0);
        const resourceCount = new Set(rows.map(r => r.resourceId)).size;
        const avanzPct = totalEH > 0 ? Math.round(totalWH / totalEH * 100) : 0;

        const kWH = getEl('kWH');
        const kWHsub = getEl('kWHsub');
        const kWHbar = getEl('kWHbar');
        const kEH = getEl('kEH');
        const kRes = getEl('kRes');
        const kResDelta = getEl('kResDelta');
        const kAv = getEl('kAv');
        const kAvS = getEl('kAvS');
        const kAvBar = getEl('kAvBar');

        if (kWH) kWH.textContent = formatNum(totalWH) + 'h';
        if (kWHsub) kWHsub.textContent = `su ${formatNum(totalEH)}h budget`;
        const whPct = totalEH > 0 ? Math.min(Math.round(totalWH / totalEH * 100), 100) : 0;
        if (kWHbar) kWHbar.style.width = whPct + '%';

        if (kEH) kEH.textContent = formatNum(totalEH) + 'h';
        if (kRes) kRes.textContent = resourceCount;
        if (kResDelta) kResDelta.textContent = '';

        if (kAv) kAv.textContent = avanzPct + '%';
        if (kAvS) kAvS.textContent = totalEH > 0 ? 'vs budget' : 'nessun budget';
        if (kAvBar) {
            kAvBar.style.width = Math.min(avanzPct, 100) + '%';
            kAvBar.className = 'dboard-kpi-bar-fill ' +
                (avanzPct > 100 ? 'dboard-bg-red' : avanzPct > 85 ? 'dboard-bg-amber' : 'dboard-bg-green');
        }
    }

    // PIE: Commesse
    function renderPieCommesse(rows) {
        // Aggrega per progetto
        const byProject = {};
        rows.forEach(r => {
            if (!byProject[r.projectId]) {
                byProject[r.projectId] = { code: r.projectCode, wh: 0 };
            }
            byProject[r.projectId].wh += r.wh || 0;
        });

        const sorted = Object.entries(byProject)
            .map(([id, v]) => ({ id, code: v.code, wh: v.wh }))
            .sort((a, b) => b.wh - a.wh)
            .slice(0, 8);

        const total = sorted.reduce((s, p) => s + p.wh, 0);
        renderPie('svgPie', 'pieLeg', sorted.map((p, i) => ({
            label: p.code || p.id,
            value: p.wh,
            pct: total > 0 ? Math.round(p.wh / total * 100) : 0,
            color: PIE_COLORS[i % PIE_COLORS.length],
        })));
    }

    // PIE: Risorse
    function renderPieRisorse(rows) {
        // Aggrega per risorsa
        const byRes = {};
        rows.forEach(r => {
            if (!byRes[r.resourceId]) {
                byRes[r.resourceId] = { name: r.resourceName, wh: 0 };
            }
            byRes[r.resourceId].wh += r.wh || 0;
        });

        const sorted = Object.entries(byRes)
            .map(([id, v]) => ({ id, name: v.name, wh: v.wh }))
            .sort((a, b) => b.wh - a.wh)
            .slice(0, 8);

        const total = sorted.reduce((s, r) => s + r.wh, 0);
        renderPie('svgPie2', 'pieLeg2', sorted.map((r, i) => ({
            label: r.name || r.id,
            value: r.wh,
            pct: total > 0 ? Math.round(r.wh / total * 100) : 0,
            color: PIE_COLORS[i % PIE_COLORS.length],
        })));
    }

    function renderPie(svgId, legendId, slices) {
        const svg = getEl(svgId);
        const legend = getEl(legendId);

        if (!svg || !legend) return;

        if (!slices.length) {
            svg.innerHTML = '<text x="100" y="100" text-anchor="middle" fill="#94a3b8" font-size="14">Nessun dato</text>';
            legend.innerHTML = '';
            return;
        }

        const cx = 100, cy = 100, r = 80;
        let startAngle = -90;
        let paths = '';

        slices.forEach((s, i) => {
            const angle = (s.pct / 100) * 360;
            const endAngle = startAngle + angle;
            const path = describeArc(cx, cy, r, startAngle, endAngle);
            const tooltip = `${s.label}: ${formatNum(s.value)}h (${s.pct}%)`;
            paths += `<path d="${path}" fill="${s.color}" data-tooltip="${htmlEsc(tooltip)}" style="cursor:pointer"><title>${htmlEsc(tooltip)}</title></path>`;
            startAngle = endAngle;
        });

        svg.innerHTML = paths;

        // Legend
        legend.innerHTML = slices.map(s => `
            <div class="orebu-pie-legend-item">
                <span class="orebu-pie-legend-dot" style="background:${s.color}"></span>
                <span class="orebu-pie-legend-label">${htmlEsc(s.label)}</span>
                <span class="orebu-pie-legend-value">${formatNum(s.value)}h</span>
                <span class="orebu-pie-legend-pct">${s.pct}%</span>
            </div>
        `).join('');
    }

    function describeArc(cx, cy, r, startAngle, endAngle) {
        if (endAngle - startAngle >= 360) {
            // Full circle
            return `M ${cx - r} ${cy} A ${r} ${r} 0 1 1 ${cx + r} ${cy} A ${r} ${r} 0 1 1 ${cx - r} ${cy}`;
        }
        const start = polarToCartesian(cx, cy, r, endAngle);
        const end = polarToCartesian(cx, cy, r, startAngle);
        const largeArc = endAngle - startAngle <= 180 ? 0 : 1;
        return `M ${cx} ${cy} L ${start.x} ${start.y} A ${r} ${r} 0 ${largeArc} 0 ${end.x} ${end.y} Z`;
    }

    function polarToCartesian(cx, cy, r, angle) {
        const rad = (angle * Math.PI) / 180;
        return {
            x: cx + r * Math.cos(rad),
            y: cy + r * Math.sin(rad),
        };
    }

    // TREND
    async function renderTrend() {
        const svg = getEl('svgTrend');
        if (!svg) return;

        const params = {
            year: state.year || new Date().getFullYear(),
            buCode: state.buCode,
        };

        const trendKey = JSON.stringify(params);

        // Dedupe: evita chiamata se parametri identici E non è il primo load
        // Primo load: lastTrendKey è stringa vuota, quindi passa sempre
        if (state.lastTrendKey !== '' && trendKey === state.lastTrendKey) {
            debugLog('renderTrend skipped - same params, key:', trendKey);
            return;
        }

        debugLog('renderTrend START - params:', params);

        try {
            const res = await window.customFetch('dashboard_ore', 'getBusinessUnitTrend', params, { showLoader: false });

            debugLog('renderTrend response:', res);

            if (!res.success || !res.data || !res.data.length) {
                svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" fill="#94a3b8" font-size="14">Nessun dato</text>';
                return;
            }

            // Aggiorna key solo dopo successo
            state.lastTrendKey = trendKey;

            drawTrendSVG(svg, res.data);

        } catch (e) {
            console.error('[ore_bu] renderTrend error:', e);
            svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" fill="#ef4444" font-size="14">Errore caricamento</text>';
        }
    }

    function drawTrendSVG(svg, data) {
        const width = svg.clientWidth || 800;
        const height = svg.clientHeight || 220;
        const padding = { top: 20, right: 20, bottom: 30, left: 50 };

        const chartW = width - padding.left - padding.right;
        const chartH = height - padding.top - padding.bottom;

        const maxVal = Math.max(...data.flatMap(d => [d.wh, d.eh]), 1);
        const yScale = chartH / maxVal;

        const n = data.length;
        const xStep = n > 1 ? chartW / (n - 1) : chartW;

        // Build paths
        let whPoints = [];
        let ehPoints = [];
        data.forEach((d, i) => {
            const x = padding.left + i * xStep;
            const yWH = padding.top + chartH - d.wh * yScale;
            const yEH = padding.top + chartH - d.eh * yScale;
            whPoints.push({ x, y: yWH, d });
            ehPoints.push({ x, y: yEH, d });
        });

        // SVG content
        let svgContent = '';

        // Grid lines
        const gridLines = 5;
        for (let i = 0; i <= gridLines; i++) {
            const y = padding.top + (chartH / gridLines) * i;
            const val = Math.round(maxVal - (maxVal / gridLines) * i);
            svgContent += `<line class="orebu-trend-grid" x1="${padding.left}" y1="${y}" x2="${width - padding.right}" y2="${y}"/>`;
            svgContent += `<text class="orebu-trend-label" x="${padding.left - 8}" y="${y + 4}" text-anchor="end">${formatNum(val)}</text>`;
        }

        // X axis labels
        data.forEach((d, i) => {
            const x = padding.left + i * xStep;
            svgContent += `<text class="orebu-trend-label" x="${x}" y="${height - 8}" text-anchor="middle">${d.label}</text>`;
        });

        // Area WH
        svgContent += `<path class="orebu-trend-area" fill="#2563eb" d="${areaPath(whPoints, chartH, padding)}"/>`;

        // Line EH
        if (ehPoints.some(p => p.d.eh > 0)) {
            svgContent += `<path class="orebu-trend-line" stroke="#10b981" d="${linePath(ehPoints)}"/>`;
        }

        // Line WH
        svgContent += `<path class="orebu-trend-line" stroke="#2563eb" d="${linePath(whPoints)}"/>`;

        // Dots WH
        whPoints.forEach(p => {
            const tooltip = `${p.d.label}: ${formatNum(p.d.wh)}h imputate`;
            svgContent += `<circle class="orebu-trend-dot" cx="${p.x}" cy="${p.y}" fill="#2563eb" data-tooltip="${htmlEsc(tooltip)}"><title>${htmlEsc(tooltip)}</title></circle>`;
        });

        // Dots EH
        ehPoints.forEach(p => {
            if (p.d.eh > 0) {
                const tooltip = `${p.d.label}: ${formatNum(p.d.eh)}h budget`;
                svgContent += `<circle class="orebu-trend-dot" cx="${p.x}" cy="${p.y}" fill="#10b981" data-tooltip="${htmlEsc(tooltip)}"><title>${htmlEsc(tooltip)}</title></circle>`;
            }
        });

        svg.innerHTML = svgContent;
    }

    function linePath(points) {
        if (!points.length) return '';
        return 'M ' + points.map(p => `${p.x},${p.y}`).join(' L ');
    }

    function areaPath(points, chartH, padding) {
        if (!points.length) return '';
        const baseline = padding.top + chartH;
        let path = `M ${points[0].x},${baseline}`;
        points.forEach(p => { path += ` L ${p.x},${p.y}`; });
        path += ` L ${points[points.length - 1].x},${baseline} Z`;
        return path;
    }

    // TABELLA COMMESSE
    function renderTableCommesse(rows) {
        // Aggrega per progetto
        const byProject = {};
        rows.forEach(r => {
            if (!byProject[r.projectId]) {
                byProject[r.projectId] = {
                    code: r.projectCode,
                    name: r.projectName,
                    status: r.projectStatus,
                    wh: 0,
                    eh: 0,
                    resources: new Set(),
                };
            }
            byProject[r.projectId].wh += r.wh || 0;
            byProject[r.projectId].eh += r.eh || 0;
            byProject[r.projectId].resources.add(r.resourceId);
        });

        const projects = Object.entries(byProject)
            .map(([id, v]) => ({
                id,
                code: v.code,
                name: v.name,
                status: v.status,
                wh: v.wh,
                eh: v.eh,
                resCount: v.resources.size,
            }))
            .sort((a, b) => b.wh - a.wh);

        const tableCount = getEl('tableCount');
        const tProj = getEl('tProj');

        if (tableCount) tableCount.textContent = projects.length + ' commesse';
        if (!tProj) return;

        tProj.innerHTML = projects.map(p => {
            const residuo = p.eh - p.wh;
            const avanzPct = p.eh > 0 ? Math.round(p.wh / p.eh * 100) : 0;
            const progW = Math.min(avanzPct, 100);
            const progCls = avanzPct > 100 ? 'dboard-bg-red' : avanzPct > 85 ? 'dboard-bg-amber' : 'dboard-bg-green';
            const residuoCol = residuo < 0 ? '#dc2626' : '#16a34a';
            const statusBadge = p.status === 'Aperta'
                ? '<span class="badge badge-success">Aperta</span>'
                : p.status === 'Chiusa'
                    ? '<span class="badge badge-muted">Chiusa</span>'
                    : '<span class="badge">n/d</span>';

            return `<tr>
                <td>
                    <div class="cell-stack">
                        <span class="cell-primary">${htmlEsc(p.code)}</span>
                        <span class="cell-secondary">${htmlEsc(p.name || '')}</span>
                    </div>
                </td>
                <td>${statusBadge}</td>
                <td><span class="muted">-</span></td>
                <td class="text-center">${p.resCount}</td>
                <td class="text-right">${formatNum(p.wh)}h</td>
                <td class="text-right">${p.eh > 0 ? formatNum(p.eh) + 'h' : '<span class="muted">-</span>'}</td>
                <td class="text-right" style="color:${residuoCol}">${p.eh > 0 ? (residuo > 0 ? '+' : '') + formatNum(residuo) + 'h' : '<span class="muted">-</span>'}</td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px" data-tooltip="Avanzamento: ${avanzPct}%">
                        <div style="width:64px;height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden">
                            <div class="${progCls}" style="width:${progW}%;height:100%;border-radius:3px"></div>
                        </div>
                        <span style="font-size:11px;color:#475569">${avanzPct}%</span>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    // TABELLA RISORSE
    function renderTableRisorse(rows) {
        // Aggrega per risorsa
        const byRes = {};
        rows.forEach(r => {
            if (!byRes[r.resourceId]) {
                byRes[r.resourceId] = {
                    name: r.resourceName,
                    role: r.resourceRole,
                    wh: 0,
                    eh: 0,
                    projects: new Set(),
                };
            }
            byRes[r.resourceId].wh += r.wh || 0;
            byRes[r.resourceId].eh += r.eh || 0;
            byRes[r.resourceId].projects.add(r.projectId);
        });

        const resources = Object.entries(byRes)
            .map(([id, v]) => ({
                id,
                name: v.name,
                role: v.role,
                wh: v.wh,
                eh: v.eh,
                projCount: v.projects.size,
            }))
            .sort((a, b) => b.wh - a.wh);

        const resCount = getEl('resCount');
        const tRes = getEl('tRes');

        if (resCount) resCount.textContent = resources.length + ' risorse';
        if (!tRes) return;

        tRes.innerHTML = resources.map(r => {
            const avanzPct = r.eh > 0 ? Math.round(r.wh / r.eh * 100) : 0;
            const progW = Math.min(avanzPct, 100);
            const progCls = avanzPct > 100 ? 'dboard-bg-red' : avanzPct > 85 ? 'dboard-bg-amber' : 'dboard-bg-green';

            return `<tr>
                <td>
                    <div class="cell-stack">
                        <span class="cell-primary">${htmlEsc(r.name)}</span>
                    </div>
                </td>
                <td><span class="muted">${htmlEsc(r.role || '-')}</span></td>
                <td class="text-center">${r.projCount}</td>
                <td class="text-right">${formatNum(r.wh)}h</td>
                <td class="text-right">${r.eh > 0 ? formatNum(r.eh) + 'h' : '<span class="muted">-</span>'}</td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px" data-tooltip="Avanzamento: ${avanzPct}%">
                        <div style="width:64px;height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden">
                            <div class="${progCls}" style="width:${progW}%;height:100%;border-radius:3px"></div>
                        </div>
                        <span style="font-size:11px;color:#475569">${r.eh > 0 ? avanzPct + '%' : '-'}</span>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    // Export CSV
    function exportCSV() {
        if (typeof window.showToast === 'function') {
            window.showToast('Export in preparazione...', 'info');
        }

        // Genera CSV lato client dai dati filtrati
        const filtered = filterRows();
        if (!filtered.length) {
            if (typeof window.showToast === 'function') {
                window.showToast('Nessun dato da esportare', 'warning');
            }
            return;
        }

        const headers = ['BU', 'Mese', 'Commessa', 'Risorsa', 'Ruolo', 'Ore Imputate', 'Ore Budget'];
        const csvRows = [headers.join(';')];

        filtered.forEach(r => {
            csvRows.push([
                r.bu,
                r.ym,
                r.projectCode,
                r.resourceName,
                r.resourceRole || '',
                r.wh,
                r.eh,
            ].map(v => `"${String(v).replace(/"/g, '""')}"`).join(';'));
        });

        const csvContent = '\uFEFF' + csvRows.join('\n'); // BOM UTF-8
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `ore_bu_${state.year || 'all'}_${state.buCode || 'all'}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);

        if (typeof window.showToast === 'function') {
            window.showToast('CSV scaricato', 'success');
        }
    }

    // View toggle
    function showView(view) {
        state.view = view;
        const skeleton = getEl('buSkeleton');
        const empty = getEl('buEmpty');
        const error = getEl('buError');
        const data = getEl('buData');

        if (skeleton) skeleton.classList.toggle('hidden', view !== 'skeleton');
        if (empty) empty.classList.toggle('hidden', view !== 'empty');
        if (error) error.classList.toggle('hidden', view !== 'error');
        if (data) data.classList.toggle('hidden', view !== 'data');
    }

    // Start
    initPage();
});
