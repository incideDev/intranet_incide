<?php
namespace Services;

/**
 * DatasourceService
 */
class DatasourceService
{
    public static function getOptions(array $input): array
    {
        global $database;
        $table = trim((string) ($input['table'] ?? ''));
        if (!$table) {
            return ['success' => false, 'message' => "Tabella mancante"];
        }

        // Whitelist check
        $wl = self::getTableInfo($table);
        if (!$wl || !$wl['is_active']) {
            return ['success' => false, 'message' => "Tabella non autorizzata"];
        }

        $val = preg_replace('/[^a-zA-Z0-9_]/', '', $input['valueCol'] ?? 'id');
        $lab = preg_replace('/[^a-zA-Z0-9_]/', '', $input['labelCol'] ?? 'descrizione');

        // Column check
        if (!empty($wl['visible_columns'])) {
            $allowed = json_decode($wl['visible_columns'], true);
            if (is_array($allowed) && (!in_array($val, $allowed) || !in_array($lab, $allowed))) {
                return ['success' => false, 'message' => "Colonna non autorizzata"];
            }
        }

        $sql = "SELECT DISTINCT `$val` as v, `$lab` as l FROM `$table` ORDER BY `$lab` LIMIT 200";
        try {
            $res = $database->query($sql, [], __FILE__ . " ==> " . __LINE__)->fetchAll(\PDO::FETCH_ASSOC);
            return ['success' => true, 'options' => $res];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function adminListTables(): array
    {
        global $database;
        // Info Schema
        $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name ASC";
        $all = $database->query($sql, [], __FILE__ . " ==> " . __LINE__)->fetchAll(\PDO::FETCH_COLUMN);

        // Whitelist
        $wlRows = $database->query("SELECT * FROM sys_db_whitelist", [], __FILE__ . " ==> " . __LINE__)->fetchAll(\PDO::FETCH_ASSOC);
        $wlMap = [];
        foreach ($wlRows as $r)
            $wlMap[$r['table_name']] = $r;

        $res = [];
        foreach ($all as $t) {
            $info = $wlMap[$t] ?? null;
            $res[] = [
                'name' => $t,
                'is_active' => $info ? (bool) $info['is_active'] : false,
                'has_column_rules' => $info && !empty($info['visible_columns'])
            ];
        }
        return ['success' => true, 'tables' => $res];
    }

    public static function adminListColumns(string $table): array
    {
        global $database;
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $sql = "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$table' ORDER BY ordinal_position";
        $cols = $database->query($sql, [], __FILE__ . " ==> " . __LINE__)->fetchAll(\PDO::FETCH_ASSOC);

        $info = self::getTableInfo($table);
        $allowed = ($info && !empty($info['visible_columns'])) ? json_decode($info['visible_columns'], true) : null;

        $res = [];
        foreach ($cols as $c) {
            $res[] = [
                'name' => $c['column_name'],
                'type' => $c['data_type'],
                'is_active' => is_null($allowed) ? true : in_array($c['column_name'], $allowed)
            ];
        }
        return ['success' => true, 'columns' => $res];
    }

    public static function adminToggleTable(string $table, bool $active): array
    {
        global $database;
        $info = self::getTableInfo($table);
        $act = $active ? 1 : 0;
        if ($info) {
            $database->query("UPDATE sys_db_whitelist SET is_active = $act WHERE table_name = '$table'", [], __FILE__ . " ==> " . __LINE__);
        } else {
            $database->query("INSERT INTO sys_db_whitelist (table_name, is_active) VALUES ('$table', $act)", [], __FILE__ . " ==> " . __LINE__);
        }
        return ['success' => true];
    }

    public static function adminUpdateColumns(string $table, ?array $cols): array
    {
        global $database;
        $json = is_array($cols) ? json_encode($cols) : 'NULL';
        $jsonVal = is_array($cols) ? "'$json'" : "NULL";

        $info = self::getTableInfo($table);
        if ($info) {
            $database->query("UPDATE sys_db_whitelist SET visible_columns = $jsonVal WHERE table_name = '$table'", [], __FILE__ . " ==> " . __LINE__);
        } else {
            $database->query("INSERT INTO sys_db_whitelist (table_name, is_active, visible_columns) VALUES ('$table', 0, $jsonVal)", [], __FILE__ . " ==> " . __LINE__);
        }
        return ['success' => true];
    }

    // New Helper for PageEditor
    public static function getWhitelistedTables(): array
    {
        global $database;
        $rows = $database->query("SELECT table_name FROM sys_db_whitelist WHERE is_active = 1 ORDER BY table_name", [], __FILE__ . " ==> " . __LINE__)->fetchAll(\PDO::FETCH_COLUMN);
        return ['success' => true, 'tables' => $rows];
    }

    /**
     * Risolve in batch le label per campi dbselect.
     * Input: { "fields": [ { "table": "...", "valueCol": "...", "labelCol": "...", "value": "..." }, ... ] }
     * Output: { "success": true, "resolved": { "0": "Label1", "1": "Label2", ... } }
     */
    public static function resolveDbselectValues(array $input): array
    {
        global $database;

        $fields = $input['fields'] ?? [];
        if (!is_array($fields) || empty($fields)) {
            return ['success' => false, 'message' => 'Parametro fields mancante o vuoto'];
        }

        $resolved = [];
        foreach ($fields as $idx => $field) {
            $table = preg_replace('/[^a-zA-Z0-9_]/', '', trim((string) ($field['table'] ?? '')));
            $valueCol = preg_replace('/[^a-zA-Z0-9_]/', '', trim((string) ($field['valueCol'] ?? 'id')));
            $labelCol = preg_replace('/[^a-zA-Z0-9_]/', '', trim((string) ($field['labelCol'] ?? '')));
            $value = $field['value'] ?? '';

            if (!$table || !$valueCol || !$labelCol || $value === '' || $value === null) {
                $resolved[(string) $idx] = '';
                continue;
            }

            // Whitelist check
            $wl = self::getTableInfo($table);
            if (!$wl || !$wl['is_active']) {
                $resolved[(string) $idx] = (string) $value;
                continue;
            }

            // Column check
            if (!empty($wl['visible_columns'])) {
                $allowed = json_decode($wl['visible_columns'], true);
                if (is_array($allowed) && (!in_array($valueCol, $allowed) || !in_array($labelCol, $allowed))) {
                    $resolved[(string) $idx] = (string) $value;
                    continue;
                }
            }

            // Gestione multi-value (es. "3,5,7")
            $values = array_map('trim', explode(',', (string) $value));
            $values = array_filter($values, function ($v) { return $v !== ''; });

            if (empty($values)) {
                $resolved[(string) $idx] = '';
                continue;
            }

            try {
                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $sql = "SELECT `$valueCol` as v, `$labelCol` as l FROM `$table` WHERE `$valueCol` IN ($placeholders) LIMIT 50";
                $rows = $database->query($sql, array_values($values), __FILE__ . ' resolveDbselectValues')->fetchAll(\PDO::FETCH_ASSOC);

                $labelMap = [];
                foreach ($rows as $row) {
                    $labelMap[(string) $row['v']] = (string) $row['l'];
                }

                $labels = [];
                foreach ($values as $v) {
                    $labels[] = $labelMap[(string) $v] ?? (string) $v;
                }
                $resolved[(string) $idx] = implode(', ', $labels);
            } catch (\Throwable $e) {
                $resolved[(string) $idx] = (string) $value;
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    error_log("[resolveDbselectValues] errore per table=$table: " . $e->getMessage());
                }
            }
        }

        return ['success' => true, 'resolved' => $resolved];
    }

    private static function getTableInfo($t)
    {
        global $database;
        return $database->query("SELECT * FROM sys_db_whitelist WHERE table_name = '$t'", [], __FILE__ . " ==> " . __LINE__)->fetch(\PDO::FETCH_ASSOC);
    }
}
