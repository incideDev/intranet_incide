<?php
namespace Services;

class HomeService {

    public static function getAllBirthdays() {
        global $database;
    
        $sql = "
            SELECT 
                p.Nominativo AS username,
                p.Data_di_Nascita
            FROM personale p
            WHERE p.Data_di_Nascita IS NOT NULL AND p.attivo = 1
            ORDER BY MONTH(p.Data_di_Nascita), DAY(p.Data_di_Nascita)
        ";
    
        $rows = $database->query($sql, [], __FILE__);
        $result = [];
    
        foreach ($rows as $row) {
            $row['profile_picture'] = getProfileImage($row['username'], 'nominativo');
            $result[] = $row;
        }
    
        return ['success' => true, 'birthdays' => $result];
    }
        
    public static function getCachedNews() {
        self::refreshNewsCacheIfNeeded();

        $cachePath = self::getWritableCachePaths()['cache'];

        if (!file_exists($cachePath)) {
            return ['success' => false, 'error' => 'File cache non trovato.'];
        }

        $data = json_decode(file_get_contents($cachePath), true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Cache corrotta.'];
        }

        // Carica la news in evidenza (solo il link serve!)
        $featured = null;
        $featured_path = ROOT . '/uploads/featured_news.json';
        if (file_exists($featured_path)) {
            $tmp = @json_decode(file_get_contents($featured_path), true);
            if (is_array($tmp) && !empty($tmp['link'])) {
                $featured = $tmp['link'];
            }
        }

        // Escludi la news in evidenza e restituisci SOLO le prime 3 disponibili
        if ($featured) {
            $data = array_filter($data, function ($item) use ($featured) {
                $l1 = rtrim($item['link'] ?? '', '/');
                $l2 = rtrim($featured, '/');
                return $l1 !== $l2;
            });
            $data = array_values($data);
        }

        // Limita sempre a massimo 3 news
        $data = array_slice($data, 0, 3);

        // Dopo il filtro e l'array_slice, se mancano news ricava le successive dal backend (WP API)
        if (count($data) < 3) {
            // Quante ne mancano?
            $mancanti = 3 - count($data);

            // Prendi la lista dei link già usati
            $used_links = array_map(function($n) { return rtrim($n['link'] ?? '', '/'); }, $data);
            if ($featured) $used_links[] = rtrim($featured, '/');

            // Chiamata API con per_page alto per trovare altre news da aggiungere
            $response = @file_get_contents('https://www.incide.it/wp-json/wp/v2/posts?per_page=10&_embed');
            $posts = json_decode($response, true);
            if (is_array($posts)) {
                foreach ($posts as $post) {
                    $plink = rtrim($post['link'] ?? '', '/');
                    if (!in_array($plink, $used_links)) {
                        $data[] = [
                            'title' => $post['title']['rendered'] ?? 'Senza titolo',
                            'date' => date('d/m/Y', strtotime($post['date'] ?? '')),
                            'link' => $post['link'] ?? '#',
                            'image' => $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? 'assets/images/default-thumbnail.jpg'
                        ];
                        $used_links[] = $plink;
                        $mancanti--;
                        if ($mancanti <= 0) break;
                    }
                }
            }
            // Limita sempre a 3 news
            $data = array_slice($data, 0, 3);
        }

        return ['success' => true, 'data' => $data];
    }

    public static function getNewsletterIndex() {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'comunicazioni' . DIRECTORY_SEPARATOR . 'newsletter_index.json';

        if (!file_exists($path)) {
            return ['success' => false, 'error' => 'File newsletter non trovato.'];
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Index newsletter corrotto.'];
        }

        return ['success' => true, 'data' => $data];
    }

    public static function deleteNewsletter($title) {
        $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'comunicazioni';
        $jsonPath = $uploadDir . DIRECTORY_SEPARATOR . 'newsletter_index.json';

        if (!file_exists($jsonPath)) {
            return ['success' => false, 'message' => 'Index non trovato'];
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            return ['success' => false, 'message' => 'Index corrotto'];
        }

        $filtered = array_filter($data, fn($n) => $n['title'] !== $title);
        if (count($data) === count($filtered)) {
            return ['success' => false, 'message' => 'Comunicazione non trovata'];
        }

        $deleted = array_values(array_filter($data, fn($n) => $n['title'] === $title))[0] ?? null;
        if ($deleted && !empty($deleted['file'])) {
            $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $deleted['file'];
            if (file_exists($filePath)) unlink($filePath);
        }

        file_put_contents($jsonPath, json_encode(array_values($filtered), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return ['success' => true];
    }

public static function refreshNewsCacheIfNeeded() {
    $paths = self::getWritableCachePaths();
    $metaPath = $paths['meta'];
    $cachePath = $paths['cache'];

    $shouldUpdate = true;
    $now = time();
    $maxAge = 3600 * 6; // 6 ore

    if (file_exists($metaPath)) {
        $meta = json_decode(file_get_contents($metaPath), true);
        if (isset($meta['updated_at']) && ($now - (int)$meta['updated_at']) < $maxAge) {
            $shouldUpdate = false;
        }
    }

    if (!$shouldUpdate) return;

    // Aumenta il per_page!
    $url = 'https://www.incide.it/wp-json/wp/v2/posts?per_page=8&_embed';
    $response = @file_get_contents($url);
    if (!$response) return;

    $data = json_decode($response, true);
    if (!is_array($data)) return;

    $news = [];
    foreach ($data as $post) {
        $news[] = [
            'title' => $post['title']['rendered'] ?? 'Senza titolo',
            'date' => date('d/m/Y', strtotime($post['date'])),
            'link' => $post['link'] ?? '#',
            'image' => $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? 'assets/images/default-thumbnail.jpg'
        ];
    }

    file_put_contents($cachePath, json_encode($news, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    file_put_contents($metaPath, json_encode(['updated_at' => $now], JSON_PRETTY_PRINT));
}

    private static function getWritableCachePaths() {
        $base = substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/services'));
        $dir = $base . '/uploads/tmp_cache';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return [
            'meta' => $dir . '/cached_news_meta.json',
            'cache' => $dir . '/cached_news.json'
        ];
    }

    public static function getFeaturedNewsBySlug($slug = null) {
        if (!$slug) {
            return ['success' => false, 'error' => 'Slug mancante'];
        }

        $url = 'https://www.incide.it/wp-json/wp/v2/posts?slug=' . urlencode($slug) . '&_embed';
        $response = @file_get_contents($url);

        if (!$response) {
            return ['success' => false, 'error' => 'Impossibile contattare il sito WordPress'];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || count($data) == 0) {
            return ['success' => false, 'error' => 'Nessun articolo trovato con questo slug'];
        }

        $post = $data[0];
        $news = [
            'id'    => $post['id'] ?? null,
            'title' => $post['title']['rendered'] ?? 'Senza titolo',
            'date'  => date('d/m/Y', strtotime($post['date'])),
            'link'  => $post['link'] ?? '#',
            'image' => $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? 'assets/images/default-thumbnail.jpg'
        ];

        return ['success' => true, 'data' => $news];
    }

    public static function getFeaturedNews()
    {
        $featured_news_path = __DIR__ . '/../uploads/featured_news.json';
        if (!file_exists($featured_news_path)) {
            return ['success' => true, 'data' => null];
        }
        $content = @file_get_contents($featured_news_path);
        if (!$content) return ['success' => true, 'data' => null];
        $json = json_decode($content, true);
        if (!is_array($json)) return ['success' => true, 'data' => null];
        return ['success' => true, 'data' => $json];
    }

    // Endpoint unificato per ottimizzare caricamento home (riduce chiamate AJAX)
    public static function getHomeData() {
        $result = [
            'success' => true,
            'featuredNews' => null,
            'cachedNews' => [],
            'newsletterIndex' => [],
            'birthdays' => []
        ];

        // Featured news
        $featured = self::getFeaturedNews();
        if ($featured['success'] && isset($featured['data'])) {
            $result['featuredNews'] = $featured['data'];
        }

        // Cached news (già filtra featured)
        $cached = self::getCachedNews();
        if ($cached['success'] && isset($cached['data'])) {
            $result['cachedNews'] = $cached['data'];
        }

        // Newsletter index
        $newsletter = self::getNewsletterIndex();
        if ($newsletter['success'] && isset($newsletter['data'])) {
            $result['newsletterIndex'] = $newsletter['data'];
        }

        // Birthdays (solo se necessario per calendario)
        $birthdays = self::getAllBirthdays();
        if ($birthdays['success'] && isset($birthdays['birthdays'])) {
            $result['birthdays'] = $birthdays['birthdays'];
        }

        return $result;
    }

public static function setFeaturedNews($data) {
    if (!isAdmin()) {
        return ['success' => false, 'message' => 'Non autorizzato'];
    }
    
    // Supporta sia link che immagine
    $link = null;
    $image = null;
    $title = null;
    
    if (is_array($data)) {
        $link = isset($data['link']) ? trim($data['link']) : null;
        $image = isset($data['image']) ? trim($data['image']) : null;
        $title = isset($data['title']) ? trim($data['title']) : null;
    } else {
        $link = trim($data);
    }
    
    // Se c'è un'immagine caricata, usa quella (non estrarre da link)
    if (!empty($image)) {
        $news = [
            'title' => $title ?: 'News in evidenza',
            'link' => $link ?: '',
            'image' => $image,
            'date' => date('d/m/Y')
        ];
    } else if (!empty($link)) {
        // Comportamento esistente: estrai da WordPress
        $slug = self::extractSlug($link);
        $url = 'https://www.incide.it/wp-json/wp/v2/posts?_embed&per_page=1&slug=' . urlencode($slug);
        $response = @file_get_contents($url);
        $wpData = @json_decode($response, true);
        $news = null;
        if (is_array($wpData) && count($wpData) > 0) {
            $post = $wpData[0];
            $news = [
                'title' => $post['title']['rendered'] ?? '',
                'link' => $link,
                'image' => $post['_embedded']['wp:featuredmedia'][0]['source_url'] ?? '',
                'date' => date('d/m/Y', strtotime($post['date'] ?? '')) ?: ''
            ];
        } else {
            $news = [
                'title' => '',
                'link' => $link,
                'image' => '',
                'date' => ''
            ];
        }
    } else {
        return ['success' => false, 'message' => 'Link o immagine obbligatori'];
    }
    
    // Salva su file. Usa la costante ROOT!
    $file = ROOT . '/uploads/featured_news.json';
    file_put_contents($file, json_encode($news, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // --- AGGIORNA CACHE NEWS solo se abbiamo un link esterno (non per immagini caricate localmente) ---
    // Le immagini caricate localmente (senza link esterno) NON devono entrare nella cache delle news
    // perché non sono news dall'API REST, sono solo immagini per la welcome-section
    if (!empty($link) && (strpos($link, 'http://') === 0 || strpos($link, 'https://') === 0)) {
        $cachePath = self::getWritableCachePaths()['cache'];
        $cacheData = [];
        if (file_exists($cachePath)) {
            $cacheData = json_decode(file_get_contents($cachePath), true);
            if (!is_array($cacheData)) $cacheData = [];
        }
        // Elimina dalla cache eventuali vecchie versioni della stessa news (stesso link)
        $cacheData = array_filter($cacheData, function ($item) use ($link) {
            $itemLink = rtrim($item['link'] ?? '', '/');
            $currentLink = rtrim($link, '/');
            return ($itemLink !== $currentLink);
        });
        // Inserisci la featured news in testa (prima posizione)
        array_unshift($cacheData, $news);
        // Mantieni solo le ultime 8 news (o quante vuoi)
        $cacheData = array_slice($cacheData, 0, 8);
        file_put_contents($cachePath, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    // Se è solo un'immagine caricata (senza link esterno), NON aggiungere alla cache
    // --- FINE AGGIORNAMENTO CACHE ---

    return ['success' => true, 'data' => $news];
}

public static function uploadFeaturedNewsImage() {
    if (!isAdmin()) {
        return ['success' => false, 'message' => 'Non autorizzato'];
    }
    
    // Verifica che ci sia un file
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File mancante o errore upload'];
    }
    
    $file = $_FILES['image'];
    
    // Validazione tipo MIME
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'message' => 'Formato file non supportato. Usa JPEG, PNG, WebP o GIF.'];
    }
    
    // Validazione dimensione (max 10MB)
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File troppo grande. Dimensione massima: 10MB.'];
    }
    
    // Crea directory se non esiste
    $uploadDir = ROOT . '/uploads/featured_news';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true)) {
            return ['success' => false, 'message' => 'Errore creazione directory upload'];
        }
    }
    
    // Gestione diversa per GIF vs altri formati
    $isGif = ($mimeType === 'image/gif');
    $optimized = false;
    $note = '';
    
    if ($isGif) {
        // Per GIF: preserva animazione, ottimizza se possibile
        $result = self::handleGifUpload($file, $uploadDir);
        if (!$result['success']) {
            return $result;
        }
        $relativePath = $result['imagePath'];
        $optimized = $result['optimized'] ?? false;
        $note = $result['note'] ?? '';
    } else {
        // Per JPEG/PNG/WebP: usa compressUploadedImage esistente
        $originalName = $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $safeBaseName = 'featured_news_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        
        // Determina formato output (mantieni formato originale per featured news)
        $outputFormat = match($mimeType) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpeg'
        };
        
        $destPath = $uploadDir . '/' . $safeBaseName . '.' . $outputFormat;
        
        // Usa compressUploadedImage con opzioni per featured news (max 2400px lato lungo)
        $compressResult = compressUploadedImage($file['tmp_name'], $destPath, [
            'maxWidth' => 2400,
            'maxHeight' => 2400,
            'quality' => 82, // JPEG quality
            'outputFormat' => $outputFormat,
            'keepOriginal' => true
        ]);
        
        if (!$compressResult['ok']) {
            // Fallback: salva originale se compressione fallisce
            $fallbackPath = $uploadDir . '/' . $safeBaseName . '.' . $extension;
            if (!move_uploaded_file($file['tmp_name'], $fallbackPath)) {
                return ['success' => false, 'message' => 'Errore durante il salvataggio del file'];
            }
            $relativePath = 'uploads/featured_news/' . basename($fallbackPath);
            $note = 'Compressione fallita, salvato originale';
        } else {
            $relativePath = 'uploads/featured_news/' . basename($compressResult['path']);
            $optimized = true;
            if (isset($compressResult['originalWidth']) && isset($compressResult['width'])) {
                if ($compressResult['originalWidth'] > $compressResult['width'] || 
                    (isset($compressResult['originalHeight']) && $compressResult['originalHeight'] > $compressResult['height'])) {
                    $note = 'Immagine ridimensionata e ottimizzata';
                } else {
                    $note = 'Immagine ottimizzata';
                }
            }
        }
    }
    
    return [
        'success' => true,
        'imagePath' => $relativePath,
        'optimized' => $optimized,
        'note' => $note,
        'message' => 'Immagine caricata con successo' . ($note ? ' (' . $note . ')' : '')
    ];
}

private static function handleGifUpload(array $file, string $uploadDir): array {
    // Verifica se GIF è animata (controlla se ha più frame)
    $isAnimated = self::isGifAnimated($file['tmp_name']);
    
    // Leggi dimensioni
    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return ['success' => false, 'message' => 'Impossibile leggere informazioni GIF'];
    }
    
    $originalWidth = $info[0];
    $originalHeight = $info[1];
    $maxDimension = 1600; // Max lato lungo per GIF
    
    // Sanitizza nome file
    $originalName = $file['name'];
    $safeBaseName = 'featured_news_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $targetPath = $uploadDir . '/' . $safeBaseName . '.gif';
    
    $optimized = false;
    $note = '';
    
    // Se dimensioni superano max, prova a ridimensionare (solo se gifsicle disponibile)
    $needsResize = ($originalWidth > $maxDimension || $originalHeight > $maxDimension);
    
    if ($needsResize || $isAnimated) {
        // Prova ottimizzazione con gifsicle se disponibile
        $gifsiclePath = self::findGifsicle();
        
        if ($gifsiclePath) {
            // Crea file temporaneo per ottimizzazione
            $tempPath = $uploadDir . '/temp_' . $safeBaseName . '.gif';
            if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
                return ['success' => false, 'message' => 'Errore durante il salvataggio temporaneo'];
            }
            
            // Costruisci comando gifsicle
            $cmd = escapeshellarg($gifsiclePath);
            $cmd .= ' -O2'; // Ottimizzazione livello 2
            $cmd .= ' --colors 256'; // Limita palette
            if ($needsResize) {
                $newWidth = min($originalWidth, $maxDimension);
                $newHeight = min($originalHeight, $maxDimension);
                $cmd .= ' --resize ' . escapeshellarg($newWidth . 'x' . $newHeight);
            }
            $cmd .= ' ' . escapeshellarg($tempPath);
            $cmd .= ' > ' . escapeshellarg($targetPath);
            $cmd .= ' 2>&1';
            
            // Esegui ottimizzazione
            $output = [];
            $returnVar = 0;
            @exec($cmd, $output, $returnVar);
            
            if ($returnVar === 0 && file_exists($targetPath) && filesize($targetPath) > 0) {
                // Ottimizzazione riuscita
                @unlink($tempPath);
                $optimized = true;
                $note = $isAnimated ? 'GIF animata ottimizzata' : 'GIF ottimizzata';
                if ($needsResize) {
                    $note .= ' e ridimensionata';
                }
            } else {
                // Ottimizzazione fallita, usa file originale
                if (file_exists($tempPath)) {
                    @rename($tempPath, $targetPath);
                }
                $note = 'GIF salvata senza ottimizzazione (gifsicle fallito)';
            }
        } else {
            // gifsicle non disponibile: salva originale
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                return ['success' => false, 'message' => 'Errore durante il salvataggio del file'];
            }
            $note = $isAnimated ? 'GIF animata salvata (gifsicle non disponibile per ottimizzazione)' : 'GIF salvata (gifsicle non disponibile)';
        }
    } else {
        // Dimensioni OK, salva direttamente
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => false, 'message' => 'Errore durante il salvataggio del file'];
        }
        $note = 'GIF salvata';
    }
    
    $relativePath = 'uploads/featured_news/' . basename($targetPath);
    
    return [
        'success' => true,
        'imagePath' => $relativePath,
        'optimized' => $optimized,
        'note' => $note
    ];
}

private static function isGifAnimated(string $filePath): bool {
    if (!file_exists($filePath)) {
        return false;
    }
    
    $fh = @fopen($filePath, 'rb');
    if (!$fh) {
        return false;
    }
    
    $count = 0;
    // Cerca pattern "NETSCAPE2.0" che indica animazione, oppure conta frame
    while (!feof($fh) && $count < 2) {
        $chunk = @fread($fh, 1024 * 100); // Leggi 100KB alla volta
        $count += substr_count($chunk, "\x00\x21\xF9"); // Frame separator
    }
    
    @fclose($fh);
    return $count > 1;
}

private static function findGifsicle(): ?string {
    // Cerca gifsicle in path standard
    $paths = [
        '/usr/bin/gifsicle',
        '/usr/local/bin/gifsicle',
        'gifsicle' // Prova nel PATH
    ];
    
    foreach ($paths as $path) {
        $output = [];
        $returnVar = 0;
        @exec(escapeshellarg($path) . ' --version 2>&1', $output, $returnVar);
        if ($returnVar === 0) {
            return $path;
        }
    }
    
    return null;
}

public static function uploadNewsletter() {
    if (!isAdmin()) {
        return ['success' => false, 'message' => 'Non autorizzato'];
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        return ['success' => false, 'message' => 'File mancante o errore upload', 'errorCode' => $errorCode];
    }

    $file = $_FILES['file'];

    // Validazione: solo file HTML
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['html', 'htm'])) {
        return ['success' => false, 'message' => 'Formato non supportato. Caricare solo file HTML.'];
    }

    // Validazione MIME (text/html)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeType, ['text/html', 'text/plain'])) {
        return ['success' => false, 'message' => 'Il contenuto del file non è HTML valido.'];
    }

    // Limite 5 MB
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File troppo grande. Massimo 5 MB.'];
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'comunicazioni';

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        return ['success' => false, 'message' => 'Errore creazione directory upload'];
    }

    // Nome sicuro: timestamp + sanitize
    $safeName = 'newsletter_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME)) . '.html';
    $destPath = $uploadDir . DIRECTORY_SEPARATOR . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success' => false, 'message' => 'Errore nel salvataggio del file'];
    }

    return ['success' => true, 'filename' => $safeName];
}

public static function updateNewsletterIndex($input) {
    if (!isAdmin()) {
        return ['success' => false, 'message' => 'Non autorizzato'];
    }

    $filename = filter_var($input['filename'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);
    $title = filter_var($input['title'] ?? '', FILTER_SANITIZE_SPECIAL_CHARS);

    if (empty($filename) || empty($title)) {
        return ['success' => false, 'message' => 'Parametri mancanti (filename, title)'];
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'comunicazioni';
    $jsonPath = $uploadDir . DIRECTORY_SEPARATOR . 'newsletter_index.json';

    // Verifica che il file uploadato esista
    $filePath = $uploadDir . DIRECTORY_SEPARATOR . basename($filename);
    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => 'File newsletter non trovato sul server'];
    }

    // Leggi o inizializza indice
    $data = [];
    if (file_exists($jsonPath)) {
        $existing = json_decode(file_get_contents($jsonPath), true);
        if (is_array($existing)) {
            $data = $existing;
        }
    }

    // Estrai thumbnail dalla prima immagine in .mcnImageContent del file HTML
    $img = '';
    $html = @file_get_contents($filePath);
    if ($html) {
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);
        // Cerca la prima <img> dentro un elemento con classe mcnImageContent
        $nodes = $xpath->query("//*[contains(concat(' ',normalize-space(@class),' '),' mcnImageContent ')]//*[local-name()='img']/@src");
        if ($nodes && $nodes->length > 0) {
            $img = trim($nodes->item(0)->nodeValue);
        }
        // Fallback: prima <img> con src http nel documento
        if (empty($img)) {
            $nodes = $xpath->query("//img[starts-with(@src,'http')]/@src");
            if ($nodes && $nodes->length > 0) {
                $img = trim($nodes->item(0)->nodeValue);
            }
        }
    }

    // Aggiungi in testa
    $entry = [
        'title' => $title,
        'file'  => 'uploads/comunicazioni/' . basename($filename),
        'date'  => date('Y-m-d H:i:s')
    ];
    if (!empty($img)) {
        $entry['img'] = $img;
    }
    array_unshift($data, $entry);

    if (file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        return ['success' => false, 'message' => 'Errore scrittura indice newsletter'];
    }

    return ['success' => true];
}

// Funzione di utilità per ottenere lo slug dalla URL WP
private static function extractSlug($link) {
    // Rimuovi eventuale slash finale
    $url_path = parse_url($link, PHP_URL_PATH);
    $url_path = rtrim($url_path, '/');
    $parts = explode('/', $url_path);
    return end($parts);
}

}