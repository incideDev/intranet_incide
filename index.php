<?php

// COSTANTE DI BOOTSTRAP (idempotente)
if (!defined('INTRANET_BOOTSTRAP')) {
    define('INTRANET_BOOTSTRAP', true);
}

// BOOTSTRAP CENTRALE (include autoload, functions, session)
require_once __DIR__ . '/core/bootstrap.php';

if ($database->LockedTime() > 0) {
    header("Location: /systemlock");
    exit;
}

// PRIMO ACCESSO: intercetta subito il link, carica solo la pagina e ferma tutto il resto
if (
    isset($_GET['page']) && $_GET['page'] === 'primo_accesso' &&
    !empty($_GET['token']) && strlen($_GET['token']) > 10
) {
    if (!defined('AccessoFileInterni')) {
        define('AccessoFileInterni', true);
    }
    require ROOT . '/MainPage/primo_accesso.php';
    exit;
}

// Costante di sicurezza globale (valida per TUTTI i file inclusi dopo)
if (!defined('AccessoFileInterni')) {
    define('AccessoFileInterni', true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? null;
    $action = $_POST['action'] ?? null;

    // Se non sono in POST, prova a recuperarli da JSON puro
    if (!$section || !$action) {
        $raw = file_get_contents('php://input');
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $section = $parsed['section'] ?? $section;
            $action = $parsed['action'] ?? $action;
            $input = $parsed;
        } else {
            $input = $_POST;
        }
    } else {
        $input = $_POST;
    }

    if ($section && $action) {
        require_once __DIR__ . '/service_router.php';
        exit;
    }
}

//------------------------------------------------------------------------
use Services\ContactService;
use Services\ProfileService;
use Services\HrService;
use Services\UserService;
use Services\FormsDataService;
use Services\RoleService;
//------------------------------------------------------------------------

$GetSection = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_SPECIAL_CHARS);
$Section = (!empty($GetSection) ? trim($GetSection) : '');

$GetPage = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS);
$Page = (!empty($GetPage) ? trim($GetPage) : '');

// LOGOUT va gestito SUBITO, prima di qualsiasi redirect/login
if ($Page === 'logout') {
    $_SESSION = [];

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
    );

    // Cancella cookie di sessione
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? $isHttps),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    // Cancella SOLO i cookie auth legacy (NON toccare remember_me)
    $expireCookie = [
        'expires' => time() - 3600,
        'path' => COOKIE_PATH,
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie('auth_dtuse', '', $expireCookie);
    setcookie('auth_token', '', $expireCookie);

    header('Location: /', true, 303);
    exit;
}

// Se non loggato, mostra la login (qui non si distrugge sessione a ogni hit)
if (!$Session->logged_in) {
    include 'MainPage/login.php';
    exit;
}

$_SESSION['user'] = $userData = $Session->userinfo;
$_SESSION['user_id'] = $userData['id'];

// Applica modalità manutenzione (dopo sessione, prima del routing)
enforceMaintenanceMode();

// Dopo che la sessione è già impostata e l'utente è loggato

if (
    $Session->logged_in === true &&
    (
        empty($_GET['section']) &&
        empty($_GET['page'])
    ) &&
    !(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
) {
    header("Location: index.php?section=home&page=home");
    exit;
}

// Solo nome ruolo per retrocompatibilità (ruoli/permessi vengono da core/session.php)
$_SESSION['role_name'] = '';

if (!empty($_SESSION['role_ids']) && is_array($_SESSION['role_ids'])) {
    $primoRuolo = (int) ($_SESSION['role_ids'][0] ?? 0);
    if ($primoRuolo > 0) {
        $_SESSION['role_name'] = $database
            ->query("SELECT name FROM sys_roles WHERE id = ?", [$primoRuolo], __FILE__)
            ->fetchColumn() ?: '';
    }
}

// Controlla se è una richiesta AJAX
$ajax_request = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (strpos($_SERVER['REQUEST_URI'], '/api/') !== false)
) ? true : false;

//------------------------------------------------------------------------

// Mappatura delle sezioni principali a una pagina predefinita
$defaultPages = [
    'gestione_intranet' => 'gestione_intranet',
    'collaborazione' => 'gestione_segnalazioni',
    'area-tecnica' => 'standard_progetti',
    'gestione' => 'contacts',
    'hr' => 'contacts',
    'commerciale' => 'estrazione_bandi',
];

// Aggiungi dinamicamente le aree root-hosted dal DocumentAreaRegistry
foreach (\Services\DocumentAreaRegistry::getRegistry() as $areaKey => $areaConf) {
    if ($areaConf['ui_host'] === 'root') {
        $defaultPages[$areaKey] = $areaKey; // es. archivio => archivio, qualita => qualita
    }
}

// Se manca la pagina, usa quella predefinita per la sezione e reindirizza
if (!$Page && isset($defaultPages[$Section])) {
    $Page = $defaultPages[$Section];
    header("Location: index.php?section={$Section}&page={$Page}");
    exit;
}

if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    error_log("DEBUG → \$Section: " . var_export($Section, true) . " | \$Page: " . var_export($Page, true));
    echo "<!-- DEBUG: Section=[" . htmlspecialchars($Section) . "] Page=[" . htmlspecialchars($Page) . "] -->\n";
}

// Inizio dello switch principale
$titolo_principale = '';

ob_start();
switch ($Page) {
    case 'home':
        $titolo_principale = 'HOME';
        // include 'views/home.php';
        break;

    case 'nextcloud':
        $titolo_principale = 'Nextcloud';
        include 'views/nextcloud.php';
        break;


    case 'gestione_intranet':
        $titolo_principale = 'Gestione Intranet';

        // Gestione sottopagine di gestione_intranet
        if ($Page === 'ruoli') {
            $titolo_principale = 'Gestione Ruoli';
            include 'views/gestione_intranet/ruoli.php';
            break;
        }

        include 'views/gestione_intranet/gestione_intranet.php';
        break;

    case 'segnalazioni_dashboard':
        $titolo_principale = 'Dashboard Segnalazioni';
        $statsRes = FormsDataService::listSegnalazioniStats($_SESSION ?? []);
        $forms = is_array($statsRes['stats'] ?? null) ? $statsRes['stats'] : [];
        include 'views/includes/form/dashboard_forms.php';
        break;

    case 'gestione_segnalazioni':
        $titolo_principale = 'Gestione Segnalazioni';
        include 'views/includes/form/gestione_segnalazioni.php';
        break;

    case 'archivio_segnalazioni':
        $titolo_principale = 'Archivio Segnalazioni';
        include 'views/includes/form/archivio_segnalazioni.php';
        break;

    case 'form_viewer':
        $formName = filter_input(INPUT_GET, 'form_name', FILTER_SANITIZE_SPECIAL_CHARS);
        $recordId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$formName || !$recordId) {
            echo "<div class='error'>parametri mancanti per visualizzare il modulo.</div>";
            return;
        }

        include 'views/includes/form/form_viewer.php';
        break;

    case 'view_form':
        $form_name = filter_input(INPUT_GET, 'form_name', FILTER_SANITIZE_SPECIAL_CHARS);
        if (!$form_name) {
            echo "<div class='error'>Nome modulo mancante.</div>";
            return;
        }
        include 'views/includes/form/view_form.php';
        break;

    case 'form':
        $form_name = filter_input(INPUT_GET, 'form_name', FILTER_SANITIZE_SPECIAL_CHARS);
        if (!$form_name) {
            echo "<div class='error'>Nome modulo mancante.</div>";
            return;
        }
        include 'views/includes/form/view_form.php';
        break;

    case 'form_listing':
    case 'generic_form_listing':
        $titolo_principale = 'Gestione Modulo';
        include 'views/includes/form/generic_form_listing.php';
        break;

    case 'moduli_admin':
        $titolo_principale = 'Gestione Moduli';
        include 'views/includes/form/moduli_admin.php';
        break;

    case 'form_editor':
        $form_name = filter_input(INPUT_GET, 'form_name', FILTER_SANITIZE_SPECIAL_CHARS);
        if (!$form_name) {
            echo "<div class='error'>Parametro 'form_name' mancante.</div>";
        } else {
            include 'views/includes/form/form_editor.php';
        }
        break;

    case 'impostazioni_moduli':
        $titolo_principale = 'Impostazioni Moduli';
        include 'views/includes/form/impostazioni_moduli.php';
        break;

    case 'page_editor':
        $titolo_principale = 'Editor Pagine & Menu';
        include 'views/gestione_intranet/page_editor.php';
        break;

    // case 'impostazioni_protocollo_email': // Rimosso: gestione spostata in Gestione Ruoli → Permessi avanzati

    case 'sys_datasources':
        $titolo_principale = 'Datasources (Whitelist DB)';
        include 'views/gestione_intranet/sys_datasources.php';
        break;

    case 'maintenance_settings':
        $titolo_principale = 'Modalità Manutenzione';
        include 'views/gestione_intranet/maintenance_settings.php';
        break;

    case 'maintenance_auth_check':
        include 'views/gestione_intranet/maintenance_auth_check.php';
        exit;
        break;

    case 'contacts':
        $titolo_principale = 'Contatti';
        $titolo_secondario = '';
        $titolo_completo = $titolo_principale;

        $roles = ContactService::getUniqueRoles();
        $departments = ContactService::getUniqueDepartments();
        $areas = ContactService::getUniqueAreas();
        $businessUnits = ContactService::getUniqueBusinessUnits();
        $activeProjects = ContactService::getAllActiveProjects();
        $competences = ContactService::getAllCompetences();
        $competenceAreas = ContactService::getAllCompetenceAreas();
        $contacts = ContactService::getContacts();

        include 'views/contacts.php';
        break;

    case 'organigram':
        include 'views/organigram.php';
        break;

    case 'area-tecnica':
        include 'views/pagina_vuota.php';
        break;

    case 'pagina_vuota':
        $titolo_principale = 'Pagina in fase di sviluppo';
        include 'views/pagina_vuota.php';
        break;

    case 'hr_dashboard':
        include 'views/HR_dashboard.php';
        break;

    case 'hr_area':
        include 'views/hr_area.php';
        break;

    case 'candidate_selection_kanban':
        include 'views/includes/hr_area/candidate_selection/candidate_selection_kanban.php';
        break;

    case 'job_profile':
        include 'views/includes/hr_area/job_gestione_profilo.php';
        break;

    case 'open_search':
        include 'views/includes/hr_area/open_search.php';
        break;

    case 'anagrafiche_hr':
        include 'views/includes/hr_area/anagrafiche_hr.php';
        break;

    case 'office_map':
        $titolo_principale = 'Mappa Ufficio (Admin)';
        include 'views/includes/office_map.php';
        break;

    case 'office_map_public':
        $titolo_principale = 'Mappa Ufficio Pubblica';
        include 'views/includes/office_map_public.php';
        break;

    case 'gestione_profilo':
        $titolo_principale = 'Gestione Profilo';
        include 'views/profile/gestione_profilo.php';
        break;

    case 'gestione_ruoli':
        $titolo_principale = 'Gestione Ruoli';
        include 'views/profile/gestione_ruoli.php';
        break;

    case 'reset_user':
        $titolo_principale = 'Gestione Utenti';
        include 'views/profile/reset_user.php';
        break;

    case 'centro_notifiche':
        $titolo_principale = 'Centro Notifiche';
        include 'views/centro_notifiche.php';
        break;

    case 'commessa':
        include 'views/includes/commesse/commessa.php';
        break;

    case 'commessa_kanban':
        $titolo_principale = 'Bacheca Commessa';
        include 'views/commesse.php';
        break;

    case 'commessa_crono':
        $titolo_principale = 'Cronoprogramma Commessa';
        include 'views/commessa_crono.php';
        break;

    case 'dashboard_commesse':
        $titolo_principale = 'Dashboard Commesse';
        include 'views/commesse_dashboard.php';
        break;

    case 'elenco_commesse':
        $titolo_principale = 'Elenco Commesse';
        include 'views/elenco_commesse.php';
        break;

    case 'archivio_commesse':
        $titolo_principale = 'Archivio Commesse';
        include 'views/archivio_commesse.php';
        break;

    case 'elenco_documenti':
        $titolo_principale = 'Elenco Documenti';
        include 'views/elenco_documenti.php';
        break;

    case 'dashboard_ore':
        $titolo_principale = 'Dashboard Ore';
        include 'views/dashboard_ore.php';
        break;

    case 'ore_business_unit':
        $titolo_principale = 'Ore per Business Unit';
        include 'views/ore_business_unit.php';
        break;

    case 'ore_dettaglio_utente':
        $titolo_principale = 'Dettaglio Utente';
        include 'views/ore_dettaglio_utente.php';
        break;

    case 'dashboard_economica':
        if (isset($_GET['debug'])) error_log("DEBUG: Entering dashboard_economica case");
        $titolo_principale = 'Dashboard Economica';
        include 'views/dashboard_economica.php';
        break;

    case 'protocollo_email':
        $titolo_principale = 'Protocollo Email';
        include 'views/protocollo_email.php';
        break;

    case 'gare':
        // Mantenuto per retrocompatibilità, reindirizza a estrazione_bandi
        header("Location: index.php?section=commerciale&page=estrazione_bandi");
        exit;
        break;

    case 'estrazione_bandi':
        $titolo_principale = 'Estrazione Bandi';
        include 'views/estrazione_bandi.php';
        break;

    case 'elenco_gare':
        $titolo_principale = 'Elenco Gare';
        include 'views/elenco_gare.php';
        break;

    case 'archivio_gare':
        $titolo_principale = 'Archivio Gare';
        include 'views/archivio_gare.php';
        break;

    case 'gare_dettaglio':
        $titolo_principale = 'Dettaglio Gara';
        include 'views/gare_dettaglio.php';
        break;

    case 'requisiti':
        $titolo_principale = 'Requisiti';
        include 'views/requisiti.php';
        break;

    //case 'offerte':
    //    $titolo_principale = 'Offerte';
    //    $controller = new OfferController($pdo);
    //    $controller->index();
    //    break;

    case 'changelog':
        $titolo_principale = 'Novità e Miglioramenti';
        include 'views/changelog/changelog.php';
        break;

    case 'changelog_admin':
        $titolo_principale = 'Gestione Changelog';
        include 'views/changelog/changelog_admin.php';
        break;

    case 'import_manager':
        $titolo_principale = 'Import Manager';
        include 'views/import_manager.php';
        break;

    case 'cv_manager':
        $titolo_principale = 'Gestione CV & Recruiting';
        include 'views/cv.php';
        break;

    case 'mom':
        $titolo_principale = 'Verbali Riunione (MOM)';
        // Supporta sia section=commerciale che section=collaborazione
        include 'views/mom.php';
        break;

    // Gestione pagine dinamiche Document Manager
    default:
        if (isset($_GET['debug'])) error_log("DEBUG: Entering DEFAULT case, Page=[" . $Page . "]");
        // Verifica se la Section è un'area documentale supportata tramite Registry
        if (\Services\DocumentAreaRegistry::isValid($Section)) {
            $config = \Services\DocumentAreaRegistry::getDocumentAreaConfig($Section);

            // Dashboard dell'area
            if ($Page === $Section) {
                $titolo_principale = 'Dashboard ' . $config['label'];
                $section = $Section;
                $documentArea = $Section;
                include 'views/document_manager.php';
                break;
            } else {
                // Pagina interna
                $stmt = $database->prepare("SELECT titolo, descrizione, colore, immagine FROM document_manager_pagine WHERE slug = ? AND section = ?");
                $stmt->execute([$Page, $Section]);
                $pagina = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($pagina) {
                    $titolo_principale = $pagina['titolo'];
                    $archivio_descrizione = $pagina['descrizione'];
                    $archivio_colore = $pagina['colore'] ?: $config['color'];
                    $section = $Section;
                    $documentArea = $Section;
                    include 'views/document_manager.php';
                    break;
                }
            }
        }

        // Gestione aree documentali hosted in altra sezione (es. formazione dentro hr)
        // Se $Page corrisponde a un'area documentale il cui ui_host === $Section
        $hostedAreaResolved = false;
        foreach (\Services\DocumentAreaRegistry::getRegistry() as $areaKey => $areaConf) {
            if ($areaConf['ui_host'] === $Section && $areaConf['ui_host'] !== 'root') {
                // Dashboard dell'area hosted (es. section=hr&page=formazione)
                if ($Page === $areaKey) {
                    $titolo_principale = 'Dashboard ' . $areaConf['label'];
                    $section = $areaKey;
                    $documentArea = $areaKey;
                    include 'views/document_manager.php';
                    $hostedAreaResolved = true;
                    break;
                }
                // Pagina interna dell'area hosted (es. section=hr&page=slug_corso)
                $stmt = $database->prepare("SELECT titolo, descrizione, colore, immagine FROM document_manager_pagine WHERE slug = ? AND section = ?");
                $stmt->execute([$Page, $areaKey]);
                $pagina = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($pagina) {
                    $titolo_principale = $pagina['titolo'];
                    $archivio_descrizione = $pagina['descrizione'];
                    $archivio_colore = $pagina['colore'] ?: $areaConf['color'];
                    $section = $areaKey;
                    $documentArea = $areaKey;
                    include 'views/document_manager.php';
                    $hostedAreaResolved = true;
                    break;
                }
            }
        }
        if ($hostedAreaResolved) {
            break;
        }

        echo "<div class='main-container'><div class='error'>Pagina non trovata o non più gestita.</div></div>";
        break;

}

$content = ob_get_clean();
if ($Page === 'primo_accesso') {
    exit($content);
}
if (!$ajax_request && strpos($_SERVER['REQUEST_URI'], '/api/') === false) {
    if (!defined('LAYOUT_INCLUDED')) {
        define('LAYOUT_INCLUDED', true);
        include 'views/layout/main.php';
    }
}
// COLLEGAMENTO AVVENUTO CON SUCCESSO  
/*
 * include ("MainPage/screen".($Session->codiceProgetto>0?"Prog":$Session->userinfo['detshop']).".php");
 *
    include ("MainPage/screen".($Session->codiceProgetto>0?"Prog":$Session->userinfo['detshop']).".php");

*/

// ENDPOINT DEBUG: ?action=debugAuth per vedere stato autorizzazione
if (isset($_GET['action']) && $_GET['action'] === 'debugAuth') {
    header('Content-Type: application/json');
    echo json_encode([
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'role_ids' => $_SESSION['role_ids'] ?? null,
        'is_admin' => isAdmin(),
        'role_id_legacy' => $_SESSION['role_id'] ?? null,
        'permissions' => $_SESSION['role_permissions'] ?? null,
        'permissions_count' => isset($_SESSION['role_permissions']) ? count($_SESSION['role_permissions']) : 0
    ]);
    exit;
}
