<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php"); die();
}
?>
<div class="gi-section">
    <h2 class="gi-section-title">Comunicazioni in Home</h2>
    <button id="aggiungiComunicazioneBtn" class="gi-btn">+ Nuova comunicazione</button>
    <div id="gi-messaggi-list">
        <!-- Qui saranno renderizzate le comunicazioni via JS -->
    </div>
</div>

<!-- Modale aggiunta comunicazione -->
<div class="gi-modal" id="gi-modal-comunicazione" style="display:none;">
    <div class="gi-modal-content">
        <span class="gi-modal-close" id="gi-modal-close">&times;</span>
        <h3>Aggiungi nuova comunicazione</h3>
        <form id="gi-form-comunicazione">
            <label for="gi-titolo">Titolo</label>
            <input type="text" id="gi-titolo" name="titolo" required>
            <label for="gi-testo">Messaggio</label>
            <textarea id="gi-testo" name="testo" required></textarea>
            <label for="gi-data-inizio">Visibile dal</label>
            <input type="date" id="gi-data-inizio" name="data_inizio">
            <label for="gi-data-fine">Visibile fino al</label>
            <input type="date" id="gi-data-fine" name="data_fine">
            <div style="margin-top: 20px;">
                <button type="submit" class="gi-btn gi-btn-save">Salva</button>
                <button type="button" class="gi-btn gi-btn-cancel" id="gi-btn-cancel">Annulla</button>
            </div>
        </form>
    </div>
</div>
