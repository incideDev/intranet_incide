/**
 * Ore Dettaglio Utente — Intra_Incide
 * Dipendenze: customFetch (main_core.js), oreHelpers.js
 */

document.addEventListener('DOMContentLoaded', function () {

    // Import helpers dal modulo condiviso
    const { getEl, getEls, formatNum, htmlEsc, PIE_COLORS } = window.oreHelpers || {};

    const DEBUG = false;
    function debugLog(...args) {
        if (DEBUG) console.log('[ore_user]', ...args);
    }

    // Config dal PHP
    const CONFIG = window.OREUSER_CONFIG || {
        canViewOthers: false,
        currentUserId: '',
        currentUserName: '',
        currentUserRole: '',
        currentUserInitials: ''
    };

    // State
    const state = {
        view: 'skeleton',
        userId: CONFIG.currentUserId,
        dateFrom: '',
        dateTo: '',
        year: '',
        projectId: '',
        userInfo: {},
        filters: {},
        rows: [],
        isLoading: false,  // Guard contro chiamate duplicate
    };

    // Init
    async function initPage() {
        // Imposta default anno corrente
        state.year = new Date().getFullYear().toString();
        const fAnno = getEl('fAnno');
        if (fAnno) {
            fAnno.innerHTML = `<option value="${state.year}">${state.year}</option>`;
        }

        await loadData();
        setupEventListeners();
    }

    function setupEventListeners() {
        const btnApplica = getEl('btnApplica');
        const btnReset = getEl('btnReset');
        const btnResetEmpty = getEl('btnResetEmpty');
        const btnRetry = getEl('btnRetry');
        const btnExportCSV = getEl('btnExportCSV');

        if (btnApplica) btnApplica.addEventListener('click', applyFilters);
        if (btnReset) btnReset.addEventListener('click', resetFilters);
        if (btnResetEmpty) btnResetEmpty.addEventListener('click', resetFilters);
        if (btnRetry) btnRetry.addEventListener('click', () => loadData());
        if (btnExportCSV) btnExportCSV.addEventListener('click', exportCSV);

        // Cambio anno aggiorna date range
        const fAnno = getEl('fAnno');
        if (fAnno) {
            fAnno.addEventListener('change', function() {
                const y = this.value;
                if (y) {
                    const fDal = getEl('fDal');
                    const fAl = getEl('fAl');
                    if (fDal) fDal.value = y + '-01-01';
                    if (fAl) fAl.value = y + '-12-31';
                }
            });
        }
    }

    async function loadData() {
        // Guard contro chiamate duplicate
        if (state.isLoading) {
            debugLog('loadData skipped - already loading');
            return;
        }
        state.isLoading = true;
        showView('skeleton');

        try {
            const params = {
                userId: state.userId,
                dateFrom: state.dateFrom,
                dateTo: state.dateTo,
                year: state.year,
                projectId: state.projectId,
            };

            debugLog('loadData params:', params);

            const res = await window.customFetch('dashboard_ore', 'getUserDetailData', params, { showLoader: false });

            if (!res.success) {
                console.error('[ore_user] loadData failed:', res.message);
                showView('error');
                return;
            }

            state.userInfo = res.data.userInfo || {};
            state.filters = res.data.filters || {};
            state.rows = res.data.rows || [];

            // Aggiorna state.userId al resourceCode restituito dal backend
            // Questo allinea il frontend con il vero identificatore usato nelle query
            if (res.data.resourceCode) {
                debugLog('Updating state.userId from', state.userId, 'to', res.data.resourceCode);
                state.userId = res.data.resourceCode;
            }

            debugLog('loadData result: rows=', state.rows.length, 'userInfo=', state.userInfo);

            if (state.rows.length === 0) {
                populateFilters();
                updateUserChip();
                updateActiveChips();
                showView('empty');
                return;
            }

            populateFilters();
            updateUserChip();
            updateActiveChips();
            renderAll();
            showView('data');

        } catch (e) {
            console.error('[ore_user] loadData error:', e);
            showView('error');
        } finally {
            state.isLoading = false;
        }
    }

    function applyFilters() {
        const fUtente = getEl('fUtente');
        const fDal = getEl('fDal');
        const fAl = getEl('fAl');
        const fAnno = getEl('fAnno');
        const fCommessa = getEl('fCommessa');

        state.userId = fUtente ? fUtente.value : CONFIG.currentUserId;
        state.dateFrom = fDal ? fDal.value : '';
        state.dateTo = fAl ? fAl.value : '';
        state.year = fAnno ? fAnno.value : '';
        state.projectId = fCommessa ? fCommessa.value : '';

        loadData();
    }

    function resetFilters() {
        state.userId = CONFIG.currentUserId;
        state.dateFrom = '';
        state.dateTo = '';
        state.year = new Date().getFullYear().toString();
        state.projectId = '';

        const fUtente = getEl('fUtente');
        const fDal = getEl('fDal');
        const fAl = getEl('fAl');
        const fAnno = getEl('fAnno');
        const fCommessa = getEl('fCommessa');

        if (fUtente) fUtente.value = CONFIG.currentUserId;
        if (fDal) fDal.value = '';
        if (fAl) fAl.value = '';
        if (fAnno) fAnno.value = state.year;
        if (fCommessa) fCommessa.value = '';

        loadData();
    }

    function populateFilters() {
        const f = state.filters;

        // Utenti (solo se admin)
        if (CONFIG.canViewOthers) {
            const fUtente = getEl('fUtente');
            if (fUtente && f.users && f.users.length > 0) {
                fUtente.innerHTML = '';
                f.users.forEach(u => {
                    const opt = document.createElement('option');
                    opt.value = u.id;  // Questo è il resourceCode (es. "SME")
                    opt.textContent = u.name || u.id;
                    // state.userId è già resourceCode dopo la risposta del backend
                    if (u.id === state.userId) opt.selected = true;
                    fUtente.appendChild(opt);
                });
            }
        }

        // Anni
        const fAnno = getEl('fAnno');
        if (fAnno && f.years) {
            fAnno.innerHTML = '';
            f.years.forEach(y => {
                const opt = document.createElement('option');
                opt.value = y;
                opt.textContent = y;
                if (String(y) === String(state.year)) opt.selected = true;
                fAnno.appendChild(opt);
            });
        }

        // Commesse
        const fCommessa = getEl('fCommessa');
        if (fCommessa && f.projects) {
            fCommessa.innerHTML = '<option value="">Tutte</option>';
            f.projects.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.code || p.id;
                if (p.id === state.projectId) opt.selected = true;
                fCommessa.appendChild(opt);
            });
        }

        // Date range
        const fDal = getEl('fDal');
        const fAl = getEl('fAl');
        if (fDal && f.dateFrom) fDal.value = f.dateFrom;
        if (fAl && f.dateTo) fAl.value = f.dateTo;
    }

    function updateUserChip() {
        const info = state.userInfo;
        const userName = getEl('userName');
        const userRole = getEl('userRole');
        const userAvatar = getEl('userAvatar');

        if (userName) userName.textContent = info.name || CONFIG.currentUserName || '';
        if (userRole) userRole.textContent = info.role || CONFIG.currentUserRole || '';

        if (userAvatar && info.name) {
            const parts = info.name.split(' ');
            const initials = parts.length >= 2
                ? (parts[0][0] + parts[1][0]).toUpperCase()
                : (info.name.substring(0, 2).toUpperCase());
            userAvatar.textContent = initials;
        }
    }

    function updateActiveChips() {
        const container = getEl('activeChips');
        if (!container) return;

        const chips = [];

        if (state.projectId) {
            const proj = (state.filters.projects || []).find(p => p.id === state.projectId);
            chips.push({
                label: 'Commessa: ' + (proj?.code || state.projectId),
                clear: () => { state.projectId = ''; const f = getEl('fCommessa'); if(f) f.value=''; loadData(); }
            });
        }

        if (state.dateFrom || state.dateTo) {
            chips.push({
                label: 'Periodo: ' + (state.dateFrom || '...') + ' - ' + (state.dateTo || '...'),
                clear: () => {
                    state.dateFrom = ''; state.dateTo = '';
                    const fDal = getEl('fDal'); const fAl = getEl('fAl');
                    if(fDal) fDal.value=''; if(fAl) fAl.value='';
                    loadData();
                }
            });
        }

        if (chips.length === 0) {
            container.innerHTML = '';
            container.style.display = 'none';
            return;
        }

        container.style.display = 'flex';
        container.innerHTML = chips.map((c, i) => `
            <span class="oreuser-chip" data-idx="${i}">
                ${htmlEsc(c.label)}
                <button class="oreuser-chip-close" data-idx="${i}">&times;</button>
            </span>
        `).join('');

        container.querySelectorAll('.oreuser-chip-close').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                const idx = parseInt(this.dataset.idx);
                if (chips[idx] && chips[idx].clear) chips[idx].clear();
            });
        });
    }

    function renderAll() {
        const rows = state.rows;
        renderKPIs(rows);
        renderPieBudget(rows);
        renderPieImputate(rows);
        renderTrend();
        renderTableCommesse(rows);
    }

    // KPIs
    function renderKPIs(rows) {
        const totalWH = rows.reduce((sum, r) => sum + (r.wh || 0), 0);
        const totalEH = rows.reduce((sum, r) => sum + (r.eh || 0), 0);
        const residuo = totalEH - totalWH;
        const avanzPct = totalEH > 0 ? Math.round(totalWH / totalEH * 100) : 0;
        const projectCount = new Set(rows.map(r => r.projectId)).size;

        const kSp = getEl('kSp');
        const kSpSub = getEl('kSpSub');
        const kSpBar = getEl('kSpBar');
        const kBu = getEl('kBu');
        const kRes = getEl('kRes');
        const kResDelta = getEl('kResDelta');
        const kAv = getEl('kAv');
        const kAvS = getEl('kAvS');
        const kAvBar = getEl('kAvBar');
        const kCo = getEl('kCo');
        const kCoS = getEl('kCoS');

        if (kSp) kSp.textContent = formatNum(totalWH) + 'h';
        if (kSpSub) kSpSub.textContent = `su ${formatNum(totalEH)}h budget`;
        const whPct = totalEH > 0 ? Math.min(Math.round(totalWH / totalEH * 100), 100) : 0;
        if (kSpBar) kSpBar.style.width = whPct + '%';

        if (kBu) kBu.textContent = formatNum(totalEH) + 'h';

        if (kRes) {
            kRes.textContent = formatNum(Math.abs(residuo)) + 'h';
            kRes.style.color = residuo < 0 ? '#dc2626' : '#16a34a';
        }
        if (kResDelta) {
            kResDelta.textContent = residuo < 0 ? 'sforamento' : 'disponibili';
            kResDelta.className = 'dboard-delta ' + (residuo < 0 ? 'dboard-delta--down' : 'dboard-delta--up');
        }

        if (kAv) kAv.textContent = avanzPct + '%';
        if (kAvS) kAvS.textContent = totalEH > 0 ? 'vs budget' : 'nessun budget';
        if (kAvBar) {
            kAvBar.style.width = Math.min(avanzPct, 100) + '%';
            kAvBar.className = 'dboard-kpi-bar-fill ' +
                (avanzPct > 100 ? 'dboard-bg-red' : avanzPct > 85 ? 'dboard-bg-amber' : 'dboard-bg-green');
        }

        if (kCo) kCo.textContent = projectCount;
        if (kCoS) kCoS.textContent = 'nel periodo';
    }

    // PIE: Budget per commessa
    function renderPieBudget(rows) {
        const byProject = {};
        rows.forEach(r => {
            if (!byProject[r.projectId]) {
                byProject[r.projectId] = { code: r.projectCode, eh: 0 };
            }
            byProject[r.projectId].eh += r.eh || 0;
        });

        const sorted = Object.entries(byProject)
            .map(([id, v]) => ({ id, code: v.code, eh: v.eh }))
            .filter(p => p.eh > 0)
            .sort((a, b) => b.eh - a.eh)
            .slice(0, 8);

        const total = sorted.reduce((s, p) => s + p.eh, 0);

        const pieTot1 = getEl('pieTot1');
        if (pieTot1) pieTot1.textContent = formatNum(total) + 'h totali';

        renderPie('svgBudget', 'legBudget', sorted.map((p, i) => ({
            label: p.code || p.id,
            value: p.eh,
            pct: total > 0 ? Math.round(p.eh / total * 100) : 0,
            color: PIE_COLORS[i % PIE_COLORS.length],
        })));
    }

    // PIE: Imputate per commessa
    function renderPieImputate(rows) {
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

        const pieTot2 = getEl('pieTot2');
        if (pieTot2) pieTot2.textContent = formatNum(total) + 'h totali';

        renderPie('svgSpese', 'legSpese', sorted.map((p, i) => ({
            label: p.code || p.id,
            value: p.wh,
            pct: total > 0 ? Math.round(p.wh / total * 100) : 0,
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
        const trendLabel = getEl('trendLabel');
        if (!svg) return;

        if (trendLabel) trendLabel.textContent = 'Anno ' + (state.year || new Date().getFullYear());

        try {
            const params = {
                userId: state.userId,
                year: state.year || new Date().getFullYear(),
            };
            const res = await window.customFetch('dashboard_ore', 'getUserDetailTrend', params, { showLoader: false });

            if (!res.success || !res.data || !res.data.length) {
                svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" fill="#94a3b8" font-size="14">Nessun dato</text>';
                return;
            }

            drawTrendSVG(svg, res.data);

        } catch (e) {
            console.error('[ore_user] renderTrend error:', e);
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

        let whPoints = [];
        let ehPoints = [];
        data.forEach((d, i) => {
            const x = padding.left + i * xStep;
            const yWH = padding.top + chartH - d.wh * yScale;
            const yEH = padding.top + chartH - d.eh * yScale;
            whPoints.push({ x, y: yWH, d });
            ehPoints.push({ x, y: yEH, d });
        });

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
        const byProject = {};
        rows.forEach(r => {
            if (!byProject[r.projectId]) {
                byProject[r.projectId] = {
                    code: r.projectCode,
                    bu: r.bu,
                    wh: 0,
                    eh: 0,
                };
            }
            byProject[r.projectId].wh += r.wh || 0;
            byProject[r.projectId].eh += r.eh || 0;
        });

        const projects = Object.entries(byProject)
            .map(([id, v]) => ({
                id,
                code: v.code,
                bu: v.bu,
                wh: v.wh,
                eh: v.eh,
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

            return `<tr>
                <td>
                    <div class="cell-stack">
                        <span class="cell-primary">${htmlEsc(p.code)}</span>
                    </div>
                </td>
                <td><span class="muted">${htmlEsc(p.bu || '-')}</span></td>
                <td class="text-right">${formatNum(p.wh)}h</td>
                <td class="text-right">${p.eh > 0 ? formatNum(p.eh) + 'h' : '<span class="muted">-</span>'}</td>
                <td class="text-right" style="color:${residuoCol}">${p.eh > 0 ? (residuo > 0 ? '+' : '') + formatNum(residuo) + 'h' : '<span class="muted">-</span>'}</td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px" data-tooltip="Avanzamento: ${avanzPct}%">
                        <div style="width:64px;height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden">
                            <div class="${progCls}" style="width:${progW}%;height:100%;border-radius:3px"></div>
                        </div>
                        <span style="font-size:11px;color:#475569">${p.eh > 0 ? avanzPct + '%' : '-'}</span>
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

        const rows = state.rows;
        if (!rows.length) {
            if (typeof window.showToast === 'function') {
                window.showToast('Nessun dato da esportare', 'warning');
            }
            return;
        }

        const headers = ['Commessa', 'Business Unit', 'Mese', 'Ore Imputate', 'Ore Budget'];
        const csvRows = [headers.join(';')];

        rows.forEach(r => {
            csvRows.push([
                r.projectCode,
                r.bu || '',
                r.ym,
                r.wh,
                r.eh,
            ].map(v => `"${String(v).replace(/"/g, '""')}"`).join(';'));
        });

        const csvContent = '\uFEFF' + csvRows.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `ore_utente_${state.userId}_${state.year || 'all'}.csv`;
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
        const skeleton = getEl('userSkeleton');
        const empty = getEl('userEmpty');
        const error = getEl('userError');
        const data = getEl('userData');

        if (skeleton) skeleton.classList.toggle('hidden', view !== 'skeleton');
        if (empty) empty.classList.toggle('hidden', view !== 'empty');
        if (error) error.classList.toggle('hidden', view !== 'error');
        if (data) data.classList.toggle('hidden', view !== 'data');
    }

    // Start
    initPage();
});
