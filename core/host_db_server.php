<?php
/*
 * Eventaule limitazione accesso in base ad indirizzi IP 
 */
$allowedIps = null;// array('79.7.68.17', '79.10.212.4', '92.223.214.90', '93.43.96.86', '91.143.205.218', '46.234.212.33');

if (php_sapi_name() !== 'cli') {
    if ($allowedIps !== null && !in_array($_SERVER['REMOTE_ADDR'], $allowedIps) || substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 2) == '64') {
        exit('Unauthorized');
    }
}

/*
 * Verifica path provenienza per evetira include da sistemi terzi
 */
$IS_PRIMO_ACCESSO = (
    isset($_GET['page']) && $_GET['page'] === 'primo_accesso' &&
    !empty($_GET['token']) && strlen($_GET['token']) > 10
);

if (
    !$IS_PRIMO_ACCESSO &&
    isset($_SERVER['HTTP_REFERER']) && (
        stripos($_SERVER['HTTP_REFERER'], "http://intra-incide.bss-online.it") === false &&
        stripos($_SERVER['HTTP_REFERER'], "https://intra-incide.bss-online.it") === false &&
        stripos($_SERVER['HTTP_REFERER'], "http://intranet-incide.bss-online.it") === false &&
        stripos($_SERVER['HTTP_REFERER'], "https://intranet-incide.bss-online.it") === false &&
        stripos($_SERVER['HTTP_REFERER'], "http://intradev-incide.bss-online.it") === false &&
        stripos($_SERVER['HTTP_REFERER'], "https://intradev-incide.bss-online.it") === false
    )
) {
    header("Location:/");
    exit;
    die();
}

/*
 * Verifica il file viene richiamato/includo dal file core principale
 */
if (!defined('HostDbDataConnector')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    exit('Unauthorized');
}

// Abilita la visualizzazione degli errori per il debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
//error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/*
 * Definizioni dati di login al database
 */
$env = getenv('DB_SERVER');
if ($env === false || $env === '') {
    exit('Missing ENV: DB_SERVER');
}
define("DB_SERVER", $env);//nome del server

$env = getenv('DB_NAME');
if ($env === false || $env === '') {
    exit('Missing ENV: DB_NAME');
}
define("DB_NAME", $env);//nome del database

$env = getenv('DB_USER');
if ($env === false || $env === '') {
    exit('Missing ENV: DB_USER');
}
define("DB_USER", $env);//username di accesso al database

$env = getenv('DB_PASS');
if ($env === false || $env === '') {
    exit('Missing ENV: DB_PASS');
}
define("DB_PASS", $env);//password di accesso al database

define("DB_DNS", "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME);

$env = getenv('SS_NAME');
if ($env === false || $env === '') {
    exit('Missing ENV: SS_NAME');
}
define("SS_NAME", $env);//nome della sessione da attribuire 

$env = getenv('SS_PUBLICNAME');
if ($env === false || $env === '') {
    exit('Missing ENV: SS_PUBLICNAME');
}
define("SS_PUBLICNAME", $env);//nome della sessione pubblica da attribuire
//
// extra account web service
$env = getenv('WDB_SERVER');
if ($env === false || $env === '') {
    exit('Missing ENV: WDB_SERVER');
}
define("WDB_SERVER", $env);//nome del server

$env = getenv('WDB_NAME');
if ($env === false) {
    exit('Missing ENV: WDB_NAME');
}
define("WDB_NAME", $env);//nome del database

$env = getenv('WDB_USER');
if ($env === false) {
    exit('Missing ENV: WDB_USER');
}
define("WDB_USER", $env);//username di accesso al database

$env = getenv('WDB_PASS');
if ($env === false) {
    exit('Missing ENV: WDB_PASS');
}
define("WDB_PASS", $env);//password di accesso al database
