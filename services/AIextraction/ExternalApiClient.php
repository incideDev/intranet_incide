<?php

namespace Services\AIextraction;

/**
 * Client per comunicare con API esterna di estrazione dati.
 * Gestisce:
 * - Autenticazione
 * - Invio file per analisi
 * - Polling stato batch
 * - Recupero risultati
 * - Gestione errori HTTP
 */
class ExternalApiClient
{
    private $config = [];
    private $debugLogs = [];

    public function __construct(array $config = [])
    {
        if (empty($config)) {
            // Usa la stessa pipeline di GareService per uniformità
            $env = \Services\ExtractionService::expandEnvPlaceholders(\Services\ExtractionService::loadEnvConfig());
            $this->config = $env;
        } else {
            $this->config = $config;
        }
    }

    /**
     * Invia un singolo file per analisi (metodo di istanza)
     * 
     * @param array $fields Campi della richiesta (extraction_types, notification_email, file_name)
     * @param array $file File da analizzare (['tmp_name', 'name', 'type'])
     * @return array Risposta API con status, body, raw
     */
    public function analyzeSingleFile(array $fields, array $file): array
    {
        $startUrl = $this->buildUrl('/batch/analyze');

        // L'API si aspetta il campo 'files' anche per singolo file
        $fileParts = [[
            'field'    => 'files',
            'tmp_name' => $file['tmp_name'],
            'name'     => $file['name'],
            'type'     => $file['type'] ?? 'application/pdf',
        ]];

        $singleFields = [
            'file_name'          => $fields['file_name'] ?? $file['name'] ?? 'document.pdf',
            'notification_email' => $fields['notification_email'] ?? ($this->config['AI_NOTIFICATION_EMAIL'] ?? ''),
        ];

        $repeated = [
            'extraction_types' => $fields['extraction_types'] ?? [],
        ];

        return $this->multipartRequest($startUrl, $singleFields, $repeated, $fileParts);
    }

    /**
     * Recupera stato di un batch
     * 
     * @param string $batchId ID del batch
     * @return array Risposta con status, progress, ecc
     */
    public function getBatchStatus(string $batchId): array
    {
        $url = $this->buildUrl('/batch/' . rawurlencode($batchId) . '/status');
        return $this->jsonRequest('GET', $url);
    }

    /**
     * Recupera risultati di un batch completato
     * 
     * @param string $batchId ID del batch
     * @return array Risposta con results/extractions
     */
    public function getBatchResults(string $batchId): array
    {
        $url = $this->buildUrl('/batch/' . rawurlencode($batchId) . '/results');
        return $this->jsonRequest('GET', $url);
    }

    /**
     * Effettua richiesta JSON generica (GET/POST/PUT)
     * 
     * @param string $method HTTP method (GET, POST, PUT, etc)
     * @param string $url URL completo
     * @param array $payload Payload per POST/PUT (opzionale)
     * @return array ['status' => int, 'body' => array|null, 'raw' => string]
     */
    public function jsonRequest(string $method, string $url, array $payload = []): array
    {
        $headers = $this->buildHeaders();
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $this->applyCurlOptions($ch);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('cURL error: ' . ($err ?: 'Unknown'));
        }

        $json = json_decode($raw, true);
        return [
            'status' => $code,
            'body'   => is_array($json) ? $json : null,
            'raw'    => $raw,
        ];
    }

    /**
     * Effettua richiesta multipart/form-data (per file upload)
     * 
     * @param string $url URL target
     * @param array $singleFields Campi semplici (nome => valore)
     * @param array $repeatedFields Campi ripetuti (nome => [valori])
     * @param array $fileParts Array di file (['field', 'tmp_name', 'name', 'type'])
     * @return array Risposta decodificata
     */
    public function multipartRequest(
        string $url,
        array $singleFields,
        array $repeatedFields,
        array $fileParts
    ): array {
        $boundary = '----pb-' . bin2hex(random_bytes(12));
        $body = $this->buildMultipartBody(
            $boundary,
            $singleFields,
            $repeatedFields,
            $fileParts
        );

        $headers = $this->buildHeaders();
        $headers[] = "Content-Type: multipart/form-data; boundary={$boundary}";
        $headers[] = "Content-Length: " . strlen($body);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
        ]);

        $this->applyCurlOptions($ch);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('cURL error: ' . ($err ?: 'Unknown'));
        }

        $json = json_decode($raw, true);
        return [
            'status' => $code,
            'body'   => is_array($json) ? $json : null,
            'raw'    => $raw,
        ];
    }

    /**
     * Costruisce il corpo di una richiesta multipart
     */
    private function buildMultipartBody(
        string $boundary,
        array $singleFields,
        array $repeatedFields,
        array $fileParts
    ): string {
        $eol = "\r\n";
        $body = '';

        // Funzione helper per aggiungere una part
        $addPart = function (string $name, string $content, ?string $filename = null, ?string $contentType = null) use (&$body, $boundary, $eol) {
            $body .= "--{$boundary}{$eol}";
            if ($filename !== null) {
                $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"{$eol}";
                $body .= "Content-Type: " . ($contentType ?: 'application/octet-stream') . "{$eol}{$eol}";
            } else {
                $body .= "Content-Disposition: form-data; name=\"{$name}\"{$eol}{$eol}";
            }
            $body .= $content . "{$eol}";
        };

        // Aggiungi campi semplici
        foreach ($singleFields as $k => $v) {
            if ($v === null) {
                continue;
            }
            $addPart($k, (string)$v);
        }

        // Aggiungi campi ripetuti
        foreach ($repeatedFields as $name => $values) {
            foreach ((array)$values as $v) {
                $addPart($name, (string)$v);
            }
        }

        // Aggiungi file
        foreach ($fileParts as $f) {
            if (empty($f['field']) || empty($f['tmp_name'])) {
                continue;
            }
            $ct  = $f['type'] ?? (mime_content_type($f['tmp_name']) ?: 'application/octet-stream');
            $fn  = $f['name'] ?? basename($f['tmp_name']);
            $bin = @file_get_contents($f['tmp_name']);
            if ($bin === false) {
                throw new \RuntimeException('Cannot read file: ' . $f['tmp_name']);
            }
            $addPart((string)$f['field'], $bin, $fn, $ct);
        }

        $body .= "--{$boundary}--{$eol}";

        return $body;
    }

    /**
     * Costruisce array di headers HTTP con autenticazione
     */
    private function buildHeaders(): array
    {
        $headers = [];

        // API key
        $apiKey = $this->config['AI_API_KEY'] ?? null;
        if ($apiKey) {
            $headers[] = 'x-api-key: ' . $apiKey;
        }

        // Bearer token
        if (!empty($this->config['AI_AUTH_BEARER'])) {
            $headers[] = 'Authorization: Bearer ' . $this->config['AI_AUTH_BEARER'];
        }
        // Basic auth
        elseif (!empty($this->config['AI_AUTH_BASIC'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->config['AI_AUTH_BASIC']);
        }

        // Host header forzato
        if (!empty($this->config['AI_FORCE_HOST_HEADER']) && !empty($this->config['AI_API_HOST'])) {
            $headers[] = 'Host: ' . $this->config['AI_API_HOST'];
        }

        return $headers;
    }

    /**
     * Applica opzioni avanzate di cURL
     */
    private function applyCurlOptions($ch): void
    {
        // DNS resolution personalizzata
        if (!empty($this->config['AI_DNS_RESOLVE'])) {
            $entries = array_map('trim', explode(',', $this->config['AI_DNS_RESOLVE']));
            curl_setopt($ch, CURLOPT_RESOLVE, $entries);
        }

        // Disabilita verifica SSL se richiesto
        if (!empty($this->config['AI_TLS_INSECURE'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
    }

    /**
     * Costruisce URL completo
     */
    private function buildUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $base = rtrim($this->getApiBase(), '/');
        $version = $this->config['AI_API_VERSION'] ?? 'v1';
        if (preg_match('#^/api/v\d#', $path)) {
            return $base . $path;
        }
        return $base . '/api/' . $version . $path;
    }

    /**
     * Ottiene base URL dell'API
     */
    private function getApiBase(): string
    {
        $base = trim((string)($this->config['AI_API_BASE'] ?? ''));
        if ($base === '') {
            throw new \RuntimeException('AI_API_BASE not configured');
        }
        return $base;
    }

    /**
     * Aggiunge un log di debug
     */
    public function addDebugLog(string $message): void
    {
        $this->debugLogs[] = date('Y-m-d H:i:s') . ' - ' . $message;
        error_log($message);
    }

    /**
     * Ottiene e resetta i log di debug
     */
    public function getDebugLogs(): array
    {
        $logs = $this->debugLogs;
        $this->debugLogs = [];
        return $logs;
    }

    /**
     * Verifica se API è configurata
     */
    public function isConfigured(): bool
    {
        try {
            $this->getApiBase();
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    // ===== METODI STATICI PER COMPATIBILITÀ CON CONTROLLER =====

    /**
     * Crea un'istanza con configurazione da environment
     */
    private static function getInstance(): self
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }

    /**
     * Lista i tipi di estrazione disponibili
     * 
     * @return array Lista dei tipi di estrazione
     */
    public static function listExtractionTypes(): array
    {
        $client = self::getInstance();
        $url = $client->buildUrl('/extraction-types');
        $response = $client->jsonRequest('GET', $url);
        
        if (($response['status'] ?? 500) >= 400) {
            throw new \RuntimeException('API ext error');
        }
        
        return $response['body'] ?? [];
    }

    /**
     * Analizza un singolo file (metodo statico per compatibilità)
     * 
     * @param array $fields Campi della richiesta (extraction_types, notification_email, file_name)
     * @param array $files Array con chiave 'file' contenente il file (compatibilità con controller)
     * @return array Risposta API con status, body, raw
     */
    public static function analyzeSingle(array $fields, array $files): array
    {
        // Adatta la firma: $files['file'] -> $file
        if (empty($files) || !isset($files['file'])) {
            throw new \RuntimeException('file PDF mancante');
        }
        
        $file = $files['file'];
        
        // Normalizza extraction_types se necessario
        $types = [];
        if (isset($fields['extraction_types'])) {
            $v = $fields['extraction_types'];
            if (is_string($v)) {
                $tmp = json_decode($v, true);
                if (is_array($tmp)) {
                    $types = array_values(array_filter(array_map('strval', $tmp)));
                }
            } elseif (is_array($v)) {
                $types = array_values(array_filter(array_map('strval', $v)));
            }
        }
        
        if (empty($types)) {
            throw new \RuntimeException('extraction_types mancante o vuoto');
        }
        
        $fields['extraction_types'] = $types;
        
        // Usa il metodo di istanza
        $client = self::getInstance();
        return $client->analyzeSingleFile($fields, $file);
    }

    /**
     * Analizza un batch (metodo statico per compatibilità)
     * 
     * @param array $fields Campi della richiesta
     * @param array $files Array con chiave 'file' contenente il file
     * @return array Risposta API con status, body, raw
     */
    public static function analyzeBatch(array $fields, array $files): array
    {
        return self::analyzeSingle($fields, $files);
    }

    /**
     * Recupera stato di un job (metodo statico per compatibilità)
     * 
     * @param string $jobId ID del job
     * @return array Risposta con status, progress, ecc
     */
    public static function jobStatus(string $jobId): array
    {
        $client = self::getInstance();
        $url = $client->buildUrl('/jobs/' . rawurlencode($jobId));
        return $client->jsonRequest('GET', $url);
    }

    /**
     * Recupera risultato di un job (metodo statico per compatibilità)
     * 
     * @param string $jobId ID del job
     * @return array Risposta con risultati
     */
    public static function jobResult(string $jobId): array
    {
        $client = self::getInstance();
        $url = $client->buildUrl('/jobs/' . rawurlencode($jobId) . '/result');
        return $client->jsonRequest('GET', $url);
    }

    /**
     * Recupera stato di un batch (metodo statico per compatibilità)
     * 
     * @param string $batchId ID del batch
     * @return array Risposta con status, progress, ecc
     */
    public static function batchStatus(string $batchId): array
    {
        $client = self::getInstance();
        return $client->getBatchStatus($batchId);
    }

    /**
     * Recupera risultati di un batch (metodo statico per compatibilità)
     * 
     * @param string $batchId ID del batch
     * @return array Risposta con results/extractions
     */
    public static function batchResults(string $batchId): array
    {
        $client = self::getInstance();
        return $client->getBatchResults($batchId);
    }

    /**
     * Download di un file relativo (metodo statico per compatibilità)
     * 
     * @param string $rel Path relativo o URL completo
     * @return array Risposta con contenuto del file
     */
    public static function download(string $rel): array
    {
        $client = self::getInstance();
        $url = $client->buildUrl($rel);
        return $client->jsonRequest('GET', $url);
    }

    // ===== NEW V1 API METHODS =====

    public function getQuota(): array
    {
        $url = $this->buildUrl('/quota');
        $res = $this->jsonRequest('GET', $url);
        if (($res['status'] ?? 500) >= 400) {
            return ['success' => false, 'message' => 'Quota check failed: HTTP ' . ($res['status'] ?? 'unknown')];
        }
        return ['success' => true, 'data' => $res['body']];
    }

    public function checkQuota(int $needed): array
    {
        $url = $this->buildUrl('/quota/check');
        $res = $this->jsonRequest('POST', $url, ['requested' => $needed]);
        if (($res['status'] ?? 500) >= 400) {
            return ['success' => false, 'message' => 'Quota check failed: HTTP ' . ($res['status'] ?? 'unknown')];
        }
        return ['success' => true, 'data' => $res['body']];
    }

    public function getDailyUsage(?string $date = null): array
    {
        $url = $this->buildUrl('/usage');
        if ($date) {
            $url .= '?date=' . rawurlencode($date);
        }
        $res = $this->jsonRequest('GET', $url);
        if (($res['status'] ?? 500) >= 400) {
            return ['success' => false, 'message' => 'Usage fetch failed: HTTP ' . ($res['status'] ?? 'unknown')];
        }
        return ['success' => true, 'data' => $res['body']];
    }

    public function getBatchUsage(string $batchId): array
    {
        $url = $this->buildUrl('/usage/batch/' . rawurlencode($batchId));
        $res = $this->jsonRequest('GET', $url);
        if (($res['status'] ?? 500) >= 400) {
            return ['success' => false, 'message' => 'Batch usage fetch failed: HTTP ' . ($res['status'] ?? 'unknown')];
        }
        return ['success' => true, 'data' => $res['body']];
    }

    public function getUsageHistory(int $days = 30, ?string $cursor = null): array
    {
        $url = $this->buildUrl('/usage/history') . '?days=' . $days;
        if ($cursor) {
            $url .= '&cursor=' . rawurlencode($cursor);
        }
        $res = $this->jsonRequest('GET', $url);
        if (($res['status'] ?? 500) >= 400) {
            return ['success' => false, 'message' => 'Usage history failed: HTTP ' . ($res['status'] ?? 'unknown')];
        }
        return ['success' => true, 'data' => $res['body']];
    }

    public function healthCheck(): array
    {
        $url = $this->buildUrl('/health');
        $res = $this->jsonRequest('GET', $url);
        if (($res['status'] ?? 500) >= 400) {
            return ['success' => false, 'message' => 'Health check failed: HTTP ' . ($res['status'] ?? 'unknown')];
        }
        return ['success' => true, 'data' => $res['body']];
    }

    public function listBatches(?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $url = $this->buildUrl('/batches') . '?limit=' . $limit . '&offset=' . $offset;
        if ($status) {
            $url .= '&status=' . rawurlencode($status);
        }
        $res = $this->jsonRequest('GET', $url);
        if (($res['status'] ?? 500) >= 400) {
            return ['success' => false, 'message' => 'Batch list failed: HTTP ' . ($res['status'] ?? 'unknown')];
        }
        return ['success' => true, 'data' => $res['body']];
    }

    public function deleteJob(string $jobId): array
    {
        $url = $this->buildUrl('/jobs/' . rawurlencode($jobId));
        $res = $this->jsonRequest('DELETE', $url);
        if (($res['status'] ?? 500) >= 400) {
            return ['success' => false, 'message' => 'Delete failed: HTTP ' . ($res['status'] ?? 'unknown')];
        }
        return ['success' => true, 'data' => $res['body']];
    }

    /**
     * Download binary file (highlighted PDFs).
     * Returns raw binary content, not JSON-decoded.
     *
     * @return array ['status' => int, 'body' => string, 'content_type' => string]
     */
    public function downloadBinary(string $jobId, string $filename): array
    {
        $url = $this->buildUrl('/jobs/' . rawurlencode($jobId) . '/download/' . rawurlencode($filename));

        $headers = $this->buildHeaders();
        $headers[] = 'Accept: application/pdf, application/octet-stream';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $this->applyCurlOptions($ch);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
        curl_close($ch);

        if ($raw === false) {
            return ['status' => 0, 'body' => '', 'content_type' => '', 'error' => $err];
        }

        return ['status' => $code, 'body' => $raw, 'content_type' => $ct];
    }
}