<?php
if (!defined('hostdbdataconnector'))
    define('hostdbdataconnector', true);
if (!defined('accessofileinterni'))
    define('accessofileinterni', true);

if ($Session->logged_in !== true) {
    header("Location: /index");
    exit;
}

if (!checkPermissionOrWarn('view_gare'))
    return;
?>
<link rel="stylesheet" href="/assets/css/gare.css">

<div class="main-container page-gare">
    <?php renderPageTitle('Archivio Gare', '#3498DB'); ?>

    <!-- VISTA TABELLA -->
    <div id="table-view" class="">
        <div class="gare-table-wrapper">
            <table class="table table-filterable gare-table" id="gare-table">
                <thead>
                    <tr>
                        <th class="gara-number">N° Gara</th>
                        <th>Ente</th>
                        <th>Titolo</th>
                        <th>Settore</th>
                        <th>Tipologia</th>
                        <th>Luogo</th>
                        <th>Data Uscita</th>
                        <th>Data Scadenza</th>
                        <th>Stato</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <div class="gare-empty-message hidden" id="gare-empty">Non è presente alcuna gara archiviata al momento.
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/gare_list.js" defer></script>