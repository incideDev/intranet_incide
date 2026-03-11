<?php
if (!checkPermissionOrWarn('view_commesse'))
    return;
?>
<link rel="stylesheet" href="assets/css/commesse_detail_overview.css">
<link rel="stylesheet" href="assets/css/commesse_dashboard.css">

<div class="main-container">
    <?php renderPageTitle("Dashboard Commesse", "#667eea"); ?>

    <p class="commessa-dashboard-subtitle">Panoramica generale delle commesse attive e archiviate</p>

    <!-- KPI Cards Row -->
    <div class="commessa-dashboard-kpi-row">
        <div class="commessa-dashboard-kpi-card" id="kpi-open">
            <div class="kpi-icon kpi-icon-open">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" id="kpi-open-value">—</div>
                <div class="kpi-label">Commesse Aperte</div>
            </div>
        </div>

        <div class="commessa-dashboard-kpi-card" id="kpi-closed">
            <div class="kpi-icon kpi-icon-closed">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="9" y1="9" x2="15" y2="15"></line>
                    <line x1="15" y1="9" x2="9" y2="15"></line>
                </svg>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" id="kpi-closed-value">—</div>
                <div class="kpi-label">Commesse Chiuse</div>
            </div>
        </div>

        <div class="commessa-dashboard-kpi-card" id="kpi-total">
            <div class="kpi-icon kpi-icon-total">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                </svg>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" id="kpi-total-value">—</div>
                <div class="kpi-label">Totale Commesse</div>
            </div>
        </div>

        <div class="commessa-dashboard-kpi-card" id="kpi-pm">
            <div class="kpi-icon kpi-icon-pm">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" id="kpi-pm-value">—</div>
                <div class="kpi-label">Project Manager</div>
            </div>
        </div>

        <div class="commessa-dashboard-kpi-card" id="kpi-sector">
            <div class="kpi-icon kpi-icon-sector">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="20" x2="18" y2="10"></line>
                    <line x1="12" y1="20" x2="12" y2="4"></line>
                    <line x1="6" y1="20" x2="6" y2="14"></line>
                </svg>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" id="kpi-sector-value">—</div>
                <div class="kpi-label">Settori</div>
            </div>
        </div>

        <div class="commessa-dashboard-kpi-card" id="kpi-bu">
            <div class="kpi-icon kpi-icon-bu">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                </svg>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" id="kpi-bu-value">—</div>
                <div class="kpi-label">Business Unit</div>
            </div>
        </div>
    </div>

    <!-- Grid 2 colonne: BU + PM -->
    <div class="commessa-dashboard-grid">
        <!-- Card: Commesse aperte per Business Unit -->
        <div class="commessa-card">
            <div class="commessa-card-header">
                <div>
                    <div class="commessa-card-title">Commesse per Business Unit</div>
                    <div class="commessa-card-subtitle">Solo commesse aperte</div>
                </div>
            </div>
            <div id="bu-bars-container" class="commessa-dashboard-bars-container">
                <div class="commessa-dashboard-loading">Caricamento...</div>
            </div>
        </div>

        <!-- Card: Project Manager (commesse aperte) -->
        <div class="commessa-card">
            <div class="commessa-card-header">
                <div>
                    <div class="commessa-card-title">Project Manager</div>
                    <div class="commessa-card-subtitle">Commesse aperte per responsabile</div>
                </div>
            </div>
            <div id="pm-list-container" class="commessa-dashboard-pm-list">
                <div class="commessa-dashboard-loading">Caricamento...</div>
            </div>
        </div>
    </div>

    <!-- Sezione: Commesse aperte per settore -->
    <div class="commessa-card commessa-dashboard-sector-card">
        <div class="commessa-card-header">
            <div>
                <div class="commessa-card-title">Commesse per Settore</div>
                <div class="commessa-card-subtitle">Distribuzione per settore merceologico</div>
            </div>
        </div>
        <div id="sector-pills-container" class="commessa-dashboard-sector-pills">
            <div class="commessa-dashboard-loading">Caricamento...</div>
        </div>
    </div>

    <!-- Sezione: Ultime 5 commesse aperte -->
    <div class="commessa-card">
        <div class="commessa-card-header">
            <div>
                <div class="commessa-card-title">Ultime Commesse Aperte</div>
                <div class="commessa-card-subtitle">Le 5 commesse aperte pi&ugrave; recenti</div>
            </div>
            <a href="index.php?section=commesse&page=elenco_commesse" class="commessa-cta secondary small">
                Vedi tutte
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </a>
        </div>
        <div id="latest-table-container">
            <div class="commessa-dashboard-loading">Caricamento...</div>
        </div>
    </div>

</div>

<script src="assets/js/commesse_dashboard.js"></script>
