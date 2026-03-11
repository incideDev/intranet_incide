<?php

namespace Services;

use Services\NotificationService;

/**
 * TASK SERVICE GLOBALE
 * 
 * Service centralizzato per tutte le operazioni task.
 * Usa ESCLUSIVAMENTE le tabelle canoniche:
 * - sys_tasks
 * - sys_task_status
 * - sys_task_activity
 * 
 * ARCHITETTURA:
 * - context_type: tipo di entità (es: 'commessa', 'gara', 'crm', 'hr')
 * - context_id: identificatore dell'entità (es: codice commessa, ID gara)
 * 
 * DB usa snake_case (context_type, context_id, parent_id, status_id, deleted_at, created_at)
 * JS usa camelCase (contextType, contextId, parentId, statusId, deletedAt, createdAt)
 * Il service mappa automaticamente tra i due formati.
 */
class TaskService
{
    /**
     * Valida e normalizza il contesto
     * 
     * @param array $context Contesto con contextType/context_id o entity_type/entity_id
     * @return array|null Array normalizzato o null se invalido
     */
    private static function normalizeContext(array $context): ?array
    {
        // Supporta sia camelCase (JS) che snake_case (legacy)
        $contextType = trim((string) ($context['context_type'] ?? $context['contextType'] ?? $context['entity_type'] ?? ''));
        $contextId = trim((string) ($context['context_id'] ?? $context['contextId'] ?? $context['entity_id'] ?? ''));

        if (empty($contextType) || empty($contextId)) {
            return null;
        }

        return [
            'context_type' => $contextType,
            'context_id' => $contextId
        ];
    }

    /**
     * Mappa array da snake_case (DB) a camelCase (JS)
     * 
     * @param array $row Riga DB
     * @return array Riga mappata per JS
     */
    private static function mapDbToJs(array $row): array
    {
        $mapped = [];
        foreach ($row as $key => $value) {
            // Mappa campi noti
            switch ($key) {
                case 'context_type':
                    $mapped['contextType'] = $value;
                    break;
                case 'context_id':
                    $mapped['contextId'] = $value;
                    break;
                case 'parent_id':
                    $mapped['parentId'] = $value;
                    break;
                case 'status_id':
                    $mapped['statusId'] = $value;
                    break;
                case 'assignee_user_id':
                    $mapped['assigneeUserId'] = $value;
                    break;
                case 'due_date':
                    $mapped['dueDate'] = $value;
                    break;
                case 'created_at':
                    $mapped['createdAt'] = $value;
                    break;
                case 'updated_at':
                    $mapped['updatedAt'] = $value;
                    break;
                case 'created_by':
                    $mapped['createdBy'] = $value;
                    break;
                case 'deleted_at':
                    $mapped['deletedAt'] = $value;
                    break;
                default:
                    $mapped[$key] = $value;
            }
        }
        return $mapped;
    }

    /**
     * Mappa array da camelCase (JS) a snake_case (DB)
     * 
     * @param array $data Dati JS
     * @return array Dati mappati per DB
     */
    private static function mapJsToDb(array $data): array
    {
        $mapped = [];
        foreach ($data as $key => $value) {
            // Mappa campi noti
            switch ($key) {
                case 'contextType':
                    $mapped['context_type'] = $value;
                    break;
                case 'contextId':
                    $mapped['context_id'] = $value;
                    break;
                case 'parentId':
                    $mapped['parent_id'] = $value;
                    break;
                case 'statusId':
                    $mapped['status_id'] = $value;
                    break;
                case 'assigneeUserId':
                    $mapped['assignee_user_id'] = $value;
                    break;
                case 'dueDate':
                    $mapped['due_date'] = $value;
                    break;
                case 'createdAt':
                    $mapped['created_at'] = $value;
                    break;
                case 'updatedAt':
                    $mapped['updated_at'] = $value;
                    break;
                case 'createdBy':
                    $mapped['created_by'] = $value;
                    break;
                case 'deletedAt':
                    $mapped['deleted_at'] = $value;
                    break;
                default:
                    $mapped[$key] = $value;
            }
        }
        return $mapped;
    }

    /**
     * Carica board completo (statuses + tasks top-level)
     * 
     * @param array $context Contesto (contextType/contextId o entity_type/entity_id)
     * @return array ['success' => bool, 'statuses' => [], 'tasks' => []]
     */
    public static function loadBoard(array $context): array
    {
        global $database;

        $ctx = self::normalizeContext($context);
        if (!$ctx) {
            return ['success' => false, 'message' => 'Contesto non valido'];
        }

        // Carica stati per questo contesto
        $statusesQuery = $database->query(
            "SELECT id, name, color, position 
             FROM sys_task_status 
             WHERE context_type = ? 
             ORDER BY position ASC",
            [$ctx['context_type']],
            __FILE__
        );

        $statuses = [];
        if ($statusesQuery) {
            foreach ($statusesQuery as $status) {
                $statuses[] = [
                    'id' => (int) $status['id'],
                    'name' => $status['name'],
                    'color' => $status['color'],
                    'position' => (int) $status['position']
                ];
            }
        }

        // Carica task top-level (parent_id IS NULL, non eliminate)
        $tasksQuery = $database->query(
            "SELECT t.*, 
                    p.Nominativo as assignee_nome,
                    c.Nominativo as creator_nome
             FROM sys_tasks t
             LEFT JOIN personale p ON p.user_id = t.assignee_user_id
             LEFT JOIN personale c ON c.user_id = t.created_by
             WHERE t.context_type = ? 
               AND t.context_id = ?
               AND t.parent_id IS NULL
               AND t.deleted_at IS NULL
             ORDER BY t.position ASC, t.created_at DESC",
            [$ctx['context_type'], $ctx['context_id']],
            __FILE__
        );

        // Carica funzione helper per immagini profilo
        require_once(substr(__DIR__, 0, strpos(__DIR__, '/services')) . '/core/functions.php');

        $tasks = [];
        if ($tasksQuery) {
            foreach ($tasksQuery as $row) {
                $task = self::mapDbToJs($row);
                $task['id'] = (int) $task['id'];
                $task['statusId'] = (int) $task['statusId'];
                $task['position'] = isset($task['position']) ? floatval($task['position']) : 0;
                $task['assigneeUserId'] = !empty($task['assigneeUserId']) ? (int) $task['assigneeUserId'] : null;

                // Campi camelCase (per JS)
                $task['assigneeNome'] = $row['assignee_nome'] ?? null;
                $task['creatorNome'] = $row['creator_nome'] ?? null;

                // Campi legacy snake_case (per compatibilità template PHP)
                $task['titolo'] = $task['title'] ?? '';
                $task['stato'] = $task['statusId'] ?? null;
                $task['status_id'] = $task['statusId'] ?? null;
                $task['data_apertura'] = $task['createdAt'] ?? null;
                $task['data_scadenza'] = $task['dueDate'] ?? null;
                $task['creatore_nome'] = $row['creator_nome'] ?? null;
                $task['assegnato_a_nome'] = $row['assignee_nome'] ?? null;

                // Calcola path immagini profilo usando getProfileImage()
                if (!empty($row['creator_nome'])) {
                    $task['img_creatore'] = getProfileImage($row['creator_nome'], 'nominativo');
                } else {
                    $task['img_creatore'] = null;
                }
                if (!empty($row['assignee_nome'])) {
                    $task['img_assegnato'] = getProfileImage($row['assignee_nome'], 'nominativo');
                } else {
                    $task['img_assegnato'] = null;
                }

                $task['specializzazione'] = $row['specializzazione'] ?? null;
                $task['fase_doc'] = $row['fase_doc'] ?? null;
                $task['descrizione_azione'] = $row['descrizione_azione'] ?? null;
                $task['path_allegato'] = $row['path_allegato'] ?? null;
                $task['screenshot'] = $row['screenshot'] ?? null;
                $task['note'] = $row['note'] ?? null;
                $task['data_chiusura'] = $row['data_chiusura'] ?? null;

                // Mappa priority: 0=Bassa, 1=Media, 2=Alta, 3=Critica
                $priorityNum = isset($task['priority']) ? (int) $task['priority'] : 1;
                $priorityMap = [0 => 'Bassa', 1 => 'Media', 2 => 'Alta', 3 => 'Critica'];
                $task['priority'] = $priorityMap[$priorityNum] ?? 'Media';

                $task['subtasks'] = []; // Lazy loaded
                $tasks[] = $task;
            }
        }

        return [
            'success' => true,
            'statuses' => $statuses,
            'tasks' => $tasks
        ];
    }

    /**
     * Carica le subtasks di una task specifica (lazy load)
     * 
     * @param int $parentTaskId ID task padre
     * @return array Array di subtasks
     */
    public static function loadChildren(int $parentTaskId): array
    {
        global $database;

        $tasksQuery = $database->query(
            "SELECT t.*, 
                    p.Nominativo as assignee_nome
             FROM sys_tasks t
             LEFT JOIN personale p ON p.user_id = t.assignee_user_id
             WHERE t.parent_id = ?
               AND t.deleted_at IS NULL
             ORDER BY t.position ASC, t.created_at ASC",
            [$parentTaskId],
            __FILE__
        );

        $subtasks = [];
        if ($tasksQuery) {
            foreach ($tasksQuery as $row) {
                $task = self::mapDbToJs($row);
                $task['id'] = (int) $task['id'];
                $task['statusId'] = (int) $task['statusId'];
                $task['position'] = isset($task['position']) ? floatval($task['position']) : 0;
                $task['assigneeUserId'] = !empty($task['assigneeUserId']) ? (int) $task['assigneeUserId'] : null;
                $task['assigneeNome'] = $row['assignee_nome'] ?? null;
                $subtasks[] = $task;
            }
        }

        return [
            'success' => true,
            'tasks' => $subtasks
        ];
    }

    /**
     * Crea una nuova task
     * 
     * @param array $input Dati task (deve includere contextType/contextId)
     * @return array Risultato operazione
     */
    public static function createTask(array $input): array
    {
        global $database;

        $ctx = self::normalizeContext($input);
        if (!$ctx) {
            return ['success' => false, 'message' => 'Contesto mancante o non valido'];
        }

        // Sanitizzazione input
        $title = strip_tags(trim($input['title'] ?? $input['titolo'] ?? ''));
        $description = strip_tags(trim($input['description'] ?? $input['descrizione'] ?? ''));
        $priority = isset($input['priority']) ? intval($input['priority']) : 1; // 0=bassa, 1=media, 2=alta, 3=critica
        $assigneeUserId = null;
        if (isset($input['assigneeUserId'])) {
            $assigneeUserId = !empty($input['assigneeUserId']) ? intval($input['assigneeUserId']) : null;
        } elseif (isset($input['assignee_user_id'])) {
            $assigneeUserId = !empty($input['assignee_user_id']) ? intval($input['assignee_user_id']) : null;
        } elseif (isset($input['assegnato_a'])) {
            $assigneeUserId = !empty($input['assegnato_a']) ? intval($input['assegnato_a']) : null;
        }
        $dueDate = null;
        if (isset($input['dueDate']) && !empty($input['dueDate'])) {
            $dueDate = trim($input['dueDate']);
        } elseif (isset($input['due_date']) && !empty($input['due_date'])) {
            $dueDate = trim($input['due_date']);
        } elseif (isset($input['deadline']) && !empty($input['deadline'])) {
            $dueDate = trim($input['deadline']);
        } elseif (isset($input['data_scadenza']) && !empty($input['data_scadenza'])) {
            $dueDate = trim($input['data_scadenza']);
        }
        $parentId = null;
        if (isset($input['parentId']) || isset($input['parent_id']) || isset($input['parent_task_id'])) {
            $parentIdValue = $input['parentId'] ?? $input['parent_id'] ?? $input['parent_task_id'] ?? null;
            $parentId = !empty($parentIdValue) ? intval($parentIdValue) : null;
        }
        $position = isset($input['position']) ? floatval($input['position']) : 0;
        $createdBy = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

        if (empty($title)) {
            return ['success' => false, 'message' => 'Titolo obbligatorio'];
        }

        if ($createdBy === 0) {
            return ['success' => false, 'message' => 'Utente non autenticato'];
        }

        // Recupera statusId: se fornito usa quello, altrimenti recupera status default per context_type
        $statusId = null;
        if (isset($input['statusId']) || isset($input['status_id']) || isset($input['stato'])) {
            $statusId = intval($input['statusId'] ?? $input['status_id'] ?? $input['stato'] ?? 1);
        } else {
            // Recupera status default per context_type (primo per position ASC)
            // Verifica se la colonna deleted_at esiste nella tabella
            $columns = $database->query("SHOW COLUMNS FROM sys_task_status LIKE 'deleted_at'", [], __FILE__)->fetch();
            $hasDeletedAt = !empty($columns);

            $query = "SELECT id FROM sys_task_status WHERE context_type = ?";
            if ($hasDeletedAt) {
                $query .= " AND deleted_at IS NULL";
            }
            $query .= " ORDER BY position ASC LIMIT 1";

            $defaultStatus = $database->query(
                $query,
                [$ctx['context_type']],
                __FILE__
            )->fetch();

            if ($defaultStatus && isset($defaultStatus['id'])) {
                $statusId = (int) $defaultStatus['id'];
            } else {
                // Fallback: usa 1 se nessuno status trovato
                $statusId = 1;
            }
        }

        // Se è una subtask, verifica che il parent esista
        if ($parentId) {
            $parentExists = $database->query(
                "SELECT id FROM sys_tasks WHERE id = ? AND deleted_at IS NULL LIMIT 1",
                [$parentId],
                __FILE__
            )->fetchColumn();

            if (!$parentExists) {
                return ['success' => false, 'message' => 'Task padre non trovata'];
            }
        }

        // Campi custom legacy (se presenti)
        $specializzazione = !empty($input['specializzazione']) ? strip_tags(trim($input['specializzazione'])) : null;
        $faseDoc = !empty($input['fase_doc']) ? strip_tags(trim($input['fase_doc'])) : null;
        $descrizioneAzione = !empty($input['descrizione_azione']) ? strip_tags(trim($input['descrizione_azione'])) : null;
        $pathAllegato = !empty($input['path_allegato']) ? strip_tags(trim($input['path_allegato'])) : null;
        $note = !empty($input['note']) ? strip_tags(trim($input['note'])) : null;

        // Gestione upload screenshot (se presente)
        $screenshot = null;
        if (!empty($input['_FILES']) && isset($input['_FILES']['screenshots'])) {
            // Carica funzione helper per upload immagini
            require_once(substr(__DIR__, 0, strpos(__DIR__, '/services')) . '/core/functions.php');

            // Prepara directory upload: uploads/tasks/{context_type}/{context_id}/
            $baseDir = defined('ROOT') ? ROOT : dirname(__DIR__);
            $relativeDir = 'uploads/tasks/' . $ctx['context_type'] . '/' . $ctx['context_id'];
            $uploadDir = rtrim($baseDir, '/\\') . '/' . $relativeDir;

            // Crea directory se non esiste
            if (!is_dir($uploadDir)) {
                if (!is_writable(dirname($uploadDir))) {
                    error_log("[TaskService] ATTENZIONE: Padre " . dirname($uploadDir) . " non scrivibile?");
                }
                @mkdir($uploadDir, 0777, true);
                @chmod($uploadDir, 0777);
            }

            // Gestisci upload usando handleTaskImageUpload
            $uploadedImages = handleTaskImageUpload($input['_FILES']['screenshots'], $uploadDir, $relativeDir);
            if (!empty($uploadedImages)) {
                $screenshot = implode(',', $uploadedImages);
            }
        } elseif (!empty($input['screenshot'])) {
            // Se screenshot è già una stringa (path esistenti), usa quella
            $screenshot = is_array($input['screenshot']) ? implode(',', $input['screenshot']) : trim($input['screenshot']);
        }

        // Gestione mom_item_id (se presente)
        $momItemId = null;
        if (isset($input['momItemId']) || isset($input['mom_item_id'])) {
            $momItemId = intval($input['momItemId'] ?? $input['mom_item_id'] ?? 0);
            if ($momItemId <= 0) {
                $momItemId = null;
            }
        }

        // Inserimento
        $query = "INSERT INTO sys_tasks 
            (context_type, context_id, mom_item_id, parent_id, title, description, status_id, priority, 
             assignee_user_id, due_date, position, created_by,
             specializzazione, fase_doc, descrizione_azione, path_allegato, screenshot, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $ok = $database->query(
            $query,
            [
                $ctx['context_type'],
                $ctx['context_id'],
                $momItemId,
                $parentId,
                $title,
                $description,
                $statusId,
                $priority,
                $assigneeUserId,
                $dueDate,
                $position,
                $createdBy,
                $specializzazione,
                $faseDoc,
                $descrizioneAzione,
                $pathAllegato,
                $screenshot,
                $note
            ],
            __FILE__
        );

        if (!$ok) {
            return ['success' => false, 'message' => 'Errore inserimento task'];
        }

        $taskId = (int) $database->lastInsertId();

        // Notifica assegnatario
        if ($assigneeUserId) {
            $personale = $database->query("SELECT user_id, Nominativo FROM personale WHERE user_id = ? LIMIT 1", [$assigneeUserId], __FILE__)->fetch();
            $autore = $personale['Nominativo'] ?? 'Qualcuno';
            $msg = "$autore ti ha assegnato una task: \"$title\"";
            $link = self::buildTaskLink($ctx['context_type'], $ctx['context_id'], $title);
            NotificationService::inviaNotifica($assigneeUserId, $msg, $link);
        }

        // Audit log
        // Per CREATED: oldStatus=null, newStatus=statusId iniziale, userId=createdBy
        self::logTaskAction($taskId, null, $statusId, $createdBy, 'CREATED', [
            'title' => $title,
            'assignee_user_id' => $assigneeUserId
        ]);

        return [
            'success' => true,
            'task_id' => $taskId,
            'message' => 'Task creata con successo'
        ];
    }

    /**
     * Aggiorna una task esistente
     * 
     * @param array $input Dati aggiornamento
     * @return array Risultato operazione
     */
    public static function updateTask(array $input): array
    {
        global $database;

        $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
        if (!$taskId) {
            return ['success' => false, 'message' => 'ID task mancante'];
        }

        // Snapshot precedente (include screenshot per gestire merge, senza data_chiusura per compatibilità)
        $prev = $database->query(
            "SELECT title, assignee_user_id, created_by, status_id, due_date, screenshot, context_type, context_id
             FROM sys_tasks 
             WHERE id = ? AND deleted_at IS NULL 
             LIMIT 1",
            [$taskId],
            __FILE__
        )->fetch();

        if (!$prev) {
            return ['success' => false, 'message' => 'Task non trovata'];
        }

        // Campi aggiornabili
        $updates = [];
        $values = [];

        $campi = [
            'title' => ['title', 'titolo'],
            'description' => ['description', 'descrizione'],
            'assignee_user_id' => ['assigneeUserId', 'assignee_user_id', 'assegnato_a'],
            'priority' => ['priority'],
            'due_date' => ['dueDate', 'due_date', 'data_scadenza'],
            'specializzazione' => ['specializzazione', 'spec'],
            'fase_doc' => ['faseDoc', 'fase_doc', 'fase-doc'],
            'descrizione_azione' => ['descrizioneAzione', 'descrizione_azione', 'azione'],
            'path_allegato' => ['pathAllegato', 'path_allegato', 'path'],
            'screenshot' => ['screenshot'],
            'note' => ['note']
        ];

        foreach ($campi as $dbField => $jsFields) {
            $val = null;
            foreach ($jsFields as $jsField) {
                if (array_key_exists($jsField, $input)) {
                    $val = $input[$jsField];
                    break;
                }
            }
            // Fallback: cerca anche con nome DB
            if ($val === null && array_key_exists($dbField, $input)) {
                $val = $input[$dbField];
            }

            if ($val !== null) {

                if ($dbField === 'assignee_user_id') {
                    $val = !empty($val) ? intval($val) : null;
                } elseif ($dbField === 'priority') {
                    $val = isset($val) ? intval($val) : null;
                } elseif ($dbField === 'due_date') {
                    $val = !empty($val) ? trim($val) : null;
                } elseif ($dbField === 'screenshot') {
                    // Screenshot può essere array o stringa comma-separated
                    if (is_array($val)) {
                        $val = implode(',', array_filter($val));
                    } else {
                        $val = !empty($val) ? trim($val) : null;
                    }
                } else {
                    $val = !empty($val) ? strip_tags(trim((string) $val)) : null;
                }

                if ($val !== null) {
                    $updates[] = "`$dbField` = ?";
                    $values[] = $val;
                }
            }
        }

        // Gestione upload screenshot (se presente in $_FILES)
        if (!empty($input['_FILES']) && isset($input['_FILES']['screenshots'])) {
            // Carica funzione helper per upload immagini
            require_once(substr(__DIR__, 0, strpos(__DIR__, '/services')) . '/core/functions.php');

            // Usa contesto dalla query $prev già eseguita sopra
            if ($prev && isset($prev['context_type']) && isset($prev['context_id'])) {
                // Prepara directory upload: uploads/tasks/{context_type}/{context_id}/
                $baseDir = defined('ROOT') ? ROOT : dirname(__DIR__);
                $relativeDir = 'uploads/tasks/' . $prev['context_type'] . '/' . $prev['context_id'];
                $uploadDir = rtrim($baseDir, '/\\') . '/' . $relativeDir;

                // Crea directory se non esiste
                if (!is_dir($uploadDir)) {
                    if (!is_writable(dirname($uploadDir))) {
                        error_log("[TaskService] ATTENZIONE: Padre " . dirname($uploadDir) . " non scrivibile?");
                    }
                    @mkdir($uploadDir, 0777, true);
                    @chmod($uploadDir, 0777);
                }

                // Gestisci upload usando handleTaskImageUpload
                $uploadedImages = handleTaskImageUpload($input['_FILES']['screenshots'], $uploadDir, $relativeDir);
                if (!empty($uploadedImages)) {
                    // Se ci sono screenshot esistenti, aggiungi i nuovi
                    $existingScreenshots = $prev['screenshot'] ?? '';
                    $allScreenshots = array_filter(array_merge(
                        $existingScreenshots ? explode(',', $existingScreenshots) : [],
                        $uploadedImages
                    ));
                    $screenshotValue = !empty($allScreenshots) ? implode(',', $allScreenshots) : null;

                    // Rimuovi screenshot dai campi normali (se presente) e aggiungi quello con upload
                    $updates = array_filter($updates, function ($update) {
                        return strpos($update, '`screenshot`') === false;
                    });
                    $values = array_values(array_filter($values, function ($val, $idx) use ($campi, $updates) {
                        // Rimuovi il valore corrispondente a screenshot se presente
                        $fieldIndex = 0;
                        foreach ($campi as $dbField => $jsField) {
                            if ($dbField === 'screenshot') {
                                return $idx !== $fieldIndex;
                            }
                            $fieldIndex++;
                        }
                        return true;
                    }, ARRAY_FILTER_USE_BOTH));

                    $updates[] = "`screenshot` = ?";
                    $values[] = $screenshotValue;
                }
            }
        }

        // Status
        if (isset($input['statusId']) || isset($input['status_id']) || isset($input['stato'])) {
            $statusId = intval($input['statusId'] ?? $input['status_id'] ?? $input['stato'] ?? 1);
            $updates[] = "`status_id` = ?";
            $values[] = $statusId;
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => 'Nessun campo da aggiornare'];
        }

        // Status
        $oldStatus = (int) ($prev['status_id'] ?? 1);
        $newStatus = null;
        if (isset($input['statusId']) || isset($input['status_id']) || isset($input['stato'])) {
            $newStatus = intval($input['statusId'] ?? $input['status_id'] ?? $input['stato'] ?? 1);
            $updates[] = "`status_id` = ?";
            $values[] = $newStatus;
        }

        // Gestione data_chiusura: se lo stato diventa "chiuso", imposta data_chiusura
        if ($newStatus !== null && $newStatus !== $oldStatus) {
            $newStatusInfo = $database->query(
                "SELECT name FROM sys_task_status WHERE id = ? LIMIT 1",
                [$newStatus],
                __FILE__
            )->fetch();

            $newStatusName = $newStatusInfo['name'] ?? '';
            $isChiuso = (stripos($newStatusName, 'chiuso') !== false || stripos($newStatusName, 'closed') !== false);

            if ($isChiuso) {
                // Verifica se la colonna data_chiusura esiste e se è vuota
                try {
                    $checkDataChiusura = $database->query(
                        "SELECT data_chiusura FROM sys_tasks WHERE id = ? LIMIT 1",
                        [$taskId],
                        __FILE__
                    )->fetch();

                    if (empty($checkDataChiusura['data_chiusura'])) {
                        // Stato diventa chiuso e data_chiusura non è impostata: imposta data corrente
                        $updates[] = "`data_chiusura` = ?";
                        $values[] = date('Y-m-d');
                    }
                } catch (\Exception $e) {
                    // Colonna non esiste, ignora
                }
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => 'Nessun campo da aggiornare'];
        }

        $values[] = $taskId;
        $query = "UPDATE sys_tasks SET " . implode(', ', $updates) . " WHERE id = ?";

        $ok = $database->query($query, $values, __FILE__);
        if (!$ok) {
            return ['success' => false, 'message' => 'Errore aggiornamento'];
        }

        // Notifiche
        $attoreId = intval($_SESSION['user_id'] ?? 0);
        $personale = $database->query("SELECT user_id, Nominativo FROM personale WHERE user_id = ? LIMIT 1", [$attoreId], __FILE__)->fetch();
        $attoreNome = $personale['Nominativo'] ?? 'qualcuno';
        $titolo = trim($prev['title'] ?? '');
        $nomeTask = $titolo !== '' ? $titolo : '(senza titolo)';
        $ctx = self::normalizeContext($input);
        if ($ctx) {
            $link = self::buildTaskLink($ctx['context_type'], $ctx['context_id'], $nomeTask);
        } else {
            $link = '';
        }

        // Riassegnazione
        $nuovoAssegnato = isset($input['assigneeUserId']) ? intval($input['assigneeUserId']) : null;
        if ($nuovoAssegnato && $nuovoAssegnato !== (int) ($prev['assignee_user_id'] ?? 0)) {
            NotificationService::inviaNotifica(
                $nuovoAssegnato,
                "$attoreNome ti ha assegnato la task: \"$nomeTask\"",
                $link
            );
        }

        // Cambio stato
        $newStatus = $newStatus ?? $oldStatus;

        if ($oldStatus !== $newStatus && $ctx) {
            $statusName = $database->query(
                "SELECT name FROM sys_task_status WHERE id = ? LIMIT 1",
                [$newStatus],
                __FILE__
            )->fetchColumn();
            $nuovoTxt = $statusName ?: (string) $newStatus;

            $destinatari = [];
            if (!empty($prev['created_by']))
                $destinatari[] = (int) $prev['created_by'];
            if (!empty($nuovoAssegnato))
                $destinatari[] = (int) $nuovoAssegnato;
            elseif (!empty($prev['assignee_user_id']))
                $destinatari[] = (int) $prev['assignee_user_id'];
            $destinatari = array_values(array_unique(array_filter($destinatari, fn($u) => $u && $u !== $attoreId)));

            foreach ($destinatari as $uid) {
                NotificationService::inviaNotifica(
                    $uid,
                    "$attoreNome ha impostato la task \"$nomeTask\" a: $nuovoTxt",
                    $link
                );
            }
        }

        // Audit log
        self::logTaskAction($taskId, $oldStatus, $newStatus, $attoreId, 'UPDATED', [
            'campi_modificati' => array_keys($input)
        ]);

        return ['success' => true, 'message' => 'Task aggiornata'];
    }

    /**
     * Sposta una task (cambio stato o ordinamento)
     * 
     * @param array $input Dati spostamento
     * @return array Risultato operazione
     */
    public static function moveTask(array $input): array
    {
        global $database;

        $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
        $newStatusId = intval($input['statusId'] ?? $input['status_id'] ?? $input['new_status'] ?? $input['stato'] ?? 0);
        $newPosition = isset($input['position']) ? floatval($input['position']) : null;

        if (!$taskId || !$newStatusId) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        // Snapshot precedente (senza data_chiusura per compatibilità se colonna non esiste)
        $prev = $database->query(
            "SELECT status_id, assignee_user_id, created_by, title, context_type, context_id
             FROM sys_tasks 
             WHERE id = ? AND deleted_at IS NULL 
             LIMIT 1",
            [$taskId],
            __FILE__
        )->fetch();

        if (!$prev) {
            return ['success' => false, 'message' => 'Task non trovata'];
        }

        $oldStatus = (int) ($prev['status_id'] ?? 1);

        // Verifica se il nuovo stato è "chiuso" (per aggiornare data_chiusura)
        $newStatusInfo = $database->query(
            "SELECT name FROM sys_task_status WHERE id = ? LIMIT 1",
            [$newStatusId],
            __FILE__
        )->fetch();

        $newStatusName = $newStatusInfo['name'] ?? '';
        $isChiuso = (stripos($newStatusName, 'chiuso') !== false || stripos($newStatusName, 'closed') !== false);

        // Aggiorna stato e position
        $updates = ["`status_id` = ?"];
        $values = [$newStatusId];

        // Se lo stato diventa "chiuso", prova ad aggiornare data_chiusura (se colonna esiste)
        if ($isChiuso) {
            // Verifica se la colonna data_chiusura esiste
            $columnExists = false;
            try {
                $checkColumn = $database->query(
                    "SELECT data_chiusura FROM sys_tasks WHERE id = ? LIMIT 1",
                    [$taskId],
                    __FILE__
                )->fetch();
                $columnExists = true;
                // Se esiste, verifica se è vuota prima di aggiornarla
                if (empty($checkColumn['data_chiusura'])) {
                    $updates[] = "`data_chiusura` = ?";
                    $values[] = date('Y-m-d');
                }
            } catch (\Exception $e) {
                // Colonna non esiste, ignora
                $columnExists = false;
            }
        }

        if ($newPosition !== null) {
            $updates[] = "`position` = ?";
            $values[] = $newPosition;
        }

        $values[] = $taskId;
        $query = "UPDATE sys_tasks SET " . implode(', ', $updates) . " WHERE id = ?";

        $ok = $database->query($query, $values, __FILE__);
        if (!$ok) {
            return ['success' => false, 'message' => 'Errore aggiornamento stato'];
        }

        // Notifica cambio stato
        if ($oldStatus !== $newStatusId) {
            $attoreId = intval($_SESSION['user_id'] ?? 0);
            $personale = $database->query("SELECT user_id, Nominativo FROM personale WHERE user_id = ? LIMIT 1", [$attoreId], __FILE__)->fetch();
            $attoreNome = $personale['Nominativo'] ?? 'qualcuno';

            $statusName = $database->query(
                "SELECT name FROM sys_task_status WHERE id = ? LIMIT 1",
                [$newStatusId],
                __FILE__
            )->fetchColumn();
            $nuovoTxt = $statusName ?: (string) $newStatusId;

            $titolo = trim($prev['title'] ?? '');
            $nomeTask = $titolo !== '' ? $titolo : '(senza titolo)';
            $link = self::buildTaskLink($prev['context_type'], $prev['context_id'], $nomeTask);

            $destinatari = [];
            if (!empty($prev['created_by']))
                $destinatari[] = (int) $prev['created_by'];
            if (!empty($prev['assignee_user_id']))
                $destinatari[] = (int) $prev['assignee_user_id'];
            $destinatari = array_values(array_unique(array_filter($destinatari, fn($u) => $u && $u !== $attoreId)));

            foreach ($destinatari as $uid) {
                NotificationService::inviaNotifica(
                    $uid,
                    "$attoreNome ha impostato la task \"$nomeTask\" a: $nuovoTxt",
                    $link
                );
            }
        }

        // Audit log
        self::logTaskAction($taskId, $oldStatus, $newStatusId, intval($_SESSION['user_id'] ?? 0), 'MOVED', []);

        return ['success' => true, 'message' => 'Task spostata'];
    }

    /**
     * Riassegna una task come subtask (reparent)
     * 
     * @param array $input Dati reparent
     * @return array Risultato operazione
     */
    public static function reparentTask(array $input): array
    {
        global $database;

        $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
        $parentId = isset($input['parentId']) && $input['parentId'] !== null
            ? intval($input['parentId'])
            : null;
        $position = isset($input['position']) ? floatval($input['position']) : null;

        if (!$taskId) {
            return ['success' => false, 'message' => 'ID task mancante'];
        }

        // Verifica esistenza task
        $exists = $database->query(
            "SELECT id FROM sys_tasks WHERE id = ? AND deleted_at IS NULL LIMIT 1",
            [$taskId],
            __FILE__
        )->fetchColumn();

        if (!$exists) {
            return ['success' => false, 'message' => 'Task non trovata'];
        }

        // Verifica parent se fornito
        if ($parentId) {
            // Prevenzione loop: impedire di rendere un task figlio di sé stesso o di un suo discendente
            if ($taskId === $parentId) {
                return ['success' => false, 'message' => 'Una task non può essere figlia di sé stessa'];
            }

            // Verifica che il parent non sia un discendente della task
            $descendants = self::getAllDescendants($taskId);
            if (in_array($parentId, $descendants)) {
                return ['success' => false, 'message' => 'Una task non può essere figlia di un suo discendente'];
            }

            $parentExists = $database->query(
                "SELECT id FROM sys_tasks WHERE id = ? AND deleted_at IS NULL LIMIT 1",
                [$parentId],
                __FILE__
            )->fetchColumn();

            if (!$parentExists) {
                return ['success' => false, 'message' => 'Task padre non trovata'];
            }
        }

        // Aggiorna parent_id e position
        $updates = ["`parent_id` = ?"];
        $values = [$parentId];

        if ($position !== null) {
            $updates[] = "`position` = ?";
            $values[] = $position;
        }

        $values[] = $taskId;
        $query = "UPDATE sys_tasks SET " . implode(', ', $updates) . " WHERE id = ?";

        $ok = $database->query($query, $values, __FILE__);
        if (!$ok) {
            return ['success' => false, 'message' => 'Errore aggiornamento'];
        }

        // Audit log
        self::logTaskAction($taskId, null, null, intval($_SESSION['user_id'] ?? 0), 'REPARENTED', [
            'parent_id' => $parentId
        ]);

        return ['success' => true, 'message' => 'Task riassegnata'];
    }

    /**
     * Elimina una task (soft delete)
     * 
     * @param array $input Dati eliminazione
     * @return array Risultato operazione
     */
    public static function deleteTask(array $input): array
    {
        global $database;

        $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
        if (!$taskId) {
            return ['success' => false, 'message' => 'ID task mancante'];
        }

        // Verifica esistenza e permessi
        $task = $database->query(
            "SELECT title, created_by, assignee_user_id, context_type, context_id 
             FROM sys_tasks 
             WHERE id = ? AND deleted_at IS NULL 
             LIMIT 1",
            [$taskId],
            __FILE__
        )->fetch();

        if (!$task) {
            return ['success' => false, 'message' => 'Task non trovata'];
        }

        $userId = intval($_SESSION['user_id'] ?? 0);
        $isOwner = ($userId === (int) ($task['created_by'] ?? 0));

        // Verifica permessi
        if (!$isOwner) {
            return ['success' => false, 'message' => 'Non autorizzato ad eliminare questa task'];
        }

        // Soft delete
        $ok = $database->query(
            "UPDATE sys_tasks SET deleted_at = NOW() WHERE id = ?",
            [$taskId],
            __FILE__
        );
        if (!$ok) {
            return ['success' => false, 'message' => 'Errore eliminazione'];
        }

        // Notifica eliminazione
        $attoreId = $userId;
        $personale = $database->query("SELECT user_id, Nominativo FROM personale WHERE user_id = ? LIMIT 1", [$attoreId], __FILE__)->fetch();
        $attoreNome = $personale['Nominativo'] ?? 'qualcuno';
        $titolo = trim($task['title'] ?? '');
        $nomeTask = $titolo !== '' ? $titolo : '(senza titolo)';
        $link = self::buildTaskLink($task['context_type'], $task['context_id'], $nomeTask);

        $destinatari = [];
        if (!empty($task['created_by']))
            $destinatari[] = (int) $task['created_by'];
        if (!empty($task['assignee_user_id']))
            $destinatari[] = (int) $task['assignee_user_id'];
        $destinatari = array_values(array_unique(array_filter($destinatari, fn($u) => $u && $u !== $attoreId)));

        foreach ($destinatari as $uid) {
            NotificationService::inviaNotifica(
                $uid,
                "$attoreNome ha eliminato la task: \"$nomeTask\"",
                $link
            );
        }

        // Audit log
        self::logTaskAction($taskId, null, null, $attoreId, 'DELETED', [
            'title' => $titolo
        ]);

        return ['success' => true, 'message' => 'Task eliminata'];
    }

    /**
     * Ottiene i dettagli di una task
     * 
     * @param array $input Dati richiesta
     * @return array Dettagli task
     */
    public static function getTaskDetails(array $input): array
    {
        global $database;

        $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
        if (!$taskId) {
            return ['success' => false, 'message' => 'ID task mancante'];
        }

        $row = $database->query(
            "SELECT t.*, 
                    s.name as status_name,
                    p.Nominativo as assignee_nome,
                    c.Nominativo as creator_nome
             FROM sys_tasks t
             LEFT JOIN sys_task_status s ON s.id = t.status_id
             LEFT JOIN personale p ON p.user_id = t.assignee_user_id
             LEFT JOIN personale c ON c.user_id = t.created_by
             WHERE t.id = ? AND t.deleted_at IS NULL 
             LIMIT 1",
            [$taskId],
            __FILE__
        )->fetch();

        if (!$row) {
            return ['success' => false, 'message' => 'Task non trovata'];
        }

        $task = self::mapDbToJs($row);
        $task['id'] = (int) $task['id'];
        $task['statusId'] = (int) $task['statusId'];
        $task['statusName'] = $row['status_name'] ?? '';
        $task['position'] = isset($task['position']) ? floatval($task['position']) : 0;
        $task['assigneeUserId'] = !empty($task['assigneeUserId']) ? (int) $task['assigneeUserId'] : null;
        $task['assigneeNome'] = $row['assignee_nome'] ?? null;
        $task['creatorNome'] = $row['creator_nome'] ?? null;

        // Campi custom commessa (mappati manualmente perché mapDbToJs potrebbe non gestirli)
        $task['specializzazione'] = $row['specializzazione'] ?? null;
        $task['faseDoc'] = $row['fase_doc'] ?? null;
        $task['descrizioneAzione'] = $row['descrizione_azione'] ?? null;
        $task['pathAllegato'] = $row['path_allegato'] ?? null;
        $task['screenshot'] = $row['screenshot'] ?? null;
        $task['note'] = $row['note'] ?? null;

        // Data chiusura (se presente)
        if (!empty($row['data_chiusura'])) {
            $task['dataChiusura'] = $row['data_chiusura'];
        }

        // Parse checklist_json se presente
        if (!empty($row['checklist_json'])) {
            $checklist = json_decode($row['checklist_json'], true);
            $task['checklist'] = is_array($checklist) ? $checklist : [];
        } else {
            $task['checklist'] = [];
        }

        return [
            'success' => true,
            'data' => $task
        ];
    }

    /**
     * Ottiene i contatori delle subtasks (totali e completate)
     * 
     * @param int $taskId ID task padre
     * @return array Contatori
     */
    public static function getSubtaskCounts(int $taskId): array
    {
        global $database;

        // Conta tutte le subtasks
        $total = (int) $database->query(
            "SELECT COUNT(*) FROM sys_tasks 
             WHERE parent_id = ? AND deleted_at IS NULL",
            [$taskId],
            __FILE__
        )->fetchColumn();

        // Conta subtasks completate (stato "chiuso" o equivalente)
        // Verifica se esiste uno stato "chiuso" per il contesto
        $task = $database->query(
            "SELECT context_type FROM sys_tasks WHERE id = ? LIMIT 1",
            [$taskId],
            __FILE__
        )->fetch();

        $completed = 0;
        if ($task) {
            // Trova ID stato "chiuso" per questo contesto
            $closedStatus = $database->query(
                "SELECT id FROM sys_task_status 
                 WHERE context_type = ? 
                   AND (name LIKE '%chiuso%' OR name LIKE '%CHIUSO%' OR name LIKE '%closed%' OR name LIKE '%CLOSED%')
                 LIMIT 1",
                [$task['context_type']],
                __FILE__
            )->fetchColumn();

            if ($closedStatus) {
                $completed = (int) $database->query(
                    "SELECT COUNT(*) FROM sys_tasks 
                     WHERE parent_id = ? AND status_id = ? AND deleted_at IS NULL",
                    [$taskId, $closedStatus],
                    __FILE__
                )->fetchColumn();
            }
        }

        return [
            'success' => true,
            'total' => $total,
            'completed' => $completed
        ];
    }

    /**
     * Carica l'activity log di una task
     * 
     * @param array $input Dati richiesta
     * @return array Activity log
     */
    public static function loadActivity(array $input): array
    {
        global $database;

        $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
        $limit = isset($input['limit']) ? intval($input['limit']) : 50;

        if (!$taskId) {
            return ['success' => false, 'message' => 'ID task mancante'];
        }

        // LIMIT non può usare placeholder in PDO, deve essere un intero nella query
        $limitInt = max(1, min(100, intval($limit))); // Clamp tra 1 e 100 per sicurezza
        $activities = $database->query(
            "SELECT a.*, p.Nominativo as user_nome
             FROM sys_task_activity a
             LEFT JOIN personale p ON p.user_id = a.created_by
             WHERE a.task_id = ?
             ORDER BY a.created_at DESC
             LIMIT " . $limitInt,
            [$taskId],
            __FILE__
        );

        $result = [];
        if ($activities) {
            foreach ($activities as $row) {
                $payload = !empty($row['payload_json'])
                    ? json_decode($row['payload_json'], true)
                    : [];

                $result[] = [
                    'id' => (int) $row['id'],
                    'type' => $row['type'],
                    'payload' => $payload,
                    'createdAt' => $row['created_at'],
                    'createdBy' => (int) $row['created_by'],
                    'userNome' => $row['user_nome'] ?? null
                ];
            }
        }

        return [
            'success' => true,
            'activities' => $result
        ];
    }

    /**
     * Aggiorna la checklist di una task
     * 
     * @param array $input Dati aggiornamento
     * @return array Risultato operazione
     */
    public static function updateChecklist(array $input): array
    {
        global $database;

        $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
        $checklist = $input['checklist'] ?? [];

        if (!$taskId) {
            return ['success' => false, 'message' => 'ID task mancante'];
        }

        // Verifica esistenza task
        $exists = $database->query(
            "SELECT id FROM sys_tasks WHERE id = ? AND deleted_at IS NULL LIMIT 1",
            [$taskId],
            __FILE__
        )->fetchColumn();

        if (!$exists) {
            return ['success' => false, 'message' => 'Task non trovata'];
        }

        // Valida e normalizza checklist
        if (!is_array($checklist)) {
            $checklist = [];
        }

        // Assicura che ogni item abbia id, text, done, position
        $normalized = [];
        foreach ($checklist as $idx => $item) {
            if (!is_array($item))
                continue;

            $normalized[] = [
                'id' => $item['id'] ?? 'item_' . ($idx + 1) . '_' . time(),
                'text' => trim($item['text'] ?? ''),
                'done' => !empty($item['done']),
                'position' => isset($item['position']) ? floatval($item['position']) : $idx
            ];
        }

        // Ordina per position
        usort($normalized, function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        $checklistJson = !empty($normalized)
            ? json_encode($normalized, JSON_UNESCAPED_UNICODE)
            : null;

        // Aggiorna checklist_json (se colonna esiste)
        try {
            $ok = $database->query(
                "UPDATE sys_tasks SET checklist_json = ? WHERE id = ?",
                [$checklistJson, $taskId],
                __FILE__
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'Errore aggiornamento checklist'];
            }
        } catch (\Exception $e) {
            // Colonna non esiste ancora - non è un errore critico
            error_log("Colonna checklist_json non esiste: " . $e->getMessage());
            return ['success' => false, 'message' => 'Checklist non ancora supportata (colonna mancante)'];
        }

        // Audit log
        self::logTaskAction($taskId, null, null, intval($_SESSION['user_id'] ?? 0), 'CHECKLIST_UPDATED', [
            'items_count' => count($normalized),
            'completed_count' => count(array_filter($normalized, fn($item) => $item['done']))
        ]);

        return [
            'success' => true,
            'message' => 'Checklist aggiornata',
            'checklist' => $normalized
        ];
    }

    /**
     * Ottiene tutti i discendenti di una task (per prevenzione loop)
     * 
     * @param int $taskId ID task
     * @return array Array di ID discendenti
     */
    private static function getAllDescendants(int $taskId): array
    {
        global $database;

        $descendants = [];
        $toCheck = [$taskId];

        while (!empty($toCheck)) {
            $currentId = array_shift($toCheck);

            $children = $database->query(
                "SELECT id FROM sys_tasks 
                 WHERE parent_id = ? AND deleted_at IS NULL",
                [$currentId],
                __FILE__
            );

            if ($children) {
                foreach ($children as $child) {
                    $childId = (int) $child['id'];
                    if (!in_array($childId, $descendants)) {
                        $descendants[] = $childId;
                        $toCheck[] = $childId;
                    }
                }
            }
        }

        return $descendants;
    }

    /**
     * Costruisce il link alla task in base al contesto
     * 
     * @param string $contextType Tipo entità
     * @param string $contextId ID entità
     * @param string $title Titolo task (opzionale)
     * @return string URL
     */
    private static function buildTaskLink(string $contextType, string $contextId, string $title = ''): string
    {
        switch ($contextType) {
            case 'commessa':
                return "index.php?section=commesse&page=commessa&tabella=" . urlencode($contextId) .
                    ($title ? "&titolo=" . urlencode($title) : "");
            case 'gara':
                return "index.php?section=gare&page=gara&id=" . urlencode($contextId);
            case 'crm':
                return "index.php?section=crm&page=contatto&id=" . urlencode($contextId);
            case 'hr':
                return "index.php?section=hr&page=candidato&id=" . urlencode($contextId);
            default:
                return "index.php";
        }
    }

    /**
     * Registra un'azione nel log audit
     * 
     * @param int $taskId ID task
     * @param int|null $oldStatus Stato precedente
     * @param int|null $newStatus Stato nuovo
     * @param int $userId ID utente
     * @param string $action Azione (CREATED, UPDATED, MOVED, DELETED, REPARENTED)
     * @param array $metadata Metadati aggiuntivi
     */
    private static function logTaskAction(
        int $taskId,
        ?int $oldStatus,
        ?int $newStatus,
        int $userId,
        string $action,
        array $metadata = []
    ): void {
        global $database;

        // Verifica se esiste tabella log
        try {
            $database->query(
                "INSERT INTO sys_task_activity 
                 (task_id, type, payload_json, created_by)
                 VALUES (?, ?, ?, ?)",
                [
                    $taskId,
                    $action,
                    json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    $userId
                ],
                __FILE__
            );
        } catch (\Exception $e) {
            // Tabella log non esiste o errore - non bloccare l'operazione
            error_log("Errore audit log: " . $e->getMessage());
        }
    }
}
