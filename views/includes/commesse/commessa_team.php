<?php
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}

use Services\CommesseService;

// Recupera team overview tramite metodo canonico
$teamData = CommesseService::getCommessaTeamOverview($tabella);
$pm = $teamData['pm'] ?? null;
$members = $teamData['members'] ?? [];

$hasTeam = ($pm !== null || !empty($members));
?>

<style>
    .team-overview-container {
        padding: 24px 0;
    }

    .team-section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    .team-section-title {
        font-size: 1.3em;
        font-weight: 600;
        color: #212529;
    }

    .team-cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 16px;
        margin-bottom: 32px;
    }

    .team-member-card {
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 20px;
        text-align: center;
        transition: all 0.2s;
    }

    .team-member-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }

    .team-avatar-wrapper {
        position: relative;
        width: 80px;
        height: 80px;
        margin: 0 auto 12px;
    }

    .team-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        object-fit: cover;
        box-shadow: 0 0 0 3px #f8f9fa;
    }

    .team-avatar-badge {
        position: absolute;
        bottom: 0;
        right: 0;
        background: #007bff;
        color: white;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 4px;
        border: 2px solid white;
    }

    .team-member-name {
        font-weight: 600;
        font-size: 1em;
        color: #212529;
        margin-bottom: 4px;
    }

    .team-member-role {
        font-size: 0.9em;
        color: #6c757d;
    }

    .team-empty-state {
        background: #f8f9fa;
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        padding: 48px 24px;
        text-align: center;
        color: #6c757d;
    }

    .team-empty-state h3 {
        margin: 0 0 12px 0;
        color: #495057;
    }

    .team-empty-state p {
        margin: 0 0 24px 0;
        font-size: 0.95em;
    }
</style>

<div class="team-overview-container">
    <!-- Header con CTA -->
    <div class="team-section-header">
        <h2 class="team-section-title">Team Commessa</h2>
        <div>
            <?php
            // CTA principale: Gestisci organigramma
            $ctaUrl = 'index.php?section=commesse&page=commessa&tabella=' . urlencode($tabella) . '&view=organigramma';
            $ctaLabel = 'Gestisci Organigramma';
            $ctaStyle = 'primary';
            include __DIR__ . '/components/cta_link.php';
            ?>
        </div>
    </div>

    <?php if ($hasTeam): ?>
        <!-- Sezione PM -->
        <?php if ($pm): ?>
            <div style="margin-bottom: 32px;">
                <h3
                    style="font-size: 1.1em; font-weight: 600; color: #495057; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #dee2e6;">
                    Responsabile Commessa
                </h3>
                <div class="team-cards-grid">
                    <div class="team-member-card">
                        <div class="team-avatar-wrapper">
                            <img src="<?= $pm['avatar'] ?>" alt="<?= htmlspecialchars($pm['nome'], ENT_QUOTES) ?>"
                                class="team-avatar">
                            <?php if ($pm['badge']): ?>
                                <span class="team-avatar-badge"><?= htmlspecialchars($pm['badge'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="team-member-name"><?= htmlspecialchars($pm['nome'], ENT_QUOTES) ?></div>
                        <div class="team-member-role"><?= htmlspecialchars($pm['ruolo'], ENT_QUOTES) ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Sezione Team Operativo -->
        <?php if (!empty($members)): ?>
            <div>
                <h3
                    style="font-size: 1.1em; font-weight: 600; color: #495057; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #dee2e6;">
                    Team Operativo <span style="font-weight: 400; color: #6c757d; font-size: 0.9em;">(<?= count($members) ?>
                        membri)</span>
                </h3>
                <div class="team-cards-grid">
                    <?php foreach ($members as $member): ?>
                        <div class="team-member-card">
                            <div class="team-avatar-wrapper">
                                <img src="<?= $member['avatar'] ?>" alt="<?= htmlspecialchars($member['nome'], ENT_QUOTES) ?>"
                                    class="team-avatar">
                                <?php if ($member['badge']): ?>
                                    <span class="team-avatar-badge"><?= htmlspecialchars($member['badge'], ENT_QUOTES) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="team-member-name"><?= htmlspecialchars($member['nome'], ENT_QUOTES) ?></div>
                            <div class="team-member-role"><?= htmlspecialchars($member['ruolo'], ENT_QUOTES) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- Empty state -->
        <div class="team-empty-state">
            <h3>Team non definito</h3>
            <p>Non è stato ancora configurato un organigramma per questa commessa.</p>
            <?php
            $ctaUrl = 'index.php?section=commesse&page=commessa&tabella=' . urlencode($tabella) . '&view=organigramma';
            $ctaLabel = 'Definisci Organigramma';
            $ctaStyle = 'primary';
            include __DIR__ . '/components/cta_link.php';
            ?>
        </div>
    <?php endif; ?>
</div>