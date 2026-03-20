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
                        <table class="dboard-table table--modern" id="tableOverviewLatest">
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
                        <table class="dboard-table table--modern" id="tableCommesse">
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
                        <table class="dboard-table table--modern" id="tableCosti">
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

            <!-- KPI Altri Costi -->
            <div class="dboard-kpi-grid dboard-kpi-grid--5 dboard-mt-16" id="kpiAltriCosti"></div>

            <!-- Acquisti -->
            <div class="dboard-card dboard-mt-16" id="costiPurchaseCard">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Acquisti</span>
                    <span class="dboard-card__meta" id="purchaseCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table table--modern">
                            <thead>
                                <tr>
                                    <th>Commessa</th>
                                    <th>Fornitore</th>
                                    <th>Descrizione</th>
                                    <th>Doc.</th>
                                    <th>Data</th>
                                    <th class="text-right">Importo</th>
                                    <th>Subforn.</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyPurchase"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Altri Costi -->
            <div class="dboard-card dboard-mt-16" id="costiOtherCard">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Altri Costi</span>
                    <span class="dboard-card__meta" id="otherCostCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table table--modern">
                            <thead>
                                <tr>
                                    <th>Commessa</th>
                                    <th>Tipo Costo</th>
                                    <th>Data</th>
                                    <th class="text-right">Preventivo</th>
                                    <th class="text-right">Effettivo</th>
                                    <th class="text-right">Delta</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyOtherCost"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Overhead -->
            <div class="dboard-card dboard-mt-16" id="costiOverheadCard">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Overhead</span>
                    <span class="dboard-card__meta" id="overheadCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table table--modern">
                            <thead>
                                <tr>
                                    <th>Commessa</th>
                                    <th>Voce di Costo</th>
                                    <th>Data</th>
                                    <th class="text-right">Qtà</th>
                                    <th class="text-right">Costo Unit.</th>
                                    <th class="text-right">Importo</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyOverhead"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Rimborsi -->
            <div class="dboard-card dboard-mt-16" id="costiReimbCard">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Rimborsi</span>
                    <span class="dboard-card__meta" id="reimbCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table table--modern">
                            <thead>
                                <tr>
                                    <th>Commessa</th>
                                    <th>Tipo</th>
                                    <th>Risorsa</th>
                                    <th>Data</th>
                                    <th class="text-right">Valore</th>
                                    <th class="text-right">Assegnato</th>
                                    <th>Approvato</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyReimb"></tbody>
                        </table>
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
                        <table class="dboard-table table--modern" id="tableScadenze">
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

            <!-- Fatture Emesse -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Fatture Emesse</span>
                    <span class="dboard-card__meta" id="fattureCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table table--modern">
                            <thead>
                                <tr>
                                    <th>Numero</th>
                                    <th>Tipo</th>
                                    <th>Data</th>
                                    <th>Pagamento</th>
                                    <th class="text-right">Imponibile</th>
                                    <th class="text-right">IVA</th>
                                    <th class="text-right">Totale</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyFatture"></tbody>
                        </table>
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

            <!-- Dettaglio Fatturato Mensile -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Dettaglio Fatturato Mensile</span>
                    <span class="dboard-card__meta" id="cashflowDetailCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table table--modern">
                            <thead>
                                <tr>
                                    <th>Numero</th>
                                    <th>Tipo</th>
                                    <th>Data</th>
                                    <th>Pagamento</th>
                                    <th class="text-right">Imponibile</th>
                                    <th class="text-right">Totale</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyCashflowDetail"></tbody>
                        </table>
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
                        <table class="dboard-table table--modern" id="tableHrHours">
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
                        <table class="dboard-table table--modern" id="tableHrCosts">
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
                    <span class="dboard-card__meta" id="hrAbsenceCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table table--modern">
                            <thead>
                                <tr>
                                    <th>Risorsa</th>
                                    <th>Data</th>
                                    <th>Tipo</th>
                                    <th>Stato</th>
                                    <th class="text-right">Ore</th>
                                    <th>Approvata</th>
                                    <th>Data Appr.</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyHrAbsence"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════ -->
        <!-- TAB: PIPELINE -->
        <!-- ════════════════════════════════════════════════════════════ -->
        <div class="dboard-panel" id="panel-pipeline">
            <!-- KPI Pipeline -->
            <div class="dboard-kpi-grid" id="kpiPipeline"></div>

            <!-- Pipeline Offerte -->
            <div class="dboard-card dboard-mt-16">
                <div class="dboard-card__head">
                    <span class="dboard-card__title">Offerte</span>
                    <span class="dboard-card__meta" id="pipelineCount"></span>
                </div>
                <div class="dboard-card__body">
                    <div class="dboard-table-wrap">
                        <table class="dboard-table table--modern">
                            <thead>
                                <tr>
                                    <th>N. Offerta</th>
                                    <th>Oggetto</th>
                                    <th>Stato</th>
                                    <th>Commerciale</th>
                                    <th>Data</th>
                                    <th class="text-right">Importo</th>
                                    <th>Esito</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyPipeline"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>
<!-- JS auto-loaded by main.php layout: assets/js/dashboard_economica.js -->
