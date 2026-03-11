<?php
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}

// Link CSS overview
echo '<link rel="stylesheet" href="/assets/css/commesse_detail_overview.css">';

// Dati placeholder (in futuro da ProjectTime / Akeron / DB)
$budgetOre = [
    'totali' => 230,
    'consumate' => 184,
    'residue' => 46,
    'percentuale' => 80
];

$risorse = [
    ['nome' => 'Mario Rossi', 'ore_consumate' => 74, 'ore_totali' => 92, 'percentuale' => 80],
    ['nome' => 'Laura Bianchi', 'ore_consumate' => 56, 'ore_totali' => 70, 'percentuale' => 80],
    ['nome' => 'Luca Verdi', 'ore_consumate' => 32, 'ore_totali' => 40, 'percentuale' => 80],
    ['nome' => 'Anna Neri', 'ore_consumate' => 22, 'ore_totali' => 28, 'percentuale' => 79],
];

// Helper per classe progress bar
function getProgressClass($perc) {
    if ($perc >= 90) return 'danger';
    if ($perc >= 75) return 'warning';
    return '';
}
?>

<div class="commessa-overview-grid">
    <!-- Card 1: Budget Ore -->
    <div class="commessa-card">
        <div class="commessa-card-header">
            <div>
                <div class="commessa-card-title">Budget Ore</div>
                <div class="commessa-card-subtitle">Avanzamento complessivo</div>
            </div>
        </div>

        <!-- Numero grande -->
        <div style="text-align: center; margin-bottom: 24px;">
            <div style="font-size: 2.5em; font-weight: 700; color: #212529;">
                <?= $budgetOre['consumate'] ?>h <span style="color: #6c757d; font-size: 0.6em;">/ <?= $budgetOre['totali'] ?>h</span>
            </div>
        </div>

        <!-- Progress bar -->
        <div class="commessa-progress">
            <div class="commessa-progress-bar <?= getProgressClass($budgetOre['percentuale']) ?>"
                 style="width: <?= $budgetOre['percentuale'] ?>%;"></div>
        </div>
        <div class="commessa-progress-label">
            <span><?= $budgetOre['percentuale'] ?>% utilizzato</span>
        </div>

        <!-- Mini cards -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 24px;">
            <div class="commessa-mini-card">
                <div class="commessa-mini-card-value"><?= $budgetOre['residue'] ?>h</div>
                <div class="commessa-mini-card-label">Ore Residue</div>
            </div>
            <div class="commessa-mini-card">
                <div class="commessa-mini-card-value"><?= $budgetOre['consumate'] ?>h</div>
                <div class="commessa-mini-card-label">Ore Consumate</div>
            </div>
        </div>

        <!-- Footer -->
        <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #e9ecef; font-size: 0.8em; color: #6c757d; text-align: center;">
            Sorgente: <strong>ProjectTime</strong> · <strong>Akeron</strong> · <em>Dati simulati</em>
        </div>
    </div>

    <!-- Card 2: Ore per Risorsa -->
    <div class="commessa-card">
        <div class="commessa-card-header">
            <div>
                <div class="commessa-card-title">Ore per Risorsa</div>
                <div class="commessa-card-subtitle">Simulazione</div>
            </div>
        </div>

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
                                <?= $r['ore_consumate'] ?> / <?= $r['ore_totali'] ?>h
                            </span>
                        </div>
                        <div class="commessa-progress" style="height: 8px;">
                            <div class="commessa-progress-bar <?= getProgressClass($r['percentuale']) ?>"
                                 style="width: <?= $r['percentuale'] ?>%;"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- CTA -->
        <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #e9ecef; text-align: right;">
            <a href="#" class="commessa-cta secondary small" data-tooltip="Dashboard ore non ancora implementata">
                Apri dashboard ore
            </a>
        </div>
    </div>
</div>
