<?php
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}

use Services\DashboardOreService;

// Link CSS overview
echo '<link rel="stylesheet" href="/assets/css/commesse_detail_overview.css">';

// Carica dati reali da project_time
$oreSummary = DashboardOreService::getCommessaOreSummary($tabella);
$hasData = $oreSummary['success'] && ($oreSummary['data']['consumate'] > 0 || $oreSummary['data']['budget'] > 0);

if ($hasData) {
    $dati = $oreSummary['data'];
    $consumate = $dati['consumate'];
    $budget = $dati['budget'];
    $residue = $dati['residue'];
    $percentuale = $dati['percentuale'];
    $hasBudget = $dati['hasBudget'];
    $risorse = $dati['risorse'];
} else {
    $consumate = 0;
    $budget = 0;
    $residue = 0;
    $percentuale = 0;
    $hasBudget = false;
    $risorse = [];
}

// Helper per classe progress bar
function getProgressClassOreCosti($perc) {
    if ($perc >= 90) return 'danger';
    if ($perc >= 75) return 'warning';
    return '';
}
?>

<?php if (!$hasData): ?>
<div class="commessa-overview-grid" style="grid-template-columns: 1fr;">
    <div class="commessa-card" style="text-align: center; padding: 48px 24px;">
        <div style="font-size: 2.5em; margin-bottom: 12px; opacity: 0.3;">⏱</div>
        <div style="font-size: 1.1em; font-weight: 600; color: #495057; margin-bottom: 8px;">Nessuna ora registrata</div>
        <div style="font-size: 0.9em; color: #6c757d;">Non risultano ore imputate su questa commessa in ProjectTime.</div>
    </div>
</div>
<?php else: ?>
<div class="commessa-overview-grid">
    <!-- Card 1: Budget Ore -->
    <div class="commessa-card">
        <div class="commessa-card-header">
            <div>
                <div class="commessa-card-title"><?= $hasBudget ? 'Budget Ore' : 'Ore Lavorate' ?></div>
                <div class="commessa-card-subtitle"><?= $hasBudget ? 'Avanzamento complessivo' : 'Totale ore imputate' ?></div>
            </div>
        </div>

        <!-- Numero grande -->
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="font-size: 2.5em; font-weight: 700; color: #212529;">
                <?= number_format($consumate, 0, ',', '.') ?>h
                <?php if ($hasBudget): ?>
                    <span style="color: #6c757d; font-size: 0.6em;">/ <?= number_format($budget, 0, ',', '.') ?>h</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($hasBudget): ?>
        <!-- Progress bar (solo se c'è budget) -->
        <div class="commessa-progress">
            <div class="commessa-progress-bar <?= getProgressClassOreCosti($percentuale) ?>"
                 style="width: <?= min($percentuale, 100) ?>%;"></div>
        </div>
        <div class="commessa-progress-label">
            <span><?= $percentuale ?>% utilizzato</span>
        </div>
        <?php endif; ?>

        <!-- Mini cards -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 24px;">
            <?php if ($hasBudget): ?>
            <div class="commessa-mini-card">
                <div class="commessa-mini-card-value"><?= number_format($residue, 0, ',', '.') ?>h</div>
                <div class="commessa-mini-card-label">Ore Residue</div>
            </div>
            <?php else: ?>
            <div class="commessa-mini-card">
                <div class="commessa-mini-card-value"><?= number_format($dati['travelHours'], 0, ',', '.') ?>h</div>
                <div class="commessa-mini-card-label">di cui Trasferta</div>
            </div>
            <?php endif; ?>
            <div class="commessa-mini-card">
                <div class="commessa-mini-card-value"><?= $dati['resourceCount'] ?></div>
                <div class="commessa-mini-card-label">Risorse Coinvolte</div>
            </div>
        </div>

        <!-- Footer -->
        <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #e9ecef; font-size: 0.8em; color: #6c757d; text-align: center;">
            Sorgente: <strong>ProjectTime</strong><?= $hasBudget ? ' · <strong>Budget</strong>' : '' ?>
        </div>
    </div>

    <!-- Card 2: Ore per Risorsa -->
    <div class="commessa-card">
        <div class="commessa-card-header">
            <div>
                <div class="commessa-card-title">Ore per Risorsa</div>
                <div class="commessa-card-subtitle">Top <?= count($risorse) ?> risorse per ore imputate</div>
            </div>
        </div>

        <?php if (!empty($risorse)): ?>
        <!-- Lista risorse -->
        <div style="display: flex; flex-direction: column; gap: 16px;">
            <?php foreach ($risorse as $r): ?>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <!-- Avatar con initials -->
                    <div class="commessa-avatar-initials">
                        <?= getInitials($r['nome']) ?>
                    </div>

                    <!-- Nome + Progress -->
                    <div style="flex: 1;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span style="font-weight: 500; font-size: 0.9em; color: #212529;">
                                <?= htmlspecialchars($r['nome']) ?>
                            </span>
                            <span style="font-size: 0.85em; font-weight: 600; color: #495057;">
                                <?= number_format($r['ore'], 0, ',', '.') ?>h
                                <span style="color: #adb5bd; font-weight: 400;">(<?= $r['percentualeSuTotale'] ?>%)</span>
                            </span>
                        </div>
                        <div class="commessa-progress" style="height: 8px;">
                            <div class="commessa-progress-bar"
                                 style="width: <?= $r['percentualeSuTotale'] ?>%;"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="color: #6c757d; text-align: center;">Nessuna risorsa trovata</p>
        <?php endif; ?>

        <!-- CTA -->
        <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #e9ecef; text-align: right;">
            <a href="/index.php?section=dashboard_ore&projectId=<?= urlencode($tabella) ?>"
               class="commessa-cta secondary small">
                Apri dashboard ore
            </a>
        </div>
    </div>
</div>
<?php endif; ?>
