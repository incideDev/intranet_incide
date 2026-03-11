<?php

namespace Services;

// Sicurezza gestita dal bootstrap centrale

class PageEditorService
{
    /* =========================
       SQL per tabella form_schede_status (OPZIONALE - per retrocompatibilit??)
       Da eseguire manualmente nel DB:

       CREATE TABLE IF NOT EXISTS `form_schede_status` (
         `id` INT AUTO_INCREMENT PRIMARY KEY,
         `form_id` INT NOT NULL COMMENT 'FK ??? forms.id',
         `record_id` INT NOT NULL COMMENT 'ID del record nella tabella mod_*',
         `scheda_key` VARCHAR(100) NOT NULL COMMENT 'Chiave/nome della scheda (es. struttura, dettagli)',
         `status` ENUM('not_started', 'draft', 'submitted') NOT NULL DEFAULT 'not_started',
         `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         `updated_by` INT NULL COMMENT 'user_id di chi ha fatto ultima azione',
         UNIQUE KEY `uk_form_record_scheda` (`form_id`, `record_id`, `scheda_key`),
         INDEX `idx_form_record` (`form_id`, `record_id`),
         INDEX `idx_status` (`status`)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
       ========================= */

    /* =========================
       SOLUZIONE SEMPLIFICATA: SQL per colonne flag nel record principale
       Da eseguire manualmente per ogni tabella mod_* che usa schede:

       ALTER TABLE `mod_NOME_MODULO` 
       ADD COLUMN `user_scheda_submitted` TINYINT(1) DEFAULT 0 COMMENT '1 se utente ha fatto submit della sua scheda',
       ADD COLUMN `responsabile_scheda_submitted` TINYINT(1) DEFAULT 0 COMMENT '1 se responsabile ha fatto submit della sua scheda';

       NOTA: Queste colonne vengono aggiunte automaticamente se mancanti durante submit/update,
       ma ?? consigliabile aggiungerle manualmente per evitare errori.
       ========================= */

    /* =========================
       Costanti per tipi condizione workflow
       ========================= */
    const CONDITION_ALWAYS = 'always';
    const CONDITION_AFTER_STEP_SAVED = 'after_step_saved';
    const CONDITION_AFTER_STEP_SUBMITTED = 'after_step_submitted';
    const CONDITION_AFTER_ALL_PREVIOUS_SUBMITTED = 'after_all_previous_submitted';

    /* =========================
       Gestione Schede Personalizzate
       ========================= */

    /**
     * MAPPA STRUTTURA SCHEDE (FASE 1 - Documentazione)
     * 
     * DOVE VIVONO I META DELLE SCHEDE:
     * - Tabella: forms
     * - Colonna: tabs_config (TEXT/JSON)
     * - Formato: JSON con struttura:
     *   {
     *     "NomeScheda": {
     *       "submit_label": "Testo bottone",
     *       "submit_action": "submit|next_step",
     *       "is_main": 0|1,
     *       "visibility_mode": "all|responsabile" (retrocompatibilit??),
     *       "unlock_after_submit_prev": 0|1 (retrocompatibilit??),
     *       "visibility_roles": ["utente", "responsabile", "assegnatario", "admin"],
     *       "edit_roles": ["utente", "responsabile", "assegnatario", "admin"],
     *       "visibility_condition": {"type": "always|after_step_saved|after_step_submitted|after_all_previous_submitted", "depends_on": "..."},
     *       "redirect_after_submit": false
     *     }
     *   }
     * 
     * DOVE VIVONO I CAMPI DELLE SCHEDE:
     * - Tabella: form_fields
     * - Colonne rilevanti:
     *   - tab_label: nome della scheda (es. "Struttura", "Dettagli")
     *   - tab_order: ordine di visualizzazione della scheda
     *   - field_name, field_type, field_placeholder, field_options, etc.
     * 
     * DOVE VIENE SALVATO LO STATO DELLE SCHEDE (submit/draft):
     * - Tabella: form_schede_status
     * - Colonne: form_id, record_id, scheda_key, status, updated_by, updated_at
     * - Status possibili: 'not_started', 'draft', 'submitted'
     * 
     * COME VIENE LETTA LA CONFIGURAZIONE:
     * - PageEditorService::getForm() carica tabs_config da forms.tabs_config
     * - Decodifica JSON e unisce con campi da form_fields raggruppati per tab_label
     * - Restituisce struttura completa con fields + configurazione per ogni scheda
     * 
     * COME VIENE DECISA VISIBILIT?? E MODIFICABILIT??:
     * - PHP: PageEditorService::calculateSchedaVisibility() (linea ~2677)
     * - JS: calculatePageVisibilityJS() in form_tabs_common.js (linea ~21)
     * - Entrambe usano visibility_roles, edit_roles, visibility_condition, schedeStatus
     * - Logica applicata in view_form.php e form_viewer.php tramite processTabsVisibilityJS()
     */

    /**
     * ANALISI FLUSSO SALVATAGGIO - FASE 1
     * 
     * AZIONE: saveFormTabs
     * ROUTER: service_router.php linea 1679
     * 
     * COSA LEGGE DAL DB:
     * - forms.id (per identificare form)
     * 
     * COSA SCRIVE NEL DB:
     * - forms.tabs_config: JSON completo con configurazione tutte le schede
     *   - submit_label, submit_action, is_main, visibility_mode, unlock_after_submit_prev
     *   - visibility_roles, edit_roles, visibility_condition, redirect_after_submit
     *   - scheda_type: calcolato da edit_roles (linea 195-198)
     *   - PROBLEMA: SOVRASCRIVE completamente, non fa merge
     * - forms.struttura_display_label: label personalizzato scheda Struttura
     * - form_fields:
     *   - DELETE tutti i campi dinamici (is_fixed=0) per ogni scheda (linea 238-245)
     *   - INSERT nuovi campi per ogni scheda (linea 248-270)
     *   - UPDATE tab_order per campi fissi (is_fixed=1) (linea 273-283)
     * 
     * PROBLEMI IDENTIFICATI:
     * 1. SOVRASCRIVE tutti i campi dinamici per ogni scheda (DELETE + INSERT)
     * 2. Se saveFormStructure viene chiamato prima, i campi salvati vengono CANCELLATI qui
     * 3. tabs_config viene SOVRASCRITTO completamente (non merge)
     * 4. Se una scheda non ?? presente in $tabs_data, viene RIMOSSA da tabs_config
     * 5. scheda_type viene calcolato da edit_roles ma pu?? essere sovrascritto se edit_roles non ?? passato correttamente
     * 
     * RACCOMANDAZIONE:
     * - Unificare con saveFormStructure in saveFormComplete() per evitare conflitti
     * - Implementare MERGE per tabs_config invece di sovrascrittura
     * - Evitare DELETE + INSERT se possibile, usare UPDATE quando il campo esiste gi??
     * 
     * Salva in forms.tabs_config (JSON) la configurazione delle schede
     * e in form_fields i campi associati a ogni scheda (tab_label, tab_order)
     */
    /**
     * @deprecated Da rimuovere entro 2026-04-01. Usare saveFormStructure.
     * Converte il vecchio formato per-tab e delega a saveFormStructure.
     */
    public static function saveFormTabs($form_name, $tabs_data)
    {
        global $database;

        error_log('[DEPRECATED] saveFormTabs() chiamato per form "' . $form_name . '" — migrare a saveFormStructure. Rimozione prevista: 2026-04-01');

        try {
            // Aggiungi colonne se mancanti (necessario prima della conversione)
            self::addColumnIfMissing('form_fields', 'tab_order', 'INT DEFAULT 0');
            self::addColumnIfMissing('form_fields', 'field_label', 'VARCHAR(255) NULL');
            self::addColumnIfMissing('forms', 'struttura_display_label', 'VARCHAR(100) NULL');

            // Salva display_label Struttura (proprietà non gestita da saveFormStructure)
            $struttura_display_label = null;
            if (isset($tabs_data['Struttura']) && is_array($tabs_data['Struttura'])) {
                $struttura_display_label = $tabs_data['Struttura']['display_label'] ?? null;
            }
            $form_stmt = $database->query("SELECT id FROM forms WHERE name = :name LIMIT 1", [':name' => $form_name], __FILE__);
            $form_row = $form_stmt ? $form_stmt->fetch(\PDO::FETCH_ASSOC) : null;
            if ($form_row) {
                $database->query(
                    "UPDATE forms SET struttura_display_label = :label WHERE id = :fid",
                    [':label' => $struttura_display_label, ':fid' => $form_row['id']],
                    __FILE__
                );
            }

            // --- Converti formato per-tab → formato flat per saveFormStructure ---
            $flatFields = [];
            $tabsConfig = [];
            foreach ($tabs_data as $tab_label => $tab_info) {
                if (!is_array($tab_info))
                    continue;
                $tab_order = (int) ($tab_info['tab_order'] ?? 0);
                $fields = isset($tab_info['fields']) ? $tab_info['fields'] : $tab_info;
                if (!is_array($fields))
                    $fields = [];

                foreach ($fields as $field) {
                    if (!empty($field['is_fixed']))
                        continue;
                    $flatFields[] = [
                        'field_name' => $field['name'] ?? '',
                        'field_type' => $field['type'] ?? '',
                        'field_options' => $field['options'] ?? [],
                        'field_label' => $field['label'] ?? '',
                        'is_fixed' => 0,
                        'sort_order' => $field['sort_order'] ?? 0,
                        'colspan' => $field['colspan'] ?? 1,
                        'parent_section_uid' => $field['parent_section_uid'] ?? '',
                        'tab_label' => $tab_label
                    ];
                }

                // Costruisci tabs_config per questa tab
                $visibilityRoles = $tab_info['visibility_roles'] ?? ['utente', 'responsabile', 'assegnatario', 'admin'];
                if (!is_array($visibilityRoles))
                    $visibilityRoles = ['utente', 'responsabile', 'assegnatario', 'admin'];
                $editRoles = $tab_info['edit_roles'] ?? $visibilityRoles;
                if (!is_array($editRoles))
                    $editRoles = $visibilityRoles;
                $visibilityCondition = $tab_info['visibility_condition'] ?? ['type' => self::CONDITION_ALWAYS];
                if (!is_array($visibilityCondition))
                    $visibilityCondition = ['type' => self::CONDITION_ALWAYS];
                $validConditions = [self::CONDITION_ALWAYS, self::CONDITION_AFTER_STEP_SAVED, self::CONDITION_AFTER_STEP_SUBMITTED, self::CONDITION_AFTER_ALL_PREVIOUS_SUBMITTED];
                if (!in_array($visibilityCondition['type'] ?? '', $validConditions))
                    $visibilityCondition['type'] = self::CONDITION_ALWAYS;

                $scheda_type = 'utente';
                if (!in_array('utente', $editRoles) && (in_array('responsabile', $editRoles) || in_array('assegnatario', $editRoles))) {
                    $scheda_type = 'responsabile';
                }

                $tabsConfig[$tab_label] = [
                    'label' => $tab_label,
                    'submit_label' => $tab_info['submit_label'] ?? null,
                    'submit_action' => $tab_info['submit_action'] ?? 'submit',
                    'is_main' => isset($tab_info['is_main']) ? (int) $tab_info['is_main'] : ($tab_label === 'Struttura' ? 1 : 0),
                    'visibility_mode' => $tab_info['visibility_mode'] ?? 'all',
                    'unlock_after_submit_prev' => isset($tab_info['unlock_after_submit_prev']) ? (int) $tab_info['unlock_after_submit_prev'] : 0,
                    'visibility_roles' => $visibilityRoles,
                    'edit_roles' => $editRoles,
                    'visibility_condition' => $visibilityCondition,
                    'redirect_after_submit' => isset($tab_info['redirect_after_submit']) ? (bool) $tab_info['redirect_after_submit'] : false,
                    'scheda_type' => $scheda_type,
                    'is_closure_tab' => isset($tab_info['is_closure_tab']) ? (int) $tab_info['is_closure_tab'] : 0
                ];
            }

            // Delega a saveFormStructure (unico percorso transazionale)
            return self::saveFormStructure([
                'form_name' => $form_name,
                'fields' => $flatFields,
                'tabs_config' => json_encode($tabsConfig, \JSON_UNESCAPED_UNICODE)
            ]);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore salvataggio schede (delegato): ' . $e->getMessage()];
        }
    }
    /**
     * Carica le regole di notifica per un form
     */
    public static function getNotificationRules($formName)
    {
        global $database;
        $stmt = $database->query("SELECT * FROM notification_rules WHERE form_name = :fn ORDER BY id DESC LIMIT 1", [':fn' => $formName], __FILE__);
        $rule = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($rule) {
            // Decodifica JSON per frontend
            $rule['events'] = json_decode($rule['events'] ?? '[]', true);
            $rule['channels'] = json_decode($rule['channels'] ?? '[]', true);
            $rule['recipients'] = json_decode($rule['recipients'] ?? '[]', true);
            $rule['messages'] = json_decode($rule['messages'] ?? '{}', true);
            return ['success' => true, 'rules' => $rule];
        } else {
            return ['success' => true, 'rules' => null];
        }
    }

    /**
     * Salva le regole di notifica
     */
    public static function saveNotificationRules($in)
    {
        global $database;
        $formName = $in['form_name'] ?? '';
        if (!$formName)
            return ['success' => false, 'message' => 'Nome form mancante'];

        $enabled = !empty($in['enabled']) ? 1 : 0;
        $events = json_encode($in['events'] ?? []);
        $channels = json_encode($in['channels'] ?? []);
        $recipients = json_encode($in['recipients'] ?? []);
        $messages = json_encode($in['messages'] ?? []);

        // Upsert
        $sql = "INSERT INTO notification_rules (form_name, enabled, events, channels, recipients, messages, created_at)
                VALUES (:fn, :en, :ev, :ch, :rc, :msg, NOW())
                ON DUPLICATE KEY UPDATE 
                enabled=:en, events=:ev, channels=:ch, recipients=:rc, messages=:msg, updated_at=NOW()";

        $database->query($sql, [
            ':fn' => $formName,
            ':en' => $enabled,
            ':ev' => $events,
            ':ch' => $channels,
            ':rc' => $recipients,
            ':msg' => $messages
        ], __FILE__);

        return ['success' => true];
    }

    /**
     * Carica le schede del form per il page editor
     */
    /**
     * Wrapper per retrocompatibilit?? - chiama getForm e converte formato per page_editor.js
     * Mantiene il formato con chiavi 'name', 'type' invece di 'field_name', 'field_type' per page_editor.js
     */
    public static function loadFormTabs($form_name)
    {
        $full = self::getForm($form_name);

        if (empty($full['success'])) {
            return ['success' => false, 'tabs' => [], 'message' => $full['message'] ?? 'Form non trovato'];
        }

        // Converti il formato dei campi da field_name/field_type a name/type per page_editor.js
        $tabs = $full['tabs'] ?? [];
        foreach ($tabs as $tab_label => &$tab_data) {
            if (isset($tab_data['fields']) && is_array($tab_data['fields'])) {
                foreach ($tab_data['fields'] as &$field) {
                    // Converti formato per page_editor.js
                    if (isset($field['field_name'])) {
                        $field['name'] = $field['field_name'];
                        unset($field['field_name']);
                    }
                    if (isset($field['field_type'])) {
                        $field['type'] = $field['field_type'];
                        unset($field['field_type']);
                    }
                    if (isset($field['field_label'])) {
                        $field['label'] = $field['field_label'];
                        unset($field['field_label']);
                    }
                    if (isset($field['field_placeholder'])) {
                        $field['placeholder'] = $field['field_placeholder'];
                        unset($field['field_placeholder']);
                    }
                    if (isset($field['field_options'])) {
                        $field['options'] = $field['field_options'];
                        unset($field['field_options']);
                    }
                }
                unset($field);
            }
        }
        unset($tab_data);

        $result = [
            'success' => true,
            'tabs' => $tabs
        ];

        // Aggiungi struttura_display_label se presente
        if (isset($full['struttura_display_label'])) {
            $result['struttura_display_label'] = $full['struttura_display_label'];
        }

        return $result;
    }

    /**
     * Wrapper sottile per retrocompatibilit?? - estrae tabs da getForm
     */
    public static function getFormFieldsByTabs($form_name)
    {
        $full = self::getForm($form_name);

        if (empty($full['success'])) {
            return ['success' => false, 'tabs' => [], 'message' => $full['message'] ?? 'Form non trovato'];
        }

        return [
            'success' => true,
            'tabs' => $full['tabs'] ?? []
        ];
    }

    /* =========================
       Registry moduli (no alias)
       ========================= */
    private static function modRegistry(): array
    {
        return [
            'gestione_richiesta' => [
                'label' => 'gestione della richiesta',
                'icon' => '/assets/icons/task-management.png',

                // default "tecnici": campi fissi; default "funzionali": config coerente con la UI guidata
                'defaults' => [
                    'fixed_fields' => ['titolo', 'descrizione', 'deadline', 'priority'],
                    'config' => [
                        'permessi' => 'responsabile_o_assegnatario',
                        'mostra_assegna' => true,
                        'consenti_forza_admin' => true,
                        'stati_visibili' => [1, 2, 3, 4, 5],
                        'nota_obbligatoria' => false,
                        'notifiche' => [
                            'invio' => ['on_assign', 'on_status_change'],
                            'canale' => 'interno' // interno | email | entrambi
                        ],
                        'ui' => [
                            'mostra_avatar' => true,
                            'mostra_badge_assegnato' => true
                        ]
                    ]
                ],

                'validate_config' => function (array $cfg) {
                    // enum/insiemi ammessi
                    $permessi_ok = ['responsabile_o_assegnatario', 'solo_responsabile', 'admin_responsabile_assegnatario'];
                    $notifiche_invio_ok = ['on_assign', 'on_status_change', 'on_due_change'];
                    $notifiche_canale_ok = ['interno', 'email', 'entrambi'];

                    // permessi
                    if (isset($cfg['permessi']) && !in_array($cfg['permessi'], $permessi_ok, true)) {
                        return ['success' => false, 'message' => 'permessi non valido'];
                    }

                    // bool semplici
                    foreach (['mostra_assegna', 'consenti_forza_admin', 'nota_obbligatoria'] as $b) {
                        if (isset($cfg[$b]) && !is_bool($cfg[$b])) {
                            return ['success' => false, 'message' => "$b deve essere boolean"];
                        }
                    }

                    // stati_visibili: array di interi 1..5
                    if (isset($cfg['stati_visibili'])) {
                        if (!is_array($cfg['stati_visibili']))
                            return ['success' => false, 'message' => 'stati_visibili deve essere array'];
                        foreach ($cfg['stati_visibili'] as $v) {
                            if (!in_array((int) $v, [1, 2, 3, 4, 5], true))
                                return ['success' => false, 'message' => 'stati_visibili contiene valori non validi'];
                        }
                    }

                    // notifiche
                    if (isset($cfg['notifiche'])) {
                        if (!is_array($cfg['notifiche']))
                            return ['success' => false, 'message' => 'notifiche deve essere oggetto'];
                        if (isset($cfg['notifiche']['invio'])) {
                            if (!is_array($cfg['notifiche']['invio']))
                                return ['success' => false, 'message' => 'notifiche.invio deve essere array'];
                            foreach ($cfg['notifiche']['invio'] as $e) {
                                if (!in_array($e, $notifiche_invio_ok, true))
                                    return ['success' => false, 'message' => 'notifiche.invio contiene valori non validi'];
                            }
                        }
                        if (isset($cfg['notifiche']['canale']) && !in_array($cfg['notifiche']['canale'], $notifiche_canale_ok, true)) {
                            return ['success' => false, 'message' => 'notifiche.canale non valido'];
                        }
                    }

                    // ui
                    if (isset($cfg['ui'])) {
                        if (!is_array($cfg['ui']))
                            return ['success' => false, 'message' => 'ui deve essere oggetto'];
                        foreach (['mostra_avatar', 'mostra_badge_assegnato'] as $b) {
                            if (isset($cfg['ui'][$b]) && !is_bool($cfg['ui'][$b])) {
                                return ['success' => false, 'message' => "ui.$b deve essere boolean"];
                            }
                        }
                    }

                    // ok
                    return ['success' => true];
                },

                'on_attach' => function (string $form_name) {
                    \Services\PageEditorService::ensureForm($form_name);
                    \Services\PageEditorService::ensureFixedFields($form_name);
                },
                'before_save_structure' => function (string $form_name) {
                    \Services\PageEditorService::ensureFixedFields($form_name);
                }
            ],
        ];
    }

    private static function sanitizeFormName($form_name)
    {
        if (!$form_name)
            return '';
        return 'mod_' . strtolower(preg_replace('/[^a-z0-9_]/i', '_', $form_name));
    }

    /* =========================
       Moduli: API
       ========================= */
    public static function getModulesRegistry(): array
    {
        $mods = self::modRegistry();
        $out = [];
        foreach ($mods as $k => $m) {
            $out[] = [
                'key' => $k,
                'label' => $m['label'] ?? $k,
                'icon' => $m['icon'] ?? null
            ];
        }
        return ['success' => true, 'modules' => $out];
    }

    public static function getModuleConfig(array $in): array
    {
        // DISABILITATO: Sistema modulare forms_modules rimosso
        return ['success' => false, 'message' => 'sistema moduli disabilitato'];

        /* CODICE ORIGINALE COMMENTATO
        global $database;
        $form_name  = isset($in['form_name'])  ? trim((string)$in['form_name'])  : '';
        $module_key = isset($in['module_key']) ? strtolower(preg_replace('/[^a-z0-9_]/i', '_', (string)$in['module_key'])) : 'gestione_richiesta';
        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name)) {
            return ['success' => false, 'message' => 'nome pagina non valido'];
        }

        $registry = self::modRegistry();
        if (!isset($registry[$module_key])) {
            return ['success' => false, 'message' => 'modulo sconosciuto'];
        }

        // defaults del modulo
        $defaults_cfg = $registry[$module_key]['defaults']['config'] ?? [];
        if (!is_array($defaults_cfg)) $defaults_cfg = [];

        // risolvi form_id
        $f = $database->query(
            "SELECT id FROM forms WHERE name=:n LIMIT 1",
            [':n' => $form_name],
            __FILE__ . ' ??? getModuleConfig.form'
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$f) return ['success' => false, 'message' => 'form non trovato'];
        $form_id = (int)$f['id'];

        // override da forms_modules
        $row = $database->query(
            "SELECT config_json FROM forms_modules WHERE form_id=:f AND module_key=:k LIMIT 1",
            [':f' => $form_id, ':k' => $module_key],
            __FILE__ . ' ??? getModuleConfig.fm'
        )->fetch(\PDO::FETCH_ASSOC);

        $cfg = $defaults_cfg;
        if ($row && !empty($row['config_json'])) {
            $dec = json_decode($row['config_json'], true);
            if (is_array($dec)) {
                $cfg = array_replace_recursive($defaults_cfg, $dec);
            }
        }

        // opzionale: validazione con la stessa closure dei defaults
        if (isset($registry[$module_key]['validate_config']) && is_callable($registry[$module_key]['validate_config'])) {
            $val = $registry[$module_key]['validate_config']($cfg);
            if (!($val['success'] ?? false)) return $val;
        }

        return ['success' => true, 'config' => $cfg, 'module_key' => $module_key];
        */
    }

    public static function listResponsabili($input = []): array
    {
        global $database;
        // Facoltativo: filtro "attivi" se la colonna esiste; altrimenti ignora
        // Usiamo una query semplice e robusta
        $sql = "SELECT user_id, Nominativo 
                FROM personale 
                WHERE COALESCE(Nominativo,'') <> '' 
            ORDER BY Nominativo ASC";
        $st = $database->query($sql, [], __FILE__ . ' — listResponsabili');
        $out = [];
        if ($st) {
            while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
                $uid = (int) ($r['user_id'] ?? 0);
                $nom = trim((string) ($r['Nominativo'] ?? ''));
                if ($uid > 0 && $nom !== '') {
                    $img = function_exists('get_profile_image') ? get_profile_image($nom, 'nominativo') : 'assets/images/default_profile.png';
                    $out[] = ['id' => $uid, 'label' => $nom, 'img' => $img];
                }
            }
        }
        return ['success' => true, 'options' => $out];
    }

    public static function getAttachedModules($input): array
    {
        // DISABILITATO: Sistema modulare forms_modules rimosso
        return ['success' => true, 'modules' => []];

        /* CODICE ORIGINALE COMMENTATO
        global $database;
        $form_name = isset($input['form_name']) ? trim((string)$input['form_name']) : '';
        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name)) {
            return ['success' => false, 'message' => 'nome pagina non valido'];
        }
        $form = $database->query("SELECT id FROM forms WHERE name=:n LIMIT 1", [':n' => $form_name], __FILE__);
        $row  = $form ? $form->fetch(\PDO::FETCH_ASSOC) : null;
        if (!$row) return ['success' => true, 'modules' => []];

        $res = $database->query(
            "SELECT module_key, config_json, sort_order
             FROM forms_modules
             WHERE form_id=:f
             ORDER BY sort_order ASC, id ASC",
            [':f' => (int)$row['id']],
            __FILE__
        );
        $mods = [];
        if ($res) {
            while ($r = $res->fetch(\PDO::FETCH_ASSOC)) {
                $mods[] = [
                    'key'    => (string)$r['module_key'],
                    'config' => $r['config_json' json_decode($r['config_json'], true) : null,
                    'sort'   => (int)$r['sort_order']
                ];
            }
        }
        return ['success' => true, 'modules' => $mods];
        */
    }

    public static function attachModule($input): array
    {
        // DISABILITATO: Sistema modulare forms_modules rimosso
        return ['success' => false, 'message' => 'sistema moduli disabilitato'];

        /* CODICE ORIGINALE COMMENTATO
        global $database;
        $registry = self::modRegistry();

        // 1) Leggi e valida input
        $form_name  = isset($input['form_name'])  ? trim((string)$input['form_name'])  : '';
        $module_key = isset($input['module_key']) ? strtolower(preg_replace('/[^a-z0-9_]/i', '_', (string)$input['module_key'])) : '';
        $config     = $input['config'] ?? null;

        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name)) {
            return ['success' => false, 'message' => 'nome pagina non valido'];
        }
        if ($module_key === '') {
            return ['success' => false, 'message' => 'module_key mancante'];
        }
        if (!isset($registry[$module_key])) {
            return ['success' => false, 'message' => 'modulo sconosciuto'];
        }

        // 2) Normalizza config (accetta stringa JSON o array)
        if (is_string($config)) {
            $dec = json_decode($config, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $config = $dec;
            } else {
                $config = null;
            }
        }
        if (!is_array($config)) $config = [];

        // 3) Merge con defaults del modulo (se presenti)
        $defaults_cfg = $registry[$module_key]['defaults']['config'] ?? [];
        if (!is_array($defaults_cfg)) $defaults_cfg = [];
        $config = array_replace_recursive($defaults_cfg, $config);

        // 4) Ensure form e recupera id
        $f = $database->query(
            "SELECT id FROM forms WHERE name=:n LIMIT 1",
            [':n' => $form_name],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$f) {
            $mk = self::ensureForm($form_name);
            if (!($mk['success'] ?? false)) {
                return ['success' => false, 'message' => 'impossibile creare il form'];
            }
            $f = $database->query(
                "SELECT id FROM forms WHERE name=:n LIMIT 1",
                [':n' => $form_name],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);
        }
        $form_id = (int)$f['id'];

        // 5) Evita duplicati
        $already = $database->query(
            "SELECT id FROM forms_modules WHERE form_id=:f AND module_key=:k LIMIT 1",
            [':f' => $form_id, ':k' => $module_key],
            __FILE__
        );
        if ($already && $already->rowCount() > 0) {
            return ['success' => true, 'message' => 'gi?? presente'];
        }

        // 6) Hook on_attach
        $hook = $registry[$module_key]['on_attach'] ?? null;
        if (is_callable($hook)) {
            try {
                $hook($form_name);
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => 'errore on_attach: ' . $e->getMessage()];
            }
        }

        // 7) Salva modulo + config (se non vuota)
        $config_json = !empty($config) ? json_encode($config, JSON_UNESCAPED_UNICODE) : null;
        if ($config_json === false) $config_json = null;

        $database->query(
            "INSERT INTO forms_modules (form_id, module_key, config_json, sort_order)
            VALUES (:f, :k, :c, 100)",
            [':f' => $form_id, ':k' => $module_key, ':c' => $config_json],
            __FILE__
        );

        return ['success' => true];
        */
    }

    public static function detachModule($input): array
    {
        // DISABILITATO: Sistema modulare forms_modules rimosso
        return ['success' => false, 'message' => 'sistema moduli disabilitato'];

        /* CODICE ORIGINALE COMMENTATO
        global $database;
        $registry = self::modRegistry();

        $form_name  = isset($input['form_name'])  ? trim((string)$input['form_name'])  : '';
        $module_key = isset($input['module_key']) ? strtolower(preg_replace('/[^a-z0-9_]/i', '_', (string)$input['module_key'])) : '';

        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name)) return ['success' => false, 'message' => 'nome pagina non valido'];
        if ($module_key === '') return ['success' => false, 'message' => 'module_key mancante'];
        if (!isset($registry[$module_key])) return ['success' => false, 'message' => 'modulo sconosciuto'];

        $f = $database->query("SELECT id FROM forms WHERE name=:n LIMIT 1", [':n' => $form_name], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        if (!$f) return ['success' => false, 'message' => 'form inesistente'];
        $form_id = (int)$f['id'];

        $hook = $registry[$module_key]['on_detach'] ?? null;
        if (is_callable($hook)) {
            try {
                $hook($form_name);
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => 'errore on_detach: ' . $e->getMessage()];
            }
        }

        $database->query(
            "DELETE FROM forms_modules WHERE form_id=:f AND module_key=:k",
            [':f' => $form_id, ':k' => $module_key],
            __FILE__
        );
        return ['success' => true];
        */
    }

    public static function saveModuleConfig($input): array
    {
        // DISABILITATO: Sistema modulare forms_modules rimosso
        return ['success' => false, 'message' => 'sistema moduli disabilitato'];

        /* CODICE ORIGINALE COMMENTATO
        global $database;
        $registry = self::modRegistry();

        $form_name  = isset($input['form_name'])  ? trim((string)$input['form_name'])  : '';
        $module_key = isset($input['module_key']) ? strtolower(preg_replace('/[^a-z0-9_]/i', '_', (string)$input['module_key'])) : '';
        $config     = $input['config'] ?? null;

        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name)) return ['success' => false, 'message' => 'nome pagina non valido'];
        if ($module_key === '') return ['success' => false, 'message' => 'module_key mancante'];
        if (!isset($registry[$module_key])) return ['success' => false, 'message' => 'modulo sconosciuto'];
        if (!is_array($config)) return ['success' => false, 'message' => 'config non valida'];

        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($json === false) return ['success' => false, 'message' => 'config non codificabile'];

        if (isset($registry[$module_key]['validate_config']) && is_callable($registry[$module_key]['validate_config'])) {
            $val = $registry[$module_key]['validate_config']($config);
            if (!($val['success'] ?? false)) return $val;
        }

        $f = $database->query("SELECT id FROM forms WHERE name = :n LIMIT 1", [':n' => $form_name], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        if (!$f) return ['success' => false, 'message' => 'form inesistente'];
        $form_id = (int)$f['id'];

        $database->query(
            "UPDATE forms_modules SET config_json=:c
         WHERE form_id=:f AND module_key=:k",
            [':c' => $json, ':f' => $form_id, ':k' => $module_key],
            __FILE__
        );

        return ['success' => true];
        */
    }

    /** slug semplice coerente con JS */
    private static function slug(string $s): string
    {
        $s = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $s));
        $s = preg_replace('/^_+|_+$/', '', $s);
        return $s === '' ? '_' : $s;
    }

    /**
     * ANALISI FLUSSO SALVATAGGIO - FASE 1
     * 
     * AZIONE: beforeSaveStructure
     * ROUTER: service_router.php linea 1767
     * 
     * COSA FA:
     * - PLACEHOLDER/HOOK: Chiama callback before_save_structure dai moduli registrati
     * - Non legge nulla dal DB
     * - Non scrive nulla nel DB
     * - Solo validazione nome form
     * 
     * PROBLEMA IDENTIFICATO:
     * - Questa funzione ?? praticamente un placeholder per hook futuri
     * - Non fa nulla di reale, pu?? essere rimossa o usata solo per validazione
     * 
     * RACCOMANDAZIONE:
     * - Rimuovere completamente o trasformare in validazione semplice pre-salvataggio
     */
    public static function beforeSaveStructure($input): array
    {
        $registry = self::modRegistry();
        $form_name = isset($input['form_name']) ? trim((string) $input['form_name']) : '';
        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name))
            return ['success' => false, 'message' => 'nome pagina non valido'];

        try {
            foreach ($registry as $k => $m) {
                if (isset($m['before_save_structure']) && is_callable($m['before_save_structure'])) {
                    $m['before_save_structure']($form_name);
                }
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'errore before_save_structure: ' . $e->getMessage()];
        }
        return ['success' => true];
    }

    public static function setFormResponsabile($input): array
    {
        global $database;

        $form_name = isset($input['form_name']) ? trim((string) $input['form_name']) : '';
        $user_ids_raw = $input['user_id'] ?? $input['user_ids'] ?? null;

        if ($form_name === '' || !preg_match('/^[\w\s\-àèéùòì]+$/ui', $form_name)) {
            return ['success' => false, 'message' => 'nome pagina non valido'];
        }

        // normalizza: consenti null, intero o stringa di ID separati da virgola
        $valid_ids = [];
        if (!is_null($user_ids_raw) && $user_ids_raw !== '') {
            if (is_array($user_ids_raw)) {
                $ids = $user_ids_raw;
            } else {
                $ids = explode(',', (string) $user_ids_raw);
            }

            foreach ($ids as $id) {
                $id = trim($id);
                if (ctype_digit($id) && (int) $id > 0) {
                    $valid_ids[] = (int) $id;
                }
            }
        }

        // verifica che il form esista
        $f = $database->query(
            "SELECT id, responsabile FROM forms WHERE name=:n LIMIT 1",
            [':n' => $form_name],
            __FILE__ . ' — setFormResponsabile.form'
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$f)
            return ['success' => false, 'message' => 'form non trovato'];

        $form_id = (int) $f['id'];
        $old_responsabili = explode(',', (string) ($f['responsabile'] ?? ''));

        // verifica esistenza di ogni responsabile sulla tabella personale
        $final_ids = [];
        foreach ($valid_ids as $u_id) {
            $st = $database->query(
                "SELECT 1 FROM personale WHERE user_id=:u LIMIT 1",
                [':u' => $u_id],
                __FILE__ . ' — setFormResponsabile.checkUser'
            );
            if ($st && $st->fetch(\PDO::FETCH_NUM)) {
                $final_ids[] = $u_id;
            }
        }

        $final_ids = array_unique($final_ids);
        $responsabile_str = !empty($final_ids) ? implode(',', $final_ids) : null;

        // update
        $database->query(
            "UPDATE forms SET responsabile = :r WHERE id = :id LIMIT 1",
            [':r' => $responsabile_str, ':id' => $form_id],
            __FILE__ . ' — setFormResponsabile.update'
        );

        // Notifica ai nuovi responsabili (quelli non presenti prima)
        foreach ($final_ids as $u_id) {
            if (!in_array((string) $u_id, $old_responsabili)) {
                try {
                    $autore = $database->getNominativoByUserId((int) ($_SESSION['user_id'] ?? 0)) ?? 'qualcuno';
                    $msg = '<div class="notifica-categoria-configurazione"><strong>' . htmlspecialchars($autore, ENT_QUOTES, 'UTF-8') . '</strong> ti ha nominato responsabile del modulo <strong>' . htmlspecialchars($form_name, ENT_QUOTES, 'UTF-8') . '</strong></div>';
                    $link = "index.php?section=configurazione&page=page-editor&form_name=" . urlencode($form_name);
                    \Services\NotificationService::inviaNotifica($u_id, $msg, $link);
                } catch (\Throwable $e) {
                    error_log("Errore invio notifica in setFormResponsabile: " . $e->getMessage());
                }
            }
        }

        return ['success' => true];
    }

    /**
     * Aggiorna metadati form (descrizione, colore, display_name)
     */
    public static function updateFormMeta($input): array
    {
        global $database;

        $form_name = isset($input['form_name']) ? trim((string) $input['form_name']) : '';
        if ($form_name === '') {
            return ['success' => false, 'message' => 'form_name mancante'];
        }

        // Verifica che il form esista
        $f = $database->query(
            "SELECT id FROM forms WHERE name=:n LIMIT 1",
            [':n' => $form_name],
            __FILE__ . ' ??? updateFormMeta.form'
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$f)
            return ['success' => false, 'message' => 'form non trovato'];
        $form_id = (int) $f['id'];

        // Costruisci query di update dinamica
        $updates = [];
        $params = [':id' => $form_id];

        if (isset($input['description'])) {
            $updates[] = 'description = :desc';
            $params[':desc'] = trim((string) $input['description']);
        }

        if (isset($input['color'])) {
            $color = trim((string) $input['color']);
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $updates[] = 'color = :color';
                $params[':color'] = strtoupper($color);
            }
        }

        if (isset($input['display_name'])) {
            // Assicuriamoci che la colonna display_name esista
            self::addColumnIfMissing('forms', 'display_name', 'VARCHAR(255) NULL');
            $updates[] = 'display_name = :dname';
            $params[':dname'] = trim((string) $input['display_name']);
        }

        if (empty($updates)) {
            return ['success' => true, 'message' => 'nessun campo da aggiornare'];
        }

        $sql = "UPDATE forms SET " . implode(', ', $updates) . " WHERE id = :id LIMIT 1";
        $database->query($sql, $params, __FILE__ . ' ??? updateFormMeta.update');

        return ['success' => true];
    }

    /* =========================
       Gestione struttura form
       ========================= */
    public static function ensureForm(string $raw_name, ?string $description = null, ?string $color = null, ?string $button_text = null): array
    {
        global $database;

        // Assicura che la colonna button_text esista
        self::addColumnIfMissing('forms', 'button_text', 'VARCHAR(50) NULL');

        // fallback: se il router non passa $color, prova dal body JSON (php://input) **solo se content-type ?? json**
        if ($color === null) {
            $ct = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';
            if (strpos($ct, 'application/json') !== false) {
                // ATTENZIONE: php://input potrebbe essere gi?? stato letto a monte; gestisci il caso serenamente
                $raw = @file_get_contents('php://input');
                if ($raw) {
                    $j = json_decode($raw, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($j['color'])) {
                        $color = (string) $j['color'];
                    }
                }
            }
        }

        $form_name = trim(mb_strtolower($raw_name));
        $form_name = preg_replace('/\s+/u', ' ', $form_name);
        $form_name = preg_replace('/[^\w\s\-????????????]/u', '', $form_name);
        $form_name = trim($form_name);
        if ($form_name === '') {
            return ['success' => false, 'message' => 'Nome modulo mancante o non valido'];
        }

        // normalizza descrizione (facoltativa)
        $desc = null;
        if ($description !== null) {
            $d = trim($description);
            $desc = ($d === '') ? null : $d;
        }

        // normalizza colore: accetta #abc, #aabbcc, senza #, lowercase ??? salva come #AABBCC
        $col = null;
        if (is_string($color)) {
            $c = trim($color);
            if ($c !== '') {
                if ($c[0] !== '#')
                    $c = '#' . $c;
                $c = strtoupper($c);
                if (preg_match('/^#([0-9A-F]{3})$/', $c, $m)) {
                    $c = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
                }
                if (preg_match('/^#[0-9A-F]{6}$/', $c)) {
                    $col = $c;
                }
            }
        }
        if ($col === null)
            $col = '#CCCCCC';

        // gi?? esiste?
        $exists = $database->query(
            "SELECT id, table_name, description, color FROM forms WHERE name = :n LIMIT 1",
            [':n' => $form_name],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);
        if ($exists) {
            // aggiorna descrizione se fornita e cambiata
            if ($desc !== null && (string) ($exists['description'] ?? '') !== (string) $desc) {
                $database->query(
                    "UPDATE forms SET description = :d WHERE id = :id",
                    [':d' => $desc, ':id' => (int) $exists['id']],
                    __FILE__ . ' ??? ensureForm.updateDesc'
                );
            }
            // aggiorna colore se fornito e cambiato
            if ($color !== null && (string) ($exists['color'] ?? '#CCCCCC') !== (string) $col) {
                $database->query(
                    "UPDATE forms SET color = :c WHERE id = :id",
                    [':c' => $col, ':id' => (int) $exists['id']],
                    __FILE__ . ' ??? ensureForm.updateColor'
                );
            }
            // aggiorna button_text se fornito
            if ($button_text !== null) {
                $btn_text = trim($button_text);
                $btn_text = ($btn_text === '' || $btn_text === 'Salva') ? null : $btn_text; // NULL = default
                $database->query(
                    "UPDATE forms SET button_text = :bt WHERE id = :id",
                    [':bt' => $btn_text, ':id' => (int) $exists['id']],
                    __FILE__ . ' ??? ensureForm.updateButtonText'
                );
            }
            return ['success' => true, 'message' => 'Esiste gi??', 'form_name' => $form_name, 'created' => false];
        }

        $table_name = 'mod_' . strtolower(preg_replace('/[^a-z0-9_]/i', '_', $form_name));
        $responsabile = (string) ($_SESSION['user_id'] ?? '0');

        // evita collisione tabella
        $tExists = $database->query("SHOW TABLES LIKE :t", [':t' => $table_name], __FILE__);
        if ($tExists && $tExists->rowCount() > 0) {
            return ['success' => false, 'message' => "Tabella $table_name gi?? esistente ma form non presente: situazione incoerente."];
        }

        // genera protocollo univoco
        $iniziali = strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', explode(' ', $form_name))));
        $baseProt = "RS_{$iniziali}";
        $protocollo = $baseProt;
        $k = 1;
        while (true) {
            $r = $database->query(
                "SELECT COUNT(*) c FROM forms WHERE protocollo = :p",
                [':p' => $protocollo],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);
            if (intval($r['c'] ?? 0) === 0)
                break;
            $protocollo = "{$baseProt}_{$k}";
            $k++;
        }

        // Normalizza button_text
        $btn_text = null;
        if ($button_text !== null) {
            $bt = trim($button_text);
            $btn_text = ($bt === '' || $bt === 'Salva') ? null : $bt;
        }

        $database->query(
            "INSERT INTO forms (name, description, table_name, responsabile, created_by, created_at, color, protocollo, button_text)
            VALUES (:n, :d, :t, :r, :cb, NOW(), :c, :p, :bt)",
            [
                ':n' => $form_name,
                ':d' => $desc,                      // pu?? essere NULL
                ':t' => $table_name,
                ':r' => $responsabile,
                ':cb' => ($_SESSION['user_id'] ?? 0),
                ':c' => $col,                       // usa il colore richiesto
                ':p' => $protocollo,
                ':bt' => $btn_text                  // testo bottone personalizzato
            ],
            __FILE__
        );

        // id form affidabile
        $form_id = (int) $database->lastInsertId();
        if ($form_id <= 0) {
            $tmp = $database->query(
                "SELECT id FROM forms WHERE name=:n LIMIT 1",
                [':n' => $form_name],
                __FILE__ . ' ??? ensureForm.getId'
            );
            $row = $tmp ? $tmp->fetch(\PDO::FETCH_ASSOC) : null;
            $form_id = (int) ($row['id'] ?? 0);
        }
        if ($form_id <= 0) {
            return ['success' => false, 'message' => 'Impossibile determinare l???ID del form appena creato'];
        }

        // tabella dati base + campi fissi
        $ddl = "CREATE TABLE `$table_name` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            submitted_by INT NOT NULL,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deadline DATE NULL,
            titolo VARCHAR(255) NOT NULL,
            descrizione TEXT NOT NULL,
            priority VARCHAR(255) DEFAULT 'Media',
            assegnato_a VARCHAR(255) NULL COMMENT 'ID assegnatari separati da virgola (max 5)',
            status_id INT DEFAULT 1,
            completed_at DATETIME NULL,
            codice_segnalazione VARCHAR(255) DEFAULT NULL,
            esito_stato VARCHAR(50) DEFAULT NULL,
            esito_note TEXT DEFAULT NULL,
            esito_data_prevista DATE DEFAULT NULL,
            esito_data DATETIME DEFAULT NULL
        )";
        $database->query($ddl, [], __FILE__);

        // form_fields fissi (solo se non esistono gi??)
        $fissi = [
            ['name' => 'titolo', 'type' => 'text', 'ph' => 'Titolo della segnalazione del problema', 'opts' => null, 'req' => 1],
            ['name' => 'descrizione', 'type' => 'textarea', 'ph' => 'Descrizione dettagliata del problema', 'opts' => null, 'req' => 1],
            ['name' => 'deadline', 'type' => 'date', 'ph' => 'Inserisci la data di scadenza', 'opts' => null, 'req' => 1],
            ['name' => 'priority', 'type' => 'select', 'ph' => 'Seleziona una priority', 'opts' => json_encode(['Bassa', 'Media', 'Alta'], JSON_UNESCAPED_UNICODE), 'req' => 1],
            [
                'name' => 'assegnato_a',
                'type' => 'dbselect',
                'ph' => 'Seleziona assegnatario',
                'opts' => json_encode([
                    'table' => 'personale',
                    'valueCol' => 'user_id',
                    'labelCol' => 'Nominativo',
                    'q' => '',
                    'limit' => 200,
                    'multiple' => 0
                ], JSON_UNESCAPED_UNICODE),
                'req' => 0
            ]
        ];

        // Verifica campi fissi esistenti
        $existingFixed = $database->query(
            "SELECT lower(field_name) as fn FROM form_fields WHERE form_id = :fid AND is_fixed = 1",
            [':fid' => $form_id],
            __FILE__
        );
        $existingFixedNames = [];
        if ($existingFixed) {
            while ($r = $existingFixed->fetch(\PDO::FETCH_ASSOC)) {
                $existingFixedNames[$r['fn']] = true;
            }
        }

        foreach ($fissi as $cf) {
            $fnLower = strtolower($cf['name']);
            if (isset($existingFixedNames[$fnLower])) {
                // Campo fisso gi?? esistente: aggiorna invece di inserire
                $database->query(
                    "UPDATE form_fields
                     SET field_type = :ft, field_placeholder = :ph, field_options = :fo, tab_label = 'Struttura'
                     WHERE form_id = :fid AND lower(field_name) = :fn AND is_fixed = 1",
                    [
                        ':fid' => $form_id,
                        ':fn' => $fnLower,
                        ':ft' => $cf['type'],
                        ':ph' => $cf['ph'],
                        ':fo' => $cf['opts']
                    ],
                    __FILE__
                );
            } else {
                // Inserisci nuovo campo fisso
                $database->query(
                    "INSERT INTO form_fields
                    (form_id, field_name, field_type, field_placeholder, field_options, required, is_fixed, tab_label)
                    VALUES(:fid,:fn,:ft,:ph,:fo,:req,1,'Struttura')",
                    [
                        ':fid' => $form_id,
                        ':fn' => $cf['name'],
                        ':ft' => $cf['type'],
                        ':ph' => $cf['ph'],
                        ':fo' => $cf['opts'],
                        ':req' => $cf['req'] ?? 1
                    ],
                    __FILE__
                );
            }
        }

        return ['success' => true, 'created' => true, 'form_name' => $form_name, 'table_name' => $table_name];
    }

    /**
     * Funzione canonica unica per caricare form completo (meta + tabs + fields)
     * Questa ?? l'unica fonte autoritativa, tutte le altre funzioni sono wrapper
     * 
     * @param string $form_name Nome del form
     * @param int|null $record_id Opzionale: ID del record da caricare (unifica getFormEntry)
     */
    /**
     * ANALISI FLUSSO SALVATAGGIO - FASE 1
     * 
     * AZIONE: getForm
     * ROUTER: service_router.php linea 1648
     * 
     * COSA LEGGE DAL DB:
     * - forms.* (tutti i campi della tabella forms)
     * - forms.tabs_config (JSON con configurazione schede)
     * - forms.struttura_display_label (label personalizzato scheda Struttura)
     * - form_fields.* (tutti i campi, raggruppati per tab_label)
     * 
     * COSA RESTITUISCE:
     * - form: meta form (name, color, button_text, etc.)
     * - tabs: array schede con fields + configurazione (submit_label, edit_roles, scheda_type, etc.)
     * - fields: campi flat (retrocompatibilit??)
     * - entry: dati record se record_id presente
     * 
     * MANIPOLAZIONI:
     * - Decodifica tabs_config JSON
     * - Raggruppa form_fields per tab_label
     * - Unisce configurazione da tabs_config con campi da form_fields
     * - Costruisce fields flat per retrocompatibilit??
     * 
     * NOTA: Questa funzione ?? chiamata prima e dopo il salvataggio per verificare lo stato
     */
    public static function getForm($form_name, $record_id = null)
    {
        global $database;

        try {
            // Carica meta form
            $formMeta = self::loadFormMeta($form_name);
            if (!$formMeta) {
                return ['success' => false, 'message' => 'Modulo non trovato'];
            }

            // Carica tabs con campi
            $tabsData = self::loadTabsWithFields($form_name, $formMeta['id']);
            if (!$tabsData['success']) {
                return $tabsData;
            }

            // Costruisci fields flat da tabs (opzionale, per retrocompatibilit??)
            $fieldsFlat = self::buildFlatFieldsFromTabs($tabsData['tabs']);

            // Prepara risultato completo
            $result = [
                'success' => true,
                'form' => $formMeta['form'],
                'tabs' => $tabsData['tabs']
            ];

            // Aggiungi fields flat se necessario
            if (!empty($fieldsFlat)) {
                $result['fields'] = $fieldsFlat;
            }

            // Aggiungi struttura_display_label se presente
            if (isset($tabsData['struttura_display_label'])) {
                $result['struttura_display_label'] = $tabsData['struttura_display_label'];
            }

            // Se record_id ?? presente, carica anche i dati del record (unifica getFormEntry)
            if ($record_id !== null && $record_id > 0) {
                $entryData = \Services\FormsDataService::getFormEntry([
                    'form_name' => $form_name,
                    'record_id' => (int) $record_id
                ]);

                if ($entryData && $entryData['success']) {
                    // Aggiungi entry data al risultato
                    $result['entry'] = $entryData;
                }
            }

            return $result;

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore caricamento form: ' . $e->getMessage()];
        }
    }

    /**
     * Metodo privato helper: carica meta form
     */
    private static function loadFormMeta(string $form_name): ?array
    {
        global $database;

        $res = $database->query("SELECT * FROM forms WHERE name = :form_name", [':form_name' => $form_name], __FILE__);
        $form = $res instanceof \PDOStatement ? $res->fetch(\PDO::FETCH_ASSOC) : null;
        if (!$form)
            return null;

        // Applica fixMojibake ai campi del form
        foreach ($form as $key => $value) {
            $form[$key] = fixMojibake($value ?? '');
        }

        // Sanitizza il nome del form per visualizzazione (underscore ??? spazi) - dopo fixMojibake
        $form['display_name'] = ucwords(str_replace('_', ' ', $form['name']));

        return ['form' => $form, 'id' => (int) $form['id']];
    }

    /**
     * Metodo privato helper: carica tabs con campi e configurazione
     */
    private static function loadTabsWithFields(string $form_name, int $form_id): array
    {
        global $database;

        try {
            // Assicurati che le colonne esistano
            self::addColumnIfMissing('form_fields', 'tab_label', 'varchar(100) null');
            self::addColumnIfMissing('form_fields', 'tab_order', 'INT DEFAULT 0');
            self::addColumnIfMissing('forms', 'struttura_display_label', 'VARCHAR(100) NULL');
            self::addColumnIfMissing('forms', 'tabs_config', 'TEXT NULL');

            // Trova struttura_display_label e tabs_config
            $form = $database->query(
                "SELECT struttura_display_label, tabs_config FROM forms WHERE id = :id LIMIT 1",
                [':id' => $form_id],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            $struttura_display_label = fixMojibake($form['struttura_display_label'] ?? null);

            // Carica tabs_config (submit_label, submit_action e nuove propriet?? per ogni scheda)
            $tabs_config = [];
            if (!empty($form['tabs_config'])) {
                try {
                    $tabs_config = json_decode($form['tabs_config'], true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($tabs_config)) {
                        error_log("Errore parsing JSON tabs_config per form_id " . ($form['id'] ?? 'unknown') . ": " . json_last_error_msg());
                        $tabs_config = [];
                    } else {
                        // Applica fixMojibake ai valori del tabs_config
                        array_walk_recursive($tabs_config, function (&$value) {
                            if (is_string($value)) {
                                $value = fixMojibake($value);
                            }
                        });
                    }
                } catch (\Exception $e) {
                    error_log("Errore parsing tabs_config per form_id " . ($form['id'] ?? 'unknown') . ": " . $e->getMessage());
                    $tabs_config = [];
                }
            }

            // Assicurati che i campi fissi abbiano tab_label='Struttura' (migrazione per form esistenti)
            $database->query(
                "UPDATE form_fields SET tab_label='Struttura' WHERE form_id=:fid AND is_fixed=1 AND (tab_label IS NULL OR tab_label='')",
                [':fid' => $form_id],
                __FILE__ . ' ??? loadTabsWithFields.fixedTabLabel'
            );

            // Carica tutti i campi con le loro schede - ORDINATI PER tab_order
            $fields = $database->query(
                "SELECT field_name, field_type, field_label, field_placeholder, field_options, required, is_fixed, sort_order, colspan, parent_section_uid, tab_label, tab_order
                 FROM form_fields 
                 WHERE form_id = :form_id 
                 ORDER BY tab_order ASC, sort_order ASC",
                [':form_id' => $form_id],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            // Raggruppa per tab_label mantenendo l'ordine
            $tabs = [];
            $tab_order_map = [];

            foreach ($fields as $field) {
                $tab_label = $field['tab_label'] ?? 'Struttura';
                $tab_order = (int) ($field['tab_order'] ?? 0);

                if (!isset($tabs[$tab_label])) {
                    $tabs[$tab_label] = [];
                    $tab_order_map[$tab_label] = $tab_order;
                }

                // Decodifica field_options per preservare datasource dei dbselect
                $fieldOptions = $field['field_options'];
                if ($field['field_type'] === 'dbselect') {


                    // Per dbselect, decodifica il JSON e restituisci come array/oggetto
                    $decoded = json_decode($fieldOptions, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        // Mantieni come stringa se decode fallisce
                    } else {
                        $fieldOptions = is_array($decoded) ? $decoded : $fieldOptions;
                    }
                }

                $tabs[$tab_label][] = [
                    'field_name' => fixMojibake($field['field_name']),
                    'field_type' => $field['field_type'],
                    'field_placeholder' => fixMojibake($field['field_placeholder']),
                    'field_options' => is_array($fieldOptions) ? $fieldOptions : fixMojibake($fieldOptions),
                    'required' => (bool) $field['required'],
                    'is_fixed' => (bool) $field['is_fixed'],
                    'sort_order' => (int) $field['sort_order'],
                    'colspan' => (int) $field['colspan'],
                    'parent_section_uid' => $field['parent_section_uid']
                ];
            }

            // Se non ci sono tab definite, crea una tab "Struttura" di default con tutti i campi
            if (empty($tabs)) {
                $tabs['Struttura'] = [];
                $tab_order_map['Struttura'] = 0;

                // Ricarica tutti i campi senza filtro tab_label
                $allFields = $database->query(
                    "SELECT field_name, field_type, field_placeholder, field_options, required, is_fixed, sort_order, colspan, parent_section_uid
                     FROM form_fields 
                     WHERE form_id = :form_id 
                     ORDER BY sort_order ASC",
                    [':form_id' => $form_id],
                    __FILE__
                )->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($allFields as $field) {
                    $fieldOptions = $field['field_options'];
                    if ($field['field_type'] === 'dbselect') {
                        $decoded = json_decode($fieldOptions, true);
                        $fieldOptions = is_array($decoded) ? $decoded : $fieldOptions;
                    }

                    $tabs['Struttura'][] = [
                        'field_name' => $field['field_name'],
                        'field_type' => $field['field_type'],
                        'field_placeholder' => $field['field_placeholder'],
                        'field_options' => $fieldOptions,
                        'required' => (bool) $field['required'],
                        'is_fixed' => (bool) $field['is_fixed'],
                        'sort_order' => (int) $field['sort_order'],
                        'colspan' => (int) $field['colspan'],
                        'parent_section_uid' => $field['parent_section_uid']
                    ];
                }
            }

            // Riordina le schede per tab_order
            uksort($tabs, function ($a, $b) use ($tab_order_map) {
                $orderA = $tab_order_map[$a] ?? 0;
                $orderB = $tab_order_map[$b] ?? 0;
                if ($orderA === $orderB) {
                    // Se hanno lo stesso order, "Struttura" viene prima
                    if ($a === 'Struttura')
                        return -1;
                    if ($b === 'Struttura')
                        return 1;
                    return strcmp($a, $b);
                }
                return $orderA <=> $orderB;
            });

            // Aggiungi submit_label, submit_action e nuove proprietà a ogni scheda
            // --------------------------------------------------------
            // Genera tab_key stabile per ogni tab_label (stessa logica di slugifyTabName nel JS)
            $slugify = function ($s) {
                $s = $s ?: 'scheda';
                if (class_exists('\Normalizer')) {
                    $s = \Normalizer::normalize($s, \Normalizer::FORM_KD);
                    $s = preg_replace('/[\x{0300}-\x{036f}]/u', '', $s);
                } else {
                    // Fallback senza intl: rimuovi accenti comuni
                    $s = strtr($s, 'àáâãäèéêëìíîïòóôõöùúûüñç', 'aaaaaeeeeiiiioooooouuuunc');
                }
                $s = strtolower($s);
                $s = preg_replace('/[^a-z0-9_]+/', '_', $s);
                return trim($s, '_') ?: 'scheda';
            };

            // Assegna tab_key unici (gestione collisioni)
            $tab_keys_used = [];
            $tab_key_map = []; // tab_label => tab_key univoco
            foreach (array_keys($tabs) as $tLabel) {
                $base = $slugify($tLabel);
                $candidate = $base;
                $suffix = 2;
                while (isset($tab_keys_used[$candidate])) {
                    $candidate = $base . '_' . $suffix;
                    $suffix++;
                    if (defined('APP_DEBUG') && APP_DEBUG) {
                        error_log("[PageEditor] tab_key collision: '$base' → '$candidate' for tab '$tLabel'");
                    }
                }
                $tab_keys_used[$candidate] = true;
                $tab_key_map[$tLabel] = $candidate;
            }

            // Costruisci mappa di lookup: tabs_config può essere indicizzato per key o per label (retrocompat)
            $config_by_key = [];
            $config_by_label = [];
            foreach ($tabs_config as $cfg_key => $cfg_val) {
                if (isset($cfg_val['label']) && is_string($cfg_val['label'])) {
                    // Nuovo formato: chiave = tab.key, con label dentro
                    $config_by_key[$cfg_key] = $cfg_val;
                    $config_by_label[$cfg_val['label']] = $cfg_val;
                } else {
                    // Vecchio formato: la chiave è la label stessa
                    $config_by_label[$cfg_key] = $cfg_val;
                    // Prova anche a indicizzare per key derivato dalla label
                    $config_by_key[$slugify($cfg_key)] = $cfg_val;
                }
            }
            // --------------------------------------------------------
            // LOGICA AUTOMATICA CHIUSURA (ESITO)
            // --------------------------------------------------------
            $hasClosure = false;
            foreach ($tabs as $tLabel => $tFields) {
                $tKey = $tab_key_map[$tLabel] ?? $slugify($tLabel);
                $tCfg = $config_by_key[$tKey] ?? $config_by_label[$tLabel] ?? [];
                if (!empty($tCfg['isClosureTab']) || !empty($tCfg['is_closure_tab'])) {
                    $hasClosure = true;
                    break;
                }
            }

            if (!$hasClosure) {
                $esitoLabel = 'Esito';
                $esitoKey = $tab_key_map[$esitoLabel] ?? $slugify($esitoLabel);
                if (isset($tabs[$esitoLabel])) {
                    $config_by_key[$esitoKey]['isClosureTab'] = true;
                    $config_by_key[$esitoKey]['scheda_type'] = 'chiusura';
                    if (!isset($config_by_key[$esitoKey]['unlock_after_submit_prev'])) {
                        $config_by_key[$esitoKey]['unlock_after_submit_prev'] = 1;
                    }
                    if (!isset($config_by_key[$esitoKey]['edit_roles'])) {
                        $config_by_key[$esitoKey]['edit_roles'] = ['responsabile', 'assegnatario', 'admin'];
                    }
                    $config_by_label[$esitoLabel] = $config_by_key[$esitoKey];
                } else {
                    $tabs[$esitoLabel] = [];
                    // Registra il tab_key per Esito appena creato
                    $tab_key_map[$esitoLabel] = $esitoKey;
                    $tab_keys_used[$esitoKey] = true;
                    $closureCfg = [
                        'isClosureTab' => true,
                        'scheda_type' => 'chiusura',
                        'visibility_roles' => ['utente', 'responsabile', 'assegnatario', 'admin'],
                        'edit_roles' => ['responsabile', 'assegnatario', 'admin'],
                        'unlock_after_submit_prev' => 1
                    ];
                    $config_by_key[$esitoKey] = $closureCfg;
                    $config_by_label[$esitoLabel] = $closureCfg;
                }
            }
            // --------------------------------------------------------

            foreach ($tabs as $tab_label => &$tab_fields) {
                $tab_key = $tab_key_map[$tab_label] ?? $slugify($tab_label);
                // Lookup: prima per key, poi per label (retrocompatibilità)
                $cfg = $config_by_key[$tab_key] ?? $config_by_label[$tab_label] ?? [];
                $isStruttura = ($tab_label === 'Struttura');

                // Default per ruoli e condizioni
                $defaultRoles = ['utente', 'responsabile', 'assegnatario', 'admin'];
                $defaultCondition = ['type' => self::CONDITION_ALWAYS];

                $tab_fields = [
                    'tab_key' => $tab_key,
                    'fields' => $tab_fields,
                    'submit_label' => $cfg['submit_label'] ?? null,
                    'submit_action' => $cfg['submit_action'] ?? 'submit',
                    'is_main' => (int) ($cfg['is_main'] ?? ($isStruttura ? 1 : 0)),
                    'visibility_mode' => $cfg['visibility_mode'] ?? 'all',
                    'unlock_after_submit_prev' => (int) ($cfg['unlock_after_submit_prev'] ?? 0),
                    'visibility_roles' => $cfg['visibility_roles'] ?? $defaultRoles,
                    'edit_roles' => $cfg['edit_roles'] ?? ($cfg['visibility_roles'] ?? $defaultRoles),
                    'visibility_condition' => $cfg['visibility_condition'] ?? $defaultCondition,
                    'redirect_after_submit' => (bool) ($cfg['redirect_after_submit'] ?? false),
                    'scheda_type' => $cfg['scheda_type'] ?? null,
                    'isClosureTab' => (bool) ($cfg['isClosureTab'] ?? $cfg['is_closure_tab'] ?? false)
                ];
            }
            unset($tab_fields);

            return [
                'success' => true,
                'tabs' => $tabs,
                'struttura_display_label' => $struttura_display_label
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore caricamento tabs: ' . $e->getMessage()];
        }
    }

    /**
     * Metodo privato helper: costruisce fields flat da tabs
     */
    private static function buildFlatFieldsFromTabs(array $tabs): array
    {
        $allFields = [];

        foreach ($tabs as $tab_data) {
            if (isset($tab_data['fields']) && is_array($tab_data['fields'])) {
                foreach ($tab_data['fields'] as $field) {
                    // Converti da formato tabs a formato flat
                    $allFields[] = [
                        'field_name' => $field['field_name'] ?? null,
                        'field_type' => $field['field_type'] ?? null,
                        'field_placeholder' => $field['field_placeholder'] ?? null,
                        'field_options' => is_string($field['field_options'] ?? null)
                            ? $field['field_options']
                            : json_encode($field['field_options'] ?? []),
                        'required' => $field['required'] ?? 0,
                        'is_fixed' => $field['is_fixed'] ?? 0,
                        'sort_order' => $field['sort_order'] ?? 0,
                        'colspan' => $field['colspan'] ?? 1,
                        'parent_section_uid' => $field['parent_section_uid'] ?? null
                    ];
                }
            }
        }

        // Ordina per sort_order
        usort($allFields, function ($a, $b) {
            $orderA = (int) ($a['sort_order'] ?? 0);
            $orderB = (int) ($b['sort_order'] ?? 0);
            if ($orderA === $orderB) {
                return 0;
            }
            return $orderA <=> $orderB;
        });

        return $allFields;
    }

    /**
     * Wrapper sottile per retrocompatibilit?? - estrae fields da getForm
     */
    public static function getFormFields($form_name)
    {
        $full = self::getForm($form_name);

        if (empty($full['success'])) {
            return ['success' => false, 'error' => $full['message'] ?? 'Form non trovato', 'fields' => []];
        }

        // Se non hai 'fields' nel full, ricavali da tabs
        $fields = $full['fields'] ?? self::buildFlatFieldsFromTabs($full['tabs'] ?? []);

        return ['success' => true, 'fields' => $fields];
    }

    public static function addFieldToForm($params)
    {
        global $database;

        $formName = $params['form_name'] ?? null;
        $fieldName = $params['field_name'] ?? null;
        $fieldName = strtolower(preg_replace('/[^a-z0-9_]/i', '_', (string) $fieldName));
        $reserved = ['select', 'from', 'where', 'order', 'group'];
        if (in_array($fieldName, $reserved, true)) {
            $fieldName .= '_field';
        }
        $fieldType = $params['field_type'] ?? null;
        $fieldOpts = $params['field_options'] ?? null;

        if (!$formName || !$fieldName || !$fieldType) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        $resForm = $database->query("SELECT id, table_name FROM forms WHERE name = :name", [':name' => $formName], __FILE__);
        $form = $resForm ? $resForm->fetch(\PDO::FETCH_ASSOC) : null;
        if (!$form)
            return ['success' => false, 'message' => 'Form non trovato'];

        $formId = (int) $form['id'];
        $tableName = (string) $form['table_name'];

        $fieldOptionsJSON = null;
        if (!empty($fieldOpts)) {
            $decoded = json_decode((string) $fieldOpts, true);
            if (is_array($decoded)) {
                $fieldOptionsJSON = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        }

        // Verifica se il campo esiste gi?? (considerando anche parent_section_uid se presente)
        $parentUid = isset($params['parent_section_uid']) ? strtolower(trim((string) $params['parent_section_uid'])) : '';
        $existing = null;

        if ($parentUid === '') {
            $existing = $database->query(
                "SELECT id, sort_order FROM form_fields 
                 WHERE form_id = :fid 
                   AND lower(field_name) = :fn 
                   AND (is_fixed = 0 OR is_fixed IS NULL)
                   AND (parent_section_uid IS NULL OR parent_section_uid = '')",
                [':fid' => $formId, ':fn' => strtolower($fieldName)],
                __FILE__ . ' ??? addfieldtoform.check'
            )->fetch(\PDO::FETCH_ASSOC);
        } else {
            $existing = $database->query(
                "SELECT id, sort_order FROM form_fields 
                 WHERE form_id = :fid 
                   AND lower(field_name) = :fn 
                   AND (is_fixed = 0 OR is_fixed IS NULL)
                   AND lower(COALESCE(parent_section_uid, '')) = :p",
                [':fid' => $formId, ':fn' => strtolower($fieldName), ':p' => $parentUid],
                __FILE__ . ' ??? addfieldtoform.check'
            )->fetch(\PDO::FETCH_ASSOC);
        }

        if ($existing) {
            // Campo gi?? esistente: aggiorna invece di inserire
            $database->query(
                "UPDATE form_fields 
                 SET field_type = :ft, field_options = :fo
                 WHERE id = :id",
                [
                    ':id' => (int) $existing['id'],
                    ':ft' => $fieldType,
                    ':fo' => $fieldOptionsJSON
                ],
                __FILE__ . ' ??? addfieldtoform.update'
            );
            return ['success' => true, 'message' => 'Campo aggiornato', 'field_id' => (int) $existing['id']];
        }

        // calcola il prossimo sort_order tra i dinamici (fissi partono da 10..50)
        $next = $database->query(
            "select coalesce(max(sort_order), 50) + 10 as n
            from form_fields
            where form_id = :fid and (is_fixed = 0 or is_fixed is null)",
            [':fid' => $formId],
            __FILE__ . ' ??? addfieldtoform.nextsort'
        )->fetch(\PDO::FETCH_ASSOC);
        $so = (int) ($next['n'] ?? 60);

        $database->query(
            "insert into form_fields 
            (form_id, field_name, field_type, field_placeholder, field_options, required, is_fixed, sort_order, parent_section_uid)
            values 
            (:form_id, :field_name, :field_type, '', :field_options, 0, 0, :so, :p)",
            [
                ':form_id' => $formId,
                ':field_name' => $fieldName,
                ':field_type' => $fieldType,
                ':field_options' => $fieldOptionsJSON,
                ':so' => $so,
                ':p' => $parentUid === '' ? null : $parentUid
            ],
            __FILE__
        );

        $columnType = match ($fieldType) {
            'textarea' => "TEXT",
            'select', 'checkbox', 'radio', 'file', 'text' => "VARCHAR(255)",
            'date' => "DATE",
            'number' => "INT",
            default => "VARCHAR(255)"
        };

        $database->query("ALTER TABLE `$tableName` ADD `$fieldName` $columnType", [], __FILE__);

        return ['success' => true, 'message' => 'Campo aggiunto con successo', 'field_name' => $fieldName];
    }

    public static function deleteFieldFromForm($params)
    {
        global $database;

        $formName = $params['form_name'] ?? null;
        $fieldName = $params['field_name'] ?? null;
        $fieldName = strtolower(preg_replace('/[^a-z0-9_]/i', '_', (string) $fieldName));
        $reserved = ['select', 'from', 'where', 'order', 'group'];
        if (in_array($fieldName, $reserved, true)) {
            $fieldName .= '_field';
        }

        if (!$formName || !$fieldName) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        $resForm = $database->query("SELECT table_name FROM forms WHERE name = :name", [':name' => $formName], __FILE__);
        $formRow = $resForm ? $resForm->fetch(\PDO::FETCH_ASSOC) : null;
        if (!$formRow)
            return ['success' => false, 'message' => 'Form non trovato'];
        $tableName = (string) $formRow['table_name'];

        $resRow = $database->query(
            "SELECT f.id, ff.is_fixed 
             FROM forms f 
             JOIN form_fields ff ON ff.form_id = f.id 
             WHERE f.name = :name AND ff.field_name = :field_name",
            [':name' => $formName, ':field_name' => $fieldName],
            __FILE__
        );
        $row = $resRow ? $resRow->fetch(\PDO::FETCH_ASSOC) : null;

        if (!$row)
            return ['success' => false, 'message' => 'Campo non trovato'];
        if (!empty($row['is_fixed']))
            return ['success' => false, 'message' => 'Campo fisso non eliminabile'];

        $database->query(
            "DELETE FROM form_fields WHERE form_id = :id AND field_name = :field_name",
            [':id' => $row['id'], ':field_name' => $fieldName],
            __FILE__
        );

        $database->query("ALTER TABLE `$tableName` DROP COLUMN `$fieldName`", [], __FILE__);

        return ['success' => true, 'message' => 'Campo eliminato con successo'];
    }

    /**
     * ANALISI FLUSSO SALVATAGGIO - FASE 1
     * 
     * AZIONE: saveFormStructure
     * ROUTER: service_router.php linea 1660
     * 
     * COSA LEGGE DAL DB:
     * - forms.id, forms.table_name (per identificare form)
     * - form_fields.* (campi esistenti per confronto diff)
     * 
     * COSA SCRIVE NEL DB (TRANSAZIONALE):
     * - form_fields: per-tab DELETE non-fixed + INSERT (idempotente)
     * - forms.tabs_config: merge configurazione schede
     * - mod_* (tabella dati): ALTER TABLE per aggiungere colonne core se mancanti
     *
     * FLAG:
     * - replace_all_tabs=1: cancella anche tab nel DB non presenti nel payload (esclusa Struttura)
     *   Default: sostituisce solo le tab incluse nel payload.
     */
    public static function saveFormStructure($input)
    {
        global $database;


        $form_name = isset($input['form_name']) ? trim((string) $input['form_name']) : '';

        // Sanitizzazione task C.3
        $form_name = filter_var($form_name, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name)) {
            return ['success' => false, 'message' => 'nome pagina non valido', 'code' => 'VALIDATION_ERROR'];
        }

        $fields = $input['fields'] ?? [];

        // Task C.2: Parsing JSON se arriva come stringa
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'message' => 'Payload fields non valido (JSON error)',
                    'code' => 'JSON_INVALID',
                    'details' => (defined('APP_DEBUG') && APP_DEBUG) ? json_last_error_msg() : null
                ];
            }
            $fields = $decoded;
        }

        if (!is_array($fields)) {
            return ['success' => false, 'message' => 'payload campi non valido (atteso array)', 'code' => 'VALIDATION_ERROR'];
        }

        // Task B.1: Logging diagnostico
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log("[PageEditor] saveFormStructure input - form: $form_name, fields count: " . count($fields));
        }

        $stmt = $database->query(
            "select id, table_name from forms where name = :name limit 1",
            [':name' => $form_name],
            __FILE__
        );
        if (!$stmt || $stmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'modulo non trovato'];
        }
        $form = $stmt->fetch(\PDO::FETCH_ASSOC);
        $form_id = (int) $form['id'];
        $table_name = (string) $form['table_name'];

        // ===== LOG CONFLITTO: Inizio saveFormStructure =====
        error_log("[PageEditor] " . __FILE__ . " ??? saveFormStructure INIZIO - form_id: $form_id, form_name: $form_name");
        $fieldsByTab = [];
        foreach ($fields as $f) {
            $tabLabel = isset($f['tab_label']) ? trim((string) $f['tab_label']) : 'Struttura';
            if (!isset($fieldsByTab[$tabLabel])) {
                $fieldsByTab[$tabLabel] = [];
            }
            $fieldName = isset($f['field_name']) ? (string) $f['field_name'] : '';
            $fieldType = isset($f['field_type']) ? (string) $f['field_type'] : '';
            $fieldsByTab[$tabLabel][] = "$fieldName($fieldType)";
        }
        foreach ($fieldsByTab as $tabLabel => $fieldList) {
            error_log("[PageEditor] " . __FILE__ . " ??? saveFormStructure - Tab \"$tabLabel\": " . count($fieldList) . " campi ??? [" . implode(', ', $fieldList) . "]");
        }
        // =====

        if (method_exists(__CLASS__, 'addColumnIfMissing')) {
            self::addColumnIfMissing('form_fields', 'sort_order', 'int unsigned not null default 0');
            self::addColumnIfMissing('form_fields', 'colspan', 'tinyint unsigned not null default 1');
            self::addColumnIfMissing('form_fields', 'parent_section_uid', 'varchar(64) null');
            self::addColumnIfMissing('form_fields', 'tab_label', 'varchar(100) null');
        }

        $dyn_fields = [];
        $seen = [];          // per-tab dedup: $seen[$tabLabel][$name] = true
        $sectionsSeen = [];   // uid -> true (globale: sezioni uniche cross-tab)

        foreach ($fields as $idx => $f) {
            $name = isset($f['field_name']) ? strtolower(preg_replace('/[^a-z0-9_]/i', '_', (string) $f['field_name'])) : '';
            $type = isset($f['field_type']) ? strtolower((string) $f['field_type']) : '';
            $optsRaw = $f['field_options'] ?? [];
            $isFixed = !empty($f['is_fixed']) ? 1 : 0;
            $order = isset($f['sort_order']) ? (int) $f['sort_order'] : (($idx + 1) * 10);
            $colspan = isset($f['colspan']) ? (int) $f['colspan'] : 1;
            $colspan = ($colspan === 2) ? 2 : 1;
            $tabLabel = isset($f['tab_label']) ? trim((string) $f['tab_label']) : 'Struttura';

            // parent: impostato dai figli di una sezione
            $parentUid = '';
            if (isset($f['parent_section_uid'])) {
                $parentUid = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $f['parent_section_uid']));
            }

            if ($isFixed === 1)
                continue;
            if ($type === '')
                return ['success' => false, 'message' => 'tutti i campi devono avere tipo'];

            // --- sezione ------------------------------------------------------------
            if ($type === 'section') {
                // options attese: { label, uid }
                $secLabel = '';
                $secUid = '';
                if (is_array($optsRaw)) {
                    $secLabel = isset($optsRaw['label']) ? trim((string) $optsRaw['label']) : '';
                    $secUid = isset($optsRaw['uid']) ? trim((string) $optsRaw['uid']) : '';
                } else {
                    $tmp = json_decode((string) $optsRaw, true);
                    $secLabel = isset($tmp['label']) ? trim((string) $tmp['label']) : '';
                    $secUid = isset($tmp['uid']) ? trim((string) $tmp['uid']) : '';
                }
                $secLabel = $secLabel !== '' ? $secLabel : 'sezione';
                $secUid = preg_replace('/[^a-z0-9_]/', '', strtolower($secUid));
                if ($secUid === '')
                    return ['success' => false, 'message' => 'section senza uid'];

                // field_name univoco per evitare problemi nei diff
                $name = '__section__' . $secUid;
                if (isset($sectionsSeen[$secUid]))
                    return ['success' => false, 'message' => "duplicato sezione '$secUid'"];
                $sectionsSeen[$secUid] = true;

                $optionsJson = json_encode(['label' => $secLabel, 'uid' => $secUid], \JSON_UNESCAPED_UNICODE);
                if ($optionsJson === false)
                    return ['success' => false, 'message' => 'config sezione non codificabile'];

                $dyn_fields[] = [
                    'name' => $name,
                    'type' => 'section',
                    'options_json' => $optionsJson,
                    'sort_order' => $order,
                    'colspan' => 2,
                    'parent_section_uid' => '', // le sezioni non hanno padre
                    'tab_label' => $tabLabel
                ];
                continue;
            }

            // --- campi normali (anche figli di sezione) ----------------------------
            if ($name === '')
                return ['success' => false, 'message' => 'tutti i campi devono avere nome'];
            // per i top-level controllo duplicati per-tab; figli: permetto stesso name in sezioni diverse
            if (!isset($seen[$tabLabel]))
                $seen[$tabLabel] = [];
            if ($parentUid === '' && isset($seen[$tabLabel][$name]))
                return ['success' => false, 'message' => "duplicato campo '$name' nella scheda '$tabLabel'"];
            if ($parentUid === '')
                $seen[$tabLabel][$name] = true;

            if (in_array($type, ['select', 'checkbox', 'radio'], true)) {
                // Se non ci sono opzioni, inizializza con un array vuoto
                if (!is_array($optsRaw)) {
                    $optsRaw = [];
                }
                if (empty($optsRaw)) {
                    $optsRaw = ['Opzione 1']; // Opzione di default
                }
                $opts = array_values(array_filter(array_map(static fn($o) => trim((string) $o), $optsRaw), static fn($o) => $o !== ''));
                if (empty($opts)) {
                    $opts = ['Opzione 1']; // Fallback se tutte le opzioni sono vuote
                }
                $optionsJson = json_encode($opts, \JSON_UNESCAPED_UNICODE);
                if ($optionsJson === false)
                    return ['success' => false, 'message' => "opzioni non codificabili per '$name'"];
            } elseif ($type === 'dbselect') {
                if (!is_array($optsRaw))
                    return ['success' => false, 'message' => "config dbselect non valida per '$name'"];
                $table = isset($optsRaw['table']) ? preg_replace('/[^\w]/', '', (string) $optsRaw['table']) : '';
                $valuecol = isset($optsRaw['valueCol']) ? preg_replace('/[^\w]/', '', (string) $optsRaw['valueCol']) : '';
                $labelcol = isset($optsRaw['labelCol']) ? preg_replace('/[^\w]/', '', (string) $optsRaw['labelCol']) : '';
                $q = isset($optsRaw['q']) ? trim((string) $optsRaw['q']) : '';
                $limit = isset($optsRaw['limit']) ? (int) $optsRaw['limit'] : 200;
                $multiRaw = $optsRaw['multiple'] ?? false;
                $multiBool = false;
                if (is_string($multiRaw)) {
                    $multiBool = in_array(strtolower(trim($multiRaw)), ['1', 'true', 'yes', 'si'], true);
                } else {
                    $multiBool = (bool) $multiRaw;
                }
                if ($table === '' || $valuecol === '' || $labelcol === '')
                    return ['success' => false, 'message' => "config dbselect incompleta per '$name'"];
                $cfg = [
                    'table' => $table,
                    'valueCol' => $valuecol,
                    'labelCol' => $labelcol,
                    'q' => $q,
                    'limit' => max(1, min(500, $limit)),
                    'multiple' => $multiBool ? 1 : 0
                ];
                $optionsJson = json_encode($cfg, \JSON_UNESCAPED_UNICODE);
                if ($optionsJson === false)
                    return ['success' => false, 'message' => "config dbselect non codificabile per '$name'"];
            } else {
                $optionsJson = '[]';
            }

            $dyn_fields[] = [
                'name' => $name,
                'label' => isset($f['field_label']) ? trim((string) $f['field_label']) : '',  // Preserva label con caratteri accentati
                'type' => $type,
                'options_json' => $optionsJson,
                'sort_order' => $order,
                'colspan' => $colspan,
                'parent_section_uid' => $parentUid,
                'tab_label' => $tabLabel
            ];
        }

        if (!empty($dyn_fields)) {
            // mappa: uidSezione => [idxSezione, orderSezione]
            $sections = [];
            // mappa: uidSezione => min(orderFigli)
            $minChildOrder = [];

            foreach ($dyn_fields as $i => $df) {
                if ($df['type'] === 'section') {
                    // field_name = "__section__{uid}"
                    $uid = preg_replace('/^__section__/', '', $df['name']);
                    $sections[$uid] = ['idx' => $i, 'order' => (int) $df['sort_order']];
                }
            }

            // trova il min sort_order per i figli di ogni sezione
            foreach ($dyn_fields as $df) {
                $p = $df['parent_section_uid'] ?? '';
                if ($p !== '') {
                    $o = (int) $df['sort_order'];
                    if (!isset($minChildOrder[$p]) || $o < $minChildOrder[$p]) {
                        $minChildOrder[$p] = $o;
                    }
                }
            }

            // se una sezione ha figli, sposta la sezione "prima del primo figlio"
            foreach ($minChildOrder as $uid => $minO) {
                if (isset($sections[$uid])) {
                    $idx = $sections[$uid]['idx'];
                    $dyn_fields[$idx]['sort_order'] = $minO - 1;
                }
            }

            // ordina: prima per sort_order asc,
            // a parit?? le 'section' prima dei campi normali
            usort($dyn_fields, static function ($a, $b) {
                $oa = (int) $a['sort_order'];
                $ob = (int) $b['sort_order'];
                if ($oa !== $ob)
                    return $oa <=> $ob;
                // tie-breaker: section prima
                $sa = ($a['type'] === 'section') ? 0 : 1;
                $sb = ($b['type'] === 'section') ? 0 : 1;
                return $sa <=> $sb;
            });

            // rinormalizza i sort_order (10,20,30???)
            $step = 10;
            $ord = 10;
            foreach ($dyn_fields as &$df) {
                $df['sort_order'] = $ord;
                $ord += $step;
            }
            unset($df);
        }

        try {
            $database->query("start transaction", [], __FILE__);

            // =====================================================================
            // STRATEGIA: per-tab DELETE + INSERT (idempotente, atomica)
            // Ogni tab viene svuotata (non-fixed) e riscritta dal payload.
            // Dedup intra-tab per chiave naturale: lower(field_name)|parent_section_uid
            // =====================================================================

            // 1. Raggruppa $dyn_fields per tab_label
            $fieldsByTab = [];
            foreach ($dyn_fields as $df) {
                $tl = $df['tab_label'] ?? 'Struttura';
                if (!isset($fieldsByTab[$tl]))
                    $fieldsByTab[$tl] = [];
                $fieldsByTab[$tl][] = $df;
            }

            // 2. Dedup intra-tab per chiave naturale (safety net, non deve servire se frontend corretto)
            foreach ($fieldsByTab as $tl => &$_tabFields) {
                $dedupKeys = [];
                $deduped = [];
                foreach ($_tabFields as $df) {
                    $dk = strtolower($df['name']) . '|' . strtolower($df['parent_section_uid'] ?? '');
                    if (isset($dedupKeys[$dk])) {
                        error_log("[PageEditor] saveFormStructure DEDUP: campo duplicato '$dk' in tab '$tl' scartato");
                        continue;
                    }
                    $dedupKeys[$dk] = true;
                    $deduped[] = $df;
                }
                $_tabFields = $deduped;
            }
            unset($_tabFields);

            // 3. Trova tab attualmente nel DB per questo form (non-fixed)
            $existingTabsStmt = $database->query(
                "SELECT DISTINCT tab_label FROM form_fields WHERE form_id = :fid AND (is_fixed = 0 OR is_fixed IS NULL) AND tab_label IS NOT NULL",
                [':fid' => $form_id],
                __FILE__
            );
            $existingDbTabs = [];
            if ($existingTabsStmt) {
                while ($r = $existingTabsStmt->fetch(\PDO::FETCH_ASSOC)) {
                    $existingDbTabs[] = $r['tab_label'];
                }
            }

            // 4. Elimina tab rimosse SOLO se replace_all_tabs=1 (esplicito).
            //    Default: sostituisci solo le tab presenti nel payload, non toccare le altre.
            $replaceAllTabs = !empty($input['replace_all_tabs']);
            if ($replaceAllTabs) {
                $tabsInPayload = array_keys($fieldsByTab);
                foreach (array_diff($existingDbTabs, $tabsInPayload) as $removedTab) {
                    if ($removedTab === 'Struttura')
                        continue;
                    $database->query(
                        "DELETE FROM form_fields WHERE form_id = :fid AND tab_label = :tl AND (is_fixed = 0 OR is_fixed IS NULL)",
                        [':fid' => $form_id, ':tl' => $removedTab],
                        __FILE__
                    );
                }
            }

            // 5. Per ogni tab nel payload: DELETE non-fixed + INSERT
            $totalInserted = 0;
            foreach ($fieldsByTab as $tl => $tabFieldsArr) {
                // DELETE tutti i campi non-fixed di questa tab
                $database->query(
                    "DELETE FROM form_fields WHERE form_id = :fid AND tab_label = :tl AND (is_fixed = 0 OR is_fixed IS NULL)",
                    [':fid' => $form_id, ':tl' => $tl],
                    __FILE__
                );

                // INSERT ogni campo deduppato
                foreach ($tabFieldsArr as $df) {
                    $database->query(
                        "INSERT INTO form_fields
                            (form_id, field_name, field_label, field_type, field_placeholder, field_options, required, is_fixed, sort_order, colspan, parent_section_uid, tab_label)
                         VALUES
                            (:fid, :n, :l, :t, '', :o, 0, 0, :so, :cs, :p, :tl)",
                        [
                            ':fid' => $form_id,
                            ':n' => $df['name'],
                            ':l' => ($df['label'] ?? ''),
                            ':t' => $df['type'],
                            ':o' => $df['options_json'],
                            ':so' => (int) $df['sort_order'],
                            ':cs' => (int) $df['colspan'],
                            ':p' => ($df['parent_section_uid'] ?? ''),
                            ':tl' => $tl
                        ],
                        __FILE__
                    );
                    $totalInserted++;
                }
            }

            // 6. ALTER TABLE per colonne fisiche core (invariato)
            $cols_stmt = $database->query("show columns from `$table_name`", [], __FILE__);
            $existingCols = [];
            if ($cols_stmt)
                while ($c = $cols_stmt->fetch(\PDO::FETCH_ASSOC))
                    $existingCols[strtolower($c['Field'])] = true;

            $core_cols_phys = ['titolo', 'descrizione', 'deadline', 'priority', 'status_id', 'assegnato_a', 'submitted_by', 'codice_segnalazione'];
            foreach ($dyn_fields as $df) {
                if ($df['type'] === 'section')
                    continue;
                $col = strtolower($df['name']);
                if (!in_array($col, $core_cols_phys))
                    continue;
                if (!isset($existingCols[$col])) {
                    $col_type = match ($df['type']) {
                        'date' => 'date',
                        'number' => 'int',
                        'textarea' => 'text',
                        default => 'varchar(255)',
                    };
                    $database->query("alter table `$table_name` add `$col` $col_type", [], __FILE__);
                }
            }

            // 7. Gestisci tabs_config DENTRO la transaction (atomico con i campi)
            if (isset($input['tabs_config']) && !empty($input['tabs_config'])) {
                $tabs_config_raw = is_string($input['tabs_config']) ? $input['tabs_config'] : json_encode($input['tabs_config']);
                $new_tabs_config = json_decode($tabs_config_raw, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($new_tabs_config)) {
                    $old_config_stmt = $database->query(
                        "SELECT tabs_config FROM forms WHERE id = :form_id LIMIT 1",
                        [':form_id' => $form_id],
                        __FILE__
                    );
                    $old_config = $old_config_stmt ? $old_config_stmt->fetch(\PDO::FETCH_ASSOC) : null;
                    $merged_config = [];

                    if ($old_config && !empty($old_config['tabs_config'])) {
                        $old_tabs_config = json_decode($old_config['tabs_config'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($old_tabs_config)) {
                            $label_to_key = [];
                            foreach ($new_tabs_config as $key => $cfg) {
                                if (isset($cfg['label']) && is_string($cfg['label'])) {
                                    $label_to_key[$cfg['label']] = $key;
                                }
                            }

                            foreach ($old_tabs_config as $old_key => $old_cfg) {
                                if (isset($new_tabs_config[$old_key])) {
                                    $merged_config[$old_key] = $old_cfg;
                                } elseif (isset($label_to_key[$old_key])) {
                                    $merged_config[$label_to_key[$old_key]] = $old_cfg;
                                } else {
                                    $merged_config[$old_key] = $old_cfg;
                                }
                            }
                        }
                    }

                    foreach ($new_tabs_config as $tab_key => $tab_config) {
                        $merged_config[$tab_key] = $tab_config;
                    }

                    $merged_json = !empty($merged_config) ? json_encode($merged_config, \JSON_UNESCAPED_UNICODE) : null;
                    $database->query(
                        "UPDATE forms SET tabs_config = :config WHERE id = :form_id",
                        [':config' => $merged_json, ':form_id' => $form_id],
                        __FILE__
                    );
                }
            }

            $database->query("commit", [], __FILE__);

            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log("[PageEditor] saveFormStructure OK - inserted $totalInserted campi in " . count($fieldsByTab) . " tab" . ($replaceAllTabs ? ' (replace_all_tabs)' : ''));
            }

            return ['success' => true, 'saved' => true, 'form_name' => $form_name, 'saved_dynamic' => count($dyn_fields)];

        } catch (\Throwable $e) {
            $database->query("rollback", [], __FILE__);
            error_log('saveformstructure error: ' . $e->getMessage());

            $response = [
                'success' => false,
                'message' => 'Errore durante il salvataggio struttura',
                'code' => 'DB_ERROR'
            ];

            if (defined('APP_DEBUG') && APP_DEBUG) {
                $response['details'] = $e->getMessage();
            }

            return $response;
        }

    }

    // saveFormDefinition RIMOSSO — usare solo saveFormStructure

    /* =========================
   Elimina un form e tutte le sue dipendenze
   ========================= */
    public static function deleteForm(array $input): array
    {
        global $database;

        $form_id = isset($input['form_id']) ? (int) $input['form_id'] : 0;
        $form_name = isset($input['form_name']) ? trim((string) $input['form_name']) : '';

        // Risolvi il form da id o da nome
        if ($form_id > 0) {
            $row = $database->query(
                "SELECT id, name, table_name FROM forms WHERE id=:id LIMIT 1",
                [':id' => $form_id],
                __FILE__ . ' ??? deleteForm.loadById'
            )->fetch(\PDO::FETCH_ASSOC);
        } elseif ($form_name !== '') {
            $row = $database->query(
                "SELECT id, name, table_name FROM forms WHERE name=:n LIMIT 1",
                [':n' => $form_name],
                __FILE__ . ' ??? deleteForm.loadByName'
            )->fetch(\PDO::FETCH_ASSOC);
        } else {
            return ['success' => false, 'message' => 'Parametri mancanti (form_id o form_name)'];
        }

        if (!$row)
            return ['success' => false, 'message' => 'Form non trovato'];

        $fid = (int) $row['id'];
        $fname = (string) $row['name'];
        $table = (string) $row['table_name'];

        try {
            $database->query("START TRANSACTION", [], __FILE__ . ' ??? deleteForm.tx');

            // 1) Se esistono campi file, prova a cancellare i file fisici (solo uploads/forms/*)
            $stF = $database->query(
                "SELECT field_name FROM form_fields WHERE form_id=:f AND field_type='file'",
                [':f' => $fid],
                __FILE__ . ' ??? deleteForm.fileFields'
            );
            $fileCols = [];
            if ($stF) {
                while ($r = $stF->fetch(\PDO::FETCH_ASSOC)) {
                    $fileCols[] = $r['field_name'];
                }
            }
            if ($fileCols) {
                $colsSql = '`' . implode('`,`', array_map(fn($c) => strtolower($c), $fileCols)) . '`';
                // Leggi i path prima di droppare la tabella
                $stPaths = $database->query("SELECT {$colsSql} FROM `{$table}`", [], __FILE__ . ' ??? deleteForm.collectFiles');
                foreach ($stPaths ?: [] as $rowf) {
                    foreach ($fileCols as $c) {
                        $p = (string) ($rowf[strtolower($c)] ?? '');
                        $root = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/forms');
                        if ($p) {
                            $abs = realpath($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($p, '/'));
                            if ($abs && $root && str_starts_with($abs, $root) && is_file($abs)) {
                                @unlink($abs);
                            }
                        }
                    }
                }
            }

            // 2) Rimuovi dipendenze
            // RIMOSSO: $database->query("DELETE FROM forms_modules WHERE form_id=:f", [':f' => $fid], __FILE__); // Tabella forms_modules eliminata
            $database->query("DELETE FROM form_fields               WHERE form_id=:f", [':f' => $fid], __FILE__ . ' ??? deleteForm.ff');
            $database->query("DELETE FROM moduli_visibilita         WHERE modulo_id=:f", [':f' => $fid], __FILE__ . ' ??? deleteForm.mv');
            $database->query("DELETE FROM moduli_visibilita_utenti  WHERE modulo_id=:f", [':f' => $fid], __FILE__ . ' ??? deleteForm.mvu');

            // 3) Elimina voci di menu che puntano a questo form
            $fname_like_plain = '%form_name=' . str_replace(['%', '_'], ['\\%', '\\_'], $fname) . '%';
            $fname_like_enc = '%form_name=' . str_replace(['%', '_'], ['\\%', '\\_'], urlencode($fname)) . '%';
            $fname_like_raw = '%form_name=' . str_replace(['%', '_'], ['\\%', '\\_'], rawurlencode($fname)) . '%';

            $database->query(
                "DELETE FROM menu_custom
                 WHERE title = :t
                    OR link LIKE :lk1 ESCAPE '\\\\'
                    OR link LIKE :lk2 ESCAPE '\\\\'
                    OR link LIKE :lk3 ESCAPE '\\\\'",
                [
                    ':t' => $fname,
                    ':lk1' => $fname_like_plain,
                    ':lk2' => $fname_like_enc,
                    ':lk3' => $fname_like_raw
                ],
                __FILE__ . ' ??? deleteForm.menu'
            );

            // 4) DROP TABLE se esiste
            $database->query("DROP TABLE IF EXISTS `{$table}`", [], __FILE__ . ' ??? deleteForm.drop');

            // 5) Rimuovi il form
            $database->query("DELETE FROM forms WHERE id=:f LIMIT 1", [':f' => $fid], __FILE__ . ' ??? deleteForm.form');

            $database->query("COMMIT", [], __FILE__ . ' ??? deleteForm.commit');
            return ['success' => true];
        } catch (\Throwable $e) {
            $database->query("ROLLBACK", [], __FILE__ . ' ??? deleteForm.rollback');
            return ['success' => false, 'message' => 'Errore eliminazione: ' . $e->getMessage()];
        }
    }

    /* =========================
       Admin: elenco moduli
       ========================= */
    public static function getAllFormsForAdmin($section = null)
    {
        global $database;

        $userId = $_SESSION['user_id'] ?? 0;

        // usa il nome fully-qualified per function_exists e per la chiamata
        $hasPerm = true;
        if (function_exists('\userHasPermission')) {
            $hasPerm = \userHasPermission('view_gestione_intranet');
        } else {
            // se proprio vuoi un fallback, controlla il ruolo admin
            $hasPerm = isAdmin();
        }

        if (!$userId || !$hasPerm) {
            return ['success' => false, 'message' => 'Accesso non autorizzato'];
        }

        // Aggiungi colonna display_name se mancante
        self::addColumnIfMissing('forms', 'display_name', 'VARCHAR(255) NULL');

        $query = "
            SELECT id, name, table_name, color, created_by, created_at, description, responsabile, display_name
            FROM forms
            ORDER BY name ASC
        ";
        $forms = $database->query($query, [], __FILE__);

        $stats = [];
        foreach ($forms as $form) {
            $tableName = $form['table_name'];
            $check = $database->query("SHOW TABLES LIKE :table", [':table' => $tableName], __FILE__);
            $exists = $check && $check->rowCount() > 0;
            $count = 0;

            $statusCounts = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0];
            if ($exists) {
                $resCount = $database->query("SELECT COUNT(*) as total FROM `$tableName`", [], __FILE__);
                $rowCount = $resCount ? $resCount->fetch(\PDO::FETCH_ASSOC) : null;
                $count = (int) ($rowCount['total'] ?? 0);

                $resStatus = $database->query("SELECT status_id, COUNT(*) as cnt FROM `$tableName` GROUP BY status_id", [], __FILE__);
                if ($resStatus) {
                    while ($row = $resStatus->fetch(\PDO::FETCH_ASSOC)) {
                        $sid = strval($row['status_id']);
                        if (isset($statusCounts[$sid]))
                            $statusCounts[$sid] = intval($row['cnt']);
                    }
                }
            }

            // Queste helper sono gi?? presenti altrove nel progetto
            $creatorName = $database->getNominativoByUserId($form['created_by'] ?? 0);
            $creatorImg = getProfileImage($creatorName, 'nominativo');
            if (!$creatorImg || $creatorImg === 'assets/images/default_profile.png') {
                $creatorImg = '/assets/images/default_profile.png';
            } elseif (strpos($creatorImg, '/') !== 0) {
                $creatorImg = '/' . $creatorImg;
            }

            $responsabileNome = null;
            $responsabileImg = null;
            if (!empty($form['responsabile'])) {
                $responsabileNome = $database->getNominativoByUserId($form['responsabile']);
                $responsabileImg = getProfileImage($responsabileNome, 'nominativo');
                if (!$responsabileImg || $responsabileImg === 'assets/images/default_profile.png') {
                    $responsabileImg = '/assets/images/default_profile.png';
                } elseif (strpos($responsabileImg, '/') !== 0) {
                    $responsabileImg = '/' . $responsabileImg;
                }
            }

            // Usa display_name se presente, altrimenti sanitizza il nome
            $displayName = !empty($form['display_name'])
                ? $form['display_name']
                : self::sanitizeFormNameForDisplay($form['name']);

            $stats[] = [
                'id' => $form['id'],
                'name' => $displayName,  // Usa display_name o nome sanitizzato
                'original_name' => $form['name'], // Conserva il nome originale per uso interno
                'description' => $form['description'] ?? null,
                'total_reports' => $count,
                'color' => $form['color'] ?? '#cccccc',
                'created_by' => $creatorName,
                'created_by_img' => $creatorImg,
                'created_at' => $database->formatDate($form['created_at'] ?? null),
                'responsabile_nome' => $responsabileNome,
                'responsabile_img' => $responsabileImg,
                'status_counts' => $statusCounts
            ];
        }

        return ['success' => true, 'stats' => $stats];
    }

    /**
     * Sanitizza il nome del form per la visualizzazione (sostituisce underscore con spazi e mette maiuscole)
     * @param string $name Nome originale del form (es. "gestione_richieste")
     * @return string Nome sanitizzato (es. "Gestione Richieste")
     */
    private static function sanitizeFormNameForDisplay(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }

    private static function columnExists(string $table, string $col): bool
    {
        global $database;
        $sql = "SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = :t
                AND COLUMN_NAME  = :c
                LIMIT 1";
        $stmt = $database->query($sql, [':t' => $table, ':c' => $col], __FILE__ . ' ??? columnExists');
        return $stmt && $stmt->fetchColumn() ? true : false;
    }

    private static function addColumnIfMissing(string $table, string $col, string $ddl): void
    {
        if (!self::columnExists($table, $col)) {
            global $database;
            $database->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}", [], __FILE__ . ' ??? addColumnIfMissing');
        }
    }

    /* =========================
       Stati per form (Kanban colonne)
       ========================= */
    private static function ensureStatesTable(): void
    {
        global $database;
        // Crea tabella se mancante (idempotente)
        $sql = "CREATE TABLE IF NOT EXISTS `form_states` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `form_id` INT NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `color` VARCHAR(7) DEFAULT '#95A5A6',
            `sort_order` INT NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `base_group` TINYINT(1) NULL,
            `is_base` TINYINT(1) NOT NULL DEFAULT 0,
            INDEX (`form_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $database->query($sql, [], __FILE__ . ' ??? ensureStatesTable');
        // Assicurati che la colonna base_group esista (per installazioni gi?? esistenti)
        if (!self::columnExists('form_states', 'base_group')) {
            $database->query("ALTER TABLE `form_states` ADD COLUMN `base_group` TINYINT(1) NULL AFTER `active`", [], __FILE__ . ' ??? ensureStatesTable.add_base_group');
        }
        if (!self::columnExists('form_states', 'is_base')) {
            $database->query("ALTER TABLE `form_states` ADD COLUMN `is_base` TINYINT(1) NOT NULL DEFAULT 0 AFTER `base_group`", [], __FILE__ . ' ??? ensureStatesTable.add_is_base');
        }
    }

    public static function getFormStates($form_name): array
    {
        global $database;
        $form_name = trim((string) $form_name);
        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name)) {
            return ['success' => false, 'message' => 'nome pagina non valido'];
        }
        self::ensureStatesTable();

        $f = $database->query("SELECT id FROM forms WHERE name=:n LIMIT 1", [':n' => $form_name], __FILE__ . ' ??? getFormStates.form')->fetch(\PDO::FETCH_ASSOC);
        if (!$f)
            return ['success' => false, 'message' => 'form non trovato'];
        $fid = (int) $f['id'];

        $st = $database->query("SELECT id, name, color, sort_order, active, base_group, is_base FROM form_states WHERE form_id=:f ORDER BY sort_order ASC, id ASC", [':f' => $fid], __FILE__ . ' ??? getFormStates.read');
        $rows = $st ? $st->fetchAll(\PDO::FETCH_ASSOC) : [];

        // Se vuoto, proponi defaults standard
        if (!$rows) {
            $rows = [
                ['id' => null, 'name' => 'Aperta', 'color' => '#3498DB', 'sort_order' => 10, 'active' => 1, 'base_group' => 1, 'is_base' => 1],
                ['id' => null, 'name' => 'In corso', 'color' => '#F1C40F', 'sort_order' => 20, 'active' => 1, 'base_group' => 2, 'is_base' => 1],
                ['id' => null, 'name' => 'Chiusa', 'color' => '#2ECC71', 'sort_order' => 30, 'active' => 1, 'base_group' => 3, 'is_base' => 1],
            ];
        }

        return ['success' => true, 'states' => array_values($rows)];
    }

    public static function saveFormStates(array $input): array
    {
        global $database;
        $form_name = trim((string) ($input['form_name'] ?? ''));
        $states = is_array($input['states'] ?? null) ? $input['states'] : [];
        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name)) {
            return ['success' => false, 'message' => 'nome pagina non valido'];
        }
        self::ensureStatesTable();

        $f = $database->query("SELECT id FROM forms WHERE name=:n LIMIT 1", [':n' => $form_name], __FILE__ . ' ??? saveFormStates.form')->fetch(\PDO::FETCH_ASSOC);
        if (!$f)
            return ['success' => false, 'message' => 'form non trovato'];
        $fid = (int) $f['id'];

        try {
            $database->query('START TRANSACTION', [], __FILE__ . ' ??? saveFormStates.tx');
            // Semplice strategia: wipe-and-insert (rapida e coerente con ordine)
            $database->query('DELETE FROM form_states WHERE form_id = :f', [':f' => $fid], __FILE__ . ' ??? saveFormStates.del');

            $ord = 10;
            foreach ($states as $s) {
                $name = trim((string) ($s['name'] ?? ''));
                if ($name === '')
                    continue;
                $color = (string) ($s['color'] ?? '#95A5A6');
                if ($color && $color[0] !== '#')
                    $color = '#' . $color;
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color))
                    $color = '#95A5A6';
                $active = (int) !!($s['active'] ?? 1);
                $baseGroup = isset($s['base_group']) ? (int) $s['base_group'] : null;
                $isBase = isset($s['is_base']) ? (int) !!$s['is_base'] : 0;
                $database->query(
                    'INSERT INTO form_states(form_id, name, color, sort_order, active, base_group, is_base) VALUES (:f,:n,:c,:o,:a,:bg,:ib)',
                    [':f' => $fid, ':n' => $name, ':c' => strtoupper($color), ':o' => $ord, ':a' => $active, ':bg' => $baseGroup, ':ib' => $isBase],
                    __FILE__ . ' ??? saveFormStates.ins'
                );
                $ord += 10;
            }

            $database->query('COMMIT', [], __FILE__ . ' ??? saveFormStates.commit');
            return ['success' => true];
        } catch (\Throwable $e) {
            $database->query('ROLLBACK', [], __FILE__ . ' ??? saveFormStates.rollback');
            return ['success' => false, 'message' => 'errore salvataggio stati: ' . $e->getMessage()];
        }
    }

    private static function ensureFixedFields(string $form_name): void
    {
        global $database;

        $f = $database->query(
            "SELECT id, table_name FROM forms WHERE name=:n LIMIT 1",
            [':n' => $form_name],
            __FILE__ . ' ??? ensureFixedFields.lookup'
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$f)
            return;

        $form_id = (int) $f['id'];
        $table = (string) $f['table_name'];

        $fixed = ['titolo', 'descrizione', 'deadline', 'priority', 'assegnato_a'];
        $meta = [
            'titolo' => ['ft' => 'text', 'ddl' => 'VARCHAR(255) NULL', 'opts' => null, 'req' => 1],
            'descrizione' => ['ft' => 'textarea', 'ddl' => 'TEXT NULL', 'opts' => null, 'req' => 1],
            'deadline' => ['ft' => 'date', 'ddl' => 'DATE NULL', 'opts' => null, 'req' => 1],
            'priority' => ['ft' => 'select', 'ddl' => 'VARCHAR(255) NULL', 'opts' => json_encode(['Bassa', 'Media', 'Alta'], JSON_UNESCAPED_UNICODE), 'req' => 1],
            'assegnato_a' => [
                'ft' => 'dbselect',
                'ddl' => 'INT NULL',
                'opts' => json_encode([
                    'table' => 'personale',
                    'valueCol' => 'user_id',
                    'labelCol' => 'Nominativo',
                    'q' => '',
                    'limit' => 200,
                    'multiple' => 0
                ], JSON_UNESCAPED_UNICODE),
                'req' => 0
            ],
        ];

        // colonne in tabella dati (idempotente)
        foreach ($fixed as $fn) {
            self::addColumnIfMissing($table, $fn, $meta[$fn]['ddl']);
        }

        // presenti in form_fields per il form
        $present = [];
        $st = $database->query(
            "SELECT LOWER(field_name) fn FROM form_fields WHERE form_id=:fid",
            [':fid' => $form_id],
            __FILE__ . ' ??? ensureFixedFields.read'
        );
        while ($st && ($r = $st->fetch(\PDO::FETCH_ASSOC))) {
            $present[$r['fn']] = true;
        }

        foreach ($fixed as $fn) {
            $req = $meta[$fn]['req'] ?? 1;
            if (empty($present[$fn])) {
                $database->query(
                    "INSERT INTO form_fields (form_id, field_name, field_type, field_placeholder, field_options, required, is_fixed, tab_label)
                    VALUES (:fid,:fn,:ft,'',:fo,:req,1,'Struttura')",
                    [
                        ':fid' => $form_id,
                        ':fn' => $fn,
                        ':ft' => $meta[$fn]['ft'],
                        ':fo' => $meta[$fn]['opts'],
                        ':req' => $req
                    ],
                    __FILE__ . ' ??? ensureFixedFields.insert'
                );
            } else {
                $database->query(
                    "UPDATE form_fields SET is_fixed=1, required=:req, tab_label='Struttura'
                    WHERE form_id=:fid AND LOWER(field_name)=:fn",
                    [':fid' => $form_id, ':fn' => $fn, ':req' => $req],
                    __FILE__ . ' ??? ensureFixedFields.update'
                );
            }
        }
    }

    public static function getMenuPlacementForForm(array $input): array
    {
        global $database;

        $form_name = isset($input['form_name']) ? trim((string) $input['form_name']) : '';
        if ($form_name === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form_name)) {
            return ['success' => false, 'message' => 'nome pagina non valido'];
        }

        try {
            // usa le colonne reali: section, parent_title, ...
            $sql = "SELECT 
                    section,
                    parent_title,
                    title,
                    link,
                    attivo,
                    ordinamento,
                    id
                FROM menu_custom
                WHERE title = :t
                ORDER BY attivo DESC, ordinamento ASC, id DESC
                LIMIT 1";
            $st = $database->query($sql, [':t' => $form_name], __FILE__ . ' ??? getMenuPlacementForForm');

            $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
            if (!$row) {
                return ['success' => true, 'placement' => null];
            }

            return [
                'success' => true,
                'placement' => [
                    'section' => (string) ($row['section'] ?? ''),
                    'parent_title' => (string) ($row['parent_title'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                    'link' => (string) ($row['link'] ?? ''),
                    'attivo' => (int) ($row['attivo'] ?? 0),
                    'ordinamento' => isset($row['ordinamento']) ? (int) $row['ordinamento'] : null
                ]
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'errore lettura menu: ' . $e->getMessage()];
        }
    }

    public static function updateAction(array $in)
    {
        return ['success' => false, 'message' => 'API deprecata: usa updateRecordFields'];
    }

    /**
     * Aggiorna i campi di un record del form (approccio unificato, senza "azioni").
     * Input:
     *  - form_name (string)
     *  - record_id (int)
     *  - data (associativo: field_name => value)
     * Comportamento:
     *  - Valida i field_name contro form_fields del form
     *  - Aggiorna solo le colonne esistenti nella tabella del form
     *  - Ignora silenziosamente chiavi sconosciute
     */
    public static function updateRecordFields(array $in): array
    {
        global $database;

        $form = trim((string) ($in['form_name'] ?? ''));
        $rid = (int) ($in['record_id'] ?? 0);
        $data = is_array($in['data'] ?? null) ? $in['data'] : [];

        if ($form === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $form) || $rid <= 0) {
            return ['success' => false, 'message' => 'parametri non validi'];
        }

        try {
            // Risolvi tabella fisica
            $rowForm = $database->query(
                "SELECT id, table_name FROM forms WHERE name=:n LIMIT 1",
                [':n' => $form],
                __FILE__ . ' ??? updateRecordFields.form'
            )->fetch(\PDO::FETCH_ASSOC);
            if (!$rowForm)
                return ['success' => false, 'message' => 'form non trovato'];

            $form_id = (int) $rowForm['id'];
            $table = (string) $rowForm['table_name'];

            // Campi validi (dal designer)
            $ff = $database->query(
                "SELECT LOWER(field_name) AS fn FROM form_fields WHERE form_id=:fid",
                [':fid' => $form_id],
                __FILE__ . ' ??? updateRecordFields.form_fields'
            );
            $valid = [];
            while ($ff && ($r = $ff->fetch(\PDO::FETCH_ASSOC)))
                $valid[$r['fn']] = true;

            // Colonne esistenti
            $colsStmt = $database->query("SHOW COLUMNS FROM `{$table}`", [], __FILE__ . ' ??? updateRecordFields.cols');
            $cols = [];
            while ($colsStmt && ($c = $colsStmt->fetch(\PDO::FETCH_ASSOC)))
                $cols[strtolower($c['Field'])] = true;

            // Prepara UPDATE
            $set = [];
            $par = [':rid' => $rid];

            foreach ($data as $k => $v) {
                $col = strtolower(preg_replace('/[^a-z0-9_]/i', '_', (string) $k));
                if (!isset($valid[$col]) || !isset($cols[$col]))
                    continue;

                // Serializza array/oggetti in JSON per coerenza
                $p = ':c_' . $col;
                $set[] = "`$col` = $p";
                $par[$p] = (is_array($v) || is_object($v)) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
            }

            if (empty($set)) {
                return ['success' => true, 'updated' => 0, 'message' => 'Nessun campo valido da aggiornare'];
            }

            $sql = "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE id = :rid LIMIT 1";
            $database->query($sql, $par, __FILE__ . ' ??? updateRecordFields.update');

            return ['success' => true, 'updated' => count($set)];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'db_error: ' . $e->getMessage()];
        }
    }

    /**
     * Restituisce le info del responsabile del form:
     *  - return: null se non impostato
     *  - altrimenti: ['id'=>int,'nome'=>string,'img'=>string]
     *
     * Firma compatibile con la chiamata attuale:
     *   PageEditorService::getResponsabileInfo($formName, $recordId)
     * Il $recordId ?? opzionale (per ora non usato per override per-record).
     */
    public static function getResponsabileInfo($formName, $recordId = null)
    {
        global $database;

        // Sanitizza formName come nel resto della classe
        $formName = trim((string) $formName);
        if ($formName === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $formName)) {
            return null;
        }

        try {
            // Leggi user_id del responsabile a livello di form
            $row = $database->query(
                "SELECT responsabile FROM forms WHERE name = :n LIMIT 1",
                [':n' => $formName],
                __FILE__ . ' ??? getResponsabileInfo.form'
            )->fetch(\PDO::FETCH_ASSOC);

            $uid = (int) ($row['responsabile'] ?? 0);
            if ($uid <= 0)
                return null;

            // Nominativo (helper di progetto, se presente)
            $nome = method_exists($database, 'getNominativoByUserId')
                ? (string) $database->getNominativoByUserId($uid)
                : '';

            // Immagine profilo (helper globale, coerente con il resto della classe)
            $img = function_exists('getProfileImage') ? getProfileImage($nome, 'nominativo') : null;
            if (!$img || $img === 'assets/images/default_profile.png') {
                $img = '/assets/images/default_profile.png';
            } elseif ($img[0] !== '/') {
                $img = '/' . $img;
            }

            return [
                'id' => $uid,
                'nome' => ($nome !== '' ? $nome : ('Utente #' . $uid)),
                'img' => $img
            ];
        } catch (\Throwable $e) {
            // Silenzioso: in pagina preferiamo non far esplodere nulla
            return null;
        }
    }

    /**
     * Ritorna info dell'utente che ha compilato il form (submitted_by)
     * Uso:
     *   PageEditorService::getCompilatoInfo($formName, $recordId)
     */
    public static function getCompilatoInfo($formName, $recordId)
    {
        global $database;

        // Sanitizza formName come nel resto della classe
        $formName = trim((string) $formName);
        if ($formName === '' || !preg_match('/^[\w\s\-????????????]+$/ui', $formName)) {
            return null;
        }

        $recordId = (int) $recordId;
        if ($recordId <= 0)
            return null;

        try {
            // Recupera la tabella del form
            $formRow = $database->query(
                "SELECT table_name FROM forms WHERE name = :n LIMIT 1",
                [':n' => $formName],
                __FILE__ . ' ??? getCompilatoInfo.form'
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$formRow)
                return null;
            $tableName = $formRow['table_name'];

            // Leggi submitted_by dal record
            $recordRow = $database->query(
                "SELECT submitted_by FROM `{$tableName}` WHERE id = :id LIMIT 1",
                [':id' => $recordId],
                __FILE__ . ' ??? getCompilatoInfo.record'
            )->fetch(\PDO::FETCH_ASSOC);

            $uid = (int) ($recordRow['submitted_by'] ?? 0);
            if ($uid <= 0)
                return null;

            // Nominativo
            $nome = method_exists($database, 'getNominativoByUserId')
                ? (string) $database->getNominativoByUserId($uid)
                : '';

            // Immagine profilo
            $img = function_exists('getProfileImage') ? getProfileImage($nome, 'nominativo') : null;
            if (!$img || $img === 'assets/images/default_profile.png') {
                $img = '/assets/images/default_profile.png';
            } elseif ($img[0] !== '/') {
                $img = '/' . $img;
            }

            return [
                'id' => $uid,
                'nome' => ($nome !== '' ? $nome : ('Utente #' . $uid)),
                'img' => $img
            ];
        } catch (\Throwable $e) {
            // Silenzioso: in pagina preferiamo non far esplodere nulla
            return null;
        }
    }

    /* =========================
       GESTIONE WORKFLOW SCHEDE
       ========================= */

    /**
     * Verifica se la tabella form_schede_status esiste
     */
    private static function schedeStatusTableExists(): bool
    {
        global $database;
        try {
            $st = $database->query("SHOW TABLES LIKE 'form_schede_status'", [], __FILE__);
            return $st && $st->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Ottiene lo stato di tutte le schede per un record specifico
     * @param string $formName Nome del form
     * @param int $recordId ID del record
     * @return array ['success' => bool, 'schede_status' => ['scheda_key' => 'status', ...]]
     */
    public static function getSchedeStatus(array $input): array
    {
        global $database;

        $formName = trim($input['form_name'] ?? '');
        $recordId = (int) ($input['record_id'] ?? 0);

        if (!$formName || $recordId <= 0) {
            return ['success' => false, 'message' => 'Parametri mancanti', 'schede_status' => []];
        }

        // Verifica esistenza tabella
        if (!self::schedeStatusTableExists()) {
            // Tabella non esiste: restituisci stato vuoto (tutti not_started)
            return ['success' => true, 'schede_status' => [], 'table_missing' => true];
        }

        try {
            // Trova form_id
            $form = $database->query(
                "SELECT id FROM forms WHERE name = :name LIMIT 1",
                [':name' => $formName],
                __FILE__ . ' ??? getSchedeStatus.form'
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$form) {
                return ['success' => false, 'message' => 'Form non trovato', 'schede_status' => []];
            }

            $formId = (int) $form['id'];

            // Carica tutti gli stati per questo record
            $rows = $database->query(
                "SELECT scheda_key, status, updated_at, updated_by
                 FROM form_schede_status
                 WHERE form_id = :form_id AND record_id = :record_id",
                [':form_id' => $formId, ':record_id' => $recordId],
                __FILE__ . ' ??? getSchedeStatus.select'
            )->fetchAll(\PDO::FETCH_ASSOC);

            $schedeStatus = [];
            foreach ($rows as $row) {
                $schedeStatus[$row['scheda_key']] = [
                    'status' => $row['status'],
                    'updated_at' => $row['updated_at'],
                    'updated_by' => (int) $row['updated_by']
                ];
            }

            return ['success' => true, 'schede_status' => $schedeStatus];

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Errore: ' . $e->getMessage(), 'schede_status' => []];
        }
    }

    /**
     * Aggiorna lo stato di una scheda per un record
     * @param array $input ['form_name', 'record_id', 'scheda_key', 'status']
     * @return array ['success' => bool, 'message' => string]
     */
    public static function updateSchedaStatus(array $input): array
    {
        global $database;

        $formName = trim($input['form_name'] ?? '');
        $recordId = (int) ($input['record_id'] ?? 0);
        $schedaKey = strtolower(trim($input['scheda_key'] ?? ''));
        $status = $input['status'] ?? 'draft';

        if (!$formName || $recordId <= 0 || !$schedaKey) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        // Valida status
        $validStatuses = ['not_started', 'draft', 'submitted'];
        if (!in_array($status, $validStatuses)) {
            $status = 'draft';
        }

        // Verifica esistenza tabella
        if (!self::schedeStatusTableExists()) {
            return ['success' => false, 'message' => 'Tabella form_schede_status non esiste. Eseguire lo script SQL.'];
        }

        try {
            // Trova form_id
            $form = $database->query(
                "SELECT id FROM forms WHERE name = :name LIMIT 1",
                [':name' => $formName],
                __FILE__ . ' ??? updateSchedaStatus.form'
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$form) {
                return ['success' => false, 'message' => 'Form non trovato'];
            }

            $formId = (int) $form['id'];
            $userId = (int) ($_SESSION['user_id'] ?? 0);

            // UPSERT: INSERT o UPDATE se esiste gi??
            $database->query(
                "INSERT INTO form_schede_status (form_id, record_id, scheda_key, status, updated_by)
                 VALUES (:form_id, :record_id, :scheda_key, :status, :user_id)
                 ON DUPLICATE KEY UPDATE status = :status2, updated_by = :user_id2",
                [
                    ':form_id' => $formId,
                    ':record_id' => $recordId,
                    ':scheda_key' => $schedaKey,
                    ':status' => $status,
                    ':user_id' => $userId,
                    ':status2' => $status,
                    ':user_id2' => $userId
                ],
                __FILE__ . ' ??? updateSchedaStatus.upsert'
            );

            return ['success' => true, 'message' => 'Stato scheda aggiornato'];

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Errore: ' . $e->getMessage()];
        }
    }

    /**
     * Salva i valori di una scheda (es. Esito) e ne aggiorna lo status a 'submitted'.
     * Usato dal form_viewer quando un responsabile/admin compila una scheda editabile.
     *
     * @param array $input ['form_name', 'record_id', 'scheda_key', 'values' => [...]]
     * @return array ['success' => bool, 'message' => string]
     */
    public static function submitScheda(array $input, array $files = []): array
    {
        global $database;

        $formName = trim($input['form_name'] ?? '');
        $recordId = (int) ($input['record_id'] ?? 0);
        $schedaKey = strtolower(trim($input['scheda_key'] ?? ''));
        $values = $input['values'] ?? [];

        if (!$formName || $recordId <= 0 || !$schedaKey) {
            return ['success' => false, 'message' => 'Parametri mancanti (form_name, record_id, scheda_key)'];
        }

        // Accetta sia file che valori; se values è vuoto ma ci sono file, procedi comunque
        $hasFiles = false;
        foreach ($files as $fKey => $fVal) {
            if (!empty($fVal['tmp_name'])) {
                $hasFiles = true;
                break;
            }
        }

        if ((!is_array($values) || empty($values)) && !$hasFiles) {
            return ['success' => false, 'message' => 'Nessun valore da salvare'];
        }
        if (!is_array($values)) {
            $values = [];
        }

        try {
            $form = $database->query(
                "SELECT id, table_name FROM forms WHERE name = :name LIMIT 1",
                [':name' => $formName],
                __FILE__ . ' submitScheda.form'
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$form) {
                return ['success' => false, 'message' => 'Form non trovato'];
            }

            $formId = (int) $form['id'];
            $tableName = $form['table_name'];
            $userId = (int) ($_SESSION['user_id'] ?? 0);

            // Verifica che il record esista
            $record = $database->query(
                "SELECT id FROM `$tableName` WHERE id = :rid LIMIT 1",
                [':rid' => $recordId],
                __FILE__ . ' submitScheda.record'
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$record) {
                return ['success' => false, 'message' => 'Record non trovato'];
            }

            // === VALIDAZIONE PERMESSI: rifiuta update su tab non editabile per quel ruolo ===
            // Struttura: MAI editabile nel viewer (è stata già salvata in view_form)
            if ($schedaKey === 'struttura') {
                return ['success' => false, 'message' => 'La scheda Struttura non è modificabile nel viewer'];
            }

            // Verifica permessi utente sulla scheda
            $roleId = (int) ($_SESSION['user']['role_id'] ?? $_SESSION['role_id'] ?? 0);
            $isAdmin = ($roleId === 1);

            // Recupera responsabile e assegnatario del form
            $formFull = $database->query(
                "SELECT responsabile FROM forms WHERE id = :fid LIMIT 1",
                [':fid' => $formId],
                __FILE__ . ' submitScheda.perm'
            )->fetch(\PDO::FETCH_ASSOC);
            $formResponsabileId = (int) ($formFull['responsabile'] ?? 0);

            $isResponsabile = ($userId === $formResponsabileId);
            $isAssegnatario = false; {
                try {
                    $colCheck = $database->query("SHOW COLUMNS FROM `$tableName` LIKE 'assegnato_a'", [], __FILE__);
                    if ($colCheck && $colCheck->rowCount() > 0) {
                        $recRow = $database->query(
                            "SELECT assegnato_a FROM `$tableName` WHERE id = :rid LIMIT 1",
                            [':rid' => $recordId],
                            __FILE__ . ' submitScheda.assegnato'
                        )->fetch(\PDO::FETCH_ASSOC);
                        $raw = trim((string) ($recRow['assegnato_a'] ?? ''));
                        if ($raw !== '') {
                            $ids = array_map('trim', explode(',', $raw));
                            foreach ($ids as $id) {
                                if (is_numeric($id) && (int) $id === $userId) {
                                    $isAssegnatario = true;
                                    break;
                                }
                            }
                        }
                    }
                } catch (\Throwable $pE) {
                    // Ignora
                }
            }

            // Solo admin, responsabile o assegnatario possono editare schede non-struttura
            $canEdit = ($isAdmin || $isResponsabile || $isAssegnatario);

            // Verifica stato scheda: se già submitted, blocca solo per utenti NON admin/responsabile/assegnatario
            if (self::schedeStatusTableExists() && !$canEdit) {
                $statusRow = $database->query(
                    "SELECT status FROM form_schede_status WHERE form_id = :fid AND record_id = :rid AND scheda_key = :sk LIMIT 1",
                    [':fid' => $formId, ':rid' => $recordId, ':sk' => $schedaKey],
                    __FILE__ . ' submitScheda.statusCheck'
                )->fetch(\PDO::FETCH_ASSOC);

                if ($statusRow && $statusRow['status'] === 'submitted') {
                    return ['success' => false, 'message' => 'Questa scheda è già stata compilata e non è più modificabile'];
                }
            }
            if (!$canEdit) {
                return ['success' => false, 'message' => 'Non hai i permessi per modificare questa scheda'];
            }

            // Recupera colonne esistenti nella tabella per filtrare solo valori validi
            $colsStmt = $database->query("SHOW COLUMNS FROM `$tableName`", [], __FILE__);
            $existingCols = [];
            while ($c = $colsStmt->fetch(\PDO::FETCH_ASSOC)) {
                $existingCols[strtolower($c['Field'])] = true;
            }

            $database->query("START TRANSACTION", [], __FILE__);

            // Aggiorna i campi nella tabella del record (fisici) e raccogli i non-fisici per EAV
            $updated = 0;
            $dynamicData = [];
            foreach ($values as $colName => $colValue) {
                $colLower = strtolower(preg_replace('/[^a-z0-9_]/i', '', $colName));
                if (!$colLower) {
                    continue;
                }
                if (isset($existingCols[$colLower])) {
                    // Colonna fisica: UPDATE diretto
                    $database->query(
                        "UPDATE `$tableName` SET `$colLower` = :val WHERE id = :rid",
                        [':val' => $colValue, ':rid' => $recordId],
                        __FILE__ . ' submitScheda.update'
                    );
                    $updated++;
                } else {
                    // Colonna non fisica: salva in EAV (form_values)
                    $dynamicData[$colLower] = $colValue;
                    if (defined('APP_DEBUG') && APP_DEBUG) {
                        error_log("[submitScheda] campo '$colLower' non fisico -> EAV per form '$formName'");
                    }
                }
            }

            // Salva campi non-fisici in EAV
            if (!empty($dynamicData)) {
                \Services\FormsDataService::saveDynamicFieldsPublic($formName, $recordId, $dynamicData);
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    error_log("[submitScheda] salvati " . count($dynamicData) . " campi EAV per form='$formName' record=$recordId");
                }
            }

            // Gestione upload file (se presenti in $files)
            $fileValues = [];
            foreach ($files as $fileFieldName => $fileData) {
                if (empty($fileData['tmp_name']) || $fileData['error'] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $fileFieldLower = strtolower(preg_replace('/[^a-z0-9_]/i', '', $fileFieldName));
                if (!$fileFieldLower) {
                    continue;
                }
                // Delega l'upload a FormsDataService::handleUploadPublic
                $savedPath = null;
                $uploadErr = null;
                $ok = \Services\FormsDataService::handleUploadPublic($fileData, $tableName, $recordId, $savedPath, $uploadErr);
                if ($ok && $savedPath) {
                    // Salva il path nella colonna fisica o EAV
                    if (isset($existingCols[$fileFieldLower])) {
                        $database->query(
                            "UPDATE `$tableName` SET `$fileFieldLower` = :val WHERE id = :rid",
                            [':val' => $savedPath, ':rid' => $recordId],
                            __FILE__ . ' submitScheda.fileUpdate'
                        );
                    } else {
                        $dynamicData[$fileFieldLower] = $savedPath;
                        \Services\FormsDataService::saveDynamicFieldsPublic($formName, $recordId, [$fileFieldLower => $savedPath]);
                    }
                    $fileValues[$fileFieldLower] = $savedPath;
                    $updated++;
                    if (defined('APP_DEBUG') && APP_DEBUG) {
                        error_log("[submitScheda] file '$fileFieldLower' salvato: $savedPath");
                    }
                } else {
                    if (defined('APP_DEBUG') && APP_DEBUG) {
                        error_log("[submitScheda] file upload fallito per '$fileFieldLower': " . ($uploadErr ?? 'errore sconosciuto'));
                    }
                }
            }

            // Merge file paths nei values per il subtask
            $subtaskValues = array_merge($values, $fileValues);

            // Sincronizza tutti gli assegnatari da assegnato_a_esito alla colonna assegnato_a del record principale
            if (isset($existingCols['assegnato_a']) && isset($subtaskValues['assegnato_a_esito'])) {
                $raw = trim((string) $subtaskValues['assegnato_a_esito']);
                $valToSave = '';
                if ($raw !== '') {
                    $ids = array_map('trim', explode(',', $raw));
                    $validIds = [];
                    foreach ($ids as $id) {
                        if (is_numeric($id) && (int) $id > 0) {
                            $validIds[] = (int) $id;
                        }
                    }
                    $valToSave = implode(',', array_slice($validIds, 0, 5));
                }
                $database->query(
                    "UPDATE `$tableName` SET `assegnato_a` = :val WHERE id = :rid",
                    [':val' => $valToSave, ':rid' => $recordId],
                    __FILE__ . ' submitScheda.assegnatoSync'
                );
                $updated++;
            }

            // Salva anche in subtask scheda_data (per coerenza con getFormEntry che legge da subtask)
            if ($schedaKey !== 'struttura') {
                try {
                    \Services\FormsDataService::saveSubtask([
                        'form_name' => $formName,
                        'parent_record_id' => $recordId,
                        'scheda_label' => $schedaKey,
                        'scheda_data' => $subtaskValues
                    ]);
                } catch (\Throwable $subE) {
                    if (defined('APP_DEBUG') && APP_DEBUG) {
                        error_log("[submitScheda] subtask save warning: " . $subE->getMessage());
                    }
                }
            }

            // Aggiorna stato scheda a 'submitted'
            if (self::schedeStatusTableExists()) {
                $database->query(
                    "INSERT INTO form_schede_status (form_id, record_id, scheda_key, status, updated_by)
                     VALUES (:fid, :rid, :sk, 'submitted', :uid)
                     ON DUPLICATE KEY UPDATE status = 'submitted', updated_by = :uid2",
                    [
                        ':fid' => $formId,
                        ':rid' => $recordId,
                        ':sk' => $schedaKey,
                        ':uid' => $userId,
                        ':uid2' => $userId
                    ],
                    __FILE__ . ' submitScheda.status'
                );
            }

            $database->query("COMMIT", [], __FILE__);

            return ['success' => true, 'message' => 'Scheda salvata', 'updated_fields' => $updated, 'eav_fields' => count($dynamicData)];

        } catch (\Throwable $e) {
            $database->query("ROLLBACK", [], __FILE__);
            error_log("[submitScheda] ERROR: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore: ' . $e->getMessage()];
        }
    }

    /**
     * WORKFLOW SEGNALAZIONI: Determina se una scheda è visibile all'utente corrente
     * 
     * Logica workflow:
     * - Admin: sempre true
     * - Scheda utente: sempre visibile a tutti (readonly dopo submit)
     * - Scheda responsabile:
     *   - Visibile SOLO al responsabile finch?? non ?? stata submitte
     *   - Dopo submit, visibile a TUTTI readonly (costituisce la risposta alla segnalazione)
     * 
     * @param array $tabConfig Configurazione scheda con scheda_type e visibility_roles
     * @param array $context ['user_id', 'role_id', 'responsabile_id', 'assegnatari_ids', 'schede_status', 'current_tab_key']
     * @return bool
     */
    private static function canViewTab(array $tabConfig, array $context): bool
    {
        $roleId = (int) ($context['role_id'] ?? 0);
        $userId = (int) ($context['user_id'] ?? 0);
        $responsabiliIds = array_filter(explode(',', (string) ($context['responsabili_ids'] ?? $context['responsabile_id'] ?? '')));
        $assegnatariIds = $context['assegnatari_ids'] ?? [];
        $schedeStatus = $context['schede_status'] ?? [];
        $currentTabKey = strtolower($context['current_tab_key'] ?? '');

        // Admin vede sempre tutto
        if ($roleId === 1) {
            return true;
        }

        // Determina ruolo utente
        $userRole = 'utente';
        if (in_array(strval($userId), $responsabiliIds)) {
            $userRole = 'responsabile';
        } elseif (in_array($userId, $assegnatariIds)) {
            $userRole = 'assegnatario';
        }

        // SOLUZIONE SEMPLIFICATA: Usa scheda_type esplicito
        $scheda_type = $tabConfig['scheda_type'] ?? 'utente';

        // WORKFLOW: Scheda utente = sempre visibile a tutti
        if ($scheda_type === 'utente') {
            return true;
        }

        // WORKFLOW: Scheda responsabile = visibile solo al responsabile finch?? non ?? submitte
        if ($scheda_type === 'responsabile') {
            // Controlla lo stato di QUESTA specifica scheda
            $thisSchedaStatus = $schedeStatus[$currentTabKey]['status'] ?? 'not_started';

            // Se la scheda ?? stata submitte, ?? visibile a tutti (readonly)
            if ($thisSchedaStatus === 'submitted') {
                return true;
            }

            // Altrimenti, visibile solo al responsabile/assegnatario
            return ($userRole === 'responsabile' || $userRole === 'assegnatario');
        }

        // Retrocompatibilit??: se scheda_type non esiste, usa visibility_roles
        if (!isset($tabConfig['scheda_type'])) {
            $visibilityRoles = $tabConfig['visibility_roles'] ?? ['utente', 'responsabile', 'assegnatario', 'admin'];
            // Retrocompatibilit??: visibility_mode = 'responsabile'
            if (($tabConfig['visibility_mode'] ?? 'all') === 'responsabile') {
                // Per retrocompatibilit??, controlla anche lo stato
                $thisSchedaStatus = $schedeStatus[$currentTabKey]['status'] ?? 'not_started';
                if ($thisSchedaStatus === 'submitted') {
                    return true; // Dopo submit, visibile a tutti
                }
                return ($userRole === 'responsabile' || $userRole === 'assegnatario');
            }
            // Se ?? configurazione default (tutti i ruoli), visibile a tutti
            $defaultRoles = ['utente', 'responsabile', 'assegnatario', 'admin'];
            $isDefaultConfig = (count($visibilityRoles) === count($defaultRoles) &&
                empty(array_diff($defaultRoles, $visibilityRoles)) &&
                empty(array_diff($visibilityRoles, $defaultRoles)));
            if ($isDefaultConfig) {
                return true;
            }
            return in_array($userRole, $visibilityRoles);
        }

        // Per tutti gli altri casi, visibile
        return true;
    }

    /**
     * GESTIONE SEPARATA PER SCHEDA: Determina se una scheda ?? editabile dall'utente corrente
     * 
     * Ogni scheda ha il suo stato indipendente in form_schede_status.
     * - Admin: sempre true
     * - Utente normale:
     *   - Schede "utente": editabile solo se questa specifica scheda non ?? stata submitte
     *   - Schede "responsabile": mai editabile
     * - Responsabile/Assegnatario:
     *   - Schede "utente": sempre false (non deve sovrascrivere dati utente)
     *   - Schede "responsabile": sempre editabile (indipendentemente dallo stato delle schede utente)
     * 
     * @param array $tabConfig Configurazione scheda con scheda_type
     * @param array $context ['user_id', 'role_id', 'responsabile_id', 'assegnatari_ids', 'schede_status', 'current_tab_key']
     * @return bool
     */
    /**
     * FUNZIONE CENTRALE: Determina se una scheda ?? editabile dall'utente corrente
     * 
     * IMPORTANTE: Lo stato "submitted" di una scheda utente NON blocca l'editing delle schede responsabile.
     * Ogni scheda ha il suo stato indipendente in form_schede_status.
     */
    public static function canEditTab(array $tabConfig, array $context): bool
    {
        $roleId = (int) ($context['role_id'] ?? 0);
        $userId = (int) ($context['user_id'] ?? 0);
        $responsabiliIds = array_filter(explode(',', (string) ($context['responsabili_ids'] ?? $context['responsabile_id'] ?? '')));
        $assegnatariIds = $context['assegnatari_ids'] ?? [];
        $schedeStatus = $context['schede_status'] ?? [];
        $currentTabKey = strtolower($context['current_tab_key'] ?? '');

        // Admin pu?? sempre fare tutto: ha tutti i ruoli e tutti i permessi
        if ($roleId === 1) {
            return true;
        }

        // Determina ruolo utente
        $userRole = 'utente';
        if (in_array(strval($userId), $responsabiliIds)) {
            $userRole = 'responsabile';
        } elseif (in_array($userId, $assegnatariIds)) {
            $userRole = 'assegnatario';
        }

        // Usa scheda_type esplicito
        $scheda_type = $tabConfig['scheda_type'] ?? 'utente';

        // IMPORTANTE: Controlla lo stato di QUESTA specifica scheda
        $thisSchedaStatus = $schedeStatus[$currentTabKey]['status'] ?? 'not_started';

        // Schede "utente" sono readonly dopo il submit per utenti NON admin
        if ($scheda_type === 'utente' && $thisSchedaStatus === 'submitted') {
            return false;
        }

        // Regola 1: Utente normale + Scheda utente = editabile solo se questa scheda non ?? stata submitte
        if ($userRole === 'utente' && $scheda_type === 'utente') {
            return ($thisSchedaStatus !== 'submitted');
        }

        // Regola 2: Utente normale + Scheda responsabile = mai editabile
        if ($userRole === 'utente' && $scheda_type === 'responsabile') {
            return false;
        }

        // Regola 3: Responsabile/Assegnatario + Scheda utente = mai editabile (non sovrascrive)
        if (($userRole === 'responsabile' || $userRole === 'assegnatario') && $scheda_type === 'utente') {
            return false;
        }

        // Regola 4: Responsabile/Assegnatario + Scheda responsabile = editabile solo se non ?? stata submitte
        // WORKFLOW: Dopo submit, la scheda responsabile diventa readonly per tutti (costituisce la risposta finale)
        if (($userRole === 'responsabile' || $userRole === 'assegnatario') && $scheda_type === 'responsabile') {
            return ($thisSchedaStatus !== 'submitted');
        }

        // Retrocompatibilit??: se scheda_type non esiste, usa logica vecchia
        if (!isset($tabConfig['scheda_type'])) {
            $editRoles = $tabConfig['edit_roles'] ?? $tabConfig['visibility_roles'] ?? ['utente', 'responsabile', 'assegnatario', 'admin'];
            if (!in_array($userRole, $editRoles)) {
                return false;
            }
            // Se ?? scheda utente, controlla lo stato di questa scheda
            $isSchedaUtente = in_array('utente', $editRoles);
            if ($userRole === 'utente' && $isSchedaUtente) {
                $thisSchedaStatus = $schedeStatus[$currentTabKey]['status'] ?? 'not_started';
                return ($thisSchedaStatus !== 'submitted');
            }
            return true;
        }

        return false;
    }

    /**
     * Calcola la visibilit?? di una scheda per un utente
     * 
     * FUNZIONE CENTRALE PER VISIBILIT?? E MODIFICABILIT?? (FASE 2 - MVP)
     * 
     * Questa funzione usa canViewTab() e canEditTab() per implementare la logica base per l'MVP:
     * - Admin (role_id == 1): vede e modifica sempre tutto
     * - Utente normale:
     *   - Schede "utente": visibili e editabili prima del submit, readonly dopo
     *   - Schede "responsabile": sempre invisibili
     * - Responsabile/Assegnatario:
     *   - Schede "utente": visibili (readonly) per vedere dati richiedente
     *   - Schede "responsabile": visibili e sempre editabili
     * 
     * @param array $tabConfig Configurazione della scheda (da tabs_config JSON)
     * @param array $context ['user_id', 'role_id', 'responsabile_id', 'assegnatari_ids', 'schede_status', 'tab_order', 'record_submitted_by']
     * @return array ['visible' => bool, 'editable' => bool, 'reason' => string]
     */
    public static function calculateSchedaVisibility(array $tabConfig, array $context): array
    {
        $userId = (int) ($context['user_id'] ?? 0);
        $roleId = (int) ($context['role_id'] ?? 0);
        $responsabiliIds = array_filter(explode(',', (string) ($context['responsabili_ids'] ?? $context['responsabile_id'] ?? '')));
        $assegnatariIds = $context['assegnatari_ids'] ?? [];
        $schedeStatus = $context['schede_status'] ?? [];
        $allTabs = $context['all_tabs'] ?? [];
        $currentTabKey = strtolower($context['current_tab_key'] ?? '');
        $recordSubmittedBy = (int) ($context['record_submitted_by'] ?? 0);

        // FASE 2 - MVP: Usa funzioni centrali canViewTab/canEditTab
        $canView = self::canViewTab($tabConfig, $context);
        if (!$canView) {
            return ['visible' => false, 'editable' => false, 'reason' => 'role_not_allowed'];
        }

        // 2. Verifica condizione di visibilit??
        $condition = $tabConfig['visibility_condition'] ?? ['type' => self::CONDITION_ALWAYS];
        $conditionType = $condition['type'] ?? self::CONDITION_ALWAYS;

        $conditionMet = true;
        $reason = 'condition_met';

        switch ($conditionType) {
            case self::CONDITION_ALWAYS:
                $conditionMet = true;
                break;

            case self::CONDITION_AFTER_STEP_SAVED:
                $dependsOn = strtolower($condition['depends_on'] ?? '');
                if ($dependsOn && isset($schedeStatus[$dependsOn])) {
                    $depStatus = $schedeStatus[$dependsOn]['status'] ?? 'not_started';
                    $conditionMet = in_array($depStatus, ['draft', 'submitted']);
                } else {
                    // Se non specificata dipendenza, guarda la scheda precedente
                    $conditionMet = self::checkPreviousTabStatus($currentTabKey, $allTabs, $schedeStatus, ['draft', 'submitted']);
                }
                if (!$conditionMet)
                    $reason = 'previous_not_saved';
                break;

            case self::CONDITION_AFTER_STEP_SUBMITTED:
                $dependsOn = strtolower($condition['depends_on'] ?? '');
                if ($dependsOn && isset($schedeStatus[$dependsOn])) {
                    $depStatus = $schedeStatus[$dependsOn]['status'] ?? 'not_started';
                    $conditionMet = ($depStatus === 'submitted');
                } else {
                    $conditionMet = self::checkPreviousTabStatus($currentTabKey, $allTabs, $schedeStatus, ['submitted']);
                }
                if (!$conditionMet)
                    $reason = 'previous_not_submitted';
                break;

            case self::CONDITION_AFTER_ALL_PREVIOUS_SUBMITTED:
                $conditionMet = self::checkAllPreviousTabsStatus($currentTabKey, $allTabs, $schedeStatus, 'submitted');
                if (!$conditionMet)
                    $reason = 'not_all_previous_submitted';
                break;

            default:
                // Retrocompatibilit??: unlock_after_submit_prev
                if (!empty($tabConfig['unlock_after_submit_prev'])) {
                    $conditionMet = self::checkPreviousTabStatus($currentTabKey, $allTabs, $schedeStatus, ['submitted']);
                    if (!$conditionMet)
                        $reason = 'previous_not_submitted';
                }
        }

        if (!$conditionMet) {
            return ['visible' => true, 'editable' => false, 'reason' => $reason, 'locked' => true];
        }

        // SOLUZIONE SEMPLIFICATA: Assicura che il record sia nel context per canEditTab
        if (!isset($context['record']) && isset($context['record_data'])) {
            $context['record'] = $context['record_data'];
        }

        // FASE 2 - MVP: Usa funzione centrale canEditTab
        $canEdit = self::canEditTab($tabConfig, $context);

        if (!$canEdit) {
            // Determina motivo per non editabile
            $userId = (int) ($context['user_id'] ?? 0);
            $userRole = 'utente';
            if (in_array(strval($userId), $responsabiliIds)) {
                $userRole = 'responsabile';
            } elseif (in_array($userId, $context['assegnatari_ids'] ?? [])) {
                $userRole = 'assegnatario';
            }

            $editRoles = $tabConfig['edit_roles'] ?? ($tabConfig['visibility_roles'] ?? ['utente', 'responsabile', 'assegnatario', 'admin']);
            $isSchedaUtente = in_array('utente', $editRoles);
            $tabStatus = $schedeStatus[$currentTabKey]['status'] ?? 'not_started';

            if ($userRole === 'utente' && $isSchedaUtente && $tabStatus === 'submitted') {
                $reason = 'already_submitted_by_user';
            } elseif ($userRole === 'utente' && !$isSchedaUtente) {
                $reason = 'role_no_edit_permission';
            } elseif (($userRole === 'responsabile' || $userRole === 'assegnatario') && $isSchedaUtente) {
                $reason = 'scheda_utente_readonly';
            } else {
                $reason = 'role_no_edit_permission';
            }

            return ['visible' => true, 'editable' => false, 'reason' => $reason];
        }

        return ['visible' => true, 'editable' => true, 'reason' => $reason];
    }

    /**
     * Helper: verifica lo stato della scheda precedente
     */
    private static function checkPreviousTabStatus(string $currentKey, array $allTabs, array $schedeStatus, array $validStatuses): bool
    {
        $tabKeys = array_keys($allTabs);
        $currentIndex = array_search($currentKey, array_map('strtolower', $tabKeys));

        if ($currentIndex === false || $currentIndex === 0) {
            return true; // Prima scheda: sempre sbloccata
        }

        $prevKey = strtolower($tabKeys[$currentIndex - 1]);
        $prevStatus = $schedeStatus[$prevKey]['status'] ?? 'not_started';

        return in_array($prevStatus, $validStatuses);
    }

    /**
     * Helper: verifica che TUTTE le schede precedenti abbiano un certo stato
     */
    private static function checkAllPreviousTabsStatus(string $currentKey, array $allTabs, array $schedeStatus, string $requiredStatus): bool
    {
        $tabKeys = array_keys($allTabs);
        $currentIndex = array_search($currentKey, array_map('strtolower', $tabKeys));

        if ($currentIndex === false || $currentIndex === 0) {
            return true; // Prima scheda
        }

        for ($i = 0; $i < $currentIndex; $i++) {
            $tabKey = strtolower($tabKeys[$i]);
            $status = $schedeStatus[$tabKey]['status'] ?? 'not_started';
            if ($status !== $requiredStatus) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calcola la prossima scheda visibile e sbloccata dopo quella corrente
     * @param array $input ['form_name', 'record_id', 'current_scheda_key', 'user_id', 'role_id']
     * @return array ['success' => bool, 'next_scheda_key' => string|null, 'is_last' => bool]
     */
    public static function calculateNextScheda(array $input): array
    {
        global $database;

        $formName = trim($input['form_name'] ?? '');
        $recordId = (int) ($input['record_id'] ?? 0);
        $currentKey = strtolower(trim($input['current_scheda_key'] ?? ''));
        $userId = (int) ($input['user_id'] ?? $_SESSION['user_id'] ?? 0);
        $roleId = (int) ($input['role_id'] ?? $_SESSION['role_id'] ?? 0);

        if (!$formName) {
            return ['success' => false, 'message' => 'form_name mancante', 'next_scheda_key' => null, 'is_last' => true];
        }

        try {
            // Carica configurazione form
            $formData = self::getForm($formName, $recordId > 0 ? $recordId : null);
            if (!$formData['success']) {
                return ['success' => false, 'message' => $formData['message'], 'next_scheda_key' => null, 'is_last' => true];
            }

            $tabs = $formData['tabs'] ?? [];
            if (empty($tabs)) {
                return ['success' => true, 'next_scheda_key' => null, 'is_last' => true];
            }

            // Carica stato schede
            $schedeStatusResult = self::getSchedeStatus(['form_name' => $formName, 'record_id' => $recordId]);
            $schedeStatus = $schedeStatusResult['schede_status'] ?? [];

            // Carica info responsabile e assegnatari
            $formRow = $database->query(
                "SELECT id, table_name, responsabile FROM forms WHERE name = :name LIMIT 1",
                [':name' => $formName],
                __FILE__ . ' — calculateNextScheda.form'
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$formRow)
                return ['success' => false, 'message' => 'Form non trovato'];

            $responsabiliIds = array_filter(explode(',', (string) ($formRow['responsabile'] ?? '')));
            $table = $formRow['table_name'];
            $assegnatariIds = [];
            $recordSubmittedBy = 0;
            $record = []; // SOLUZIONE SEMPLIFICATA: Inizializza record vuoto

            // SOLUZIONE SEMPLIFICATA: Carica record con flag
            if ($recordId > 0 && !empty($table)) {
                $table = $table;
                $colCheckAssegnato = $database->query("SHOW COLUMNS FROM `{$table}` LIKE 'assegnato_a'", [], __FILE__);
                $colCheckSubmitted = $database->query("SHOW COLUMNS FROM `{$table}` LIKE 'submitted_by'", [], __FILE__);
                $colCheckUserScheda = $database->query("SHOW COLUMNS FROM `{$table}` LIKE 'user_scheda_submitted'", [], __FILE__);
                $colCheckRespScheda = $database->query("SHOW COLUMNS FROM `{$table}` LIKE 'responsabile_scheda_submitted'", [], __FILE__);

                $selectCols = ['id'];
                if ($colCheckAssegnato && $colCheckAssegnato->rowCount() > 0) {
                    $selectCols[] = 'assegnato_a';
                }
                if ($colCheckSubmitted && $colCheckSubmitted->rowCount() > 0) {
                    $selectCols[] = 'submitted_by';
                }
                if ($colCheckUserScheda && $colCheckUserScheda->rowCount() > 0) {
                    $selectCols[] = 'user_scheda_submitted';
                }
                if ($colCheckRespScheda && $colCheckRespScheda->rowCount() > 0) {
                    $selectCols[] = 'responsabile_scheda_submitted';
                }

                $rec = $database->query(
                    "SELECT " . implode(', ', $selectCols) . " FROM `{$table}` WHERE id = :id LIMIT 1",
                    [':id' => $recordId],
                    __FILE__
                )->fetch(\PDO::FETCH_ASSOC);

                if ($rec) {
                    $record = $rec;
                    if (isset($rec['assegnato_a']) && !empty($rec['assegnato_a'])) {
                        $val = trim($rec['assegnato_a']);
                        if (is_numeric($val)) {
                            $assegnatariIds[] = (int) $val;
                        }
                    }
                    if (isset($rec['submitted_by']) && !empty($rec['submitted_by'])) {
                        $recordSubmittedBy = (int) $rec['submitted_by'];
                    }
                }
            }

            // Trova la scheda corrente e cerca la successiva
            $tabKeys = array_keys($tabs);
            $currentIndex = array_search($currentKey, array_map('strtolower', $tabKeys));

            if ($currentIndex === false) {
                // Fallback: prova con il nome originale
                foreach ($tabKeys as $idx => $key) {
                    if (strtolower($key) === $currentKey) {
                        $currentIndex = $idx;
                        break;
                    }
                }
            }

            if ($currentIndex === false) {
                return ['success' => true, 'next_scheda_key' => null, 'is_last' => true];
            }

            // Cerca la prossima scheda visibile e sbloccata
            for ($i = $currentIndex + 1; $i < count($tabKeys); $i++) {
                $tabKey = $tabKeys[$i];
                $tabConfig = $tabs[$tabKey];

                $context = [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'responsabili_ids' => implode(',', $responsabiliIds),
                    'assegnatari_ids' => $assegnatariIds,
                    'schede_status' => $schedeStatus,
                    'all_tabs' => $tabs,
                    'current_tab_key' => strtolower($tabKey),
                    'record_submitted_by' => $recordSubmittedBy,
                    'record' => $record // SOLUZIONE SEMPLIFICATA: Passa record con flag
                ];

                $visibility = self::calculateSchedaVisibility($tabConfig, $context);

                if ($visibility['visible'] && $visibility['editable'] && empty($visibility['locked'])) {
                    return [
                        'success' => true,
                        'next_scheda_key' => $tabKey,
                        'is_last' => false
                    ];
                }
            }

            // Nessuna scheda successiva trovata
            return ['success' => true, 'next_scheda_key' => null, 'is_last' => true];

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Errore: ' . $e->getMessage(), 'next_scheda_key' => null, 'is_last' => true];
        }
    }

    /**
     * Processa tutte le schede e restituisce quelle visibili per l'utente
     * @param array $input ['form_name', 'record_id', 'user_id', 'role_id']
     * @return array ['success' => bool, 'visible_tabs' => [...], 'schede_status' => [...]]
     */
    public static function getVisibleSchedeForUser(array $input): array
    {
        global $database;

        $formName = trim($input['form_name'] ?? '');
        $recordId = (int) ($input['record_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? $_SESSION['user_id'] ?? 0);
        $roleId = (int) ($input['role_id'] ?? $_SESSION['role_id'] ?? 0);

        if (!$formName) {
            return ['success' => false, 'message' => 'form_name mancante', 'visible_tabs' => []];
        }

        try {
            // Carica configurazione form
            $formData = self::getForm($formName, $recordId > 0 ? $recordId : null);
            if (!$formData['success']) {
                return ['success' => false, 'message' => $formData['message'], 'visible_tabs' => []];
            }

            $tabs = $formData['tabs'] ?? [];
            if (empty($tabs)) {
                return ['success' => true, 'visible_tabs' => [], 'schede_status' => []];
            }

            // Carica stato schede
            $schedeStatusResult = self::getSchedeStatus(['form_name' => $formName, 'record_id' => $recordId]);
            $schedeStatus = $schedeStatusResult['schede_status'] ?? [];

            // Carica info responsabile e assegnatari
            $formQuery = $database->query(
                "SELECT id, table_name, responsabile FROM forms WHERE name = :name LIMIT 1",
                [':name' => $formName],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$formQuery)
                return ['success' => false, 'message' => 'Form non trovato', 'visible_tabs' => []];

            $responsabiliIds = array_filter(explode(',', (string) ($formQuery['responsabile'] ?? '')));
            $table = $formQuery['table_name'];
            $assegnatariIds = [];
            $recordSubmittedBy = 0;
            $record = []; // SOLUZIONE SEMPLIFICATA: Inizializza record vuoto

            if ($recordId > 0 && !empty($table)) {
                $table = $table;
                // Controlla se esiste colonna assegnato_a e submitted_by
                $colCheckAssegnato = $database->query("SHOW COLUMNS FROM `{$table}` LIKE 'assegnato_a'", [], __FILE__);
                $colCheckSubmitted = $database->query("SHOW COLUMNS FROM `{$table}` LIKE 'submitted_by'", [], __FILE__);

                // Carica assegnato_a e submitted_by in una query
                $selectCols = ['id'];
                if ($colCheckAssegnato && $colCheckAssegnato->rowCount() > 0) {
                    $selectCols[] = 'assegnato_a';
                }
                if ($colCheckSubmitted && $colCheckSubmitted->rowCount() > 0) {
                    $selectCols[] = 'submitted_by';
                }

                $rec = $database->query(
                    "SELECT " . implode(', ', $selectCols) . " FROM `{$table}` WHERE id = :id LIMIT 1",
                    [':id' => $recordId],
                    __FILE__
                )->fetch(\PDO::FETCH_ASSOC);

                if ($rec) {
                    if (isset($rec['assegnato_a']) && !empty($rec['assegnato_a'])) {
                        $val = trim($rec['assegnato_a']);
                        if (is_numeric($val)) {
                            $assegnatariIds[] = (int) $val;
                        }
                    }
                    if (isset($rec['submitted_by']) && !empty($rec['submitted_by'])) {
                        $recordSubmittedBy = (int) $rec['submitted_by'];
                    }
                }
            }

            // Processa ogni scheda
            $visibleTabs = [];
            foreach ($tabs as $tabKey => $tabConfig) {
                $context = [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'responsabili_ids' => implode(',', $responsabiliIds),
                    'assegnatari_ids' => $assegnatariIds,
                    'schede_status' => $schedeStatus,
                    'all_tabs' => $tabs,
                    'current_tab_key' => strtolower($tabKey),
                    'record_submitted_by' => $recordSubmittedBy,
                    'record' => $record // SOLUZIONE SEMPLIFICATA: Passa record con flag
                ];

                $visibility = self::calculateSchedaVisibility($tabConfig, $context);

                if ($visibility['visible']) {
                    $visibleTabs[$tabKey] = array_merge($tabConfig, [
                        '__visibility' => $visibility
                    ]);
                }
            }

            return [
                'success' => true,
                'visible_tabs' => $visibleTabs,
                'schede_status' => $schedeStatus,
                'responsabili_ids' => $responsabiliIds,
                'assegnatari_ids' => $assegnatariIds
            ];

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Errore: ' . $e->getMessage(), 'visible_tabs' => []];
        }
    }
    /**
     * STUB: Salva configurazione notifiche
     * @param array $input ['form_name' => string, 'config' => array]
     * @return array ['success' => bool, 'message' => string]
     */
    public static function saveNotificationConfig($input)
    {
        global $database;

        $form_name = trim($input['form_name'] ?? '');
        $config = $input['config'] ?? [];

        if (empty($form_name)) {
            return ['success' => false, 'message' => 'Nome form mancante'];
        }

        // Validazione base
        if (!is_array($config)) {
            return ['success' => false, 'message' => 'Configurazione non valida'];
        }

        // TODO: Inserimento/aggiornamento nella tabella notification_rules
        // Query suggerita per quando avrai accesso al DB:
        // INSERT INTO notification_rules (form_name, events, channels, recipients, messages, enabled, created_at)
        // VALUES (:form_name, :events, :channels, :recipients, :messages, :enabled, NOW())
        // ON DUPLICATE KEY UPDATE 
        //   events = VALUES(events),
        //   channels = VALUES(channels),
        //   recipients = VALUES(recipients),
        //   messages = VALUES(messages),
        //   enabled = VALUES(enabled),
        //   updated_at = NOW()

        // Per ora restituiamo success=true senza scrivere sul DB
        return [
            'success' => true,
            'message' => 'Configurazione notifiche salvata (placeholder - DB non ancora implementato)',
            'data' => $config
        ];
    }

    /**
     * STUB: Carica configurazione notifiche
     * @param array $input ['form_name' => string]
     * @return array ['success' => bool, 'config' => array]
     */
    public static function getNotificationConfig($input)
    {
        global $database;

        $form_name = trim($input['form_name'] ?? '');

        if (empty($form_name)) {
            return ['success' => false, 'message' => 'Nome form mancante'];
        }

        // TODO: Query SELECT dalla tabella notification_rules
        // SELECT * FROM notification_rules WHERE form_name = :form_name LIMIT 1

        // Per ora restituiamo configurazione vuota
        return [
            'success' => true,
            'config' => [
                'enabled' => false,
                'events' => [
                    'on_submit' => false,
                    'on_status_change' => false,
                    'on_assignment_change' => false
                ],
                'channels' => [
                    'in_app' => false,
                    'email' => false
                ],
                'recipients' => [
                    'responsabile' => false,
                    'assegnatario' => false,
                    'creatore' => false,
                    'custom_email' => false,
                    'custom_email_value' => ''
                ],
                'messages' => [
                    'in_app_message' => '',
                    'email_subject' => '',
                    'email_body' => ''
                ]
            ]
        ];
    }
}

