<?php

namespace Services\AIextraction;

/**
 * Helper per decodificare testi e JSON in modo intelligente
 */
class TextDecoder
{
    /**
     * Decodifica JSON in modo intelligente
     * Gestisce stringhe "null", JSON valido, e fallback
     * 
     * @param mixed $value Valore da decodificare
     * @param mixed $fallback Valore di fallback
     * @return mixed Valore decodificato o fallback
     */
    public static function smartDecode($value, $fallback = null)
    {
        if ($value === null) {
            return $fallback;
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strtolower($trimmed) === 'null') {
            return $fallback;
        }

        $decoded = json_decode($trimmed, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $fallback;
        }

        return $decoded;
    }

    /**
     * Estrae valore scalare da value_text o value_json
     */
    public static function extractScalarValue(?string $valueText, ?array $valueJson, string $typeCode): ?string
    {
        // Priorità 1: value_text
        if (!empty($valueText) && trim($valueText) !== '' && strtolower(trim($valueText)) !== 'null') {
            return trim($valueText);
        }

        // Priorità 2: value_json
        if (is_array($valueJson)) {
            $candidates = ['answer', 'value', 'result', 'response', 'text', 'display_value'];
            foreach ($candidates as $key) {
                if (isset($valueJson[$key])) {
                    $val = $valueJson[$key];
                    if (is_string($val) && trim($val) !== '') {
                        return trim($val);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Estrae data da valore (supporta più formati)
     */
    public static function extractDate($value): ?string
    {
        if (!$value) {
            return null;
        }

        if (is_array($value)) {
            // Struttura: date con year, month, day
            if (isset($value['year']) && isset($value['month']) && isset($value['day'])) {
                $y = $value['year'];
                $m = $value['month'];
                $d = $value['day'];
                if ($y && $m && $d) {
                    return sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
                }
            }
            // Struttura annidata: date.date
            if (isset($value['date']) && is_array($value['date'])) {
                return self::extractDate($value['date']);
            }
            // answer
            if (isset($value['answer'])) {
                return self::extractDate($value['answer']);
            }
        }

        if (is_string($value)) {
            // Prova regex ISO
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $value, $m)) {
                return $m[0];
            }
            // Prova d/m/y
            if (preg_match('/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $value, $m)) {
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
                $year = $m[3];
                if (strlen($year) === 2) {
                    $year = '20' . $year;
                }
                return sprintf('%04d-%02d-%02d', (int)$year, (int)$month, (int)$day);
            }
        }

        return null;
    }
}

/**
 * Helper per formattare testi
 */
class TextFormatter
{
    /**
     * Pulisce testo italiano (spazi multipli, trim)
     */
    public static function cleanItalianText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Genera role_code da nome ruolo
     */
    public static function generateRoleCode(string $roleName): string
    {
        $code = preg_replace('/[^a-zA-Z0-9\s]/', '', $roleName);
        $code = preg_replace('/\s+/', '_', trim($code));
        $code = strtoupper($code);

        if (strlen($code) > 50) {
            $code = substr($code, 0, 50);
        }

        return $code ?: 'ROLE_' . uniqid();
    }

    /**
     * Formatta numero come euro
     */
    public static function formatEuro(?float $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return number_format($value, 2, ',', '.') . ' €';
    }

    /**
     * Formatta data ISO a formato italiano
     */
    public static function formatItalianDate(string $iso): string
    {
        try {
            $dt = new \DateTime($iso);
            return $dt->format('d-m-Y');
        } catch (\Throwable $e) {
            return $iso;
        }
    }

    /**
     * Normalizza booleano
     */
    public static function normalizeBoolean(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['si', 'sì', 'yes', 'true', '1', 'obbligatorio'], true)) {
            return 1;
        }
        if (in_array($normalized, ['no', 'false', '0', 'facoltativo'], true)) {
            return 0;
        }

        return null;
    }

    /**
     * Stringifica valore per estrazione
     */
    public static function stringify($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'si' : 'no';
        }

        if (is_array($value)) {
            // Per array, prova answer
            if (isset($value['answer'])) {
                return self::stringify($value['answer']);
            }
            // Altrimenti JSON
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return trim((string)$value) ?: null;
    }

    /**
     * Abbrevia testo
     */
    public static function truncate(string $text, int $maxLength = 100): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength - 3) . '…';
    }
}

/**
 * Helper per operazioni di validazione e controllo
 */
class TextValidator
{
    /**
     * Verifica se testo è vuoto/invalido
     */
    public static function isEmpty(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        $trim = trim($value);
        return $trim === '' || strtolower($trim) === 'null' || strtolower($trim) === 'array';
    }

    /**
     * Verifica se è un valore booleano
     */
    public static function isBoolean(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $lower = strtolower(trim($value));
        $boolValues = ['si', 'sì', 'yes', 'true', '1', 'no', 'false', '0', 'obbligatorio', 'facoltativo'];

        return in_array($lower, $boolValues, true);
    }

    /**
     * Verifica se è una data
     */
    public static function isDate($value): bool
    {
        if (is_array($value) && isset($value['year'], $value['month'], $value['day'])) {
            return true;
        }

        if (is_string($value)) {
            return TextDecoder::extractDate($value) !== null;
        }

        return false;
    }

    /**
     * Verifica se è un URL
     */
    public static function isUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}

/**
 * Normalizza estrazioni e popola tabelle normalizzate (ext_req_docs, ext_req_econ, ext_req_roles)
 */
class ExtractionNormalizer
{
    private $pdo;
    private $debugLogs = [];

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Processa tutte le estrazioni per un job e popola le tabelle normalizzate
     * 
     * @param int $jobId ID del job da processare
     */
    public function processAll(int $jobId): void
    {
        $this->addDebugLog("ExtractionNormalizer: Inizio processamento job {$jobId}");

        // Recupera tutte le estrazioni per questo job
        $stmt = $this->pdo->prepare("
            SELECT id, type_code, value_text, value_json 
            FROM ext_extractions 
            WHERE job_id = :job_id
        ");
        $stmt->execute([':job_id' => $jobId]);
        $extractions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($extractions)) {
            $this->addDebugLog("ExtractionNormalizer: Nessuna estrazione trovata per job {$jobId}");
            return;
        }

        $this->addDebugLog("ExtractionNormalizer: Trovate " . count($extractions) . " estrazioni per job {$jobId}");

        // Raggruppa estrazioni per type_code
        $byType = [];
        foreach ($extractions as $ext) {
            $typeCode = $ext['type_code'] ?? '';
            if (!isset($byType[$typeCode])) {
                $byType[$typeCode] = [];
            }
            
            $valueJson = $ext['value_json'];
            if (is_string($valueJson)) {
                $valueJson = json_decode($valueJson, true);
            }
            
            $byType[$typeCode][] = [
                'id' => $ext['id'],
                'type_code' => $typeCode,
                'value_text' => $ext['value_text'],
                'value_json' => $valueJson,
            ];
        }

        // Processa documentazione richiesta
        if (isset($byType['documentazione_richiesta_tecnica'])) {
            $this->processDocumentazioneRichiesta($jobId, $byType['documentazione_richiesta_tecnica']);
        }

        // Processa fatturato globale
        if (isset($byType['fatturato_globale_n_minimo_anni'])) {
            $this->processFatturatoGlobale($jobId, $byType['fatturato_globale_n_minimo_anni']);
        }

        // Processa requisiti economici
        if (isset($byType['requisiti_di_capacita_economica_finanziaria'])) {
            $this->processRequisitiEconomici($jobId, $byType['requisiti_di_capacita_economica_finanziaria']);
        }

        // Processa requisiti ruoli
        if (isset($byType['requisiti_idoneita_professionale_gruppo_lavoro'])) {
            $this->processRequisitiRuoli($jobId, $byType['requisiti_idoneita_professionale_gruppo_lavoro']);
        }

        $this->addDebugLog("ExtractionNormalizer: Processamento job {$jobId} completato");
    }

    /**
     * Processa documentazione richiesta tecnica
     */
    private function processDocumentazioneRichiesta(int $jobId, array $extractions): void
    {
        $this->addDebugLog("ExtractionNormalizer: Processamento documentazione_richiesta_tecnica per job {$jobId}");
        // Implementazione semplificata - la logica completa è in GareService
        // Questa è una versione stub che può essere estesa
    }

    /**
     * Processa fatturato globale
     */
    private function processFatturatoGlobale(int $jobId, array $extractions): void
    {
        $this->addDebugLog("ExtractionNormalizer: Processamento fatturato_globale_n_minimo_anni per job {$jobId}");
        // Implementazione semplificata - la logica completa è in GareService
        // Questa è una versione stub che può essere estesa
    }

    /**
     * Processa requisiti economici
     */
    private function processRequisitiEconomici(int $jobId, array $extractions): void
    {
        $this->addDebugLog("ExtractionNormalizer: Processamento requisiti_di_capacita_economica_finanziaria per job {$jobId}");
        // Implementazione semplificata - la logica completa è in GareService
        // Questa è una versione stub che può essere estesa
    }

    /**
     * Processa requisiti ruoli
     */
    private function processRequisitiRuoli(int $jobId, array $extractions): void
    {
        $this->addDebugLog("ExtractionNormalizer: Processamento requisiti_idoneita_professionale_gruppo_lavoro per job {$jobId}");
        // Implementazione semplificata - la logica completa è in GareService
        // Questa è una versione stub che può essere estesa
    }

    /**
     * Aggiunge un log di debug
     */
    private function addDebugLog(string $message): void
    {
        $this->debugLogs[] = date('Y-m-d H:i:s') . ' - ' . $message;
        error_log("ExtractionNormalizer: " . $message);
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
}