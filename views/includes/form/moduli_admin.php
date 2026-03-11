<?php
if (!defined('HostDbDataConnector')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Accesso diretto non consentito.');
}

if (!checkPermissionOrWarn('view_moduli')) return;
?>

<div class="main-container page-moduli-admin">
    <?php renderPageTitle("Gestione Moduli", "#2C3E50"); ?>

    <!-- Placeholder per Dashboard Avanzata -->
    <div class="dashboard-section">
        <h2> Statistiche sui Moduli</h2>
        <p style="color: #666;">(In arrivo: tempo medio di completamento, moduli inattivi, ecc.)</p>
    </div>

    <!-- Lista Moduli -->
    <div class="form-grid" id="admin-form-grid">
        <!-- Le card dei moduli verranno caricate via JS -->
    </div>

        <!-- Modale Creazione Form -->
        <?php include substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/views/includes/form/create_form.php'; ?>
    
        <!-- Script dedicato con percorso dinamico -->
        <?php
        $assetsPath = str_replace($_SERVER['DOCUMENT_ROOT'], '', substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views'))) . '/assets/js';
        ?>
        <script src="<?= $assetsPath ?>/formGenerator.js?v=<?= time() ?>" defer></script>
</div>
