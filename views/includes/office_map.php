<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include('page-errors/404.php');
    exit;
}

if (!checkPermissionOrWarn('view_mappa_admin')) return;
?>

<link rel="stylesheet" href="/assets/css/office_map.css">

<div class="main-container">
<?php renderPageTitle("Map Manager", "#FF6F00"); ?>
    <div class="plan-container with-sidebar">
        <!-- COLONNA MAPPA -->
        <div class="map-wrapper">
            <div id="map-container">
                <div id="map-inner-wrapper">
                    <img id="plan-image" src="/assets/plan/Planimetrie Ufficio Padova-PT.png" alt="Planimetria Ufficio">
                    <div id="personnel-container"></div>
                </div>
            </div>
        </div>

        <!-- SIDEBAR CONTATTI -->
        <div id="contacts-sidebar" class="contacts-sidebar">
            <!-- TOOLS -->
            <div class="map-tools">
                <button id="tool-select" class="tool-button" data-tooltip="Selezione Multipla">
                    <img src="/assets/icons/select_unlock.png" alt="Selezione" class="tool-icon" id="icon-tool-select">
                </button>
                <button id="tool-lock-areas" class="tool-button" data-tooltip="Blocca Aree">
                    <img src="/assets/icons/area_unlock.png" alt="Blocca Aree" class="tool-icon" id="icon-tool-areas">
                </button>
                <button id="tool-lock-contacts" class="tool-button" data-tooltip="Blocca Contatti">
                    <img src="/assets/icons/icon_unlock.png" alt="Blocca Contatti" class="tool-icon" id="icon-tool-contacts">
                </button>
            </div>

            <div class="contacts-sidebar-header">
                <div class="floor-switcher">
                    <button class="floor-tab" data-floor="Plan_PT">PT</button>
                    <button class="floor-tab" data-floor="Plan_P3">P3</button>
                </div>
                <input type="text" id="contact-search" placeholder="Cerca contatto..." />
                <button id="show-all-btn">Mostra tutti</button>
            </div>

            <div id="contact-list" class="contact-list"></div>
        </div>
    </div>
</div>
