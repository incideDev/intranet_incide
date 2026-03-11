<?php
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
        exit;
        die();
    }
}

/*
 * Verifica il file viene richiamato/includo dal file core principale
 */
if (!defined('HostDbDataConnector')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    exit('Unauthorized');
}
/**
 * Database.php
 * 
 * The Database class is meant to simplify the task of accessing
 * information from the website's database.
 */
include("constants.php");

class MySQLDB
{
    var $connection;         //The MySQL database connection
    var $num_active_users;   //Number of active users viewing site
    var $num_active_guests;  //Number of active guests viewing site
    var $num_members;        //Number of signed-up users
    var $ImpostaQuery;
    var $ip;
    var $porta;
    var $user_agent;
    var $script_filename;
    var $http_host;
    var $server_name;
    var $level;
    var $azienda;
    /* Note: call getNumMembers() to access $num_members! */

    /* Class constructor */
    function __construct()
    {
        /* Make connection to database */
        //$this->connection = new mysqli(DB_SERVER, DB_USER, DB_PASS, DB_NAME); 
        //if($this->connection===false){
        //    exit("UNABLE TO CONNECT - CONNECTION REFUSED");
        //} 

        try {
            $this->connection = new PDO(DB_DNS . ';charset=utf8mb4', DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);
        } catch (PDOException $e) {
            // Registra l'errore in un file di log
            $this->log_error("Errore connessione al database: " . $e->getMessage());
            // Mostra un messaggio generico all'utente
            echo 'Si è verificato un problema di connessione al database. Contattare l\'assistena.';
            exit;
        }

        /**
         * Only query database to find out number of members
         * when getNumMembers() is called for the first time,
         * until then, default value set.
         */
        $this->num_members = -1;

        if (TRACK_VISITORS) {
            /* Calculate number of users at site */
            $this->calcNumActiveUsers();
            /* Calculate number of guests at site */
            $this->calcNumActiveGuests();
        }
        $SVR = filter_input_array(INPUT_SERVER);
        $this->ip = $SVR['REMOTE_ADDR'];
        $this->porta = $SVR['REMOTE_PORT'];
        $this->user_agent = $SVR['HTTP_USER_AGENT'];
        $this->script_filename = $SVR["SCRIPT_FILENAME"];
        $this->http_host = $SVR['HTTP_HOST'];
        $this->server_name = $SVR['SERVER_NAME'];
        $this->ClearLog();
    }

    function anagrafica()
    {
        /* recupera i dati aziendali  */
        $Res = $this->query("SELECT * FROM " . SYS_AZIENDA . " WHERE 1 LIMIT 1", [], __FILE__ . " ==> " . __LINE__);
        $db = $Res->fetch(PDO::FETCH_ASSOC);
        return $db;
    }


    /**
     * confirmUserPass - Checks whether or not the given
     * username is in the database, if so it checks if the
     * given password is the same password in the database
     * for that user. If the user doesn't exist or if the
     * passwords don't match up, it returns an error code
     * (1 or 2). On success it returns 0.
     */
    function confirmUserPass($username, $password)
    {
        $this->IsLocked();
        /* Verify that user is in database */
        $q = "SELECT password FROM " . SYS_USERS . " WHERE username = :username AND disabled='0'  ";
        $result = $this->query($q, [':username' => $username], __FILE__ . " ==> " . __LINE__);
        $db = $result->fetch(PDO::FETCH_ASSOC);
        if (!$db || !isset($db["password"])) {
            $this->loginErrorAddLog();
            return 1; //Indicates username failure
        }

        /* Validate that password is correct */
        if (password_verify($password, $db["password"]) == true) {
            //$this->ClearLog();
            return 0; //Success! Username and password confirmed
        } else {
            $this->loginErrorAddLog();
            return 2; //Indicates password failure
        }
    }

    /*
     * IsLocked - verifica se l'IP è bloccato nel database principale (lock locale).
     */
    protected function IsLocked()
    {
        if (IPLOCK_ATTIVO === 0) {
            return false;
        }
        $sql = "SELECT * FROM locked_ip WHERE ip = ? AND log>=" . SITE_MAX_CHANCE;
        $res = $this->query($sql, [$this->ip], __FILE__ . " ==> " . __LINE__);
        if ($res && $res->fetch(PDO::FETCH_ASSOC) !== false) {
            header("Location: locked.php");
            die;
        }
        return false;
    }
    /* AddLog. questa funzione 	*/
    protected function loginErrorAddLog()
    {
        if (IPLOCK_ATTIVO === 0) {
            return true;
        }

        $time = time();
        $q = "SELECT * FROM locked_ip WHERE ip=? ";
        $res = $this->query($q, [$this->ip], __FILE__ . " ==> " . __LINE__);
        if ($res && $res->fetch(PDO::FETCH_ASSOC) !== false) {
            $query = "UPDATE locked_ip SET ts = ?, log=log+1 WHERE ip = ?";
            $Tipo = 'ss';
            $Dati = [$time, $this->ip];
        } else {
            $query = "INSERT INTO locked_ip (ip, ts, log, porta, user_agent, http_host ,server_name, script_filename )"
                . " VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $Tipo = 'ssisssss';
            $Dati = [$this->ip, $time, 1, $this->porta, $this->user_agent, $this->http_host, $this->server_name, $this->script_filename];
        }
        $this->query($query, $Dati, __FILE__ . " ==> " . __LINE__);

        $result = $this->query("SELECT * FROM locked_ip WHERE email=0 AND ip = ? AND log >= ? ", [$this->ip, SITE_MAX_CHANCE], __FILE__ . " ==> " . __LINE__);

        if ($result && $result->fetch(PDO::FETCH_ASSOC) !== false) {
            if (SITE_SEND_MAIL_ALERT) {
                $body = "Rilevati " . SITE_MAX_CHANCE . " tentativi errati di login al gestionale.\r\n";
                $body .= "L'accesso &egrave; stato bloccato all'IP " . $this->ip . "\r\n";
                $body .= "Alle ore: " . date("H:i");
                mail(SITE_ADMIN_MAIL, "Blocco di sicurezza", $body, "From: sito");
                $this->query("UPDATE locked_ip SET email=1 WHERE ip = ? ", [$this->ip], __FILE__ . " ==> " . __LINE__);
            }
        }
    }
    protected function ClearLog()
    {
        if (IPLOCK_ATTIVO === 0) {
            return true;
        }
        if (isset($_SESSION['hardlock'])) {
            unset($_SESSION['hardlock']);
        }
        if (isset($_SESSION['permalock'])) {
            unset($_SESSION['permalock']);
        }

        $time1 = time() - SITE_RESET_TIME;
        $sql = "DELETE FROM locked_ip WHERE ts < :time ";
        $this->query($sql, [':time' => $time1], __FILE__ . " ==>" . __LINE__);
    }
    public function LockedTime()
    {
        if (IPLOCK_ATTIVO === 0) {
            return false;
        }
        $sql = "SELECT ts FROM locked_ip WHERE ip = ? AND log >= ? ";
        $res = $this->query($sql, [$this->ip, SITE_MAX_CHANCE], __FILE__ . " ==>" . __LINE__);
        $row = $res ? $res->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && isset($row["ts"])) {
            $sec = $row['ts'] + SITE_RESET_TIME - time();
            $min = intval($sec / 60);
            switch ($min) {
                case 0:
                    return "meno di 1 minuto";
                    break;
                case 1:
                    return "1 minuto";
                    break;
                default:
                    return $min . " minuti";
            }
        }
        return false;
    }

    /**
     * confirmUserID - Checks whether or not the given
     * username is in the database, if so it checks if the
     * given userid is the same userid in the database
     * for that user. If the user doesn't exist or if the
     * userids don't match up, it returns an error code
     * (1 or 2). On success it returns 0.
     */
    function confirmUserID($username, $token)
    {
        $Res = $this->query(
            "SELECT * FROM " . SYS_USERS . " WHERE username = :username AND auth_token = :auth_token LIMIT 1",
            [':username' => $username, ':auth_token' => $token],
            __FILE__ . " ==> " . __LINE__
        );
        $dbUser = $Res->fetch(PDO::FETCH_ASSOC);
        if ($dbUser === false || empty($dbUser["auth_token"])) {
            /*
             * Registert error log to lock/ban user after N try
             */
            //$this->loginErrorAddLog();
            return 1; //Indicates username failure
        }
        /* Validate that userid is correct */
        if ($token == $dbUser['auth_token']) {
            return 0; //Success! Username and userid confirmed
        } else {
            return 2; //Indicates userid invalid
        }
    }

    /**
     * usernameTaken - Returns true if the username has
     * been taken by another user, false otherwise.
     */
    function usernameTaken($username)
    {
        $Res = $this->query("SELECT username FROM " . SYS_USERS . " WHERE username = :username ", [':username' => $username], __FILE__ . " ==> " . __LINE__);
        return ($Res->fetchColumn() > 0);
    }

    /**
     * usernameBanned - Returns true if the username has
     * been banned by the administrator.
     */
    function usernameBanned($username)
    {
        $Res = $this->query("SELECT count(*) FROM " . SYS_BANNED_USERS . " WHERE username = :username ", [':username' => $username], __FILE__ . " ==> " . __LINE__);
        return ($Res->fetchColumn() > 0);
    }

    /**
     * updateUserField - Updates a field, specified by the field
     * parameter, in the user's row of the database.
     */
    function updateUserField($username, $field, $value)
    {
        $q = "UPDATE " . SYS_USERS . " SET " . $field . " = :value WHERE username = :username ";
        return $this->query($q, [':value' => $value, ':username' => $username], __FILE__ . " ==> " . __LINE__);
    }

    /**
     * getUserInfo - Returns the result array from a mysql
     * query asking for all information stored regarding
     * the given username. If query fails, NULL is returned.
     */
    function getUserInfo($username)
    {
        $q = "SELECT 
                " . SYS_USERS . ".*, 
                " . PERSONALE . ".Nominativo AS nominativo, 
                " . PERSONALE . ".Email_Aziendale AS email, 
                sur.role_id AS role_id, 
                sr.name AS ruolo
            FROM " . SYS_USERS . "
            LEFT JOIN " . PERSONALE . " ON " . SYS_USERS . ".id = " . PERSONALE . ".user_id
            LEFT JOIN sys_user_roles sur ON sur.user_id = " . SYS_USERS . ".id
            LEFT JOIN sys_roles sr ON sr.id = sur.role_id
            WHERE " . SYS_USERS . ".username = :username AND " . SYS_USERS . ".disabled = '0'";

        $result = $this->query($q, [':username' => $username], __FILE__ . " ==> " . __LINE__);
        if (!$result) {
            return false;
        }

        $Data = $result->fetch(PDO::FETCH_ASSOC);

        // Rigenera il path immagine profilo se esiste il nominativo
        if ($Data && isset($Data['nominativo']) && trim($Data['nominativo']) !== '') {

            $imagePath = getProfileImage($Data['nominativo'], 'nominativo');

            // Se l'immagine trovata NON è quella di default, usala
            if ($imagePath !== 'assets/images/default_profile.png') {
                $Data['profile_picture'] = $imagePath;
            }
        }

        return $Data;
    }

    function getUtenti()
    {
        global $database;
        $result = $database->query("SELECT ragsoc, email, token FROM " . SYS_USERS . " ", [], __FILE__ . " ==> " . __LINE__);
        if (!$result) {
            return FALSE;
        }
        while ($u = $result->fetch(PDO::FETCH_ASSOC)) {
            $U[$u["tableID"]] = $u["ragsoc"];
        }
        return $U;
    }

    /**
     * getConfigurazione - Ritorna un array contenetne le impostazioni sulla configurazione aziendale.
     * quale i mastri prefissati, aliquote iva perminenti e listino base.
     */
    function getConfigurazione()
    {
        //dati aziendali 
        $result = $this->query("SELECT * FROM " . SYS_AZIENDA . " WHERE 1", [], __FILE__ . " ==> " . __LINE__);
        /* Error occurred, return given name by default */
        if (!$result) {
            return false;
        }
        $db = $result->fetch(PDO::FETCH_ASSOC);
        $result->closeCursor();
        return ($db !== false) ? $db : false;
    }

    /**
     * getNumMembers - Returns the number of signed-up users
     * of the website, banned members not included. The first
     * time the function is called on page load, the database
     * is queried, on subsequent calls, the stored result
     * is returned. This is to improve efficiency, effectively
     * not querying the database when no call is made.
     */
    function getNumMembers()
    {
        if ($this->num_members < 0) {
            $result = $this->query("SELECT count(*) FROM " . SYS_USERS, [], __FILE__ . " ==>" . __LINE__);
            $this->num_members = $result ? (int) $result->fetchColumn() : 0;
        }
        return $this->num_members;
    }

    /**
     * calcNumActiveUsers - Finds out how many active users
     * are viewing site and sets class variable accordingly.
     */
    function calcNumActiveUsers()
    {
        /* Calculate number of users at site */
        $result = $this->query("SELECT count(*) FROM " . SYS_ACTIVE_USERS, [], __FILE__ . " ==>" . __LINE__);
        $this->num_active_users = 0;//($result?$result->fetchColumn():0);
    }
    /**
     * ShowActiveUsers - Finds out how many active users
     * are viewing site and sets class variable accordingly.
     */
    function showNumActiveUsers()
    {
        /* Show number of users at site */
        $result = $this->query("SELECT * FROM " . SYS_ACTIVE_USERS . " ORDER BY description ASC", [], __FILE__ . " ==>" . __LINE__);
        return $result;
    }

    /**
     * calcNumActiveGuests - Finds out how many active guests
     * are viewing site and sets class variable accordingly.
     */
    function calcNumActiveGuests()
    {
        /* Calculate number of guests at site */
        $Res = $this->query("SELECT COUNT(*) FROM " . SYS_ACTIVE_GUESTS, [], __FILE__ . " ==>" . __LINE__);
        $count = $Res ? $Res->fetchColumn() : false;
        $this->num_active_guests = ($count !== false) ? (int) $count : 0;
    }






    /**
     * addActiveUser - Updates username's last active timestamp
     * in the database, and also adds him to the table of
     * active users, or updates timestamp if already there.
     */
    function addActiveUser($username, $time, $description, $role_id)
    {
        $qUser = "UPDATE " . SYS_USERS . " SET timestamp = :timestamp  WHERE username = :username ";
        $this->query($qUser, [':timestamp' => $time, ':username' => $username], __FILE__ . " ==>" . __LINE__);
        if (!TRACK_VISITORS) {
            return;
        }
        $qGuest = "REPLACE INTO " . SYS_ACTIVE_USERS . " (username, timestamp, description, role_id) VALUES (:username, :timestamp, :description, :role_id)";
        $this->query($qGuest, [
            ':username' => $username,
            ':timestamp' => $time,
            ':description' => $description,
            ':role_id' => $role_id
        ], __FILE__ . " ==>" . __LINE__);
        $this->calcNumActiveUsers();
    }

    /* addActiveGuest - Adds guest to active guests table */
    function addActiveGuest($ip, $time)
    {
        if (!TRACK_VISITORS) {
            return;
        }
        $stmt = $this->connection->prepare("REPLACE INTO " . SYS_ACTIVE_GUESTS . " VALUES (:ip,:timestamp)");
        $stmt->execute([':ip' => $ip, ':timestamp' => $time]);
        $this->calcNumActiveGuests();
    }

    /* These functions are self explanatory, no need for comments */

    /* removeActiveUser */
    function removeActiveUser($username)
    {
        if (!TRACK_VISITORS) {
            return;
        }
        $q = "DELETE FROM " . SYS_ACTIVE_USERS . " WHERE username = :username ";
        $this->query($q, [':username' => $username], __FILE__ . " ==>" . __LINE__);
        $this->calcNumActiveUsers();
    }

    /* removeActiveGuest */
    function removeActiveGuest($ip)
    {
        if (!TRACK_VISITORS) {
            return;
        }
        $this->query("DELETE FROM " . SYS_ACTIVE_GUESTS . " WHERE ip = :ip ", [':ip' => $ip], __FILE__ . " ==>" . __LINE__);
        $this->calcNumActiveGuests();
    }

    /* removeInactiveUsers */
    function removeInactiveUsers()
    {
        if (!TRACK_VISITORS) {
            return;
        }
        $timeout = time() - USER_TIMEOUT * 60;
        $this->query("DELETE FROM " . SYS_ACTIVE_USERS . " WHERE timestamp < :timestamp ", [':timestamp' => $timeout], __FILE__ . " ==>" . __LINE__);
        $this->calcNumActiveUsers();
    }

    /* removeInactiveGuests */
    function removeInactiveGuests()
    {
        if (!TRACK_VISITORS) {
            return;
        }
        $timeout = time() - GUEST_TIMEOUT * 60;
        $this->query("DELETE FROM " . SYS_ACTIVE_GUESTS . " WHERE timestamp < :timestamp ", [':timestamp' => $timeout], __FILE__ . " ==>" . __LINE__);
        $this->calcNumActiveGuests();
    }

    /*
     * writelog(). funzione per scrivere il log di accesso
     */
    function writelog($subuser)
    {
        $q = "INSERT INTO " . SYS_LOG_ACCESSO . " (ip, porta, agent, user) VALUES (:ip, :porta, :agent, :user)";
        $this->query($q, [':ip' => $this->ip, ':porta' => $this->porta, ':agent' => $this->user_agent, ':user' => $subuser], __FILE__ . " ==>" . __LINE__);
    }

    /*
     * log_error. funzione per scrivere su disco eventuali errori non scrivibili su database
     */
    function log_error($message)
    {
        if (!file_exists(ROOT . '/logs/') || !is_writeable(ROOT . '/logs/')) {
            if (!mkdir(ROOT . '/logs/', 0755)) {
                die('Errore permessi per generare la cartella di LOG. Impossibile registrare il messaggio: ' . $message);
            }
        }
        $log_file = ROOT . '/logs/errors.log';
        error_log($message . "\n", 3, $log_file);
    }

    /**
     * query - Performs the given query on the database and
     * returns the result (PDOStatement or false).
     * Signature: query($sql, $params, $filerow)
     * - $params: array o null per query senza placeholder
     * - $filerow: stringa per log errori (es. __FILE__ . " ==> " . __LINE__)
     */
    function query($query, $params = null, $filerow = null, $AF = null)
    {
        global $mailer;
        $actualParams = is_array($params) ? $params : null;
        $stmt = $this->connection->prepare($query);
        if ($stmt) {
            $stmt->execute($actualParams);
            if ($AF === 1) {
                $AffectedRows = $stmt->rowCount();
            }
            if (DEBUG_OK) {
                $this->queryLog($query, 0);
            }
            if ($AF === 1) {
                return ["result" => $stmt, "af" => $AffectedRows];
            }
            return $stmt;
        } else {
            $ERn = intval($this->query_errorno());
            echo "<br />/////////////////////";
            echo "<br />" . $filerow;
            echo "<br />" . $query;
            echo "<br />" . $ERn . ' ' . $this->query_error();
            echo "<br />" . $this->query_error();
            exit;
            if ($ERn != '2006' && $ERn != '1317' && $ERn != '2013') {
                if (DEBUG_ERROR) {
                    $this->queryLog($query, 1, $this->query_error());
                }
                if (SEND_MAIL_ALERT) {
                    $mailer->sendError($query, $ERn . ' ' . $this->query_error(), $filerow);
                }
            }
            return false;
        }
    }


    function return_result($result)
    {
        if ($result) {
            $dbarray = $result->fetch(PDO::FETCH_ASSOC);
            $result->closeCursor();
            return $dbarray;
        } else {
            return false;
        }
    }
    function cleanData($value)
    {
        if (isset($value)) {
            if (!is_null($value)) {
                $data = trim($value);
                $data = stripslashes($data);
                $data = addslashes($data);
            } else {
                $data = "";
            }
        } else {
            $data = false;
        }
        return $data;
    }
    /**
     * (PHP 8)<br/>
     * preleva gli array di dati e genera la query appropriata in base ai dati inseriti.<br/>
     * se array insert>0 crea una query per insert<br/>
     * se array update>0 crea una query per update<br/>
     * se array insert>0 e update>0 crea una query per insert con ON DUPLICATE <br/>
     *  
     * @param string $TABLE, array $Val, array $ColIns, array $ColUpd, string $Condizione, string $filerow <p>

     * </p>
     * @param $TABLE [stringa] <p> La tabella dove inserire/aggiornare i dati </p>
     * @param $Val [array ] <p> $Val[0] contiene il tipo di dato inserito del prepare statement [sssii]</p>
     * <p> $Val[1] contiene i valori da inserire </p>
     * @param $ColIns [array] <p> Array delle colonne da INSERIRE </p>
     * @param $ColUpd [array] <p> Array delle colonne da MODIFICARE </p>
     * @param $Condizione [stringa] <p> la condizione da applicare in caso di UPDATE </p>
     * @param $filerow [stringa] <p> file e riga in caso di errore __FILE__." => ".__LINE__ </p> 
     * @return false se l'iserimento non va a buon fine
     */
    function cleanQuery($TABLE, $Val = null, $ColIns = null, $ColUpd = null, $Condizione = null, $filerow = null)
    {
        global $database;
        if (($TABLE = trim($TABLE)) == "") {
            return false;
        }
        if (is_null($Val) || !is_array($Val)) {
            return false;
        }
        $types = $Val[0];
        $value = $Val[1];
        $Qicols = '';
        $Qivals = '';
        $Quvals = '';
        $Fi = false;
        $Fu = false;
        $I = 0;
        $U = 0;
        if (is_array($ColIns)) {
            $I = count($ColIns);
            $Fi = false;
            foreach ($ColIns as $iv) {
                if (isset($value[$iv])) {
                    $Params[] = $value[$iv];
                    $Qicols .= (!$Fi ? '' : ',') . ' ' . $iv . '';
                    $Qivals .= (!$Fi ? '' : ',') . ' ? ';
                    $Fi = true;
                }
            }
        }
        if (is_array($ColUpd)) {
            $U = count($ColUpd);
            $Fu = false;
            foreach ($ColUpd as $uv) {
                if (isset($value[$uv])) {
                    $Params[] = $value[$uv];
                    $Quvals .= (!$Fu ? '' : ',') . ' ' . $uv . ' = ? ';
                    $Fu = true;
                }
            }
        }
        if (($I == 0 && $U == 0) || !isset($Params)) {
            return false;
        }
        if ($I > 0 && $U > 0) {
            $Q = "INSERT INTO " . $TABLE . " (" . $Qicols . ") VALUES (" . $Qivals . ") ON DUPLICATE KEY UPDATE " . $Quvals . " ";
        } elseif ($U > 0) {
            $Q = "UPDATE " . $TABLE . " SET " . $Quvals . " " . ($Condizione != " " ? "WHERE " . trim($Condizione) : "");
        } else {
            $Q = "INSERT INTO " . $TABLE . " (" . $Qicols . ") VALUES (" . $Qivals . ")";
        }
        $result = $database->query($Q, $Params, $filerow);
        return $result;
    }


    function prepare($query, $filerow = "")
    {
        global $mailer;
        $result = $this->connection->prepare($query);
        if ($result) {
            if (DEBUG_OK) {
                $this->queryLog($query, 0);
            }
            return $result;
        } else {
            //esclude la comunicazione di :
            //2006 server gone away - 1317 Query execution was interrupted - 2013 Lost connection to MySQL server during query
            $ERn = intval($this->query_errorno());
            if ($ERn != '2006' && $ERn != '1317' && $ERn != '2013') {
                if (DEBUG_ERROR) {
                    $this->queryLog($query, 1, $this->query_error());
                }
                if (SEND_MAIL_ALERT) {
                    $mailer->sendError($query, $ERn . ' ' . $this->query_error(), $filerow);
                }
            }
            return false;
        }
    }

    function stmt_execute($stmt, $query, $filerow)
    {
        global $mailer;
        $return = $stmt->execute();
        if ($return) {
            if (DEBUG_OK) {
                $this->queryLog($query, 0);
            }
        } else {
            $info = $stmt->errorInfo();
            $ERn = isset($info[1]) ? (int) $info[1] : 0;
            $errMsg = isset($info[2]) ? $info[2] : '';
            if ($ERn != 2006 && $ERn != 1317 && $ERn != 2013) {
                if (DEBUG_ERROR) {
                    $this->queryLog($query, 1, $errMsg);
                }
                if (SEND_MAIL_ALERT) {
                    $mailer->sendError($query, $ERn . '-' . $errMsg, $filerow);
                }
            }
        }
        return $return;
    }


    function query_error()
    {
        $info = $this->connection->errorInfo();
        return isset($info[2]) ? $info[2] : '';
    }

    function query_errorno()
    {
        $info = $this->connection->errorInfo();
        return isset($info[1]) ? (int) $info[1] : 0;
    }

    function errorInfo()
    {
        return $this->connection->errorInfo();
    }

    function lastid()
    {
        return $this->connection->lastInsertId();
    }

    function blocca($tabelle, $filerow = "")
    {
        return $this->query("LOCK TABLES " . $tabelle, [], $filerow);
    }

    function sblocca()
    {
        return $this->query("UNLOCK TABLES", [], __FILE__ . " ==> " . __LINE__);
    }

    function free($result)
    {
        if ($result instanceof PDOStatement) {
            $result->closeCursor();
        }
    }

    function sendErrors($data, $error, $filerow = "")
    {
        global $mailer;
        if (DEBUG_ERROR) {
            $this->queryLog($data, 1, $error);
        }
        if (SEND_MAIL_ALERT) {
            $mailer->sendError($data, $error, $filerow);
        }
    }

    function queryLog($query, $tipo, $error = "")
    {
        if ($tipo == 1) {
            $file = 'errorLog.txt';
        } else {
            $file = 'successLog.txt';
        }
        if (!file_exists(ROOT . '/debug/') || !is_writeable(ROOT . '/debug/')) {
            @mkdir(ROOT . '/debug/', 0755);
        }
        $filename = ROOT . '/debug/' . $file;
        $tmpname = ROOT . '/debug/' . md5($query);
        $context = stream_context_create();
        $fp = @fopen($filename, 'r', 1, $context);
        file_put_contents($tmpname, date("d-m-Y H:i:s") . " -- " . $query . ($error != "" ? "\r\n<br />  -- --  " . $error . "\n<br />" : "") . "\n");
        file_put_contents($tmpname, $fp, FILE_APPEND);
        @fclose($fp);
        @unlink($filename);
        @rename($tmpname, $filename);
    }

    /*
     * gestione log per tracciare le operazioni fatte dagli utenti. 
     */
    function preparaLog($TABS, $column, $ESCMOD, $value, $OLD)
    {
        $MOD = "";
        $Controllo = false;
        if (is_array($column)) {
            foreach ($column as $campo) {
                $Controllo = true;
                if (isset($value[$campo]) && !in_array($campo, $ESCMOD) && $OLD[$campo] != $value[$campo]) {
                    if (($OLD[$campo] == '0' && $value[$campo] == '') || ($OLD[$campo] == '' && $value[$campo] == '0')) {
                        continue;
                    }
                    $MOD .= ($MOD != "" ? "<br/>" : "");
                    if (!isset($TABS[$campo])) {
                        $MOD .= $campo . ":<b>DA</b> " . $OLD[$campo] . "  <b>A</b> " . $value[$campo];
                    } else {
                        $MOD .= ($TABS[$campo]["testo"] != "" ? $TABS[$campo]["testo"] : $TABS[$campo]["title"]) . ":";
                        switch (trim($TABS[$campo]["tipo"])) {
                            case "text":
                                $MOD .= "<b>DA</b> " . $OLD[$campo] . "  <b>A</b> " . $value[$campo];
                                break;
                            case "select":
                            case "checkbox":
                            case "radio":
                                if (isset($TABS[$campo]["valori"])) {
                                    $MOD .= "<b>DA</b> " . (isset($TABS[$campo]["valori"][$OLD[$campo]]) ? $TABS[$campo]["valori"][$OLD[$campo]] : $OLD[$campo])
                                        . "  <b>A</b> " . (isset($TABS[$campo]["valori"][$value[$campo]]) ? $TABS[$campo]["valori"][$value[$campo]] : $value[$campo]);
                                } else {
                                    $MOD .= "<b>DA</b> " . $OLD[$campo] . "  <b>A</b> " . $value[$campo];
                                }
                                break;
                        }
                    }
                }
            }
        }
        if ($Controllo === true && $MOD == "") {
            $MOD = "Nessuna modifica apportata";
        }
        return $MOD;
    }

    public function getNominativoByUserId($user_id)
    {
        $sql = "SELECT Nominativo FROM personale WHERE user_id = :id";
        $res = $this->query($sql, ['id' => $user_id], __FILE__);

        if ($res && $row = $res->fetch(\PDO::FETCH_ASSOC)) {
            return $row['Nominativo'];
        }

        return null;
    }

    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }

    public function getLastInsertedIdFromTable($table)
    {
        $res = $this->query("SELECT MAX(id) as max_id FROM `$table`", [], __FILE__);
        $row = $res ? $res->fetch(\PDO::FETCH_ASSOC) : null;
        return $row ? (int) $row['max_id'] : 0;
    }

    public function formatDate($data)
    {
        if (!$data || $data === '0000-00-00')
            return '-';
        $timestamp = strtotime($data);
        if (!$timestamp)
            return $data;
        // Forza sempre il formato dd/mm/yyyy
        return date('d/m/Y', $timestamp);
    }


}
/* Create database connection */
$database = new MySQLDB;
