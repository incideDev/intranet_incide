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

    <?php
    $section = $_GET['section'] ?? 'dashboard'; // Imposta dashboard come default

    if ($section === 'dashboard') : ?>
        <!-- Mostra la dashboard solo nella sezione "dashboard" -->
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
        <script src="assets/js/hr_dashboard.js"></script>
    <?php else : ?>
        <!-- Mostra il contenuto della sottosezione corrente -->
        <div class="hr-content">
            <?php
            switch ($section) {
                case 'job_profile':
                    include 'includes/hr_area/job_gestione_profilo.php';
                    break;
                case 'open_search':
                    include 'includes/hr_area/open_search.php';
                    break;
                case 'candidate_selection':
                    include 'includes/hr_area/candidate_selection/candidate_selection.php';
                    break;
                default:
                    echo "<p>Seleziona una sezione valida dalla sidebar.</p>";
                    break;
            }
            ?>
        </div>
    <?php endif; ?>
</div>
<div id="addCandidateModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeCreateCandidateModal()">&times;</span>
        <h2>Aggiungi Candidato</h2>
        <!-- Contenuto dinamico caricato tramite JavaScript -->
    </div>
</div>


<script src="assets/js/hr_dashboard.js"></script>
