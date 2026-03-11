<?php
namespace Services;

/**
 * Servizio per la gestione dei requisiti delle gare
 */
class RequisitiService
{
    /**
     * Decode sicuro del JSON comprovante
     *
     * @param string|null $json
     * @return array
     */
    private static function decodeComprovanteJson(?string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Ritorna valore stringa normalizzato per ricerche
     *
     * @param mixed $value
     * @return string
     */
    private static function normalizeSearchValue($value): string
    {
        $str = is_string($value) ? $value : '';
        return mb_strtolower(trim($str));
    }

    /**
     * Calcola macro_categoria in base alla tipologia_prestazione
     * Mappatura centralizzata per garantire coerenza
     * 
     * @param string $tipologiaPrestazione Tipologia della prestazione
     * @return string Macro categoria calcolata
     */
    private static function calcolaMacroCategoria(string $tipologiaPrestazione): string
    {
        $tipologia = mb_strtoupper(trim($tipologiaPrestazione));
        if (empty($tipologia)) {
            return 'PROGETTAZIONE';
        }

        // Pattern per PROGETTAZIONE SPECIALISTICA (deve essere verificato prima di PROGETTAZIONE generica)
        $patternSpecialistica = '/\b(SPECIALISTIC[AO]|IMPIANTI|STRUTTURALE|ELETTRIC[AO]|MECCANIC[AO]|TERMOTECNIC[AO]|IDRAULIC[AO]|ANTINCENDIO|ACUSTIC[AO]|ILLUMINAZIONE)\b/i';
        $patternProgettazione = '/\b(PROGETTAZIONE|PROGETTISTICA|PROGETTO|DL|DEFINITIVA|ESECUTIVA|PRELIMINARE|FATTIBILIT[AÃ€])\b/i';
        $patternEsecuzione = '/\b(ESECUZIONE|CANTIERE|LAVORI|REALIZZAZIONE|COSTRUZIONE|INSTALLAZIONE)\b/i';
        $patternSicurezza = '/\b(SICUREZZA|COORDINAMENTO|CSP|CSE|POS|PSC|DVR|ANTINFORTUNISTIC[AO]|COORDINATORE)\b/i';

        if (preg_match($patternProgettazione, $tipologia)) {
            if (preg_match($patternSpecialistica, $tipologia)) {
                return 'PROGETTAZIONE SPECIALISTICA';
            }
            return 'PROGETTAZIONE';
        }

        if (preg_match($patternEsecuzione, $tipologia)) {
            return 'ESECUZIONE DEI LAVORI';
        }

        if (preg_match($patternSicurezza, $tipologia)) {
            return 'SICUREZZA';
        }

        return 'PROGETTAZIONE';
    }
    /**
     * Ottiene i dati del fatturato annuale
     * 
     * @return array { success: bool, data: array }
     */
    public static function getFatturatoAnnuale(): array
    {
        global $database;

        try {
            $stmt = $database->query(
                "SELECT anno, fatturato FROM gar_fatturato_annuale ORDER BY anno DESC",
                [],
                __FILE__
            );

            if (!$stmt) {
                return ['success' => false, 'message' => 'Errore nella query'];
            }

            $data = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $data[] = [
                    'anno' => (int) $row['anno'],
                    'fatturato' => (float) $row['fatturato'],
                    'fatturato_formatted' => number_format((float) $row['fatturato'], 2, ',', '.') . ' €'
                ];
            }

            return [
                'success' => true,
                'data' => $data
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Salva o aggiorna un anno di fatturato
     * 
     * @param array $params { anno: int, fatturato: float }
     * @return array { success: bool, message: string }
     */
    public static function saveFatturato(array $params): array
    {
        global $database;

        $anno = isset($params['anno']) ? (int) $params['anno'] : 0;
        $fatturato = isset($params['fatturato']) ? (float) $params['fatturato'] : 0.0;

        if ($anno < 1900 || $anno > 2100) {
            return ['success' => false, 'message' => 'Anno non valido'];
        }

        try {
            // Verifica se l'anno esiste già
            $exists = $database->query(
                "SELECT anno FROM gar_fatturato_annuale WHERE anno = ?",
                [$anno],
                __FILE__
            )->fetch();

            if ($exists) {
                // Update
                $database->query(
                    "UPDATE gar_fatturato_annuale SET fatturato = ? WHERE anno = ?",
                    [$fatturato, $anno],
                    __FILE__
                );
            } else {
                // Insert
                $database->query(
                    "INSERT INTO gar_fatturato_annuale (anno, fatturato) VALUES (?, ?)",
                    [$anno, $fatturato],
                    __FILE__
                );
            }

            return ['success' => true, 'message' => 'Fatturato salvato con successo'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore nel salvataggio: ' . $e->getMessage()];
        }
    }

    /**
     * Elimina un anno di fatturato
     * 
     * @param array $params { anno: int }
     * @return array { success: bool, message: string }
     */
    public static function deleteFatturato(array $params): array
    {
        global $database;

        $anno = isset($params['anno']) ? (int) $params['anno'] : 0;

        if ($anno <= 0) {
            return ['success' => false, 'message' => 'Anno non valido'];
        }

        try {
            $database->query(
                "DELETE FROM gar_fatturato_annuale WHERE anno = ?",
                [$anno],
                __FILE__
            );

            return ['success' => true, 'message' => 'Fatturato eliminato con successo'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore nell\'eliminazione: ' . $e->getMessage()];
        }
    }

    /**
     * Ottiene i dati dei comprovanti dalla tabella gar_prestazioni_normative
     * DEPRECATO: Usare listComprovantiProgetti per la nuova UI
     * 
     * @return array { success: bool, data: array }
     */
    public static function getComprovanti(): array
    {
        global $database;

        try {
            $stmt = $database->query(
                "SELECT cod, categoria, prestazione FROM gar_prestazioni_normative ORDER BY cod ASC",
                [],
                __FILE__
            );

            if (!$stmt) {
                return ['success' => false, 'message' => 'Errore nella query'];
            }

            $data = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $data[] = [
                    'cod' => $row['cod'] ?? '',
                    'categoria' => $row['categoria'] ?? '',
                    'prestazione' => $row['prestazione'] ?? ''
                ];
            }

            return [
                'success' => true,
                'data' => $data
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Lista progetti comprovanti con filtri e paginazione
     * 
     * @param array $params Parametri di ricerca e paginazione
     * @return array { success: bool, data: { items: array, pagination: array }, errors: array }
     */
    public static function listComprovantiProgetti(array $params = []): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Sanificazione parametri
            $search = isset($params['search']) ? trim((string) $params['search']) : '';
            $codiceCommessa = isset($params['codice_commessa']) ? trim((string) $params['codice_commessa']) : '';
            $committente = isset($params['committente']) ? trim((string) $params['committente']) : '';
            $dataInizio = isset($params['data_inizio']) ? trim((string) $params['data_inizio']) : '';
            $dataFine = isset($params['data_fine']) ? trim((string) $params['data_fine']) : '';
            $page = isset($params['page']) ? max(1, (int) $params['page']) : 1;
            $perPage = isset($params['per_page']) ? max(1, min(100, (int) $params['per_page'])) : 50;
            $offset = ($page - 1) * $perPage;

            // Query base: solo comprovanti effettivamente create (con JSON compilato)
            $sql = "SELECT
                        p.id,
                        p.codice_commessa,
                        p.committente,
                        p.comprovante_json,
                        p.comprovante_pdf,
                        p.created_at,
                        COALESCE(COUNT(DISTINCT s.id), 0) as servizi_count
                    FROM gar_comprovanti_progetti p
                    LEFT JOIN gar_comprovanti_servizi s ON s.progetto_id = p.id
                    WHERE p.comprovante_json IS NOT NULL
                      AND p.comprovante_json != ''
                    GROUP BY p.id
                    ORDER BY p.created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            $items = [];
            $searchNorm = self::normalizeSearchValue($search);
            $codiceNorm = self::normalizeSearchValue($codiceCommessa);
            $committenteNorm = self::normalizeSearchValue($committente);

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $json = self::decodeComprovanteJson($row['comprovante_json'] ?? null);

                // Priorità al JSON (Source of Truth) come da piano
                $committenteValue = $json['committente'] ?? ($row['committente'] ?? '');

                $titoloProgetto = $json['titolo_progetto'] ?? ($row['descrizione_lavoro'] ?? '');
                $oggettoContratto = $json['oggetto_contratto'] ?? '';
                $dataInizioPrestazione = $json['data_inizio_prestazione'] ?? null;
                $dataFinePrestazione = $json['data_fine_prestazione'] ?? null;

                $importoTotale = $json['importo_lavori_totale'] ?? null;
                if ($importoTotale === null || $importoTotale === '') {
                    $importoTotale = $json['importo_prestazioni'] ?? null;
                }

                // Filtri lato PHP (per compatibilitÃ  con dati JSON)
                if ($codiceNorm && mb_strpos(self::normalizeSearchValue($row['codice_commessa'] ?? ''), $codiceNorm) === false) {
                    continue;
                }
                if ($committenteNorm && mb_strpos(self::normalizeSearchValue($committenteValue), $committenteNorm) === false) {
                    continue;
                }
                if ($searchNorm) {
                    $haystack = self::normalizeSearchValue(
                        ($row['codice_commessa'] ?? '') . ' ' .
                        $committenteValue . ' ' .
                        $titoloProgetto . ' ' .
                        $oggettoContratto
                    );
                    if (mb_strpos($haystack, $searchNorm) === false) {
                        continue;
                    }
                }
                if ($dataInizio && $dataFinePrestazione) {
                    if ($dataFinePrestazione < $dataInizio) {
                        continue;
                    }
                }
                if ($dataFine && $dataInizioPrestazione) {
                    if ($dataInizioPrestazione > $dataFine) {
                        continue;
                    }
                }

                $hasServizi = (int) $row['servizi_count'] > 0;
                $hasCampiChiave = !empty($row['codice_commessa']) && !empty($committenteValue);

                $qualita = 'rosso';
                if ($hasServizi && $hasCampiChiave) {
                    $qualita = 'verde';
                } elseif ($hasCampiChiave) {
                    $qualita = 'giallo';
                }

                $items[] = [
                    'id' => (int) $row['id'],
                    'codice_commessa' => $row['codice_commessa'] ?? '',
                    'committente' => $committenteValue ?? '',
                    'titolo_progetto' => $titoloProgetto,
                    'oggetto_contratto' => $oggettoContratto,
                    'importo_complessivo_lavori' => isset($importoTotale) && $importoTotale !== '' ? (float) $importoTotale : null,
                    'data_inizio_servizi' => $dataInizioPrestazione ?: null,
                    'data_fine_servizi' => $dataFinePrestazione ?: null,
                    'suddivisione_count' => (int) $row['servizi_count'],
                    'categorie_count' => count($json['categorie_opera'] ?? []),
                    'partecipanti_count' => count($json['partecipanti'] ?? []),
                    'incarichi_count' => count($json['incarichi'] ?? []),
                    'qualita' => $qualita,
                    'has_pdf' => !empty($json['comprovante_pdf']) || !empty($row['comprovante_pdf'])
                ];
            }

            $totalRows = count($items);
            $items = array_slice($items, $offset, $perPage);

            return [
                'success' => true,
                'data' => [
                    'items' => $items,
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $totalRows,
                        'total_pages' => (int) ceil($totalRows / $perPage)
                    ]
                ],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Ottiene il dettaglio completo di un progetto comprovante
     * 
     * @param int $progettoId ID del progetto
     * @return array { success: bool, data: { progetto: array, servizi: array, status: array }, errors: array }
     */
    public static function getComprovanteDettaglio(int $progettoId): array
    {
        try {
            // Usa il servizio canonico per recuperare i dati normalizzati (UNICO PUNTO DI VERITÀ)
            $progData = \Services\CommesseService::getComprovanteFullData($progettoId);

            if (!$progData) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Progetto non trovato']
                ];
            }

            // Calcolo qualità dati
            $hasServizi = count($progData['suddivisione_servizio']) > 0;
            $hasCampiChiave = !empty($progData['codice_commessa']) && !empty($progData['committente']);

            $qualita = 'rosso';
            if ($hasServizi && $hasCampiChiave) {
                $qualita = 'verde';
            } elseif ($hasCampiChiave) {
                $qualita = 'giallo';
            }

            return [
                'success' => true,
                'data' => [
                    'progetto' => $progData,
                    'servizi' => $progData['suddivisione_servizio'],
                    'status' => [
                        'qualita' => $qualita,
                        'servizi_count' => count($progData['suddivisione_servizio']),
                        'incarichi_count' => count($progData['incarichi'] ?? [])
                    ]
                ],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Crea un nuovo progetto comprovante
     * 
     * @param array $data Dati del progetto
     * @return array { success: bool, data: array, errors: array }
     */
    public static function createComprovanteProgetto(array $data): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Validazione
            $errors = [];
            if (empty($data['codice_commessa'])) {
                $errors[] = 'Codice commessa obbligatorio';
            }
            if (empty($data['committente'])) {
                $errors[] = 'Committente obbligatorio';
            }
            if (empty($data['descrizione_lavoro'])) {
                $errors[] = 'Descrizione lavoro obbligatoria';
            }

            if (!empty($errors)) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => $errors
                ];
            }

            // Sanificazione
            $codiceCommessa = trim((string) $data['codice_commessa']);
            $numeroComprovante = isset($data['numero_comprovante']) && $data['numero_comprovante'] !== '' ? (int) $data['numero_comprovante'] : null;
            $committente = trim((string) $data['committente']);
            $indirizzo = isset($data['indirizzo']) ? trim((string) $data['indirizzo']) : null;
            $descrizioneLavoro = trim((string) $data['descrizione_lavoro']);
            $importoComplessivo = isset($data['importo_complessivo_lavori']) && $data['importo_complessivo_lavori'] !== '' ? (float) $data['importo_complessivo_lavori'] : null;
            $importoProgettati = isset($data['importo_lavori_progettati']) && $data['importo_lavori_progettati'] !== '' ? (float) $data['importo_lavori_progettati'] : null;
            $importoOnorario = isset($data['importo_onorario']) && $data['importo_onorario'] !== '' ? (float) $data['importo_onorario'] : null;
            $dataInizio = isset($data['data_inizio_servizi']) && $data['data_inizio_servizi'] !== '' ? trim((string) $data['data_inizio_servizi']) : null;
            $dataFine = isset($data['data_fine_servizi']) && $data['data_fine_servizi'] !== '' ? trim((string) $data['data_fine_servizi']) : null;
            $soggettoEsecutore = isset($data['soggetto_esecutore']) ? trim((string) $data['soggetto_esecutore']) : null;
            $comprovantePdf = isset($data['comprovante_pdf']) ? trim((string) $data['comprovante_pdf']) : null;

            // Verifica unicitÃ  codice_commessa
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_progetti WHERE codice_commessa = :codice");
            $checkStmt->execute([':codice' => $codiceCommessa]);
            if ($checkStmt->fetch()) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Codice commessa giÃ  esistente']
                ];
            }

            $sql = "INSERT INTO gar_comprovanti_progetti 
                    (codice_commessa, numero_comprovante, committente, indirizzo, descrizione_lavoro,
                     importo_complessivo_lavori, importo_lavori_progettati, importo_onorario,
                     data_inizio_servizi, data_fine_servizi, soggetto_esecutore, comprovante_pdf)
                    VALUES 
                    (:codice_commessa, :numero_comprovante, :committente, :indirizzo, :descrizione_lavoro,
                     :importo_complessivo_lavori, :importo_lavori_progettati, :importo_onorario,
                     :data_inizio_servizi, :data_fine_servizi, :soggetto_esecutore, :comprovante_pdf)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':codice_commessa' => $codiceCommessa,
                ':numero_comprovante' => $numeroComprovante,
                ':committente' => $committente,
                ':indirizzo' => $indirizzo,
                ':descrizione_lavoro' => $descrizioneLavoro,
                ':importo_complessivo_lavori' => $importoComplessivo,
                ':importo_lavori_progettati' => $importoProgettati,
                ':importo_onorario' => $importoOnorario,
                ':data_inizio_servizi' => $dataInizio,
                ':data_fine_servizi' => $dataFine,
                ':soggetto_esecutore' => $soggettoEsecutore,
                ':comprovante_pdf' => $comprovantePdf
            ]);

            $newId = (int) $pdo->lastInsertId();

            return [
                'success' => true,
                'data' => ['id' => $newId],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Aggiorna un progetto comprovante esistente
     * 
     * @param int $progettoId ID del progetto
     * @param array $data Dati da aggiornare
     * @return array { success: bool, data: array, errors: array }
     */
    public static function updateComprovanteProgetto(int $progettoId, array $data): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Verifica esistenza
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_progetti WHERE id = :id");
            $checkStmt->execute([':id' => $progettoId]);
            if (!$checkStmt->fetch()) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Progetto non trovato']
                ];
            }

            // Validazione campi obbligatori se presenti
            $errors = [];
            if (isset($data['codice_commessa']) && empty(trim((string) $data['codice_commessa']))) {
                $errors[] = 'Codice commessa obbligatorio';
            }
            if (isset($data['committente']) && empty(trim((string) $data['committente']))) {
                $errors[] = 'Committente obbligatorio';
            }
            if (isset($data['descrizione_lavoro']) && empty(trim((string) $data['descrizione_lavoro']))) {
                $errors[] = 'Descrizione lavoro obbligatoria';
            }

            if (!empty($errors)) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => $errors
                ];
            }

            // Costruzione SET clause dinamico
            $setParts = [];
            $bindParams = [':id' => $progettoId];

            $fields = [
                'codice_commessa',
                'numero_comprovante',
                'committente',
                'indirizzo',
                'descrizione_lavoro',
                'importo_complessivo_lavori',
                'importo_lavori_progettati',
                'importo_onorario',
                'data_inizio_servizi',
                'data_fine_servizi',
                'soggetto_esecutore',
                'comprovante_pdf'
            ];

            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    if (in_array($field, ['numero_comprovante'])) {
                        $setParts[] = "$field = :$field";
                        $bindParams[":$field"] = $data[$field] !== '' ? (int) $data[$field] : null;
                    } elseif (in_array($field, ['importo_complessivo_lavori', 'importo_lavori_progettati', 'importo_onorario'])) {
                        $setParts[] = "$field = :$field";
                        $bindParams[":$field"] = $data[$field] !== '' ? (float) $data[$field] : null;
                    } else {
                        $setParts[] = "$field = :$field";
                        $bindParams[":$field"] = $data[$field] !== '' ? trim((string) $data[$field]) : null;
                    }
                }
            }

            if (empty($setParts)) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Nessun campo da aggiornare']
                ];
            }

            // Verifica unicitÃ  codice_commessa se viene modificato
            if (isset($data['codice_commessa'])) {
                $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_progetti WHERE codice_commessa = :codice AND id != :id");
                $checkStmt->execute([':codice' => trim((string) $data['codice_commessa']), ':id' => $progettoId]);
                if ($checkStmt->fetch()) {
                    return [
                        'success' => false,
                        'data' => null,
                        'errors' => ['Codice commessa giÃ  esistente']
                    ];
                }
            }

            $sql = "UPDATE gar_comprovanti_progetti SET " . implode(', ', $setParts) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindParams);

            return [
                'success' => true,
                'data' => ['id' => $progettoId],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Elimina un progetto comprovante
     * 
     * @param int $progettoId ID del progetto
     * @return array { success: bool, data: array, errors: array }
     */
    public static function deleteComprovanteProgetto(int $progettoId): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Verifica esistenza
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_progetti WHERE id = :id");
            $checkStmt->execute([':id' => $progettoId]);
            if (!$checkStmt->fetch()) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Progetto non trovato']
                ];
            }

            // DELETE CASCADE gestirÃ  servizi e prestazioni
            $stmt = $pdo->prepare("DELETE FROM gar_comprovanti_progetti WHERE id = :id");
            $stmt->execute([':id' => $progettoId]);

            return [
                'success' => true,
                'data' => ['id' => $progettoId],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Upload PDF comprovante e associazione al progetto
     */
    public static function uploadComprovantePdf(): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            $progettoId = isset($_POST['progetto_id']) ? (int) $_POST['progetto_id'] : 0;
            if ($progettoId <= 0) {
                return ['success' => false, 'errors' => ['progetto_id non valido']];
            }

            // Verifica esistenza progetto
            $checkStmt = $pdo->prepare("SELECT id, codice_commessa FROM gar_comprovanti_progetti WHERE id = :id");
            $checkStmt->execute([':id' => $progettoId]);
            $progetto = $checkStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$progetto) {
                return ['success' => false, 'errors' => ['Progetto non trovato']];
            }

            // Validazione file
            if (!isset($_FILES['comprovante_pdf']) || $_FILES['comprovante_pdf']['error'] !== UPLOAD_ERR_OK) {
                $errorCode = $_FILES['comprovante_pdf']['error'] ?? 4; // 4 = UPLOAD_ERR_NO_FILE
                return ['success' => false, 'errors' => ['Nessun file caricato o errore upload (codice: ' . $errorCode . ')']];
            }

            $file = $_FILES['comprovante_pdf'];
            $maxSize = 20 * 1024 * 1024; // 20MB
            if ($file['size'] > $maxSize) {
                return ['success' => false, 'errors' => ['File troppo grande (max 20MB)']];
            }

            // Verifica MIME type
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            if ($mimeType !== 'application/pdf') {
                return ['success' => false, 'errors' => ['Formato non valido: è ammesso solo PDF']];
            }

            // Verifica estensione
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                return ['success' => false, 'errors' => ['Estensione non valida: è ammesso solo .pdf']];
            }

            // Directory di destinazione
            // __DIR__ è /path/to/intranet_dev/services
            $baseDir = dirname(__DIR__);
            $codice = preg_replace('/[^A-Za-z0-9_\-]/', '', $progetto['codice_commessa']);

            // Definisci cartelle
            $relDir = '/uploads/comprovanti/' . $codice . '/';
            $uploadDir = $baseDir . $relDir;

            // DEBUG: Log paths to verify execution of new code
            error_log("uploadComprovantePdf: BaseDir=$baseDir, UploadDir=$uploadDir");

            // Crea cartella se non esiste (con gestione errori)
            if (!is_dir($uploadDir)) {
                // @ per sopprimere warning (che romperebbero il JSON response)
                if (!@mkdir($uploadDir, 0755, true)) {
                    $err = error_get_last();
                    return ['success' => false, 'errors' => ['Errore creazione cartella: ' . ($err['message'] ?? 'Permessi negati')]];
                }
            }

            // Verifica scrivibilità
            if (!is_writable($uploadDir)) {
                return ['success' => false, 'errors' => ['Cartella di destinazione non scrivibile']];
            }

            // Genera nome file univoco
            $safeName = preg_replace('/[^A-Za-z0-9_\.\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
            $filename = uniqid() . '_' . $safeName . '.pdf';
            $destPath = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                return ['success' => false, 'errors' => ['Errore nel salvataggio del file (move_uploaded_file failed)']];
            }

            // Path relativo per il DB (senza document root o path assoluti)
            // Nota: in DB salviamo usually "uploads/comprovanti/..."
            $relativePath = 'uploads/comprovanti/' . $codice . '/' . $filename;

            // Aggiorna campo comprovante_pdf
            $updateStmt = $pdo->prepare("UPDATE gar_comprovanti_progetti SET comprovante_pdf = :pdf WHERE id = :id");
            $updateStmt->execute([':pdf' => $relativePath, ':id' => $progettoId]);

            // Aggiorna anche il JSON se presente (sync)
            $jsonStmt = $pdo->prepare("SELECT comprovante_json FROM gar_comprovanti_progetti WHERE id = :id");
            $jsonStmt->execute([':id' => $progettoId]);
            $jsonRow = $jsonStmt->fetch(\PDO::FETCH_ASSOC);

            if (!empty($jsonRow['comprovante_json'])) {
                $jsonData = json_decode($jsonRow['comprovante_json'], true);
                if (is_array($jsonData)) {
                    $jsonData['comprovante_pdf'] = $relativePath; // Aggiorna path nel JSON
                    $updateJsonStmt = $pdo->prepare("UPDATE gar_comprovanti_progetti SET comprovante_json = :json WHERE id = :id");
                    $updateJsonStmt->execute([
                        ':json' => json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                        ':id' => $progettoId
                    ]);
                }
            }

            return [
                'success' => true,
                'data' => [
                    'path' => $relativePath,
                    'filename' => $file['name'],
                    'full_url' => $relativePath // Frontend might append base url
                ],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'errors' => ['Errore imprevisto: ' . $e->getMessage()]];
        }
    }

    /**
     * Crea un nuovo servizio collegato a un progetto
     *
     * @param array $data Dati del servizio
     * @return array { success: bool, data: array, errors: array }
     */
    public static function createComprovanteServizio(array $data): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Validazione
            $errors = [];
            if (empty($data['progetto_id']) || (int) $data['progetto_id'] <= 0) {
                $errors[] = 'Progetto ID obbligatorio';
            }
            if (empty($data['codice_servizio'])) {
                $errors[] = 'Codice servizio obbligatorio';
            }
            if (empty($data['categoria_servizio'])) {
                $errors[] = 'Categoria servizio obbligatoria';
            }

            if (!empty($errors)) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => $errors
                ];
            }

            // Verifica esistenza progetto
            $progettoId = (int) $data['progetto_id'];
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_progetti WHERE id = :id");
            $checkStmt->execute([':id' => $progettoId]);
            if (!$checkStmt->fetch()) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Progetto non trovato']
                ];
            }

            $codiceServizio = trim((string) $data['codice_servizio']);
            $categoriaServizio = trim((string) $data['categoria_servizio']);
            $importo = isset($data['importo']) && $data['importo'] !== '' ? (float) $data['importo'] : null;

            $sql = "INSERT INTO gar_comprovanti_servizi (progetto_id, codice_servizio, categoria_servizio, importo)
                    VALUES (:progetto_id, :codice_servizio, :categoria_servizio, :importo)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':progetto_id' => $progettoId,
                ':codice_servizio' => $codiceServizio,
                ':categoria_servizio' => $categoriaServizio,
                ':importo' => $importo
            ]);

            $newId = (int) $pdo->lastInsertId();

            return [
                'success' => true,
                'data' => ['id' => $newId],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Aggiorna un servizio esistente
     * 
     * @param int $servizioId ID del servizio
     * @param array $data Dati da aggiornare
     * @return array { success: bool, data: array, errors: array }
     */
    public static function updateComprovanteServizio(int $servizioId, array $data): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Verifica esistenza
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_servizi WHERE id = :id");
            $checkStmt->execute([':id' => $servizioId]);
            if (!$checkStmt->fetch()) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Servizio non trovato']
                ];
            }

            // Costruzione SET clause dinamico
            $setParts = [];
            $bindParams = [':id' => $servizioId];

            if (isset($data['codice_servizio'])) {
                $setParts[] = "codice_servizio = :codice_servizio";
                $bindParams[':codice_servizio'] = trim((string) $data['codice_servizio']);
            }

            if (isset($data['categoria_servizio'])) {
                $setParts[] = "categoria_servizio = :categoria_servizio";
                $bindParams[':categoria_servizio'] = trim((string) $data['categoria_servizio']);
            }

            if (isset($data['importo'])) {
                $setParts[] = "importo = :importo";
                $bindParams[':importo'] = $data['importo'] !== '' ? (float) $data['importo'] : null;
            }

            if (empty($setParts)) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Nessun campo da aggiornare']
                ];
            }

            $sql = "UPDATE gar_comprovanti_servizi SET " . implode(', ', $setParts) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindParams);

            return [
                'success' => true,
                'data' => ['id' => $servizioId],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Elimina un servizio
     * 
     * @param int $servizioId ID del servizio
     * @return array { success: bool, data: array, errors: array }
     */
    public static function deleteComprovanteServizio(int $servizioId): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Verifica esistenza
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_servizi WHERE id = :id");
            $checkStmt->execute([':id' => $servizioId]);
            if (!$checkStmt->fetch()) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Servizio non trovato']
                ];
            }

            $stmt = $pdo->prepare("DELETE FROM gar_comprovanti_servizi WHERE id = :id");
            $stmt->execute([':id' => $servizioId]);

            return [
                'success' => true,
                'data' => ['id' => $servizioId],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Ottiene l'elenco delle macro categorie disponibili dalla tabella gar_comprovanti_prestazioni_catalogo
     * 
     * @return array { success: bool, data: array, errors: array }
     */
    public static function getMacroCategorieComprovanti(): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            $stmt = $pdo->query(
                "SELECT DISTINCT macro_categoria 
                 FROM gar_comprovanti_prestazioni_catalogo 
                 WHERE macro_categoria IS NOT NULL AND macro_categoria != ''
                 ORDER BY macro_categoria ASC"
            );

            $data = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $data[] = $row['macro_categoria'];
            }

            return [
                'success' => true,
                'data' => $data,
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Ottiene l'elenco delle tipologie prestazione per una macro_categoria specifica
     * 
     * @param string $macroCategoria Macro categoria per filtrare le tipologie
     * @return array { success: bool, data: array, errors: array }
     */
    public static function getTipologiePrestazioneByMacroCategoria(string $macroCategoria): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Sanificazione input
            $macroCategoria = trim((string) $macroCategoria);
            if (empty($macroCategoria)) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['macro_categoria non valida']
                ];
            }

            $stmt = $pdo->prepare(
                "SELECT DISTINCT prestazione 
                 FROM gar_comprovanti_prestazioni_catalogo 
                 WHERE macro_categoria = :macro_categoria 
                   AND prestazione IS NOT NULL 
                   AND prestazione != ''
                 ORDER BY prestazione ASC"
            );

            $stmt->execute([':macro_categoria' => $macroCategoria]);

            $data = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $data[] = $row['prestazione'];
            }

            return [
                'success' => true,
                'data' => $data,
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Crea una nuova prestazione collegata a un progetto
     * 
     * @param array $data Dati della prestazione
     * @return array { success: bool, data: array, errors: array }
     */
    public static function createComprovantePrestazione(array $data): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Validazione
            $errors = [];
            if (empty($data['progetto_id']) || (int) $data['progetto_id'] <= 0) {
                $errors[] = 'Progetto ID obbligatorio';
            }
            if (empty($data['macro_categoria'])) {
                $errors[] = 'Macro categoria obbligatoria';
            }
            if (empty($data['tipologia_prestazione'])) {
                $errors[] = 'Tipologia prestazione obbligatoria';
            }

            if (!empty($errors)) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => $errors
                ];
            }

            // Verifica esistenza progetto
            $progettoId = (int) $data['progetto_id'];
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_progetti WHERE id = :id");
            $checkStmt->execute([':id' => $progettoId]);
            if (!$checkStmt->fetch()) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Progetto non trovato']
                ];
            }

            $macroCategoria = trim((string) $data['macro_categoria']);
            $tipologiaPrestazione = trim((string) $data['tipologia_prestazione']);
            $flagEseguita = isset($data['flag_eseguita']) ? ((int) $data['flag_eseguita'] === 1 ? 1 : 0) : 0;
            $note = isset($data['note']) ? trim((string) $data['note']) : null;

            $sql = "INSERT INTO gar_comprovanti_prestazioni (progetto_id, tipologia_prestazione, flag_eseguita, note, macro_categoria)
                    VALUES (:progetto_id, :tipologia_prestazione, :flag_eseguita, :note, :macro_categoria)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':progetto_id' => $progettoId,
                ':tipologia_prestazione' => $tipologiaPrestazione,
                ':flag_eseguita' => $flagEseguita,
                ':note' => $note,
                ':macro_categoria' => $macroCategoria
            ]);

            $newId = (int) $pdo->lastInsertId();

            return [
                'success' => true,
                'data' => ['id' => $newId],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Aggiorna una prestazione esistente
     * 
     * @param int $prestazioneId ID della prestazione
     * @param array $data Dati da aggiornare
     * @return array { success: bool, data: array, errors: array }
     */
    public static function updateComprovantePrestazione(int $prestazioneId, array $data): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Verifica esistenza
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_prestazioni WHERE id = :id");
            $checkStmt->execute([':id' => $prestazioneId]);
            if (!$checkStmt->fetch()) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Prestazione non trovata']
                ];
            }

            // Costruzione SET clause dinamico
            $setParts = [];
            $bindParams = [':id' => $prestazioneId];

            if (isset($data['tipologia_prestazione'])) {
                $tipologiaPrestazione = trim((string) $data['tipologia_prestazione']);
                $setParts[] = "tipologia_prestazione = :tipologia_prestazione";
                $bindParams[':tipologia_prestazione'] = $tipologiaPrestazione;

                // Ricalcola macro_categoria se tipologia_prestazione viene modificata
                $macroCategoria = self::calcolaMacroCategoria($tipologiaPrestazione);
                $setParts[] = "macro_categoria = :macro_categoria";
                $bindParams[':macro_categoria'] = $macroCategoria;
            }

            if (isset($data['flag_eseguita'])) {
                $setParts[] = "flag_eseguita = :flag_eseguita";
                $bindParams[':flag_eseguita'] = ((int) $data['flag_eseguita'] === 1 ? 1 : 0);
            }

            if (isset($data['note'])) {
                $setParts[] = "note = :note";
                $bindParams[':note'] = $data['note'] !== '' ? trim((string) $data['note']) : null;
            }

            if (empty($setParts)) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Nessun campo da aggiornare']
                ];
            }

            $sql = "UPDATE gar_comprovanti_prestazioni SET " . implode(', ', $setParts) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindParams);

            return [
                'success' => true,
                'data' => ['id' => $prestazioneId],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Elimina una prestazione
     * 
     * @param int $prestazioneId ID della prestazione
     * @return array { success: bool, data: array, errors: array }
     */
    public static function deleteComprovantePrestazione(int $prestazioneId): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Verifica esistenza
            $checkStmt = $pdo->prepare("SELECT id FROM gar_comprovanti_prestazioni WHERE id = :id");
            $checkStmt->execute([':id' => $prestazioneId]);
            if (!$checkStmt->fetch()) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Prestazione non trovata']
                ];
            }

            $stmt = $pdo->prepare("DELETE FROM gar_comprovanti_prestazioni WHERE id = :id");
            $stmt->execute([':id' => $prestazioneId]);

            return [
                'success' => true,
                'data' => ['id' => $prestazioneId],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Toggle del flag eseguita di una prestazione
     * 
     * @param int $prestazioneId ID della prestazione
     * @return array { success: bool, data: array, errors: array }
     */
    public static function togglePrestazioneEseguita(int $prestazioneId): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Recupera valore attuale
            $stmt = $pdo->prepare("SELECT flag_eseguita FROM gar_comprovanti_prestazioni WHERE id = :id");
            $stmt->execute([':id' => $prestazioneId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => ['Prestazione non trovata']
                ];
            }

            $nuovoValore = ((int) $row['flag_eseguita'] === 1) ? 0 : 1;

            $updateStmt = $pdo->prepare("UPDATE gar_comprovanti_prestazioni SET flag_eseguita = :flag WHERE id = :id");
            $updateStmt->execute([':flag' => $nuovoValore, ':id' => $prestazioneId]);

            return [
                'success' => true,
                'data' => ['id' => $prestazioneId, 'flag_eseguita' => $nuovoValore],
                'errors' => []
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => ['Errore: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Ottiene i requisiti di personale dal bando per un job_id specifico
     * 
     * @param int $jobId ID della gara
     * @return array { success: bool, data: array }
     */
    /**
     * Recupera i requisiti di idoneitÃ  professionale per un job_id specifico
     * 
     * @param int $jobId ID del job della gara
     * @return array { success: bool, data: array }
     */
    public static function getRequisitiPersonale(int $jobId): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Query diretta sulla tabella gar_gara_idoneita_professionale
            $stmt = $pdo->prepare(
                "SELECT id, job_id, extraction_id, ruolo, requisiti, extra_json, obbligatorio 
                 FROM gar_gara_idoneita_professionale 
                 WHERE job_id = :job_id 
                 ORDER BY id ASC"
            );

            $stmt->execute([':job_id' => $jobId]);

            $data = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $data[] = [
                    'id' => (int) $row['id'],
                    'job_id' => (int) $row['job_id'],
                    'extraction_id' => isset($row['extraction_id']) ? (int) $row['extraction_id'] : null,
                    'ruolo' => $row['ruolo'] ?? '',
                    'requisiti' => $row['requisiti'] ?? '',
                    'extra_json' => $row['extra_json'] ?? null,
                    'obbligatorio' => (int) ($row['obbligatorio'] ?? 0)
                ];
            }

            return [
                'success' => true,
                'data' => $data
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Alias per retrocompatibilitÃ  - usa getRequisitiPersonale
     * 
     * @param int $jobId ID del job della gara
     * @return array { success: bool, data: array }
     */
    public static function getIdoneitaProfessionaleByJobId(int $jobId): array
    {
        return self::getRequisitiPersonale($jobId);
    }

    /**
     * Metodo di debug per vedere quali job_id hanno requisiti nella tabella
     * 
     * @return array { success: bool, data: array }
     */
    public static function debugJobIdsConRequisiti(): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            $stmt = $pdo->query(
                "SELECT DISTINCT job_id, COUNT(*) as count 
                 FROM gar_gara_idoneita_professionale 
                 GROUP BY job_id 
                 ORDER BY job_id DESC 
                 LIMIT 20"
            );

            $data = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $data[] = [
                    'job_id' => (int) $row['job_id'],
                    'count' => (int) $row['count']
                ];
            }

            return [
                'success' => true,
                'data' => $data
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Ottiene l'elenco delle gare per il selettore
     * Restituisce solo gare con participation=1 e non archiviate
     * Usa direttamente ext_job_files JOIN ext_gare per garantire job_id corretti
     * 
     * @return array { success: bool, data: array }
     */
    public static function getElencoGare(): array
    {
        global $database;

        try {
            $pdo = $database->connection;

            // Query diretta: ext_job_files JOIN ext_gare per filtrare solo gare con participation=1
            // e non archiviate. Usa original_name come label principale.
            // Nota: usiamo subquery per le aggregazioni MAX per evitare problemi con GROUP BY
            $sql = "SELECT 
                        j.id AS job_id,
                        jf.original_name AS file_name,
                        j.created_at,
                        (SELECT COALESCE(e1.value_text, JSON_UNQUOTE(JSON_EXTRACT(e1.value_json, '$.answer')))
                         FROM ext_extractions e1 
                         WHERE e1.job_id = j.id AND e1.type_code = 'oggetto_appalto' 
                         LIMIT 1) AS estr_titolo,
                        (SELECT COALESCE(e2.value_text, JSON_UNQUOTE(JSON_EXTRACT(e2.value_json, '$.answer')))
                         FROM ext_extractions e2 
                         WHERE e2.job_id = j.id AND e2.type_code = 'stazione_appaltante' 
                         LIMIT 1) AS estr_ente,
                        (SELECT COALESCE(e3.value_text, JSON_UNQUOTE(JSON_EXTRACT(e3.value_json, '$.answer')))
                         FROM ext_extractions e3 
                         WHERE e3.job_id = j.id AND e3.type_code = 'luogo_provincia_appalto' 
                         LIMIT 1) AS estr_luogo
                    FROM ext_job_files jf
                    INNER JOIN ext_jobs j ON j.id = jf.job_id
                    INNER JOIN ext_gare g ON g.job_id = j.id
                    WHERE g.participation = 1 
                      AND COALESCE(g.archiviata, 0) = 0
                    ORDER BY j.created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            $data = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $jobId = (int) $row['job_id'];
                $fileName = $row['file_name'] ?? '';
                $titolo = $row['estr_titolo'] ?? $fileName;
                $ente = $row['estr_ente'] ?? '';
                $luogo = $row['estr_luogo'] ?? '';

                // Costruisci label: preferisci titolo estratto, altrimenti file_name
                $label = $titolo ?: $fileName ?: 'Gara #' . $jobId;
                if ($ente) {
                    $label .= ' - ' . $ente;
                }
                if ($luogo) {
                    $label .= ' (' . $luogo . ')';
                }

                $data[] = [
                    'job_id' => $jobId,
                    'label' => $label,
                    'titolo' => $titolo,
                    'file_name' => $fileName,
                    'ente' => $ente,
                    'luogo' => $luogo
                ];
            }

            return [
                'success' => true,
                'data' => $data
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Ottiene il personale interno attivo
     * 
     * @return array { success: bool, data: array }
     */
    public static function getPersonaleAttivo(): array
    {
        global $database;

        try {
            $stmt = $database->query(
                "SELECT Cod_Operatore, Nominativo, Genere, Tipo_Addetto, Reparto, Ruolo, 
                        Titolo_di_Studio, Stabilimento, Company, Data_Assunzione, Stato, attivo
                 FROM personale 
                 WHERE attivo = 1 
                 ORDER BY Nominativo ASC",
                [],
                __FILE__
            );

            if (!$stmt) {
                return ['success' => false, 'message' => 'Errore nella query'];
            }

            $data = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                // Formatta la data di assunzione
                $dataAssunzione = '';
                if (!empty($row['Data_Assunzione'])) {
                    try {
                        $date = new \DateTime($row['Data_Assunzione']);
                        $dataAssunzione = $date->format('d/m/Y');
                    } catch (\Exception $e) {
                        $dataAssunzione = $row['Data_Assunzione'];
                    }
                }

                $data[] = [
                    'Cod_Operatore' => $row['Cod_Operatore'] ?? '',
                    'Nominativo' => $row['Nominativo'] ?? '',
                    'Genere' => $row['Genere'] ?? '',
                    'Tipo_Addetto' => $row['Tipo_Addetto'] ?? '',
                    'Reparto' => $row['Reparto'] ?? '',
                    'Ruolo' => $row['Ruolo'] ?? '',
                    'Titolo_di_Studio' => $row['Titolo_di_Studio'] ?? '',
                    'Stabilimento' => $row['Stabilimento'] ?? '',
                    'Company' => $row['Company'] ?? '',
                    'Data_Assunzione' => $dataAssunzione,
                    'Stato' => $row['Stato'] ?? '',
                    'attivo' => (int) ($row['attivo'] ?? 0)
                ];
            }

            return [
                'success' => true,
                'data' => $data
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Errore: ' . $e->getMessage()
            ];
        }
    }
}
