<?php
namespace Services;

if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

class VisibilitaSezioniService {

    public static function getConfig($input) {
        global $database;

        $sezione = $input['sezione'] ?? '';
        $sezione = preg_replace('/[^a-z0-9_]/i', '', $sezione);

        if (!$sezione) {
            return ['success' => false, 'message' => 'Sezione non valida'];
        }

        $query = "SELECT sezione, blocco, ruolo_id FROM visibilita_sezioni WHERE sezione = ?";
        $results = $database->query($query, [$sezione], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        return ['success' => true, 'config' => $results];
    }

    public static function saveConfig($input) {
        global $database;

        $sezione = $input['sezione'] ?? '';
        $sezione = preg_replace('/[^a-z0-9_]/i', '', $sezione);
        $config = $input['config'] ?? [];

        if (!$sezione || !is_array($config)) {
            return ['success' => false, 'message' => 'Input non valido'];
        }

        // Rimuove le entry esistenti per la sezione
        $database->query("DELETE FROM visibilita_sezioni WHERE sezione = ?", [$sezione], __FILE__);

        // Inserisce le nuove configurazioni
        foreach ($config as $entry) {
            $blocco = preg_replace('/[^a-z0-9_]/i', '', $entry['blocco'] ?? '');
            $ruoloId = intval($entry['ruolo_id'] ?? 0);

            if ($blocco && $ruoloId > 0) {
                $database->query(
                    "INSERT INTO visibilita_sezioni (sezione, blocco, ruolo_id) VALUES (?, ?, ?)",
                    [$sezione, $blocco, $ruoloId],
                    __FILE__
                );
            }
        }

        return ['success' => true];
    }
}
