<?php
/**
 * SimpleXLSX - Legge file Excel XLSX (formato Office Open XML)
 * Versione semplificata standalone per leggere file .xlsx
 * 
 * Basato su SimpleXLSX di Sergey Shuchkin
 * Adattato per essere standalone e compatibile con PHP 7.x+
 */

class SimpleXLSX {
    private $rows = [];
    private $sheets = [];
    private $sheetNames = [];
    private $activeSheet = 0;
    private $loaded = false;
    
    /**
     * Carica un file XLSX
     * @param string $filename Path al file XLSX
     */
    public function __construct($filename) {
        if (!file_exists($filename)) {
            $this->loaded = false;
            return;
        }
        
        // XLSX è un file ZIP
        $zip = new ZipArchive();
        $result = $zip->open($filename);
        if ($result !== true) {
            $this->loaded = false;
            return;
        }
        
        try {
            // Leggi shared strings (valori condivisi)
            $sharedStrings = [];
            if (($sharedStringsXML = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $sharedStrings = $this->parseSharedStrings($sharedStringsXML);
                // Debug: verifica che le shared strings siano state lette
                if (empty($sharedStrings)) {
                    error_log("SimpleXLSX: Shared strings file trovato ma vuoto o non parsato correttamente");
                }
            } else {
                error_log("SimpleXLSX: File sharedStrings.xml non trovato nel ZIP");
            }
            
            // Leggi workbook per ottenere i nomi dei fogli
            if (($workbookXML = $zip->getFromName('xl/workbook.xml')) !== false) {
                $this->parseWorkbook($workbookXML);
            }
            
            // Se non ci sono fogli, prova a trovare il primo foglio disponibile
            if (empty($this->sheets)) {
                // Cerca tutti i file sheet nel ZIP
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename_in_zip = $zip->getNameIndex($i);
                    if (preg_match('/xl\/worksheets\/sheet(\d+)\.xml$/', $filename_in_zip, $matches)) {
                        $this->sheets[] = $matches[1];
                        $this->sheetNames[] = 'Sheet' . $matches[1];
                    }
                }
            }
            
            // Leggi il primo foglio (o quello attivo)
            if (count($this->sheets) > 0) {
                $sheetFile = 'xl/worksheets/sheet' . ($this->activeSheet + 1) . '.xml';
                if (($sheetXML = $zip->getFromName($sheetFile)) !== false) {
                    $this->parseSheet($sheetXML, $sharedStrings);
                } else {
                    // Prova a trovare il primo foglio disponibile
                    for ($i = 1; $i <= 10; $i++) {
                        $sheetFile = 'xl/worksheets/sheet' . $i . '.xml';
                        if (($sheetXML = $zip->getFromName($sheetFile)) !== false) {
                            $this->parseSheet($sheetXML, $sharedStrings);
                            break;
                        }
                    }
                }
            }
            
            $this->loaded = true;
        } catch (\Exception $e) {
            $this->loaded = false;
            error_log("SimpleXLSX Error: " . $e->getMessage());
        } finally {
            $zip->close();
        }
    }
    
    /**
     * Verifica se il file è stato caricato correttamente
     * @return bool
     */
    public function isLoaded() {
        return $this->loaded;
    }
    
    /**
     * Parsa sharedStrings.xml per ottenere i valori condivisi
     */
    private function parseSharedStrings($xml) {
        $strings = [];
        $xmlObj = simplexml_load_string($xml);
        if ($xmlObj === false) {
            return $strings;
        }
        
        // Usa getNamespaces per ottenere i namespace corretti
        $namespaces = $xmlObj->getNamespaces(true);
        $ns = '';
        if (isset($namespaces[''])) {
            $ns = $namespaces[''];
        } elseif (isset($namespaces['main'])) {
            $ns = $namespaces['main'];
        } else {
            // Prova il namespace standard di Excel
            $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        }
        
        // Prova a leggere gli elementi si direttamente senza xpath
        $siElements = [];
        
        // Prova con namespace
        if ($ns) {
            foreach ($xmlObj->children($ns) as $child) {
                if ($child->getName() === 'si') {
                    $siElements[] = $child;
                }
            }
        }
        
        // Se non trovato, prova senza namespace
        if (empty($siElements)) {
            foreach ($xmlObj->children() as $child) {
                if ($child->getName() === 'si') {
                    $siElements[] = $child;
                }
            }
        }
        
        // Se ancora vuoto, prova a cercare direttamente nell'XML come stringa
        if (empty($siElements)) {
            // Usa regex per estrarre i valori tra <t> e </t>
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $xml, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $match) {
                    // Decodifica entità HTML
                    $text = html_entity_decode($match, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $strings[] = $text;
                }
                return $strings;
            }
        }
        
        foreach ($siElements as $item) {
            $text = '';
            
            // Cerca il nodo 't' direttamente (ricorsivo)
            $text = $this->findTextNode($item, $ns);
            
            // Se ancora vuoto, prova a leggere direttamente come stringa XML
            if ($text === '') {
                $itemXml = $item->asXML();
                if (preg_match('/<t[^>]*>(.*?)<\/t>/s', $itemXml, $matches)) {
                    $text = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            
            $strings[] = $text;
        }
        
        return $strings;
    }
    
    /**
     * Parsa workbook.xml per ottenere i nomi dei fogli
     */
    private function parseWorkbook($xml) {
        $xml = simplexml_load_string($xml);
        if ($xml === false) {
            return;
        }
        
        // Usa getNamespaces per ottenere i namespace corretti
        $namespaces = $xml->getNamespaces(true);
        $ns = '';
        if (isset($namespaces[''])) {
            $ns = $namespaces[''];
        }
        
        // Cerca l'elemento sheets
        $sheetsElement = null;
        if ($ns && isset($xml->sheets)) {
            $sheetsElement = $xml->sheets;
        } elseif (isset($xml->sheets)) {
            $sheetsElement = $xml->sheets;
        }
        
        if ($sheetsElement) {
            $sheets = [];
            if ($ns) {
                foreach ($sheetsElement->children($ns) as $child) {
                    if ($child->getName() === 'sheet') {
                        $sheets[] = $child;
                    }
                }
            }
            
            if (empty($sheets)) {
                foreach ($sheetsElement->children() as $child) {
                    if ($child->getName() === 'sheet') {
                        $sheets[] = $child;
                    }
                }
            }
            
            foreach ($sheets as $sheet) {
                $name = isset($sheet['name']) ? (string)$sheet['name'] : '';
                $sheetId = isset($sheet['sheetId']) ? (string)$sheet['sheetId'] : '';
                if ($name !== '') {
                    $this->sheetNames[] = $name;
                    $this->sheets[] = $sheetId;
                }
            }
        }
    }
    
    /**
     * Parsa un foglio XML
     */
    private function parseSheet($xml, $sharedStrings) {
        $xml = simplexml_load_string($xml);
        if ($xml === false) {
            return;
        }
        
        // Usa getNamespaces per ottenere i namespace corretti
        $namespaces = $xml->getNamespaces(true);
        $ns = '';
        if (isset($namespaces[''])) {
            $ns = $namespaces[''];
        }
        
        // Cerca l'elemento sheetData
        $sheetDataElement = null;
        if ($ns && isset($xml->sheetData)) {
            $sheetDataElement = $xml->sheetData;
        } elseif (isset($xml->sheetData)) {
            $sheetDataElement = $xml->sheetData;
        }
        
        if (!$sheetDataElement) {
            return;
        }
        
        // Leggi le righe direttamente senza xpath
        $rows = [];
        if ($ns) {
            foreach ($sheetDataElement->children($ns) as $child) {
                if ($child->getName() === 'row') {
                    $rows[] = $child;
                }
            }
        }
        
        if (empty($rows)) {
            foreach ($sheetDataElement->children() as $child) {
                if ($child->getName() === 'row') {
                    $rows[] = $child;
                }
            }
        }
        
        $maxCol = 0;
        foreach ($rows as $row) {
            $rowNum = isset($row['r']) ? (int)$row['r'] : 0;
            
            // Leggi le celle direttamente
            $cells = [];
            if ($ns) {
                foreach ($row->children($ns) as $child) {
                    if ($child->getName() === 'c') {
                        $cells[] = $child;
                    }
                }
            }
            
            if (empty($cells)) {
                foreach ($row->children() as $child) {
                    if ($child->getName() === 'c') {
                        $cells[] = $child;
                    }
                }
            }
            
            $rowData = [];
            $lastCol = -1; // Traccia l'ultima colonna processata per il fallback sequenziale
            foreach ($cells as $cell) {
                // CRITICO: Leggi l'attributo 'r' (riferimento cella come "A1", "B1", etc.)
                // Prova MULTIPLE modalità per gestire diversi formati XML e namespace
                $cellRef = '';
                $t = '';

                // Metodo 1: Leggi tutti gli attributi iterando (più affidabile)
                $attributes = $cell->attributes();
                if ($attributes) {
                    foreach ($attributes as $key => $val) {
                        $keyStr = (string)$key;
                        if ($keyStr === 'r') {
                            $cellRef = (string)$val;
                        } elseif ($keyStr === 't') {
                            $t = (string)$val;
                        }
                    }
                }

                // Metodo 2: Accesso diretto se non trovato
                if ($cellRef === '' && isset($cell['r'])) {
                    $cellRef = (string)$cell['r'];
                }
                if ($t === '' && isset($cell['t'])) {
                    $t = (string)$cell['t'];
                }

                // Metodo 3: FALLBACK con regex sul XML raw se ancora vuoto
                if ($cellRef === '') {
                    $cellXml = $cell->asXML();
                    if (preg_match('/\br=["\']([A-Z]+\d+)["\']/', $cellXml, $matches)) {
                        $cellRef = $matches[1];
                    }
                }
                if ($t === '') {
                    $cellXml = isset($cellXml) ? $cellXml : $cell->asXML();
                    if (preg_match('/\bt=["\']([a-z])["\']/', $cellXml, $matches)) {
                        $t = $matches[1];
                    }
                }

                // Determina l'indice di colonna
                $col = 0;
                if ($cellRef !== '') {
                    // Usa il riferimento cella per determinare la posizione esatta
                    $col = $this->columnToIndex($cellRef);
                    $lastCol = $col;
                } else {
                    // Fallback: colonna successiva (NON dovrebbe mai accadere con XLSX validi)
                    $lastCol++;
                    $col = $lastCol;
                    // Log warning per debug
                    error_log("SimpleXLSX WARNING: Cella senza attributo 'r' in riga $rowNum, usando fallback col=$col. XML cell: " . $cell->asXML());
                }

                // DEBUG: Log primi riferimenti per verificare parsing
                static $debugCount = 0;
                if ($debugCount < 20) {
                    error_log("SimpleXLSX DEBUG: Row=$rowNum, CellRef='$cellRef', Col=$col, t='$t'");
                    $debugCount++;
                }

                $value = '';

                // Controlla se il valore è in shared strings
                if (isset($cell->v)) {
                    $cellValue = trim((string)$cell->v);
                    
                    // Se il tipo è 's' (stringa condivisa) e abbiamo shared strings
                    if ($t === 's' && !empty($sharedStrings) && is_numeric($cellValue)) {
                        $index = (int)$cellValue;
                        // Verifica che l'indice sia valido
                        if ($index >= 0 && $index < count($sharedStrings)) {
                            $sharedValue = $sharedStrings[$index];
                            if ($sharedValue !== '' && $sharedValue !== null) {
                                $value = $sharedValue;
                            } else {
                                // Se la shared string è vuota, usa il valore numerico come fallback
                                $value = $cellValue;
                            }
                        } else {
                            // Indice fuori range, usa il valore numerico
                            $value = $cellValue;
                        }
                    } else {
                        // Per tutti gli altri tipi (numeri, date, ecc.), usa il valore diretto
                        $value = $cellValue;
                    }
                }
                
                $rowData[$col] = $value;
                $lastCol = max($lastCol, $col);
            }
            
            // Riempi le celle mancanti con stringhe vuote fino a maxCol
            if (!empty($rowData)) {
                $currentMax = max(array_keys($rowData));
                $maxCol = max($maxCol, $currentMax);
                for ($i = 0; $i <= $maxCol; $i++) {
                    if (!isset($rowData[$i])) {
                        $rowData[$i] = '';
                    }
                }
                ksort($rowData);
            }

            $this->rows[] = $rowData; // NON usare array_values qui, preserva gli indici
        }

        // CRITICO: Normalizza TUTTE le righe alla stessa lunghezza
        // Usa il numero di colonne della PRIMA riga (header) come riferimento
        if (!empty($this->rows)) {
            // La prima riga (header) definisce il numero di colonne atteso
            $headerColCount = count($this->rows[0]);
            $finalColCount = max($maxCol + 1, $headerColCount);

            error_log("SimpleXLSX DEBUG: Normalizzazione finale - headerColCount=$headerColCount, maxCol=$maxCol, finalColCount=$finalColCount, totalRows=" . count($this->rows));

            foreach ($this->rows as $rowIdx => &$row) {
                $originalCount = count($row);
                // Assicura che ogni riga abbia tutte le colonne da 0 a finalColCount-1
                for ($i = 0; $i < $finalColCount; $i++) {
                    if (!isset($row[$i])) {
                        $row[$i] = '';
                    }
                }
                ksort($row);
                // Ora converti in array sequenziale (sicuro perché abbiamo tutte le chiavi)
                $row = array_values($row);

                // Debug: log se una riga aveva meno colonne
                if ($originalCount !== $finalColCount && $rowIdx < 5) {
                    error_log("SimpleXLSX DEBUG: Riga $rowIdx normalizzata da $originalCount a $finalColCount colonne");
                }
            }
            unset($row); // Rimuovi il riferimento
        }
    }
    
    /**
     * Trova ricorsivamente un nodo di testo 't' in un elemento
     */
    private function findTextNode($element, $ns = '') {
        // Cerca direttamente i figli 't'
        if ($ns) {
            foreach ($element->children($ns) as $child) {
                if ($child->getName() === 't') {
                    $text = (string)$child;
                    // Gestisci anche il caso in cui il testo sia in un CDATA
                    if ($text === '' && isset($child[0])) {
                        $text = (string)$child[0];
                    }
                    return $text;
                }
            }
        }
        
        foreach ($element->children() as $child) {
            if ($child->getName() === 't') {
                $text = (string)$child;
                // Gestisci anche il caso in cui il testo sia in un CDATA
                if ($text === '' && isset($child[0])) {
                    $text = (string)$child[0];
                }
                return $text;
            }
        }
        
        // Cerca ricorsivamente
        if ($ns) {
            foreach ($element->children($ns) as $child) {
                $result = $this->findTextNode($child, $ns);
                if ($result !== '') {
                    return $result;
                }
            }
        }
        
        foreach ($element->children() as $child) {
            $result = $this->findTextNode($child, $ns);
            if ($result !== '') {
                return $result;
            }
        }
        
        return '';
    }
    
    /**
     * Converte una colonna Excel (es. "A", "B", "AA") in indice numerico (0-based)
     */
    private function columnToIndex($cellRef) {
        if (empty($cellRef)) {
            return 0;
        }
        
        $col = preg_replace('/[0-9]/', '', $cellRef);
        $col = strtoupper(trim($col));
        
        if (empty($col)) {
            return 0;
        }
        
        $index = 0;
        $len = strlen($col);
        for ($i = 0; $i < $len; $i++) {
            $char = $col[$i];
            if ($char >= 'A' && $char <= 'Z') {
                $index = $index * 26 + (ord($char) - ord('A') + 1);
            }
        }
        
        return max(0, $index - 1);
    }
    
    /**
     * Restituisce tutte le righe come array
     * @return array Array di righe, ogni riga è un array di celle
     */
    public function rows() {
        return $this->rows;
    }
    
    /**
     * Restituisce i nomi dei fogli
     * @return array Array di nomi dei fogli
     */
    public function sheetNames() {
        return $this->sheetNames;
    }
}

