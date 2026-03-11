<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

if (!checkPermissionOrWarn('view_contatti')) return;
?>

<div class="main-container">
    <h1>Area HR</h1>

    <!-- Sezione dashboard -->
    <div class="hr-dashboard">
        <div class="dashboard-column">
            <h3>Offerte</h3>
            <div class="dashboard-counter" id="jobProfilesCount">
                <span>0</span>
            </div>
            <p>Totale profili di lavoro definiti</p>
        </div>

        <div class="dashboard-column">
            <h3>Campagne</h3>
            <div class="dashboard-counter" id="openSearchCount">
                <span>0</span>
            </div>
            <p>Totale campagne attive</p>
        </div>

        <div class="dashboard-column">
            <h3>Candidati</h3>
            <div class="dashboard-counter" id="candidatesCount">
                <span>0</span>
            </div>
            <p>Totale candidature attive</p>
        </div>
    </div>

    <!-- Script della dashboard -->
    <script src="assets/js/hr_dashboard.js"></script>
</div>
