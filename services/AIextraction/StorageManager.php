<?php

namespace Services;

/**
 * Gestisce persistenza e storage di file e dati estratti.
 * 
 * Responsabilità:
 * - Salvataggio file PDF upload
 * - Operazioni CRUD su ext_extractions, ext_citations, ext_table_cells
 * - Eliminazione file da storage
 * - Lookup e deduplica file
 */
class StorageManager
{
    private $pdo;
    private $storageBase;

    public function __construct(\PDO $pdo, string $storageBase = null)
    {
        $this->pdo = $pdo;
        $this->storageBase = $storageBase ?: self::getStorageBase();
    }

    // ========== FILE OPERATIONS ==========

    /**
     * Salva PDF uploadato nel filesystem
     * 
     * @param array $file File array da $_FILES
     * @param string $sha256 Hash SHA256 del file (opzionale)
     * @return array Metadati file salvato
     * @throws \RuntimeException
     */
    public function savePdf(array $file, ?string $sha256 = null): array
    {
        // Validazioni
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . ($file['error'] ?? 'Unknown'));
        }

        $mimeType = mime_content_type($file['tmp_name']) ?: 'application/pdf';
        if ($mimeType !== 'application/pdf') {
            throw new \RuntimeException('Only PDF files allowed');
        }

        // Calcola hash se non fornito
        if ($sha256 === null) {
            $sha256 = hash_file('sha256', $file['tmp_name']);
            if ($sha256 === false) {
                throw new \RuntimeException('Cannot calculate file hash');
            }
        }

        // Prepara directory (anno/mese/giorno)
        $dir = $this->ensureStorageDir('pdf_ai/' . date('Y/m/d'));

        // Genera nome file sicuro
        $originalName = $file['name'] ?? 'document.pdf';
        $safeName = preg_replace('/[^\w\-.]+/u', '_', $originalName);
        $filename = $sha256 . '_' . $safeName;
        $destination = $dir . '/' . $filename;

        // Sposta file
        if (!@move_uploaded_file($file['tmp_name'], $destination)) {
            $lastError = error_get_last();
            throw new \RuntimeException('Move failed: ' . ($lastError['message'] ?? 'Unknown'));
        }

        $sizeBytes = filesize($destination);

        return [
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'size_bytes'    => $sizeBytes,
            'rel_path'      => $this->relativePath($destination),
            'absolute_path' => $destination,
            'sha256'        => $sha256,
        ];
    }

    /**
     * Elimina file dal filesystem
     */
    public function deleteFile(string $relPath): void
    {
        if ($relPath === '') {
            return;
        }

        $fullPath = $this->absolutePath($relPath);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    /**
     * Verifica se file esiste nel filesystem
     */
    public function fileExists(string $relPath): bool
    {
        return is_file($this->absolutePath($relPath));
    }

    /**
     * Legge contenuto file
     */
    public function readFile(string $relPath): string
    {
        $fullPath = $this->absolutePath($relPath);
        $content = @file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException('Cannot read file: ' . $relPath);
        }
        return $content;
    }

    // ========== DATABASE OPERATIONS - EXTRACTIONS ==========

    /**
     * Salva un'estrazione in ext_extractions
     * 
     * @param int $jobId Job ID
     * @param array $extraction Dati estrazione
     * @return int ID dell'estrazione inserita
     */
    /**
     * Rimuove campi di debug dal value_json prima di salvare
     */
    private function cleanValueJson($valueJson): ?array
    {
        if (!is_array($valueJson)) {
            return $valueJson;
        }

        // Rimuovi campi di debug/metadati che non devono essere salvati
        $debugFields = [
            'chain_of_thought', 'chainOfThought', 'reasoning', 
            'processing_time', 'error', 'error_details',
            'empty_reason', 'reason', 'message', 'note', 'explanation',
            'raw_block', 'raw_text', 'debug', 'logs'
        ];
        foreach ($debugFields as $field) {
            unset($valueJson[$field]);
        }

        return $valueJson;
    }

    public function saveExtraction(int $jobId, array $extraction): int
    {
        // Pulisci value_json rimuovendo campi di debug
        $cleanValueJson = null;
        if (isset($extraction['value_json'])) {
            $cleanValueJson = $this->cleanValueJson($extraction['value_json']);
        }
        
        // Verifica che value_text non contenga campi di debug
        $valueText = $extraction['value_text'] ?? null;
        if (is_string($valueText) && (
            stripos($valueText, 'chain_of_thought') !== false ||
            stripos($valueText, 'reasoning') !== false ||
            stripos($valueText, 'empty_reason') !== false ||
            preg_match('/^\s*[\{\[]/', trim($valueText))
        )) {
            // Se value_text contiene campi di debug o JSON grezzo, imposta a null
            $valueText = null;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO ext_extractions (job_id, type_code, value_text, value_json, confidence)
             VALUES (:j, :t, :vt, :vj, :c)"
        );

        $stmt->execute([
            ':j'  => $jobId,
            ':t'  => $extraction['type_code'] ?? null,
            ':vt' => $valueText,
            ':vj' => $cleanValueJson !== null 
                ? json_encode($cleanValueJson, JSON_UNESCAPED_UNICODE) 
                : null,
            ':c'  => $extraction['confidence'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Salva più estrazioni in una transazione
     * 
     * @param int $jobId Job ID
     * @param array $extractions Array di estrazioni
     * @return array Array di ID estratti
     */
    public function saveExtractions(int $jobId, array $extractions): array
    {
        $ids = [];

        try {
            $this->pdo->beginTransaction();

            foreach ($extractions as $extraction) {
                $ids[] = $this->saveExtraction($jobId, $extraction);
            }

            $this->pdo->commit();

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $ids;
    }

    /**
     * Elimina tutte le estrazioni di un job
     */
    public function deleteExtractions(int $jobId): void
    {
        // Prima elimina citations e table_cells
        $stmt = $this->pdo->prepare(
            "SELECT id FROM ext_extractions WHERE job_id = :job_id"
        );
        $stmt->execute([':job_id' => $jobId]);
        $extractionIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (!empty($extractionIds)) {
            $placeholders = implode(',', array_fill(0, count($extractionIds), '?'));
            $this->pdo->prepare("DELETE FROM ext_citations WHERE extraction_id IN ($placeholders)")
                ->execute($extractionIds);
            $this->pdo->prepare("DELETE FROM ext_table_cells WHERE extraction_id IN ($placeholders)")
                ->execute($extractionIds);
        }

        // Poi elimina le estrazioni
        $this->pdo->prepare("DELETE FROM ext_extractions WHERE job_id = :job_id")
            ->execute([':job_id' => $jobId]);
    }

    /**
     * Recupera estrazione per ID
     */
    public function getExtraction(int $extractionId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM ext_extractions WHERE id = :id"
        );
        $stmt->execute([':id' => $extractionId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    // ========== DATABASE OPERATIONS - CITATIONS ==========

    /**
     * Salva citazioni per un'estrazione
     * 
     * @param int $extractionId Extraction ID
     * @param array $citations Array di citazioni
     */
    public function saveCitations(int $extractionId, array $citations): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ext_citations (extraction_id, page_number, snippet, highlight_rel_path)
             VALUES (:e, :p, :s, :h)"
        );

        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                continue;
            }

            $stmt->execute([
                ':e' => $extractionId,
                ':p' => (int)($citation['page_number'] ?? $citation['page'] ?? 0),
                ':s' => $citation['snippet'] ?? null,
                ':h' => $citation['highlight_rel_path'] ?? null,
            ]);
        }
    }

    /**
     * Recupera citazioni di un'estrazione
     */
    public function getCitations(int $extractionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM ext_citations WHERE extraction_id = :id ORDER BY id ASC"
        );
        $stmt->execute([':id' => $extractionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Elimina citazioni di un'estrazione
     */
    public function deleteCitations(int $extractionId): void
    {
        $this->pdo->prepare("DELETE FROM ext_citations WHERE extraction_id = :id")
            ->execute([':id' => $extractionId]);
    }

    // ========== DATABASE OPERATIONS - TABLE CELLS ==========

    /**
     * Salva celle di tabella per un'estrazione
     * 
     * @param int $extractionId Extraction ID
     * @param array $headers Colonne della tabella
     * @param array $rows Righe della tabella
     */
    public function saveTableCells(int $extractionId, array $headers, array $rows): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ext_table_cells (extraction_id, r, c, header, cell_text)
             VALUES (:e, :r, :c, :h, :t)"
        );

        foreach ($rows as $rIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $cIndex => $cell) {
                $cellText = $this->normalizeCellValue($cell);
                $header = $headers[$cIndex] ?? null;

                $stmt->execute([
                    ':e' => $extractionId,
                    ':r' => (int)$rIndex,
                    ':c' => (int)$cIndex,
                    ':h' => $header,
                    ':t' => $cellText,
                ]);
            }
        }
    }

    /**
     * Recupera celle di tabella per un'estrazione
     */
    public function getTableCells(int $extractionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r, c, header, cell_text FROM ext_table_cells 
             WHERE extraction_id = :id 
             ORDER BY r ASC, c ASC"
        );
        $stmt->execute([':id' => $extractionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Elimina celle di tabella
     */
    public function deleteTableCells(int $extractionId): void
    {
        $this->pdo->prepare("DELETE FROM ext_table_cells WHERE extraction_id = :id")
            ->execute([':id' => $extractionId]);
    }

    // ========== DATABASE OPERATIONS - JOB FILES ==========

    /**
     * Allega file a job
     * 
     * @param int $jobId Job ID
     * @param array $fileMeta Metadati file da savePdf()
     * @throws \PDOException Se il file è già allegato
     */
    public function attachFileToJob(int $jobId, array $fileMeta): void
    {
        $sql = "INSERT INTO ext_job_files 
                (job_id, original_name, mime_type, size_bytes, rel_path, sha256)
                VALUES (:job, :n, :m, :s, :p, :h)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':job' => $jobId,
            ':n'   => $fileMeta['original_name'],
            ':m'   => $fileMeta['mime_type'],
            ':s'   => $fileMeta['size_bytes'],
            ':p'   => $fileMeta['rel_path'],
            ':h'   => $fileMeta['sha256'],
        ]);
    }

    /**
     * Cerca file per SHA256
     */
    public function findFileBySha(string $sha): ?array
    {
        $sql = "SELECT f.job_id, f.original_name, f.mime_type, f.size_bytes, f.rel_path, f.sha256,
                       j.ext_job_id, j.ext_batch_id, j.status
                FROM ext_job_files f
                LEFT JOIN ext_jobs j ON j.id = f.job_id
                WHERE f.sha256 = :sha
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':sha' => $sha]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Elimina file di un job
     */
    public function deleteJobFiles(int $jobId): void
    {
        $this->pdo->prepare("DELETE FROM ext_job_files WHERE job_id = :id")
            ->execute([':id' => $jobId]);
    }

    // ========== BATCH OPERATIONS ==========

    /**
     * Cancella completamente un job (files, extractions, citations, etc)
     * 
     * @param int $jobId Job ID
     */
    public function purgeJob(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }

        $filePaths = [];

        try {
            $this->pdo->beginTransaction();

            // Recupera file path per eliminare fisicamente
            $stmt = $this->pdo->prepare("SELECT rel_path FROM ext_job_files WHERE job_id = :id");
            $stmt->execute([':id' => $jobId]);
            $filePaths = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            // Recupera extraction IDs
            $stmt = $this->pdo->prepare("SELECT id FROM ext_extractions WHERE job_id = :id");
            $stmt->execute([':id' => $jobId]);
            $extractionIds = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            // Elimina in cascata
            if (!empty($extractionIds)) {
                $placeholders = implode(',', array_fill(0, count($extractionIds), '?'));
                $this->pdo->prepare("DELETE FROM ext_citations WHERE extraction_id IN ($placeholders)")
                    ->execute($extractionIds);
                $this->pdo->prepare("DELETE FROM ext_table_cells WHERE extraction_id IN ($placeholders)")
                    ->execute($extractionIds);
            }

            $this->pdo->prepare("DELETE FROM ext_extractions WHERE job_id = :id")
                ->execute([':id' => $jobId]);
            $this->pdo->prepare("DELETE FROM ext_job_files WHERE job_id = :id")
                ->execute([':id' => $jobId]);
            $this->pdo->prepare("DELETE FROM ext_jobs WHERE id = :id")
                ->execute([':id' => $jobId]);

            $this->pdo->commit();

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Elimina file fisicamente
        foreach ($filePaths as $relPath) {
            if ($relPath) {
                $this->deleteFile($relPath);
            }
        }
    }

    /**
     * Sostituisce estrazioni di un job (delete old, insert new)
     * 
     * @param int $jobId Job ID
     * @param array $answers Nuove estrazioni (da mapSingleAnswer())
     */
    public function replaceExtractions(int $jobId, array $answers): void
    {
        try {
            $this->pdo->beginTransaction();

            // Elimina vecchie
            $this->deleteExtractions($jobId);

            // Inserisci nuove
            foreach ($answers as $answer) {
                $extractionId = $this->saveExtraction($jobId, $answer);

                if (!empty($answer['citations']) && is_array($answer['citations'])) {
                    $this->saveCitations($extractionId, $answer['citations']);
                }

                // Salva tabelle se presenti
                $valueJson = $answer['value_json'] ?? null;
                if (is_string($valueJson)) {
                    $valueJson = json_decode($valueJson, true);
                }

                if (is_array($valueJson) && isset($valueJson['headers']) && isset($valueJson['rows'])) {
                    $this->saveTableCells(
                        $extractionId,
                        $valueJson['headers'],
                        $valueJson['rows']
                    );
                }
            }

            $this->pdo->commit();

        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ========== HELPER METHODS ==========

    /**
     * Normalizza valore cella di tabella
     */
    private function normalizeCellValue($cell): ?string
    {
        if ($cell === null) {
            return null;
        }

        if (is_array($cell)) {
            $cellValue = $cell['raw'] ?? $cell['value'] ?? null;
            if ($cellValue === null && !empty($cell)) {
                $cellValue = is_scalar($cell) ? (string)$cell : json_encode($cell);
            }
        } else {
            $cellValue = $cell;
        }

        $text = trim((string)$cellValue);
        return $text !== '' ? $text : null;
    }

    /**
     * Assicura che directory di storage esista
     * 
     * @return string Percorso assoluto della directory
     */
    private function ensureStorageDir(string $suffix): string
    {
        $dir = $this->storagePath($suffix);

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true)) {
                $error = error_get_last()['message'] ?? 'Unknown error';
                throw new \RuntimeException('Cannot create storage directory: ' . $error);
            }
            @chmod($dir, 0775);
        }

        if (!is_writable($dir)) {
            @chmod($dir, 0775);
            if (!is_writable($dir)) {
                throw new \RuntimeException('Storage directory not writable: ' . $dir);
            }
        }

        return $dir;
    }

    /**
     * Percorso assoluto da percorso relativo
     */
    private function absolutePath(string $rel): string
    {
        return rtrim($this->storageBase, '/\\') . '/' . ltrim($rel, '/');
    }

    /**
     * Percorso relativo da percorso assoluto
     */
    private function relativePath(string $absolute): string
    {
        $base = str_replace('\\', '/', rtrim($this->storageBase, '/\\')) . '/';
        $path = str_replace('\\', '/', $absolute);

        if (strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }

        return ltrim($path, '/');
    }

    /**
     * Percorso di storage completo
     */
    private function storagePath(string $suffix = ''): string
    {
        return rtrim($this->storageBase . '/' . ltrim($suffix, '/'), '/');
    }

    /**
     * Recupera base path storage
     */
    public static function getStorageBase(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }

        $root = dirname(__DIR__);
        $uploads = $root . '/uploads';
        if (!is_dir($uploads)) {
            @mkdir($uploads, 0775, true);
        }

        $base = $uploads . '/gare_ai';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        return $base;
    }

    /**
     * Ritorna path assoluto per file
     */
    public static function absolutePathStatic(string $rel): string
    {
        return self::getStorageBase() . '/' . ltrim($rel, '/');
    }
}