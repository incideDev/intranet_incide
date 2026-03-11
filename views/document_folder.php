<?php
$documentiPath = __DIR__ . '/../Caricamento_Documenti/' . $page . '/';
$subfolder = $_GET['subfolder'] ?? null;

if ($subfolder) {
    $documentiPath .= $subfolder . '/';
}

if (!is_dir($documentiPath)) {
    die(json_encode(['error' => 'Cartella non trovata']));
}

$files = array_diff(scandir($documentiPath), ['.', '..']);
?>

<div class="main-container">
    <h1><?= htmlspecialchars($titolo_principale, ENT_QUOTES, 'UTF-8') ?><?= $subfolder ? ' - ' . htmlspecialchars($subfolder, ENT_QUOTES, 'UTF-8') : '' ?></h1>
    <div class="document-grid">
        <?php foreach ($files as $file): ?>
            <?php
            $filePath = $documentiPath . $file;
            $isFolder = is_dir($filePath);
            $icon = $isFolder ? 'assets/icons/folder.png' : 'assets/icons/file.png';
            ?>
            <div class="document-card" onclick="window.location.href='<?= $isFolder ? 'index.php?section=archivio&page=' . urlencode($page) . '&subfolder=' . urlencode($file) : $filePath ?>'">
                <img src="<?= $icon ?>" alt="Icona">
                <p><?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</div>
