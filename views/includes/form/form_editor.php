<?php
if (!checkPermissionOrWarn('view_moduli')) return;

$formName = isset($_GET['form_name']) ? htmlspecialchars(trim($_GET['form_name'])) : null;
if (!preg_match('/^[\w\s\-àèéùòì]+$/ui', $formName)) {
    die("<p style='color:red;'> Nome modulo non valido.</p>");
}
if (!$formName) {
    echo "<p> Parametro 'form_name' mancante per modificare il modulo.</p>";
    return;
}

$responsabileInfo = null;
$stmt = $database->query(
    "SELECT f.responsabile, p.Nominativo 
     FROM forms f 
     LEFT JOIN personale p ON f.responsabile = p.user_id 
     WHERE f.name = :name",
    [':name' => $formName],
    __FILE__
);

if ($stmt && ($row = $stmt->fetch(PDO::FETCH_ASSOC)) && !empty($row['Nominativo'])) {
    $imgPath = getProfileImage($row['Nominativo'], 'nominativo');
    $responsabileInfo = [
        'nome' => $row['Nominativo'],
        'img' => $imgPath
    ];
}
?>

<link rel="stylesheet" href="assets/css/form.css">

<div class="main-container editor-wrapper">

    <!-- contenitore dashboard moduli -->
    <div class="modules-dock" id="modules-dock" style="margin-bottom:14px; border:1px solid #e3e3e3; border-radius:10px; padding:12px;">
        <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;">
            <div style="min-width:260px; flex:1;">
                <h4 style="margin:0 0 8px;">moduli attivi</h4>
                <div id="active-modules" class="modules-list" style="display:flex; gap:8px; flex-wrap:wrap;"></div>
            </div>
            <div style="min-width:260px; flex:1;">
                <h4 style="margin:0 0 8px;">moduli disponibili</h4>
                <div id="available-modules" class="modules-list" style="display:flex; gap:8px; flex-wrap:wrap;"></div>
            </div>
        </div>
        <p style="margin:10px 0 0; font-size:12px; color:#666;">
            suggerimento: aggiungi <b>gestione della richiesta</b> per i campi base.
        </p>
    </div>

    <!-- SINISTRA: foglio editor -->
    <div class="pagina-foglio editor-mode" id="form-editor-preview">
        <?php renderPageTitle('<img src="assets/icons/edit.png" alt="Modifica" style="width:19px;vertical-align:middle;margin-right:6px;"> Modifica Modulo: ' . htmlspecialchars($formName), "#2980B9", false); ?>
        <?php if ($responsabileInfo): ?>
            <p class="responsabile-info">
                <span class="label">Responsabile:</span>
                <img src="<?= htmlspecialchars($responsabileInfo['img']) ?>" class="profile-image-small">
                <span><?= htmlspecialchars($responsabileInfo['nome']) ?></span>
            </p>
        <?php endif; ?>

        <!-- QUI ANTEPRIMA DRAG&DROP DEI CAMPI -->
        <div id="form-fields-preview" class="form-fields-preview"></div>
        <div class="form-editor-footer" style="margin-top:30px">
            <button id="save-form-btn" type="button" class="button">💾 Salva Modifiche</button>
        </div>
    </div>

    <!-- DESTRA: dashboard campi drag -->
    <div class="field-dashboard" id="field-dashboard" style="position: absolute; top: 70px; right: 30px; z-index: 30;">
    <div id="dashboard-drag-handle" style="position: absolute; top: 6px; right: 6px; width: 24px; height: 24px; cursor: grab; display: flex; align-items: center; justify-content: center;">
        <img src="assets/icons/drag.png" alt="Trascina" style="width: 10px; height: 10px; opacity: 0.7;">
    </div>
        <h4>Campi disponibili</h4>
        <div class="field-card" draggable="true" data-type="text">Campo Testo</div>
        <div class="field-card" draggable="true" data-type="textarea">Area Testo</div>
        <div class="field-card" draggable="true" data-type="select">Select</div>
        <div class="field-card" draggable="true" data-type="checkbox">Checkbox</div>
        <div class="field-card" draggable="true" data-type="radio">Radio</div>
        <div class="field-card" draggable="true" data-type="file">File</div>
        <div class="field-card" draggable="true" data-type="date">Data</div>
    </div>
</div>

<!-- Hidden input per nome modulo -->
<input type="hidden" id="form-name-editor" value="<?= htmlspecialchars($formName) ?>">
