<?php
define('hostdbdataconnector', true);
define('accessofileinterni', true);

use Services\CommesseService;

if (!checkPermissionOrWarn('view_commesse'))
    return;

// Parametri sicuri - sanificazione robusta
$tabella = filter_input(INPUT_GET, 'tabella', FILTER_SANITIZE_SPECIAL_CHARS);
if ($tabella) {
    // Whitelist: solo alfanumerici e underscore
    $tabella = preg_replace('/[^A-Za-z0-9_]/', '', $tabella);
}

if (!$tabella) {
    echo "<div class='error'>Parametro 'tabella' mancante o non valido nell'URL.</div>";
    echo "<p><a href='index.php?section=commesse&page=elenco_commesse'>← Torna all'elenco commesse</a></p>";
    return;
}

// Dati bacheca
$row = CommesseService::getBachecaByTabella($tabella);
if (!$row) {
    echo "<div class='error'>Commessa non trovata o non autorizzata.</div>";
    echo "<p><a href='index.php?section=commesse&page=elenco_commesse'>← Torna all'elenco commesse</a></p>";
    return;
}
$titolo = $row['titolo'] ?? 'Commessa';

// Router sezioni - sanificazione robusta
$view = filter_input(INPUT_GET, 'view', FILTER_SANITIZE_SPECIAL_CHARS);
if ($view) {
    // Whitelist: solo lettere minuscole e underscore
    $view = preg_replace('/[^a-z_]/', '', strtolower($view));
} else {
    $view = '';
}

// Recupera dati commessa reali da elenco_commesse
global $database;
$commessaData = $database->query(
    "SELECT codice, oggetto, cliente, stato, responsabile_commessa, data_ordine, data_inizio_prevista, data_fine_prevista, valore_prodotto, business_unit
     FROM elenco_commesse WHERE codice = ? LIMIT 1",
    [$tabella],
    __FILE__
)->fetch(\PDO::FETCH_ASSOC);

if (!$commessaData) {
    echo "<div class='error'>Dati commessa non trovati.</div>";
    echo "<p><a href='index.php?section=commesse&page=elenco_commesse'>← Torna all'elenco commesse</a></p>";
    return;
}

// Applica fixMojibake
$commessaData['codice'] = fixMojibake($commessaData['codice'] ?? '');
$commessaData['oggetto'] = fixMojibake($commessaData['oggetto'] ?? '');
$commessaData['cliente'] = fixMojibake($commessaData['cliente'] ?? '');
$commessaData['stato'] = fixMojibake($commessaData['stato'] ?? '');
$commessaData['responsabile_commessa'] = fixMojibake($commessaData['responsabile_commessa'] ?? '');

// Mappa TAB KEYS -> FILE (whitelist canonica)
$tabMap = [
    'dati' => 'commessa_dati.php',
    'team' => 'commessa_team.php',
    'crono' => 'commessa_crono.php',
    'ore_costi' => 'commessa_ore_costi.php',
    'elaborati' => 'commessa_elaborati.php',
    'repository' => 'commessa_repository.php',
    'task' => 'commessa_task_overview.php',  // Overview, NON kanban completo
    'riunioni' => 'commessa_riunioni.php',
    'organigramma' => 'commessa_organigramma.php',
    'sicurezza' => 'commessa_sicurezza.php',
    'chiusura' => 'commessa_chiusura.php',
    // Sotto-view legacy sicurezza (raggiungibili dalle card interne)
    'documenti_sicurezza' => 'commessa_sicurezza_documenti.php',
    'controlli_sicurezza' => 'commessa_controlli_sicurezza.php',
    'sic_vvcs' => 'commessa_sic_vvcs.php',
    'sic_vcs' => 'commessa_sic_vcs.php',
    'sic_vrtp' => 'commessa_sic_vrtp.php',
    'sic_vpos' => 'commessa_sic_vpos.php',
    'sic_vfp' => 'commessa_sic_vfp.php',
    'sic_elenco_doc' => 'commessa_sic_elenco_doc.php',
];

// Fallback view: se non specificato o non valido, usa 'dati'
if (!$view || !isset($tabMap[$view])) {
    $view = 'dati';
}

// Tabs definizioni (keys canoniche + labels UI)
$tabs = [
    'dati' => 'Dati Generali',
    'team' => 'Team',
    'crono' => 'Cronoprogramma',
    'ore_costi' => 'Ore & Costi',
    'elaborati' => 'Elenco Elaborati',
    'repository' => 'Repository Docs',
    'task' => 'Task',
    'riunioni' => 'Riunioni',
    'organigramma' => 'Organigramma',
    'sicurezza' => 'Sicurezza',
    'chiusura' => 'Chiusura',
];

// Badge stato con colori
$statoBadgeColor = match ($commessaData['stato'] ?? '') {
    'Chiusa' => '#dc3545',
    'Aperta' => '#28a745',
    default => '#6c757d'
};
?>
<div class="main-container">
    <link rel="stylesheet" href="assets/css/commessa_dati_refinement.css">
    <style>
        .commessa-detail-header {
            background: #fafbfc;
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            padding: 0;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        .commessa-detail-title {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px 12px 20px;
            border-bottom: 1px solid #e1e4e8;
        }

        .commessa-codice {
            font-size: 1.5em;
            font-weight: bold;
            color: #212529;
        }

        .commessa-badge-stato {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 600;
            color: white;
        }

        .commessa-descrizione {
            font-size: 1em;
            color: #495057;
            padding: 12px 20px;
            border-bottom: 1px solid #e1e4e8;
        }

        .commessa-meta-section {
            padding: 16px 20px;
        }

        .commessa-meta-title {
            font-size: 0.7em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6c757d;
            margin-bottom: 12px;
        }

        .commessa-meta-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px 20px;
        }

        .commessa-meta-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f0f0f0;
        }

        .commessa-meta-label {
            font-size: 0.7em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #8590a6;
        }

        .commessa-meta-value {
            font-size: 0.9em;
            font-weight: 600;
            color: #212529;
        }

        .commessa-meta-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 600;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: fit-content;
        }

        @media (max-width: 1024px) {
            .commessa-meta-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .commessa-meta-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .commessa-detail-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .commessa-codice {
                font-size: 1.3em;
            }
        }

        .commessa-tabs {
            display: flex;
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 24px;
            gap: 8px;
            flex-wrap: wrap;
        }

        .commessa-tab {
            padding: 12px 20px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-size: 0.95em;
            font-weight: 500;
            color: #6c757d;
            text-decoration: none;
            transition: all 0.2s;
        }

        .commessa-tab:hover {
            color: #495057;
            background: #f8f9fa;
        }

        .commessa-tab.active {
            color: #007bff;
            border-bottom-color: #007bff;
        }

        .commessa-tab-content {
            min-height: 300px;
        }
    </style>

    <!-- Header dettaglio commessa -->
    <div class="commessa-detail-header">
        <div class="commessa-detail-title">
            <span class="commessa-codice"><?= htmlspecialchars($commessaData['codice'] ?? '—') ?></span>
            <span class="commessa-badge-stato" style="background-color: <?= $statoBadgeColor ?>">
                <?= htmlspecialchars($commessaData['stato'] ?? 'N/D') ?>
            </span>
        </div>

        <div class="commessa-descrizione">
            <?= htmlspecialchars($commessaData['oggetto'] ?? '—') ?>
        </div>

        <div class="commessa-meta-section">
            <div class="commessa-meta-title">Dettagli Commessa</div>
            <div class="commessa-meta-grid">
                <div class="commessa-meta-item">
                    <span class="commessa-meta-label">Cliente</span>
                    <span class="commessa-meta-value"><?= htmlspecialchars($commessaData['cliente'] ?? '—') ?></span>
                </div>
                <div class="commessa-meta-item">
                    <span class="commessa-meta-label">Responsabile</span>
                    <span
                        class="commessa-meta-value"><?= htmlspecialchars($commessaData['responsabile_commessa'] ?? '—') ?></span>
                </div>
                <?php if (!empty($commessaData['business_unit'])): ?>
                    <div class="commessa-meta-item">
                        <span class="commessa-meta-label">Business Unit</span>
                        <span class="commessa-meta-badge"><?= htmlspecialchars($commessaData['business_unit']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($commessaData['data_inizio_prevista'])): ?>
                    <div class="commessa-meta-item">
                        <span class="commessa-meta-label">Data Inizio</span>
                        <span class="commessa-meta-value"><?php
                        $dataInizio = $commessaData['data_inizio_prevista'];
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInizio)) {
                            echo htmlspecialchars(date('d/m/Y', strtotime($dataInizio)));
                        } else {
                            echo htmlspecialchars($dataInizio);
                        }
                        ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($commessaData['data_fine_prevista'])): ?>
                    <div class="commessa-meta-item">
                        <span class="commessa-meta-label">Data Fine Prevista</span>
                        <span class="commessa-meta-value"><?php
                        $dataFine = $commessaData['data_fine_prevista'];
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFine)) {
                            echo htmlspecialchars(date('d/m/Y', strtotime($dataFine)));
                        } else {
                            echo htmlspecialchars($dataFine);
                        }
                        ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tabs navigazione -->
    <div class="commessa-tabs">
        <?php
        $baseUrl = 'index.php?section=commesse&page=commessa&tabella=' . urlencode($tabella);
        foreach ($tabs as $tabKey => $tabLabel):
            $isActive = ($view === $tabKey);
            $activeClass = $isActive ? 'active' : '';
            ?>
            <a href="<?= $baseUrl . '&view=' . urlencode($tabKey) ?>" class="commessa-tab <?= $activeClass ?>"
                data-tab="<?= htmlspecialchars($tabKey) ?>">
                <?= htmlspecialchars($tabLabel) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Contenuto tab corrente -->
    <div class="commessa-tab-content-wrapper" <?= ($view === 'organigramma' ? 'style="padding:0; border:none; box-shadow:none; background:transparent;"' : '') ?>>
        <?php
        // Include tab tramite whitelist
        $fileView = __DIR__ . '/' . $tabMap[$view];
        if (is_file($fileView) && is_readable($fileView)) {
            include $fileView;
        } else {
            echo "<div class='alert alert-warning'>";
            echo "<p><strong>Sezione in sviluppo</strong></p>";
            echo "<p>La sezione <strong>" . htmlspecialchars($tabs[$view] ?? $view) . "</strong> sarà disponibile a breve.</p>";
            echo "</div>";
        }
        ?>
    </div>
</div>