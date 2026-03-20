/**
 * Dashboard Economica V2
 * Tab: Overview, Commesse & SAL, Costi, Scadenze & Pagamenti, Cash Flow, HR Economico, Pipeline
 */

document.addEventListener('DOMContentLoaded', function () {

    // ═══════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════

    function getEl(id) {
        return document.getElementById(id);
    }

    function bindEl(id, event, handler) {
        const el = getEl(id);
        if (el) {
            el.addEventListener(event, handler);
        }
        return el;
    }

    function htmlEsc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatNum(val, decimals = 0) {
        const num = parseFloat(val) || 0;
        return num.toLocaleString('it-IT', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function formatCurrency(val) {
        const num = parseFloat(val) || 0;
        return num.toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
    }

    function formatPercent(val) {
        const num = parseFloat(val) || 0;
        return num.toFixed(1) + '%';
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString('it-IT');
    }

    function getDeltaClass(delta) {
        if (delta > 0) return 'pill-danger';
        if (delta < 0) return 'pill-success';
        return 'pill-info';
    }

    function getProgressClass(progress) {
        if (progress >= 80) return 'pill-success';
        if (progress >= 50) return 'pill-warning';
        return 'pill-info';
    }

    // ═══════════════════════════════════════════════════════════
    // STATE
    // ═══════════════════════════════════════════════════════════

    const state = {
        year: new Date().getFullYear().toString(),
        bu: '',
        pm: '',
        cliente: '',
        currentTab: 'overview',
        loadedTabs: new Set(),
        isLoading: false
    };

    // DOM refs
    const $loading = getEl('dashLoading');
    const $error = getEl('dashError');
    const $panels = getEl('dashPanels');

    // ═══════════════════════════════════════════════════════════
    // INIT
    // ═══════════════════════════════════════════════════════════

    async function initPage() {
        bindEvents();
        await loadFilterOptions();
        await loadTabData('overview');
    }

    function bindEvents() {
        // Tab switching
        document.querySelectorAll('.dboard-tab').forEach(tab => {
            tab.addEventListener('click', function () {
                const tabName = this.dataset.tab;
                switchTab(tabName);
            });
        });

        // Apply filters
        bindEl('btnApply', 'click', applyFilters);

        // Reset
        bindEl('btnReset', 'click', resetFilters);

        // Retry
        bindEl('btnRetry', 'click', function () {
            loadTabData(state.currentTab);
        });

        // Year change
        bindEl('filterYear', 'change', function () {
            state.year = this.value;
            const yearLabel = getEl('currentYearLabel');
            if (yearLabel) yearLabel.textContent = this.value;
        });
    }

    function switchTab(tabName) {
        // Update tab buttons
        document.querySelectorAll('.dboard-tab').forEach(t => t.classList.remove('active'));
        const activeTab = document.querySelector(`.dboard-tab[data-tab="${tabName}"]`);
        if (activeTab) activeTab.classList.add('active');

        // Update panels
        document.querySelectorAll('.dboard-panel').forEach(p => p.classList.remove('active'));
        const panel = getEl('panel-' + tabName);
        if (panel) panel.classList.add('active');

        state.currentTab = tabName;

        // Load data if not cached
        if (!state.loadedTabs.has(tabName)) {
            loadTabData(tabName);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // FILTERS
    // ═══════════════════════════════════════════════════════════

    async function loadFilterOptions() {
        try {
            const res = await window.customFetch('dashboard_economica', 'getFilterOptions', {}, { showLoader: false });
            if (!res.success) {
                console.error('[dashboard_economica] getFilterOptions failed:', res.message);
                return;
            }

            // Years
            const yearSelect = getEl('filterYear');
            if (yearSelect && res.data.years && res.data.years.length > 0) {
                yearSelect.innerHTML = '';
                res.data.years.forEach(y => {
                    const opt = document.createElement('option');
                    opt.value = y.value;
                    opt.textContent = y.label;
                    yearSelect.appendChild(opt);
                });
                const currentYear = new Date().getFullYear().toString();
                if (res.data.years.some(y => y.value == currentYear)) {
                    yearSelect.value = currentYear;
                    state.year = currentYear;
                } else {
                    state.year = yearSelect.value;
                }
            }

            // Business Units
            populateSelect(getEl('filterBU'), res.data.businessUnits, 'value', 'label', 'Tutte le BU');

            // Project Managers
            populateSelect(getEl('filterPM'), res.data.projectManagers, 'value', 'label', 'Tutti i PM');

            // Clienti
            populateSelect(getEl('filterCliente'), res.data.clienti, 'value', 'label', 'Tutti i clienti');

        } catch (e) {
            console.error('[dashboard_economica] loadFilterOptions error:', e);
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
            opt.value = item[valKey];
            opt.textContent = item[labelKey];
            selectEl.appendChild(opt);
        });
    }

    function applyFilters() {
        const yearEl = getEl('filterYear');
        const buEl = getEl('filterBU');
        const pmEl = getEl('filterPM');
        const clienteEl = getEl('filterCliente');
        const yearLabel = getEl('currentYearLabel');

        state.year = yearEl ? yearEl.value : state.year;
        state.bu = buEl ? buEl.value : '';
        state.pm = pmEl ? pmEl.value : '';
        state.cliente = clienteEl ? clienteEl.value : '';

        if (yearLabel) yearLabel.textContent = state.year;

        // Clear cache and reload current tab
        state.loadedTabs.clear();
        loadTabData(state.currentTab);
    }

    function resetFilters() {
        const buEl = getEl('filterBU');
        const pmEl = getEl('filterPM');
        const clienteEl = getEl('filterCliente');

        if (buEl) buEl.value = '';
        if (pmEl) pmEl.value = '';
        if (clienteEl) clienteEl.value = '';
        state.bu = '';
        state.pm = '';
        state.cliente = '';
        applyFilters();
    }

    function getFilters() {
        return {
            year: state.year,
            bu: state.bu,
            pm: state.pm,
            cliente: state.cliente
        };
    }

    // ═══════════════════════════════════════════════════════════
    // TAB DATA LOADING
    // ═══════════════════════════════════════════════════════════

    async function loadTabData(tabName) {
        showLoading();

        try {
            switch (tabName) {
                case 'overview':
                    await loadOverviewData();
                    break;
                case 'commesse':
                    await loadCommesseData();
                    break;
                case 'costi':
                    await loadCostiData();
                    break;
                case 'scadenze':
                    await loadScadenzeData();
                    break;
                case 'cashflow':
                    await loadCashflowData();
                    break;
                case 'hr':
                    await loadHrData();
                    break;
                case 'pipeline':
                    await loadPipelineData();
                    break;
            }
            state.loadedTabs.add(tabName);
            showPanels();
        } catch (e) {
            console.error('[dashboard_economica] loadTabData error:', e);
            showError(e.message || 'Errore di caricamento');
        }
    }

    // ═══════════════════════════════════════════════════════════
    // OVERVIEW
    // ═══════════════════════════════════════════════════════════

    async function loadOverviewData() {
        const filters = getFilters();

        const [kpiRes, trendRes, projectsRes] = await Promise.all([
            window.customFetch('dashboard_economica', 'getOverviewKpi', filters, { showLoader: false }),
            window.customFetch('dashboard_economica', 'getHoursTrend', filters, { showLoader: false }),
            window.customFetch('dashboard_economica', 'getProjectsEconomicSummary', filters, { showLoader: false })
        ]);

        if (!kpiRes.success) {
            throw new Error(kpiRes.message || 'Errore caricamento KPI');
        }

        renderOverviewKpi(kpiRes.data);

        if (trendRes.success) {
            renderHoursTrend(trendRes.data);
        }

        // Render BU distribution from KPI data
        if (kpiRes.data && kpiRes.data.counts) {
            renderBuDistribution(kpiRes.data);
        }

        // Render latest projects (top 10)
        if (projectsRes.success && projectsRes.data) {
            renderOverviewLatestProjects(projectsRes.data.slice(0, 10));
        }
    }

    function renderOverviewKpi(data) {
        const container = getEl('kpiOverview');
        if (!container) return;

        const hours = data.hours || {};
        const milestones = data.milestones || {};
        const installments = data.installments || {};
        const counts = data.counts || {};
        const economics = data.economics || {};

        const kpis = [
            {
                label: 'Commesse Attive',
                value: formatNum(counts.totalCommesse),
                hint: `${formatNum(counts.totalClienti)} clienti`
            },
            {
                label: 'Ore Lavorate',
                value: formatNum(hours.totalWorkHours, 0),
                hint: `${formatNum(hours.projectCount)} progetti`
            },
            {
                label: 'Valore Milestones',
                value: formatCurrency(milestones.totalValue),
                hint: `${formatNum(milestones.projectCount)} progetti`
            },
            {
                label: 'Costo Lavoro Prev.',
                value: formatCurrency(milestones.totalQuotLaborCost),
                hint: `Ore: ${formatNum(milestones.totalQuotHours, 0)}`
            },
            {
                label: 'Costo Lavoro Eff.',
                value: formatCurrency(milestones.totalLaborCost),
                hint: `Ore: ${formatNum(milestones.totalHoursWorked, 0)}`
            },
            {
                label: 'Scostamento',
                value: formatCurrency(economics.deltaCosto),
                hint: formatPercent(economics.deltaPercent),
                colorClass: economics.deltaCosto > 0 ? 'kpi-danger' : (economics.deltaCosto < 0 ? 'kpi-success' : '')
            },
            {
                label: 'Costo Medio/Ora',
                value: formatCurrency(economics.costoMedioOrario),
                hint: ''
            },
            {
                label: 'Avanzamento',
                value: formatPercent(milestones.avgProgress),
                hint: ''
            },
            {
                label: 'Rate Anno',
                value: formatNum(installments.total),
                hint: `Fatturate: ${formatNum(installments.billed)}`
            },
            {
                label: 'Valore Rate',
                value: formatCurrency(installments.expectedValue),
                hint: ''
            }
        ];

        container.innerHTML = kpis.map(k => `
            <div class="dboard-kpi-card ${k.colorClass || ''}">
                <div class="dboard-kpi-label">${htmlEsc(k.label)}</div>
                <div class="dboard-kpi-value">${k.value}</div>
                ${k.hint ? `<div class="dboard-kpi-hint">${htmlEsc(k.hint)}</div>` : ''}
            </div>
        `).join('');
    }

    function renderHoursTrend(data) {
        const container = getEl('chartHoursTrend');
        if (!container) return;

        if (!data || data.length === 0) {
            container.innerHTML = '<p class="muted text-center">Nessun dato disponibile per il trend.</p>';
            return;
        }

        const maxHours = Math.max(...data.map(d => d.totalHours || 0));
        const monthNames = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

        const barWidth = 40;
        const barGap = 8;
        const chartHeight = 180;
        const chartWidth = data.length * (barWidth + barGap);

        let barsHtml = '';
        data.forEach((d, i) => {
            const h = maxHours > 0 ? (d.totalHours / maxHours) * (chartHeight - 40) : 0;
            const x = i * (barWidth + barGap);
            const y = chartHeight - h - 25;
            barsHtml += `
                <g class="dboard-bar-group">
                    <rect x="${x}" y="${y}" width="${barWidth}" height="${h}" fill="var(--color-primary, #3b82f6)" rx="4"/>
                    <text x="${x + barWidth / 2}" y="${chartHeight - 8}" text-anchor="middle" class="dboard-chart-label">${monthNames[d.month - 1] || d.month}</text>
                    <text x="${x + barWidth / 2}" y="${y - 5}" text-anchor="middle" class="dboard-chart-value">${formatNum(d.totalHours)}</text>
                </g>
            `;
        });

        container.innerHTML = `
            <svg width="100%" height="${chartHeight}" viewBox="0 0 ${Math.max(chartWidth, 400)} ${chartHeight}" preserveAspectRatio="xMinYMax meet">
                ${barsHtml}
            </svg>
        `;
    }

    function renderBuDistribution(data) {
        const container = getEl('chartBuDistribution');
        if (!container) return;

        const hours = data.hours || {};
        const counts = data.counts || {};

        container.innerHTML = `
            <div class="dboard-stat-list">
                <div class="dboard-stat-item">
                    <span class="dboard-stat-label">Progetti attivi</span>
                    <span class="dboard-stat-value">${formatNum(hours.projectCount)}</span>
                </div>
                <div class="dboard-stat-item">
                    <span class="dboard-stat-label">Risorse impegnate</span>
                    <span class="dboard-stat-value">${formatNum(hours.resourceCount)}</span>
                </div>
                <div class="dboard-stat-item">
                    <span class="dboard-stat-label">Clienti</span>
                    <span class="dboard-stat-value">${formatNum(counts.totalClienti)}</span>
                </div>
                <div class="dboard-stat-item">
                    <span class="dboard-stat-label">Ore viaggio</span>
                    <span class="dboard-stat-value">${formatNum(hours.totalTravelHours)}</span>
                </div>
            </div>
        `;
    }

    function renderOverviewLatestProjects(projects) {
        renderSimpleTable(
            'tbodyOverviewLatest', 'overviewLatestCount',
            projects || [], 7,
            row => {
                const progressClass = getProgressClass(row.avgProgress);
                return `
                    <tr>
                        <td><strong>${htmlEsc(row.projectCode)}</strong></td>
                        <td class="text-truncate">${htmlEsc(row.projectDesc)}</td>
                        <td>${htmlEsc(row.customerName)}</td>
                        <td>${htmlEsc(row.projectManager)}</td>
                        <td>${htmlEsc(row.businessUnit || '-')}</td>
                        <td class="text-right">${formatCurrency(row.milestoneValue)}</td>
                        <td class="text-right"><span class="pill ${progressClass}">${formatPercent(row.avgProgress)}</span></td>
                    </tr>
                `;
            }
        );
    }

    // ═══════════════════════════════════════════════════════════
    // COMMESSE & SAL
    // ═══════════════════════════════════════════════════════════

    async function loadCommesseData() {
        const filters = getFilters();
        const [projectsRes, kpiRes] = await Promise.all([
            window.customFetch('dashboard_economica', 'getProjectsEconomicSummary', filters, { showLoader: false }),
            window.customFetch('dashboard_economica', 'getOverviewKpi', filters, { showLoader: false })
        ]);

        if (!projectsRes.success) {
            throw new Error(projectsRes.message || 'Errore caricamento commesse');
        }

        // Render KPI for Commesse tab
        if (kpiRes.success) {
            renderCommesseKpi(kpiRes.data);
        }

        renderCommesseTable(projectsRes.data);
        renderSalSummary(projectsRes.data);
    }

    function renderCommesseKpi(data) {
        const container = getEl('kpiCommesse');
        if (!container) return;

        const milestones = data.milestones || {};
        const economics = data.economics || {};

        const kpis = [
            { label: 'Commesse', value: formatNum(milestones.projectCount), hint: '' },
            { label: 'Valore Totale', value: formatCurrency(milestones.totalValue), hint: '' },
            { label: 'Avanzamento Medio', value: formatPercent(milestones.avgProgress), hint: '' },
            {
                label: 'Scostamento Costo',
                value: formatCurrency(economics.deltaCosto),
                colorClass: economics.deltaCosto > 0 ? 'kpi-danger' : (economics.deltaCosto < 0 ? 'kpi-success' : '')
            }
        ];

        container.innerHTML = kpis.map(k => `
            <div class="dboard-kpi-card ${k.colorClass || ''}">
                <div class="dboard-kpi-label">${htmlEsc(k.label)}</div>
                <div class="dboard-kpi-value">${k.value}</div>
            </div>
        `).join('');
    }

    function renderCommesseTable(data) {
        renderSimpleTable(
            'tbodyCommesse', 'commesseCount',
            data || [], 12,
            row => {
                const progressClass = getProgressClass(row.avgProgress);
                const deltaClass = getDeltaClass(row.deltaCost);
                return `
                    <tr>
                        <td><strong>${htmlEsc(row.projectCode)}</strong></td>
                        <td class="text-truncate">${htmlEsc(row.projectDesc)}</td>
                        <td>${htmlEsc(row.customerName)}</td>
                        <td>${htmlEsc(row.projectManager)}</td>
                        <td><span class="pill">${htmlEsc(row.projectStatus || '-')}</span></td>
                        <td class="text-right">${formatCurrency(row.milestoneValue)}</td>
                        <td class="text-right">${formatNum(row.quotExpectedHours, 0)}</td>
                        <td class="text-right">${formatNum(row.hoursWorked, 0)}</td>
                        <td class="text-right">${formatCurrency(row.quotLaborCost)}</td>
                        <td class="text-right">${formatCurrency(row.laborCost)}</td>
                        <td class="text-right"><span class="pill ${deltaClass}">${formatCurrency(row.deltaCost)}</span></td>
                        <td class="text-right"><span class="pill ${progressClass}">${formatPercent(row.avgProgress)}</span></td>
                    </tr>
                `;
            }
        );
    }

    function renderSalSummary(data) {
        const container = getEl('salSummaryContent');
        if (!container) return;

        if (!data || data.length === 0) {
            container.innerHTML = '<p class="muted text-center">Nessun dato SAL disponibile</p>';
            return;
        }

        // Group by progress ranges
        const ranges = [
            { label: '0-25%', min: 0, max: 25, count: 0, value: 0 },
            { label: '25-50%', min: 25, max: 50, count: 0, value: 0 },
            { label: '50-75%', min: 50, max: 75, count: 0, value: 0 },
            { label: '75-100%', min: 75, max: 100, count: 0, value: 0 },
            { label: '>100%', min: 100, max: Infinity, count: 0, value: 0 }
        ];

        data.forEach(p => {
            const prog = p.avgProgress || 0;
            for (const r of ranges) {
                if (prog >= r.min && prog < r.max) {
                    r.count++;
                    r.value += p.milestoneValue || 0;
                    break;
                }
            }
        });

        container.innerHTML = `
            <div class="dboard-sal-grid">
                ${ranges.map(r => `
                    <div class="dboard-sal-item">
                        <div class="dboard-sal-label">${r.label}</div>
                        <div class="dboard-sal-count">${r.count} commesse</div>
                        <div class="dboard-sal-value">${formatCurrency(r.value)}</div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    // ═══════════════════════════════════════════════════════════
    // COSTI
    // ═══════════════════════════════════════════════════════════

    async function loadCostiData() {
        const filters = getFilters();
        const res = await window.customFetch('dashboard_economica', 'getCostsBreakdown', filters, { showLoader: false });

        if (!res.success) {
            throw new Error(res.message || 'Errore caricamento costi');
        }

        renderCostiData(res.data);
    }

    function renderCostiData(data) {
        const laborCosts = data.laborCosts || [];
        const totals = data.totals || {};

        // KPI Costi Lavoro
        const kpiContainer = getEl('kpiCosti');
        if (kpiContainer) {
            const deltaClass = totals.deltaCost > 0 ? 'kpi-danger' : (totals.deltaCost < 0 ? 'kpi-success' : '');
            kpiContainer.innerHTML = `
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Costo Preventivato</div>
                    <div class="dboard-kpi-value">${formatCurrency(totals.quotLaborCost)}</div>
                    <div class="dboard-kpi-hint">Ore: ${formatNum(totals.quotHours, 0)}</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Costo Effettivo</div>
                    <div class="dboard-kpi-value">${formatCurrency(totals.actualLaborCost)}</div>
                    <div class="dboard-kpi-hint">Ore: ${formatNum(totals.actualHours, 0)}</div>
                </div>
                <div class="dboard-kpi-card ${deltaClass}">
                    <div class="dboard-kpi-label">Scostamento</div>
                    <div class="dboard-kpi-value">${formatCurrency(totals.deltaCost)}</div>
                    <div class="dboard-kpi-hint">${formatPercent(totals.deltaPercent)}</div>
                </div>
            `;
        }

        // Tabella costi lavoro
        renderSimpleTable(
            'tbodyCosti', 'costiCount',
            laborCosts, 11,
            row => {
                const deltaClass = getDeltaClass(row.deltaCost);
                return `
                    <tr>
                        <td><strong>${htmlEsc(row.projectCode)}</strong></td>
                        <td class="text-truncate">${htmlEsc(row.projectDesc)}</td>
                        <td>${htmlEsc(row.customerName)}</td>
                        <td>${htmlEsc(row.projectManager)}</td>
                        <td class="text-right">${formatNum(row.quotHours, 0)}</td>
                        <td class="text-right">${formatNum(row.actualHours, 0)}</td>
                        <td class="text-right">${formatCurrency(row.quotLaborCost)}</td>
                        <td class="text-right">${formatCurrency(row.actualLaborCost)}</td>
                        <td class="text-right"><span class="pill ${deltaClass}">${formatCurrency(row.deltaCost)}</span></td>
                        <td class="text-right"><span class="pill ${deltaClass}">${formatPercent(row.deltaPercent)}</span></td>
                        <td class="text-right">${formatNum(row.resourceCount)}</td>
                    </tr>
                `;
            }
        );

        // ── KPI Altri Costi ──
        const at = data.altriTotals || {};
        const kpiAltri = getEl('kpiAltriCosti');
        if (kpiAltri) {
            const grandTotal = (at.totalPurchase || 0) + (at.totalOtherEffective || 0) + (at.totalOverhead || 0) + (at.totalReimbursements || 0);
            kpiAltri.innerHTML = `
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Acquisti</div>
                    <div class="dboard-kpi-value">${formatCurrency(at.totalPurchase)}</div>
                    <div class="dboard-kpi-hint">${(data.purchaseCosts || []).length} righe</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Altri Costi</div>
                    <div class="dboard-kpi-value">${formatCurrency(at.totalOtherEffective)}</div>
                    <div class="dboard-kpi-hint">Prev: ${formatCurrency(at.totalOtherExpected)}</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Overhead</div>
                    <div class="dboard-kpi-value">${formatCurrency(at.totalOverhead)}</div>
                    <div class="dboard-kpi-hint">${(data.overheadCosts || []).length} righe</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Rimborsi</div>
                    <div class="dboard-kpi-value">${formatCurrency(at.totalReimbursements)}</div>
                    <div class="dboard-kpi-hint">${(data.reimbursements || []).length} righe</div>
                </div>
                <div class="dboard-kpi-card kpi-accent">
                    <div class="dboard-kpi-label">Totale Altri Costi</div>
                    <div class="dboard-kpi-value">${formatCurrency(grandTotal)}</div>
                </div>
            `;
        }

        // ── Tabella Acquisti ──
        renderSimpleTable(
            'tbodyPurchase', 'purchaseCount',
            data.purchaseCosts || [], 7,
            row => `
                <tr>
                    <td><strong>${htmlEsc(row.projectCode)}</strong></td>
                    <td>${htmlEsc(row.supplierName)}</td>
                    <td class="text-truncate">${htmlEsc(row.itemDescription)}</td>
                    <td>${htmlEsc(row.documentNr)}</td>
                    <td>${formatDate(row.date)}</td>
                    <td class="text-right">${formatCurrency(row.totalCost)}</td>
                    <td>${row.isSubfornitura ? '<span class="pill pill-info">Sì</span>' : '-'}</td>
                </tr>
            `
        );

        // ── Tabella Altri Costi ──
        renderSimpleTable(
            'tbodyOtherCost', 'otherCostCount',
            data.otherCosts || [], 6,
            row => {
                const deltaClass = getDeltaClass(row.delta);
                return `
                    <tr>
                        <td><strong>${htmlEsc(row.projectCode)}</strong></td>
                        <td>${htmlEsc(row.costType)}</td>
                        <td>${formatDate(row.date)}</td>
                        <td class="text-right">${formatCurrency(row.expectedCost)}</td>
                        <td class="text-right">${formatCurrency(row.effectiveCost)}</td>
                        <td class="text-right"><span class="pill ${deltaClass}">${formatCurrency(row.delta)}</span></td>
                    </tr>
                `;
            }
        );

        // ── Tabella Overhead ──
        renderSimpleTable(
            'tbodyOverhead', 'overheadCount',
            data.overheadCosts || [], 6,
            row => `
                <tr>
                    <td><strong>${htmlEsc(row.projectCode)}</strong></td>
                    <td>${htmlEsc(row.costItem)}</td>
                    <td>${formatDate(row.date)}</td>
                    <td class="text-right">${formatNum(row.quantity, 2)}</td>
                    <td class="text-right">${formatCurrency(row.unitCost)}</td>
                    <td class="text-right">${formatCurrency(row.amountCost)}</td>
                </tr>
            `
        );

        // ── Tabella Rimborsi ──
        renderSimpleTable(
            'tbodyReimb', 'reimbCount',
            data.reimbursements || [], 7,
            row => `
                <tr>
                    <td><strong>${htmlEsc(row.projectCode)}</strong></td>
                    <td>${htmlEsc(row.reimbType)}</td>
                    <td>${htmlEsc(row.resourceName)}</td>
                    <td>${formatDate(row.date)}</td>
                    <td class="text-right">${formatCurrency(row.value)}</td>
                    <td class="text-right">${formatCurrency(row.assigned)}</td>
                    <td>${row.isApproved ? '<span class="pill pill-success">Sì</span>' : '<span class="pill pill-warning">No</span>'}</td>
                </tr>
            `
        );
    }

    const COLLAPSE_LIMIT = 5;

    function renderSimpleTable(tbodyId, countId, rows, colSpan, rowRenderer) {
        const tbody = getEl(tbodyId);
        const countEl = getEl(countId);
        if (!tbody) return;

        const wrap = tbody.closest('.dboard-table-wrap');
        if (countEl) {
            countEl.textContent = `${rows.length} righe`;
        }
        if (rows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center muted">Nessun dato disponibile</td></tr>`;
            removeToggle(wrap);
            return;
        }

        tbody.innerHTML = rows.map((row, i) => {
            const html = rowRenderer(row);
            if (i >= COLLAPSE_LIMIT) {
                return html.replace('<tr>', '<tr class="dboard-hidden-row">');
            }
            return html;
        }).join('');

        if (wrap) {
            wrap.classList.add('dboard-table-wrap--collapsible');
            wrap.classList.remove('dboard-expanded');
        }

        if (rows.length > COLLAPSE_LIMIT) {
            ensureToggle(wrap, rows.length);
        } else {
            removeToggle(wrap);
        }
    }

    function ensureToggle(wrap, total) {
        if (!wrap) return;
        let btn = wrap.parentElement.querySelector('.dboard-toggle-btn');
        if (!btn) {
            btn = document.createElement('button');
            btn.className = 'dboard-toggle-btn';
            btn.type = 'button';
            wrap.parentElement.appendChild(btn);
        }
        const hidden = total - COLLAPSE_LIMIT;
        btn.textContent = `Mostra tutte (${total})`;
        btn.onclick = function () {
            const expanded = wrap.classList.toggle('dboard-expanded');
            btn.textContent = expanded ? 'Mostra meno' : `Mostra tutte (${total})`;
        };
    }

    function removeToggle(wrap) {
        if (!wrap) return;
        const btn = wrap.parentElement.querySelector('.dboard-toggle-btn');
        if (btn) btn.remove();
    }

    // ═══════════════════════════════════════════════════════════
    // SCADENZE & PAGAMENTI
    // ═══════════════════════════════════════════════════════════

    async function loadScadenzeData() {
        const filters = getFilters();
        const [res, invRes] = await Promise.all([
            window.customFetch('dashboard_economica', 'getInstallments', filters, { showLoader: false }),
            window.customFetch('dashboard_economica', 'getInvoicesData', filters, { showLoader: false })
        ]);

        if (!res.success) {
            throw new Error(res.message || 'Errore caricamento scadenze');
        }

        renderScadenzeData(res.data);

        if (invRes.success) {
            renderFattureData(invRes.data);
        }
    }

    function renderScadenzeData(data) {
        // KPI Scadenze
        const kpiContainer = getEl('kpiScadenze');
        if (kpiContainer) {
            const totalValue = data.reduce((acc, r) => acc + (r.expectedInstallValue || 0), 0);
            const totalNet = data.reduce((acc, r) => acc + (r.installNetAmount || 0), 0);
            const billedCount = data.filter(r => r.isBilled).length;

            kpiContainer.innerHTML = `
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Rate Totali</div>
                    <div class="dboard-kpi-value">${formatNum(data.length)}</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Valore Previsto</div>
                    <div class="dboard-kpi-value">${formatCurrency(totalValue)}</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Importo Netto</div>
                    <div class="dboard-kpi-value">${formatCurrency(totalNet)}</div>
                </div>
                <div class="dboard-kpi-card kpi-success">
                    <div class="dboard-kpi-label">Fatturate</div>
                    <div class="dboard-kpi-value">${formatNum(billedCount)}</div>
                    <div class="dboard-kpi-hint">${formatPercent(data.length > 0 ? (billedCount / data.length * 100) : 0)}</div>
                </div>
            `;
        }

        // Tabella scadenze
        renderSimpleTable(
            'tbodyScadenze', 'scadenzeCount',
            data || [], 8,
            row => {
                const billedIcon = row.isBilled ? '<span class="pill pill-success">Si</span>' : '<span class="pill pill-warning">No</span>';
                return `
                    <tr>
                        <td>${formatDate(row.installDate)}</td>
                        <td><strong>${htmlEsc(row.projectCode)}</strong></td>
                        <td>${htmlEsc(row.customerName)}</td>
                        <td class="text-truncate">${htmlEsc(row.installDesc || row.milestoneDescription || '-')}</td>
                        <td class="text-right">${formatCurrency(row.expectedInstallValue)}</td>
                        <td class="text-right">${formatCurrency(row.installNetAmount)}</td>
                        <td><span class="pill">${htmlEsc(row.statusDesc || row.statusCode || '-')}</span></td>
                        <td class="text-center">${billedIcon}</td>
                    </tr>
                `;
            }
        );
    }

    function renderFattureData(data) {
        const invoices = data.invoices || [];
        const totals = data.totals || {};

        const countEl = getEl('fattureCount');
        if (countEl) {
            countEl.textContent = `${invoices.length} fatture — Tot: ${formatCurrency(totals.totalAmount)}`;
        }

        renderSimpleTable(
            'tbodyFatture', null,
            invoices, 7,
            row => `
                <tr>
                    <td><strong>${htmlEsc(row.invoiceNumber)}</strong></td>
                    <td>${htmlEsc(row.invoiceType)}</td>
                    <td>${formatDate(row.invoiceDate)}</td>
                    <td class="text-truncate">${htmlEsc(row.paymentDesc)}</td>
                    <td class="text-right">${formatCurrency(row.taxable)}</td>
                    <td class="text-right">${formatCurrency(row.tax)}</td>
                    <td class="text-right">${formatCurrency(row.amount)}</td>
                </tr>
            `
        );
    }

    // ═══════════════════════════════════════════════════════════
    // CASH FLOW
    // ═══════════════════════════════════════════════════════════

    async function loadCashflowData() {
        const filters = getFilters();

        const [res, invRes] = await Promise.all([
            window.customFetch('dashboard_economica', 'getInstallments', filters, { showLoader: false }),
            window.customFetch('dashboard_economica', 'getInvoicesData', filters, { showLoader: false })
        ]);

        if (!res.success) {
            throw new Error(res.message || 'Errore caricamento cash flow');
        }

        renderCashflowData(res.data, invRes.success ? invRes.data : null);
    }

    function renderCashflowData(data, invoiceData) {
        // KPI Cash Flow
        const kpiContainer = getEl('kpiCashflow');
        if (kpiContainer) {
            const billedTotal = data.filter(r => r.isBilled).reduce((acc, r) => acc + (r.installNetAmount || 0), 0);
            const pendingTotal = data.filter(r => !r.isBilled).reduce((acc, r) => acc + (r.expectedInstallValue || 0), 0);

            kpiContainer.innerHTML = `
                <div class="dboard-kpi-card kpi-success">
                    <div class="dboard-kpi-label">Fatturato</div>
                    <div class="dboard-kpi-value">${formatCurrency(billedTotal)}</div>
                </div>
                <div class="dboard-kpi-card kpi-warning">
                    <div class="dboard-kpi-label">Da Fatturare</div>
                    <div class="dboard-kpi-value">${formatCurrency(pendingTotal)}</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Totale Atteso</div>
                    <div class="dboard-kpi-value">${formatCurrency(billedTotal + pendingTotal)}</div>
                </div>
            `;
        }

        // Cash Flow Chart - monthly breakdown
        const chartContainer = getEl('chartCashflow');
        if (chartContainer) {
            // Group by month
            const monthlyData = {};
            data.forEach(row => {
                if (!row.installDate) return;
                const d = new Date(row.installDate);
                const month = d.getMonth() + 1;
                if (!monthlyData[month]) {
                    monthlyData[month] = { billed: 0, pending: 0 };
                }
                if (row.isBilled) {
                    monthlyData[month].billed += row.installNetAmount || 0;
                } else {
                    monthlyData[month].pending += row.expectedInstallValue || 0;
                }
            });

            const monthNames = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
            const months = Object.keys(monthlyData).sort((a, b) => a - b);

            if (months.length === 0) {
                chartContainer.innerHTML = '<p class="muted text-center">Nessun dato cash flow disponibile per l\'anno.</p>';
            } else {
                const maxVal = Math.max(...months.map(m => (monthlyData[m].billed || 0) + (monthlyData[m].pending || 0)));
                const chartHeight = 200;
                const barWidth = 50;
                const barGap = 20;
                const chartWidth = months.length * (barWidth + barGap);

                let barsHtml = '';
                months.forEach((m, i) => {
                    const md = monthlyData[m];
                    const totalH = maxVal > 0 ? ((md.billed + md.pending) / maxVal) * (chartHeight - 50) : 0;
                    const billedH = maxVal > 0 ? (md.billed / maxVal) * (chartHeight - 50) : 0;
                    const x = i * (barWidth + barGap);

                    barsHtml += `
                        <g class="dboard-bar-group">
                            <rect x="${x}" y="${chartHeight - totalH - 25}" width="${barWidth}" height="${totalH}" fill="#fcd34d" rx="4"/>
                            <rect x="${x}" y="${chartHeight - billedH - 25}" width="${barWidth}" height="${billedH}" fill="#22c55e" rx="4"/>
                            <text x="${x + barWidth / 2}" y="${chartHeight - 8}" text-anchor="middle" class="dboard-chart-label">${monthNames[m - 1]}</text>
                        </g>
                    `;
                });

                chartContainer.innerHTML = `
                    <div class="dboard-chart-legend">
                        <span class="dboard-legend-item"><span class="dboard-legend-color" style="background:#22c55e"></span> Fatturato</span>
                        <span class="dboard-legend-item"><span class="dboard-legend-color" style="background:#fcd34d"></span> Da fatturare</span>
                    </div>
                    <svg width="100%" height="${chartHeight}" viewBox="0 0 ${Math.max(chartWidth, 400)} ${chartHeight}" preserveAspectRatio="xMinYMax meet">
                        ${barsHtml}
                    </svg>
                `;
            }
        }

        // Dettaglio fatturato
        if (invoiceData) {
            const invoices = invoiceData.invoices || [];
            const invTotals = invoiceData.totals || {};
            const detailCount = getEl('cashflowDetailCount');
            if (detailCount) {
                detailCount.textContent = `${invoices.length} fatture — Tot: ${formatCurrency(invTotals.totalAmount)}`;
            }
            renderSimpleTable(
                'tbodyCashflowDetail', null,
                invoices, 6,
                row => `
                    <tr>
                        <td><strong>${htmlEsc(row.invoiceNumber)}</strong></td>
                        <td>${htmlEsc(row.invoiceType)}</td>
                        <td>${formatDate(row.invoiceDate)}</td>
                        <td class="text-truncate">${htmlEsc(row.paymentDesc)}</td>
                        <td class="text-right">${formatCurrency(row.taxable)}</td>
                        <td class="text-right">${formatCurrency(row.amount)}</td>
                    </tr>
                `
            );
        }
    }

    // ═══════════════════════════════════════════════════════════
    // HR ECONOMICO
    // ═══════════════════════════════════════════════════════════

    async function loadHrData() {
        const filters = getFilters();
        const res = await window.customFetch('dashboard_economica', 'getHrEconomicSummary', filters, { showLoader: false });

        if (!res.success) {
            throw new Error(res.message || 'Errore caricamento HR');
        }

        renderHrData(res.data);
    }

    function renderHrData(data) {
        const resourceHours = data.resourceHours || [];
        const resourceCosts = data.resourceCosts || [];
        const totals = data.totals || {};

        // KPI HR
        const kpiContainer = getEl('kpiHr');
        if (kpiContainer) {
            const deltaClass = totals.deltaCost > 0 ? 'kpi-danger' : (totals.deltaCost < 0 ? 'kpi-success' : '');
            kpiContainer.innerHTML = `
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Risorse Attive</div>
                    <div class="dboard-kpi-value">${formatNum(totals.resourceCount)}</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Ore Totali</div>
                    <div class="dboard-kpi-value">${formatNum(totals.totalHours, 0)}</div>
                    <div class="dboard-kpi-hint">Lavoro: ${formatNum(totals.totalWorkHours, 0)}</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Costo Preventivato</div>
                    <div class="dboard-kpi-value">${formatCurrency(totals.quotCost)}</div>
                </div>
                <div class="dboard-kpi-card ${deltaClass}">
                    <div class="dboard-kpi-label">Scostamento</div>
                    <div class="dboard-kpi-value">${formatCurrency(totals.deltaCost)}</div>
                    <div class="dboard-kpi-hint">${formatPercent(totals.deltaPercent)}</div>
                </div>
            `;
        }

        // Tabella ore per risorsa
        renderSimpleTable(
            'tbodyHrHours', 'hrHoursCount',
            resourceHours, 7,
            row => `
                <tr>
                    <td><strong>${htmlEsc(row.resourceName || row.resourceId)}</strong></td>
                    <td>${htmlEsc(row.roleName || '-')}</td>
                    <td>${htmlEsc(row.department || '-')}</td>
                    <td class="text-right">${formatNum(row.totalWorkHours, 1)}</td>
                    <td class="text-right">${formatNum(row.totalTravelHours, 1)}</td>
                    <td class="text-right">${formatNum(row.totalHours, 1)}</td>
                    <td class="text-right">${formatNum(row.projectCount)}</td>
                </tr>
            `
        );

        // Tabella costi per risorsa
        renderSimpleTable(
            'tbodyHrCosts', 'hrCostsCount',
            resourceCosts, 8,
            row => {
                const deltaClass = getDeltaClass(row.deltaCost);
                return `
                    <tr>
                        <td><strong>${htmlEsc(row.resourceName || row.resourceId)}</strong></td>
                        <td>${htmlEsc(row.roleName || '-')}</td>
                        <td class="text-right">${formatNum(row.quotHours, 1)}</td>
                        <td class="text-right">${formatNum(row.actualHours, 1)}</td>
                        <td class="text-right">${formatCurrency(row.quotCost)}</td>
                        <td class="text-right">${formatCurrency(row.actualCost)}</td>
                        <td class="text-right"><span class="pill ${deltaClass}">${formatCurrency(row.deltaCost)}</span></td>
                        <td class="text-right"><span class="pill ${deltaClass}">${formatPercent(row.deltaPercent)}</span></td>
                    </tr>
                `;
            }
        );

        // Tabella assenze
        const absences = data.absences || [];
        renderSimpleTable(
            'tbodyHrAbsence', 'hrAbsenceCount',
            absences, 7,
            row => `
                <tr>
                    <td><strong>${htmlEsc(row.resourceName)}</strong></td>
                    <td>${formatDate(row.absenceDate)}</td>
                    <td>${htmlEsc(row.absenceType)}</td>
                    <td><span class="pill">${htmlEsc(row.status)}</span></td>
                    <td class="text-right">${formatNum(row.hours, 1)}</td>
                    <td>${row.isApproved ? '<span class="pill pill-success">Sì</span>' : '<span class="pill pill-warning">No</span>'}</td>
                    <td>${formatDate(row.approveDate)}</td>
                </tr>
            `
        );
    }

    // ═══════════════════════════════════════════════════════════
    // PIPELINE
    // ═══════════════════════════════════════════════════════════

    async function loadPipelineData() {
        const filters = getFilters();
        const res = await window.customFetch('dashboard_economica', 'getPipelineData', filters, { showLoader: false });

        if (!res.success) {
            throw new Error(res.message || 'Errore caricamento pipeline');
        }

        renderPipelineData(res.data);
    }

    function renderPipelineData(data) {
        const quotations = data.quotations || [];
        const totals = data.totals || {};

        // KPI Pipeline
        const kpiContainer = getEl('kpiPipeline');
        if (kpiContainer) {
            kpiContainer.innerHTML = `
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Offerte Totali</div>
                    <div class="dboard-kpi-value">${formatNum(totals.totalQuotations)}</div>
                    <div class="dboard-kpi-hint">${formatCurrency(totals.totalAmount)}</div>
                </div>
                <div class="dboard-kpi-card kpi-success">
                    <div class="dboard-kpi-label">Vinte</div>
                    <div class="dboard-kpi-value">${formatNum(totals.wonCount)}</div>
                    <div class="dboard-kpi-hint">${formatCurrency(totals.wonAmount)}</div>
                </div>
                <div class="dboard-kpi-card kpi-danger">
                    <div class="dboard-kpi-label">Perse</div>
                    <div class="dboard-kpi-value">${formatNum(totals.lostCount)}</div>
                </div>
                <div class="dboard-kpi-card kpi-warning">
                    <div class="dboard-kpi-label">Aperte</div>
                    <div class="dboard-kpi-value">${formatNum(totals.openCount)}</div>
                    <div class="dboard-kpi-hint">${formatCurrency(totals.openAmount)}</div>
                </div>
                <div class="dboard-kpi-card">
                    <div class="dboard-kpi-label">Win Rate</div>
                    <div class="dboard-kpi-value">${formatPercent(totals.winRate)}</div>
                    <div class="dboard-kpi-hint">su offerte chiuse</div>
                </div>
            `;
        }

        // Tabella offerte
        renderSimpleTable(
            'tbodyPipeline', 'pipelineCount',
            quotations, 7,
            row => {
                const statusClass = getQuotationStatusClass(row.statusCode);
                return `
                    <tr>
                        <td><strong>${htmlEsc(row.quotationNo)}</strong></td>
                        <td class="text-truncate">${htmlEsc(row.subject)}</td>
                        <td><span class="pill ${statusClass}">${htmlEsc(row.status)}</span></td>
                        <td>${htmlEsc(row.salesOperator)}</td>
                        <td>${formatDate(row.quotationDate)}</td>
                        <td class="text-right">${formatCurrency(row.amount)}</td>
                        <td>${htmlEsc(row.outcome || '-')}</td>
                    </tr>
                `;
            }
        );
    }

    function getQuotationStatusClass(statusCode) {
        if (!statusCode) return '';
        const s = statusCode.toUpperCase();
        if (s.includes('CHIUSAPOS')) return 'pill-success';
        if (s.includes('CHIUSANEG')) return 'pill-danger';
        if (s.includes('EMESSA')) return 'pill-info';
        return 'pill-warning';
    }

    // ═══════════════════════════════════════════════════════════
    // VIEW STATE MANAGEMENT
    // ═══════════════════════════════════════════════════════════

    function showLoading() {
        if ($loading) $loading.classList.remove('hidden');
        if ($error) $error.classList.add('hidden');
        if ($panels) $panels.classList.add('hidden');
    }

    function showPanels() {
        if ($loading) $loading.classList.add('hidden');
        if ($error) $error.classList.add('hidden');
        if ($panels) $panels.classList.remove('hidden');
    }

    function showError(msg) {
        if ($loading) $loading.classList.add('hidden');
        if ($panels) $panels.classList.add('hidden');
        if ($error) $error.classList.remove('hidden');
        const errorMsg = getEl('dashErrorMsg');
        if (errorMsg) errorMsg.textContent = msg;
    }

    // ═══════════════════════════════════════════════════════════
    // START
    // ═══════════════════════════════════════════════════════════

    initPage();

});
