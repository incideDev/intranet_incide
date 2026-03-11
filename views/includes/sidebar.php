<?php
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    header('HTTP/1.0 403 Forbidden');
    exit('Accesso diretto non consentito.');
}

// Determina la sezione corrente
$currentSection = $_GET['section'] ?? null;

$menuData = getStaticMenu();

// Se la sezione non ?? presente nel menu (es. l???utente non ha permessi), fallback su archivio
if (!isset($menuData[$currentSection]) || empty($menuData[$currentSection]['menus'])) {
    $sectionMenu = [];
} else {
    $sectionMenu = $menuData[$currentSection]['menus'];
}


?>
<div class="fixed-sidebar hairline">
    <ul id="sidebar-menu" class="menu" data-current-section="<?= htmlspecialchars($currentSection, ENT_QUOTES, 'UTF-8'); ?>">
        <p class="loading-message">Caricamento in corso...</p>
    </ul>
</div>

<script>
    window.sidebarMenuData = <?= json_encode([
        'success' => true,
        'menus' => $sectionMenu
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/sidebar.js"></script>

