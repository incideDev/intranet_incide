<?php

namespace Services;

use Exception;
use PDO;
class CvService
{
    // Configurazione
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'msg'];
    private const PYTHON_SCRIPT_REL_PATH = '/core/tools/cv_parser/cv_parser.py';

    /**
     * Gestisce l'upload dei CV e il parsing tramite Python
     */
    public static function handleUpload(): array
    {
        global $database;

        if (empty($_FILES['cv_files'])) {
            return ['success' => false, 'error' => 'Nessun file caricato'];
        }

        $files = $_FILES['cv_files'];
        $results = [];
        $errors = [];

        // Supporto per upload singolo o multiplo
        $fileCount = is_array($files['name']) ? count($files['name']) : 1;

        if (!is_array($files['name'])) {
            // Normalizza struttura se singolo file ma non in array
            $files = [
                'name' => [$files['name']],
                'type' => [$files['type']],
                'tmp_name' => [$files['tmp_name']],
                'error' => [$files['error']],
                'size' => [$files['size']]
            ];
            $fileCount = 1;
        }

        for ($i = 0; $i < $fileCount; $i++) {
            $result = self::processCvFile($files, $i);
            $results[] = $result;

            if (!$result['success']) {
                $errors[] = $result;
            }
        }

        $response = [
            'success' => count($errors) === 0,
            'total_files' => $fileCount,
            'processed' => count(array_filter($results, fn($r) => $r['success'])),
            'failed' => count($errors),
            'results' => $results
        ];

        if (!empty($errors) && defined('DEBUG_MODE') && DEBUG_MODE) {
            $response['errors'] = $errors;
        }

        return $response;
    }

    private static function processCvFile(array $fileInfo, int $index): array
    {
        $fileName = $fileInfo['name'][$index];
        $fileTmp = $fileInfo['tmp_name'][$index];
        $fileSize = $fileInfo['size'][$index];
        $fileError = $fileInfo['error'][$index];

        // 1. Validazione Upload
        if ($fileError !== UPLOAD_ERR_OK) {
            $msg = 'Errore upload generico';
            switch ($fileError) {
                case UPLOAD_ERR_INI_SIZE:
                    $msg = 'File supera upload_max_filesize';
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $msg = 'File supera MAX_FILE_SIZE form';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $msg = 'Caricamento parziale';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $msg = 'Nessun file caricato';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $msg = 'Cartella temporanea mancante';
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $msg = 'Scrittura su disco fallita';
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $msg = 'Upload bloccato da estensione';
                    break;
            }
            error_log("CvService: Upload Error per $fileName: $msg ($fileError)");
            return ['success' => false, 'file' => $fileName, 'error' => $msg];
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            return [
                'success' => false,
                'file' => $fileName,
                'error' => 'File troppo grande (max ' . (self::MAX_FILE_SIZE / 1024 / 1024) . ' MB)'
            ];
        }

        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($fileExt, self::ALLOWED_EXTENSIONS)) {
            return [
                'success' => false,
                'file' => $fileName,
                'error' => 'Formato file non supportato (' . implode(', ', self::ALLOWED_EXTENSIONS) . ')'
            ];
        }

        // 2. Destinazione Storage
        // Recupera root progetto in modo sicuro
        $projectRoot = realpath(__DIR__ . '/..');
        $baseUploads = $projectRoot . '/uploads';

        // Fix: Normalizza separatori per Windows
        $baseUploads = str_replace('\\', '/', $baseUploads);

        // Verifica Base Uploads
        if (!is_dir($baseUploads)) {
            if (!@mkdir($baseUploads, 0775, true)) {
                $err = error_get_last()['message'] ?? 'Unknown';
                error_log("CvService: Impossibile creare uploads root ($baseUploads): $err");
                return ['success' => false, 'file' => $fileName, 'error' => "Server Error: Cannot create root uploads dir ($err)"];
            }
        }

        $relPath = 'cv/' . date('Y/m/d');
        $uploadDir = $baseUploads . '/' . $relPath;

        // 3. Creazione Directory (Ricorsiva)
        if (!is_dir($uploadDir)) {
            if (!@mkdir($uploadDir, 0777, true)) { // 0777 su Windows è ignorato ma su Linux è permissivo
                $err = error_get_last()['message'] ?? 'Unknown';
                error_log("CvService: Mkdir failed for $uploadDir: $err");

                // Ritorna errore specifico all'utente per debug
                return [
                    'success' => false,
                    'file' => $fileName,
                    'error' => "FS Error: mkdir('$uploadDir') failed. Reason: $err"
                ];
            }
        }

        if (!is_writable($uploadDir)) {
            return [
                'success' => false,
                'file' => $fileName,
                'error' => "FS Error: Directory created but not writable: $uploadDir"
            ];
        }

        // 4. Calcolo Nome File Sicuro
        $safeName = preg_replace('/[^\w\-.]+/u', '_', $fileName);
        // Formato nome: uniqid + safeName per leggibilità mantenendo unicità temporale
        $uniqueName = uniqid() . '_' . $safeName;
        $destPath = $uploadDir . '/' . $uniqueName;

        // 5. Spostamento
        if (!@move_uploaded_file($fileTmp, $destPath)) {
            $e = error_get_last()['message'] ?? 'Unknown';
            error_log("CvService: move_uploaded_file fallito. Src: $fileTmp, Dest: $destPath. Err: $e");
            return ['success' => false, 'file' => $fileName, 'error' => 'Errore salvataggio fisico del file'];
        }

        // Imposta permessi leggibili per il web server/script successivi
        @chmod($destPath, 0664);

        // 6. Parsing Python
        $parsedData = self::parseCvWithPython($destPath);

        if (!is_array($parsedData) || isset($parsedData['error'])) {
            $err = is_array($parsedData) && isset($parsedData['error']) ? $parsedData['error'] : 'Risposta parser non valida';
            // Non cancelliamo il file, potrebbe servire per debug o recupero manuale?
            // Per ora lasciamolo, o spostiamolo in 'failed'?
            return [
                'success' => false,
                'file' => $fileName,
                'error' => 'Errore parsing CV: ' . $err
            ];
        }

        $profession = (string) ($parsedData['profession'] ?? '');
        $score = (float) ($parsedData['score'] ?? 0);

        $nome = trim((string) ($parsedData['personal_info']['nome'] ?? ''));
        $cognome = trim((string) ($parsedData['personal_info']['cognome'] ?? ''));
        $email = trim((string) ($parsedData['personal_info']['email'] ?? ''));
        $telefono = trim((string) ($parsedData['personal_info']['telefono'] ?? ''));

        if ($score <= 0 || $profession === 'Non classificato') {
            return [
                'success' => false,
                'file' => $fileName,
                'error' => 'CV non classificabile o score insufficiente'
            ];
        }

        // Blocca SOLO se manca ogni identificativo minimo (nome+cognome oppure contatto)
        $hasName = ($nome !== '' && $cognome !== '' && stripos($nome, 'errore') === false && stripos($cognome, 'errore') === false);
        $hasContact = ($email !== '' || $telefono !== '');

        if (!$hasName && !$hasContact) {
            return [
                'success' => false,
                'file' => $fileName,
                'error' => 'Identificativo candidato non rilevato (nome/cognome o contatto)'
            ];
        }

        $dbResult = self::saveCvToDatabase($parsedData, $uniqueName, $destPath);

        if (is_array($dbResult) && isset($dbResult['error'])) {
            return [
                'success' => false,
                'file' => $fileName,
                'error' => 'Errore salvataggio DB: ' . $dbResult['error']
            ];
        }

        $candidatoId = (int) $dbResult;

        if ($candidatoId > 0) {
            self::logActivity($candidatoId, 'cv_caricato', "File: $fileName");

            return [
                'success' => true,
                'file' => $fileName,
                'candidato_id' => $candidatoId,
                'nome' => ($parsedData['personal_info']['nome'] ?? '') . ' ' . ($parsedData['personal_info']['cognome'] ?? ''),
                'professionalita' => $parsedData['profession'],
                'score' => $parsedData['score']
            ];
        } else {
            return [
                'success' => false,
                'file' => $fileName,
                'error' => 'Errore sconosciuto nel salvataggio DB'
            ];
        }
    }

    private static function parseCvWithPython(string $filePath): array
    {
        $scriptPath = __DIR__ . '/..' . self::PYTHON_SCRIPT_REL_PATH;
        if (!file_exists($scriptPath)) {
            error_log("CvService - Python script not found at: $scriptPath");
            return ['error' => 'Script parser non trovato'];
        }

        $python = self::getPythonExecutable();

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && strtolower($python) === 'py') {
            $command = 'py -3 ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($filePath);
        } else {
            $command = escapeshellarg($python) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($filePath);
        }

        error_log("CvService - Executing: $command");

        if (!function_exists('proc_open')) {
            return ['error' => 'proc_open non disponibile'];
        }

        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $returnValue = proc_close($process);

            if ($returnValue !== 0) {
                error_log("CvService - Python Error: $errors");
                return ['error' => 'Python script failed: ' . $errors];
            }
        } else {
            return ['error' => 'Impossibile eseguire il comando Python'];
        }

        // Clean output
        $output = str_replace("\xEF\xBB\xBF", '', $output);
        $output = trim($output);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && function_exists('iconv')) {
            $output = iconv('UTF-8', 'UTF-8//IGNORE', $output);
        }

        $parsed = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("CvService - JSON Decode Error: " . json_last_error_msg() . " | Output snippet: " . substr($output, 0, 100));
            return ['error' => 'Errore parsing JSON: ' . json_last_error_msg()];
        }

        return is_array($parsed) ? $parsed : ['error' => 'Risposta parser invalida'];
    }

    /**
     * Valida struttura e contenuto del JSON parsato prima dell'inserimento DB
     * Distingue tra errori bloccanti (ritorna error) e warnings (logga ma procede)
     * Supporta schema V1 (legacy) e V2 (con projects)
     *
     * @param array $data Dati parsati dal Python
     * @return array ['warnings' => [...]] se valido, ['error' => msg] se errore bloccante
     */
    private static function validateParsedData(array $data): array
    {
        // ERRORI BLOCCANTI: struttura JSON fondamentalmente rotta

        // 1. Verifica chiavi top-level obbligatorie (V1 compatible)
        $requiredKeys = ['personal_info', 'profession', 'score', 'education', 'experience', 'skills', 'languages', 'certifications'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $data)) {
                return ['error' => "Chiave obbligatoria mancante: {$key}"];
            }
        }

        // 2. Verifica personal_info è array
        if (!is_array($data['personal_info'])) {
            return ['error' => 'personal_info deve essere un array'];
        }

        // 3. Verifica profession è stringa
        if (!is_null($data['profession']) && !is_string($data['profession'])) {
            return ['error' => 'profession deve essere una stringa o null'];
        }

        // 4. Verifica score è numerico e in range
        if (!is_numeric($data['score'])) {
            return ['error' => 'score deve essere numerico'];
        }
        $score = (float) $data['score'];
        if ($score < 0 || $score > 100) {
            return ['error' => 'score deve essere un numero tra 0 e 100'];
        }

        // 5. Verifica collections sono array (anche vuoti)
        $arrayFields = ['education', 'experience', 'skills', 'languages', 'certifications'];
        foreach ($arrayFields as $field) {
            if (!is_array($data[$field])) {
                return ['error' => "{$field} deve essere un array"];
            }
        }

        // 6. V2: Verifica projects se presente (opzionale per backward compatibility)
        if (array_key_exists('projects', $data) && !is_array($data['projects'])) {
            return ['error' => 'projects deve essere un array'];
        }

        // WARNINGS NON BLOCCANTI: dati mancanti/incompleti (logga ma non blocca)
        $warnings = [];

        // Merge warnings dal parser (V2)
        if (!empty($data['warnings']) && is_array($data['warnings'])) {
            foreach ($data['warnings'] as $w) {
                if (is_string($w) && $w !== '') {
                    $warnings[] = $w;
                }
            }
        }

        // Nome/cognome mancanti
        if (empty($data['personal_info']['nome']) || empty($data['personal_info']['cognome'])) {
            $warnings[] = 'Nome o cognome non rilevato (salvati come NULL)';
        }

        // Experience senza azienda e posizione (item vuoto, verrà filtrato)
        foreach ($data['experience'] as $idx => $exp) {
            if (empty($exp['azienda']) && empty($exp['posizione'])) {
                $warnings[] = "Esperienza #{$idx} vuota ignorata";
            }
        }

        // Education senza titolo (item vuoto, verrà filtrato)
        foreach ($data['education'] as $idx => $edu) {
            if (empty($edu['titolo'])) {
                $warnings[] = "Istruzione #{$idx} senza titolo ignorata";
            }
        }

        // Certifications senza nome (item vuoto, verrà filtrato)
        foreach ($data['certifications'] as $idx => $cert) {
            if (empty($cert['nome'])) {
                $warnings[] = "Certificazione #{$idx} senza nome ignorata";
            }
        }

        // V2: Validate projects and add warnings for incomplete ones
        $projects = $data['projects'] ?? [];
        foreach ($projects as $idx => $proj) {
            // Tronca descrizioni troppo lunghe e genera warning
            if (!empty($proj['descrizione_breve']) && strlen($proj['descrizione_breve']) > 500) {
                $warnings[] = "Progetto #{$idx}: descrizione troncata a 500 caratteri";
            }
        }

        return ['warnings' => $warnings];
    }

    /**
     * Calcola score "enhanced" V2 applicando correzioni deterministiche
     * Regole chiare per CV tecnici con "anchor forti" deterministiche.
     *
     * REGOLE SCORING V2:
     * +10 se certificazioni contengono "UNI" o "BIM" o "Manager"
     * +8 se ruolo/professione contiene "MANAGER"
     * +6 se anni continui nella stessa azienda > 5 (fidelita)
     * +4 se ha almeno N progetti con importo > 1M
     * +3 se professional_profile presente e ben scritto
     *
     * NON penalizzare CV con sezioni progetti verbose (qualita documento != qualita candidato)
     *
     * @param array $data Dati parsati (con experience, skills, profession, projects)
     * @param float $baseScore Score base dal Python (0-100)
     * @return float Score corretto (0-100)
     */
    private static function calculateEnhancedScore(array $data, float $baseScore): float
    {
        $score = $baseScore;

        // === BONUS POSITIVI (anchor forti) ===

        // 1. CERTIFICAZIONI FORTI: +10 se contengono UNI/BIM/Manager
        $certBonus = 0;
        foreach ($data['certifications'] ?? [] as $cert) {
            $certName = strtoupper($cert['nome'] ?? '');
            // UNI = normativa italiana, forte segnale di professionalita tecnica
            if (preg_match('/\bUNI\s*[\d\-:]/', $certName)) {
                $certBonus += 4;
            }
            // BIM certification = forte segnale per settore costruzioni
            if (preg_match('/\bBIM\s*(MANAGER|COORDINATOR|SPECIALIST|MODELER)?\b/', $certName)) {
                $certBonus += 4;
            }
            // Manager nel certificato = leadership riconosciuta
            if (strpos($certName, 'MANAGER') !== false) {
                $certBonus += 3;
            }
            // Normative ISO/EN = professionalita
            if (preg_match('/\b(ISO|EN)\s*\d/', $certName)) {
                $certBonus += 2;
            }
        }
        $score += min($certBonus, 10); // Cap a +10

        // 2. RUOLO MANAGER: +8 se professione/ruoli contengono MANAGER
        $profession = strtoupper($data['profession'] ?? '');
        $hasManagerRole = false;
        if (strpos($profession, 'MANAGER') !== false) {
            $hasManagerRole = true;
        }
        foreach ($data['experience'] ?? [] as $exp) {
            $posizione = strtoupper($exp['posizione'] ?? '');
            if (strpos($posizione, 'MANAGER') !== false || strpos($posizione, 'RESPONSABILE') !== false) {
                $hasManagerRole = true;
            }
        }
        // Bonus anche da progetti V2
        foreach ($data['projects'] ?? [] as $proj) {
            $ruolo = strtoupper($proj['ruolo'] ?? '');
            if (strpos($ruolo, 'MANAGER') !== false || strpos($ruolo, 'RESPONSABILE') !== false) {
                $hasManagerRole = true;
            }
        }
        if ($hasManagerRole) {
            $score += 8;
        }

        // 3. FEDELTA AZIENDALE: +6 se anni continui stessa azienda > 5
        $maxYearsAtCompany = 0;
        foreach ($data['experience'] ?? [] as $exp) {
            $startYear = null;
            $endYear = null;

            if (!empty($exp['data_inizio'])) {
                if (preg_match('/(\d{4})/', $exp['data_inizio'], $m)) {
                    $startYear = (int) $m[1];
                }
            }
            if (!empty($exp['in_corso'])) {
                $endYear = (int) date('Y');
            } elseif (!empty($exp['data_fine'])) {
                if (preg_match('/(\d{4})/', $exp['data_fine'], $m)) {
                    $endYear = (int) $m[1];
                }
            }

            if ($startYear && $endYear) {
                $years = $endYear - $startYear;
                if ($years > $maxYearsAtCompany) {
                    $maxYearsAtCompany = $years;
                }
            }
        }
        if ($maxYearsAtCompany >= 5) {
            $score += 6;
        } elseif ($maxYearsAtCompany >= 3) {
            $score += 3;
        }

        // 4. PROGETTI DI VALORE: +4 se almeno 2 progetti con importo > 1M
        $bigProjects = 0;
        foreach ($data['projects'] ?? [] as $proj) {
            $importo = $proj['importo_euro'] ?? 0;
            if (is_numeric($importo) && $importo >= 1000000) {
                $bigProjects++;
            }
        }
        if ($bigProjects >= 2) {
            $score += 4;
        } elseif ($bigProjects >= 1) {
            $score += 2;
        }

        // 5. PROFESSIONAL PROFILE: +3 se presente e ben scritto (> 100 char)
        $profile = $data['professional_profile'] ?? '';
        if (is_string($profile) && strlen(trim($profile)) > 100) {
            $score += 3;
        }

        // === COERENZA: bonus se ruoli/skills allineati con professionalita ===
        $coherenceBonus = 0;
        if ($profession !== '') {
            // Conta esperienze coerenti
            $coherentExperiences = 0;
            foreach ($data['experience'] ?? [] as $exp) {
                $posizione = strtoupper($exp['posizione'] ?? '');
                $professionWords = array_filter(preg_split('/\s+/', $profession), fn($w) => strlen($w) > 2);
                $posizioneWords = array_filter(preg_split('/\s+/', $posizione), fn($w) => strlen($w) > 2);

                $overlap = count(array_intersect($professionWords, $posizioneWords));
                if ($overlap > 0) {
                    $coherentExperiences++;
                }
            }
            $coherenceBonus += min($coherentExperiences, 5);

            // Skills coerenti
            $professionSkillHints = [
                'INGEGNERE STRUTTURALE' => ['SAP2000', 'ETABS', 'MIDAS', 'STRAUS', 'TEKLA'],
                'BIM' => ['REVIT', 'NAVISWORKS', 'BIM 360'],
                'DISEGNATORE CAD' => ['AUTOCAD', 'CIVIL 3D', 'RHINO', 'SKETCHUP'],
                'PROJECT MANAGER' => ['MS PROJECT', 'PRIMAVERA'],
            ];

            $targets = [];
            foreach ($professionSkillHints as $pKey => $hints) {
                if (strpos($profession, $pKey) !== false) {
                    $targets = array_merge($targets, $hints);
                }
            }

            $coherentSkills = 0;
            if (!empty($targets)) {
                foreach ($data['skills'] ?? [] as $skill) {
                    $skillName = strtoupper(trim((string) ($skill['nome'] ?? '')));
                    if ($skillName === '')
                        continue;
                    foreach ($targets as $t) {
                        if (strpos($skillName, $t) !== false) {
                            $coherentSkills++;
                            break;
                        }
                    }
                }
            }
            $coherenceBonus += min($coherentSkills, 4);
        }
        $score += $coherenceBonus;

        // === PENALITA LIMITATE (non penalizzare progetti verbose) ===

        // Gap > 24 mesi tra esperienze consecutive = possibile red flag
        // Ma solo penalita leggera per non punire chi ha fatto progetti freelance
        if (!empty($data['experience'])) {
            $experiences = $data['experience'];
            usort($experiences, function ($a, $b) {
                $aStart = $a['data_inizio'] ?? '';
                $bStart = $b['data_inizio'] ?? '';
                $aKey = preg_match('/^\d{4}/', $aStart, $m) ? $m[0] : '0000';
                $bKey = preg_match('/^\d{4}/', $bStart, $m) ? $m[0] : '0000';
                return strcmp($bKey, $aKey);
            });

            $gapPenalty = 0;
            for ($i = 0; $i < count($experiences) - 1; $i++) {
                $recent = $experiences[$i];
                $older = $experiences[$i + 1];

                $recentStartYear = null;
                $olderEndYear = null;

                if (!empty($recent['data_inizio']) && preg_match('/(\d{4})/', $recent['data_inizio'], $m)) {
                    $recentStartYear = (int) $m[1];
                }
                if (!empty($older['in_corso'])) {
                    continue; // Incoerente, skip
                }
                if (!empty($older['data_fine']) && preg_match('/(\d{4})/', $older['data_fine'], $m)) {
                    $olderEndYear = (int) $m[1];
                }

                if ($recentStartYear && $olderEndYear) {
                    $gapYears = $recentStartYear - $olderEndYear;
                    // Solo gap > 2 anni = penalita leggera
                    if ($gapYears > 2) {
                        $gapPenalty += min($gapYears - 2, 3); // Max -3 per gap
                    }
                }
            }
            $score -= min($gapPenalty, 8); // Cap totale -8
        }

        // Clamp score a range 0-100
        return max(0, min(100, round($score, 2)));
    }

    /**
     * Salva i dati del CV parsato nel database
     * Supporta schema V1 (legacy) e V2 (con projects, professional_profile)
     *
     * @return int|array Returns ID (int) on success, or array ['error' => msg] on failure
     */
    private static function saveCvToDatabase(array $data, string $filename, string $filepath)
    {
        global $database;
        // Usa $database->connection per le transazioni poiché MySQLDB non espone beginTransaction
        // $database è istanza di MySQLDB che ha proprietà pubblica $connection (PDO)

        // VALIDAZIONE STRICT: verifica struttura JSON prima di transazione
        $validation = self::validateParsedData($data);
        if (!empty($validation['error'])) {
            error_log("CvService - Validation Error: " . $validation['error']);
            return ['error' => $validation['error']];
        }
        // se vuoi: salva warnings nel log o ritornali al chiamante
        $warnings = [];
        if (!empty($validation['warnings']) && is_array($validation['warnings'])) {
            $warnings = $validation['warnings'];
            foreach ($warnings as $w) {
                error_log("CvService - Warning: " . $w);
            }
        }
        $warningsJson = !empty($warnings) ? json_encode($warnings, JSON_UNESCAPED_UNICODE) : null;

        // Determina schema version (V2 se presente, default V1 per backward compat)
        $schemaVersion = isset($data['schema_version']) ? (int) $data['schema_version'] : 1;

        try {
            if ($database->connection instanceof PDO) {
                $database->connection->beginTransaction();
            }

            $personal = $data['personal_info'];

            // Calcola score enhanced (applica correzioni deterministiche)
            $baseScore = (float) ($data['score'] ?? 0);
            $enhancedScore = self::calculateEnhancedScore($data, $baseScore);

            // Professional profile (V2)
            $professionalProfile = null;
            if (!empty($data['professional_profile']) && is_string($data['professional_profile'])) {
                $professionalProfile = substr(trim($data['professional_profile']), 0, 600);
            }

            // Inserisci candidato con campi V2
            $stmt = $database->prepare("
                INSERT INTO cv_candidati (
                    nome, cognome, email, telefono, indirizzo, citta, cap, provincia,
                    file_cv_originale, file_cv_path, professionalita, professional_profile,
                    score_totale, warnings_json, parser_schema_version, stato, data_inserimento
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'nuovo', NOW())
            ");

            if (!$stmt) {
                throw new Exception("Prepare failed for cv_candidati. " . json_encode($database->errorInfo() ?? []));
            }

            $exec = $stmt->execute([
                $personal['nome'] ?? null,
                $personal['cognome'] ?? null,
                $personal['email'] ?? null,
                $personal['telefono'] ?? null,
                $personal['indirizzo'] ?? null,
                $personal['citta'] ?? null,
                $personal['cap'] ?? null,
                $personal['provincia'] ?? null,
                $data['file_originale'] ?? $filename,
                $filepath,
                $data['profession'] ?? null,
                $professionalProfile,
                $enhancedScore,
                $warningsJson,
                $schemaVersion
            ]);

            if (!$exec) {
                $err = $stmt->errorInfo();
                throw new Exception("Execute failed cv_candidati: " . ($err[2] ?? 'Unknown'));
            }

            $candidatoId = $database->lastInsertId();

            // Istruzione
            if (!empty($data['education'])) {
                $stmtEdu = $database->prepare("
                    INSERT INTO cv_istruzione (candidato_id, tipo, titolo, istituto, data_inizio, data_fine, in_corso)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($data['education'] as $edu) {
                    // Filtra item senza titolo (warning già loggato in validateParsedData)
                    if (empty($edu['titolo'])) {
                        continue;
                    }

                    $stmtEdu->execute([
                        $candidatoId,
                        $edu['tipo'] ?? 'altro',
                        $edu['titolo'] ?? null,
                        $edu['istituto'] ?? null,
                        $edu['data_inizio'] ?? null,
                        $edu['data_fine'] ?? null,
                        (int) ($edu['in_corso'] ?? 0)
                    ]);
                }
            }

            // Esperienze
            if (!empty($data['experience'])) {
                $stmtExp = $database->prepare("
                    INSERT INTO cv_esperienze (candidato_id, azienda, posizione, data_inizio, data_fine, in_corso, descrizione)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($data['experience'] as $exp) {
                    // Filtra item senza azienda E posizione (warning già loggato in validateParsedData)
                    if (empty($exp['azienda']) && empty($exp['posizione'])) {
                        continue;
                    }

                    // Convert dates CONSERVATIVE: solo pattern chiari (YYYY-MM-DD già ok, YYYY, MM/YYYY)
                    $start = null;
                    $end = null;

                    $rawStart = isset($exp['data_inizio']) ? trim((string) $exp['data_inizio']) : '';
                    $rawEnd = isset($exp['data_fine']) ? trim((string) $exp['data_fine']) : '';

                    // Data inizio
                    if ($rawStart !== '') {
                        // Pattern 0: già normalizzato YYYY-MM-DD (lascia invariato)
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawStart)) {
                            $start = $rawStart;
                        }
                        // Pattern 1: MM/YYYY o MM-YYYY (es: 06/2020) -> primo giorno mese
                        elseif (preg_match('/\b(0[1-9]|1[0-2])[\/\-](19\d{2}|20\d{2})\b/', $rawStart, $m)) {
                            $start = $m[2] . '-' . $m[1] . '-01';
                        }
                        // Pattern 2: solo YYYY (es: 2020) -> 01/01
                        elseif (preg_match('/\b(19\d{2}|20\d{2})\b/', $rawStart, $m)) {
                            $start = $m[1] . '-01-01';
                        }
                        // Altrimenti: null
                    }

                    // Data fine (solo se non in corso)
                    if ($rawEnd !== '' && empty($exp['in_corso'])) {
                        // Pattern 0: già normalizzato YYYY-MM-DD
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEnd)) {
                            $end = $rawEnd;
                        }
                        // Pattern 1: MM/YYYY o MM-YYYY -> ultimo giorno mese
                        elseif (preg_match('/\b(0[1-9]|1[0-2])[\/\-](19\d{2}|20\d{2})\b/', $rawEnd, $m)) {
                            $month = (int) $m[1];
                            $year = (int) $m[2];
                            $lastDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
                            $end = $m[2] . '-' . $m[1] . '-' . str_pad((string) $lastDay, 2, '0', STR_PAD_LEFT);
                        }
                        // Pattern 2: solo YYYY -> 31/12
                        elseif (preg_match('/\b(19\d{2}|20\d{2})\b/', $rawEnd, $m)) {
                            $end = $m[1] . '-12-31';
                        }
                        // Altrimenti: null
                    }

                    $stmtExp->execute([
                        $candidatoId,
                        $exp['azienda'] ?? null,
                        $exp['posizione'] ?? null,
                        $start,
                        $end,
                        (int) ($exp['in_corso'] ?? 0),
                        $exp['descrizione'] ?? null
                    ]);
                }
            }

            // Competenze
            if (!empty($data['skills'])) {
                $stmtSkill = $database->prepare("
                    INSERT INTO cv_competenze (candidato_id, nome, categoria, livello)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($data['skills'] as $skill) {
                    if (empty($skill['nome'])) {
                        continue;
                    }
                    $stmtSkill->execute([
                        $candidatoId,
                        $skill['nome'],
                        $skill['categoria'] ?? 'altro',
                        $skill['livello'] ?? 'intermedio'
                    ]);
                }
            }

            // Lingue
            if (!empty($data['languages'])) {
                $stmtLang = $database->prepare("
                    INSERT INTO cv_lingue (candidato_id, lingua, livello, certificazione)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($data['languages'] as $lang) {
                    if (empty($lang['lingua']) || empty($lang['livello'])) {
                        continue;
                    }
                    $stmtLang->execute([
                        $candidatoId,
                        $lang['lingua'],
                        $lang['livello'],
                        $lang['certificazione'] ?? null
                    ]);
                }
            }

            // Certificazioni
            if (!empty($data['certifications'])) {
                $stmtCert = $database->prepare("
                    INSERT INTO cv_certificazioni (candidato_id, nome, data_rilascio)
                    VALUES (?, ?, ?)
                ");
                foreach ($data['certifications'] as $cert) {
                    // Filtra item senza nome (warning già loggato in validateParsedData)
                    if (empty($cert['nome'])) {
                        continue;
                    }

                    // NOTA: 'type' non è incluso nell'INSERT (ignorato se presente nel JSON)
                    $stmtCert->execute([
                        $candidatoId,
                        $cert['nome'],
                        $cert['data_rilascio'] ?? null
                    ]);
                }
            }

            // Progetti (V2) - salva in cv_progetti
            if (!empty($data['projects']) && is_array($data['projects'])) {
                $stmtProj = $database->prepare("
                    INSERT INTO cv_progetti (
                        candidato_id, nome, luogo, anno_inizio, anno_fine,
                        ruolo, importo_euro, committente, descrizione_breve, tags_json
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($data['projects'] as $proj) {
                    // Valida: almeno un campo significativo (non tutto vuoto)
                    $hasContent = !empty($proj['nome']) ||
                        !empty($proj['descrizione_breve']) ||
                        !empty($proj['importo_euro']) ||
                        !empty($proj['committente']);

                    if (!$hasContent) {
                        continue; // Skip progetti completamente vuoti
                    }

                    // Prepara tags_json (deve essere JSON valido o NULL)
                    $tagsJson = null;
                    if (!empty($proj['tags']) && is_array($proj['tags'])) {
                        $tagsJson = json_encode($proj['tags'], JSON_UNESCAPED_UNICODE);
                    }

                    // Tronca descrizione se troppo lunga
                    $descrizioneBreveTrunc = null;
                    if (!empty($proj['descrizione_breve'])) {
                        $descrizioneBreveTrunc = substr(trim($proj['descrizione_breve']), 0, 500);
                    }

                    $stmtProj->execute([
                        $candidatoId,
                        !empty($proj['nome']) ? substr($proj['nome'], 0, 255) : null,
                        !empty($proj['luogo']) ? substr($proj['luogo'], 0, 255) : null,
                        !empty($proj['anno_inizio']) && is_numeric($proj['anno_inizio']) ? (int) $proj['anno_inizio'] : null,
                        !empty($proj['anno_fine']) && is_numeric($proj['anno_fine']) ? (int) $proj['anno_fine'] : null,
                        !empty($proj['ruolo']) ? substr($proj['ruolo'], 0, 255) : null,
                        !empty($proj['importo_euro']) && is_numeric($proj['importo_euro']) ? (int) $proj['importo_euro'] : null,
                        !empty($proj['committente']) ? substr($proj['committente'], 0, 255) : null,
                        $descrizioneBreveTrunc,
                        $tagsJson
                    ]);
                }
            }

            if ($database->connection instanceof PDO) {
                $database->connection->commit();
            }
            return (int) $candidatoId;

        } catch (Exception $e) {
            if ($database->connection instanceof PDO) {
                $database->connection->rollBack();
            }
            error_log("CvService - DB Error: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    public static function getCandidatesList(array $input): array
    {
        global $database;

        $search = $input['search'] ?? '';
        $profession = $input['profession'] ?? '';
        $minScore = $input['min_score'] ?? 0;
        $status = $input['status'] ?? '';

        $sql = "SELECT * FROM cv_candidati_completa WHERE 1=1";
        $params = [];

        if ($search) {
            $sql .= " AND (CONCAT(nome, ' ', cognome) LIKE ? OR email LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if ($profession) {
            $sql .= " AND professionalita = ?";
            $params[] = $profession;
        }

        if ($minScore > 0) {
            $sql .= " AND score_totale >= ?";
            $params[] = $minScore;
        }

        if ($status) {
            $sql .= " AND stato = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY score_totale DESC, data_inserimento DESC";

        $stmt = $database->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode warnings_json for each candidate (safe fallback)
        foreach ($candidates as &$candidate) {
            $candidate['warnings'] = [];

            $warningsJson = $candidate['warnings_json'] ?? null;
            if (!is_string($warningsJson) || $warningsJson === '') {
                continue;
            }

            $decoded = json_decode($warningsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                continue;
            }

            // Normalizza: solo stringhe
            $candidate['warnings'] = array_values(array_filter($decoded, fn($w) => is_string($w) && $w !== ''));
        }
        unset($candidate);

        return ['success' => true, 'data' => $candidates];
    }

    public static function getCandidateDetail(int $id): array
    {
        global $database;

        if (!$id)
            return ['success' => false, 'error' => 'ID mancante'];

        $stmt = $database->prepare("SELECT * FROM cv_candidati WHERE id = ?");
        $stmt->execute([$id]);
        $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$candidate) {
            return ['success' => false, 'error' => 'Candidato non trovato'];
        }

        // Fetch related data
        $candidate['istruzione'] = $database->query("SELECT * FROM cv_istruzione WHERE candidato_id = ? ORDER BY data_inizio DESC", [$id], __FILE__)->fetchAll(PDO::FETCH_ASSOC);
        $candidate['esperienze'] = $database->query("SELECT * FROM cv_esperienze WHERE candidato_id = ? ORDER BY data_inizio DESC", [$id], __FILE__)->fetchAll(PDO::FETCH_ASSOC);
        $candidate['competenze'] = $database->query("SELECT * FROM cv_competenze WHERE candidato_id = ? ORDER BY categoria, nome", [$id], __FILE__)->fetchAll(PDO::FETCH_ASSOC);
        $candidate['lingue'] = $database->query("SELECT * FROM cv_lingue WHERE candidato_id = ? ORDER BY livello ASC", [$id], __FILE__)->fetchAll(PDO::FETCH_ASSOC);
        $candidate['certificazioni'] = $database->query("SELECT * FROM cv_certificazioni WHERE candidato_id = ? ORDER BY data_rilascio DESC", [$id], __FILE__)->fetchAll(PDO::FETCH_ASSOC);

        // Progetti (V2) - carica con decode tags_json
        $candidate['progetti'] = [];
        try {
            $progetti = $database->query(
                "SELECT * FROM cv_progetti WHERE candidato_id = ? ORDER BY anno_inizio DESC, id DESC",
                [$id],
                __FILE__
            )->fetchAll(PDO::FETCH_ASSOC);

            // Decode tags_json per ogni progetto
            foreach ($progetti as &$proj) {
                $proj['tags'] = [];
                if (!empty($proj['tags_json']) && is_string($proj['tags_json'])) {
                    $decoded = json_decode($proj['tags_json'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $proj['tags'] = $decoded;
                    }
                }
            }
            unset($proj);

            $candidate['progetti'] = $progetti;
        } catch (Exception $e) {
            // Tabella potrebbe non esistere ancora (backward compat)
            error_log("CvService - progetti query failed (table may not exist): " . $e->getMessage());
        }

        // Decode warnings_json (safe fallback)
        $candidate['warnings'] = [];

        $warningsJson = $candidate['warnings_json'] ?? null;
        if (is_string($warningsJson) && $warningsJson !== '') {
            $decoded = json_decode($warningsJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $candidate['warnings'] = array_values(array_filter($decoded, fn($w) => is_string($w) && $w !== ''));
            }
        }

        return ['success' => true, 'data' => $candidate];
    }

    public static function compareCandidates(string $ids): array
    {
        global $database;

        $idsArray = array_filter(array_map('intval', explode(',', $ids)));
        if (count($idsArray) < 2)
            return ['success' => false, 'error' => 'Minimo 2 candidati'];
        if (count($idsArray) > 5)
            return ['success' => false, 'error' => 'Massimo 5 candidati'];

        $placeholders = implode(',', array_fill(0, count($idsArray), '?'));
        $sql = "SELECT * FROM cv_candidati_completa WHERE id IN ($placeholders)";

        $stmt = $database->prepare($sql);
        $stmt->execute($idsArray);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidates as &$candidate) {
            $candidate['competenze_detail'] = $database->query("SELECT nome, categoria, livello FROM cv_competenze WHERE candidato_id = ?", [$candidate['id']], __FILE__)->fetchAll(PDO::FETCH_ASSOC);
            $candidate['lingue_detail'] = $database->query("SELECT lingua, livello FROM cv_lingue WHERE candidato_id = ?", [$candidate['id']], __FILE__)->fetchAll(PDO::FETCH_ASSOC);
        }

        return ['success' => true, 'data' => $candidates];
    }

    public static function getStatistics(): array
    {
        global $database;
        $stats = [];

        $stats['total'] = $database->query("SELECT COUNT(*) FROM cv_candidati", [], __FILE__)->fetchColumn();
        $stats['avg_score'] = $database->query("SELECT ROUND(AVG(score_totale), 2) FROM cv_candidati", [], __FILE__)->fetchColumn() ?: 0;
        $stats['new_candidates'] = $database->query("SELECT COUNT(*) FROM cv_candidati WHERE data_inserimento >= DATE_SUB(NOW(), INTERVAL 30 DAY)", [], __FILE__)->fetchColumn();
        $stats['hired'] = $database->query("SELECT COUNT(*) FROM cv_candidati WHERE stato = 'assunto'", [], __FILE__)->fetchColumn();

        // By Professionalita
        $byProf = $database->query("SELECT professionalita, COUNT(*) as count FROM cv_candidati GROUP BY professionalita ORDER BY count DESC", [], __FILE__)->fetchAll(PDO::FETCH_KEY_PAIR);
        $stats['by_profession'] = $byProf;

        // Score Distribution
        $byScore = $database->query("
            SELECT CASE 
                WHEN score_totale <= 20 THEN '0-20'
                WHEN score_totale <= 40 THEN '21-40'
                WHEN score_totale <= 60 THEN '41-60'
                WHEN score_totale <= 80 THEN '61-80'
                ELSE '81-100' END as range_score, COUNT(*) as count
            FROM cv_candidati GROUP BY range_score ORDER BY range_score
        ", [], __FILE__)->fetchAll(PDO::FETCH_KEY_PAIR);
        $stats['score_distribution'] = $byScore;

        $stats['by_status'] = $database->query("SELECT stato, COUNT(*) as count FROM cv_candidati GROUP BY stato", [], __FILE__)->fetchAll(PDO::FETCH_KEY_PAIR);

        return ['success' => true, 'data' => $stats];
    }

    public static function getProfessions(): array
    {
        global $database;
        $professions = $database->query("SELECT DISTINCT professionalita FROM cv_candidati WHERE professionalita IS NOT NULL ORDER BY professionalita", [], __FILE__)->fetchAll(PDO::FETCH_COLUMN);
        return ['success' => true, 'data' => $professions];
    }

    public static function updateCandidateStatus(int $id, string $status): array
    {
        global $database;
        $validStatuses = ['nuovo', 'in_valutazione', 'colloquio', 'assunto', 'scartato'];

        if (!$id || !in_array($status, $validStatuses)) {
            return ['success' => false, 'error' => 'Stato non valido'];
        }

        $stmt = $database->prepare("UPDATE cv_candidati SET stato = ? WHERE id = ?");
        $ok = $stmt->execute([$status, $id]);

        if ($ok) {
            self::logActivity($id, 'stato_modificato', "Nuovo stato: $status");
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => 'Errore aggiornamento DB'];
        }
    }

    private static function getPythonExecutable(): string
    {
        // 1. Cerca nel venv locale del progetto (priorità assoluta)
        $venvPython = __DIR__ . '/../.venv/Scripts/python.exe'; // Windows
        if (file_exists($venvPython)) {
            return $venvPython;
        }

        $venvPythonUnix = __DIR__ . '/../.venv/bin/python'; // Unix/Linux
        if (file_exists($venvPythonUnix)) {
            return $venvPythonUnix;
        }

        // 2. Check override config
        if (defined('PYTHON_EXECUTABLE_OVERRIDE')) {
            return PYTHON_EXECUTABLE_OVERRIDE;
        }

        // 3. System fallback
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Prova paths comuni su Windows XAMPP o user
            $commonPaths = [
                'py',
                'python',
                'C:\\Python39\\python.exe',
                'C:\\Python310\\python.exe',
                'C:\\Python311\\python.exe',
                'C:\\Python312\\python.exe',
                'C:\\Python313\\python.exe',
                // Path specificato nel vecchio config
                'C:\\Users\\Tesi1\\AppData\\Local\\Programs\\Python\\Python313\\python.exe'
            ];

            foreach ($commonPaths as $path) {
                if ($path === 'py' || $path === 'python')
                    return $path;
                if (file_exists($path))
                    return $path;
            }
            return 'python';
        } else {
            // Linux defaults
            return 'python3';
        }
    }

    private static function logActivity(int $candidatoId, string $azione, ?string $dettagli = null): void
    {
        global $database;

        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            $utente = 'sistema';
            if (isset($_SESSION['user'])) {
                if (is_array($_SESSION['user'])) {
                    // Prova a estrarre un identificativo stabile (senza inventare campi)
                    if (isset($_SESSION['user']['username']) && is_string($_SESSION['user']['username']) && trim($_SESSION['user']['username']) !== '') {
                        $utente = trim($_SESSION['user']['username']);
                    } elseif (isset($_SESSION['user']['email']) && is_string($_SESSION['user']['email']) && trim($_SESSION['user']['email']) !== '') {
                        $utente = trim($_SESSION['user']['email']);
                    } elseif (isset($_SESSION['user']['id']) && is_scalar($_SESSION['user']['id'])) {
                        $utente = 'user#' . (string) $_SESSION['user']['id'];
                    } else {
                        // Fallback conservativo: non serializziamo l'array nel DB
                        $utente = 'user';
                    }
                } elseif (is_string($_SESSION['user']) && trim($_SESSION['user']) !== '') {
                    $utente = trim($_SESSION['user']);
                } elseif (is_scalar($_SESSION['user'])) {
                    $utente = (string) $_SESSION['user'];
                }
            }

            $stmt = $database->prepare("
                INSERT INTO cv_activity_log (candidato_id, azione, dettagli, utente, ip_address)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$candidatoId, $azione, $dettagli, $utente, $ip]);
        } catch (Exception $e) {
            error_log("Cannot log activity: " . $e->getMessage());
        }
    }

}
