<?php 
define('AccessoFileInterni', true);

use Services\NotificationService;

if (!$Session->logged_in) {
    header("Location: /");
    exit;
}
?>

<div class="main-container">
    <?php renderPageTitle("Centro Notifiche", "#2980B9"); ?>
    <div class="notifiche-filtri-bar" style="display:flex; gap:12px; align-items:center; margin-bottom:18px;">
        <input type="text" id="cerca-notifiche" placeholder="Cerca notifiche..." style="flex:2; min-width:220px;">
        <button class="button" onclick="filtraSoloNonLette()">Solo non lette</button>
        <button class="button" onclick="segnaTutteComeLette()">Segna tutte come lette</button>
        <button class="button" onclick="eliminaTutteNotifiche()">Elimina tutte</button>
    </div>

    <div id="notifiche-lista" class="notifiche-lista"></div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    caricaCentroNotifiche();
});
</script>
