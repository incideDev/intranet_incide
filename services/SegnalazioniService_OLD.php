<?php
/**
 * @deprecated Questo servizio è deprecato. Usa FormsDataService per nuove implementazioni.
 * I controlli legacy basati su role_id vanno sostituiti con userHasPermission().
 */
namespace Services;
use Services\NotificationService;

if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

class SegnalazioniService
{
    private static function getWritableUploadPath($formTable, $userId) {
        $base = substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/services'));
        $date = date('dmY');
        $relative = "uploads/tmp_segnalazioni/{$formTable}/{$userId}_{$date}";
        $fullPath = $base . '/' . $relative;

        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0775, true);
        }

        return [
            'absolute' => $fullPath,
            'relative' => $relative
        ];
    }

    private static function sanitizeFormName($form_name) {
        if (!$form_name) return '';
        return 'mod_' . strtolower(preg_replace('/[^a-z0-9_]/i', '_', $form_name));
    }
    
    private static function generateProtocollo(string $form_name): string {
        $prefisso = 'RS';
        $iniziali = strtoupper(implode('', array_map(fn($p) => $p[0] ?? '', explode(' ', $form_name))));
        return "{$prefisso}_{$iniziali}";
    }
    

    public static function getFilledForms($filtri = []) {
        global $database;
    
        $personale = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
        $personaleMap = [];
        foreach ($personale as $p) {
            $personaleMap[$p['user_id']] = $p['Nominativo'];
        }
    
        $forms = $database->query("SELECT id, name, table_name, responsabile, color, protocollo FROM forms", [], __FILE__);
        $allSegnalazioni = [];
    
        foreach ($forms as $form) {
            if (!empty($filtri['form_name']) && $filtri['form_name'] !== $form['name']) continue;
            if (!empty($filtri['responsabile'])) {
                $filtratoId = array_search($filtri['responsabile'], $personaleMap);
                if ($filtratoId === false || $filtratoId != $form['responsabile']) continue;
            }
            
            $tableName = $form['table_name'];
            $res = $database->query("SHOW TABLES LIKE :name", ['name' => $tableName], __FILE__);
            $tableExists = $res && $res->rowCount() > 0;
            if (!$tableExists) continue;
    
            $checkArg = $database->query("SHOW COLUMNS FROM `$tableName` LIKE 'argomentazione'", [], __FILE__);
            $hasArg = $checkArg && $checkArg->rowCount() > 0;
            
            $checkArch = $database->query("SHOW COLUMNS FROM `$tableName` LIKE 'archiviata'", [], __FILE__);
            $hasArchiviata = $checkArch && $checkArch->rowCount() > 0;

            $query = "SELECT id, submitted_by, submitted_at, deadline, status_id, codice_segnalazione, priority, assegnato_a" . ($hasArg ? ", argomentazione" : "") . " FROM `$tableName` WHERE 1=1";
            $params = [];
    
            if (!empty($filtri['status_id'])) {
                $query .= " AND status_id = :status_id";
                $params['status_id'] = $filtri['status_id'];
            }

            // --- FILTRO DATA INIZIO SEGNALAZIONI ---
            if (!empty($filtri['data_invio_min'])) {
                // Gestisce sia date complete che solo YYYY-MM-DD (solo la parte di data, senza ora)
                $query .= " AND DATE(submitted_at) >= :data_invio_min";
                $params['data_invio_min'] = $filtri['data_invio_min'];
            }

            if (!empty($filtri['archivio'])) {
                if (!$hasArchiviata) {
                    continue;
                }
                $query .= " AND archiviata = 1";
            } else {
                if ($hasArchiviata) {
                    $query .= " AND (archiviata IS NULL OR archiviata = 0)";
                }
            }

            $query .= " ORDER BY submitted_at DESC";
            $righe = $database->query($query, $params, __FILE__);
    
            foreach ($righe as $riga) {
                // Prendi nome e img del responsabile
                $responsabile_id = $form['responsabile'] ?? null;
                $responsabile_nome = $personaleMap[$responsabile_id] ?? '—';
                $responsabile_img = get_profile_image($responsabile_nome, 'nominativo');
                if (!$responsabile_img || $responsabile_img === 'assets/images/default_profile.png') {
                    $responsabile_img = '/assets/images/default_profile.png';
                } elseif (strpos($responsabile_img, '/') !== 0) {
                    $responsabile_img = '/' . $responsabile_img;
                }
            
                // Prendi nome e img di chi ha creato la segnalazione
                $creato_da_id = $riga['submitted_by'] ?? null;
                $creato_da_nome = $personaleMap[$creato_da_id] ?? '—';
                $creato_da_img = get_profile_image($creato_da_nome, 'nominativo');
                if (!$creato_da_img || $creato_da_img === 'assets/images/default_profile.png') {
                    $creato_da_img = '/assets/images/default_profile.png';
                } elseif (strpos($creato_da_img, '/') !== 0) {
                    $creato_da_img = '/' . $creato_da_img;
                }
            
                $assegnato_id = $riga['assegnato_a'] ?? null;
                $assegnato_nome = (!empty($assegnato_id) && isset($personaleMap[(string)$assegnato_id])) 
                    ? $personaleMap[(string)$assegnato_id] 
                    : null;
            
                $allSegnalazioni[] = [
                    'id' => $riga['id'],
                    'form_name' => fixMojibake($form['name']),
                    'creato_da' => fixMojibake($creato_da_nome),
                    'creato_da_img' => $creato_da_img,
                    'assegnato_a' => $riga['assegnato_a'],
                    'assegnato_a_nome' => fixMojibake($assegnato_nome),
                    'responsabile' => $responsabile_id,
                    'responsabile_nome' => fixMojibake($responsabile_nome),
                    'responsabile_img' => $responsabile_img,
                    'submitted_by' => (int)$riga['submitted_by'],
                    'data_invio' => $database->formatDate($riga['submitted_at']),
                    'data_scadenza' => $database->formatDate($riga['deadline']),
                    'priority' => fixMojibake($riga['priority'] ?? 'Media'),
                    'stato' => $riga['status_id'],
                    'table_name' => $tableName,
                    'color' => $form['color'] ?? '#CCCCCC',
                    'argomentazione' => $hasArg ? fixMojibake($riga['argomentazione'] ?? null) : null,
                    'codice_segnalazione' => fixMojibake($riga['codice_segnalazione'] ?? null)
                ];
            }                    
        }
        return ['success' => true, 'forms' => $allSegnalazioni];
    }
    
    public static function getFormEntry($form_name, $record_id) {
        global $database;
    
        $table = self::sanitizeFormName($form_name);
    
        $res = $database->query("SELECT * FROM forms WHERE name = :name", [':name' => $form_name], __FILE__);
        $form = $res ? $res->fetch(\PDO::FETCH_ASSOC) : null;
        if (!$form) return null;
    
        $resFields = $database->query("SELECT * FROM form_fields WHERE form_id = :form_id ORDER BY id ASC", ['form_id' => $form['id']], __FILE__);
        $fields = $resFields ? $resFields->fetchAll(\PDO::FETCH_ASSOC) : [];
    
        $resEntry = $database->query("SELECT * FROM `$table` WHERE id = :id", [':id' => $record_id], __FILE__);
        $entry = $resEntry ? $resEntry->fetch(\PDO::FETCH_ASSOC) : null;
    
        if (!$entry) return null;
    
        // ✅ Formatto le date italiane
        $entry['submitted_at'] = $database->formatDate($entry['submitted_at'] ?? '');
        if (!empty($entry['deadline'])) {
            $entry['deadline_raw'] = $entry['deadline']; // YYYY-MM-DD per input
            $entry['deadline'] = $database->formatDate($entry['deadline']); // dd/mm/yyyy per visualizzazione
        } else {
            $entry['deadline_raw'] = '';
            $entry['deadline'] = '';
        }        
        $entry['completed_at'] = $database->formatDate($entry['completed_at'] ?? '');

        // Applica fixMojibake ai dati dell'entry
        foreach ($entry as $key => $value) {
            $entry[$key] = fixMojibake($value ?? '');
        }

        // Applica fixMojibake ai fields
        foreach ($fields as &$field) {
            foreach ($field as $key => $value) {
                $field[$key] = fixMojibake($value ?? '');
            }
        }
        unset($field);

        return [
            'data' => $entry,
            'responsabile' => $form['responsabile'] ?? null,
            'protocollo' => fixMojibake($form['protocollo'] ?? null),
            'completed_at' => $entry['completed_at'],
            'fields' => $fields
        ];
    }
    
    public static function getFormStatistics() {
        global $database;

        $userRole = $_SESSION['role_id'] ?? 0;

        // Recupera solo i moduli visibili all'utente corrente
        $forms = $database->query("
            SELECT f.id, f.name, f.table_name, f.color, f.created_by, f.created_at, f.description, f.responsabile
            FROM forms f
            WHERE 
                NOT EXISTS (
                    SELECT 1 
                    FROM moduli_visibilita mv 
                    WHERE mv.modulo_id = f.id
                )
                OR EXISTS (
                    SELECT 1 
                    FROM moduli_visibilita mv 
                    WHERE mv.modulo_id = f.id AND mv.ruolo_id = :role_id
                )
        ", [':role_id' => $userRole], __FILE__);

        $stats = [];

        foreach ($forms as $form) {
            $tableName = $form['table_name'];
            // Verifica se la tabella esiste
            $check = $database->query("SHOW TABLES LIKE :table", [':table' => $tableName], __FILE__);
            $exists = $check && $check->rowCount() > 0;
            $count = 0;

            // Calcola i conteggi per stato
            $statusCounts = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0];

            if ($exists) {
                // Conteggio totale
                $resCount = $database->query("SELECT COUNT(*) as total FROM `$tableName`", [], __FILE__);
                $rowCount = $resCount ? $resCount->fetch(\PDO::FETCH_ASSOC) : null;
                $count = $rowCount['total'] ?? 0;

                // Conteggi per stato
                $resStatus = $database->query("SELECT status_id, COUNT(*) as cnt FROM `$tableName` GROUP BY status_id", [], __FILE__);
                if ($resStatus) {
                    while ($row = $resStatus->fetch(\PDO::FETCH_ASSOC)) {
                        $sid = strval($row['status_id']);
                        if (isset($statusCounts[$sid])) $statusCounts[$sid] = intval($row['cnt']);
                    }
                }
            }

            // dentro il foreach ($forms as $form) { ... } prima di pushare $stats[]
            $userId = $_SESSION['user_id'] ?? 0;
            $roleId = $_SESSION['role_id'] ?? 0;

            $visibile = true;
            $resV = $database->query(
                "SELECT 1
                FROM moduli_visibilita mv
                WHERE mv.modulo_id = :fid AND mv.ruolo_id = :rid
                UNION
                SELECT 1
                FROM moduli_visibilita_utenti mvu
                WHERE mvu.modulo_id = :fid AND mvu.user_id = :uid",
                [':fid' => $form['id'], ':rid' => $roleId, ':uid' => $userId],
                __FILE__
            );
            if ($resV && $resV->rowCount() === 0 && intval($roleId) !== 1) {
                $visibile = false;
            }
            if (!$visibile) continue;

            $creatorName = $database->getNominativoByUserId($form['created_by'] ?? 0);

            $creatorImg = get_profile_image($creatorName, 'nominativo');
            if (!$creatorImg || $creatorImg === 'assets/images/default_profile.png') {
                $creatorImg = '/assets/images/default_profile.png';
            } elseif (strpos($creatorImg, '/') !== 0) {
                $creatorImg = '/' . $creatorImg;
            }

            // ----- BLOCCO RESPONSABILE -----
            $responsabileNome = null;
            $responsabileImg = null;
            if (!empty($form['responsabile'])) {
                $responsabileNome = $database->getNominativoByUserId($form['responsabile']);
                $responsabileImg = get_profile_image($responsabileNome, 'nominativo');
                if (!$responsabileImg || $responsabileImg === 'assets/images/default_profile.png') {
                    $responsabileImg = '/assets/images/default_profile.png';
                } elseif (strpos($responsabileImg, '/') !== 0) {
                    $responsabileImg = '/' . $responsabileImg;
                }
            }
            // ----- FINE BLOCCO RESPONSABILE -----

            // Sanitizza il nome del form per visualizzazione (underscore → spazi)
            $displayName = ucwords(str_replace('_', ' ', $form['name']));

            $stats[] = [
                'id' => $form['id'],
                'name' => $displayName,  // Usa il nome sanitizzato per visualizzazione
                'original_name' => $form['name'], // Conserva il nome originale per uso interno
                'description' => $form['description'] ?? null,
                'total_reports' => (int)$count,
                'color' => $form['color'] ?? '#cccccc',
                'created_by' => $creatorName,
                'created_by_img' => $creatorImg,
                'created_at' => $database->formatDate($form['created_at'] ?? null),
                'responsabile_nome' => $responsabileNome,
                'responsabile_img' => $responsabileImg,
                'status_counts' => $statusCounts
            ];
        }

        return [
            'success' => true,
            'stats' => $stats
        ];
    }

    public static function getResponsabileInfo($user_id) {
        global $database;
    
        // Recupera nominativo
        $nominativo = $database->getNominativoByUserId($user_id);
        if (!$nominativo) {
            return ['success' => false, 'error' => 'Nominativo non trovato'];
        }
    
        // Usa funzione già definita in functions
        $imagePath = get_profile_image($nominativo, 'nominativo');
    
        if (!$imagePath || $imagePath === 'assets/images/default_profile.png') {
            $imagePath = '/assets/images/default_profile.png';
        } else {
            // assicurati che inizi con / (URL assoluto)
            if (strpos($imagePath, '/') !== 0) {
                $imagePath = '/' . $imagePath;
            }
        }
    
        return [
            'success' => true,
            'nominativo' => $nominativo,
            'image' => $imagePath
        ];
    }
    
    public static function getResponsabiliList() {
        global $database;
        $res = $database->query("SELECT user_id, Nominativo FROM personale WHERE attivo = 1 ORDER BY Nominativo ASC", [], __FILE__);

        if ($res instanceof \PDOStatement) {
            $data = [];
            foreach ($res as $row) {
                $img = get_profile_image($row['Nominativo'], 'nominativo');
                if (!$img || $img === 'assets/images/default_profile.png') {
                    $img = '/assets/images/default_profile.png';
                } elseif (strpos($img, '/') !== 0) {
                    $img = '/' . $img;
                }
                $data[] = [
                    'user_id' => $row['user_id'],
                    'Nominativo' => $row['Nominativo'],
                    'image' => $img
                ];
            }
            return ['success' => true, 'data' => $data];
        }

        return ['success' => false, 'error' => 'Errore nel caricamento dei responsabili'];
    }

    public static function updateFormStatus($args)
    {
        global $database;

        $table = preg_replace('/[^a-z0-9_]/i', '', $args['table_name'] ?? '');
        $id = (int)($args['form_id'] ?? 0);
        $status = (int)($args['stato'] ?? 0);
        $userId = $_SESSION['user_id'] ?? null;

        if (!$table || !$id || !$status || !$userId) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        // Recupera sia table_name che nome umano del form!
        $formInfo = $database->query("SELECT name, table_name, responsabile FROM forms WHERE table_name = :table", [':table' => $table], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        if (!$formInfo) return ['success' => false, 'message' => 'Form non trovato'];
        $form_name = $formInfo['name'];
        $responsabile = $formInfo['responsabile'];

        // Prendi assegnato_a (ID) dalla tabella dati
        $row = $database->query("SELECT assegnato_a FROM `$table` WHERE id = :id", ['id' => $id], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        $assegnatoA = $row['assegnato_a'] ?? null;

        $roleId = $_SESSION['role_id'] ?? null;
        if (
            strval($userId) !== strval($assegnatoA) &&
            strval($userId) !== strval($responsabile) &&
            intval($roleId) !== 1
        ) {
            return ['success' => false, 'message' => 'Solo il responsabile, l\'assegnatario o l\'amministratore possono cambiare lo stato'];
        }

        try {
            $database->query(
                "UPDATE `$table` SET status_id = :status WHERE id = :id",
                ['status' => $status, 'id' => $id],
                __FILE__
            );

            // Notifica
            $rowUser = $database->query("SELECT submitted_by, argomentazione FROM `$table` WHERE id = :id", ['id' => $id], __FILE__)->fetch(\PDO::FETCH_ASSOC);
            if ($rowUser && !empty($rowUser['submitted_by']) && $form_name) {
                $statiMap = [
                    1 => "Da Definire",
                    2 => "In Attesa",
                    3 => "In Revisione",
                    4 => "Accettato",
                    5 => "Rifiutato",
                    6 => "Completato"
                ];
                $nomeStato = $statiMap[$status] ?? "Stato aggiornato";
                $argomentazioneSafe = htmlspecialchars($rowUser['argomentazione'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $nomeStatoSafe = htmlspecialchars($nomeStato, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $msg = '<div class="notifica-categoria-segnalazioni">'
                    . 'Lo stato della tua segnalazione'
                    . (!empty($argomentazioneSafe) ? ' "<strong>' . $argomentazioneSafe . '</strong>"' : '')
                    . ' è stato aggiornato a: <strong>' . $nomeStatoSafe . '</strong>'
                    . '</div>';
                $link = "index.php?section=collaborazione&page=form_viewer&form_name=" . urlencode($form_name) . "&id=" . $id;
                NotificationService::inviaNotifica($rowUser['submitted_by'], $msg, $link);
            }
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    

    




    public static function updateFormEntry($post, $files, $userId = null){
        global $database;
        $userId = $_SESSION['user_id'] ?? null;
    
        $formName = $post['form_name'] ?? null;
        $recordId = $post['record_id'] ?? null;
    
        if (!$userId || !$formName || !$recordId) {
            return ['success' => false, 'message' => 'Parametri mancanti o utente non autenticato'];
        }
    
        $tableName = self::sanitizeFormName($formName);
    
        $resForm = $database->query("SELECT id FROM forms WHERE name = :name", [':name' => $formName], __FILE__);
        $form = $resForm ? $resForm->fetch(\PDO::FETCH_ASSOC) : null;
        if (!$form) return ['success' => false, 'message' => 'Form non trovato'];
        
        $resFields = $database->query("SELECT field_name, field_type FROM form_fields WHERE form_id = :id", [
            ':id' => $form['id']
        ], __FILE__);
        $fields = $resFields ? $resFields->fetchAll(\PDO::FETCH_ASSOC) : [];
        
        $dataIt = date('dmY');
        $paths = self::getWritableUploadPath($tableName, $userId);
        $uploadDir = $paths['absolute'];
        $relativePath = $paths['relative'];

        // CREA la cartella SOLO SE CI SONO FILE DA CARICARE
        $hasFiles = false;
        foreach ($fields as $field) {
            $name = strtolower($field['field_name']);
            $type = $field['field_type'];
            if ($type === 'file' && isset($files[$name]) && $files[$name]['error'] === UPLOAD_ERR_OK) {
                $hasFiles = true;
                break;
            }
        }
        if ($hasFiles && !is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0777, true)) {
                error_log("Impossibile creare la cartella: $uploadDir");
                return [
                    'success' => false,
                    'message' => "Impossibile creare la cartella upload: $uploadDir. Controlla permessi (www-data deve poter scrivere)."
                ];
            }
        }

        $set = [];
        $params = [':id' => $recordId];
        if (empty($params)) {
            error_log("⚠️ Parametri updateFormEntry vuoti: " . json_encode($post));
        }
        
        foreach ($fields as $field) {
            $name = strtolower($field['field_name']);
            $type = $field['field_type'];
        
            if ($type === 'file' && isset($files[$name]) && $files[$name]['error'] === UPLOAD_ERR_OK) {
                // Normalizza nome file (evita spazi, maiuscole, caratteri strani)
                $originalName = preg_replace('/[^a-z0-9_\-.]/i', '_', strtolower($files[$name]['name']));
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                // Crea sempre nome univoco e pulito
                $uniqueName = uniqid('img_', true) . '_' . date('Ymd_His') . '.' . $ext;
                $destination = $uploadDir . '/' . $uniqueName;

                // Verifica presenza MIME e EXTENSION
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $files[$name]['tmp_name']);
                finfo_close($finfo);

                $types = getAllowedImageTypes();
                $allowedMimes = $types['mimes'];
                $allowedExts = $types['exts'];

                // Controllo estensione e mime strettissimi
                if (!in_array($mime, $allowedMimes) || !in_array($ext, $allowedExts)) {
                    return ['success' => false, 'message' => "Tipo di file non consentito ($ext, $mime). Ammessi solo: jpg, jpeg, png."];
                }

                if ($files[$name]['size'] > 5 * 1024 * 1024) {
                    return ['success' => false, 'message' => 'Immagine troppo pesante (max 5MB).'];
                }

                // Blocca estensioni rischiose (anche camuffate)
                $estensioni_pericolose = ['php','phtml','phar','js','html','exe','svg','bat','sh','cmd','pl','cgi'];
                if (in_array($ext, $estensioni_pericolose)) {
                    return ['success' => false, 'message' => 'Estensione del file non consentita.'];
                }

                // Salva e comprimi in percorso unico
                $compressedPath = compressImage($files[$name]['tmp_name'], $destination, 75, true);
                if (!$compressedPath || !file_exists($compressedPath)) {
                    return ['success' => false, 'message' => 'Errore durante la compressione dell\'immagine.'];
                }

                // Controllo contenuto file (PHP, script, SVG, ecc)
                $contenutoFile = @file_get_contents($compressedPath);
                if (
                    $contenutoFile === false ||
                    preg_match('/<\?php|<script|<svg|base64|onerror=|onload=|javascript:/i', $contenutoFile)
                ) {
                    // Cancella solo se esiste davvero
                    if (file_exists($compressedPath)) @unlink($compressedPath);
                    return ['success' => false, 'message' => 'Il file contiene codice potenzialmente pericoloso. Upload annullato.'];
                }

                // Salva percorso relativo
                $relativeFinalPath = $relativePath . '/' . basename($compressedPath);
                $params[":$name"] = $relativeFinalPath;
                $set[] = "`$name` = :$name";
            } elseif (isset($post[$name])) {
                $value = is_array($post[$name]) ? implode(',', $post[$name]) : $post[$name];
        
                // Se il campo è di tipo date/datetime e il valore è vuoto, lo togli dal set (NON lo aggiorni)
                if (($type === 'date' || $type === 'datetime' || $type === 'timestamp') && (!$value || $value === "")) {
                    // Non aggiungere nulla: NON aggiorni la deadline
                    continue;
                }
        
                $set[] = "`$name` = :$name";
                $params[":$name"] = $value;
            }
        }
        
        if (empty($set)) return ['success' => false, 'message' => 'Nessun campo aggiornato'];
    
        $sql = "UPDATE `$tableName` SET " . implode(', ', $set) . " WHERE id = :id";
        $database->query($sql, $params, __FILE__);
        $lastId = (int)$post['record_id'];

        // Invia notifica se presente assegnazione
        if (!empty($post['assegnato_a'])) {
            $assegnatoRaw = $post['assegnato_a'] ?? null;
            $assegnatoId = null;

            if ($assegnatoRaw) {
                $res = $database->query("SELECT user_id FROM personale WHERE user_id = :id OR Nominativo = :nom", [
                    ':id' => $assegnatoRaw,
                    ':nom' => $assegnatoRaw
                ], __FILE__);
                $row = $res ? $res->fetch(\PDO::FETCH_ASSOC) : null;
                $assegnatoId = $row['user_id'] ?? null;
            }

            if ($assegnatoId) {
                $autore = $database->getNominativoByUserId($userId) ?? 'Qualcuno';
                $argomentazione = $post['argomentazione'] ?? '(nessuna descrizione)';
                $autoreSafe = htmlspecialchars($autore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $argomentazioneSafe = htmlspecialchars($argomentazione, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $msg = '<div class="notifica-categoria-segnalazioni">'
                    . '<strong>' . $autoreSafe . '</strong> ti ha assegnato una segnalazione'
                    . ($argomentazioneSafe ? ': <strong>' . $argomentazioneSafe . '</strong>' : '')
                    . '</div>';
                $link = "index.php?section=collaborazione&page=form_viewer&form_name=" . urlencode($formName) . "&id=" . $lastId;
                NotificationService::inviaNotifica($assegnatoId, $msg, $link);
            }
        }
        return ['success' => true, 'message' => 'Segnalazione aggiornata'];
    }
    
    public static function deleteFormEntry($form_name, $record_id) {
        global $database;
    
        if (!$form_name || !is_numeric($record_id)) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }
    
        $table = self::sanitizeFormName($form_name);
    
        try {
            $database->query("DELETE FROM `$table` WHERE id = :id", [':id' => $record_id], __FILE__);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }    
    
    public static function submitFormDetails($post, $files) {
        global $database;
        $submittedBy = $_SESSION['user_id'] ?? null;
    
        $formName = $post['form_name'] ?? null;
        $deadline = $post['deadline'] ?? null;

        if (!$submittedBy || !$formName || !$deadline) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }
    
        $tableName = self::sanitizeFormName($formName);
        $dataIt = date('dmY');
        $paths = self::getWritableUploadPath($tableName, $submittedBy);
        $uploadDir = $paths['absolute'];
        $relativePath = $paths['relative'];
        if (!is_dir($uploadDir)) {
            if (!is_writable(dirname($uploadDir))) {
                error_log(" Cartella non scrivibile: " . dirname($uploadDir));
            } else {
                mkdir($uploadDir, 0777, true);
            }
        }
    
        $columns = ['submitted_by', 'submitted_at', 'deadline', 'status_id'];
        $placeholders = [':submitted_by', 'NOW()', ':deadline', ':status_id'];
        $params = [
            ':submitted_by' => $submittedBy,
            ':deadline' => $deadline,
            ':status_id' => 1
        ];
        
        $colonneEsistenti = [];
        $resColonne = $database->query("SHOW COLUMNS FROM `$tableName`", [], __FILE__);
        if ($resColonne) {
            while ($riga = $resColonne->fetch(\PDO::FETCH_ASSOC)) {
                $colonneEsistenti[] = $riga['Field'];
            }
        }
        
        foreach ($post as $key => $value) {
            $col = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $key));
            if (!in_array($col, $colonneEsistenti)) continue;
            if (in_array($col, ['submitted_by', 'submitted_at', 'status_id', 'deadline'])) continue;

            $columns[] = "`$col`";
            $placeholders[] = is_numeric($col) ? ":c$col" : ':' . $col;
            $params[is_numeric($col) ? ":c$col" : ":" . $col] = is_array($value) ? implode(',', $value) : $value;
        }

        foreach ($files as $fieldName => $file) {
            // IGNORA il campo se vuoto o se l'utente ha deselezionato il file
            if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($file['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'Errore durante il caricamento del file.'];
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uniqueName = uniqid('img_', true) . '.' . $ext;
            $destination = $uploadDir . '/' . $uniqueName;

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $allowedMimes = ['image/jpeg', 'image/png'];
            $allowedExts = ['jpg', 'jpeg', 'png'];

            if (!in_array($mime, $allowedMimes) || !in_array($ext, $allowedExts)) {
                return [
                    'success' => false,
                    'message' => 'Tipo di file non consentito. Sono accettate solo immagini JPG e PNG.'
                ];
            }

            if ($file['size'] > 5 * 1024 * 1024) {
                return [
                    'success' => false,
                    'message' => 'Immagine troppo pesante. Il limite è 5MB.'
                ];
            }

            // Sicurezza: blocca solo estensioni eseguibili
            $pericolosi = ['php','phtml','phar','js','html'];
            if (in_array($ext, $pericolosi)) {
                return [
                    'success' => false,
                    'message' => 'Estensione del file non consentita.'
                ];
            }

            $compressedPath = compressImage($file['tmp_name'], $destination, 75, true);
            if (!$compressedPath) {
                return ['success' => false, 'message' => 'Errore durante la compressione dell\'immagine.'];
            }

            $col = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $fieldName));
            $paramKey = ':' . $col;

            if (!in_array("`$col`", $columns)) {
                $columns[] = "`$col`";
                $placeholders[] = $paramKey;
            }
            $params[$paramKey] = $relativePath . '/' . basename($compressedPath);
        }

        $res = $database->query("SELECT protocollo FROM forms WHERE name = :name", [':name' => $formName], __FILE__);
        $row = $res ? $res->fetch(\PDO::FETCH_ASSOC) : null;

        // genera le iniziali corrette dal nome del form
        $iniziali = strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', explode(' ', $formName))));
        $prefix = 'RS_' . $iniziali;

        // calcola numero progressivo
        $anno = date('y');
        $res = $database->query("SELECT MAX(id) as max_id FROM `$tableName`", [], __FILE__);
        $row = $res ? $res->fetch(\PDO::FETCH_ASSOC) : null;
        $lastId = $row['max_id'] ?? 0;
        $nextId = (int)$lastId + 1;

        // costruisci codice protocollo
        $codiceSegnalazione = "{$prefix}#" . str_pad($nextId, 3, '0', STR_PAD_LEFT) . "_{$anno}";
        if (in_array('codice_segnalazione', $colonneEsistenti)) {
            $columns[] = "`codice_segnalazione`";
            $placeholders[] = ":codice_segnalazione";
            $params[":codice_segnalazione"] = $codiceSegnalazione;
        }
        
        $sql = "INSERT INTO `$tableName` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        error_log("QUERY: " . $sql);
        error_log("PARAMS: " . json_encode($params));
        
        // Invia notifica se presente assegnazione
        $assegnatoRaw = $post['assegnato_a'] ?? null;
        $assegnatoId = null;

        if ($assegnatoRaw) {
            $res = $database->query("SELECT user_id FROM personale WHERE user_id = :id OR Nominativo = :nom", [
                ':id' => $assegnatoRaw,
                ':nom' => $assegnatoRaw
            ], __FILE__);
            $row = $res ? $res->fetch(\PDO::FETCH_ASSOC) : null;
            $assegnatoId = $row['user_id'] ?? null;
        }

        $result = $database->query($sql, $params, __FILE__);

        // OTTIENI ID CORRETTO PRIMA DI USARLO
        $lastId = $database->lastInsertId();
        if (!$lastId || $lastId == 0) {
            $lastId = $database->getLastInsertedIdFromTable($tableName);
        }
        
        // NOTIFICA
        if ($assegnatoId && $lastId > 0) {
            $autore = $database->getNominativoByUserId($submittedBy) ?? 'Qualcuno';
            $argomentazione = $post['argomentazione'] ?? '(nessuna descrizione)';
            $autoreSafe = htmlspecialchars($autore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $argomentazioneSafe = htmlspecialchars($argomentazione, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $msg = '<div class="notifica-categoria-segnalazioni">'
                . '<strong>' . $autoreSafe . '</strong> ti ha assegnato una segnalazione'
                . ($argomentazioneSafe ? ': <strong>' . $argomentazioneSafe . '</strong>' : '')
                . '</div>';
            $link = "index.php?section=collaborazione&page=form_viewer&form_name=" . urlencode($formName) . "&id=" . $lastId;
            NotificationService::inviaNotifica($assegnatoId, $msg, $link);
        }

        // NOTIFICA AL RESPONSABILE (subito dopo la creazione della segnalazione)
        $formRow = $database->query("SELECT responsabile FROM forms WHERE name = :name", [':name' => $formName], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        if ($formRow && !empty($formRow['responsabile'])) {
            $autore = $database->getNominativoByUserId($submittedBy) ?? 'Qualcuno';
            $argomentazione = $post['argomentazione'] ?? '(nessuna descrizione)';
            $autoreSafe = htmlspecialchars($autore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $argomentazioneSafe = htmlspecialchars($argomentazione, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $msg = '<div class="notifica-categoria-segnalazioni">'
                . '<strong>' . $autoreSafe . '</strong> ha creato una nuova segnalazione'
                . ($argomentazioneSafe ? ': <strong>' . $argomentazioneSafe . '</strong>' : '')
                . '</div>';
            $link = "index.php?section=collaborazione&page=form_viewer&form_name=" . urlencode($formName) . "&id=" . $lastId;
            NotificationService::inviaNotifica($formRow['responsabile'], $msg, $link);
        }

        return ['success' => true, 'message' => 'Modulo inviato correttamente', 'id' => $lastId];        
    }
    
    public static function getFirstEntryId($form_name) {
        global $database;
        $table = self::sanitizeFormName($form_name);
    
        $res = $database->query("SELECT id FROM `$table` ORDER BY submitted_at ASC LIMIT 1", [], __FILE__);
        $row = $res ? $res->fetch(\PDO::FETCH_ASSOC) : null;
        
        return $row ? $row['id'] : null;
    }

    public static function getScadenze($userId = null) {
        global $database;
    
        $forms = $database->query("SELECT id, name, table_name, color, responsabile FROM forms", [], __FILE__);
        $scadenze = [];
    
        foreach ($forms as $form) {
            $tableName = $form['table_name'];
            $responsabile = $form['responsabile'];
    
            // Verifica che la tabella esista
            $check = $database->query("SHOW TABLES LIKE :t", [':t' => $tableName], __FILE__);
            if (!$check || $check->rowCount() == 0) continue;
    
            // Verifica che la colonna deadline esista
            $checkCol = $database->query("SHOW COLUMNS FROM `$tableName` LIKE 'deadline'", [], __FILE__);
            if (!$checkCol || $checkCol->rowCount() == 0) continue;
    
            $query  = "SELECT id, argomentazione, deadline, status_id, priority, assegnato_a, codice_segnalazione
                    FROM `$tableName`
                    WHERE deadline IS NOT NULL";
            $rows = $database->query($query, [], __FILE__);

            foreach ($rows as $row) {
                // Solo responsabile o assegnato_a vede la scadenza!
                if ($userId && $row['assegnato_a'] != $userId && $responsabile != $userId) {
                    continue;
                }
    
                $scadenze[] = [
                    'id' => $row['id'],
                    'argomentazione' => $row['argomentazione'] ?? '',
                    'deadline' => $row['deadline'],
                    'status_id' => $row['status_id'] ?? null,
                    'priority' => $row['priority'] ?? null,
                    'assegnato_a' => $row['assegnato_a'] ?? null,
                    'codice_segnalazione' => $row['codice_segnalazione'] ?? null,
                    'form_name' => $form['name'],
                    'table_name' => $tableName,
                    'color' => $form['color'] ?? '#CCC'
                ];
            }
        }
    
        return ['success' => true, 'scadenze' => $scadenze];
    }
    

    public static function aggiornaEsitoSegnalazione($post)
    {
        global $database;
        $formName = $post['form_name'] ?? null;
        $recordId = $post['record_id'] ?? null;
        $esitoStato = $post['esito_stato'] ?? null;
        $esitoNote = $post['esito_note'] ?? null;
        $esitoDataPrevista = $post['esito_data_prevista'] ?? null;
        $userId = $_SESSION['user_id'] ?? null;

        if (!$formName || !$recordId || !$esitoStato || !$userId) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        $tableName = self::sanitizeFormName($formName);

        // Sicurezza: controlla che sia responsabile o assegnatario
        $row = $database->query("SELECT assegnato_a FROM `$tableName` WHERE id = :id", ['id' => $recordId], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        $formRow = $database->query("SELECT responsabile FROM forms WHERE name = :name", [':name' => $formName], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        $roleId = $_SESSION['role_id'] ?? null;
        $isAdmin = intval($roleId) === 1;
        $isResp = ($formRow && $formRow['responsabile'] && $formRow['responsabile'] == $userId);
        $isAss = ($row && $row['assegnato_a'] && $row['assegnato_a'] == $userId);

        if (!$isResp && !$isAss && !$isAdmin) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $database->query(
            "UPDATE `$tableName`
                SET esito_stato = :esito_stato,
                    status_id = :status_id,
                    esito_note = :esito_note,
                    esito_data_prevista = :esito_data_prevista,
                    esito_data = NOW()
                WHERE id = :id",
            [
                'esito_stato' => $esitoStato,
                'status_id' => (int)$esitoStato,
                'esito_note' => $esitoNote,
                'esito_data_prevista' => $esitoDataPrevista,
                'id' => $recordId
            ],
            __FILE__
        );

        // INVIA NOTIFICA ALL'UTENTE CHE HA APERTO LA SEGNALAZIONE
        $rowUtente = $database->query("SELECT submitted_by, argomentazione FROM `$tableName` WHERE id = :id", ['id' => $recordId], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        if ($rowUtente && !empty($rowUtente['submitted_by'])) {
            $idUtente = $rowUtente['submitted_by'];
            $autore = $database->getNominativoByUserId($userId) ?? 'Responsabile';
            $argomentazioneSafe = !empty($rowUtente['argomentazione']) ? htmlspecialchars($rowUtente['argomentazione'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
            // Mappa degli stati
            $statiMap = [
                1 => "Da Definire",
                2 => "In Attesa",
                3 => "In Revisione",
                4 => "Accettato",
                5 => "Rifiutato",
                6 => "Completato"
            ];
            $esitoTesto = isset($statiMap[$esitoStato]) ? $statiMap[$esitoStato] : $esitoStato;
            $esitoStatoSafe = htmlspecialchars($esitoTesto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $msg = '<div class="notifica-categoria-segnalazioni">'
                . '<strong>' . htmlspecialchars($autore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong> ha dato un esito alla tua segnalazione'
                . ($argomentazioneSafe ? ': <strong>' . $argomentazioneSafe . '</strong>' : '')
                . ($esitoStatoSafe ? ' &rarr; <span style="color:#C0392B;">' . $esitoStatoSafe . '</span>' : '')
                . '</div>';
            $link = "index.php?section=collaborazione&page=form_viewer&form_name=" . urlencode($formName) . "&id=" . $recordId;
            $resNotifica = NotificationService::inviaNotifica($idUtente, $msg, $link);
            if (!empty($resNotifica['troncato'])) {
                return [
                    'success' => false,
                    'message' => $resNotifica['message']
                ];
            }
        }

        // (opzionale: invia notifica all’utente che ha creato la segnalazione)
        return ['success' => true, 'message' => 'Esito aggiornato'];
    }
    
    public static function aggiornaAssegnatoA($params) {
        global $database;
    
        $form_id = isset($params['form_id']) ? intval($params['form_id']) : 0;
        $table = isset($params['table_name']) ? self::sanitizeFormName($params['table_name']) : '';
        $assegnato_a = isset($params['assegnato_a']) ? trim($params['assegnato_a']) : '';
    
        // Verifica login utente
        $userid = $_SESSION['user_id'] ?? 0;
        if (!$userid || !$form_id || !$table) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }
    
        // Prendi il responsabile del modulo dalla tabella forms
        $sql = "SELECT responsabile FROM forms WHERE table_name = :table LIMIT 1";
        $row = $database->query($sql, [':table' => $table], __FILE__)->fetch(\PDO::FETCH_ASSOC);
    
        $roleId = $_SESSION['role_id'] ?? null;
        if (!$row || (strval($row['responsabile']) !== strval($userid) && intval($roleId) !== 1)) {
            return ['success' => false, 'message' => 'Solo il responsabile o l\'amministratore possono assegnare'];
        }

        // Aggiorna assegnato_a nella tabella del modulo
        $sql = "UPDATE $table SET assegnato_a = :assegnato WHERE id = :id LIMIT 1";
        $ok = $database->query($sql, [
            ':assegnato' => $assegnato_a,
            ':id' => $form_id
        ], __FILE__);
    
        if ($ok) {
            // --- INVIA NOTIFICA ASSEGNATO ---
            if (!empty($assegnato_a)) {
                $autore = $database->getNominativoByUserId($userid) ?? 'Responsabile';
                $riga = $database->query("SELECT argomentazione FROM $table WHERE id = :id", [':id' => $form_id], __FILE__)->fetch(\PDO::FETCH_ASSOC);
                $argomentazione = $riga['argomentazione'] ?? '';
                $autoreSafe = htmlspecialchars($autore, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $argomentazioneSafe = htmlspecialchars($argomentazione, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $msg = '<div class="notifica-categoria-segnalazioni">'
                    . '<strong>' . $autoreSafe . '</strong> ti ha assegnato una segnalazione'
                    . ($argomentazioneSafe ? ': <strong>' . $argomentazioneSafe . '</strong>' : '')
                    . '</div>';
                // Recupera il nome umano del form dalla tabella forms
                $rowForm = $database->query("SELECT name FROM forms WHERE table_name = :t", [':t' => $table], __FILE__)->fetch(\PDO::FETCH_ASSOC);
                $formName = $rowForm['name'] ?? $table; // fallback, ma non dovrebbe servire

                $link = "index.php?section=collaborazione&page=form_viewer&form_name=" . urlencode($formName) . "&id=" . $form_id;
                NotificationService::inviaNotifica($assegnato_a, $msg, $link);
            }
            return ['success' => true];
        } else {
            return ['success' => false, 'message' => 'Errore salvataggio'];
        }

    }

    public static function archiviaSegnalazione($input) {
        global $database;
        $form_name = isset($input['form_name']) ? $input['form_name'] : '';
        $record_id = isset($input['record_id']) ? intval($input['record_id']) : 0;

        if (!$form_name || !$record_id) {
            return ['success' => false, 'message' => 'Parametri mancanti.'];
        }

        $table = self::sanitizeFormName($form_name);

        // Verifica esistenza campo 'archiviata'
        $fields = $database->query("SHOW COLUMNS FROM `$table` LIKE 'archiviata'", [], __FILE__);
        if (!$fields || $fields->rowCount() == 0) {
            $database->query("ALTER TABLE `$table` ADD COLUMN archiviata TINYINT(1) DEFAULT 0", [], __FILE__);
        }

        // Aggiorna il record
        $sql = "UPDATE `$table` SET archiviata = 1 WHERE id = :id";
        $res = $database->query($sql, [':id' => $record_id], __FILE__);
        if ($res && $res->rowCount() > 0) {
            return ['success' => true];
        }
        return ['success' => false, 'message' => 'Errore durante archiviazione.'];
    }

    public static function ripristinaSegnalazione($input) {
        global $database;
        $form_name = isset($input['form_name']) ? $input['form_name'] : '';
        $record_id = isset($input['record_id']) ? intval($input['record_id']) : 0;

        if (!$form_name || !$record_id) {
            return ['success' => false, 'message' => 'Parametri mancanti.'];
        }

        // Trova il nome fisico della tabella
        $table = $form_name;
        $table = self::sanitizeFormName($form_name);

        // Verifica esistenza campo 'archiviata'
        $fields = $database->query("SHOW COLUMNS FROM `$table` LIKE 'archiviata'", [], __FILE__);
        if (!$fields || $fields->rowCount() == 0) {
            return ['success' => false, 'message' => "Questa segnalazione non supporta l'archiviazione."];
        }

        // Aggiorna il record
        $sql = "UPDATE `$table` SET archiviata = 0 WHERE id = :id";
        $res = $database->query($sql, [':id' => $record_id], __FILE__);
        if ($res && $res->rowCount() > 0) {
            return ['success' => true];
        }
        return ['success' => false, 'message' => 'Errore durante il ripristino.'];
    }




}
