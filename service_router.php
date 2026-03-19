<?php

// Nota: service_router.php viene sempre chiamato da ajax.php che già fa il bootstrap
// Non ridefinire costanti qui per evitare warning "already defined"

// Bootstrap già fatto da ajax.php, procedere con la logica router

// Risposta JSON per API (charset esplicito)
header('Content-Type: application/json; charset=utf-8');

// sendJsonResponse è già disponibile da bootstrap.php (incluso da ajax.php)

// Bootstrap completato, sessione inizializzata

// Se le validazioni sono già state fatte da ajax.php, salta
if (!defined('AJAX_VALIDATIONS_DONE')) {
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
}

require_once __DIR__ . '/core/autoload.php';

use Services\MenuCustomService;
use Services\ChangelogService;
use Services\ArchivioService;
use Services\ContactService;
use Services\ProfileService;
use Services\HrService;
use Services\UserService;
use Services\HomeService;
use Services\FilterService;
use Services\OfficeMapService;
use Services\RoleService;
use Services\NotificationService;
use Services\SidebarService;
use Services\CommesseService;
use Services\GareService;
use Services\ExtractionService;
use Services\RequisitiService;
use Services\ProtocolloEmailService;
use Services\ImportManagerService;
use Services\VisibilitaSezioniService;
use Services\PageEditorService;
use Services\FormsDataService;
use Services\GestioneIntranetService;
use Services\TableService;
use Services\TaskService;
use Services\MomService;
use Services\DocumentManagerService;
use Services\CvService;
use Services\DatasourceService;
use Services\ChecklistService;
use Services\DashboardOreService;
use Services\DashboardEconomicaService;
use Services\CommessaCronoService;
use Services\ElencoDocumentiService;
use Services\ElencoDocumentiPdfService;


$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (defined('APP_DEBUG') && APP_DEBUG) {
    error_log("----------------------------------------------------------------");
    error_log("DIAGNOSTICS START");
    error_log("URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
    error_log("Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A'));
    error_log("Content-Type: " . $contentType);
    error_log("CSRF Header (HTTP_CSRF_TOKEN): " . ($_SERVER['HTTP_CSRF_TOKEN'] ?? 'N/A'));
    error_log("CSRF Header (HTTP_X_CSRF_TOKEN): " . ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? 'N/A'));
    error_log("Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'N/A'));
    error_log("Session ID: " . session_id());
    error_log("Logged in: " . (isset($_SESSION['user_id']) ? 'Yes (User ID: ' . $_SESSION['user_id'] . ')' : 'No'));
    error_log("POST keys: " . json_encode(array_keys($_POST)));
    error_log("FILES keys: " . json_encode(array_keys($_FILES)));
    error_log("----------------------------------------------------------------");
}

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

        error_log("service_router.php - \$_POST vuoto con FormData. post_max_size: {$postMaxSize}, upload_max_filesize: {$uploadMaxFilesize}, CONTENT_LENGTH: {$contentLength}");

        // Se CONTENT_LENGTH è maggiore di post_max_size, PHP rifiuta silenziosamente
        if ($contentLength > 0) {
            $postMaxBytes = parseSizeHelper($postMaxSize);
            if ($contentLength > $postMaxBytes) {
                error_log("service_router.php - CONTENT_LENGTH ({$contentLength}) supera post_max_size ({$postMaxBytes})");
            }
        }
    }
}

// Helper function per convertire "60M" in bytes
function parseSizeHelper($size)
{
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
    $size = preg_replace('/[^0-9\.]/', '', $size);
    if ($unit) {
        return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    }
    return round($size);
}

// Leggi section e action dal JSON o FormData
// Con FormData multipli, potrebbe essere necessario leggere direttamente da $_POST
$section = $input['section'] ?? $_POST['section'] ?? $_REQUEST['section'] ?? null;
$action = $input['action'] ?? $_POST['action'] ?? $_REQUEST['action'] ?? null;

// Gestione legacy archivio/qualita verso nuovo hub document_manager
// Gestione legacy archivio/qualita verso nuovo hub document_manager
if (\Services\DocumentAreaRegistry::isValid($section)) {
    $input['documentArea'] = $section;
    $section = 'document_manager';
}

// Debug: se mancano i parametri, logga cosa abbiamo ricevuto
if (!$section || !$action) {
    $postMaxSize = ini_get('post_max_size');
    $uploadMaxFilesize = ini_get('upload_max_filesize');
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    $postMaxBytes = parseSizeHelper($postMaxSize);

    error_log('service_router.php - Parametri mancanti. Content-Type: ' . ($contentType ?? 'n/d'));
    error_log('service_router.php - $_POST keys: ' . implode(', ', array_keys($_POST ?? [])));
    error_log('service_router.php - $input keys: ' . implode(', ', array_keys($input ?? [])));
    error_log('service_router.php - CONTENT_LENGTH: ' . $contentLength . ', post_max_size: ' . $postMaxSize . ' (' . $postMaxBytes . ' bytes)');

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

// Validazione CSRF Token (solo se non già fatta da ajax.php)
if (!defined('AJAX_VALIDATIONS_DONE')) {
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
        error_log("CSRF Mismatch (service_router.php). Sorgenti: " . json_encode($sources));

        sendJsonResponse(['success' => false, 'message' => 'Token CSRF non valido']);
    }
}


switch ($section) {
    case 'home':
        switch ($action) {
            case 'getHomeData':
                // Endpoint unificato per ottimizzare caricamento home (riduce da 4+ a 1 chiamata)
                sendJsonResponse(HomeService::getHomeData());
                break;

            case 'getAllBirthdays':
                sendJsonResponse(HomeService::getAllBirthdays());
                break;

            case 'getCachedNews':
                sendJsonResponse(HomeService::getCachedNews());
                break;

            case 'getNewsletterIndex':
                sendJsonResponse(HomeService::getNewsletterIndex());
                break;

            case 'deleteNewsletter':
                $title = $input['title'] ?? null;
                if (!$title) {
                    sendJsonResponse(['success' => false, 'message' => 'Titolo mancante']);
                } else {
                    sendJsonResponse(HomeService::deleteNewsletter($title));
                }
                break;

            case 'getFeaturedNewsBySlug':
                $slug = $input['slug'] ?? null;
                sendJsonResponse(HomeService::getFeaturedNewsBySlug($slug));
                break;

            case 'getFeaturedNews':
                sendJsonResponse(HomeService::getFeaturedNews());
                break;

            case 'setFeaturedNews':
                sendJsonResponse(HomeService::setFeaturedNews($input));
                break;

            case 'uploadFeaturedNewsImage':
                sendJsonResponse(HomeService::uploadFeaturedNewsImage());
                break;

            case 'uploadNewsletter':
                sendJsonResponse(HomeService::uploadNewsletter());
                break;

            case 'updateNewsletterIndex':
                sendJsonResponse(HomeService::updateNewsletterIndex($input));
                break;

            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta per home']);
                break;
        }
        exit;

    case 'sidebar':
        switch ($action) {
            case 'getSidebarMenu':
                $sectionName = $input['targetSection'] ?? 'archivio';
                sendJsonResponse(SidebarService::getSidebarMenu($sectionName));
                break;

            // NUOVO: per popolare la prima select
            case 'getSectionsList':
                sendJsonResponse(SidebarService::getSectionsList());
                break;

            // NUOVO: per popolare la seconda select in base alla sezione scelta
            case 'getParentMenus':
                $sectionName = $input['targetSection'] ?? 'archivio';
                sendJsonResponse(SidebarService::getParentMenusForSection($sectionName));
                break;

            // (facoltativo)
            case 'getSidebarStructure':
                $sectionName = $input['targetSection'] ?? 'archivio';
                sendJsonResponse(SidebarService::getSidebarStructure($sectionName));
                break;



            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta in sidebar']);
                break;
        }
        exit;

    case 'document_manager':
        $documentArea = $input['documentArea'] ?? $_POST['documentArea'] ?? $_REQUEST['documentArea'] ?? null;
        if (!\Services\DocumentAreaRegistry::isValid($documentArea)) {
            sendJsonResponse(['success' => false, 'error' => 'Area documentale non valida']);
        }
        $configVars = \Services\DocumentAreaRegistry::getDocumentAreaConfig($documentArea);

        $writeActions = ['createPagina', 'editPagina', 'deletePagina', 'uploadDocumenti', 'uploadThumb', 'addCommento', 'deleteDocumento', 'renameDocumento', 'deleteDocumentiMultipli', 'createMenu', 'createArchivioMenu', 'markMissingDocumento', 'createFolder', 'renameFolder', 'deleteFolder', 'moveDocumenti'];
        $readActions = ['getPagine', 'getPagina', 'getDocumenti', 'getDocumentiCount', 'getCommenti', 'getMenus', 'getArchivioMenus', 'previewWord', 'listFolders'];

        if (in_array($action, $writeActions)) {
            if (!userHasPermission($configVars['permissions']['manage'])) {
                sendJsonResponse(['success' => false, 'error' => 'Nessuna autorizzazione']);
            }
        }
        if (in_array($action, $readActions)) {
            if (!userHasPermission($configVars['permissions']['view'])) {
                sendJsonResponse(['success' => false, 'error' => 'Nessuna autorizzazione']);
            }
        }

        switch ($action) {
            case 'getPagine':
                sendJsonResponse(['success' => true, 'pagine' => DocumentManagerService::getPagine($documentArea)]);
                break;

            case 'getPagina':
                sendJsonResponse(DocumentManagerService::getPagina($documentArea, $input['slug'] ?? ''));
                break;

            case 'createPagina':
                sendJsonResponse(DocumentManagerService::createPagina($documentArea, $input, $_SESSION['user']));
                break;

            case 'editPagina':
                sendJsonResponse(DocumentManagerService::editPagina($documentArea, $input, $_SESSION['user']));
                break;

            case 'deletePagina':
                sendJsonResponse(DocumentManagerService::deletePagina($documentArea, $input['slug'] ?? ''));
                break;

            case 'uploadDocumenti':
                sendJsonResponse(DocumentManagerService::uploadDocumenti($documentArea));
                break;

            case 'uploadThumb':
                sendJsonResponse(DocumentManagerService::uploadThumb($documentArea));
                break;

            case 'getDocumenti':
                $page = isset($input['page']) ? intval($input['page']) : 1;
                $limit = isset($input['limit']) ? intval($input['limit']) : 50;
                $folder = $input['folder'] ?? null;
                sendJsonResponse(DocumentManagerService::getDocumenti($documentArea, ['slug' => $input['slug'] ?? '', 'page' => $page, 'limit' => $limit, 'folder' => $folder]));
                break;

            case 'getDocumentiCount':
                sendJsonResponse(DocumentManagerService::getDocumentiCount($documentArea, $input['slug'] ?? ''));
                break;

            case 'getCommenti':
                sendJsonResponse(DocumentManagerService::getCommenti($documentArea, intval($input['id_documento'] ?? 0)));
                break;

            case 'addCommento':
                sendJsonResponse(DocumentManagerService::addCommento($documentArea, $input, $_SESSION['user']));
                break;

            case 'deleteDocumento':
                sendJsonResponse(DocumentManagerService::deleteDocumento($documentArea, intval($input['id'] ?? 0)));
                break;

            case 'renameDocumento':
                sendJsonResponse(DocumentManagerService::renameDocumento($documentArea, $input));
                break;

            case 'markMissingDocumento':
                sendJsonResponse(DocumentManagerService::markMissingDocumento($documentArea, intval($input['id'] ?? 0)));
                break;

            case 'deleteDocumentiMultipli':
                sendJsonResponse(DocumentManagerService::deleteDocumentiMultipli($documentArea, $input));
                break;

            case 'getMenus':
            case 'getArchivioMenus': // Alias legacy
                sendJsonResponse(DocumentManagerService::getMenus($documentArea));
                break;

            case 'createMenu':
            case 'createArchivioMenu': // Alias legacy
                sendJsonResponse(DocumentManagerService::createMenu($documentArea, $input));
                break;

            case 'previewWord':
                sendJsonResponse(DocumentManagerService::previewWordDocument($documentArea, $input['path'] ?? ''));
                break;

            case 'listFolders':
                sendJsonResponse(DocumentManagerService::listFolders($documentArea, ['slug' => $input['slug'] ?? '']));
                break;

            case 'createFolder':
                sendJsonResponse(DocumentManagerService::createFolder($documentArea, ['slug' => $input['slug'] ?? '', 'folder' => $input['folder'] ?? '']));
                break;

            case 'renameFolder':
                sendJsonResponse(DocumentManagerService::renameFolder($documentArea, ['slug' => $input['slug'] ?? '', 'folder' => $input['folder'] ?? '', 'newName' => $input['newName'] ?? '']));
                break;

            case 'deleteFolder':
                sendJsonResponse(DocumentManagerService::deleteFolder($documentArea, ['slug' => $input['slug'] ?? '', 'folder' => $input['folder'] ?? '']));
                break;

            case 'moveDocumenti':
                sendJsonResponse(DocumentManagerService::moveDocumenti($documentArea, ['slug' => $input['slug'] ?? '', 'ids' => $input['ids'] ?? [], 'destination' => $input['destination'] ?? null]));
                break;

            default:
                sendJsonResponse(['success' => false, 'error' => 'Azione non valida for ' . $documentArea]);
                break;
        }
        break;


    case 'dashboard_ore': {
        // Dashboard Ore - routing API
        switch ($action) {
            case 'getFilterOptions':
                sendJsonResponse(DashboardOreService::getFilterOptions());
                break;
            case 'getKPI':
                sendJsonResponse(DashboardOreService::getKPI($input));
                break;
            case 'getTrend':
                sendJsonResponse(DashboardOreService::getTrend($input));
                break;
            case 'getHeatmap':
                sendJsonResponse(DashboardOreService::getHeatmap($input));
                break;
            case 'getCommesse':
                sendJsonResponse(DashboardOreService::getCommesse($input));
                break;
            case 'getRisorse':
                sendJsonResponse(DashboardOreService::getRisorse($input));
                break;
            case 'getAnomalies':
                sendJsonResponse(DashboardOreService::getAnomalies($input));
                break;
            case 'getResourceDetail':
                sendJsonResponse(DashboardOreService::getResourceDetail($input));
                break;
            case 'sendCertReminder':
                sendJsonResponse(DashboardOreService::sendCertReminder($input));
                break;
            case 'smokeTest':
                sendJsonResponse(DashboardOreService::smokeTest($input));
                break;
            case 'getProjectDailySeries':
                sendJsonResponse(DashboardOreService::getProjectDailySeries($input));
                break;
            case 'exportCSV':
                DashboardOreService::exportCSV($input);
                exit;
            case 'getBusinessUnitData':
                sendJsonResponse(DashboardOreService::getBusinessUnitData($input));
                break;
            case 'getBusinessUnitTrend':
                sendJsonResponse(DashboardOreService::getBusinessUnitTrend($input));
                break;
            case 'getUserDetailData':
                sendJsonResponse(DashboardOreService::getUserDetailData($input));
                break;
            case 'getUserDetailTrend':
                sendJsonResponse(DashboardOreService::getUserDetailTrend($input));
                break;
            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non valida per dashboard_ore']);
                break;
        }
        break;
    }

    case 'dashboard_economica': {
        // Dashboard Economica V2 - routing API
        switch ($action) {
            case 'getFilterOptions':
                sendJsonResponse(DashboardEconomicaService::getFilterOptions());
                break;
            case 'getOverviewKpi':
                sendJsonResponse(DashboardEconomicaService::getOverviewKpi($input));
                break;
            case 'getHoursTrend':
                sendJsonResponse(DashboardEconomicaService::getHoursTrend($input));
                break;
            case 'getProjectsEconomicSummary':
                sendJsonResponse(DashboardEconomicaService::getProjectsEconomicSummary($input));
                break;
            case 'getInstallments':
                sendJsonResponse(DashboardEconomicaService::getInstallments($input));
                break;
            case 'getCostsBreakdown':
                sendJsonResponse(DashboardEconomicaService::getCostsBreakdown($input));
                break;
            case 'getHrEconomicSummary':
                sendJsonResponse(DashboardEconomicaService::getHrEconomicSummary($input));
                break;
            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non valida per dashboard_economica']);
                break;
        }
        break;
    }

    case 'commesse': {
        $canView = function (): bool {
            if (!function_exists('userHasPermission')) {
                error_log('[SECURITY] userHasPermission() non disponibile – accesso negato (commesse)');
                return false;
            }
            return userHasPermission('view_commesse');
        };

        switch ($action) {
            case 'createTask':
                sendJsonResponse(CommesseService::createTask($input));
                break;

            case 'getTasks': {
                $tabella = (string) ($input['tabella'] ?? '');
                sendJsonResponse(['success' => true, 'tasks' => CommesseService::getTasks($tabella)]);
                break;
            }

            case 'deleteTask':
                sendJsonResponse(CommesseService::deleteTask($input));
                break;

            case 'createSubtask':
                sendJsonResponse(CommesseService::createSubtask($input));
                break;

            case 'createSubtasksFromFormTabs':
                sendJsonResponse(CommesseService::createSubtasksFromFormTabs($input));
                break;

            case 'updateSubtask':
                sendJsonResponse(CommesseService::updateSubtask($input));
                break;

            case 'deleteSubtask':
                sendJsonResponse(CommesseService::deleteSubtask($input));
                break;

            case 'getResponsabiliPerTask': {
                $taskId = (int) ($input['task_id'] ?? 0);
                sendJsonResponse(CommesseService::getResponsabiliPerTask($taskId));
                break;
            }

            case 'getScadenzeCommesse': {
                $user_id = $_SESSION['user_id'] ?? null;
                sendJsonResponse(CommesseService::getScadenzeCommesse($user_id));
                break;
            }

            case 'updateAssegnatoA':
                sendJsonResponse(CommesseService::updateAssegnatoA($input));
                break;

            case 'updateTaskStatus':
                sendJsonResponse(CommesseService::updateTaskStatus($input));
                break;

            case 'getTaskDetails':
                sendJsonResponse(CommesseService::getTaskDetails($input));
                break;

            case 'saveTaskDetails':
                sendJsonResponse(CommesseService::saveTaskDetails($input));
                break;

            case 'createTabella': {
                $nome = (string) ($input['tabella'] ?? '');
                sendJsonResponse(CommesseService::createTabella($nome, $input));
                break;
            }

            case 'deleteBacheca': {
                $id = (int) ($input['id'] ?? 0);
                sendJsonResponse(CommesseService::deleteBacheca($id));
                break;
            }

            case 'getAnagrafica': {
                $bachecaId = (int) ($input['bacheca_id'] ?? 0);
                $stmt = $database->query("SELECT * FROM commesse_anagrafica WHERE bacheca_id = ?", [$bachecaId], __FILE__);
                sendJsonResponse(['success' => true, 'data' => $stmt->fetch(\PDO::FETCH_ASSOC)]);
                break;
            }

            case 'saveAnagrafica':
                sendJsonResponse(CommesseService::saveAnagrafica($input));
                break;

            case 'avviaCommessa': {
                $codice = $input['codice_commessa'] ?? null;
                sendJsonResponse(CommesseService::avviaCommessa($codice));
                break;
            }

            case 'saveMembri':
                sendJsonResponse(CommesseService::saveMembri($input));
                break;

            case 'getMembri':
                sendJsonResponse(CommesseService::getMembri($input));
                break;

            case 'getOpereDm50':
                sendJsonResponse(CommesseService::getOpereDm50());
                break;

            case 'saveComprovante':
                sendJsonResponse(CommesseService::saveComprovante($input));
                break;

            case 'getComprovante':
                sendJsonResponse(CommesseService::getComprovante($input));
                break;

            case 'exportComprovanteWord':
                sendJsonResponse(CommesseService::exportComprovanteWord($input));
                break;

            case 'getPersonale':
                sendJsonResponse(CommesseService::getPersonale($input));
                break;

            case 'saveOrganigrammaTree':
                sendJsonResponse(CommesseService::saveOrganigrammaTree($input));
                break;

            case 'getPartecipanti': {
                $tabella = (string) ($input['tabella'] ?? '');
                sendJsonResponse(CommesseService::getPartecipanti($tabella));
                break;
            }

            /* ===== Sezioni commessa (cantiere/sicurezza) ===== */
            case 'getEnabledSections': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                $sections = CommesseService::getEnabledSections($tabella);
                sendJsonResponse(['success' => true, 'sections' => $sections]);
                break;
            }

            case 'setSectionEnabled': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }

                // sanitize
                $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                $sectionKey = isset($input['sectionKey']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['sectionKey']) : '';
                $enabled = isset($input['enabled']) ? (int) !!$input['enabled'] : 0;

                if ($tabella === '' || $sectionKey === '') {
                    sendJsonResponse(['success' => false, 'message' => 'parametri mancanti']);
                    break;
                }

                $res = \Services\CommesseService::setSectionEnabled($tabella, $sectionKey, $enabled);
                sendJsonResponse($res);
                break;
            }

            /* ===== Organigramma Imprese ===== */
            case 'getOrganigrammaImprese': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                $data = CommesseService::getOrganigrammaImprese($tabella);
                sendJsonResponse(['success' => true, 'data' => $data]);
                break;
            }

            case 'saveOrganigrammaImprese': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                $data = isset($input['data']) && is_array($input['data']) ? $input['data'] : [];
                $res = CommesseService::saveOrganigrammaImprese($tabella, $data);
                sendJsonResponse($res);
                break;
            }

            case 'getImpreseAnagrafiche': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    $q = isset($input['q']) ? trim((string) $input['q']) : '';
                    $items = CommesseService::getImpreseAnagrafiche($q, 200);
                    sendJsonResponse(['success' => true, 'items' => $items]);
                } catch (\Throwable $e) {
                    // Risposta SEMPRE JSON
                    sendJsonResponse([
                        'success' => false,
                        'message' => 'errore interno getImpreseAnagrafiche',
                        'code' => 500
                    ]);
                }
                break;
            }

            /* ===== Organigramma Cantiere ===== */
            case 'getOrganigrammaCantiere': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                    $data = \Services\CommesseService::getOrganigrammaCantiere($tabella);
                    sendJsonResponse(['success' => true, 'data' => $data]);
                } catch (\Throwable $e) {
                    sendJsonResponse(['success' => false, 'message' => 'errore interno getOrganigrammaCantiere', 'code' => 500]);
                }
                break;
            }

            case 'saveOrganigrammaCantiere': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                    $data = isset($input['data']) && is_array($input['data']) ? $input['data'] : [];
                    $res = \Services\CommesseService::saveOrganigrammaCantiere($tabella, $data);
                    sendJsonResponse($res);
                } catch (\Throwable $e) {
                    sendJsonResponse(['success' => false, 'message' => 'errore interno saveOrganigrammaCantiere', 'code' => 500]);
                }
                break;
            }

            /* ===== Documenti Sicurezza: MODULI COMPILABILI (VRTP, VVCS, VCS, VPOS) ===== */
            case 'listSicurezzaForms': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                    $tipo = isset($input['tipo']) ? preg_replace('/[^A-Z0-9_]/', '', (string) $input['tipo']) : '';
                    $q = isset($input['q']) ? trim((string) $input['q']) : '';
                    $out = \Services\CommesseService::listSicurezzaForms($tabella, $tipo ?: null, $q);
                    sendJsonResponse(['success' => true, 'items' => $out]);
                } catch (\Throwable $e) {
                    sendJsonResponse(['success' => false, 'message' => 'errore interno listSicurezzaForms', 'code' => 500]);
                }
                break;
            }

            case 'getSicurezzaForm': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    $id = isset($input['id']) ? (int) $input['id'] : 0;
                    $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                    $form = \Services\CommesseService::getSicurezzaForm($id, $tabella);
                    sendJsonResponse($form);
                } catch (\Throwable $e) {
                    sendJsonResponse(['success' => false, 'message' => 'errore interno getSicurezzaForm', 'code' => 500]);
                }
                break;
            }

            case 'saveSicurezzaForm': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    $res = \Services\CommesseService::saveSicurezzaForm($input);
                    sendJsonResponse($res);
                } catch (\Throwable $e) {
                    sendJsonResponse(['success' => false, 'message' => 'errore interno saveSicurezzaForm', 'code' => 500]);
                }
                break;
            }

            case 'deleteSicurezzaForm': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    $res = \Services\CommesseService::deleteSicurezzaForm($input);
                    sendJsonResponse($res);
                } catch (\Throwable $e) {
                    sendJsonResponse(['success' => false, 'message' => 'errore interno deleteSicurezzaForm', 'code' => 500]);
                }
                break;
            }

            /* ====== VFP (Verifica Formazione Personale) ====== */
            case 'getVfpFormazione': {
                $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                $azienda_id = isset($input['azienda_id']) ? (int) $input['azienda_id'] : 0;
                $res = \Services\CommesseService::getVfpFormazione(['tabella' => $tabella, 'azienda_id' => $azienda_id]);
                sendJsonResponse($res);
                break;
            }

            case 'saveRowField': {
                // salva campo testuale (cognome/nome/posizione/unilav)
                $res = \Services\CommesseService::saveRowField($input);
                sendJsonResponse($res);
                break;
            }

            case 'saveVfpCell': {
                // salva cella data (key/value)
                $res = \Services\CommesseService::saveVfpCell($input);
                sendJsonResponse($res);
                break;
            }

            case 'addVfpOperatore': {
                $res = \Services\CommesseService::addVfpOperatore($input);
                sendJsonResponse($res);
                break;
            }

            case 'deleteVfpOperatore': {
                $res = \Services\CommesseService::deleteVfpOperatore($input);
                sendJsonResponse($res);
                break;
            }

            /* ====== IMPOSTAZIONI SICUREZZA (tabelle inline) ====== */
            case 'listSettings': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    // Sanificazione/whitelist del tipo
                    $type = isset($input['type']) ? strtolower(trim((string) $input['type'])) : '';
                    $allowed = ['sic_docs', 'tipi_documento', 'ruoli', 'tipi_impresa'];
                    if (!in_array($type, $allowed, true)) {
                        sendJsonResponse(['success' => false, 'error' => 'Tipo non valido']);
                        break;
                    }
                    $safe = $input;
                    $safe['type'] = $type;

                    $res = \Services\CommesseService::listSettings($safe);
                    sendJsonResponse($res);
                } catch (\Throwable $e) {
                    sendJsonResponse(['success' => false, 'error' => 'errore interno listSettings', 'code' => 500]);
                }
                break;
            }

            case 'saveSetting': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    // Sanificazione/whitelist del tipo
                    $type = isset($input['type']) ? strtolower(trim((string) $input['type'])) : '';
                    $allowed = ['sic_docs', 'tipi_documento', 'ruoli', 'tipi_impresa'];
                    if (!in_array($type, $allowed, true)) {
                        sendJsonResponse(['success' => false, 'error' => 'Tipo non valido']);
                        break;
                    }
                    // Normalizza anche id se presente (server comunque ricontrolla)
                    if (isset($input['id'])) {
                        $input['id'] = (int) $input['id'];
                    }
                    // sort_order/attivo possono arrivare come stringhe
                    if (isset($input['sort_order']))
                        $input['sort_order'] = (int) $input['sort_order'];
                    if (isset($input['attivo']))
                        $input['attivo'] = (int) !!$input['attivo'];

                    $safe = $input;
                    $safe['type'] = $type;

                    $res = \Services\CommesseService::saveSetting($safe);
                    sendJsonResponse($res);
                } catch (\Throwable $e) {
                    sendJsonResponse(['success' => false, 'error' => 'errore interno saveSetting', 'code' => 500]);
                }
                break;
            }

            case 'deleteSetting': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }
                try {
                    // Sanificazione/whitelist del tipo + id
                    $type = isset($input['type']) ? strtolower(trim((string) $input['type'])) : '';
                    $allowed = ['sic_docs', 'tipi_documento', 'ruoli', 'tipi_impresa'];
                    if (!in_array($type, $allowed, true)) {
                        sendJsonResponse(['success' => false, 'error' => 'Tipo non valido']);
                        break;
                    }
                    $id = isset($input['id']) ? (int) $input['id'] : 0;
                    if ($id <= 0) {
                        sendJsonResponse(['success' => false, 'error' => 'ID non valido']);
                        break;
                    }

                    $safe = $input;
                    $safe['type'] = $type;
                    $safe['id'] = $id;

                    $res = \Services\CommesseService::deleteSetting($safe);
                    sendJsonResponse($res);
                } catch (\Throwable $e) {
                    sendJsonResponse(['success' => false, 'error' => 'errore interno deleteSetting', 'code' => 500]);
                }
                break;
            }

            case 'getImpresaDettagli': {
                $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                $aziendaId = isset($input['azienda_id']) ? (int) $input['azienda_id'] : 0;

                if ($tabella === '' || $aziendaId <= 0) {
                    sendJsonResponse(['success' => false, 'error' => 'Parametri non validi']);
                    return;
                }

                $res = \Services\CommesseService::getImpresaDettagli($tabella, $aziendaId); // <<-- QUI il nuovo nome
                if (!is_array($res))
                    $res = ['success' => false, 'error' => 'Dati non disponibili'];

                header('Content-Type: application/json; charset=utf-8');
                sendJsonResponse($res, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                return;
            }

            case 'listImpresePerControlli': {
                $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                header('Content-Type: application/json; charset=utf-8');
                sendJsonResponse(\Services\CommesseService::listImpresePerControlli(['tabella' => $tabella]), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            // === Documenti Sicurezza: LIST
            case 'listDocumentiSicurezza': {
                header('Content-Type: application/json; charset=utf-8');
                $tabella = isset($input['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', (string) $input['tabella']) : '';
                $azienda_id = isset($input['azienda_id']) ? (int) $input['azienda_id'] : 0;   // 0 = workspace
                $tipo = isset($input['tipo']) ? preg_replace('/[^A-Z0-9_]/', '', (string) $input['tipo']) : null;
                $items = \Services\CommesseService::listDocumentiSicurezza($tabella, $azienda_id, $tipo);
                sendJsonResponse(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            // === Documenti Sicurezza: UPLOAD (con azienda)
            case 'uploadDocumentoSicurezza': {
                header('Content-Type: application/json; charset=utf-8');
                // NB: il check CSRF è già stato fatto all'inizio del router.
                // Con multipart/form-data i dati arrivano in $_POST + $_FILES.
                $out = \Services\CommesseService::uploadDocumentoSicurezza($_POST, $_FILES);
                sendJsonResponse($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            // === Documenti Sicurezza: DELETE
            case 'deleteDocumentoSicurezza': {
                header('Content-Type: application/json; charset=utf-8');
                // Con fetch JSON i dati arrivano nel body
                $raw = file_get_contents('php://input');
                $j = json_decode($raw, true) ?: [];
                $out = \Services\CommesseService::deleteDocumentoSicurezza($j);
                sendJsonResponse($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            // === Documenti Sicurezza: SET SCADENZA
            case 'setScadenzaDocumentoSicurezza': {
                header('Content-Type: application/json; charset=utf-8');
                $raw = file_get_contents('php://input');
                $j = json_decode($raw, true) ?: [];
                $out = \Services\CommesseService::setScadenzaDocumentoSicurezza($j);
                sendJsonResponse($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            /* ===== Documenti Sicurezza: WORKSPACE (senza azienda_id) ===== */
            case 'uploadDocumentoSicurezzaWs': {
                // NIENTE check CSRF locale.
                $post = $_POST + $_FILES;
                $post['azienda_id'] = 0; // workspace
                $out = \Services\CommesseService::uploadDocumentoSicurezza($post);
                sendJsonResponse($out);
                break;
            }

            case 'deleteDocumentoSicurezzaWs': {
                // NIENTE check CSRF locale.
                $raw = file_get_contents('php://input');
                $j = json_decode($raw, true) ?: [];
                $j['azienda_id'] = 0;
                $out = \Services\CommesseService::deleteDocumentoSicurezza($j);
                sendJsonResponse($out);
                break;
            }

            case 'setScadenzaDocumentoSicurezzaWs': {
                // NIENTE check CSRF locale.
                $raw = file_get_contents('php://input');
                $j = json_decode($raw, true) ?: [];
                $j['azienda_id'] = 0;
                $out = \Services\CommesseService::setScadenzaDocumentoSicurezza($j);
                sendJsonResponse($out);
                break;
            }

            /* ===== Dashboard Commesse Stats ===== */
            case 'getDashboardStats': {
                if (!$canView()) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    break;
                }

                try {
                    // Condizioni normalizzate per stato (case-insensitive, trim)
                    // CHIUSA = TRIM(UPPER(stato)) = 'CHIUSA'
                    // APERTA = stato IS NULL OR TRIM(UPPER(stato)) <> 'CHIUSA'
                    $whereAperta = "(stato IS NULL OR TRIM(UPPER(stato)) <> 'CHIUSA')";
                    $whereChiusa = "TRIM(UPPER(stato)) = 'CHIUSA'";

                    // Mappa user_id => Nominativo per risolvere ID numerici
                    $personaleStmt = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
                    $personaleMap = [];
                    foreach ($personaleStmt as $p) {
                        $personaleMap[(int) $p['user_id']] = $p['Nominativo'];
                    }

                    // Helper per risolvere responsabile (ID numerico → nominativo)
                    $resolveResponsabile = function ($raw) use ($personaleMap) {
                        if ($raw === null || $raw === '') {
                            return '-';
                        }
                        if (is_numeric($raw)) {
                            return $personaleMap[(int) $raw] ?? $raw;
                        }
                        return $raw;
                    };

                    // KPI: conteggi principali
                    $openCount = $database->query(
                        "SELECT COUNT(*) FROM elenco_commesse WHERE $whereAperta",
                        [],
                        __FILE__
                    )->fetchColumn();

                    $closedCount = $database->query(
                        "SELECT COUNT(*) FROM elenco_commesse WHERE $whereChiusa",
                        [],
                        __FILE__
                    )->fetchColumn();

                    $totalCount = $database->query(
                        "SELECT COUNT(*) FROM elenco_commesse",
                        [],
                        __FILE__
                    )->fetchColumn();

                    $pmCount = $database->query(
                        "SELECT COUNT(DISTINCT responsabile_commessa) FROM elenco_commesse
                         WHERE $whereAperta
                         AND responsabile_commessa IS NOT NULL AND responsabile_commessa != ''",
                        [],
                        __FILE__
                    )->fetchColumn();

                    $sectorCount = $database->query(
                        "SELECT COUNT(DISTINCT settore_merceologico) FROM elenco_commesse
                         WHERE $whereAperta
                         AND settore_merceologico IS NOT NULL AND settore_merceologico != ''",
                        [],
                        __FILE__
                    )->fetchColumn();

                    $buCount = $database->query(
                        "SELECT COUNT(DISTINCT business_unit) FROM elenco_commesse
                         WHERE $whereAperta
                         AND business_unit IS NOT NULL AND business_unit != ''",
                        [],
                        __FILE__
                    )->fetchColumn();

                    // byBu: commesse aperte per business unit
                    $byBuStmt = $database->query(
                        "SELECT business_unit AS label, COUNT(*) AS count
                         FROM elenco_commesse
                         WHERE $whereAperta
                         AND business_unit IS NOT NULL AND business_unit != ''
                         GROUP BY business_unit
                         ORDER BY count DESC",
                        [],
                        __FILE__
                    );
                    $byBu = $byBuStmt->fetchAll(\PDO::FETCH_ASSOC);

                    // byPm: commesse aperte per project manager (limit 8)
                    $byPmStmt = $database->query(
                        "SELECT responsabile_commessa AS label, COUNT(*) AS count
                         FROM elenco_commesse
                         WHERE $whereAperta
                         AND responsabile_commessa IS NOT NULL AND responsabile_commessa != ''
                         GROUP BY responsabile_commessa
                         ORDER BY count DESC
                         LIMIT 8",
                        [],
                        __FILE__
                    );
                    $byPmRaw = $byPmStmt->fetchAll(\PDO::FETCH_ASSOC);

                    // Risolvi ID numerici e aggiungi initials + imagePath per ogni PM
                    $byPm = [];
                    foreach ($byPmRaw as $pm) {
                        $label = $resolveResponsabile($pm['label']);
                        $parts = explode(' ', trim($label));
                        if (count($parts) >= 2) {
                            $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
                        } else {
                            $initials = strtoupper(substr($label, 0, 2));
                        }
                        // Aggiungi immagine profilo usando la funzione globale getProfileImage
                        $imagePath = getProfileImage($label, 'nominativo', 'assets/images/default_profile.png');
                        $byPm[] = [
                            'label' => $label,
                            'count' => $pm['count'],
                            'initials' => $initials,
                            'imagePath' => $imagePath
                        ];
                    }

                    // bySector: commesse aperte per settore (limit 10)
                    $bySectorStmt = $database->query(
                        "SELECT settore_merceologico AS label, COUNT(*) AS count
                         FROM elenco_commesse
                         WHERE $whereAperta
                         AND settore_merceologico IS NOT NULL AND settore_merceologico != ''
                         GROUP BY settore_merceologico
                         ORDER BY count DESC
                         LIMIT 10",
                        [],
                        __FILE__
                    );
                    $bySector = $bySectorStmt->fetchAll(\PDO::FETCH_ASSOC);

                    // latest: ultime 5 commesse aperte
                    $latestStmt = $database->query(
                        "SELECT codice, oggetto, cliente, business_unit, responsabile_commessa, data_creazione
                         FROM elenco_commesse
                         WHERE $whereAperta
                         ORDER BY data_creazione DESC
                         LIMIT 5",
                        [],
                        __FILE__
                    );
                    $latestRaw = $latestStmt->fetchAll(\PDO::FETCH_ASSOC);

                    // Formatta i dati per il frontend
                    $latest = [];
                    foreach ($latestRaw as $row) {
                        $apertura = '';
                        if (!empty($row['data_creazione'])) {
                            try {
                                $dt = new \DateTime($row['data_creazione']);
                                $apertura = $dt->format('d/m/Y');
                            } catch (\Exception $e) {
                                $apertura = $row['data_creazione'];
                            }
                        }
                        $latest[] = [
                            'codice' => $row['codice'] ?? '',
                            'titolo' => !empty($row['oggetto']) ? $row['oggetto'] : '-',
                            'cliente' => $row['cliente'] ?? '-',
                            'bu' => $row['business_unit'] ?? '-',
                            'pm' => $resolveResponsabile($row['responsabile_commessa']),
                            'apertura' => $apertura ?: '-'
                        ];
                    }

                    sendJsonResponse([
                        'success' => true,
                        'data' => [
                            'kpi' => [
                                'open' => (int) $openCount,
                                'closed' => (int) $closedCount,
                                'total' => (int) $totalCount,
                                'pmCount' => (int) $pmCount,
                                'sectorCount' => (int) $sectorCount,
                                'buCount' => (int) $buCount
                            ],
                            'byBu' => $byBu,
                            'byPm' => $byPm,
                            'bySector' => $bySector,
                            'latest' => $latest
                        ]
                    ]);
                } catch (\Throwable $e) {
                    error_log('[getDashboardStats] Errore: ' . $e->getMessage());
                    sendJsonResponse(['success' => false, 'message' => 'Errore interno', 'code' => 500]);
                }
                break;
            }

            case 'getPageData': {
                // Endpoint per cronoprogramma commessa
                $idProject = trim($input['id_project'] ?? '');
                if (empty($idProject)) {
                    sendJsonResponse(['success' => false, 'message' => 'ID Progetto mancante']);
                    break;
                }
                sendJsonResponse(CommessaCronoService::getPageData($idProject));
                break;
            }

            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta in commesse']);
                break;
        }
        exit;
    }

    case 'task': {
        // TASK ENGINE GLOBALE
        // Supporta qualsiasi contesto (commesse, gare, crm, hr, ecc)

        switch ($action) {
            case 'createTask':
                sendJsonResponse(TaskService::createTask($input));
                break;

            case 'updateTask':
                sendJsonResponse(TaskService::updateTask($input));
                break;

            case 'moveTask':
                sendJsonResponse(TaskService::moveTask($input));
                break;

            case 'deleteTask':
                sendJsonResponse(TaskService::deleteTask($input));
                break;

            case 'getTaskDetails':
                sendJsonResponse(TaskService::getTaskDetails($input));
                break;

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

            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta in task']);
                break;
        }
        exit;
    }

    case 'elenco_documenti':
        // Elenco Documenti per Commesse - Document list management with submittal tracking
        if ($action === 'generatePdf') {
            // Stream PDF direttamente — non passa per sendJsonResponse
            ElencoDocumentiPdfService::streamPdf($input);
            // streamPdf termina con exit, ma per sicurezza:
            exit;
        }
        $result = ElencoDocumentiService::handleAction($action, $input);
        sendJsonResponse($result);
        break;

    case 'gare':
        header('Content-Type: application/json; charset=utf-8');

        switch ($action) {
            case 'getGare':
                // Controllo permesso: view_gare
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                try {
                    $filtri = is_array($input) ? $input : [];
                    sendJsonResponse(\Services\GareService::listGare($filtri), JSON_UNESCAPED_UNICODE);
                } catch (\Throwable $e) {
                    http_response_code(500);

                    // Debug solo negli header, non nel JSON
                    header('X-Debug-SQL: ' . base64_encode($e->getMessage()));
                    sendJsonResponse([
                        'success' => false,
                        'error' => 'server_error'
                    ]);
                }
                break;

            case 'uploadExtraction':
                // Controllo permesso: create_gare o edit_gare, fallback a view_gare
                if (
                    !function_exists('userHasPermission') ||
                    (!userHasPermission('create_gare') && !userHasPermission('edit_gare') && !userHasPermission('view_gare'))
                ) {
                    http_response_code(403);
                    sendJsonResponse(['ok' => false, 'error' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                try {
                    // Gestisce sia file singolo che multipli
                    // Con file multipli: $_FILES['file']['name'][0], $_FILES['file']['name'][1], ecc.
                    // Con file singolo: $_FILES['file']['name']
                    // Con FormData multipli: potrebbe arrivare come 'file[]'
                    $fileKey = null;
                    if (isset($_FILES['file'])) {
                        $fileKey = 'file';
                    } elseif (isset($_FILES['file[]'])) {
                        $fileKey = 'file[]';
                        // Normalizza 'file[]' in 'file' per compatibilità
                        $_FILES['file'] = $_FILES['file[]'];
                    }

                    if (!$fileKey) {
                        sendJsonResponse(['ok' => false, 'error' => 'file mancante'], JSON_UNESCAPED_UNICODE);
                        break;
                    }

                    // Verifica che ci sia almeno un file valido
                    $hasFile = false;
                    $filesToCheck = $_FILES[$fileKey];
                    if (isset($filesToCheck['name'])) {
                        if (is_array($filesToCheck['name'])) {
                            // File multipli: verifica che almeno uno abbia un nome e non sia vuoto
                            $hasFile = !empty(array_filter($filesToCheck['name'], function ($name) {
                                return !empty($name);
                            }));
                        } else {
                            // File singolo: verifica che abbia un nome
                            $hasFile = !empty($filesToCheck['name']);
                        }
                    }

                    if (!$hasFile) {
                        sendJsonResponse(['ok' => false, 'error' => 'Nessun file valido ricevuto'], JSON_UNESCAPED_UNICODE);
                        break;
                    }

                    // Assicura che $_POST contenga extraction_types (potrebbe arrivare da FormData come JSON string)
                    if (empty($_POST['extraction_types'])) {
                        if (!empty($input['extraction_types'])) {
                            $_POST['extraction_types'] = $input['extraction_types'];
                        }
                    }

                    // Se extraction_types è una stringa JSON, decodificala
                    if (isset($_POST['extraction_types']) && is_string($_POST['extraction_types'])) {
                        $decoded = json_decode($_POST['extraction_types'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $_POST['extraction_types'] = $decoded;
                        }
                    }

                    $result = \Services\ExtractionService::upload($_POST, $_FILES);
                    // Normalizza la risposta: GareService::upload() restituisce ['ok' => true, 'jobs' => [...]]
                    // ma potrebbe anche restituire ['success' => false, 'message' => ...] in caso di errore
                    if (isset($result['success']) && !$result['success']) {
                        // Converti formato ['success' => false, 'message' => ...] in ['ok' => false, 'error' => ...]
                        sendJsonResponse([
                            'ok' => false,
                            'error' => $result['message'] ?? 'Errore sconosciuto'
                        ], JSON_UNESCAPED_UNICODE);
                    } else {
                        sendJsonResponse($result, JSON_UNESCAPED_UNICODE);
                    }
                } catch (\Throwable $e) {
                    http_response_code(400);
                    sendJsonResponse(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'jobPull':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                sendJsonResponse(\Services\ExtractionService::jobPull($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'jobResults':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                sendJsonResponse(\Services\ExtractionService::jobResults($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'jobShow':
                // Controllo permesso: view_gare
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                sendJsonResponse(\Services\ExtractionService::jobShow($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'listJobsByGara':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                // Ora accetta job_id (gara_id = job_id per retrocompatibilità)
                $jobId = (int) ($input['job_id'] ?? $input['gara_id'] ?? 0);
                sendJsonResponse(\Services\ExtractionService::listJobsByGara($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'getEstrazioniGara':
                // Controllo permesso: view_gare
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                // Ora accetta job_id (id_gara = job_id per retrocompatibilità)
                $jobId = (int) ($input['job_id'] ?? $input['id_gara'] ?? $input['gara_id'] ?? 0);
                echo json_encode(\Services\ExtractionService::getEstrazioniGara($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'getNormalizedDocs':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                echo json_encode(\Services\ExtractionService::getNormalizedDocs($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'getNormalizedEcon':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                echo json_encode(\Services\ExtractionService::getNormalizedEcon($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'normalizeGara':
                // Controllo permesso: edit_gare o view_gare
                if (
                    !function_exists('userHasPermission') ||
                    (!userHasPermission('edit_gare') && !userHasPermission('view_gare'))
                ) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                if (!$jobId) {
                    echo json_encode(['success' => false, 'message' => 'job_id mancante'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                echo json_encode(\Services\GareService::normalizeGara($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'getNormalizedRoles':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                echo json_encode(\Services\ExtractionService::getNormalizedRoles($jobId), JSON_UNESCAPED_UNICODE);
                break;

            // Funzioni rimosse: consumeDraft, stashDraft (non più utilizzate)

            case 'updateParticipation':
                // Controllo permesso: edit_gare o view_gare
                if (
                    !function_exists('userHasPermission') ||
                    (!userHasPermission('edit_gare') && !userHasPermission('view_gare'))
                ) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                $participation = isset($input['participation']) ? (bool) $input['participation'] : false;
                echo json_encode(\Services\GareService::updateParticipation($jobId, $participation), JSON_UNESCAPED_UNICODE);
                break;

            case 'updateGaraStatus':
                // Controllo permesso: edit_gare o view_gare
                if (
                    !function_exists('userHasPermission') ||
                    (!userHasPermission('edit_gare') && !userHasPermission('view_gare'))
                ) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                $statusId = (int) ($input['status_id'] ?? 0);
                echo json_encode(\Services\GareService::updateGaraStatus($jobId, $statusId), JSON_UNESCAPED_UNICODE);
                break;

            case 'updatePriorita':
                // Controllo permesso: edit_gare o view_gare
                if (
                    !function_exists('userHasPermission') ||
                    (!userHasPermission('edit_gare') && !userHasPermission('view_gare'))
                ) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                $priorita = isset($input['priorita']) ? ($input['priorita'] !== null ? (int) $input['priorita'] : null) : null;
                echo json_encode(\Services\GareService::updatePriorita($jobId, $priorita), JSON_UNESCAPED_UNICODE);
                break;

            case 'updateAssegnatoA':
                // Controllo permesso: edit_gare o view_gare
                if (
                    !function_exists('userHasPermission') ||
                    (!userHasPermission('edit_gare') && !userHasPermission('view_gare'))
                ) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                $assegnatoA = isset($input['assegnato_a']) ? $input['assegnato_a'] : null;
                echo json_encode(\Services\GareService::updateAssegnatoA($jobId, $assegnatoA), JSON_UNESCAPED_UNICODE);
                break;

            case 'updateNote':
                // Controllo permesso: edit_gare o view_gare
                if (
                    !function_exists('userHasPermission') ||
                    (!userHasPermission('edit_gare') && !userHasPermission('view_gare'))
                ) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                $note = isset($input['note']) ? $input['note'] : null;
                echo json_encode(\Services\GareService::updateNote($jobId, $note), JSON_UNESCAPED_UNICODE);
                break;

            case 'updateScadenzaCustom':
                // Controllo permesso: edit_gare o view_gare
                if (
                    !function_exists('userHasPermission') ||
                    (!userHasPermission('edit_gare') && !userHasPermission('view_gare'))
                ) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                $scadenzaCustom = isset($input['scadenza_custom']) ? $input['scadenza_custom'] : null;
                echo json_encode(\Services\GareService::updateScadenzaCustom($jobId, $scadenzaCustom), JSON_UNESCAPED_UNICODE);
                break;

            case 'updateGaraFields':
                // Controllo permesso: edit_gare o view_gare
                if (
                    !function_exists('userHasPermission') ||
                    (!userHasPermission('edit_gare') && !userHasPermission('view_gare'))
                ) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
                echo json_encode(\Services\GareService::updateGaraFields($jobId, $fields), JSON_UNESCAPED_UNICODE);
                break;

            case 'listNcFolder':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                sendJsonResponse(\Services\GareService::listNcFolderGara($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'getNcFiles':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                sendJsonResponse(\Services\GareService::getNcFilesGara($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'attachNcFile':
                if (!function_exists('userHasPermission') || !userHasPermission('edit_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                $fileInfo = [
                    'path' => $input['path'] ?? '',
                    'name' => $input['name'] ?? '',
                    'mime' => $input['mime'] ?? 'application/octet-stream',
                    'size' => (int) ($input['size'] ?? 0),
                ];
                sendJsonResponse(\Services\GareService::attachNcFileGara($jobId, $fileInfo), JSON_UNESCAPED_UNICODE);
                break;

            case 'detachNcFile':
                if (!function_exists('userHasPermission') || !userHasPermission('edit_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                $path = $input['path'] ?? '';
                sendJsonResponse(\Services\GareService::detachNcFileGara($jobId, $path), JSON_UNESCAPED_UNICODE);
                break;

            case 'getGaraMetadata':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? $input['id'] ?? 0);
                sendJsonResponse(\Services\GareService::getGaraMetadata($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'searchCommesse':
                // Ricerca commesse per autocomplete collegamento gara
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $q = isset($input['q']) ? trim((string)$input['q']) : '';
                sendJsonResponse(\Services\CommesseService::searchCommesse(['q' => $q]), JSON_UNESCAPED_UNICODE);
                break;

            case 'checkQuota':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                sendJsonResponse(\Services\ExtractionService::checkQuota($input), JSON_UNESCAPED_UNICODE);
                break;

            case 'getExtractionTypes':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                sendJsonResponse(\Services\ExtractionService::getExtractionTypes(), JSON_UNESCAPED_UNICODE);
                break;

            case 'reExtract':
                if (!function_exists('userHasPermission') || !userHasPermission('edit_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $jobId = (int) ($input['job_id'] ?? 0);
                if ($jobId <= 0) {
                    sendJsonResponse(['success' => false, 'message' => 'job_id mancante'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                sendJsonResponse(\Services\ExtractionService::reExtract($jobId), JSON_UNESCAPED_UNICODE);
                break;

            case 'apiHealth':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                sendJsonResponse(\Services\ExtractionService::apiHealth(), JSON_UNESCAPED_UNICODE);
                break;

            case 'getQuota':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                $env = \Services\ExtractionService::expandEnvPlaceholders(\Services\ExtractionService::loadEnvConfig());
                $client = new \Services\AIextraction\ExternalApiClient($env);
                sendJsonResponse($client->getQuota(), JSON_UNESCAPED_UNICODE);
                break;

            case 'getBatchUsage':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                sendJsonResponse(\Services\ExtractionService::getBatchUsageAction($input), JSON_UNESCAPED_UNICODE);
                break;

            case 'listBatches':
                if (!function_exists('userHasPermission') || !userHasPermission('view_gare')) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                sendJsonResponse(\Services\ExtractionService::listBatchesAction($input), JSON_UNESCAPED_UNICODE);
                break;

            case 'deleteRemoteJob':
                if (!function_exists('userHasPermission') ||
                    (!userHasPermission('edit_gare') && !userHasPermission('create_gare'))) {
                    http_response_code(403);
                    sendJsonResponse(['success' => false, 'message' => 'Permesso negato'], JSON_UNESCAPED_UNICODE);
                    break;
                }
                sendJsonResponse(\Services\ExtractionService::deleteRemoteJob($input), JSON_UNESCAPED_UNICODE);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta in gare'], JSON_UNESCAPED_UNICODE);
                break;
        }
        exit;

    case 'requisiti':
        header('Content-Type: application/json; charset=utf-8');

        switch ($action) {
            case 'getFatturatoAnnuale':
                echo json_encode(\Services\RequisitiService::getFatturatoAnnuale(), JSON_UNESCAPED_UNICODE);
                break;

            case 'saveFatturato':
                echo json_encode(\Services\RequisitiService::saveFatturato($input), JSON_UNESCAPED_UNICODE);
                break;

            case 'deleteFatturato':
                echo json_encode(\Services\RequisitiService::deleteFatturato($input), JSON_UNESCAPED_UNICODE);
                break;

            case 'getComprovanti':
                echo json_encode(\Services\RequisitiService::getComprovanti(), JSON_UNESCAPED_UNICODE);
                break;

            case 'getRequisitiPersonale':
                $jobId = isset($input['job_id']) ? (int) $input['job_id'] : 0;
                if ($jobId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'job_id non valido'], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(\Services\RequisitiService::getRequisitiPersonale($jobId), JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'getPersonaleAttivo':
                echo json_encode(\Services\RequisitiService::getPersonaleAttivo(), JSON_UNESCAPED_UNICODE);
                break;

            case 'getElencoGare':
                echo json_encode(\Services\RequisitiService::getElencoGare(), JSON_UNESCAPED_UNICODE);
                break;

            case 'debugJobIdsConRequisiti':
                echo json_encode(\Services\RequisitiService::debugJobIdsConRequisiti(), JSON_UNESCAPED_UNICODE);
                break;

            // Comprovanti - Lista progetti
            case 'listComprovantiProgetti':
                echo json_encode(\Services\RequisitiService::listComprovantiProgetti($input), JSON_UNESCAPED_UNICODE);
                break;

            // Comprovanti - Dettaglio progetto
            case 'getComprovanteDettaglio':
                $progettoId = isset($input['progetto_id']) ? (int) $input['progetto_id'] : 0;
                if ($progettoId <= 0) {
                    echo json_encode(['success' => false, 'data' => null, 'errors' => ['progetto_id non valido']], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(\Services\RequisitiService::getComprovanteDettaglio($progettoId), JSON_UNESCAPED_UNICODE);
                }
                break;

            // Comprovanti - Esportazione Word (Source of Truth in CommesseService)
            case 'exportComprovanteWord':
                echo json_encode(\Services\CommesseService::exportComprovanteWord($input), JSON_UNESCAPED_UNICODE);
                break;

            // Comprovanti - CRUD Progetto
            case 'createComprovanteProgetto':
                echo json_encode(\Services\RequisitiService::createComprovanteProgetto($input), JSON_UNESCAPED_UNICODE);
                break;

            case 'updateComprovanteProgetto':
                $progettoId = isset($input['progetto_id']) ? (int) $input['progetto_id'] : 0;
                if ($progettoId <= 0) {
                    echo json_encode(['success' => false, 'data' => null, 'errors' => ['progetto_id non valido']], JSON_UNESCAPED_UNICODE);
                } else {
                    unset($input['progetto_id']);
                    echo json_encode(\Services\RequisitiService::updateComprovanteProgetto($progettoId, $input), JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'deleteComprovanteProgetto':
                $progettoId = isset($input['progetto_id']) ? (int) $input['progetto_id'] : 0;
                if ($progettoId <= 0) {
                    echo json_encode(['success' => false, 'data' => null, 'errors' => ['progetto_id non valido']], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(\Services\RequisitiService::deleteComprovanteProgetto($progettoId), JSON_UNESCAPED_UNICODE);
                }
                break;

            // Comprovanti - Upload PDF
            case 'uploadComprovantePdf':
                // PULIZIA BUFFER: Rimuove eventuali warning PHP accumulati prima del JSON
                if (ob_get_length())
                    ob_clean();

                $res = \Services\RequisitiService::uploadComprovantePdf();
                if (empty($res['success'])) {
                    http_response_code(400);
                }
                echo json_encode($res, JSON_UNESCAPED_UNICODE);
                break;

            // Comprovanti - CRUD Servizi
            case 'createComprovanteServizio':
                echo json_encode(\Services\RequisitiService::createComprovanteServizio($input), JSON_UNESCAPED_UNICODE);
                break;

            case 'updateComprovanteServizio':
                $servizioId = isset($input['servizio_id']) ? (int) $input['servizio_id'] : 0;
                if ($servizioId <= 0) {
                    echo json_encode(['success' => false, 'data' => null, 'errors' => ['servizio_id non valido']], JSON_UNESCAPED_UNICODE);
                } else {
                    unset($input['servizio_id']);
                    echo json_encode(\Services\RequisitiService::updateComprovanteServizio($servizioId, $input), JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'deleteComprovanteServizio':
                $servizioId = isset($input['servizio_id']) ? (int) $input['servizio_id'] : 0;
                if ($servizioId <= 0) {
                    echo json_encode(['success' => false, 'data' => null, 'errors' => ['servizio_id non valido']], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(\Services\RequisitiService::deleteComprovanteServizio($servizioId), JSON_UNESCAPED_UNICODE);
                }
                break;

            // Comprovanti - CRUD Prestazioni
            case 'createComprovantePrestazione':
                echo json_encode(\Services\RequisitiService::createComprovantePrestazione($input), JSON_UNESCAPED_UNICODE);
                break;

            case 'updateComprovantePrestazione':
                $prestazioneId = isset($input['prestazione_id']) ? (int) $input['prestazione_id'] : 0;
                if ($prestazioneId <= 0) {
                    echo json_encode(['success' => false, 'data' => null, 'errors' => ['prestazione_id non valido']], JSON_UNESCAPED_UNICODE);
                } else {
                    unset($input['prestazione_id']);
                    echo json_encode(\Services\RequisitiService::updateComprovantePrestazione($prestazioneId, $input), JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'deleteComprovantePrestazione':
                $prestazioneId = isset($input['prestazione_id']) ? (int) $input['prestazione_id'] : 0;
                if ($prestazioneId <= 0) {
                    echo json_encode(['success' => false, 'data' => null, 'errors' => ['prestazione_id non valido']], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(\Services\RequisitiService::deleteComprovantePrestazione($prestazioneId), JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'togglePrestazioneEseguita':
                $prestazioneId = isset($input['prestazione_id']) ? (int) $input['prestazione_id'] : 0;
                if ($prestazioneId <= 0) {
                    echo json_encode(['success' => false, 'errors' => ['prestazione_id non valido']], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(\Services\RequisitiService::togglePrestazioneEseguita($prestazioneId), JSON_UNESCAPED_UNICODE);
                }
                break;

            // Comprovanti - Liste per select
            case 'getMacroCategorieComprovanti':
                echo json_encode(\Services\RequisitiService::getMacroCategorieComprovanti(), JSON_UNESCAPED_UNICODE);
                break;

            case 'getTipologiePrestazioneByMacroCategoria':
                $macroCategoria = isset($input['macro_categoria']) ? trim((string) $input['macro_categoria']) : '';
                if (empty($macroCategoria)) {
                    echo json_encode(['success' => false, 'data' => null, 'errors' => ['macro_categoria non valida']], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(\Services\RequisitiService::getTipologiePrestazioneByMacroCategoria($macroCategoria), JSON_UNESCAPED_UNICODE);
                }
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta in requisiti']);
                break;
        }
        exit;

    case 'contacts':
        switch ($action) {
            case 'getProfileData':
                $userId = filter_var($input['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(ContactService::getProfileData($userId));
                break;

            case 'getProfileImage':
                $name = $input['name'] ?? null;
                if (!$name) {
                    echo json_encode(['status' => 'error', 'message' => 'Nome mancante.']);
                } else {
                    echo json_encode(ContactService::getProfileImageByName($name));
                }
                break;

            case 'getContacts':
                echo json_encode(ContactService::getContacts());
                break;

            case 'getCompetencesByArea':
                $areaId = filter_var($input['area_id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(ContactService::getCompetencesByArea($areaId));
                break;

            case 'getUserCompetences':
                $userId = filter_var($input['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(ContactService::getUserCompetences($userId));
                break;

            case 'getProfileRoles':
                $userId = filter_var($input['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(ContactService::getProfileRoles($userId));
                break;

            case 'getProfileActiveProjects':
                $userId = filter_var($input['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(ContactService::getProfileActiveProjects($userId));
                break;

            case 'getProfileCoworkers':
                $userId = filter_var($input['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(ContactService::getProfileCoworkers($userId));
                break;

            case 'getFilteredContacts':
                echo json_encode(ContactService::getFilteredContacts($input));
                break;

            case 'checkCurriculumExistence':
                $filename = filter_var($input['filename'] ?? null, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                if (!$filename) {
                    echo json_encode(['success' => false, 'message' => 'Nome file mancante']);
                } else {
                    echo json_encode(ContactService::checkCurriculumExistence($filename));
                }
                break;

            case 'getMinifiedUserList':
                echo json_encode(ContactService::getMinifiedUserList());
                break;

            case 'resetUserPassword':
                $userId = intval($input['user_id'] ?? 0);
                echo json_encode(ContactService::resetUserPassword($userId));
                break;

            case 'getCompanyContacts':
                $aziendaId = isset($input['azienda_id']) ? (int) $input['azienda_id'] : 0;
                $fields = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
                $includeRaw = !empty($input['includeRaw']) && $input['includeRaw'] !== 'false';

                if ($aziendaId <= 0) {
                    // Usa sendJsonResponse per consistenza con le nuove API
                    sendJsonResponse(['success' => false, 'message' => 'ID azienda non valido', 'data' => []]);
                } else {
                    $data = ContactService::getCompanyContacts($aziendaId, $fields, $includeRaw);
                    sendJsonResponse(['success' => true, 'data' => $data]);
                }
                break;

            default:
                echo json_encode(['error' => 'Azione non riconosciuta in contacts']);
                break;
        }
        exit;

    case 'office_map':
        switch ($action) {
            case 'get_positions':
                $floor = $input['floor'] ?? null;
                echo json_encode(OfficeMapService::getPositions($floor));
                break;

            case 'get_postazioni':
                $floor = $input['floor'] ?? null;
                echo json_encode(OfficeMapService::getPostazioni($floor));
                break;

            case 'get_available_contacts':
                $all = isset($input['all']) && $input['all'] == 1;
                echo json_encode(OfficeMapService::getAvailableContacts($all));
                break;

            case 'save_position':
                echo json_encode(
                    OfficeMapService::savePosition(
                        $input['user_id'] ?? null,
                        $input['floor'] ?? '',
                        floatval($input['x_position'] ?? 0),
                        floatval($input['y_position'] ?? 0),
                        $input['interno'] ?? null
                    )
                );
                break;

            case 'save_postazione':
                echo json_encode(
                    OfficeMapService::savePostazione(
                        $input['floor'] ?? '',
                        $input['interno'] ?? '',
                        floatval($input['x_position'] ?? 0),
                        floatval($input['y_position'] ?? 0),
                        floatval($input['width'] ?? 0),
                        floatval($input['height'] ?? 0)
                    )
                );
                break;

            case 'delete_postazione':
                echo json_encode(
                    OfficeMapService::deletePostazione(
                        $input['floor'] ?? '',
                        $input['interno'] ?? ''
                    )
                );
                break;

            case 'change_floor':
                echo json_encode(
                    OfficeMapService::changeFloor(
                        $input['user_id'] ?? null,
                        $input['new_floor'] ?? ''
                    )
                );
                break;

            case 'archive_contact':
                echo json_encode(OfficeMapService::archiveContact($input['user_id'] ?? null));
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta in office_map']);
                break;
        }
        exit;

    case 'profile':
        switch ($action) {
            case 'getPersonalInfo':
                $userId = filter_var($input['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(ProfileService::getPersonalInfo($userId));
                break;

            case 'updatePersonalInfo':
                $userId = filter_var($input['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);

                // Prepara TUTTI i campi ricevuti, tranne section/action/id/csrf_token
                $data = [];
                foreach ($input as $k => $v) {
                    if (in_array($k, ['section', 'action', 'id', 'csrf_token']))
                        continue;
                    $data[$k] = $v;
                }
                echo json_encode(ProfileService::updatePersonalInfo($userId, $data));
                break;

            case 'updateBio':
                $userId = $_SESSION['user_id'] ?? null;
                $bio = $input['bio'] ?? null;
                if (!$userId || $bio === null) {
                    echo json_encode(['success' => false, 'message' => 'Dati mancanti.']);
                } else {
                    echo json_encode(ProfileService::updateUserBio($userId, $bio));
                }
                break;

            default:
                echo json_encode(['error' => 'Azione non riconosciuta in profile']);
                break;
        }
        exit;



    case 'hr':
        switch ($action) {
            case 'getAreas':
                echo json_encode(['success' => true, 'data' => HrService::getAreas()]);
                break;

            case 'getCompetencesForArea':
                $areaId = filter_var($input['areaId'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(HrService::getCompetencesForArea($areaId));
                break;

            case 'getUserCompetences':
                $userId = filter_var($input['id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(HrService::getUserCompetences($userId));
                break;

            case 'addCompetence':
                $userId = $_SESSION['user_id'] ?? null;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'Utente non autenticato.']);
                    exit;
                }
                $competenceId = filter_var($input['competenza_id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                $level = filter_var($input['lvl'] ?? 1, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(HrService::addCompetence($userId, $competenceId, $level));
                break;

            case 'removeCompetence':
                $userId = $_SESSION['user_id'] ?? null;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'Utente non autenticato.']);
                    exit;
                }
                $competenceId = filter_var($input['competenza_id'] ?? null, FILTER_SANITIZE_NUMBER_INT);
                echo json_encode(HrService::removeCompetence($userId, $competenceId));
                break;

            default:
                echo json_encode(['error' => 'Azione non riconosciuta in hr']);
                break;
        }
        exit;

    case 'user':
        switch ($action) {

            case 'getUserList':
                echo json_encode(UserService::getUserList());
                exit;

            case 'updateEmail':
                $userId = $input['user_id'] ?? null;
                $email = $input['email'] ?? '';
                $res = UserService::updateEmail($userId, $email);
                sendJsonResponse($res);
                exit;

            case 'changePassword':
                $userId = $_SESSION['user_id'] ?? null;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'Utente non autenticato.']);
                    exit;
                }

                $currentPassword = $input['currentPassword'] ?? null;
                $newPassword = $input['newPassword'] ?? null;
                $confirmPassword = $input['confirmPassword'] ?? null;

                if (!$currentPassword || !$newPassword || !$confirmPassword) {
                    echo json_encode(['success' => false, 'message' => 'Tutti i campi password sono obbligatori.']);
                    exit;
                }

                echo json_encode(UserService::changePassword($userId, $currentPassword, $newPassword, $confirmPassword));

                break;

            case 'inviaInvito':
                $userId = $input['user_id'] ?? null;
                echo json_encode(UserService::inviaInvito($userId));
                break;

            default:
                echo json_encode(['error' => 'Azione non riconosciuta in user']);
                break;
        }
        exit;

    case 'protocollo_email':
        switch ($action) {

            case 'getCommesse':
                $oldErrorReporting = error_reporting(E_ALL);
                $oldDisplayErrors = ini_set('display_errors', '0');
                $data = ProtocolloEmailService::getCommesse();
                error_reporting($oldErrorReporting);
                if ($oldDisplayErrors !== false) {
                    ini_set('display_errors', $oldDisplayErrors);
                }
                echo json_encode([
                    'success' => true,
                    'data' => $data
                ]);
                break;

            case 'caricaAziende':
                echo json_encode([
                    'success' => true,
                    'data' => ProtocolloEmailService::caricaAziende()
                ]);
                break;

            case 'getTuttiContatti':
                echo json_encode([
                    'success' => true,
                    'data' => ProtocolloEmailService::getTuttiContatti()
                ]);
                break;

            case 'getContattiByAzienda':
                $azienda = $input['azienda_id'] ?? ($input['azienda'] ?? '');
                $data = ProtocolloEmailService::getContattiByAzienda($azienda);
                sendJsonResponse(['success' => true, 'data' => $data]);
                break;

            case 'aggiungiAzienda':
                $result = ProtocolloEmailService::aggiungiAzienda(null);
                header('Content-Type: application/json');
                echo json_encode($result);
                exit;

            case 'aggiungiContatto':
                $azienda_id = intval($input['azienda_id'] ?? 0);
                $dati = [
                    'email' => $input['email'] ?? '',
                    'nome' => $input['nome'] ?? '',
                    'cognome' => $input['cognome'] ?? '',
                    'cognome_e_nome' => $input['cognome_e_nome'] ?? '',
                    'ruolo' => $input['ruolo'] ?? '',
                    'telefono' => $input['telefono'] ?? '',
                    'cellulare' => $input['cellulare'] ?? '',
                    'titolo' => $input['titolo'] ?? ''
                ];
                echo json_encode(ProtocolloEmailService::aggiungiContatto($azienda_id, $dati));
                break;

            case 'getDestinatariDettaglio':
                $protocollo_email_id = intval($input['protocollo_email_id'] ?? 0);
                $data = ProtocolloEmailService::getDestinatariDettaglio($protocollo_email_id);
                sendJsonResponse(['success' => true, 'data' => $data]);
                break;

            case 'getArchivio':
                $pagina = isset($input['pagina']) ? intval($input['pagina']) : 1;
                $limite = isset($input['limite']) ? intval($input['limite']) : 10;
                $id = isset($input['id']) ? intval($input['id']) : null;
                echo json_encode(
                    ProtocolloEmailService::getArchivio($pagina, $limite, $id)
                );
                break;

            case 'modificaProtocollo':
                echo json_encode(ProtocolloEmailService::modificaProtocollo($input));
                break;

            case 'getPreviewProtocollo':
                echo json_encode(
                    ProtocolloEmailService::getPreviewProtocollo($input)
                );
                break;

            case 'genera':
                echo json_encode(ProtocolloEmailService::genera($input));
                break;

            case 'generaEApri':
                echo json_encode(ProtocolloEmailService::generaEApri($input));
                break;

            case 'eliminaProtocollo':
                $id = intval($input['id'] ?? 0);
                echo json_encode(ProtocolloEmailService::eliminaProtocollo($id));
                break;

            default:
                echo json_encode([
                    'success' => false,
                    'message' => 'Azione non riconosciuta in protocollo_email'
                ]);
                break;
        }
        exit;

    case 'notifiche':
        switch ($action) {
            case 'get_unread':
                $userId = $_SESSION['user_id'] ?? null;
                if (!$userId) {
                    sendJsonResponse(['success' => false, 'message' => 'Utente non autenticato']);
                }
                sendJsonResponse(['success' => true, 'notifiche' => NotificationService::getUnread($userId)]);
                break;

            case 'getUltime':
                $userId = $_SESSION['user_id'] ?? null;
                $limit = isset($input['limit']) ? intval($input['limit']) : 5;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'Utente non autenticato']);
                    exit;
                }
                $data = NotificationService::getUltime($userId, $limit);
                sendJsonResponse(['success' => true, 'data' => $data]);
                exit;

            case 'get_all':
                $userId = $_SESSION['user_id'] ?? null;
                if (!$userId) {
                    echo json_encode(['success' => false, 'message' => 'Utente non autenticato']);
                    exit;
                }
                echo json_encode(['success' => true, 'notifiche' => NotificationService::getAll($userId)]);
                break;

            case 'mark_all_as_read':
                $userId = $_SESSION['user_id'] ?? null;
                if (!$userId)
                    exit(json_encode(['success' => false, 'message' => 'Utente non loggato']));
                NotificationService::markAllAsRead($userId);
                exit(json_encode(['success' => true]));
                break;

            case 'toggle_pin':
                $userId = $_SESSION['user_id'] ?? null;
                $notificaId = isset($_POST['notifica_id']) ? intval($_POST['notifica_id']) : null;
                if (!$userId || !$notificaId)
                    exit(json_encode(['success' => false, 'message' => 'Dati mancanti']));
                NotificationService::togglePin($notificaId, $userId);
                exit(json_encode(['success' => true]));
                break;

            case 'delete':
                $userId = $_SESSION['user_id'] ?? null;
                $notificaId = $_POST['notifica_id'] ?? null;
                if (!$userId || !$notificaId)
                    exit(json_encode(['success' => false, 'message' => 'Dati mancanti']));
                NotificationService::delete($notificaId, $userId);
                exit(json_encode(['success' => true]));
                break;

            case 'delete_all':
                $userId = $_SESSION['user_id'] ?? null;
                if (!$userId)
                    exit(json_encode(['success' => false, 'message' => 'Utente non loggato']));
                NotificationService::deleteAll($userId);
                exit(json_encode(['success' => true]));
                break;

            case 'mark_as_read':
                $userId = $_SESSION['user_id'] ?? null;
                $notificaId = $_POST['notifica_id'] ?? null;
                if (!$userId || !$notificaId)
                    exit(json_encode(['success' => false, 'message' => 'Dati mancanti']));
                NotificationService::markAsRead($notificaId, $userId);
                exit(json_encode(['success' => true]));
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta in notifiche']);
                break;
        }
        exit;

    case 'filters':
        switch ($action) {
            case 'getDynamicFilters':
                $table = $input['table'] ?? null;
                $columns = $input['columns'] ?? [];
                echo json_encode(FilterService::getDynamicFilters($table, $columns));
                break;

            case 'getFilteredData':
                $table = $input['table'] ?? null;
                $filters = $input['filters'] ?? [];
                echo json_encode(FilterService::getFilteredData($table, $filters));
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta in filters']);
                break;
        }
        exit;

    case 'changelog':
        switch ($action) {
            case 'getLatest':
                echo json_encode(ChangelogService::getLatest());
                break;

            case 'getAll':
                echo json_encode(ChangelogService::getAll());
                break;

            case 'addChangelog':
                $input['url'] = $input['url'] ?? null;
                echo json_encode(ChangelogService::addChangelog($input));
                break;

            case 'deleteChangelog':
                $input = json_decode(file_get_contents('php://input'), true);
                $id = $input['id'] ?? ($_POST['id'] ?? 0);
                echo json_encode(ChangelogService::deleteChangelog($id));
                break;

            case 'getNextVersion':
                echo json_encode(ChangelogService::getNextVersion($input));
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta in changelog']);
                break;
        }
        exit;

    case 'gestione_intranet':
        switch ($action) {
            case 'getComunicazioni':
                sendJsonResponse(GestioneIntranetService::getComunicazioni());
                break;
            case 'debugAuth':
                // Endpoint debug per verificare stato autorizzazione
                $response = [
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'username' => $_SESSION['username'] ?? null,
                    'role_ids' => $_SESSION['role_ids'] ?? null,
                    'role_id_legacy' => $_SESSION['role_id'] ?? null,
                    'is_admin' => isAdmin(),
                    'permissions' => $_SESSION['role_permissions'] ?? null,
                    'permissions_count' => isset($_SESSION['role_permissions']) ? count($_SESSION['role_permissions']) : 0
                ];
                echo json_encode($response);
                break;
            case 'getMaintenanceStatus':
                // Solo admin possono vedere lo stato
                if (!isAdmin()) {
                    $debug = [
                        'user_id' => $_SESSION['user_id'] ?? 'MISSING',
                        'username' => $_SESSION['username'] ?? 'MISSING',
                        'role_ids' => $_SESSION['role_ids'] ?? 'MISSING',
                        'is_admin' => false,
                        'required_context' => 'getMaintenanceStatus'
                    ];
                    // Debug hit: Accesso non autorizzato getMaintenanceStatus
                    $response = ['success' => false, 'message' => 'Accesso non autorizzato'];
                    if (!defined('APP_ENV') || APP_ENV === 'dev') {
                        $reason = 'Unknown';
                        if ($debug['role_ids'] === 'MISSING') {
                            $reason = 'Sessione non inizializzata (role_ids mancante)';
                        } elseif ($debug['is_admin']) {
                            $reason = 'Impossibile - è admin ma è stato negato';
                        } elseif ($debug['permissions_count'] === 0) {
                            $reason = 'Nessuna permission caricata per i ruoli';
                        } else {
                            $reason = "Permission richiesta non presente nei ruoli";
                        }

                        $response['debug'] = [
                            'user_id' => $debug['user_id'],
                            'username' => $debug['username'],
                            'role_ids' => $debug['role_ids'],
                            'is_admin' => $debug['is_admin'],
                            'required_context' => $debug['required_context'] ?? 'unknown',
                            'reason' => $reason
                        ];
                    }
                    header('X-Auth-Debug: ' . base64_encode(json_encode($debug)));
                    sendJsonResponse($response);
                }
                sendJsonResponse(GestioneIntranetService::getMaintenanceStatus());
                break;
            case 'saveMaintenanceSettings':
                // Solo admin possono salvare
                if (!isAdmin()) {
                    $debug = [
                        'user_id' => $_SESSION['user_id'] ?? 'MISSING',
                        'username' => $_SESSION['username'] ?? 'MISSING',
                        'role_ids' => $_SESSION['role_ids'] ?? 'MISSING',
                        'is_admin' => false,
                        'required_context' => 'saveMaintenanceSettings'
                    ];
                    // Debug hit: Accesso non autorizzato saveMaintenanceSettings
                    $response = ['success' => false, 'message' => 'Accesso non autorizzato'];
                    if (!defined('APP_ENV') || APP_ENV === 'dev') {
                        $reason = 'Unknown';
                        if ($debug['role_ids'] === 'MISSING') {
                            $reason = 'Sessione non inizializzata (role_ids mancante)';
                        } elseif ($debug['is_admin']) {
                            $reason = 'Impossibile - è admin ma è stato negato';
                        } elseif ($debug['permissions_count'] === 0) {
                            $reason = 'Nessuna permission caricata per i ruoli';
                        } else {
                            $reason = "Permission richiesta non presente nei ruoli";
                        }

                        $response['debug'] = [
                            'user_id' => $debug['user_id'],
                            'username' => $debug['username'],
                            'role_ids' => $debug['role_ids'],
                            'is_admin' => $debug['is_admin'],
                            'required_context' => $debug['required_context'] ?? 'unknown',
                            'reason' => $reason
                        ];
                    }
                    header('X-Auth-Debug: ' . base64_encode(json_encode($debug)));
                    sendJsonResponse($response);
                }
                $maintenanceMode = isset($input['maintenance_mode']) ? intval($input['maintenance_mode']) : 0;
                $maintenanceMessage = isset($input['maintenance_message']) ? trim($input['maintenance_message']) : '';
                sendJsonResponse(GestioneIntranetService::saveMaintenanceSettings($maintenanceMode, $maintenanceMessage));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta in gestione_intranet']);
                break;
        }
        exit;

    case 'roles':
        switch ($action) {
            case 'getRoles':
            case 'listRoles':
            case 'searchUsers':
            case 'getUserRoles':
            case 'getUserRoleMappings':
                // Operazioni READ: richiedono view_gestione_intranet O view_gestione_ruoli
                if (!isAdmin() && !userHasPermission('view_gestione_intranet') && !userHasPermission('view_gestione_ruoli')) {
                    sendJsonResponse(['success' => false, 'message' => 'Accesso non autorizzato']);
                }
                break;

            case 'setUserRoles':
                // Operazione WRITE: richiede admin (o manage_roles se implementato)
                if (!isAdmin()) {
                    sendJsonResponse(['success' => false, 'message' => 'Accesso non autorizzato - operazione richiede privilegi amministrativi']);
                }
                break;

            case 'listPageEditorForms':
            case 'getRolePageEditorPermissions':
                // READ: richiedono view_gestione_intranet (coerente con altri READ)
                if (!isAdmin() && !userHasPermission('view_gestione_intranet')) {
                    sendJsonResponse(['success' => false, 'message' => 'Accesso non autorizzato']);
                }
                break;

            case 'saveRole':
            case 'deleteRole':
            case 'addRoleToUser':
            case 'removeRoleFromUser':
            case 'assignRoleToUser':
                // Operazioni WRITE: richiede admin
                if (!isAdmin()) {
                    sendJsonResponse(['success' => false, 'message' => 'Accesso non autorizzato - operazione richiede privilegi amministrativi']);
                }
                break;

            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta in roles']);
                exit;
        }

        switch ($action) {
            case 'getRoles':
            case 'listRoles':
                // Lista tutti i ruoli disponibili (inclusi permessi per la gestione)
                sendJsonResponse(\Services\RoleService::getRoles());
                break;

            case 'getUserRoleMappings':
                sendJsonResponse(\Services\RoleService::getUserRoleMappings());
                break;

            case 'addRoleToUser':
                sendJsonResponse(\Services\RoleService::addRoleToUser($input));
                break;

            case 'removeRoleFromUser':
                sendJsonResponse(\Services\RoleService::removeRoleFromUser($input));
                break;

            case 'assignRoleToUser':
                sendJsonResponse(\Services\RoleService::assignRoleToUser($input));
                break;

            case 'saveRole':
                sendJsonResponse(\Services\RoleService::saveRole($input));
                break;

            case 'deleteRole':
                $roleId = isset($input['id']) ? intval($input['id']) : null;
                if (!$roleId) {
                    sendJsonResponse(['success' => false, 'message' => 'ID ruolo mancante']);
                } else {
                    sendJsonResponse(\Services\RoleService::deleteRole($roleId));
                }
                break;

            case 'searchUsers':
                // Cerca utenti per username/email
                $q = isset($input['q']) ? trim($input['q']) : '';
                if (strlen($q) < 2) {
                    sendJsonResponse(['success' => false, 'message' => 'Query troppo corta (minimo 2 caratteri)']);
                }
                sendJsonResponse(\Services\RoleService::searchUsers($q));
                break;

            case 'getUserRoles':
                // Ottieni ruoli assegnati a un utente
                $userId = isset($input['user_id']) ? intval($input['user_id']) : 0;
                if ($userId <= 0) {
                    sendJsonResponse(['success' => false, 'message' => 'ID utente non valido']);
                }
                $roleIds = \Services\RoleService::getRoleIdsByUserId($userId);
                sendJsonResponse(['success' => true, 'data' => $roleIds]);
                break;

            case 'setUserRoles':
                // Imposta ruoli per un utente
                $userId = isset($input['user_id']) ? intval($input['user_id']) : 0;
                $roleIds = isset($input['role_ids']) ? (array) $input['role_ids'] : [];
                $roleIds = array_map('intval', array_filter($roleIds));

                if ($userId <= 0) {
                    sendJsonResponse(['success' => false, 'message' => 'ID utente non valido']);
                }

                sendJsonResponse(\Services\RoleService::setUserRoles($userId, $roleIds));
                break;

            case 'listPageEditorForms':
                // Lista tutte le pagine create con page_editor (tabelle forms)
                sendJsonResponse(\Services\RoleService::getAllPageEditorForms());
                break;

            case 'getRolePageEditorPermissions':
                // Ottieni permessi page_editor per un ruolo
                $roleId = isset($input['role_id']) ? intval($input['role_id']) : 0;
                if ($roleId <= 0) {
                    sendJsonResponse(['success' => false, 'message' => 'ID ruolo non valido']);
                }
                $formIds = \Services\RoleService::getPageEditorFormIdsByRoleId($roleId);
                sendJsonResponse(['success' => true, 'data' => $formIds]);
                break;

            case 'setRolePageEditorPermissions':
                // Imposta permessi page_editor per un ruolo
                $roleId = isset($input['role_id']) ? intval($input['role_id']) : 0;
                $formIds = isset($input['form_ids']) ? (array) $input['form_ids'] : [];
                $formIds = array_map('intval', array_filter($formIds));

                if ($roleId <= 0) {
                    sendJsonResponse(['success' => false, 'message' => 'ID ruolo non valido']);
                }

                sendJsonResponse(\Services\RoleService::setPageEditorFormIdsForRole($roleId, $formIds));
                break;
        }
        exit;

    case 'visibilita_sezioni':
        if ($action === 'getConfig') {
            $sezione = isset($input['sezione']) ? preg_replace('/[^a-z0-9_\-]/', '', (string) $input['sezione']) : '';
            echo json_encode(VisibilitaSezioniService::getConfig(['sezione' => $sezione]));
            exit;
        }

        if ($action === 'saveConfig') {
            $sezione = isset($input['sezione']) ? preg_replace('/[^a-z0-9_\-]/', '', (string) $input['sezione']) : '';
            $config = (isset($input['config']) && is_array($input['config'])) ? $input['config'] : [];

            // opzionale: ricontrollo JSON già validato sopra, ma manteniamo lo stile BSS
            echo json_encode(VisibilitaSezioniService::saveConfig([
                'sezione' => $sezione,
                'config' => $config
            ]));
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'azione non riconosciuta in visibilita_sezioni']);
        exit;

    case 'import_manager':
        switch ($action) {
            case 'getTables':
                echo json_encode(ImportManagerService::getTables());
                break;
            case 'previewFile':
                echo json_encode(ImportManagerService::previewFile($input, $_FILES));
                break;
            case 'suggestMapping':
                echo json_encode(ImportManagerService::suggestMapping($input, $_FILES));
                break;
            case 'doImport':
                echo json_encode(ImportManagerService::doImport($input, $_FILES));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta in import_manager']);
                break;
        }
        exit;

    /* ===========================
     * PAGE EDITOR  (schema & admin)
     * =========================== */
    case 'page_editor':
        switch ($action) {
            // === Form (meta/schema) ===
            case 'getForm':
                try {
                    $formName = $input['form_name'] ?? ($input['name'] ?? null);
                    $recordId = isset($input['record_id']) ? (int) $input['record_id'] : null;
                    $result = PageEditorService::getForm((string) $formName, $recordId);
                    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false || json_last_error() !== JSON_ERROR_NONE) {
                        error_log("Errore JSON encode in page_editor/getForm: " . json_last_error_msg());
                        echo json_encode(['success' => false, 'message' => 'Errore nella serializzazione dei dati'], JSON_UNESCAPED_UNICODE);
                    } else {
                        echo $json;
                    }
                } catch (\Exception $e) {
                    error_log("Errore in page_editor/getForm: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Errore nel caricamento del form: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
                } catch (\Throwable $e) {
                    error_log("Errore fatale in page_editor/getForm: " . $e->getMessage());
                    echo json_encode(['success' => false, 'message' => 'Errore nel caricamento del form'], JSON_UNESCAPED_UNICODE);
                }
                break;

            case 'ensureForm':
                $name = $input['form_name'] ?? ($input['name'] ?? ($input['raw_name'] ?? ''));
                $desc = array_key_exists('description', $input) ? (string) $input['description'] : null;
                $color = array_key_exists('color', $input) ? (string) $input['color'] : null;
                $button_text = array_key_exists('button_text', $input) ? (string) $input['button_text'] : null;
                echo json_encode(PageEditorService::ensureForm((string) $name, $desc, $color, $button_text));
                break;

            case 'saveFormStructure':
                echo json_encode(PageEditorService::saveFormStructure($input));
                break;

            case 'getFormFields':
                echo json_encode(PageEditorService::getFormFields($input['form_name'] ?? ''));
                break;

            case 'deleteForm':
                echo json_encode(PageEditorService::deleteForm($input));
                break;

            case 'getAllFormsForAdmin':
                echo json_encode(PageEditorService::getAllFormsForAdmin());
                break;

            // === Gestione Schede Personalizzate ===
            case 'saveFormTabs':
                $formName = $input['form_name'] ?? '';
                $tabsData = $input['tabs_data'] ?? [];
                echo json_encode(PageEditorService::saveFormTabs($formName, $tabsData));
                break;

            case 'loadFormTabs':
                $formName = $input['form_name'] ?? '';
                echo json_encode(PageEditorService::loadFormTabs($formName));
                break;

            case 'getFormFieldsByTabs':
                $formName = $input['form_name'] ?? '';
                echo json_encode(PageEditorService::getFormFieldsByTabs($formName));
                break;

            // === Stati per Kanban (per-form) ===
            case 'getFormStates':
                $formName = $input['form_name'] ?? '';
                echo json_encode(PageEditorService::getFormStates($formName));
                break;
            case 'saveFormStates':
                echo json_encode(PageEditorService::saveFormStates($input));
                break;

            // === Notifiche ===
            case 'saveNotificationRules':
                $payload = $input['config'] ?? [];
                $payload['form_name'] = $input['form_name'] ?? '';
                echo json_encode(PageEditorService::saveNotificationRules($payload));
                break;

            case 'getNotificationRules':
                $formName = $input['form_name'] ?? '';
                echo json_encode(PageEditorService::getNotificationRules($formName));
                break;

            // === Salvataggio Dati Form ===
            case 'saveFormData':
                $formName = $input['form_name'] ?? '';
                $recordId = $input['record_id'] ?? null;
                $data = $input['data'] ?? [];

                if ($recordId) {
                    // Update esistente
                    $result = \Services\FormsDataService::update([
                        'form_name' => $formName,
                        'record_id' => $recordId,
                        ...$data
                    ]);

                    // Sincronizza le subtasks dalle schede compilate
                    if ($result['success']) {
                        try {
                            \Services\FormsDataService::syncSubtasksFromTabs([
                                'form_name' => $formName,
                                'record_id' => $recordId
                            ]);
                        } catch (\Exception $e) {
                            // Ignora errori di sincronizzazione subtasks
                            error_log("Errore sync subtasks: " . $e->getMessage());
                        }
                    }

                    echo json_encode($result);
                } else {
                    // Nuovo record
                    $result = \Services\FormsDataService::submit([
                        'form_name' => $formName,
                        ...$data
                    ]);

                    // Converti 'id' in 'record_id' per compatibilità
                    if ($result['success'] && isset($result['id'])) {
                        $result['record_id'] = $result['id'];

                        // Sincronizza le subtasks dalle schede compilate
                        try {
                            \Services\FormsDataService::syncSubtasksFromTabs([
                                'form_name' => $formName,
                                'record_id' => $result['id']
                            ]);
                        } catch (\Exception $e) {
                            // Ignora errori di sincronizzazione subtasks
                            error_log("Errore sync subtasks: " . $e->getMessage());
                        }
                    }

                    echo json_encode($result);
                }
                break;

            case 'getFormData':
                $formName = $input['form_name'] ?? '';
                $recordId = $input['record_id'] ?? 0;
                echo json_encode(\Services\FormsDataService::getFormEntry([
                    'form_name' => $formName,
                    'record_id' => $recordId
                ]));
                break;

            // === Hook pre-salvataggio struttura ===
            case 'beforeSaveStructure':
                echo json_encode(PageEditorService::beforeSaveStructure($input));
                break;

            // === Wizard ===
            case 'getMenuPlacementForForm':
                echo json_encode(PageEditorService::getMenuPlacementForForm([
                    'form_name' => $input['form_name'] ?? ''
                ]));
                break;

            // === Moduli dell'editor ===
            case 'getModulesRegistry':
                echo json_encode(PageEditorService::getModulesRegistry());
                break;

            case 'getModuleConfig':
                echo json_encode(PageEditorService::getModuleConfig($input));
                break;

            case 'getAttachedModules':
                echo json_encode(PageEditorService::getAttachedModules($input));
                break;

            case 'attachModule':
                echo json_encode(PageEditorService::attachModule($input));
                break;

            case 'detachModule':
                echo json_encode(PageEditorService::detachModule($input));
                break;

            case 'saveModuleConfig':
                echo json_encode(PageEditorService::saveModuleConfig($input));
                break;

            case 'listResponsabili':
                echo json_encode(PageEditorService::listResponsabili($input));
                break;

            case 'setFormResponsabile':
                echo json_encode(PageEditorService::setFormResponsabile($input));
                break;

            // === Statistiche (dashboard editor) ===
            case 'getFilledForms':
                echo json_encode(FormsDataService::listSegnalazioniFilled(is_array($input) ? $input : []));
                break;

            // === Gestione Subtasks ===
            case 'syncSubtasksFromTabs':
                echo json_encode(FormsDataService::syncSubtasksFromTabs($input));
                break;

            case 'syncAllSubtasksForForm':
                echo json_encode(FormsDataService::syncAllSubtasksForForm($input));
                break;

            case 'forceSyncSubtasks':
                // Forza la sincronizzazione immediata di un record con debug
                try {
                    $form_name = $input['form_name'] ?? '';
                    $record_id = (int) ($input['record_id'] ?? 0);

                    if (!$form_name || !$record_id) {
                        echo json_encode(['success' => false, 'message' => 'Parametri mancanti']);
                        break;
                    }

                    // Assicura le colonne
                    $form = FormsDataService::getFormEntry(['form_name' => $form_name, 'record_id' => $record_id]);
                    if (!$form['success']) {
                        sendJsonResponse($form);
                        break;
                    }

                    // Forza sincronizzazione
                    $syncResult = FormsDataService::syncSubtasksFromTabs([
                        'form_name' => $form_name,
                        'record_id' => $record_id
                    ]);

                    echo json_encode($syncResult);
                } catch (\Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                }
                break;

            // === Gestione Stato Schede (FASE 3 - MVP) ===
            case 'getSchedeStatus':
                echo json_encode(PageEditorService::getSchedeStatus($input));
                break;

            case 'updateSchedaStatus':
                echo json_encode(PageEditorService::updateSchedaStatus($input));
                break;

            case 'submitScheda':
                // Supporta sia JSON che FormData (con file)
                // Se FormData, i valori arrivano in $input['values'] (array) e i file in $_FILES
                echo json_encode(PageEditorService::submitScheda($input, $_FILES ?? []));
                break;

            // === Creazione menu padre inline ===
            case 'createParentMenu':
                $menu_section = isset($input['menu_section']) ? preg_replace('/[^a-z0-9_\-]/i', '', trim((string) $input['menu_section'])) : '';
                $menu_title = isset($input['title']) ? trim((string) $input['title']) : '';

                if ($menu_section === '' || $menu_title === '') {
                    echo json_encode(['success' => false, 'message' => 'Sezione e titolo menu sono obbligatori']);
                    break;
                }
                if (mb_strlen($menu_title) < 2 || mb_strlen($menu_title) > 80) {
                    echo json_encode(['success' => false, 'message' => 'Il titolo deve essere tra 2 e 80 caratteri']);
                    break;
                }

                // Invece di creare una voce di menu inutile, creiamo un segnaposto temporaneo
                // che verrà rimosso quando si aggiunge la prima voce figlia
                $result = MenuCustomService::upsert([
                    'section' => $menu_section,
                    'parent_title' => $menu_title,
                    'title' => $menu_title . ' (menu padre)',
                    'link' => '#menu-parent-placeholder',
                    'attivo' => 0  // Non attivo, quindi non appare nella navigazione
                ]);

                if ($result['success']) {
                    // Ora il menu padre esisterà nella lista dei parent disponibili
                    echo json_encode(['success' => true, 'message' => 'Menu padre creato con successo', 'menu_title' => $menu_title]);
                } else {
                    echo json_encode($result);
                }
                break;

            // === Aggiornamento metadati form (dal modale proprietà) ===
            case 'updateFormMeta':
                $form_name = isset($input['form_name']) ? trim((string) $input['form_name']) : '';
                $description = isset($input['description']) ? trim((string) $input['description']) : null;
                $color = isset($input['color']) ? trim((string) $input['color']) : null;
                $display_name = isset($input['display_name']) ? trim((string) $input['display_name']) : null;

                if ($form_name === '') {
                    echo json_encode(['success' => false, 'message' => 'form_name mancante']);
                    break;
                }

                echo json_encode(PageEditorService::updateFormMeta([
                    'form_name' => $form_name,
                    'description' => $description,
                    'color' => $color,
                    'display_name' => $display_name
                ]));
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'azione non riconosciuta in page_editor']);
                break;
        }
        exit;


    /* ===========================
     * DATASOURCE  (utility DB)
     * =========================== */
    case 'datasource':
        switch ($action) {
            case 'getOptions':
                // Recupera le opzioni per un datasource specifico
                sendJsonResponse(DatasourceService::getOptions($input));
                break;

            case 'resolveDbselectValues':
                // Risolve in batch le label per campi dbselect (usato da form_viewer readonly)
                sendJsonResponse(DatasourceService::resolveDbselectValues($input));
                break;

            case 'getWhitelistedTables':
                // Usato dal Page Editor per popolare la select delle tabelle
                // Richiede diritti di admin o editor
                if (!isAdmin() && !userHasPermission('view_gestione_intranet')) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    return;
                }
                sendJsonResponse(DatasourceService::getWhitelistedTables());
                break;

            // === ADMIN ACTIONS ===
            case 'adminListTables':
                if (!isAdmin() && !userHasPermission('view_gestione_intranet')) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    return;
                }
                sendJsonResponse(DatasourceService::adminListTables());
                break;

            case 'adminToggleTable':
                if (!isAdmin() && !userHasPermission('view_gestione_intranet')) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    return;
                }
                sendJsonResponse(DatasourceService::adminToggleTable(
                    trim($input['table'] ?? ''),
                    (bool) ($input['active'] ?? false)
                ));
                break;

            case 'adminListColumns':
                if (!isAdmin() && !userHasPermission('view_gestione_intranet')) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    return;
                }
                sendJsonResponse(DatasourceService::adminListColumns(trim($input['table'] ?? '')));
                break;

            case 'adminUpdateColumns':
                if (!isAdmin() && !userHasPermission('view_gestione_intranet')) {
                    sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
                    return;
                }
                $cols = (isset($input['columns']) && is_array($input['columns'])) ? $input['columns'] : null;
                sendJsonResponse(DatasourceService::adminUpdateColumns(trim($input['table'] ?? ''), $cols));
                break;

            default:
                sendJsonResponse(['success' => false, 'message' => "Azione datasource '$action' non valida"]);
                break;
        }
        exit;



    /* ===========================
     * MENU CUSTOM  (owner: MenuCustomService)
     * =========================== */
    case 'menu_custom':
        if (!userHasPermission('manage_menu_custom')) {
            sendJsonResponse(['success' => false, 'message' => 'permesso negato']);
            exit;
        }
        switch ($action) {
            case 'list':
            case 'listAll':
                echo json_encode(MenuCustomService::listAll());
                break;

            case 'getSectionsAndParents':
                echo json_encode(MenuCustomService::getSectionsAndParents());
                break;

            case 'getMenuPlacementForForm':
                echo json_encode(MenuCustomService::getMenuPlacementForForm($input['form_name'] ?? ''));
                break;

            case 'upsert':
                // Evita collisione con 'section' del router
                $menu_section = isset($input['menu_section']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string) $input['menu_section']) : '';
                if ($menu_section === '' && isset($input['section'])) {
                    $menu_section = preg_replace('/[^a-z0-9_\-]/i', '', (string) $input['section']);
                }
                $parent_title = isset($input['parent_title']) ? trim((string) $input['parent_title']) : '';
                $title = isset($input['title']) ? trim((string) $input['title']) : '';
                $link = isset($input['link']) ? trim((string) $input['link']) : '';
                $attivo = isset($input['attivo']) ? (int) $input['attivo'] : 0;
                $ordinamento = isset($input['ordinamento']) ? (int) $input['ordinamento'] : null;

                $payload = [
                    'section' => $menu_section,
                    'parent_title' => $parent_title,
                    'title' => $title,
                    'link' => $link,
                    'attivo' => $attivo
                ];
                if ($ordinamento !== null)
                    $payload['ordinamento'] = $ordinamento;

                echo json_encode(MenuCustomService::upsert($payload));
                break;

            case 'delete':
                echo json_encode(MenuCustomService::delete($input['id'] ?? null));
                break;

            case 'reorder':
                echo json_encode(MenuCustomService::reorder($input['rows'] ?? []));
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'azione non riconosciuta in menu_custom']);
                break;
        }
        exit;


    /* ===========================
     * FORMS  (entries/data only)
     * =========================== */
    case 'forms':
        switch ($action) {
            // === CRUD record ===
            case 'submit':
                error_log("[service_router] forms/submit - CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'N/A'));
                error_log("[service_router] forms/submit - _POST keys: " . implode(', ', array_keys($_POST)));
                error_log("[service_router] forms/submit - _FILES keys: " . implode(', ', array_keys($_FILES)));
                if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
                    error_log("[service_router] forms/submit - Usando JSON input");
                    echo json_encode(FormsDataService::submit(is_array($input) ? $input : [], []));
                } else {
                    error_log("[service_router] forms/submit - Usando _POST e _FILES");
                    foreach ($_FILES as $key => $file) {
                        error_log("[service_router] forms/submit - File '$key': tmp_name={$file['tmp_name']}, error={$file['error']}, size={$file['size']}");
                    }
                    echo json_encode(FormsDataService::submit($_POST, $_FILES));
                }
                break;

            case 'update':
                if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
                    echo json_encode(FormsDataService::update(is_array($input) ? $input : [], []));
                } else {
                    echo json_encode(FormsDataService::update($_POST, $_FILES));
                }
                break;

            case 'saveSubtask':
                try {
                    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
                        $result = FormsDataService::saveSubtask(is_array($input) ? $input : [], []);
                    } else {
                        $result = FormsDataService::saveSubtask($_POST, $_FILES);
                    }
                    echo json_encode($result);
                } catch (\Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Errore salvataggio subtask: ' . $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
                break;

            case 'getFormEntry':
                echo json_encode(FormsDataService::getFormEntry([
                    'form_name' => $input['form_name'] ?? null,
                    'record_id' => $input['record_id'] ?? null
                ]));
                break;

            case 'getFormMeta':
                echo json_encode(FormsDataService::getFormMeta([
                    'form_name' => $input['form_name'] ?? null
                ]));
                break;

            case 'updateFormEntry':
                echo json_encode(FormsDataService::update(is_array($input) ? $input : [], $_FILES));
                break;

            case 'deleteFormEntry':
                echo json_encode(FormsDataService::delete([
                    'form_name' => $input['form_name'] ?? null,
                    'record_id' => $input['record_id'] ?? null
                ]));
                break;

            case 'updateFormStatus':
                echo json_encode(FormsDataService::updateFormStatus(is_array($input) ? $input : []));
                break;

            case 'updateDate':
                echo json_encode(FormsDataService::updateDate(is_array($input) ? $input : []));
                break;

            case 'getFirstEntryId':
                echo json_encode(FormsDataService::getFirstEntryId([
                    'form_name' => $input['form_name'] ?? null
                ]));
                break;

            case 'aggiornaAssegnatoA':
                echo json_encode(FormsDataService::aggiornaAssegnatoA([
                    'form_id' => $input['form_id'] ?? null,
                    'form_name' => $input['form_name'] ?? '',
                    'table_name' => $input['table_name'] ?? '',
                    'assegnato_a' => $input['assegnato_a'] ?? ''
                ]));
                break;



            // === Retrocompatibilità (pass-through senza duplicare logica) ===
            case 'getFormFields':
                echo json_encode(PageEditorService::getFormFields($input['form_name'] ?? ''));
                break;

            case 'getUtentiList':
                echo json_encode(FormsDataService::getUtentiList());
                break;

            case 'getResponsabileInfo':
                echo json_encode(FormsDataService::getResponsabileInfo([
                    'user_id' => $input['user_id'] ?? null
                ]));
                break;

            case 'addDynamicField':
                // route "vecchia" -> usa la sorgente autoritativa PageEditorService
                echo json_encode(PageEditorService::addFieldToForm([
                    'form_name' => $input['form_name'] ?? ($_POST['form_name'] ?? ''),
                    'field_name' => $input['field_name'] ?? ($_POST['field_name'] ?? ''),
                    'field_type' => $input['field_type'] ?? ($_POST['field_type'] ?? ''),
                    'field_options' => $input['field_options'] ?? ($_POST['field_options'] ?? null),
                ]));
                break;

            case 'archiviaSegnalazione':
                echo json_encode(FormsDataService::archiviaSegnalazione([
                    'form_name' => $input['form_name'] ?? null,
                    'record_id' => $input['record_id'] ?? null
                ]));
                break;

            case 'ripristinaSegnalazione':
                echo json_encode(FormsDataService::ripristinaSegnalazione([
                    'form_name' => $input['form_name'] ?? null,
                    'record_id' => $input['record_id'] ?? null
                ]));
                break;

            case 'updateEsito':
                $metaEsito = [];
                $metaEsitoKeys = ['data_apertura_esito', 'deadline_esito', 'assegnato_a_esito', 'priorita_esito', 'stato_esito'];
                foreach ($metaEsitoKeys as $mek) {
                    if (isset($input[$mek]) && $input[$mek] !== '') {
                        $metaEsito[$mek] = filter_var($input[$mek], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                    }
                }
                echo json_encode(\Services\FormSubmissionService::updateEsito(
                    $input['form_name'] ?? '',
                    (int) ($input['record_id'] ?? 0),
                    $input['esito_stato'] ?? '',
                    $input['esito_note'] ?? '',
                    $metaEsito
                ));
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'azione non riconosciuta in forms']);
                break;
        }
        exit;

    // ========== TABELLE SERVER-SIDE RIUTILIZZABILI ==========
    case 'table_api':
        try {
            $action = $input['action'] ?? '';

            switch ($action) {
                case 'query':
                    // Validazione parametri
                    $table = $input['table'] ?? '';
                    if (empty($table)) {
                        echo json_encode(['ok' => false, 'message' => 'Nome tabella mancante']);
                        exit;
                    }

                    $result = TableService::query([
                        'table' => $table,
                        'page' => $input['page'] ?? 1,
                        'pageSize' => $input['pageSize'] ?? 25,
                        'filters' => $input['filters'] ?? [],
                        'sort' => $input['sort'] ?? '',
                        'dir' => $input['dir'] ?? 'asc',
                        'search' => $input['search'] ?? '',
                    ]);

                    echo json_encode($result);
                    break;

                case 'facets':
                    // Validazione parametri
                    $table = $input['table'] ?? '';
                    $column = $input['column'] ?? '';

                    if (empty($table) || empty($column)) {
                        echo json_encode(['ok' => false, 'message' => 'Parametri mancanti']);
                        exit;
                    }

                    $result = TableService::facets([
                        'table' => $table,
                        'column' => $column,
                        'filters' => $input['filters'] ?? [],
                        'limit' => $input['limit'] ?? 200,
                    ]);

                    echo json_encode($result);
                    break;

                default:
                    echo json_encode(['ok' => false, 'message' => 'Azione non riconosciuta']);
                    break;
            }
        } catch (\Exception $e) {
            error_log("Table API error: " . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
            echo json_encode([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        }
        exit;

    case 'mom':
        switch ($action) {
            case 'getArchivio':
                $filtri = [
                    'filterSection' => $input['filterSection'] ?? '',
                    'stato' => $input['stato'] ?? '',
                    'dataDa' => $input['dataDa'] ?? '',
                    'dataA' => $input['dataA'] ?? '',
                    'testo' => $input['testo'] ?? '',
                    'contextType' => $input['contextType'] ?? '',
                    'contextId' => $input['contextId'] ?? ''
                ];
                sendJsonResponse(MomService::getArchivio($filtri));
                break;

            case 'getGlobalItems':
                $filtri = [
                    'filterSection' => $input['filterSection'] ?? '',
                    'tipo' => $input['tipo'] ?? '',
                    'contextType' => $input['contextType'] ?? '',
                    'contextId' => $input['contextId'] ?? ''
                ];
                sendJsonResponse(MomService::getGlobalItems($filtri));
                break;

            case 'getEvents':
                $filtri = [
                    'start' => isset($input['start']) && trim($input['start']) !== '' ? trim($input['start']) : '',
                    'end' => isset($input['end']) && trim($input['end']) !== '' ? trim($input['end']) : '',
                    // Map filterSection to section (avoiding top-level section overwrite issue)
                    'section' => isset($input['filterSection']) ? trim($input['filterSection']) : (isset($input['section']) ? trim($input['section']) : '')
                ];
                sendJsonResponse(MomService::getEvents($filtri));
                break;

            case 'getUser':
                sendJsonResponse(MomService::getUser());
                break;

            case 'getDettaglio':
                $momId = intval($input['momId'] ?? $input['mom_id'] ?? 0);
                if (!$momId) {
                    sendJsonResponse(['success' => false, 'message' => 'momId mancante']);
                    break;
                }
                sendJsonResponse(MomService::getDettaglio($momId));
                break;

            case 'saveMom':
                sendJsonResponse(MomService::saveMom($input));
                break;

            case 'updateMomStatus':
                $momId = intval($input['momId'] ?? $input['mom_id'] ?? 0);
                if (!$momId) {
                    sendJsonResponse(['success' => false, 'message' => 'momId mancante']);
                    break;
                }
                sendJsonResponse(MomService::updateMomStatus([
                    'momId' => $momId,
                    'stato' => $input['stato'] ?? ''
                ]));
                break;

            case 'deleteMom':
                $momId = intval($input['momId'] ?? $input['mom_id'] ?? 0);
                if (!$momId) {
                    sendJsonResponse(['success' => false, 'message' => 'momId mancante']);
                    break;
                }
                sendJsonResponse(MomService::deleteMom($momId));
                break;

            case 'uploadAllegato':
                $momId = intval($input['momId'] ?? $input['mom_id'] ?? 0);
                // Permetti momId = 0 per allegati temporanei di MOM nuovi
                if ($momId < 0) {
                    sendJsonResponse(['success' => false, 'message' => 'momId non valido']);
                    break;
                }
                if (empty($_FILES['file'])) {
                    sendJsonResponse(['success' => false, 'message' => 'File mancante']);
                    break;
                }
                sendJsonResponse(MomService::uploadAllegato($momId, $_FILES['file']));
                break;

            case 'deleteAllegato':
                $allegatoId = intval($input['allegatoId'] ?? $input['allegato_id'] ?? 0);
                if (!$allegatoId) {
                    sendJsonResponse(['success' => false, 'message' => 'allegatoId mancante']);
                    break;
                }
                sendJsonResponse(MomService::deleteAllegato($allegatoId));
                break;

            case 'downloadAllegato':
                $allegatoId = intval($input['allegatoId'] ?? $input['allegato_id'] ?? 0);
                if (!$allegatoId) {
                    sendJsonResponse(['success' => false, 'message' => 'allegatoId mancante']);
                    break;
                }
                // Questo endpoint non restituisce JSON ma invia direttamente il file
                MomService::downloadAllegato($allegatoId);
                break;

            case 'exportPdf':
                $momId = intval($input['momId'] ?? $input['mom_id'] ?? 0);
                if (!$momId) {
                    sendJsonResponse(['success' => false, 'message' => 'momId mancante']);
                    break;
                }
                sendJsonResponse(MomService::exportPdf($momId));
                break;

            case 'getPreviewProgressivo':
                sendJsonResponse(MomService::getPreviewProgressivo($input));
                break;

            case 'getAllegatiTemporanei':
                sendJsonResponse(MomService::getAllegatiTemporanei());
                break;

            case 'pulisciAllegatiTemporanei':
                sendJsonResponse(MomService::pulisciAllegatiTemporanei());
                break;

            case 'getCommesse':
                // Riutilizza ProtocolloEmailService per ottenere commesse
                sendJsonResponse([
                    'success' => true,
                    'data' => \Services\ProtocolloEmailService::getCommesse()
                ]);
                break;

            case 'saveItemsOrder':
                $momId = intval($input['momId'] ?? $input['mom_id'] ?? 0);
                $items = $input['items'] ?? [];
                if (!$momId) {
                    sendJsonResponse(['success' => false, 'message' => 'momId mancante']);
                    break;
                }
                sendJsonResponse(MomService::saveItemsOrder(['momId' => $momId, 'items' => $items]));
                break;

            case 'initializeItemsOrdering':
                // Utility per inizializzare ordinamento su items esistenti
                $momId = intval($input['momId'] ?? $input['mom_id'] ?? 0);
                sendJsonResponse(MomService::initializeItemsOrdering($momId));
                break;

            case 'cloneMom':
                $momId = intval($input['momId'] ?? $input['mom_id'] ?? 0);
                if ($momId <= 0) {
                    sendJsonResponse(['success' => false, 'message' => 'momId mancante o non valido']);
                    break;
                }
                sendJsonResponse(MomService::cloneMom($momId));
                break;

            case 'updateItemStatusFromTask':
                $taskId = intval($input['taskId'] ?? $input['task_id'] ?? 0);
                if (!$taskId) {
                    sendJsonResponse(['success' => false, 'message' => 'taskId mancante']);
                    break;
                }
                sendJsonResponse(MomService::updateItemStatusFromTask($taskId));
                break;

            case 'getItemDetails':
                $itemId = intval($input['itemId'] ?? $input['item_id'] ?? 0);
                sendJsonResponse(MomService::getItemDetails($itemId));
                break;

            case 'getItemChecklist':
                $itemId = intval($input['itemId'] ?? $input['item_id'] ?? 0);
                sendJsonResponse(MomService::getItemChecklist($itemId));
                break;

            case 'addChecklistItem':
                sendJsonResponse(MomService::addChecklistItem($input));
                break;

            case 'toggleChecklistItem':
                $checkId = intval($input['checkId'] ?? $input['id'] ?? 0);
                sendJsonResponse(MomService::toggleChecklistItem($checkId));
                break;

            case 'deleteChecklistItem':
                $checkId = intval($input['checkId'] ?? $input['id'] ?? 0);
                sendJsonResponse(MomService::deleteChecklistItem($checkId));
                break;

            case 'getItemActivity':
                $itemId = intval($input['itemId'] ?? $input['item_id'] ?? 0);
                sendJsonResponse(MomService::getItemActivity($itemId));
                break;

            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta in mom']);
                break;
        }


    case 'cv':
        switch ($action) {
            case 'list':
                sendJsonResponse(CvService::getCandidatesList($input));
                break;
            case 'detail':
                sendJsonResponse(CvService::getCandidateDetail((int) ($input['id'] ?? 0)));
                break;
            case 'upload':
                sendJsonResponse(CvService::handleUpload());
                break;
            case 'statistics':
                sendJsonResponse(CvService::getStatistics());
                break;
            case 'professions':
                sendJsonResponse(CvService::getProfessions());
                break;
            case 'update_status':
                sendJsonResponse(CvService::updateCandidateStatus((int) ($input['id'] ?? 0), $input['status'] ?? ''));
                break;
            case 'compare':
                sendJsonResponse(CvService::compareCandidates($input['ids'] ?? ''));
                break;
            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta per cv']);
                break;
        }
        exit;

    case 'checklists':
        // API GENERICA GLOBAL CHECKLISTS
        switch ($action) {
            case 'list':
                $entityType = trim($input['entityType'] ?? '');
                $entityId = (int) ($input['entityId'] ?? 0);
                sendJsonResponse(ChecklistService::listItems($entityType, $entityId));
                break;
            case 'add':
                $entityType = trim($input['entityType'] ?? '');
                $entityId = (int) ($input['entityId'] ?? 0);
                $label = trim($input['label'] ?? '');
                sendJsonResponse(ChecklistService::add($entityType, $entityId, $label));
                break;
            case 'toggle':
                $id = (int) ($input['id'] ?? 0);
                sendJsonResponse(ChecklistService::toggle($id));
                break;
            case 'delete':
                $id = (int) ($input['id'] ?? 0);
                sendJsonResponse(ChecklistService::delete($id));
                break;
            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta per checklists']);
                break;
        }
        exit;

    case 'nextcloud':
        // NEXTCLOUD PROXY & LISTING
        if (!userHasPermission('view_nextcloud')) {
            sendJsonResponse(['success' => false, 'message' => 'Permesso negato']);
        }

        switch ($action) {
            case 'list':
                $path = $input['path'] ?? '/INTRANET/';
                try {
                    $items = \Services\Nextcloud\NextcloudService::listFolder($path);
                    sendJsonResponse(['success' => true, 'data' => $items]);
                } catch (\Exception $e) {
                    sendJsonResponse(['success' => false, 'message' => $e->getMessage()]);
                }
                break;

            case 'file':
                $path = $_GET['path'] ?? $input['path'] ?? '';
                if (!$path)
                    exit('Path mancante');
                \Services\Nextcloud\NextcloudService::proxyFile($path);
                break;

            case 'thumb':
                $path = $_GET['path'] ?? $input['path'] ?? '';
                $w = (int) ($_GET['w'] ?? 400);
                $h = (int) ($_GET['h'] ?? 400);
                if (!$path)
                    exit('Path mancante');
                \Services\Nextcloud\NextcloudService::proxyThumbnail($path, $w, $h);
                break;

            default:
                sendJsonResponse(['success' => false, 'message' => 'Azione non riconosciuta in nextcloud']);
                break;
        }
        exit;

    default:
        sendJsonResponse(['error' => 'sezione non riconosciuta']);
}
