<?php
// COSTANTE DI BOOTSTRAP (idempotente)
if (!defined('INTRANET_BOOTSTRAP')) {
    define('INTRANET_BOOTSTRAP', true);
}

// INIZIO BUFFER OUTPUT PER JSON PURO (previene warning/notice nel body)
ob_start();

// BOOTSTRAP CENTRALE
require_once 'core/bootstrap.php';

// ── EARLY-EXIT: stream binario Nextcloud (file/thumb) ──────────────
// Eseguito PRIMA del wrapper JSON per evitare Content-Type: application/json
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['section'] ?? '') === 'nextcloud'
    && in_array($_GET['action'] ?? '', ['file', 'thumb'], true)
) {
    ob_end_clean(); // scarta eventuale output buffer

    // Sessione valida (utente loggato)
    if ($database->LockedTime() > 0 || $Session->logged_in !== true) {
        http_response_code(403);
        exit('Not authenticated');
    }

    require_once __DIR__ . '/services/Nextcloud/NextcloudService.php';
    \Services\Nextcloud\NextcloudService::init($database->connection);

    $ncPath = $_GET['path'] ?? '';
    if (!$ncPath) {
        http_response_code(400);
        exit('Path mancante');
    }

    try {
        if ($_GET['action'] === 'file') {
            \Services\Nextcloud\NextcloudService::streamFile($ncPath);
        } else {
            $w = (int) ($_GET['w'] ?? 400);
            $h = (int) ($_GET['h'] ?? 400);
            \Services\Nextcloud\NextcloudService::streamThumb($ncPath, $w, $h);
        }
    } catch (\Exception $e) {
        http_response_code(500);
        exit('Errore: ' . $e->getMessage());
    }
    exit; // non raggiunge mai il flusso JSON
}
// ── FINE EARLY-EXIT ────────────────────────────────────────────────

// Risposta JSON per API (charset esplicito)
header('Content-Type: application/json; charset=utf-8');

// sendJsonResponse è già disponibile da bootstrap.php

// Bootstrap completato, sessione inizializzata

if (isset($_SERVER['HTTP_REFERER'])) {
    $address = 'https://' . $_SERVER['SERVER_NAME'];
    $addressHttp = 'http://' . $_SERVER['SERVER_NAME'];
    if (
        strpos($_SERVER['HTTP_REFERER'], $address) !== 0
        && strpos($_SERVER['HTTP_REFERER'], $addressHttp) !== 0
    ) {
        sendJsonResponse(['success' => false, 'message' => 'Referer non valido']);
    }
} else {
    sendJsonResponse(['success' => false, 'message' => 'Referer mancante']);
}

if ($database->LockedTime() > 0 || $Session->logged_in !== true) {
    sendJsonResponse(['success' => false, 'message' => 'Utente non autenticato']);
}

require_once __DIR__ . '/core/autoload.php';

use Services\TaskService;

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
error_log("ajax.php - Content-Type: " . $contentType);
error_log("ajax.php - REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A'));
error_log("ajax.php - \$_POST keys: " . json_encode(array_keys($_POST)));
error_log("ajax.php - \$_FILES keys: " . json_encode(array_keys($_FILES)));

if (strpos($contentType, 'application/json') !== false) {
    $inputRaw = file_get_contents('php://input');
    $input = json_decode($inputRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendJsonResponse(['success' => false, 'message' => 'JSON non valido']);
    }
} else {
    // Per FormData (multipart/form-data), usa $_POST direttamente
    // IMPORTANTE: Quando viene inviato FormData, PHP popola automaticamente $_POST e $_FILES
    // A volte $_POST potrebbe non essere ancora popolato (race condition), quindi usiamo anche $_REQUEST come fallback
    $input = $_POST ?? [];

    // Se $_POST è vuoto ma abbiamo FormData, prova $_REQUEST come fallback
    // (PHP popola $_REQUEST con $_POST + $_GET + $_COOKIE)
    if (empty($input) && strpos($contentType, 'multipart/form-data') !== false) {
        $input = $_REQUEST ?? [];
    }

    // Se $_POST è vuoto, potrebbe essere un problema di post_max_size o upload_max_filesize
    // In questo caso, PHP potrebbe aver rifiutato silenziosamente la richiesta
    if (empty($input) && strpos($contentType, 'multipart/form-data') !== false) {
        // Verifica se c'è un problema con i limiti PHP
        $postMaxSize = ini_get('post_max_size');
        $uploadMaxFilesize = ini_get('upload_max_filesize');
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;

        error_log("ajax.php - \$_POST vuoto con FormData. post_max_size: {$postMaxSize}, upload_max_filesize: {$uploadMaxFilesize}, CONTENT_LENGTH: {$contentLength}");

        // Se CONTENT_LENGTH è maggiore di post_max_size, PHP rifiuta silenziosamente
        if ($contentLength > 0) {
            // Calcolo inline per evitare dipendenza da parseSizeHelper (definita in service_router.php)
            $unit = preg_replace('/[^bkmgtpezy]/i', '', $postMaxSize);
            $size = preg_replace('/[^0-9\.]/', '', $postMaxSize);
            $postMaxBytes = $unit ? round($size * pow(1024, stripos('bkmgtpezy', $unit[0]))) : round($size);
            if ($contentLength > $postMaxBytes) {
                error_log("ajax.php - CONTENT_LENGTH ({$contentLength}) supera post_max_size ({$postMaxBytes})");
            }
        }
    }
}

// Leggi section e action dal JSON o FormData
// Con FormData multipli, potrebbe essere necessario leggere direttamente da $_POST
$section = $input['section'] ?? $_POST['section'] ?? $_REQUEST['section'] ?? null;
$action = $input['action'] ?? $_POST['action'] ?? $_REQUEST['action'] ?? null;

// Debug: se mancano i parametri, logga cosa abbiamo ricevuto
if (!$section || !$action) {
    $postMaxSize = ini_get('post_max_size');
    $uploadMaxFilesize = ini_get('upload_max_filesize');
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    // Calcolo inline per evitare dipendenza da parseSizeHelper (definita in service_router.php)
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $postMaxSize);
    $size = preg_replace('/[^0-9\.]/', '', $postMaxSize);
    $postMaxBytes = $unit ? round($size * pow(1024, stripos('bkmgtpezy', $unit[0]))) : round($size);

    error_log('ajax.php - Parametri mancanti. Content-Type: ' . ($contentType ?? 'n/d'));
    error_log('ajax.php - $_POST keys: ' . implode(', ', array_keys($_POST ?? [])));
    error_log('ajax.php - $input keys: ' . implode(', ', array_keys($input ?? [])));
    error_log('ajax.php - CONTENT_LENGTH: ' . $contentLength . ', post_max_size: ' . $postMaxSize . ' (' . $postMaxBytes . ' bytes)');

    $errorMsg = 'Parametri mancanti.';
    if ($contentLength > 0 && $contentLength > $postMaxBytes) {
        $errorMsg = "Dimensione richiesta ({$contentLength} bytes) supera il limite PHP post_max_size ({$postMaxSize}).";
    } elseif (empty($_POST) && strpos($contentType ?? '', 'multipart/form-data') !== false) {
        $errorMsg = 'FormData non parsato correttamente. Verifica i limiti PHP (post_max_size, upload_max_filesize).';
    }

    sendJsonResponse([
        'success' => false,
        'message' => $errorMsg,
        'debug' => [
            'content_type' => $contentType ?? null,
            'content_length' => $contentLength,
            'post_max_size' => $postMaxSize,
            'post_max_bytes' => $postMaxBytes,
            'upload_max_filesize' => $uploadMaxFilesize,
            'post_keys' => array_keys($_POST ?? []),
            'input_keys' => array_keys($input ?? []),
            'request_keys' => array_keys($_REQUEST ?? []),
            'section_from_input' => $input['section'] ?? null,
            'action_from_input' => $input['action'] ?? null,
            'section_from_post' => $_POST['section'] ?? null,
            'action_from_post' => $_POST['action'] ?? null,
            'section_from_request' => $_REQUEST['section'] ?? null,
            'action_from_request' => $_REQUEST['action'] ?? null,
        ]
    ]);
}

// Validazione CSRF Token
$allHeaders = function_exists('getallheaders') ? getallheaders() : [];
$validToken = $_SESSION['CSRFtoken'] ?? '';

// Estrai tutte le varianti
$tXHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $allHeaders['X-Csrf-Token'] ?? null;
$tCsrfHeader = $_SERVER['HTTP_CSRF_TOKEN'] ?? $allHeaders['Csrf-Token'] ?? null;
$tCSRFCapitalHeader = $allHeaders['CSRF-Token'] ?? null;
$tInput = $input['csrf_token'] ?? $_REQUEST['csrf_token'] ?? null;

$providedToken = $tXHeader ?? $tCsrfHeader ?? $tCSRFCapitalHeader ?? $tInput;

if (!$validToken || $providedToken !== $validToken) {
    // Diagnostica temporanea
    $sources = [
        'X-Csrf-Token' => $tXHeader ? substr($tXHeader, 0, 8) . '...' : 'assente',
        'Csrf-Token' => $tCsrfHeader ? substr($tCsrfHeader, 0, 8) . '...' : 'assente',
        'CSRF-Token' => $tCSRFCapitalHeader ? substr($tCSRFCapitalHeader, 0, 8) . '...' : 'assente',
        'Body/Request' => $tInput ? substr($tInput, 0, 8) . '...' : 'assente'
    ];
    error_log("CSRF Mismatch (ajax.php). Sorgenti: " . json_encode($sources));

    sendJsonResponse(['success' => false, 'message' => 'Token CSRF non valido']);
}




// === GESTIONE SEZIONE 'tasks' (MINIMALE) ===

if ($section === 'tasks') {
    switch ($action) {
        case 'loadBoard': {
            $context = [
                'contextType' => $input['contextType'] ?? $input['context_type'] ?? $input['entity_type'] ?? '',
                'contextId' => $input['contextId'] ?? $input['context_id'] ?? $input['entity_id'] ?? ''
            ];
            $result = TaskService::loadBoard($context);
            sendJsonResponse($result);
            break;
        }

        case 'loadChildren': {
            $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
            if (!$taskId) {
                sendJsonResponse(['success' => false, 'message' => 'taskId mancante']);
                break;
            }
            $result = TaskService::loadChildren($taskId);
            sendJsonResponse($result);
            break;
        }

        case 'createTask': {
            // Passa anche $_FILES se presente (per upload screenshot)
            if (!empty($_FILES)) {
                $input['_FILES'] = $_FILES;
            }
            sendJsonResponse(TaskService::createTask($input));
            break;
        }

        case 'updateTask': {
            // Passa anche $_FILES se presente (per upload screenshot)
            if (!empty($_FILES)) {
                $input['_FILES'] = $_FILES;
            }
            sendJsonResponse(TaskService::updateTask($input));
            break;
        }

        case 'moveTask':
            sendJsonResponse(TaskService::moveTask($input));
            break;

        case 'reparentTask':
            sendJsonResponse(TaskService::reparentTask($input));
            break;

        case 'deleteTask':
            sendJsonResponse(TaskService::deleteTask($input));
            break;

        case 'getTaskDetails':
            sendJsonResponse(TaskService::getTaskDetails($input));
            break;

        case 'getSubtaskCounts': {
            $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
            if (!$taskId) {
                sendJsonResponse(['success' => false, 'message' => 'taskId mancante']);
                break;
            }
            sendJsonResponse(TaskService::getSubtaskCounts($taskId));
            break;
        }

        case 'loadActivity': {
            $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
            if (!$taskId) {
                sendJsonResponse(['success' => false, 'message' => 'taskId mancante']);
                break;
            }
            sendJsonResponse(TaskService::loadActivity($input));
            break;
        }

        case 'updateChecklist': {
            sendJsonResponse(TaskService::updateChecklist($input));
            break;
        }

        default:
            sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta in tasks']);
            break;
    }
    exit; // IMPORTANTE: exit dopo gestione tasks
}

// === GESTIONE SEZIONE 'contacts' (Nuova implementazione) ===
if ($section === 'contacts') {
    if ($action === 'getCompanyContacts') {
        $aziendaId = isset($input['azienda_id']) ? (int) $input['azienda_id'] : 0;

        if ($aziendaId > 0) {
            try {
                // Supporta campi opzionali
                $fields = isset($input['fields']) ? $input['fields'] : [];
                // Usa ContactService
                // Assicurati che \Services\ContactService sia caricato o usa FQN
                $data = \Services\ContactService::getCompanyContacts($aziendaId, $fields);
                sendJsonResponse(['success' => true, 'data' => $data]);
            } catch (Exception $e) {
                error_log("Errore in getCompanyContacts: " . $e->getMessage());
                sendJsonResponse(['success' => false, 'message' => 'Errore nel recupero contatti: ' . $e->getMessage()]);
            }
        } else {
            sendJsonResponse(['success' => true, 'data' => []]);
        }
    }
    // Se l'azione non è getCompanyContacts, lascia cadere nel switch o gestisci errore?
    // Per ora exit per sicurezza se match section
    // Ma se ci sono altre action contacts gestite da service_router, non dovremmo fare exit qui?
    // service_router gestisce 'contacts' -> 'getProfileData' ecc.
    // Quindi se action != getCompanyContacts, NON fare exit qui!
    // Solo se gestito.
}

// Per tutte le altre sezioni, delega a service_router.php (flusso esistente)
switch ($section) {
    case 'home':
    case 'sidebar':
    case 'notifiche':
    case 'commesse':
    case 'dashboard_ore':
    case 'gare':
    case 'archivio':
    case 'contact': // Legacy? o alias di contacts?
    case 'contacts': // Aggiunto per permettere fallback su service_router per altre actions
    case 'profile':
    case 'hr':
    case 'user':
    case 'filter':
    case 'office_map':
    case 'role':
    case 'roles':
    case 'requisiti':
    case 'protocollo_email':
    case 'import_manager':
    case 'visibilita_sezioni':
    case 'page_editor':
    case 'forms_data':
    case 'gestione_intranet':
    case 'table':
    case 'tasks': // Tasks è gestito sopra con if, ma se action non matcha?
    case 'mom': // Aggiungo MOM esplicitamente per chiarezza
    case 'nextcloud':
    default:
        // Delega a service_router.php per tutte le altre sezioni
        try {
            require_once __DIR__ . '/service_router.php';
        } catch (\Throwable $e) {
            // Cattura Errori Fatali e Eccezioni
            error_log("CRITICAL ERROR in service_router: " . $e->getMessage());
            sendJsonResponse([
                'success' => false,
                'message' => 'Server Error: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
        exit;
}
