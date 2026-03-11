<?php
/*
 * Verifica il file viene richiamato/includo dal file core principale
 */
if (!defined('HostDbDataConnector')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    exit('Unauthorized');
}
/*
 * Costanti globali da usare nel programma
 */
ini_set('session.gc_maxlifetime', 60 * 60 * 3);
include("host_db_server.php");
//variabile della ROOT directori in modo da richiamare il percorso principale se necessario.
define("ROOT", $_SERVER['DOCUMENT_ROOT']);


/***********************************
 * POSSIBILI COSTANTI PREDEFINITE *
 ************************************/

/**
 * dati del FTP 
 */
define("FTP_SERVER", " ");
define("FTP_USER", " ");//nomeutente database 
define("FTP_PASS", " ");//password database

/**
 * dati del DB per segnare gli ip bloccati in fase di login
 */
define("IPLOCK_ATTIVO", 0);  //indicare se è attivo o meno il blocco in caso di troppi errori
define("IP_SERVER", "localhost");
define("IP_USER", "inserire un nome utente");//nomeutente database 
define("IP_PASS", "inserire una password");//password database
define("IP_NAME", "inserire nome database");//nome database
define("FULL_RESET_TIME", 43200);  //(12 ore per cancellazione record.)
define("RESET_TIME", 1800);  //(30 minuti)
define("MAX_CHANCE", 4);
define("SEND_MAIL_ALERT", 0);


/*
 * dati relativi al tempo di reset del blocco DB globale
 */
define("ADMIN_MAIL", "debug@bss-online.it");
define("DEBUG_OK", 0);		//riporta le query effettuate su file
define("DEBUG_ERROR", 0);	//riporta le query con errore su file

/*
 * dati relativi al tempo di reset del blocco DB sito
 */
define("SITE_RESET_TIME", 600);  //in secondi
define("SITE_MAX_CHANCE", 6);
define("SITE_SEND_MAIL_ALERT", 0);
define("SITE_ADMIN_MAIL", "debug@bss-online.it");

/**
 * Costanti tabelle Database - tabelle di sistema base.
 */
define("SYS_USERS", "users");
define("SYS_LOG_ACCESSO", "sys_acces_log");	//tabella log accesso
define("SYS_ACTIVE_GUESTS", "sys_active_guests");	//tabella ospiti al momento (non usata)
define("SYS_ACTIVE_USERS", "sys_active_users");	//tabella utenti al momento attivi
define("SYS_AZIENDA", "sys_azienda");		//tabella dati azienda
define("SYS_BANNED_USERS", "sys_banned_users");	//tabella utenti bannati
define("SYS_BANNED_IP", "sys_banned_ip");	//tabella IP bannati


define("PERSONALE", "personale");   //tabella gestione personale
define("RUOLI", "roles");       //tabella ruoli assegnati 

/**
 * Special Names and Level Constants - the admin
 * page will only be accessible to the user with
 * the admin name and also to those users at the
 * admin user level. Feel free to change the names
 * and level constants as you see fit, you may
 * also add additional level specifications.
 * Levels must be digits between 0-9.
 */
define("ADMIN_NAME", "admin");
define("GUEST_NAME", "Guest");
define("MASTER_LEVEL", 9);
define("ADMIN_LEVEL", 8);
define("USER_LEVEL", 1);
define("GUEST_LEVEL", 0);

/**
 * This boolean constant controls whether or
 * not the script keeps track of active users
 * and active guests who are visiting the site.
 */
define("TRACK_VISITORS", true);

/**
 * Timeout Constants - these constants refer to
 * the maximum amount of time (in minutes) after
 * their last page fresh that a user and guest
 * are still considered active visitors.
 */
define("USER_TIMEOUT", 10);
define("GUEST_TIMEOUT", 5);

/**
 * Cookie Constants - these are the parameters
 * to the setcookie function call, change them
 * if necessary to fit your website. If you need
 * help, visit www.php.net for more info.
 * <http://www.php.net/manual/en/function.setcookie.php>
 */
define("COOKIE_EXPIRE", 60 * 60 * 24 * 30);  //100 anni by default  (60*60*24)=1 giorno
define("COOKIE_PATH", "/");  //Avaible in whole domain
define("COOKIE_SEED", "9(£|ad'125Df9"); //see to crypt/dectypr username

/**
 * This constant forces all users to have
 * lowercase usernames, capital letters are
 * converted automatically.
 */
define("ALL_LOWERCASE", false);