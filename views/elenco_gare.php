<?php
if (!defined('hostdbdataconnector'))
    define('hostdbdataconnector', true);
if (!defined('accessofileinterni'))
    define('accessofileinterni', true);

if ($Session->logged_in !== true) {
    header("Location: /index");
    exit;
}

if (!checkPermissionOrWarn('view_gare'))
    return;
?>
<link rel="stylesheet" href="/assets/css/gare.css">
<style>
.gare-kpi-panel {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}
.gare-kpi-card {
    flex: 1;
    min-width: 130px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
    border: 1px solid #e8ecf0;
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    transition: box-shadow 0.15s, transform 0.15s;
}
.gare-kpi-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.12); transform: translateY(-2px); }
.gare-kpi-card.active { box-shadow: 0 0 0 2px var(--kpi-color,#3498db); }
.gare-kpi-icon {
    width: 42px; height: 42px; border-radius: 10px;
    background: var(--kpi-color, #3498db);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.gare-kpi-icon svg { width: 20px; height: 20px; fill: none; stroke: #fff; stroke-width: 2; }
.gare-kpi-value { font-size: 1.7em; font-weight: 700; line-height: 1; color: #1a1f23; }
.gare-kpi-label { font-size: 0.72em; text-transform: uppercase; color: #6c757d; margin-top: 2px; letter-spacing: 0.4px; }
.gare-filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 14px;
    padding: 12px 14px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e1e4e8;
}
.gare-filter-bar label { font-size: 12px; font-weight: 600; color: #586069; white-space: nowrap; }
.gare-filter-bar select,
.gare-filter-bar input[type="text"] {
    padding: 6px 10px; border: 1px solid #d1d5da; border-radius: 6px;
    font-size: 13px; background: #fff; color: #24292e; min-width: 120px;
}
.gare-filter-bar select:focus, .gare-filter-bar input:focus { outline: none; border-color: #0366d6; }
.gare-filter-bar .gf-reset-btn {
    padding: 6px 14px; border: 1px solid #d1d5da; border-radius: 6px;
    background: #fff; font-size: 13px; cursor: pointer; color: #586069;
}
.gare-filter-bar .gf-reset-btn:hover { background: #f1f3f4; }
.gare-filter-active-count {
    font-size: 11px; color: #0366d6; font-weight: 600; margin-left: auto;
}
</style>

<div class="main-container page-gare">
    <?php renderPageTitle('Elenco Gare', '#3498DB'); ?>

    <!-- KPI PANEL -->
    <div class="gare-kpi-panel" id="gare-kpi-panel" style="display:none">
        <div class="gare-kpi-card" data-kpi="totale" style="--kpi-color:#3498db">
            <div class="gare-kpi-icon">
                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            </div>
            <div><div class="gare-kpi-value" id="kpi-val-totale">—</div><div class="gare-kpi-label">Totale</div></div>
        </div>
        <div class="gare-kpi-card" data-kpi="1" style="--kpi-color:#f0b429">
            <div class="gare-kpi-icon">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div><div class="gare-kpi-value" id="kpi-val-1">—</div><div class="gare-kpi-label">In valutazione</div></div>
        </div>
        <div class="gare-kpi-card" data-kpi="2" style="--kpi-color:#5b8def">
            <div class="gare-kpi-icon">
                <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div><div class="gare-kpi-value" id="kpi-val-2">—</div><div class="gare-kpi-label">In corso</div></div>
        </div>
        <div class="gare-kpi-card" data-kpi="3" style="--kpi-color:#17a2b8">
            <div class="gare-kpi-icon">
                <svg viewBox="0 0 24 24"><polyline points="22 2 13 11 11 9 22 2"/><path d="M22 2L11 13M9 11l-7 10 7-4.5L22 2z"/></svg>
            </div>
            <div><div class="gare-kpi-value" id="kpi-val-3">—</div><div class="gare-kpi-label">Consegnate</div></div>
        </div>
        <div class="gare-kpi-card" data-kpi="4" style="--kpi-color:#63b365">
            <div class="gare-kpi-icon">
                <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div><div class="gare-kpi-value" id="kpi-val-4">—</div><div class="gare-kpi-label">Aggiudicate</div></div>
        </div>
        <div class="gare-kpi-card" data-kpi="5" style="--kpi-color:#e74c3c">
            <div class="gare-kpi-icon">
                <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </div>
            <div><div class="gare-kpi-value" id="kpi-val-5">—</div><div class="gare-kpi-label">Non aggiudicate</div></div>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="gare-filter-bar" id="gare-filter-bar" style="display:none">
        <label for="gf-stato">Stato</label>
        <select id="gf-stato" data-filter="stato">
            <option value="">Tutti gli stati</option>
        </select>
        <label for="gf-bu">BU</label>
        <select id="gf-bu" data-filter="bu">
            <option value="">Tutte le BU</option>
        </select>
        <label for="gf-assegnato">Assegnato</label>
        <select id="gf-assegnato" data-filter="assegnato">
            <option value="">Tutti</option>
        </select>
        <label for="gf-ente">Ente</label>
        <input type="text" id="gf-ente" data-filter="ente" placeholder="Cerca ente...">
        <button class="gf-reset-btn" id="gf-reset">&#10005; Reset</button>
        <span class="gare-filter-active-count" id="gf-count" style="display:none"></span>
    </div>

    <!-- VISTA TABELLA -->
    <div id="table-view" class="">
        <div class="gare-table-wrapper">
            <table class="table table-filterable gare-table" id="gare-table" data-remote="0" data-page-size="10">
                <thead>
                    <tr>
                        <th class="gara-number">N° Gara</th>
                        <th>Ente</th>
                        <th>Titolo</th>
                        <th>Settore</th>
                        <th>Tipologia</th>
                        <th>Luogo</th>
                        <th>Data Uscita</th>
                        <th>Data Scadenza</th>
                        <th>Stato</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <div class="gare-empty-message hidden" id="gare-empty">Non è presente alcuna gara al momento.</div>
        </div>
    </div>

    <!-- VISTA KANBAN -->
    <div id="kanban-view" class="hidden">
        <?php
        // Mappa stati gare (status_id => label) - sincronizzati con GareService.php
        $gare_statimap = \Services\GareService::getGareStatusMap();

        // Colori stati - sincronizzati con GareService.php
        $gare_stateColors = \Services\GareService::getGareStatusColors();

        // Prepara parametri per il kanban template unificato
        $statimap = $gare_statimap;
        $stateColors = $gare_stateColors;
        $tasks = []; // Le gare saranno popolate via JS
        $tabella = 'ext_jobs';
        $kanbanType = 'gare';
        $showAddButton = false; // Le gare si aggiungono tramite estrazione bandi
        $dataAttributes = ['tipo' => 'gara'];

        // Include il template unificato
        include __DIR__ . '/components/kanban_template.php';
        ?>
    </div>

    <!-- VISTA CALENDARIO -->
    <div id="calendar-view" class="hidden">
        <!-- Il renderer JS popolerà -->
    </div>

    <!-- VISTA GANTT -->
    <div id="gantt-view" class="hidden">
        <!-- Il renderer JS popolerà -->
    </div>
</div>

<script src="/assets/js/gare_list.js" defer></script>
<script src="/assets/js/modules/calendar_view.js" defer></script>
<script src="/assets/js/modules/gantt_view.js" defer></script>