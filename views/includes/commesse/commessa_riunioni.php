<?php
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}

// Link CSS overview
echo '<link rel="stylesheet" href="/assets/css/commesse_detail_overview.css">';

// Dati placeholder (TODO: bind con sistema MOM/riunioni)
$riunioni = [
    [
        'titolo' => 'Kick-off meeting progetto',
        'data' => '2024-05-10 10:00',
        'partecipanti' => ['Mario Rossi', 'Laura Bianchi', 'Luca Verdi'],
        'mom_disponibile' => true
    ],
    [
        'titolo' => 'Review avanzamento lavori',
        'data' => '2024-06-15 14:30',
        'partecipanti' => ['Mario Rossi', 'Anna Neri', 'Paolo Blu'],
        'mom_disponibile' => true
    ],
    [
        'titolo' => 'Coordinamento tecnico strutture',
        'data' => '2024-06-28 09:00',
        'partecipanti' => ['Laura Bianchi', 'Luca Verdi'],
        'mom_disponibile' => false
    ],
];

// Helper per initials
function getMeetingInitials($nome) {
    $parts = explode(' ', $nome);
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($nome, 0, 2));
}
?>

<div class="commessa-card">
    <div class="commessa-card-header">
        <div>
            <div class="commessa-card-title">Riunioni</div>
            <div class="commessa-card-subtitle"><?= count($riunioni) ?> riunioni registrate</div>
        </div>
        <div style="display: flex; gap: 12px;">
            <a href="#" class="commessa-cta secondary small" data-tooltip="Crea verbale riunione">
                <img src="/assets/icons/plus.png" alt="" class="commessa-cta-icon">
                Nuova riunione
            </a>
            <a href="#" class="commessa-cta" data-tooltip="Apri registro riunioni">
                <img src="/assets/icons/mom.png" alt="" class="commessa-cta-icon">
                Registro riunioni
            </a>
        </div>
    </div>

    <?php if (!empty($riunioni)): ?>
        <!-- Lista meeting cards -->
        <div style="display: flex; flex-direction: column; gap: 16px;">
            <?php foreach ($riunioni as $riunione): ?>
                <div class="commessa-meeting-card">
                    <div class="commessa-meeting-header">
                        <div>
                            <div class="commessa-meeting-title">
                                <?= htmlspecialchars($riunione['titolo']) ?>
                            </div>
                            <?php if ($riunione['mom_disponibile']): ?>
                                <span class="commessa-badge success" style="margin-top: 6px;">
                                    MOM Disponibile
                                </span>
                            <?php else: ?>
                                <span class="commessa-badge secondary" style="margin-top: 6px;">
                                    MOM Mancante
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="commessa-meeting-date">
                            <?= date('d/m/Y H:i', strtotime($riunione['data'])) ?>
                        </div>
                    </div>

                    <!-- Partecipanti -->
                    <div style="margin-bottom: 12px; font-size: 0.8em; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6c757d;">
                        Partecipanti
                    </div>
                    <div class="commessa-meeting-participants">
                        <?php foreach ($riunione['partecipanti'] as $partecipante): ?>
                            <div class="commessa-participant-chip">
                                <div class="commessa-avatar-initials" style="width: 20px; height: 20px; font-size: 0.65em;">
                                    <?= getMeetingInitials($partecipante) ?>
                                </div>
                                <span><?= htmlspecialchars($partecipante) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Azioni -->
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #f0f0f0; text-align: right;">
                        <?php if ($riunione['mom_disponibile']): ?>
                            <a href="#" class="commessa-cta small" data-tooltip="Apri verbale">
                                <img src="/assets/icons/show.png" alt="" class="commessa-cta-icon">
                                Apri MOM
                            </a>
                        <?php else: ?>
                            <a href="#" class="commessa-cta secondary small" data-tooltip="Crea verbale">
                                <img src="/assets/icons/plus.png" alt="" class="commessa-cta-icon">
                                Crea MOM
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Footer -->
        <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #e9ecef; text-align: right;">
            <a href="#" class="commessa-cta secondary">
                Vedi tutte le riunioni
            </a>
        </div>

    <?php else: ?>
        <!-- Empty state -->
        <div class="commessa-empty">
            <h3>Nessuna riunione associata</h3>
            <p>Inizia registrando la prima riunione o creando un verbale (MOM).</p>
            <a href="#" class="commessa-cta">
                <img src="/assets/icons/mom.png" alt="" class="commessa-cta-icon">
                Crea prima riunione
            </a>
        </div>
    <?php endif; ?>

    <!-- Info footer -->
    <div class="commessa-alert info" style="margin-top: 20px;">
        <svg class="commessa-alert-icon" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
        </svg>
        <div class="commessa-alert-content">
            <strong>TODO: Integrazione MOM</strong>
            Questa vista mostra dati placeholder. L'integrazione con il sistema MOM (Minutes of Meeting) è in fase di sviluppo.
        </div>
    </div>
</div>
