<?php
/**
 * Dettaglio Utente - Analisi ore per singolo utente
 * Integrato in Intra_Incide: 2026-03
 * Stessa architettura di dashboard_ore e ore_business_unit
 */
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    die();
}

// Questa pagina è accessibile a tutti gli utenti loggati (vedono solo se stessi)
// Gli utenti con permesso view_dashboard_ore possono vedere anche altri utenti
$canViewOthers = userHasPermission('view_dashboard_ore');

// Dati utente corrente per default
$currentUserId = $_SESSION['user_id'] ?? '';
$currentUserName = ($_SESSION['nome'] ?? '') . ' ' . ($_SESSION['cognome'] ?? '');
$currentUserRole = $_SESSION['ruolo'] ?? '';
$currentUserInitials = '';
if (!empty($_SESSION['nome']) && !empty($_SESSION['cognome'])) {
    $currentUserInitials = strtoupper(substr($_SESSION['nome'], 0, 1) . substr($_SESSION['cognome'], 0, 1));
}
$currentProfilePic = $Session->userinfo['profile_picture'] ?? null;
$defaultPic = 'assets/images/default_profile.png';
$hasProfilePic = $currentProfilePic && $currentProfilePic !== $defaultPic;
?>
<link rel="stylesheet" href="assets/css/dashboard_ore.css">
<link rel="stylesheet" href="assets/css/ore_dettaglio_utente.css">

<div class="main-container" id="oreUserRoot">

    <!-- PAGE HEADER -->
    <div class="dboard-page-header">
        <div class="dboard-page-header__left">
            <h1 class="dboard-page-title">Ore Utente</h1>
            <span class="oreuser-badge">Dettaglio Personale</span>
        </div>
        <div class="dboard-page-header__right">
            <div class="oreuser-user-chip" id="userChip">
                <span class="oreuser-avatar" id="userAvatar"><?php if ($hasProfilePic): ?><img src="/<?= htmlspecialchars($currentProfilePic) ?>" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"><?php else: ?><?= htmlspecialchars($currentUserInitials) ?><?php endif; ?></span>
                <div class="oreuser-user-info">
                    <span class="oreuser-user-name" id="userName"><?php echo htmlspecialchars($currentUserName); ?></span>
                    <span class="oreuser-user-role" id="userRole"><?php echo htmlspecialchars($currentUserRole); ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="oreuser-actions-row">
        <button class="button" id="btnExportCSV">Esporta CSV</button>
    </div>

    <!-- FILTER BAR -->
    <div class="dboard-filter-bar">
        <div class="dboard-filter-inner">

            <?php if ($canViewOthers): ?>
            <div class="dboard-fg">
                <label class="dboard-fg-label">Utente</label>
                <select class="dboard-select" id="fUtente">
                    <option value="<?php echo htmlspecialchars($currentUserId); ?>"><?php echo htmlspecialchars($currentUserName); ?></option>
                </select>
            </div>
            <div class="dboard-vsep"></div>
            <?php else: ?>
            <input type="hidden" id="fUtente" value="<?php echo htmlspecialchars($currentUserId); ?>">
            <?php endif; ?>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Dal</label>
                <input type="date" class="dboard-input" id="fDal">
            </div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Al</label>
                <input type="date" class="dboard-input" id="fAl">
            </div>

            <div class="dboard-vsep"></div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Anno</label>
                <select class="dboard-select" id="fAnno"></select>
            </div>

            <div class="dboard-fg">
                <label class="dboard-fg-label">Commessa</label>
                <select class="dboard-select" id="fCommessa">
                    <option value="">Tutte</option>
                </select>
            </div>

            <div class="dboard-vsep"></div>

            <div class="dboard-filter-actions">
                <button class="button primary" id="btnApplica">Applica</button>
                <button class="button" id="btnReset">Reset</button>
            </div>

        </div>
    </div>

    <!-- ACTIVE CHIPS -->
    <div class="oreuser-chips-container" id="activeChips"></div>

    <!-- SKELETON -->
    <div id="userSkeleton">
        <div class="dboard-kpi-grid oreuser-kpi-grid">
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
    <div id="userEmpty" class="hidden">
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
    <div id="userError" class="hidden">
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
    <div id="userData" class="hidden">

        <!-- KPI GRID -->
        <div class="dboard-kpi-grid oreuser-kpi-grid" id="kpiGrid">
            <div class="dboard-kpi-card">
                <div class="dboard-kpi-top">
                    <span class="dboard-kpi-label">Ore Imputate</span>
                </div>
                <div class="dboard-kpi-value" id="kSp">0</div>
                <div class="dboard-kpi-foot">
                    <span class="muted" id="kSpSub">su 0h budget</span>
                </div>
                <div class="dboard-kpi-bar"><div class="dboard-kpi-bar-fill dboard-bg-blue" id="kSpBar" style="width:0%"></div></div>
            </div>

            <div class="dboard-kpi-card">
                <div class="dboard-kpi-top">
                    <span class="dboard-kpi-label">Ore a Budget</span>
                </div>
                <div class="dboard-kpi-value" id="kBu">0</div>
                <div class="dboard-kpi-foot">
                    <span class="muted">totale periodo</span>
                </div>
            </div>

            <div class="dboard-kpi-card">
                <div class="dboard-kpi-top">
                    <span class="dboard-kpi-label">Residuo</span>
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

            <div class="dboard-kpi-card">
                <div class="dboard-kpi-top">
                    <span class="dboard-kpi-label">Commesse</span>
                </div>
                <div class="dboard-kpi-value" id="kCo">0</div>
                <div class="dboard-kpi-foot">
                    <span class="muted" id="kCoS">nel periodo</span>
                </div>
            </div>
        </div>

        <!-- CHARTS ROW (3 colonne: 2 pie + trend) -->
        <div class="oreuser-charts-row dboard-mb-24">
            <!-- PIE: Budget per commessa -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <div>
                        <span class="dboard-card__title">Budget per Commessa</span>
                        <p class="dboard-card__sub">Distribuzione ore budget</p>
                    </div>
                    <div class="dboard-card__head-right">
                        <span class="muted" id="pieTot1">0h totali</span>
                    </div>
                </div>
                <div class="dboard-card__body orebu-pie-container oreuser-pie-compact">
                    <div class="orebu-pie-wrap">
                        <svg id="svgBudget" viewBox="0 0 200 200" class="orebu-pie"></svg>
                    </div>
                    <div class="orebu-pie-legend" id="legBudget"></div>
                </div>
            </div>

            <!-- PIE: Imputate per commessa -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <div>
                        <span class="dboard-card__title">Ore Imputate per Commessa</span>
                        <p class="dboard-card__sub">Distribuzione ore lavorate</p>
                    </div>
                    <div class="dboard-card__head-right">
                        <span class="muted" id="pieTot2">0h totali</span>
                    </div>
                </div>
                <div class="dboard-card__body orebu-pie-container oreuser-pie-compact">
                    <div class="orebu-pie-wrap">
                        <svg id="svgSpese" viewBox="0 0 200 200" class="orebu-pie"></svg>
                    </div>
                    <div class="orebu-pie-legend" id="legSpese"></div>
                </div>
            </div>

            <!-- TREND MENSILE -->
            <div class="dboard-card">
                <div class="dboard-card__head">
                    <div>
                        <span class="dboard-card__title">Trend Mensile</span>
                        <p class="dboard-card__sub" id="trendLabel">Anno corrente</p>
                    </div>
                    <div class="dboard-card__head-right">
                        <div class="dboard-chart-legend" id="trendLegend">
                            <span class="dboard-leg-item"><span class="dboard-leg-dot" style="background:#2563eb"></span>Imputate</span>
                            <span class="dboard-leg-item"><span class="dboard-leg-dot" style="background:#10b981"></span>Budget</span>
                        </div>
                    </div>
                </div>
                <div class="dboard-card__body">
                    <div class="orebu-trend-wrap">
                        <svg id="svgTrend" class="orebu-trend"></svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABELLA COMMESSE -->
        <div class="dboard-card">
            <div class="dboard-card__head">
                <div>
                    <span class="dboard-card__title">Dettaglio Commesse</span>
                    <p class="dboard-card__sub">Ore per commessa nel periodo</p>
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
                            <th class="col-bu">Business Unit</th>
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

    </div><!-- /#userData -->

</div><!-- /#oreUserRoot -->

<script>
    // Passa info utente al JS
    window.OREUSER_CONFIG = {
        canViewOthers: <?php echo $canViewOthers ? 'true' : 'false'; ?>,
        currentUserId: <?php echo json_encode($currentUserId); ?>,
        currentUserName: <?php echo json_encode($currentUserName); ?>,
        currentUserRole: <?php echo json_encode($currentUserRole); ?>,
        currentUserInitials: <?php echo json_encode($currentUserInitials); ?>
    };
</script>
<!-- Helpers condivisi per modulo Gestione Ore -->
<script src="assets/js/modules/oreHelpers.js"></script>
<script src="assets/js/ore_dettaglio_utente.js" defer></script>
