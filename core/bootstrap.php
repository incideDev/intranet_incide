<?php
/**
 * Bootstrap Centrale - Punto Unico di Inizializzazione
 * Include tutti i componenti essenziali per l'applicazione
 */

// Prevenzione accesso diretto
if (!defined('INTRANET_BOOTSTRAP')) {
    header('HTTP/1.0 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Accesso diretto non autorizzato']);
    exit;
}

// Autoload Composer (DomPDF e altri pacchetti installati via composer)
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}
unset($vendorAutoload);

// Autoload delle classi
require_once __DIR__ . '/autoload.php';

// Funzioni core
require_once __DIR__ . '/functions.php';

// Caricamento .env (una sola volta, prima di qualsiasi include DB)
if (!defined('ENV_LOADED')) {
    define('ENV_LOADED', true);

    $envPath = __DIR__ . '/../config/.env';
    if (!file_exists($envPath)) {
        exit('Missing config/.env');
    }
    $envLines = file($envPath, FILE_IGNORE_NEW_LINES);
    if ($envLines === false) {
        exit('Unable to read config/.env');
    }
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        $key = trim($parts[0]);
        $value = isset($parts[1]) ? trim($parts[1]) : '';
        
        // Rimuove eventuali apici saltuari dal valore
        $value = trim($value, '"\'');
        
        if ($key !== '') {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Gestione sessione (include inizializzazione ruoli/permessi)
require_once __DIR__ . '/session.php';

// JSON Response Helper (inglobato per evitare file separato)
if (!function_exists('sendJsonResponse')) {
    /**
     * Invia risposta JSON pulita
     * 
     * @param array|object $data Dati da inviare
     * @param int $options Opzioni json_encode (default: JSON_UNESCAPED_UNICODE)
     * @return void
     */
    function sendJsonResponse($data, $options = 0)
    {
        // Pulisce eventuali warning/notice dal buffer
        if (ob_get_length()) {
            ob_clean();
        }

        // Output JSON puro
        echo json_encode($data, $options | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Costante di sicurezza globale
if (!defined('AccessoFileInterni')) {
    define('AccessoFileInterni', true);
}

// Verifica ambiente (preferenza a .env, fallback dev)
if (!defined('APP_ENV')) {
    $envVal = getenv('APP_ENV') ?: 'dev';
    define('APP_ENV', $envVal);
}
