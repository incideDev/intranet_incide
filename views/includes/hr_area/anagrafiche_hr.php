<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

if (!checkPermissionOrWarn('view_contatti')) return;
?>

<div class="main-container">
    <div class="anagrafiche-container">
        <h2>Anagrafiche HR</h2>

        <!-- Tab per Aree e Competenze -->
        <div class="tab">
            <button class="tablinks active" onclick="openTab(event, 'Aree')">Aree</button>
            <button class="tablinks" onclick="openTab(event, 'Competenze')">Competenze</button>
        </div>

        <div id="Aree" class="tabcontent">
        <button class="btn-add" onclick="openAddAreaModal()">+ Aggiungi Area</button>

        <table id="aree-table" class="hr-table">
            <thead>
                <tr>
                    <th class="azioni-colonna">Azioni</th>
                    <th>Nome Area</th>
                </tr>
            </thead>
            <tbody>
                <!-- Dati caricati dinamicamente -->
            </tbody>
        </table>
    </div>

        <div id="Competenze" class="tabcontent">
            <button class="btn-add" onclick="openAddCompetenzaModal()">+ Aggiungi Competenza</button>

            <div class="tab-navigation">
                <!-- Freccia sinistra per scorrere -->
                <button class="tab-arrow left-arrow" onclick="scrollTabs('left')">&lt;</button>

                <!-- Contenitore delle schede -->
                <ul id="tab-links-container" class="tab-links"></ul>

                <!-- Freccia destra per scorrere -->
                <button class="tab-arrow right-arrow" onclick="scrollTabs('right')">&gt;</button>
            </div>

            <!-- Contenitori delle schede -->
            <div id="tab-content-container" class="tab-content-container"></div>
        </div>
    </div>

    <!-- Modal per aggiungere una nuova Area -->
    <div id="addAreaModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span onclick="closeAddAreaModal()" class="close">&times;</span>
            <h2>Aggiungi Area</h2>
            <form onsubmit="addArea(event)">
                <label for="areaName">Nome:</label>
                <input type="text" id="areaName" required>
                <button class="btn-submit" type="submit">Salva</button>
            </form>
        </div>
    </div>

    <!-- Modal per aggiungere una nuova competenza -->
    <div id="addCompetenzaModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span onclick="closeAddCompetenzaModal()" class="close">&times;</span>
            <h2>Aggiungi Competenza</h2>
            <form id="addCompetenzaForm" onsubmit="addCompetenza(event)">
                <label for="competenzaName">Nome Competenza:</label>
                <input type="text" id="competenzaName" required>

                <label for="competenzaDescrizione">Descrizione:</label>
                <textarea id="competenzaDescrizione" rows="3"></textarea>

                <label for="competenzaArea">Area:</label>
                <select id="competenzaArea" required>
                    <!-- Le opzioni saranno generate dinamicamente -->
                </select>

                <button class="btn-submit" type="submit">Salva</button>
            </form>
        </div>
    </div>

</div>

<script src="assets/js/hr_anagrafiche.js"></script>
