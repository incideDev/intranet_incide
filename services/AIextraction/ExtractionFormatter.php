<?php

namespace Services\AIextraction;

/**
 * Classe per formattare e visualizzare dati di estrazione.
 * Gestisce la conversione di valori raw in formati display-friendly.
 */
class ExtractionFormatter
{
    private const DATE_TYPE_CODES = [
        'data_scadenza_gara_appalto',
        'data_uscita_gara_appalto',
        'data_scadenza_gara',
        'data_uscita_gara',
        'data_pubblicazione',
        'data_scadenza_offerta',
    ];

    /**
     * Ordine fisso per i blocchi di estrazione nella pagina Dettaglio Gara
     * Questo ordine deve corrispondere esattamente allo screenshot storico (job #186)
     * 
     * IMPORTANTE: Qualsiasi extraction_type non presente in questa mappa
     * finirà in coda con ordinamento alfabetico (indice >= 1000)
     */
    private const DETTAGLIO_GARA_ORDER = [
        'oggetto_appalto' => 1,
        'luogo_provincia_appalto' => 2,
        'data_scadenza_gara_appalto' => 3,
        'data_uscita_gara_appalto' => 4,
        'settore_industriale_gara_appalto' => 5,
        'sopralluogo_obbligatorio' => 6,
        'sopralluogo_deadline' => 7, // Campo per data richiesta sopralluogo (se presente come extraction_type separato)
        'stazione_appaltante' => 8,
        'tipologia_di_appalto' => 9,
        'tipologia_di_gara' => 10,
        'link_portale_stazione_appaltante' => 11,
        'importi_opere_per_categoria_id_opere' => 12,
        'importi_corrispettivi_categoria_id_opere' => 13,
        'importi_requisiti_tecnici_categoria_id_opere' => 14,
        'documentazione_richiesta_tecnica' => 15,
        'requisiti_tecnico_professionali' => 16,
        'fatturato_globale_n_minimo_anni' => 17,
        'requisiti_di_capacita_economica_finanziaria' => 18,
        'requisiti_idoneita_professionale_gruppo_lavoro' => 19,
    ];

    /**
     * @deprecated Usa DETTAGLIO_GARA_ORDER invece. Mantenuto per retrocompatibilità.
     */
    private const EXTRACTION_SORT_ORDER = [
        'oggetto_appalto' => 10,
        'luogo_provincia_appalto' => 20,
        'data_scadenza_gara_appalto' => 30,
        'data_uscita_gara_appalto' => 40,
        'settore_industriale_gara_appalto' => 50,
        'sopralluogo_obbligatorio' => 60,
        'stazione_appaltante' => 70,
        'tipologia_di_appalto' => 80,
        'tipologia_di_gara' => 90,
        'link_portale_stazione_appaltante' => 100,
        'importi_opere_per_categoria_id_opere' => 200,
        'importi_corrispettivi_categoria_id_opere' => 210,
        'importi_requisiti_tecnici_categoria_id_opere' => 220,
        'settore_industriale_gara_appalto_tecnico' => 225,
        'documentazione_richiesta_tecnica' => 230,
        'requisiti_tecnico_professionali' => 300,
        'fatturato_globale_n_minimo_anni' => 310,
        'requisiti_di_capacita_economica_finanziaria' => 320,
        'requisiti_idoneita_professionale_gruppo_lavoro' => 330,
    ];

    /**
     * Restituisce il nome display per un type_code.
     * Delega a ExtractionConstants come fonte unica di verità per i label.
     */
    public static function displayNameForExtractionType(string $type): string
    {
        return \Services\ExtractionConstants::getDisplayName($type);
    }

    /**
     * Converte un valore di estrazione in stringa per display.
     * 
     * IMPORTANTE: NON usa mai campi di debug come chain_of_thought, reasoning, empty_reason, ecc.
     * Se non trova una risposta pulita, restituisce NULL.
     */
    public static function stringifyExtractionValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Campi di debug da ignorare completamente
        $debugFields = ['chain_of_thought', 'chainOfThought', 'reasoning', 'empty_reason', 
                        'reason', 'message', 'note', 'explanation', 'raw_block', 'raw_text', 
                        'debug', 'logs', 'citations', 'processing_time', 'error', 'error_details'];

        if (is_array($value)) {
            // Verifica se è un campo di debug
            foreach ($debugFields as $field) {
                if (array_key_exists($field, $value)) {
                    // Se l'array contiene solo campi di debug, restituisci null
                    $hasNonDebugField = false;
                    foreach ($value as $k => $v) {
                        if (!in_array($k, $debugFields, true)) {
                            $hasNonDebugField = true;
                            break;
                        }
                    }
                    if (!$hasNonDebugField) {
                        return null;
                    }
                }
            }
            
            // Campi accettabili come risposta (in ordine di priorità)
            if (array_key_exists('answer', $value)) {
                return self::stringifyExtractionValue($value['answer']);
            }
            if (array_key_exists('value', $value)) {
                return self::stringifyExtractionValue($value['value']);
            }
            if (isset($value['location']) && is_array($value['location'])) {
                return self::formatLocationValue($value['location']);
            }
            if (isset($value['url'])) {
                return (string) $value['url'];
            }
            if (isset($value['text']) && is_string($value['text'])) {
                $text = trim($value['text']);
                // Verifica che non sia reasoning o JSON grezzo
                if ($text !== '' && strlen($text) < 500 && !preg_match('/^\s*[\{\[]/', $text) &&
                    stripos($text, 'chain_of_thought') === false &&
                    stripos($text, 'reasoning') === false) {
                    return $text;
                }
            }
            if (isset($value['name']) && is_string($value['name'])) {
                return trim($value['name']);
            }
            // NON restituire JSON grezzo se contiene solo campi di debug
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'si' : 'no';
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            // Rifiuta JSON grezzo, testi troppo lunghi, o contenuti sospetti
            if ($trimmed !== '' && 
                strlen($trimmed) < 500 && 
                !preg_match('/^\s*[\{\[]/', $trimmed) &&
                stripos($trimmed, 'chain_of_thought') === false &&
                stripos($trimmed, 'reasoning') === false) {
                return $trimmed;
            }
        } elseif (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Costruisce il valore display per un'estrazione
     */
    public static function buildExtractionDisplay(array $row, $decodedJson = null): ?string
    {
        $type = $row['type_code'] ?? ($row['type'] ?? null);
        $valueText = $row['value_text'] ?? null;
        $decoded = $decodedJson ?? self::decodeExtractionJson($row['value_json'] ?? null);

        if ($type !== null && in_array((string) $type, self::DATE_TYPE_CODES, true)) {
            $formattedDate = self::formatDateDisplay($valueText, $decoded);
            if ($formattedDate !== null) {
                return $formattedDate;
            }
        }

        if ($type === 'luogo_provincia_appalto') {
            $location = self::extractLocationData($decoded);
            if ($location !== null) {
                $formatted = self::formatLocationValue($location);
                if ($formatted !== null && $formatted !== '') {
                    return $formatted;
                }
            }
        }

        if (is_string($valueText) && trim($valueText) !== '') {
            return trim($valueText);
        }

        if (is_array($decoded)) {
            // Gestisci date
            if (isset($decoded['date']) && is_array($decoded['date'])) {
                $date = $decoded['date'];
                $year = $date['year'] ?? null;
                $month = $date['month'] ?? null;
                $day = $date['day'] ?? null;
                if ($year && $month && $day) {
                    $hour = $date['hour'] ?? null;
                    $minute = $date['minute'] ?? null;
                    $dateStr = sprintf('%02d/%02d/%04d', $day, $month, $year);
                    if ($hour !== null && $minute !== null) {
                        $dateStr .= sprintf(' %02d:%02d', $hour, $minute);
                    }
                    return $dateStr;
                }
            }
            // Gestisci URL
            if (isset($decoded['url']) && is_string($decoded['url'])) {
                return $decoded['url'];
            }
            // Se entries esiste (anche vuoto), non restituire display value
            if (isset($decoded['entries'])) {
                return null;
            }
            // Usa solo answer dalla nuova struttura API
            if (isset($decoded['answer']) && is_string($decoded['answer']) && trim($decoded['answer']) !== '') {
                return trim($decoded['answer']);
            }
        }

        return null;
    }

    /**
     * Estrae dati location da un valore decoded
     */
    public static function extractLocationData($decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['location']) && is_array($decoded['location'])) {
            return $decoded['location'];
        }

        if (isset($decoded['answer']) && is_array($decoded['answer']) && isset($decoded['answer']['location']) && is_array($decoded['answer']['location'])) {
            return $decoded['answer']['location'];
        }

        return null;
    }

    /**
     * Formatta un array location in stringa
     */
    public static function formatLocationValue(array $location): ?string
    {
        $segments = [];

        $name = trim((string) ($location['entity_name'] ?? $location['organization'] ?? ''));
        if ($name !== '') {
            $entityType = trim((string) ($location['entity_type'] ?? ''));
            if ($entityType !== '') {
                if (stripos($name, $entityType) === false) {
                    $name .= ' (' . $entityType . ')';
                }
            }
            $segments[] = $name;
        }

        $addressSegments = [];

        $streetParts = [];
        if (!empty($location['street'])) {
            $streetParts[] = trim((string) $location['street']);
        }
        if (!empty($location['house_number'])) {
            $streetParts[] = trim((string) $location['house_number']);
        }
        if ($streetParts) {
            $addressSegments[] = implode(' ', $streetParts);
        }

        $cityLineParts = [];
        if (!empty($location['postal_code'])) {
            $cityLineParts[] = trim((string) $location['postal_code']);
        }
        if (!empty($location['city'])) {
            $city = trim((string) $location['city']);
            if (!empty($location['district'])) {
                $city .= ' (' . trim((string) $location['district']) . ')';
            }
            $cityLineParts[] = $city;
        }
        if ($cityLineParts) {
            $addressSegments[] = implode(' ', $cityLineParts);
        }

        foreach (['province', 'region', 'state'] as $key) {
            if (!empty($location[$key])) {
                $value = trim((string) $location[$key]);
                if ($value !== '') {
                    $addressSegments[] = $value;
                }
            }
        }

        if (!empty($location['country'])) {
            $country = trim((string) $location['country']);
            if ($country !== '') {
                $addressSegments[] = $country;
            }
        }

        if ($addressSegments) {
            $segments[] = implode(', ', $addressSegments);
        }

        $extras = [];
        if (!empty($location['nuts_code'])) {
            $extras[] = 'NUTS ' . strtoupper(trim((string) $location['nuts_code']));
        }
        if (!empty($location['scope'])) {
            $extras[] = trim((string) $location['scope']);
        }
        if (!empty($location['location_type'])) {
            $extras[] = trim((string) $location['location_type']);
        }

        if (!$segments && !empty($location['raw_text'])) {
            $segments[] = trim((string) $location['raw_text']);
        }

        if ($extras) {
            $segments[] = implode(', ', $extras);
        }

        $segments = array_values(array_filter(array_map(static function ($segment) {
            return trim(preg_replace('/\s+/', ' ', (string) $segment));
        }, $segments)));

        if (!$segments) {
            return null;
        }

        return implode(' – ', $segments);
    }

    /**
     * Deriva il motivo per cui un'estrazione è vuota
     */
    public static function deriveEmptyReason(
        ?string $typeCode,
        $displayValue,
        array $requirementsDetails,
        $decoded,
        $valueText,
        $rawJson = null
    ): ?string {
        $hasDisplay = is_string($displayValue) && trim($displayValue) !== '';
        $hasRequirements = !empty($requirementsDetails['entries']);
        $hasTable = !empty($requirementsDetails['table']) && !empty($requirementsDetails['table']['rows']);

        if ($hasDisplay || $hasRequirements || $hasTable) {
            return null;
        }

        if (is_string($valueText) && trim($valueText) !== '') {
            return null;
        }

        $messages = [];

        if (!empty($requirementsDetails['raw_block']) && is_string($requirementsDetails['raw_block'])) {
            $messages[] = $requirementsDetails['raw_block'];
        }

        if (is_array($decoded)) {
            if (!empty($decoded['answer']) && is_string($decoded['answer']) && trim($decoded['answer']) !== '') {
                return null;
            }

            // NON usare chain_of_thought, empty_reason, reason, explanation come messaggi da mostrare
            // Questi campi sono solo per debug/log, non per display all'utente
            // Se non c'è una risposta vera, non mostrare niente (return null)
            
            // I messaggi qui sono solo per log interno, non per display
            // Rimuoviamo completamente l'uso di chain_of_thought come fallback
        } elseif (is_string($decoded) && trim($decoded) !== '') {
            $messages[] = $decoded;
        } elseif (is_string($rawJson) && trim($rawJson) !== '') {
            $messages[] = $rawJson;
        }

        foreach ($messages as $message) {
            $summary = self::summarizeEmptyReason($message);
            if ($summary !== '') {
                return $summary;
            }
        }

        return null;
    }

    /**
     * Riassume un motivo di empty reason
     */
    public static function summarizeEmptyReason(string $text): string
    {
        $raw = trim(strip_tags($text));
        if ($raw === '' || preg_match('/^\s*[\{\[]/', $raw)) {
            return '';
        }

        $clean = trim(preg_replace('/\s+/', ' ', $raw));
        if ($clean === '') {
            return '';
        }

        $clean = ltrim($clean, '-•: ');
        $sentences = preg_split('/(?<=[.?!])\s+/', $clean);
        $summary = $sentences[0] ?? $clean;

        if (mb_strlen($summary) > 240) {
            $summary = mb_substr($summary, 0, 237) . '…';
        }

        return $summary;
    }

    /**
     * Confronta due estrazioni per ordinamento nella pagina Dettaglio Gara
     * Usa DETTAGLIO_GARA_ORDER per garantire un ordine fisso e deterministico
     * 
     * IMPORTANTE: Non usa più ID o timestamp come tie-breaker per evitare ordinamenti casuali.
     * Se due estrazioni hanno lo stesso type_code, mantengono l'ordine originale (stabile sort).
     */
    public static function compareExtractionSort(array $a, array $b): int
    {
        $typeA = $a['tipo'] ?? null;
        $typeB = $b['tipo'] ?? null;
        
        $orderA = self::sortKeyForType($typeA);
        $orderB = self::sortKeyForType($typeB);
        
        // Se hanno lo stesso ordine, mantieni l'ordine originale (stabile sort)
        // NON usare ID o timestamp per evitare ordinamenti casuali
        if ($orderA === $orderB) {
            // Se hanno lo stesso type_code, ordina alfabeticamente per type_code
            // altrimenti mantieni l'ordine originale
            if ($typeA === $typeB) {
                return 0; // Stesso tipo, stesso ordine
            }
            // Type_code diversi con stesso ordine (entrambi non nella mappa) → ordine alfabetico
            return strcmp($typeA ?? '', $typeB ?? '');
        }
        
        return $orderA <=> $orderB;
    }

    /**
     * Confronta due righe di estrazione per ordinamento
     */
    public static function compareExtractionSortRow(array $a, array $b): int
    {
        $typeA = $a['type_code'] ?? ($a['type'] ?? null);
        $typeB = $b['type_code'] ?? ($b['type'] ?? null);
        $orderA = self::sortKeyForType($typeA);
        $orderB = self::sortKeyForType($typeB);
        if ($orderA === $orderB) {
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        }
        return $orderA <=> $orderB;
    }

    /**
     * Restituisce la chiave di ordinamento per un type_code
     * Usa DETTAGLIO_GARA_ORDER per l'ordinamento fisso nella pagina Dettaglio Gara
     * 
     * Logica:
     * - Se il type_code è in DETTAGLIO_GARA_ORDER, usa l'indice corrispondente (1-19)
     * - Se non è presente, assegna 1000 + posizione alfabetica per ordinamento in coda
     */
    public static function sortKeyForType($type): int
    {
        if (!is_string($type) || $type === '') {
            return 10000;
        }
        
        // Priorità 1: usa DETTAGLIO_GARA_ORDER (ordinamento fisso per Dettaglio Gara)
        if (isset(self::DETTAGLIO_GARA_ORDER[$type])) {
            return self::DETTAGLIO_GARA_ORDER[$type];
        }
        
        // Priorità 2: fallback a EXTRACTION_SORT_ORDER (per retrocompatibilità)
        if (isset(self::EXTRACTION_SORT_ORDER[$type])) {
            return self::EXTRACTION_SORT_ORDER[$type];
        }
        
        // Priorità 3: type_code non nella mappa → finisce in coda con ordinamento alfabetico
        // Usa 1000 come base + hash del nome per ordinamento alfabetico stabile
        // Questo garantisce che type_code non nella mappa siano ordinati alfabeticamente
        // ma sempre dopo quelli nella mappa (indici 1-19)
        $baseOffset = 1000;
        $typeLower = strtolower($type);
        
        // Calcola un offset basato sul nome per ordinamento alfabetico
        // Usa i primi 3 caratteri per garantire un ordinamento stabile e deterministico
        $alphabeticalOffset = 0;
        $maxChars = min(3, strlen($typeLower));
        for ($i = 0; $i < $maxChars; $i++) {
            $char = ord($typeLower[$i]) - ord('a');
            if ($char >= 0 && $char <= 25) {
                // Ogni carattere contribuisce: posizione * 26^(2-i)
                // Es: 'a' = 0, 'b' = 1, 'z' = 25
                $alphabeticalOffset += $char * pow(26, 2 - $i);
            }
        }
        // Limita a 999 per evitare overflow e mantenere ordine stabile
        $alphabeticalOffset = min(999, max(0, $alphabeticalOffset));
        
        return $baseOffset + $alphabeticalOffset;
    }

    /**
     * Formatta una data per display
     */
    public static function formatDateDisplay($valueText, $decoded): ?string
    {
        $candidates = [];

        if (is_array($decoded)) {
            // Nuova struttura: data.date con year, month, day
            if (isset($decoded['date']) && is_array($decoded['date'])) {
                $date = $decoded['date'];
                $year = $date['year'] ?? null;
                $month = $date['month'] ?? null;
                $day = $date['day'] ?? null;
                if ($year && $month && $day) {
                    $hour = $date['hour'] ?? null;
                    $minute = $date['minute'] ?? null;
                    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    if ($hour !== null && $minute !== null) {
                        $dateStr .= sprintf(' %02d:%02d:00', $hour, $minute);
                    }
                    $candidates[] = $dateStr;
                } else {
                    $candidates[] = $decoded['date'];
                }
            }
            if (isset($decoded['answer'])) {
                $candidates[] = $decoded['answer'];
            }
            if (isset($decoded['value'])) {
                $candidates[] = $decoded['value'];
            }
        }

        if ($valueText !== null && $valueText !== '') {
            $candidates[] = $valueText;
        }

        foreach ($candidates as $candidate) {
            $iso = self::extractDateValue($candidate);
            if ($iso) {
                $dt = \DateTime::createFromFormat('Y-m-d', $iso);
                if ($dt instanceof \DateTime) {
                    return $dt->format('d-m-Y');
                }
            }

            if (is_string($candidate) && preg_match('/\d{4}-\d{2}-\d{2}/', $candidate)) {
                try {
                    $dateTime = new \DateTime($candidate);
                    return $dateTime->format('d-m-Y');
                } catch (\Exception $e) {
                    // ignore parse errors
                }
            }
        }

        return null;
    }

    /**
     * Estrae un valore data ISO da vari formati
     */
    public static function extractDateValue($value): ?string
    {
        if (!$value) {
            return null;
        }
        if (is_array($value)) {
            // Nuova struttura: date con year, month, day direttamente
            if (isset($value['year']) && isset($value['month']) && isset($value['day'])) {
                $y = $value['year'];
                $m = $value['month'];
                $d = $value['day'];
                if ($y && $m && $d) {
                    return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
                }
            }
            // Struttura annidata: date.date
            if (isset($value['date']) && is_array($value['date'])) {
                $y = $value['date']['year'] ?? null;
                $m = $value['date']['month'] ?? null;
                $d = $value['date']['day'] ?? null;
                if ($y && $m && $d) {
                    return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, (int) $d);
                }
            }
            if (isset($value['answer'])) {
                return self::extractDateValue($value['answer']);
            }
        }
        if (is_string($value)) {
            if (preg_match('/\d{4}-\d{2}-\d{2}/', $value, $m)) {
                return $m[0];
            }
            if (preg_match('/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $value, $m)) {
                $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
                $year = $m[3];
                if (strlen($year) === 2) {
                    $year = '20' . $year;
                }
                return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
            }
        }
        return null;
    }

    /**
     * Decodifica un JSON di estrazione
     */
    public static function decodeExtractionJson($value): ?array
    {
        if (empty($value)) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Normalizza un candidato per display
     */
    public static function normalizeDisplayCandidate($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'si' : 'no';
        }
        if (is_array($value)) {
            $string = json_encode($value, JSON_UNESCAPED_UNICODE);
            return self::normalizeDisplayCandidate($string);
        }
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    /**
     * Estrae un valore booleano da un'estrazione
     */
    public static function boolFromExtraction($value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_array($value)) {
            if (array_key_exists('bool_answer', $value)) {
                return (bool) $value['bool_answer'];
            }
            if (array_key_exists('answer', $value)) {
                return self::boolFromExtraction($value['answer']);
            }
        }
        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return null;
            }
            if (in_array($normalized, ['1', 'true', 'si', 'sì', 'yes', 'obbligatorio'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'non', 'facoltativo'], true)) {
                return false;
            }
        }
        return null;
    }

    /**
     * Formatta una data ISO in formato italiano
     */
    public static function formatIsoDateToItalian(string $iso): string
    {
        try {
            $dt = new \DateTime($iso);
            return $dt->format('d-m-Y');
        } catch (\Throwable $e) {
            return $iso;
        }
    }

    /**
     * Formatta un importo in euro
     */
    public static function formatEuroAmount($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_numeric($value)) {
            return '€ ' . number_format((float) $value, 2, ',', '.');
        }
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') {
                return null;
            }
            // Se già formattato, restituisci così com'è
            if (preg_match('/^€?\s*\d+[.,]\d{2}$/', $trim)) {
                return $trim;
            }
            // Prova a parsare
            $parsed = self::parseEuroAmount($trim);
            if ($parsed !== null) {
                return '€ ' . number_format($parsed, 2, ',', '.');
            }
        }
        return null;
    }

    /**
     * Parsea un importo in euro da stringa
     */
    public static function parseEuroAmount($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-' || $raw === '--') {
            return null;
        }

        // Rimuovi simboli di valuta e spazi
        $raw = preg_replace('/[€$£\s]/', '', $raw);
        
        // Gestisci separatori decimali
        if (strpos($raw, ',') !== false) {
            // Formato italiano: 1.234,56
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            // Formato inglese: 1,234.56 o 1234.56
            $raw = str_replace(',', '', $raw);
        }

        if (!is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    /**
     * Verifica se un type_code è una data
     */
    public static function isDateType(string $typeCode): bool
    {
        return in_array($typeCode, self::DATE_TYPE_CODES, true);
    }
}

