<?php
namespace Services;

class FilterService
{
    /**
     * Ottiene filtri dinamici per una tabella
     * 
     * @param string $table Nome tabella
     * @param array $columnsRequested Colonne richieste (nomi logici)
     * @return array { success: bool, filters: array }
     */
    public static function getDynamicFilters($table, $columnsRequested = [])
    {
        global $database;

        // Tabelle permesse con mapping nomi logici → nomi reali
        $allowedTables = [
            "elenco_bandi_gare" => [
                "table" => "elenco_bandi_gare",
                "columns" => [
                    "tipo" => "tipo_lavori",
                    "stato" => "status_id",
                    "responsabile" => "responsabili",
                    "ente" => "ente",
                    "luogo" => "luogo",
                    "n_gara" => "n_gara"
                ]
            ],
            "commesse" => [
                "table" => "elenco_commesse",
                "columns" => [
                    "responsabile" => "responsabile_commessa",
                    "stato" => "stato",
                    "tipo" => "business_unit"
                ]
            ],
            "forms" => [
                "table" => "forms",
                "columns" => [
                    "form_name" => "name",
                    "status_id" => "status_id",
                    "responsabile" => "responsabile",
                    "color" => "color"
                ]
            ]
        ];

        if (!isset($allowedTables[$table])) {
            return ["success" => false, "message" => "Tabella non valida"];
        }

        $tableConfig = $allowedTables[$table];
        $realTable = $tableConfig["table"];
        $columnMap = $tableConfig["columns"];

        try {
            // Verifica colonne esistenti
            $existingColumns = $database->query("SHOW COLUMNS FROM `$realTable`", [], __FILE__)
                ->fetchAll(\PDO::FETCH_COLUMN);

            $filters = [];

            foreach ($columnsRequested as $requested) {
                if (!isset($columnMap[$requested]))
                    continue;

                $realColumn = $columnMap[$requested];
                if (!in_array($realColumn, $existingColumns))
                    continue;

                $res = $database->query(
                    "SELECT DISTINCT `$realColumn` FROM `$realTable` 
                     WHERE `$realColumn` IS NOT NULL AND `$realColumn` <> '' 
                     ORDER BY `$realColumn` 
                     LIMIT 100",
                    [],
                    __FILE__
                );
                $values = $res->fetchAll(\PDO::FETCH_COLUMN);

                if (!empty($values)) {
                    // Converte user_id in nominativi per colonne responsabile/assegnato
                    if (in_array($realColumn, ['responsabile', 'assegnato_a', 'creato_da', 'responsabili'])) {
                        $values = array_map(function ($user_id) use ($database) {
                            if (!is_numeric($user_id))
                                return $user_id;
                            return $database->getNominativoByUserId($user_id) ?? $user_id;
                        }, $values);
                        $values = array_unique($values);
                    }

                    // Converte status_id in stringhe per le gare
                    if ($realColumn === 'status_id' && $realTable === 'elenco_bandi_gare') {
                        $statusMap = [
                            1 => 'Bozza',
                            2 => 'Pubblicata',
                            3 => 'In Valutazione',
                            4 => 'Aggiudicata',
                            5 => 'Archiviata'
                        ];
                        $values = array_map(function ($id) use ($statusMap) {
                            return $statusMap[$id] ?? $id;
                        }, $values);
                    }

                    $filters[$requested] = array_values($values);
                }
            }

            return ["success" => true, "filters" => $filters];
        } catch (\Exception $e) {
            return ["success" => false, "message" => "Errore: " . $e->getMessage()];
        }
    }

    public static function getFilteredData($table, $filters = [])
    {
        global $database;

        $allowedTables = [
            "elenco_bandi_gare" => "elenco_bandi_gare",
            "commesse" => "elenco_commesse"
        ];

        if (!isset($allowedTables[$table])) {
            return ["success" => false, "message" => "Tabella non valida"];
        }

        $table = $allowedTables[$table];

        $whereClauses = [];
        $params = [];

        // ✅ Filtra per date
        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            $whereClauses[] = "DATE(data_scadenza) BETWEEN :from_date AND :to_date";
            $params['from_date'] = $filters['from_date'];
            $params['to_date'] = $filters['to_date'];
        }

        foreach ($filters as $column => $value) {
            if (!empty($value) && !in_array($column, ['from_date', 'to_date'])) {
                $whereClauses[] = "`$column` = :$column";
                $params[$column] = $value;
            }
        }

        $whereSQL = $whereClauses ? "WHERE " . implode(" AND ", $whereClauses) : "";

        try {
            $stmt = $database->query("SELECT * FROM `$table` $whereSQL", $params, __FILE__);
            $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return ["success" => true, "data" => $data];
        } catch (\Exception $e) {
            return ["success" => false, "message" => "Errore: " . $e->getMessage()];
        }
    }
}
