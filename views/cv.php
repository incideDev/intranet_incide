<?php
if (!defined('AccessoFileInterni'))
    define('AccessoFileInterni', true);

// Render basic page structure
?>
<div class="main-container" id="cv-manager-app">
    <?php renderPageTitle('Gestione CV & Recruiting', '#667eea'); ?>

    <!-- Token CSRF for JS -->
    <?php if (isset($_SESSION['CSRFtoken']) && $_SESSION['CSRFtoken'] !== ''): ?>
        <meta name="token-csrf" content="<?= $_SESSION['CSRFtoken'] ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="/assets/css/form.css">
    <link rel="stylesheet" href="/assets/css/cv_manager.css">

    <!-- NAVIGATION TABS -->
    <div style="margin-bottom: 20px;">
        <div class="tab-navigation">
            <div class="tab-links">
                <button class="tab-link active" onclick="cvManager.switchTab('cv-view-list')"
                    data-target="cv-view-list">
                    <i class="bi bi-list-ul"></i> Elenco
                </button>
                <button class="tab-link" onclick="cvManager.switchTab('cv-view-stats')" data-target="cv-view-stats">
                    <i class="bi bi-pie-chart"></i> Statistiche
                </button>
                <button class="tab-link" onclick="cvManager.switchTab('cv-view-compare')" data-target="cv-view-compare">
                    <i class="bi bi-layout-split"></i> Confronta
                </button>
            </div>
        </div>
    </div>

    <!-- LIST VIEW -->
    <div id="cv-view-list" class="cv-view-section">
        <div class="archive-container">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
                <h3 style="margin-bottom: 0; font-weight: bold;">Candidati</h3>
            </div>
            <hr style="margin-bottom: 16px;">

            <!-- Filters (MOM Style) -->
            <div class="row half-width" style="gap: 20px; margin-bottom: 16px;">
                <div style="flex: 2;">
                    <label for="cv-search-input" class="text-label label-above"
                        style="margin-bottom: 4px;">Cerca:</label>
                    <input type="text" id="cv-search-input" class="select-box" style="width:100%;"
                        placeholder="Nome, email o parole chiave...">
                </div>
                <div style="flex: 1;">
                    <label for="cv-filter-profession" class="text-label label-above"
                        style="margin-bottom: 4px;">Professionalità:</label>
                    <select id="cv-filter-profession" class="select-box" style="width:100%;">
                        <option value="">Tutte</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label for="cv-filter-status" class="text-label label-above"
                        style="margin-bottom: 4px;">Stato:</label>
                    <select id="cv-filter-status" class="select-box" style="width:100%;">
                        <option value="">Tutti</option>
                        <option value="nuovo">Nuovo</option>
                        <option value="in_valutazione">In Valutazione</option>
                        <option value="colloquio">Colloquio</option>
                        <option value="assunto">Assunto</option>
                        <option value="scartato">Scartato</option>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label for="cv-filter-score" class="text-label label-above"
                        style="margin-bottom: 4px;">Score:</label>
                    <select id="cv-filter-score" class="select-box" style="width:100%;">
                        <option value="0">Tutti</option>
                        <option value="40">40+</option>
                        <option value="60">60+</option>
                        <option value="80">80+</option>
                    </select>
                </div>
            </div>

            <div class="row" style="margin-bottom: 16px;">
                <button type="button" class="button button-secondary" id="btn-reset-filters">Reset</button>
            </div>

            <!-- Grid Container -->
            <div id="cv-list-container" class="cv-grid">
                <div class="cv-flex-center" style="width: 100%; grid-column: 1 / -1; padding: 40px;">
                    <div class="cv-spinner"></div>
                    <p class="cv-text-muted mt-2">Caricamento...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- DETAIL VIEW (Hidden Overlay/Section) -->
    <div id="cv-view-detail" class="cv-view-section d-none">
        <div class="archive-container">
            <div id="cv-detail-content"></div>
        </div>
    </div>

    <!-- STATS VIEW -->
    <div id="cv-view-stats" class="cv-view-section d-none">
        <div class="archive-container">
            <div id="stats-container">
                <div class="cv-flex-center cv-text-muted">Caricamento statistiche...</div>
            </div>
            <!-- Charts -->
            <div class="cv-row" style="margin-top: 30px; gap: 20px;">
                <div class="cv-col" style="background: #f9f9f9; padding: 20px; border-radius: 12px; flex:1;">
                    <h5 class="cv-text-center mb-3">Distribuzione per Professionalità</h5>
                    <div style="position: relative; height: 300px; width: 100%;">
                        <canvas id="professionChart"></canvas>
                    </div>
                </div>
                <div class="cv-col" style="background: #f9f9f9; padding: 20px; border-radius: 12px; flex:1;">
                    <h5 class="cv-text-center mb-3">Distribuzione Score</h5>
                    <div style="position: relative; height: 300px; width: 100%;">
                        <canvas id="scoreChart"></canvas>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- COMPARISON VIEW -->
    <div id="cv-view-compare" class="cv-view-section d-none">
        <div class="archive-container">
            <h3 style="margin-bottom: 10px;"><i class="bi bi-bar-chart"></i> Confronto Candidati</h3>
            <p class="cv-text-muted mb-3">Seleziona fino a 5 candidati per confrontare le competenze</p>
            <hr>

            <div style="margin-bottom: 20px;">
                <label class="text-label label-above">Seleziona candidati da confrontare:</label>
                <select class="select-box" id="comparisonSelect" multiple size="10" style="height: 200px; width: 100%;">
                    <!-- Popolato dinamicamente via JS -->
                </select>
                <div style="margin-top: 15px;">
                    <button class="button" onclick="cvManager.compareSelected()">
                        <i class="bi bi-graph-up"></i> Confronta Selezionati
                    </button>
                    <small class="cv-text-muted" style="margin-left: 10px;">(Tieni premuto CTRL per selezionare più
                        candidati)</small>
                </div>
            </div>

            <div id="comparisonContainer" style="margin-top: 30px;"></div>
        </div>
    </div>

</div>

<!-- PDF Modal -->
<div id="pdf-modal" class="modal" style="display:none;">
    <div class="modal-content" style="width: 90vw; height: 90vh; max-width: 100%; padding: 0;">
        <span class="close" onclick="closeModal('pdf-modal')"
            style="position: absolute; top: 10px; right: 25px; color: #fff; font-size: 35px; font-weight: bold; cursor: pointer; z-index: 1001;">&times;</span>
        <iframe id="pdf-modal-content" style="width: 100%; height: 100%; border: none;"></iframe>
    </div>
</div>

<!-- Scripts -->
<script src="/assets/js/modal_viewer.js"></script>
<script src="/assets/js/chart.umd.js"></script>
<script src="/assets/js/cv_manager.js"></script>