<?php
/**
 * Dashboard Ore - Analisi ore lavorate, straordinari, saturazione, anomalie.
 * Integrato in Intra_Incide: 2026-02
 */
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    die();
}

if (!userHasPermission('view_dashboard_ore')) {
    echo '<div class="alert alert-warning">Non hai i permessi per accedere a questa pagina.</div>';
    return;
}
?>
<link rel="stylesheet" href="assets/css/dashboard_ore.css">

<div class="main-container" id="dashboardOreRoot">

    <!-- PAGE HEADER -->
    <div class="dboard-page-header">
        <div class="dboard-page-header__left">
            <h1 class="dboard-page-title">Dashboard Ore</h1>
            <p class="dboard-page-sub" id="dashPeriodLabel">Caricamento...</p>
        </div>
        <div class="dboard-page-header__right">
            <button class="button" id="btnExportCSV">Esporta CSV</button>
            <button class="button primary" id="btnSaveView">Salva vista</button>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="dboard-filter-bar">
        <div class="dboard-filter-inner">

            <div class="dboard-fg">
                <label class="dboard-fg-label">Periodo</label>
                <div class="dboard-toggle-group" id="periodToggle">
                    <button class="active" data-period="month">Mese</button>
                    <button data-period="quarter">Trimestre</button>
                    <button data-period="custom">Custom</button>
                </div>
            </div>

            <div class="dboard-fg" id="customDateRange" style="display:none">
                <label class="dboard-fg-label">Dal / Al</label>
                <div class="dboard-date-range">
                    <input type="date" id="filterDateFrom" class="dboard-input">
                    <span class="dboard-date-sep">-</span>
                    <input type="date" id="filterDateTo" class="dboard-input">
                </div>
            </div>

            <div class="dboard-vsep"></div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Business Unit</label>
                <select class="dboard-select" id="filterBU">
                    <option value="">Tutte le BU</option>
                </select>
            </div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Commessa</label>
                <select class="dboard-select" id="filterProject">
                    <option value="">Tutte</option>
                </select>
            </div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Risorsa</label>
                <select class="dboard-select" id="filterResource">
                    <option value="">Tutte</option>
                </select>
            </div>

            <div class="dboard-vsep"></div>

            <div class="dboard-filter-actions">
                <button class="button primary" id="btnApply">Applica</button>
                <button class="button" id="btnReset">Reset</button>
                <button class="button" id="btnCompare">Confronta</button>
            </div>

        </div>
    </div>

    <!-- COMPARE BANNER -->
    <div class="dboard-compare-banner hidden" id="compareBanner">
        <span id="compareBannerText"></span>
        <button class="dboard-compare-close" id="btnCompareClose">&times;</button>
    </div>

    <!-- SKELETON -->
    <div id="dashSkeleton" class="hidden">
        <div class="dboard-kpi-grid">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="dboard-kpi-card">
                <div class="dboard-skeleton dboard-skeleton--label"></div>
                <div class="dboard-skeleton dboard-skeleton--value"></div>
                <div class="dboard-skeleton dboard-skeleton--hint"></div>
            </div>
            <?php endfor; ?>
        </div>
        <div class="dboard-card dboard-skeleton--card">
            <div class="dboard-card__head">
                <div class="dboard-skeleton dboard-skeleton--title"></div>
            </div>
            <div class="dboard-card__body dboard-loading-body">
                <div class="dboard-spinner"></div>
                <span class="muted">Caricamento dati...</span>
            </div>
        </div>
    </div>

    <!-- EMPTY STATE -->
    <div id="dashEmpty" class="hidden">
        <div class="dboard-card">
            <div class="dboard-state-box">
                <div class="dboard-state-icon dboard-state-icon--empty"></div>
                <p class="dboard-state-title">Nessun dato trovato</p>
                <p class="muted">Non ci sono ore registrate con i filtri selezionati.</p>
                <button class="button" id="btnResetEmpty">Resetta filtri</button>
            </div>
        </div>
    </div>

    <!-- ERROR STATE -->
    <div id="dashError" class="hidden">
        <div class="dboard-card">
            <div class="dboard-state-box">
                <div class="dboard-state-icon dboard-state-icon--error"></div>
                <p class="dboard-state-title">Errore di caricamento</p>
                <p class="muted">Impossibile recuperare i dati. Verifica la connessione.</p>
                <button class="button primary" id="btnRetry">Riprova</button>
            </div>
        </div>
    </div>

    <!-- DATA CONTENT -->
    <div id="dashData" class="hidden">

        <!-- KPI GRID -->
        <div class="dboard-kpi-grid" id="kpiGrid"></div>

        <!-- TREND -->
        <div class="dboard-card dboard-mb-24">
            <div class="dboard-card__head">
                <div>
                    <span class="dboard-card__title">Ore giornaliere</span>
                    <p class="dboard-card__sub">Trend del periodo selezionato</p>
                </div>
                <div class="dboard-card__head-right">
                    <div class="dboard-toggle-group dboard-toggle-group--sm" id="granToggle">
                        <button class="active" data-gran="day">Giorno</button>
                        <button data-gran="week">Settimana</button>
                    </div>
                </div>
            </div>
            <div class="dboard-card__body">
                <div class="dboard-chart-legend" id="trendLegend">
                    <span class="dboard-leg-item"><span class="dboard-leg-dot" style="background:#2563eb"></span>Ore lavorate</span>
                    <span class="dboard-leg-item"><span class="dboard-leg-dot" style="background:#f59e0b"></span>Straordinari</span>
                    <span class="dboard-leg-item"><span class="dboard-leg-dot dboard-leg-dot--dashed"></span>Obiettivo</span>
                </div>
                <div class="dboard-chart-wrap">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>

        <!-- COMMESSE + ANOMALIE -->
        <div class="dboard-grid-3">

            <div class="dboard-card">
                <div class="dboard-card__head">
                    <div>
                        <span class="dboard-card__title">Top commesse per ore</span>
                        <p class="dboard-card__sub" id="commSub"></p>
                    </div>
                    <div class="dboard-card__head-right">
                        <span class="dboard-pill active" data-commfilter="all">Tutte</span>
                        <span class="dboard-pill" data-commfilter="alert">Con alert</span>
                    </div>
                </div>
                <div class="table-container">
                    <table class="table table--modern">
                        <thead>
                            <tr>
                                <th class="col-description">Commessa</th>
                                <th class="col-amount">Ore Imputate</th>
                                <th class="col-amount">Budget</th>
                                <th class="col-amount">Residuo</th>
                                <th style="min-width:130px">Avanzamento</th>
                                <th class="col-status">Alert</th>
                                <th class="col-status">Stato</th>
                            </tr>
                        </thead>
                        <tbody id="commBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="dboard-card">
                <div class="dboard-card__head">
                    <div>
                        <span class="dboard-card__title">Anomalie</span>
                        <p class="dboard-card__sub" id="anomSub"></p>
                    </div>
                    <div class="dboard-card__head-right">
                        <span class="dboard-pill" data-anomfilter="all">Tutte</span>
                        <span class="dboard-pill active" data-anomfilter="critical">Critiche</span>
                    </div>
                </div>
                <div class="dboard-card__body" id="anomList"></div>
            </div>

        </div>

        <!-- DETTAGLIO RISORSE -->
        <div class="dboard-card">
            <div class="dboard-card__head">
                <div>
                    <span class="dboard-card__title">Dettaglio Risorse</span>
                    <p class="dboard-card__sub" id="resSub">Clicca una riga per il dettaglio</p>
                </div>
                <div class="dboard-card__head-right">
                    <div class="dboard-tab-bar">
                        <button class="dboard-tab active" data-restab="all">Tutti</button>
                        <button class="dboard-tab" data-restab="over">Oversaturati</button>
                        <button class="dboard-tab" data-restab="anom">Con anomalie</button>
                    </div>
                </div>
            </div>
            <div class="table-container">
                <table class="table table--modern">
                    <thead>
                        <tr>
                            <th class="col-person">Risorsa</th>
                            <th class="col-bu">BU</th>
                            <th class="col-amount">Ore Imputate</th>
                            <th class="col-amount">Ore a Budget</th>
                            <th style="min-width:140px">Avanzamento</th>
                            <th class="col-status">#Commesse</th>
                            <th>Trend 8 sett.</th>
                            <th class="col-actions">Azione</th>
                        </tr>
                    </thead>
                    <tbody id="resBody"></tbody>
                </table>
            </div>
        </div>

    </div><!-- /#dashData -->

    <!-- RESOURCE DRAWER -->
    <div class="dboard-drawer hidden" id="resourceDrawer">
        <div class="dboard-drawer__head">
            <div>
                <div class="dboard-drawer__title" id="drawerName">-</div>
                <div class="dboard-drawer__role muted" id="drawerRole">-</div>
            </div>
            <button class="dboard-drawer__close" id="btnDrawerClose">&times;</button>
        </div>
        <div class="dboard-drawer__body" id="drawerBody"></div>
    </div>

    <!-- PROJECT DRAWER -->
    <div class="dboard-drawer dboard-drawer--wide hidden" id="projectDrawer">
        <div class="dboard-drawer__head">
            <div>
                <div class="dboard-drawer__title" id="projectDrawerTitle">-</div>
                <div class="dboard-drawer__role muted" id="projectDrawerSub">-</div>
            </div>
            <button class="dboard-drawer__close" id="btnProjectDrawerClose">&times;</button>
        </div>
        <div class="dboard-drawer__body" id="projectDrawerBody">
            <div class="dboard-skeleton dboard-skeleton--chart"></div>
        </div>
    </div>

</div><!-- /#dashboardOreRoot -->

<!-- Chart.js UMD (espone window.Chart) - DEVE essere prima di dashboard_ore.js -->
<script src="assets/js/chart.umd.js"></script>
<!-- Helpers condivisi per modulo Gestione Ore -->
<script src="assets/js/modules/oreHelpers.js"></script>
<script src="assets/js/dashboard_ore.js" defer></script>
