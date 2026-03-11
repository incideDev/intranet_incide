<?php
// ----- INIZIO BLOCCO SOSTITUTIVO -----
$__sec_req_raw = $_GET['section'] ?? '';
$__sec_req = preg_replace('/[^a-z_]/i', '', strtolower((string) $__sec_req_raw));

$formName_raw = $_GET['form_name'] ?? null;
$formName = $formName_raw ? preg_replace('/[^\w\s\-àèéùòì]/u', '', strtolower(trim((string) $formName_raw))) : null;

// Se manca il form_name: fermati SUBITO (niente HTML prima degli header)
if (!$formName) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}

// Ricava la sezione "effettiva" del form dal menu (se presente)
$__sec_eff = $__sec_req;
try {
    // Usa il servizio già presente nel tuo codice
    $placement = \Services\MenuCustomService::getMenuPlacementForForm($formName);
    if (!empty($placement['success']) && !empty($placement['placement']['section'])) {
        $__sec_eff = (string) $placement['placement']['section'];
    }
} catch (\Throwable $e) {
    // in caso di errori DB non cambiamo sezione
}

// Se la sezione richiesta NON coincide con quella effettiva → REDIRECT 301 PRIMA DI QUALSIASI OUTPUT
if ($__sec_req !== '' && $__sec_eff !== '' && $__sec_req !== $__sec_eff) {
    $qs = [
        'section' => $__sec_eff,
        'page' => 'view_form',
        'form_name' => $formName
    ];
    if (isset($_GET['id']))
        $qs['id'] = (string) $_GET['id'];
    if (isset($_GET['edit']))
        $qs['edit'] = (string) $_GET['edit'];
    header('Location: index.php?' . http_build_query($qs), true, 301);
    exit;
}

// ----- FINE BLOCCO SOSTITUTIVO -----

// ENFORCEMENT BACKEND: Controllo accesso al form PRIMA di qualsiasi processing
// NOTA: NON usare permessi generici (view_segnalazioni, view_moduli) per pagine page_editor.
// Ogni pagina page_editor richiede permesso specifico page_editor_form_view:<form_id>
// Deve essere applicato SEMPRE (sia per nuovi record che per esistenti)
global $database;
$form = $database->query("SELECT id, table_name FROM forms WHERE name=:n LIMIT 1", [':n' => $formName], __FILE__)->fetch(\PDO::FETCH_ASSOC);

if (!$form) {
    // Form non trovato: usa lo stesso stile di checkPermissionOrWarn
    echo "<div style='color:red; padding:20px; border:1px solid red; margin:20px; background:#ffeaea;'>";
    echo "<h3>Modulo Non Trovato</h3>";
    echo "<p>Il modulo richiesto non esiste o non è più disponibile.</p>";
    echo "</div>";
    exit;
}

// Controllo accesso: admin bypass o permesso specifico
$formId = (int) $form['id'];
$requiredPermission = "page_editor_form_view:{$formId}";
if (!isAdmin() && !userHasPermission($requiredPermission)) {
    // Usa lo stesso stile di checkPermissionOrWarn per consistenza
    echo "<div style='color:red; padding:20px; border:1px solid red; margin:20px; background:#ffeaea;'>";
    echo "<h3>Accesso Negato</h3>";
    echo "<p>Non hai i permessi per accedere a questa pagina.</p>";
    echo "<p>Permesso richiesto: <strong>{$requiredPermission}</strong></p>";
    echo "</div>";
    exit;
}

$compilatoInfo = null;
$formAssegnatariIds = [];

// Helper locale per JSON sicuro (evita errori "Unexpected token '<'")
function safeJsonEncode($value, $fallback = null)
{
    if ($fallback === null) {
        $fallback = (is_array($value) || is_object($value)) ? '{}' : 'null';
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON encode error in view_form.php: " . json_last_error_msg() . " - Value type: " . gettype($value));
        return $fallback;
    }
    return $json;
}

$isNewRecord = !isset($_GET['id']);
// Inizializza $recordId per evitare errori di variabile indefinita
$recordId = null;

// FIX BUG: Se viene passato un ID ma il record non esiste, tratta come nuovo
if (isset($_GET['id'])) {
    $requestedId = (int) $_GET['id'];
    $table = $form['table_name'];

    // Controlla se il record esiste
    $recordExists = false;
    try {
        $checkRecord = $database->query("SELECT id FROM `{$table}` WHERE id=:id LIMIT 1", [':id' => $requestedId], __FILE__);
        $recordExists = ($checkRecord && $checkRecord->fetch(PDO::FETCH_ASSOC) !== null);
    } catch (\Throwable $e) {
        // In caso di errore DB, assumi che non esista
        $recordExists = false;
    }

    // Se il record non esiste, tratta come nuovo (ignora l'ID)
    if (!$recordExists) {
        $isNewRecord = true;
    }
}

// ------------------------------------------------------------------
// DEBUG NOTIFICHE: Controlla configurazione da DB
// ------------------------------------------------------------------
try {
    $rulesChk = \Services\PageEditorService::getNotificationRules($formName);
    if (!empty($rulesChk['rules'])) {
        echo "<!-- [DEBUG] Notification Rules Found for $formName: Enabled=" . ($rulesChk['rules']['enabled'] ? 'YES' : 'NO') . " -->";
    } else {
        echo "<!-- [DEBUG] No Notification Rules for $formName -->";
    }
} catch (Exception $e) {
}
// ------------------------------------------------------------------

if (isset($_GET['id']) && !$isNewRecord) {
    $compilatoInfo = \Services\PageEditorService::getCompilatoInfo($formName, (int) $_GET['id']);

    // Recupera assegnatari dal record se esiste
    try {
        $table = $form['table_name'];
        $recordId = (int) $_GET['id']; // Siamo nel blocco if(isset($_GET['id']) && !$isNewRecord), quindi ID esiste

        // Controlla se la colonna assegnato_a esiste
        $colExists = false;
        try {
            $checkCol = $database->query("SHOW COLUMNS FROM `{$table}` LIKE 'assegnato_a'", [], __FILE__);
            $colExists = ($checkCol && $checkCol->rowCount() > 0);
        } catch (\Throwable $e) {
            // Colonna non esiste
        }

        if ($colExists) {
            $record = $database->query("SELECT assegnato_a FROM `{$table}` WHERE id=:id LIMIT 1", [':id' => $recordId], __FILE__)->fetch(\PDO::FETCH_ASSOC);
            if ($record && !empty($record['assegnato_a'])) {
                $assegnatoValue = trim((string) $record['assegnato_a']);
                // Se è un ID numerico, aggiungilo alla lista
                if (is_numeric($assegnatoValue) && (int) $assegnatoValue > 0) {
                    $formAssegnatariIds[] = (int) $assegnatoValue;
                }
            }
        }
    } catch (\Throwable $e) {
        // Ignora errori
    }
}

// Carica info form per permessi (responsabile)
// Usa query diretta per ottenere solo responsabile (necessario per permessi)
$formInfo = null;
try {
    global $database;
    $st = $database->query("SELECT responsabile FROM forms WHERE name=:n LIMIT 1", [':n' => $formName], __FILE__);
    $formRow = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
    if ($formRow) {
        $formInfo = ['responsabile' => (int) ($formRow['responsabile'] ?? 0)];
    }
} catch (\Throwable $e) {
    // Ignora errori
}

// Info utente corrente per permessi
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRoleId = (int) ($_SESSION['role_id'] ?? 0);
$isAdmin = isAdmin();
$isResponsabile = $formInfo && ((int) ($formInfo['responsabile'] ?? 0) === $currentUserId);
$canEditAssegnatoA = true; // Logic changed: anyone filling the form can assign it

// NOTA: Le funzioni di visibilità sono ora gestite da PageEditorService::getVisibleSchedeForUser
// e dal JavaScript processTabsVisibilityJS che usa calculatePageVisibilityJS
// Queste funzioni PHP sono state rimosse perché obsolete e sostituite dalla logica del servizio
?>

<div class="main-container">
    <div class="pagina-foglio">
        <div class="view-form-header" style="--form-color:#ccc;">
            <div class="form-header-row">
                <h1 id="form-title">Caricamento...</h1>
                <span class="protocollo-tag" id="protocollo-viewer" data-tooltip="protocollo">-</span>
            </div>
            <hr class="form-title-divider">
        </div>

        <!-- Struttura a griglia -->
        <div class="view-form-content">
            <!-- Schede -->
            <div class="form-tabs-container">
                <div class="form-tabs-bar" id="form-tabs-bar">
                    <!-- Le schede verranno generate dinamicamente -->
                </div>
                <div class="form-tabs-content">
                    <form id="form-invio" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="form_name" value="<?= htmlspecialchars($formName); ?>">
                        <?php if (isset($_GET['id'])): ?>
                            <input type="hidden" name="record_id" value="<?= (int) $_GET['id'] ?>">
                        <?php endif; ?>
                        <div class="view-form-grid" id="form-fields"></div>
                        <button type="submit" class="button" id="form-submit-btn">Salva</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="/assets/css/form.css">

<style>
    /* Professional 'Altro' logic styling */
    .custom-input-container {
        display: none;
        align-items: center;
        gap: 10px;
        margin-top: 8px;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .custom-input-field {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
    }

    .custom-input-field:focus {
        border-color: #007bff;
        outline: none;
        box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
    }

    .btn-back-to-list {
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 6px 12px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
        font-size: 12px;
        color: #666;
    }

    .btn-back-to-list:hover {
        background: #e9ecef;
        color: #333;
    }
</style>

<script src="/assets/js/form_tabs_common.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", async () => {
        // Alias per compatibilità
        window.current_formname = window.current_formname || <?= json_encode($formName) ?>;
        window.form_edit_mode = <?= isset($_GET['edit']) ? 'true' : 'false' ?>;

        const formName = window.current_formname || <?= json_encode($formName) ?>;
        const formEl = document.getElementById("form-invio");
        const formFieldsContainer = document.getElementById("form-fields");

        const isEdit = !!window.form_edit_mode;
        const recordId = <?= $recordId === null ? 'null' : (int) $recordId ?>;
        const isNewRecord = !recordId;
        const canEditAssegnatoA = <?= json_encode($canEditAssegnatoA) ?>;
        let entryData = {};

        // Dati per calcolo visibilità schede
        const visibilityData = {
            currentUserId: <?= json_encode($currentUserId) ?>,
            currentRoleId: <?= json_encode($currentRoleId) ?>,
            formResponsabileId: <?= json_encode(($formInfo && isset($formInfo['responsabile'])) ? (int) $formInfo['responsabile'] : 0) ?>,
            formAssegnatariIds: <?= json_encode($formAssegnatariIds) ?>,
            isNewRecord: isNewRecord,
            recordSubmittedBy: 0 // Verrà aggiornato dopo il caricamento entryData
        };

        // Funzione per renderizzare i campi
        // isEditable: se false, i campi sono readonly (usato per logica botta e risposta)
        function renderField(field, target_container = formFieldsContainer, isEditable = true) {
            if (!field) return;

            // Sicurezza: skippa i campi fissi (sono nel meta block)
            const fieldName = String(field.field_name || '').toLowerCase();
            const fissiKeys = ['titolo', 'descrizione', 'deadline', 'priority'];
            if (fissiKeys.includes(fieldName)) {
                return; // Non renderizzare - già nel meta block
            }

            if (String(field.field_type || '').toLowerCase() === 'section') {
                let cfg = {};
                try {
                    cfg = typeof field.field_options === 'string' ?
                        (JSON.parse(field.field_options || '{}') || {}) :
                        (field.field_options || {});
                } catch (_) {
                    cfg = {};
                }

                const fs = document.createElement('fieldset');
                fs.className = 'view-form-group form-section';
                // FIX: Le sezioni DEVONO sempre occupare tutta la riga (2 colonne)
                fs.classList.add('span-2'); // Classe per colspan=2
                fs.style.gridColumn = '1 / -1'; // Forza l'occupazione di tutte le colonne
                fs.style.width = '100%'; // Forza larghezza piena

                const lg = document.createElement('legend');
                lg.className = 'form-section-legend';
                lg.textContent = (cfg.label || 'sezione');
                lg.setAttribute('data-tooltip', 'titolo sezione');
                fs.appendChild(lg);

                const inner = document.createElement('div');
                inner.className = 'section-grid';
                fs.appendChild(inner);

                const ch = Array.isArray(cfg.children) ? cfg.children : [];
                if (!ch.length) {
                    const empty = document.createElement('div');
                    empty.className = 'input-static';
                    empty.style.gridColumn = '1 / -1';
                    empty.style.color = '#999';
                    empty.textContent = 'Nessun campo nella sezione.';
                    inner.appendChild(empty);
                } else {
                    ch.forEach((c, idx) => {
                        const faux = {
                            field_name: (c.name || '').toString(),
                            field_type: (c.type || 'text'),
                            field_placeholder: (c.placeholder || ''),
                            field_label: (c.label || c.etichetta || ''),
                            field_options: (c.type === 'dbselect') ?
                                JSON.stringify(c.datasource || {}) : JSON.stringify(Array.isArray(c.options) ? c.options : []),
                            colspan: (Number(c.colspan) === 2 ? 2 : 1)
                        };
                        if (faux) {
                            renderField(faux, inner, isEditable);
                        }
                    });
                }

                target_container?.appendChild(fs);
                return;
            }

            // ——— campi standard ———
            const group = document.createElement('div');
            group.classList.add('view-form-group');

            // colspan (1 = mezza colonna, 2 = intera riga)
            const cs_raw = parseInt(field.colspan ?? field.colSpan ?? field.ColSpan ?? 1, 10);
            const cs = (cs_raw === 2) ? 2 : 1;
            if (cs === 2) group.classList.add('span-2');

            // Fallback labels per i 5 campi fissi (solo se placeholder vuoto)
            const FIXED_LABELS_FALLBACK = {
                titolo: 'Titolo',
                descrizione: 'Descrizione',
                deadline: 'Scadenza',
                priority: 'Priorità',
                assegnato_a: 'Assegnato a'
            };

            const field_key = String(field.field_name || '').toLowerCase();

            // Calcolo label con priorità: field_placeholder → field_options.label → FIXED_LABELS_FALLBACK → humanizedName
            let label_name = '';

            // 1) field_placeholder (fonte principale)
            if (field.field_placeholder && String(field.field_placeholder).trim()) {
                label_name = String(field.field_placeholder).trim();
            } else {
                // 2) field_label o field_options.label
                try {
                    if (field.field_label && String(field.field_label).trim()) {
                        label_name = String(field.field_label).trim();
                    } else {
                        const fo = typeof field.field_options === 'string' ?
                            JSON.parse(field.field_options || '{}') :
                            (field.field_options || {});
                        if (fo && fo.label && String(fo.label).trim()) {
                            label_name = String(fo.label).trim();
                        }
                    }
                } catch (_) { }

                // 3) Fallback per campi fissi
                if (!label_name && FIXED_LABELS_FALLBACK[field_key]) {
                    label_name = FIXED_LABELS_FALLBACK[field_key];
                }

                // 4) Fallback generico (humanizedName)
                if (!label_name) {
                    label_name = String(field.field_name || '')
                        .replace(/_/g, ' ')
                        .replace(/\b\w/g, l => l.toUpperCase());
                }
            }

            const label = document.createElement('label');
            label.innerHTML = label_name + ':';
            group.appendChild(label);

            // placeholder: prendi quello del campo, altrimenti genera "inserisci {label}"
            let placeholder = '';
            if (typeof field.field_placeholder === 'string') {
                placeholder = field.field_placeholder.trim();
            }
            const lowerType = String(field.field_type || '').toLowerCase();
            const isTextual = (lowerType === 'text' || lowerType === 'textarea' || lowerType === 'date' || lowerType === 'dbselect');
            if (!placeholder && isTextual && label_name) {
                placeholder = 'inserisci ' + label_name.toLowerCase();
            }

            const name = field.field_name;
            const type = field.field_type;
            // CORREZIONE: Per dbselect, field_options è un oggetto, non un array
            let options = [];
            try {
                if (type === 'dbselect') {
                    // Per dbselect, field_options è un oggetto con table, valueCol, labelCol
                    options = typeof field.field_options === 'object' && !Array.isArray(field.field_options)
                        ? field.field_options
                        : (JSON.parse(field.field_options || '{}') || {});
                } else {
                    // Per select: supporto stringa JSON O oggetto/array (come dbselect)
                    if (Array.isArray(field.field_options)) {
                        options = field.field_options;
                    } else if (typeof field.field_options === 'string') {
                        try { options = JSON.parse(field.field_options || '[]') || []; } catch (_) { options = []; }
                    } else if (field.field_options && typeof field.field_options === 'object' && !Array.isArray(field.field_options)) {
                        options = Array.isArray(field.field_options.options) ? field.field_options.options : [];
                    } else {
                        options = [];
                    }
                }
            } catch (_) {
                options = (type === 'dbselect') ? {} : [];
            }
            let value = entryData[name] ?? '';

            // DATA AUTOMATICA: Se il campo è 'data_apertura' (o varianti) ed è vuoto, usa data odierna
            const nameCheck = String(name).toLowerCase().replace(/_/g, '').replace(/\s/g, ''); // Normalizza: data_apertura -> dataapertura
            if ((nameCheck === 'dataapertura' || nameCheck === 'datadiapertura' || nameCheck === 'dataaperturaesito') && !value) {
                // console.log('[DEBUG] Setting default date for:', name); 
                const now = new Date();
                const yyyy = now.getFullYear();
                const mm = String(now.getMonth() + 1).padStart(2, '0');
                const dd = String(now.getDate()).padStart(2, '0');
                value = `${yyyy}-${mm}-${dd}`;
            }

            const is_required = ['deadline', 'titolo', 'priority'].includes(String(name || '').toLowerCase());

            if (type === 'textarea') {
                const el = document.createElement('textarea');
                el.name = name;
                el.placeholder = placeholder;
                el.disabled = !isEditable; // Usa isEditable per logica botta e risposta
                el.readOnly = !isEditable;
                el.value = value;
                if (is_required && isEditable) el.required = true;
                group.appendChild(el);

            } else if (type === 'select') {
                const el = document.createElement('select');
                el.name = name;
                el.disabled = !isEditable; // Usa isEditable per logica botta e risposta
                if (is_required && isEditable) el.required = true;

                const phOpt = document.createElement('option');
                phOpt.value = '';
                phOpt.disabled = true;
                phOpt.textContent = placeholder || '-- seleziona --';
                el.appendChild(phOpt);
                const optsArr = Array.isArray(options) ? options : [];
                optsArr.forEach(opt => {
                    const o = document.createElement('option');
                    const val = (opt && typeof opt === 'object' && opt.value !== undefined) ? String(opt.value) : String(opt);
                    const txt = (opt && typeof opt === 'object' && opt.label !== undefined) ? String(opt.label) : String(opt);
                    o.value = val;
                    o.textContent = txt;
                    if (String(val).toLowerCase() === String(value).toLowerCase()) o.selected = true;
                    el.appendChild(o);
                });

                const isCustomAllowed = field.allow_custom !== false;
                let customInputMode = null;

                if (isCustomAllowed) {
                    const customContainer = document.createElement('div');
                    customContainer.className = 'custom-input-container';

                    const customInput = document.createElement('input');
                    customInput.type = 'text';
                    customInput.className = 'custom-input-field';
                    customInput.placeholder = 'Inserisci valore personalizzato...';

                    const backBtn = document.createElement('button');
                    backBtn.type = 'button';
                    backBtn.className = 'btn-back-to-list';
                    backBtn.innerHTML = '<span>🔙 Torna alla lista</span>';

                    customContainer.appendChild(customInput);
                    customContainer.appendChild(backBtn);

                    const wrapper = document.createElement('div');
                    wrapper.style.position = 'relative';
                    wrapper.appendChild(el);
                    wrapper.appendChild(customContainer);
                    group.appendChild(wrapper);

                    customInputMode = (val = '') => {
                        el.style.display = 'none';
                        el.disabled = true;

                        customContainer.style.display = 'flex';
                        customInput.value = val;
                        customInput.name = name;
                        customInput.disabled = !isEditable;
                        if (is_required && isEditable) customInput.required = true;

                        setTimeout(() => customInput.focus(), 50);
                    };

                    backBtn.onclick = () => {
                        customContainer.style.display = 'none';
                        el.style.display = 'block';
                        el.disabled = !isEditable;
                        el.name = name;
                        el.value = '';
                        customInput.name = '';
                        customInput.required = false;
                    };

                    el.addEventListener('change', () => {
                        if (el.value === '__other__') {
                            customInputMode();
                        }
                    });

                    // Option per "Altro" - Added AFTER main options
                    const otherOpt = document.createElement('option');
                    otherOpt.value = '__other__';
                    otherOpt.textContent = '➕ Altro (inserimento libero)...';
                    el.appendChild(otherOpt);

                    // Check initial value: if not in list and not empty, enable custom mode
                    if (value && value !== '' && !optsArr.some(opt => {
                        const v = (opt && typeof opt === 'object' && opt.value !== undefined) ? String(opt.value) : String(opt);
                        return v.toLowerCase() === String(value).toLowerCase();
                    })) {
                        setTimeout(() => customInputMode(value), 0);
                    }
                } else {
                    group.appendChild(el);
                }

                el.value = value;
                if (!el.value && value && !isCustomAllowed) {
                    // Fallback se valore esiste ma custom non permesso e non in lista
                    const o = document.createElement('option');
                    o.value = value;
                    o.textContent = value;
                    o.selected = true;
                    el.appendChild(o);
                }

            } else if (type === 'dbselect') {
                // Per dbselect, creiamo un componente custom con checkbox per selezione multipla
                const wrapper = document.createElement('div');
                wrapper.className = 'dbselect-wrapper';

                // Se è multiple, creiamo un custom dropdown con checkbox
                // Altrimenti usiamo un select normale
                let el, customDropdown = null;

                const normalizeSelected = (raw) => {
                    if (Array.isArray(raw)) {
                        return raw.map(v => String(v).trim()).filter(Boolean);
                    }
                    if (typeof raw === 'string') {
                        // Prova prima a parsare come JSON array (caso: "[val1,val2]")
                        if (raw.trim().startsWith('[') && raw.trim().endsWith(']')) {
                            try {
                                const parsed = JSON.parse(raw);
                                if (Array.isArray(parsed)) {
                                    return parsed.map(v => String(v).trim()).filter(Boolean);
                                }
                            } catch (e) {
                                // Se il parsing fallisce, continua con split
                            }
                        }
                        // Altrimenti split per virgola (caso: "val1,val2")
                        return raw.split(',').map(v => v.trim()).filter(Boolean).map(v => String(v));
                    }
                    if (raw === null || raw === undefined || raw === '') return [];
                    return [String(raw).trim()];
                };
                const resolveBool = (val) => {
                    if (typeof val === 'string') {
                        return ['1', 'true', 'yes', 'si'].includes(val.trim().toLowerCase());
                    }
                    return !!val;
                };

                // CORREZIONE: Per dbselect, field_options può essere un oggetto (da tabs) o una stringa JSON (da fields)
                // FUNZIONE ROBUSTA PER ESTRARRE CONFIGURAZIONE DBSELECT
                const getDeepConfig = (obj) => {
                    if (!obj || typeof obj !== 'object') return {};
                    if (obj.table || obj.Table || obj.tabella) return obj;
                    const sub = obj.datasource || obj.dataSource || obj.ds || obj.options;
                    if (sub && typeof sub === 'object' && !Array.isArray(sub)) return getDeepConfig(sub);
                    if (Array.isArray(obj) && obj.length > 0) return getDeepConfig(obj[0]);
                    return obj;
                };

                let rootCfg = {};
                try {
                    if (typeof field.field_options === 'object' && !Array.isArray(field.field_options)) {
                        rootCfg = field.field_options || {};
                    } else if (typeof field.field_options === 'string' && field.field_options.trim()) {
                        rootCfg = JSON.parse(field.field_options);
                    }
                } catch (e) {
                    console.warn('Errore parsing field_options per dbselect:', e);
                }

                let cfg = getDeepConfig(rootCfg);
                if ((!cfg.table && !cfg.valueCol && !cfg.valuecol) && options && typeof options === 'object' && !Array.isArray(options)) {
                    cfg = getDeepConfig(options);
                }
                if ((!cfg.table && !cfg.valueCol && !cfg.valuecol) && field && typeof field === 'object') {
                    cfg = getDeepConfig(field.datasource || field.options || field);
                }

                const sources = [
                    cfg,
                    rootCfg,
                    field?.datasource,
                    field?.options,
                    field
                ].filter(s => s && typeof s === 'object' && !Array.isArray(s));

                const pick = (...keys) => {
                    for (const src of sources) {
                        for (const k of keys) {
                            if (src[k] !== undefined && src[k] !== null && String(src[k]).trim() !== '') return String(src[k]);
                        }
                    }
                    return '';
                };

                // Check allow_custom in field options OR top level field property, default TRUE if undefined
                let ac_val = pick('allow_custom', 'allowCustom');
                if (ac_val === undefined || ac_val === '') {
                    ac_val = field.allow_custom; // Fallback to top level
                }
                const allow_custom = ac_val !== false && ac_val !== 'false' && ac_val !== '0' && ac_val !== 0;

                const rawMulti = cfg.multiple ?? cfg.multiselect ?? cfg.allowMultiple ?? cfg.multi ?? false;
                const isMultiple = resolveBool(rawMulti);

                const normalizedSelected = normalizeSelected(value);
                const selectedValues = isMultiple ? normalizedSelected : (normalizedSelected.length ? [normalizedSelected[0]] : []);
                const selectedSet = new Set(selectedValues.map(v => String(v)));

                if (isMultiple) {
                    // Crea un custom dropdown con checkbox per selezione multipla
                    const customSelect = document.createElement('div');
                    customSelect.className = 'custom-dbselect-multiple';
                    customSelect.setAttribute('data-tooltip', 'valori dal database');

                    const displayButton = document.createElement('button');
                    displayButton.type = 'button';
                    displayButton.className = 'custom-dbselect-button';
                    displayButton.disabled = !isEditable;
                    displayButton.innerHTML = '<span class="custom-dbselect-text">-- seleziona --</span><span class="custom-dbselect-arrow">▼</span>';

                    const dropdown = document.createElement('div');
                    dropdown.className = 'custom-dbselect-dropdown';
                    dropdown.style.display = 'none';

                    customSelect.appendChild(displayButton);
                    customSelect.appendChild(dropdown);
                    wrapper.appendChild(customSelect);

                    el = customSelect; // Usa il wrapper come riferimento
                    customDropdown = dropdown;
                } else {
                    // Select normale per selezione singola
                    el = document.createElement('select');
                    el.name = name;
                    el.disabled = !isEditable;
                    if (is_required && isEditable) el.required = true;
                    el.setAttribute('data-tooltip', 'valori dal database');
                    el.className = 'dbselect-field';

                    const placeholderOpt = document.createElement('option');
                    placeholderOpt.value = '';
                    placeholderOpt.textContent = placeholder || '-- seleziona --';
                    placeholderOpt.disabled = true; // Placeholder non selezionabile
                    el.appendChild(placeholderOpt);

                    // Opzione esplicita "Nessuno" (utile per deselezionare)
                    // Se definita in custom_none_option, o se allow_custom è falso e non è obbligatorio
                    const customNoneLabel = field.custom_none_option;
                    if (customNoneLabel || (!allow_custom && !is_required)) {
                        const noneOpt = document.createElement('option');
                        noneOpt.value = '__NULL__'; // Valore speciale per indicare "vuoto" esplicito
                        noneOpt.textContent = customNoneLabel || 'Nessuna selezione';
                        noneOpt.selected = selectedSet.size === 0; // Selezionato se vuoto
                        el.appendChild(noneOpt);
                    }

                    // Se non c'è nessuna selezione e non abbiamo aggiunto l'opzione "Nessuno" selezionata, selezioniamo placeholder
                    // Ma placeholder è disabled, quindi browser selezionerà la prima opzione valida o rimarrà vuoto.
                    // Se value è vuoto, e placeholder è disabled, browser mostra placeholder.

                    const loading = document.createElement('option');
                    loading.value = '';
                    loading.disabled = true;
                    loading.textContent = 'caricamento...';
                    el.appendChild(loading);

                    wrapper.appendChild(el);

                }




                const datasource_raw = pick('datasource', 'ds', 'dataSource', 'catalogo');
                const table_raw = pick('table', 'Table', 'TABLE', 'tabella');
                const value_col_raw = pick('valueCol', 'valuecol', 'value_col', 'value', 'val');
                const label_col_raw = pick('labelCol', 'labelcol', 'label_col', 'label', 'text');

                const datasource = (datasource_raw || '').replace(/[^a-z0-9_.]/gi, '');
                // Permetti punti nei nomi (per schema.table)
                const table = (table_raw || '').replace(/[^a-z0-9_.]/gi, '');
                const value_col = (value_col_raw || '').replace(/[^a-z0-9_.]/gi, '');
                const label_col = (label_col_raw || value_col_raw || '').replace(/[^a-z0-9_.]/gi, '');



                // Improved logic for "Altro..." (Other) mode
                let customInput = null;
                let resetCustomBtn = null;
                let enableCustomMode = null;

                // Always allow custom for single select unless explicitly disabled via cfg
                const isCustomAllowed = (allow_custom !== false && !isMultiple);

                if (isCustomAllowed) {
                    customInput = document.createElement('input');
                    customInput.type = 'text';
                    customInput.className = 'dbselect-custom-input field-input'; // Use standard classes
                    customInput.style.flex = '1';
                    customInput.placeholder = 'Inserisci valore personalizzato...';

                    resetCustomBtn = document.createElement('button');
                    resetCustomBtn.type = 'button';
                    resetCustomBtn.className = 'button btn-secondary small';
                    resetCustomBtn.innerHTML = '<span>Annulla</span>';
                    resetCustomBtn.title = 'Torna alla selezione';
                    resetCustomBtn.style.padding = '0 12px';
                    resetCustomBtn.style.flexShrink = '0';
                    resetCustomBtn.style.fontSize = '12px';

                    const customContainer = document.createElement('div');
                    customContainer.className = 'dbselect-custom-container';
                    customContainer.style.display = 'none';
                    customContainer.style.marginTop = '6px';
                    customContainer.style.alignItems = 'center';
                    customContainer.style.gap = '8px';
                    customContainer.appendChild(customInput);
                    customContainer.appendChild(resetCustomBtn);

                    wrapper.appendChild(customContainer);

                    enableCustomMode = (val = '') => {
                        el.style.display = 'none';
                        el.disabled = true;

                        customContainer.style.display = 'flex';
                        customInput.value = val;
                        customInput.name = name;
                        customInput.disabled = !isEditable;
                        if (is_required && isEditable) customInput.required = true;

                        // Scroll visually if needed or just focus
                        setTimeout(() => customInput.focus(), 50);
                    };

                    resetCustomBtn.onclick = () => {
                        el.style.display = 'block';
                        el.disabled = !isEditable;
                        el.name = name;
                        el.value = '';

                        customContainer.style.display = 'none';
                        customInput.name = '';
                        customInput.required = false;
                    };

                    el.addEventListener('change', () => {
                        if (el.value === '__other__') {
                            enableCustomMode();
                        }
                    });
                }

                // Debug: log se la configurazione è incompleta
                if (!table || !value_col) {
                    console.warn('⚠️ [dbselect] Configurazione incompleta per campo:', name, {
                        field_options: field.field_options,
                        cfg_parsed: cfg,
                        extracted: { table, value_col, label_col },
                        raw_picks: { table_raw, value_col_raw, label_col_raw }
                    });
                } else {
                    console.log('✅ [dbselect] Configurazione OK per campo:', name, {
                        table,
                        value_col,
                        label_col
                    });
                }

                // Container per il summary delle selezioni multiple
                const summaryContainer = isMultiple ? document.createElement('div') : null;
                if (summaryContainer) {
                    summaryContainer.className = 'dbselect-multi-summary-container';
                    const summary = document.createElement('div');
                    summary.className = 'dbselect-multi-summary is-empty';
                    summary.textContent = 'Nessun elemento selezionato';
                    summaryContainer.appendChild(summary);
                }

                // Per custom dropdown, mantieni traccia delle opzioni e selezioni
                let optionsData = [];
                const currentSelections = new Set(selectedValues);

                let refreshSummary = () => {
                    if (!summaryContainer || !isMultiple) return;
                    const summary = summaryContainer.querySelector('.dbselect-multi-summary');
                    if (!summary) return;

                    const chosen = Array.from(currentSelections).map(val => {
                        const opt = optionsData.find(o => String(o.value) === String(val));
                        return opt ? opt.label : val;
                    }).filter(Boolean);

                    summary.innerHTML = '';
                    if (!chosen.length) {
                        summary.classList.add('is-empty');
                        summary.textContent = 'Nessun elemento selezionato';
                        const button = wrapper.querySelector('.custom-dbselect-button .custom-dbselect-text');
                        if (button) button.textContent = '-- seleziona --';
                        return;
                    }
                    summary.classList.remove('is-empty');
                    chosen.forEach(text => {
                        const chip = document.createElement('span');
                        chip.className = 'summary-chip';
                        chip.textContent = text;
                        summary.appendChild(chip);
                    });

                    // Aggiorna il testo del bottone
                    const button = wrapper.querySelector('.custom-dbselect-button .custom-dbselect-text');
                    if (button) {
                        button.textContent = chosen.length === 1 ? chosen[0] : `${chosen.length} elementi selezionati`;
                    }
                };

                // Gestione apertura/chiusura dropdown per custom select
                if (isMultiple && customDropdown) {
                    const button = wrapper.querySelector('.custom-dbselect-button');
                    let isOpen = false;

                    button.addEventListener('click', (e) => {
                        if (button.disabled) return;
                        e.stopPropagation();
                        isOpen = !isOpen;
                        customDropdown.style.display = isOpen ? 'block' : 'none';
                        wrapper.classList.toggle('open', isOpen);
                    });

                    // Chiudi quando si clicca fuori
                    document.addEventListener('click', (e) => {
                        if (!wrapper.contains(e.target)) {
                            isOpen = false;
                            customDropdown.style.display = 'none';
                            wrapper.classList.remove('open');
                        }
                    });
                } else if (!isMultiple) {
                    // Event listener per select normale
                    const placeholderOpt = el.querySelector('option[value=""]');
                    el.addEventListener('change', () => {
                        if (placeholderOpt && placeholderOpt.selected) {
                            placeholderOpt.selected = false;
                        }
                    });
                }

                // Espone metadati per la logica a cascata
                if (datasource) wrapper.dataset.datasource = datasource;
                wrapper.dataset.table = table;
                wrapper.dataset.valueCol = value_col;
                wrapper.dataset.labelCol = label_col;
                if (!isMultiple && el) {
                    if (datasource) el.dataset.datasource = datasource;
                    el.dataset.table = table;
                    el.dataset.valueCol = value_col;
                    el.dataset.labelCol = label_col;
                }

                // Passa i filtri extra alla chiamata (per cascading selects)
                async function populateDbSelect(extraFilters = {}) {
                    if (!table || !value_col) {
                        console.warn('[DEBUG] populateDbSelect abortito: config incompleta', {
                            field_name: name,
                            table,
                            value_col,
                            cfg
                        });
                        if (!isMultiple) {
                            const loading = el.querySelector('option[value=""][disabled]');
                            if (loading) loading.textContent = 'config incompleta (tabella/colonna)';
                        }
                        refreshSummary();
                        return;
                    }

                    console.log('[DEBUG] populateDbSelect chiamata con:', {
                        table,
                        valueCol: value_col,
                        labelCol: label_col,
                        field_name: name,
                        extraFilters
                    });

                    try {
                        const payload = {
                            datasource: datasource,
                            table: table,
                            valueCol: value_col,
                            labelCol: label_col,
                            ...extraFilters // Aggiungi filtri (es. { 'azienda': 'Acme' })
                        };
                        const resp = await window.customFetch('datasource', 'getOptions', payload);

                        console.log('[DEBUG] Risposta datasource/getOptions:', resp);

                        var dbSeenSet = new Set();

                        if (!isMultiple) {
                            // Pulisci le opzioni (eccetto placeholder)
                            const placeholderOpt = el.querySelector('option[value=""][disabled]:first-child');
                            el.innerHTML = '';
                            if (placeholderOpt) el.appendChild(placeholderOpt);
                            else {
                                const p = document.createElement('option');
                                p.value = '';
                                p.textContent = placeholder || '-- seleziona --';
                                p.disabled = false; // MUST be enabled to stay selected by browser
                                if (!selectedValues.length) p.selected = true;
                                el.appendChild(p);
                            }

                            // Opzione esplicita "Nessuno" (utile per deselezionare)
                            // Se definita in custom_none_option, o se allow_custom è falso e non è obbligatorio
                            const customNoneLabel = field.custom_none_option;
                            if (customNoneLabel || (!allow_custom && !is_required)) {
                                const noneOpt = document.createElement('option');
                                noneOpt.value = '__NULL__'; // Valore speciale per indicare "vuoto" esplicito
                                noneOpt.textContent = customNoneLabel || 'Nessuna selezione';
                                // CORREZIONE: Selezionato se il set è vuoto OPPURE se contiene esplicitamente __NULL__ o stringa vuota
                                if (selectedSet.size === 0 || selectedSet.has('__NULL__') || selectedSet.has('')) {
                                    noneOpt.selected = true;
                                    dbSeenSet.add('__NULL__');
                                    dbSeenSet.add('');
                                }
                                el.appendChild(noneOpt);
                            }
                        } else if (customDropdown) {
                            customDropdown.innerHTML = '';
                        }

                        if (resp && resp.success && Array.isArray(resp.options)) {
                            // Se abbiamo filtri attivi (cascading) e c'è UN SOLO RISULTATO, auto-selezionalo!
                            // Ma solo se non ho già una selezione manuale valida
                            const shouldAutoSelect = (Object.keys(extraFilters).length > 0 && resp.options.length === 1 && selectedValues.length === 0);

                            // Mark wrapper as inferred if auto-selected, otherwise clear
                            wrapper.dataset.inferred = shouldAutoSelect ? 'true' : 'false';

                            let autoSelectedVal = null;

                            if (isMultiple && customDropdown) {
                                // Popola il custom dropdown con checkbox
                                optionsData = [];
                                resp.options.forEach(row => {
                                    const val = String(row.v);
                                    const label = String(row.l);
                                    optionsData.push({ value: val, label: label });

                                    if (shouldAutoSelect && !autoSelectedVal) autoSelectedVal = val;

                                    const optionItem = document.createElement('div');
                                    optionItem.className = 'custom-dbselect-option';

                                    const checkbox = document.createElement('input');
                                    checkbox.type = 'checkbox';
                                    checkbox.value = val;
                                    // Se auto-select o già selezionato
                                    checkbox.checked = currentSelections.has(val) || (shouldAutoSelect && val === autoSelectedVal);
                                    if (checkbox.checked && shouldAutoSelect) currentSelections.add(val);

                                    checkbox.disabled = !isEditable;

                                    const labelEl = document.createElement('label');
                                    // Gestione migliorata etichetta null per multi-select
                                    const labelText = (row.l !== null && row.l !== undefined && String(row.l).trim() !== '')
                                        ? String(row.l)
                                        : (row.v ? `ID: ${row.v} (Senza etichetta)` : '(Nessuna etichetta)');
                                    labelEl.textContent = labelText;
                                    labelEl.style.marginLeft = '8px';
                                    labelEl.style.cursor = isEditable ? 'pointer' : 'default';

                                    // Toggle selezione
                                    const toggleSelection = () => {
                                        if (!isEditable) return;
                                        if (checkbox.checked) {
                                            currentSelections.add(val);
                                        } else {
                                            currentSelections.delete(val);
                                        }
                                        refreshSummary();
                                    };

                                    checkbox.addEventListener('change', toggleSelection);
                                    if (isEditable) {
                                        labelEl.addEventListener('click', () => {
                                            checkbox.checked = !checkbox.checked;
                                            toggleSelection();
                                        });
                                    }

                                    optionItem.appendChild(checkbox);
                                    optionItem.appendChild(labelEl);
                                    customDropdown.appendChild(optionItem);
                                    dbSeenSet.add(val);
                                });

                                // CORREZIONE: Reverse Lookup anche per multi-select
                                selectedValues.forEach(val => {
                                    const sVal = String(val);
                                    if (dbSeenSet.has(sVal) || sVal === '__NULL__' || sVal === '') return;
                                    const matchByLabel = resp.options.find(row => String(row.l).toLowerCase() === sVal.toLowerCase());
                                    if (matchByLabel) {
                                        const actualId = String(matchByLabel.v);
                                        // Trova il checkbox corrispondente
                                        const cb = customDropdown.querySelector(`input[type="checkbox"][value="${actualId}"]`);
                                        if (cb) {
                                            cb.checked = true;
                                            currentSelections.add(actualId);
                                            dbSeenSet.add(sVal);
                                        }
                                    }
                                });

                                const missing = selectedValues.filter(val => !dbSeenSet.has(String(val)));
                                missing.forEach(val => {
                                    optionsData.push({ value: val, label: val });

                                    const optionItem = document.createElement('div');
                                    optionItem.className = 'custom-dbselect-option';

                                    const checkbox = document.createElement('input');
                                    checkbox.type = 'checkbox';
                                    checkbox.value = val;
                                    checkbox.checked = true;
                                    checkbox.disabled = !isEditable;

                                    const labelEl = document.createElement('label');
                                    labelEl.textContent = val;
                                    labelEl.style.marginLeft = '8px';
                                    labelEl.style.cursor = isEditable ? 'pointer' : 'default';

                                    optionItem.appendChild(checkbox);
                                    optionItem.appendChild(labelEl);

                                    checkbox.addEventListener('change', () => {
                                        if (!isEditable) return;
                                        if (checkbox.checked) {
                                            currentSelections.add(val);
                                        } else {
                                            currentSelections.delete(val);
                                        }
                                        refreshSummary();
                                    });

                                    if (isEditable) {
                                        labelEl.addEventListener('click', () => {
                                            checkbox.checked = !checkbox.checked;
                                            checkbox.dispatchEvent(new Event('change'));
                                        });
                                    }

                                    customDropdown.appendChild(optionItem);
                                });
                            } else {
                                // Popola select normale
                                resp.options.forEach(row => {
                                    const val = String(row.v);
                                    if (shouldAutoSelect && !autoSelectedVal) {
                                        autoSelectedVal = val;
                                        // Aggiorna lo stato interno e l'elemento
                                        selectedValues.push(val);
                                        selectedSet.add(val);
                                    }
                                    const opt = document.createElement('option');
                                    opt.value = String(row.v);
                                    // Gestione migliorata etichetta null
                                    opt.textContent = (row.l !== null && row.l !== undefined && String(row.l).trim() !== '')
                                        ? String(row.l)
                                        : (row.v ? `ID: ${row.v} (Senza etichetta)` : '(Nessuna etichetta)');
                                    if (selectedSet.has(String(row.v))) {
                                        opt.selected = true;
                                        dbSeenSet.add(val);
                                    }
                                    el.appendChild(opt);
                                });

                                // CORREZIONE: Se abbiamo dei valori selezionati che non sono stati trovati come ID,
                                // proviamo a vedere se corrispondono ai LABEL (es. se è stato salvato il nome invece dell'ID)
                                const stillMissing = [];
                                selectedValues.forEach(val => {
                                    const sVal = String(val);
                                    if (dbSeenSet.has(sVal) || sVal === '__NULL__' || sVal === '') return;

                                    // Cerca match per LABEL
                                    const matchByLabel = resp.options.find(row => String(row.l).toLowerCase() === sVal.toLowerCase());
                                    if (matchByLabel) {
                                        // Trovato! Seleziona l'opzione corrispondente (già aggiunta al DOM)
                                        const actualId = String(matchByLabel.v);
                                        const optToSelect = el.querySelector(`option[value="${actualId}"]`);
                                        if (optToSelect) {
                                            optToSelect.selected = true;
                                            dbSeenSet.add(sVal); // Segna questo valore come "gestito"
                                            return;
                                        }
                                    }
                                    stillMissing.push(val);
                                });

                                const missing = stillMissing.filter(val => !dbSeenSet.has(String(val)));
                                missing.forEach(val => {
                                    if (isCustomAllowed) {
                                        if (enableCustomMode) enableCustomMode(val);
                                    } else {
                                        const opt = document.createElement('option');
                                        opt.value = String(val);
                                        opt.textContent = String(val);
                                        opt.selected = true;
                                        el.appendChild(opt);
                                    }
                                });

                                // Add Other option at the VERY END for single select
                                if (isCustomAllowed) {
                                    const otherOpt = document.createElement('option');
                                    otherOpt.value = '__other__';
                                    otherOpt.textContent = '➕ Altro (inserimento libero)...';
                                    el.appendChild(otherOpt);
                                }

                                // Se abbiamo fatto auto-select su select normale
                                if (shouldAutoSelect && autoSelectedVal) {
                                    el.value = autoSelectedVal;
                                    // Trigger change per propagare la cascata
                                    setTimeout(() => el.dispatchEvent(new Event('change', { bubbles: true })), 0);
                                }
                            }
                        } else {
                            // Gestione empty
                            if (isMultiple && customDropdown) {
                                const optionItem = document.createElement('div');
                                optionItem.textContent = 'nessun risultato';
                                optionItem.style.padding = '8px';
                                optionItem.style.color = '#999';
                                customDropdown.appendChild(optionItem);
                            } else {
                                const opt = document.createElement('option');
                                opt.value = '';
                                opt.disabled = false;
                                opt.textContent = 'nessun risultato';
                                el.appendChild(opt);
                            }
                        }
                    } catch (e) {
                        console.error('dbselect populate error', e);
                        if (!isMultiple) {
                            const loading = el.querySelector('option[value=""][disabled]');
                            if (loading && loading.parentNode) loading.parentNode.removeChild(loading);
                        }
                        if (isMultiple && customDropdown) {
                            const optionItem = document.createElement('div');
                            optionItem.className = 'custom-dbselect-option';
                            optionItem.style.padding = '8px 12px';
                            optionItem.style.color = '#dc3545';
                            optionItem.textContent = 'errore caricamento';
                            customDropdown.appendChild(optionItem);
                        } else {
                            const opt = document.createElement('option');
                            opt.value = '';
                            opt.disabled = false;
                            opt.textContent = 'errore caricamento';
                            el.appendChild(opt);
                        }
                    } finally {
                        // Ensure selection is respected
                        if (!isMultiple) {
                            if (selectedValues.length && String(selectedValues[0]) !== '') {
                                el.value = selectedValues[0];
                                // If still not set (not in list), custom mode already handled by 'missing' logic
                            } else {
                                el.value = ''; // Ensure placeholder
                            }
                        }
                        refreshSummary();
                    }
                }

                // Attach methods to wrapper for external access
                wrapper.reloadOptions = populateDbSelect;
                if (!isMultiple && el) {
                    el.reloadOptions = populateDbSelect;
                }

                populateDbSelect();
                group.appendChild(wrapper);
                if (summaryContainer) {
                    group.appendChild(summaryContainer);
                    refreshSummary();
                }

                // Aggiungi input hidden per i valori selezionati nel custom dropdown (per il submit del form)
                if (isMultiple && customDropdown) {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = name + '[]';
                    hiddenInput.id = name + '_hidden';
                    wrapper.appendChild(hiddenInput);

                    // Aggiorna l'input hidden quando cambiano le selezioni
                    const updateHiddenInput = () => {
                        hiddenInput.value = Array.from(currentSelections).join(',');
                    };

                    // Sovrascrivi refreshSummary per aggiornare anche l'input hidden
                    const originalRefreshSummary = refreshSummary;
                    refreshSummary = () => {
                        originalRefreshSummary();
                        updateHiddenInput();
                    };

                    refreshSummary();
                }

            } else if (type === 'checkbox') {
                const box = document.createElement('div');
                box.classList.add('checkbox-group');
                // Gestione robusta: trim degli spazi e array vuoto se mancante
                const selected = typeof value === 'string'
                    ? value.split(',').map(v => v.trim()).filter(Boolean)
                    : (Array.isArray(value) ? value : []);

                options.forEach((opt, idx) => {
                    const w = document.createElement('label');
                    w.classList.add('checkbox-label');
                    const i = document.createElement('input');
                    i.type = 'checkbox';
                    i.name = name + '[]';
                    i.value = opt;
                    i.disabled = !isEditable; // Usa isEditable per logica botta e risposta
                    // Confronto case-insensitive e con trim
                    const optStr = String(opt).trim();
                    i.checked = selected.some(s => String(s).trim().toLowerCase() === optStr.toLowerCase());
                    if (is_required && idx === 0 && isEditable) i.required = true;
                    w.appendChild(i);
                    w.append(' ' + opt);
                    box.appendChild(w);
                });
                group.appendChild(box);

            } else if (type === 'radio') {
                const box = document.createElement('div');
                box.classList.add('radio-group');
                options.forEach(opt => {
                    const w = document.createElement('label');
                    w.classList.add('radio-label');
                    const i = document.createElement('input');
                    i.type = 'radio';
                    i.name = name;
                    i.value = opt;
                    i.disabled = !isEditable; // Usa isEditable per logica botta e risposta
                    i.checked = (opt === value);
                    w.appendChild(i);
                    w.append(' ' + opt);
                    box.appendChild(w);
                });
                group.appendChild(box);

            } else if (type === 'file') {
                const drop_zone = document.createElement('div');
                drop_zone.className = 'dropzone-upload';

                const i = document.createElement('input');
                i.type = 'file';
                i.name = name;
                i.accept = 'image/jpeg, image/png, application/pdf';
                i.style.display = 'none';
                i.disabled = !isEditable; // Usa isEditable per logica botta e risposta

                const preview = document.createElement('div');
                preview.className = 'upload-preview';

                const dz_text = document.createElement('div');
                dz_text.className = 'dropzone-text';
                dz_text.textContent = "trascina qui un file, clicca o incolla (ctrl+v)";

                const remove_btn = document.createElement('button');
                remove_btn.type = 'button';
                remove_btn.className = 'upload-remove-btn';
                remove_btn.textContent = 'rimuovi immagine';

                remove_btn.style.display = 'none'; // Nascondi di default

                const info_box = document.createElement('div');
                info_box.className = 'upload-info';
                info_box.textContent = 'formati accettati: jpg, png, pdf. max 5mb.';

                drop_zone.append(preview, dz_text, i, remove_btn, info_box);
                group.appendChild(drop_zone);

                if (entryData[name] && typeof entryData[name] === 'string' && entryData[name].length > 4) {
                    let img_src = entryData[name];
                    if (!/^https?:\/\//i.test(img_src) && img_src[0] !== '/') img_src = '/' + img_src;
                    // Se è un PDF o altro non immagine, mostra icona/link
                    if (img_src.toLowerCase().endsWith('.pdf')) {
                        preview.innerHTML = `<div style="text-align:center; padding:20px;"><br><a href="${img_src}" target="_blank" style="font-size:12px; color:#666;">Visualizza PDF</a></div>`;
                    } else {
                        preview.innerHTML = `<img src="${img_src}" class="upload-preview-img">`;
                    }
                    remove_btn.style.display = 'inline-block';
                    dz_text.style.display = 'none';
                }

                function showPreview(file) {
                    if (!file) return;
                    const ext = (file.name.split('.').pop() || '').toLowerCase();
                    if (!['jpg', 'jpeg', 'png', 'pdf'].includes(ext) && !file.type.startsWith('image/') && file.type !== 'application/pdf') {
                        preview.innerHTML = "<span style='color:#c0392b'>file non valido</span>";
                        remove_btn.style.display = 'none';
                        dz_text.style.display = 'block';
                        i.value = '';
                        return;
                    }

                    if (file.type === 'application/pdf' || ext === 'pdf') {
                        preview.innerHTML = `<div style="text-align:center; padding:20px; font-weight:bold; color:#555;"><span style="font-size:48px;">📄</span><br>${file.name}</div>`;
                        remove_btn.style.display = 'inline-block';
                        dz_text.style.display = 'none';
                    } else {
                        const reader = new FileReader();
                        reader.onload = e => {
                            preview.innerHTML = `<img src="${e.target.result}" style="max-width: 220px; max-height: 180px; border-radius:6px; display:block; margin:0 auto;">`;
                            remove_btn.style.display = 'inline-block';
                            dz_text.style.display = 'none';
                        };
                        reader.readAsDataURL(file);
                    }
                }

                // Attivo solo se isEditable
                if (isEditable) {
                    drop_zone.addEventListener('click', () => {
                        if (!i.disabled) i.click();
                    });
                }
                drop_zone.addEventListener('dragover', e => {
                    e.preventDefault();
                    drop_zone.style.background = '#e6f7ff';
                });
                drop_zone.addEventListener('dragleave', () => {
                    drop_zone.style.background = '#f9f9f9';
                });
                drop_zone.addEventListener('drop', e => {
                    e.preventDefault();
                    drop_zone.style.background = '#f9f9f9';
                    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                        i.files = e.dataTransfer.files;
                        showPreview(i.files[0]);
                    }
                });

                if (!window._pasteScreenshotHandlerAttached) {
                    window.addEventListener('paste', function (e) {
                        const items = (e.clipboardData || e.originalEvent.clipboardData).items;
                        for (let idx in items) {
                            const item = items[idx];
                            if (item.kind === 'file') {
                                const blob = item.getAsFile();
                                const file_inputs = document.querySelectorAll('input[type=file]:not([disabled])');
                                if (file_inputs.length) {
                                    const dt = new DataTransfer();
                                    dt.items.add(blob);
                                    file_inputs[0].files = dt.files;
                                    const parent_drop = file_inputs[0].closest('.dropzone-upload');
                                    if (parent_drop) {
                                        const preview_div = parent_drop.querySelector('.upload-preview');
                                        const dz_text_div = parent_drop.querySelector('div:not(.upload-preview)');
                                        const remove_btn_div = parent_drop.querySelector('.upload-remove-btn');
                                        const file = blob;
                                        const allowed = ['image/jpeg', 'image/png', 'application/pdf'];
                                        if (!allowed.includes(file.type)) {
                                            if (typeof showToast === 'function') showToast("Solo immagini JPG/PNG o PDF sono consentiti.", "error");
                                            return;
                                        }

                                        const reader = new FileReader();
                                        reader.onload = function (ev) {
                                            if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
                                                preview_div.innerHTML = `<div style="text-align:center; padding:20px; font-weight:bold; color:#555;"><span style="font-size:48px;">📄</span><br>${file.name || 'PDF Incollato'}</div>`;
                                            } else {
                                                preview_div.innerHTML = `<img src="${ev.target.result}" style="max-width: 220px; max-height: 180px; border-radius:6px; display:block; margin:0 auto;">`;
                                            }
                                            remove_btn_div.style.display = 'inline-block';
                                            dz_text_div.style.display = 'none';
                                        };
                                        reader.readAsDataURL(blob);
                                        reader.readAsDataURL(blob);
                                    }
                                }
                            }
                        }
                    });
                    window._pasteScreenshotHandlerAttached = true;
                }

                i.addEventListener('change', function () {
                    if (i.files && i.files[0]) showPreview(i.files[0]);
                });

                remove_btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    i.value = '';
                    preview.innerHTML = '';
                    remove_btn.style.display = 'none';
                    dz_text.style.display = 'block';
                });

            } else {
                const i = document.createElement('input');
                i.type = (type === 'dbselect') ? 'text' : type;
                i.name = name;
                i.placeholder = placeholder;
                i.disabled = !isEditable; // Usa isEditable per logica botta e risposta
                i.readOnly = !isEditable;

                if (type === 'date') {
                    if (entryData[name + '_raw']) {
                        i.value = entryData[name + '_raw'];
                    } else if (value && /^\d{2}\/\d{2}\/\d{4}$/.test(value)) {
                        const [d, m, y] = value.split('/');
                        i.value = `${y}-${m}-${d}`;
                    } else {
                        i.value = value;
                    }
                } else {
                    i.value = value;
                }

                if (is_required && isEditable) i.required = true;
                group.appendChild(i);
            }

            // append nel container di destinazione (può essere la grid principale o una section)
            if (target_container && typeof target_container.appendChild === 'function') {
                target_container.appendChild(group);
            }
        }

        // ================================================================
        // SHARED: parseFieldOptions — parsing robusto di field_options
        // Per select/checkbox/radio → array di opzioni
        // Per dbselect            → oggetto datasource
        // Per section             → oggetto { uid, label, children, … }
        // ================================================================
        function parseFieldOptions(raw, fieldType) {
            const ft = String(fieldType || '').toLowerCase();
            if (ft === 'dbselect') {
                // Deve restituire un oggetto (table, valueCol, labelCol, …)
                if (typeof raw === 'object' && raw !== null && !Array.isArray(raw)) return raw;
                try {
                    const parsed = (typeof raw === 'string') ? JSON.parse(raw || '{}') : {};
                    return (typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)) ? parsed : {};
                } catch { return {}; }
            }
            if (ft === 'section') {
                if (typeof raw === 'object' && raw !== null && !Array.isArray(raw)) return raw;
                try {
                    const parsed = (typeof raw === 'string') ? JSON.parse(raw || '{}') : {};
                    return (typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)) ? parsed : {};
                } catch { return {}; }
            }
            // select, checkbox, radio, ecc. → array
            if (Array.isArray(raw)) return raw;
            if (typeof raw === 'string') {
                try {
                    const parsed = JSON.parse(raw || '[]');
                    return Array.isArray(parsed) ? parsed : [];
                } catch { return []; }
            }
            if (typeof raw === 'object' && raw !== null && Array.isArray(raw.options)) return raw.options;
            return [];
        }

        // ================================================================
        // SHARED: buildSectionHierarchy — dedup + attacca figli a sezioni
        // Input:  fields[]  (flat, possono contenere sezioni e figli)
        //         opts.excludeFixed (bool, default false) — rimuove i campi fissi
        // Output: { fieldsWithSections[], uniqueMap }
        //         fieldsWithSections = sezioni (con children iniettati in field_options) + campi senza genitore
        // ================================================================
        function buildSectionHierarchy(fields, opts) {
            opts = opts || {};
            const fissiKeys = ['titolo', 'descrizione', 'deadline', 'priority', 'assegnato_a'];

            // 1) Opzionale: escludi fissi
            if (opts.excludeFixed) {
                fields = fields.filter(f => {
                    const n = String(f?.field_name || f?.name || '').toLowerCase();
                    return !fissiKeys.includes(n);
                });
            }

            // 2) Deduplicazione (field_name + parent_section_uid)
            const uniqueMap = {};
            fields = fields.filter(f => {
                const fname = String(f?.field_name || f?.name || '').toLowerCase();
                const ftype = String(f?.field_type || f?.type || '').toLowerCase();
                // Sezioni senza nome vanno sempre processate
                if (!fname || ftype === 'section') return true;
                const puid = String(f?.parent_section_uid || '').toLowerCase();
                const key = fname + '|' + puid;
                if (uniqueMap[key]) return false;
                uniqueMap[key] = true;
                return true;
            });

            // 3) Indicizza sezioni per uid
            const sectionsByUid = {};
            fields.forEach(r => {
                if (String(r.field_type || r.type || '').toLowerCase() !== 'section') return;
                const cfg = parseFieldOptions(r.field_options, 'section');
                const uid = String(cfg.uid || '').trim().toLowerCase();
                if (!uid) return;
                sectionsByUid[uid] = r;
                cfg.children = [];
                r.field_options = JSON.stringify(cfg);
            });

            // 4) Attacca figli alle sezioni
            const remaining = [];
            fields.forEach(r => {
                if (String(r.field_type || r.type || '').toLowerCase() === 'section') {
                    remaining.push(r);
                    return;
                }
                const puid = (r.parent_section_uid || '').toString().trim().toLowerCase();
                if (puid && sectionsByUid[puid]) {
                    try {
                        const child = {
                            name: (r.field_name || r.name || '').toString(),
                            type: (r.field_type || r.type || 'text').toString(),
                            options: parseFieldOptions(r.field_options, r.field_type || r.type),
                            datasource: parseFieldOptions(r.field_options, 'dbselect'),
                            colspan: (Number(r.colspan) === 2 ? 2 : 1),
                            placeholder: r.field_placeholder || '',
                            label: r.field_label || '',
                            required: r.required || false
                        };
                        const secCfg = JSON.parse(sectionsByUid[puid].field_options || '{}');
                        (secCfg.children ||= []).push(child);
                        sectionsByUid[puid].field_options = JSON.stringify(secCfg);
                    } catch (_) { }
                } else {
                    remaining.push(r);
                }
            });

            return { fieldsWithSections: remaining, uniqueMap: uniqueMap };
        }

        /**
         * Helper per renderizzare 'assegnato_a' e 'stato' nel meta-block usando le funzionalità standard (dbselect, ecc.)
         * ma con layout 'meta-row-split' invece di 'view-form-group'
         */
        function renderAssegnatoInMeta(container, entryData, isEditable) {
            // Definizione campo fake coerente con PageEditorService
            const assegnatoField = {
                field_name: 'assegnato_a',
                field_type: 'dbselect',
                field_placeholder: 'Seleziona assegnatario',
                field_options: {
                    datasource: 'personale_disponibile',
                    multiple: 0
                },
                required: 0,
                is_fixed: 1
                // field_value sarà preso da entryData['assegnato_a']
            };

            // Container temporaneo per far lavorare renderField
            const tempContainer = document.createElement('div');

            // Usa renderField standard (che popola il dbselect, gestisce eventi, ecc.)
            renderField(assegnatoField, tempContainer, isEditable);

            // Estrai l'elemento input/wrapper generato (scarta label e group wrapper)
            const generatedWrapper = tempContainer.querySelector('.view-form-group > .dbselect-wrapper, .view-form-group > select, .view-form-group > input');

            if (generatedWrapper) {
                // Crea struttura meta-row-split per Assegnato a + Stato
                const rowAssegnatoStato = document.createElement("div");
                rowAssegnatoStato.className = "meta-row meta-row-split";

                // --- Assegnato a ---
                const labelAssegnato = document.createElement("span");
                labelAssegnato.className = "label-split";
                labelAssegnato.textContent = "Assegnato a:";

                const valueAssegnato = document.createElement("span");
                valueAssegnato.className = "value-split";

                // Sposta l'elemento generato (con tutti i suoi eventi attaccati) nel value-split
                valueAssegnato.appendChild(generatedWrapper);

                // --- Stato ---
                const labelStato = document.createElement("span");
                labelStato.className = "label-split";
                labelStato.textContent = "Stato:";

                const valueStato = document.createElement("span");
                valueStato.className = "value-split";

                // Select per lo stato (stessi valori di gestione_segnalazioni)
                const selectStato = document.createElement("select");
                selectStato.name = "status_id";
                selectStato.disabled = !isEditable;

                const statiMap = { 1: 'Aperta', 2: 'In corso', 3: 'Chiusa' };
                // Usa status_id (nome colonna DB) con fallback a stato
                const statoAttuale = entryData?.status_id ?? entryData?.stato ?? '1';

                Object.entries(statiMap).forEach(([key, label]) => {
                    const opt = document.createElement("option");
                    opt.value = key;
                    opt.textContent = label;
                    if (String(key) === String(statoAttuale)) opt.selected = true;
                    selectStato.appendChild(opt);
                });
                valueStato.appendChild(selectStato);

                // Aggiungi tutti gli elementi alla riga
                rowAssegnatoStato.appendChild(labelAssegnato);
                rowAssegnatoStato.appendChild(valueAssegnato);
                rowAssegnatoStato.appendChild(labelStato);
                rowAssegnatoStato.appendChild(valueStato);

                container.appendChild(rowAssegnatoStato);
            }
        }

        // Pre-carica utenti per UserManager (utile per risolvere nomi/immagini degli assegnatari)
        if (window.UserManager) {
            window.customFetch('user', 'getAllMinified').then(resp => {
                if (resp && resp.success && Array.isArray(resp.data)) {
                    window.UserManager.populate(resp.data);
                }
            });
        }

        // UNA SOLA CHIAMATA: getForm restituisce form + tabs + fields + entry data (se record_id presente)
        let formMeta = null;
        let tabs = {};
        let fullResponse = null; // Dichiarato fuori dal try per essere accessibile dopo
        try {
            fullResponse = await window.customFetch('page_editor', 'getForm', {
                form_name: formName,
                record_id: (isEdit && recordId) ? recordId : null
            });

            if (!fullResponse || !fullResponse.success) {
                formFieldsContainer.innerHTML = `<p style="color:red;">${(fullResponse && fullResponse.message) || "Errore nel caricamento del form"}</p>`;
                return;
            }

            // DEBUG: Log dei campi per verificare field_options dei dbselect
            console.log('[DEBUG] Form response:', fullResponse);
            if (fullResponse.tabs) {
                Object.keys(fullResponse.tabs).forEach(tabName => {
                    const tab = fullResponse.tabs[tabName];
                    if (tab.fields) {
                        tab.fields.forEach(field => {
                            if (field.field_type === 'dbselect') {
                                console.log(`[DEBUG] Campo dbselect "${field.field_name}":`, {
                                    field_options: field.field_options,
                                    type: typeof field.field_options
                                });
                            }
                        });
                    }
                });
            }

            // Estrai form meta
            formMeta = fullResponse;
            // Imposta il colore del form
            const color = formMeta.form?.color || "#CCC";
            document.querySelector(".view-form-header")?.style.setProperty('--form-color', color);
            // Normalizza il nome del form sostituendo underscore con spazi
            const displayName = (formMeta.form?.name || formName).replace(/_/g, ' ');
            document.getElementById("form-title").textContent = displayName;

            // Imposta il protocollo se presente
            const protocolloEl = document.getElementById("protocollo-viewer");
            if (protocolloEl && formMeta.form?.protocollo) {
                protocolloEl.textContent = formMeta.form.protocollo;
            }

            // Aggiorna il testo del bottone submit se personalizzato
            const submitBtn = document.getElementById('form-submit-btn');
            if (submitBtn && formMeta.form?.button_text) {
                submitBtn.textContent = formMeta.form.button_text;
            }

            // Estrai tabs da getForm
            tabs = fullResponse.tabs || {};

            // Estrai entry data da getForm (se presente)
            if (isEdit && recordId && fullResponse.entry && fullResponse.entry.success && fullResponse.entry.data) {
                entryData = fullResponse.entry.data;
                // Aggiorna recordSubmittedBy per logica canEditTab
                if (entryData.submitted_by) {
                    visibilityData.recordSubmittedBy = parseInt(entryData.submitted_by) || 0;
                }
            }
        } catch (e) {
            console.warn('Errore caricamento form:', e);
            formFieldsContainer.innerHTML = `<p style="color:red;">Errore nel caricamento del form</p>`;
            return;
        }

        // Carica lo stato delle schede per questo record (se in modifica)
        let schedeStatus = {};
        if (isEdit && recordId) {
            try {
                const statusResponse = await window.customFetch('page_editor', 'getSchedeStatus', {
                    form_name: formName,
                    record_id: recordId
                });
                if (statusResponse?.success) {
                    schedeStatus = statusResponse.schede_status || {};
                }
            } catch (e) {
                console.warn('Errore caricamento stato schede:', e);
            }
        }

        // Salva lo stato schede per uso nel submit
        window.__schedeStatus = schedeStatus;
        window.__allTabs = tabs;

        // DEBUG: Log delle schede prima del filtro
        console.log('[VIEW_FORM] Schede prima del filtro:', Object.keys(tabs));
        console.log('[VIEW_FORM] Dati visibilità:', visibilityData);
        console.log('[VIEW_FORM] Stato schede:', schedeStatus);
        // DEBUG: Log dettagliato per ogni scheda (scheda_type e configurazione)
        Object.keys(tabs).forEach(tabName => {
            const tabData = tabs[tabName];
            if (tabData && !Array.isArray(tabData)) {
                console.log(`[VIEW_FORM] Scheda "${tabName}":`, {
                    scheda_type: tabData.scheda_type,
                    visibility_roles: tabData.visibility_roles,
                    edit_roles: tabData.edit_roles,
                    __visibility: tabData.__visibility
                });
            }
        });

        // Filtra le schede usando la logica con supporto workflow avanzato
        const tabsBeforeFilter = Object.keys(tabs).length;
        tabs = processTabsVisibilityJS(tabs, visibilityData, schedeStatus);

        // ESCLUSIONE SCHEDA ESITO: Su view_form (creazione/modifica record) non mostriamo mai la scheda di chiusura
        Object.keys(tabs).forEach(tabKey => {
            const tabData = tabs[tabKey];
            if (tabData && !Array.isArray(tabData) && (tabData.isClosureTab === true || tabData.isClosureTab === 1 || tabData.scheda_type === 'chiusura')) {
                delete tabs[tabKey];
            }
        });

        const tabsAfterFilter = Object.keys(tabs).length;

        // DEBUG: Log delle schede dopo il filtro
        console.log('[VIEW_FORM] Schede dopo il filtro:', Object.keys(tabs));
        console.log('[VIEW_FORM] Schede rimosse:', tabsBeforeFilter - tabsAfterFilter);

        const tabNames = Object.keys(tabs);

        // Trova la scheda iniziale (considera parametro URL 'tab' per redirect workflow)
        const urlParams = new URLSearchParams(window.location.search);
        const requestedTab = urlParams.get('tab');
        let startTab = findStartTabJS(tabs, visibilityData, schedeStatus);

        // Se c'è un tab richiesto via URL, verifica che sia accessibile
        if (requestedTab && tabs[requestedTab]) {
            const requestedTabConfig = tabs[requestedTab];
            const visibility = requestedTabConfig.__visibility;
            // Se è visibile e non disabilitato, usalo come tab iniziale
            if (visibility?.visible && !visibility?.disabled) {
                startTab = requestedTab;
            } else {
                // Se il tab richiesto non è accessibile, mostra un messaggio e usa il primo tab disponibile
                console.warn(`[VIEW_FORM] Tab "${requestedTab}" richiesto ma non accessibile - motivo: ${visibility?.reason || 'sconosciuto'}`);
                // startTab rimane quello calcolato da findStartTabJS
            }
        } else if (requestedTab && !tabs[requestedTab]) {
            // Se il tab richiesto non esiste o è stato filtrato (non visibile), mostra un messaggio
            console.warn(`[VIEW_FORM] Tab "${requestedTab}" richiesto ma non disponibile o non visibile`);
            // startTab rimane quello calcolato da findStartTabJS
        }

        // Se non ci sono schede personalizzate, usa il metodo originale
        if (tabNames.length <= 1 && tabNames.includes('Struttura')) {
            // formMeta è già stato caricato all'inizio con getForm, passa anche tabs (GIÀ FILTRATI) e fields
            // IMPORTANTE: Passa tabs (già filtrati) invece di fullResponse?.tabs (non filtrati)
            // per evitare che vengano mostrati campi di schede non visibili
            try {
                await loadFormWithoutTabs(formMeta, tabs, fullResponse?.fields || null);
            } catch (err) {
                console.error('[INIT] ERRORE in loadFormWithoutTabs:', err);
            }
            // NON fare return qui! Altrimenti il listener submit non viene attaccato!
        } else {
            // Carica le schede personalizzate (già filtrate)
            await loadFormWithTabs(tabs, startTab);
        }

        // Funzioni per gestire le schede
        async function loadFormWithTabs(tabs, startTab = null) {
            const tabsBar = document.getElementById('form-tabs-bar');
            const formFieldsContainer = document.getElementById('form-fields');
            const formEl = document.getElementById('form-invio');

            // Estrai configurazione delle schede (le schede sono già filtrate)
            const tabsConfig = {};
            const tabsFields = {};
            Object.keys(tabs).forEach(tabName => {
                const tabData = tabs[tabName];
                if (Array.isArray(tabData)) {
                    tabsFields[tabName] = tabData;
                    tabsConfig[tabName] = {
                        submit_label: null,
                        submit_action: 'submit',
                        is_disabled: false
                    };
                } else if (tabData && Array.isArray(tabData.fields)) {
                    tabsFields[tabName] = tabData.fields;
                    tabsConfig[tabName] = {
                        submit_label: tabData.submit_label || null,
                        submit_action: tabData.submit_action || 'submit',
                        is_disabled: (tabData.__disabled === true)
                    };
                } else {
                    tabsFields[tabName] = [];
                    tabsConfig[tabName] = {
                        submit_label: null,
                        submit_action: 'submit',
                        is_disabled: false
                    };
                }
            });

            // Usa la scheda iniziale passata come parametro o fallback
            const tabNames = Object.keys(tabsFields);
            let activeTab = startTab || tabNames[0] || 'Struttura';

            // Se non ci sono schede visibili, mostra un messaggio
            if (tabNames.length === 0) {
                formFieldsContainer.innerHTML = '<p style="color:red;padding:20px;">Non hai i permessi per visualizzare questo form.</p>';
                return;
            }

            // Rimuovi il bottone submit globale se esiste
            const oldSubmitBtn = formEl.querySelector('#form-submit-btn');
            if (oldSubmitBtn) oldSubmitBtn.remove();

            // Genera la barra delle schede (tutte le schede sono già visibili, filtrate da processTabsVisibilityJS)
            tabsBar.innerHTML = '';
            tabNames.forEach((tabName, index) => {
                const config = tabsConfig[tabName];
                const isDisabled = config.is_disabled || false;
                const tabData = tabs[tabName];

                // Verifica se la scheda ha restrizioni di visibilità (mostra lucchetto)
                // Una scheda ha restrizioni se non è visibile a tutti gli utenti normali
                const hasRestrictions = (() => {
                    if (!tabData || Array.isArray(tabData)) return false;

                    const visibilityRoles = tabData.visibility_roles || ['utente', 'responsabile', 'assegnatario', 'admin'];
                    const visibilityMode = tabData.visibility_mode || 'all';

                    // Se visibility_mode è 'responsabile', ha restrizioni (solo responsabile/assegnatari)
                    if (visibilityMode === 'responsabile') return true;

                    // Verifica se è la configurazione di default (tutti i ruoli = nessuna restrizione)
                    const defaultRoles = ['utente', 'responsabile', 'assegnatario', 'admin'];
                    const isDefaultConfig = (visibilityRoles.length === defaultRoles.length &&
                        defaultRoles.every(r => visibilityRoles.includes(r)) &&
                        visibilityRoles.every(r => defaultRoles.includes(r)));

                    // Se è configurazione di default, non ha restrizioni (retrocompatibilità)
                    if (isDefaultConfig) return false;

                    // Se visibility_roles non contiene 'utente', ha restrizioni
                    return !visibilityRoles.includes('utente');
                })();

                const tabButton = document.createElement('div');
                tabButton.className = 'form-tab' + (isDisabled ? ' tab-disabled' : '');

                // Crea contenuto con icona lucchetto se ha restrizioni
                const tabContent = document.createElement('span');
                tabContent.textContent = tabName;

                if (hasRestrictions) {
                    const lockIcon = document.createElement('span');
                    lockIcon.className = 'tab-lock-icon';
                    lockIcon.innerHTML = '🔒';
                    lockIcon.title = 'Questa scheda è visibile solo a responsabili e/o assegnatari';
                    tabButton.appendChild(lockIcon);
                }

                tabButton.appendChild(tabContent);
                tabButton.dataset.tab = tabName;

                if (tabName === activeTab) {
                    tabButton.classList.add('active');
                }

                // Se è disabilitata, aggiungi stile e pointer-events
                if (isDisabled) {
                    tabButton.title = 'Questa scheda diventerà disponibile dopo aver inviato la scheda precedente';
                } else if (hasRestrictions) {
                    // Se ha restrizioni ma non è disabilitata, mostra tooltip
                    tabButton.title = 'Questa scheda è visibile solo a responsabili e/o assegnatari';
                }

                tabButton.addEventListener('click', () => {
                    if (isDisabled || tabButton.classList.contains('tab-disabled')) return; // Non permettere click se disabilitata

                    // Rimuovi active da tutte le schede
                    tabsBar.querySelectorAll('.form-tab').forEach(t => t.classList.remove('active'));
                    // Aggiungi active alla scheda cliccata
                    tabButton.classList.add('active');
                    activeTab = tabName;

                    // Mostra/nascondi i campi in base alla scheda
                    formFieldsContainer.querySelectorAll('[data-tab-name]').forEach(field => {
                        if (field.dataset.tabName === tabName) {
                            field.style.display = '';
                            // Ripristina required sui campi visibili
                            field.querySelectorAll('[data-was-required="true"]').forEach(input => {
                                input.required = true;
                            });
                        } else {
                            field.style.display = 'none';
                            // Rimuovi required dai campi nascosti per evitare errori di validazione
                            field.querySelectorAll('[required]').forEach(input => {
                                input.dataset.wasRequired = 'true';
                                input.required = false;
                            });
                        }
                    });

                    // Mostra/nascondi i bottoni submit per scheda
                    formEl.querySelectorAll('[data-tab-submit-btn]').forEach(btn => {
                        btn.style.display = (btn.dataset.tabSubmitBtn === tabName) ? '' : 'none';
                    });

                    // BottomBar: aggiorna per il nuovo tab
                    if (window.BottomBar) {
                        const swCfg = tabsConfig[tabName] || {};
                        const swData = tabs[tabName];
                        const swEditable = swData && swData.__visibility
                            ? (swData.__visibility.editable !== false) : true;
                        const swIsLast = (tabName === tabNames[tabNames.length - 1]);
                        const swAction = (swIsLast && (swCfg.submit_action || 'submit') === 'next_step')
                            ? 'submit' : (swCfg.submit_action || 'submit');
                        const swGetLabel = (tn) => {
                            const tc = tabsConfig[tn];
                            if (tc && tc.submit_label && tc.submit_label.trim() !== '') return tc.submit_label.trim();
                            for (const tName of tabNames) {
                                const tC = tabsConfig[tName];
                                if (tC && tC.submit_label && tC.submit_label.trim() !== '') return tC.submit_label.trim();
                            }
                            return 'Salva';
                        };
                        window.BottomBar.setConfig({
                            actions: [{
                                id: swAction,
                                label: swGetLabel(tabName),
                                className: 'button-primary',
                                disabled: !swEditable || !hasEditableTabs
                            }]
                        });
                    }
                });

                tabsBar.appendChild(tabButton);
            });

            // Renderizza TUTTI i campi di TUTTE le schede visibili nel DOM (già filtrate)
            formFieldsContainer.innerHTML = '';
            tabNames.forEach((tabName, index) => {
                // Renderizza i campi di questa scheda direttamente in formFieldsContainer
                // ma con un attributo data-tab-name per identificarli
                const isFirstVisible = (tabName === activeTab);
                renderTabFieldsToContainer(tabName, tabsFields[tabName], isFirstVisible);
            });

            // Crea un bottone submit per ogni scheda visibile (già filtrate)
            // Il bottone deve essere attivo solo se ci sono schede editabili
            let hasEditableTabs = false;
            tabNames.forEach((tabName) => {
                const tabData = tabs[tabName];
                if (tabData && tabData.__visibility && tabData.__visibility.editable !== false) {
                    hasEditableTabs = true;
                } else if (!tabData || !tabData.__visibility) {
                    // Se non c'è visibilità, assume editabile (retrocompatibilità)
                    hasEditableTabs = true;
                }
            });

            tabNames.forEach((tabName, index) => {
                const isLastTab = (index === tabNames.length - 1);
                const tabConfig = tabsConfig[tabName] || {};
                const submitAction = tabConfig.submit_action || 'submit';
                // Se è l'ultima scheda e submitAction è 'next_step', fallback a submit
                const effectiveAction = (isLastTab && submitAction === 'next_step') ? 'submit' : submitAction;

                // Verifica se questa scheda è editabile
                const tabData = tabs[tabName];
                const tabIsEditable = tabData && tabData.__visibility ? (tabData.__visibility.editable !== false) : true;

                // Funzione di fallback per il testo del bottone
                const getButtonLabelForTab = (tn) => {
                    const tc = tabsConfig[tn];
                    if (tc && tc.submit_label && tc.submit_label.trim() !== '') {
                        return tc.submit_label.trim();
                    }
                    // Cerca la prima submitLabel non vuota tra tutte le schede
                    for (const tName of tabNames) {
                        const tC = tabsConfig[tName];
                        if (tC && tC.submit_label && tC.submit_label.trim() !== '') {
                            return tC.submit_label.trim();
                        }
                    }
                    return 'Salva';
                };

                const buttonLabel = getButtonLabelForTab(tabName);

                const submitBtn = document.createElement('button');
                submitBtn.type = (effectiveAction === 'next_step') ? 'button' : 'submit';
                submitBtn.className = 'button';
                submitBtn.id = `form-submit-btn-${tabName}`;
                submitBtn.setAttribute('data-tab-submit-btn', tabName);
                submitBtn.textContent = buttonLabel;
                submitBtn.style.display = (index === 0) ? '' : 'none'; // Mostra solo il primo

                // Disabilita il bottone se questa scheda non è editabile o se non ci sono schede editabili
                if (!tabIsEditable || !hasEditableTabs) {
                    submitBtn.disabled = true;
                    submitBtn.title = 'Non ci sono schede modificabili';
                }

                if (effectiveAction === 'next_step') {
                    submitBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        // Trova la scheda successiva
                        const nextIndex = index + 1;
                        if (nextIndex < tabNames.length) {
                            const nextTabName = tabNames[nextIndex];
                            // Simula click sul tab successivo
                            const nextTabButton = tabsBar.querySelector(`[data-tab="${nextTabName}"]`);
                            if (nextTabButton) {
                                nextTabButton.click();
                            }
                        } else {
                            // Ultima scheda: fallback a submit (non dovrebbe mai arrivare qui)
                            console.warn('Ultima scheda raggiunta, comportamento submit non implementato');
                            formEl.requestSubmit();
                        }
                    });
                }

                formEl.appendChild(submitBtn);
            });

            // BottomBar: configura per il tab attivo
            if (window.BottomBar) {
                const bbGetLabel = (tn) => {
                    const tc = tabsConfig[tn];
                    if (tc && tc.submit_label && tc.submit_label.trim() !== '') return tc.submit_label.trim();
                    for (const tName of tabNames) {
                        const tC = tabsConfig[tName];
                        if (tC && tC.submit_label && tC.submit_label.trim() !== '') return tC.submit_label.trim();
                    }
                    return 'Salva';
                };
                const bbTabConfig = tabsConfig[activeTab] || {};
                const bbTabData = tabs[activeTab];
                const bbEditable = bbTabData && bbTabData.__visibility
                    ? (bbTabData.__visibility.editable !== false) : true;
                const bbIsLast = (activeTab === tabNames[tabNames.length - 1]);
                const bbAction = (bbIsLast && (bbTabConfig.submit_action || 'submit') === 'next_step')
                    ? 'submit' : (bbTabConfig.submit_action || 'submit');

                window.BottomBar.setConfig({
                    actions: [{
                        id: bbAction,
                        label: bbGetLabel(activeTab),
                        className: 'button-primary',
                        disabled: !bbEditable || !hasEditableTabs
                    }]
                });
            }

            // Dopo aver renderizzato tutti i campi, rimuovi required dai campi nascosti
            formFieldsContainer.querySelectorAll('[data-tab-name]').forEach(field => {
                if (field.style.display === 'none') {
                    field.querySelectorAll('[required]').forEach(input => {
                        input.dataset.wasRequired = 'true';
                        input.required = false;
                    });
                }
            });
        }

        // Nuova funzione per renderizzare i campi con identificativo scheda
        function renderTabFieldsToContainer(tabName, fields, isVisible) {
            const formFieldsContainer = document.getElementById('form-fields');

            // Se è la scheda Struttura o Esito, mostra titolo e compilato da
            if (tabName === 'Struttura' || String(tabName).toLowerCase() === 'esito') {
                renderMetaFieldsWithTab(tabName, isVisible);
            }

            // Usa buildSectionHierarchy (shared) per dedup + gerarchia sezioni
            const { fieldsWithSections } = buildSectionHierarchy(fields, { excludeFixed: false });

            // Se è la scheda Struttura, escludi i campi già renderizzati nel meta block
            const fissiKeys = ['titolo', 'descrizione', 'deadline', 'priority', 'assegnato_a'];
            const fieldsToRender = tabName === 'Struttura'
                ? fieldsWithSections.filter(f => {
                    const fname = String(f.field_name || '').toLowerCase();
                    return !fissiKeys.includes(fname);
                })
                : fieldsWithSections;

            // Determina se la scheda è editabile
            const tabData = tabs[tabName];
            let tabIsEditable = true;
            if (tabData && tabData.__visibility) {
                tabIsEditable = tabData.__visibility.editable !== false;
            }

            // CORREZIONE PERMESSI: Se l'utente è il creatore del record, deve poter modificare SE NON DISABILITATO 
            // (Assumiamo che "edit" sia permesso se è il mio record, a meno che non ci siano blocchi specifici di workflow)
            // Ma __visibility.editable viene dal backend (PageEditorService). Se il backend dice "read-only",
            // forse è perché il record è chiuso o in stato avanzato.
            // Tuttavia, l'utente segnala che "se è creatore deve poter modificare".
            // Controlliamo entryData.created_by vs currentUserId
            const createdBy = entryData?.created_by || entryData?.utente_id;
            if (String(createdBy) === String(visibilityData.currentUserId)) {
                // Se sono il creatore, forzo editabile A MENO CHE non sia esplicitamente bloccato da un flag "disabled" (es. step futuri)
                // Ma attenzione: se la scheda è "chiusa" o in readonly per stato, forse non dovrei.
                // L'utente dice: "a prescindere dai permessi... deve poterlo modificare".
                if (!tabData.__visibility || tabData.__visibility.editable !== false) {
                    // Non faccio nulla, è già editabile
                } else {
                    // Se era false, lo forzo a true?
                    // Controlliamo se è una questione di ruoli. Se __visibility.reason contiene 'role', allora override sicuro.
                    tabIsEditable = true;
                }
            }

            fieldsToRender.forEach(field => {
                renderFieldWithTab(field, tabName, isVisible, tabIsEditable);
            });

            // LOGICA TAB ESITO: Aggiungi campi di chiusura se siamo nel tab Esito
            if (String(tabName).toLowerCase() === 'esito') {
                const closureFields = [
                    {
                        field_name: 'esito',
                        field_type: 'select',
                        field_label: 'Esito',
                        field_options: ['Positivo', 'Negativo', 'In attesa'],
                        required: 0,
                        colspan: 1
                    },
                    {
                        field_name: 'data_chiusura',
                        field_type: 'date',
                        field_label: 'Data Chiusura',
                        required: 0,
                        colspan: 1
                    },
                    {
                        field_name: 'note_esito',
                        field_type: 'textarea',
                        field_label: 'Note Esito',
                        required: 0,
                        colspan: 2
                    }
                ];

                closureFields.forEach(cf => {
                    // Evita duplicati se i campi esistono già
                    const exists = fieldsToRender.some(f => String(f.field_name).toLowerCase() === String(cf.field_name).toLowerCase());
                    if (!exists) {
                        // Forza isEditable true per questi campi se siamo in edit mode, 
                        // anche se il tab fosse parzialmente bloccato (purché l'utente possa salvare)
                        renderFieldWithTab(cf, tabName, isVisible, tabIsEditable);
                    }
                });
            }
        }

        // Renderizza campo con attributo data-tab-name
        function renderFieldWithTab(field, tabName, isVisible, isEditable = true) {
            const formFieldsContainer = document.getElementById('form-fields');

            // Usa la funzione renderField esistente ma sul container temporaneo
            const tempContainer = document.createElement('div');
            renderField(field, tempContainer, isEditable);

            // Prendi TUTTI i figli renderizzati (non solo il primo) e aggiungi l'attributo data-tab-name
            Array.from(tempContainer.children).forEach(renderedField => {
                renderedField.dataset.tabName = tabName;
                if (!isVisible) {
                    renderedField.style.display = 'none';
                }
                formFieldsContainer.appendChild(renderedField);
            });
        }

        // Funzione helper per creare il blocco meta (Campi fissi)
        function createMetaBlock(isEditable, compilatoInfo, tabName = null) {
            const ed = entryData || {};

            // Blocco meta (Titolo + Compilato da + Campi Fissi)
            const metaBlock = document.createElement("div");
            metaBlock.className = "form-meta-block";
            if (tabName) {
                metaBlock.style.gridColumn = "1 / -1";
                metaBlock.dataset.tabName = tabName;
            }

            // 1. Titolo
            const rowTitolo = document.createElement("div");
            rowTitolo.className = "meta-row";
            const titoloInput = document.createElement("input");
            titoloInput.type = "text";
            titoloInput.className = "input-title";
            titoloInput.name = "titolo";
            titoloInput.placeholder = "Inserisci il titolo";
            titoloInput.value = ed.titolo || '';
            titoloInput.disabled = !isEditable;
            titoloInput.readOnly = !isEditable;
            if (isEditable) titoloInput.required = true;
            rowTitolo.innerHTML = `<span class="label">Titolo:</span><span class="meta-value"></span>`;
            rowTitolo.querySelector('.meta-value').appendChild(titoloInput);
            metaBlock.appendChild(rowTitolo);

            // 2. Descrizione
            const rowDesc = document.createElement("div");
            rowDesc.className = "meta-row";
            const labelDesc = document.createElement("span");
            labelDesc.className = "label";
            labelDesc.textContent = "Descrizione:";
            const valueDesc = document.createElement("span");
            valueDesc.className = "meta-value";
            const textareaDesc = document.createElement("textarea");
            textareaDesc.name = "descrizione";
            textareaDesc.placeholder = "Inserisci la descrizione...";
            textareaDesc.disabled = !isEditable;
            textareaDesc.readOnly = !isEditable;
            if (isEditable) textareaDesc.required = true;
            textareaDesc.value = ed.descrizione || '';
            valueDesc.appendChild(textareaDesc);
            rowDesc.appendChild(labelDesc);
            rowDesc.appendChild(valueDesc);
            metaBlock.appendChild(rowDesc);

            // 3. Opening Date (Data di apertura) | Deadline (Data di scadenza)
            const rowOpeningDeadline = document.createElement("div");
            rowOpeningDeadline.className = "meta-row meta-row-split";

            // Data di apertura
            const labelOpen = document.createElement("span");
            labelOpen.className = "label-split";
            labelOpen.textContent = "Data di apertura:";
            const valueOpen = document.createElement("span");
            valueOpen.className = "value-split";
            // DATA APERTURA: Ricerca fuzzy come nel form_viewer
            let openDateText = '—';


            // Cerca chiavi normalizzate
            let foundKey = Object.keys(ed).find(k => {
                const n = k.toLowerCase().replace(/_/g, '').replace(/\s/g, '');
                return n === 'dataapertura' || n === 'datadiapertura' || n === 'datainvio';
            });

            // Priorità: Chiave trovata > created_at > data_creazione > oggi (se nuovo)
            const rawOpenDate = (foundKey ? ed[foundKey] : null) || ed.created_at || ed.data_creazione || '';

            if (rawOpenDate) {
                try {
                    const d = new Date(rawOpenDate);
                    if (!isNaN(d.getTime())) openDateText = d.toLocaleDateString('it-IT');
                    else openDateText = rawOpenDate;
                } catch (e) { openDateText = rawOpenDate; }
            } else {
                openDateText = new Date().toLocaleDateString('it-IT');
            }
            valueOpen.textContent = openDateText;

            // Deadline
            const labelDead = document.createElement("span");
            labelDead.className = "label-split";
            labelDead.textContent = "Data di scadenza:";
            const valueDead = document.createElement("span");
            valueDead.className = "value-split";
            const inputDead = document.createElement("input");
            inputDead.type = "date";
            inputDead.name = "deadline";
            inputDead.value = ed.deadline_raw || ed.deadline || '';
            inputDead.disabled = !isEditable;
            inputDead.readOnly = !isEditable;
            if (isEditable) inputDead.required = true;
            valueDead.appendChild(inputDead);

            rowOpeningDeadline.appendChild(labelOpen);
            rowOpeningDeadline.appendChild(valueOpen);
            rowOpeningDeadline.appendChild(labelDead);
            rowOpeningDeadline.appendChild(valueDead);
            metaBlock.appendChild(rowOpeningDeadline);

            // 4. Creato da | Assegnato a
            const rowCreatedAssigned = document.createElement("div");
            rowCreatedAssigned.className = "meta-row meta-row-split";

            // Creato da
            const labelCreated = document.createElement("span");
            labelCreated.className = "label-split";
            labelCreated.textContent = "Creato da:";
            const valueCreated = document.createElement("span");
            valueCreated.className = "value-split";
            if (compilatoInfo && (compilatoInfo.nome || compilatoInfo.username)) {
                const nome = compilatoInfo.nome || compilatoInfo.username || 'Utente';
                const img = compilatoInfo.img || '/assets/images/default_profile.png';
                valueCreated.innerHTML = `<img src="${img}" class="profile-image-small" style="vertical-align:middle; margin-right:5px;"> ${nome}`;
            } else if (!entryData.id && window.CURRENT_USER) {
                // Nuovo record: usa utente corrente
                const nome = window.CURRENT_USER.nominativo || window.CURRENT_USER.username || 'Utente';
                const img = window.CURRENT_USER.profile_picture || '/assets/images/default_profile.png';
                valueCreated.innerHTML = `<img src="${img}" class="profile-image-small" style="vertical-align:middle; margin-right:5px;"> ${nome}`;
            } else {
                valueCreated.textContent = '—';
            }

            rowCreatedAssigned.appendChild(labelCreated);
            rowCreatedAssigned.appendChild(valueCreated);
            metaBlock.appendChild(rowCreatedAssigned);

            // 5. Priorità | Stato
            const rowPriorityStatus = document.createElement("div");
            rowPriorityStatus.className = "meta-row meta-row-split";

            // Priorità
            const labelPrio = document.createElement("span");
            labelPrio.className = "label-split";
            labelPrio.textContent = "Priorità:";
            const valuePrio = document.createElement("span");
            valuePrio.className = "value-split";
            const selectPrio = document.createElement("select");
            selectPrio.name = "priority";
            selectPrio.disabled = !isEditable;
            if (isEditable) selectPrio.required = true;
            const prioVal = ed.priority || 'Media';
            ['Bassa', 'Media', 'Alta'].forEach(opt => {
                const o = document.createElement("option");
                o.value = opt;
                o.textContent = opt;
                if (opt === prioVal) o.selected = true;
                selectPrio.appendChild(o);
            });
            valuePrio.appendChild(selectPrio);

            // Stato
            const labelStato = document.createElement("span");
            labelStato.className = "label-split";
            labelStato.textContent = "Stato:";
            const valueStato = document.createElement("span");
            valueStato.className = "value-split";
            const selectStato = document.createElement("select");
            selectStato.name = "status_id";
            selectStato.disabled = !isEditable;
            const statiMap = { 1: 'Aperta', 2: 'In corso', 3: 'Chiusa' };
            const statoAttuale = ed.status_id ?? ed.stato ?? '1';
            Object.entries(statiMap).forEach(([key, label]) => {
                const opt = document.createElement("option");
                opt.value = key;
                opt.textContent = label;
                if (String(key) === String(statoAttuale)) opt.selected = true;
                selectStato.appendChild(opt);
            });
            valueStato.appendChild(selectStato);

            rowPriorityStatus.appendChild(labelPrio);
            rowPriorityStatus.appendChild(valuePrio);
            rowPriorityStatus.appendChild(labelStato);
            rowPriorityStatus.appendChild(valueStato);
            metaBlock.appendChild(rowPriorityStatus);

            return metaBlock;
        }

        // Nuova funzione per renderizzare i meta fields con tab
        function renderMetaFieldsWithTab(tabName, isVisible) {
            const formFieldsContainer = document.getElementById('form-fields');
            const compilatoInfo = <?= json_encode($compilatoInfo) ?>;

            // Determina se la scheda è editabile
            const tabData = tabs[tabName];
            let tabIsEditable = true;
            if (tabData && tabData.__visibility) {
                tabIsEditable = tabData.__visibility.editable !== false;
            }

            const metaBlock = createMetaBlock(tabIsEditable, compilatoInfo, tabName);

            if (!isVisible) {
                metaBlock.style.display = 'none';
            }
            formFieldsContainer.appendChild(metaBlock);

            // Divisorio dinamico DOPO il meta-block
            const lineaSeparatrice = document.createElement("hr");
            lineaSeparatrice.className = "form-title-divider dynamic-divider";
            lineaSeparatrice.style.gridColumn = "1 / -1"; // Occupa tutta la riga
            lineaSeparatrice.dataset.tabName = tabName;
            if (!isVisible) {
                lineaSeparatrice.style.display = 'none';
            }
            const formColor = document.querySelector(".view-form-header")?.style.getPropertyValue('--form-color') || '#CCC';
            lineaSeparatrice.style.setProperty('--form-color', formColor);
            metaBlock.insertAdjacentElement("afterend", lineaSeparatrice);
        }

        async function loadFormWithoutTabs(response, tabsData = null, fieldsData = null) {
            // Nascondi la barra delle schede
            document.getElementById('form-tabs-bar').style.display = 'none';

            const compilatoInfo = <?= json_encode($compilatoInfo) ?>;
            const color = response.form?.color || "#CCC";
            document.querySelector(".view-form-header")?.style.setProperty('--form-color', color);
            // Normalizza il nome del form sostituendo underscore con spazi
            const displayName = (response.form?.name || formName).replace(/_/g, ' ');
            document.getElementById("form-title").textContent = displayName;

            // Submit inline nascosto: il submit è gestito SOLO dalla BottomBar
            const submitBtn = document.getElementById('form-submit-btn');
            if (submitBtn) {
                submitBtn.style.display = 'none';
            }
            const strutturaTabData = (tabsData && tabsData['Struttura'] && !Array.isArray(tabsData['Struttura']))
                ? tabsData['Struttura'] : null;
            const strutturaSubmitLabel = (strutturaTabData && strutturaTabData.submit_label)
                ? String(strutturaTabData.submit_label).trim() : '';

            // Determina se i campi sono editabili (per schede senza tab)
            let fieldsEditable = true;
            if (tabs && Object.keys(tabs).length > 1) {
                const strutturaTab = tabs['Struttura'];
                if (strutturaTab && strutturaTab.__visibility) {
                    fieldsEditable = strutturaTab.__visibility.editable !== false;
                }
            }

            // Blocco meta (Titolo + Compilato da + Campi Fissi)
            const metaBlock = createMetaBlock(fieldsEditable, compilatoInfo);
            formEl.insertBefore(metaBlock, formEl.firstChild);

            // Divisorio DOPO il meta-block
            const lineaSeparatrice = document.createElement("hr");
            lineaSeparatrice.className = "form-title-divider dynamic-divider";
            lineaSeparatrice.style.setProperty('--form-color', color);
            metaBlock.insertAdjacentElement("afterend", lineaSeparatrice);

            // IMPORTANTE: Quando ci sono schede multiple, NON usare fieldsData
            // perché contiene tutti i campi di tutte le schede senza tab_label
            // Usa SEMPRE tabsData (già filtrati) per estrarre i campi delle schede visibili
            let fields = [];

            if (tabsData && Object.keys(tabsData).length > 0) {
                // tabsData è già filtrato (passato come tabs filtrati da processTabsVisibilityJS)
                // NON chiamare processTabsVisibilityJS di nuovo qui!
                // Estrai solo i campi delle schede visibili
                Object.keys(tabsData).forEach(tabLabel => {
                    const tabData = tabsData[tabLabel];
                    const tabFields = (Array.isArray(tabData) ? tabData : (tabData.fields || []));
                    tabFields.forEach(field => {
                        fields.push({
                            field_name: field.field_name || field.name,
                            field_type: field.field_type || field.type,
                            field_placeholder: field.field_placeholder || field.placeholder,
                            field_options: field.field_options || field.options,
                            required: field.required || 0,
                            is_fixed: field.is_fixed || 0,
                            sort_order: field.sort_order || 0,
                            colspan: field.colspan || 1,
                            parent_section_uid: field.parent_section_uid || null,
                            tab_label: tabLabel // Aggiungi tab_label per riferimento futuro
                        });
                    });
                });
            } else if (fieldsData && fieldsData.length > 0) {
                // Fallback: se tabsData non è disponibile, usa fieldsData
                // Ma questo caso non dovrebbe mai verificarsi quando ci sono schede multiple
                fields = fieldsData;
            }

            // DEBUG: Log dei campi estratti
            console.log('[loadFormWithoutTabs] Campi estratti:', fields.length, 'da', Object.keys(tabsData || {}).length, 'schede visibili');
            console.log('[loadFormWithoutTabs] Nomi schede:', Object.keys(tabsData || {}));

            // Carica i campi con la risposta corretta
            loadFieldsFromResponse({ fields: fields, strutturaSubmitLabel: strutturaSubmitLabel });
        }

        function loadFieldsFromResponse(data) {
            const allFields = Array.isArray(data.fields) ? data.fields : [];

            // Usa buildSectionHierarchy (shared) per dedup + gerarchia sezioni
            const { fieldsWithSections } = buildSectionHierarchy(allFields, { excludeFixed: true });

            // Separa dinamici e file
            const fissiKeys = ['titolo', 'descrizione', 'deadline', 'priority', 'assegnato_a'];
            const dinamici = [];
            const file = [];
            fieldsWithSections.forEach(f => {
                const n = String(f.field_name || f.name || '').toLowerCase();
                if (fissiKeys.includes(n)) return; // già nel meta-block
                if (f.field_type === 'file') {
                    file.push(f);
                } else {
                    dinamici.push(f);
                }
            });

            // Determina se i campi sono editabili
            let fieldsEditable = true;
            if (tabs && Object.keys(tabs).length > 1) {
                const strutturaTab = tabs['Struttura'];
                if (strutturaTab && strutturaTab.__visibility) {
                    fieldsEditable = strutturaTab.__visibility.editable !== false;
                }
            }

            // CORREZIONE PERMESSI (NO TABS): Se l'utente è il creatore del record, deve poter modificare
            const createdBy = entryData?.created_by || entryData?.utente_id;
            if (String(createdBy) === String(visibilityData.currentUserId)) {
                fieldsEditable = true;
            }

            // Renderizza campi dinamici
            dinamici.forEach(f => renderField(f, formFieldsContainer, fieldsEditable));
            file.forEach(f => renderField(f, formFieldsContainer, fieldsEditable));

            // Disabilita il bottone submit se non ci sono campi editabili
            const submitBtn = document.getElementById('form-submit-btn');
            if (submitBtn && !fieldsEditable) {
                submitBtn.disabled = true;
                submitBtn.title = 'Non ci sono campi modificabili';
            }

            // BottomBar: configura per form senza schede
            // Priorità: submit_label tab Struttura > button_text form > "Salva"
            if (window.BottomBar) {
                var bbLabel = (data.strutturaSubmitLabel !== undefined ? data.strutturaSubmitLabel : '')
                    || ((formMeta.form && formMeta.form.button_text) ? formMeta.form.button_text : '')
                    || 'Salva';
                window.BottomBar.setConfig({
                    actions: [{
                        id: 'submit',
                        label: bbLabel,
                        className: 'button-primary',
                        disabled: !fieldsEditable,
                        tooltip: fieldsEditable ? 'Invia il modulo' : 'Non ci sono campi modificabili'
                    }]
                });
            }
        }
        // BottomBar: handler azioni
        document.addEventListener('bottomBar:action', (e) => {
            const actionId = e.detail && e.detail.actionId;
            if (actionId === 'submit') {
                formEl.requestSubmit();
            } else if (actionId === 'next_step') {
                const tabsBar = document.getElementById('form-tabs-bar');
                if (tabsBar) {
                    const activeBtn = tabsBar.querySelector('.form-tab.active');
                    if (activeBtn) {
                        const allTabs = Array.from(tabsBar.querySelectorAll('.form-tab'));
                        const idx = allTabs.indexOf(activeBtn);
                        if (idx >= 0 && idx < allTabs.length - 1) {
                            allTabs[idx + 1].click();
                        }
                    }
                }
            }
        });

        // Gestione invio form
        formEl.addEventListener('submit', async (e) => {
            // SAFETY CHECK: Impedisci invio di valore '__other__' nei dbselect
            const dbs = formFieldsContainer.querySelectorAll('.dbselect-field');
            let blocked = false;
            dbs.forEach(sel => {
                if (sel.value === '__other__' && !sel.disabled && sel.style.display !== 'none') {
                    console.warn('Fixing dbselect stuck on __other__:', sel.name);
                    sel.dispatchEvent(new Event('change'));
                    blocked = true;
                }
            });
            if (blocked) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }

            e.preventDefault();

            // VALIDAZIONE PRE-SUBMIT: Verifica campi required nelle schede nascoste
            const tabsBar = document.getElementById('form-tabs-bar');
            let firstInvalidField = null;
            let invalidTabName = null;

            const checkedGroups = new Set(); // Evita controlli duplicati per radio/checkbox groups

            formFieldsContainer.querySelectorAll('[data-tab-name]').forEach(field => {
                if (field.style.display === 'none') {
                    // Verifica campi obbligatori nelle schede nascoste
                    const requiredFields = field.querySelectorAll('[data-was-required="true"]');
                    requiredFields.forEach(input => {
                        let isInvalid = false;

                        if (input.type === 'checkbox' || input.type === 'radio') {
                            // Per checkbox/radio, verifica se almeno uno è checked
                            const groupName = input.name;

                            // Evita di controllare lo stesso gruppo più volte
                            if (checkedGroups.has(groupName)) return;
                            checkedGroups.add(groupName);

                            const group = field.querySelectorAll(`[name="${groupName}"]`);
                            isInvalid = !Array.from(group).some(i => i.checked);
                        } else if (input.tagName === 'SELECT') {
                            // Per select, verifica se ha un valore selezionato valido
                            isInvalid = !input.value || input.value === '';
                        } else {
                            // Per altri input (text, textarea, date, ecc.)
                            isInvalid = !input.value || input.value.trim() === '';
                        }

                        if (isInvalid && !firstInvalidField) {
                            firstInvalidField = input;
                            invalidTabName = field.dataset.tabName;
                        }
                    });
                }
            });

            // Se c'è un campo invalid in una scheda nascosta, mostrala
            if (firstInvalidField && invalidTabName && tabsBar) {
                // Trova e clicca sul tab corretto
                const tabButton = Array.from(tabsBar.querySelectorAll('.form-tab'))
                    .find(btn => btn.dataset.tab === invalidTabName);
                if (tabButton) {
                    tabButton.click();
                    // Aspetta un attimo che il tab si mostri e poi fai focus
                    setTimeout(() => {
                        firstInvalidField.focus();
                        showToast(`Compila tutti i campi obbligatori nella scheda "${invalidTabName}"`, 'warning');
                    }, 100);
                    return;
                }
            }

            const submitBtn = formEl.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;
            if (window.BottomBar) window.BottomBar.updateAction('submit', { disabled: true });

            // Verifica se ci sono file da inviare PRIMA di processare i dati
            const fileInputs = Array.from(formEl.querySelectorAll('input[type="file"]'));
            const hasFiles = fileInputs.some(input => input.files && input.files.length > 0);

            // Se ci sono file, processali e comprimi le immagini prima dell'upload
            if (hasFiles) {
                const formData = new FormData(formEl);

                // Processa file immagini: comprimi se sono immagini
                for (const input of fileInputs) {
                    if (input.files && input.files.length > 0) {
                        const file = input.files[0];
                        // Se è un'immagine, comprimi (per i form sono sempre piccole e servono solo come riferimenti)
                        if (file.type && file.type.startsWith('image/') && typeof window.compressImageFile === 'function') {
                            try {
                                const compressed = await window.compressImageFile(file, {
                                    maxWidth: 800,
                                    maxHeight: 800,
                                    quality: 0.75,
                                    outputType: 'image/webp',
                                    keepName: false,
                                    outputNameSuffix: '_opt'
                                });
                                // Sostituisci il file nel FormData con quello compresso
                                formData.set(input.name, compressed, compressed.name);
                            } catch (error) {
                                console.warn('[Form] Errore compressione immagine, uso originale:', error);
                                // Usa file originale se compressione fallisce
                            }
                        }
                    }
                }

                // FIX DATE: Rimuovi le date vuote dal payload per evitare errori SQL ('' vs NULL)
                formEl.querySelectorAll('input[type="date"]').forEach(input => {
                    if (!input.value && formData.has(input.name)) {
                        formData.delete(input.name);
                    }
                });

                const action = recordId ? 'update' : 'submit';
                const response = await window.customFetch('forms', action, formData);

                if (response.success) {
                    const finalId = response.id || recordId;
                    showToast('Form salvato con successo!', 'success');
                    const sec = window.CURRENT_SECTION || new URLSearchParams(location.search).get('section') || 'gestione';
                    setTimeout(() => {
                        window.location.href = `index.php?section=${encodeURIComponent(sec)}&page=form_viewer&form_name=${encodeURIComponent(formName)}&id=${finalId}`;
                    }, 700);
                } else {
                    showToast("Errore: " + ((response && response.message) || "Invio fallito"), "error");
                    if (submitBtn) submitBtn.disabled = false;
                    if (window.BottomBar) window.BottomBar.updateAction('submit', { disabled: false });
                }
                return; // Esci subito, non processare ulteriormente
            }

            // Se non ci sono file, procedi con la logica normale (raccolta dati per schede)
            const formData = new FormData(formEl);

            // FIX DATE: Rimuovi le date vuote dal payload per evitare errori SQL ('' vs NULL)
            formEl.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value && formData.has(input.name)) {
                    formData.delete(input.name);
                }
            });

            // Rimuovi assegnato_a dal payload se l'utente non ha permessi
            if (!canEditAssegnatoA && formData.has('assegnato_a')) {
                formData.delete('assegnato_a');
            }

            // Separa i dati per scheda (LOGICA BOTTA E RISPOSTA)
            // IMPORTANTE: I dati vengono separati per scheda per garantire che:
            // - La scheda "Struttura" viene salvata con update() sul record principale
            // - Le altre schede vengono salvate come subtask con saveSubtask()
            // - Questo garantisce che i dati dell'utente non vengano sovrascritti quando
            //   il responsabile modifica solo le sue schede (che vengono salvate come subtask separate)
            // - I campi readonly (disabled) non vengono inviati nel form, quindi non vengono
            //   inclusi nei dati raccolti
            const dataByTab = {};
            const processedKeys = new Set();

            const tabElements = formFieldsContainer.querySelectorAll('[data-tab-name]');

            // Se non ci sono schede, raccogli semplicemente tutti i campi in "Struttura"
            if (tabElements.length === 0) {
                dataByTab['Struttura'] = {};

                // Raccogli tutti gli input/select/textarea nel form
                const allInputs = formEl.querySelectorAll('input, select, textarea');
                allInputs.forEach(input => {
                    const key = input.name;
                    if (!key) return;

                    if (input.type === 'checkbox') {
                        // Checkbox multipli con stesso nome
                        if (key.endsWith('[]')) {
                            const cleanKey = key.slice(0, -2);
                            if (!processedKeys.has(cleanKey)) {
                                const checked = formEl.querySelectorAll(`input[name="${key}"]:checked`);
                                const values = Array.from(checked).map(c => c.value);
                                dataByTab['Struttura'][cleanKey] = values.join(',');
                                processedKeys.add(cleanKey);
                            }
                        } else if (input.checked) {
                            dataByTab['Struttura'][key] = input.value;
                        }
                    } else if (input.type === 'radio') {
                        if (input.checked) {
                            dataByTab['Struttura'][key] = input.value;
                        }
                    } else {
                        dataByTab['Struttura'][key] = input.value;
                    }
                });

            } else {
                // Con schede: comportamento normale
                // Itera sui campi del form
                tabElements.forEach(fieldGroup => {
                    const tabName = fieldGroup.dataset.tabName;
                    if (!dataByTab[tabName]) dataByTab[tabName] = {};

                    // Trova tutti gli input/select/textarea dentro questo fieldGroup
                    const inputs = fieldGroup.querySelectorAll('input, select, textarea');

                    inputs.forEach(input => {
                        let key = input.name;
                        if (!key) return;

                        // Gestione array (checkbox con name[] o hidden input per dbselect multipli)
                        if (key.endsWith('[]')) {
                            const cleanKey = key.slice(0, -2);
                            const fullKey = `${tabName}:${cleanKey}`;

                            if (!processedKeys.has(fullKey)) {
                                // CORREZIONE: Per dbselect multipli, cerca prima l'hidden input
                                // Cerca sia nel fieldGroup che nel wrapper del dbselect (che potrebbe essere fuori dal fieldGroup)
                                let hiddenInput = fieldGroup.querySelector(`input[type="hidden"][name="${key}"]`);
                                if (!hiddenInput) {
                                    // Se non trovato nel fieldGroup, cerca nel form completo (perché il wrapper potrebbe essere fuori)
                                    hiddenInput = formEl.querySelector(`input[type="hidden"][name="${key}"]`);
                                }

                                if (hiddenInput && hiddenInput.value) {
                                    // Il valore è già una stringa separata da virgole
                                    dataByTab[tabName][cleanKey] = hiddenInput.value;
                                    console.log(`[SUBMIT] Campo ${cleanKey} trovato da hidden input:`, hiddenInput.value);
                                } else {
                                    // Fallback: raccogli tutti i checked per questo nome (checkbox normali)
                                    const allChecked = fieldGroup.querySelectorAll(`input[name="${key}"]:checked`);
                                    const values = Array.from(allChecked).map(c => c.value);
                                    dataByTab[tabName][cleanKey] = values.join(',');
                                    console.log(`[SUBMIT] Campo ${cleanKey} raccolto da checkbox:`, values.join(','));
                                }
                                processedKeys.add(fullKey);
                            }
                        } else {
                            // Campo normale
                            if (input.type === 'checkbox') {
                                if (input.checked) {
                                    dataByTab[tabName][key] = input.value;
                                }
                            } else if (input.type === 'radio') {
                                if (input.checked) {
                                    dataByTab[tabName][key] = input.value;
                                }
                            } else {
                                // NON sovrascrivere se il valore è vuoto e ne esiste già uno
                                if (input.value || !dataByTab[tabName][key]) {
                                    dataByTab[tabName][key] = input.value;
                                }
                            }
                        }
                    });
                });
            } // Fine if/else schede

            // Aggiungi anche i campi "sciolti" (senza data-tab-name, es. form_name, record_id)
            for (const [key, value] of formData.entries()) {
                if (key === 'form_name' || key === 'record_id') {
                    // Questi vanno nel payload principale
                    if (!dataByTab['Struttura']) dataByTab['Struttura'] = {};
                    dataByTab['Struttura'][key] = value;
                }
            }

            try {
                // 1. Salva prima il record principale (scheda Struttura)
                // IMPORTANTE: Solo i dati della scheda "Struttura" vengono salvati nel record principale.
                // Le altre schede vengono salvate come subtask separate, garantendo che i dati
                // dell'utente non vengano sovrascritti quando il responsabile modifica solo le sue schede.
                const mainData = dataByTab['Struttura'] || {};

                // VERIFICA: customFetch esiste?
                if (!window.customFetch) {
                    console.error('[SUBMIT] ERRORE: window.customFetch non è definito!');
                    showToast('Errore: sistema di comunicazione non disponibile', 'error');
                    if (submitBtn) submitBtn.disabled = false;
                    if (window.BottomBar) window.BottomBar.updateAction('submit', { disabled: false });
                    return;
                }

                // Se siamo in edit mode, usa update invece di submit
                const action = recordId ? 'update' : 'submit';

                // Usa oggetto normale (non ci sono file, altrimenti saremmo usciti prima)
                // NOTA: mainData contiene solo i campi della scheda "Struttura" che sono editabili
                // (i campi readonly non vengono inviati nel form, quindi non sono in mainData)
                const payload = {
                    form_name: formName,
                    record_id: recordId || undefined,
                    ...mainData
                };

                // Rimuovi assegnato_a dal payload se l'utente non ha permessi o se è vuoto (per evitare errori SQL su INT)
                if ((!canEditAssegnatoA && 'assegnato_a' in payload) || (payload.assegnato_a === '') || (payload.assegnato_a === '__NULL__')) {
                    delete payload.assegnato_a;
                }
                // Stessa cosa per created_by se presente e vuoto
                if (payload.created_by === '' || payload.created_by === '__NULL__') {
                    delete payload.created_by;
                }

                const response = await window.customFetch('forms', action, payload);

                if (response.success) {
                    const finalId = response.id || recordId;

                    // 2. Salva le altre schede come subtask (LOGICA BOTTA E RISPOSTA)
                    // IMPORTANTE: Le schede diverse da "Struttura" vengono salvate come subtask separate.
                    // Questo garantisce che i dati dell'utente (nella scheda "Struttura" o in altre schede)
                    // non vengano sovrascritti quando il responsabile modifica solo le sue schede.
                    // Ogni scheda ha la sua subtask con scheda_data JSON, quindi i dati sono isolati.

                    // CORREZIONE: Filtra solo le schede EDITABILI dall'utente corrente
                    // Per evitare di salvare (e marcare come submitted) schede che l'utente non può modificare
                    const otherTabs = Object.keys(dataByTab).filter(t => {
                        if (t === 'Struttura') return false;
                        // Verifica se la scheda è editabile dall'utente corrente
                        const tabConfig = tabs[t];
                        if (tabConfig && tabConfig.__visibility) {
                            return tabConfig.__visibility.editable !== false;
                        }
                        return true; // Retrocompatibilità
                    });

                    if (otherTabs.length > 0) {
                        // Salva ogni scheda come subtask
                        for (const tabName of otherTabs) {
                            const tabData = dataByTab[tabName];
                            if (!tabData || Object.keys(tabData).length === 0) {
                                continue;
                            }

                            await window.customFetch('forms', 'saveSubtask', {
                                form_name: formName,
                                parent_record_id: finalId,
                                scheda_label: tabName,
                                scheda_data: tabData
                            });
                        }

                        showToast('Form e schede salvate con successo!', 'success');
                    } else {
                        showToast('Form salvato con successo!', 'success');
                    }

                    // GESTIONE SEPARATA PER SCHEDA: Lo stato di ogni scheda viene aggiornato automaticamente
                    // in form_schede_status da FormsDataService::submit, update e saveSubtask.
                    // Ogni scheda ha il suo stato indipendente, quindi l'utente può compila re le sue schede
                    // finché non le ha submitte singolarmente, e il responsabile può sempre compilare le sue.

                    // 4. Verifica se c'è redirect_after_submit configurato
                    // FASE 3 - MVP: Determina la scheda corrente dal contesto (prima scheda con dati inviati)
                    const allTabs = window.__allTabs || {};
                    const submittedTabNames = Object.keys(dataByTab);
                    const currentTabName = submittedTabNames.length > 0 ? submittedTabNames[0] : 'Struttura';
                    const currentTabConfig = allTabs[currentTabName] || {};
                    const shouldRedirectToNext = currentTabConfig.redirect_after_submit === true;

                    if (shouldRedirectToNext && finalId) {
                        // Aggiorna lo stato locale per il calcolo della prossima scheda
                        const updatedSchedeStatus = { ...(window.__schedeStatus || {}) };
                        updatedSchedeStatus[currentTabName.toLowerCase()] = { status: 'submitted' };

                        // Calcola la prossima scheda disponibile
                        const nextTab = findNextAvailableTabJS(currentTabName, allTabs, visibilityData, updatedSchedeStatus);

                        if (nextTab) {
                            // Redirect alla prossima scheda (modifica del record)
                            const sec = window.CURRENT_SECTION || new URLSearchParams(location.search).get('section') || 'gestione';
                            setTimeout(() => {
                                window.location.href = `index.php?section=${encodeURIComponent(sec)}&page=view_form&form_name=${encodeURIComponent(formName)}&id=${finalId}&edit=1&tab=${encodeURIComponent(nextTab)}`;
                            }, 500);
                            return;
                        } else {
                            // Workflow completato - vai al viewer
                            showToast('Workflow completato!', 'success');
                        }
                    }

                    // Redirect di default: vai al form_viewer
                    const sec = window.CURRENT_SECTION || new URLSearchParams(location.search).get('section') || 'gestione';
                    setTimeout(() => {
                        window.location.href = `index.php?section=${encodeURIComponent(sec)}&page=form_viewer&form_name=${encodeURIComponent(formName)}&id=${finalId}`;
                    }, 700);
                } else {
                    showToast("Errore: " + ((response && response.message) || "Invio fallito"), "error");
                    if (submitBtn) submitBtn.disabled = false;
                    if (window.BottomBar) window.BottomBar.updateAction('submit', { disabled: false });
                }
            } catch (error) {
                console.error('Errore invio form:', error);
                showToast('Errore durante l\'invio del form', 'error');
                if (submitBtn) submitBtn.disabled = false;
                if (window.BottomBar) window.BottomBar.updateAction('submit', { disabled: false });
            }
        });

    });
</script>