<?php
/**
 * Business Unit - Analisi ore per Business Unit
 * Integrato in Intra_Incide: 2026-03
 * Stessa architettura di dashboard_ore (view + js + css + service)
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
<link rel="stylesheet" href="assets/css/ore_business_unit.css">

<div class="main-container" id="oreBuRoot">

    <!-- PAGE HEADER -->
    <div class="dboard-page-header">
        <div class="dboard-page-header__left">
            <h1 class="dboard-page-title">Ore per Business Unit</h1>
            <p class="dboard-page-sub" id="buPeriodLabel">Caricamento...</p>
        </div>
        <div class="dboard-page-header__right">
            <button class="button" id="btnExportCSV">Esporta CSV</button>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="dboard-filter-bar">
        <div class="dboard-filter-inner">

            <div class="dboard-fg">
                <label class="dboard-fg-label">Anno</label>
                <select class="dboard-select" id="filterYear"></select>
            </div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Mese</label>
                <select class="dboard-select" id="filterMonth">
                    <option value="">Tutti</option>
                </select>
            </div>

            <div class="dboard-vsep"></div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Project Manager</label>
                <select class="dboard-select" id="filterPM">
                    <option value="">Tutti</option>
                </select>
            </div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Commessa</label>
                <select class="dboard-select" id="filterProject">
                    <option value="">Tutte</option>
                </select>
            </div>

            <div class="dboard-vsep"></div>

            <div class="dboard-filter-actions">
                <button class="button primary" id="btnApply">Applica</button>
                <button class="button" id="btnReset">Reset</button>
            </div>

        </div>
    </div>

    <!-- BU TABS -->
    <div class="orebu-tabs-container">
        <div class="orebu-tabs" id="buTabs">
            <button class="orebu-tab active" data-bu="">Tutte le BU</button>
        </div>
    </div>

    <!-- SKELETON -->
    <div id="buSkeleton">
        <div class="dboard-kpi-grid orebu-kpi-grid">
            <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="dboard-kpi-card">
                <div class="dboard-skeleton dboard-skeleton--label"></div>
                <div class="dboard-skeleton dboard-skeleton--value"></div>
                <div class="dboard-skeleton dboard-skeleton--hint"></div>
            </div>
            <?php endfor; ?>
        </div>
        <div class="dboard-card dboard-skeleton--card">
            <div class="dboard-card__body dboard-loading-body">
                <div class="dboard-spinner"></div>
                <span class="muted">Caricamento dati...</span>
            </div>
        </div>
    </div>

    <!-- EMPTY STATE -->
    <div id="buEmpty" class="hidden">
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
    <div id="buError" class="hidden">
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
    <div id="buData" class="hidden">

        <!-- KPI GRID -->
        <div class="dboard-kpi-grid orebu-kpi-grid" id="kpiGrid">
            <div class="dboard-kpi-card">
                <div class="dboard-kpi-top">
                    <span class="dboard-kpi-label">Ore Imputate</span>
                </div>
                <div class="dboard-kpi-value" id="kWH">0</div>
                <div class="dboard-kpi-foot">
                    <span class="muted" id="kWHsub">su 0h budget</span>
                </div>
                <div class="dboard-kpi-bar"><div class="dboard-kpi-bar-fill dboard-bg-blue" id="kWHbar" style="width:0%"></div></div>
            </div>

            <div class="dboard-kpi-card">
                <div class="dboard-kpi-top">
                    <span class="dboard-kpi-label">Ore a Budget</span>
                </div>
                <div class="dboard-kpi-value" id="kEH">0</div>
                <div class="dboard-kpi-foot">
                    <span class="muted">totale periodo</span>
                </div>
            </div>

            <div class="dboard-kpi-card">
                <div class="dboard-kpi-top">
                    <span class="dboard-kpi-label">Risorse Attive</span>
                </div>
                <div class="dboard-kpi-value" id="kRes">0</div>
                <div class="dboard-kpi-foot">
                    <span class="dboard-delta" id="kResDelta"></span>
                </div>
            </div>

            <div class="dboard-kpi-card">
                <div class="dboard-kpi-top">
                    <span class="dboard-kpi-label">Avanzamento</span>
                </div>
                <div class="dboard-kpi-value" id="kAv">0%</div>
                <div class="dboard-kpi-foot">
                    <span class="muted" id="kAvS">vs budget</span>
                </div>
                <div class="dboard-kpi-bar"><div class="dboard-kpi-bar-fill dboard-bg-green" id="kAvBar" style="width:0%"></div></div>
            </div>
        </div>

        <!-- PIE CHARTS ROW -->
        <div class="dboard-grid-2 dboard-mb-24">
            <!-- PIE: Top Commesse -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <div>
                        <span class="dboard-card__title">Distribuzione Ore per Commessa</span>
                        <p class="dboard-card__sub">Top 8 commesse</p>
                    </div>
                </div>
                <div class="dboard-card__body orebu-pie-container">
                    <div class="orebu-pie-wrap">
                        <svg id="svgPie" viewBox="0 0 200 200" class="orebu-pie"></svg>
                    </div>
                    <div class="orebu-pie-legend" id="pieLeg"></div>
                </div>
            </div>

            <!-- PIE: Top Risorse -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <div>
                        <span class="dboard-card__title">Distribuzione Ore per Risorsa</span>
                        <p class="dboard-card__sub">Top 8 risorse</p>
                    </div>
                </div>
                <div class="dboard-card__body orebu-pie-container">
                    <div class="orebu-pie-wrap">
                        <svg id="svgPie2" viewBox="0 0 200 200" class="orebu-pie"></svg>
                    </div>
                    <div class="orebu-pie-legend" id="pieLeg2"></div>
                </div>
            </div>
        </div>

        <!-- TREND MENSILE -->
        <div class="dboard-card dboard-mb-24">
            <div class="dboard-card__head">
                <div>
                    <span class="dboard-card__title">Trend Mensile</span>
                    <p class="dboard-card__sub" id="trendBU">Tutte le BU</p>
                </div>
                <div class="dboard-card__head-right">
                    <div class="dboard-chart-legend" id="trendLegend">
                        <span class="dboard-leg-item"><span class="dboard-leg-dot" style="background:#2563eb"></span>Ore imputate</span>
                        <span class="dboard-leg-item"><span class="dboard-leg-dot" style="background:#10b981"></span>Ore budget</span>
                    </div>
                </div>
            </div>
            <div class="dboard-card__body">
                <div class="orebu-trend-wrap">
                    <svg id="svgTrend" class="orebu-trend"></svg>
                </div>
            </div>
        </div>

        <!-- TABELLA COMMESSE -->
        <div class="dboard-card dboard-mb-24">
            <div class="dboard-card__head">
                <div>
                    <span class="dboard-card__title">Commesse</span>
                    <p class="dboard-card__sub" id="tBU">Tutte le BU</p>
                </div>
                <div class="dboard-card__head-right">
                    <span class="muted" id="tableCount">0 commesse</span>
                </div>
            </div>
            <div class="table-container">
                <table class="table table--modern">
                    <thead>
                        <tr>
                            <th class="col-description">Commessa</th>
                            <th class="col-status">Stato</th>
                            <th class="col-person">PM</th>
                            <th class="col-status">#Risorse</th>
                            <th class="col-amount">Ore Imputate</th>
                            <th class="col-amount">Budget</th>
                            <th class="col-amount">Residuo</th>
                            <th style="min-width:130px">Avanzamento</th>
                        </tr>
                    </thead>
                    <tbody id="tProj"></tbody>
                </table>
            </div>
        </div>

        <!-- TABELLA RISORSE -->
        <div class="dboard-card">
            <div class="dboard-card__head">
                <div>
                    <span class="dboard-card__title">Risorse</span>
                    <p class="dboard-card__sub">Dettaglio per risorsa</p>
                </div>
                <div class="dboard-card__head-right">
                    <span class="muted" id="resCount">0 risorse</span>
                </div>
            </div>
            <div class="table-container">
                <table class="table table--modern">
                    <thead>
                        <tr>
                            <th class="col-person">Risorsa</th>
                            <th class="col-bu">Ruolo</th>
                            <th class="col-status">#Commesse</th>
                            <th class="col-amount">Ore Imputate</th>
                            <th class="col-amount">Budget</th>
                            <th style="min-width:130px">Avanzamento</th>
                        </tr>
                    </thead>
                    <tbody id="tRes"></tbody>
                </table>
            </div>
        </div>

    </div><!-- /#buData -->

</div><!-- /#oreBuRoot -->

<!-- Helpers condivisi per modulo Gestione Ore -->
<script src="assets/js/modules/oreHelpers.js"></script>
<script src="assets/js/ore_business_unit.js" defer></script>
