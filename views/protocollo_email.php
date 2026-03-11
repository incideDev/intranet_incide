<?php
if (!defined('AccessoFileInterni')) define('AccessoFileInterni', true);

if ($database->LockedTime() > 0) {
    header("Location: /systemlock");
    exit;
}
if ($Session->logged_in !== true) {
    header("Location: /index");
    exit;
}

if (!checkPermissionOrWarn('view_protocollo_email')) return;

use Services\ProtocolloEmailService;

// Calcola visibilità blocchi usando permessi avanzati (single source of truth)
$visibilita = getProtocolloEmailVisibility();
$puo_generale = $visibilita['generale'];
$puo_singole  = $visibilita['commesse'];
$solo_proprie = false; // Mantenuto per retrocompatibilità, non più usato da visibilita_sezioni
?>

<?php if (isset($_SESSION['CSRFtoken']) && $_SESSION['CSRFtoken'] !== ''): ?>
    <meta name="token-csrf" content="<?= $_SESSION['CSRFtoken'] ?>">
<?php endif; ?>

<div class="main-container">
<?php renderPageTitle('Protocollo Comunicazioni', "#C0392B");?>

    
<div class="top-container" id="protocollo-form">
    <input type="hidden" id="ccn" name="ccn" value="archivio_mail@incide.it">
    <input type="hidden" id="protocol-editing-id" name="protocol-editing-id" value="">
        <div class="row half-width" style="gap: 20px; margin-bottom: 10px;">
            <div style="flex: 1;">
                <label for="nuova-tipologia" class="text-label label-above" style="margin-bottom: 4px;">Tipologia:</label>
                <select id="nuova-tipologia" name="tipologia" class="select-box" onchange="handleTipologiaChange()" style="width:100%;">
                    <option value="">Seleziona tipologia</option>
                    <option value="email">Email</option>
                    <option value="lettera">Lettera</option>
                </select>
            </div>

            <div style="flex: 1; display:none;" id="modello-lettera-wrapper">
                <label for="modello-lettera" class="text-label label-above" style="margin-bottom: 4px;">Modello Lettera:</label>
                <select id="modello-lettera" name="modello_lettera" class="select-box" style="width:100%;" disabled>
                    <option value="">Seleziona modello</option>
                    <option value="ModQG02_2025_ITA">ModQG02_2025_ITA (Italiano)</option>
                    <option value="ModQG02_2025_ENG">ModQG02_2025_ENG (English)</option>
                    <option value="ModQG02_2025_FR">ModQG02_2025_FR (Français)</option>
                </select>
            </div>
        </div>

        <div class="row half-width" style="gap: 20px; margin-bottom: 10px;">
            <div style="flex: 1;">
                <label for="area" class="text-label label-above" style="margin-bottom: 4px;">area:</label>
                <select id="area" name="area" class="select-box" style="width:100%;">
                    <option value="" selected>Seleziona area</option>
                    <?php if ($puo_singole): ?>
                        <option value="commessa">Commessa</option>
                    <?php endif; ?>
                    <?php if ($puo_generale): ?>
                        <option value="generale">Generale</option>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <div class="row two-columns" style="gap: 20px; margin-bottom: 10px;">
            <div style="flex:2; position: relative;">
                <label for="custom-project-select" class="text-label label-above">Codice / Descrizione:</label>
                <div id="custom-project-select" class="custom-select-box custom-select-box-form" tabindex="0">
                    <span class="custom-select-placeholder">Seleziona un codice</span>
                    <div class="custom-select-dropdown" style="display:none;"></div>
                </div>
                <input type="hidden" id="project" name="commessa" value="">
            </div>
        </div>

        <!-- Oggetto -->
        <div class="row full-width protocollo" style="align-items: center; margin-bottom: 10px;">
            <label for="subject" class="text-label label-inline" style="min-width: 120px;">Oggetto:</label>
            <input type="text" id="subject" name="oggetto" class="select-box" style="flex:1;" placeholder="Oggetto della comunicazione">
        </div>

        <!-- Protocollo -->
        <div class="row full-width protocollo" style="align-items: center; margin-bottom: 10px;">
            <label for="final-code" class="text-label label-inline" style="min-width: 120px;">Protocollo:</label>
            <input type="text" id="final-code" class="select-box readonly" style="flex:1;" placeholder="Codice protocollo generato">
        </div>

        <!-- Tabella DESTINATARI / CC -->
        <div style="margin-bottom: 16px;">
            <table id="tabella-destinatari" class="custom-recipients-table" style="width:100%; min-width:560px;">
                <thead>
                    <tr>
                        <th style="width:28%;">Destinatario</th>
                        <th style="width:28%;">Nome referente</th>
                        <th style="width:28%;">Contatto</th>
                        <th style="width:16%;">Tipo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for($i=0; $i<6; $i++): ?>
                        <tr>
                            <td>
                                <div class="custom-select-box destinatario-select-box" tabindex="0" style="width:100%;">
                                    <span class="custom-select-placeholder">Seleziona destinatario</span>
                                    <input type="hidden" value="">
                                </div>
                            </td>
                            <td>
                                <div class="custom-select-box referente-select-box" tabindex="0" style="width:100%;">
                                    <span class="custom-select-placeholder">Seleziona referente</span>
                                    <input type="hidden" value="">
                                </div>
                            </td>
                            <td>
                                <div class="custom-select-box contatto-select-box" tabindex="0" style="width:100%;">
                                    <span class="custom-select-placeholder">Seleziona contatto</span>
                                    <input type="hidden" value="">
                                </div>
                            </td>
                            <td>
                            <div class="custom-select-box type-select-box" tabindex="0" style="width:100%;">
                                <span class="custom-select-placeholder">Seleziona tipo</span>
                                <input type="hidden" value="">
                                <div class="custom-select-dropdown" style="display:none;">
                                <div class="custom-select-option" data-value="to">TO</div>
                                <div class="custom-select-option" data-value="cc">CC</div>
                                </div>
                            </div>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
            <div style="font-size:12px;color:#888;padding-top:4px;">
                Massimo 6 righe. Compila solo quelle che servono.
            </div>
        </div>

        <!-- Pulsanti -->
        <div class="protocollo-btn-bar">
            <button type="button" id="btn-genera" class="button" onclick="genera()">Genera Protocollo</button>
            <button type="button" id="btn-generaEApri" class="button" onclick="generaEApri()">Genera e Apri</button>
            <button type="button" id="btn-salva-protocollo" class="button" style="display:none;" onclick="salvaProtocolloModificato()">Salva Modifiche</button>
            <button type="button" id="btn-annulla-protocollo" class="button" style="display:none;" onclick="annullaProtocollo()">Annulla</button>
        </div>
    </div>

    <!-- ARCHIVIO TABELLARE -->
    <div class="archive-container" id="archive-container">
        <div style="display: flex; align-items: baseline; justify-content: space-between;">
            <h3 style="margin-bottom: 12px; font-weight:bold">elenco comunicazioni in uscita</h3>
            <?php if ($puo_generale && $puo_singole): ?>
            <div class="archive-filters" style="display:flex; align-items:center;">
                <div id="arch-segment" class="tab" data-tooltip="filtra elenco">
                    <button type="button" class="active" data-mode="tutte" data-tooltip="mostra generale e commesse">tutte</button>
                    <button type="button" data-mode="generale" data-tooltip="solo area generale">generale</button>
                    <button type="button" data-mode="commessa" data-tooltip="solo aree commesse">commesse</button>
                </div>
                <input type="hidden" id="arch-mode" value="tutte">
            </div>
            <?php endif; ?>
        </div>
        <hr style="margin-bottom: 16px;">

        <div class="table-container">
            <table id="protocolTable" class="table table-filterable">
                <thead>
                    <tr>
                        <th>Azioni</th>
                        <th>Protocollo</th>
                        <th>Commessa</th>
                        <th>Inviato Da</th>
                        <th>Ditta</th>
                        <th>Referente Email</th>
                        <th>Nome Referente</th>
                        <th>Data</th>
                        <th>Oggetto</th>
                        <th>Tipologia</th>
                    </tr>
                </thead>
                <tbody id="protocolTable_body">
                    <!-- Popolamento dinamico da JS -->
                </tbody>
            </table>
        </div>
    </div>

    <div id="modal-nuovo-destinatario" class="modal modal-small">
        <div class="modal-content">
            <span class="close-modal" onclick="window.toggleModal('modal-nuovo-destinatario', 'close')">&times;</span>
            <h3>Nuovo destinatario (azienda)</h3>
            <form id="nuovo-destinatario-form" autocomplete="off">
                <div class="modal-form-grid">
                    <div>
                        <label for="dest-ragione">Ragione sociale*:</label>
                        <input type="text" id="dest-ragione" name="ragionesociale" required>
                    </div>
                    <div>
                        <label for="dest-piva">Partita IVA:</label>
                        <input type="text" id="dest-piva" name="partitaiva">
                    </div>
                    <div>
                        <label for="dest-citta">Città:</label>
                        <input type="text" id="dest-citta" name="citta">
                    </div>
                    <div>
                        <label for="dest-email">Email:</label>
                        <input type="email" id="dest-email" name="email">
                    </div>
                    <div>
                        <label for="dest-tel">Telefono:</label>
                        <input type="text" id="dest-tel" name="telefono">
                    </div>
                </div>
                <div class="modal-btns">
                    <button type="button" id="btn-cancella-nuovo-destinatario" class="button">Annulla</button>
                    <button type="submit" id="btn-salva-nuovo-destinatario" class="button">Salva</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modal-nuovo-contatto" class="modal modal-small">
        <div class="modal-content">
            <span class="close-modal" onclick="window.toggleModal('modal-nuovo-contatto', 'close')">&times;</span>
            <h3>Nuovo contatto</h3>
            <form id="nuovo-contatto-form" autocomplete="off">
                <div class="modal-form-grid">
                    <div>
                        <label>Email:</label>
                        <input type="email" id="contatto-email" name="email" required>
                    </div>
                    <div>
                        <label>Ruolo:</label>
                        <input type="text" id="contatto-ruolo" name="ruolo">
                    </div>
                    <div>
                        <label>Cognome:</label>
                        <input type="text" id="contatto-cognome" name="cognome" required>
                    </div>
                    <div>
                        <label>Nome:</label>
                        <input type="text" id="contatto-nome" name="nome" required>
                    </div>
                    <div>
                        <label>Titolo:</label>
                        <input type="text" id="contatto-titolo" name="titolo">
                    </div>
                    <div>
                        <label>Telefono:</label>
                        <input type="text" id="contatto-telefono" name="telefono">
                    </div>
                    <div>
                        <label>Cellulare:</label>
                        <input type="text" id="contatto-cellulare" name="cellulare">
                    </div>
                </div>
                <div class="modal-btns">
                    <button type="button" id="btn-cancella-nuovo-contatto" class="button">Annulla</button>
                    <button type="submit" id="btn-salva-nuovo-contatto" class="button">Salva</button>
                </div>
            </form>
        </div>
    </div>
<script>
window.CURRENT_USER_ID = <?= intval($_SESSION['user_id'] ?? 0) ?>;
window.CURRENT_USERNAME = <?= json_encode($_SESSION['username'] ?? '') ?>;
window.CURRENT_USER_IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;
window.CURRENT_USER_ROLE_ID = <?= intval($_SESSION['role_id'] ?? 0) ?>;

window.VIS_SEZ = {
    generale: <?= $puo_generale ? 'true' : 'false' ?>,
    singole_commesse: <?= $puo_singole ? 'true' : 'false' ?>,
    solo_proprie_commesse: <?= $solo_proprie ? 'true' : 'false' ?>
};
</script>

</div>

<script type="text/javascript">
    var inviato_da = '<?php echo $_SESSION['username'] ?? 'Sconosciuto'; ?>';
</script>
