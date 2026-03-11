<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// Assicurati che $documentArea sia definita (dal wrapper o dall'include)
if (!isset($documentArea)) {
    if (isset($section) && \Services\DocumentAreaRegistry::isValid($section)) {
        $documentArea = $section;
    } else {
        $documentArea = 'archivio'; // Fallback
    }
}

$config = \Services\DocumentAreaRegistry::getDocumentAreaConfig($documentArea);

// Permessi dinamici
$permView = $config['permissions']['view'];
$permManage = $config['permissions']['manage'];

if (!checkPermissionOrWarn($permView))
    return;

use Services\DocumentManagerService;

$pageSlug = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
// Ottieni pagine filate per area
$pagine = getDocumentAreaPages($documentArea);
$canManage = userHasPermission($permManage);

// Iniezione JS
echo "<script>";
echo "window.documentArea = " . json_encode([
    'id' => $documentArea,
    'label' => $config['label'],
    'macro_policy' => $config['macro_policy'],
    'ui_host' => $config['ui_host'],
    'permissions' => [
        'manage' => $canManage
    ]
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) . ";";
echo "</script>";

// Se page=documentArea o vuoto, siamo in dashboard
$isVistaGenerale = ($pageSlug === $documentArea || empty($pageSlug));
?>

<div class="main-container">

    <!-- Vista ELENCO (tabella) -->
    <div id="elenco-view" <?php if (!($pageSlug && !$isVistaGenerale))
        echo 'class="hidden"'; ?>>
        <?php
        if ($pageSlug && !$isVistaGenerale) {
            $pagina = array_filter($pagine, fn($p) => $p['slug'] === $pageSlug);
            $pagina = reset($pagina);

            if (!$pagina) {
                echo "<div class='error'>Pagina non trovata</div>";
            } else {
                renderPageTitle(ucfirst($config['label']) . ": " . htmlspecialchars($pagina['titolo']), $pagina['colore'] ?? $config['color']);
                ?>
                <div class="archivio-dettaglio">
                    <p><?= nl2br(htmlspecialchars($pagina['descrizione'] ?? '')) ?></p>

                    <style>
                        .dm-folder-card {
                            display: flex;
                            flex-direction: column;
                            align-items: center;
                            justify-content: flex-start;
                            width: 110px;
                            min-height: 90px;
                            padding: 12px 8px;
                            box-sizing: border-box;
                            border-radius: 8px;
                            cursor: pointer;
                            background: var(--bg-secondary, #f5f5f5);
                            border: 1px solid var(--border-color, #e0e0e0);
                            transition: box-shadow .15s, transform .15s;
                        }

                        .dm-folder-card:hover {
                            box-shadow: 0 2px 8px rgba(0, 0, 0, .12);
                            transform: translateY(-2px);
                        }

                        .dm-folder-name {
                            font-size: 12px;
                            margin-top: 8px;
                            text-align: center;
                            width: 100%;
                            word-break: break-word;
                            line-height: 1.3;
                        }

                        .dm-folder-add {
                            border-style: dashed;
                            opacity: .7;
                        }

                        .dm-folder-add:hover {
                            opacity: 1;
                        }

                        .dm-folder-breadcrumb a {
                            color: var(--link-color, #1a73e8);
                            text-decoration: none;
                        }

                        .dm-folder-breadcrumb a:hover {
                            text-decoration: underline;
                        }
                    </style>

                    <!-- BREADCRUMB CARTELLE -->
                    <div id="dm-folder-breadcrumb" class="dm-folder-breadcrumb"
                        style="display:none; margin: 12px 0 8px; font-size: 14px;">
                        <a href="#" id="dm-breadcrumb-root"
                            data-tooltip="Torna alla root"><?= htmlspecialchars($pagina['titolo']) ?></a>
                        <span id="dm-breadcrumb-separator" style="display:none;"> &rsaquo; </span>
                        <span id="dm-breadcrumb-folder" style="font-weight:600;"></span>
                    </div>

                    <!-- GRID CARTELLE -->
                    <div id="dm-folders-grid" class="dm-folders-grid"
                        style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                        <!-- Popolamento JS -->
                    </div>

                    <!-- MODALE UPLOAD GLOBALE -->
                    <?php if ($canManage): ?>
                        <div id="uploadModalDM" class="modal">
                            <div class="modal-content modal-small">
                                <div class="modal-header">
                                    <h3>Carica Documenti</h3>
                                    <span class="close" data-tooltip="Chiudi">&times;</span>
                                </div>
                                <div class="modal-body">
                                    <div id="dropAreaDM" class="big-drop-area">
                                        <div class="upload-preview"></div>
                                        <div class="big-drop-label" data-tooltip="Trascina qui i file da caricare">
                                            <i class="fa fa-cloud-upload"
                                                style="font-size: 32px; margin-bottom: 8px; display: block;"></i>
                                            Trascina qui uno o più file
                                        </div>
                                        <div class="muted">oppure</div>
                                        <input type="file" id="uploadFilesDM" name="files[]" multiple class="hidden"
                                            accept="application/pdf,image/jpeg,image/png,image/webp,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                                        <button type="button" class="button button-secondary" id="selectFilesBtn"
                                            data-tooltip="Seleziona i file dal computer">
                                            <i class="fa fa-folder-open"></i> Seleziona file
                                        </button>
                                    </div>

                                    <!-- Lista file selezionati -->
                                    <div id="uploadFilesList"
                                        style="display: none; margin-top: 15px; max-height: 150px; overflow-y: auto;"></div>

                                    <!-- Campo titolo -->
                                    <div class="form-group" id="uploadTitoloGroup" style="margin-top: 15px; display: none;">
                                        <label for="uploadTitoloDM"
                                            style="font-weight: 500; margin-bottom: 4px; display: block;">Titolo</label>
                                        <input type="text" id="uploadTitoloDM" name="titolo" maxlength="128"
                                            placeholder="Lascia vuoto per usare il nome del file" class="input">
                                    </div>

                                    <!-- Campo descrizione -->
                                    <div class="form-group" id="uploadDescrizioneGroup" style="margin-top: 10px; display: none;">
                                        <label for="uploadDescrizioneDM"
                                            style="font-weight: 500; margin-bottom: 4px; display: block;">Descrizione <span
                                                style="font-weight: normal; color: #888;">(facoltativa)</span></label>
                                        <textarea id="uploadDescrizioneDM" name="descrizione" maxlength="300"
                                            placeholder="Descrizione" class="textarea" rows="2"></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="button button-secondary" id="cancelUploadDM">Annulla</button>
                                    <button type="button" class="button button-primary" id="uploadButtonDM"
                                        data-tooltip="Carica i documenti selezionati" disabled>
                                        <i class="fa fa-upload"></i> Carica
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>


                    <!-- VISTA TABELLA -->
                    <div class="table-wrapper hidden" style="margin-top: 20px;">
                        <table class="table table-filterable" id="documentiTable" data-page-size="50">
                            <thead>
                                <tr>
                                    <th class="azioni-colonna">Azioni</th>
                                    <th>Titolo</th>
                                    <th>Descrizione</th>
                                    <th>Data Creazione</th>
                                    <th>Data Caricamento</th>
                                </tr>
                            </thead>
                            <tbody id="documenti-list">
                                <!-- Popolamento JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- VISTA KANBAN/GRIGLIA FILE -->
                    <div id="documenti-kanban" class="documenti-kanban-view" style="display: flex; flex-wrap: wrap; gap: 15px;">
                        <!-- Popolamento JS -->
                    </div>

                </div>
                <?php
            }
        }
        ?>
    </div>

    <!-- Vista DASHBOARD (griglia cartelle) -->
    <div id="kanban-view" <?php if ($pageSlug && !$isVistaGenerale)
        echo 'class="hidden"'; ?>>
        <?php
        if (!$pageSlug || $isVistaGenerale) {
            renderPageTitle('Sezione ' . $config['label'], $config['color']); ?>
            <?php if (empty($pagine) && $documentArea === 'formazione'): ?>
                <div class="empty-dashboard" style="text-align: center; padding: 40px;">
                    <img src="/assets/images/howto/dashboard_formazione.webp" alt="Come usare la dashboard"
                        style="max-width: 100%; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                </div>
            <?php else: ?>
                <div class="grid-container">
                    <?php foreach ($pagine as $pagina): ?>
                        <div class="grid-item" data-slug="<?= htmlspecialchars($pagina['slug']) ?>">
                            <img src="<?= htmlspecialchars($pagina['immagine']) ?>" alt="<?= htmlspecialchars($pagina['slug']) ?>"
                                class="grid-image">
                            <p><?= htmlspecialchars($pagina['descrizione']) ?></p>
                            <?php
                            $targetSection = ($config['ui_host'] === 'root') ? $documentArea : $config['ui_host'];
                            ?>
                            <a href="index.php?section=<?= urlencode($targetSection) ?>&page=<?= urlencode($pagina['slug']) ?>"
                                class="button"><?= strtoupper($pagina['titolo']) ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php } ?>
    </div>

</div>

<?php if ($canManage): ?>
    <div id="modalNuovaPagina" class="modal">
        <div class="modal-content modal-medium">
            <div class="modal-header">
                <h3>Crea nuova pagina</h3>
                <span class="close" data-tooltip="Chiudi">&times;</span>
            </div>
            <div class="modal-body">
                <p class="muted" style="margin-bottom:20px;font-size:14px;">Compila i campi per aggiungere una nuova sezione
                </p>
                <form id="formNuovaPagina" autocomplete="off">
                    <?php if ($config['macro_policy'] !== 'single'): ?>
                        <div class="form-group">
                            <label for="menuSelect">Menu<span class="required">*</span></label>
                            <div class="u-flex-row">
                                <select id="menuSelect" name="menu_title" required class="input" style="flex:1;">
                                    <option value="">-- Seleziona menu --</option>
                                </select>
                                <button type="button" id="btnNuovoMenu" class="button button-secondary"
                                    style="padding:8px 12px;min-width:auto;" data-tooltip="Crea nuovo menu">+</button>
                            </div>
                            <div id="createMenuInlineArea" class="hidden">
                                <div class="form-group">
                                    <label for="newMenuTitleDM">Nome nuovo menu</label>
                                    <input type="text" id="newMenuTitleDM" class="input"
                                        placeholder="Inserisci il nome del menu" maxlength="80">
                                </div>
                                <div class="u-flex-row">
                                    <button type="button" id="btnCreateMenuInlineDM" class="button">Crea</button>
                                    <button type="button" id="btnCancelMenuInlineDM"
                                        class="button button-secondary">Annulla</button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="titolo">Titolo della pagina<span class="required">*</span></label>
                        <input type="text" id="titolo" name="titolo" maxlength="64" required class="input">
                    </div>

                    <div class="form-group">
                        <label for="descrizione">Descrizione breve</label>
                        <textarea id="descrizione" name="descrizione" rows="2" maxlength="200" class="textarea"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Immagine copertina</label>
                        <div class="u-flex-row">
                            <input type="file" id="uploadThumb" accept="image/*" class="hidden">
                            <button type="button" id="uploadThumbBtn" class="button button-secondary">Scegli
                                immagine</button>
                            <input type="hidden" name="immagine" id="immaginePath">
                            <div id="previewThumb"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Colore sezione</label>
                        <input type="color" name="colore" value="#ce221c" class="input"
                            style="width:60px;height:38px;padding:2px;">
                    </div>

                    <input type="hidden" id="slug" name="slug">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="button button-secondary" id="cancelNuovaPagina">Annulla</button>
                <button type="submit" form="formNuovaPagina" class="button">Crea pagina</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
    // window.documentArea è già iniettato all'inizio del documento
    // Nota: window.currentSection è deprecato – usare window.documentArea.id
    window.currentSection = window.documentArea ? window.documentArea.id : '<?= htmlspecialchars($documentArea, ENT_QUOTES) ?>';
</script>

<script src="/assets/js/media_viewer.js"></script>
<script src="/assets/js/document_manager.js"></script>