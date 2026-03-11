<?php
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}

// Link CSS overview
echo '<link rel="stylesheet" href="/assets/css/commesse_detail_overview.css">';

// Dati placeholder (TODO: bind con document manager / Nextcloud)
$cartelle = [
    ['nome' => 'Documenti generali', 'icona' => 'folder.png', 'attiva' => true],
    ['nome' => 'Elaborati grafici', 'icona' => 'folder.png', 'attiva' => false],
    ['nome' => 'Relazioni tecniche', 'icona' => 'folder.png', 'attiva' => false],
    ['nome' => 'Calcoli strutturali', 'icona' => 'folder.png', 'attiva' => false],
    ['nome' => 'Sicurezza', 'icona' => 'folder.png', 'attiva' => false],
];

$files = [
    ['nome' => 'Relazione_generale_rev01.pdf', 'size' => '2.4 MB', 'icona' => 'pdf.png'],
    ['nome' => 'Capitolato_tecnico.docx', 'size' => '850 KB', 'icona' => 'word.png'],
    ['nome' => 'Computo_metrico.xlsx', 'size' => '1.2 MB', 'icona' => 'excel.png'],
    ['nome' => 'Planimetria_generale.dwg', 'size' => '5.6 MB', 'icona' => 'cad.png'],
];

// Helper per icona file
function getFileIcon($nome) {
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    $iconMap = [
        'pdf' => 'pdf.png',
        'docx' => 'word.png',
        'doc' => 'word.png',
        'xlsx' => 'excel.png',
        'xls' => 'excel.png',
        'dwg' => 'cad.png',
        'dxf' => 'cad.png',
        'jpg' => 'image.png',
        'png' => 'image.png',
    ];
    return $iconMap[$ext] ?? 'file.png';
}
?>

<div class="commessa-card">
    <div class="commessa-card-header">
        <div>
            <div class="commessa-card-title">Repository Documenti</div>
            <div class="commessa-card-subtitle">Gestione file e cartelle</div>
        </div>
        <div style="display: flex; gap: 12px;">
            <a href="#" class="commessa-cta secondary small" data-tooltip="Carica nuovi file">
                <img src="/assets/icons/upload.png" alt="" class="commessa-cta-icon">
                Upload
            </a>
            <a href="index.php?section=commesse&page=commessa_documenti&tabella=<?= urlencode($tabella) ?>"
               class="commessa-cta">
                <img src="/assets/icons/folder.png" alt="" class="commessa-cta-icon">
                Apri Document Manager
            </a>
        </div>
    </div>

    <!-- Layout folder/file -->
    <div class="commessa-repo-layout">
        <!-- Sidebar cartelle -->
        <div class="commessa-folder-list">
            <div style="font-size: 0.75em; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6c757d; margin-bottom: 12px; padding: 0 12px;">
                Cartelle
            </div>
            <?php foreach ($cartelle as $cartella): ?>
                <div class="commessa-folder-item <?= $cartella['attiva'] ? 'active' : '' ?>">
                    <img src="/assets/icons/<?= htmlspecialchars($cartella['icona']) ?>"
                         alt=""
                         class="commessa-folder-icon">
                    <span><?= htmlspecialchars($cartella['nome']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Lista file -->
        <div class="commessa-file-list">
            <div style="margin-bottom: 16px; font-size: 0.85em; color: #6c757d;">
                <strong><?= count($files) ?> file</strong> nella cartella selezionata
            </div>

            <?php if (!empty($files)): ?>
                <?php foreach ($files as $file): ?>
                    <div class="commessa-file-item">
                        <div class="commessa-file-info">
                            <img src="/assets/icons/<?= getFileIcon($file['nome']) ?>"
                                 alt=""
                                 class="commessa-file-icon">
                            <div>
                                <div class="commessa-file-name">
                                    <?= htmlspecialchars($file['nome']) ?>
                                </div>
                                <span class="commessa-file-size">
                                    <?= htmlspecialchars($file['size']) ?>
                                </span>
                            </div>
                        </div>
                        <a href="#" class="commessa-cta small" data-tooltip="Scarica file">
                            <img src="/assets/icons/download.png" alt="" class="commessa-cta-icon">
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="commessa-empty" style="padding: 32px;">
                    <h3>Cartella vuota</h3>
                    <p>Nessun file presente in questa cartella.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info footer -->
    <div class="commessa-alert info" style="margin-top: 20px;">
        <svg class="commessa-alert-icon" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
        </svg>
        <div class="commessa-alert-content">
            <strong>TODO: Integrazione Document Manager</strong>
            Questa vista mostra dati placeholder. L'integrazione con il sistema Document Manager / Nextcloud è in fase di sviluppo.
        </div>
    </div>
</div>
