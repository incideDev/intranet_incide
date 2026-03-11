<?php
if (!checkPermissionOrWarn('view_moduli'))
    return;

/* se 'form_name' è presente = MODIFICA; se manca = CREAZIONE */
$form_name_param = isset($_GET['form_name']) ? trim((string) $_GET['form_name']) : '';
if ($form_name_param !== '' && !preg_match('/^[\w\s\-àèéùòì]+$/ui', $form_name_param)) {
    die("<p style='color:red;'> nome pagina non valido.</p>");
}

/* info responsabile e dati form (solo se esiste già) */
$responsabile_info = null;
$form_meta_data = null;
if ($form_name_param !== '') {
    $stmt = $database->query(
        "select f.responsabile, p.Nominativo 
         from forms f 
         left join personale p on f.responsabile = p.user_id 
         where f.name = :name",
        [':name' => $form_name_param],
        __FILE__
    );
    if ($stmt && ($row = $stmt->fetch(PDO::FETCH_ASSOC)) && !empty($row['Nominativo'])) {
        $img = getProfileImage($row['Nominativo'], 'nominativo');
        $responsabile_info = ['nome' => $row['Nominativo'], 'img' => $img];
    }

    // Carica dati meta del form (se esistono campi di esempio o default)
    // Nota: questi sono solo valori di default per l'editor, non vengono salvati qui
    $form_meta_data = [
        'titolo' => '',
        'descrizione' => '',
        'deadline' => '',
        'priority' => 'Media'
    ];
}
?>
<link rel="stylesheet" href="assets/css/form.css">

<div class="main-container editor-wrapper">

    <!-- TOOLBAR LATERALE DESTRA (fissa) -->
    <div id="pe-right-toolbar" class="rtb" aria-label="Toolbar editor">
        <!-- Tabs verticali: Campi -->
        <div class="rtb-tabs">
            <!-- Tab: Wizard/Proprietà -->
            <button id="rtb-tab-wizard" class="rtb-tab" type="button" aria-label="Proprietà"
                data-tooltip="Proprietà pagina (wizard)">
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 5v4.59l3.3 3.3-1.4 1.42L11 12.41V7h2z"
                        fill="currentColor" />
                </svg>
            </button>
            <button id="rtb-tab-fields" class="rtb-tab is-active" type="button" data-tooltip="Campi">
                <img src="assets/icons/fields.png" alt="Campi"
                    onerror="this.outerHTML='<span class=&quot;rtb-tab-fallback&quot;>⌗</span>';" />
            </button>

            <!-- Tab: Azioni -->
            <button id="rtb-tab-response" class="rtb-tab" type="button" aria-label="Azioni"
                data-tooltip="Blocchi azioni">
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                    <path
                        d="M9 3h6a2 2 0 0 1 2 2h2a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1h2a2 2 0 0 1 2-2zm0 2v1h6V5H9z"
                        fill="currentColor" opacity=".85" />
                    <path d="M10.5 13.5l2 2 4-4" stroke="currentColor" stroke-width="2" fill="none"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>

            <!-- Tab: Stati -->
            <button id="rtb-tab-states" class="rtb-tab" type="button" aria-label="Stati"
                data-tooltip="Impostazioni stati">
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 4h16v4H4zM4 10h16v4H4zM4 16h16v4H4z" fill="currentColor" />
                </svg>
            </button>

            <!-- Tab: Schede -->
            <button id="rtb-tab-tabs" class="rtb-tab" type="button" aria-label="Schede" data-tooltip="Proprietà schede">
                <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 3h18v18H3V3zm2 2v14h14V5H5zm2 2h10v2H7V7zm0 4h10v2H7v-2zm0 4h7v2H7v-2z"
                        fill="currentColor" />
                </svg>
            </button>

            <!-- Tab: Notifiche -->
            <button id="rtb-tab-notify" class="rtb-tab" type="button" aria-label="Notifiche"
                data-tooltip="Configurazione notifiche">
                <img src="assets/icons/bell.png" alt="Notifiche" style="width:18px;height:18px;"
                    onerror="this.outerHTML='<span class=&quot;rtb-tab-fallback&quot;>🔔</span>';" />
            </button>
        </div>

        <!-- Pannello espandibile verso sinistra -->
        <div id="rtb-panel" class="rtb-panel" data-section="fields" aria-hidden="false">
            <div class="rtb-panel-header">
                <span class="rtb-title">Campi</span>
                <button id="rtb-close" class="rtb-close" type="button" aria-label="Chiudi"
                    data-tooltip="Chiudi pannello">✖</button>
            </div>
            <div class="rtb-panel-body">
                <!-- Palette “Campi” (default). La palette “azioni” viene iniettata via JS quando si clicca l’altro tab -->
                <div class="field-dashboard" id="field-dashboard">
                    <div class="field-card" draggable="true" data-type="text">campo testo</div>
                    <div class="field-card" draggable="true" data-type="textarea">area testo</div>
                    <div class="field-card" draggable="true" data-type="select">select</div>
                    <div class="field-card" draggable="true" data-type="checkbox">checkbox</div>
                    <div class="field-card" draggable="true" data-type="radio">radio</div>
                    <div class="field-card" draggable="true" data-type="file">file</div>
                    <div class="field-card" draggable="true" data-type="date">data</div>
                    <div class="field-card" draggable="true" data-type="dbselect">db select (dal DB)</div>
                </div>
            </div>
        </div>
    </div>

    <!-- SINISTRA: foglio editor -->
    <div class="pagina-foglio editor-mode" id="form-editor-preview">
        <?php
        $display_name = str_replace('_', ' ', $form_name_param); // Sostituisci underscore con spazi per la visualizzazione
        $title_html = ($form_name_param !== '')
            ? '<img src="assets/icons/edit.png" alt="modifica" style="width:19px;vertical-align:middle;margin-right:6px;"> modifica pagina: ' . htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8')
            : '<img src="assets/icons/plus2.png" alt="crea" style="width:19px;vertical-align:middle;margin-right:6px;"> crea nuova pagina';
        renderPageTitle($title_html, "#878787ff", false);
        ?>

        <!-- TAB BAR -->
        <div id="pe-tabs" style="margin-bottom:8px;"></div>

        <!-- Form Meta Block (allineato a view_form.php) -->
        <div class="form-meta-block" id="pe-standard-meta-block"
            data-tooltip="Dati della richiesta compilati dall'utente" style="grid-column: 1 / -1; margin-bottom: 12px;">
            <!-- 1. Titolo -->
            <div class="meta-row">
                <span class="label">Titolo:</span>
                <span class="meta-value">
                    <input type="text" class="input-title" name="pe-meta-titolo" id="pe-meta-titolo"
                        placeholder="Inserisci il titolo"
                        value="<?= htmlspecialchars($form_meta_data['titolo'] ?? '') ?>" disabled>
                </span>
            </div>

            <!-- 2. Descrizione -->
            <div class="meta-row">
                <span class="label">Descrizione:</span>
                <span class="meta-value">
                    <textarea name="pe-meta-descrizione" id="pe-meta-descrizione"
                        placeholder="Inserisci la descrizione..."
                        disabled><?= htmlspecialchars($form_meta_data['descrizione'] ?? '') ?></textarea>
                </span>
            </div>

            <!-- 3. Opening Date | Deadline -->
            <div class="meta-row meta-row-split">
                <!-- Data Apertura (Fake per editor) -->
                <span class="label-split">Data di apertura:</span>
                <span class="value-split">
                    <?= date('d/m/Y') ?>
                </span>

                <!-- Deadline -->
                <span class="label-split">Data di scadenza:</span>
                <span class="value-split">
                    <input type="date" name="pe-meta-deadline" id="pe-meta-deadline"
                        value="<?= htmlspecialchars($form_meta_data['deadline'] ?? '') ?>" disabled>
                </span>
            </div>

            <!-- 4. Created By | Assigned To -->
            <div class="meta-row meta-row-split">
                <!-- Created By (Fake per editor) -->
                <span class="label-split">Creato da:</span>
                <span class="value-split">—</span>

                <!-- Responsabile (Assegnato a) -->
                <span class="label-split">Assegnato a:</span>
                <span class="value-split" id="pe-meta-responsabile-display">
                    <?php if ($responsabile_info): ?>
                        <img src="<?= htmlspecialchars($responsabile_info['img']) ?>" class="profile-image-small"
                            alt="responsabile">
                        <?= htmlspecialchars($responsabile_info['nome']) ?>
                    <?php else: ?>
                        <span style="color: #999;">—</span>
                    <?php endif; ?>
                </span>
            </div>

            <!-- 5. Priorità | Stato -->
            <div class="meta-row meta-row-split">
                <!-- Priorità -->
                <span class="label-split">Priorità:</span>
                <span class="value-split">
                    <select name="pe-meta-priority" id="pe-meta-priority" disabled>
                        <?php
                        $priority_options = ['Bassa', 'Media', 'Alta'];
                        $current_priority = $form_meta_data['priority'] ?? 'Media';
                        foreach ($priority_options as $opt):
                            ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $current_priority ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>

                <!-- Stato (Fake per editor) -->
                <span class="label-split">Stato:</span>
                <span class="value-split">
                    <select disabled>
                        <option selected>Aperta</option>
                        <option>In corso</option>
                        <option>Chiusa</option>
                    </select>
                </span>
            </div>
        </div>

        <!-- Form Meta Block Esito (popolato da JS, nascosto di default) -->
        <div class="form-meta-block" id="pe-esito-meta-block"
            data-tooltip="Dati compilati dal responsabile come esito della segnalazione">
        </div>

        <!-- ====== STAGE A: STRUTTURA ====== -->
        <div id="editor-stage" class="editor-stage">
            <div id="form-fields-preview" class="form-fields-preview"></div>
        </div>

        <!-- ====== STAGE B: WIZARD ====== -->
        <div id="pe-wizard-placeholder" style="display:none"></div>
        <div id="pe-wizard" class="pe-wizard" style="display:none; margin-top:0;">
            <h3 id="pe-wizard-heading" style="margin-top:0;">
                <?= $form_name_param === '' ? 'Definizione pagina e inserimento nel menu' : 'Proprietà pagina' ?>
            </h3>

            <!-- Nome + Descrizione + Resp -->
            <div class="field-card" style="cursor:default;">
                <div style="display:grid; grid-template-columns:1fr; gap:10px; max-width:520px;">
                    <div>
                        <label for="pe-form-name" style="display:block; margin-bottom:6px;">Nome pagina</label>
                        <input type="text" id="pe-form-name" value="<?= htmlspecialchars($form_name_param) ?>"
                            placeholder="es. richiesta materiali" style="width:100%;max-width:520px;"
                            <?= $form_name_param !== '' ? 'readonly' : '' ?>>
                        <?php if ($form_name_param !== ''): ?>
                            <p style="font-size:12px;color:#6c6c6c;margin:6px 0 0;">
                                Il nome non è modificabile da qui.
                            </p>
                        <?php else: ?>
                            <p style="font-size:12px;color:#6c6c6c;margin:6px 0 0;">
                                Il nome verrà normalizzato (spazi/caratteri speciali rimossi).
                            </p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="pe-form-desc" style="display:block; margin-bottom:6px;">Descrizione
                            (facoltativa)</label>
                        <textarea id="pe-form-desc" rows="2" placeholder="Descrizione della pagina…"
                            style="width:100%;max-width:520px;"></textarea>
                    </div>
                    <div id="pe-responsabili-wrapper">
                        <label style="display:block; margin-bottom:6px;">Responsabili (max 3)</label>
                        <div id="pe-responsabili-container">
                            <div class="pe-responsabile-row"
                                style="display:flex; gap:8px; margin-bottom:8px; align-items:center;">
                                <select class="pe-form-resp" style="width:100%;max-width:450px;">
                                    <option value="">— seleziona responsabile —</option>
                                    <!-- opzioni caricate via JS -->
                                </select>
                                <button type="button" class="button pe-add-resp" style="padding:4px 12px; height:32px;"
                                    title="Aggiungi responsabile">+</button>
                            </div>
                        </div>
                        <p style="font-size:12px;color:#6c6c6c;margin:6px 0 0;">Puoi definire fino a 3 responsabili per
                            questo modulo.</p>
                    </div>
                </div>
            </div>

            <!-- Sezione + Menu padre -->
            <div class="field-card" style="cursor:default; margin-top:10px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:520px;">
                    <div>
                        <label for="mc-section" style="display:block; margin-bottom:6px;">Sezione</label>
                        <select id="mc-section" style="width:100%;"></select>
                    </div>
                    <div>
                        <label for="mc-parent" style="display:block; margin-bottom:6px;">Menu padre</label>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <select id="mc-parent" style="flex:1;"></select>
                            <button type="button" id="btn-new-parent-menu" class="button button-secondary"
                                style="padding:6px 10px; min-width:auto;"
                                data-tooltip="Crea nuovo menu padre">+</button>
                        </div>
                        <div id="new-parent-menu-form" class="hidden"
                            style="margin-top:8px; padding:10px; background:#f8f9fa; border-radius:6px; border:1px solid #e1e8ed;">
                            <label style="display:block; margin-bottom:4px; font-size:12px;">Nome nuovo menu</label>
                            <input type="text" id="new-parent-menu-title" class="input" placeholder="Es: Report Mensili"
                                maxlength="80" style="width:100%; margin-bottom:8px;">
                            <div style="display:flex; gap:6px;">
                                <button type="button" id="btn-create-parent-menu" class="button"
                                    style="padding:6px 12px; font-size:12px;">Crea</button>
                                <button type="button" id="btn-cancel-parent-menu" class="button button-secondary"
                                    style="padding:6px 12px; font-size:12px;">Annulla</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Colore + Voce attiva -->
            <div class="field-card" style="cursor:default; margin-top:10px;">
                <div
                    style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-width:520px; align-items:center;">
                    <div>
                        <label for="pe-form-color" style="display:block; margin-bottom:6px;">Colore del modulo</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="color" id="pe-form-color" value=""
                                style="width:48px;height:32px;padding:0;border:none;">
                            <input type="text" id="pe-form-color-hex" value="" maxlength="7"
                                pattern="^#([A-Fa-f0-9]{6})$" style="width:110px;" placeholder="#RRGGBB"
                                data-tooltip="Inserisci un esadecimale (#RRGGBB)">
                        </div>
                        <p style="font-size:12px;color:#6c6c6c;margin:6px 0 0;">Usato per evidenziare il modulo
                            (tabella/Kanban).</p>
                    </div>

                    <div style="display:flex; align-items:center; gap:8px; margin-top:22px;">
                        <input type="checkbox" id="mc-attivo" checked
                            data-tooltip="se disattivi, la voce viene creata ma non visibile nel menu">
                        <label for="mc-attivo" style="margin:0;">Voce attiva</label>
                    </div>
                </div>
            </div>

            <div style="margin-top:14px; display:flex; gap:8px; flex-wrap:wrap;">
                <button id="pe-wizard-save" class="button" type="button">
                    <?= $form_name_param === '' ? '💾 salva pagina e aggiungi al menu' : '💾 salva proprietà' ?>
                </button>
            </div>
        </div>

        <!-- ====== STAGE C: AZIONI (layout coerente con “Struttura”) ====== -->
        <div id="response-stage" class="editor-stage" style="display:none;">
            <div id="response-config-form"></div>
        </div>

        <!-- Footer preview esito -->
        <div id="response-esito-preview" class="pe-response-footer" style="display:none; margin-top:24px;">
            <!-- popolato via JS -->
        </div>
    </div>

    <!-- BARRA AZIONI FISSA IN BASSO -->
    <div id="pe-bottom-bar" style="
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: linear-gradient(to top, rgba(255,255,255,0.98) 0%, rgba(255,255,255,0.95) 100%);
        backdrop-filter: blur(8px);
        border-top: 1px solid #e0e0e0;
        padding: 12px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 1000;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.08);
    ">
        <div style="font-size: 13px; color: #666;">
            <span id="pe-status-text">Pronto per salvare</span>
        </div>
        <div style="display: flex; gap: 10px;">
            <button id="pe-preview-btn" class="button btn-secondary" type="button"
                style="padding: 10px 20px; font-weight: 500;">
                👁️ Anteprima
            </button>
            <button id="pe-unified-save" class="button" type="button"
                style="padding: 10px 24px; font-weight: 500; background: #cd211d; color: white;">
                💾 Salva
            </button>
        </div>
    </div>
</div>

<style>
    /* Aggiungi padding al contenuto per evitare che venga coperto dalla barra fissa */
    .pagina-foglio.editor-mode {
        padding-bottom: 80px;
    }
</style>

<!-- Modal impostazioni "blocchi azioni" (riutilizzato da tutti i tipi) -->
<div id="resp-field-modal" class="modal hidden" aria-hidden="true">
    <div class="modal-dialog" role="dialog" aria-modal="true">
        <div class="modal-header">
            <h3 id="resp-field-title" style="margin:0;">Impostazioni</h3>
            <button class="close-modal" aria-label="Chiudi">✖</button>
        </div>
        <div class="modal-body" id="resp-field-body"></div>
        <div class="modal-footer">
            <button class="button" id="resp-field-save">Salva</button>
            <button class="button btn-secondary" id="resp-field-cancel">Annulla</button>
        </div>
    </div>
</div>

<!-- Modal Anteprima Form (pagina-foglio come unico contenuto, centrato, overlay scuro) -->
<div id="preview-modal" class="modal"
    style="display:none; position: fixed; inset: 0; z-index: 100000; align-items:center; justify-content:center; padding:20px; background: rgba(0,0,0,0.45);">
</div>

<input type="hidden" id="form-name-editor" value="<?= htmlspecialchars($form_name_param) ?>">

<!-- js -->
<script src="assets/js/gestione_intranet/page_editor.js"></script>