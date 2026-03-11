<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include('page-errors/404.php');
    exit;
}

if (!checkPermissionOrWarn('view_mappa')) return;
?>

<link rel="stylesheet" href="/assets/css/office_map.css">

<div class="main-container">
    <?php renderPageTitle("Mappa dell'Ufficio", "#FF6F00"); ?>

    <div class="plan-container with-sidebar">
        <!-- COLONNA MAPPA -->
        <div class="map-wrapper">
            <div id="map-container">
                <div id="map-inner-wrapper">
                    <img id="plan-image" src="/assets/plan/Planimetrie Ufficio Padova-PT.png" alt="Planimetria Ufficio">
                    <div id="personnel-container" class="personnel-container"></div>
                </div>
            </div>
        </div>

        <!-- SIDEBAR -->
        <div id="contacts-sidebar" class="contacts-sidebar">
            <div class="contacts-sidebar-header">
                <div class="floor-switcher">
                    <button class="floor-tab" data-floor="Plan_PT">PT</button>
                    <button class="floor-tab" data-floor="Plan_P3">P3</button>
                </div>
            </div>

            <div id="contact-list" class="contact-list">
                <!-- Card dettagli contatto -->
                <div class="public-sidebar-card" id="sidebar-user-card" style="display: none;">
                    <div class="user-card-header">
                        <img src="/assets/images/default_profile.png" alt="Avatar" class="user-avatar" />
                        <div class="user-info">
                            <h3 id="user-fullname">Mario Rossi</h3>
                            <p id="user-role">Ruolo</p>
                        </div>
                    </div>
                    <div class="user-card-body">
                        <p><strong>Interno:</strong> <span id="user-interno">-</span></p>
                        <p><strong>Email:</strong> <span id="user-email">-</span></p>
                        <p><strong>Telefono:</strong> <span id="user-telefono">-</span></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
