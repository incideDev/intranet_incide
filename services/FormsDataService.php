<?php

namespace Services;

class FormsDataService
{
    // ——— UTIL ———
    private static function getFormByName(string $form_name): ?array
    {
        global $database;
        $st = $database->query("SELECT * FROM forms WHERE name=:n LIMIT 1", [':n' => $form_name], __FILE__);
        $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
        return $row ?: null;
    }
    private static function colExists(string $table, string $col): bool
    {
        global $database;
        $st = $database->query("SHOW COLUMNS FROM `$table` LIKE :c", [':c' => $col], __FILE__);
        return $st && $st->rowCount() > 0;
    }

    // ——— LETTURA SINGOLO RECORD (ex getFormEntry) ———
    public static function getFormEntry(array $in): array
    {
        try {
            global $database;
            $form_name = trim($in['form_name'] ?? '');
            $id = (int) ($in['record_id'] ?? 0);
            if (!$form_name || !$id)
                return ['success' => false, 'message' => 'Parametri mancanti'];

            $form = self::getFormByName($form_name);
            if (!$form)
                return ['success' => false, 'message' => 'Form non trovato'];
            $table = $form['table_name'];


            $st = $database->query("SELECT * FROM `$table` WHERE id=:id LIMIT 1", [':id' => $id], __FILE__);
            $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
            if (!$row)
                return ['success' => false, 'message' => 'Record non trovato'];

            // Merge EAV data
            $dynamicValues = self::loadDynamicFields($form_name, $id);
            if (!empty($dynamicValues)) {
                // If a key exists in BOTH $row and $dynamicValues, $row (legacy/physical) wins usually?
                // Or $dynamicValues? If we are moving to EAV, new updates go there.
                // So EAV should overwrite legacy if both exist? 
                // However, I made sure 'update' writes to physical if exists.
                // So they should be in sync if physical exists. If physical does NOT exist, it's only in EAV.
                // Safest: Merge dynamic into row.
                $row = array_merge($row, $dynamicValues);
            }

            // Se questa è una subtask, carica il record principale
            $isSubtask = isset($row['parent_record_id']) && $row['parent_record_id'] !== null && $row['parent_record_id'] > 0;
            if ($isSubtask) {
                // Se stiamo caricando una subtask, carica invece il record principale
                $id = (int) $row['parent_record_id'];
                $st = $database->query("SELECT * FROM `$table` WHERE id=:id LIMIT 1", [':id' => $id], __FILE__);
                $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$row)
                    return ['success' => false, 'message' => 'Record principale non trovato'];
            }

            // Carica i dati dalle subtask e uniscili al record principale
            if (self::colExists($table, 'parent_record_id') && self::colExists($table, 'scheda_data')) {
                try {
                    $subtasks = $database->query(
                        "SELECT scheda_label, scheda_data FROM `$table` WHERE parent_record_id = ? ORDER BY scheda_label",
                        [$id],
                        __FILE__
                    )->fetchAll(\PDO::FETCH_ASSOC);

                    // Per ogni subtask, estrai i dati da scheda_data e uniscili a $row
                    foreach ($subtasks as $subtask) {
                        if (!empty($subtask['scheda_data'])) {
                            $schedaDataRaw = trim((string) $subtask['scheda_data']);
                            if ($schedaDataRaw !== '') {
                                $schedaData = json_decode($schedaDataRaw, true);
                                if (json_last_error() === JSON_ERROR_NONE && is_array($schedaData)) {
                                    // Unisci senza sovrascrivere valori non vuoti con vuoti (preserva path file)
                                    foreach ($schedaData as $k => $v) {
                                        if ($v === null || $v === '')
                                            continue;
                                        if (is_string($v) && trim($v) === '')
                                            continue;
                                        $row[$k] = $v;
                                    }
                                } else {
                                    // Log errore JSON ma continua (non bloccare il caricamento)
                                    error_log("Errore parsing JSON scheda_data per subtask (form: $form_name, record: $id): " . json_last_error_msg());
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Ignora errori - probabilmente la tabella non supporta ancora le subtask
                    error_log("Errore caricamento subtask per record $id: " . $e->getMessage());
                }
            }

            // aggiungi _raw per date (UX dei tuoi file)
            foreach ($row as $k => $v) {
                if (preg_match('/_date$|_data$|deadline$/i', $k) && $v && strlen($v) >= 10) {
                    $row[$k . '_raw'] = substr($v, 0, 10);
                }
            }

            // assegnato_a resta come ID/CSV - la UI frontend gestisce la traduzione in nomi

            // responsabile (compat a chi lo usa in viewer)
            $responsabile = (int) ($form['responsabile'] ?? 0);

            // Applica fixMojibake ai dati del record
            foreach ($row as $key => $value) {
                $row[$key] = fixMojibake($value ?? '');
            }

            // Estrai meta_esito dal record (campi con suffisso _esito da scheda_data subtask)
            $metaEsitoKeys = ['data_apertura_esito', 'deadline_esito', 'assegnato_a_esito', 'priorita_esito', 'stato_esito'];
            $metaEsito = [];
            foreach ($metaEsitoKeys as $mek) {
                if (isset($row[$mek]) && $row[$mek] !== '' && $row[$mek] !== null) {
                    $metaEsito[$mek] = $row[$mek];
                }
            }

            return [
                'success' => true,
                'data' => $row,
                'meta_esito' => !empty($metaEsito) ? $metaEsito : null,
                'fields' => (function () use ($database, $form) {
                    $s = $database->query("SELECT * FROM form_fields WHERE form_id=:f ORDER BY id", [':f' => $form['id']], __FILE__);
                    $fields = $s ? $s->fetchAll(\PDO::FETCH_ASSOC) : [];
                    // Applica fixMojibake ai campi dei fields
                    foreach ($fields as &$field) {
                        foreach ($field as $key => $value) {
                            $field[$key] = fixMojibake($value ?? '');
                        }
                    }
                    unset($field);
                    return $fields;
                })(),
                'protocollo' => $form['protocollo'] ?? null,
                'responsabile' => $responsabile,
                'module_config' => (function () use ($form) {
                    $res = \Services\PageEditorService::getModuleConfig([
                        'form_name' => $form['name'],
                        'module_key' => 'gestione_richiesta'
                    ]);
                    return ($res['success'] ?? false) ? ($res['config'] ?? null) : null;
                })()
            ];
        } catch (\Exception $e) {
            error_log("Errore in getFormEntry (form: " . ($in['form_name'] ?? 'unknown') . ", record: " . ($in['record_id'] ?? 0) . "): " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nel caricamento del record: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            error_log("Errore fatale in getFormEntry (form: " . ($in['form_name'] ?? 'unknown') . ", record: " . ($in['record_id'] ?? 0) . "): " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nel caricamento del record'];
        }
    }


    // ——— HELPERS EAV ———
    private static function ensureEAVTable()
    {
        global $database;
        static $checked = false;
        if ($checked)
            return;

        // Tabella ibrida EAV: form_name + record_id -> field_name = value
        // Usa MEDIUMTEXT per evitare limiti di lunghezza
        $sql = "CREATE TABLE IF NOT EXISTS `form_values` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `form_name` VARCHAR(100) NOT NULL,
            `record_id` INT UNSIGNED NOT NULL,
            `field_name` VARCHAR(100) NOT NULL,
            `field_value` MEDIUMTEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_val` (`form_name`, `record_id`, `field_name`),
            INDEX `idx_rec` (`form_name`, `record_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $database->query($sql, [], __FILE__);
        $checked = true;
    }

    /**
     * Wrapper pubblico per saveDynamicFields (usato da PageEditorService::submitScheda)
     */
    public static function saveDynamicFieldsPublic(string $form_name, int $record_id, array $dynamic_data)
    {
        self::saveDynamicFields($form_name, $record_id, $dynamic_data);
    }

    private static function saveDynamicFields(string $form_name, int $record_id, array $dynamic_data)
    {
        global $database;
        self::ensureEAVTable();

        if (empty($dynamic_data))
            return;

        $sql = "INSERT INTO `form_values` (form_name, record_id, field_name, field_value) 
                VALUES (:fn, :rid, :k, :v)
                ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)";

        foreach ($dynamic_data as $k => $v) {
            // Se value è array, converti in JSON o CSV (coerente con logica precedente)
            if (is_array($v))
                $v = implode(',', $v); // o json_encode($v) se preferito, ma il codice esistente usava implode per checkbox/select

            $database->query($sql, [
                ':fn' => $form_name,
                ':rid' => $record_id,
                ':k' => $k,
                ':v' => $v
            ], __FILE__);
        }
    }

    private static function loadDynamicFields(string $form_name, int $record_id): array
    {
        global $database;
        // Check se tabella esiste (per evitare errori prima della migrazione/creazione)
        // Ma ensureEAVTable dovrebbe averla creata. Se usiamo questa fn in getFormEntry, chiamiamo ensure first.
        self::ensureEAVTable();

        $st = $database->query(
            "SELECT field_name, field_value FROM form_values WHERE form_name=:fn AND record_id=:rid",
            [':fn' => $form_name, ':rid' => $record_id],
            __FILE__
        );
        $ret = [];
        if ($st) {
            while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                $ret[$row['field_name']] = $row['field_value'];
            }
        }
        return $ret;
    }

    // ——— inserimento con protocollo + notifiche ———
    public static function submit(array $in, array $files = []): array
    {
        global $database;

        $form_name = trim($in['form_name'] ?? '');
        if ($form_name === '')
            return ['success' => false, 'message' => 'form_name mancante'];

        // Sanitizzazione form_name per evitare problemi
        $form_nameSan = preg_replace('/[^a-z0-9_]/i', '_', $form_name);

        $form = self::getFormByName($form_name);
        if (!$form)
            return ['success' => false, 'message' => 'form non trovato'];
        $table = $form['table_name'];

        // Core fields (colonne fisiche sicure nel DB)
        // Questi campi DEVONO esistere nella tabella mod_
        $coreCols = ['submitted_by', 'deadline', 'titolo', 'descrizione', 'priority', 'status_id', 'assegnato_a', 'codice_segnalazione', 'submitted_at'];

        // config modulo
        $cfgRes = \Services\PageEditorService::getModuleConfig([
            'form_name' => $form_name,
            'module_key' => 'gestione_richiesta'
        ]);
        $cfg = ($cfgRes['success'] ?? false) ? ($cfgRes['config'] ?? []) : [];
        $mostraAssegna = (bool) ($cfg['mostra_assegna'] ?? true);

        // Preparazione valori Core
        $insertCols = ['submitted_by', 'deadline', 'titolo', 'descrizione', 'priority', 'status_id'];
        $insertVals = [
            (int) ($_SESSION['user_id'] ?? 0),
            $in['deadline'] ?? null,
            trim((string) ($in['titolo'] ?? '')),
            trim((string) ($in['descrizione'] ?? '')),
            $in['priority'] ?? 'media',
            (int) ($in['status_id'] ?? 1)
        ];

        // Se esistono colonne core opzionali nel DB fisico, usale
        if (self::colExists($table, 'assegnato_a')) {
            $insertCols[] = 'assegnato_a';
            $insertVals[] = $mostraAssegna ? ($in['assegnato_a'] ?? null) : null;
        }

        // Gestione dynamic fields
        $fieldsSt = $database->query("select field_name, field_type from form_fields where form_id=:f", [':f' => $form['id']], __FILE__);
        $defFields = $fieldsSt ? $fieldsSt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $dynamicData = [];
        $tempFilePaths = [];
        $processedFields = []; // Per evitare duplicati (es. allega_screenshot specificato due volte)

        foreach ($defFields as $f) {
            $name = strtolower($f['field_name'] ?? '');
            if (!$name || in_array($name, $coreCols, true) || in_array($name, $processedFields, true))
                continue;

            $processedFields[] = $name;

            // Check if column exists physically (Legacy support)
            // Se la colonna esiste FISICAMENTE, la usiamo ancora per evitare rotture immediate di report che fanno query dirette
            // MA NON facciamo più ALTER TABLE se non esiste.
            $isPhysical = self::colExists($table, $name);

            // Value retrieval
            $val = null;
            if ($f['field_type'] === 'file') {
                if (!empty($files[$name]['tmp_name'])) {
                    $ok = self::handleUpload($files[$name], $table, null, $saved, $err);
                    if (!$ok)
                        return ['success' => false, 'message' => $err];
                    $val = $saved;
                    $tempFilePaths[$name] = $saved;
                }
            } else {
                if (array_key_exists($name, $in)) {
                    $val = $in[$name];
                } else if (array_key_exists($name . '[]', $in)) {
                    $val = $in[$name . '[]'];
                }
                if (is_array($val))
                    $val = implode(',', $val);
            }

            // Se esiste fisicamente, salva in tabella, altrimenti in EAV
            if ($isPhysical) {
                $insertCols[] = $name;
                $insertVals[] = $val;
            } else {
                // EAV
                if ($val !== null) {
                    $dynamicData[$name] = $val;
                }
            }
        }

        // Codice segnalazione
        if (self::colExists($table, 'codice_segnalazione')) {
            $prefix = 'RS_'; // Semplificato o logica complessa
            // ... (logica generazione codice mantenuta breve per brevità, o copiata se critica)
            $prefix = (function (string $n) {
                $iniziali = strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', preg_split('/\s+/u', $n))));
                return 'RS_' . $iniziali;
            })($form_name);
            $nextRow = $database->query("select max(id) m from `$table`", [], __FILE__)->fetch(\PDO::FETCH_ASSOC);
            $next = (int) ($nextRow['m'] ?? 0) + 1;
            $codice = $prefix . '#' . str_pad((string) $next, 3, '0', STR_PAD_LEFT) . '_' . date('y');

            $insertCols[] = 'codice_segnalazione';
            $insertVals[] = $codice;
        }

        try {
            $ph = implode(',', array_fill(0, count($insertCols), '?'));
            $colsSql = '`' . implode('`,`', $insertCols) . '`';
            $database->query("insert into `$table` ($colsSql) values ($ph)", $insertVals, __FILE__);
            $new_id = (int) $database->lastInsertId();

            // Salvataggio EAV
            self::saveDynamicFields($form_name, $new_id, $dynamicData);

            // Move files logic
            if (!empty($tempFilePaths)) {
                self::moveUploadedFilesToFinalLocation($form_name, $table, $new_id, $tempFilePaths);
            }

            self::updateSchedaSubmitFlag($form_name, $table, $new_id, 'Struttura', (int) ($_SESSION['user_id'] ?? 0));

            // Legacy manual notification removed in favor of processRules (on_submit) below

            // GESTIONE REGOLE NOTIFICA AVANZATE (Nuovo sistema)
            try {
                // Ricostruisci dati record per il template engine
                $recordData = $in;
                $recordData['id'] = $new_id;
                $recordData['submitted_by'] = $_SESSION['user_id'] ?? 0;
                $recordData['now'] = date('d/m/Y H:i');
                // Aggiungi dati form_values se presenti (EAV)
                $recordData = array_merge($recordData, $dynamicData);

                \Services\NotificationService::processRules($form_name, 'on_submit', $recordData);
            } catch (\Throwable $e) {
                error_log("Errore processRules on_submit: " . $e->getMessage());
            }

            return ['success' => true, 'id' => $new_id];

        } catch (\PDOException $e) {
            // Intercetta errore 1118 se dovesse ancora capitare (es. troppe colonne core)
            if ($e->getCode() == '42000' && strpos($e->getMessage(), '1118') !== false) {
                error_log("CRITICAL SQL 1118 in form submit: " . $e->getMessage());
                return ['success' => false, 'message' => 'Errore strutturale DB: limite colonne raggiunto. Contattare assistenza.', 'code' => 'DB_ROW_SIZE_LIMIT'];
            }
            throw $e;
        }
    }

    // ——— UPDATE (ex updateFormEntry) ———
    public static function update(array $in, array $files = []): array
    {
        global $database;
        $form_name = trim($in['form_name'] ?? '');
        $id = (int) ($in['record_id'] ?? 0);
        if (!$form_name || !$id)
            return ['success' => false, 'message' => 'Parametri mancanti'];

        $form = self::getFormByName($form_name);
        if (!$form)
            return ['success' => false, 'message' => 'Form non trovato'];
        $table = $form['table_name'];

        $st = $database->query("SELECT field_name, field_type FROM form_fields WHERE form_id=:f", [':f' => $form['id']], __FILE__);
        $fieldsDefs = $st ? $st->fetchAll(\PDO::FETCH_ASSOC) : [];

        $oldRow = $database->query("SELECT * FROM `$table` WHERE id=:id LIMIT 1", [':id' => $id], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        if (!$oldRow)
            return ['success' => false, 'message' => 'Record non trovato'];

        $possibleCoreCols = [
            'deadline',
            'titolo',
            'descrizione',
            'priority',
            'assegnato_a',
            'status_id',
            'assegnato_a_esito',
            'data_apertura_esito',
            'deadline_esito',
            'priorita_esito',
            'stato_esito'
        ];

        $coreCols = [];
        // Filtra solo colonne esistenti fisicamente per evitare errori SQL
        foreach ($possibleCoreCols as $c) {
            if (self::colExists($table, $c)) {
                $coreCols[] = $c;
            }
        }

        $sets = [];
        $vals = [];
        $dynamicData = [];

        // Permessi assegnato_a estesi (allineati a aggiornaAssegnatoA)
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $isAssegnatario = false;
        if (isset($oldRow['assegnato_a']) && (string) $oldRow['assegnato_a'] !== '') {
            $assIds = array_map('trim', explode(',', (string) $oldRow['assegnato_a']));
            foreach ($assIds as $aid) {
                if (is_numeric($aid) && (int) $aid === $uid) {
                    $isAssegnatario = true;
                    break;
                }
            }
        }
        $isCreatore = (isset($oldRow['submitted_by']) && (int) $oldRow['submitted_by'] === $uid);
        $canEditAssegnatoA = isAdmin() || ((int) ($form['responsabile'] ?? 0) === $uid) || $isAssegnatario || $isCreatore;

        // Core updates
        foreach ($coreCols as $b) {
            if (array_key_exists($b, $in)) {
                // Controllo permessi specifico per assegnato_a
                if (($b === 'assegnato_a' || $b === 'assegnato_a_esito') && !$canEditAssegnatoA)
                    continue;

                $sets[] = "`$b`=?";
                $vals[] = $in[$b];
            }
        }

        $processedFields = [];

        // Dynamic fields
        foreach ($fieldsDefs as $f) {
            $name = strtolower($f['field_name'] ?? '');
            if (!$name || in_array($name, $coreCols) || in_array($name, $processedFields, true))
                continue;

            $processedFields[] = $name;

            $val = null;
            $hasVal = false;

            if ($f['field_type'] === 'file') {
                if (!empty($files[$name]['tmp_name'])) {
                    if (self::handleUpload($files[$name], $table, $id, $savedPath, $err)) {
                        $val = $savedPath;
                        $hasVal = true;
                    }
                }
            } else {
                if (array_key_exists($name, $in)) {
                    $val = $in[$name];
                    $hasVal = true;
                } else if (array_key_exists($name . '[]', $in)) {
                    $val = $in[$name . '[]'];
                    $hasVal = true;
                }
                if (is_array($val))
                    $val = implode(',', $val);
            }

            if ($hasVal) {
                // Check physical existence
                if (self::colExists($table, $name)) {
                    $sets[] = "`$name`=?";
                    $vals[] = $val;
                } else {
                    $dynamicData[$name] = $val;
                }
            }
        }

        if (!empty($sets)) {
            $vals[] = $id;
            $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE id=?";
            $database->query($sql, $vals, __FILE__);
        }

        // Save EAV
        self::saveDynamicFields($form_name, $id, $dynamicData);

        // Notifica e update flag (Mantenuto logica esistente)

        // Notifica se assegnato_a è cambiato
        if (isset($in['assegnato_a'])) {
            $oldAssegnatoRaw = $oldRow['assegnato_a'] ?? null;
            $newAssegnatoRaw = $in['assegnato_a'];

            // Normalizza per confronto (potrebbero essere ID o Nominativi)
            $getUserId = function ($raw) use ($database) {
                if (empty($raw))
                    return null;
                if (is_numeric($raw))
                    return (int) $raw;
                $r = $database->query(
                    "select user_id from personale where Nominativo=:n limit 1",
                    [':n' => $raw],
                    __FILE__
                )->fetch(\PDO::FETCH_ASSOC);
                return $r['user_id'] ?? null;
            };

            $valOld = $getUserId($oldAssegnatoRaw);
            $valNew = $getUserId($newAssegnatoRaw);

            if ($valNew && $valNew != $valOld) {
                // Legacy manual notification removed in favor of processRules (on_assignment_change) below

                // INTEGRATION: Trigger Advanced Notification Rules for Assignment Change
                try {
                    $assignData = $oldRow;
                    $assignData = array_merge($assignData, $in);
                    $assignData['now'] = date('d/m/Y H:i');

                    \Services\NotificationService::processRules($form_name, 'on_assignment_change', $assignData);
                } catch (\Throwable $e) {
                    error_log("Error in processRules(on_assignment_change): " . $e->getMessage());
                }
            }
        }

        // INTEGRATION: Trigger Advanced Notification Rules for Status Change (Real change only)
        if (isset($in['status_id']) && (int) $in['status_id'] !== (int) ($oldRow['status_id'] ?? 0)) {
            try {
                $statusData = $oldRow;
                $statusData = array_merge($statusData, $in);
                $statusData = array_merge($statusData, $dynamicData);
                $statusData['id'] = $id;
                $statusData['now'] = date('d/m/Y H:i');

                \Services\NotificationService::processRules($form_name, 'on_status_change', $statusData);
            } catch (\Throwable $e) {
                error_log("Error in processRules(on_status_change) from update: " . $e->getMessage());
            }
        }

        if (isset($in['titolo']) || isset($in['descrizione']) || isset($in['deadline'])) {
            self::updateSchedaSubmitFlag($form_name, $table, $id, 'Struttura', $uid);
        }

        return ['success' => true];
    }


    /**
     * Salva una subtask (scheda) collegata a un record principale
     */
    public static function saveSubtask(array $in, array $files = []): array
    {
        global $database;

        $form_name = trim($in['form_name'] ?? '');
        $parent_record_id = (int) ($in['parent_record_id'] ?? 0);
        $scheda_label = trim($in['scheda_label'] ?? '');
        $scheda_data = $in['scheda_data'] ?? [];
        if (is_string($scheda_data)) {
            $decoded = json_decode($scheda_data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $scheda_data = $decoded;
            } else {
                $scheda_data = [];
            }
        }
        if (!is_array($scheda_data)) {
            $scheda_data = [];
        }

        if (!$form_name || !$parent_record_id || !$scheda_label) {
            return ['success' => false, 'message' => 'Parametri mancanti (form_name, parent_record_id, scheda_label)'];
        }

        $form = self::getFormByName($form_name);
        if (!$form) {
            return ['success' => false, 'message' => 'Form non trovato'];
        }

        $table = $form['table_name'];

        // Assicura che la tabella abbia le colonne necessarie
        self::ensureSubtaskColumns($table);

        // Verifica che il record principale esista
        $mainRecord = $database->query(
            "SELECT * FROM `$table` WHERE id = ? AND parent_record_id IS NULL LIMIT 1",
            [$parent_record_id],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$mainRecord) {
            return ['success' => false, 'message' => 'Record principale non trovato'];
        }

        // Cerca se esiste già una subtask per questa scheda
        $existing = $database->query(
            "SELECT id FROM `$table` WHERE parent_record_id = ? AND scheda_label = ? LIMIT 1",
            [$parent_record_id, $scheda_label],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        $titolo = "Scheda: " . $scheda_label;
        $descrizione = "Dati della scheda " . $scheda_label;
        $status_id = $mainRecord['status_id'] ?? 1;
        $submitted_by = $mainRecord['submitted_by'] ?? 0;
        $deadline = isset($mainRecord['deadline']) ? $mainRecord['deadline'] : null;
        $priority = isset($mainRecord['priority']) ? $mainRecord['priority'] : 'media';

        // Controlla quali colonne esistono
        $hasAssegnato = self::colExists($table, 'assegnato_a');
        $hasDeadline = self::colExists($table, 'deadline');
        $hasPriority = self::colExists($table, 'priority');
        $hasSubmittedBy = self::colExists($table, 'submitted_by');
        $hasStatusId = self::colExists($table, 'status_id');

        if ($existing) {
            // Aggiorna la subtask esistente
            if (!empty($files)) {
                foreach ($files as $fname => $file) {
                    if (empty($file['tmp_name'])) {
                        continue;
                    }
                    $savedPath = null;
                    $err = null;
                    if (self::handleUpload($file, $table, (int) $existing['id'], $savedPath, $err)) {
                        $scheda_data[$fname] = $savedPath;
                    } else {
                        return ['success' => false, 'message' => $err ?: 'Errore upload file subtask'];
                    }
                }
            }
            $sets = [
                'titolo = ?',
                'descrizione = ?',
                'scheda_data = ?'
            ];
            $vals = [$titolo, $descrizione, json_encode($scheda_data)];

            if ($hasStatusId) {
                $sets[] = 'status_id = ?';
                $vals[] = $status_id;
            }
            if ($hasPriority) {
                $sets[] = 'priority = ?';
                $vals[] = $priority;
            }

            $vals[] = $existing['id'];

            $database->query(
                "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE id = ?",
                $vals,
                __FILE__
            );

            // GESTIONE SEPARATA PER SCHEDA: Aggiorna lo stato anche per update di subtask esistente
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            self::updateSchedaSubmitFlag($form_name, $table, $parent_record_id, $scheda_label, $uid);

            return ['success' => true, 'message' => 'Subtask aggiornata', 'subtask_id' => $existing['id'], 'action' => 'updated'];
        } else {
            // Crea una nuova subtask - inserisci solo le colonne che esistono
            $cols = ['parent_record_id', 'scheda_label', 'scheda_data', 'titolo', 'descrizione'];
            $vals = [$parent_record_id, $scheda_label, json_encode($scheda_data), $titolo, $descrizione];

            if ($hasStatusId) {
                $cols[] = 'status_id';
                $vals[] = $status_id;
            }
            if ($hasSubmittedBy) {
                $cols[] = 'submitted_by';
                $vals[] = $submitted_by;
            }
            if ($hasAssegnato) {
                $cols[] = 'assegnato_a';
                $vals[] = isset($mainRecord['assegnato_a']) ? $mainRecord['assegnato_a'] : null;
            }
            if ($hasDeadline) {
                $cols[] = 'deadline';
                $vals[] = $deadline;
            }
            if ($hasPriority) {
                $cols[] = 'priority';
                $vals[] = $priority;
            }

            // Aggiungi submitted_at se la colonna esiste
            if (self::colExists($table, 'submitted_at')) {
                $cols[] = 'submitted_at';
                $vals[] = date('Y-m-d H:i:s');
            }

            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $colsSql = '`' . implode('`,`', $cols) . '`';

            $database->query(
                "INSERT INTO `$table` ($colsSql) VALUES ($placeholders)",
                $vals,
                __FILE__
            );

            $subtask_id = (int) $database->lastInsertId();

            if (!empty($files)) {
                $updated = $scheda_data;
                foreach ($files as $fname => $file) {
                    if (empty($file['tmp_name'])) {
                        continue;
                    }
                    $savedPath = null;
                    $err = null;
                    if (self::handleUpload($file, $table, (int) $subtask_id, $savedPath, $err)) {
                        $updated[$fname] = $savedPath;
                    } else {
                        return ['success' => false, 'message' => $err ?: 'Errore upload file subtask'];
                    }
                }
                $database->query(
                    "UPDATE `$table` SET scheda_data = ? WHERE id = ?",
                    [json_encode($updated), $subtask_id],
                    __FILE__
                );
            }

            // GESTIONE SEPARATA PER SCHEDA: Aggiorna lo stato di questa specifica scheda
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            self::updateSchedaSubmitFlag($form_name, $table, $parent_record_id, $scheda_label, $uid);

            return ['success' => true, 'message' => 'Subtask creata', 'subtask_id' => $subtask_id, 'action' => 'created'];
        }
    }

    public static function updateFormStatus(array $in): array
    {
        global $database;

        $form_name = trim((string) ($in['form_name'] ?? ''));
        $table = '';
        $record_id = (int) ($in['record_id'] ?? ($in['form_id'] ?? 0));
        $status_id = (int) ($in['status_id'] ?? ($in['stato'] ?? 0));

        if ($status_id <= 0)
            return ['success' => false, 'message' => 'status_id/stato mancante o non valido'];

        // risolvi form + tabella
        if ($form_name !== '') {
            $form = self::getFormByName($form_name);
            if (!$form)
                return ['success' => false, 'message' => 'form non trovato'];
            $table = $form['table_name'];
        } else {
            $table = preg_replace('/[^a-z0-9_]/i', '', (string) ($in['table_name'] ?? ''));
            if ($table === '')
                return ['success' => false, 'message' => 'table_name mancante'];
            $stForm = $database->query(
                "SELECT id, name, responsabile FROM forms WHERE table_name = :t LIMIT 1",
                [':t' => $table],
                __FILE__ . ' ⇒ updateFormStatus.findForm'
            );
            $form = $stForm ? $stForm->fetch(\PDO::FETCH_ASSOC) : null;
            if (!$form)
                return ['success' => false, 'message' => 'form non trovato'];
            $form_name = (string) $form['name'];
        }

        if ($record_id <= 0)
            return ['success' => false, 'message' => 'record_id/form_id mancante o non valido'];

        // carica riga (per assegnato_a) e identità - usa SELECT * per evitare errori se la colonna non esiste
        $row = $database->query(
            "SELECT * FROM `{$table}` WHERE id=:id LIMIT 1",
            [':id' => $record_id],
            __FILE__ . ' ⇒ updateFormStatus.loadRow'
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$row)
            return ['success' => false, 'message' => 'record non trovato'];

        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $isAdm = isAdmin();
        $isRes = ((int) ($form['responsabile'] ?? 0) === $uid);
        // Controlla se la colonna assegnato_a esiste prima di usarla
        $isAss = false;
        if (isset($row['assegnato_a']) && (string) $row['assegnato_a'] !== '') {
            $assIds = array_map('trim', explode(',', (string) $row['assegnato_a']));
            $isAss = in_array((string) $uid, $assIds, true);
        }

        // --- carica la config modulo (gestione_richiesta) ---
        $cfgRes = \Services\PageEditorService::getModuleConfig([
            'form_name' => $form_name,
            'module_key' => 'gestione_richiesta'
        ]);
        $cfg = ($cfgRes['success'] ?? false) ? ($cfgRes['config'] ?? []) : [];

        // --- permessi di chi può cambiare lo stato ---
        $perm = (string) ($cfg['permessi'] ?? 'responsabile_o_assegnatario');
        $allowed = false;
        if ($perm === 'solo_responsabile')
            $allowed = ($isAdm || $isRes);
        elseif ($perm === 'admin_responsabile_assegnatario')
            $allowed = ($isAdm || $isRes || $isAss);
        else /* responsabile_o_assegnatario */
            $allowed = ($isAdm || $isRes || $isAss);
        if (!$allowed)
            return ['success' => false, 'message' => 'permesso negato'];

        // --- lista stati ammessi dalla config (se presente) ---
        if (!empty($cfg['stati_visibili']) && is_array($cfg['stati_visibili'])) {
            $allowedStates = array_map('intval', $cfg['stati_visibili']);
            if (!in_array($status_id, $allowedStates, true)) {
                return ['success' => false, 'message' => 'stato non consentito da configurazione'];
            }
        }

        // aggiorna lo stato
        $ok = $database->query(
            "UPDATE `{$table}` SET status_id = :s WHERE id = :id",
            [':s' => $status_id, ':id' => $record_id],
            __FILE__ . ' ⇒ updateFormStatus.update'
        );
        if (!$ok)
            return ['success' => false, 'message' => 'errore salvataggio'];

        // INTEGRATION: Advanced Notification Rules for Status Change
        try {
            if ($row['status_id'] != $status_id) {
                $statusData = $row;
                $statusData['status_id'] = $status_id;
                $statusData['id'] = $record_id;
                $statusData['now'] = date('d/m/Y H:i');

                \Services\NotificationService::processRules($form_name, 'on_status_change', $statusData);
            }
        } catch (\Throwable $e) {
            // Non bloccare il ritorno success
            error_log("Errore invio notifica in updateFormStatus: " . $e->getMessage());
        }

        return ['success' => true, 'status_id' => $status_id, 'form_name' => $form_name, 'record_id' => $record_id];
    }

    /**
     * Aggiorna una data di un record (drag&drop dal calendario).
     * Input:
     * - form_id (int)            → id del record (obbligatorio)
     * - table_name (string)      → tabella (consigliato)
     * - form_name (string)       → alternativo a table_name
     * - field (string)           → 'data_scadenza' | 'deadline' | 'data_uscita'
     * - value (YYYY-MM-DD)       → nuova data
     */
    public static function updateDate(array $in): array
    {
        global $database;

        $recordId = (int) ($in['form_id'] ?? 0);
        if ($recordId <= 0)
            return ['success' => false, 'message' => 'form_id mancante o non valido'];

        $fieldUi = strtolower(trim((string) ($in['field'] ?? '')));
        if ($fieldUi === '')
            return ['success' => false, 'message' => 'field mancante'];

        $val = trim((string) ($in['value'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val))
            return ['success' => false, 'message' => 'Formato data non valido (YYYY-MM-DD)'];
        [$yy, $mm, $dd] = array_map('intval', explode('-', $val));
        if (!checkdate($mm, $dd, $yy))
            return ['success' => false, 'message' => 'Data non valida'];

        // 1) Risolvi tabella e (se disponibile) il form
        $table = '';
        $form = null;

        if (!empty($in['form_name'])) {
            $form = self::getFormByName(trim((string) $in['form_name']));
            if ($form)
                $table = (string) $form['table_name'];
        }

        if ($table === '' && !empty($in['table_name'])) {
            $table = preg_replace('/[^a-z0-9_]/i', '', (string) $in['table_name']);
            if ($table !== '') {
                $st = $database->query("SELECT * FROM forms WHERE table_name=:t LIMIT 1", [':t' => $table], __FILE__ . ' ⇒ updateDate.findFormByTable');
                $form = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
            }
        }

        // Tabella deve esistere
        if ($table === '')
            return ['success' => false, 'message' => 'tabella non fornita'];
        $chkT = $database->query("SHOW TABLES LIKE :t", [':t' => $table], __FILE__ . ' ⇒ updateDate.checkTable');
        if (!$chkT || $chkT->rowCount() === 0)
            return ['success' => false, 'message' => 'tabella inesistente'];

        // 2) Determina la colonna reale da aggiornare, in base a cosa ESISTE
        $hasDeadline = self::colExists($table, 'deadline');
        $hasDataScadenza = self::colExists($table, 'data_scadenza');
        $hasDataUscita = self::colExists($table, 'data_uscita');

        $col = '';
        if ($fieldUi === 'data_uscita') {
            if ($hasDataUscita)
                $col = 'data_uscita';
        } else {
            // data_scadenza|deadline → preferisci deadline, altrimenti data_scadenza
            if ($hasDeadline)
                $col = 'deadline';
            elseif ($hasDataScadenza)
                $col = 'data_scadenza';
        }
        if ($col === '') {
            return ['success' => false, 'message' => "campo non gestito: nella tabella '{$table}' non trovo una colonna compatibile"];
        }

        // 3) Prepara SELECT della riga con colonne esistenti (evita Fatal)
        $hasAss = self::colExists($table, 'assegnato_a');
        $hasSub = self::colExists($table, 'submitted_by');
        $selCols = ['id'];
        if ($hasAss)
            $selCols[] = 'assegnato_a';
        if ($hasSub)
            $selCols[] = 'submitted_by';
        $sqlSel = "SELECT " . implode(',', $selCols) . " FROM `{$table}` WHERE id=:id LIMIT 1";

        $row = $database->query($sqlSel, [':id' => $recordId], __FILE__ . ' ⇒ updateDate.loadRow')->fetch(\PDO::FETCH_ASSOC);
        if (!$row)
            return ['success' => false, 'message' => 'record non trovato'];

        // 4) Permessi: con form uso la policy configurata; senza form → admin o (assegnatario/autore se colonne presenti)
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        $isAdm = isAdmin();

        $allowed = false;
        if ($form) {
            $isRes = ((int) ($form['responsabile'] ?? 0) === $uid);
            $isAss = false;
            if ($hasAss && (string) ($row['assegnato_a'] ?? '') !== '') {
                $assIds = array_map('trim', explode(',', (string) ($row['assegnato_a'] ?? '')));
                $isAss = in_array((string) $uid, $assIds, true);
            }

            $cfgRes = \Services\PageEditorService::getModuleConfig([
                'form_name' => $form['name'],
                'module_key' => 'gestione_richiesta'
            ]);
            $cfg = ($cfgRes['success'] ?? false) ? ($cfgRes['config'] ?? []) : [];
            $perm = (string) ($cfg['permessi'] ?? 'responsabile_o_assegnatario');

            if ($perm === 'solo_responsabile')
                $allowed = ($isAdm || $isRes);
            elseif ($perm === 'admin_responsabile_assegnatario')
                $allowed = ($isAdm || $isRes || $isAss);
            else /* responsabile_o_assegnatario */
                $allowed = ($isAdm || $isRes || $isAss);
        } else {
            $isAss = false;
            if ($hasAss && (string) ($row['assegnato_a'] ?? '') !== '') {
                $assIds = array_map('trim', explode(',', (string) ($row['assegnato_a'] ?? '')));
                $isAss = in_array((string) $uid, $assIds, true);
            }
            $isAut = ($hasSub && (string) ($row['submitted_by'] ?? '') !== '' && (string) $row['submitted_by'] === (string) $uid);
            $allowed = ($isAdm || $isAss || $isAut);
            if (!$hasAss && !$hasSub) {
                // Se la tabella non ha colonne di ownership, consenti solo admin
                $allowed = $isAdm;
            }
        }
        if (!$allowed)
            return ['success' => false, 'message' => 'permesso negato'];

        // 5) Update
        $ok = $database->query(
            "UPDATE `{$table}` SET `{$col}` = :v WHERE id = :id",
            [':v' => $val, ':id' => $recordId],
            __FILE__ . ' ⇒ updateDate.update'
        );
        if (!$ok)
            return ['success' => false, 'message' => 'errore salvataggio'];

        return [
            'success' => true,
            'form_name' => $form['name'] ?? null,
            'table_name' => $table,
            'record_id' => $recordId,
            'field' => $col,
            'value' => $val
        ];
    }

    public static function listSegnalazioniFilled(array $filtri = []): array
    {
        global $database;

        // mappa personale per nomi/immagini
        $personale = $database->query("select user_id, Nominativo from personale", [], __FILE__);
        $pmap = [];
        foreach ($personale ?: [] as $p) {
            $pmap[(string) $p['user_id']] = $p['Nominativo'];
        }

        $forms = $database->query("select id,name,table_name,responsabile,color,protocollo from forms", [], __FILE__);
        $out = [];

        foreach ($forms ?: [] as $f) {
            if (!empty($filtri['form_name']) && strtolower($filtri['form_name']) !== strtolower($f['name']))
                continue;

            // filtro responsabile (nome) se richiesto
        if (!empty($filtri['responsabile'])) {
            $rid = array_search($filtri['responsabile'], $pmap, true);
            $r_ids = array_filter(explode(',', (string) ($f['responsabile'] ?? '')));
            if ($rid === false || !in_array((string)$rid, $r_ids))
                continue;
        }

            // tabella esiste?
            $t = $f['table_name'];
            $chk = $database->query("show tables like :t", [':t' => $t], __FILE__);
            if (!$chk || $chk->rowCount() === 0)
                continue;

            // alcune colonne opzionali
            $has_titolo = (bool) $database->query("show columns from `$t` like 'titolo'", [], __FILE__)->rowCount();
            $has_arch = (bool) $database->query("show columns from `$t` like 'archiviata'", [], __FILE__)->rowCount();
            $has_assegnato = (bool) $database->query("show columns from `$t` like 'assegnato_a'", [], __FILE__)->rowCount();
            $has_desc = (bool) $database->query("show columns from `$t` like 'descrizione'", [], __FILE__)->rowCount();

            $sql = "select id,submitted_by,submitted_at,deadline,status_id,codice_segnalazione,priority"
                . ($has_assegnato ? ",assegnato_a" : "")
                . ($has_titolo ? ",titolo" : "")
                . ($has_desc ? ",descrizione" : "")
                . " from `$t` where 1=1";
            $params = [];

            if (!empty($filtri['status_id'])) {
                $sql .= " and status_id = :s";
                $params[':s'] = (int) $filtri['status_id'];
            }

            if (!empty($filtri['data_invio_min'])) {
                $sql .= " and date(submitted_at) >= :dmin";
                $params[':dmin'] = $filtri['data_invio_min'];
            }

            if (!empty($filtri['archivio'])) {
                if (!$has_arch)
                    continue;
                $sql .= " and archiviata = 1";
            } else {
                if ($has_arch)
                    $sql .= " and (archiviata is null or archiviata = 0)";
            }

            // Filtra solo i record principali (non le subtasks) - SE la colonna esiste
            try {
                $has_parent = (bool) $database->query("show columns from `$t` like 'parent_record_id'", [], __FILE__)->rowCount();
                if ($has_parent) {
                    $sql .= " and (parent_record_id is null or parent_record_id = 0)";
                }
            } catch (\Exception $e) {
                // Ignora - la colonna non esiste ancora
            }

            $sql .= " order by submitted_at desc";
            $rows = $database->query($sql, $params, __FILE__);

            foreach ($rows ?: [] as $r) {
                $resp_id = $f['responsabile'] ?? null;
                $responsabili_arr = [];
                $first_nome = null;
                $first_img = null;

                if ($resp_id) {
                    $rIds = array_filter(explode(',', (string) $resp_id));
                    foreach ($rIds as $index => $rid) {
                        $nm = $pmap[(string) $rid] ?? '—';
                        $img = getProfileImage($nm, 'nominativo') ?: '/assets/images/default_profile.png';
                        if ($img && strpos($img, '/') !== 0) {
                            $img = '/' . $img;
                        }
                        $responsabili_arr[] = [
                            'id' => $rid,
                            'nome' => $nm,
                            'img' => $img
                        ];
                        if ($index === 0) {
                            $first_nome = $nm;
                            $first_img = $img;
                        }
                    }
                }

                $resp_nome = $first_nome ?: '—';
                $resp_img = $first_img ?: '/assets/images/default_profile.png';

                $aut_id = $r['submitted_by'] ?? null;
                $aut_nome = $aut_id ? ($pmap[(string) $aut_id] ?? '—') : '—';
                $aut_img = getProfileImage($aut_nome, 'nominativo') ?: '/assets/images/default_profile.png';
                if ($aut_img && strpos($aut_img, '/') !== 0)
                    $aut_img = '/' . $aut_img;

                $ass_id = $has_assegnato ? ($r['assegnato_a'] ?? null) : null;
                $ass_nome = null;
                if ($ass_id !== null && $ass_id !== '') {
                    $assIds = array_map('trim', explode(',', (string) $ass_id));
                    $nomi = [];
                    foreach ($assIds as $aid) {
                        if ($aid !== '' && isset($pmap[(string) $aid])) {
                            $nomi[] = $pmap[(string) $aid];
                        }
                    }
                    $ass_nome = !empty($nomi) ? implode(', ', $nomi) : null;
                }

                // Recupera le subtasks per questo record (se supportate)
                $subtasks = [];
                try {
                    // Assicurati che la tabella abbia le colonne per le subtasks
                    self::ensureSubtaskColumns($t);

                    $subtasks = self::getSubtasks($t, (int) $r['id']);

                    // Se non ci sono subtasks, prova a sincronizzarle automaticamente
                    if (empty($subtasks)) {
                        // Sincronizza in modo silenzioso
                        self::syncSubtasksFromTabs([
                            'form_name' => $f['name'],
                            'record_id' => (int) $r['id']
                        ]);

                        // Ricarica le subtasks dopo la sincronizzazione
                        $subtasks = self::getSubtasks($t, (int) $r['id']);
                    }
                } catch (\Exception $e) {
                    // Ignora errori - probabilmente la tabella non ha ancora le colonne
                    error_log("Errore caricamento subtasks per {$t} record {$r['id']}: " . $e->getMessage());
                }

                $out[] = [
                    'id' => (int) $r['id'],
                    'form_name' => $f['name'],
                    'creato_da' => $aut_nome,
                    'creato_da_img' => $aut_img,
                    'assegnato_a' => $ass_id,
                    'assegnato_a_nome' => $ass_nome,
                    'responsabile' => $resp_id,
                    'responsabile_nome' => $resp_nome,
                    'responsabile_img' => $resp_img,
                    'responsabili' => $responsabili_arr,
                    'submitted_by' => (int) $r['submitted_by'],
                    'data_invio' => $database->formatDate($r['submitted_at']),
                    'data_scadenza' => $database->formatDate($r['deadline']),
                    'priority' => $r['priority'] ?? 'media',
                    'stato' => (int) $r['status_id'],
                    'table_name' => $t,
                    'color' => $f['color'] ?? '#cccccc',
                    'titolo' => $has_titolo ? ($r['titolo'] ?? null) : null,
                    'descrizione' => $has_desc ? ($r['descrizione'] ?? null) : null,
                    'codice_segnalazione' => $r['codice_segnalazione'] ?? null,
                    'subtasks' => $subtasks
                ];
            }
        }

        return ['success' => true, 'forms' => $out];
    }

    public static function listscadenze(?int $user_id = null): array
    {
        global $database;
        $forms = $database->query("select id,name,table_name,color,responsabile from forms", [], __FILE__);
        $out = [];

        foreach ($forms ?: [] as $f) {
            $t = $f['table_name'];
            $resp = (int) ($f['responsabile'] ?? 0);

            $x = $database->query("show tables like :t", [':t' => $t], __FILE__);
            if (!$x || $x->rowCount() === 0)
                continue;

            $c = $database->query("show columns from `$t` like 'deadline'", [], __FILE__);
            if (!$c || $c->rowCount() === 0)
                continue;

            // Controlla se esiste la colonna assegnato_a
            $has_assegnato = (bool) $database->query("show columns from `$t` like 'assegnato_a'", [], __FILE__)->rowCount();

            $sql = "select id,titolo,deadline,status_id,priority,codice_segnalazione"
                . ($has_assegnato ? ",assegnato_a" : "")
                . " from `$t` where deadline is not null";

            $rows = $database->query($sql, [], __FILE__);

            foreach ($rows ?: [] as $r) {
                if ($has_assegnato && $user_id) {
                    $assIds = array_map('trim', explode(',', (string) ($r['assegnato_a'] ?? '')));
                    $isAssOrResp = in_array((string) $user_id, $assIds, true) || ($resp === (int) $user_id);
                    if (!$isAssOrResp)
                        continue;
                }

                $out[] = [
                    'id' => (int) $r['id'],
                    'titolo' => $r['titolo'] ?? '',
                    'deadline' => $r['deadline'],
                    'status_id' => (int) ($r['status_id'] ?? 0),
                    'priority' => $r['priority'] ?? null,
                    'assegnato_a' => $has_assegnato ? ($r['assegnato_a'] ?? null) : null,
                    'codice_segnalazione' => $r['codice_segnalazione'] ?? null,
                    'form_name' => $f['name'],
                    'table_name' => $t,
                    'color' => $f['color'] ?? '#ccc'
                ];
            }
        }
        return ['success' => true, 'scadenze' => $out];
    }

    public static function aggiornaAssegnatoA(array $in): array
    {
        global $database;

        $form_id = (int) ($in['form_id'] ?? 0);
        $assegn = trim((string) ($in['assegnato_a'] ?? ''));

        // ✅ robust admin check
        $userid = (int) ($_SESSION['user_id'] ?? 0);
        $isAdmin = isAdmin();

        if (!$userid || !$form_id)
            return ['success' => false, 'message' => 'parametri mancanti'];

        // ✅ risolvi tabella partendo da form_name o da table_name “sporco”
        $table = '';
        $formRow = null;

        if (!empty($in['form_name'])) {
            $formRow = self::getFormByName(trim((string) $in['form_name']));
            if ($formRow)
                $table = $formRow['table_name'];
        }
        if ($table === '') {
            $tableFromInput = preg_replace('/[^a-z0-9_ ]/i', '', (string) ($in['table_name'] ?? ''));
            // prova prima come table_name reale
            $st = $database->query("SELECT * FROM forms WHERE table_name=:t LIMIT 1", [':t' => $tableFromInput], __FILE__);
            $formRow = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;

            // se non trovato, prova come name “umano”
            if (!$formRow) {
                $st = $database->query("SELECT * FROM forms WHERE name=:n LIMIT 1", [':n' => $tableFromInput], __FILE__);
                $formRow = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
            }
            if ($formRow)
                $table = $formRow['table_name'];
        }

        if ($table === '' || !$formRow) {
            return ['success' => false, 'message' => 'form non trovato'];
        }

        // Controlla se esiste la colonna assegnato_a
        $has_assegnato = (bool) $database->query("show columns from `$table` like 'assegnato_a'", [], __FILE__)->rowCount();
        if (!$has_assegnato) {
            return ['success' => false, 'message' => 'campo assegnato_a non disponibile per questo form'];
        }

        // Carica il record per verificare assegnatario attuale e creatore
        $row = $database->query(
            "SELECT * FROM `{$table}` WHERE id=:id LIMIT 1",
            [':id' => $form_id],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['success' => false, 'message' => 'record non trovato'];
        }

        // Permessi: solo admin, responsabile del form o assegnatario attuale possono modificare
        $respId = (int) ($formRow['responsabile'] ?? 0);
        $isResponsabile = ((string) $respId === (string) $userid);
        $isAssegnatario = false;
        if (isset($row['assegnato_a']) && (string) $row['assegnato_a'] !== '') {
            $assIds = array_map('trim', explode(',', (string) $row['assegnato_a']));
            foreach ($assIds as $aid) {
                if ((string) $aid === (string) $userid) {
                    $isAssegnatario = true;
                    break;
                }
            }
        }

        if (!$isAdmin && !$isResponsabile && !$isAssegnatario) {
            return ['success' => false, 'message' => 'Solo admin, responsabile o assegnatario possono modificare l\'assegnatario'];
        }

        $ok = $database->query("UPDATE `{$table}` SET assegnato_a=:a WHERE id=:id LIMIT 1", [':a' => $assegn, ':id' => $form_id], __FILE__);
        if (!$ok)
            return ['success' => false, 'message' => 'errore salvataggio'];

        // INTEGRATION: Advanced Notification Rules for Assignment Change
        if ($assegn !== '') {
            try {
                $assignData = $row;
                $assignData['assegnato_a'] = $assegn;
                $assignData['id'] = $form_id;
                $assignData['now'] = date('d/m/Y H:i');

                \Services\NotificationService::processRules($formRow['name'], 'on_assignment_change', $assignData);
            } catch (\Throwable $e) {
                error_log("Error in processRules(on_assignment_change) from aggiornaAssegnatoA: " . $e->getMessage());
            }
        }
        return ['success' => true];
    }



    // ——— LISTE UTENTI / INFO (compatibilità viewer) ———
    public static function getUtentiList(): array
    {
        global $database;
        $st = $database->query("SELECT user_id, Nominativo FROM personale WHERE Attivo=1 ORDER BY Nominativo ASC", [], __FILE__);
        $rows = $st ? $st->fetchAll(\PDO::FETCH_ASSOC) : [];
        // prova ad arricchire con immagine
        foreach ($rows as &$r) {
            $img = getProfileImage($r['Nominativo'], 'nominativo');
            $r['image'] = $img ?: '/assets/images/default_profile.png';
        }
        return ['success' => true, 'data' => $rows];
    }

    public static function getResponsabileInfo(array $in): array
    {
        global $database;
        $uid = (int) ($in['user_id'] ?? 0);
        if (!$uid)
            return ['success' => false];
        $st = $database->query("SELECT Nominativo FROM personale WHERE user_id=:u LIMIT 1", [':u' => $uid], __FILE__);
        $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
        if (!$row)
            return ['success' => false];
        $img = getProfileImage($row['Nominativo'], 'nominativo');
        return ['success' => true, 'nominativo' => $row['Nominativo'], 'image' => $img ?: '/assets/images/default_profile.png'];
    }

    // ——— METADATI (colore/protocollo senza permessi admin) ———
    public static function getFormMeta(array $in): array
    {
        $f = self::getFormByName(trim($in['form_name'] ?? ''));
        if (!$f)
            return ['success' => false, 'message' => 'Form non trovato'];
        return ['success' => true, 'color' => $f['color'] ?? '#CCC', 'protocollo' => $f['protocollo'] ?? null];
    }

    /**
     * Wrapper pubblico per handleUpload (usato da PageEditorService::submitScheda per file nelle schede)
     */
    public static function handleUploadPublic(array $file, string $table_name, ?int $record_id, ?string &$savedPath, ?string &$err): bool
    {
        return self::handleUpload($file, $table_name, $record_id, $savedPath, $err);
    }

    // ——— Upload helper ———
    private static function handleUpload(array $file, string $table_name, ?int $record_id, ?string &$savedPath, ?string &$err): bool
    {
        $err = null;
        $savedPath = null;

        error_log("[handleUpload] Inizio upload - table: $table_name, record_id: " . ($record_id ?? 'null') . ", error: {$file['error']}, tmp_name: {$file['tmp_name']}");

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $err = 'Errore upload (code: ' . $file['error'] . ')';
            error_log("[handleUpload] ERRORE: $err");
            return false;
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            $err = 'Immagine troppo pesante (max 5MB)';
            return false;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        // Estendi supporto a più tipi di file
        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv'
        ];

        if (!in_array($mime, $allowedMimes)) {
            $err = 'Formato non supportato (MIME: ' . $mime . ')';
            return false;
        }

        // Recupera root assoluta in modo sicuro
        $baseDir = defined('ROOT') ? ROOT : dirname(__DIR__);
        // Se defined ROOT fallisse o fosse vuoto, fallback su dirname(__DIR__)
        if (!$baseDir)
            $baseDir = dirname(__DIR__);

        // Verifica che la cartella uploads esista
        $uploadsBase = rtrim($baseDir, '/\\') . '/uploads';
        if (!is_dir($uploadsBase)) {
            if (!@mkdir($uploadsBase, 0777, true)) {
                $lastError = error_get_last();
                $err = 'Impossibile creare la cartella base uploads: ' . ($lastError['message'] ?? 'errore sconosciuto');
                error_log("[handleUpload] ERRORE: $err");
                return false;
            }
            @chmod($uploadsBase, 0777);
        }

        // Se record_id è null (durante submit), salva in temp, altrimenti nella cartella finale
        if ($record_id === null) {
            $relDir = "uploads/segnalazioni/{$table_name}/temp_" . uniqid();
        } else {
            $date = date('dmY');
            $relDir = "uploads/segnalazioni/{$table_name}/{$record_id}_{$date}";
        }

        // Costruisci il percorso assoluto
        $absDir = rtrim($baseDir, '/\\') . '/' . $relDir;

        // Crea la directory con permessi corretti (0777 come da richiesta)
        if (!is_dir($absDir)) {
            // Check pre-creazione stile vecchio sistema
            if (!is_writable(dirname($absDir))) {
                error_log("[handleUpload] ATTENZIONE: La cartella padre " . dirname($absDir) . " non sembra scrivibile.");
            }

            error_log("[handleUpload] Creando directory: $absDir");
            if (!@mkdir($absDir, 0777, true)) {
                $lastError = error_get_last();
                $err = 'Impossibile creare la cartella di upload: ' . ($lastError['message'] ?? 'errore sconosciuto');
                error_log("[handleUpload] ERRORE creazione directory: $err");
                return false;
            }
            @chmod($absDir, 0777);
        }

        // Verifica finale scrittura
        if (!is_writable($absDir)) {
            error_log("[handleUpload] Directory non scrivibile, provo chmod 0777...");
            @chmod($absDir, 0777);
            if (!is_writable($absDir)) {
                $err = 'Directory di upload non scrivibile: ' . $absDir;
                error_log("[handleUpload] ERRORE: $err");
                return false;
            }
        }

        // Gestione salvataggio in base al tipo
        $isImage = in_array($mime, ['image/jpeg', 'image/png', 'image/webp']);

        if ($isImage) {
            $name = uniqid('img_', true);
            $dest = $absDir . '/' . $name . '.webp';

            $compressResult = compressUploadedImage($file['tmp_name'], $dest, [
                'maxWidth' => 800,
                'maxHeight' => 800,
                'quality' => 75,
                'outputFormat' => 'webp',
                'stripMetadata' => true,
                'keepOriginal' => false
            ]);

            if ($compressResult['ok']) {
                $savedPath = $relDir . '/' . basename($compressResult['path']);
                return true;
            } else {
                // Fallback
                $ext = 'jpg';
                if ($mime === 'image/png')
                    $ext = 'png';
                elseif ($mime === 'image/webp')
                    $ext = 'webp';

                $name = uniqid('img_', true) . '.' . $ext;
                $dest = $absDir . '/' . $name;

                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    $err = 'Salvataggio file fallito';
                    return false;
                }
                $savedPath = $relDir . '/' . $name;
                return true;
            }
        } else {
            $extMap = [
                'image/gif' => 'gif',
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'text/plain' => 'txt',
                'text/csv' => 'csv'
            ];

            $ext = $extMap[$mime] ?? 'dat';
            $name = uniqid('doc_', true) . '.' . $ext;
            $dest = $absDir . '/' . $name;

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                $err = 'Salvataggio documento fallito';
                return false;
            }
            $savedPath = $relDir . '/' . $name;
            return true;
        }
    }

    /**
     * Sposta i file dalla cartella temporanea alla cartella finale dopo il submit
     */
    /**
     * Sposta i file dalla cartella temporanea alla cartella finale dopo il submit
     */
    private static function moveUploadedFilesToFinalLocation(string $form_name, string $table_name, int $record_id, array $tempFilePaths): void
    {
        global $database;
        error_log("[moveUploadedFilesToFinalLocation] Inizio - table: $table_name, record_id: $record_id");

        $baseDir = defined('ROOT') ? ROOT : dirname(__DIR__);
        if (!$baseDir)
            $baseDir = dirname(__DIR__);

        $date = date('dmY');
        $relFinalDir = "uploads/segnalazioni/{$table_name}/{$record_id}_{$date}";
        $finalDir = rtrim($baseDir, '/\\') . '/' . $relFinalDir;

        // Crea directory finale con 0777
        if (!is_dir($finalDir)) {
            if (!is_writable(dirname($finalDir))) {
                error_log("[moveFiles] ATTENZIONE: Padre " . dirname($finalDir) . " non scrivibile?");
            }
            if (!@mkdir($finalDir, 0777, true)) {
                error_log("[moveFiles] ERRORE mkdir finale: $finalDir");
                return;
            }
            @chmod($finalDir, 0777);
        }

        $sets = [];
        $vals = [];
        $eavData = [];

        foreach ($tempFilePaths as $fieldName => $tempPath) {
            // Risolvi path assoluto del temporaneo
            $absTempPath = $tempPath;
            if (!file_exists($absTempPath) && !str_starts_with($absTempPath, '/') && !preg_match('/^[A-Z]:\\\\/i', $absTempPath)) {
                $absTempPath = $baseDir . '/' . ltrim($tempPath, '/');
            }

            if (!file_exists($absTempPath)) {
                error_log("[moveFiles] Temp file non trovato: $absTempPath");
                continue;
            }

            $fileName = basename($absTempPath);
            $absFinalPath = $finalDir . '/' . $fileName;

            if (@rename($absTempPath, $absFinalPath)) {
                $finalRelPath = $relFinalDir . '/' . $fileName;

                // Decide dove salvare: tabella fisica o EAV
                if (self::colExists($table_name, $fieldName)) {
                    $sets[] = "`{$fieldName}` = ?";
                    $vals[] = $finalRelPath;
                } else {
                    $eavData[$fieldName] = $finalRelPath;
                }
            } else {
                error_log("[moveFiles] ERRORE rename: $absTempPath -> $absFinalPath");
            }

            // Pulizia cartella temp
            $tempDir = dirname($absTempPath);
            // Se contiene solo . e ..
            if (is_dir($tempDir)) {
                $files = scandir($tempDir);
                if (count($files) <= 2) {
                    @rmdir($tempDir);
                }
            }
        }

        if (!empty($sets)) {
            $vals[] = $record_id;
            $sql = "UPDATE `{$table_name}` SET " . implode(', ', $sets) . " WHERE id = ?";
            $database->query($sql, $vals, __FILE__);
        }

        if (!empty($eavData)) {
            self::saveDynamicFields($form_name, $record_id, $eavData);
        }
    }


    public static function archiviaSegnalazione(array $in): array
    {
        global $database;
        $form_name = trim((string) ($in['form_name'] ?? ''));
        $id = (int) ($in['record_id'] ?? 0);
        if ($form_name === '' || !$id)
            return ['success' => false, 'message' => 'parametri mancanti'];

        $f = self::getFormByName($form_name);
        if (!$f)
            return ['success' => false, 'message' => 'form non trovato'];
        $t = $f['table_name'];

        $c = $database->query("show columns from `$t` like 'archiviata'", [], __FILE__);
        if (!$c || $c->rowCount() === 0)
            $database->query("alter table `$t` add archiviata tinyint(1) default 0", [], __FILE__);

        $r = $database->query("update `$t` set archiviata=1 where id=:i", [':i' => $id], __FILE__);
        return $r && $r->rowCount() > 0 ? ['success' => true] : ['success' => false, 'message' => 'errore durante archiviazione'];
    }

    public static function ripristinaSegnalazione(array $in): array
    {
        global $database;
        $form_name = trim((string) ($in['form_name'] ?? ''));
        $id = (int) ($in['record_id'] ?? 0);
        if ($form_name === '' || !$id)
            return ['success' => false, 'message' => 'parametri mancanti'];

        $f = self::getFormByName($form_name);
        if (!$f)
            return ['success' => false, 'message' => 'form non trovato'];
        $t = $f['table_name'];

        $c = $database->query("show columns from `$t` like 'archiviata'", [], __FILE__);
        if (!$c || $c->rowCount() === 0)
            return ['success' => false, 'message' => "questa segnalazione non supporta l'archiviazione"];

        $r = $database->query("update `$t` set archiviata=0 where id=:i", [':i' => $id], __FILE__);
        return $r && $r->rowCount() > 0 ? ['success' => true] : ['success' => false, 'message' => 'errore durante il ripristino'];
    }

    public static function delete(array $in): array
    {
        global $database;
        $form_name = trim($in['form_name'] ?? '');
        $id = (int) ($in['record_id'] ?? 0);
        if ($form_name === '' || $id <= 0) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }
        $form = self::getFormByName($form_name);
        if (!$form)
            return ['success' => false, 'message' => 'Form non trovato'];
        $table = $form['table_name'];

        try {
            // EAV cleanup
            $database->query("DELETE FROM `form_values` WHERE form_name=:fn AND record_id=:rid", [':fn' => $form_name, ':rid' => $id], __FILE__);

            // Legacy/Main table cleanup
            $database->query("DELETE FROM `$table` WHERE id=:id", [':id' => $id], __FILE__);

            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }


    public static function getFirstEntryId(array $in)
    {
        global $database;
        $form_name = trim($in['form_name'] ?? '');
        if ($form_name === '')
            return null;
        $form = self::getFormByName($form_name);
        if (!$form)
            return null;
        $table = $form['table_name'];
        $st = $database->query("SELECT id FROM `$table` ORDER BY submitted_at ASC LIMIT 1", [], __FILE__);
        $row = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
        return $row ? (int) $row['id'] : null;
    }

    // ——— elenco moduli “segnalazioni” (solo con modulo gestione_richiesta/richiesta) ———
    public static function listSegnalazioniForms(array $session): array
    {
        global $database;

        $userId = (int) ($session['user_id'] ?? 0);
        $roleId = (int) ($session['user']['role_id'] ?? ($session['role_id'] ?? 0));

        // base: prendo tutti i forms (rimozione sistema modulare forms_modules)
        $sql = "
            select
                f.id, f.name, f.description, f.table_name, f.color,
                f.created_by, f.created_at, f.responsabile
            from forms f
            where 1=1
              and (
                    (
                        select count(*) from moduli_visibilita mv where mv.modulo_id = f.id
                    ) = 0
                    and (
                        select count(*) from moduli_visibilita_utenti mvu where mvu.modulo_id = f.id
                    ) = 0
                 or exists (
                        select 1 from moduli_visibilita mv2
                         where mv2.modulo_id = f.id and mv2.ruolo_id = :rid
                    )
                 or exists (
                        select 1 from moduli_visibilita_utenti mvu2
                         where mvu2.modulo_id = f.id and mvu2.user_id = :uid
                    )
              )
            order by f.name asc
        ";
        $params = [
            ':rid' => $roleId,
            ':uid' => $userId
        ];

        $st = $database->query($sql, $params, __FILE__ . ' ⇒ listSegnalazioniForms');
        $rows = $st ? $st->fetchAll(\PDO::FETCH_ASSOC) : [];

        // arricchisco con info utili per le card
        foreach ($rows as &$r) {
            $creatorName = $database->getNominativoByUserId((int) ($r['created_by'] ?? 0));
            $creatorImg = getProfileImage($creatorName, 'nominativo') ?: '/assets/images/default_profile.png';
            if ($creatorImg && strpos($creatorImg, '/') !== 0)
                $creatorImg = '/' . $creatorImg;

            $respNome = null;
            $respImg = null;
            $responsabiliList = [];

            if (!empty($r['responsabile'])) {
                $rIds = array_filter(explode(',', (string) $r['responsabile']));
                foreach ($rIds as $index => $rid) {
                    $rname = $database->getNominativoByUserId((int) $rid);
                    $rimg = getProfileImage($rname, 'nominativo') ?: '/assets/images/default_profile.png';
                    if ($rimg && strpos($rimg, '/') !== 0) {
                        $rimg = '/' . $rimg;
                    }

                    $responsabiliList[] = [
                        'id' => $rid,
                        'name' => $rname,
                        'img' => $rimg
                    ];

                    // Salva il primo come legacy
                    if ($index === 0) {
                        $respNome = $rname;
                        $respImg = $rimg;
                    }
                }
            }

            $r['created_by_name'] = $creatorName ?: null;
            $r['created_by_img'] = $creatorImg;
            $r['responsabile_nome'] = $respNome;
            $r['responsabile_img'] = $respImg;
            $r['responsabili'] = $responsabiliList;
        }

        return ['success' => true, 'data' => $rows];
    }

    // ——— versione con statistiche (totale e per status_id) per dashboard ———
    public static function listSegnalazioniStats(array $session): array
    {
        global $database;

        $base = self::listSegnalazioniForms($session);
        if (!($base['success'] ?? false))
            return $base;

        $forms = $base['data'] ?? [];
        foreach ($forms as &$f) {
            $table = $f['table_name'];

            // verifica tabella
            $chk = $database->query("SHOW TABLES LIKE :t", [':t' => $table], __FILE__ . ' ⇒ listSegnalazioniStats.chk');
            $exists = $chk && $chk->rowCount() > 0;

            $total = 0;
            $statusCounts = ['1' => 0, '2' => 0, '3' => 0];

            if ($exists) {
                // Escludi subtask (parent_record_id > 0) se la colonna esiste
                $hasParent = false;
                try {
                    $colChk = $database->query("SHOW COLUMNS FROM `$table` LIKE 'parent_record_id'", [], __FILE__ . ' ⇒ listSegnalazioniStats.colChk');
                    $hasParent = $colChk && $colChk->rowCount() > 0;
                } catch (\Throwable $e) {
                }
                $whereMain = $hasParent ? " WHERE (parent_record_id IS NULL OR parent_record_id = 0)" : "";

                $rc = $database->query("SELECT COUNT(*) tot FROM `$table`" . $whereMain, [], __FILE__ . ' ⇒ listSegnalazioniStats.tot');
                $row = $rc ? $rc->fetch(\PDO::FETCH_ASSOC) : null;
                $total = (int) ($row['tot'] ?? 0);

                $rs = $database->query("SELECT status_id, COUNT(*) c FROM `$table`" . $whereMain . " GROUP BY status_id", [], __FILE__ . ' ⇒ listSegnalazioniStats.byStatus');
                if ($rs) {
                    while ($r = $rs->fetch(\PDO::FETCH_ASSOC)) {
                        $k = (string) ($r['status_id'] ?? '');
                        if ($k !== '' && array_key_exists($k, $statusCounts)) {
                            $statusCounts[$k] = (int) $r['c'];
                        }
                    }
                }
            }

            $f['total_reports'] = $total;
            $f['status_counts'] = $statusCounts;
        }

        return ['success' => true, 'stats' => $forms];
    }

    public static function getFormFields(array $in): array
    {
        global $database;

        $form_name = trim((string) ($in['form_name'] ?? ''));
        if ($form_name === '') {
            return ['success' => false, 'message' => 'form_name mancante'];
        }

        $form = self::getFormByName($form_name);
        if (!$form) {
            return ['success' => false, 'message' => 'Form non trovato'];
        }

        $st = $database->query(
            "SELECT * FROM form_fields WHERE form_id = :fid ORDER BY id ASC",
            [':fid' => $form['id']],
            __FILE__ . ' ⇒ getFormFields'
        );
        $fields = $st ? $st->fetchAll(\PDO::FETCH_ASSOC) : [];

        return ['success' => true, 'fields' => $fields];
    }

    public static function addDynamicField(array $post, $user_id = null): array
    {
        // Normalizza i parametri attesi dal builder
        $params = [
            'form_name' => $post['form_name'] ?? ($post['name'] ?? ''),
            'field_name' => $post['field_name'] ?? ($post['fieldName'] ?? ''),
            'field_type' => $post['field_type'] ?? ($post['fieldType'] ?? ''),
            'field_options' => $post['field_options'] ?? ($post['fieldOptions'] ?? null),
        ];

        // Se field_options arriva come array, serializza in JSON
        if (is_array($params['field_options'])) {
            $params['field_options'] = json_encode($params['field_options'], JSON_UNESCAPED_UNICODE);
        }

        // Delega all'implementazione già presente in PageEditorService
        return \Services\PageEditorService::addFieldToForm($params);
    }

    /* =========================
       GESTIONE SUBTASKS PER SEGNALAZIONI
       ========================= */

    /**
     * Aggiunge le colonne necessarie per le subtasks a una tabella di segnalazioni
     */
    private static function ensureSubtaskColumns(string $table): void
    {
        global $database;

        // Verifica e aggiungi colonna parent_record_id
        if (!self::colExists($table, 'parent_record_id')) {
            $database->query("ALTER TABLE `$table` ADD COLUMN `parent_record_id` INT DEFAULT NULL", [], __FILE__);
            $database->query("ALTER TABLE `$table` ADD INDEX `idx_parent_record` (`parent_record_id`)", [], __FILE__);
        }

        // Verifica e aggiungi colonna scheda_label
        if (!self::colExists($table, 'scheda_label')) {
            $database->query("ALTER TABLE `$table` ADD COLUMN `scheda_label` VARCHAR(100) DEFAULT NULL", [], __FILE__);
        }

        // Verifica e aggiungi colonna scheda_data (JSON con i dati della scheda)
        if (!self::colExists($table, 'scheda_data')) {
            $database->query("ALTER TABLE `$table` ADD COLUMN `scheda_data` JSON DEFAULT NULL", [], __FILE__);
        }
    }

    /**
     * Rileva quali schede sono state compilate per un record
     */
    private static function getCompiledTabs(string $form_name, int $record_id): array
    {
        global $database;

        try {
            // Recupera la struttura delle schede del form
            $tabsResult = \Services\PageEditorService::getFormFieldsByTabs($form_name);
            if (!$tabsResult['success'] || empty($tabsResult['tabs'])) {
                return [];
            }

            // Recupera i dati del record
            $form = self::getFormByName($form_name);
            if (!$form)
                return [];

            $table = $form['table_name'];
            $record = $database->query("SELECT * FROM `$table` WHERE id = ? LIMIT 1", [$record_id], __FILE__)->fetch(\PDO::FETCH_ASSOC);
            if (!$record)
                return [];

            $compiledTabs = [];
            $tabs = $tabsResult['tabs'];

            // Per ogni scheda, controlla se almeno un campo è compilato
            foreach ($tabs as $tab_label => $fields) {
                // Salta la scheda "Struttura" (campi fissi)
                if ($tab_label === 'Struttura')
                    continue;

                // Salta se non è un array di campi
                if (!is_array($fields))
                    continue;

                $hasData = false;
                $tabData = [];

                // Controlla ogni campo della scheda
                foreach ($fields as $field) {
                    if (!is_array($field) || !isset($field['field_name']))
                        continue;

                    $fieldName = $field['field_name'];

                    // Se il campo esiste nel record e ha un valore
                    if (isset($record[$fieldName]) && $record[$fieldName] !== null && $record[$fieldName] !== '') {
                        $hasData = true;
                        $tabData[$fieldName] = $record[$fieldName];
                    }
                }

                // Se la scheda ha almeno un campo compilato, la consideriamo compilata
                if ($hasData) {
                    $compiledTabs[$tab_label] = [
                        'label' => $tab_label,
                        'fields' => $fields,
                        'data' => $tabData
                    ];
                }
            }

            return $compiledTabs;
        } catch (\Exception $e) {
            error_log("Errore getCompiledTabs: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Crea o aggiorna le subtasks per un record basandosi sulle schede compilate
     */
    public static function syncSubtasksFromTabs(array $input): array
    {
        global $database;

        $form_name = trim($input['form_name'] ?? '');
        $record_id = (int) ($input['record_id'] ?? 0);

        if (!$form_name || !$record_id) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        $form = self::getFormByName($form_name);
        if (!$form) {
            return ['success' => false, 'message' => 'Form non trovato'];
        }

        $table = $form['table_name'];

        // Assicura che la tabella abbia le colonne necessarie
        self::ensureSubtaskColumns($table);

        // Verifica che il record principale esista e NON sia una subtask
        $mainRecord = $database->query(
            "SELECT * FROM `$table` WHERE id = ? AND parent_record_id IS NULL LIMIT 1",
            [$record_id],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$mainRecord) {
            return ['success' => false, 'message' => 'Record principale non trovato'];
        }

        // Rileva quali schede sono state compilate
        $compiledTabs = self::getCompiledTabs($form_name, $record_id);

        if (empty($compiledTabs)) {
            return ['success' => true, 'message' => 'Nessuna scheda compilata trovata', 'subtasks_count' => 0];
        }

        $created = 0;
        $updated = 0;

        // Per ogni scheda compilata, crea o aggiorna una subtask
        foreach ($compiledTabs as $tab_label => $tabInfo) {
            // Cerca se esiste già una subtask per questa scheda
            $existing = $database->query(
                "SELECT id FROM `$table` WHERE parent_record_id = ? AND scheda_label = ? LIMIT 1",
                [$record_id, $tab_label],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            // Prepara un titolo e descrizione per la subtask
            $titolo = "Scheda: " . $tab_label;
            $descrizione = "Dati della scheda " . $tab_label;

            // Usa i valori del record principale per campi comuni
            $status_id = $mainRecord['status_id'] ?? 1;
            $submitted_by = $mainRecord['submitted_by'] ?? 0;
            $assegnato_a = $mainRecord['assegnato_a'] ?? null;
            $deadline = $mainRecord['deadline'] ?? null;

            if ($existing) {
                // Aggiorna la subtask esistente
                $database->query(
                    "UPDATE `$table` SET 
                        titolo = ?,
                        descrizione = ?,
                        scheda_data = ?,
                        status_id = ?
                    WHERE id = ?",
                    [
                        $titolo,
                        $descrizione,
                        json_encode($tabInfo['data']),
                        $status_id,
                        $existing['id']
                    ],
                    __FILE__
                );
                $updated++;
            } else {
                // Crea una nuova subtask
                $database->query(
                    "INSERT INTO `$table` (
                        parent_record_id,
                        scheda_label,
                        scheda_data,
                        titolo,
                        descrizione,
                        status_id,
                        submitted_by,
                        assegnato_a,
                        deadline,
                        submitted_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                    [
                        $record_id,
                        $tab_label,
                        json_encode($tabInfo['data']),
                        $titolo,
                        $descrizione,
                        $status_id,
                        $submitted_by,
                        $assegnato_a,
                        $deadline
                    ],
                    __FILE__
                );
                $created++;
            }
        }

        return [
            'success' => true,
            'message' => "Subtasks sincronizzate: $created create, $updated aggiornate",
            'subtasks_count' => count($compiledTabs),
            'created' => $created,
            'updated' => $updated
        ];
    }

    /**
     * Sincronizza le subtasks per TUTTI i record di un form
     */
    public static function syncAllSubtasksForForm(array $input): array
    {
        global $database;

        $form_name = trim($input['form_name'] ?? '');
        if (!$form_name) {
            return ['success' => false, 'message' => 'form_name mancante'];
        }

        $form = self::getFormByName($form_name);
        if (!$form) {
            return ['success' => false, 'message' => 'Form non trovato'];
        }

        $table = $form['table_name'];

        // Assicura che la tabella abbia le colonne necessarie
        try {
            self::ensureSubtaskColumns($table);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore creazione colonne: ' . $e->getMessage()];
        }

        // Recupera tutti i record principali
        $sql = "SELECT id FROM `$table` WHERE (parent_record_id IS NULL OR parent_record_id = 0)";
        $records = $database->query($sql, [], __FILE__);

        $synced = 0;
        $errors = 0;

        if ($records) {
            foreach ($records as $record) {
                try {
                    $result = self::syncSubtasksFromTabs([
                        'form_name' => $form_name,
                        'record_id' => (int) $record['id']
                    ]);

                    if ($result['success']) {
                        $synced++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    error_log("Errore sync record {$record['id']}: " . $e->getMessage());
                }
            }
        }

        return [
            'success' => true,
            'message' => "Sincronizzati $synced record, $errors errori",
            'synced' => $synced,
            'errors' => $errors
        ];
    }

    /**
     * Recupera le subtasks per un record
     */
    private static function getSubtasks(string $table, int $parent_id): array
    {
        global $database;

        // Verifica se la colonna parent_record_id esiste
        if (!self::colExists($table, 'parent_record_id')) {
            return [];
        }

        // Recupera le subtasks usando SELECT * per evitare errori con colonne mancanti
        try {
            $subtasks = $database->query(
                "SELECT * FROM `$table` WHERE parent_record_id = ? ORDER BY scheda_label ASC",
                [$parent_id],
                __FILE__
            );
        } catch (\Exception $e) {
            error_log("Errore getSubtasks per tabella $table: " . $e->getMessage());
            return [];
        }

        // Mappa personale per nomi e immagini
        $personale = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
        $pmap = [];
        foreach ($personale ?: [] as $p) {
            $pmap[(string) $p['user_id']] = $p['Nominativo'];
        }

        $result = [];
        foreach ($subtasks ?: [] as $sub) {
            $ass_id = $sub['assegnato_a'] ?? null;
            $ass_nome = $ass_id && isset($pmap[(string) $ass_id]) ? $pmap[(string) $ass_id] : null;
            $ass_img = $ass_nome ? getProfileImage($ass_nome, 'nominativo') : null;
            if ($ass_img && strpos($ass_img, '/') !== 0)
                $ass_img = '/' . $ass_img;

            $result[] = [
                'id' => (int) $sub['id'],
                'titolo' => $sub['titolo'] ?? '',
                'descrizione' => $sub['descrizione'] ?? '',
                'scheda_label' => $sub['scheda_label'] ?? '',
                'scheda_data' => isset($sub['scheda_data']) && $sub['scheda_data'] ? json_decode($sub['scheda_data'], true) : [],
                'status_id' => (int) ($sub['status_id'] ?? 1),
                'submitted_by' => (int) ($sub['submitted_by'] ?? 0),
                'assegnato_a' => $ass_id,
                'assegnato_a_nome' => $ass_nome,
                'img_assegnato' => $ass_img ?: '/assets/images/default_profile.png',
                'deadline' => $sub['deadline'] ?? null
            ];
        }

        return $result;
    }

    /**
     * GESTIONE SEPARATA PER SCHEDA: Aggiorna lo stato di una specifica scheda in form_schede_status
     * 
     * Ogni scheda ha il suo stato indipendente. Quando viene salvata, aggiorna solo
     * lo stato di quella specifica scheda, non blocca altre schede.
     * 
     * @param string $form_name Nome del form
     * @param string $table Nome tabella (non usato, mantenuto per retrocompatibilità)
     * @param int $record_id ID record principale
     * @param string $scheda_label Nome scheda (es. "Struttura", "Dettagli")
     * @param int $user_id ID utente che ha fatto submit
     */
    private static function updateSchedaSubmitFlag(string $form_name, string $table, int $record_id, string $scheda_label, int $user_id): void
    {
        try {
            // Usa PageEditorService::updateSchedaStatus per aggiornare lo stato della singola scheda
            $result = \Services\PageEditorService::updateSchedaStatus([
                'form_name' => $form_name,
                'record_id' => $record_id,
                'scheda_key' => strtolower($scheda_label),
                'status' => 'submitted'
            ]);

            if (!$result['success']) {
                error_log("[FormsDataService] Errore aggiornamento stato scheda '{$scheda_label}': " . ($result['message'] ?? 'errore sconosciuto'));
            }
        } catch (\Throwable $e) {
            // Non bloccare il submit se fallisce l'aggiornamento flag
            error_log("[FormsDataService] Errore aggiornamento stato scheda: " . $e->getMessage());
        }
    }
}

