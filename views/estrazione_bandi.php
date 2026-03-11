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
    <?php renderPageTitle('Estrazione Bandi', '#3498DB'); ?>

    <!-- VISTA TABELLA -->
    <div id="table-view" class="">
        <div class="gare-table-wrapper">
            <table class="table table-filterable gare-table" id="gare-table" data-remote="0" data-page-size="10">
                <thead>
                    <tr>
                        <th class="gara-number">N° Gara</th>
                        <th>Bando</th>
                        <th class="gara-amount">Importo Lavori</th>
                        <th class="gara-amount">Importo Corrispettivo</th>
                        <th>Ente</th>
                        <th>Luogo</th>
                        <th>Data Uscita</th>
                        <th>Data Scadenza</th>
                        <th>Partecipazione</th>
                        <th>Avanzamento</th>
                        <th>Aggiornato</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <div class="gare-empty-message hidden" id="gare-empty">Non è presente alcuna estrazione al momento.</div>
        </div>
    </div>
</div>

<div id="modalAddGaraOverlay" class="gare-modal-overlay"></div>
<div id="modalAddGara" class="gare-modal">
    <div class="gare-modal-content">
        <button type="button" class="gare-modal-close" id="gare-modal-close" aria-label="Chiudi"></button>

        <div class="gare-modal-header">
            <div class="gare-modal-illustration" aria-hidden="true">
                <svg class="gare-modal-hero-icon" viewBox="0 0 48 48" role="presentation" focusable="false">
                    <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M18 10h12l6 6v20a4 4 0 0 1-4 4H18a4 4 0 0 1-4-4V14a4 4 0 0 1 4-4z" />
                        <path d="M30 10v6h6" />
                        <path d="M20 27h12" />
                        <path d="M23 32h6" />
                        <polyline points="24 21 27 24 24 27" />
                    </g>
                </svg>
            </div>
            <div class="gare-modal-copy">
                <h2>Nuova estrazione PDF</h2>
                <p>Carica i documenti di gara in formato PDF per avviare l'estrazione automatica dei dati.</p>
            </div>
        </div>

        <div class="gare-modal-body">
            <form id="gareUploadForm" class="gare-upload-form" enctype="multipart/form-data">
                <div class="gare-upload-area" id="gareUploadArea">
                    <input type="file" id="gareUploadInput" name="file[]" accept="application/pdf" multiple hidden>
                    <div class="gare-upload-area-inner">
                        <div class="gare-upload-icon" aria-hidden="true">
                            <svg viewBox="0 0 32 32" role="presentation" focusable="false">
                                <g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M12 6h9l5 5v13a2 2 0 0 1-2 2h-12a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z" />
                                    <path d="M21 6v5h5" />
                                    <path d="M12 17h8" />
                                    <path d="M14 21h6" />
                                </g>
                            </svg>
                        </div>
                        <div class="gare-upload-text">
                            <strong>Trascina i PDF qui</strong>
                            <span>oppure <button type="button" id="gareUploadSelect" class="gare-upload-select">scegli
                                    dal tuo dispositivo</button></span>
                            <small class="gare-upload-subtext">Accettiamo file .pdf fino a 25 MB. Puoi selezionare più
                                documenti insieme.</small>
                        </div>
                    </div>
                </div>

                <div class="gare-upload-helper">
                    <div class="gare-upload-pill">
                        <span class="pill-bullet" aria-hidden="true"></span>
                        Prima di procedere verifica che il PDF sia leggibile per ottenere risultati accurati.
                    </div>
                    <div class="gare-upload-pill neutral">
                        <span class="pill-bullet" aria-hidden="true"></span>
                        I caricamenti restano visibili esclusivamente agli utenti autorizzati.
                    </div>
                </div>

                <div class="gare-selected-files hidden" id="gareSelectedFiles" aria-live="polite">
                    <div class="gare-selected-files-header">
                        <h3>File selezionati</h3>
                        <span id="gareSelectedCounter" class="gare-selected-counter"></span>
                    </div>
                    <ul id="gareSelectedList"></ul>
                </div>

                <div class="gare-form-actions">
                    <button type="button" id="gareUploadCancel" class="gare-button secondary">Annulla</button>
                    <button type="submit" id="gareUploadSubmit" class="gare-button primary" disabled>Carica</button>
                </div>
            </form>

            <div class="gare-upload-status hidden" id="gareUploadStatus" aria-live="polite">
                <h3>Stato caricamento</h3>
                <ul id="gareUploadStatusList"></ul>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/gare_list.js" defer></script>