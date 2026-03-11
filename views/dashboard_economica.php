<?php
/**
 * Dashboard Economica V2 - Riepilogo economico completo.
 * Tab: Overview, Commesse & SAL, Costi, Scadenze & Pagamenti, Cash Flow, HR Economico, Pipeline
 */
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    die();
}

if (!userHasPermission('view_dashboard_economica')) {
    echo '<div class="alert alert-warning">Non hai i permessi per accedere a questa pagina.</div>';
    return;
}
?>
<link rel="stylesheet" href="assets/css/dashboard_economica.css?v=<?= time() ?>">

<div class="main-container" id="dashboardEconomicaRoot">

    <!-- PAGE HEADER -->
    <div class="dboard-page-header">
        <div class="dboard-page-header__left">
            <h1 class="dboard-page-title">Dashboard Economica</h1>
            <p class="dboard-page-sub" id="dashPeriodLabel">Anno: <span id="currentYearLabel"><?= date('Y') ?></span></p>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="dboard-filter-bar">
        <div class="dboard-filter-inner">

            <div class="dboard-fg">
                <label class="dboard-fg-label">Anno</label>
                <select class="dboard-select" id="filterYear">
                    <option value="<?= date('Y') ?>"><?= date('Y') ?></option>
                </select>
            </div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Business Unit</label>
                <select class="dboard-select" id="filterBU">
                    <option value="">Tutte le BU</option>
                </select>
            </div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Project Manager</label>
                <select class="dboard-select" id="filterPM">
                    <option value="">Tutti i PM</option>
                </select>
            </div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Cliente</label>
                <select class="dboard-select" id="filterCliente">
                    <option value="">Tutti i clienti</option>
                </select>
            </div>

            <div class="dboard-vsep"></div>

            <div class="dboard-filter-actions">
                <button class="button primary" id="btnApply">Applica</button>
                <button class="button" id="btnReset">Reset</button>
            </div>

        </div>
    </div>

    <!-- TAB BAR -->
    <div class="dboard-tabs">
        <button class="dboard-tab active" data-tab="overview">Overview</button>
        <button class="dboard-tab" data-tab="commesse">Commesse &amp; SAL</button>
        <button class="dboard-tab" data-tab="costi">Costi</button>
        <button class="dboard-tab" data-tab="scadenze">Scadenze &amp; Pagamenti</button>
        <button class="dboard-tab" data-tab="cashflow">Cash Flow</button>
        <button class="dboard-tab" data-tab="hr">HR Economico</button>
        <button class="dboard-tab" data-tab="pipeline">Pipeline</button>
    </div>

    <!-- LOADING STATE -->
    <div id="dashLoading" class="dboard-state-box">
        <div class="dboard-spinner"></div>
        <span class="muted">Caricamento dati...</span>
    </div>

    <!-- ERROR STATE -->
    <div id="dashError" class="hidden">
        <div class="dboard-state-box">
            <p class="dboard-state-title">Errore di caricamento</p>
            <p class="muted" id="dashErrorMsg">Impossibile recuperare i dati.</p>
            <button class="button primary" id="btnRetry">Riprova</button>
        </div>
    </div>

    <!-- PANELS -->
    <div id="dashPanels" class="hidden">

        <!-- ════════════════════════════════════════════════════════════ -->
        <!-- TAB: OVERVIEW -->
        <!-- ════════════════════════════════════════════════════════════ -->
        <div class="dboard-panel active" id="panel-overview">
            <!-- KPI Grid -->
            <div class="dboard-kpi-grid" id="kpiOverview"></div>

            <!-- Charts Row -->
            <div class="dboard-row">
                <div class="dboard-col-8">
                    <div class="dboard-card">
                        <div class="dboard-card__head">
                            <span class="dboard-card__title">Trend Ore Mensile</span>
                        </div>
                        <div class="dboard-card__body">
                            <div id="chartHoursTrend" class="dboard-chart-area"></div>
                        </div>
                    </div>
                </div>
                <div class="dboard-col-4">
                    <div class="dboard-card">
                        <div class="dboard-card__head">
                            <span class="dboard-card__title">Distribuzione per BU</span>
                        </div>
                        <div class="dboard-card__body">
                            <div id="chartBuDistribution" class="dboard-chart-area"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Latest Projects -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Ultime Commesse Attive</span>
                    <span class="dboard-card__meta" id="overviewLatestCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table" id="tableOverviewLatest">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Descrizione</th>
                                    <th>Cliente</th>
                                    <th>PM</th>
                                    <th>BU</th>
                                    <th class="text-right">Valore</th>
                                    <th class="text-right">Avanzamento</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyOverviewLatest"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════ -->
        <!-- TAB: COMMESSE & SAL -->
        <!-- ════════════════════════════════════════════════════════════ -->
        <div class="dboard-panel" id="panel-commesse">
            <!-- KPI Commesse -->
            <div class="dboard-kpi-grid dboard-kpi-grid--small" id="kpiCommesse"></div>

            <!-- Tabella Commesse -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Riepilogo Economico Commesse</span>
                    <span class="dboard-card__meta" id="commesseCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table" id="tableCommesse">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Descrizione</th>
                                    <th>Cliente</th>
                                    <th>PM</th>
                                    <th>Stato</th>
                                    <th class="text-right">Valore</th>
                                    <th class="text-right">Ore Prev.</th>
                                    <th class="text-right">Ore Lav.</th>
                                    <th class="text-right">Costo Prev.</th>
                                    <th class="text-right">Costo Eff.</th>
                                    <th class="text-right">Delta</th>
                                    <th class="text-right">Avanz. %</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyCommesse"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SAL Summary -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Stato Avanzamento Lavori (SAL)</span>
                </div>
                <div class="dboard-card__body">
                    <div id="salSummaryContent" class="dboard-summary-grid"></div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════ -->
        <!-- TAB: COSTI -->
        <!-- ════════════════════════════════════════════════════════════ -->
        <div class="dboard-panel" id="panel-costi">
            <!-- KPI Costi -->
            <div class="dboard-kpi-grid dboard-kpi-grid--small" id="kpiCosti"></div>

            <!-- Tabella Costi Lavoro -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Costi Lavoro per Commessa</span>
                    <span class="dboard-card__meta" id="costiCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table" id="tableCosti">
                            <thead>
                                <tr>
                                    <th>Commessa</th>
                                    <th>Descrizione</th>
                                    <th>Cliente</th>
                                    <th>PM</th>
                                    <th class="text-right">Ore Prev.</th>
                                    <th class="text-right">Ore Eff.</th>
                                    <th class="text-right">Costo Prev.</th>
                                    <th class="text-right">Costo Eff.</th>
                                    <th class="text-right">Delta</th>
                                    <th class="text-right">Delta %</th>
                                    <th class="text-right">Risorse</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyCosti"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Altri Costi -->
            <div class="dboard-card dboard-mt-16" id="costiAltriCard">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Altri Costi</span>
                    <span class="dboard-card__meta">Acquisti, Rimborsi, Overhead</span>
                </div>
                <div class="dboard-card__body">
                    <div id="costiAltriContent">
                        <div class="dboard-empty-state">
                            <p class="muted">Sezione in completamento. Tabelle richieste:</p>
                            <ul class="dboard-pending-list" id="costiPendingList">
                                <li>project_purchase</li>
                                <li>project_other_cost</li>
                                <li>project_overheads_cost</li>
                                <li>quotation_reimbursment</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════ -->
        <!-- TAB: SCADENZE & PAGAMENTI -->
        <!-- ════════════════════════════════════════════════════════════ -->
        <div class="dboard-panel" id="panel-scadenze">
            <!-- KPI Scadenze -->
            <div class="dboard-kpi-grid dboard-kpi-grid--small" id="kpiScadenze"></div>

            <!-- Scadenze in arrivo -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Rate / Scadenze</span>
                    <span class="dboard-card__meta" id="scadenzeCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table" id="tableScadenze">
                            <thead>
                                <tr>
                                    <th>Data Scadenza</th>
                                    <th>Commessa</th>
                                    <th>Cliente</th>
                                    <th>Descrizione</th>
                                    <th class="text-right">Valore Prev.</th>
                                    <th class="text-right">Importo Netto</th>
                                    <th>Stato</th>
                                    <th>Fatturato</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyScadenze"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Pagamenti ricevuti -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Pagamenti Ricevuti</span>
                    <span class="dboard-card__meta" id="pagamentiCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div id="pagamentiContent">
                        <div class="dboard-empty-state">
                            <p class="muted">Sezione in completamento. Richiede integrazione tabella pagamenti.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════ -->
        <!-- TAB: CASH FLOW -->
        <!-- ════════════════════════════════════════════════════════════ -->
        <div class="dboard-panel" id="panel-cashflow">
            <!-- KPI Cash Flow -->
            <div class="dboard-kpi-grid dboard-kpi-grid--small" id="kpiCashflow"></div>

            <!-- Cash Flow Chart -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Cash Flow Mensile</span>
                </div>
                <div class="dboard-card__body">
                    <div id="chartCashflow" class="dboard-chart-area dboard-chart-area--large"></div>
                </div>
            </div>

            <!-- Dettaglio Cash Flow -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Dettaglio Entrate/Uscite</span>
                </div>
                <div class="dboard-card__body">
                    <div id="cashflowDetailContent">
                        <div class="dboard-empty-state">
                            <p class="muted">Sezione in completamento. Richiede aggregazione dati fatturazione e pagamenti.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════ -->
        <!-- TAB: HR ECONOMICO -->
        <!-- ════════════════════════════════════════════════════════════ -->
        <div class="dboard-panel" id="panel-hr">
            <!-- KPI HR -->
            <div class="dboard-kpi-grid dboard-kpi-grid--small" id="kpiHr"></div>

            <!-- Ore per Risorsa -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Ore Lavorate per Risorsa</span>
                    <span class="dboard-card__meta" id="hrHoursCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table" id="tableHrHours">
                            <thead>
                                <tr>
                                    <th>Risorsa</th>
                                    <th>Ruolo</th>
                                    <th>Reparto</th>
                                    <th class="text-right">Ore Lavoro</th>
                                    <th class="text-right">Ore Viaggio</th>
                                    <th class="text-right">Ore Totali</th>
                                    <th class="text-right">Progetti</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyHrHours"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Costi per Risorsa -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Costi Previsto vs Effettivo per Risorsa</span>
                    <span class="dboard-card__meta" id="hrCostsCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table" id="tableHrCosts">
                            <thead>
                                <tr>
                                    <th>Risorsa</th>
                                    <th>Ruolo</th>
                                    <th class="text-right">Ore Prev.</th>
                                    <th class="text-right">Ore Eff.</th>
                                    <th class="text-right">Costo Prev.</th>
                                    <th class="text-right">Costo Eff.</th>
                                    <th class="text-right">Delta</th>
                                    <th class="text-right">Delta %</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyHrCosts"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Assenze -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Assenze</span>
                </div>
                <div class="dboard-card__body">
                    <div id="hrAssenzeContent">
                        <div class="dboard-empty-state">
                            <p class="muted">Sezione in completamento. Richiede sincronizzazione tabella hr_absence.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════ -->
        <!-- TAB: PIPELINE -->
        <!-- ════════════════════════════════════════════════════════════ -->
        <div class="dboard-panel" id="panel-pipeline">
            <!-- KPI Pipeline -->
            <div class="dboard-kpi-grid dboard-kpi-grid--small" id="kpiPipeline"></div>

            <!-- Pipeline Overview -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Pipeline Opportunita</span>
                </div>
                <div class="dboard-card__body">
                    <div id="pipelineContent">
                        <div class="dboard-empty-state">
                            <p class="muted">Sezione in completamento. Richiede integrazione tabelle:</p>
                            <ul class="dboard-pending-list">
                                <li>quotation_header</li>
                                <li>negotiation</li>
                                <li>negotiation_budget</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trattative Attive -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Trattative Attive</span>
                    <span class="dboard-card__meta" id="trattativeCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div id="trattativeContent">
                        <div class="dboard-empty-state">
                            <p class="muted">Dati trattative non ancora disponibili.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>
<!-- JS auto-loaded by main.php layout: assets/js/dashboard_economica.js -->
