<?php

namespace Services;

/**
 * Servizio unificato per tabelle server-side riutilizzabili.
 * Gestisce query, filtri, paginazione, ordinamento e facets in modo totalmente dinamico.
 * 
 * Principi:
 * - Server-side totale: nessun filtro basato su DOM
 * - Whitelist rigida per tabelle e colonne
 * - Sanificazione completa di tutti gli input
 * - PDO prepared statements per sicurezza
 */
class TableService
{
    /**
     * Whitelist delle tabelle consentite e relative configurazioni.
     * Struttura: ['nome_tabella' => ['columns' => [...], 'sortable' => [...], 'filterable' => [...]]]
     * 
     * IMPORTANTE: Aggiungere qui tutte le tabelle che devono essere utilizzabili.
     */
    private static $tableWhitelist = [
        'gar_comprovanti_prestazioni_catalogo' => [
            'columns' => ['codice', 'macro_categoria', 'prestazione', 'ordine', 'id'],
            'filterable' => ['codice', 'macro_categoria', 'prestazione'],
            'sortable' => ['codice', 'macro_categoria', 'prestazione', 'ordine'],
            'primary_key' => 'id',
        ],
        'gar_fatturato_annuale' => [
            'columns' => ['anno', 'fatturato', 'id'],
            'filterable' => ['anno', 'fatturato'],
            'sortable' => ['anno', 'fatturato'],
            'primary_key' => 'id',
        ],
        // Aggiungere altre tabelle qui seguendo lo stesso pattern
    ];

    /**
     * Valida che una tabella sia nella whitelist
     */
    private static function validateTable(string $tableName): array
    {
        if (!isset(self::$tableWhitelist[$tableName])) {
            throw new \InvalidArgumentException("Tabella '{$tableName}' non consentita");
        }
        return self::$tableWhitelist[$tableName];
    }

    /**
     * Sanifica nome tabella/colonna per prevenire SQL injection
     */
    private static function sanitizeIdentifier(string $identifier): string
    {
        // Permette solo caratteri alfanumerici e underscore
        return preg_replace('/[^a-z0-9_]/i', '', $identifier);
    }

    /**
     * Sanifica valore per LIKE query
     */
    private static function sanitizeLikeValue(string $value): string
    {
        // Rimuove caratteri pericolosi per LIKE
        $value = str_replace(['%', '_'], ['\\%', '\\_'], $value);
        return trim($value);
    }

    /**
     * Esegue query server-side con filtri, paginazione e ordinamento
     * 
     * @param array $params Parametri della richiesta:
     *   - table: nome tabella (richiesto)
     *   - page: pagina corrente (default: 1)
     *   - pageSize: righe per pagina (default: 25)
     *   - filters: array associativo colonna => valore filtro
     *   - sort: colonna ordinamento (default: prima colonna sortable)
     *   - dir: direzione ordinamento 'asc'|'desc' (default: 'asc')
     *   - search: testo ricerca globale (opzionale)
     * 
     * @return array ['ok' => bool, 'rowsHtml' => string, 'page' => int, 'pageSize' => int, 'totalRows' => int, 'totalPages' => int]
     */
    public static function query(array $params): array
    {
        $database = $GLOBALS['database'] ?? null;
        $pdo = $database ? ($database->connection ?? null) : null;

        if (!$pdo) {
            throw new \RuntimeException('Connessione al database non disponibile');
        }

        // Validazione e sanificazione parametri
        $tableName = self::sanitizeIdentifier($params['table'] ?? '');
        if (empty($tableName)) {
            throw new \InvalidArgumentException('Nome tabella mancante');
        }

        $config = self::validateTable($tableName);
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['pageSize'] ?? 25)));
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $sortColumn = self::sanitizeIdentifier($params['sort'] ?? '');
        $sortDir = strtolower($params['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $search = trim($params['search'] ?? '');

        // Validazione colonna ordinamento
        if (empty($sortColumn) || !in_array($sortColumn, $config['sortable'], true)) {
            $sortColumn = $config['sortable'][0] ?? $config['columns'][0] ?? 'id';
        }
        if (!in_array($sortColumn, $config['sortable'], true)) {
            throw new \InvalidArgumentException("Colonna '{$sortColumn}' non ordinabile");
        }

        // Costruzione WHERE clause
        $whereConditions = [];
        $whereParams = [];

        // Filtri per colonna
        foreach ($filters as $column => $value) {
            $column = self::sanitizeIdentifier($column);
            if (empty($column) || !in_array($column, $config['filterable'], true)) {
                continue; // Ignora colonne non filtrabili
            }

            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            $whereConditions[] = "`{$column}` LIKE :filter_{$column}";
            $whereParams[":filter_{$column}"] = '%' . self::sanitizeLikeValue($value) . '%';
        }

        // Ricerca globale (su tutte le colonne filtrabili)
        if (!empty($search)) {
            $searchConditions = [];
            foreach ($config['filterable'] as $col) {
                $searchConditions[] = "`{$col}` LIKE :search_{$col}";
                $whereParams[":search_{$col}"] = '%' . self::sanitizeLikeValue($search) . '%';
            }
            if (!empty($searchConditions)) {
                $whereConditions[] = '(' . implode(' OR ', $searchConditions) . ')';
            }
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        // Query COUNT per totalRows
        $countSql = "SELECT COUNT(*) as total FROM `{$tableName}` {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        foreach ($whereParams as $key => $value) {
            $countStmt->bindValue($key, $value, \PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalRows = (int)$countStmt->fetch(\PDO::FETCH_ASSOC)['total'];
        $totalPages = max(1, (int)ceil($totalRows / $pageSize));

        // Query dati con LIMIT/OFFSET
        $offset = ($page - 1) * $pageSize;
        $dataSql = "SELECT * FROM `{$tableName}` {$whereClause} ORDER BY `{$sortColumn}` {$sortDir} LIMIT :limit OFFSET :offset";
        $dataStmt = $pdo->prepare($dataSql);
        
        foreach ($whereParams as $key => $value) {
            $dataStmt->bindValue($key, $value, \PDO::PARAM_STR);
        }
        $dataStmt->bindValue(':limit', $pageSize, \PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Determina quali colonne visualizzare
        // Se viene passato un array 'columns' nei parametri, usa quello (dalla data-columns del frontend)
        // Altrimenti usa tutte le colonne dalla config (escludendo 'id' se presente)
        $displayColumns = $params['columns'] ?? null;
        if (is_array($displayColumns) && !empty($displayColumns)) {
            // Valida che tutte le colonne richieste siano nella whitelist
            $validColumns = [];
            foreach ($displayColumns as $col) {
                $colKey = is_array($col) ? ($col['key'] ?? '') : $col;
                $colKey = self::sanitizeIdentifier($colKey);
                if (in_array($colKey, $config['columns'], true)) {
                    $validColumns[] = $colKey;
                }
            }
            $displayColumns = !empty($validColumns) ? $validColumns : array_diff($config['columns'], ['id']);
        } else {
            // Escludi 'id' dalle colonne visualizzate di default
            $displayColumns = array_diff($config['columns'], ['id']);
        }

        // Genera HTML righe (deve essere personalizzabile per tabella)
        $rowsHtml = self::generateRowsHtml($tableName, $rows, $displayColumns);

        return [
            'ok' => true,
            'rowsHtml' => $rowsHtml,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalRows' => $totalRows,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Restituisce valori distinti per una colonna (per dropdown filtri)
     * 
     * @param array $params Parametri:
     *   - table: nome tabella (richiesto)
     *   - column: nome colonna (richiesto)
     *   - filters: filtri attivi da applicare (opzionale)
     *   - limit: limite massimo valori (default: 200)
     * 
     * @return array ['ok' => bool, 'values' => array]
     */
    public static function facets(array $params): array
    {
        $database = $GLOBALS['database'] ?? null;
        $pdo = $database ? ($database->connection ?? null) : null;

        if (!$pdo) {
            throw new \RuntimeException('Connessione al database non disponibile');
        }

        $tableName = self::sanitizeIdentifier($params['table'] ?? '');
        if (empty($tableName)) {
            throw new \InvalidArgumentException('Nome tabella mancante');
        }

        $config = self::validateTable($tableName);
        $column = self::sanitizeIdentifier($params['column'] ?? '');
        
        if (empty($column) || !in_array($column, $config['filterable'], true)) {
            throw new \InvalidArgumentException("Colonna '{$column}' non filtrabile");
        }

        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $limit = max(1, min(200, (int)($params['limit'] ?? 200)));

        // Costruzione WHERE per filtri esistenti (escludendo la colonna corrente)
        $whereConditions = [];
        $whereParams = [];

        foreach ($filters as $col => $value) {
            $col = self::sanitizeIdentifier($col);
            if ($col === $column || empty($col) || !in_array($col, $config['filterable'], true)) {
                continue;
            }

            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            $whereConditions[] = "`{$col}` LIKE :filter_{$col}";
            $whereParams[":filter_{$col}"] = '%' . self::sanitizeLikeValue($value) . '%';
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        // Query valori distinti
        $sql = "SELECT DISTINCT `{$column}` as value FROM `{$tableName}` {$whereClause} ORDER BY `{$column}` ASC LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        
        foreach ($whereParams as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        $values = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $val = trim($row['value'] ?? '');
            if ($val !== '') {
                $values[] = $val;
            }
        }

        return [
            'ok' => true,
            'values' => $values,
        ];
    }

    /**
     * Genera HTML per le righe della tabella.
     * Questo metodo può essere esteso per personalizzare il rendering per tabella.
     * 
     * @param string $tableName Nome tabella
     * @param array $rows Array di righe dal database
     * @param array $displayColumns Array di nomi colonne da visualizzare
     * @return string HTML delle righe <tr>...</tr>
     */
    private static function generateRowsHtml(string $tableName, array $rows, array $displayColumns): string
    {
        $html = '';
        
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($displayColumns as $col) {
                $value = htmlspecialchars($row[$col] ?? '', ENT_QUOTES, 'UTF-8');
                $html .= "<td>{$value}</td>";
            }
            $html .= '</tr>';
        }

        return $html;
    }

    /**
     * Registra una nuova tabella nella whitelist.
     * Utile per aggiungere tabelle dinamicamente senza modificare la costante.
     * 
     * @param string $tableName Nome tabella
     * @param array $config Configurazione ['columns' => [...], 'filterable' => [...], 'sortable' => [...]]
     */
    public static function registerTable(string $tableName, array $config): void
    {
        // Validazione configurazione
        if (empty($config['columns']) || !is_array($config['columns'])) {
            throw new \InvalidArgumentException("Configurazione non valida per tabella '{$tableName}': columns richiesto");
        }

        $tableName = self::sanitizeIdentifier($tableName);
        
        // Aggiungi alla whitelist (in runtime)
        // Nota: in produzione potresti voler salvare questo in database o file config
        self::$tableWhitelist[$tableName] = [
            'columns' => array_map([self::class, 'sanitizeIdentifier'], $config['columns']),
            'filterable' => array_map([self::class, 'sanitizeIdentifier'], $config['filterable'] ?? $config['columns']),
            'sortable' => array_map([self::class, 'sanitizeIdentifier'], $config['sortable'] ?? $config['columns']),
            'primary_key' => self::sanitizeIdentifier($config['primary_key'] ?? 'id'),
        ];
    }
}

