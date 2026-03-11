<?php
define('HostDbDataConnector', true); // deve essere PRIMA di qualsiasi include

$IS_PRIMO_ACCESSO = (
    isset($_GET['page']) && $_GET['page'] === 'primo_accesso' &&
    !empty($_GET['token']) && strlen($_GET['token']) > 10
);

if (
    !$IS_PRIMO_ACCESSO &&
    isset($_SERVER['HTTP_REFERER'])
) {
    $address = 'https://' . ($_SERVER['SERVER_NAME'] ?? '');
    $addressHttp = 'http://' . ($_SERVER['SERVER_NAME'] ?? '');
    if (
        strpos($_SERVER['HTTP_REFERER'], $address) !== 0 &&
        strpos($_SERVER['HTTP_REFERER'], $addressHttp) !== 0
    ) {
        header("Location:/");
        die();
    }
}

require_once("database.php");
require_once(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/core')) . '/core/functions.php');

//classe gestione sessioni 
class Session
{
    var $username;
    var $userid;
    var $userlevel;
    var $time;
    var $logged_in;
    var $userinfo = null;
    var $url;
    var $referrer;
    var $CSRFtoken;
    var $azienda;
    var $level;
    var $tableid;

    function __construct()
    {
        $this->time = time();
        $this->startSession();
    }

    function startSession()
    {
        global $database;
        ini_set('session.name', SS_NAME);

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        );

        // Cookie della sessione: stessi criteri dei cookie auth
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => COOKIE_PATH,
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        $REMOTE_ADDR = filter_input(INPUT_SERVER, 'REMOTE_ADDR');

        $this->level = array('1' => '(minimo)', '2' => '', '3' => '', '4' => '', '5' => '(intermedio)', '6' => '', '7' => '', '8' => '(massimo)', '9' => '(IT - sistema sviluppo)');
        $this->azienda = $database->anagrafica();
        $this->logged_in = $this->checkLogin();

        // Se non loggato, tenta auto-login da cookie "Ricordami"
        // MA solo se NON siamo sulla pagina di login (per mostrare "Bentornato" invece)
        if ($this->logged_in === false) {
            $isLoginPage = $this->isLoginPage();
            if (!$isLoginPage && tryAutoLoginFromRememberCookie()) {
                // Auto-login riuscito, ricarica userinfo
                $this->logged_in = true;
                $this->userinfo = $database->getUserInfo($_SESSION['username']);
                $this->tableid = $_SESSION['user_id'];
            }

            // Pulisci token scaduti occasionalmente (1% delle richieste)
            if (mt_rand(1, 100) === 1) {
                cleanupExpiredRememberTokens();
            }
        }

        if ($this->logged_in === true) {
            if (empty($_SESSION['CSRFtoken'])) {
                $_SESSION['CSRFtoken'] = bin2hex(random_bytes(32));
            }

            $_SESSION["cookietime"] = "GST" . $this->azienda["pariva"];
            $this->level = array('1' => '(minimo)', '2' => '', '3' => '', '4' => '', '5' => '(agente)', '6' => '', '7' => '', '8' => '(massimo)', '9' => '(sistema - bss)');

            $database->addActiveUser($this->userinfo["username"], $this->time, $this->userinfo["ruolo"] ?? 'undefined', $this->userinfo["role_id"]);
        } else {
            $_SESSION['username'] = GUEST_NAME;
            $this->userlevel = GUEST_LEVEL;
            $database->addActiveGuest($REMOTE_ADDR, $this->time);
        }

        if ($this->logged_in === true) {
            $userId = (int)$this->userinfo['id'];
            $username = $this->userinfo['username'] ?? 'unknown';

            // UNICO BOOTSTRAP: carica ruoli e permessi multi-ruolo
            $roleIds = \Services\RoleService::getRoleIdsByUserId($userId);

            // VALIDAZIONE: utente deve avere almeno un ruolo assegnato
            // NOTA: Non tentiamo migrazione legacy perché users.role_id è NULL per tutti nel dump
            if (empty($roleIds)) {
                // LOG DETTAGLIATO per debug (sempre)
                $queryResult = [];
                try {
                    $checkQuery = $database->query(
                        "SELECT role_id FROM sys_user_roles WHERE user_id = ?",
                        [$userId],
                        __FILE__
                    );
                    if ($checkQuery) {
                        $queryResult = $checkQuery->fetchAll(\PDO::FETCH_COLUMN);
                    }
                } catch (\Throwable $e) {
                    $queryResult = ['error' => $e->getMessage()];
                }
                
                error_log(sprintf(
                    "[AUTH ERROR] Utente senza ruoli: user_id=%d, username=%s, sys_user_roles_result=%s, userinfo_keys=%s, __FILE__=%s",
                    $userId,
                    $username,
                    json_encode($queryResult),
                    json_encode(array_keys($this->userinfo ?? [])),
                    __FILE__
                ));
                
                // Verifica se logout() esiste (audit)
                $hasLogout = method_exists($this, 'logout');
                if (defined('APP_ENV') && APP_ENV === 'dev') {
                    error_log(sprintf(
                        "[AUTH DEBUG] method_exists(logout)=%s, __FILE__=%s",
                        $hasLogout ? 'yes' : 'no',
                        __FILE__
                    ));
                }

                // UI pulita: messaggio semplice
                echo "<div style='color:red; padding:20px; border:1px solid red; margin:20px; background:#ffeaea;'>";
                echo "<h3>Accesso non autorizzato</h3>";
                echo "<p>Configurazione utente incompleta.</p>";
                echo "<p>L'utente non ha ruoli assegnati. Contattare un amministratore per assegnare ruoli in <strong>Gestione Ruoli</strong>.</p>";
                echo "</div>";

                // Logout forzato per sicurezza (con fallback se metodo non esiste)
                if ($hasLogout) {
                    $this->logout();
                } else {
                    // Fallback inline: pulizia sessione e cookie
                    $_SESSION = [];
                    
                    $isHttps = (
                        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
                    );
                    
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        if (ini_get('session.use_cookies')) {
                            $params = session_get_cookie_params();
                            setcookie(session_name(), '', [
                                'expires'  => time() - 42000,
                                'path'     => $params['path'] ?? '/',
                                'domain'   => $params['domain'] ?? '',
                                'secure'   => (bool)($params['secure'] ?? $isHttps),
                                'httponly' => (bool)($params['httponly'] ?? true),
                                'samesite' => $params['samesite'] ?? 'Lax',
                            ]);
                        }
                        session_destroy();
                    }
                    
                    $expireCookie = [
                        'expires'  => time() - 3600,
                        'path'     => COOKIE_PATH,
                        'secure'   => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ];
                    setcookie('auth_dtuse', '', $expireCookie);
                    setcookie('auth_token', '', $expireCookie);
                    
                    $this->logged_in = false;
                    $this->userinfo = null;
                }
                exit;
            }

            $_SESSION['role_ids'] = $roleIds;

            // Retrocompatibilità: primo ruolo come role_id singolo (NON usare per auth)
            $_SESSION['role_id'] = $roleIds[0] ?? 0;

            // Permessi: somma di tutti i ruoli dell'utente
            $_SESSION['role_permissions'] = \Services\RoleService::getPermissionsByRoleIds($roleIds);
        }

        $database->removeInactiveUsers();
        $database->removeInactiveGuests();
    }
    function checkLogin()
    {
        global $database;

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        );

        // 1) Se non abbiamo sessione utente ma abbiamo cookie auth, tentiamo bootstrap della sessione
        if (
            empty($_SESSION['user_id']) &&
            !empty($_COOKIE['auth_token']) &&
            !empty($_COOKIE['auth_dtuse'])
        ) {
            $decryptedUsername = openssl_decrypt((string) $_COOKIE['auth_dtuse'], 'AES-128-ECB', COOKIE_SEED);

            // Se decrypt fallisce, non provare nemmeno: pulisci cookie e stop
            if (!is_string($decryptedUsername) || $decryptedUsername === '') {
                $expireCookie = [
                    'expires' => time() - 3600,
                    'path' => COOKIE_PATH,
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ];
                setcookie('auth_dtuse', '', $expireCookie);
                setcookie('auth_token', '', $expireCookie);
                return false;
            }

            $_SESSION['token'] = (string) $_COOKIE['auth_token'];
            $_SESSION['username'] = $decryptedUsername;
            $CookieLogin = true;
        }

        // 2) Se abbiamo username+token in sessione, validiamo
        if (!empty($_SESSION['username']) && !empty($_SESSION['token']) && $_SESSION['username'] !== GUEST_NAME) {
            $username = (string) $_SESSION['username'];
            $token = (string) $_SESSION['token'];

            if ($database->confirmUserID($username, $token) != 0) {
                unset($_SESSION['username'], $_SESSION['token'], $_SESSION['user_id']);

                $expireCookie = [
                    'expires' => time() - 3600,
                    'path' => COOKIE_PATH,
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ];
                setcookie('auth_dtuse', '', $expireCookie);
                setcookie('auth_token', '', $expireCookie);

                return false;
            }

            $this->userinfo = $database->getUserInfo($username);
            if (empty($this->userinfo) || empty($this->userinfo['id'])) {
                // In caso di user non trovato (coerenza DB), chiudi tutto
                unset($_SESSION['username'], $_SESSION['token'], $_SESSION['user_id']);

                $expireCookie = [
                    'expires' => time() - 3600,
                    'path' => COOKIE_PATH,
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ];
                setcookie('auth_dtuse', '', $expireCookie);
                setcookie('auth_token', '', $expireCookie);

                return false;
            }

            $this->tableid = $_SESSION['user_id'] = (int) $this->userinfo['id'];

            // 3) Se l’accesso era da cookie, ruota token e aggiorna cookie (hardening)
            if (!empty($CookieLogin)) {
                $this->userinfo['auth_token'] = bin2hex(random_bytes(32));
                $database->updateUserField($username, 'auth_token', $this->userinfo['auth_token']);

                $userCookie = openssl_encrypt($username, 'AES-128-ECB', COOKIE_SEED);

                $setCookie = [
                    'expires' => time() + COOKIE_EXPIRE,
                    'path' => COOKIE_PATH,
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ];
                setcookie('auth_dtuse', (string) $userCookie, $setCookie);
                setcookie('auth_token', (string) $this->userinfo['auth_token'], $setCookie);
            }

            return true;
        }

        return false;
    }
    function login($Dati)
    {
        global $database;
        if ($this->azienda['acclog'] == 1) {
            $database->writelog($Dati["user"]);
        }

        $Errori = [];

        if (!$Dati["user"] || strlen($Dati["user"] = trim($Dati["user"])) == 0) {
            $Errori["user"] = "*Nome utente non inserito";
        }

        if (!$Dati["pass"] || strlen($Dati["pass"] = trim($Dati["pass"])) == 0) {
            $Errori["pass"] = "*Password non inserita";
        }

        $Args = func_get_args();
        foreach ($Args[0] as $k => $Val) {
            if ($Val !== null && preg_match("/([<|'>])/", $Val)) {
                $Errori[$k] = "*Carattere non ammesso";
            }
        }

        if (count($Errori) > 0)
            return ["error" => $Errori];

        $result = $database->confirmUserPass($Dati["user"], $Dati["pass"]);
        if ($result == 1)
            $Errori["user"] = "*Nome utente non riconosciuto";
        if ($result == 2)
            $Errori["pass"] = "*password Invalida";
        if (count($Errori) > 0)
            return ["error" => $Errori];

        $_SESSION = [];

        $REMOTE_ADDR = filter_input(INPUT_SERVER, 'REMOTE_ADDR');
        $HTTP_USER_AGENT = filter_input(INPUT_SERVER, 'HTTP_USER_AGENT');

        $this->userinfo = $database->getUserInfo($Dati["user"]);
        $_SESSION['username'] = $this->userinfo['username'];
        $this->tableid = $_SESSION['user_id'] = $this->userinfo['id'];
        $_SESSION['token'] = $this->userinfo['auth_token'] = bin2hex(random_bytes(32));

        $_SESSION['IPaddress'] = $REMOTE_ADDR;
        $_SESSION['userAgent'] = $HTTP_USER_AGENT;

        $database->updateUserField($this->userinfo["username"], "auth_token", $this->userinfo['auth_token']);
        $database->addActiveUser($this->userinfo["username"], $this->time, $this->userinfo['ragsoc'], $this->userinfo['role_id']);
        $database->removeActiveGuest($REMOTE_ADDR);

        // Gestione "Ricordami" con nuovo sistema sicuro (selector:validator)
        if ($Dati["remember"]) {
            // Usa il nuovo sistema con tabella sys_user_remember_tokens
            createRememberToken((int) $this->userinfo['id']);
        }
        // Nota: non cancelliamo i cookie se remember=false,
        // così l'utente che torna al login vede "Bentornato"

        return true;
    }

    function convUtf8($d)
    {
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                $d[$k] = $this->convUtf8($v);
            }
        } elseif (is_string($d)) {
            return mb_convert_encoding($d, 'UTF-8', 'ISO-8859-1');
        }
        return $d;
    }

    /**
     * Verifica se siamo sulla pagina di login.
     * Usato per decidere se fare auto-login o mostrare "Bentornato".
     *
     * @return bool
     */
    function isLoginPage(): bool
    {
        // Controlla se il file corrente è login.php
        $scriptName = filter_input(INPUT_SERVER, 'SCRIPT_NAME') ?? '';

        // Se stiamo accedendo direttamente a MainPage/login.php
        if (stripos($scriptName, 'login.php') !== false) {
            return true;
        }

        $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS);
        $section = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_SPECIAL_CHARS);

        // Se page=login o page=logout (dopo logout si mostra la login)
        if ($page === 'login' || $page === 'logout') {
            return true;
        }

        // Se nessun parametro e nessuna sessione, verrà mostrato login
        if (empty($page) && empty($section) && empty($_SESSION['user_id'])) {
            return true;
        }

        return false;
    }

    /**
     * Esegue il logout distruggendo la sessione e i cookie di autenticazione.
     */
    function logout(): void
    {
        // Pulisci tutte le variabili di sessione
        $_SESSION = [];

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );

        // Cancella cookie di sessione
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();

                setcookie(session_name(), '', [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'] ?? '/',
                    'domain'   => $params['domain'] ?? '',
                    'secure'   => (bool)($params['secure'] ?? $isHttps),
                    'httponly' => (bool)($params['httponly'] ?? true),
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]);
            }

            session_destroy();
        }

        // Cancella cookie auth legacy
        $expireCookie = [
            'expires'  => time() - 3600,
            'path'     => COOKIE_PATH,
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie('auth_dtuse', '', $expireCookie);
        setcookie('auth_token', '', $expireCookie);

        // Reset stato logged_in
        $this->logged_in = false;
        $this->userinfo = null;
    }
}

// la sessione iniziale è sempre inizializzata
$Session = new Session;
