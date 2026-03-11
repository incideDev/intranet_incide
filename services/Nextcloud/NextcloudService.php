<?php
namespace Services\Nextcloud;

use PDO;
use Exception;

class NextcloudService
{
    private static $baseUrl;
    private static $user;
    private static $password;
    private static $db;
    private static $rootWhitelist = '/INTRANET/';
    private static $thumbCacheDir = '/uploads/tmp_cache/nextcloud_thumbs/';
    private static $logFile;
    private static $debugLog = []; // Accumulatore log per output frontend
    private static $initialized = false;

    public static function init(?PDO $database = null)
    {
        if (self::$initialized && $database === null)
            return;

        self::$db = $database;
        self::$logFile = dirname(dirname(__DIR__)) . '/logs/sync.log';

        self::$baseUrl = rtrim(getenv('NEXTCLOUD_BASE_URL') ?: $_ENV['NEXTCLOUD_BASE_URL'] ?: '', '/');
        self::$user = getenv('NEXTCLOUD_USER') ?: $_ENV['NEXTCLOUD_USER'] ?: '';
        self::$password = getenv('NEXTCLOUD_APP_PASSWORD') ?: $_ENV['NEXTCLOUD_APP_PASSWORD'] ?: '';

        if (!self::$baseUrl || !self::$user || !self::$password) {
            $msg = "Configurazione Nextcloud mancante nel file .env";
            self::logDebug($msg, ['base_url' => self::$baseUrl, 'user' => self::$user]);
            // Non lanciamo eccezione in init statico per non bloccare tutto se inutilizzato,
            // ma i metodi successivi falliranno.
        }
        self::$initialized = true;
    }

    private static function checkInit()
    {
        if (!self::$initialized) {
            global $database;
            // Tenta init automatico con globale se non fatto
            self::init($database && $database->connection ? $database->connection : null);
        }
        if (!self::$baseUrl || !self::$user || !self::$password) {
            throw new Exception("NextcloudService non inizializzato correttamente o configurazione mancante.");
        }
    }

    private static function ensureLogDir()
    {
        $logDir = dirname(dirname(__DIR__)) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        return $logDir;
    }

    private static function logError($method, $path, $httpCode, $error, $context = [])
    {
        $dir = self::ensureLogDir();
        $logFile = $dir . '/nextcloud_webdav.log';

        $entry = sprintf(
            "[%s] [Nextcloud ERROR] %s | Path: %s | HTTP: %s | Error: %s | Context: %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($method),
            $path,
            $httpCode,
            $error,
            json_encode($context, JSON_UNESCAPED_SLASHES)
        );

        file_put_contents($logFile, $entry, FILE_APPEND);
    }

    private static function logDebug($message, $context = [])
    {
        $dir = self::ensureLogDir();
        // Aggiorna self::$logFile se non punta alla directory corretta o inizializzalo
        if (empty(self::$logFile)) {
            self::$logFile = $dir . '/sync.log';
        }

        if (isset($context['password']))
            $context['password'] = '***';

        self::$debugLog[] = sprintf("[%s] %s %s", date('H:i:s'), $message, !empty($context) ? json_encode($context) : '');

        $entry = sprintf(
            "[%s] [Nextcloud] %s | %s\n",
            date('Y-m-d H:i:s'),
            $message,
            json_encode($context, JSON_UNESCAPED_SLASHES)
        );

        file_put_contents(self::$logFile, $entry, FILE_APPEND);
    }

    public static function getLastLog()
    {
        return self::$debugLog;
    }

    public static function validatePath($path)
    {
        $path = str_replace('\\', '/', $path);

        if (strpos($path, '/../') !== false || strpos($path, '../') === 0 || strpos($path, '..') === (strlen($path) - 2)) {
            self::logDebug("Tentativo directory traversal", ['path' => $path]);
            throw new Exception("Percorso non valido (directory traversal rilevato)");
        }

        if (substr($path, 0, 1) !== '/') {
            $path = '/' . $path;
        }

        if (strpos($path, self::$rootWhitelist) !== 0) {
            self::logDebug("Accesso fuori whitelist", ['path' => $path, 'whitelist' => self::$rootWhitelist]);
            throw new Exception("Accesso negato: il percorso deve iniziare con " . self::$rootWhitelist);
        }

        return $path;
    }

    private static function buildWebDavUrl($path)
    {
        $segments = explode('/', $path);
        $encodedSegments = array_map('rawurlencode', $segments);
        $urlPath = implode('/', $encodedSegments);
        return self::$baseUrl . '/remote.php/dav/files/' . rawurlencode(self::$user) . $urlPath;
    }

    public static function listFolder($path)
    {
        self::checkInit();
        $path = self::validatePath($path);
        if (substr($path, -1) !== '/')
            $path .= '/';

        $url = self::buildWebDavUrl($path);

        $syncStart = date('Y-m-d H:i:s');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PROPFIND");
        $propfindBody = '<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <d:getlastmodified/>
    <d:getcontentlength/>
    <d:getcontenttype/>
    <d:getetag/>
    <d:resourcetype/>
    <oc:fileid/>
    <nc:creation_time/>
  </d:prop>
</d:propfind>';

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Depth: 1',
            'Content-Type: application/xml; charset=utf-8',
            'Accept: application/xml',
            'Content-Length: ' . strlen($propfindBody)
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $propfindBody);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, self::$user . ":" . self::$password);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $responseRaw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseRaw === false) {
            throw new Exception("Errore Connessione cURL: $error");
        }

        $body = substr($responseRaw, $headerSize);

        self::logDebug("PROPFIND Response", [
            'status' => $httpCode,
            'body_length' => strlen($body)
        ]);

        if ($httpCode >= 400) {
            throw new Exception("Errore Nextcloud ($httpCode)");
        }

        return self::parseWebDavResponse($body, $path, $syncStart);
    }

    private static function parseWebDavResponse($xmlString, $requestedPath, $syncStart = null)
    {
        $xmlString = ltrim($xmlString);
        if (substr($xmlString, 0, 3) === "\xEF\xBB\xBF") {
            $xmlString = substr($xmlString, 3);
        }

        if (empty($xmlString)) {
            throw new Exception("Risposta Nextcloud vuota.");
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xmlString)) {
            libxml_clear_errors();
            throw new Exception("Parsing XML fallito.");
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('oc', 'http://owncloud.org/ns');
        $xpath->registerNamespace('nc', 'http://nextcloud.org/ns');

        $items = [];
        $responses = $xpath->query('//d:multistatus/d:response');

        foreach ($responses as $response) {
            $hrefNode = $xpath->query('d:href', $response)->item(0);
            if (!$hrefNode)
                continue;

            $href = urldecode($hrefNode->nodeValue);
            $marker = '/files/' . self::$user . '/';
            $pos = strpos($href, $marker);

            if ($pos !== false) {
                $itemPath = '/' . substr($href, $pos + strlen($marker));
            } else {
                continue;
            }

            if (substr($itemPath, 0, 1) !== '/')
                $itemPath = '/' . $itemPath;

            $reqNorm = rtrim($requestedPath, '/');
            $itemNorm = rtrim($itemPath, '/');
            if ($reqNorm === $itemNorm)
                continue;

            $baseNameStr = basename($itemPath);
            if (strpos($baseNameStr, '.') === 0 && strtolower($baseNameStr) !== '.ds_store')
                continue;

            // Blacklist file di sistema
            $systemFilesBlacklist = ['thumbs.db', '.ds_store'];
            if (in_array(strtolower($baseNameStr), $systemFilesBlacklist)) {
                continue;
            }

            $prop = $xpath->query('d:propstat/d:prop', $response)->item(0);
            if (!$prop)
                continue;

            $isDir = $xpath->query('d:resourcetype/d:collection', $prop)->length > 0;
            $sizeNode = $xpath->query('d:getcontentlength', $prop)->item(0);
            $mimeNode = $xpath->query('d:getcontenttype', $prop)->item(0);
            $etagNode = $xpath->query('d:getetag', $prop)->item(0);
            $modNode = $xpath->query('d:getlastmodified', $prop)->item(0);
            $creationNode = $xpath->query('nc:creation_time', $prop)->item(0);

            $creationDate = null;
            if ($creationNode && !empty($creationNode->nodeValue) && $creationNode->nodeValue !== '0') {
                $ts = (int) $creationNode->nodeValue;
                if ($ts > 0) {
                    $creationDate = date('Y-m-d H:i:s', $ts);
                }
            }

            $item = [
                'name' => basename($itemPath),
                'path' => $itemPath,
                'is_dir' => $isDir,
                'mime' => $mimeNode ? $mimeNode->nodeValue : '',
                'size' => $sizeNode ? (int) $sizeNode->nodeValue : 0,
                'last_modified' => $modNode ? date('Y-m-d H:i:s', strtotime($modNode->nodeValue)) : null,
                'creation_date' => $creationDate,
                'etag' => $etagNode ? trim($etagNode->nodeValue, '"') : ''
            ];

            $items[] = $item;
            self::updateDBCache($item, $syncStart);
        }

        usort($items, function ($a, $b) {
            if ($a['is_dir'] != $b['is_dir'])
                return $b['is_dir'] <=> $a['is_dir'];
            return strcasecmp($a['name'], $b['name']);
        });

        if ($syncStart !== null) {
            self::pruneCache($requestedPath, $syncStart);
        }

        return $items;
    }

    private static function pruneCache($folderPath, $syncStart)
    {
        if (!self::$db)
            return;

        // Normalizza folderPath: leading slash, trailing slash, no doppi slash
        $folderPath = rtrim($folderPath, '/');
        if (substr($folderPath, 0, 1) !== '/') {
            $folderPath = '/' . $folderPath;
        }
        $folderPath .= '/'; // es: /INTRANET/Cartella/

        // Preparazione pattern per LIKE con escape corretto
        // Ipotizzando che il path non contenga wildcard malevole, tuttavia lo sproteggiamo.
        $escapedFolderPath = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\%', '\_'],
            $folderPath
        );

        // Pattern 1: tutti i discendenti diretti e non
        $pattern = $escapedFolderPath . '%';
        // Pattern 2: solo i nipoti (tutto ciò che ha una ulteriore slash dopo il pattern principale)
        $subPattern = $escapedFolderPath . '%/%';

        $sql = "DELETE FROM sys_nextcloud_cache 
                WHERE nc_path LIKE :pattern ESCAPE '\\\\' 
                  AND nc_path NOT LIKE :subPattern ESCAPE '\\\\' 
                  AND last_seen_at < :syncStart";

        try {
            self::$db->prepare($sql)->execute([
                ':pattern' => $pattern,
                ':subPattern' => $subPattern,
                ':syncStart' => $syncStart
            ]);
        } catch (Exception $e) {
            self::logDebug("Errore DB Prune Cache: " . $e->getMessage());
        }
    }

    private static function updateDBCache($item, $syncStart = null)
    {
        if (!self::$db)
            return;

        if ($syncStart === null) {
            $syncStart = date('Y-m-d H:i:s');
        }

        $sql = "INSERT INTO sys_nextcloud_cache (nc_path, etag, mime, size, last_modified, is_dir, last_seen_at)
                VALUES (:path, :etag, :mime, :size, :last_mod, :is_dir, :syncStart_insert)
                ON DUPLICATE KEY UPDATE 
                    etag = VALUES(etag), mime = VALUES(mime), size = VALUES(size),
                    last_modified = VALUES(last_modified), is_dir = VALUES(is_dir), last_seen_at = :syncStart_update";

        try {
            self::$db->prepare($sql)->execute([
                ':path' => $item['path'],
                ':etag' => $item['etag'],
                ':mime' => $item['mime'],
                ':size' => $item['size'],
                ':last_mod' => $item['last_modified'],
                ':is_dir' => $item['is_dir'] ? 1 : 0,
                ':syncStart_insert' => $syncStart,
                ':syncStart_update' => $syncStart
            ]);
        } catch (Exception $e) {
            self::logDebug("Errore DB Cache: " . $e->getMessage());
        }
    }

    public static function ensureFolderExists($path)
    {
        self::checkInit();
        $path = self::validatePath($path);
        $segments = explode('/', trim($path, '/'));
        $currentPath = '';

        foreach ($segments as $segment) {
            $currentPath .= '/' . $segment;
            if ($currentPath === '/INTRANET')
                continue;

            if (!self::exists($currentPath)) {
                self::mkdir($currentPath);
            }
        }
        return true;
    }

    public static function exists($path)
    {
        self::checkInit();
        $url = self::buildWebDavUrl($path);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, self::$user . ":" . self::$password);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PROPFIND");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Depth: 0']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 && $httpCode !== 404) {
            self::logError('PROPFIND', $path, $httpCode, "Errore verifica esistenza");
        }

        return ($httpCode < 300);
    }

    public static function mkdir($path)
    {
        self::checkInit();
        $url = self::buildWebDavUrl(rtrim($path, '/') . '/');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, self::$user . ":" . self::$password);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "MKCOL");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 && $httpCode !== 405) {
            self::logError('MKCOL', $path, $httpCode, "Errore creazione cartella");
            throw new Exception("Errore creazione cartella $path ($httpCode)");
        }
        return true;
    }

    public static function uploadFile($localPath, $remotePath)
    {
        self::checkInit();
        $remotePath = self::validatePath($remotePath);
        $url = self::buildWebDavUrl($remotePath);

        if (!file_exists($localPath)) {
            throw new Exception("File locale non trovato: $localPath");
        }

        $fp = fopen($localPath, 'r');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, self::$user . ":" . self::$password);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($localPath));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($httpCode >= 400) {
            self::logError('PUT', $remotePath, $httpCode, "Errore upload file");
            throw new Exception("Errore upload file su Nextcloud ($httpCode)");
        }
        return true;
    }

    public static function movePath($fromPath, $toPath)
    {
        self::checkInit();
        $fromPath = self::validatePath($fromPath);
        $toPath = self::validatePath($toPath);

        $sourceUrl = self::buildWebDavUrl($fromPath);
        $destUrl = self::buildWebDavUrl($toPath);

        $ch = curl_init($sourceUrl);
        curl_setopt($ch, CURLOPT_USERPWD, self::$user . ":" . self::$password);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "MOVE");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Destination: $destUrl"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            self::logError('MOVE', $fromPath, $httpCode, "Errore spostamento", ['destination' => $toPath]);
            throw new Exception("Errore spostamento su Nextcloud ($httpCode)");
        }
        return true;
    }

    public static function deletePath($remotePath)
    {
        self::checkInit();
        $remotePath = self::validatePath($remotePath);
        $url = self::buildWebDavUrl($remotePath);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, self::$user . ":" . self::$password);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400 && $httpCode !== 404) {
            self::logError('DELETE', $remotePath, $httpCode, "Errore eliminazione");
            throw new Exception("Errore eliminazione su Nextcloud ($httpCode)");
        }
        return true;
    }

    public static function enqueueSync($entityType, $entityId, $action, $remotePath, $payload = null)
    {
        self::checkInit();
        if (!self::$db)
            return false;
        $sql = "INSERT INTO nextcloud_sync_queue (entity_type, entity_id, action, remote_path, payload, status)
                VALUES (?, ?, ?, ?, ?, 'pending')";
        $stmt = self::$db->prepare($sql);
        return $stmt->execute([
            $entityType,
            $entityId,
            $action,
            $remotePath,
            $payload ? json_encode($payload) : null
        ]);
    }

    /**
     * Proxy a file from Nextcloud to the browser
     */
    public static function proxyFile($path)
    {
        self::checkInit();
        $path = self::validatePath($path);
        $url = self::buildWebDavUrl($path);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, self::$user . ":" . self::$password);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            header("HTTP/1.1 404 Not Found");
            echo "File not found on Nextcloud: " . $error;
            exit;
        }

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Pass through some important headers
        $headerLines = explode("\r\n", $headers);
        foreach ($headerLines as $line) {
            if (
                stripos($line, 'Content-Type:') === 0 ||
                stripos($line, 'Content-Length:') === 0 ||
                stripos($line, 'Content-Disposition:') === 0 ||
                stripos($line, 'Last-Modified:') === 0 ||
                stripos($line, 'ETag:') === 0
            ) {
                header($line);
            }
        }

        echo $body;
        exit;
    }

    /**
     * Proxy a thumbnail from Nextcloud to the browser
     */
    public static function proxyThumbnail($path, $width = 400, $height = 400)
    {
        self::checkInit();
        $path = self::validatePath($path);

        // Nextcloud WebDAV preview endpoint: /remote.php/dav/files/USER/PATH?preview=1&x=W&y=H
        $url = self::buildWebDavUrl($path) . "?preview=1&x={$width}&y={$height}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, self::$user . ":" . self::$password);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            // Fallback: if preview fails, try to stream the original if it's an image
            // Or just return a placeholder
            header("Content-Type: image/png");
            // Transparent 1x1 pixel or a generic icon
            echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
            exit;
        }

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $headerLines = explode("\r\n", $headers);
        foreach ($headerLines as $line) {
            if (stripos($line, 'Content-Type:') === 0 || stripos($line, 'Content-Length:') === 0) {
                header($line);
            }
        }

        echo $body;
        exit;
    }

    // ── Stream binario per early-exit in ajax.php ──────────────────

    private static $MIME_MAP = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt' => 'text/plain',
    ];

    private static function guessMime(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return self::$MIME_MAP[$ext] ?? 'application/octet-stream';
    }

    private static function curlFetch(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => self::$user . ':' . self::$password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['response' => $response, 'code' => $httpCode, 'headerSize' => $headerSize, 'error' => $error];
    }

    private static function extractContentType(string $rawHeaders): ?string
    {
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                return trim(substr($line, 13));
            }
        }
        return null;
    }

    public static function streamFile(string $remotePath): void
    {
        self::checkInit();
        $remotePath = self::validatePath($remotePath);
        $url = self::buildWebDavUrl($remotePath);

        $r = self::curlFetch($url);
        if ($r['response'] === false || $r['code'] >= 400) {
            http_response_code(404);
            echo 'File not found on Nextcloud';
            return;
        }

        $headers = substr($r['response'], 0, $r['headerSize']);
        $body = substr($r['response'], $r['headerSize']);

        $ct = self::extractContentType($headers) ?: self::guessMime($remotePath);
        header('Content-Type: ' . $ct);
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
        echo $body;
    }

    public static function streamThumb(string $remotePath, int $w = 400, int $h = 400): void
    {
        self::checkInit();
        $remotePath = self::validatePath($remotePath);
        $url = self::buildWebDavUrl($remotePath) . "?preview=1&x={$w}&y={$h}";

        $r = self::curlFetch($url);
        if ($r['response'] === false || $r['code'] >= 400) {
            // 1x1 transparent PNG placeholder
            header('Content-Type: image/png');
            header('Cache-Control: no-store');
            echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
            return;
        }

        $headers = substr($r['response'], 0, $r['headerSize']);
        $body = substr($r['response'], $r['headerSize']);

        $ct = self::extractContentType($headers) ?: 'image/jpeg';
        header('Content-Type: ' . $ct);
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
        echo $body;
    }
}
