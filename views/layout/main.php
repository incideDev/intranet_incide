<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intranet Aziendale - <?php echo htmlspecialchars($titolo_principale ?? ''); ?></title>
    <?php echo (isset($_SESSION['CSRFtoken']) && $_SESSION['CSRFtoken'] !== '' ? '<meta name="token-csrf" content="' . $_SESSION['CSRFtoken'] . '">' : ''); ?>
    <link rel="preload" href="/assets/css/styles.css?v=<?= time() ?>" as="style">
    <link rel="stylesheet" href="/assets/css/styles.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/assets/css/buttons.css">
    <link rel="stylesheet" href="/assets/css/badges-alerts.css">
    <link rel="stylesheet" href="/assets/css/cards-panels.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/assets/css/modals-dialogs.css">
    <link rel="stylesheet" href="/assets/css/nav-tabs.css">
    <link rel="stylesheet" href="/assets/css/tables.css">
    <link rel="stylesheet" href="/assets/css/forms-layout.css">
    <link rel="stylesheet" href="/assets/css/utilities.css">
    <link rel="stylesheet" href="/assets/css/legacy.css">
    <link rel="stylesheet" href="/assets/css/adaptive_overrides.css">
    <link rel="stylesheet" href="/assets/css/bottom-bar.css">
    <?php if (($Page ?? '') === 'mom'): ?>
        <link rel="stylesheet" href="/assets/css/mom.css">
    <?php endif; ?>
    <?php if (($Page ?? '') === 'cv_manager'): ?>
        <link rel="stylesheet" href="/assets/css/cv_manager.css">
    <?php endif; ?>
    <?php if (($Page ?? '') === 'elenco_documenti' || (($Page ?? '') === 'commessa' && ($view ?? '') === 'elaborati')): ?>
        <link rel="stylesheet" href="/assets/css/elenco_documenti.css?v=<?= time() ?>">
    <?php endif; ?>
    <link rel="icon" href="/assets/favicon/incide.ico" type="image/x-icon">
</head>

<body class="<?php echo ($Page ?? '') === 'home' ? 'home-page' : ''; ?>">

    <!-- Navbar -->
    <?php
    if (isset($_SESSION['user_id']) && file_exists(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/views/includes/navbar.php')) {
        include(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/views/includes/navbar.php');
    }
    ?>

    <!-- Layout principale -->
    <?php
    $isHomeOrLogin = !isset($_SESSION['user_id']) || ($Page ?? '') === 'home' || ($Page ?? '') === 'login';
    ?>

    <?php if (!$isHomeOrLogin): ?>
        <!-- Layout loggato -->
        <?php
        if (file_exists(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/views/includes/sidebar.php')) {
            include(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/views/includes/sidebar.php');
        }
        if (file_exists(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/views/includes/function-bar.php')) {
            include(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/views/includes/function-bar.php');
        }
        ?>
        <div class="dynamic-content">
            <?php
            if (!empty($content)) {
                echo $content;
            } else {
                echo "<!-- Nessun contenuto definito -->";
            }
            ?>
        </div>

    <?php else: ?>
        <!-- Layout per home o login -->
        <div class="home-background"></div>
        <?php if (($Page ?? '') === 'home'): ?>
            <?php include(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . '/views/home.php'); ?>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Definizione sicura di CURRENT_USER -->
    <?php if (isset($_SESSION['user'])): ?>
        <?php
        $safeUser = $_SESSION['user'];
        // Forza sempre il role_id per sicurezza
        $safeUser['role_id'] = $_SESSION['role_id'] ?? ($safeUser['role_id'] ?? null);
        // Aggiungi i permessi dalla sessione
        $safeUser['permissions'] = $_SESSION['role_permissions'] ?? [];
        $safeUser['role_ids'] = $_SESSION['role_ids'] ?? [];
        $safeUser['is_admin'] = isAdmin();
        foreach (['password', 'auth_token', 'temp_key'] as $campoDaRimuovere) {
            unset($safeUser[$campoDaRimuovere]);
        }
        ?>
        <script>
            window.CURRENT_USER = <?= json_encode($safeUser, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
            window.CURRENT_USER_PERMISSIONS = <?= json_encode($_SESSION['role_permissions'] ?? [], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
            // Funzione globale per verificare i permessi
            window.userHasPermission = window.userHasPermission || function (perm) {
                if (!window.CURRENT_USER) return false;
                // Admin bypass: controlla is_admin o role_ids include 1
                if (window.CURRENT_USER.is_admin === true) return true;
                const roleIds = window.CURRENT_USER.role_ids || [];
                if (Array.isArray(roleIds) && roleIds.includes(1)) return true;
                // Fallback legacy: role_id === 1
                const roleId = window.CURRENT_USER.role_id || window.CURRENT_USER.roleId;
                if (roleId === 1 || roleId === '1') return true;
                const perms = window.CURRENT_USER.permissions || window.CURRENT_USER_PERMISSIONS || [];
                return perms.includes(perm) || perms.includes('*');
            };
        </script>
    <?php endif; ?>

    <!-- Libreria AJAX globale -->
    <script src="/assets/js/modules/ajax.js"></script>

    <!-- Modules base e utilities -->
    <script src="/assets/js/modules/main_core.js"></script>

    <!-- Autocomplete manager globale (dropdown, aziende, contatti) -->
    <script src="/assets/js/modules/autocomplete_manager.js"></script>

    <!-- Resize colonne tabelle -->
    <script src="/assets/js/modules/table_resize.js"></script>

    <!-- Components & Details Modules -->
    <script src="/assets/js/components/side_panel.js?v=<?= time() ?>"></script>
    <script src="/assets/js/components/bottom_bar.js?v=<?= time() ?>"></script>
    <script src="/assets/js/modules/task_details.js?v=<?= time() ?>"></script>
    <script src="/assets/js/mom_details.js?v=<?= time() ?>"></script>
    <script src="/assets/js/modules/mom_item_details.js?v=<?= time() ?>"></script>

    <!-- Calendar View (Global) -->
    <?php if (in_array($Page ?? '', ['mom', 'elenco_gare', 'gestione_segnalazioni', 'segnalazioni_dashboard'])): ?>
        <script src="/assets/js/modules/calendar_view.js?v=<?= time() ?>"></script>
    <?php endif; ?>

    <!-- Scripts specifici della pagina -->
    <?php
    if (isset($Page) && $Page !== '') {
        $pageScriptPath = "/assets/js/" . $Page . ".js";
        $localPath = substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/views')) . $pageScriptPath;
        if (file_exists($localPath)) {
            echo '<script src="' . $pageScriptPath . '?v=' . time() . '"></script>';
        }
    }
    // Quando il modulo elenco_documenti è embedded nella tab elaborati di commessa,
    // il JS non viene caricato dall'auto-load (cerca commessa.js), quindi lo carichiamo esplicitamente.
    if (($Page ?? '') === 'commessa' && ($view ?? '') === 'elaborati') {
        echo '<script src="/assets/js/elenco_documenti.js?v=' . time() . '"></script>';
    }
    ?>

    <!-- Da togliere prima o poi-->
    <?php
    if (ob_get_length() && preg_match_all('/<\/body>|<\/html>/', ob_get_contents(), $matches) > 2) {
        error_log(" Doppia chiusura body/html rilevata nel layout.");
    }
    ?>
    <!---------------------------->

</body>

</html>