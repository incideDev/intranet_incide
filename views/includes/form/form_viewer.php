<?php
$__sec_raw = $_GET['section'] ?? '';
$__sec = preg_replace('/[^a-z_]/i', '', strtolower((string) $__sec_raw));

if (
    $__sec === 'collaborazione' && !checkPermissionOrWarn('view_segnalazioni')
) {
    return;
}

enforceFormVisibilityOrRedirect();

$formName_raw = $_GET['form_name'] ?? null;
$formName = $formName_raw ? preg_replace('/[^\w\s\-àèéùòì]/u', '', strtolower(trim((string) $formName_raw))) : null;

$recordId_raw = $_GET['id'] ?? null;
$recordId = is_numeric($recordId_raw) ? (int) $recordId_raw : null;

$initialTab_raw = $_GET['tab'] ?? null;
$initialTab = $initialTab_raw ? preg_replace('/[^\w\s\-àèéùòì]/u', '', trim((string) $initialTab_raw)) : null;

if (!$formName || $recordId <= 0) {
    echo "<p> parametri mancanti o non validi per visualizzare il form.</p>";
    return;
}

// Ottieni informazioni di chi ha compilato il form
$compilato_info = \Services\PageEditorService::getCompilatoInfo($formName, $recordId);

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
$currentRoleId = (int) ($_SESSION['user']['role_id'] ?? $_SESSION['role_id'] ?? 0);
$isAdmin = ($currentRoleId === 1);
$isResponsabile = $formInfo && ((int) ($formInfo['responsabile'] ?? 0) === $currentUserId);
$canViewAssegnatoA = $isAdmin || $isResponsabile; // Opzione: mostrare sempre o solo ad admin/responsabile

// Recupera assegnatari dal record
$formAssegnatariIds = [];
if ($recordId > 0) {
    try {
        global $database;
        $form = $database->query("SELECT id, table_name FROM forms WHERE name=:n LIMIT 1", [':n' => $formName], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        if ($form) {
            $table = $form['table_name'];

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
                    $ids = array_map('trim', explode(',', $assegnatoValue));
                    foreach ($ids as $id) {
                        if (is_numeric($id) && (int) $id > 0) {
                            $formAssegnatariIds[] = (int) $id;
                        }
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        // Ignora errori
    }
}
?>

<link rel="stylesheet" href="/assets/css/form.css">
<link rel="stylesheet" href="/assets/css/global_drawer.css">
<script src="/assets/js/modules/user_manager.js"></script>
<script src="/assets/js/form_tabs_common.js"></script>
<script>

    document.addEventListener('DOMContentLoaded', function () {
        var meta = document.querySelector('meta[name="token-csrf"]');
        if (!meta) {
            console.warn('csrf token mancante nei meta: aggiungilo per sicurezza ajax.');
            if (typeof showToast === 'function') showToast('attenzione: csrf token mancante nei meta.', 'error');
        }
        // export sezione per fix breadcrumb / redirect coerenti
        window.current_section = <?= json_encode($__sec) ?>;
    });
</script>

<div class="main-container">
    <div class="pagina-foglio">
        <div class="view-form-header" style="--form-color:#ccc;">
            <div class="form-header-row">
                <h1 id="form-title"><?= htmlspecialchars($formName) ?></h1>
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
                    <div class="view-form-grid" id="readonly-form">
                        <!-- I campi disabilitati verranno iniettati qui via JS -->
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        window.current_user = {
            id: <?= json_encode($_SESSION['user_id'] ?? null) ?>,
            role_id: <?= json_encode($_SESSION['role_id'] ?? null) ?>
        };
    </script>

    <script>
        document.addEventListener("DOMContentLoaded", async function () {
            // Pre-carica utenti per UserManager (utile per risolvere nomi/immagini degli assegnatari)
            if (window.UserManager) {
                try {
                    const resp = await window.customFetch('forms', 'getUtentiList');
                    if (resp && resp.success && Array.isArray(resp.data)) {
                        window.UserManager.populate(resp.data);
                    }
                } catch (e) {
                    console.warn('Errore pre-caricamento utenti:', e);
                }
            }
            document.addEventListener('change', function (e) {
                if (e.target.type === 'file') {
                    const file = e.target.files[0];
                    if (!file) return;

                    const allowed = ['image/jpeg', 'image/png', 'application/pdf'];
                    if (!allowed.includes(file.type)) {
                        showToast("Solo immagini JPG/PNG o PDF sono consentiti.", "error");
                        e.target.value = "";
                        return;
                    }

                    if (file.size > 5 * 1024 * 1024) {
                        showToast("File troppo pesante. Limite 5MB.", "error");
                        e.target.value = "";
                        return;
                    }
                }
            });

            window.compiled = null;

            const formName = <?= json_encode($formName) ?>;
            const recordId = <?= json_encode($recordId) ?>;
            const initialTabParam = <?= json_encode($initialTab ?? '') ?>;
            window.APP_DEBUG = <?= json_encode(defined('APP_DEBUG') && APP_DEBUG) ?>;
            const container = document.getElementById("readonly-form");

            // UNA SOLA CHIAMATA: getForm restituisce form + tabs + fields + entry data (se record_id presente)
            const fullResponse = await customFetch('page_editor', 'getForm', {
                form_name: formName,
                record_id: recordId || null
            });

            if (!fullResponse || !fullResponse.success) {
                container.innerHTML = `<p class="error">${fullResponse?.message || "Errore nel caricamento del form"}</p>`;
                return;
            }

            // Estrai form meta e tabs da getForm
            const formMeta = fullResponse;
            // Imposta il colore del form
            const color = formMeta.form?.color || "#CCC";
            document.querySelector(".view-form-header")?.style.setProperty('--form-color', color);
            document.getElementById("form-title").textContent = formMeta.form?.name || formName;

            const tabsResponse = {
                success: true,
                tabs: fullResponse.tabs || {}
            };

            // Estrai entry data da getForm (se presente)
            const entryResponse = fullResponse.entry || null;
            if (!entryResponse || !entryResponse.success || !entryResponse.data || !Array.isArray(entryResponse.fields)) {
                container.innerHTML = `<p class="error"> ${entryResponse?.error || entryResponse?.message || "Errore nel caricamento del record"}</p>`;
                return;
            }

            window.compiled = entryResponse.data;
            const compiled = window.compiled;
            window.data = entryResponse;

            if (window.APP_DEBUG) {
                console.log('[form_viewer] entryResponse.data (compiled):', JSON.parse(JSON.stringify(compiled)));
                console.log('[form_viewer] entryResponse.fields count:', (entryResponse.fields || []).length);
                console.log('[form_viewer] tabs from getForm:', Object.keys(fullResponse.tabs || {}));
            }

            // Imposta il protocollo se presente
            const protocolloEl = document.getElementById("protocollo-viewer");
            if (protocolloEl && compiled.codice_segnalazione) {
                protocolloEl.textContent = compiled.codice_segnalazione;
            }

            // Dati per calcolo visibilità schede
            const visibilityData = {
                currentUserId: <?= json_encode($currentUserId) ?>,
                currentRoleId: <?= json_encode($currentRoleId) ?>,
                formResponsabileId: <?= json_encode($formInfo['responsabile'] ?? 0) ?>,
                formAssegnatariIds: <?= json_encode($formAssegnatariIds) ?>,
                isNewRecord: false // In form_viewer siamo sempre in modalità visualizzazione record esistente
            };

            // Carica lo stato delle schede per questo record
            let schedeStatus = {};
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

            // Se abbiamo schede personalizzate, usale
            if (tabsResponse && tabsResponse.success && tabsResponse.tabs) {
                let tabs = tabsResponse.tabs;
                const tabNames = Object.keys(tabs);

                if (tabNames.length > 1 || !tabNames.includes('Struttura')) {
                    // Filtra le schede usando la logica con supporto workflow avanzato
                    tabs = processTabsVisibilityJS(tabs, visibilityData, schedeStatus);

                    // HARD RULE form_viewer: Struttura è SEMPRE readonly per tutti (anche admin)
                    Object.keys(tabs).forEach(tName => {
                        const tData = tabs[tName];
                        const tKey = (tData && tData.tab_key) ? tData.tab_key : tName.toLowerCase();
                        if (tKey === 'struttura' || tName === 'Struttura') {
                            if (tData && tData.__visibility) {
                                tData.__visibility.editable = false;
                                tData.__visibility.reason = 'viewer_struttura_always_readonly';
                            }
                        }
                    });

                    // Override Esito: editabile per admin/responsabile/assegnatario
                    const isAdminUser = (visibilityData.currentRoleId === 1);
                    const isResponsabileUser = (String(visibilityData.currentUserId) === String(visibilityData.formResponsabileId));
                    const isAssegnatarioUser = Array.isArray(visibilityData.formAssegnatariIds) && visibilityData.formAssegnatariIds.some(id => String(id) === String(visibilityData.currentUserId));
                    const canManageEsito = isAdminUser || isResponsabileUser || isAssegnatarioUser;

                    Object.keys(tabs).forEach(tName => {
                        const tData = tabs[tName];
                        const tKey = (tData && tData.tab_key) ? tData.tab_key : tName.toLowerCase();
                        if ((tKey === 'esito' || tName === 'Esito') && tData && tData.__visibility) {
                            if (canManageEsito) {
                                tData.__visibility.editable = true;
                                tData.__visibility.reason = 'viewer_esito_always_editable_per_auth';
                            }
                        }
                    });

                    if (window.APP_DEBUG) {
                        console.log('[form_viewer] tabs dopo processamento visibilità:', Object.keys(tabs).map(n => {
                            const v = tabs[n]?.__visibility || {};
                            return { tab: n, visible: v.visible, editable: v.editable, reason: v.reason };
                        }));
                    }

                    // Trova la scheda iniziale (hash #esito o parametro ?tab=Esito hanno priorità)
                    let startTab = findStartTabJS(tabs, visibilityData, schedeStatus);
                    const initialTabFromHash = (location.hash && location.hash.length > 1) ? location.hash.replace(/^#/, '').trim() : '';
                    const requestedTab = initialTabFromHash || initialTabParam || '';
                    if (requestedTab) {
                        const tabKeys = Object.keys(tabs);
                        const paramLower = String(requestedTab).toLowerCase();
                        const matchedTab = tabKeys.find(t => {
                            const labelLower = String(t).toLowerCase();
                            if (labelLower === paramLower) return true;
                            const tabData = tabs[t];
                            const tabKey = (tabData && tabData.tab_key) ? String(tabData.tab_key).toLowerCase() : '';
                            return (tabKey && tabKey === paramLower);
                        });
                        if (matchedTab) {
                            startTab = matchedTab;
                        }
                    }

                    await loadFormWithTabs(tabs, startTab);
                    return;
                }
            }

            // Altrimenti usa il metodo originale senza schede
            await loadFormWithoutTabs();

            // Funzioni per gestire le schede
            async function loadFormWithTabs(tabs, startTab = null) {
                const tabsBar = document.getElementById('form-tabs-bar');
                const container = document.getElementById('readonly-form');

                // Nascondi la barra delle schede se c'è solo una scheda visibile (es. Esito nascosto → solo Struttura)
                // NON usare loadFormWithoutTabs: quel percorso carica TUTTI i campi (Struttura+Esito) mescolati.
                // Dobbiamo sempre usare i campi della singola scheda visibile.
                if (Object.keys(tabs).length <= 1) {
                    tabsBar.style.display = 'none';
                }

                // Estrai array di campi e visibilità da ogni scheda (già filtrate)
                const tabsFields = {};
                const tabsVisibility = {};
                Object.keys(tabs).forEach(tabName => {
                    const tabData = tabs[tabName];
                    if (Array.isArray(tabData)) {
                        tabsFields[tabName] = tabData;
                        tabsVisibility[tabName] = { visible: true, editable: false };
                    } else if (tabData && Array.isArray(tabData.fields)) {
                        tabsFields[tabName] = tabData.fields;
                        tabsVisibility[tabName] = tabData.__visibility || { visible: true, editable: false };
                    } else {
                        tabsFields[tabName] = [];
                        tabsVisibility[tabName] = tabData?.__visibility || { visible: true, editable: false };
                    }
                });

                // Usa la scheda iniziale passata come parametro o fallback
                const tabNames = Object.keys(tabsFields);
                let activeTab = startTab || tabNames[0] || 'Struttura';

                const isAdminUser = (visibilityData.currentRoleId === 1);
                const isResponsabileUser = (String(visibilityData.currentUserId) === String(visibilityData.formResponsabileId));
                const isAssegnatarioUser = Array.isArray(visibilityData.formAssegnatariIds) && visibilityData.formAssegnatariIds.some(id => String(id) === String(visibilityData.currentUserId));
                const canManageEsito = isAdminUser || isResponsabileUser || isAssegnatarioUser;
                tabNames.forEach(tName => {
                    if ((String(tName).toLowerCase() === 'esito') && canManageEsito && tabsVisibility[tName]) {
                        tabsVisibility[tName].editable = true;
                    }
                });

                // Se non ci sono schede visibili, mostra un messaggio
                if (tabNames.length === 0) {
                    container.innerHTML = '<p style="color:red;padding:20px;">Non hai i permessi per visualizzare questo form.</p>';
                    return;
                }

                // Genera la barra delle schede (tutte le schede sono già visibili, filtrate da processTabsVisibilityJS)
                tabsBar.innerHTML = '';
                tabNames.forEach((tabName, index) => {
                    const tabButton = document.createElement('div');
                    tabButton.className = 'form-tab';
                    const vis = tabsVisibility[tabName] || {};
                    // Mostra lucchetto se locked/disabled
                    if (vis.disabled || vis.locked) {
                        tabButton.innerHTML = tabName + ' <span style="opacity:.5">🔒</span>';
                    } else {
                        tabButton.textContent = tabName;
                    }
                    tabButton.dataset.tab = tabName;

                    if (tabName === activeTab) {
                        tabButton.classList.add('active');
                    }

                    // Se tab è locked/disabled, non permettere il click
                    if (vis.disabled || vis.locked) {
                        tabButton.style.opacity = '0.5';
                        tabButton.style.cursor = 'not-allowed';
                    } else {
                        tabButton.addEventListener('click', () => {
                            tabsBar.querySelectorAll('.form-tab').forEach(t => t.classList.remove('active'));
                            tabButton.classList.add('active');
                            activeTab = tabName;
                            showTabFields(tabName, tabsFields[tabName], tabsVisibility[tabName]);
                        });
                    }

                    tabsBar.appendChild(tabButton);
                });

                // Mostra i campi della prima scheda visibile
                if (tabNames.length > 0) {
                    showTabFields(activeTab, tabsFields[activeTab], tabsVisibility[activeTab]);
                }
            }

            function showTabFields(tabName, fields, visibility = {}) {
                const container = document.getElementById('readonly-form');
                container.innerHTML = '';
                let isEditable = !!(visibility && visibility.editable);
                if (String(tabName).toLowerCase() === 'esito') {
                    const isAdminUser = (visibilityData.currentRoleId === 1);
                    const isResponsabileUser = (String(visibilityData.currentUserId) === String(visibilityData.formResponsabileId));
                    const isAssegnatarioUser = Array.isArray(visibilityData.formAssegnatariIds) && visibilityData.formAssegnatariIds.some(id => String(id) === String(visibilityData.currentUserId));
                    const canManageEsito = isAdminUser || isResponsabileUser || isAssegnatarioUser;
                    if (canManageEsito) {
                        isEditable = true;
                    }
                }
                container.style.pointerEvents = 'auto';
                container.classList.remove('form-editable');
                if (isEditable) {
                    container.classList.add('form-editable');
                }

                // Se è la scheda Struttura, mostra i metadati
                if (tabName === 'Struttura') {
                    showMetaFields(isEditable);
                }

                // Se è la scheda Esito, mostra form-meta-block in cima (Titolo, Descrizione, Compilato da, campi Esito-specifici)
                if (String(tabName).toLowerCase() === 'esito') {
                    showEsitoMetaBlock(container, isEditable);
                }

                // --- aggancia i figli alle sezioni usando parent_section_uid ---
                const sectionsByUid = {};
                fields.forEach(r => {
                    if (String(r.field_type || '').toLowerCase() !== 'section') return;
                    let cfg = {};
                    try {
                        cfg = typeof r.field_options === 'string' ? (JSON.parse(r.field_options || '{}') || {}) : (r.field_options || {});
                    } catch (_) {
                        cfg = {};
                    }
                    const uid = String(cfg.uid || '').trim().toLowerCase();
                    if (!uid) return;
                    sectionsByUid[uid] = r;
                    cfg.children = [];
                    r.field_options = JSON.stringify(cfg);
                });

                const remaining = [];
                fields.forEach(r => {
                    if (String(r.field_type || '').toLowerCase() === 'section') {
                        remaining.push(r);
                        return;
                    }
                    const puid = (r.parent_section_uid || '').toString().trim().toLowerCase();
                    if (puid && sectionsByUid[puid]) {
                        try {
                            const child = {
                                name: (r.field_name || '').toString(),
                                type: (r.field_type || 'text').toString(),
                                options: (() => {
                                    try {
                                        const x = JSON.parse(r.field_options || '[]');
                                        return Array.isArray(x) ? x : [];
                                    } catch {
                                        return [];
                                    }
                                })(),
                                datasource: (() => {
                                    try {
                                        const x = JSON.parse(r.field_options || '{}');
                                        return (typeof x === 'object' && x && !Array.isArray(x)) ? x : {};
                                    } catch {
                                        return {};
                                    }
                                })(),
                                colspan: (Number(r.colspan) === 2 ? 2 : 1)
                            };
                            const secCfg = JSON.parse(sectionsByUid[puid].field_options || '{}');
                            (secCfg.children ||= []).push(child);
                            sectionsByUid[puid].field_options = JSON.stringify(secCfg);
                        } catch (_) { }
                    } else {
                        remaining.push(r);
                    }
                });

                const fieldsWithSections = remaining;

                // Renderizza i campi: editable o readonly in base a __visibility
                fieldsWithSections.forEach(field => {
                    render_readonly_field(field, container, isEditable);
                });

                // Blocco chiusura fisso (solo tab Esito): sotto i campi custom, sopra il bottone submit
                if (String(tabName).toLowerCase() === 'esito') {
                    showClosureFixedBlock(container, isEditable);
                }

                // Se la tab è editabile, aggiungi bottone di submit (Esito ha campi in meta-block e closure-block, non in fields)
                if (isEditable && (fields.length > 0 || String(tabName).toLowerCase() === 'esito')) {
                    const btnRow = document.createElement('div');
                    btnRow.style.cssText = 'grid-column:1/-1;display:flex;justify-content:flex-end;margin-top:1rem;gap:0.5rem;';

                    const btnSubmit = document.createElement('button');
                    btnSubmit.type = 'button';
                    btnSubmit.className = 'btn btn-primary';
                    btnSubmit.textContent = 'Salva ' + tabName;
                    btnSubmit.addEventListener('click', () => submitEditableTab(tabName, container));
                    btnRow.appendChild(btnSubmit);

                    container.appendChild(btnRow);
                }

                if (isEditable) {
                    const enableInputs = () => {
                        container.querySelectorAll('input, select, textarea, button[type="button"]').forEach(el => {
                            el.removeAttribute('disabled');
                            el.removeAttribute('readonly');
                            el.disabled = false;
                            el.readOnly = false;
                        });
                    };
                    enableInputs();
                    requestAnimationFrame(() => enableInputs());
                    setTimeout(enableInputs, 100);
                }

                // Batch resolve dbselect readonly labels
                flushDbselectResolveQueue();
            }

            // Funzione batch per risolvere le label dei dbselect readonly
            async function flushDbselectResolveQueue() {
                const queue = window._dbselectResolveQueue || [];
                window._dbselectResolveQueue = [];
                if (!queue.length) return;

                const batchFields = queue.map(item => ({
                    table: item.table,
                    valueCol: item.valueCol,
                    labelCol: item.labelCol,
                    value: item.value
                }));

                try {
                    const resp = await customFetch('datasource', 'resolveDbselectValues', { fields: batchFields });
                    if (resp && resp.success && resp.resolved) {
                        queue.forEach((item, idx) => {
                            const label = resp.resolved[String(idx)];
                            if (label && item.el) {
                                item.el.textContent = label;
                            }
                        });
                    }
                } catch (e) {
                    if (window.APP_DEBUG) {
                        console.warn('[flushDbselectResolveQueue] error:', e);
                    }
                }
            }

            // Salva i dati di una scheda editabile (es. Esito)
            async function submitEditableTab(tabName, container) {
                const formName = <?= json_encode($formName) ?>;
                const recordId = <?= json_encode($recordId ?? 0) ?>;
                const inputs = container.querySelectorAll('[name]');

                // Controlla se ci sono file da inviare
                const fileInputs = container.querySelectorAll('input[type="file"][name]');
                let hasFiles = false;
                fileInputs.forEach(fi => {
                    if (fi.files && fi.files.length > 0) {
                        hasFiles = true;
                    }
                });

                if (hasFiles) {
                    // Con file: usa FormData (multipart/form-data)
                    const fd = new FormData();
                    fd.append('form_name', formName);
                    fd.append('record_id', recordId);
                    fd.append('scheda_key', tabName.toLowerCase());

                    inputs.forEach(el => {
                        if (el.type === 'file') {
                            if (el.files && el.files.length > 0) {
                                fd.append(el.name, el.files[0]);
                            }
                        } else if (el.type === 'checkbox') {
                            fd.append('values[' + el.name + ']', el.checked ? 1 : 0);
                        } else if (el.tagName === 'SELECT' && el.multiple) {
                            fd.append('values[' + el.name + ']', Array.from(el.selectedOptions).map(o => o.value).join(','));
                        } else {
                            fd.append('values[' + el.name + ']', el.value);
                        }
                    });

                    if (window.APP_DEBUG) {
                        console.log('[submitEditableTab] FormData con file, scheda_key:', tabName.toLowerCase());
                    }

                    try {
                        const result = await window.customFetch('page_editor', 'submitScheda', fd);

                        if (window.APP_DEBUG) {
                            console.log('[submitEditableTab] response:', result);
                        }

                        if (result?.success) {
                            if (typeof showToast === 'function') showToast(tabName + ' salvato con successo', 'success');
                            setTimeout(() => {
                                const u = new URL(location.href);
                                u.hash = '';
                                location.href = u.toString();
                            }, 800);
                        } else {
                            if (typeof showToast === 'function') showToast(result?.message || 'Errore salvataggio', 'error');
                        }
                    } catch (e) {
                        console.error('[submitEditableTab]', e);
                        if (typeof showToast === 'function') showToast('Errore di rete', 'error');
                    }

                } else {
                    // Senza file: usa JSON (come prima)
                    const data = {};
                    inputs.forEach(el => {
                        if (el.type === 'file') return; // skip file senza selezione
                        if (el.type === 'checkbox') {
                            data[el.name] = el.checked ? 1 : 0;
                        } else if (el.tagName === 'SELECT' && el.multiple) {
                            data[el.name] = Array.from(el.selectedOptions).map(o => o.value).join(',');
                        } else {
                            data[el.name] = el.value;
                        }
                    });

                    if (window.APP_DEBUG) {
                        console.log('[submitEditableTab] payload JSON:', {
                            form_name: formName,
                            record_id: recordId,
                            scheda_key: tabName.toLowerCase(),
                            values: data
                        });
                    }

                    try {
                        const result = await window.customFetch('page_editor', 'submitScheda', {
                            form_name: formName,
                            record_id: recordId,
                            scheda_key: tabName.toLowerCase(),
                            values: data
                        });

                        if (window.APP_DEBUG) {
                            console.log('[submitEditableTab] response:', result);
                        }

                        if (result?.success) {
                            if (typeof showToast === 'function') showToast(tabName + ' salvato con successo', 'success');
                            setTimeout(() => {
                                const u = new URL(location.href);
                                u.hash = '';
                                location.href = u.toString();
                            }, 800);
                        } else {
                            if (typeof showToast === 'function') showToast(result?.message || 'Errore salvataggio', 'error');
                        }
                    } catch (e) {
                        console.error('[submitEditableTab]', e);
                        if (typeof showToast === 'function') showToast('Errore di rete', 'error');
                    }
                }
            }

            function showMetaFields(isEditable) {
                const container = document.getElementById('readonly-form');
                const compiled = window.compiled;

                // Blocco meta - LAYOUT IDENTICO A ESITO
                const metaBlock = document.createElement("div");
                metaBlock.className = "form-meta-block";
                metaBlock.style.gridColumn = "1 / -1";

                const compilatoInfo = <?= json_encode($compilato_info) ?>;
                const emptyDash = '—';

                // Riga 1: Titolo
                const rowTitolo = document.createElement("div");
                rowTitolo.className = "meta-row";
                const titoloSafe = (compiled.titolo || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') || emptyDash;
                rowTitolo.innerHTML = `<span class="label">Titolo:</span><span class="meta-value">${titoloSafe}</span>`;
                metaBlock.appendChild(rowTitolo);

                // Riga 2: Descrizione
                const rowDesc = document.createElement("div");
                rowDesc.className = "meta-row";
                const descSafe = (compiled.descrizione || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') || emptyDash;
                rowDesc.innerHTML = `<span class="label">Descrizione:</span><span class="meta-value">${descSafe}</span>`;
                metaBlock.appendChild(rowDesc);

                // Riga 3 SPLIT: Data apertura + Scadenza
                const rowDataScad = document.createElement("div");
                rowDataScad.className = "meta-row meta-row-split";
                const dataApertura = (compiled.data_apertura || compiled.created_at || '').toString().substring(0, 10) || emptyDash;
                const deadline = (compiled.deadline || '').toString().substring(0, 10) || emptyDash;
                rowDataScad.innerHTML = `
                    <span class="label-split">Data di apertura:</span>
                    <span class="value-split">${dataApertura}</span>
                    <span class="label-split">Scadenza:</span>
                    <span class="value-split">${deadline}</span>
                `;
                metaBlock.appendChild(rowDataScad);

                // Riga 4 SPLIT: Compilato da + Assegnato a
                const rowCompilatoAss = document.createElement("div");
                rowCompilatoAss.className = "meta-row meta-row-split";

                let createdByHtml = emptyDash;
                if (compilatoInfo && (compilatoInfo.nome || compilatoInfo.username)) {
                    const nome = compilatoInfo.nome || compilatoInfo.username || 'Utente';
                    createdByHtml = compilatoInfo.img
                        ? '<img src="' + (compilatoInfo.img || '').replace(/"/g, '&quot;') + '" class="profile-image-small" style="vertical-align:middle;margin-right:5px;"> ' + (nome || '').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        : (nome || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }

                rowCompilatoAss.innerHTML = `
                    <span class="label-split">Compilato da:</span>
                    <span class="value-split">${createdByHtml}</span>
                    <span class="label-split">Assegnato a <span style="font-size:11px;color:#888;">(max 5)</span>:</span>
                    <span class="value-split" id="struttura-meta-assegnato-wrap"></span>
                `;
                metaBlock.appendChild(rowCompilatoAss);

                // Gestione Assegnato a con avatar
                const canViewAssegnatoA = <?= json_encode($canViewAssegnatoA) ?>;
                const assegnatoValue = compiled.assegnato_a || '';
                const assegnatoWrap = rowCompilatoAss.querySelector('#struttura-meta-assegnato-wrap');

                const assignedIds = window.normalizeIdList(assegnatoValue);
                const avatarsCont = document.createElement('div');
                avatarsCont.className = 'assignee-avatars-container-inline';
                avatarsCont.style.cssText = 'display:inline-block; vertical-align:middle; min-width:100px; min-height:30px;';
                avatarsCont.style.cursor = isEditable ? 'pointer' : 'default';
                assegnatoWrap.appendChild(avatarsCont);

                const updateAvatarsUI = (ids) => {
                    const users = ids.map(id => {
                        const u = window.UserManager ? window.UserManager.getUser(id) : null;
                        return {
                            id: id,
                            nominativo: u ? u.nome : id,
                            imagePath: u ? u.img : null
                        };
                    });
                    window.renderAvatarsOverlap(avatarsCont, users, { maxVisible: 3 });
                };

                updateAvatarsUI(assignedIds);

                if (isEditable) {
                    avatarsCont.addEventListener('click', async () => {
                        const result = await window.openMultiUserPicker({
                            selectedIds: window.normalizeIdList(assegnatoValue),
                            maxSelection: 6,
                            title: 'Assegna Utenti'
                        });
                        if (result !== null) {
                            const newIds = window.normalizeIdList(result);
                            const resp = await window.customFetch('page_editor', 'submitScheda', {
                                form_name: formName,
                                record_id: recordId,
                                scheda_key: 'struttura',
                                values: { assegnato_a: newIds.join(',') }
                            });
                            if (resp && resp.success) {
                                updateAvatarsUI(newIds);
                                if (window.showToast) window.showToast('Assegnatari aggiornati.', 'success');
                            }
                        }
                    });
                }

                // Riga 5 SPLIT: Priorità + Stato
                const rowPrioStato = document.createElement("div");
                rowPrioStato.className = "meta-row meta-row-split";
                const priority = compiled.priority || emptyDash;
                const stato = compiled.stato || emptyDash;
                rowPrioStato.innerHTML = `
                    <span class="label-split">Priorità:</span>
                    <span class="value-split">${priority}</span>
                    <span class="label-split">Stato:</span>
                    <span class="value-split">${stato}</span>
                `;
                metaBlock.appendChild(rowPrioStato);

                container.appendChild(metaBlock);

                // Divisorio DOPO il meta-block (solo Struttura)
                const lineaSeparatrice = document.createElement("hr");
                lineaSeparatrice.className = "form-title-divider dynamic-divider";
                lineaSeparatrice.style.gridColumn = "1 / -1";
                const formColor = document.querySelector(".view-form-header")?.style.getPropertyValue('--form-color') || '#CCC';
                lineaSeparatrice.style.setProperty('--form-color', formColor);
                metaBlock.insertAdjacentElement("afterend", lineaSeparatrice);
            }

            function showEsitoMetaBlock(container, isEditable) {
                const compiled = window.compiled || {};
                const disabledAttr = isEditable ? '' : 'disabled';
                const emptyDash = '—';
                const compilatoInfo = <?= json_encode($compilato_info) ?>;

                const metaBlock = document.createElement('div');
                metaBlock.className = 'form-meta-block esito-meta-block';
                metaBlock.style.gridColumn = '1 / -1';
                metaBlock.style.marginBottom = '16px';
                metaBlock.style.borderLeft = '4px solid #3498db';

                let dataApEsito = compiled.data_apertura_esito || '';
                if (!dataApEsito) {
                    const foundKey = Object.keys(compiled).find(k => {
                        const n = k.toLowerCase().replace(/_/g, '').replace(/\s/g, '');
                        return (n === 'dataapertura' || n === 'datadiapertura' || n === 'datainvio');
                    });
                    dataApEsito = (foundKey ? compiled[foundKey] : null) || compiled.created_at || compiled.data_creazione || '';
                    if (dataApEsito) {
                        try {
                            const d = new Date(dataApEsito);
                            if (!isNaN(d.getTime())) {
                                dataApEsito = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                            }
                        } catch (e) { }
                    }
                }
                if (!dataApEsito) {
                    const now = new Date();
                    dataApEsito = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
                }
                const dlEsito = (compiled.deadline_esito || '').toString().substring(0, 10);
                const priEsito = compiled.priorita_esito || '';
                const staEsito = compiled.stato_esito || '';
                const assegnatoEsitoRaw = compiled.assegnato_a_esito || compiled.assegnato_a || '';
                const assegnatoEsitoIds = (typeof assegnatoEsitoRaw === 'string')
                    ? assegnatoEsitoRaw.split(',').map(v => v.trim()).filter(Boolean)
                    : (Array.isArray(assegnatoEsitoRaw) ? assegnatoEsitoRaw : []);

                let createdByHtml = emptyDash;
                if (compilatoInfo && (compilatoInfo.nome || compilatoInfo.username)) {
                    const nome = compilatoInfo.nome || compilatoInfo.username || 'Utente';
                    createdByHtml = compilatoInfo.img
                        ? '<img src="' + (compilatoInfo.img || '').replace(/"/g, '&quot;') + '" class="profile-image-small" style="vertical-align:middle;margin-right:5px;"> ' + (nome || '').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        : (nome || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }

                const titoloSafe = (compiled.titolo || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') || emptyDash;
                const descSafe = (compiled.descrizione || '').replace(/</g, '&lt;').replace(/>/g, '&gt;') || emptyDash;
                const priOpts = ['Bassa', 'Media', 'Alta'].map(o => '<option value="' + o + '" ' + (priEsito === o ? 'selected' : '') + '>' + o + '</option>').join('');
                const staOpts = ['Aperta', 'In corso', 'Chiusa'].map(o => '<option value="' + o + '" ' + (staEsito === o ? 'selected' : '') + '>' + o + '</option>').join('');

                metaBlock.innerHTML = `
                    <div class="form-meta-header">Dati Esito del Responsabile</div>
                    <div class="meta-row"><span class="label">Titolo:</span><span class="meta-value">${titoloSafe}</span></div>
                    <div class="meta-row"><span class="label">Descrizione:</span><span class="meta-value">${descSafe}</span></div>
                    <div class="meta-row meta-row-split">
                        <span class="label-split">Data apertura (esito):</span>
                        <span class="value-split"><input type="date" name="data_apertura_esito" value="${(dataApEsito || '').replace(/"/g, '&quot;')}" ${disabledAttr} class="input-light"></span>
                        <span class="label-split">Scadenza (esito):</span>
                        <span class="value-split"><input type="date" name="deadline_esito" value="${(dlEsito || '').replace(/"/g, '&quot;')}" ${disabledAttr} class="input-light"></span>
                    </div>
                    <div class="meta-row meta-row-split">
                        <span class="label-split">Compilato da:</span>
                        <span class="value-split">${createdByHtml}</span>
                        <span class="label-split">Assegnato a <span style="font-size:11px;color:#888;">(max 5)</span>:</span>
                        <span class="value-split" id="esito-meta-assegnato-wrap"></span>
                    </div>
                    <div class="meta-row meta-row-split">
                        <span class="label-split">Priorità (esito):</span>
                        <span class="value-split"><select name="priorita_esito" ${disabledAttr} class="input-light">${priOpts}</select></span>
                        <span class="label-split">Stato (esito):</span>
                        <span class="value-split"><select name="stato_esito" ${disabledAttr} class="input-light">${staOpts}</select></span>
                    </div>
                `;

                const assegnatoWrap = metaBlock.querySelector('#esito-meta-assegnato-wrap');
                const assignedEsitoIds = window.normalizeIdList(assegnatoEsitoRaw);

                const avatarsContEsito = document.createElement('div');
                avatarsContEsito.className = 'assignee-avatars-container-inline';
                avatarsContEsito.style.cssText = 'display:inline-block; vertical-align:middle; min-width:100px; min-height:30px;';
                avatarsContEsito.style.cursor = isEditable ? 'pointer' : 'default';
                assegnatoWrap.appendChild(avatarsContEsito);

                // Input hidden per far sì che submitEditableTab lo veda se clicchiamo "Salva Esito"
                const hiddenAssEsito = document.createElement('input');
                hiddenAssEsito.type = 'hidden';
                hiddenAssEsito.name = 'assegnato_a_esito';
                hiddenAssEsito.value = assignedEsitoIds.join(',');
                assegnatoWrap.appendChild(hiddenAssEsito);

                const updateAvatarsEsitoUI = (ids) => {
                    const users = ids.map(id => {
                        const u = window.UserManager ? window.UserManager.getUser(id) : null;
                        return {
                            id: id,
                            nominativo: u ? u.nome : 'Utente ' + id,
                            imagePath: u ? u.img : null
                        };
                    });
                    window.renderAvatarsOverlap(avatarsContEsito, users, { maxVisible: 3 });
                };

                updateAvatarsEsitoUI(assignedEsitoIds);

                if (isEditable) {
                    avatarsContEsito.addEventListener('click', async () => {
                        const result = await window.openMultiUserPicker({
                            selectedIds: window.normalizeIdList(hiddenAssEsito.value),
                            maxSelection: 6,
                            title: 'Seleziona assegnatari'
                        });
                        if (result !== null) {
                            const newIds = window.normalizeIdList(result);
                            hiddenAssEsito.value = newIds.join(',');
                            updateAvatarsEsitoUI(newIds);
                        }
                    });
                }

                container.appendChild(metaBlock);
            }

            function showClosureFixedBlock(container, isEditable) {
                const compiled = window.compiled || {};
                const esitoVal = compiled.esito_stato || compiled.esito || '';
                const noteVal = compiled.esito_note || compiled.note_esito || '';
                const dataChiusuraVal = (compiled.data_chiusura_raw || compiled.data_chiusura || '').toString().substring(0, 10);

                const closureBlock = document.createElement('div');
                closureBlock.className = 'form-meta-block closure-fixed-block';
                closureBlock.style.gridColumn = '1 / -1';
                closureBlock.style.borderLeft = '4px solid #e74c3c';
                closureBlock.style.marginBottom = '20px';

                closureBlock.innerHTML = `
                    <div class="form-meta-header">Esito della Segnalazione</div>
                    <div class="meta-row">
                        <span class="label">Esito <span style="color:#e74c3c">*</span>:</span>
                        <span class="meta-value">
                            <select ${isEditable ? 'name="esito_stato"' : 'disabled'} class="input-light" style="font-weight:500;">
                                <option value="">-- Seleziona Esito --</option>
                                <option value="accettata" ${esitoVal === 'accettata' ? 'selected' : ''} style="color:#27ae60; font-weight:bold;">Accettata</option>
                                <option value="in_valutazione" ${esitoVal === 'in_valutazione' ? 'selected' : ''} style="color:#f39c12; font-weight:bold;">In Valutazione</option>
                                <option value="rifiutata" ${esitoVal === 'rifiutata' ? 'selected' : ''} style="color:#c0392b; font-weight:bold;">Rifiutata</option>
                            </select>
                        </span>
                    </div>
                    <div class="meta-row">
                        <span class="label">Note Esito:</span>
                        <span class="meta-value">
                            <textarea ${isEditable ? 'name="esito_note"' : 'disabled'} class="input-light" style="min-height:80px;" placeholder="Inserisci eventuali note...">${(noteVal || '').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</textarea>
                        </span>
                    </div>
                    <div class="meta-row meta-row-split">
                        <span class="label-split">Data Chiusura:</span>
                        <span class="value-split">
                            <input type="date" ${isEditable ? 'name="data_chiusura"' : 'disabled'} class="input-light" style="font-weight:500;" value="${(dataChiusuraVal || '').replace(/"/g, '&quot;')}">
                        </span>
                    </div>
                `;

                container.appendChild(closureBlock);
            }

            async function loadFormWithoutTabs() {
                // Nascondi la barra delle schede
                document.getElementById('form-tabs-bar').style.display = 'none';

                // Usa il codice originale per caricare i campi
                const container = document.getElementById('readonly-form');
                const compiled = window.compiled;
                const data = window.data;

                // Mostra i meta fields (titolo + compilato da)
                showMetaFields(false);

                // Carica i campi normalmente usando il codice originale
                loadFieldsFromResponse(data);
            }

            function loadFieldsFromResponse(data) {
                // Lista campi con deduplicazione per evitare duplicati reali nel database
                const allFields = Array.isArray(data.fields) ? data.fields : [];

                // Escludi i campi fissi (sono nel meta-block)
                const fissiKeys = ['titolo', 'descrizione', 'deadline', 'priority', 'assegnato_a'];
                let fields = allFields.filter(f => {
                    const n = String(f?.field_name || '').toLowerCase();
                    return !fissiKeys.includes(n);
                });

                // Deduplicazione: usa chiave composita (field_name + parent_section_uid) per identificazione univoca
                // Questo è coerente con la logica backend che usa field_name + parent_section_uid
                const uniqueMap = {};
                fields = fields.filter(f => {
                    const fieldName = String(f?.field_name || '').toLowerCase();
                    const parentUid = String(f?.parent_section_uid || '').toLowerCase();
                    const key = fieldName + '|' + parentUid;
                    if (uniqueMap[key]) {
                        return false;
                    }
                    uniqueMap[key] = true;
                    return true;
                });

                // --- aggancia i figli alle sezioni usando parent_section_uid ---
                const sectionsByUid = {};
                fields.forEach(r => {
                    if (String(r.field_type || '').toLowerCase() !== 'section') return;
                    let cfg = {};
                    try {
                        cfg = typeof r.field_options === 'string' ? (JSON.parse(r.field_options || '{}') || {}) : (r.field_options || {});
                    } catch (_) {
                        cfg = {};
                    }
                    const uid = String(cfg.uid || '').trim().toLowerCase();
                    if (!uid) return;
                    sectionsByUid[uid] = r;
                    // prepara il contenitore figli in cfg
                    cfg.children = [];
                    r.field_options = JSON.stringify(cfg);
                });

                // raccogli figli e rimuovili dal "piatto" principale
                const remaining = [];
                fields.forEach(r => {
                    if (String(r.field_type || '').toLowerCase() === 'section') {
                        remaining.push(r);
                        return;
                    }
                    const puid = (r.parent_section_uid || '').toString().trim().toLowerCase();
                    if (puid && sectionsByUid[puid]) {
                        // push come child sintetico
                        try {
                            const child = {
                                name: (r.field_name || '').toString(),
                                type: (r.field_type || 'text').toString(),
                                options: (() => {
                                    try {
                                        const x = JSON.parse(r.field_options || '[]');
                                        return Array.isArray(x) ? x : [];
                                    } catch {
                                        return [];
                                    }
                                })(),
                                datasource: (() => {
                                    try {
                                        const x = JSON.parse(r.field_options || '{}');
                                        return (typeof x === 'object' && x && !Array.isArray(x)) ? x : {};
                                    } catch {
                                        return {};
                                    }
                                })(),
                                colspan: (Number(r.colspan) === 2 ? 2 : 1)
                            };
                            // append nel cfg della sezione
                            const secCfg = JSON.parse(sectionsByUid[puid].field_options || '{}');
                            (secCfg.children ||= []).push(child);
                            sectionsByUid[puid].field_options = JSON.stringify(secCfg);
                        } catch (_) { }
                    } else {
                        remaining.push(r);
                    }
                });

                // sovrascrivi l'elenco da renderizzare con: sezioni (con children) + gli altri
                const fieldsWithSections = remaining;

                // Filtra i campi: escludi i fissi (già nel meta-block), separa dinamici e file
                const dinamici = [];
                const file = [];

                fieldsWithSections.forEach(f => {
                    const n = String(f.field_name || '').toLowerCase();
                    if (fissiKeys.includes(n)) {
                        // Skippa - sono nel meta-block
                        return;
                    }
                    if (f.field_type === 'file') {
                        file.push(f);
                    } else {
                        dinamici.push(f);
                    }
                });

                // Renderizza campi dinamici
                dinamici.forEach(f => render_readonly_field(f));

                // Infine i file
                file.forEach(f => render_readonly_field(f));
            }


            // Funzione per renderizzare un campo. Se isEditable=true, renderizza come form input.
            function render_readonly_field(field, target_container = container, isEditable = false) {
                if (!field) return;

                // Sicurezza: skippa i campi fissi (sono nel meta block)
                const fieldName = String(field.field_name || '').toLowerCase();
                const fissiKeys = ['titolo', 'descrizione', 'deadline', 'priority', 'assegnato_a'];
                if (fissiKeys.includes(fieldName)) {
                    return; // Non renderizzare - già nel meta block
                }

                if (String(field.field_type || '').toLowerCase() === 'section') {
                    // come in view_form.php: usa cfg.children se presenti
                    let cfg = {};
                    try {
                        cfg = typeof field.field_options === 'string' ?
                            (JSON.parse(field.field_options || '{}') || {}) :
                            (field.field_options || {});
                    } catch (_) {
                        cfg = {};
                    }

                    // ——— dentro render_readonly_field, caso 'section' ———
                    const fs = document.createElement('fieldset');
                    fs.className = 'view-form-group form-section';
                    fs.style.gridColumn = '1 / -1'; // fieldset SEMPRE full-width

                    const lg = document.createElement('legend');
                    lg.className = 'form-section-legend';
                    lg.textContent = (cfg.label || 'sezione');
                    lg.setAttribute('data-tooltip', (cfg.label ? `Sezione: ${cfg.label}` : 'Sezione')); // tooltip standard

                    // opzionale ma utile per accessibilità
                    const legendId = `legend-${(cfg.label || 'sezione').toString().toLowerCase().replace(/[^\w]+/g, '-')}`;
                    lg.id = legendId;
                    fs.setAttribute('aria-labelledby', legendId);

                    // l'ordine conta: prima il legend, poi il contenuto
                    fs.appendChild(lg);

                    const inner = document.createElement('div');
                    inner.className = 'section-grid';
                    fs.appendChild(inner);

                    const ch = Array.isArray(cfg.children) ? cfg.children : [];
                    if (!ch.length) {
                        const empty = document.createElement('div');
                        empty.className = 'input-static span-2 muted';
                        empty.textContent = 'nessun campo nella sezione.';
                        inner.appendChild(empty);
                    } else {
                        ch.forEach(c => {
                            const faux = {
                                field_name: (c.name || '').toString(),
                                field_type: (c.type || 'text'),
                                field_placeholder: '',
                                field_options: (c.type === 'dbselect') ?
                                    JSON.stringify(c.datasource || {}) : JSON.stringify(Array.isArray(c.options) ? c.options : []),
                                colspan: (Number(c.colspan) === 2 ? 2 : 1)
                            };
                            render_readonly_field(faux, inner, isEditable);
                        });
                    }

                    if (target_container && typeof target_container.appendChild === 'function') {
                        target_container.appendChild(fs);
                    }
                    return;
                }

                // campi standard readonly
                const group = document.createElement('div');
                group.classList.add('view-form-group');

                // colspan (1 = mezza colonna, 2 = intera riga)
                const cs_raw = parseInt(field.colspan ?? field.colSpan ?? field.ColSpan ?? 1, 10);
                const cs = (cs_raw === 2) ? 2 : 1;
                if (cs === 2) group.style.gridColumn = '1 / span 2';

                const name = field.field_name;
                const type = String(field.field_type || 'text').toLowerCase();
                const placeholder = field.field_placeholder || '';
                let options = [];
                try {
                    options = JSON.parse(field.field_options || '[]');
                } catch {
                    options = [];
                }

                const label_name = computeDisplayLabel(field);

                const makeLabel = () => {
                    const el = document.createElement('label');
                    el.innerHTML = label_name + ':';
                    return el;
                };
                group.appendChild(makeLabel());

                const keylower = String(name || '').toLowerCase();
                let value = window.compiled?.[keylower] ?? window.compiled?.[name] ?? '';
                if (value === '' && type === 'file' && window.compiled) {
                    const match = Object.keys(window.compiled).find(k => String(k).toLowerCase() === keylower);
                    if (match) value = window.compiled[match];
                }

                if (type === 'textarea') {
                    const el = document.createElement('textarea');
                    el.disabled = !isEditable;
                    if (isEditable) el.name = name;
                    el.value = String(value ?? '');
                    group.appendChild(el);

                } else if (type === 'select') {
                    const el = document.createElement('select');
                    el.disabled = !isEditable;
                    if (isEditable) el.name = name;

                    const def = document.createElement('option');
                    def.value = '';
                    def.disabled = true;
                    def.textContent = placeholder || '-- seleziona --';
                    el.appendChild(def);

                    options.forEach(opt => {
                        const o = document.createElement('option');
                        o.value = opt;
                        o.textContent = opt;
                        if (String(opt).toLowerCase() === String(value).toLowerCase()) o.selected = true;
                        el.appendChild(o);
                    });

                    el.value = String(value ?? '');
                    group.appendChild(el);

                } else if (type === 'dbselect') {
                    // Logica di parsing robusta (allineata a view_form.php)
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

                    const sources = [cfg, rootCfg, field?.datasource, field?.options, field].filter(s => s && typeof s === 'object' && !Array.isArray(s));
                    const pick = (...keys) => {
                        for (const src of sources) {
                            for (const k of keys) {
                                if (src[k] !== undefined && src[k] !== null && String(src[k]).trim() !== '') return String(src[k]);
                            }
                        }
                        return '';
                    };

                    const dbTable = pick('table', 'Table', 'TABLE', 'tabella').replace(/[^a-z0-9_.]/gi, '');
                    const dbValueCol = pick('valueCol', 'valuecol', 'value_col', 'value', 'val').replace(/[^a-z0-9_.]/gi, '');
                    const dbLabelColRaw = pick('labelCol', 'labelcol', 'label_col', 'label', 'text');
                    const dbLabelCol = (dbLabelColRaw || dbValueCol || '').replace(/[^a-z0-9_.]/gi, '');

                    if (!dbTable || !dbValueCol) {
                        console.warn('⚠️ [form_viewer] Configurazione dbselect incompleta per:', field.field_name, { field_options: field.field_options, extracted: { dbTable, dbValueCol, dbLabelCol } });
                    }

                    const resolveBool = (val) => {
                        if (typeof val === 'string') {
                            return ['1', 'true', 'yes', 'si'].includes(val.trim().toLowerCase());
                        }
                        return !!val;
                    };
                    const normalizeSelected = (raw) => {
                        if (Array.isArray(raw)) return raw.map(v => String(v).trim()).filter(Boolean);
                        if (typeof raw === 'string') {
                            if (raw.trim().startsWith('[') && raw.trim().endsWith(']')) {
                                try {
                                    const parsed = JSON.parse(raw);
                                    if (Array.isArray(parsed)) return parsed.map(v => String(v).trim()).filter(Boolean);
                                } catch (e) { }
                            }
                            return raw.split(',').map(v => v.trim()).filter(Boolean).map(v => String(v));
                        }
                        if (raw === null || raw === undefined || raw === '') return [];
                        return [String(raw).trim()];
                    };
                    const isMultiple = resolveBool(pick('multiple', 'multiselect', 'allowMultiple', 'multi') || cfg.multiple || false);
                    const normalizedSelected = normalizeSelected(value);
                    const selectedValues = isMultiple ? normalizedSelected : (normalizedSelected.length ? [normalizedSelected[0]] : []);


                    if (!isEditable) {
                        // READONLY: mostra solo testo label (no dropdown, no fetch opzioni)
                        const span = document.createElement('span');
                        span.className = 'input-static dbselect-readonly';
                        span.setAttribute('data-field', name);

                        if (!selectedValues.length || (selectedValues.length === 1 && selectedValues[0] === '')) {
                            span.textContent = '—';
                        } else {
                            // Placeholder temporaneo mentre risolviamo la label
                            span.textContent = selectedValues.join(', ');
                            // Registra per batch resolve
                            if (dbTable && dbValueCol && dbLabelCol) {
                                window._dbselectResolveQueue = window._dbselectResolveQueue || [];
                                window._dbselectResolveQueue.push({
                                    el: span,
                                    table: dbTable,
                                    valueCol: dbValueCol,
                                    labelCol: dbLabelCol,
                                    value: selectedValues.join(',')
                                });
                            }
                        }
                        group.appendChild(span);

                    } else {
                        // EDITABLE: dropdown completo con fetch opzioni
                        const el = document.createElement('select');
                        el.name = name;
                        el.setAttribute('data-tooltip', 'valori dal database');
                        if (isMultiple) {
                            el.multiple = true;
                        }

                        const selectedSet = new Set(selectedValues.map(v => String(v)));
                        const placeholderOpt = document.createElement('option');
                        placeholderOpt.value = '';
                        placeholderOpt.textContent = placeholder || '-- seleziona --';
                        if (isMultiple) {
                            placeholderOpt.disabled = true;
                            placeholderOpt.hidden = true;
                        } else {
                            placeholderOpt.disabled = true;
                            placeholderOpt.selected = selectedSet.size === 0;
                        }
                        el.appendChild(placeholderOpt);

                        const summary = isMultiple ? document.createElement('div') : null;
                        if (summary) {
                            summary.className = 'dbselect-multi-summary is-empty';
                            summary.textContent = 'Nessun elemento selezionato';
                        }

                        const refreshSummary = () => {
                            if (!summary) return;
                            const chosen = Array.from(el.options || [])
                                .filter(opt => opt.selected && !opt.disabled && opt.value !== '')
                                .map(opt => opt.textContent.trim())
                                .filter(Boolean);
                            summary.innerHTML = '';
                            if (!chosen.length) {
                                summary.classList.add('is-empty');
                                summary.textContent = 'Nessun elemento selezionato';
                                return;
                            }
                            summary.classList.remove('is-empty');
                            chosen.forEach(text => {
                                const chip = document.createElement('span');
                                chip.className = 'summary-chip';
                                chip.textContent = text;
                                summary.appendChild(chip);
                            });
                        };

                        (async () => {
                            try {
                                if (!dbTable || !dbValueCol || !dbLabelCol) { refreshSummary(); return; }
                                const resp = await customFetch('datasource', 'getOptions', {
                                    table: dbTable,
                                    valueCol: dbValueCol,
                                    labelCol: dbLabelCol
                                });
                                if (resp && resp.success && Array.isArray(resp.options)) {
                                    const seen = new Set();
                                    resp.options.forEach(row => {
                                        const val = String(row.v);
                                        const opt = document.createElement('option');
                                        opt.value = val;
                                        opt.textContent = String(row.l);
                                        if (selectedSet.has(val)) {
                                            opt.selected = true;
                                            seen.add(val);
                                        }
                                        el.appendChild(opt);
                                    });
                                    const missing = selectedValues.filter(val => !seen.has(String(val)));
                                    missing.forEach(val => {
                                        const opt = document.createElement('option');
                                        opt.value = String(val);
                                        opt.textContent = String(val) + ' (non in elenco)';
                                        opt.selected = true;
                                        el.appendChild(opt);
                                    });
                                }
                            } catch (e) {
                                console.warn('dbselect populate error', e);
                            } finally {
                                refreshSummary();
                            }
                        })();

                        group.appendChild(el);
                        if (summary) {
                            group.appendChild(summary);
                            refreshSummary();
                        }
                    }

                } else if (type === 'checkbox') {
                    const box = document.createElement('div');
                    box.classList.add('checkbox-group');
                    // Gestione robusta: trim degli spazi e array vuoto se mancante
                    const selected = typeof value === 'string'
                        ? value.split(',').map(v => v.trim()).filter(Boolean)
                        : (Array.isArray(value) ? value : []);

                    options.forEach(opt => {
                        const w = document.createElement('label');
                        w.classList.add('checkbox-label');
                        const i = document.createElement('input');
                        i.type = 'checkbox';
                        i.disabled = !isEditable;
                        if (isEditable) { i.name = name; i.value = String(opt); }
                        const optStr = String(opt).trim();
                        i.checked = selected.some(s => String(s).trim().toLowerCase() === optStr.toLowerCase());
                        w.appendChild(i);
                        w.append(' ' + opt);
                        box.appendChild(w);
                    });
                    group.appendChild(box);

                } else if (type === 'radio') {
                    const norm = (Array.isArray(options) ? options : []).map(o => {
                        if (o && typeof o === 'object' && !Array.isArray(o)) {
                            return { v: String(o.v), l: String(o.l) };
                        }
                        const s = String(o);
                        return { v: s, l: s };
                    });
                    const val = String(value ?? '');

                    const box = document.createElement('div');
                    box.classList.add('radio-group');

                    norm.forEach(opt => {
                        const w = document.createElement('label');
                        w.classList.add('radio-label');
                        const i = document.createElement('input');
                        i.type = 'radio';
                        i.disabled = !isEditable;
                        if (isEditable) { i.name = name; i.value = String(opt.v); }
                        i.checked = (val === String(opt.v));
                        w.appendChild(i);
                        w.append(' ' + String(opt.l));
                        box.appendChild(w);
                    });

                    group.appendChild(box);

                } else if (type === 'file') {
                    let cleaned = (value && typeof value === 'string') ? value.trim() : '';

                    if (isEditable) {
                        // ——— EDITABLE: stesso widget dropzone di view_form ———
                        const dropZone = document.createElement('div');
                        dropZone.className = 'dropzone-upload';

                        const fileInput = document.createElement('input');
                        fileInput.type = 'file';
                        fileInput.name = name;
                        fileInput.accept = 'image/jpeg, image/png, application/pdf';
                        fileInput.style.display = 'none';

                        const preview = document.createElement('div');
                        preview.className = 'upload-preview';

                        const dzText = document.createElement('div');
                        dzText.className = 'dropzone-text';
                        dzText.textContent = 'trascina qui un file, clicca o incolla (ctrl+v)';

                        const removeBtn = document.createElement('button');
                        removeBtn.type = 'button';
                        removeBtn.className = 'upload-remove-btn';
                        removeBtn.textContent = 'rimuovi immagine';
                        removeBtn.style.display = 'none';

                        const infoBox = document.createElement('div');
                        infoBox.className = 'upload-info';
                        infoBox.textContent = 'formati accettati: jpg, png, pdf. max 5mb.';

                        dropZone.append(preview, dzText, fileInput, removeBtn, infoBox);
                        group.appendChild(dropZone);

                        // Se c'è già un file salvato, mostra anteprima
                        if (cleaned) {
                            let imgSrc = cleaned;
                            if (!/^https?:\/\//i.test(imgSrc) && imgSrc[0] !== '/') imgSrc = '/' + imgSrc;
                            if (imgSrc.toLowerCase().endsWith('.pdf')) {
                                preview.innerHTML = '<div style="text-align:center;padding:20px;"><span style="font-size:48px;">📄</span><br><a href="' + imgSrc + '" target="_blank" style="font-size:12px;color:#666;">Visualizza PDF</a></div>';
                            } else {
                                preview.innerHTML = '<img src="' + imgSrc + '" class="upload-preview-img">';
                            }
                            removeBtn.style.display = 'inline-block';
                            dzText.style.display = 'none';
                        }

                        function showFilePreview(file) {
                            if (!file) return;
                            const ext = (file.name.split('.').pop() || '').toLowerCase();
                            if (!['jpg', 'jpeg', 'png', 'pdf'].includes(ext) && !file.type.startsWith('image/') && file.type !== 'application/pdf') {
                                preview.innerHTML = "<span style='color:#c0392b'>file non valido</span>";
                                removeBtn.style.display = 'none';
                                dzText.style.display = 'block';
                                fileInput.value = '';
                                return;
                            }
                            if (file.type === 'application/pdf' || ext === 'pdf') {
                                preview.innerHTML = '<div style="text-align:center;padding:20px;font-weight:bold;color:#555;"><span style="font-size:48px;">📄</span><br>' + file.name + '</div>';
                                removeBtn.style.display = 'inline-block';
                                dzText.style.display = 'none';
                            } else {
                                const reader = new FileReader();
                                reader.onload = function (ev) {
                                    preview.innerHTML = '<img src="' + ev.target.result + '" style="max-width:220px;max-height:180px;border-radius:6px;display:block;margin:0 auto;">';
                                    removeBtn.style.display = 'inline-block';
                                    dzText.style.display = 'none';
                                };
                                reader.readAsDataURL(file);
                            }
                        }

                        // Click per aprire esplora risorse
                        dropZone.addEventListener('click', function (ev) {
                            if (ev.target === removeBtn) return;
                            fileInput.click();
                        });

                        // Drag & Drop
                        dropZone.addEventListener('dragover', function (ev) { ev.preventDefault(); dropZone.style.background = '#e6f7ff'; });
                        dropZone.addEventListener('dragleave', function () { dropZone.style.background = '#f9f9f9'; });
                        dropZone.addEventListener('drop', function (ev) {
                            ev.preventDefault();
                            dropZone.style.background = '#f9f9f9';
                            if (ev.dataTransfer.files && ev.dataTransfer.files[0]) {
                                fileInput.files = ev.dataTransfer.files;
                                showFilePreview(fileInput.files[0]);
                            }
                        });

                        // Rimuovi
                        removeBtn.addEventListener('click', function (ev) {
                            ev.stopPropagation();
                            fileInput.value = '';
                            preview.innerHTML = '';
                            removeBtn.style.display = 'none';
                            dzText.style.display = 'block';
                        });

                        // Change
                        fileInput.addEventListener('change', function () {
                            if (fileInput.files && fileInput.files[0]) showFilePreview(fileInput.files[0]);
                        });

                        // Paste Ctrl+V (registra una sola volta per pagina)
                        if (!window._viewerPasteHandlerAttached) {
                            window.addEventListener('paste', function (ev) {
                                const items = (ev.clipboardData || ev.originalEvent.clipboardData).items;
                                for (let idx in items) {
                                    const item = items[idx];
                                    if (item.kind === 'file') {
                                        const blob = item.getAsFile();
                                        const activeInputs = document.querySelectorAll('.dropzone-upload input[type=file]:not([disabled])');
                                        if (activeInputs.length) {
                                            const dt = new DataTransfer();
                                            dt.items.add(blob);
                                            activeInputs[0].files = dt.files;
                                            const parentDrop = activeInputs[0].closest('.dropzone-upload');
                                            if (parentDrop) {
                                                const previewDiv = parentDrop.querySelector('.upload-preview');
                                                const removeBtnDiv = parentDrop.querySelector('.upload-remove-btn');
                                                const dzTextDiv = parentDrop.querySelector('.dropzone-text');
                                                const pastedFile = blob;
                                                const allowedPaste = ['image/jpeg', 'image/png', 'application/pdf'];
                                                if (!allowedPaste.includes(pastedFile.type)) {
                                                    if (typeof showToast === 'function') showToast('Solo immagini JPG/PNG o PDF sono consentiti.', 'error');
                                                    return;
                                                }
                                                const pasteReader = new FileReader();
                                                pasteReader.onload = function (pev) {
                                                    if (pastedFile.type === 'application/pdf') {
                                                        previewDiv.innerHTML = '<div style="text-align:center;padding:20px;font-weight:bold;color:#555;"><span style="font-size:48px;">📄</span><br>' + (pastedFile.name || 'PDF Incollato') + '</div>';
                                                    } else {
                                                        previewDiv.innerHTML = '<img src="' + pev.target.result + '" style="max-width:220px;max-height:180px;border-radius:6px;display:block;margin:0 auto;">';
                                                    }
                                                    if (removeBtnDiv) removeBtnDiv.style.display = 'inline-block';
                                                    if (dzTextDiv) dzTextDiv.style.display = 'none';
                                                };
                                                pasteReader.readAsDataURL(blob);
                                            }
                                        }
                                    }
                                }
                            });
                            window._viewerPasteHandlerAttached = true;
                        }

                    } else {
                        // ——— READONLY: mostra file esistente o "nessun allegato" ———
                        if (!cleaned) {
                            const empty = document.createElement('span');
                            empty.className = 'no-file';
                            empty.textContent = 'nessun allegato';
                            group.appendChild(empty);
                        } else {
                            if (!/^https?:\/\//i.test(cleaned) && cleaned[0] !== '/') cleaned = '/' + cleaned;
                            const fileurl = cleaned;
                            const filename = cleaned.split('/').pop() || '';
                            const ext = (filename.split('.').pop() || '').toLowerCase();
                            const imageext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

                            group.classList.add('span-2');
                            if (imageext.includes(ext)) {
                                const wrap = document.createElement('div');
                                wrap.classList.add('image-wrapper');
                                const img = document.createElement('img');
                                img.src = fileurl;
                                img.alt = filename;
                                img.className = 'form-image-preview';
                                img.setAttribute('data-tooltip', 'clicca per ingrandire');
                                img.onclick = () => window.showImageModal ? window.showImageModal(img.src) : window.open(img.src, '_blank');
                                wrap.appendChild(img);
                                group.appendChild(wrap);
                            } else {
                                const a = document.createElement('a');
                                a.href = fileurl;
                                a.target = '_blank';
                                a.className = 'file-link';
                                a.innerHTML = '📎 <span class="file-link-name">' + filename + '</span>';
                                group.appendChild(a);
                            }
                        }
                    }

                } else if (type === 'date') {
                    const input = document.createElement('input');
                    input.type = 'date';
                    input.disabled = !isEditable;
                    if (isEditable) input.name = name;
                    const dateraw = (window.compiled?.[keylower + '_raw'] || value || '');
                    input.value = String(dateraw);
                    group.appendChild(input);

                } else {
                    if (!isEditable && type === 'text' && String(value).length > 25) {
                        const span = document.createElement('span');
                        span.className = 'input-static';
                        span.textContent = String(value);
                        group.appendChild(span);
                    } else {
                        const input = document.createElement('input');
                        input.type = type;
                        input.value = String(value ?? '');
                        input.disabled = !isEditable;
                        if (isEditable) input.name = name;
                        group.appendChild(input);
                    }
                }

                if (target_container && typeof target_container.appendChild === 'function') {
                    target_container.appendChild(group);
                }
            }

            // helpers label
            function prettifyName(n) {
                return String(n || '')
                    .replace(/_/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .replace(/\b\w/g, c => c.toUpperCase());
            }

            function isGenericPlaceholder(ph) {
                const p = String(ph || '').trim().toLowerCase();
                if (!p) return true;
                if (p === '-- seleziona --' || p === 'scelta') return true;
                if (/^seleziona\b/.test(p)) return true;
                if (/^inserisci\b/.test(p)) return true;
                if (/^scegli\b/.test(p)) return true;
                return false;
            }

            function computeDisplayLabel(field) {
                // Fallback labels per i 5 campi fissi (solo se placeholder vuoto)
                const FIXED_LABELS_FALLBACK = {
                    titolo: 'Titolo',
                    descrizione: 'Descrizione',
                    deadline: 'Scadenza',
                    priority: 'Priorità',
                    assegnato_a: 'Assegnato a'
                };

                const key = String(field?.field_name || '').toLowerCase();
                const ph = String(field?.field_placeholder || '').trim();

                // 1) field_placeholder (fonte principale)
                if (ph && !isGenericPlaceholder(ph)) {
                    return ph;
                }

                // 2) field_options.label
                let labelFromOptions = '';
                try {
                    const fo = typeof field?.field_options === 'string' ?
                        JSON.parse(field?.field_options || '{}') :
                        (field?.field_options || {});
                    if (fo && fo.label && String(fo.label).trim()) {
                        labelFromOptions = String(fo.label).trim();
                    }
                } catch (_) { }

                if (labelFromOptions) return labelFromOptions;

                // 3) Fallback per campi fissi
                if (FIXED_LABELS_FALLBACK[key]) {
                    return FIXED_LABELS_FALLBACK[key];
                }

                // 4) Fallback generico (humanizedName)
                return prettifyName(field?.field_name || '');
            }


            // --- BREADCRUMB HARD-FIX ---
            (() => {
                const sec = window.current_section || 'gestione';
                const label = sec.charAt(0).toUpperCase() + sec.slice(1);

                // prova a beccare i breadcrumb più comuni
                const roots = [
                    document.querySelector('.breadcrumb'),
                    document.querySelector('nav.breadcrumb'),
                    document.querySelector('ol.breadcrumb')
                ].filter(Boolean);

                roots.forEach(root => {
                    root.querySelectorAll('a, span, li').forEach(el => {
                        if (/segnalazioni\b/i.test(el.textContent.trim())) {
                            el.textContent = label;
                            if (el.tagName === 'A' && el.href) {
                                const u = new URL(el.href, location.origin);
                                u.searchParams.set('section', sec);
                                el.href = u.toString();
                            }
                        }
                    });
                });
            })();

        });
    </script>
</div>