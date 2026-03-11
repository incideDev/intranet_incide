<?php
if (!defined('HostDbDataConnector')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Accesso diretto non consentito.');
}

if (!checkPermissionOrWarn('view_gestione_changelog')) return;
?>

<div class="main-container page-changelog-admin">
    <?php renderPageTitle("Gestione Changelog", "#2C3E50"); ?>

    <div class="changelog-admin-container">

        <!-- Form inserimento -->
        <div class="changelog-box new-update-box">
            <h3>Nuovo Aggiornamento</h3>
            <form id="changelogForm" class="form-box">
                <div class="form-row">
                    <label for="titolo">Titolo:</label>
                    <input type="text" name="titolo" id="titolo" required>
                </div>

                <div class="form-row">
                    <label for="descrizione">Descrizione:</label>
                    <textarea name="descrizione" id="descrizione" rows="4" required></textarea>
                </div>

                <div class="form-row">
                    <label for="data">Data:</label>
                    <input type="date" name="data" id="data" value="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-row">
                    <label for="sezione">Sezione:</label>
                    <input type="text" name="sezione" id="sezione" placeholder="(opzionale)">
                </div>

                <div class="form-row">
                    <label for="url">URL pagina (opzionale):</label>
                    <input type="text" name="url" id="url" placeholder="es: index.php?section=moduli&page=view_modulo">
                </div>

                <div class="form-row version-row">
                    <div style="display: flex; flex-direction: column; align-items: flex-start;">
                        <label class="checkbox-label" for="nuova_major" style="margin-bottom: 4px;">Nuova major?</label>
                        <input type="checkbox" name="nuova_major" id="nuova_major" style="margin:0;">
                    </div>
                    <span class="version-desc" style="margin-left: 12px;">
                        Spunta solo se vuoi iniziare una nuova versione principale (es: da 0.x a 1.0)
                    </span>
                </div>

                <div class="form-row next-version-row" style="align-items:center; margin-top: 8px;">
                    <label style="margin-right: 8px;">Prossima versione:</label>
                    <span id="nextVersionLabel" style="font-weight: bold; margin-right: 8px;">Auto</span>
                    <button id="editVersionBtn" type="button" class="edit-version-btn" data-tooltip="Imposta manualmente la versione"
                        style="background: none; border: none; padding: 0; margin: 0 2px; cursor: pointer;">
                        <img src="assets/icons/edit.png" alt="Modifica versione" style="width:18px;height:18px;vertical-align:middle;opacity:0.75;">
                    </button>
                    <input type="text" id="customVersionInput" name="versione" class="custom-version-input"
                        style="display:none; width:85px; margin-left:6px;" placeholder="es: 0.2.1">
                </div>

                <div class="form-actions">
                    <button type="submit" class="button">Salva Aggiornamento</button>
                </div>
            </form>
        </div>

        <!-- Risultati -->
        <div class="changelog-box">
            <h3>Aggiornamenti Recenti</h3>
            <div id="changelogResults" class="changelog-list"></div>
        </div>

    </div>
</div>
