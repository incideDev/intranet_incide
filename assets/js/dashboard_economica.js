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
        const tbody = getEl('tbodyOverviewLatest');
        const countEl = getEl('overviewLatestCount');
        if (!tbody) return;

        if (countEl) {
            countEl.textContent = `Top ${projects.length}`;
        }

        if (!projects || projects.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center muted">Nessuna commessa trovata</td></tr>';
            return;
        }

        tbody.innerHTML = projects.map(row => {
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
        }).join('');
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
        const tbody = getEl('tbodyCommesse');
        const countEl = getEl('commesseCount');
        if (!tbody) return;

        if (countEl) {
            countEl.textContent = `${data.length} commesse`;
        }

        if (!data || data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center muted">Nessuna commessa trovata</td></tr>';
            return;
        }

        tbody.innerHTML = data.map(row => {
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
        }).join('');
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

        // KPI Costi
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

        // Tabella costi
        const tbody = getEl('tbodyCosti');
        const countEl = getEl('costiCount');
        if (tbody) {
            if (countEl) {
                countEl.textContent = `${laborCosts.length} commesse`;
            }

            if (laborCosts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="11" class="text-center muted">Nessun dato costi disponibile</td></tr>';
            } else {
                tbody.innerHTML = laborCosts.map(row => {
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
                }).join('');
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // SCADENZE & PAGAMENTI
    // ═══════════════════════════════════════════════════════════

    async function loadScadenzeData() {
        const filters = getFilters();
        const res = await window.customFetch('dashboard_economica', 'getInstallments', filters, { showLoader: false });

        if (!res.success) {
            throw new Error(res.message || 'Errore caricamento scadenze');
        }

        renderScadenzeData(res.data);
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
        const tbody = getEl('tbodyScadenze');
        const countEl = getEl('scadenzeCount');
        if (tbody) {
            if (countEl) {
                countEl.textContent = `${data.length} rate`;
            }

            if (!data || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center muted">Nessuna rata trovata per l\'anno selezionato</td></tr>';
            } else {
                tbody.innerHTML = data.map(row => {
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
                }).join('');
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // CASH FLOW
    // ═══════════════════════════════════════════════════════════

    async function loadCashflowData() {
        const filters = getFilters();

        // Load installments to compute basic cash flow
        const res = await window.customFetch('dashboard_economica', 'getInstallments', filters, { showLoader: false });

        if (!res.success) {
            throw new Error(res.message || 'Errore caricamento cash flow');
        }

        renderCashflowData(res.data);
    }

    function renderCashflowData(data) {
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
        const tbodyHours = getEl('tbodyHrHours');
        const hoursCountEl = getEl('hrHoursCount');
        if (tbodyHours) {
            if (hoursCountEl) {
                hoursCountEl.textContent = `${resourceHours.length} risorse`;
            }

            if (resourceHours.length === 0) {
                tbodyHours.innerHTML = '<tr><td colspan="7" class="text-center muted">Nessuna risorsa con ore registrate</td></tr>';
            } else {
                tbodyHours.innerHTML = resourceHours.map(row => `
                    <tr>
                        <td><strong>${htmlEsc(row.resourceName || row.resourceId)}</strong></td>
                        <td>${htmlEsc(row.roleName || '-')}</td>
                        <td>${htmlEsc(row.department || '-')}</td>
                        <td class="text-right">${formatNum(row.totalWorkHours, 1)}</td>
                        <td class="text-right">${formatNum(row.totalTravelHours, 1)}</td>
                        <td class="text-right">${formatNum(row.totalHours, 1)}</td>
                        <td class="text-right">${formatNum(row.projectCount)}</td>
                    </tr>
                `).join('');
            }
        }

        // Tabella costi per risorsa
        const tbodyCosts = getEl('tbodyHrCosts');
        const costsCountEl = getEl('hrCostsCount');
        if (tbodyCosts) {
            if (costsCountEl) {
                costsCountEl.textContent = `${resourceCosts.length} risorse`;
            }

            if (resourceCosts.length === 0) {
                tbodyCosts.innerHTML = '<tr><td colspan="8" class="text-center muted">Nessun dato costi per risorsa</td></tr>';
            } else {
                tbodyCosts.innerHTML = resourceCosts.map(row => {
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
                }).join('');
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    // PIPELINE
    // ═══════════════════════════════════════════════════════════

    async function loadPipelineData() {
        // Pipeline requires quotation/negotiation tables not yet available
        // Render placeholder with KPI showing "in completamento"
        renderPipelinePlaceholder();
    }

    function renderPipelinePlaceholder() {
        const kpiContainer = getEl('kpiPipeline');
        if (kpiContainer) {
            kpiContainer.innerHTML = `
                <div class="dboard-kpi-card kpi-muted">
                    <div class="dboard-kpi-label">Opportunita</div>
                    <div class="dboard-kpi-value">-</div>
                    <div class="dboard-kpi-hint">In completamento</div>
                </div>
                <div class="dboard-kpi-card kpi-muted">
                    <div class="dboard-kpi-label">Valore Pipeline</div>
                    <div class="dboard-kpi-value">-</div>
                    <div class="dboard-kpi-hint">In completamento</div>
                </div>
                <div class="dboard-kpi-card kpi-muted">
                    <div class="dboard-kpi-label">Win Rate</div>
                    <div class="dboard-kpi-value">-</div>
                    <div class="dboard-kpi-hint">In completamento</div>
                </div>
            `;
        }
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
