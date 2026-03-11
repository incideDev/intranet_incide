<?php

namespace Services;

// Sicurezza gestita dal bootstrap centrale

use Services\NotificationService;

class CommesseService
{

    public static function getTasks(string $tabella): array
    {
        global $database;

        $tabella = preg_replace('/[^a-z0-9_]/i', '', $tabella);
        if (!$tabella)
            return [];

        // CREA la tabella se non esiste
        $check = $database->query("SHOW TABLES LIKE :t", [':t' => $tabella], __FILE__);
        if (!$check || $check->rowCount() === 0) {
            $crea = self::createTabella(str_replace('com_', '', $tabella));
            if (!$crea['success'])
                return [];
        }

        $personale = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
        $map = [];
        foreach ($personale as $p) {
            $map[$p['user_id']] = $p['Nominativo'];
        }

        // Verifica se la colonna parent_task_id esiste (per compatibilit?? con tabelle vecchie)
        $hasParentColumn = false;
        $columns = $database->query("SHOW COLUMNS FROM `$tabella` LIKE 'parent_task_id'", [], __FILE__);
        if ($columns && $columns->rowCount() > 0) {
            $hasParentColumn = true;
        }

        // Query modificata per supportare subtasks
        $parentFilter = $hasParentColumn ? "WHERE parent_task_id IS NULL" : "";
        $righe = $database->query("
            SELECT id, titolo, descrizione, specializzazione, assegnato_a, submitted_by,
                deadline, status_id, priority, data_apertura, data_chiusura, fase_doc, descrizione_azione,
                " . ($hasParentColumn ? "parent_task_id, scheda_label, scheda_data" : "NULL as parent_task_id, NULL as scheda_label, NULL as scheda_data") . "
            FROM `$tabella`
            $parentFilter
            ORDER BY submitted_at DESC
        ", [], __FILE__);

        $tasks = [];
        foreach ($righe as $r) {
            $img_creatore = 'assets/images/default_profile.png';
            if (isset($r['submitted_by']) && isset($map[$r['submitted_by']])) {
                $tmp = function_exists('getProfileImage') ? getProfileImage($map[$r['submitted_by']], 'nominativo') : null;
                if ($tmp)
                    $img_creatore = $tmp;
            }

            $img_assegnato = 'assets/images/default_profile.png';
            if (isset($r['assegnato_a']) && isset($map[$r['assegnato_a']])) {
                $tmp = function_exists('getProfileImage') ? getProfileImage($map[$r['assegnato_a']], 'nominativo') : null;
                if ($tmp)
                    $img_assegnato = $tmp;
            }

            $taskData = [
                'id' => $r['id'],
                'titolo' => $r['titolo'] ?? '',
                'descrizione' => $r['descrizione'] ?? '',
                'specializzazione' => $r['specializzazione'] ?? '',
                'assegnato_a_id' => $r['assegnato_a'] ?? null,
                'assegnato_a_nome' => (isset($r['assegnato_a']) && isset($map[$r['assegnato_a']])) ? $map[$r['assegnato_a']] : '???',
                'data_scadenza' => $r['deadline'] ?? '',
                'img_assegnato' => $img_assegnato,
                'img_creatore' => $img_creatore,
                'creatore_nome' => (isset($r['submitted_by']) && isset($map[$r['submitted_by']])) ? $map[$r['submitted_by']] : '???',
                'stato' => (int) ($r['status_id'] ?? 1),
                'tabella' => $tabella,
                'data_apertura' => $r['data_apertura'] ?? '',
                'data_chiusura' => $r['data_chiusura'] ?? '',
                'submitted_by' => (int) ($r['submitted_by'] ?? 0),
                'priority' => $r['priority'] ?? '',
                'fase_doc' => $r['fase_doc'] ?? '',
                'descrizione_azione' => $r['descrizione_azione'] ?? '',
                'parent_task_id' => $r['parent_task_id'] ?? null,
                'scheda_label' => $r['scheda_label'] ?? null,
                'scheda_data' => $r['scheda_data'] ?? null,
            ];

            // Recupera le subtasks per questa task principale (se esiste la colonna)
            if ($hasParentColumn) {
                $subtasks = $database->query("
                    SELECT id, titolo, descrizione, specializzazione, assegnato_a, submitted_by,
                        deadline, status_id, priority, data_apertura, data_chiusura, fase_doc, 
                        descrizione_azione, parent_task_id, scheda_label, scheda_data
                    FROM `$tabella`
                    WHERE parent_task_id = ?
                    ORDER BY submitted_at ASC
                ", [$r['id']], __FILE__);

                $subtasksList = [];
                foreach ($subtasks as $sub) {
                    $sub_img_creatore = 'assets/images/default_profile.png';
                    if (isset($sub['submitted_by']) && isset($map[$sub['submitted_by']])) {
                        $tmp = function_exists('getProfileImage') ? getProfileImage($map[$sub['submitted_by']], 'nominativo') : null;
                        if ($tmp)
                            $sub_img_creatore = $tmp;
                    }

                    $sub_img_assegnato = 'assets/images/default_profile.png';
                    if (isset($sub['assegnato_a']) && isset($map[$sub['assegnato_a']])) {
                        $tmp = function_exists('getProfileImage') ? getProfileImage($map[$sub['assegnato_a']], 'nominativo') : null;
                        if ($tmp)
                            $sub_img_assegnato = $tmp;
                    }

                    $subtasksList[] = [
                        'id' => $sub['id'],
                        'titolo' => $sub['titolo'] ?? '',
                        'descrizione' => $sub['descrizione'] ?? '',
                        'specializzazione' => $sub['specializzazione'] ?? '',
                        'assegnato_a_id' => $sub['assegnato_a'] ?? null,
                        'assegnato_a_nome' => (isset($sub['assegnato_a']) && isset($map[$sub['assegnato_a']])) ? $map[$sub['assegnato_a']] : '???',
                        'data_scadenza' => $sub['deadline'] ?? '',
                        'img_assegnato' => $sub_img_assegnato,
                        'img_creatore' => $sub_img_creatore,
                        'creatore_nome' => (isset($sub['submitted_by']) && isset($map[$sub['submitted_by']])) ? $map[$sub['submitted_by']] : '???',
                        'stato' => (int) ($sub['status_id'] ?? 1),
                        'tabella' => $tabella,
                        'data_apertura' => $sub['data_apertura'] ?? '',
                        'data_chiusura' => $sub['data_chiusura'] ?? '',
                        'submitted_by' => (int) ($sub['submitted_by'] ?? 0),
                        'priority' => $sub['priority'] ?? '',
                        'fase_doc' => $sub['fase_doc'] ?? '',
                        'descrizione_azione' => $sub['descrizione_azione'] ?? '',
                        'parent_task_id' => $sub['parent_task_id'],
                        'scheda_label' => $sub['scheda_label'] ?? null,
                        'scheda_data' => $sub['scheda_data'] ?? null,
                    ];
                }
                $taskData['subtasks'] = $subtasksList;
            } else {
                $taskData['subtasks'] = [];
            }

            $tasks[] = $taskData;
        }
        return $tasks;
    }

    public static function createTask($input)
    {
        global $database;

        $personale = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
        $map = [];
        foreach ($personale as $p) {
            $map[$p['user_id']] = $p['Nominativo'];
        }

        // Normalizzatori per i vari tipi di campo
        $fixDate = function ($val) {
            return (isset($val) && trim($val) !== '') ? $val : null;
        };
        $fixInt = function ($val) {
            return (isset($val) && trim($val) !== '') ? intval($val) : null;
        };

        $tabellaLogica = $input['tabella'] ?? null;
        // Normalizzo subito: tolgo eventuale 'com_' in ingresso (gestione robusta)
        $tabellaLogica = preg_replace('/^com_/', '', $tabellaLogica ?? '');
        $tabella = 'com_' . strtolower(preg_replace('/[^a-z0-9_]/i', '_', $tabellaLogica));

        $titolo = strip_tags(trim($input['titolo'] ?? ''));
        $descrizione = strip_tags(trim($input['descrizione'] ?? ''));
        $data_scadenza = $fixDate($input['data_scadenza'] ?? null);
        $data_creazione = $fixDate($input['data_creazione'] ?? null);
        $data_chiusura = $fixDate($input['data_chiusura'] ?? null);
        $stato = intval($input['stato'] ?? 1);
        $referente = $fixInt($input['referente'] ?? null);
        $assegnato_a = $fixInt($input['assegnato_a'] ?? null);
        $submitted_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
        $priority = strip_tags(trim($input['priority'] ?? 'Media'));
        $fase_doc = strip_tags(trim($input['fase_doc'] ?? ''));
        $specializzazione = strip_tags(trim($input['specializzazione'] ?? ''));
        $descrizione_azione = strip_tags(trim($input['descrizione_azione'] ?? ''));
        $referente_chiusura = strip_tags(trim($input['referente_chiusura'] ?? ''));
        $note = strip_tags(trim($input['note'] ?? ''));

        if (!$tabella || !$titolo) {
            return ['success' => false, 'message' => 'Dati mancanti'];
        }

        // PREVIENE SQL INJECTION
        if (!preg_match('/^[a-z0-9_]+$/', $tabella)) {
            return ['success' => false, 'message' => 'Tabella non valida'];
        }

        // CREA LA TABELLA SE NON ESISTE
        $check = $database->query("SHOW TABLES LIKE :t", [':t' => $tabella], __FILE__);
        if (!$check || $check->rowCount() === 0) {
            $crea = self::createTabella($tabellaLogica);
            if (!$crea['success']) {
                return ['success' => false, 'message' => 'Impossibile creare la tabella: ' . $crea['message']];
            }
        }

        // Data apertura: SEMPRE valorizzata oggi, NON modificabile da input
        $data_apertura = date('Y-m-d');
        $data_chiusura = null; // nuova task mai chiusa

        $query = "INSERT INTO `$tabella` 
            (titolo, descrizione, status_id, deadline, assegnato_a, referente, submitted_by, priority, fase_doc, specializzazione, data_apertura, descrizione_azione, data_chiusura, referente_chiusura, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $ok = $database->query(
            $query,
            [
                $titolo,
                $descrizione,
                $stato,
                $data_scadenza,
                $assegnato_a,
                $referente,
                $submitted_by,
                $priority,
                $fase_doc,
                $specializzazione,
                $data_apertura,
                $descrizione_azione,
                $data_chiusura,
                $referente_chiusura,
                $note
            ],
            __FILE__
        );

        $imgProfilo = 'assets/images/default_profile.png';
        if (function_exists('getProfileImage') && $assegnato_a && isset($map[$assegnato_a])) {
            $imgProfilo = getProfileImage($map[$assegnato_a], 'nominativo');
        }

        if ($ok) {
            $id = $database->lastInsertId();

            // INVIO NOTIFICA SE ASSEGNATO A QUALCUNO
            if ($assegnato_a && isset($map[$assegnato_a])) {
                $autore = isset($map[$submitted_by]) ? $map[$submitted_by] : "Qualcuno";
                $nome_task = $titolo ?: "(senza titolo)";
                $msg = "$autore ti ha assegnato una task: \"$nome_task\"";
                $link = "index.php?section=commesse&page=commessa&tabella=" . urlencode($tabellaLogica) . "&titolo=" . urlencode($titolo);
                NotificationService::inviaNotifica($assegnato_a, $msg, $link);
            }

            return [
                'success' => true,
                'task' => [
                    'id' => $id,
                    'titolo' => $titolo,
                    'descrizione' => $descrizione,
                    'data_scadenza' => $data_scadenza,
                    'stato' => $stato,
                    'assegnato_a' => $assegnato_a,
                    'img_responsabile' => $imgProfilo,
                    'tabella' => $tabella,
                    'priority' => $priority
                ]
            ];
        }

        return ['success' => false, 'message' => 'Errore inserimento'];
    }

    public static function createTabella(string $nomeTabella, array $input = []): array
    {
        global $database;

        $tabella_logica = ltrim(preg_replace('/[^a-zA-Z0-9_]/', '_', $nomeTabella), '_');
        $nomeTabellaFisica = 'com_' . strtolower($tabella_logica);

        if (!preg_match('/^[a-z0-9_]+$/', $nomeTabellaFisica)) {
            return ['success' => false, 'message' => 'Nome tabella non valido'];
        }

        // esistenza tabella fisica
        $checkTable = $database->query("SHOW TABLES LIKE :t", [':t' => $nomeTabellaFisica], __FILE__);
        if ($checkTable && $checkTable->rowCount() > 0) {
            return ['success' => false, 'message' => 'La tabella esiste gi??.'];
        }

        // esistenza riga bacheca (usa il codice commessa esatto)
        $checkRow = $database->query(
            "SELECT COUNT(*) FROM commesse_bacheche WHERE tabella = :t",
            [':t' => $tabella_logica],
            __FILE__
        );
        if ($checkRow->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'nome tabella gi?? registrato in commesse_bacheche.'];
        }

        // crea tabella fisica task (con supporto subtasks)
        $sql = "CREATE TABLE `$nomeTabellaFisica` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_task_id INT DEFAULT NULL,
            titolo VARCHAR(255) NOT NULL,
            descrizione TEXT DEFAULT NULL,
            assegnato_a INT DEFAULT NULL,
            submitted_by INT DEFAULT NULL,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            deadline DATE DEFAULT NULL,
            data_apertura DATE DEFAULT NULL,
            status_id INT DEFAULT 1,
            priority VARCHAR(255) DEFAULT 'Media',
            referente VARCHAR(255) DEFAULT NULL,
            fase_doc VARCHAR(255) DEFAULT NULL,
            specializzazione VARCHAR(255) DEFAULT NULL,
            descrizione_azione TEXT DEFAULT NULL,
            data_chiusura DATE DEFAULT NULL,
            referente_chiusura VARCHAR(255) DEFAULT NULL,
            note TEXT DEFAULT NULL,
            path_allegato VARCHAR(255) DEFAULT NULL,
            screenshot VARCHAR(255) DEFAULT NULL,
            scheda_data JSON DEFAULT NULL,
            scheda_label VARCHAR(100) DEFAULT NULL,
            INDEX idx_parent_task (parent_task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $database->query($sql, [], __FILE__);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore SQL nella creazione tabella', 'details' => $e->getMessage()];
        }

        // --- prepara dati bacheca
        $titolo = $input['titolo'] ?? $_POST['titolo'] ?? $_GET['titolo'] ?? $nomeTabella;
        if (!$titolo) {
            $titolo = ucfirst(str_replace('_', ' ', $tabella_logica));
        }

        $root = self::resolveRespCommessaIdByCodice($tabella_logica);
        $organigramma_seed = json_encode(
            ['user_id' => ($root ? (int) $root : null), 'children' => []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $insert = $database->query(
            "INSERT INTO commesse_bacheche (titolo, tabella, organigramma) VALUES (?, ?, ?)",
            [$titolo, $tabella_logica, $organigramma_seed],
            __FILE__
        );
        if (!$insert) {
            return ['success' => false, 'message' => 'Tabella creata ma errore nella registrazione su commesse_bacheche.'];
        }

        $id_bacheca = $database->lastInsertId();

        // --- Anagrafica (come prima) ---
        $anagrafica = $input['anagrafica'] ?? [];
        if (!is_array($anagrafica))
            $anagrafica = [];

        $columns = ['bacheca_id'];
        $values = [$id_bacheca];
        $placeholders = ['?'];

        foreach ($anagrafica as $key => $val) {
            $val = trim($val);
            if (in_array($key, ['data_creazione', 'data_inizio', 'data_fine', 'data_ordine']) && $val === '')
                $val = null;
            if ($key === 'importo_lavori' && ($val === '' || !is_numeric($val)))
                $val = null;

            $columns[] = $key;
            $placeholders[] = '?';
            $values[] = $val;
        }

        $query = "INSERT INTO commesse_anagrafica (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
        $ok = $database->query($query, $values, __FILE__);

        if (!$ok) {
            return ['success' => false, 'message' => 'Bacheca creata ma errore nel salvataggio anagrafica.'];
        }

        return ['success' => true, 'message' => 'Bacheca creata con successo.'];
    }

    public static function saveAnagrafica(array $input): array
    {
        global $database;

        $bacheca_id = intval($input['bacheca_id'] ?? 0);
        $data = $input['anagrafica'] ?? [];

        if (!$bacheca_id || !is_array($data)) {
            return ['success' => false, 'message' => 'Dati mancanti'];
        }

        $stmt = $database->query("SELECT id FROM commesse_anagrafica WHERE bacheca_id = ?", [$bacheca_id], __FILE__);

        if ($stmt && $stmt->rowCount() > 0) {
            // UPDATE
            $set = [];
            $values = [];

            foreach ($data as $key => $val) {
                $val = trim($val);
                if (in_array($key, ['data_creazione', 'data_inizio', 'data_fine', 'data_ordine']) && $val === '') {
                    $val = null;
                }

                $set[] = "$key = ?";
                $values[] = $val;
            }

            $values[] = $bacheca_id;

            $ok = $database->query(
                "UPDATE commesse_anagrafica SET " . implode(",", $set) . " WHERE bacheca_id = ?",
                $values,
                __FILE__
            );
        } else {
            // INSERT
            $columns = ['bacheca_id'];
            $placeholders = ['?'];
            $values = [$bacheca_id];

            foreach ($data as $key => $val) {
                $val = trim($val);

                // Imposta NULL se campo vuoto su tipi speciali
                if (
                    in_array($key, ['data_creazione', 'data_inizio', 'data_fine', 'data_ordine']) && $val === ''
                ) {
                    $val = null;
                }
                if (
                    in_array($key, ['importo_lavori']) && ($val === '' || !is_numeric($val))
                ) {
                    $val = null;
                }

                // Se ?? un campo DATE e la stringa ?? vuota ??? salva NULL
                if (in_array($key, ['data_creazione', 'data_inizio', 'data_fine', 'data_ordine']) && $val === '') {
                    $val = null;
                }

                $columns[] = $key;
                $placeholders[] = '?';
                $values[] = $val;
            }

            $ok = $database->query(
                "INSERT INTO commesse_anagrafica (" . implode(",", $columns) . ") VALUES (" . implode(",", $placeholders) . ")",
                $values,
                __FILE__
            );
        }

        return $ok ? ['success' => true] : ['success' => false, 'message' => 'Errore nel salvataggio'];
    }

    public static function deleteBacheca(int $id): array
    {
        global $database;

        if ($id <= 0) {
            return ['success' => false, 'message' => 'ID non valido'];
        }

        // Elimina eventuale anagrafica legata (con ON DELETE CASCADE ?? opzionale)
        $database->query("DELETE FROM commesse_anagrafica WHERE bacheca_id = ?", [$id], __FILE__);

        // Ottieni il nome tabella
        $row = $database->query("SELECT tabella FROM commesse_bacheche WHERE id = ?", [$id], __FILE__)->fetch();
        if (!$row || !isset($row['tabella'])) {
            return ['success' => false, 'message' => 'Bacheca non trovata'];
        }

        $nomeTabellaFisica = 'com_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($row['tabella']));

        // Elimina la tabella fisica
        try {
            $database->query("DROP TABLE IF EXISTS `$nomeTabellaFisica`", [], __FILE__);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore nel DROP TABLE', 'error' => $e->getMessage()];
        }

        // Elimina la riga nella tabella bacheche
        $ok = $database->query("DELETE FROM commesse_bacheche WHERE id = ?", [$id], __FILE__);

        return $ok
            ? ['success' => true, 'message' => 'Bacheca eliminata']
            : ['success' => false, 'message' => 'Errore nella rimozione della bacheca'];
    }

    public static function getResponsabiliPerTask(int $taskId): array
    {
        global $database;

        if (!$taskId) {
            return ['success' => false, 'message' => 'ID task mancante'];
        }

        // 1. Trova la tabella da cui proviene la task
        $tabella = $database->query("SHOW TABLES LIKE 'com_%'", [], __FILE__)->fetchAll(\PDO::FETCH_COLUMN);

        $tabellaTask = null;
        foreach ($tabella as $nomeTabella) {
            $exists = $database->query("SELECT id FROM `$nomeTabella` WHERE id = ?", [$taskId], __FILE__);
            if ($exists && $exists->rowCount() > 0) {
                $tabellaTask = $nomeTabella;
                break;
            }
        }

        if (!$tabellaTask) {
            return ['success' => false, 'message' => 'Task non trovata in nessuna tabella commessa.'];
        }

        // 2. Recupera user_id assegnato (pu?? essere null)
        $assignedId = $database->query("SELECT assegnato_a FROM `$tabellaTask` WHERE id = ?", [$taskId], __FILE__)->fetchColumn();

        // 3. Prendi tutti gli utenti
        $utenti = $database->query("SELECT user_id, Nominativo, Ruolo FROM personale", [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        $output = [];
        foreach ($utenti as $u) {
            // Ottieni disciplina/subdisciplina
            $disc = estraiDisciplinaDaRuolo($u['Ruolo'] ?? '');
            $output[] = [
                'user_id' => (int) $u['user_id'],
                'Nominativo' => $u['Nominativo'],
                'assegnato' => ($assignedId && $assignedId == $u['user_id']),
                'imagePath' => getProfileImage($u['Nominativo'], 'nominativo'),
                'disciplina' => $disc['disciplina'],
                'subdisciplina' => $disc['subdisciplina']
            ];
        }

        return ['success' => true, 'responsabili' => $output];
    }

    public static function updateAssegnatoA(array $input): array
    {
        global $database;

        $task_id = intval($input['task_id'] ?? 0);
        $nuovo_user_id = isset($input['user_id']) ? intval($input['user_id']) : null;
        $tabella_logica = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');

        if (!$task_id || !$tabella_logica) {
            return ['success' => false, 'message' => 'parametri mancanti'];
        }

        $tabella = 'com_' . $tabella_logica;
        if (!preg_match('/^com_[a-z0-9_]+$/', $tabella)) {
            return ['success' => false, 'message' => 'tabella non valida'];
        }

        $row = $database->query("select id, titolo, assegnato_a, submitted_by from `$tabella` where id = ? limit 1", [$task_id], __FILE__)->fetch();
        if (!$row)
            return ['success' => false, 'message' => 'task non trovata'];

        $ok = $database->query("update `$tabella` set assegnato_a = ? where id = ?", [$nuovo_user_id, $task_id], __FILE__);
        if (!$ok)
            return ['success' => false, 'message' => 'errore aggiornamento'];

        // notifica riassegnazione
        if ($nuovo_user_id && $nuovo_user_id !== (int) ($row['assegnato_a'] ?? 0)) {
            $autore_id = intval($_SESSION['user_id'] ?? 0);
            $autore_nome = $database->query("select nominativo from personale where user_id = ? limit 1", [$autore_id], __FILE__)->fetchColumn() ?: 'qualcuno';
            $titolo = trim((string) ($row['titolo'] ?? ''));
            $nome_task = $titolo !== '' ? $titolo : '(senza titolo)';
            $link = "index.php?section=commesse&page=commessa&tabella=" . urlencode($tabella_logica) . "&titolo=" . urlencode($nome_task);

            NotificationService::inviaNotifica($nuovo_user_id, "$autore_nome ti ha assegnato la task: \"$nome_task\"", $link);
        }

        return ['success' => true];
    }

    public static function getTaskDetails(array $input): array
    {
        global $database;

        $taskId = intval($input['task_id'] ?? 0);
        $tabellaLogica = $input['tabella'] ?? null;

        if (!$taskId || !$tabellaLogica) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        // NUOVO SISTEMA: cerca prima in sys_tasks
        if (class_exists('Services\TaskService')) {
            try {
                $queryResult = $database->query(
                    "SELECT t.*, 
                            s.name as status_name,
                            p.Nominativo as assignee_nome,
                            c.Nominativo as creator_nome
                     FROM sys_tasks t
                     LEFT JOIN sys_task_status s ON s.id = t.status_id
                     LEFT JOIN personale p ON p.user_id = t.assignee_user_id
                     LEFT JOIN personale c ON c.user_id = t.created_by
                     WHERE t.id = ? 
                       AND t.context_type = 'commessa'
                       AND t.context_id = ?
                       AND t.deleted_at IS NULL 
                     LIMIT 1",
                    [$taskId, $tabellaLogica],
                    __FILE__
                );

                if ($queryResult) {
                    $row = $queryResult->fetch(\PDO::FETCH_ASSOC);

                    if ($row && is_array($row) && !empty($row['id'])) {
                        // Mappa i campi per compatibilit?? con il formato atteso
                        $statiMap = [
                            1 => 'DA DEFINIRE',
                            2 => 'APERTO',
                            3 => 'IN CORSO',
                            4 => 'CHIUSO'
                        ];

                        $statusInt = (int) ($row['status_id'] ?? 1);
                        $statusTxt = $statiMap[$statusInt] ?? ($row['status_name'] ?? 'DA DEFINIRE');

                        // Mappa priority: 0=Bassa, 1=Media, 2=Alta, 3=Critica
                        $priorityNum = isset($row['priority']) ? (int) $row['priority'] : 1;
                        $priorityMap = [0 => 'Bassa', 1 => 'Media', 2 => 'Alta', 3 => 'Critica'];
                        $priorityTxt = $priorityMap[$priorityNum] ?? 'Media';

                        // Formatta data_apertura (rimuovi ora se presente)
                        $dataApertura = $row['created_at'] ?? '';
                        if ($dataApertura && strpos($dataApertura, ' ') !== false) {
                            $dataApertura = substr($dataApertura, 0, 10); // Prendi solo la data (YYYY-MM-DD)
                        }

                        // Formatta data_scadenza (rimuovi ora se presente)
                        $dataScadenza = $row['due_date'] ?? '';
                        if ($dataScadenza && strpos($dataScadenza, ' ') !== false) {
                            $dataScadenza = substr($dataScadenza, 0, 10); // Prendi solo la data (YYYY-MM-DD)
                        }

                        return [
                            'success' => true,
                            'data' => [
                                'titolo' => $row['title'] ?? '',
                                'descrizione' => $row['description'] ?? '',
                                'data_scadenza' => $dataScadenza,
                                'referente' => $row['creator_nome'] ?? '',
                                'assegnato_a' => $row['assignee_user_id'] ?? null,
                                'fase_doc' => $row['fase_doc'] ?? '',
                                'specializzazione' => $row['specializzazione'] ?? '',
                                'data_apertura' => $dataApertura,
                                'descrizione_azione' => $row['descrizione_azione'] ?? $row['description'] ?? '',
                                'data_chiusura' => $row['data_chiusura'] ?? '',
                                'priority' => $priorityTxt,
                                'stato' => $statusTxt,
                                'status_id' => $statusInt,
                                'referente_chiusura' => '',
                                'note' => $row['note'] ?? '',
                                'path_allegato' => $row['path_allegato'] ?? '',
                                'screenshot' => $row['screenshot'] ?? '',
                                'submitted_by' => $row['created_by'] ?? null,
                                'tabella' => $tabellaLogica // Aggiunto per compatibilit??
                            ]
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Se c'?? un errore, continua con il fallback legacy
                error_log("CommesseService::getTaskDetails - Errore query sys_tasks: " . $e->getMessage());
            }
        }

        // FALLBACK: cerca nella tabella legacy per compatibilit??
        $tabellaFisica = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $tabellaLogica));
        if (strpos($tabellaFisica, 'com_') !== 0) {
            $tabellaFisica = 'com_' . $tabellaFisica;
        }
        if (!preg_match('/^com_[a-z0-9_]+$/', $tabellaFisica)) {
            return ['success' => false, 'message' => 'Tabella non valida'];
        }

        $row = $database->query("SELECT * FROM `$tabellaFisica` WHERE id = ?", [$taskId], __FILE__)->fetch();
        if (!$row) {
            return ['success' => false, 'message' => 'Task non trovata'];
        }

        $statiMap = [
            1 => 'DA DEFINIRE',
            2 => 'APERTO',
            3 => 'IN CORSO',
            4 => 'CHIUSO'
        ];

        $statusInt = (int) ($row['status_id'] ?? 1);
        $statusTxt = $statiMap[$statusInt] ?? 'DA DEFINIRE';

        return [
            'success' => true,
            'data' => [
                'titolo' => $row['titolo'] ?? '',
                'descrizione' => $row['descrizione'] ?? '',
                'data_scadenza' => $row['deadline'] ?? '',
                'referente' => $row['referente'] ?: ($row['responsabile'] ?? ''),
                'assegnato_a' => $row['assegnato_a'] ?? null,
                'fase_doc' => $row['fase_doc'] ?? '',
                'specializzazione' => $row['specializzazione'] ?? '',
                'data_apertura' => $row['data_apertura'] ?? '',
                'descrizione_azione' => $row['descrizione_azione'] ?? '',
                'data_chiusura' => $row['data_chiusura'] ?? '',
                'priority' => $row['priority'] ?? '',
                'stato' => $statusTxt,
                'status_id' => $statusInt,
                'referente_chiusura' => $row['referente_chiusura'] ?? '',
                'note' => $row['note'] ?? '',
                'submitted_by' => $row['submitted_by'] ?? null
            ]
        ];
    }

    public static function saveTaskDetails(array $input): array
    {
        global $database;

        $task_id = intval($input['task_id'] ?? 0);
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');
        if (strpos($tabella, 'com_') !== 0)
            $tabella = 'com_' . $tabella;

        if (!$task_id || !$tabella || !preg_match('/^com_[a-z0-9_]+$/', $tabella)) {
            return ['success' => false, 'message' => 'parametri non validi'];
        }

        $stati_map = ['da definire' => 1, 'aperto' => 2, 'in corso' => 3, 'chiuso' => 4];
        $status_id = null;
        if (isset($input['stato'])) {
            if (is_numeric($input['stato']))
                $status_id = (int) $input['stato'];
            else {
                $status_txt = mb_strtoupper(trim((string) $input['stato']), 'utf-8');
                $status_id = $stati_map[mb_strtolower($status_txt, 'utf-8')] ?? 1;
            }
        } elseif (isset($input['status_id'])) {
            $status_id = (int) $input['status_id'];
        }
        if ($status_id < 1 || $status_id > 4)
            $status_id = 1;

        // snapshot precedente per confronti
        $prev = $database->query("select titolo, assegnato_a, submitted_by, status_id as old_status, deadline, data_chiusura from `$tabella` where id = ? limit 1", [$task_id], __FILE__)->fetch();
        if (!$prev)
            return ['success' => false, 'message' => 'task non trovata'];

        $data_chiusura = null;
        if ($status_id === 4 && empty($prev['data_chiusura']))
            $data_chiusura = date('Y-m-d');

        $campi = ['titolo', 'descrizione', 'assegnato_a', 'referente', 'fase_doc', 'specializzazione', 'descrizione_azione', 'priority', 'status_id', 'referente_chiusura', 'note'];
        $updates = [];
        $values = [];

        foreach ($campi as $campo) {
            if ($campo === 'status_id') {
                $val = $status_id;
            } elseif (in_array($campo, ['assegnato_a', 'referente'], true)) {
                $val = isset($input[$campo]) && is_numeric($input[$campo]) && intval($input[$campo]) > 0 ? intval($input[$campo]) : null;
            } else {
                $val = trim((string) ($input[$campo] ?? ''));
                if ($campo === 'descrizione_azione' && $val === '')
                    $val = null;
                if (in_array($campo, ['titolo', 'descrizione', 'fase_doc', 'specializzazione', 'descrizione_azione', 'priority', 'referente_chiusura', 'note'], true)) {
                    $val = strip_tags($val);
                }
            }
            $updates[] = "`$campo` = ?";
            $values[] = $val;
        }

        if (array_key_exists('data_scadenza', $input)) {
            $deadlineVal = trim((string) $input['data_scadenza']);
            $deadlineVal = ($deadlineVal !== '') ? $deadlineVal : null;
            $updates[] = "`deadline` = ?";
            $values[] = $deadlineVal;
        }

        if (!is_null($data_chiusura)) {
            $updates[] = "`data_chiusura` = ?";
            $values[] = $data_chiusura;
        }

        $values[] = $task_id;
        $ok = $database->query("update `$tabella` set " . implode(", ", $updates) . " where id = ?", $values, __FILE__);
        if (!$ok)
            return ['success' => false, 'message' => 'errore durante l\'aggiornamento'];

        // notifiche post-aggiornamento
        $attore_id = intval($_SESSION['user_id'] ?? 0);
        $attore_nome = $database->query("select nominativo from personale where user_id = ? limit 1", [$attore_id], __FILE__)->fetchColumn() ?: 'qualcuno';
        $titolo = trim((string) ($prev['titolo'] ?? ''));
        $nome_task = $titolo !== '' ? $titolo : '(senza titolo)';
        $tabella_logica = preg_replace('/^com_/', '', $tabella);
        $link = "index.php?section=commesse&page=commessa&tabella=" . urlencode($tabella_logica) . "&titolo=" . urlencode($nome_task);

        // riassegnazione?
        // riassegnazione?
        $nuovo_assegnato = isset($input['assegnato_a']) && is_numeric($input['assegnato_a']) ? intval($input['assegnato_a']) : null;
        if ($nuovo_assegnato && $nuovo_assegnato !== (int) ($prev['assegnato_a'] ?? 0)) {
            // FIX: usa il nome corretto del metodo (N maiuscola)
            NotificationService::inviaNotifica($nuovo_assegnato, "$attore_nome ti ha assegnato la task: \"$nome_task\"", $link);
        }

        // cambio stato?
        if ((int) ($prev['old_status'] ?? 0) !== $status_id) {
            $stati_txt = [1 => 'da definire', 2 => 'aperto', 3 => 'in corso', 4 => 'chiuso'];
            $nuovo_txt = $stati_txt[$status_id] ?? (string) $status_id;

            $dest = [];
            if (!empty($prev['submitted_by']))
                $dest[] = (int) $prev['submitted_by'];
            if (!empty($nuovo_assegnato))
                $dest[] = (int) $nuovo_assegnato;
            elseif (!empty($prev['assegnato_a']))
                $dest[] = (int) $prev['assegnato_a'];
            $dest = array_values(array_unique(array_filter($dest, fn($u) => $u && $u !== $attore_id)));

            foreach ($dest as $uid) {
                // FIX: messaggio corretto per cambio stato (niente $nuova_deadline qui)
                NotificationService::inviaNotifica($uid, "$attore_nome ha impostato la task \"$nome_task\" a: $nuovo_txt", $link);
            }
        }

        // cambio scadenza? (opzionale ma utile)
        $nuova_deadline = null; // evita "Undefined variable"
        if (array_key_exists('data_scadenza', $input)) {
            $nuova_deadline = trim((string) $input['data_scadenza']);
            if ($nuova_deadline === '')
                $nuova_deadline = null;

            if ($nuova_deadline !== ($prev['deadline'] ?? null)) {
                $dest = [];
                if (!empty($prev['submitted_by']))
                    $dest[] = (int) $prev['submitted_by'];
                if (!empty($nuovo_assegnato))
                    $dest[] = (int) $nuovo_assegnato;
                elseif (!empty($prev['assegnato_a']))
                    $dest[] = (int) $prev['assegnato_a'];
                $dest = array_values(array_unique(array_filter($dest, fn($u) => $u && $u !== $attore_id)));

                foreach ($dest as $uid) {
                    $msg = $attore_nome . ' ha aggiornato la scadenza di "' . $nome_task . '" al ' . ($nuova_deadline ?? '???');
                    NotificationService::inviaNotifica($uid, $msg, $link);
                }
            }
        }

        return ['success' => true];
    }

    public static function getScadenzeCommesse($user_id = null)
    {
        global $database;

        // Prende tutte le bacheche commesse
        $bacheche = $database->query("SELECT tabella, titolo FROM commesse_bacheche", [], __FILE__);
        $scadenze = [];

        foreach ($bacheche as $b) {
            $tabella = 'com_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($b['tabella']));
            // Verifica che la tabella esista e abbia la colonna deadline
            $checkTab = $database->query("SHOW TABLES LIKE :t", [':t' => $tabella], __FILE__);
            if (!$checkTab || $checkTab->rowCount() == 0)
                continue;
            $checkCol = $database->query("SHOW COLUMNS FROM `$tabella` LIKE 'deadline'", [], __FILE__);
            if (!$checkCol || $checkCol->rowCount() == 0)
                continue;

            // Prepara filtro per user_id se passato (solo assegnato_a, come richiesto)
            $where = "WHERE deadline IS NOT NULL";
            $params = [];
            if ($user_id) {
                $where .= " AND (assegnato_a = :uid OR submitted_by = :uid)";
                $params['uid'] = $user_id;
            }

            $rows = $database->query("SELECT id, titolo, descrizione, deadline, assegnato_a, submitted_by, status_id, priority FROM `$tabella` $where", $params, __FILE__);
            foreach ($rows as $r) {
                $scadenze[] = [
                    'id' => $r['id'],
                    'titolo' => $r['titolo'],
                    'descrizione' => $r['descrizione'] ?? '',
                    'argomentazione_field' => $r['descrizione'] ?? '',
                    'deadline' => $r['deadline'],
                    'status_id' => $r['status_id'] ?? null,
                    'priority' => $r['priority'] ?? 'Media',
                    'assegnato_a' => $r['assegnato_a'] ?? null,
                    'submitted_by' => $r['submitted_by'] ?? null,
                    'bacheca' => $b['titolo'],
                    'tabella' => $b['tabella'],
                    'table_name' => $b['tabella'],
                    'type' => 'commessa'
                ];
            }
        }

        return ['success' => true, 'scadenze' => $scadenze];
    }

    public static function updateTaskStatus(array $input): array
    {
        global $database;

        $task_id = intval($input['task_id'] ?? 0);
        $status_id = intval($input['stato'] ?? 1);
        $tabella_logica = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');

        if (!$task_id || !$tabella_logica) {
            return ['success' => false, 'message' => 'parametri mancanti o non validi'];
        }

        $tabella = (strpos($tabella_logica, 'com_') === 0) ? $tabella_logica : 'com_' . $tabella_logica;

        $prev = $database->query("select titolo, status_id as old_status, data_chiusura, assegnato_a, submitted_by from `$tabella` where id = ? limit 1", [$task_id], __FILE__)->fetch();
        if (!$prev)
            return ['success' => false, 'message' => 'task non trovata'];

        $data_chiusura = ($status_id === 4 && empty($prev['data_chiusura'])) ? date('Y-m-d') : null;

        $q = "update `$tabella` set status_id = ?";
        $params = [$status_id];
        if (!is_null($data_chiusura)) {
            $q .= ", data_chiusura = ?";
            $params[] = $data_chiusura;
        }
        $q .= " where id = ?";
        $params[] = $task_id;

        $ok = $database->query($q, $params, __FILE__);
        if (!$ok)
            return ['success' => false, 'message' => 'errore aggiornamento stato task'];

        // notifica cambio stato
        $stati_map = [1 => 'da definire', 2 => 'aperto', 3 => 'in corso', 4 => 'chiuso'];
        $nuovo_txt = $stati_map[$status_id] ?? (string) $status_id;

        $attore_id = intval($_SESSION['user_id'] ?? 0);
        $attore_nome = $database->query("select nominativo from personale where user_id = ? limit 1", [$attore_id], __FILE__)->fetchColumn() ?: 'qualcuno';
        $titolo = trim((string) ($prev['titolo'] ?? ''));
        $nome_task = $titolo !== '' ? $titolo : '(senza titolo)';
        $link = "index.php?section=commesse&page=commessa&tabella=" . urlencode($tabella_logica) . "&titolo=" . urlencode($nome_task);

        $destinatari = [];
        if (!empty($prev['submitted_by']))
            $destinatari[] = (int) $prev['submitted_by'];
        if (!empty($prev['assegnato_a']))
            $destinatari[] = (int) $prev['assegnato_a'];
        $destinatari = array_values(array_unique(array_filter($destinatari, fn($u) => $u && $u !== $attore_id)));

        foreach ($destinatari as $uid) {
            NotificationService::inviaNotifica($uid, "$attore_nome ha impostato la task \"$nome_task\" a: $nuovo_txt", $link);
        }

        return ['success' => true];
    }

    private static function logTaskAction($id_task, $tabella, $stato_prev, $stato_new, $autore, $note = null)
    {
        global $database;
        $database->query(
            "INSERT INTO commesse_tasks_log (id_task, tabella, stato_precedente, stato_nuovo, autore, note) VALUES (?, ?, ?, ?, ?, ?)",
            [$id_task, $tabella, $stato_prev, $stato_new, $autore, $note],
            __FILE__
        );
    }

    public static function getBachecaTeam($input)
    {
        global $database;
        $id = intval($input['bacheca_id'] ?? 0);
        $row = $database->query(
            "SELECT b.id, b.titolo, b.tabella, c.responsabile_commessa
            FROM commesse_bacheche b
            LEFT JOIN elenco_commesse c ON c.codice = b.tabella
            WHERE b.id = ?
            LIMIT 1",
            [$id],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        $membri = $database->query(
            "SELECT user_id FROM commesse_utenti WHERE bacheca_id = ?",
            [$id],
            __FILE__
        )->fetchAll(\PDO::FETCH_COLUMN);

        if (!$row)
            return ['success' => false, 'message' => 'Bacheca non trovata'];

        $respId = $row['responsabile_commessa'] ?? null;
        if (!is_numeric($respId) && !empty($respId)) {
            $respId = $database->query("SELECT user_id FROM personale WHERE LOWER(Nominativo)=LOWER(?) LIMIT 1", [trim((string) $respId)], __FILE__)->fetchColumn();
        }
        $respId = $respId ? (int) $respId : null;

        return [
            'success' => true,
            'data' => [
                'id' => (int) $row['id'],
                'titolo' => $row['titolo'],
                'nome_commessa' => $row['tabella'],
                'responsabile_id' => $respId,
                'membri_ids' => $membri
            ]
        ];
    }

    public static function updateBacheca($input)
    {
        global $database;
        $id = intval($input['bacheca_id'] ?? 0);
        $resp = intval($input['responsabile_id'] ?? 0);
        $nome_commessa = trim($input['nome_commessa'] ?? "");
        $membri = $input['membri'] ?? [];
        if (!$id || !$resp || !is_array($membri) || !$nome_commessa)
            return ['success' => false, 'message' => 'Dati mancanti'];

        // Prendo il codice commessa (tabella logica) dalla bacheca
        $codice = $database->query(
            "SELECT tabella FROM commesse_bacheche WHERE id = ? LIMIT 1",
            [$id],
            __FILE__
        )->fetchColumn();

        if (!$codice)
            return ['success' => false, 'message' => 'Bacheca non trovata'];

        // Aggiorno il nome della bacheca (se vuoi mantenerlo)
        $database->query(
            "UPDATE commesse_bacheche SET nome_commessa = ? WHERE id = ?",
            [$nome_commessa, $id],
            __FILE__
        );

        // Aggiorno il responsabile nella TABELLA elenco_commesse
        $database->query(
            "UPDATE elenco_commesse SET responsabile_commessa = ? WHERE codice = ?",
            [$resp, $codice],
            __FILE__
        );

        // Rigenero i membri
        $database->query("DELETE FROM commesse_utenti WHERE bacheca_id = ?", [$id], __FILE__);
        foreach ($membri as $uid) {
            $database->query(
                "INSERT INTO commesse_utenti (bacheca_id, user_id) VALUES (?, ?)",
                [$id, intval($uid)],
                __FILE__
            );
        }
        return ['success' => true];
    }

    public static function saveMembri(array $input): array
    {
        global $database;
        $bacheca_id = intval($input['bacheca_id'] ?? 0);
        $membri = $input['membri'] ?? [];
        if (!$bacheca_id || !is_array($membri)) {
            return ['success' => false, 'message' => 'Dati mancanti'];
        }

        // Pulisci tutto
        $database->query("DELETE FROM commesse_utenti WHERE bacheca_id = ?", [$bacheca_id], __FILE__);

        // Inserisci membri nuovi
        foreach ($membri as $m) {
            if (!is_array($m) || !isset($m['user_id']))
                continue;

            $user_id = intval($m['user_id']);
            $disciplina = !empty($m['disciplina']) ? $m['disciplina'] : null;
            $subdisciplina = !empty($m['subdisciplina']) ? $m['subdisciplina'] : null;

            $database->query(
                "INSERT INTO commesse_utenti (bacheca_id, user_id, disciplina, subdisciplina)
                VALUES (?, ?, ?, ?)",
                [$bacheca_id, $user_id, $disciplina, $subdisciplina],
                __FILE__
            );
        }
        return ['success' => true];
    }

    public static function inviaNotificaGruppoLavoro($bacheca_id, $titolo, $tabella, $responsabile_id, $membri_ids, $user_id_azione)
    {
        global $database;

        // Prendi nome responsabile
        $responsabile = $database->query(
            "SELECT Nominativo FROM personale WHERE user_id = ? LIMIT 1",
            [$responsabile_id],
            __FILE__
        )->fetchColumn();
        $responsabile = $responsabile ?: "N/D";

        foreach ($membri_ids as $uid) {
            if ($uid == $responsabile_id) {
                $messaggio = '<div class="notifica-categoria-commesse">'
                    . 'Sei stato aggiunto come <b>responsabile</b> della commessa <strong>' . htmlspecialchars($titolo) . '</strong>'
                    . '.</div>';
            } else {
                $messaggio = '<div class="notifica-categoria-commesse">'
                    . 'Sei stato aggiunto al gruppo di lavoro della commessa <strong>' . htmlspecialchars($titolo) . '</strong>'
                    . '.<br>Responsabile: <strong>' . htmlspecialchars($responsabile) . '</strong>'
                    . '</div>';
            }
            $link = "index.php?section=collaborazione&page=commesse&tabella=" . urlencode($tabella) . "&titolo=" . urlencode($titolo);
            NotificationService::inviaNotifica($uid, $messaggio, $link);
        }
    }

    public static function deleteTask(array $input): array
    {
        global $database;

        $task_id = intval($input['task_id'] ?? 0);
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');
        if (strpos($tabella, 'com_') !== 0)
            $tabella = 'com_' . $tabella;

        if (!$task_id || !$tabella) {
            return ['success' => false, 'message' => 'parametri mancanti o non validi'];
        }

        $task = $database->query("select titolo, submitted_by, assegnato_a from `$tabella` where id = ? limit 1", [$task_id], __FILE__)->fetch();
        if (!$task)
            return ['success' => false, 'message' => 'task non trovata'];

        $user_id = intval($_SESSION['user_id'] ?? 0);

        $tabella_logica = preg_replace('/^com_/', '', $tabella);
        $resp = $database->query("select responsabile_commessa from elenco_commesse where codice = ? limit 1", [$tabella_logica], __FILE__)->fetchColumn();

        $resp_id = null;
        if ($resp) {
            if (is_numeric($resp))
                $resp_id = (int) $resp;
            else {
                $resp_id = $database->query("select user_id from personale where lower(nominativo)=lower(?) limit 1", [trim((string) $resp)], __FILE__)->fetchColumn();
                $resp_id = $resp_id ? (int) $resp_id : null;
            }
        }
        $is_responsabile = ($user_id && $resp_id && $resp_id === $user_id);

        if ($user_id !== (int) ($task['submitted_by'] ?? 0) && !$is_responsabile) {
            return ['success' => false, 'message' => 'non sei autorizzato ad eliminare questa task'];
        }

        $ok = $database->query("delete from `$tabella` where id = ?", [$task_id], __FILE__);
        if (!$ok)
            return ['success' => false, 'message' => 'errore durante l\'eliminazione'];

        // notifica eliminazione a creatore e assegnatario (escludendo chi ha eseguito l???azione)
        $attore_id = $user_id;
        $attore_nome = $database->query("select nominativo from personale where user_id = ? limit 1", [$attore_id], __FILE__)->fetchColumn() ?: 'qualcuno';
        $titolo = trim((string) ($task['titolo'] ?? ''));
        $nome_task = $titolo !== '' ? $titolo : '(senza titolo)';
        $link = "index.php?section=commesse&page=commessa&tabella=" . urlencode($tabella_logica) . "&titolo=" . urlencode($nome_task);

        $dest = [];
        if (!empty($task['submitted_by']))
            $dest[] = (int) $task['submitted_by'];
        if (!empty($task['assegnato_a']))
            $dest[] = (int) $task['assegnato_a'];
        $dest = array_values(array_unique(array_filter($dest, fn($u) => $u && $u !== $attore_id)));

        foreach ($dest as $uid) {
            NotificationService::inviaNotifica($uid, "$attore_nome ha eliminato la task \"$nome_task\"", $link);
        }

        return ['success' => true];
    }

    /**
     * Crea una subtask collegata a una task principale
     * @param array $input - dati della subtask con parent_task_id obbligatorio
     * @return array
     */
    public static function createSubtask(array $input): array
    {
        global $database;

        $parent_task_id = intval($input['parent_task_id'] ?? 0);
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');
        if (strpos($tabella, 'com_') !== 0)
            $tabella = 'com_' . $tabella;

        if (!$parent_task_id || !$tabella) {
            return ['success' => false, 'message' => 'parent_task_id e tabella sono obbligatori'];
        }

        // Verifica che la task principale esista
        $parent = $database->query("SELECT id FROM `$tabella` WHERE id = ? AND parent_task_id IS NULL LIMIT 1", [$parent_task_id], __FILE__)->fetch();
        if (!$parent) {
            return ['success' => false, 'message' => 'Task principale non trovata'];
        }

        // Crea la subtask con i dati forniti
        $titolo = strip_tags(trim($input['titolo'] ?? ''));
        $descrizione = strip_tags(trim($input['descrizione'] ?? ''));
        $scheda_label = strip_tags(trim($input['scheda_label'] ?? ''));
        $scheda_data = isset($input['scheda_data']) && is_array($input['scheda_data']) ? json_encode($input['scheda_data']) : null;
        $status_id = intval($input['status_id'] ?? 1);
        $submitted_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
        $assegnato_a = isset($input['assegnato_a']) && is_numeric($input['assegnato_a']) ? intval($input['assegnato_a']) : null;
        $priority = strip_tags(trim($input['priority'] ?? 'Media'));
        $deadline = trim($input['deadline'] ?? '');
        if ($deadline === '')
            $deadline = null;

        $query = "INSERT INTO `$tabella` 
            (parent_task_id, titolo, descrizione, scheda_label, scheda_data, status_id, submitted_by, assegnato_a, priority, deadline, data_apertura)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $ok = $database->query(
            $query,
            [$parent_task_id, $titolo, $descrizione, $scheda_label, $scheda_data, $status_id, $submitted_by, $assegnato_a, $priority, $deadline],
            __FILE__
        );

        if ($ok) {
            $subtask_id = $database->lastInsertId();
            return [
                'success' => true,
                'subtask_id' => $subtask_id,
                'message' => 'Subtask creata con successo'
            ];
        }

        return ['success' => false, 'message' => 'Errore durante la creazione della subtask'];
    }

    /**
     * Crea subtasks da schede compilate di un form
     * @param array $input - contiene parent_task_id, tabella, form_name, record_id
     * @return array
     */
    public static function createSubtasksFromFormTabs(array $input): array
    {
        global $database;

        $parent_task_id = intval($input['parent_task_id'] ?? 0);
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');
        if (strpos($tabella, 'com_') !== 0)
            $tabella = 'com_' . $tabella;
        $form_name = trim($input['form_name'] ?? '');
        $record_id = intval($input['record_id'] ?? 0);

        if (!$parent_task_id || !$tabella || !$form_name || !$record_id) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        // Verifica che la task principale esista
        $parent = $database->query("SELECT id, titolo FROM `$tabella` WHERE id = ? AND parent_task_id IS NULL LIMIT 1", [$parent_task_id], __FILE__)->fetch();
        if (!$parent) {
            return ['success' => false, 'message' => 'Task principale non trovata'];
        }

        // Recupera le schede del form
        $tabsResult = \Services\PageEditorService::getFormFieldsByTabs(['form_name' => $form_name]);
        if (!$tabsResult['success'] || empty($tabsResult['tabs'])) {
            return ['success' => false, 'message' => 'Nessuna scheda trovata per il form'];
        }

        // Recupera i dati compilati del form
        $formDataResult = \Services\FormsDataService::getFormEntry(['form_name' => $form_name, 'record_id' => $record_id]);
        if (!$formDataResult['success'] || empty($formDataResult['data'])) {
            return ['success' => false, 'message' => 'Dati del form non trovati'];
        }

        $formData = $formDataResult['data'];
        $tabs = $tabsResult['tabs'];
        $created_subtasks = [];

        // Per ogni scheda (esclusa "Struttura" che contiene campi fissi), crea una subtask
        foreach ($tabs as $tab_label => $fields) {
            // Salta la scheda Struttura (campi fissi comuni)
            if ($tab_label === 'Struttura')
                continue;

            // Estrai i dati relativi a questa scheda
            $scheda_data = [];
            foreach ($fields as $field) {
                $field_name = strtolower($field['field_name']);
                if (isset($formData[$field_name])) {
                    $scheda_data[$field_name] = $formData[$field_name];
                }
            }

            // Crea titolo e descrizione per la subtask
            $titolo_subtask = $parent['titolo'] . " - " . $tab_label;
            $descrizione_subtask = "Dati compilati per la scheda: " . $tab_label;

            // Crea la subtask
            $subtaskResult = self::createSubtask([
                'parent_task_id' => $parent_task_id,
                'tabella' => $tabella,
                'titolo' => $titolo_subtask,
                'descrizione' => $descrizione_subtask,
                'scheda_label' => $tab_label,
                'scheda_data' => $scheda_data,
                'status_id' => 1, // DA DEFINIRE
                'priority' => 'Media'
            ]);

            if ($subtaskResult['success']) {
                $created_subtasks[] = [
                    'subtask_id' => $subtaskResult['subtask_id'],
                    'scheda_label' => $tab_label
                ];
            }
        }

        if (empty($created_subtasks)) {
            return ['success' => false, 'message' => 'Nessuna subtask creata'];
        }

        return [
            'success' => true,
            'message' => count($created_subtasks) . ' subtask create con successo',
            'subtasks' => $created_subtasks
        ];
    }

    /**
     * Aggiorna una subtask
     * @param array $input
     * @return array
     */
    public static function updateSubtask(array $input): array
    {
        global $database;

        $subtask_id = intval($input['subtask_id'] ?? 0);
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');
        if (strpos($tabella, 'com_') !== 0)
            $tabella = 'com_' . $tabella;

        if (!$subtask_id || !$tabella) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        // Verifica che sia effettivamente una subtask (ha parent_task_id)
        $subtask = $database->query("SELECT parent_task_id FROM `$tabella` WHERE id = ? LIMIT 1", [$subtask_id], __FILE__)->fetch();
        if (!$subtask || !$subtask['parent_task_id']) {
            return ['success' => false, 'message' => 'Subtask non trovata'];
        }

        // Prepara i campi da aggiornare
        $updates = [];
        $values = [];

        if (isset($input['titolo'])) {
            $updates[] = "titolo = ?";
            $values[] = strip_tags(trim($input['titolo']));
        }
        if (isset($input['descrizione'])) {
            $updates[] = "descrizione = ?";
            $values[] = strip_tags(trim($input['descrizione']));
        }
        if (isset($input['status_id'])) {
            $updates[] = "status_id = ?";
            $values[] = intval($input['status_id']);
        }
        if (isset($input['assegnato_a'])) {
            $updates[] = "assegnato_a = ?";
            $values[] = is_numeric($input['assegnato_a']) ? intval($input['assegnato_a']) : null;
        }
        if (isset($input['priority'])) {
            $updates[] = "priority = ?";
            $values[] = strip_tags(trim($input['priority']));
        }
        if (isset($input['deadline'])) {
            $updates[] = "deadline = ?";
            $deadline = trim($input['deadline']);
            $values[] = $deadline === '' ? null : $deadline;
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => 'Nessun campo da aggiornare'];
        }

        $values[] = $subtask_id;
        $ok = $database->query("UPDATE `$tabella` SET " . implode(", ", $updates) . " WHERE id = ?", $values, __FILE__);

        if ($ok) {
            return ['success' => true, 'message' => 'Subtask aggiornata'];
        }

        return ['success' => false, 'message' => 'Errore durante l\'aggiornamento'];
    }

    /**
     * Elimina una subtask
     * @param array $input
     * @return array
     */
    public static function deleteSubtask(array $input): array
    {
        global $database;

        $subtask_id = intval($input['subtask_id'] ?? 0);
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');
        if (strpos($tabella, 'com_') !== 0)
            $tabella = 'com_' . $tabella;

        if (!$subtask_id || !$tabella) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        // Verifica che sia effettivamente una subtask (ha parent_task_id)
        $subtask = $database->query("SELECT parent_task_id FROM `$tabella` WHERE id = ? LIMIT 1", [$subtask_id], __FILE__)->fetch();
        if (!$subtask || !$subtask['parent_task_id']) {
            return ['success' => false, 'message' => 'Subtask non trovata'];
        }

        $ok = $database->query("DELETE FROM `$tabella` WHERE id = ?", [$subtask_id], __FILE__);

        if ($ok) {
            return ['success' => true, 'message' => 'Subtask eliminata'];
        }

        return ['success' => false, 'message' => 'Errore durante l\'eliminazione'];
    }

    private static function getWritableUploadPath($tabella, $user_id)
    {
        $base = dirname(__FILE__);
        while ($base && !is_dir($base . '/uploads')) {
            $base = dirname($base);
        }

        if (!$base || !is_dir($base . '/uploads')) {
            throw new \Exception("Cartella /uploads non trovata");
        }

        $date = date('dmY');
        $relative = "uploads/tmp_commesse/{$tabella}/{$user_id}_{$date}";
        $full_path = $base . '/' . $relative;

        if (!is_dir($full_path)) {
            mkdir($full_path, 0775, true);
        }

        return [
            'absolute' => $full_path,
            'relative' => $relative
        ];
    }

    public static function getBachecaByTabella($tabella)
    {
        global $database;
        $tabella = preg_replace('/[^a-zA-Z0-9_]/', '', $tabella);
        $res = $database->query("SELECT * FROM commesse_bacheche WHERE tabella = :t LIMIT 1", [':t' => $tabella], __FILE__);
        return $res && $res->rowCount() ? $res->fetch(\PDO::FETCH_ASSOC) : null;
    }

    public static function getAnagraficaByTabella($tabella_logica)
    {
        global $database;
        $tabella = preg_replace('/[^a-zA-Z0-9_]/', '', $tabella_logica);

        $row = $database->query(
            "SELECT a.* FROM commesse_anagrafica a
            INNER JOIN commesse_bacheche b ON a.bacheca_id = b.id
            WHERE b.tabella = :t LIMIT 1",
            [':t' => $tabella],
            __FILE__
        );
        return $row ? $row->fetch(\PDO::FETCH_ASSOC) : null;
    }

    public static function avviaCommessa($codice_commessa)
    {
        global $database;

        $commessa = $database->query(
            "SELECT codice, oggetto, cliente, stato, responsabile_commessa, data_ordine, data_inizio_prevista, data_fine_prevista, valore_prodotto, business_unit FROM elenco_commesse WHERE codice = ? LIMIT 1",
            [$codice_commessa],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$commessa)
            return ['success' => false, 'message' => 'Commessa non trovata'];

        // Applica fixMojibake ai campi che potrebbero contenere mojibake
        $commessa['codice'] = fixMojibake($commessa['codice'] ?? '');
        $commessa['oggetto'] = fixMojibake($commessa['oggetto'] ?? '');
        $commessa['cliente'] = fixMojibake($commessa['cliente'] ?? '');
        $commessa['stato'] = fixMojibake($commessa['stato'] ?? '');
        $commessa['responsabile_commessa'] = fixMojibake($commessa['responsabile_commessa'] ?? '');

        $root_input = $commessa['responsabile_commessa'] ?? null;
        $root_uid = null;
        if (is_numeric($root_input)) {
            $root_uid = (int) $root_input;
        } elseif (is_string($root_input) && trim($root_input) !== '') {
            $root_uid = $database->query(
                "SELECT user_id FROM personale WHERE LOWER(Nominativo)=LOWER(?) LIMIT 1",
                [trim($root_input)],
                __FILE__
            )->fetchColumn() ?: null;
            if ($root_uid !== null)
                $root_uid = (int) $root_uid;
        }

        $creaTab = self::createTabella($codice_commessa, [
            'titolo' => $commessa['oggetto'] ?: $codice_commessa
        ]);

        if (!$creaTab['success']) {
            return ['success' => false, 'message' => 'Errore creazione workspace', 'details' => $creaTab['message']];
        }

        return ['success' => true, 'message' => 'Workspace avviato'];
    }

    public static function getMembri($input)
    {
        global $database;
        $bacheca_id = intval($input['bacheca_id'] ?? 0);
        if (!$bacheca_id)
            return ['success' => false];

        $rows = $database->query("SELECT user_id, disciplina, subdisciplina FROM commesse_utenti WHERE bacheca_id = ?", [$bacheca_id], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);
        return ['success' => true, 'membri' => $rows];
    }

    public static function saveOrganigrammaTree($input)
    {
        global $database;

        $commessa_id = intval($input['commessa_id'] ?? 0);
        $organigramma = $input['organigramma'] ?? null;

        if (!$commessa_id || !is_array($organigramma)) {
            return ['success' => false, 'message' => 'dati mancanti'];
        }

        // carico info bacheca/commessa per notifiche e link
        $row_bach = $database->query(
            "select b.titolo, b.tabella
            from commesse_bacheche b
            where b.id = ? limit 1",
            [$commessa_id],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row_bach) {
            return ['success' => false, 'message' => 'bacheca non trovata'];
        }

        $titolo_bacheca = (string) ($row_bach['titolo'] ?? '');
        $tabella_logica = (string) ($row_bach['tabella'] ?? '');
        $resp_commessa_id = self::resolveRespCommessaIdByCodice($tabella_logica);

        // leggo organigramma precedente per calcolare diff
        $prev_json = $database->query(
            "select organigramma from commesse_bacheche where id = ? limit 1",
            [$commessa_id],
            __FILE__
        )->fetchColumn();

        $prev_tree = $prev_json ? json_decode($prev_json, true) : null;

        // se user_id non c'?? o ?? null, provo a risolvere dal responsabile commessa
        if (!array_key_exists('user_id', $organigramma) || $organigramma['user_id'] === null) {
            if ($resp_commessa_id) {
                $organigramma['user_id'] = (int) $resp_commessa_id;
            }
        }

        // cast finale se presente
        if (isset($organigramma['user_id']) && $organigramma['user_id'] !== null) {
            $organigramma['user_id'] = (int) $organigramma['user_id'];
        }

        $json = json_encode($organigramma, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return ['success' => false, 'message' => 'organigramma non serializzabile (json non valido)'];
        }

        // helper: estrai tutti gli user_id da un albero
        $collect_ids = function ($node) use (&$collect_ids) {
            if (!is_array($node))
                return [];
            $ids = [];
            if (isset($node['user_id']) && $node['user_id'])
                $ids[] = (int) $node['user_id'];
            if (!empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $ch) {
                    $ids = array_merge($ids, $collect_ids($ch));
                }
            }
            return $ids;
        };

        $old_ids = $collect_ids($prev_tree ?: []);
        $new_ids = $collect_ids($organigramma);

        $old_ids = array_values(array_unique(array_filter(array_map('intval', $old_ids))));
        $new_ids = array_values(array_unique(array_filter(array_map('intval', $new_ids))));

        $added = array_values(array_diff($new_ids, $old_ids));
        $removed = array_values(array_diff($old_ids, $new_ids));

        // salvo il nuovo organigramma
        $ok = $database->query(
            "update commesse_bacheche set organigramma = ? where id = ?",
            [$json, $commessa_id],
            __FILE__
        );

        if (!$ok) {
            return ['success' => false, 'message' => 'errore nel salvataggio organigramma'];
        }

        // prepara contesto notifiche
        $attore_id = intval($_SESSION['user_id'] ?? 0);
        $attore_nome = $database->query(
            "select nominativo from personale where user_id = ? limit 1",
            [$attore_id],
            __FILE__
        )->fetchColumn() ?: 'qualcuno';

        $resp_nome = $resp_commessa_id
            ? ($database->query("select nominativo from personale where user_id = ? limit 1", [$resp_commessa_id], __FILE__)->fetchColumn() ?: 'n/d')
            : 'n/d';

        $link = "index.php?section=collaborazione&page=commesse&tabella=" . urlencode($tabella_logica) . "&titolo=" . urlencode($titolo_bacheca);

        // invio notifiche per added
        foreach ($added as $uid) {
            if (!$uid || $uid === $attore_id)
                continue; // no auto-notifica
            $msg = '<div class="notifica-categoria-commesse">'
                . htmlspecialchars($attore_nome) . ' ti ha <b>aggiunto</b> all???organigramma della commessa '
                . '<strong>' . htmlspecialchars($titolo_bacheca) . '</strong>.'
                . '<br>responsabile: <strong>' . htmlspecialchars($resp_nome) . '</strong>'
                . '</div>';
            NotificationService::inviaNotifica($uid, $msg, $link);
        }

        // invio notifiche per removed
        foreach ($removed as $uid) {
            if (!$uid || $uid === $attore_id)
                continue;
            $msg = '<div class="notifica-categoria-commesse">'
                . htmlspecialchars($attore_nome) . ' ti ha <b>rimosso</b> dall???organigramma della commessa '
                . '<strong>' . htmlspecialchars($titolo_bacheca) . '</strong>.'
                . '</div>';
            NotificationService::inviaNotifica($uid, $msg, $link);
        }

        return ['success' => true, 'message' => 'organigramma salvato'];
    }

    public static function getPartecipanti($tabella)
    {
        global $database;
        $tabella = preg_replace('/[^a-zA-Z0-9_]/', '', $tabella);
        if (!$tabella)
            return ['success' => false, 'error' => 'Tabella mancante'];

        // Recupera la struttura organigramma dalla tabella commesse_bacheche
        $row = $database->query(
            "SELECT organigramma FROM commesse_bacheche WHERE tabella = :t LIMIT 1",
            [':t' => $tabella],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['organigramma'])) {
            return ['success' => false, 'error' => 'Organigramma non trovato'];
        }

        $organigramma = json_decode($row['organigramma'], true);
        if (!is_array($organigramma))
            return ['success' => false, 'error' => 'Organigramma non valido'];

        // Funzione ricorsiva per estrarre TUTTI gli user_id
        $ids = [];
        $estrai = function ($nodo) use (&$estrai, &$ids) {
            if (isset($nodo['user_id']))
                $ids[] = $nodo['user_id'];
            if (!empty($nodo['children']) && is_array($nodo['children'])) {
                foreach ($nodo['children'] as $child)
                    $estrai($child);
            }
        };
        $estrai($organigramma);
        $ids = array_unique($ids);

        if (empty($ids))
            return ['success' => false, 'error' => 'Nessun partecipante'];

        // Recupera i nominativi e (eventuale disciplina)
        $in = implode(',', array_fill(0, count($ids), '?'));
        $utenti = $database->query(
            "SELECT user_id as id, Nominativo as nominativo FROM personale WHERE user_id IN ($in) ORDER BY Nominativo ASC",
            $ids,
            __FILE__
        )->fetchAll(\PDO::FETCH_ASSOC);

        return ['success' => true, 'utenti' => $utenti];
    }

    /**
     * Metodo canonico per ottenere overview team commessa
     * Riusa logica esistente da commessa_organigramma.php
     *
     * @param string $codice Codice commessa (es: ADR06G)
     * @return array ['pm' => [...], 'tl' => null, 'members' => [...]]
     */
    public static function getCommessaTeamOverview(string $codice): array
    {
        global $database;

        $codice = preg_replace('/[^A-Za-z0-9_]/', '', $codice);
        if (!$codice) {
            return ['pm' => null, 'tl' => null, 'members' => []];
        }

        // Recupera dati commessa + responsabile
        $commessa = $database->query(
            "SELECT b.id, b.organigramma, c.responsabile_commessa
             FROM commesse_bacheche b
             LEFT JOIN elenco_commesse c ON c.codice = b.tabella
             WHERE b.tabella = ? LIMIT 1",
            [$codice],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$commessa) {
            return ['pm' => null, 'tl' => null, 'members' => []];
        }

        $responsabile_raw = $commessa['responsabile_commessa'] ?? null;
        $responsabile_id = null;

        // Risoluzione robusta responsabile_commessa -> user_id (riuso logica da commessa_organigramma.php)
        if ($responsabile_raw !== null && $responsabile_raw !== '') {
            if (is_numeric($responsabile_raw)) {
                $cand = (int)$responsabile_raw;
                $exists = $database->query(
                    "SELECT 1 FROM personale WHERE user_id = ? LIMIT 1",
                    [$cand],
                    __FILE__
                )->fetchColumn();
                if ($exists) $responsabile_id = $cand;
            }

            if (!$responsabile_id && !is_numeric($responsabile_raw)) {
                $norm = function(string $s) {
                    $s = trim($s);
                    $s = preg_replace('/\s+/', ' ', $s);
                    return mb_strtolower($s, 'UTF-8');
                };

                $needle = $norm((string)$responsabile_raw);
                $righe = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
                $byNorm = [];
                foreach ($righe as $r) {
                    $full = $norm($r['Nominativo']);
                    $byNorm[$full] = (int)$r['user_id'];
                }

                if (isset($byNorm[$needle])) {
                    $responsabile_id = $byNorm[$needle];
                }
            }
        }

        // Mappa utenti con avatar
        $utenti_stmt = $database->query("SELECT user_id, Nominativo, Ruolo FROM personale", [], __FILE__);
        $utenti = $utenti_stmt ? $utenti_stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $utenti_map = [];
        foreach ($utenti as $u) {
            $parsed = function_exists('estraiDisciplinaDaRuolo')
                ? estraiDisciplinaDaRuolo($u['Ruolo'] ?? '')
                : ['disciplina' => null, 'subdisciplina' => null, 'badge' => null];

            $img = function_exists('getProfileImage')
                ? getProfileImage($u['Nominativo'], 'nominativo')
                : 'assets/images/default_profile.png';

            $utenti_map[(int)$u['user_id']] = [
                'id'            => (int)$u['user_id'],
                'nome'          => fixMojibake($u['Nominativo'] ?? ''),
                'ruolo_db'      => $u['Ruolo'] ?? '',
                'img'           => htmlspecialchars($img, ENT_QUOTES),
                'disciplina'    => $parsed['disciplina'],
                'badge'         => $parsed['badge'],
            ];
        }

        // Parse organigramma JSON
        $organigramma_salvato = null;
        if (!empty($commessa['organigramma'])) {
            $tmp = json_decode($commessa['organigramma'], true);
            $organigramma_salvato = is_array($tmp) ? $tmp : null;
        }

        $result = [
            'pm' => null,
            'tl' => null,
            'members' => []
        ];

        // PM (root)
        if ($responsabile_id && isset($utenti_map[$responsabile_id])) {
            $result['pm'] = [
                'id' => $responsabile_id,
                'nome' => $utenti_map[$responsabile_id]['nome'],
                'ruolo' => 'Project Manager',
                'avatar' => $utenti_map[$responsabile_id]['img'],
                'badge' => $utenti_map[$responsabile_id]['badge']
            ];
        }

        // Membri dall'organigramma (flatten ricorsivo)
        if ($organigramma_salvato) {
            $membri_ids = [];
            $flatten = function($node) use (&$flatten, &$membri_ids, $responsabile_id) {
                if (isset($node['user_id']) && $node['user_id'] !== $responsabile_id) {
                    $membri_ids[] = (int)$node['user_id'];
                }
                if (!empty($node['children']) && is_array($node['children'])) {
                    foreach ($node['children'] as $child) {
                        $flatten($child);
                    }
                }
            };
            $flatten($organigramma_salvato);
            $membri_ids = array_unique($membri_ids);

            foreach ($membri_ids as $uid) {
                if (isset($utenti_map[$uid])) {
                    $result['members'][] = [
                        'id' => $uid,
                        'nome' => $utenti_map[$uid]['nome'],
                        'ruolo' => 'Membro Team',
                        'avatar' => $utenti_map[$uid]['img'],
                        'badge' => $utenti_map[$uid]['badge']
                    ];
                }
            }
        }

        return $result;
    }

    public static function resolveRespCommessaIdByCodice(string $codice_commessa): ?int
    {
        global $database;

        // 1) leggi il valore com'?? nel DB (pu?? essere numero o stringa "Nome Cognome")
        $resp = $database->query(
            "SELECT responsabile_commessa FROM elenco_commesse WHERE codice = ? LIMIT 1",
            [$codice_commessa],
            __FILE__
        )->fetchColumn();

        if ($resp === false || $resp === null || $resp === '')
            return null;

        // 2) se ?? numerico, verifica che esista in personale
        if (is_numeric($resp)) {
            $id = (int) $resp;
            $exists = $database->query(
                "SELECT 1 FROM personale WHERE user_id = ? LIMIT 1",
                [$id],
                __FILE__
            )->fetchColumn();
            return $exists ? $id : null;
        }

        // 3) normalizzatore: trim e spazi singoli, minuscolo
        $norm = function (string $s): string {
            $s = trim($s);
            $s = preg_replace('/\s+/', ' ', $s);
            $s = mb_strtolower($s, 'UTF-8');
            $s = str_replace([',', '.'], '', $s);
            return $s;
        };

        $needle = $norm((string) $resp);

        // 4) pre-carica personale e crea mappa con entrambe le varianti:
        //    "cognome nome" (com????? nel DB) E "nome cognome" (swap)
        $righe = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
        $map = []; // chiave normalizzata -> user_id (int)

        foreach ($righe as $r) {
            $n1 = $norm((string) $r['Nominativo']);       // es: "boldrini francesco"
            $map[$n1] = (int) $r['user_id'];

            $parts = explode(' ', $n1);
            if (count($parts) >= 2) {
                // swap: "nome cognome" (primo token e ultimo token invertiti)
                $alt = $norm($parts[count($parts) - 1] . ' ' . $parts[0]); // "francesco boldrini"
                if (!isset($map[$alt]))
                    $map[$alt] = (int) $r['user_id'];
            }
        }

        if (isset($map[$needle]))
            return $map[$needle];

        // 5) ultimo tentativo: LIKE su entrambe le forme (needle e swap)
        $parts = explode(' ', $needle);
        $swapNeedle = null;
        if (count($parts) >= 2) {
            $swapNeedle = $norm($parts[count($parts) - 1] . ' ' . $parts[0]);
        }

        $likeId = $database->query(
            "SELECT user_id 
            FROM personale 
            WHERE LOWER(Nominativo) LIKE ? " . ($swapNeedle ? " OR LOWER(Nominativo) LIKE ?" : "") . "
            LIMIT 1",
            $swapNeedle ? ['%' . $needle . '%', '%' . $swapNeedle . '%'] : ['%' . $needle . '%'],
            __FILE__
        )->fetchColumn();

        return $likeId ? (int) $likeId : null;
    }

    /** Ritorna array<string> di section_key abilitate per una commessa (tabella) */
    public static function getEnabledSections($tabella): array
    {
        $tabella = preg_replace('/[^a-z0-9_]/i', '', (string) $tabella);
        if ($tabella === '')
            return [];

        // prova con $database (BSS), fallback a PDO grezzo se serve
        try {
            global $database;
            $stmt = $database->query(
                "SELECT section_key FROM commesse_sections WHERE tabella = ? AND enabled = 1",
                [$tabella],
                __FILE__
            );
            $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) : [];
            return array_values(array_filter(array_map('strval', $rows)));
        } catch (\Throwable $e) {
            try {
                global $pdo;
                $st = $pdo->prepare("SELECT section_key FROM commesse_sections WHERE tabella = ? AND enabled = 1");
                $st->execute([$tabella]);
                $rows = $st->fetchAll(\PDO::FETCH_COLUMN, 0);
                return array_values(array_filter(array_map('strval', $rows)));
            } catch (\Throwable $e2) {
                return [];
            }
        }
    }

    /** Abilita/disabilita una sezione (idempotente, con UPSERT) */
    public static function setSectionEnabled($tabella, $sectionKey, $enabled): array
    {
        $tabella = preg_replace('/[^a-z0-9_]/i', '', (string) $tabella);
        $sectionKey = preg_replace('/[^a-z0-9_]/i', '', (string) $sectionKey);
        $enabled = (int) !!$enabled;

        if ($tabella === '' || $sectionKey === '') {
            return ['success' => false, 'message' => 'Parametri non validi'];
        }

        $sql = "
        INSERT INTO commesse_sections (tabella, section_key, enabled)
        VALUES (:t, :k, :e)
        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP
    ";

        try {
            global $database;
            $database->query($sql, [':t' => $tabella, ':k' => $sectionKey, ':e' => $enabled], __FILE__);
            return ['success' => true];
        } catch (\Throwable $e) {
            try {
                global $pdo;
                $st = $pdo->prepare($sql);
                $st->execute([':t' => $tabella, ':k' => $sectionKey, ':e' => $enabled]);
                return ['success' => true];
            } catch (\Throwable $e2) {
                return ['success' => false, 'message' => 'Errore DB'];
            }
        }
    }

    /** ===== Organigramma Imprese: storage key-value su commesse_sezioni ===== */
    public static function getOrganigrammaImprese(string $tabella): array
    {
        global $database;
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $tabella);
        if ($tabella === '')
            return ['azienda_id' => null, 'children' => []];

        self::ensureSezioniTable();

        $row = $database->query(
            "SELECT data_json FROM commesse_sezioni WHERE tabella = ? AND sezione_key = 'organigramma_imprese' LIMIT 1",
            [$tabella],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['data_json'])) {
            return ['azienda_id' => null, 'children' => []];
        }

        $decoded = json_decode($row['data_json'], true);

        // Normalizzazione:
        // - se ?? gi?? un oggetto con azienda_id/children ??? ok
        // - se ?? un array (vecchio formato: solo children) ??? wrappa
        if (is_array($decoded) && array_key_exists('azienda_id', $decoded) && array_key_exists('children', $decoded)) {
            if (!isset($decoded['children']) || !is_array($decoded['children']))
                $decoded['children'] = [];
            if (!array_key_exists('azienda_id', $decoded))
                $decoded['azienda_id'] = null;
            return $decoded;
        }

        if (is_array($decoded)) {
            // legacy: children-only
            return [
                'azienda_id' => null,
                'children' => $decoded
            ];
        }

        return ['azienda_id' => null, 'children' => []];
    }

    public static function saveOrganigrammaImprese(string $tabella, array $data): array
    {
        global $database;
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $tabella);
        if ($tabella === '')
            return ['success' => false, 'message' => 'tabella mancante'];

        self::ensureSezioniTable();

        // Validazione: deve essere un oggetto con chiavi azienda_id (int|null) e children (array)
        if (!array_key_exists('children', $data) || !is_array($data['children'])) {
            // legacy: se arriva un array "puro", tratalo come children-only
            $data = [
                'azienda_id' => null,
                'children' => is_array($data) ? $data : []
            ];
        }
        if (!array_key_exists('azienda_id', $data))
            $data['azienda_id'] = null;

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false)
            return ['success' => false, 'message' => 'json non valido'];

        // upsert
        $exists = $database->query(
            "SELECT 1 FROM commesse_sezioni WHERE tabella = ? AND sezione_key = 'organigramma_imprese' LIMIT 1",
            [$tabella],
            __FILE__
        )->fetchColumn();

        if ($exists) {
            $database->query(
                "UPDATE commesse_sezioni SET data_json = ?, updated_at = NOW() WHERE tabella = ? AND sezione_key = 'organigramma_imprese' LIMIT 1",
                [$json, $tabella],
                __FILE__
            );
        } else {
            $database->query(
                "INSERT INTO commesse_sezioni (tabella, sezione_key, data_json, created_at, updated_at)
             VALUES (?, 'organigramma_imprese', ?, NOW(), NOW())",
                [$tabella, $json],
                __FILE__
            );
        }

        return ['success' => true];
    }

    private static function ensureSezioniTable(): void
    {
        global $database;
        // NB: tabella semplice K/V per sezioni commessa
        $database->query("
        CREATE TABLE IF NOT EXISTS commesse_sezioni (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tabella VARCHAR(100) NOT NULL,
            sezione_key VARCHAR(100) NOT NULL,
            data_json LONGTEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_tab_key (tabella, sezione_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", [], __FILE__);
    }

    /** ===== Moduli sicurezza compilabili (VRTP, VVCS, VCS, VPOS) ===== */
    private static function ensureSicurezzaFormsTables(): void
    {
        global $database;
        $database->query("
            CREATE TABLE IF NOT EXISTS commesse_sicurezza_forms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tabella VARCHAR(100) NOT NULL,
                tipo VARCHAR(16) NOT NULL,           -- VRTP | VVCS | VCS | VPOS
                titolo VARCHAR(255) NOT NULL,
                data_json LONGTEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tabella (tabella),
                INDEX idx_tipo (tipo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", [], __FILE__);

        $database->query("
            CREATE TABLE IF NOT EXISTS commesse_sicurezza_forms_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                form_id INT NOT NULL,
                action VARCHAR(24) NOT NULL,         -- create|update|delete
                actor_id INT NULL,
                note TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_form (form_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ", [], __FILE__);
    }

    /** Elenco moduli per tabella/tipo */
    public static function listSicurezzaForms(string $tabella, ?string $tipo = null, string $q = ''): array
    {
        global $database;
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $tabella);
        if ($tabella === '')
            return [];
        self::ensureSicurezzaFormsTables();

        $params = [':t' => $tabella];
        $where = "WHERE tabella = :t";
        if ($tipo) {
            $tipo = preg_replace('/[^A-Z0-9_]/', '', $tipo);
            $where .= " AND tipo = :k";
            $params[':k'] = $tipo;
        }
        if ($q !== '') {
            $qLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $where .= " AND (titolo LIKE :q OR data_json LIKE :q)";
            $params[':q'] = $qLike;
        }

        $stmt = $database->query("
            SELECT id, tipo, titolo, created_by, created_at, updated_at
            FROM commesse_sicurezza_forms
            $where
            ORDER BY updated_at DESC, created_at DESC
        ", $params, __FILE__);

        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['created_by'] = isset($r['created_by']) ? (int) $r['created_by'] : null;
        }
        return $rows;
    }

    /** Lettura singolo modulo */
    public static function getSicurezzaForm(int $id, string $tabella): array
    {
        global $database;
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $tabella);
        if ($id <= 0 || $tabella === '')
            return ['success' => false, 'message' => 'parametri mancanti'];
        self::ensureSicurezzaFormsTables();

        $row = $database->query(
            "
            SELECT id, tipo, titolo, data_json, created_by, created_at, updated_at
            FROM commesse_sicurezza_forms
            WHERE id = ? AND tabella = ? LIMIT 1",
            [$id, $tabella],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row)
            return ['success' => false, 'message' => 'form non trovato'];

        $data = [];
        if (!empty($row['data_json'])) {
            $tmp = json_decode($row['data_json'], true);
            if (is_array($tmp))
                $data = $tmp;
        }

        return [
            'success' => true,
            'form' => [
                'id' => (int) $row['id'],
                'tipo' => $row['tipo'],
                'titolo' => $row['titolo'],
                'data' => $data,
                'created_by' => (int) ($row['created_by'] ?? 0),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ]
        ];
    }

    /** Salvataggio/aggiornamento modulo */
    public static function saveSicurezzaForm(array $input): array
    {
        global $database;
        self::ensureSicurezzaFormsTables();

        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
        $tipo = isset($input['tipo']) ? preg_replace('/[^A-Z0-9_]/', '', (string) $input['tipo']) : '';
        $titolo = trim(strip_tags((string) ($input['titolo'] ?? '')));

        if ($tabella === '' || $tipo === '' || $titolo === '')
            return ['success' => false, 'message' => 'parametri obbligatori mancanti'];

        // tipi ammessi
        $allowed = ['VRTP', 'VVCS', 'VCS', 'VPOS'];
        if (!in_array($tipo, $allowed, true))
            return ['success' => false, 'message' => 'tipo non valido'];

        // data_json: DEVE essere array ??? json valido
        $data = isset($input['data']) && is_array($input['data']) ? $input['data'] : [];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false)
            return ['success' => false, 'message' => 'json non valido'];

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

        if ($id > 0) {
            $ok = $database->query(
                "UPDATE commesse_sicurezza_forms
                 SET tipo=?, titolo=?, data_json=?, updated_at=NOW()
                 WHERE id=? AND tabella=? LIMIT 1",
                [$tipo, $titolo, $json, $id, $tabella],
                __FILE__
            );
            if ($ok) {
                $database->query("INSERT INTO commesse_sicurezza_forms_log (form_id, action, actor_id) VALUES (?, 'update', ?)", [$id, $userId], __FILE__);
                return ['success' => true, 'id' => $id];
            }
            return ['success' => false, 'message' => 'errore update form'];
        }

        $ok = $database->query(
            "INSERT INTO commesse_sicurezza_forms (tabella, tipo, titolo, data_json, created_by)
             VALUES (?, ?, ?, ?, ?)",
            [$tabella, $tipo, $titolo, $json, $userId],
            __FILE__
        );
        if ($ok) {
            $newId = (int) $database->lastInsertId();
            $database->query("INSERT INTO commesse_sicurezza_forms_log (form_id, action, actor_id) VALUES (?, 'create', ?)", [$newId, $userId], __FILE__);
            return ['success' => true, 'id' => $newId];
        }
        return ['success' => false, 'message' => 'errore insert form'];
    }

    /** Delete (owner o Resp_Commessa) */
    public static function deleteSicurezzaForm(array $input): array
    {
        global $database;
        self::ensureSicurezzaFormsTables();

        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
        if ($id <= 0 || $tabella === '')
            return ['success' => false, 'message' => 'parametri mancanti'];

        $row = $database->query(
            "SELECT id, tabella, created_by FROM commesse_sicurezza_forms WHERE id=? AND tabella=? LIMIT 1",
            [$id, $tabella],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$row)
            return ['success' => false, 'message' => 'form non trovato'];

        $userId = (int) ($_SESSION['user_id'] ?? 0);

        // verifica responsabile commessa
        $resp = $database->query("SELECT responsabile_commessa FROM elenco_commesse WHERE codice = ? LIMIT 1", [$tabella], __FILE__)->fetchColumn();
        $respId = null;
        if ($resp) {
            if (is_numeric($resp))
                $respId = (int) $resp;
            else {
                $respId = $database->query("SELECT user_id FROM personale WHERE LOWER(Nominativo)=LOWER(?) LIMIT 1", [trim((string) $resp)], __FILE__)->fetchColumn();
                $respId = $respId ? (int) $respId : null;
            }
        }
        $isOwner = ($userId && $userId === (int) ($row['created_by'] ?? 0));
        $isResp = ($respId && $userId === $respId);
        if (!$isOwner && !$isResp)
            return ['success' => false, 'message' => 'non autorizzato'];

        $ok = $database->query("DELETE FROM commesse_sicurezza_forms WHERE id=? LIMIT 1", [$id], __FILE__);
        if ($ok) {
            $database->query("INSERT INTO commesse_sicurezza_forms_log (form_id, action, actor_id) VALUES (?, 'delete', ?)", [$id, $userId], __FILE__);
            return ['success' => true];
        }
        return ['success' => false, 'message' => 'errore eliminazione form'];
    }

    /** ===== Lista imprese da 'anagrafiche' (drag source sidebar) =====
     *  Ritorna: array di { id:int, label:string, piva?:string }
     *  - Filtra per testo se $q non ?? vuoto
     *  - Non assumo schema rigido: cerco la miglior "denominazione" con COALESCE
     */
    public static function getImpreseAnagrafiche(string $q = '', int $limit = 200): array
    {
        global $database;
        $limit = max(1, min($limit, 500));
        $q = trim($q ?? '');
        $qLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        // Campi REALI della tua tabella:
        // id, ragionesociale, partitaiva, (eventuali: email, codicefiscale, telefono, ...)

        $sql = "
        SELECT 
            a.id,
            COALESCE(NULLIF(TRIM(a.ragionesociale),''), CONCAT('Anagrafica #', a.id)) AS label,
            a.partitaiva AS piva
        FROM anagrafiche a
        WHERE (? = ''
               OR a.ragionesociale LIKE ?
               OR a.partitaiva     LIKE ?
               OR a.email          LIKE ?
               OR a.codicefiscale  LIKE ?
        )
        ORDER BY label ASC
        LIMIT {$limit}
        ";

        $rows = $database->query(
            $sql,
            [$q, $qLike, $qLike, $qLike, $qLike],
            __FILE__
        );

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $label = trim((string) ($r['label'] ?? ''));
            if ($id <= 0 || $label === '')
                continue;

            $out[] = [
                'id' => $id,
                'label' => $label,
                'piva' => isset($r['piva']) ? (string) $r['piva'] : null,
            ];
        }
        return $out;
    }

    /** ===== Organigramma Cantiere: storage key-value su commesse_sezioni ===== */
    public static function getOrganigrammaCantiere(string $tabella): array
    {
        global $database;
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $tabella);
        if ($tabella === '')
            return ['azienda_id' => null, 'role' => null, 'children' => []];

        self::ensureSezioniTable();

        $row = $database->query(
            "SELECT data_json FROM commesse_sezioni WHERE tabella = ? AND sezione_key = 'organigramma_cantiere' LIMIT 1",
            [$tabella],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['data_json'])) {
            return ['azienda_id' => null, 'role' => null, 'children' => []];
        }

        $decoded = json_decode($row['data_json'], true);

        // Normalizzazione:
        if (is_array($decoded) && array_key_exists('children', $decoded)) {
            if (!isset($decoded['children']) || !is_array($decoded['children']))
                $decoded['children'] = [];
            if (!array_key_exists('azienda_id', $decoded))
                $decoded['azienda_id'] = null;
            if (!array_key_exists('role', $decoded))
                $decoded['role'] = null;
            return $decoded;
        }

        // legacy: se in passato era un array (solo children)
        if (is_array($decoded)) {
            return ['azienda_id' => null, 'role' => null, 'children' => $decoded];
        }

        return ['azienda_id' => null, 'role' => null, 'children' => []];
    }

    public static function saveOrganigrammaCantiere(string $tabella, array $data): array
    {
        global $database;
        $tabella = preg_replace('/[^a-z0-9_]/i', '', $tabella);
        if ($tabella === '')
            return ['success' => false, 'message' => 'tabella mancante'];

        self::ensureSezioniTable();

        // Validazione: oggetto con keys azienda_id, role, children
        if (!array_key_exists('children', $data) || !is_array($data['children'])) {
            $data = [
                'azienda_id' => null,
                'role' => null,
                'children' => is_array($data) ? $data : []
            ];
        }
        if (!array_key_exists('azienda_id', $data))
            $data['azienda_id'] = null;
        if (!array_key_exists('role', $data))
            $data['role'] = null;

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json === false)
            return ['success' => false, 'message' => 'json non valido'];

        $exists = $database->query(
            "SELECT 1 FROM commesse_sezioni WHERE tabella = ? AND sezione_key = 'organigramma_cantiere' LIMIT 1",
            [$tabella],
            __FILE__
        )->fetchColumn();

        if ($exists) {
            $database->query(
                "UPDATE commesse_sezioni SET data_json = ?, updated_at = NOW() WHERE tabella = ? AND sezione_key = 'organigramma_cantiere' LIMIT 1",
                [$json, $tabella],
                __FILE__
            );
        } else {
            $database->query(
                "INSERT INTO commesse_sezioni (tabella, sezione_key, data_json, created_at, updated_at)
             VALUES (?, 'organigramma_cantiere', ?, NOW(), NOW())",
                [$tabella, $json],
                __FILE__
            );
        }

        return ['success' => true];
    }

    /* =========================
     *  IMPOSTAZIONI SICUREZZA
     *  - Tipi documento, Ruoli, Tipi impresa
     *  Metodi generici per LIST/UPSERT/DELETE
     * ========================= */

    /** mappa "type" -> nome tabella fisica */
    /** mappa "type" -> nome tabella fisica */
    private static function mapTypeToTable(string $type): ?string
    {
        $type = strtolower(trim($type));
        return [
            // alias ???storico??? usato dalla UI impostazioni
            'sic_docs' => 'sic_tipi_documento',
            // mapping gi?? esistenti
            'tipi_documento' => 'sic_tipi_documento',
            'ruoli' => 'sic_ruoli_cantiere',
            'tipi_impresa' => 'sic_tipi_impresa',
        ][$type] ?? null;
    }

    /** sanitizzatori coerenti con linee guida (client filtra, server valida) */
    private static function sanitizeCodiceServer(?string $v): ?string
    {
        if ($v === null)
            return null;
        $v = preg_replace('/[^A-Za-z0-9_\-\.]/', '', trim($v));
        if ($v === '')
            return null;
        return mb_substr($v, 0, 32);
    }
    private static function sanitizeNomeServer(?string $v): string
    {
        $v = trim((string) $v);
        $v = preg_replace('/[\x00-\x1F]+/u', '', $v);
        return mb_substr($v, 0, 120);
    }

    public static function listSettings(array $req): array
    {
        global $database;

        $table = self::mapTypeToTable($req['type'] ?? '');
        if (!$table)
            return ['success' => false, 'error' => 'Tipo non valido'];

        try {
            $rows = $database->query("
            SELECT id, codice, nome
            FROM `$table`
            ORDER BY nome ASC, id ASC
        ", [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            // cast coerente
            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
            }

            return ['success' => true, 'rows' => $rows];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Errore in lettura'];
        }
    }

    public static function saveSetting(array $req): array
    {
        global $database;

        $table = self::mapTypeToTable($req['type'] ?? '');
        if (!$table)
            return ['success' => false, 'error' => 'Tipo non valido'];

        $id = isset($req['id']) ? max(0, (int) $req['id']) : 0;
        $codice = self::sanitizeCodiceServer($req['codice'] ?? null);
        $nome = self::sanitizeNomeServer($req['nome'] ?? '');

        if ($nome === '')
            return ['success' => false, 'error' => 'Nome obbligatorio'];

        // Check duplicato "nome" (evita reliance su SQLSTATE e funziona anche senza UNIQUE temporaneo)
        if ($id > 0) {
            $dup = $database->query(
                "SELECT id FROM `$table` WHERE nome = ? AND id <> ? LIMIT 1",
                [$nome, $id],
                __FILE__
            )->fetchColumn();
        } else {
            $dup = $database->query(
                "SELECT id FROM `$table` WHERE nome = ? LIMIT 1",
                [$nome],
                __FILE__
            )->fetchColumn();
        }
        if ($dup) {
            return ['success' => false, 'error' => 'Nome gi?? esistente'];
        }

        try {
            if ($id > 0) {
                $ok = $database->query(
                    "UPDATE `$table` SET codice = ?, nome = ? WHERE id = ?",
                    [$codice, $nome, $id],
                    __FILE__
                );
                if (!$ok)
                    return ['success' => false, 'error' => 'Aggiornamento non riuscito'];
                return ['success' => true, 'id' => $id];
            } else {
                $ok = $database->query(
                    "INSERT INTO `$table` (codice, nome) VALUES (?, ?)",
                    [$codice, $nome],
                    __FILE__
                );
                if (!$ok)
                    return ['success' => false, 'error' => 'Inserimento non riuscito'];
                return ['success' => true, 'id' => (int) $database->lastInsertId()];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Errore database'];
        }
    }

    /** DELETE */
    public static function deleteSetting(array $req): array
    {
        global $database;

        $table = self::mapTypeToTable($req['type'] ?? '');
        if (!$table)
            return ['success' => false, 'error' => 'Tipo non valido'];

        // if (!checkPermission('edit_commesse')) return ['success'=>false,'error'=>'Permesso negato'];

        $id = isset($req['id']) ? (int) $req['id'] : 0;
        if ($id <= 0)
            return ['success' => false, 'error' => 'ID non valido'];

        try {
            $ok = $database->query("DELETE FROM `$table` WHERE id = ? LIMIT 1", [$id], __FILE__);
            if (!$ok)
                return ['success' => false, 'error' => 'Eliminazione non riuscita'];
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Errore database'];
        }
    }

    // services/CommesseService.php
    public static function getImpresaDettagli(string $tabella, int $aziendaId): array
    {
        global $database;

        // Etichetta azienda: anagrafiche.ragionesociale
        $label = "Impresa #{$aziendaId}";
        try {
            $stmt = $database->query(
                "SELECT COALESCE(NULLIF(TRIM(ragionesociale),''), CONCAT('Impresa #', id)) AS label
             FROM anagrafiche
             WHERE id = ? LIMIT 1",
                [$aziendaId],
                __FILE__
            );
            if ($stmt && ($r = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                $label = (string) ($r['label'] ?? $label);
            }
        } catch (\Throwable $e) {
            // fallback ok
        }

        // Documenti di sicurezza per questa impresa
        $rows = [];
        try {
            $stmt = $database->query(
                "SELECT tipo AS codice, path_file, titolo, scadenza, personale
             FROM commesse_sicurezza_docs
             WHERE tabella = ? AND azienda_id = ?
             ORDER BY created_at DESC, id DESC",
                [$tabella, $aziendaId],
                __FILE__
            );
            $docs = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            $base = (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https')
                . '://' . ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');

            foreach ($docs as $d) {
                $rel = trim((string) ($d['path_file'] ?? ''), '/');
                $url = $rel === '' ? '' : (preg_match('#^https?://#i', $rel) ? $rel : "{$base}/{$rel}");

                // personale ?? JSON nel DB -> decodifica in array (se presente)
                $pers = [];
                if (isset($d['personale']) && is_string($d['personale']) && $d['personale'] !== '') {
                    $tmp = json_decode($d['personale'], true);
                    if (is_array($tmp))
                        $pers = array_values(array_filter(array_map('strval', $tmp)));
                }

                $rows[] = [
                    'codice' => strtoupper((string) ($d['codice'] ?? '')),
                    'has' => true,
                    'file_url' => $url,
                    'titolo' => (string) ($d['titolo'] ?? ''),
                    'scadenza' => $d['scadenza'] ?? null,
                    'personale' => $pers, // ??? array di stringhe per la vista
                ];
            }
        } catch (\Throwable $e) {
            // nessun documento => rows vuoto
        }

        return [
            'success' => true,
            'impresa' => ['label' => $label, 'id' => $aziendaId],
            'docs' => $rows
        ];
    }

    /**
     * Verifica presenza/validit?? documento per IMPRESA usando la TUA tabella reale:
     *   commesse_sicurezza_docs
     * Ritorna: ['ok'=>bool, 'updated_at'=>string|null, 'url'=>string|null]
     * NOTE:
     * - normalizza i tipi (POS|VRTP|VCS|VVCS|VPOS)
     * - considera scadenza (se presente) e prende il file pi?? recente
     */
    private static function checkDocumento(string $tabella, int $aziendaId, string $tipo): array
    {
        global $database;

        $tabella = preg_replace('/[^a-z0-9_]/i', '', $tabella);
        if ($tabella === '' || $aziendaId <= 0)
            return ['ok' => false];

        $norm = function (string $s): string {
            $s = strtoupper($s);
            $s = str_replace(['.', ' ', '-'], '', $s);
            return preg_replace('/[^A-Z0-9]/', '', $s);
        };

        $tipoKey = $norm($tipo);
        $aliasMap = [
            'POS' => ['POS', 'VPOS'],
            'VRTP' => ['VRTP'],
            'VCS' => ['VCS', 'VVCS'],
        ];
        $canonKey = $tipoKey;
        foreach ($aliasMap as $canon => $vars) {
            if (in_array($tipoKey, array_map($norm, $vars), true)) {
                $canonKey = $canon;
                break;
            }
        }
        $variants = array_map($norm, $aliasMap[$canonKey] ?? [$canonKey]);

        $place = implode(',', array_fill(0, count($variants), '?'));
        $sql = "
        SELECT d.id, d.updated_at, d.path_file
        FROM commesse_sicurezza_docs d
        WHERE d.tabella = ?
          AND d.azienda_id = ?
          AND UPPER(REPLACE(REPLACE(REPLACE(d.tipo,'.',''),' ',''),'-','')) IN ($place)
          AND (d.scadenza IS NULL OR d.scadenza >= CURRENT_DATE())
        ORDER BY d.updated_at DESC
        LIMIT 1
    ";

        $params = array_merge([$tabella, $aziendaId], $variants);

        try {
            $row = $database->query($sql, $params, __FILE__)->fetch(\PDO::FETCH_ASSOC);
            if (!$row)
                return ['ok' => false];

            $path = (string) ($row['path_file'] ?? '');
            if ($path !== '' && !preg_match('#^https?://#i', $path)) {
                $path = '/' . ltrim($path, '/');
            }
            return [
                'ok' => true,
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'url' => $path ?: null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false];
        }
    }

    // === Aggiungi in class CommesseService ===
    private static function ensureSicurezzaDocsTable(): void
    {
        global $database;
        // Schema allineato alla tua tabella reale
        $database->query("
            CREATE TABLE IF NOT EXISTS commesse_sicurezza_docs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tabella VARCHAR(100) NOT NULL,
                tipo VARCHAR(16) NOT NULL,
                titolo VARCHAR(255) NOT NULL,
                path_file VARCHAR(255) NOT NULL,
                versione INT NOT NULL DEFAULT 1,
                emesso_il DATE NULL,
                scadenza DATE NULL,
                personale JSON NULL,
                azienda_id INT NOT NULL,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                INDEX idx_ta (tabella, azienda_id),
                INDEX idx_tipo (tipo),
                INDEX idx_scad (scadenza)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ", [], __FILE__);
    }

    private static function getSicurezzaUploadPath(string $tabella, int $aziendaId): array
    {
        $base = defined('ROOT') ? ROOT : dirname(__DIR__);
        $dir = rtrim($base, '/\\') . "/uploads/commesse_sicurezza/" . $tabella . "/" . $aziendaId;
        if (!is_dir($dir))
            mkdir($dir, 0775, true);
        return ['absolute' => $dir, 'relative' => "uploads/commesse_sicurezza/{$tabella}/{$aziendaId}"];
    }

    public static function uploadDocumentoSicurezza(array $post, array $files = [])
    {
        global $database;

        // Assicura la presenza della tabella docs
        self::ensureSicurezzaDocsTable();

        try {
            /* ========= Validazione input base ========= */
            $tabella = isset($post['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $post['tabella']) : '';
            $azienda_id = isset($post['azienda_id']) ? (int) $post['azienda_id'] : 0;
            $tipo = isset($post['tipo']) ? strtoupper(preg_replace('/[^A-Z0-9_]/', '', (string) $post['tipo'])) : '';
            $scadenza = isset($post['scadenza']) ? trim((string) $post['scadenza']) : null;

            if ($tabella === '' || $azienda_id <= 0 || $tipo === '') {
                return ['success' => false, 'message' => 'Parametri mancanti o non validi'];
            }

            // file caricato?
            $f = $files['file'] ?? $post['file'] ?? null;
            if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'File mancante o non valido'];
            }

            /* ========= Normalizza scadenza ========= */
            if ($scadenza !== null && $scadenza !== '') {
                // accetta sia YYYY-MM-DD che DD/MM/YYYY
                if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $scadenza)) {
                    // ok
                } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $scadenza, $m)) {
                    $scadenza = "{$m[3]}-{$m[2]}-{$m[1]}";
                } else {
                    $scadenza = null; // formato non riconosciuto ??? salvo null
                }
            } else {
                $scadenza = null;
            }

            /* ========= Raccolta ???personale??? (chips) ========= */
            // dal FormData possono arrivare come personale[] ripetuto
            $personaleVals = [];
            if (isset($post['personale'])) {
                // pu?? essere stringa o array
                $personaleVals = is_array($post['personale']) ? $post['personale'] : [$post['personale']];
            }
            if (isset($post['personale[]'])) {
                $arr = is_array($post['personale[]']) ? $post['personale[]'] : [$post['personale[]']];
                $personaleVals = array_merge($personaleVals, $arr);
            }
            // pulizia minimale
            $personaleVals = array_values(array_filter(array_map(static function ($v) {
                $s = trim((string) $v);
                return $s !== '' ? $s : null;
            }, $personaleVals)));
            $personaleJson = $personaleVals ? json_encode($personaleVals, JSON_UNESCAPED_UNICODE) : null;

            /* ========= Prepara nome file sicuro e cartella ========= */
            $origName = (string) $f['name'];
            $ext = pathinfo($origName, PATHINFO_EXTENSION);
            $base = pathinfo($origName, PATHINFO_FILENAME);

            $safeExt = $ext ? ('.' . strtolower(preg_replace('/[^a-z0-9]/i', '', $ext))) : '';
            $safeBase = substr(preg_replace('/[^a-z0-9_\-]/i', '_', $base), 0, 120);
            $uniq = bin2hex(random_bytes(6));
            $newName = ($safeBase ?: 'doc') . '_' . $uniq . $safeExt;

            // uploads/commesse/{tabella}/{azienda_id}/sicurezza/{tipo}/
            $relDir = "uploads/commesse/{$tabella}/{$azienda_id}/sicurezza/{$tipo}";
            $baseDir = defined('ROOT') ? ROOT : dirname(__DIR__);
            $absDir = rtrim($baseDir, '/\\') . '/' . $relDir;

            if (!is_dir($absDir)) {
                @mkdir($absDir, 0775, true);
            }
            if (!is_dir($absDir) || !is_writable($absDir)) {
                return ['success' => false, 'message' => 'Directory di upload non disponibile'];
            }

            $absPath = $absDir . '/' . $newName;
            if (!@move_uploaded_file($f['tmp_name'], $absPath)) {
                return ['success' => false, 'message' => 'Errore nel salvataggio del file'];
            }

            $path_file = $relDir . '/' . $newName;

            /* ========= Calcola versione (MAX+1) per chiave logica ========= */
            $sqlv = "SELECT COALESCE(MAX(versione),0) AS v
                 FROM commesse_sicurezza_docs
                 WHERE tabella = :tabella AND azienda_id = :azienda_id AND tipo = :tipo";
            $rowv = $database->query($sqlv, [
                ':tabella' => $tabella,
                ':azienda_id' => $azienda_id,
                ':tipo' => $tipo
            ], __FILE__)->fetch(\PDO::FETCH_ASSOC);
            $versione = (int) ($rowv['v'] ?? 0) + 1;

            /* ========= Altri campi opzionali coerenti con schema ========= */
            $titolo = $origName;               // mostri il nome originale
            $emesso_il = null;                    // non lo stiamo gestendo ora
            $created_by = $_SESSION['user_id'] ?? null;

            /* ========= INSERT ========= */
            $sql = "INSERT INTO commesse_sicurezza_docs
                (tabella, tipo, titolo, path_file, versione, emesso_il, scadenza, personale, azienda_id, created_by, created_at, updated_at)
                VALUES
                (:tabella, :tipo, :titolo, :path_file, :versione, :emesso_il, :scadenza, :personale, :azienda_id, :created_by, NOW(), NOW())";

            $params = [
                ':tabella' => $tabella,
                ':tipo' => $tipo,
                ':titolo' => $titolo,
                ':path_file' => $path_file,
                ':versione' => $versione,
                ':emesso_il' => $emesso_il,
                ':scadenza' => $scadenza,       // pu?? essere null
                ':personale' => $personaleJson,  // JSON o null
                ':azienda_id' => $azienda_id,
                ':created_by' => $created_by
            ];

            $database->query($sql, $params, __FILE__);
            $newId = (int) $database->lastInsertId();

            /* ========= URL per frontend ========= */
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $baseUrl = $host ? ($scheme . '://' . $host) : '';
            $file_url = $baseUrl ? (rtrim($baseUrl, '/') . '/' . ltrim($path_file, '/')) : $path_file;

            return [
                'success' => true,
                'id' => $newId,
                'tabella' => $tabella,
                'azienda_id' => $azienda_id,
                'tipo' => $tipo,
                'titolo' => $titolo,
                'versione' => $versione,
                'scadenza' => $scadenza,
                'personale' => $personaleVals, // echo ???umano??? oltre al JSON salvato
                'path_file' => $path_file,
                'file_url' => $file_url,
                'message' => 'Documento caricato'
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Errore interno uploadDocumentoSicurezza',
                'error' => $e->getMessage()
            ];
        }
    }

    public static function deleteDocumentoSicurezza(array $input): array
    {
        global $database;
        self::ensureSicurezzaDocsTable();

        $tabella = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');
        $aziendaId = intval($input['azienda_id'] ?? 0);
        $tipo = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $input['tipo'] ?? ''));

        if ($tabella === '' || $aziendaId <= 0 || $tipo === '')
            return ['success' => false, 'message' => 'Parametri mancanti'];

        $row = $database->query(
            "SELECT id, path_file FROM commesse_sicurezza_docs
         WHERE tabella=? AND azienda_id=? AND tipo=?
         ORDER BY created_at DESC, id DESC
         LIMIT 1",
            [$tabella, $aziendaId, $tipo],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$row)
            return ['success' => false, 'message' => 'Documento non trovato'];

        // Best effort: rimuovi file fisico
        $base = dirname(__FILE__);
        while ($base && !is_dir($base . '/uploads'))
            $base = dirname($base);
        if (!empty($row['path_file']))
            @unlink($base . '/' . ltrim($row['path_file'], '/'));

        $database->query("DELETE FROM commesse_sicurezza_docs WHERE id=? LIMIT 1", [(int) $row['id']], __FILE__);
        return ['success' => true];
    }

    public static function setScadenzaDocumentoSicurezza(array $input): array
    {
        global $database;
        self::ensureSicurezzaDocsTable();

        $tabella = preg_replace('/[^a-z0-9_]/i', '', $input['tabella'] ?? '');
        $aziendaId = intval($input['azienda_id'] ?? 0);
        $tipo = strtoupper(preg_replace('/[^A-Z0-9_]/', '', $input['tipo'] ?? ''));
        $scadenza = isset($input['scadenza']) && trim($input['scadenza']) !== '' ? $input['scadenza'] : null;

        if ($tabella === '' || $aziendaId <= 0 || $tipo === '')
            return ['success' => false, 'message' => 'Parametri mancanti'];

        // aggiorna SOLO l'ultimo caricato
        $database->query(
            "UPDATE commesse_sicurezza_docs
         SET scadenza = ?
         WHERE tabella=? AND azienda_id=? AND tipo=?
         ORDER BY created_at DESC, id DESC
         LIMIT 1",
            [$scadenza, $tabella, $aziendaId, $tipo],
            __FILE__
        );

        return ['success' => true];
    }

    /**
     * Ritorna: ['success'=>bool, 'aziende'=>[['azienda_id'=>int,'nome'=>string,'ruolo'=>string], ...]]
     */
    public static function listImpresePerControlli(array $req): array
    {
        global $database;

        try {
            $tabella = preg_replace('/[^a-z0-9_]/i', '', (string) ($req['tabella'] ?? ''));
            if ($tabella === '') {
                return ['success' => false, 'message' => 'Parametro tabella mancante'];
            }

            $aziende = self::fetchImpreseForCommessa($tabella);

            // Normalizza output JSON-safe
            if (!is_array($aziende))
                $aziende = [];
            foreach ($aziende as &$az) {
                $az['azienda_id'] = (int) ($az['azienda_id'] ?? 0);
                $az['nome'] = trim((string) ($az['nome'] ?? ''));
                $az['ruolo'] = trim((string) ($az['ruolo'] ?? ''));
            }

            return ['success' => true, 'aziende' => $aziende];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Errore interno listImpresePerControlli',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Discovery robuste:
     * 1) Prova schemi noti (commesse_organigramma_imprese, commesse_imprese, commesse_org_imprese, organigramma_imprese, commesse_utenti come fallback "impresa" se presente).
     * 2) Se falliscono, cerca dinamicamente una tabella "link" che abbia colonna 'tabella' e una tra 'azienda_id'/'impresa_id',
     *    e una tabella "master" imprese (imprese / aziende / imprese_anagrafica / anagrafica_imprese) con colonna nome (ragione_sociale/nome/denominazione/company_name).
     */
    private static function fetchImpreseForCommessa(string $tabella): array
    {
        global $database;

        $tableExists = function (string $name) use ($database): bool {
            $q = $database->query(
                "SELECT COUNT(*) AS n
                   FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t",
                [':t' => $name],
                __FILE__
            );
            if (!$q)
                return false;
            $row = $q->fetch(\PDO::FETCH_ASSOC);
            return (int) ($row['n'] ?? 0) > 0;
        };

        $colExists = function (string $table, string $column) use ($database): bool {
            $q = $database->query(
                "SELECT COUNT(*) AS n
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c",
                [':t' => $table, ':c' => $column],
                __FILE__
            );
            if (!$q)
                return false;
            $row = $q->fetch(\PDO::FETCH_ASSOC);
            return (int) ($row['n'] ?? 0) > 0;
        };

        // 1) Schemi noti (pi?? probabili)
        $knownLinkMasters = [
            // link, master, link.azienda_col, master.name_col (detect dinamica)
            ['link' => 'commesse_organigramma_imprese', 'master' => 'imprese', 'azienda_cols' => ['azienda_id', 'impresa_id']],
            ['link' => 'commesse_imprese', 'master' => 'imprese', 'azienda_cols' => ['azienda_id', 'impresa_id']],
            ['link' => 'commesse_org_imprese', 'master' => 'imprese', 'azienda_cols' => ['azienda_id', 'impresa_id']],
            ['link' => 'organigramma_imprese', 'master' => 'imprese', 'azienda_cols' => ['azienda_id', 'impresa_id']],

            ['link' => 'commesse_organigramma_imprese', 'master' => 'aziende', 'azienda_cols' => ['azienda_id', 'impresa_id']],
            ['link' => 'commesse_imprese', 'master' => 'aziende', 'azienda_cols' => ['azienda_id', 'impresa_id']],
            ['link' => 'commesse_org_imprese', 'master' => 'aziende', 'azienda_cols' => ['azienda_id', 'impresa_id']],
            ['link' => 'organigramma_imprese', 'master' => 'aziende', 'azienda_cols' => ['azienda_id', 'impresa_id']],
        ];

        $nameCandidates = ['ragione_sociale', 'nome', 'denominazione', 'company_name', 'ragioneSociale']; // rispettiamo possibili legacy

        foreach ($knownLinkMasters as $km) {
            $link = $km['link'];
            $master = $km['master'];
            if (!$tableExists($link) || !$tableExists($master))
                continue;

            // deve esistere 'tabella' nel link
            if (!$colExists($link, 'tabella'))
                continue;

            // colonna azienda nel link
            $aziendaCol = null;
            foreach ($km['azienda_cols'] as $c) {
                if ($colExists($link, $c)) {
                    $aziendaCol = $c;
                    break;
                }
            }
            if (!$aziendaCol)
                continue;

            // colonna nome nell'anagrafica
            $nameCol = null;
            foreach ($nameCandidates as $nc) {
                if ($colExists($master, $nc)) {
                    $nameCol = $nc;
                    break;
                }
            }
            if (!$nameCol)
                continue;

            // colonna ruolo (opzionale) nel link
            $ruoloCol = $colExists($link, 'ruolo') ? 'ruolo' : null;

            $sql = "SELECT L.`$aziendaCol` AS azienda_id, M.`$nameCol` AS nome" . ($ruoloCol ? ", COALESCE(L.`$ruoloCol`, '') AS ruolo" : ", '' AS ruolo") . "
                      FROM `$link` L
                      JOIN `$master` M ON M.id = L.`$aziendaCol`
                     WHERE L.tabella = :t";

            $stmt = $database->query($sql, [':t' => $tabella], __FILE__);
            if ($stmt) {
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if (is_array($rows) && count($rows) > 0) {
                    return $rows;
                }
            }
        }

        // 2) Auto-discovery dinamico:
        //    trova una tabella link con colonne: tabella + (azienda_id|impresa_id)
        $linkCandidates = [];
        $q = $database->query(
            "SELECT TABLE_NAME
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND COLUMN_NAME = 'tabella'
           GROUP BY TABLE_NAME",
            [],
            __FILE__
        );
        if ($q) {
            $linkCandidates = $q->fetchAll(\PDO::FETCH_COLUMN);
        }

        foreach ($linkCandidates as $link) {
            if (!$tableExists($link))
                continue;

            $aziendaCol = null;
            foreach (['azienda_id', 'impresa_id'] as $cand) {
                if ($colExists($link, $cand)) {
                    $aziendaCol = $cand;
                    break;
                }
            }
            if (!$aziendaCol)
                continue;

            // scegli master ???imprese???
            $masterOptions = ['imprese', 'aziende', 'imprese_anagrafica', 'anagrafica_imprese'];
            $master = null;
            foreach ($masterOptions as $m) {
                if ($tableExists($m)) {
                    $master = $m;
                    break;
                }
            }
            if (!$master)
                continue;

            $nameCol = null;
            foreach ($nameCandidates as $nc) {
                if ($colExists($master, $nc)) {
                    $nameCol = $nc;
                    break;
                }
            }
            if (!$nameCol)
                continue;

            $ruoloCol = $colExists($link, 'ruolo') ? 'ruolo' : null;

            $sql = "SELECT L.`$aziendaCol` AS azienda_id, M.`$nameCol` AS nome" . ($ruoloCol ? ", COALESCE(L.`$ruoloCol`, '') AS ruolo" : ", '' AS ruolo") . "
                      FROM `$link` L
                      JOIN `$master` M ON M.id = L.`$aziendaCol`
                     WHERE L.tabella = :t";

            $stmt = $database->query($sql, [':t' => $tabella], __FILE__);
            if ($stmt) {
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                if (is_array($rows) && count($rows) > 0) {
                    return $rows;
                }
            }
        }

        // 3) Fallback vuoto: UI mostrer?? placeholder
        return [];
    }

    public static function listDocumentiSicurezza(string $tabella, int $aziendaId = 0, ?string $tipo = null): array
    {
        global $database;

        $tabella = preg_replace('/[^a-z0-9_]/i', '', $tabella);
        $aziendaId = max(0, (int) $aziendaId);
        $tipo = ($tipo !== null) ? preg_replace('/[^A-Z0-9_]/', '', $tipo) : null;

        if ($tabella === '')
            return [];

        // Tabella reale: commesse_sicurezza_docs
        // Colonne reali: id, tabella, tipo, titolo, path_file, versione, emesso_il, scadenza, personale, azienda_id, created_by, created_at, updated_at
        $params = [':tab' => $tabella, ':az' => $aziendaId];
        $where = "WHERE tabella = :tab AND azienda_id = :az";

        if ($tipo !== null && $tipo !== '') {
            $where .= " AND tipo = :tipo";
            $params[':tipo'] = $tipo;
        }

        $sql = "SELECT id, titolo, path_file, created_at, updated_at, scadenza
            FROM commesse_sicurezza_docs
            $where
            ORDER BY COALESCE(updated_at, created_at) DESC, id DESC";

        $st = $database->query($sql, $params, __FILE__);
        if (!$st)
            return [];

        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];

        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            $titolo = trim((string) ($r['titolo'] ?? ''));
            $path = (string) ($r['path_file'] ?? '');
            $url = $path !== '' ? (strpos($path, '/') === 0 ? $path : '/' . ltrim($path, '/')) : '';
            $nome = $titolo !== '' ? $titolo : ($url !== '' ? basename($url) : 'Documento');
            $ext = strtolower(pathinfo($nome !== '' ? $nome : ($url !== '' ? basename($url) : ''), PATHINFO_EXTENSION));

            $out[] = [
                'id' => $id,
                'nome' => $nome,                   // usato come etichetta
                'ext' => $ext ?: null,
                'url' => $url,                    // da aprire nell???iframe
                'preview_url' => null,                    // se in futuro generi thumb
                'uploaded_at' => (string) ($r['created_at'] ?? null),
                'scadenza' => (string) ($r['scadenza'] ?? null),
            ];
        }
        return $out;
    }

    /* =========================
     *  VFP - VERIFICA FORMAZIONE PERSONALE (sic_*)
     * ========================= */

    private static function ensureSicVfpTables(): void
    {
        global $database;
        // Operatori
        $database->query("
        CREATE TABLE IF NOT EXISTS sic_vfp_operatori (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tabella VARCHAR(100) NOT NULL,
            azienda_id INT NOT NULL,
            cognome VARCHAR(120) NULL,
            nome VARCHAR(120) NULL,
            posizione ENUM('regolare','irregolare') NULL,
            unilav ENUM('indeterminato','determinato') NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ta (tabella, azienda_id),
            INDEX idx_az (azienda_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", [], __FILE__);

        // Date key/value
        $database->query("
        CREATE TABLE IF NOT EXISTS sic_vfp_date (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operatore_id INT NOT NULL,
            col_code VARCHAR(32) NOT NULL,
            value_date DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_op_col (operatore_id, col_code),
            INDEX idx_col (col_code),
            CONSTRAINT fk_vfp_date_op
                FOREIGN KEY (operatore_id) REFERENCES sic_vfp_operatori(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", [], __FILE__);
    }

    private static function sanitizeTabellaForCommessa(?string $t): string
    {
        $t = preg_replace('/[^a-z0-9_]/i', '', (string) $t);
        return strtolower($t);
    }

    /**
     * Recupera tutte le opere da gar_opere_dm50 per popolare i select
     * @return array Array di opere con id_opera e identificazione_opera
     */
    public static function getOpereDm50(): array
    {
        global $database;
        $pdo = $database->connection;

        try {
            // Verifica che la tabella esista
            $tableExists = $pdo->query("SHOW TABLES LIKE 'gar_opere_dm50'");
            if (!$tableExists || $tableExists->rowCount() === 0) {
                return ['success' => true, 'data' => []];
            }

            $sql = "SELECT id_opera, identificazione_opera 
                    FROM gar_opere_dm50 
                    ORDER BY id_opera ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return ['success' => true, 'data' => $rows];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore nel recupero opere: ' . $e->getMessage()];
        }
    }

    /** Elenco righe per la tabella VFP */
    public static function getVfpFormazione(array $input): array
    {
        global $database;

        self::ensureSicVfpTables();

        $tabella = self::sanitizeTabellaForCommessa($input['tabella'] ?? '');
        $aziendaId = (int) ($input['azienda_id'] ?? 0);

        if ($tabella === '' || $aziendaId <= 0) {
            return ['success' => false, 'message' => 'parametri mancanti', 'rows' => []];
        }

        // operatori
        $ops = $database->query("
        SELECT id, cognome, nome, posizione, unilav
        FROM sic_vfp_operatori
        WHERE tabella = ? AND azienda_id = ?
        ORDER BY cognome ASC, nome ASC, id ASC
    ", [$tabella, $aziendaId], __FILE__)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (!$ops)
            return ['success' => true, 'rows' => []];

        // mappa id -> dates
        $ids = array_map(static fn($r) => (int) $r['id'], $ops);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $dates = $database->query("
        SELECT operatore_id, col_code, value_date
        FROM sic_vfp_date
        WHERE operatore_id IN ($in)
    ", $ids, __FILE__)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($dates as $d) {
            $oid = (int) $d['operatore_id'];
            if (!isset($map[$oid]))
                $map[$oid] = [];
            $map[$oid][strtolower($d['col_code'])] = $d['value_date'];
        }

        $rows = [];
        foreach ($ops as $r) {
            $rows[] = [
                'id' => (int) $r['id'],
                'cognome' => (string) ($r['cognome'] ?? ''),
                'nome' => (string) ($r['nome'] ?? ''),
                'posizione' => (string) ($r['posizione'] ?? ''),
                'unilav' => (string) ($r['unilav'] ?? ''),
                'dates' => $map[(int) $r['id']] ?? new \stdClass()
            ];
        }

        return ['success' => true, 'rows' => $rows];
    }

    /** Salva campo testuale di una riga (cognome/nome/posizione/unilav) */
    public static function saveRowField(array $input): array
    {
        global $database;

        self::ensureSicVfpTables();

        $tabella = self::sanitizeTabellaForCommessa($input['tabella'] ?? '');
        $aziendaId = (int) ($input['azienda_id'] ?? 0);
        $rowId = (int) ($input['row_id'] ?? 0);
        $field = preg_replace('/[^a-z0-9_]/i', '', (string) ($input['field'] ?? ''));
        $value = isset($input['value']) ? trim((string) $input['value']) : null;

        if ($tabella === '' || $aziendaId <= 0 || $rowId <= 0 || $field === '') {
            return ['success' => false, 'message' => 'parametri mancanti'];
        }

        // whitelist campi modificabili
        $allowed = ['cognome', 'nome', 'posizione', 'unilav'];
        if (!in_array($field, $allowed, true)) {
            return ['success' => false, 'message' => 'campo non consentito'];
        }

        // normalizzazioni coerenti con UI
        if ($field === 'posizione') {
            $value = $value !== '' ? strtolower($value) : null;
            if ($value !== null && !in_array($value, ['regolare', 'irregolare'], true))
                $value = null;
        }
        if ($field === 'unilav') {
            $value = $value !== '' ? strtolower($value) : null;
            if ($value !== null && !in_array($value, ['indeterminato', 'determinato'], true))
                $value = null;
        }

        // vincolo su appartenenza riga
        $own = $database->query("
        SELECT id FROM sic_vfp_operatori WHERE id = ? AND tabella = ? AND azienda_id = ? LIMIT 1
    ", [$rowId, $tabella, $aziendaId], __FILE__)->fetchColumn();
        if (!$own)
            return ['success' => false, 'message' => 'riga non trovata'];

        $ok = $database->query(
            "UPDATE sic_vfp_operatori SET `$field` = ? WHERE id = ? LIMIT 1",
            [$value !== '' ? $value : null, $rowId],
            __FILE__
        );
        return $ok ? ['success' => true] : ['success' => false, 'message' => 'update fallito'];
    }

    /** Salva una cella data (key/value) */
    public static function saveVfpCell(array $input): array
    {
        global $database;

        self::ensureSicVfpTables();

        $tabella = self::sanitizeTabellaForCommessa($input['tabella'] ?? '');
        $aziendaId = (int) ($input['azienda_id'] ?? 0);
        $rowId = (int) ($input['row_id'] ?? 0);
        $colCode = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) ($input['col_code'] ?? '')));
        $valueDate = isset($input['value_date']) && trim((string) $input['value_date']) !== '' ? trim((string) $input['value_date']) : null;

        if ($tabella === '' || $aziendaId <= 0 || $rowId <= 0 || $colCode === '') {
            return ['success' => false, 'message' => 'parametri mancanti'];
        }

        // whitelist col_code ammessi (in linea con JS)
        $allowed = [
            'ci',
            'is',
            'cons_dpi',
            'ps',
            'f_generale',
            'r_basso',
            'r_medio',
            'r_alto',
            'preposto',
            'dirigente',
            'ddl',
            'rspp',
            'rls',
            'csp_cse',
            'primo_socc',
            'antincendio',
            'lavori_quota',
            'dpi3cat',
            'amb_conf',
            'pimus',
            'ple'
        ];
        if (!in_array($colCode, $allowed, true)) {
            return ['success' => false, 'message' => 'colonna non consentita'];
        }

        // verifica riga appartenenza
        $own = $database->query("
        SELECT id FROM sic_vfp_operatori WHERE id = ? AND tabella = ? AND azienda_id = ? LIMIT 1
    ", [$rowId, $tabella, $aziendaId], __FILE__)->fetchColumn();
        if (!$own)
            return ['success' => false, 'message' => 'riga non trovata'];

        // normalizza data: accetta YYYY-MM-DD, altrimenti NULL
        if ($valueDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valueDate)) {
            $valueDate = null;
        }

        // UPSERT
        $exists = $database->query("
        SELECT id FROM sic_vfp_date WHERE operatore_id = ? AND col_code = ? LIMIT 1
    ", [$rowId, $colCode], __FILE__)->fetchColumn();

        if ($exists) {
            $ok = $database->query("
            UPDATE sic_vfp_date SET value_date = ? WHERE id = ? LIMIT 1
        ", [$valueDate, (int) $exists], __FILE__);
        } else {
            $ok = $database->query("
            INSERT INTO sic_vfp_date (operatore_id, col_code, value_date) VALUES (?, ?, ?)
        ", [$rowId, $colCode, $valueDate], __FILE__);
        }

        return $ok ? ['success' => true] : ['success' => false, 'message' => 'save fallito'];
    }

    /** Aggiunge una riga operatore */
    public static function addVfpOperatore(array $input): array
    {
        global $database;

        self::ensureSicVfpTables();

        $tabella = self::sanitizeTabellaForCommessa($input['tabella'] ?? '');
        $aziendaId = (int) ($input['azienda_id'] ?? 0);

        if ($tabella === '' || $aziendaId <= 0) {
            return ['success' => false, 'message' => 'parametri mancanti'];
        }

        $ok = $database->query("
        INSERT INTO sic_vfp_operatori (tabella, azienda_id) VALUES (?, ?)
    ", [$tabella, $aziendaId], __FILE__);

        if (!$ok)
            return ['success' => false, 'message' => 'insert fallito'];

        $id = (int) $database->lastInsertId();
        return ['success' => true, 'row' => ['id' => $id]];
    }

    /** Elimina una riga operatore (e cascade le date) */
    public static function deleteVfpOperatore(array $input): array
    {
        global $database;

        self::ensureSicVfpTables();

        $tabella = self::sanitizeTabellaForCommessa($input['tabella'] ?? '');
        $aziendaId = (int) ($input['azienda_id'] ?? 0);
        $rowId = (int) ($input['row_id'] ?? 0);

        if ($tabella === '' || $aziendaId <= 0 || $rowId <= 0) {
            return ['success' => false, 'message' => 'parametri mancanti'];
        }

        // vincolo appartenenza
        $own = $database->query("
        SELECT id FROM sic_vfp_operatori WHERE id = ? AND tabella = ? AND azienda_id = ? LIMIT 1
        ", [$rowId, $tabella, $aziendaId], __FILE__)->fetchColumn();
        if (!$own)
            return ['success' => false, 'message' => 'riga non trovata'];

        $ok = $database->query("DELETE FROM sic_vfp_operatori WHERE id = ? LIMIT 1", [$rowId], __FILE__);
        return $ok ? ['success' => true] : ['success' => false, 'message' => 'delete fallito'];
    }

    /**
     * Salva il comprovante/certificato dalla pagina chiusura commessa
     */
    public static function saveComprovante(array $input): array
    {
        global $database;
        $pdo = $database->connection;

        try {
            // Sanitizza codice_commessa
            $codiceCommessa = isset($input['tabella']) ? preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim((string) $input['tabella']))) : '';
            if (empty($codiceCommessa)) {
                return ['success' => false, 'error' => 'Codice commessa mancante o non valido'];
            }

            // Estrai dati header
            $protocolloNumero = isset($input['protocollo_numero']) ? trim((string) $input['protocollo_numero']) : null;
            $luogoDataLettera = isset($input['luogo_data_lettera']) ? trim((string) $input['luogo_data_lettera']) : null;
            $committente = isset($input['committente']) ? trim((string) $input['committente']) : null;

            // Costruisci JSON completo
            $comprovanteJson = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['success' => false, 'error' => 'JSON non valido: ' . json_last_error_msg()];
            }

            // Verifica/crea progetto
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_progetti WHERE codice_commessa = :codice");
            $checkStmt->execute([':codice' => $codiceCommessa]);
            $existing = $checkStmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                // UPDATE
                $updateSql = "UPDATE gar_comprovanti_progetti SET 
                    comprovante_json = :json,
                    protocollo_numero = :protocollo,
                    luogo_data_lettera = :luogo_data,
                    committente = :committente
                    WHERE codice_commessa = :codice";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([
                    ':json' => $comprovanteJson,
                    ':protocollo' => $protocolloNumero,
                    ':luogo_data' => $luogoDataLettera,
                    ':committente' => $committente,
                    ':codice' => $codiceCommessa
                ]);
                $progettoId = (int) $existing['id'];
            } else {
                // INSERT
                $insertSql = "INSERT INTO gar_comprovanti_progetti 
                    (codice_commessa, comprovante_json, protocollo_numero, luogo_data_lettera, committente)
                    VALUES (:codice, :json, :protocollo, :luogo_data, :committente)";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([
                    ':codice' => $codiceCommessa,
                    ':json' => $comprovanteJson,
                    ':protocollo' => $protocolloNumero,
                    ':luogo_data' => $luogoDataLettera,
                    ':committente' => $committente
                ]);
                $progettoId = (int) $pdo->lastInsertId();
            }

            // Risincronizza gar_comprovanti_servizi
            $deleteStmt = $pdo->prepare("DELETE FROM gar_comprovanti_servizi WHERE progetto_id = :progetto_id");
            $deleteStmt->execute([':progetto_id' => $progettoId]);

            // Inserisci righe suddivisione_servizio
            $suddivisioneServizio = $input['suddivisione_servizio'] ?? [];
            if (is_array($suddivisioneServizio) && !empty($suddivisioneServizio)) {
                $insertServizioSql = "INSERT INTO gar_comprovanti_servizi 
                    (progetto_id, societa_nome, categoria_id_opera, percentuale_rtp, servizi_svolti, importo)
                    VALUES (:progetto_id, :societa_nome, :categoria_id_opera, :percentuale_rtp, :servizi_svolti, :importo)";
                $insertServizioStmt = $pdo->prepare($insertServizioSql);

                foreach ($suddivisioneServizio as $servizio) {
                    $societaNome = isset($servizio['societa_nome']) ? trim((string) $servizio['societa_nome']) : null;
                    $categoriaIdOpera = isset($servizio['categoria_id_opera']) ? trim((string) $servizio['categoria_id_opera']) : null;
                    $percentualeRtp = isset($servizio['percentuale_rtp']) && is_numeric($servizio['percentuale_rtp']) ? (float) $servizio['percentuale_rtp'] : null;
                    $serviziSvolti = isset($servizio['servizi_svolti']) ? trim((string) $servizio['servizi_svolti']) : null;
                    $importo = isset($servizio['importo']) && is_numeric($servizio['importo']) ? (float) $servizio['importo'] : null;

                    if ($societaNome && $categoriaIdOpera) {
                        $insertServizioStmt->execute([
                            ':progetto_id' => $progettoId,
                            ':societa_nome' => $societaNome,
                            ':categoria_id_opera' => $categoriaIdOpera,
                            ':percentuale_rtp' => $percentualeRtp,
                            ':servizi_svolti' => $serviziSvolti,
                            ':importo' => $importo
                        ]);
                    }
                }
            }

            return ['success' => true, 'progetto_id' => $progettoId];
        } catch (\Exception $e) {
            error_log("CommesseService::saveComprovante - Errore: " . $e->getMessage());
            return ['success' => false, 'error' => 'Errore salvataggio: ' . $e->getMessage()];
        }
    }

    /**
     * Recupera i dati del comprovante per la pagina chiusura
     */
    public static function getComprovante(array $input): array
    {
        global $database;
        $pdo = $database->connection;

        try {
            // Se abbiamo l'ID, usiamolo direttamente
            $progettoId = isset($input['progetto_id']) ? (int) $input['progetto_id'] : 0;

            if ($progettoId <= 0) {
                // Altrimenti cerchiamo per codice_commessa
                $codiceCommessa = isset($input['tabella']) ? preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim((string) $input['tabella']))) : '';
                if (!empty($codiceCommessa)) {
                    $stmt = $pdo->prepare("SELECT id FROM gar_comprovanti_progetti WHERE codice_commessa = :codice LIMIT 1");
                    $stmt->execute([':codice' => $codiceCommessa]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($row) {
                        $progettoId = (int) $row['id'];
                    } else {
                        // FALLBACK: Se non esiste come comprovante, cerchiamo nella tabella commesse generale per pre-popolare
                        $stmtCommessa = $pdo->prepare("SELECT codice, oggetto, cliente, data_inizio_prevista, data_fine_prevista, valore_prodotto FROM elenco_commesse WHERE codice = :codice LIMIT 1");
                        $stmtCommessa->execute([':codice' => $codiceCommessa]);
                        $commessa = $stmtCommessa->fetch(\PDO::FETCH_ASSOC);

                        if ($commessa) {
                            return [
                                'success' => true,
                                'data' => [
                                    'codice_commessa' => $commessa['codice'],
                                    'titolo_progetto' => $commessa['oggetto'],
                                    'committente' => $commessa['cliente'],
                                    'data_inizio_prestazione' => $commessa['data_inizio_prevista'],
                                    'data_fine_prestazione' => $commessa['data_fine_prevista'],
                                    'importo_prestazioni' => (float) $commessa['valore_prodotto'],
                                    'is_new' => true // Flag per indicare che è una nuova pre-popolazione
                                ]
                            ];
                        }
                    }
                }
            }

            if ($progettoId <= 0) {
                return ['success' => true, 'data' => null];
            }

            $fullData = self::getComprovanteFullData($progettoId);
            return ['success' => true, 'data' => $fullData];

        } catch (\Exception $e) {
            error_log("CommesseService::getComprovante - Errore: " . $e->getMessage());
            return ['success' => false, 'error' => 'Errore recupero: ' . $e->getMessage()];
        }
    }

    /**
     * Recupera i dati completi e normalizzati di un comprovante (ViewModel)
     * Utilizzato sia per l'export Word che per la visualizzazione Requisiti
     * 
     * @param int $progettoId ID univoco del progetto in gar_comprovanti_progetti
     * @return array|null I dati normalizzati o null se non trovato
     */
    public static function getComprovanteFullData(int $progettoId): ?array
    {
        global $database;
        $pdo = $database->connection;

        $stmt = $pdo->prepare("SELECT * FROM gar_comprovanti_progetti WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $progettoId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row)
            return null;

        $jsonData = !empty($row['comprovante_json']) ? json_decode($row['comprovante_json'], true) : [];
        if (!is_array($jsonData))
            $jsonData = [];

        // Recupera servizi dal DB (pi?? affidabili per query filtri/join)
        $serviziStmt = $pdo->prepare("SELECT societa_nome, categoria_id_opera, percentuale_rtp, servizi_svolti, importo FROM gar_comprovanti_servizi WHERE progetto_id = :progetto_id ORDER BY id ASC");
        $serviziStmt->execute([':progetto_id' => $progettoId]);
        $serviziDb = $serviziStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Prepara partecipanti (da JSON)
        $partecipanti = $jsonData['partecipanti'] ?? [];
        if (empty($partecipanti) && !empty($row['committente'])) {
            // Fallback minimo se partecipanti vuoti ma DB ha committente
            // (Scenario raro ma coperto per sicurezza)
        }

        // Costruisci ViewModel unificato
        return [
            'id' => (int) $row['id'],
            'codice_commessa' => $row['codice_commessa'],
            'committente' => $row['committente'] ?: ($jsonData['committente'] ?? ''),
            'protocollo_numero' => $row['protocollo_numero'] ?: ($jsonData['protocollo_numero'] ?? null),
            'luogo_data_lettera' => $row['luogo_data_lettera'] ?: ($jsonData['luogo_data_lettera'] ?? null),
            'titolo_progetto' => $jsonData['titolo_progetto'] ?? '',
            'oggetto_contratto' => $jsonData['oggetto_contratto'] ?? '',
            'riferimento_contratto' => $jsonData['riferimento_contratto'] ?? '',
            'indirizzo_committente' => $jsonData['indirizzo_committente'] ?? '',
            'cig' => $jsonData['cig'] ?? '',
            'cup' => $jsonData['cup'] ?? '',
            'rup_nome' => $jsonData['rup_nome'] ?? '',
            'rup_riferimento' => $jsonData['rup_riferimento'] ?? '',
            'importo_prestazioni' => (float) ($jsonData['importo_prestazioni'] ?? 0),
            'importo_lavori_totale' => (float) ($jsonData['importo_lavori_totale'] ?? 0),
            'importo_lavori_esclusi_oneri' => (float) ($jsonData['importo_lavori_esclusi_oneri'] ?? 0),
            'oneri_sicurezza' => (float) ($jsonData['oneri_sicurezza'] ?? 0),
            'data_inizio_prestazione' => $jsonData['data_inizio_prestazione'] ?? null,
            'data_fine_prestazione' => $jsonData['data_fine_prestazione'] ?? null,
            'societa_incaricata' => $jsonData['societa_incaricata'] ?? '',
            'flags' => $jsonData['flags'] ?? [],
            'partecipanti' => $partecipanti,
            'categorie_opera' => $jsonData['categorie_opera'] ?? [],
            'suddivisione_servizio' => $serviziDb, // Usiamo i dati della tabella servizi
            'incarichi' => $jsonData['incarichi'] ?? [],
            'raw_json' => $jsonData
        ];
    }


    /**
     * Genera documento Word comprovante da template PHPWord
     * 
     * @param array $input Dati comprovante (richiesto projetto_id o tabella=codice_commessa)
     * @return array ['success' => bool, 'url' => string]
     */
    public static function exportComprovanteWord(array $input): array
    {
        global $database;
        $pdo = $database->connection;

        try {
            // Cerchiamo l'ID univoco
            $progettoId = isset($input['progetto_id']) ? (int) $input['progetto_id'] : 0;

            if ($progettoId <= 0) {
                // Fallback su codice commessa (vecchia UI o router esistente)
                $codiceCommessaInput = isset($input['tabella']) ? preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim((string) $input['tabella']))) : '';
                if (!empty($codiceCommessaInput)) {
                    $stmt = $pdo->prepare("SELECT id FROM gar_comprovanti_progetti WHERE codice_commessa = :codice LIMIT 1");
                    $stmt->execute([':codice' => $codiceCommessaInput]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($row)
                        $progettoId = (int) $row['id'];
                }
            }

            if ($progettoId <= 0) {
                return ['success' => false, 'error' => 'Identificativo comprovante mancante o non trovato'];
            }

            // RECUPERA DATI NORMALIZZATI (UNICO PUNTO DI VERITÀ)
            $progData = self::getComprovanteFullData($progettoId);
            if (!$progData) {
                return ['success' => false, 'error' => 'Dati comprovante non trovati per ID: ' . $progettoId];
            }

            $codiceCommessa = $progData['codice_commessa'];

            // Base path (progetto root)
            $base = dirname(__DIR__);

            // Include PHPWord
            $phpWordInit = $base . '/IntLibs/phpWord/phpword_init.php';
            if (!file_exists($phpWordInit)) {
                return ['success' => false, 'error' => 'PHPWord non disponibile'];
            }
            require_once $phpWordInit;

            // Percorso template
            $templatePath = $base . '/IntLibs/phpWord/templates/Mod_Comprovante.docx';
            if (!file_exists($templatePath)) {
                return ['success' => false, 'error' => 'Template Word non trovato: Mod_Comprovante.docx'];
            }

            $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

            // Helpers per template
            $normalizeString = function ($val) {
                if ($val === null || $val === '')
                    return '';
                $fixed = fixMojibake((string) $val);
                return htmlspecialchars($fixed, ENT_QUOTES, 'UTF-8');
            };

            $formatImporto = function ($val) {
                if ($val === null || $val === '')
                    return '';
                $num = is_numeric($val) ? (float) $val : 0;
                return number_format($num, 2, ',', '.');
            };

            $formatData = function ($val) {
                if (empty($val))
                    return '';
                try {
                    $dt = new \DateTime($val);
                    return $dt->format('d/m/Y');
                } catch (\Exception $e) {
                    return $val;
                }
            };

            $formatFlag = function ($val) {
                return (!empty($val) && ($val == 1 || $val === true || strtolower($val) === 'si')) ? 'X' : '';
            };

            // Mapping servizi: da key a label leggibile
            $serviziLabelsMap = [
                'fase_sf' => 'Studio di fattibilità (SF)',
                'fase_pp' => 'Progetto Preliminare (PP)',
                'fase_pd' => 'Progetto Definitivo (PD)',
                'fase_pfte' => 'Progetto di fattibilità tecnico ed economica (PFTE)',
                'fase_pe' => 'Progetto Esecutivo (PE)',
                'fase_dl' => 'Direzione Lavori (DL)',
                'fase_dos' => 'Direzione Operativa strutture (DOS)',
                'fase_doi' => 'Direzione Operativa impianti (DOI)',
                'fase_da' => 'Direzione artistica (DA)',
                'fase_csp' => 'Coordinamento Sicurezza in Fase progettazione (CSP)',
                'fase_cse' => 'Coordinamento Sicurezza in Fase esecuzione (CSE)',
                'att_bim' => 'Progettazione in BIM',
                'att_cam_dnsh' => 'Progetto redatto in conformità ai CAM / DNSH',
                'att_antincendio' => 'Progettazione antincendio',
                'att_acustica' => 'Progettazione acustica',
                'att_relazione_geologica' => 'Relazione Geologica'
            ];

            $formatServiziSvolti = function ($serviziSvolti) use ($serviziLabelsMap, $normalizeString) {
                if (empty($serviziSvolti))
                    return '';
                $keys = is_array($serviziSvolti) ? $serviziSvolti : explode(',', (string) $serviziSvolti);
                $labels = [];
                foreach ($keys as $key) {
                    $key = trim($key);
                    if (!empty($key)) {
                        $label = $serviziLabelsMap[$key] ?? $key;
                        $labels[] = $normalizeString($label);
                    }
                }
                return implode(', ', $labels);
            };

            // Anagrafiche helpers
            $getAnagraficaByRagioneSociale = function ($ragioneSociale) use ($pdo) {
                if (empty($ragioneSociale))
                    return null;
                $ragioneSociale = trim((string) $ragioneSociale);
                $stmt = $pdo->prepare("SELECT * FROM anagrafiche WHERE ragionesociale = :ragione LIMIT 1");
                $stmt->execute([':ragione' => $ragioneSociale]);
                return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            };

            $buildSedeLegale = function ($anag) {
                if (!$anag)
                    return '';
                $parts = [];
                if (!empty($anag['indirizzo']))
                    $parts[] = trim((string) $anag['indirizzo']);
                if (!empty($anag['cap']))
                    $parts[] = trim((string) $anag['cap']);
                $citta = !empty($anag['citt']) ? trim((string) $anag['citt']) : (!empty($anag['comune']) ? trim((string) $anag['comune']) : '');
                if (!empty($citta))
                    $parts[] = $citta;
                if (!empty($anag['provincia']))
                    $parts[] = '(' . trim((string) $anag['provincia']) . ')';
                return implode(', ', $parts);
            };

            $getCfPiva = function ($anag) {
                if (!$anag)
                    return '';
                if (!empty($anag['partitaiva']))
                    return 'P.IVA ' . $anag['partitaiva'];
                if (!empty($anag['codicefiscale']))
                    return 'C.F. ' . $anag['codicefiscale'];
                return '';
            };

            // POPOLA PLACEHOLDER
            $templateProcessor->setValue('committente', $normalizeString($progData['committente']));
            $templateProcessor->setValue('indirizzo_committente', $normalizeString($progData['indirizzo_committente']));
            $templateProcessor->setValue('oggetto_contratto', $normalizeString($progData['oggetto_contratto']));
            $templateProcessor->setValue('riferimento_contratto', $normalizeString($progData['riferimento_contratto']));
            $templateProcessor->setValue('rup_nome', $normalizeString($progData['rup_nome']));
            $templateProcessor->setValue('rup_riferimento', $normalizeString($progData['rup_riferimento']));
            $templateProcessor->setValue('data_inizio_prestazione', $formatData($progData['data_inizio_prestazione']));
            $templateProcessor->setValue('data_fine_prestazione', $formatData($progData['data_fine_prestazione']));
            $templateProcessor->setValue('importo_prestazioni', $formatImporto($progData['importo_prestazioni']));
            $templateProcessor->setValue('importo_lavori_esclusi_oneri', $formatImporto($progData['importo_lavori_esclusi_oneri']));
            $templateProcessor->setValue('oneri_sicurezza', $formatImporto($progData['oneri_sicurezza']));
            $templateProcessor->setValue('importo_lavori_totale', $formatImporto($progData['importo_lavori_totale']));
            $templateProcessor->setValue('quota_percentuale', ''); // TODO se necessario

            // Flags
            $flags = $progData['flags'];
            $templateProcessor->setValue('fase_sf', $formatFlag($flags['fase_sf'] ?? null));
            $templateProcessor->setValue('fase_pp', $formatFlag($flags['fase_pp'] ?? null));
            $templateProcessor->setValue('fase_pd', $formatFlag($flags['fase_pd'] ?? null));
            $templateProcessor->setValue('fase_pfte', $formatFlag($flags['fase_pfte'] ?? null));
            $templateProcessor->setValue('fase_pe', $formatFlag($flags['fase_pe'] ?? null));
            $templateProcessor->setValue('fase_dl', $formatFlag($flags['fase_dl'] ?? null));
            $templateProcessor->setValue('fase_dos', $formatFlag($flags['fase_dos'] ?? null));
            $templateProcessor->setValue('fase_doi', $formatFlag($flags['fase_doi'] ?? null));
            $templateProcessor->setValue('fase_da', $formatFlag($flags['fase_da'] ?? null));
            $templateProcessor->setValue('fase_csp', $formatFlag($flags['fase_csp'] ?? null));
            $templateProcessor->setValue('fase_cse', $formatFlag($flags['fase_cse'] ?? null));
            $templateProcessor->setValue('att_bim', $formatFlag($flags['att_bim'] ?? null));
            $templateProcessor->setValue('att_cam_dnsh', $formatFlag($flags['att_cam_dnsh'] ?? null));
            $templateProcessor->setValue('att_antincendio', $formatFlag($flags['att_antincendio'] ?? null));
            $templateProcessor->setValue('att_acustica', $formatFlag($flags['att_acustica'] ?? null));
            $templateProcessor->setValue('att_geo', $formatFlag($flags['att_relazione_geologica'] ?? null));

            // Tabella Partecipanti
            $partecipanti = $progData['partecipanti'];
            if (!empty($partecipanti)) {
                $templateProcessor->cloneRow('societa_incaricata', count($partecipanti));
                foreach ($partecipanti as $idx => $part) {
                    $rowNum = $idx + 1;
                    $anag = $getAnagraficaByRagioneSociale($part['societa_nome']);
                    $templateProcessor->setValue("societa_incaricata#{$rowNum}", $normalizeString($part['societa_nome']));
                    $templateProcessor->setValue("societa_sede_legale#{$rowNum}", $normalizeString($buildSedeLegale($anag)));
                    $templateProcessor->setValue("societa_cf_piva#{$rowNum}", $normalizeString($getCfPiva($anag)));
                    $percString = isset($part['percentuale']) ? number_format($part['percentuale'], 2, ',', '.') . '%' : '';
                    $templateProcessor->setValue("societa_percentuale#{$rowNum}", $percString);
                }
            } else {
                $templateProcessor->cloneRow('societa_incaricata', 1);
                $templateProcessor->setValue('societa_incaricata#1', $normalizeString($progData['societa_incaricata']));
                $templateProcessor->setValue('societa_sede_legale#1', '');
                $templateProcessor->setValue('societa_cf_piva#1', '');
                $templateProcessor->setValue('societa_percentuale#1', '100%');
            }

            // Tabella Incarichi
            $incarichi = $progData['incarichi'];
            if (!empty($incarichi)) {
                $templateProcessor->cloneRow('inc_nome', count($incarichi));
                foreach ($incarichi as $idx => $inc) {
                    $rowNum = $idx + 1;
                    $templateProcessor->setValue("inc_nome#{$rowNum}", $normalizeString($inc['nome']));
                    $templateProcessor->setValue("inc_ruolo#{$rowNum}", $normalizeString($inc['ruolo']));
                    $templateProcessor->setValue("inc_qualita#{$rowNum}", $normalizeString($inc['qualita']));
                    $templateProcessor->setValue("inc_societa#{$rowNum}", $normalizeString($inc['societa']));
                }
            } else {
                $templateProcessor->cloneRow('inc_nome', 1);
                $templateProcessor->setValue('inc_nome#1', '');
                $templateProcessor->setValue('inc_ruolo#1', '');
                $templateProcessor->setValue('inc_qualita#1', '');
                $templateProcessor->setValue('inc_societa#1', '');
            }

            // Tabella Categorie
            $categorie = $progData['categorie_opera'];
            $totCat = array_sum(array_column($categorie, 'importo'));
            if (!empty($categorie)) {
                $templateProcessor->cloneRow('cat_id', count($categorie));
                foreach ($categorie as $idx => $cat) {
                    $rowNum = $idx + 1;
                    $importo = (float) ($cat['importo'] ?? 0);
                    $perc = ($totCat > 0) ? ($importo / $totCat) * 100 : 0;
                    $templateProcessor->setValue("cat_id#{$rowNum}", $normalizeString($cat['categoria_id']));
                    $templateProcessor->setValue("cat_desc#{$rowNum}", $normalizeString($cat['categoria_desc']));
                    $templateProcessor->setValue("cat_importo#{$rowNum}", $formatImporto($importo));
                    $templateProcessor->setValue("quota_percentuale#{$rowNum}", number_format($perc, 2, ',', '.') . '%');
                }
            } else {
                $templateProcessor->cloneRow('cat_id', 1);
                $templateProcessor->setValue('cat_id#1', '');
                $templateProcessor->setValue('cat_desc#1', '');
                $templateProcessor->setValue('cat_importo#1', '');
                $templateProcessor->setValue('quota_percentuale#1', '');
            }

            // Tabella Suddivisione Servizio (Modello A)
            $servizi = $progData['suddivisione_servizio'];
            if (!empty($servizi)) {
                $templateProcessor->cloneRow('sud_societa', count($servizi));
                foreach ($servizi as $idx => $serv) {
                    $rowNum = $idx + 1;
                    $templateProcessor->setValue("sud_societa#{$rowNum}", $normalizeString($serv['societa_nome']));
                    $templateProcessor->setValue("sud_categoria#{$rowNum}", $normalizeString($serv['categoria_id_opera']));
                    $templateProcessor->setValue("sud_importo#{$rowNum}", $formatImporto($serv['importo']));
                    $templateProcessor->setValue("sud_servizi#{$rowNum}", $formatServiziSvolti($serv['servizi_svolti']));

                    // Quota su categoria
                    $catId = $serv['categoria_id_opera'];
                    $importoCat = 0;
                    foreach ($categorie as $c)
                        if ($c['categoria_id'] == $catId)
                            $importoCat = (float) $c['importo'];
                    $percCat = ($importoCat > 0) ? ((float) $serv['importo'] / $importoCat) * 100 : 0;
                    $templateProcessor->setValue("quota_percentuale#{$rowNum}", number_format($percCat, 2, ',', '.') . '%');
                }
            } else {
                $templateProcessor->cloneRow('sud_societa', 1);
                $templateProcessor->setValue('sud_societa#1', '');
                $templateProcessor->setValue('sud_categoria#1', '');
                $templateProcessor->setValue('sud_importo#1', '');
                $templateProcessor->setValue('sud_servizi#1', '');
                $templateProcessor->setValue('quota_percentuale#1', '');
            }

            // Finalizzazione
            $outputDir = $base . '/uploads/commesse/comprovanti';
            if (!is_dir($outputDir))
                mkdir($outputDir, 0775, true);

            $fileName = 'COMPROVANTE_' . $codiceCommessa . '_' . date('Ymd_His') . '.docx';
            $outputPath = $outputDir . '/' . $fileName;

            // Verifica placeholder residui
            $tempPath = $outputDir . '/temp_' . uniqid() . '.docx';
            $templateProcessor->saveAs($tempPath);

            $zip = new \ZipArchive();
            if ($zip->open($tempPath) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if (preg_match_all('/\$\{([^}]+)\}/', $xml, $matches)) {
                    @unlink($tempPath);
                    return ['success' => false, 'error' => 'Placeholder residui: ' . implode(', ', array_unique($matches[1]))];
                }
            }
            rename($tempPath, $outputPath);

            // Calcola URL relativo web
            $docRoot = $_SERVER['DOCUMENT_ROOT'];
            // Normalizza slashes per confronto
            $docRootNormalized = str_replace('\\', '/', $docRoot);
            $outputPathNormalized = str_replace('\\', '/', $outputPath);

            // Rimuovi la document root dal path assoluto per ottenere il path web
            $webPath = str_ireplace($docRootNormalized, '', $outputPathNormalized);

            // Assicura che inizi con /
            if (substr($webPath, 0, 1) !== '/') {
                $webPath = '/' . $webPath;
            }

            // Verifica finale esistenza file
            if (!file_exists($outputPath)) {
                return ['success' => false, 'error' => 'Errore scrittura file su disco'];
            }

            return ['success' => true, 'url' => $webPath];

        } catch (\Exception $e) {
            error_log("CommesseService::exportComprovanteWord - Errore: " . $e->getMessage());
            return ['success' => false, 'error' => 'Errore generazione Word: ' . $e->getMessage()];
        }
    }

    public static function getPersonale(array $input = []): array
    {
        global $database;

        try {
            $sql = "SELECT user_id, Nominativo, Ruolo 
                    FROM personale 
                    WHERE COALESCE(Nominativo, '') <> '' 
                    ORDER BY Nominativo ASC";
            $stmt = $database->query($sql, [], __FILE__);

            $personale = [];
            if ($stmt) {
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $personale[] = [
                        'user_id' => (int) $row['user_id'],
                        'nominativo' => trim((string) $row['Nominativo']),
                        'ruolo' => trim((string) ($row['Ruolo'] ?? ''))
                    ];
                }
            }

            return ['success' => true, 'data' => $personale];
        } catch (\Exception $e) {
            error_log("CommesseService::getPersonale - Errore: " . $e->getMessage());
            return ['success' => false, 'error' => 'Errore caricamento personale: ' . $e->getMessage()];
        }
    }
    public static function searchCommesse(array $input): array
    {
        global $database;
        $q = trim((string) ($input['q'] ?? ''));
        $qLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        try {
            // Unione tra commesse reali e comprovanti già esistenti per l'autocomplete
            $sql = "SELECT DISTINCT codice as value, label, oggetto, cliente FROM (
                        SELECT codice, CONCAT(codice, ' - ', oggetto) as label, oggetto, cliente 
                        FROM elenco_commesse 
                        WHERE codice LIKE ? OR oggetto LIKE ?
                        UNION
                        SELECT codice_commessa as codice, CONCAT(codice_commessa, ' - ', committente) as label, '' as oggetto, committente as cliente
                        FROM gar_comprovanti_progetti
                        WHERE codice_commessa LIKE ? OR committente LIKE ?
                    ) as combined
                    ORDER BY value DESC
                    LIMIT 20";

            $stmt = $database->query($sql, [$qLike, $qLike, $qLike, $qLike], __FILE__);
            $data = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            return ['success' => true, 'data' => $data];
        } catch (\Exception $e) {
            error_log("CommesseService::searchCommesse - Errore: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

