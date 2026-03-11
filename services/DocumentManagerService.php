<?php

namespace Services;

require_once __DIR__ . '/Nextcloud/NextcloudService.php';

use PDO;
use Services\Nextcloud\NextcloudService;
use Services\DocumentAreaRegistry;

class DocumentManagerService
{
    // Costanti per limiti e configurazione
    private static $MAX_FILE_SIZE = 20 * 1024 * 1024; // 20MB
    private static $ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/plain',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    private static $FORBIDDEN_EXTENSIONS = ['php', 'phtml', 'phar', 'js', 'html', 'htaccess', 'sh', 'exe', 'bat', 'cmd', 'com', 'dll'];
    private static $DEFAULT_PAGE_LIMIT = 50;
    private static $MAX_PAGE_LIMIT = 200;

    private static $memoryDebugLogs = [];

    private static function logSyncDebug($message, $context = [])
    {
        // Accumula in memoria per invio al frontend
        $timestamp = date('H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';
        self::$memoryDebugLogs[] = "[$timestamp] $message $contextStr";
    }

    // Configurazione sezioni ora delegata alla classe DocumentAreaRegistry

    /**
     * Helper per generare slug
     */
    private static function slugify(string $text): string
    {
        $text = preg_replace('~[^-\w\s]+~', '', $text);
        $text = preg_replace('~[\s\-_]+~', '-', $text);
        $text = trim($text, '-');
        $text = strtolower($text);
        if (empty($text))
            return 'n-a';
        return $text;
    }

    /**
     * Costruisce il path deterministico su Nextcloud
     */
    public static function buildNextcloudFolderPath(string $documentArea, string $menuTitle, string $pageSlug): string
    {
        $config = DocumentAreaRegistry::getDocumentAreaConfig($documentArea);
        if (!$config) {
            return "/INTRANET/UNKNOWN/" . self::slugify($pageSlug) . "/";
        }
        $root = $config['nextcloud_root'];
        $pSlug = self::slugify($pageSlug);

        if ($config['macro_policy'] === 'single') {
            return "/INTRANET/{$root}/{$pSlug}/";
        } else {
            $menuSlug = self::slugify($menuTitle);
            return "/INTRANET/{$root}/{$menuSlug}/{$pSlug}/";
        }
    }

    /**
     * Sanitizza e valida un nome cartella (solo 1 livello, no traversal).
     * Ritorna il nome pulito oppure null se invalido.
     */
    public static function sanitizeFolderName(?string $folder): ?string
    {
        if ($folder === null || $folder === '') {
            return null; // root, nessuna cartella
        }
        $folder = trim($folder);

        // Decodifica percent-encoding prima del check traversal (es. %2F, %2E%2E)
        $decoded = urldecode($folder);
        if ($decoded !== $folder) {
            // Se la decodifica cambia il valore, riapplica i check sulla versione decodificata
            if (
                strpos($decoded, '/') !== false ||
                strpos($decoded, '\\') !== false ||
                strpos($decoded, '..') !== false
            ) {
                return null;
            }
        }

        // Reject traversal, slashes, backslashes, control chars (su input originale)
        if (
            $folder === '.' || $folder === '..' ||
            strpos($folder, '/') !== false ||
            strpos($folder, '\\') !== false ||
            strpos($folder, '..') !== false ||
            preg_match('/[\x00-\x1f\x7f]/', $folder)
        ) {
            return null;
        }
        // Sanitize: rimuovi caratteri non sicuri per filesystem
        $folder = preg_replace('/[<>:"|?*]/', '_', $folder);
        $folder = preg_replace('/_+/', '_', $folder);
        $folder = trim($folder, '_ ');
        if ($folder === '' || mb_strlen($folder) > 100) {
            return null;
        }
        return $folder;
    }

    /**
     * Elenca sottocartelle (solo livello 1) della pagina su Nextcloud.
     */
    public static function listFolders(string $section, array $params): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $slug = $params['slug'] ?? '';
        if (empty($slug)) {
            return ['success' => false, 'error' => 'Pagina non specificata'];
        }

        $resPag = $database->query(
            "SELECT menu_title FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1",
            [$section, $slug],
            __FILE__ . ' ⇒ ' . __FUNCTION__
        );
        $pagina = $resPag->fetch(PDO::FETCH_ASSOC);
        if (!$pagina) {
            return ['success' => false, 'error' => 'Pagina non trovata'];
        }

        $ncPath = self::buildNextcloudFolderPath($section, $pagina['menu_title'], $slug);

        try {
            NextcloudService::init($database->connection);
            if (!NextcloudService::exists($ncPath)) {
                return ['success' => true, 'folders' => []];
            }
            $items = NextcloudService::listFolder($ncPath);
            $folders = [];
            foreach ($items as $item) {
                if ($item['is_dir']) {
                    $folders[] = [
                        'name' => $item['name'],
                        'last_modified' => $item['last_modified']
                    ];
                }
            }
            return ['success' => true, 'folders' => $folders];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Errore Nextcloud: ' . $e->getMessage()];
        }
    }

    /**
     * Crea sottocartella nella pagina su Nextcloud.
     */
    public static function createFolder(string $section, array $input): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $slug = $input['slug'] ?? '';
        $folderName = self::sanitizeFolderName($input['folder'] ?? '');

        if (empty($slug)) {
            return ['success' => false, 'error' => 'Pagina non specificata'];
        }
        if ($folderName === null || $folderName === '') {
            return ['success' => false, 'error' => 'Nome cartella non valido'];
        }

        $resPag = $database->query(
            "SELECT menu_title FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1",
            [$section, $slug],
            __FILE__ . ' ⇒ ' . __FUNCTION__
        );
        $pagina = $resPag->fetch(PDO::FETCH_ASSOC);
        if (!$pagina) {
            return ['success' => false, 'error' => 'Pagina non trovata'];
        }

        $ncPath = self::buildNextcloudFolderPath($section, $pagina['menu_title'], $slug);
        $folderPath = rtrim($ncPath, '/') . '/' . $folderName . '/';

        try {
            NextcloudService::init($database->connection);
            NextcloudService::ensureFolderExists($ncPath);
            if (NextcloudService::exists($folderPath)) {
                return ['success' => false, 'error' => 'Cartella già esistente'];
            }
            NextcloudService::mkdir($folderPath);
            return ['success' => true, 'folder' => $folderName];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Errore Nextcloud: ' . $e->getMessage()];
        }
    }

    private static function validateSection(string $section): bool
    {
        return DocumentAreaRegistry::isValid($section);
    }

    private static function getSectionConfig(string $section): ?array
    {
        return DocumentAreaRegistry::getDocumentAreaConfig($section);
    }

    public static function getMenus(string $section): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        // Recupera i nomi dei menu esistenti (sub-menu creati dagli utenti)
        // Modificato per restituire lista piatta 'data' compatibile con JS
        $sql = "SELECT title FROM menu_custom 
                WHERE section = ? AND attivo = 1 
                ORDER BY title ASC";

        $res = $database->query($sql, [$section], __FILE__ . ' ⇒ ' . __FUNCTION__);
        $menus = $res->fetchAll(PDO::FETCH_ASSOC);

        return ['success' => true, 'data' => $menus];
    }

    public static function createMenu(string $section, array $input): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $title = isset($input['title']) ? trim($input['title']) : '';
        $parentTitle = isset($input['parent_title']) ? trim($input['parent_title']) : '';

        if (empty($title)) {
            return ['success' => false, 'error' => 'Titolo obbligatorio'];
        }

        if (empty($parentTitle)) {
            $parentTitle = ucfirst($section);
        }

        $exists = $database->query("SELECT id FROM menu_custom WHERE section = ? AND title = ? AND parent_title = ? LIMIT 1", [$section, $title, $parentTitle], __FILE__ . ' ⇒ ' . __FUNCTION__);
        if ($exists->fetch()) {
            return ['success' => false, 'error' => 'Menu con questo nome già esistente'];
        }

        $maxOrd = $database->query("SELECT MAX(ordinamento) as max_ord FROM menu_custom WHERE section = ?", [$section], __FILE__ . ' ⇒ ' . __FUNCTION__);
        $maxRow = $maxOrd->fetch(PDO::FETCH_ASSOC);
        $ordinamento = (int) ($maxRow['max_ord'] ?? 100) + 10;

        $link = "index.php?section={$section}&page={$section}";
        $sql = "INSERT INTO menu_custom (section, parent_title, title, link, attivo, ordinamento, created_at, updated_at)
                VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())";
        $database->query($sql, [$section, $parentTitle, $title, $link, $ordinamento], __FILE__ . ' ⇒ ' . __FUNCTION__);

        $menuId = $database->lastInsertId();

        // Hook Nextcloud
        NextcloudService::init($database->connection);
        $ncPath = "/INTRANET/" . strtoupper($section) . "/" . self::slugify($title) . "/";
        try {
            NextcloudService::ensureFolderExists($ncPath);
        } catch (\Exception $e) {
            NextcloudService::enqueueSync('menu', $menuId, 'create', $ncPath, ['title' => $title]);
        }

        return ['success' => true, 'id' => (int) $menuId, 'title' => $title];
    }

    public static function getPagine(string $section): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return [];
        }

        $sql = "SELECT id, titolo, slug, descrizione, immagine, colore, menu_title, sync_nextcloud, ordinamento 
                FROM document_manager_pagine WHERE section = ? ORDER BY ordinamento ASC, titolo ASC";
        $res = $database->query($sql, [$section], __FILE__ . ' ⇒ ' . __FUNCTION__);
        return $res->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getPagina(string $section, string $slug): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $res = $database->query("SELECT * FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1", [$section, $slug], __FILE__ . ' ⇒ ' . __FUNCTION__);
        $pagina = $res->fetch(PDO::FETCH_ASSOC);

        if (!$pagina) {
            return ['success' => false, 'error' => 'Pagina non trovata'];
        }

        return ['success' => true, 'data' => $pagina];
    }

    public static function createPagina(string $section, array $input, array $user): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $titolo = $input['titolo'] ?? '';
        $slug = $input['slug'] ?? '';
        $descrizione = $input['descrizione'] ?? '';
        $immagine = $input['immagine'] ?? '';
        $colore = $input['colore'] ?? '';
        $menuTitle = $input['menu_title'] ?? '';

        if (empty($titolo) || empty($slug)) {
            return ['success' => false, 'error' => 'Titolo e Slug obbligatori'];
        }

        $slug = preg_replace('/[^a-z0-9\-_]/i', '', strtolower($slug));

        $exists = $database->query("SELECT id FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1", [$section, $slug], __FILE__ . ' ⇒ ' . __FUNCTION__);
        if ($exists->fetch()) {
            return ['success' => false, 'error' => 'Pagina con questo slug già esistente'];
        }

        $maxOrd = $database->query("SELECT MAX(ordinamento) as max_ord FROM document_manager_pagine WHERE section = ?", [$section], __FILE__ . ' ⇒ ' . __FUNCTION__);
        $maxRow = $maxOrd->fetch(PDO::FETCH_ASSOC);
        $ordinamento = (int) ($maxRow['max_ord'] ?? 10) + 10;

        $sql = "INSERT INTO document_manager_pagine (section, titolo, slug, descrizione, immagine, colore, menu_title, ordinamento, sync_nextcloud)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        $database->query($sql, [$section, $titolo, $slug, $descrizione, $immagine, $colore, $menuTitle, $ordinamento], __FILE__ . ' ⇒ ' . __FUNCTION__);

        // Hook Nextcloud
        NextcloudService::init($database->connection);
        $ncPath = self::buildNextcloudFolderPath($section, $menuTitle, $slug);
        try {
            NextcloudService::ensureFolderExists($ncPath);
            $database->query("UPDATE document_manager_pagine SET sync_nextcloud = 'synced' WHERE slug = ? AND section = ?", [$slug, $section], __FILE__ . ' ⇒ ' . __FUNCTION__);
        } catch (\Exception $e) {
            NextcloudService::enqueueSync('page', $slug, 'create', $ncPath, ['menu' => $menuTitle]);
        }

        return ['success' => true, 'slug' => $slug];
    }

    public static function editPagina(string $section, array $input, array $user): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $titolo = $input['titolo'] ?? '';
        $slug = $input['slug'] ?? '';
        $originalSlug = $input['original_slug'] ?? '';
        $descrizione = $input['descrizione'] ?? '';
        $immagine = $input['immagine'] ?? '';
        $colore = $input['colore'] ?? '';
        $menuTitle = $input['menu_title'] ?? '';

        if (empty($titolo) || empty($slug) || empty($originalSlug)) {
            return ['success' => false, 'error' => 'Dati obbligatori mancanti'];
        }

        $slug = preg_replace('/[^a-z0-9\-_]/i', '', strtolower($slug));

        if ($slug !== $originalSlug) {
            $exists = $database->query("SELECT id FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1", [$section, $slug], __FILE__ . ' ⇒ ' . __FUNCTION__);
            if ($exists->fetch()) {
                return ['success' => false, 'error' => 'Nuovo slug già in uso'];
            }
        }

        $sql = "UPDATE document_manager_pagine SET titolo=?, slug=?, descrizione=?, immagine=?, colore=?, menu_title=?
                WHERE section = ? AND slug = ?";
        $database->query($sql, [$titolo, $slug, $descrizione, $immagine, $colore, $menuTitle, $section, $originalSlug], __FILE__ . ' ⇒ ' . __FUNCTION__);

        if ($slug !== $originalSlug) {
            $database->query("UPDATE document_manager_documenti SET slug = ? WHERE section = ? AND slug = ?", [$slug, $section, $originalSlug], __FILE__ . ' ⇒ ' . __FUNCTION__);

            // Hook Nextcloud - Rinomina cartella
            NextcloudService::init($database->connection);
            $oldPath = self::buildNextcloudFolderPath($section, $menuTitle, $originalSlug);
            $newPath = self::buildNextcloudFolderPath($section, $menuTitle, $slug);
            try {
                NextcloudService::movePath($oldPath, $newPath);
            } catch (\Exception $e) {
                NextcloudService::enqueueSync('page', $slug, 'move', $newPath, ['old_path' => $oldPath]);
            }
        }

        return ['success' => true, 'slug' => $slug];
    }

    public static function deletePagina(string $section, string $slug): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $res = $database->query("SELECT menu_title FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1", [$section, $slug], __FILE__ . ' ⇒ ' . __FUNCTION__);
        $pageData = $res->fetch(PDO::FETCH_ASSOC);

        if (!$pageData)
            return ['success' => false, 'error' => 'Pagina non trovata'];

        $resDocs = $database->query("SELECT id FROM document_manager_documenti WHERE section = ? AND slug = ?", [$section, $slug], __FILE__ . ' ⇒ ' . __FUNCTION__);
        $documenti = $resDocs->fetchAll(PDO::FETCH_ASSOC);

        foreach ($documenti as $doc) {
            self::deleteDocumento($section, (int) $doc['id']);
        }

        $database->query("DELETE FROM document_manager_pagine WHERE section = ? AND slug = ?", [$section, $slug], __FILE__ . ' ⇒ ' . __FUNCTION__);

        // Hook Nextcloud
        NextcloudService::init($database->connection);
        $ncPath = self::buildNextcloudFolderPath($section, $pageData['menu_title'], $slug);
        try {
            NextcloudService::deletePath($ncPath);
        } catch (\Exception $e) {
            NextcloudService::enqueueSync('page', $slug, 'delete', $ncPath);
        }

        // Locale
        $config = self::getSectionConfig($section);
        $localDir = dirname(__DIR__) . '/' . $config['upload_dir'] . '/' . $slug;
        if (is_dir($localDir)) {
            $files = glob($localDir . '/*');
            foreach ($files as $f)
                if (is_file($f))
                    unlink($f);
            rmdir($localDir);
        }

        return ['success' => true];
    }

    public static function uploadDocumenti(string $section)
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $config = self::getSectionConfig($section);
        $slug = $_POST['slug'] ?? '';
        $userId = $_SESSION['user_id'] ?? 0;
        $folder = self::sanitizeFolderName($_POST['folder'] ?? null);

        if (empty($slug)) {
            return ['success' => false, 'error' => 'Pagina non specificata'];
        }

        $files = $_FILES['files'] ?? ($_FILES['documenti'] ?? []);
        if (empty($files['name'][0])) {
            return ['success' => false, 'error' => 'Nessun file selezionato'];
        }

        $uploadDir = $config['upload_dir'] . '/' . $slug;
        if ($folder) {
            $uploadDir .= '/' . $folder;
        }
        $absoluteDir = dirname(__DIR__) . '/' . $uploadDir;
        if (!is_dir($absoluteDir))
            mkdir($absoluteDir, 0755, true);

        $resPag = $database->query("SELECT menu_title FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1", [$section, $slug], __FILE__ . ' ⇒ ' . __FUNCTION__);
        $paginaRow = $resPag->fetch(PDO::FETCH_ASSOC);
        $menuTitle = $paginaRow['menu_title'] ?? 'Generale';
        $ncBaseFolder = self::buildNextcloudFolderPath($section, $menuTitle, $slug);
        $ncFolder = $folder ? rtrim($ncBaseFolder, '/') . '/' . $folder . '/' : $ncBaseFolder;

        // Hook Nextcloud
        NextcloudService::init($database->connection);

        $successi = [];
        $errori = [];

        foreach ($files['name'] as $i => $name) {
            $tmp = $files['tmp_name'][$i];
            if ($files['error'][$i] !== UPLOAD_ERR_OK)
                continue;

            // Security Checks
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmp);
            finfo_close($finfo);

            if (!in_array($mime, self::$ALLOWED_MIME_TYPES)) {
                $errori[] = "$name: Tipo file non consentito ($mime)";
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, self::$FORBIDDEN_EXTENSIONS)) {
                $errori[] = "$name: Estensione non consentita ($ext)";
                continue;
            }

            // Sanitizzazione nome file:
            // 1. Rimuove parentesi tonde, quadre, graffe e spazi doppi
            $cleanName = preg_replace('/[\(\)\[\]\{\}]/', '', $name);
            // 2. Sostituisce caratteri non alfanumerici (tranne . - _) con underscore, ma preserva la struttura
            $safeName = preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', $cleanName);
            // 3. Rimuove underscore multipli e all'inizio/fine
            $safeName = preg_replace('/_+/', '_', $safeName);

            // Fallback se il nome diventa vuoto o solo estensione
            if (empty(pathinfo($safeName, PATHINFO_FILENAME))) {
                $safeName = 'doc_' . uniqid() . '.' . $ext;
            }
            $destination = $absoluteDir . '/' . $safeName;
            $pathForDb = $uploadDir . '/' . $safeName;

            if (move_uploaded_file($tmp, $destination)) {
                // Support both array and singular inputs
                $titolo = ($_POST['titoli'][$i] ?? null) ?: ($_POST['titolo'] ?? $name);
                $descrizione = ($_POST['descrizioni'][$i] ?? null) ?: ($_POST['descrizione'] ?? '');
                $ncRemotePath = $ncFolder . $safeName;

                // 1. Insert LOCAL default
                $sql = "INSERT INTO document_manager_documenti (section, slug, nome_file, path, data_caricamento, caricato_da, titolo, descrizione, storage, remote_path)
                        VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 'local', NULL)";
                $database->query($sql, [$section, $slug, $safeName, $pathForDb, $userId, $titolo, $descrizione], __FILE__ . ' ⇒ ' . __FUNCTION__);
                $docId = $database->lastInsertId();

                // 2. Try NC Upload
                try {
                    NextcloudService::ensureFolderExists($ncFolder);
                    NextcloudService::uploadFile($destination, $ncRemotePath);

                    // 3. Update to NC if success and DELETE local file
                    $database->query(
                        "UPDATE document_manager_documenti SET storage='nextcloud', remote_path=?, path=? WHERE id=?",
                        [$ncRemotePath, 'nextcloud://' . $ncRemotePath, $docId],
                        __FILE__ . ' ⇒ ' . __FUNCTION__
                    );

                    if (file_exists($destination)) {
                        unlink($destination);
                    }
                } catch (\Exception $e) {
                    // 4. Queue with VALID ID if fail
                    NextcloudService::enqueueSync('document', (string) $docId, 'upload', $ncRemotePath, [
                        'local_path' => $pathForDb,
                        'section' => $section,
                        'slug' => $slug
                    ]);
                }

                $successi[] = $safeName;
            } else {
                $errori[] = "$name: Errore locale";
            }
        }

        return ['success' => true, 'caricati' => $successi, 'errori' => $errori];
    }

    public static function uploadThumb(string $section): array
    {
        if (empty($_FILES['file']))
            return ['success' => false, 'error' => 'File mancante'];

        $file = $_FILES['file'];

        // Security Checks
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMime = ['image/png', 'image/jpeg', 'image/webp'];
        if (!in_array($mime, $allowedMime)) {
            return ['success' => false, 'error' => 'Formato immagine non valido'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        // Force safe extension based on mime if needed, but pathinfo is usually okay for images if mime is checked
        // Double check against forbidden just in case
        if (in_array($ext, self::$FORBIDDEN_EXTENSIONS)) {
            return ['success' => false, 'error' => 'Estensione non valida'];
        }

        $filename = 'thumb_' . uniqid() . '.' . $ext;
        $relPath = 'uploads/document_manager/thumbs/' . $filename;
        $absPath = dirname(__DIR__) . '/' . $relPath;
        if (!is_dir(dirname($absPath)))
            mkdir(dirname($absPath), 0755, true);
        if (move_uploaded_file($file['tmp_name'], $absPath))
            return ['success' => true, 'path' => '/' . $relPath];
        return ['success' => false, 'error' => 'Errore'];
    }

    public static function getDocumenti(string $section, array $params): array
    {
        global $database;
        $slug = $params['slug'] ?? '';
        $page = (int) ($params['page'] ?? 1);
        $limit = (int) ($params['limit'] ?? self::$DEFAULT_PAGE_LIMIT);
        $offset = ($page - 1) * $limit;
        $folder = self::sanitizeFolderName($params['folder'] ?? null);

        $debugSync = (isset($params['debug_nc']) && $params['debug_nc'] == 1) || (isset($_GET['debug_nc']) && $_GET['debug_nc'] == 1);
        $debugStats = [];

        // 1. Recupera info pagina per path NC
        $resPag = $database->query("SELECT menu_title FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1", [$section, $slug]);
        $pagina = $resPag->fetch(PDO::FETCH_ASSOC);

        if ($pagina) {
            $ncBasePath = self::buildNextcloudFolderPath($section, $pagina['menu_title'], $slug);
            // Se folder specificato, lavora nella sotto-cartella
            $ncPath = $folder ? rtrim($ncBasePath, '/') . '/' . $folder . '/' : $ncBasePath;

            // 2. Cache Check (TTL 60s) — include folder nel key per sync indipendenti
            if (session_status() === PHP_SESSION_NONE)
                session_start();
            $cacheKey = "nc_sync_" . md5($section . $slug . ($folder ?? ''));
            $lastSync = $_SESSION[$cacheKey] ?? 0;
            $now = time();

            // Sync solo se cache scaduta o debug attivo
            if ($debugSync || ($now - $lastSync > 60)) {
                if ($debugSync)
                    self::logSyncDebug("Sync start for $section/$slug", ['path' => $ncPath, 'cache_bypass' => true]);

                try {
                    NextcloudService::init($database->connection);
                    $exists = NextcloudService::exists($ncPath);

                    if ($debugSync) {
                        $debugStats['exists'] = $exists;
                        $debugStats['path'] = $ncPath;
                        self::logSyncDebug("Path exists check", ['exists' => $exists]);
                    }

                    if ($exists) {
                        $remoteFiles = NextcloudService::listFolder($ncPath);
                        if ($debugSync) {
                            $debugStats['remoteCountRaw'] = count($remoteFiles);
                            self::logSyncDebug("Files found raw", ['count' => count($remoteFiles)]);
                        }

                        $_SESSION[$cacheKey] = $now;

                        $remoteMap = [];
                        foreach ($remoteFiles as $rf) {
                            if (!$rf['is_dir']) {
                                $remoteMap[$rf['name']] = $rf;
                            }
                        }

                        if ($debugSync) {
                            $debugStats['remoteFilesFiltered'] = count($remoteMap);
                            $debugStats['remoteSample'] = array_slice(array_keys($remoteMap), 0, 5);
                        }

                        // Recupera file DB filtrati per cartella corrente (via remote_path)
                        // così file con lo stesso nome in cartelle diverse non si sovrappongono
                        $dbSql = "SELECT id, nome_file, remote_path, storage FROM document_manager_documenti WHERE section = ? AND slug = ? AND is_missing = 0 AND remote_path LIKE ? ESCAPE '\\\\' AND remote_path NOT LIKE ? ESCAPE '\\\\'";

                        $ncPathNorm = rtrim($ncPath, '/') . '/';
                        $ncPathEscaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $ncPathNorm);

                        $dbFiles = $database->query($dbSql, [$section, $slug, $ncPathEscaped . '%', $ncPathEscaped . '%/%'])->fetchAll(PDO::FETCH_ASSOC);
                        $dbMap = [];
                        foreach ($dbFiles as $df) {
                            $dbMap[$df['nome_file']] = $df;
                        }

                        $inserted = 0;
                        $systemFilesBlacklist = ['thumbs.db', '.ds_store'];

                        foreach ($remoteMap as $name => $data) {
                            if (in_array(strtolower($name), $systemFilesBlacklist)) {
                                continue;
                            }

                            if (!isset($dbMap[$name])) {
                                // Insert
                                $sqlIns = "INSERT INTO document_manager_documenti (section, slug, nome_file, path, data_caricamento, data_creazione, caricato_da, titolo, descrizione, storage, remote_path)
                                           VALUES (?, ?, ?, ?, ?, ?, NULL, ?, '', 'nextcloud', ?)";
                                $fakeLocalPath = "nextcloud://$name";

                                $database->query($sqlIns, [
                                    $section,
                                    $slug,
                                    $name,
                                    $fakeLocalPath,
                                    $data['last_modified'] ?? date('Y-m-d H:i:s'),
                                    $data['creation_date'] ?? null,
                                    $name,
                                    $data['path']
                                ]);
                                $inserted++;
                            }
                        }

                        // Pruning in document_manager_documenti: 
                        // Imposta is_missing = 1 per i file in DB che non esistono più in remoto 
                        // (così non appariranno più in lista)
                        $missingIds = [];
                        $missingPaths = [];

                        foreach ($dbMap as $name => $df) {
                            if (!isset($remoteMap[$name]) && ($df['storage'] ?? 'local') === 'nextcloud') {
                                $missingIds[] = $df['id'];
                                $missingPaths[] = $df['remote_path'];
                            }
                        }

                        if (!empty($missingIds)) {
                            // Chunk processing per evitare query giganti (max 300 item per batch)
                            $chunkSize = 300;

                            // Update batch per is_missing
                            foreach (array_chunk($missingIds, $chunkSize) as $chunkIds) {
                                $placeholdersIds = implode(',', array_fill(0, count($chunkIds), '?'));
                                $database->query("UPDATE document_manager_documenti SET is_missing = 1, missing_at = NOW() WHERE id IN ($placeholdersIds)", $chunkIds);
                            }

                            // Delete batch per sys_nextcloud_cache
                            foreach (array_chunk($missingPaths, $chunkSize) as $chunkPaths) {
                                $placeholdersPaths = implode(',', array_fill(0, count($chunkPaths), '?'));
                                $database->query("DELETE FROM sys_nextcloud_cache WHERE nc_path IN ($placeholdersPaths)", $chunkPaths);
                            }
                        }

                        if ($debugSync) {
                            $debugStats['inserted'] = $inserted;
                            self::logSyncDebug("Sync Inserted: $inserted");
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Nextcloud sync error: " . $e->getMessage());
                    if ($debugSync) {
                        $debugStats['error'] = $e->getMessage();
                        self::logSyncDebug("Sync Error", ['error' => $e->getMessage()]);
                    }
                }
            } // Close if ($now - $lastSync > 60)
        } // Close if ($pagina)

        // Filtro per folder: se folder specificato filtra file nella sotto-cartella,
        // altrimenti solo file in root (nessuna sotto-cartella)
        $sqlParams = [$section, $slug];
        $folderFilter = '';
        if ($folder && isset($ncBasePath)) {
            // File nella cartella specifica
            $folderPrefix = rtrim($ncBasePath, '/') . '/' . $folder . '/';
            $folderPrefixEscaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $folderPrefix);
            $folderFilter = " AND d.remote_path LIKE ? ESCAPE '\\\\' AND d.remote_path NOT LIKE ? ESCAPE '\\\\'";
            $sqlParams[] = $folderPrefixEscaped . '%';
            $sqlParams[] = $folderPrefixEscaped . '%/%'; // escludi sotto-sotto-cartelle
        } elseif (isset($ncBasePath)) {
            // Solo file in root (escludi quelli nelle sotto-cartelle)
            $rootPrefix = rtrim($ncBasePath, '/') . '/';
            $rootPrefixEscaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $rootPrefix);
            $folderFilter = " AND (d.remote_path LIKE ? ESCAPE '\\\\' AND d.remote_path NOT LIKE ? ESCAPE '\\\\')";
            $sqlParams[] = $rootPrefixEscaped . '%';
            $sqlParams[] = $rootPrefixEscaped . '%/%';
        }

        $sql = "SELECT d.*, u.username as caricato_da_nome
                FROM document_manager_documenti d
                LEFT JOIN users u ON d.caricato_da = u.id
                WHERE d.section = ? AND d.slug = ? AND d.is_missing = 0{$folderFilter}
                ORDER BY d.data_caricamento DESC
                LIMIT ? OFFSET ?";

        $stmt = $database->connection->prepare($sql);
        // Bind string params (section, slug, folder LIKE patterns)
        foreach ($sqlParams as $i => $val) {
            $stmt->bindValue($i + 1, $val, PDO::PARAM_STR);
        }
        // Bind LIMIT/OFFSET come interi (PDO::PARAM_INT)
        $nextIdx = count($sqlParams) + 1;
        $stmt->bindValue($nextIdx, (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue($nextIdx + 1, (int) $offset, PDO::PARAM_INT);
        $stmt->execute();
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Post-processing: genera file_url pronto per il frontend
        foreach ($docs as &$doc) {
            if (($doc['storage'] ?? 'local') === 'nextcloud' && !empty($doc['remote_path'])) {
                $doc['file_url'] = 'ajax.php?section=nextcloud&action=file&path=' . urlencode($doc['remote_path']);
                $doc['thumb'] = 'ajax.php?section=nextcloud&action=thumb&path=' . urlencode($doc['remote_path']) . '&w=400&h=300';
            } else {
                $fileUrl = $doc['path'] ?? '';
                if ($fileUrl !== '' && strpos($fileUrl, 'http://') !== 0 && strpos($fileUrl, 'https://') !== 0 && $fileUrl[0] !== '/' && strpos($fileUrl, 'ajax.php') !== 0) {
                    $fileUrl = '/' . $fileUrl;
                }
                $doc['file_url'] = $fileUrl;
            }
        }

        // Conteggio totale per paginazione (con stesso filtro folder)
        $countParams = [$section, $slug];
        $countFilter = '';
        if ($folder && isset($ncBasePath)) {
            $folderPrefix = rtrim($ncBasePath, '/') . '/' . $folder . '/';
            $folderPrefixEscaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $folderPrefix);
            $countFilter = " AND remote_path LIKE ? ESCAPE '\\\\' AND remote_path NOT LIKE ? ESCAPE '\\\\'";
            $countParams[] = $folderPrefixEscaped . '%';
            $countParams[] = $folderPrefixEscaped . '%/%';
        } elseif (isset($ncBasePath)) {
            $rootPrefix = rtrim($ncBasePath, '/') . '/';
            $rootPrefixEscaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $rootPrefix);
            $countFilter = " AND (remote_path LIKE ? ESCAPE '\\\\' AND remote_path NOT LIKE ? ESCAPE '\\\\')";
            $countParams[] = $rootPrefixEscaped . '%';
            $countParams[] = $rootPrefixEscaped . '%/%';
        }
        $resCount = $database->query("SELECT COUNT(*) FROM document_manager_documenti WHERE section = ? AND slug = ? AND is_missing = 0{$countFilter}", $countParams);
        $totalRow = (int) $resCount->fetchColumn();

        $result = [
            'success' => true,
            'data' => $docs,
            'pagination' => [
                'currentPage' => $page,
                'pageSize' => $limit,
                'totalRows' => $totalRow,
                'totalPages' => ceil($totalRow / $limit),
                'hasMore' => ($page * $limit) < $totalRow
            ]
        ];
        if ($debugSync) {
            $debugStats['logs'] = self::$memoryDebugLogs; // Aggiunge i log
            $result['debug'] = $debugStats;
        }
        return $result;
    }

    public static function getDocumentiCount(string $section, string $slug): array
    {
        global $database;
        $res = $database->query("SELECT COUNT(*) FROM document_manager_documenti WHERE section = ? AND slug = ? AND is_missing = 0", [$section, $slug], __FILE__ . ' ⇒ ' . __FUNCTION__);
        return ['success' => true, 'count' => (int) $res->fetchColumn()];
    }

    public static function markMissingDocumento(string $section, int $id): array
    {
        global $database;
        // 1. Legge il remote_path prima di invalidare
        $res = $database->query("SELECT remote_path FROM document_manager_documenti WHERE section = ? AND id = ?", [$section, $id]);
        $row = $res->fetch(PDO::FETCH_ASSOC);

        // 2. Setta is_missing solo se nextcloud
        $sql = "UPDATE document_manager_documenti SET is_missing = 1, missing_at = NOW() WHERE section = ? AND id = ? AND storage = 'nextcloud'";
        $database->query($sql, [$section, $id], __FILE__ . ' ⇒ ' . __FUNCTION__);

        // 3. Elimina il record sys_nextcloud_cache associato se presente
        if ($row && !empty($row['remote_path'])) {
            $database->query("DELETE FROM sys_nextcloud_cache WHERE nc_path = ?", [$row['remote_path']]);
        }

        return ['success' => true];
    }

    public static function deleteDocumento(string $section, int $id): array
    {
        global $database;
        $res = $database->query("SELECT path, storage, remote_path FROM document_manager_documenti WHERE section = ? AND id = ?", [$section, $id], __FILE__ . ' ⇒ ' . __FUNCTION__);
        $row = $res->fetch(PDO::FETCH_ASSOC);

        if (!$row)
            return ['success' => false, 'error' => 'Not found'];

        if (file_exists(dirname(__DIR__) . '/' . ltrim($row['path'], '/')))
            unlink(dirname(__DIR__) . '/' . ltrim($row['path'], '/'));

        if (($row['storage'] ?? 'local') === 'nextcloud' && $row['remote_path']) {
            NextcloudService::init($database->connection);
            try {
                NextcloudService::deletePath($row['remote_path']);
            } catch (\Exception $e) {
                NextcloudService::enqueueSync('document', (string) $id, 'delete', $row['remote_path']);
            }
        }

        $database->query("DELETE FROM document_manager_documenti WHERE section = ? AND id = ?", [$section, $id], __FILE__ . ' ⇒ ' . __FUNCTION__);

        // Pulizia entry da sys_nextcloud_cache
        if (($row['storage'] ?? 'local') === 'nextcloud' && !empty($row['remote_path'])) {
            $database->query("DELETE FROM sys_nextcloud_cache WHERE nc_path = ?", [$row['remote_path']]);
        }

        return ['success' => true];
    }

    public static function renameDocumento(string $section, array $input): array
    {
        global $database;
        $id = (int) ($input['id'] ?? 0);
        $titolo = trim($input['titolo'] ?? '');
        $descrizione = trim($input['descrizione'] ?? '');
        $database->query("UPDATE document_manager_documenti SET titolo = ?, descrizione = ? WHERE section = ? AND id = ?", [$titolo, $descrizione, $section, $id], __FILE__ . ' ⇒ ' . __FUNCTION__);
        return ['success' => true];
    }

    public static function deleteDocumentiMultipli(string $section, array $input): array
    {
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            return ['success' => false, 'error' => 'Nessun documento specificato'];
        }
        if (count($ids) > 200) {
            return ['success' => false, 'error' => 'Massimo 200 documenti per operazione'];
        }

        $deleted = [];
        $failed = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                $failed[] = ['id' => $id, 'reason' => 'ID non valido'];
                continue;
            }
            try {
                $res = self::deleteDocumento($section, $id);
                if ($res['success'] ?? false) {
                    $deleted[] = $id;
                } else {
                    $failed[] = ['id' => $id, 'reason' => $res['error'] ?? 'Errore sconosciuto'];
                }
            } catch (\Exception $e) {
                $failed[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return ['success' => true, 'deleted' => $deleted, 'failed' => $failed];
    }

    public static function previewWordDocument(string $section, string $path): array
    {
        return ['success' => true, 'preview_url' => $path];
    }

    public static function getCommenti(string $section, int $id_documento): array
    {
        global $database;
        $res = $database->query("SELECT c.*, u.username as autore FROM document_manager_commenti c JOIN users u ON c.user_id = u.id WHERE section = ? AND id_documento = ? ORDER BY data_inserimento ASC", [$section, $id_documento], __FILE__ . ' ⇒ ' . __FUNCTION__);
        return ['success' => true, 'commenti' => $res->fetchAll(PDO::FETCH_ASSOC)];
    }

    public static function addCommento(string $section, array $input, array $user): array
    {
        global $database;
        $id_doc = intval($input['id_documento'] ?? 0);
        $text = $input['commento'] ?? '';
        $database->query("INSERT INTO document_manager_commenti (section, id_documento, user_id, commento, data_inserimento) VALUES (?, ?, ?, ?, NOW())", [$section, $id_doc, $user['id'], $text], __FILE__ . ' ⇒ ' . __FUNCTION__);
        return ['success' => true];
    }

    /**
     * Rinomina una sottocartella della pagina su Nextcloud + aggiorna remote_path in DB.
     */
    public static function renameFolder(string $section, array $input): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $slug = $input['slug'] ?? '';
        $oldName = self::sanitizeFolderName($input['folder'] ?? '');
        $newName = self::sanitizeFolderName($input['newName'] ?? '');

        if (empty($slug)) {
            return ['success' => false, 'error' => 'Pagina non specificata'];
        }
        if ($oldName === null || $oldName === '' || $newName === null || $newName === '') {
            return ['success' => false, 'error' => 'Nome cartella non valido'];
        }
        if ($oldName === $newName) {
            return ['success' => true, 'folder' => $newName];
        }

        $resPag = $database->query(
            "SELECT menu_title FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1",
            [$section, $slug],
            __FILE__ . ' ⇒ ' . __FUNCTION__
        );
        $pagina = $resPag->fetch(PDO::FETCH_ASSOC);
        if (!$pagina) {
            return ['success' => false, 'error' => 'Pagina non trovata'];
        }

        $ncBasePath = self::buildNextcloudFolderPath($section, $pagina['menu_title'], $slug);
        $oldPath = rtrim($ncBasePath, '/') . '/' . $oldName . '/';
        $newPath = rtrim($ncBasePath, '/') . '/' . $newName . '/';

        try {
            NextcloudService::init($database->connection);
            if (!NextcloudService::exists($oldPath)) {
                return ['success' => false, 'error' => 'Cartella non trovata su Nextcloud'];
            }
            if (NextcloudService::exists($newPath)) {
                return ['success' => false, 'error' => 'Una cartella con questo nome esiste già'];
            }
            NextcloudService::movePath($oldPath, $newPath);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Errore Nextcloud (MOVE): ' . $e->getMessage()];
        }

        // Aggiorna remote_path di tutti i documenti nella cartella
        // SEPARATO: se fallisce, tentiamo rollback su Nextcloud
        $oldPrefix = rtrim($ncBasePath, '/') . '/' . $oldName . '/';
        $newPrefix = rtrim($ncBasePath, '/') . '/' . $newName . '/';
        try {
            $database->query(
                "UPDATE document_manager_documenti SET remote_path = CONCAT(?, SUBSTRING(remote_path, ?)), path = CONCAT('nextcloud://', CONCAT(?, SUBSTRING(remote_path, ?))) WHERE section = ? AND slug = ? AND remote_path LIKE ?",
                [$newPrefix, strlen($oldPrefix) + 1, $newPrefix, strlen($oldPrefix) + 1, $section, $slug, $oldPrefix . '%'],
                __FILE__ . ' ⇒ ' . __FUNCTION__
            );
        } catch (\Exception $e) {
            // DB aggiornamento fallito: tenta rollback del MOVE su Nextcloud
            try {
                NextcloudService::movePath($newPath, $oldPath);
            } catch (\Exception $rollbackEx) {
                // Rollback fallito: log e ritorna errore critico per segnalare divergenza
                error_log('[DocumentManager] CRITICAL: DB update fallito E rollback NC fallito. Cartella NC: ' . $newPath . ' | DB ancora su: ' . $oldPath . ' | Errore DB: ' . $e->getMessage() . ' | Errore rollback: ' . $rollbackEx->getMessage());
                return ['success' => false, 'error' => 'Errore critico: cartella rinominata su Nextcloud ma DB non aggiornato. Contattare amministratore.'];
            }
            return ['success' => false, 'error' => 'Errore DB aggiornamento path: ' . $e->getMessage() . ' (rollback Nextcloud eseguito)'];
        }

        return ['success' => true, 'folder' => $newName];
    }

    /**
     * Elimina una sottocartella della pagina su Nextcloud + rimuove documenti dal DB.
     */
    public static function deleteFolder(string $section, array $input): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $slug = $input['slug'] ?? '';
        $folderName = self::sanitizeFolderName($input['folder'] ?? '');

        if (empty($slug)) {
            return ['success' => false, 'error' => 'Pagina non specificata'];
        }
        if ($folderName === null || $folderName === '') {
            return ['success' => false, 'error' => 'Nome cartella non valido'];
        }

        $resPag = $database->query(
            "SELECT menu_title FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1",
            [$section, $slug],
            __FILE__ . ' ⇒ ' . __FUNCTION__
        );
        $pagina = $resPag->fetch(PDO::FETCH_ASSOC);
        if (!$pagina) {
            return ['success' => false, 'error' => 'Pagina non trovata'];
        }

        $ncBasePath = self::buildNextcloudFolderPath($section, $pagina['menu_title'], $slug);
        $folderPath = rtrim($ncBasePath, '/') . '/' . $folderName . '/';

        try {
            NextcloudService::init($database->connection);

            // Verifica che la cartella esista prima di tentare listFolder (evita eccezione 404 generica)
            if (!NextcloudService::exists($folderPath)) {
                return ['success' => false, 'error' => 'Cartella non trovata su Nextcloud'];
            }

            // Verifica che la cartella sia vuota prima di eliminarla
            $items = NextcloudService::listFolder($folderPath);
            if (count($items) > 0) {
                return ['success' => false, 'error' => 'Impossibile eliminare: la cartella non è vuota'];
            }

            NextcloudService::deletePath($folderPath);

            // Rimuovi documenti dal DB
            $folderPrefix = rtrim($ncBasePath, '/') . '/' . $folderName . '/';
            $database->query(
                "DELETE FROM document_manager_documenti WHERE section = ? AND slug = ? AND remote_path LIKE ?",
                [$section, $slug, $folderPrefix . '%'],
                __FILE__ . ' ⇒ ' . __FUNCTION__
            );

            // Rimuovi anche file locali nella cartella (best-effort, non blocca)
            $config = self::getSectionConfig($section);
            $localDir = dirname(__DIR__) . '/' . $config['upload_dir'] . '/' . $slug . '/' . $folderName;
            if (is_dir($localDir)) {
                $files = glob($localDir . '/*');
                foreach ($files as $f) {
                    if (is_file($f))
                        unlink($f);
                }
                if (!rmdir($localDir)) {
                    error_log('[DocumentManager] Cartella locale non rimossa (potrebbe contenere file nascosti): ' . $localDir);
                }
            }

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Errore Nextcloud: ' . $e->getMessage()];
        }
    }

    /**
     * Sposta uno o più documenti in una cartella (o nella root) della stessa pagina.
     */
    public static function moveDocumenti(string $section, array $input): array
    {
        global $database;
        if (!self::validateSection($section)) {
            return ['success' => false, 'error' => 'Sezione non valida'];
        }

        $slug = $input['slug'] ?? '';
        $ids = $input['ids'] ?? [];
        $destination = self::sanitizeFolderName($input['destination'] ?? null); // null = root

        if (empty($slug)) {
            return ['success' => false, 'error' => 'Pagina non specificata'];
        }
        if (!is_array($ids) || empty($ids)) {
            return ['success' => false, 'error' => 'Nessun documento specificato'];
        }
        if (count($ids) > 200) {
            return ['success' => false, 'error' => 'Massimo 200 documenti per operazione'];
        }

        $resPag = $database->query(
            "SELECT menu_title FROM document_manager_pagine WHERE section = ? AND slug = ? LIMIT 1",
            [$section, $slug],
            __FILE__ . ' ⇒ ' . __FUNCTION__
        );
        $pagina = $resPag->fetch(PDO::FETCH_ASSOC);
        if (!$pagina) {
            return ['success' => false, 'error' => 'Pagina non trovata'];
        }

        $ncBasePath = self::buildNextcloudFolderPath($section, $pagina['menu_title'], $slug);
        $destFolder = $destination
            ? rtrim($ncBasePath, '/') . '/' . $destination . '/'
            : $ncBasePath;

        NextcloudService::init($database->connection);

        // Assicura che la cartella destinazione esista
        try {
            NextcloudService::ensureFolderExists($destFolder);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Cartella destinazione non raggiungibile: ' . $e->getMessage()];
        }

        $moved = [];
        $failed = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                $failed[] = ['id' => $id, 'reason' => 'ID non valido'];
                continue;
            }

            $res = $database->query(
                "SELECT id, nome_file, remote_path, storage FROM document_manager_documenti WHERE section = ? AND id = ?",
                [$section, $id],
                __FILE__ . ' ⇒ ' . __FUNCTION__
            );
            $doc = $res->fetch(PDO::FETCH_ASSOC);
            if (!$doc) {
                $failed[] = ['id' => $id, 'reason' => 'Documento non trovato'];
                continue;
            }

            $fileName = $doc['nome_file'];
            $storage = $doc['storage'] ?? 'local';
            $newRemotePath = $destFolder . $fileName;

            // Skip se è già nella destinazione
            if ($doc['remote_path'] === $newRemotePath) {
                $moved[] = $id;
                continue;
            }

            // Step 1: MOVE su Nextcloud (solo se storage nextcloud)
            $ncMoved = false;
            if ($storage === 'nextcloud' && !empty($doc['remote_path'])) {
                try {
                    // Controlla collisione prima di muovere
                    if (NextcloudService::exists($newRemotePath)) {
                        $failed[] = ['id' => $id, 'reason' => 'Un file con questo nome esiste già nella destinazione'];
                        continue;
                    }
                    NextcloudService::movePath($doc['remote_path'], $newRemotePath);
                    $ncMoved = true;
                } catch (\Exception $e) {
                    $failed[] = ['id' => $id, 'reason' => 'Errore MOVE Nextcloud: ' . $e->getMessage()];
                    continue;
                }
            }

            // Step 2: UPDATE DB — path corretto in base allo storage
            $newPath = ($storage === 'nextcloud')
                ? 'nextcloud://' . $newRemotePath
                : ($doc['path'] ?? ''); // per storage locale mantieni path stale o gestisci separatamente

            try {
                $database->query(
                    "UPDATE document_manager_documenti SET remote_path = ?, path = ? WHERE id = ?",
                    [$newRemotePath, $newPath, $id],
                    __FILE__ . ' ⇒ ' . __FUNCTION__
                );
                $moved[] = $id;
            } catch (\Exception $e) {
                // DB fallito: se avevamo spostato su NC, rollback
                if ($ncMoved) {
                    try {
                        NextcloudService::movePath($newRemotePath, $doc['remote_path']);
                    } catch (\Exception $rollbackEx) {
                        error_log('[DocumentManager] CRITICAL: MOVE NC ok ma UPDATE DB fallito E rollback fallito. id=' . $id . ' | NC path: ' . $newRemotePath . ' | DB old path: ' . $doc['remote_path'] . ' | DB err: ' . $e->getMessage() . ' | Rollback err: ' . $rollbackEx->getMessage());
                        $failed[] = ['id' => $id, 'reason' => 'Errore critico: file spostato su Nextcloud ma DB non aggiornato. Contattare amministratore.'];
                        continue;
                    }
                }
                $failed[] = ['id' => $id, 'reason' => 'Errore DB: ' . $e->getMessage() . ($ncMoved ? ' (rollback Nextcloud eseguito)' : '')];
            }
        }

        return ['success' => true, 'moved' => $moved, 'failed' => $failed];
    }
}
