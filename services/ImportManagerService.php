<?php
namespace Services;

if (!defined('HostDbDataConnector')) {
    define('HostDbDataConnector', true);
}

// Includi SimpleXLSX per leggere file Excel
if (!class_exists('SimpleXLSX')) {
    require_once __DIR__ . '/../IntLibs/SimpleXLSX/SimpleXLSX.php';
}

/**
 * Classe per la lettura robusta di file CSV e XLSX
 * Garantisce sempre la corrispondenza posizionale tra headers e righe
 */
class FileReader
{
    /**
     * Legge un file e restituisce headers e righe normalizzate
     * @param string $filepath Path al file
     * @param string $filename Nome del file
     * @param string|null $mimeType MIME type del file
     * @return array ['headers' => array, 'rows' => array, 'error' => string|null]
     */
    public static function readFile($filepath, $filename, $mimeType = null)
    {
        $fileType = self::detectFileType($filename, $mimeType);

        if ($fileType === 'xlsx') {
            return self::readXLSX($filepath);
        } else {
            return self::readCSV($filepath);
        }
    }

    /**
     * Rileva il tipo di file
     */
    private static function detectFileType($filename, $mimeType = null)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === 'xlsx' || $ext === 'xls') {
            return 'xlsx';
        }

        if (
            $mimeType && (
                strpos($mimeType, 'spreadsheet') !== false ||
                strpos($mimeType, 'excel') !== false ||
                $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            )
        ) {
            return 'xlsx';
        }

        return 'csv';
    }

    /**
     * Legge un file CSV con normalizzazione garantita
     */
    private static function readCSV($filepath)
    {
        $delimiter = self::detectDelimiter($filepath);
        $handle = fopen($filepath, 'r');

        if (!$handle) {
            return ['headers' => [], 'rows' => [], 'error' => 'Impossibile aprire il file'];
        }

        $headers = fgetcsv($handle, 0, $delimiter);
        if ($headers === false || empty($headers)) {
            fclose($handle);
            return ['headers' => [], 'rows' => [], 'error' => 'File senza header valido'];
        }

        $headers = array_map([self::class, 'toUtf8'], $headers);
        $headerCount = count($headers);

        $rows = [];
        $lineNumber = 2; // Header è riga 1

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row = array_map([self::class, 'toUtf8'], $row);

            // CRITICO: Normalizza sempre per avere headerCount colonne
            // Questo garantisce che ogni colonna corrisponda sempre alla stessa posizione
            $normalizedRow = [];
            for ($i = 0; $i < $headerCount; $i++) {
                // Se la cella esiste, prendila (anche se vuota)
                // Se non esiste (celle vuote alla fine), metti stringa vuota
                $normalizedRow[$i] = isset($row[$i]) ? $row[$i] : '';
            }

            $rows[] = [
                'data' => $normalizedRow,
                'line' => $lineNumber
            ];
            $lineNumber++;
        }

        fclose($handle);
        return ['headers' => $headers, 'rows' => $rows, 'error' => null];
    }

    /**
     * Legge un file XLSX con normalizzazione garantita
     */
    private static function readXLSX($filepath)
    {
        $xlsx = new \SimpleXLSX($filepath);

        if (!$xlsx->isLoaded()) {
            return ['headers' => [], 'rows' => [], 'error' => 'Impossibile caricare il file XLSX'];
        }

        $allRows = $xlsx->rows();
        if (empty($allRows)) {
            return ['headers' => [], 'rows' => [], 'error' => 'File XLSX vuoto'];
        }

        // Prima riga = headers
        $headers = array_map([self::class, 'toUtf8'], array_shift($allRows));
        $headerCount = count($headers);

        $rows = [];
        $lineNumber = 2;

        foreach ($allRows as $row) {
            // CRITICO: NON usare array_values() perché distrugge gli indici delle celle!
            // Se SimpleXLSX ritorna [0 => 'A', 2 => 'C'] (cella B vuota), array_values()
            // lo convertirebbe in [0 => 'A', 1 => 'C'] causando shift dei dati!

            // Normalizza sempre per avere headerCount colonne mantenendo le posizioni originali
            $normalizedRow = [];
            for ($i = 0; $i < $headerCount; $i++) {
                if (isset($row[$i])) {
                    // Converti in UTF8 e gestisci null/vuoto
                    $val = $row[$i];
                    $val = self::toUtf8($val);
                    $normalizedRow[$i] = ($val === null || $val === '') ? '' : (string) $val;
                } else {
                    // Cella mancante o vuota -> stringa vuota (preserva posizione!)
                    $normalizedRow[$i] = '';
                }
            }

            $rows[] = [
                'data' => $normalizedRow,
                'line' => $lineNumber
            ];
            $lineNumber++;
        }

        return ['headers' => $headers, 'rows' => $rows, 'error' => null];
    }

    /**
     * Rileva il delimitatore CSV
     */
    private static function detectDelimiter($filepath)
    {
        $delimiters = [",", ";", "\t", "|"];
        $results = [];
        $handle = fopen($filepath, 'r');
        $line = fgets($handle);
        fclose($handle);

        foreach ($delimiters as $delimiter) {
            $fields = str_getcsv($line, $delimiter);
            $results[$delimiter] = count($fields);
        }

        arsort($results);
        return key($results);
    }

    /**
     * Converte stringa a UTF-8
     */
    private static function toUtf8($string)
    {
        if ($string === null || !is_string($string)) {
            return $string;
        }
        if ($string === '') {
            return $string;
        }
        if (!mb_check_encoding($string, 'UTF-8')) {
            return mb_convert_encoding($string, 'UTF-8', 'Windows-1252');
        }
        return $string;
    }
}

/**
 * Classe per il mapping posizionale affidabile tra file e database
 */
class DataMapper
{
    private $headers = [];
    private $normalizedHeaders = [];
    private $headerToIndex = [];
    private $dbFields = [];
    private $fieldToHeaderIndex = [];

    /**
     * Inizializza il mapper con headers del file e campi del DB
     * @param array $fileHeaders Headers del file
     * @param array $dbFields Campi del database
     * @param array|null $customMapping Mapping personalizzato [dbField => fileHeaderIndex]
     */
    public function __construct($fileHeaders, $dbFields, $customMapping = null)
    {
        $this->headers = $fileHeaders;
        $this->dbFields = $dbFields;
        $this->normalizeHeaders();

        if ($customMapping !== null && is_array($customMapping)) {
            $this->fieldToHeaderIndex = $customMapping;
        } else {
            $this->buildMapping();
        }
    }

    /**
     * Normalizza gli headers del file
     */
    private function normalizeHeaders()
    {
        foreach ($this->headers as $idx => $header) {
            $normalized = preg_replace('/[^a-zA-Z0-9_]+/', '_', $header);
            $normalized = preg_replace('/_+/', '_', $normalized);
            $normalized = trim($normalized, '_');
            $normalizedKey = strtolower($normalized);

            $this->normalizedHeaders[$idx] = $normalizedKey;
            $this->headerToIndex[$normalizedKey] = $idx;
        }
    }

    /**
     * Costruisce il mapping tra campi DB e indici degli headers
     */
    private function buildMapping()
    {
        foreach ($this->dbFields as $field) {
            $field_lc = strtolower(trim($field));
            $field_normalized = preg_replace('/[^a-zA-Z0-9_]/', '', $field_lc);

            $matchedIndex = null;

            // 1. Match esatto case-insensitive
            if (isset($this->headerToIndex[$field_lc])) {
                $matchedIndex = $this->headerToIndex[$field_lc];
            }
            // 2. Match normalizzato (senza caratteri speciali)
            elseif (isset($this->headerToIndex[$field_normalized])) {
                $matchedIndex = $this->headerToIndex[$field_normalized];
            }
            // 3. Match fuzzy (rimuovi anche underscore)
            else {
                $field_fuzzy = preg_replace('/[^a-zA-Z0-9]/', '', $field_lc);
                foreach ($this->headerToIndex as $normHeader => $idx) {
                    $header_fuzzy = preg_replace('/[^a-zA-Z0-9]/', '', $normHeader);
                    if ($header_fuzzy === $field_fuzzy) {
                        $matchedIndex = $idx;
                        break;
                    }
                }
            }

            if ($matchedIndex !== null) {
                $this->fieldToHeaderIndex[$field] = $matchedIndex;
            }
        }
    }

    /**
     * Mappa una riga del file ai valori per i campi del DB
     * @param array $rowData Array normalizzato con headerCount elementi
     * @return array Associativo: field => value
     */
    public function mapRow($rowData)
    {
        $mapped = [];

        foreach ($this->dbFields as $field) {
            $value = null;

            // Usa il mapping pre-calcolato
            if (isset($this->fieldToHeaderIndex[$field])) {
                $headerIndex = $this->fieldToHeaderIndex[$field];
                if (isset($rowData[$headerIndex])) {
                    $value = $rowData[$headerIndex];
                }
            }

            // Se non trovato, il valore rimane null
            $mapped[$field] = $value;
        }

        return $mapped;
    }

    /**
     * Restituisce gli headers normalizzati
     */
    public function getNormalizedHeaders()
    {
        return $this->normalizedHeaders;
    }
}

/**
 * Classe per processare e validare i valori
 */
class ValueProcessor
{
    private $fieldInfo = [];

    public function __construct($fieldInfo)
    {
        $this->fieldInfo = $fieldInfo;
    }

    /**
     * Pulisce e normalizza un valore
     */
    public function cleanValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $cleaned = trim($value);

            // Rimuovi CDATA se presente
            if (preg_match('/^<!\[CDATA\[(.*?)\]\]>$/i', $cleaned, $matches)) {
                $cleaned = trim($matches[1]);
            }

            // Stringa vuota dopo pulizia = NULL
            return ($cleaned === '') ? null : $cleaned;
        }

        return $value;
    }

    /**
     * Processa un valore per un campo specifico del DB
     */
    public function processValue($field, $rawValue, $fieldInfo)
    {
        $cleaned = $this->cleanValue($rawValue);

        // Se è null o vuoto, gestisci in base alle caratteristiche del campo
        if ($cleaned === null) {
            return $this->handleNullValue($field, $fieldInfo);
        }

        // Converti date italiane in formato MySQL se il campo è di tipo date/datetime
        if ($fieldInfo) {
            $type = strtolower($fieldInfo['type']);
            if (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
                // Gestisci il valore 0 (intero o stringa) come NULL per i campi data
                if (
                    $cleaned === 0 || $cleaned === '0' || $cleaned === 0.0 || $cleaned === '0.0' ||
                    (is_numeric($cleaned) && floatval($cleaned) == 0)
                ) {
                    return $this->handleNullValue($field, $fieldInfo);
                }

                if (is_string($cleaned)) {
                    $converted = self::convertItalianDateToMySQL($cleaned, $type);
                    // Se la conversione restituisce CURRENT_TIMESTAMP, mantienilo così
                    if ($converted === 'CURRENT_TIMESTAMP') {
                        return 'CURRENT_TIMESTAMP';
                    }
                    $cleaned = $converted;
                    // Se la conversione ha restituito NULL, gestisci in base alle caratteristiche del campo
                    if ($cleaned === null) {
                        return $this->handleNullValue($field, $fieldInfo);
                    }
                }
            }
        }

        // Valida tipo numerico INT
        if ($fieldInfo && strpos(strtolower($fieldInfo['type']), 'int') !== false) {
            if (!is_numeric($cleaned) && $cleaned !== '0') {
                // Valore non numerico per campo numerico - usa handleNullValue
                return $this->handleNullValue($field, $fieldInfo);
            }
            $result = is_numeric($cleaned) ? $cleaned : null;
            if ($result === null) {
                return $this->handleNullValue($field, $fieldInfo);
            }

            // Verifica range per evitare "Out of range" error
            $type = strtolower($fieldInfo['type']);
            $numVal = floatval($result);
            $maxInt = PHP_INT_MAX;
            $minInt = PHP_INT_MIN;

            // Limiti per tipi INT comuni
            if (strpos($type, 'tinyint') !== false) {
                $maxInt = 127;
                $minInt = -128;
                if (strpos($type, 'unsigned') !== false) {
                    $maxInt = 255;
                    $minInt = 0;
                }
            } elseif (strpos($type, 'smallint') !== false) {
                $maxInt = 32767;
                $minInt = -32768;
                if (strpos($type, 'unsigned') !== false) {
                    $maxInt = 65535;
                    $minInt = 0;
                }
            } elseif (strpos($type, 'mediumint') !== false) {
                $maxInt = 8388607;
                $minInt = -8388608;
                if (strpos($type, 'unsigned') !== false) {
                    $maxInt = 16777215;
                    $minInt = 0;
                }
            } elseif (strpos($type, 'bigint') === false) {
                // INT normale (non BIGINT)
                $maxInt = 2147483647;
                $minInt = -2147483648;
                if (strpos($type, 'unsigned') !== false) {
                    $maxInt = 4294967295;
                    $minInt = 0;
                }
            }

            // Se fuori range, usa handleNullValue
            if ($numVal > $maxInt || $numVal < $minInt) {
                return $this->handleNullValue($field, $fieldInfo);
            }

            return $result;
        }

        // Valida tipo numerico DECIMAL/FLOAT/DOUBLE/NUMERIC
        if ($fieldInfo && is_string($cleaned)) {
            $type = strtolower($fieldInfo['type']);
            if (
                strpos($type, 'decimal') !== false || strpos($type, 'float') !== false ||
                strpos($type, 'double') !== false || strpos($type, 'numeric') !== false
            ) {
                // Pulisci il valore numerico: rimuovi spazi, gestisci separatori migliaia e decimali
                $cleaned = trim($cleaned);
                // Rimuovi spazi
                $cleaned = str_replace(' ', '', $cleaned);
                // Rimuovi caratteri non numerici tranne punto, virgola e segno meno
                $cleaned = preg_replace('/[^0-9.,\-]/', '', $cleaned);

                // Gestisci separatori migliaia e decimali
                // Se ci sono sia punti che virgole, determina quale è il separatore decimale
                $hasComma = strpos($cleaned, ',') !== false;
                $hasDot = strpos($cleaned, '.') !== false;

                if ($hasComma && $hasDot) {
                    // Entrambi presenti: l'ultimo è il separatore decimale
                    $lastComma = strrpos($cleaned, ',');
                    $lastDot = strrpos($cleaned, '.');
                    if ($lastComma > $lastDot) {
                        // Virgola è il separatore decimale (formato italiano: 1.234,56)
                        $cleaned = str_replace('.', '', $cleaned); // Rimuovi punti (separatori migliaia)
                        $cleaned = str_replace(',', '.', $cleaned); // Converti virgola in punto
                    } else {
                        // Punto è il separatore decimale (formato inglese: 1,234.56)
                        $cleaned = str_replace(',', '', $cleaned); // Rimuovi virgole (separatori migliaia)
                    }
                } elseif ($hasComma && !$hasDot) {
                    // Solo virgola: potrebbe essere separatore migliaia o decimale
                    // Se ci sono più virgole, sono separatori migliaia, altrimenti è decimale
                    $commaCount = substr_count($cleaned, ',');
                    if ($commaCount > 1) {
                        // Più virgole = separatori migliaia (formato: 1,234,567)
                        $cleaned = str_replace(',', '', $cleaned);
                    } else {
                        // Una virgola = separatore decimale (formato italiano: 1234,56)
                        $cleaned = str_replace(',', '.', $cleaned);
                    }
                } elseif ($hasDot && !$hasComma) {
                    // Solo punto: potrebbe essere separatore migliaia o decimale
                    // Se ci sono più punti, sono separatori migliaia, altrimenti è decimale
                    $dotCount = substr_count($cleaned, '.');
                    if ($dotCount > 1) {
                        // Più punti = separatori migliaia (formato: 1.234.567)
                        $cleaned = str_replace('.', '', $cleaned);
                    }
                    // Un punto = separatore decimale (formato inglese: 1234.56), lo manteniamo
                }

                // Se è vuoto dopo la pulizia, restituisci null
                if ($cleaned === '' || $cleaned === '-' || $cleaned === '.') {
                    return null;
                }
                // Valida che sia numerico
                if (!is_numeric($cleaned)) {
                    return null;
                }
                // Restituisci il valore numerico pulito
                return $cleaned;
            }
        }

        // Tronca se necessario
        if ($fieldInfo && is_string($cleaned)) {
            $cleaned = $this->truncateValue($cleaned, $fieldInfo);
        }

        return $cleaned;
    }

    /**
     * Converte una data italiana in formato MySQL (YYYY-MM-DD o YYYY-MM-DD HH:MM:SS)
     * Gestisce: dd/mm/yyyy, dd-mm-yyyy, dd.mm.yyyy, date già in formato MySQL, timestamp Excel
     * Formati supportati: "19/11/2025, 12:29", "19/11/2025 12:29", "19/11/2025"
     */
    private static function convertItalianDateToMySQL($dateValue, $fieldType)
    {
        if (empty($dateValue)) {
            return $dateValue;
        }

        // Gestisci il valore "0" come NULL (spesso usato in Excel per date vuote)
        // Normalizza prima di controllare
        $checkValue = is_string($dateValue) ? trim($dateValue) : $dateValue;
        if (
            $checkValue === '0' || $checkValue === 0 || $checkValue === '0.0' || $checkValue === 0.0 ||
            $checkValue === '' || (is_numeric($checkValue) && floatval($checkValue) == 0)
        ) {
            return null;
        }

        // Gestisci valori speciali SQL (funzioni MySQL)
        if (is_string($dateValue)) {
            $dateValueLower = strtolower(trim($dateValue));
            if (
                $dateValueLower === 'current_timestamp()' || $dateValueLower === 'now()' ||
                $dateValueLower === 'current_timestamp' || $dateValueLower === 'now' ||
                $dateValueLower === 'curdate()' || $dateValueLower === 'curtime()'
            ) {
                // Restituisci come funzione SQL, non come stringa
                return 'CURRENT_TIMESTAMP';
            }
        }

        // Determina se il campo supporta l'ora
        $isDateTime = (strpos($fieldType, 'datetime') !== false || strpos($fieldType, 'timestamp') !== false);
        $isTime = (strpos($fieldType, 'time') !== false);
        $needsTime = $isDateTime || $isTime;

        // Se è numerico, potrebbe essere un timestamp Excel
        if (is_numeric($dateValue)) {
            $numValue = (float) $dateValue;
            // Excel timestamp: giorni dal 1 gennaio 1900 (valori tipici > 1)
            if ($numValue > 1 && $numValue < 1000000) {
                try {
                    // Excel timestamp: base è 30 dicembre 1899 per Excel Windows
                    $baseTimestamp = mktime(0, 0, 0, 12, 30, 1899);
                    $timestamp = (int) ($baseTimestamp + ($numValue * 86400));
                    $dateObj = new \DateTime();
                    $dateObj->setTimestamp($timestamp);
                    if ($needsTime) {
                        return $dateObj->format('Y-m-d H:i:s');
                    }
                    return $dateObj->format('Y-m-d');
                } catch (\Exception $e) {
                    // Se fallisce, continua con il parsing normale
                }
            }
            // Se è un timestamp Unix normale, convertilo
            if ($numValue > 946684800) { // Dopo 2000-01-01
                $dateObj = new \DateTime();
                $dateObj->setTimestamp((int) round($numValue));
                if ($needsTime) {
                    return $dateObj->format('Y-m-d H:i:s');
                }
                return $dateObj->format('Y-m-d');
            }
        }

        if (!is_string($dateValue)) {
            return $dateValue;
        }

        $dateValue = trim($dateValue);

        // Se è già in formato MySQL (YYYY-MM-DD o YYYY-MM-DD HH:MM:SS), restituiscilo così
        if (preg_match('/^\d{4}-\d{2}-\d{2}(\s+\d{2}:\d{2}(:\d{2})?)?$/', $dateValue)) {
            // Se il campo non supporta l'ora ma la data ha l'ora, rimuovila
            if (!$needsTime && preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/', $dateValue)) {
                return substr($dateValue, 0, 10);
            }
            return $dateValue;
        }

        // Formato italiano con virgola: "19/11/2025, 12:29" o "19/11/2025, 12:29:45"
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})\s*,\s*(\d{1,2}):(\d{1,2})(:(\d{1,2}))?$/', $dateValue, $matches)) {
            $dd = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $mm = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $yyyy = $matches[3];
            $hh = str_pad($matches[4], 2, '0', STR_PAD_LEFT);
            $ii = str_pad($matches[5], 2, '0', STR_PAD_LEFT);
            $ss = isset($matches[7]) ? str_pad($matches[7], 2, '0', STR_PAD_LEFT) : '00';

            // Gestisci anni a 2 cifre
            if (strlen($yyyy) === 2) {
                $year = (int) $yyyy;
                $yyyy = ($year >= 70) ? '19' . $yyyy : '20' . $yyyy;
            }

            // Valida la data
            $month = (int) $mm;
            $day = (int) $dd;
            $year = (int) $yyyy;

            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && $year >= 1900 && $year <= 2100) {
                if (function_exists('checkdate') && checkdate($month, $day, $year)) {
                    $mysqlDate = "$yyyy-$mm-$dd";
                    if ($needsTime) {
                        $mysqlDate .= " $hh:$ii:$ss";
                    }
                    return $mysqlDate;
                }
            }
        }

        // Formato italiano: dd/mm/yyyy o dd-mm-yyyy o dd.mm.yyyy (con o senza ora)
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})(\s+(\d{1,2}):(\d{1,2})(:(\d{1,2}))?)?$/', $dateValue, $matches)) {
            $dd = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $mm = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $yyyy = $matches[3];

            // Gestisci anni a 2 cifre
            if (strlen($yyyy) === 2) {
                $year = (int) $yyyy;
                $yyyy = ($year >= 70) ? '19' . $yyyy : '20' . $yyyy;
            }

            // Valida la data usando checkdate
            $month = (int) $mm;
            $day = (int) $dd;
            $year = (int) $yyyy;

            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 && $year >= 1900 && $year <= 2100) {
                if (function_exists('checkdate') && checkdate($month, $day, $year)) {
                    $mysqlDate = "$yyyy-$mm-$dd";

                    // Se c'è anche l'ora e il campo la supporta, aggiungila
                    if ($needsTime && isset($matches[5]) && isset($matches[6])) {
                        $hh = str_pad($matches[5], 2, '0', STR_PAD_LEFT);
                        $ii = str_pad($matches[6], 2, '0', STR_PAD_LEFT);
                        $ss = isset($matches[8]) ? str_pad($matches[8], 2, '0', STR_PAD_LEFT) : '00';
                        $mysqlDate .= " $hh:$ii:$ss";
                    }

                    return $mysqlDate;
                }
            }
        }

        // Prova con strtotime come fallback (gestisce molti formati)
        $timestamp = @strtotime($dateValue);
        if ($timestamp !== false && $timestamp > 0) {
            if ($needsTime) {
                return date('Y-m-d H:i:s', $timestamp);
            }
            return date('Y-m-d', $timestamp);
        }

        // Se non riesce a convertire, restituisci NULL invece del valore originale
        // per evitare errori SQL
        return null;
    }

    /**
     * Gestisce valori NULL in base alle caratteristiche del campo
     */
    private function handleNullValue($field, $fieldInfo)
    {
        if (!$fieldInfo) {
            return null;
        }

        // Se il campo accetta NULL, restituisci null
        if ($fieldInfo['null']) {
            return null;
        }

        // Se ha un default, usa quello
        if ($fieldInfo['default'] !== null) {
            return $fieldInfo['default'];
        }

        // Se è una chiave primaria, non possiamo impostare un valore
        if ($fieldInfo['key'] === 'PRI') {
            return null;
        }

        // Gestione speciale per azienda_id
        if ($field === 'azienda_id') {
            return '1';
        }

        // Gestione speciale per campo "estero" (default a 0 = non estero)
        if ($field === 'estero') {
            return '0';
        }

        // Gestione speciale per campo "allegati" (default a stringa vuota o 0)
        if ($field === 'allegati') {
            return '';
        }

        // Valore di default basato sul tipo
        $type = strtolower($fieldInfo['type']);

        if (
            strpos($type, 'int') !== false || strpos($type, 'decimal') !== false ||
            strpos($type, 'float') !== false || strpos($type, 'double') !== false
        ) {
            return (strpos($field, 'id') !== false) ? '1' : '0';
        }

        if (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
            if (strpos($field, 'data') !== false || strpos($field, 'date') !== false) {
                return date('Y-m-d H:i:s');
            }
            return null; // Date obbligatorie senza default devono essere gestite diversamente
        }

        // Per stringhe, stringa vuota invece di null per NOT NULL
        return '';
    }

    /**
     * Tronca un valore in base alla lunghezza massima della colonna
     */
    private function truncateValue($value, $fieldInfo)
    {
        if (!isset($fieldInfo['type']) || !is_string($value)) {
            return $value;
        }

        $type = strtolower($fieldInfo['type']);

        // Gestisci VARCHAR e CHAR
        if (preg_match('/^(varchar|char)\((\d+)\)/i', $type, $matches)) {
            $maxBytes = (int) $matches[2];

            // MySQL VARCHAR conta i BYTE, non i caratteri
            // Per sicurezza, tronchiamo considerando che ogni carattere può occupare fino a 4 byte in UTF-8
            // Ma per caratteri comuni italiani (latin1/utf8mb3) occupano 1-3 byte

            // Controlla la lunghezza in byte
            $currentBytes = strlen($value);

            if ($currentBytes > $maxBytes) {
                // Tronca considerando i byte, non i caratteri
                // Usa un margine di sicurezza per evitare problemi con caratteri multibyte
                $safeBytes = max(1, $maxBytes - 3); // Margine di 3 byte per sicurezza

                // Tronca byte per byte fino a raggiungere il limite
                $truncated = '';
                $byteCount = 0;
                $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

                foreach ($chars as $char) {
                    $charBytes = strlen($char);
                    if ($byteCount + $charBytes > $safeBytes) {
                        break;
                    }
                    $truncated .= $char;
                    $byteCount += $charBytes;
                }

                return $truncated;
            }
        } elseif (strpos($type, 'text') !== false) {
            // Per TEXT, limita a 50000 caratteri per sicurezza
            $maxLength = 50000;
            $currentLength = mb_strlen($value, 'UTF-8');

            if ($currentLength > $maxLength) {
                return mb_substr($value, 0, $maxLength, 'UTF-8');
            }
        }

        return $value;
    }
}

class ImportManagerService
{
    public static function getTables()
    {
        if (!userHasPermission('view_import_manager')) {
            return ['error' => 'Accesso negato'];
        }
        $mysqli = new \mysqli(DB_SERVER, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_errno) {
            return ['error' => 'Connessione DB fallita'];
        }
        $tables = [];
        $res = $mysqli->query("show tables");
        while ($row = $res->fetch_array()) {
            $tables[] = $row[0];
        }
        return ['tables' => $tables];
    }

    /**
     * Suggerisce il mapping automatico tra colonne file e campi DB
     */
    public static function suggestMapping($input, $files)
    {
        if (!userHasPermission('view_import_manager')) {
            return ['error' => 'Accesso negato'];
        }

        $mysqli = new \mysqli(DB_SERVER, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_errno) {
            return ['error' => 'Connessione DB fallita'];
        }

        $table = isset($input['table']) ? strtolower($mysqli->real_escape_string($input['table'])) : '';
        if (!$table) {
            return ['error' => 'Tabella non specificata'];
        }

        if (!isset($files['datafile']) || $files['datafile']['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'File non caricato correttamente'];
        }

        $filepath = $files['datafile']['tmp_name'];
        $filename = $files['datafile']['name'];
        $mimeType = $files['datafile']['type'] ?? null;

        $fileData = FileReader::readFile($filepath, $filename, $mimeType);
        if ($fileData['error']) {
            return ['error' => $fileData['error']];
        }

        $fileHeaders = $fileData['headers'];
        if (empty($fileHeaders)) {
            return ['error' => 'File senza header'];
        }

        // Ottieni campi DB con info
        $dbFields = [];
        $fieldInfo = [];
        $desc = $mysqli->query("describe `$table`");
        while ($col = $desc->fetch_assoc()) {
            $fieldName = strtolower($col['Field']);
            $dbFields[] = $fieldName;
            $fieldInfo[$fieldName] = [
                'type' => $col['Type'],
                'null' => strtoupper($col['Null']) === 'YES',
                'key' => $col['Key']
            ];
        }

        // Calcola suggerimenti per ogni campo DB
        $suggestions = [];
        foreach ($dbFields as $dbField) {
            $bestMatch = self::findBestMatch($dbField, $fileHeaders, $fieldInfo[$dbField]);
            if ($bestMatch) {
                $suggestions[$dbField] = $bestMatch;
            }
        }

        // Calcola anche suggerimenti inversi (da colonna file a campo DB)
        $reverseSuggestions = [];
        foreach ($fileHeaders as $idx => $fileHeader) {
            $bestMatch = self::findBestMatchForFileColumn($fileHeader, $dbFields, $fieldInfo);
            if ($bestMatch) {
                $reverseSuggestions[$idx] = [
                    'file_header' => $fileHeader,
                    'suggested_db_field' => $bestMatch['field'],
                    'confidence' => $bestMatch['confidence'],
                    'match_type' => $bestMatch['match_type']
                ];
            }
        }

        return [
            'file_headers' => $fileHeaders,
            'db_fields' => $dbFields,
            'field_info' => $fieldInfo,
            'suggestions' => $suggestions,
            'reverse_suggestions' => $reverseSuggestions
        ];
    }

    /**
     * Trova il miglior match per un campo DB tra le colonne del file
     */
    private static function findBestMatch($dbField, $fileHeaders, $fieldInfo)
    {
        $dbField_lc = strtolower(trim($dbField));
        $dbField_normalized = preg_replace('/[^a-zA-Z0-9_]/', '', $dbField_lc);
        $dbField_fuzzy = preg_replace('/[^a-zA-Z0-9]/', '', $dbField_lc);

        $bestMatch = null;
        $bestScore = 0;
        $bestType = 'none';

        foreach ($fileHeaders as $idx => $fileHeader) {
            $fileHeader_lc = strtolower(trim($fileHeader));
            $fileHeader_normalized = preg_replace('/[^a-zA-Z0-9_]/', '', $fileHeader_lc);
            $fileHeader_fuzzy = preg_replace('/[^a-zA-Z0-9]/', '', $fileHeader_lc);

            $score = 0;
            $matchType = 'none';

            // 1. Match esatto case-insensitive (score 100)
            if ($fileHeader_lc === $dbField_lc) {
                $score = 100;
                $matchType = 'exact';
            }
            // 2. Match normalizzato (score 90)
            elseif ($fileHeader_normalized === $dbField_normalized) {
                $score = 90;
                $matchType = 'normalized';
            }
            // 3. Match fuzzy (score 80)
            elseif ($fileHeader_fuzzy === $dbField_fuzzy) {
                $score = 80;
                $matchType = 'fuzzy';
            }
            // 4. Contiene (score basato su lunghezza)
            elseif (strpos($fileHeader_lc, $dbField_lc) !== false || strpos($dbField_lc, $fileHeader_lc) !== false) {
                $score = min(strlen($dbField_lc), strlen($fileHeader_lc)) * 2;
                $matchType = 'contains';
            }
            // 5. Similarità Levenshtein (score basato su distanza)
            else {
                $distance = levenshtein($fileHeader_lc, $dbField_lc);
                $maxLen = max(strlen($fileHeader_lc), strlen($dbField_lc));
                if ($maxLen > 0) {
                    $similarity = (1 - ($distance / $maxLen)) * 50;
                    if ($similarity > 30) {
                        $score = $similarity;
                        $matchType = 'similarity';
                    }
                }
            }

            // Bonus per campi ID
            if (strpos($dbField, 'id') !== false && strpos($fileHeader_lc, 'id') !== false) {
                $score += 10;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'file_header_index' => $idx,
                    'file_header' => $fileHeader,
                    'confidence' => min(100, $score),
                    'match_type' => $matchType
                ];
                $bestType = $matchType;
            }
        }

        return ($bestScore >= 30) ? $bestMatch : null;
    }

    /**
     * Trova il miglior match per una colonna file tra i campi DB
     */
    private static function findBestMatchForFileColumn($fileHeader, $dbFields, $fieldInfo)
    {
        $fileHeader_lc = strtolower(trim($fileHeader));
        $fileHeader_normalized = preg_replace('/[^a-zA-Z0-9_]/', '', $fileHeader_lc);
        $fileHeader_fuzzy = preg_replace('/[^a-zA-Z0-9]/', '', $fileHeader_lc);

        $bestMatch = null;
        $bestScore = 0;

        foreach ($dbFields as $dbField) {
            $dbField_lc = strtolower(trim($dbField));
            $dbField_normalized = preg_replace('/[^a-zA-Z0-9_]/', '', $dbField_lc);
            $dbField_fuzzy = preg_replace('/[^a-zA-Z0-9]/', '', $dbField_lc);

            $score = 0;
            $matchType = 'none';

            if ($fileHeader_lc === $dbField_lc) {
                $score = 100;
                $matchType = 'exact';
            } elseif ($fileHeader_normalized === $dbField_normalized) {
                $score = 90;
                $matchType = 'normalized';
            } elseif ($fileHeader_fuzzy === $dbField_fuzzy) {
                $score = 80;
                $matchType = 'fuzzy';
            } elseif (strpos($fileHeader_lc, $dbField_lc) !== false || strpos($dbField_lc, $fileHeader_lc) !== false) {
                $score = min(strlen($dbField_lc), strlen($fileHeader_lc)) * 2;
                $matchType = 'contains';
            } else {
                $distance = levenshtein($fileHeader_lc, $dbField_lc);
                $maxLen = max(strlen($fileHeader_lc), strlen($dbField_lc));
                if ($maxLen > 0) {
                    $similarity = (1 - ($distance / $maxLen)) * 50;
                    if ($similarity > 30) {
                        $score = $similarity;
                        $matchType = 'similarity';
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'field' => $dbField,
                    'confidence' => min(100, $score),
                    'match_type' => $matchType
                ];
            }
        }

        return ($bestScore >= 30) ? $bestMatch : null;
    }

    public static function previewFile($input, $files)
    {
        if (!userHasPermission('view_import_manager')) {
            return ['error' => 'Accesso negato'];
        }

        $mysqli = new \mysqli(DB_SERVER, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_errno) {
            return ['error' => 'Connessione DB fallita'];
        }

        $mode = $input['mode'] ?? 'insert';
        $table = isset($input['table']) ? strtolower($mysqli->real_escape_string($input['table'])) : '';
        $newTableName = isset($input['new_table_name']) ? strtolower($input['new_table_name']) : '';

        if ($mode === 'create_new') {
            if (!$newTableName) {
                return ['error' => 'Nome nuova tabella mancante'];
            }
        } else {
            if (!$table) {
                return ['error' => 'Tabella non specificata'];
            }
        }

        if (!isset($files['datafile']) || $files['datafile']['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'File non caricato correttamente'];
        }

        $filepath = $files['datafile']['tmp_name'];
        $filename = $files['datafile']['name'];
        $mimeType = $files['datafile']['type'] ?? null;

        $fileData = FileReader::readFile($filepath, $filename, $mimeType);

        if ($fileData['error']) {
            return ['error' => $fileData['error']];
        }

        $headers = $fileData['headers'];
        $allRows = $fileData['rows'];

        if (empty($headers)) {
            return ['error' => 'File senza header o formato non supportato'];
        }

        // Normalizza headers per preview
        $normalizedHeaders = [];
        foreach ($headers as $h) {
            $safe = preg_replace('/[^a-zA-Z0-9_]+/', '_', $h);
            $safe = preg_replace('/_+/', '_', $safe);
            $safe = trim($safe, '_');
            $normalizedHeaders[] = strtolower($safe);
        }

        $preview = [];
        $use_headers = $normalizedHeaders;
        $selected_indexes = [];

        if (!empty($input['selected_columns'])) {
            $selected_columns = array_map('strtolower', json_decode($input['selected_columns'], true));
            $use_headers = $selected_columns;

            foreach ($selected_columns as $sel_col) {
                $idx = array_search(strtolower($sel_col), $normalizedHeaders);
                if ($idx !== false) {
                    $selected_indexes[] = $idx;
                }
            }
        } else {
            $selected_indexes = array_keys($normalizedHeaders);
        }

        foreach ($allRows as $rowInfo) {
            $row = $rowInfo['data'];
            $filtered_row = [];
            foreach ($selected_indexes as $idx) {
                $filtered_row[] = isset($row[$idx]) ? $row[$idx] : null;
            }
            $preview[] = $filtered_row;
        }

        $fields = [];
        if ($mode === 'create_new') {
            $fields = $normalizedHeaders;
        } else {
            $desc = $mysqli->query("describe `$table`");
            while ($col = $desc->fetch_assoc()) {
                $fields[] = strtolower($col['Field']);
            }
        }

        return [
            'headers' => $normalizedHeaders,
            'preview' => $preview,
            'table_fields' => $fields
        ];
    }

    public static function doImport($input, $files)
    {
        try {
            if (!userHasPermission('view_import_manager')) {
                return ['error' => 'Accesso negato'];
            }

            $mysqli = new \mysqli(DB_SERVER, DB_USER, DB_PASS, DB_NAME);
            if ($mysqli->connect_errno) {
                return ['error' => 'Connessione DB fallita'];
            }

            // Abilita le eccezioni per mysqli
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            $table = isset($input['table']) ? $mysqli->real_escape_string($input['table']) : '';
            $mode = isset($input['mode']) ? $input['mode'] : 'insert';
            $key_field = isset($input['key_field']) ? trim($input['key_field']) : '';
            $update_fields = !empty($input['update_fields']) ? json_decode($input['update_fields'], true) : [];
            $skip_existing = isset($input['skip_existing']) && $input['skip_existing'] === '1';
            $csrf_token = isset($input['csrf_token']) ? $input['csrf_token'] : '';

            $selected_columns = [];
            $selected_rows = [];

            // Parse selected_rows per TUTTI i modi (non solo create_new)
            if (!empty($input['selected_rows'])) {
                $selected_rows = json_decode($input['selected_rows'], true);
            }

            if ($mode === 'create_new') {
                $newTableName = isset($input['new_table_name']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $input['new_table_name']) : '';
                $newTableName = strtolower($newTableName);
                if (!$newTableName || strlen($newTableName) < 3) {
                    return ['error' => 'Nome tabella non valido'];
                }
                $chk = $mysqli->query("SHOW TABLES LIKE '$newTableName'");
                if ($chk && $chk->num_rows > 0) {
                    return ['error' => 'Esiste già una tabella con questo nome'];
                }
                $table = $newTableName;

                if (!empty($input['selected_columns'])) {
                    $selected_columns = json_decode($input['selected_columns'], true);
                }
            }

            $table = strtolower($table);

            if (!$table || !$mode || !isset($files['datafile']) || $files['datafile']['error'] !== UPLOAD_ERR_OK) {
                return ['error' => 'Dati mancanti o file non valido'];
            }

            if (empty($csrf_token) || $csrf_token !== ($_SESSION['CSRFtoken'] ?? '')) {
                return ['error' => 'Token CSRF non valido'];
            }

            $filepath = $files['datafile']['tmp_name'];
            $filename = $files['datafile']['name'];
            $mimeType = $files['datafile']['type'] ?? null;

            // Leggi il file
            $fileData = FileReader::readFile($filepath, $filename, $mimeType);

            if ($fileData['error']) {
                return ['error' => $fileData['error']];
            }

            $headers = $fileData['headers'];
            $allRows = $fileData['rows'];

            if (empty($headers)) {
                return ['error' => 'File senza header o formato non supportato'];
            }

            // Crea tabella se necessario
            if ($mode === 'create_new') {
                $use_headers = !empty($selected_columns) ? $selected_columns : $headers;
                $result = self::createTable($mysqli, $table, $use_headers);
                if (isset($result['error'])) {
                    return $result;
                }
                $headers = $use_headers;
            }

            // Ottieni informazioni sui campi del DB
            $dbFields = [];
            $fieldInfo = [];
            $desc = $mysqli->query("describe `$table`");
            while ($col = $desc->fetch_assoc()) {
                $fieldName = strtolower($col['Field']);
                $dbFields[] = $fieldName;
                $fieldInfo[$fieldName] = [
                    'null' => strtoupper($col['Null']) === 'YES',
                    'default' => $col['Default'],
                    'type' => $col['Type'],
                    'key' => $col['Key']
                ];
            }

            // Se overwrite, svuota la tabella
            if ($mode === 'overwrite') {
                $mysqli->query("truncate table `$table`");
            }

            // Gestione modalità create_new con filtered_preview
            if ($mode === 'create_new' && !empty($input['filtered_preview'])) {
                return self::importFilteredPreview($mysqli, $table, $input['filtered_preview'], $dbFields, $fieldInfo);
            }

            // Ottieni mapping personalizzato se presente
            $customMapping = null;
            if (!empty($input['column_mapping'])) {
                $customMapping = json_decode($input['column_mapping'], true);
                // Converti da formato [dbField => fileHeader] a [dbField => fileHeaderIndex]
                if ($customMapping && is_array($customMapping)) {
                    $convertedMapping = [];
                    foreach ($customMapping as $dbField => $fileHeader) {
                        $fileIndex = array_search($fileHeader, $headers);
                        if ($fileIndex !== false) {
                            $convertedMapping[strtolower($dbField)] = $fileIndex;
                        }
                    }
                    $customMapping = $convertedMapping;
                }
            }

            // Crea mapper e processor
            $mapper = new DataMapper($headers, $dbFields, $customMapping);
            $processor = new ValueProcessor($fieldInfo);

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $log = '';

            // Processa ogni riga
            $headerCount = count($headers);
            foreach ($allRows as $rowInfo) {
                $rowData = $rowInfo['data'];
                $lineNumber = $rowInfo['line'];

                // VALIDAZIONE CRITICA: Verifica che il numero di colonne corrisponda agli headers
                // Se non corrisponde, c'è un problema nel parsing del file
                $rowColCount = count($rowData);
                if ($rowColCount !== $headerCount) {
                    $log .= "ATTENZIONE Riga $lineNumber: Numero colonne ($rowColCount) diverso da headers ($headerCount) - Normalizzazione forzata\n";
                    // Forza normalizzazione per garantire corrispondenza posizionale
                    $normalizedRowData = [];
                    for ($i = 0; $i < $headerCount; $i++) {
                        $normalizedRowData[$i] = isset($rowData[$i]) ? $rowData[$i] : '';
                    }
                    $rowData = $normalizedRowData;
                }

                // Filtra per selected_rows se necessario
                if (!empty($selected_rows) && !in_array($lineNumber - 2, $selected_rows)) {
                    continue;
                }

                // Salta righe completamente vuote
                if (self::isEmptyRow($rowData)) {
                    $log .= "Riga $lineNumber: Riga completamente vuota nel file, saltata\n";
                    $skipped++;
                    continue;
                }

                // Mappa la riga ai campi del DB
                $mappedValues = $mapper->mapRow($rowData);

                // Log di debug per capire il mapping (solo se non ci sono valori mappati)
                $mappedCount = count(array_filter($mappedValues, function ($v) {
                    return $v !== null && trim($v) !== ''; }));
                if ($mappedCount === 0) {
                    $log .= "Riga $lineNumber: Nessun valore mappato dai dati del file. Headers disponibili: " . implode(', ', array_slice($headers, 0, 5)) . "...\n";
                }

                // Processa i valori
                $processedValues = [];
                foreach ($dbFields as $field) {
                    if ($field === 'id') {
                        continue;
                    }

                    $rawValue = $mappedValues[$field] ?? null;
                    $info = $fieldInfo[$field] ?? null;
                    $processedValues[$field] = $processor->processValue($field, $rawValue, $info);
                }

                // Verifica che ci sia almeno un valore significativo
                if (!self::hasSignificantValues($processedValues)) {
                    // Log dettagliato per capire perché è stata saltata
                    $nonEmptyFields = [];
                    foreach ($processedValues as $field => $val) {
                        if ($val !== null && $val !== '' && $val !== 'CURRENT_TIMESTAMP') {
                            $nonEmptyFields[] = "$field=" . (is_string($val) ? substr($val, 0, 50) : $val);
                        }
                    }
                    $log .= "Riga $lineNumber: Nessun valore significativo dopo processing, saltata. Campi non vuoti: " . (count($nonEmptyFields) > 0 ? implode(', ', $nonEmptyFields) : 'nessuno') . "\n";
                    $skipped++;
                    continue;
                }

                // Gestione speciale per data_creazione
                if (
                    in_array('data_creazione', $dbFields) &&
                    (empty($processedValues['data_creazione']) || $processedValues['data_creazione'] === null)
                ) {
                    $processedValues['data_creazione'] = date('Y-m-d H:i:s');
                }

                // Esegui insert o update
                try {
                    if ($mode === 'update' && $key_field) {
                        $result = self::handleUpdate($mysqli, $table, $processedValues, $key_field, $mappedValues, $update_fields, $fieldInfo, $lineNumber, $skip_existing);
                        if ($result['updated'] ?? false) {
                            $updated++;
                        } elseif ($result['inserted'] ?? false) {
                            $inserted++;
                        } else {
                            $skipped++;
                            $log .= $result['log'] ?? '';
                        }
                    } else {
                        $result = self::handleInsert($mysqli, $table, $processedValues, $fieldInfo, $lineNumber);
                        if ($result['success'] ?? false) {
                            $inserted++;
                        } else {
                            $skipped++;
                            $log .= $result['log'] ?? '';
                        }
                    }
                } catch (\Exception $e) {
                    $skipped++;
                    $errorMsg = $e->getMessage();
                    // Gestisci chiavi duplicate come warning, non errore fatale
                    if (strpos($errorMsg, 'Duplicate entry') !== false) {
                        $log .= "Riga $lineNumber: Record già esistente (chiave duplicata) - " . $errorMsg . "\n";
                    } else {
                        $log .= "Riga $lineNumber: Errore - " . $errorMsg . "\n";
                    }
                }
            }

            return [
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'log' => $log,
                'created_table' => ($mode === 'create_new' ? $table : null)
            ];
        } catch (\Exception $e) {
            // Cattura qualsiasi errore e restituisci sempre JSON
            return [
                'error' => 'Errore durante l\'importazione: ' . $e->getMessage(),
                'inserted' => isset($inserted) ? $inserted : 0,
                'updated' => isset($updated) ? $updated : 0,
                'skipped' => isset($skipped) ? $skipped : 0,
                'log' => (isset($log) ? $log . "\n" : '') . "Errore fatale: " . $e->getMessage()
            ];
        }
    }

    /**
     * Crea una nuova tabella
     */
    private static function createTable($mysqli, $tableName, $headers)
    {
        $cols = [];
        foreach ($headers as $col) {
            $safe = preg_replace('/[^a-zA-Z0-9_]+/', '_', $col);
            $safe = preg_replace('/_+/', '_', $safe);
            $safe = trim($safe, '_');
            $safe = strtolower($safe);
            if ($safe === 'id') {
                continue;
            }
            $type = (count($headers) > 20) ? "TEXT NULL" : "VARCHAR(255) NULL";
            $cols[] = "`$safe` $type";
        }

        $sqlCreate = "CREATE TABLE `$tableName` (id INT PRIMARY KEY AUTO_INCREMENT, " . implode(',', $cols) . ")";
        if (!$mysqli->query($sqlCreate)) {
            return ['error' => 'Errore nella creazione tabella: ' . $mysqli->error];
        }

        return ['success' => true];
    }

    /**
     * Importa dati dalla preview filtrata (modalità create_new)
     */
    private static function importFilteredPreview($mysqli, $table, $filteredPreviewJson, $dbFields, $fieldInfo)
    {
        $previewRows = json_decode($filteredPreviewJson, true);
        $realFields = array_filter($dbFields, function ($f) {
            return $f !== 'id'; });
        $realFields = array_values($realFields);
        $fieldCount = count($realFields);

        $inserted = 0;
        $skipped = 0;
        $log = '';

        foreach ($previewRows as $idx => $filtered_row) {
            // Normalizza la riga
            $normalizedRow = [];
            for ($i = 0; $i < $fieldCount; $i++) {
                $normalizedRow[$i] = isset($filtered_row[$i]) ? trim($filtered_row[$i]) : '';
            }

            $cols = [];
            $vals = [];
            foreach ($realFields as $fieldIdx => $field) {
                $cols[] = "`$field`";
                $val = isset($normalizedRow[$fieldIdx]) ? $normalizedRow[$fieldIdx] : '';
                if ($val === '' || $val === null) {
                    $vals[] = "NULL";
                } else {
                    $vals[] = "'" . $mysqli->real_escape_string($val) . "'";
                }
            }

            $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
            if ($mysqli->query($sql)) {
                $inserted++;
            } else {
                $log .= "Riga " . ($idx + 1) . ": Errore insert - " . $mysqli->error . "\n";
                $skipped++;
            }
        }

        return [
            'inserted' => $inserted,
            'updated' => 0,
            'skipped' => $skipped,
            'log' => $log,
            'created_table' => $table
        ];
    }

    /**
     * Verifica se una riga è completamente vuota
     */
    private static function isEmptyRow($rowData)
    {
        foreach ($rowData as $cell) {
            $cellTrimmed = is_string($cell) ? trim($cell) : (string) $cell;
            if ($cellTrimmed !== '' && $cellTrimmed !== null && $cellTrimmed !== 'NULL') {
                return false;
            }
        }
        return true;
    }

    /**
     * Verifica se ci sono valori significativi
     */
    private static function hasSignificantValues($values)
    {
        $systemFields = ['id', 'azienda_id', 'data_creazione', 'dataultimamodifica', 'utenteultimamodifica', 'creatoda', 'created_at', 'updated_at'];

        // Conta quanti campi hanno valori (anche 0 o stringhe vuote dopo trim)
        $fieldsWithValues = 0;

        foreach ($values as $field => $val) {
            if (in_array($field, $systemFields)) {
                continue;
            }

            // Considera significativo qualsiasi valore che non sia null o stringa completamente vuota
            // Includi anche '0' come valore significativo (potrebbe essere un valore valido)
            if ($val !== null && $val !== 'CURRENT_TIMESTAMP') {
                $valStr = is_string($val) ? trim($val) : (string) $val;
                // Considera significativo se non è vuoto dopo trim, o se è il numero 0
                if ($valStr !== '' || $val === 0 || $val === '0') {
                    $fieldsWithValues++;
                }
            }
        }

        // Richiedi almeno 1 campo con valore (più permissivo rispetto a prima)
        return $fieldsWithValues > 0;
    }

    /**
     * Gestisce l'update di un record
     * @param bool $skipExisting Se true, salta i record esistenti invece di aggiornarli
     */
    private static function handleUpdate($mysqli, $table, $processedValues, $keyField, $mappedValues, $updateFields, $fieldInfo, $lineNumber, $skipExisting = false)
    {
        // Trova il valore della chiave nei dati mappati
        $keyValue = null;
        foreach ($mappedValues as $field => $value) {
            if (strtolower($field) === strtolower($keyField) && $value !== null && trim($value) !== '') {
                $keyValue = trim($value);
                break;
            }
        }

        if (!$keyValue) {
            return [
                'updated' => false,
                'inserted' => false,
                'log' => "Riga $lineNumber: Campo chiave '$keyField' mancante o vuoto, saltata\n"
            ];
        }

        // Trova il campo chiave nel DB
        $dbKeyField = null;
        foreach (array_keys($fieldInfo) as $f) {
            if (strtolower($f) === strtolower($keyField)) {
                $dbKeyField = $f;
                break;
            }
        }

        if (!$dbKeyField) {
            $dbKeyField = $keyField;
        }

        // Verifica se esiste
        $check = $mysqli->query("SELECT id FROM `$table` WHERE `$dbKeyField` = '" . $mysqli->real_escape_string($keyValue) . "' LIMIT 1");

        if ($check && $check->num_rows > 0) {
            // Record già esistente
            if ($skipExisting) {
                // Se skipExisting è attivo, salta il record senza modificarlo
                return [
                    'updated' => false,
                    'inserted' => false,
                    'log' => ''  // Non logghiamo i skip per non riempire il log
                ];
            }

            // UPDATE
            $set = [];
            $fieldsToUpdate = !empty($updateFields) ? $updateFields : null;

            foreach ($processedValues as $f => $val) {
                if ($f === 'id' || strtolower($f) === strtolower($dbKeyField)) {
                    continue;
                }

                if ($fieldsToUpdate !== null) {
                    $fieldIncluded = false;
                    foreach ($fieldsToUpdate as $uf) {
                        if (strtolower(trim($uf)) === strtolower($f)) {
                            $fieldIncluded = true;
                            break;
                        }
                    }
                    if (!$fieldIncluded) {
                        continue;
                    }
                }

                $info = $fieldInfo[$f] ?? null;
                $set[] = self::buildSetClause($f, $val, $info, $mysqli);
            }

            if (!empty($set)) {
                $sql = "UPDATE `$table` SET " . implode(',', $set) . " WHERE `$dbKeyField`='" . $mysqli->real_escape_string($keyValue) . "'";
                if ($mysqli->query($sql)) {
                    return ['updated' => true, 'inserted' => false, 'log' => ''];
                } else {
                    return [
                        'updated' => false,
                        'inserted' => false,
                        'log' => "Riga $lineNumber: Errore update - " . $mysqli->error . "\n"
                    ];
                }
            }
        } else {
            // INSERT (record non trovato)
            $insertResult = self::handleInsert($mysqli, $table, $processedValues, $fieldInfo, $lineNumber, true);
            // Converti il formato del risultato da handleInsert (success) a handleUpdate (updated/inserted)
            return [
                'updated' => false,
                'inserted' => $insertResult['success'] ?? false,
                'log' => $insertResult['log'] ?? ''
            ];
        }

        return ['updated' => false, 'inserted' => false, 'log' => ''];
    }

    /**
     * Gestisce l'insert di un record
     */
    private static function handleInsert($mysqli, $table, $processedValues, $fieldInfo, $lineNumber, $isUpdateFallback = false)
    {
        $cols = [];
        $vals = [];

        foreach ($processedValues as $f => $val) {
            if ($f === 'id') {
                continue;
            }

            $info = $fieldInfo[$f] ?? null;

            // Se il valore è null/vuoto e il campo è timestamp NOT NULL con default CURRENT_TIMESTAMP, usa CURRENT_TIMESTAMP
            if (($val === null || $val === '') && $info) {
                $type = strtolower($info['type'] ?? '');
                if (
                    (strpos($type, 'timestamp') !== false || strpos($type, 'datetime') !== false) &&
                    !$info['null'] &&
                    ($info['default'] === 'CURRENT_TIMESTAMP' ||
                        (is_string($info['default']) && strtoupper($info['default']) === 'CURRENT_TIMESTAMP'))
                ) {
                    $val = 'CURRENT_TIMESTAMP';
                }
            }

            // Verifica e tronca il valore se necessario prima di inserirlo (doppio controllo basato su BYTE)
            if ($info && is_string($val) && $val !== 'CURRENT_TIMESTAMP') {
                $type = strtolower($info['type'] ?? '');
                // Controlla se è VARCHAR o CHAR e tronca se necessario (considerando i BYTE)
                if (preg_match('/^(varchar|char)\((\d+)\)/i', $type, $matches)) {
                    $maxBytes = (int) $matches[2];
                    $currentBytes = strlen($val); // strlen conta i byte, non i caratteri

                    if ($currentBytes > $maxBytes) {
                        // Tronca considerando i byte con margine di sicurezza
                        $safeBytes = max(1, $maxBytes - 3);

                        // Tronca byte per byte preservando i caratteri UTF-8
                        $truncated = '';
                        $byteCount = 0;
                        $chars = preg_split('//u', $val, -1, PREG_SPLIT_NO_EMPTY);

                        foreach ($chars as $char) {
                            $charBytes = strlen($char);
                            if ($byteCount + $charBytes > $safeBytes) {
                                break;
                            }
                            $truncated .= $char;
                            $byteCount += $charBytes;
                        }

                        $val = $truncated;
                    }
                }
            }

            $cols[] = "`$f`";
            $vals[] = self::buildValueClause($val, $info, $mysqli);
        }

        $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";

        try {
            if ($mysqli->query($sql)) {
                return ['success' => true, 'log' => ''];
            } else {
                $errorMsg = $mysqli->error;

                // Gestisci constraint azienda_id
                if (strpos($errorMsg, 'CONSTRAINT') !== false && strpos($errorMsg, 'azienda_id') !== false) {
                    // Prova a correggere
                    foreach ($cols as $idx => $col) {
                        if (strtolower(trim($col, '`')) === 'azienda_id') {
                            $correctedVals = $vals;
                            $correctedVals[$idx] = '1';
                            $sqlCorrected = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $correctedVals) . ")";
                            if ($mysqli->query($sqlCorrected)) {
                                return ['success' => true, 'log' => "Riga $lineNumber: Constraint azienda_id corretto\n"];
                            }
                            break;
                        }
                    }
                }

                // Gestisci chiavi duplicate (non è un errore fatale, solo un warning)
                if (strpos($errorMsg, 'Duplicate entry') !== false) {
                    return [
                        'success' => false,
                        'log' => "Riga $lineNumber: Record già esistente (chiave duplicata) - " . $errorMsg . "\n"
                    ];
                }

                return [
                    'success' => false,
                    'log' => "Riga $lineNumber: Errore insert - " . $errorMsg . "\n"
                ];
            }
        } catch (\mysqli_sql_exception $e) {
            $errorMsg = $e->getMessage();

            // Gestisci chiavi duplicate
            if (strpos($errorMsg, 'Duplicate entry') !== false) {
                return [
                    'success' => false,
                    'log' => "Riga $lineNumber: Record già esistente (chiave duplicata) - " . $errorMsg . "\n"
                ];
            }

            return [
                'success' => false,
                'log' => "Riga $lineNumber: Errore insert - " . $errorMsg . "\n"
            ];
        }
    }

    /**
     * Costruisce una clausola SET per UPDATE
     */
    private static function buildSetClause($field, $value, $fieldInfo, $mysqli)
    {
        // Gestisci valori speciali SQL (funzioni)
        if (is_string($value) && strtoupper($value) === 'CURRENT_TIMESTAMP') {
            return "`$field`=CURRENT_TIMESTAMP";
        }

        if ($value === '') {
            return "`$field`=''";
        } elseif ($value === null) {
            if ($fieldInfo && !$fieldInfo['null']) {
                $type = strtolower($fieldInfo['type']);
                if (
                    strpos($type, 'int') !== false || strpos($type, 'decimal') !== false ||
                    strpos($type, 'float') !== false || strpos($type, 'double') !== false
                ) {
                    return "`$field`=0";
                } elseif (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
                    // Se il campo ha default CURRENT_TIMESTAMP, usa quello
                    if (
                        $fieldInfo['default'] === 'CURRENT_TIMESTAMP' ||
                        (is_string($fieldInfo['default']) && strtoupper($fieldInfo['default']) === 'CURRENT_TIMESTAMP')
                    ) {
                        return "`$field`=CURRENT_TIMESTAMP";
                    }
                    return "`$field`='" . date('Y-m-d H:i:s') . "'";
                } else {
                    return "`$field`=''";
                }
            } else {
                return "`$field`=NULL";
            }
        } else {
            $type = strtolower($fieldInfo['type'] ?? '');
            if (
                strpos($type, 'int') !== false || strpos($type, 'decimal') !== false ||
                strpos($type, 'float') !== false || strpos($type, 'double') !== false ||
                strpos($type, 'numeric') !== false
            ) {
                return "`$field`=" . (is_numeric($value) ? $value : 'NULL');
            } else {
                return "`$field`='" . $mysqli->real_escape_string($value) . "'";
            }
        }
    }

    /**
     * Costruisce una clausola VALUE per INSERT
     */
    private static function buildValueClause($value, $fieldInfo, $mysqli)
    {
        // Gestisci valori speciali SQL (funzioni)
        if (is_string($value)) {
            $valueUpper = strtoupper(trim($value));
            // Riconosci CURRENT_TIMESTAMP in varie forme
            if (
                $valueUpper === 'CURRENT_TIMESTAMP' || $valueUpper === 'CURRENT_TIMESTAMP()' ||
                $valueUpper === 'NOW()' || $valueUpper === 'NOW'
            ) {
                return 'CURRENT_TIMESTAMP';
            }
        }

        if ($value === '') {
            return "''";
        } elseif ($value === null) {
            if ($fieldInfo && !$fieldInfo['null'] && $fieldInfo['default'] === null && $fieldInfo['key'] !== 'PRI') {
                $type = strtolower($fieldInfo['type']);
                if (
                    strpos($type, 'int') !== false || strpos($type, 'decimal') !== false ||
                    strpos($type, 'float') !== false || strpos($type, 'double') !== false
                ) {
                    return '0';
                } elseif (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
                    // Se il campo ha default CURRENT_TIMESTAMP, usa quello
                    if (
                        $fieldInfo['default'] === 'CURRENT_TIMESTAMP' ||
                        (is_string($fieldInfo['default']) && strtoupper($fieldInfo['default']) === 'CURRENT_TIMESTAMP')
                    ) {
                        return 'CURRENT_TIMESTAMP';
                    }
                    return "'" . date('Y-m-d H:i:s') . "'";
                } else {
                    return "''";
                }
            } else {
                return 'NULL';
            }
        } else {
            $type = strtolower($fieldInfo['type'] ?? '');
            if (
                strpos($type, 'int') !== false || strpos($type, 'decimal') !== false ||
                strpos($type, 'float') !== false || strpos($type, 'double') !== false ||
                strpos($type, 'numeric') !== false
            ) {
                return is_numeric($value) ? $value : 'NULL';
            } else {
                // Controlla di nuovo se è CURRENT_TIMESTAMP prima di metterlo tra virgolette
                if (is_string($value)) {
                    $valueUpper = strtoupper(trim($value));
                    if (
                        $valueUpper === 'CURRENT_TIMESTAMP' || $valueUpper === 'CURRENT_TIMESTAMP()' ||
                        $valueUpper === 'NOW()' || $valueUpper === 'NOW'
                    ) {
                        return 'CURRENT_TIMESTAMP';
                    }
                }
                return "'" . $mysqli->real_escape_string($value) . "'";
            }
        }
    }
}


