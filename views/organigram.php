<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

if (!checkPermissionOrWarn('view_contatti')) return;
?>

<div class="main-container">
  <h1>Organigramma</h1>
  <div id="orgChartDiv"></div>

  <!-- Inclusione degli script -->
  <script src="assets/js/libraries/d3.min.js"></script>
  <script src="assets/js/organigram/contextMenu.js"></script>
  <script src="assets/js/organigram.js"></script>
</div>
