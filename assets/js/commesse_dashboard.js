/**
 * Dashboard Commesse - JavaScript
 * Carica e renderizza i dati della dashboard tramite customFetch
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        loadDashboardData();
    }

    /**
     * Carica i dati della dashboard tramite API
     */
    async function loadDashboardData() {
        try {
            // Verifica che customFetch sia disponibile
            if (typeof window.customFetch !== 'function') {
                console.error('customFetch non disponibile');
                showAllErrors('Errore: funzione di caricamento non disponibile');
                return;
            }

            const response = await window.customFetch('commesse', 'getDashboardStats', {});

            if (!response || !response.success) {
                const msg = response?.message || 'Errore nel caricamento dei dati';
                console.error('Errore API:', msg);
                showAllErrors(msg);
                return;
            }

            const data = response.data;

            // Renderizza KPI
            renderKpi(data.kpi);

            // Renderizza Business Unit bars
            renderBuBars(data.byBu);

            // Renderizza Project Manager list
            renderPmList(data.byPm);

            // Renderizza Settori pills
            renderSectorPills(data.bySector);

            // Renderizza tabella ultime commesse
            renderLatestTable(data.latest);

        } catch (err) {
            console.error('Errore caricamento dashboard:', err);
            showAllErrors('Errore di connessione');
        }
    }

    /**
     * Mostra errore in tutti i container
     */
    function showAllErrors(message) {
        const containers = [
            'bu-bars-container',
            'pm-list-container',
            'sector-pills-container',
            'latest-table-container'
        ];

        containers.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.innerHTML = '<div class="commessa-dashboard-empty">' + escapeHtml(message) + '</div>';
            }
        });

        // KPI rimangono con "—"
    }

    /**
     * Renderizza i KPI
     */
    function renderKpi(kpi) {
        if (!kpi) return;

        setKpiValue('kpi-open-value', kpi.open);
        setKpiValue('kpi-closed-value', kpi.closed);
        setKpiValue('kpi-total-value', kpi.total);
        setKpiValue('kpi-pm-value', kpi.pmCount);
        setKpiValue('kpi-sector-value', kpi.sectorCount);
        setKpiValue('kpi-bu-value', kpi.buCount);
    }

    function setKpiValue(elementId, value) {
        const el = document.getElementById(elementId);
        if (el) {
            el.textContent = value !== undefined && value !== null ? value : '—';
        }
    }

    /**
     * Renderizza le barre per Business Unit
     */
    function renderBuBars(byBu) {
        const container = document.getElementById('bu-bars-container');
        if (!container) return;

        if (!byBu || byBu.length === 0) {
            container.innerHTML = '<div class="commessa-dashboard-empty">Nessuna business unit trovata</div>';
            return;
        }

        // Trova il massimo per calcolare le percentuali
        const maxCount = Math.max(...byBu.map(item => parseInt(item.count) || 0));

        let html = '';
        byBu.forEach(item => {
            const count = parseInt(item.count) || 0;
            const percent = maxCount > 0 ? Math.round((count / maxCount) * 100) : 0;
            const label = escapeHtml(item.label || 'N/D');

            html += `
                <div class="commessa-dashboard-bar-item">
                    <div class="bar-label-row">
                        <span class="bar-label">${label}</span>
                        <span class="bar-count">${count}</span>
                    </div>
                    <div class="commessa-progress">
                        <div class="commessa-progress-bar" style="width: ${percent}%;"></div>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    /**
     * Renderizza la lista dei Project Manager
     */
    function renderPmList(byPm) {
        const container = document.getElementById('pm-list-container');
        if (!container) return;

        if (!byPm || byPm.length === 0) {
            container.innerHTML = '<div class="commessa-dashboard-empty">Nessun project manager trovato</div>';
            return;
        }

        let html = '';
        byPm.forEach(item => {
            const count = parseInt(item.count) || 0;
            const label = escapeHtml(item.label || 'N/D');
            const initials = escapeHtml(item.initials || 'ND');
            // Usa imagePath dal backend oppure genera avatar con iniziali via JS
            const imagePath = item.imagePath || window.generateInitialsAvatar(item.label);

            html += `
                <div class="commessa-dashboard-pm-item">
                    <img class="pm-avatar-img" src="${escapeHtml(imagePath)}" alt="${label}" onerror="this.src=window.generateInitialsAvatar('${escapeHtml(item.label)}')">
                    <div class="pm-info">
                        <span class="pm-name">${label}</span>
                    </div>
                    <span class="pm-count-badge">${count}</span>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    /**
     * Renderizza i pill per i settori
     */
    function renderSectorPills(bySector) {
        const container = document.getElementById('sector-pills-container');
        if (!container) return;

        if (!bySector || bySector.length === 0) {
            container.innerHTML = '<div class="commessa-dashboard-empty">Nessun settore trovato</div>';
            return;
        }

        let html = '';
        bySector.forEach(item => {
            const count = parseInt(item.count) || 0;
            const label = escapeHtml(item.label || 'N/D');

            html += `
                <div class="commessa-dashboard-sector-pill">
                    <span class="sector-count">${count}</span>
                    <span class="sector-label">${label}</span>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    /**
     * Renderizza la tabella delle ultime commesse
     */
    function renderLatestTable(latest) {
        const container = document.getElementById('latest-table-container');
        if (!container) return;

        if (!latest || latest.length === 0) {
            container.innerHTML = '<div class="commessa-dashboard-empty">Nessuna commessa aperta trovata</div>';
            return;
        }

        let html = `
            <table class="commessa-table commessa-dashboard-table">
                <thead>
                    <tr>
                        <th>Codice</th>
                        <th>Titolo</th>
                        <th>Cliente</th>
                        <th>Business Unit</th>
                        <th>Project Manager</th>
                        <th>Apertura</th>
                    </tr>
                </thead>
                <tbody>
        `;

        latest.forEach(row => {
            const codice = escapeHtml(row.codice || '');
            const titolo = escapeHtml(row.titolo || '-');
            const cliente = escapeHtml(row.cliente || '-');
            const bu = escapeHtml(row.bu || '-');
            const pm = escapeHtml(row.pm || '-');
            const apertura = escapeHtml(row.apertura || '-');

            const detailUrl = 'index.php?section=commesse&page=commessa&tabella=' + encodeURIComponent(row.codice) + '&view=dati';

            html += `
                <tr class="commessa-dashboard-row-clickable" data-href="${detailUrl}">
                    <td><a href="${detailUrl}" class="commessa-dashboard-link">${codice}</a></td>
                    <td><a href="${detailUrl}" class="commessa-dashboard-link-title">${titolo}</a></td>
                    <td>${cliente}</td>
                    <td>${bu}</td>
                    <td>${pm}</td>
                    <td>${apertura}</td>
                </tr>
            `;
        });

        html += '</tbody></table>';
        container.innerHTML = html;

        // Aggiungi click handler per le righe
        container.querySelectorAll('.commessa-dashboard-row-clickable').forEach(row => {
            row.addEventListener('click', function (e) {
                // Se il click e' su un link, lascia gestire al link
                if (e.target.tagName === 'A') return;

                const href = this.dataset.href;
                if (href) {
                    window.location.href = href;
                }
            });
        });
    }

    /**
     * Escape HTML per prevenire XSS
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const str = String(text);
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})();
