/**
 * COMMESSA CRONOPROGRAMMA - JAVASCRIPT
 * Gestisce il caricamento e rendering della pagina cronoprogramma
 */

document.addEventListener('DOMContentLoaded', function () {
    console.log('DOMContentLoaded fired');
    console.log('window.COMMESSA_ID:', window.COMMESSA_ID);
    console.log('window.customFetch:', typeof window.customFetch);

    const idProject = window.COMMESSA_ID;

    if (!idProject) {
        console.error('COMMESSA_ID non definito. window.COMMESSA_ID =', window.COMMESSA_ID);
        return;
    }

    loadCronoprogramma(idProject);

    // Event listeners per switch vista
    document.addEventListener('click', function (e) {
        if (e.target.id === 'btn-gantt') {
            switchView('gantt');
        } else if (e.target.id === 'btn-lista') {
            switchView('lista');
        }
    });
});

/**
 * Carica i dati del cronoprogramma via AJAX
 */
async function loadCronoprogramma(idProject) {
    try {
        const response = await window.customFetch('commesse', 'getPageData', {
            id_project: idProject
        });

        if (!response.success) {
            showError(response.message || 'Errore nel caricamento dei dati');
            return;
        }

        const data = response.data;

        // Verifica se ci sono fasi con date
        const hasDates = data.milestones.some(m => m.ExpectedStartDate && m.ExpectedEndDate);
        if (!hasDates) {
            showWarning('Nessuna data definita per le fasi. Aggiorna il cronoprogramma.');
        }

        // Render dei vari blocchi
        renderToolbar(data.milestones);
        renderGantt(data);
        renderLista(data);
        renderRiepilogo(data);

    } catch (error) {
        console.error('Errore loadCronoprogramma:', error);
        showError('Errore di connessione. Riprova più tardi.');
    }
}

/**
 * Mostra un messaggio di errore
 */
function showError(message) {
    const container = document.querySelector('.cronoprogramma-wrapper, .main-container');
    if (container) {
        container.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
}

/**
 * Mostra un messaggio di warning
 */
function showWarning(message) {
    const container = document.querySelector('.cronoprogramma-wrapper, .main-container');
    if (container) {
        const alert = document.createElement('div');
        alert.className = 'alert alert-warning';
        alert.textContent = message;
        container.insertBefore(alert, container.firstChild);
    }
}


/**
 * Render toolbar
 */
function renderToolbar(milestones) {
    // Calcola range date per il selettore periodo
    let minDate = null;
    let maxDate = null;

    milestones.forEach(m => {
        if (m.ExpectedStartDate) {
            const d = new Date(m.ExpectedStartDate);
            if (!minDate || d < minDate) minDate = d;
        }
        if (m.ExpectedEndDate) {
            const d = new Date(m.ExpectedEndDate);
            if (!maxDate || d > maxDate) maxDate = d;
        }
    });

    let periodLabel = 'Periodo non definito';
    if (minDate && maxDate) {
        const monthStart = minDate.toLocaleDateString('it-IT', { month: 'short', year: 'numeric' });
        const monthEnd = maxDate.toLocaleDateString('it-IT', { month: 'short', year: 'numeric' });
        periodLabel = `${monthStart} – ${monthEnd}`;
    }

    const html = `
        <div class="commessa-crono-toolbar">
            <div class="commessa-crono-toolbar-title">Cronoprogramma</div>
            <span class="commessa-crono-period-label">${periodLabel}</span>
            <button class="btn active" id="btn-gantt">Gantt</button>
            <button class="btn" id="btn-lista">Lista</button>
        </div>
    `;

    document.getElementById('toolbar-placeholder').innerHTML = html;
}

/**
 * Render vista Gantt
 */
function renderGantt(data) {
    const { milestones, phases, installments } = data;

    // Calcola range mesi
    const monthsRange = calculateMonthsRange(milestones);
    if (monthsRange.length === 0) {
        document.getElementById('gantt-container').innerHTML = '<div class="alert alert-warning">Nessuna fase con date definite</div>';
        return;
    }

    const { rangeStart, rangeEnd } = monthsRange[0];
    const totalDays = Math.ceil((rangeEnd - rangeStart) / (1000 * 60 * 60 * 24));

    // Header mesi
    let headerMonthsHTML = '';
    monthsRange.forEach(m => {
        const currentMonth = new Date().getMonth() === m.month.getMonth() &&
            new Date().getFullYear() === m.month.getFullYear();
        const className = currentMonth ? 'gantt-month-cell current-month' : 'gantt-month-cell';
        headerMonthsHTML += `<div class="${className}">${m.label}</div>`;
    });

    const gridColumns = `repeat(${monthsRange.length}, 1fr)`;
    const gridBg = `repeating-linear-gradient(90deg, transparent, transparent calc(${100 / monthsRange.length}% - 1px), #f4f5f7 calc(${100 / monthsRange.length}% - 1px), #f4f5f7 ${100 / monthsRange.length}%)`;

    let rowsHTML = '';

    // Raggruppa phases per milestone
    const phasesByMilestone = {};
    phases.forEach(p => {
        if (!phasesByMilestone[p.IdProjectMilestone]) {
            phasesByMilestone[p.IdProjectMilestone] = [];
        }
        phasesByMilestone[p.IdProjectMilestone].push(p);
    });

    // Raggruppa installments per milestone
    const installmentsByMilestone = {};
    installments.forEach(i => {
        if (!installmentsByMilestone[i.IdProjectMilestone]) {
            installmentsByMilestone[i.IdProjectMilestone] = [];
        }
        installmentsByMilestone[i.IdProjectMilestone].push(i);
    });

    // Colori fasi
    const phaseColors = ['gantt-bar-color-1', 'gantt-bar-color-2', 'gantt-bar-color-3', 'gantt-bar-color-4'];

    milestones.forEach((milestone, index) => {
        const colorClass = phaseColors[index % phaseColors.length] || 'gantt-bar-color-default';
        const statusClass = (milestone.MilestoneStatus || '').toLowerCase();

        // Barra Gantt principale
        let barHTML = '';
        if (milestone.ExpectedStartDate && milestone.ExpectedEndDate) {
            const left = calcBarPosition(milestone.ExpectedStartDate, rangeStart, totalDays);
            const width = calcBarWidth(milestone.ExpectedStartDate, milestone.ExpectedEndDate, totalDays);
            const progress = calcProgress(milestone.HoursWorked, milestone.QuotExpectedHours);

            barHTML = `
                <div class="gantt-bar ${colorClass}" style="left: ${left}%; width: ${width}%;">
                    <div class="gantt-bar-bg ${colorClass}"></div>
                    <div class="gantt-bar-progress ${colorClass}" style="width: ${progress}%;"></div>
                </div>
            `;
        } else {
            barHTML = '<div class="gantt-bar-no-dates">Date non definite</div>';
        }

        // Marker pagamenti
        let markersHTML = '';
        const milestonePays = installmentsByMilestone[milestone.IdProjectMilestone] || [];
        milestonePays.forEach(pay => {
            if (pay.InstallDate) {
                const left = calcBarPosition(pay.InstallDate, rangeStart, totalDays);
                const markerClass = pay.StatusDesc === 'Scaduto' ? 'scaduto' : 'ok';
                const tooltipHTML = `
                    <div class="payment-marker-tooltip">
                        ${escapeHtml(pay.InstallDesc || 'Pagamento')}<br>
                        ${formatDate(pay.InstallDate)}<br>
                        ${formatEuro(pay.InstallNetAmount)}<br>
                        ${escapeHtml(pay.StatusDesc || '')}
                    </div>
                `;
                markersHTML += `
                    <div class="payment-marker ${markerClass}" style="left: ${left}%;">
                        ${tooltipHTML}
                    </div>
                `;
            }
        });

        // Riga fase header
        rowsHTML += `
            <div class="gantt-fase-row" data-milestone-id="${milestone.IdProjectMilestone}" onclick="toggleFase('${milestone.IdProjectMilestone}')">
                <div class="gantt-fase-label">
                    <span class="gantt-fase-toggle open" id="toggle-${milestone.IdProjectMilestone}">▶</span>
                    <div class="gantt-fase-name">
                        ${escapeHtml(milestone.MilestoneDescription || 'Fase senza nome')}
                        <span class="fase-status-badge ${statusClass}">${escapeHtml(milestone.MilestoneStatus || '')}</span>
                    </div>
                    <div class="gantt-fase-meta">
                        Ore prev: ${milestone.QuotExpectedHours || 0} | Ore eff: ${milestone.HoursWorked || 0} | Valore: ${formatEuro(milestone.MilestoneValue)}
                    </div>
                </div>
                <div class="gantt-fase-bar-area" style="background: ${gridBg};">
                    ${barHTML}
                    ${markersHTML}
                </div>
            </div>
        `;

        // Sotto-attività
        const milestonePhases = phasesByMilestone[milestone.IdProjectMilestone] || [];
        milestonePhases.forEach(phase => {
            let subBarHTML = '';
            if (phase.ExpectedStartDate && phase.ExpectedEndDate) {
                const left = calcBarPosition(phase.ExpectedStartDate, rangeStart, totalDays);
                const width = calcBarWidth(phase.ExpectedStartDate, phase.ExpectedEndDate, totalDays);
                subBarHTML = `<div class="gantt-sub-bar ${colorClass}" style="left: ${left}%; width: ${width}%;"></div>`;
            } else {
                subBarHTML = '<div class="gantt-bar-no-dates">Date non definite</div>';
            }

            const statusBadge = getPhaseStatusBadge(phase.StatusCode);

            rowsHTML += `
                <div class="gantt-subactivity-row" data-parent-milestone="${milestone.IdProjectMilestone}">
                    <div class="gantt-subactivity-label">
                        <div class="gantt-subactivity-code">${escapeHtml(phase.PhaseHRCode || '')}</div>
                        <div class="gantt-subactivity-desc">${escapeHtml(phase.PhaseHRDescTable || '')}</div>
                        <div class="gantt-subactivity-resource">👤 ${escapeHtml(phase.ResourceName || '—')}</div>
                    </div>
                    <div class="gantt-subactivity-bar-area" style="background: repeating-linear-gradient(90deg, transparent, transparent calc(${100 / monthsRange.length}% - 1px), #f0f0f0 calc(${100 / monthsRange.length}% - 1px), #f0f0f0 ${100 / monthsRange.length}%);">
                        ${subBarHTML}
                        ${statusBadge}
                    </div>
                </div>
            `;
        });
    });

    const html = `
        <div class="commessa-crono-gantt-grid">
            <div class="gantt-header">
                <div class="gantt-header-label">FASE / ATTIVITÀ</div>
                <div class="gantt-header-months" style="grid-template-columns: ${gridColumns};">
                    ${headerMonthsHTML}
                </div>
            </div>
            ${rowsHTML}
        </div>
    `;

    document.getElementById('gantt-container').innerHTML = html;
}

/**
 * Render vista Lista
 */
function renderLista(data) {
    const { milestones, phases } = data;

    // Raggruppa phases per milestone
    const phasesByMilestone = {};
    phases.forEach(p => {
        if (!phasesByMilestone[p.IdProjectMilestone]) {
            phasesByMilestone[p.IdProjectMilestone] = [];
        }
        phasesByMilestone[p.IdProjectMilestone].push(p);
    });

    let rowsHTML = '';

    milestones.forEach(milestone => {
        const statusClass = (milestone.MilestoneStatus || '').toLowerCase();

        // Riga fase
        rowsHTML += `
            <tr class="table-crono-fase-row">
                <td>${escapeHtml(milestone.MilestoneDescription || 'Fase senza nome')}</td>
                <td>${escapeHtml(milestone.MilestoneManagerName || milestone.ProjectManagerName || '—')}</td>
                <td><span class="fase-status-badge ${statusClass}">${escapeHtml(milestone.MilestoneStatus || '')}</span></td>
                <td>${formatDate(milestone.ExpectedStartDate)}</td>
                <td>${formatDate(milestone.ExpectedEndDate)}</td>
                <td>${milestone.QuotExpectedHours || 0}</td>
                <td>${milestone.HoursWorked || 0}</td>
                <td>${formatEuro(milestone.MilestoneValue)}</td>
            </tr>
        `;

        // Righe sotto-attività
        const milestonePhases = phasesByMilestone[milestone.IdProjectMilestone] || [];
        milestonePhases.forEach(phase => {
            const statusBadge = getPhaseStatusBadgeText(phase.StatusCode);

            rowsHTML += `
                <tr>
                    <td class="table-crono-subactivity-cell">${escapeHtml(phase.PhaseHRDescTable || '')}</td>
                    <td>${escapeHtml(phase.ResourceName || '—')}</td>
                    <td>${statusBadge}</td>
                    <td>${formatDate(phase.ExpectedStartDate)}</td>
                    <td>${formatDate(phase.ExpectedEndDate)}</td>
                    <td>${phase.ExpectedHoursQuot || 0}</td>
                    <td>—</td>
                    <td>—</td>
                </tr>
            `;
        });
    });

    const html = `
        <div style="background: #fff; border: 1px solid #e1e4e8; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); overflow: hidden;">
            <table class="table-crono">
                <thead>
                    <tr>
                        <th>Fase / Attività</th>
                        <th>Risorsa</th>
                        <th>Stato</th>
                        <th>Data Inizio</th>
                        <th>Data Fine</th>
                        <th>Ore Prev.</th>
                        <th>Ore Eff.</th>
                        <th>Valore</th>
                    </tr>
                </thead>
                <tbody>
                    ${rowsHTML}
                </tbody>
            </table>
        </div>
    `;

    document.getElementById('lista-container').innerHTML = html;
}

/**
 * Render riepilogo (KPI + scadenze pagamento)
 */
function renderRiepilogo(data) {
    const { milestones, installments } = data;

    // Calcola KPI
    let totOrePreventivate = 0;
    let totOreEffettive = 0;
    let totValore = 0;

    milestones.forEach(m => {
        totOrePreventivate += parseFloat(m.QuotExpectedHours || 0);
        totOreEffettive += parseFloat(m.HoursWorked || 0);
        totValore += parseFloat(m.MilestoneValue || 0);
    });

    const percCompletamento = totOrePreventivate > 0 ? Math.round((totOreEffettive / totOrePreventivate) * 100) : 0;

    // SAL scaduti
    let salScaduto = 0;
    installments.forEach(i => {
        if (i.StatusDesc === 'Scaduto' && i.IsBilled === '0') {
            salScaduto += parseFloat(i.InstallNetAmount || 0);
        }
    });

    const salScadutoClass = salScaduto > 0 ? 'danger' : 'success';
    const salScadutoSub = salScaduto > 0 ? 'Non fatturato' : 'Nessun SAL scaduto';

    // Barra avanzamento
    const barWidth = percCompletamento;

    // Tabella scadenze
    let scadenzeRowsHTML = '';
    installments.forEach(i => {
        const statusClass = i.StatusDesc === 'Scaduto' ? 'stato-scaduto' : (i.IsBilled === '1' ? 'stato-fatturato' : 'stato-dafatturare');
        const statusLabel = i.IsBilled === '1' ? 'Fatturato' : i.StatusDesc || 'N/D';

        scadenzeRowsHTML += `
            <tr>
                <td>${escapeHtml(i.InstallDesc || 'Scadenza')}</td>
                <td>${formatDate(i.InstallDate)}</td>
                <td>${formatEuro(i.ExpectedInstallValue)}</td>
                <td>${formatEuro(i.InstallNetAmount)}</td>
                <td class="${statusClass}">${escapeHtml(statusLabel)}</td>
            </tr>
        `;
    });

    const html = `
        <div class="commessa-riepilogo-container">
            <div class="commessa-riepilogo-title">Riepilogo Commessa</div>

            <div class="kpi-grid">
                <div class="kpi-box">
                    <div class="kpi-box-value">${totOrePreventivate.toFixed(0)}</div>
                    <div class="kpi-box-label">Ore Preventivate</div>
                </div>
                <div class="kpi-box">
                    <div class="kpi-box-value success">${totOreEffettive.toFixed(0)}</div>
                    <div class="kpi-box-label">Ore Effettive</div>
                    <div class="kpi-box-sub">${percCompletamento}% completamento</div>
                </div>
                <div class="kpi-box">
                    <div class="kpi-box-value">${formatEuro(totValore)}</div>
                    <div class="kpi-box-label">Valore Totale</div>
                </div>
                <div class="kpi-box">
                    <div class="kpi-box-value ${salScadutoClass}">${formatEuro(salScaduto)}</div>
                    <div class="kpi-box-label">SAL Scaduto</div>
                    <div class="kpi-box-sub">${salScadutoSub}</div>
                </div>
            </div>

            <div class="avanz-bar-container">
                <div class="avanz-bar-labels">
                    <div class="avanz-bar-labels-left">Avanzamento ore: ${totOreEffettive.toFixed(0)} / ${totOrePreventivate.toFixed(0)}</div>
                    <div class="avanz-bar-labels-right">${percCompletamento}%</div>
                </div>
                <div class="avanz-bar-track">
                    <div class="avanz-bar-fill" style="width: ${barWidth}%;"></div>
                </div>
            </div>

            <div class="scadenze-table-title">Scadenze Pagamento</div>
            <table class="table-crono">
                <thead>
                    <tr>
                        <th>Descrizione</th>
                        <th>Data</th>
                        <th>Importo Previsto</th>
                        <th>Importo Fatturato</th>
                        <th>Stato</th>
                    </tr>
                </thead>
                <tbody>
                    ${scadenzeRowsHTML || '<tr><td colspan="5" style="text-align:center; color:#6c757d;">Nessuna scadenza</td></tr>'}
                </tbody>
            </table>
        </div>
    `;

    document.getElementById('riepilogo-container').innerHTML = html;
}

/**
 * Calcola il range di mesi per il Gantt
 */
function calculateMonthsRange(milestones) {
    let minDate = null;
    let maxDate = null;

    milestones.forEach(m => {
        if (m.ExpectedStartDate) {
            const d = new Date(m.ExpectedStartDate);
            if (!minDate || d < minDate) minDate = d;
        }
        if (m.ExpectedEndDate) {
            const d = new Date(m.ExpectedEndDate);
            if (!maxDate || d > maxDate) maxDate = d;
        }
    });

    if (!minDate || !maxDate) return [];

    // Espandi al mese intero
    const rangeStart = new Date(minDate.getFullYear(), minDate.getMonth(), 1);
    const rangeEnd = new Date(maxDate.getFullYear(), maxDate.getMonth() + 1, 0);

    const months = [];
    const current = new Date(rangeStart);

    while (current <= rangeEnd) {
        const label = current.toLocaleDateString('it-IT', { month: 'short', year: 'numeric' });
        months.push({
            month: new Date(current),
            label: label.charAt(0).toUpperCase() + label.slice(1),
            rangeStart,
            rangeEnd
        });
        current.setMonth(current.getMonth() + 1);
    }

    return months;
}

/**
 * Calcola la posizione left della barra (in %)
 */
function calcBarPosition(dateStr, rangeStart, totalDays) {
    if (!dateStr) return 0;
    const date = new Date(dateStr);
    const offsetDays = Math.ceil((date - rangeStart) / (1000 * 60 * 60 * 24));
    const percent = (offsetDays / totalDays) * 100;
    return Math.max(1, Math.min(99, percent));
}

/**
 * Calcola la larghezza della barra (in %)
 */
function calcBarWidth(startDateStr, endDateStr, totalDays) {
    if (!startDateStr || !endDateStr) return 1;
    const start = new Date(startDateStr);
    const end = new Date(endDateStr);
    const durationDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
    const percent = (durationDays / totalDays) * 100;
    return Math.max(1, Math.min(98, percent));
}

/**
 * Calcola la percentuale di avanzamento
 */
function calcProgress(worked, expected) {
    const w = parseFloat(worked || 0);
    const e = parseFloat(expected || 0);
    if (e === 0) return 0;
    return Math.min(100, (w / e) * 100);
}

/**
 * Switch tra vista Gantt e Lista
 */
function switchView(view) {
    const ganttContainer = document.getElementById('gantt-container');
    const listaContainer = document.getElementById('lista-container');
    const btnGantt = document.getElementById('btn-gantt');
    const btnLista = document.getElementById('btn-lista');

    if (view === 'gantt') {
        ganttContainer.style.display = 'block';
        listaContainer.style.display = 'none';
        btnGantt.classList.add('active');
        btnLista.classList.remove('active');
    } else {
        ganttContainer.style.display = 'none';
        listaContainer.style.display = 'block';
        btnGantt.classList.remove('active');
        btnLista.classList.add('active');
    }
}

/**
 * Toggle visibilità sotto-attività
 */
function toggleFase(milestoneId) {
    const toggle = document.getElementById('toggle-' + milestoneId);
    const subrows = document.querySelectorAll(`[data-parent-milestone="${milestoneId}"]`);

    if (toggle) {
        toggle.classList.toggle('open');
    }

    subrows.forEach(row => {
        row.classList.toggle('hidden');
    });
}

/**
 * Formatta valore monetario
 */
function formatEuro(value) {
    const num = parseFloat(value || 0);
    return '€ ' + num.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

/**
 * Formatta data da Y-m-d a dd/mm/yyyy
 */
function formatDate(dateStr) {
    if (!dateStr || dateStr === '—' || dateStr === '-') return '—';
    const parts = dateStr.split('-');
    if (parts.length === 3) {
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }
    return dateStr;
}

/**
 * Escape HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

/**
 * Badge stato fase (HTML per Gantt)
 */
function getPhaseStatusBadge(statusCode) {
    let className = 'gantt-subactivity-badge';
    let label = '';

    if (statusCode === '1APERTA') {
        className += ' aperta';
        label = 'Aperta';
    } else if (statusCode === '0PIANIFICATA') {
        className += ' pianificata';
        label = 'Pianificata';
    } else if (statusCode === '2CHIUSA') {
        className += ' chiusa';
        label = 'Chiusa';
    } else {
        className += ' pianificata';
        label = statusCode || 'N/D';
    }

    return `<div class="${className}">${escapeHtml(label)}</div>`;
}

/**
 * Badge stato fase (testo per Lista)
 */
function getPhaseStatusBadgeText(statusCode) {
    let className = 'fase-status-badge';
    let label = '';

    if (statusCode === '1APERTA') {
        className += ' aperta';
        label = 'Aperta';
    } else if (statusCode === '0PIANIFICATA') {
        className += ' pianificata';
        label = 'Pianificata';
    } else if (statusCode === '2CHIUSA') {
        className += ' chiusa';
        label = 'Chiusa';
    } else {
        className += ' pianificata';
        label = statusCode || 'N/D';
    }

    return `<span class="${className}">${escapeHtml(label)}</span>`;
}

