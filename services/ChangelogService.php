<?php
namespace Services;

if (!defined('AccessoFileInterni')) {
    header("HTTP/1.0 404 Not Found");
    include("page-errors/404.php");
    die();
}

class ChangelogService {

    public static function getLatest(): array
    {
        global $database;
        $stmt = $database->query(
            "SELECT * FROM changelog ORDER BY data DESC, id DESC LIMIT 10",
            [],
            __FILE__
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $output = [];

        foreach ($rows as &$row) {
            $row['data'] = $database->formatDate($row['data']);
            $output[] = $row;
        }

        return ['success' => true, 'data' => $output];
    }

    public static function getAll(): array
    {
        global $database;
        $stmt = $database->query(
            "SELECT * FROM changelog ORDER BY data DESC, id DESC",
            [],
            __FILE__
        );

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $output = [];

        foreach ($rows as &$row) {
            $row['data'] = $database->formatDate($row['data']);
            $output[] = $row;
        }
        return ['success' => true, 'data' => $output];
    }

    public static function addChangelog(array $input): array
    {
        global $database;

        $titolo       = trim(strip_tags($input['titolo'] ?? ''));
        $descrizione  = trim($input['descrizione'] ?? '');
        $data         = $input['data'] ?? date('Y-m-d');
        $sezione      = isset($input['sezione']) ? trim($input['sezione']) : null;
        $url          = isset($input['url']) ? trim($input['url']) : null;

        // VERSIONAMENTO
        $row = $database->query("SELECT versione_major, versione_minor FROM changelog ORDER BY versione_major DESC, versione_minor DESC, id DESC LIMIT 1", [], __FILE__)->fetch(\PDO::FETCH_ASSOC);
        $major = (int)($row['versione_major'] ?? 0);
        $minor = (int)($row['versione_minor'] ?? 0);

        if (!empty($input['nuova_major'])) {
            $major += 1;
            $minor = 0;
        } else {
            $minor += 1;
        }

        if (!$titolo || !$descrizione) {
            return [
                'success' => false,
                'message' => 'Titolo e descrizione sono obbligatori.'
            ];
        }

        $sql = "INSERT INTO changelog (titolo, descrizione, data, sezione, url, versione_major, versione_minor)
                VALUES (:titolo, :descrizione, :data, :sezione, :url, :versione_major, :versione_minor)";

        $params = [
            ':titolo'          => $titolo,
            ':descrizione'     => $descrizione,
            ':data'            => $data,
            ':sezione'         => $sezione,
            ':url'             => $url,
            ':versione_major'  => $major,
            ':versione_minor'  => $minor
        ];

        $ok = $database->query($sql, $params, __FILE__);

        if ($ok) {
            // Invia una notifica a tutti gli utenti attivi
            require_once substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/services')) . '/services/NotificationService.php';
            $utenti = $database->query("SELECT id, username FROM users WHERE disabled = 0", [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

            $titoloSafe = htmlspecialchars($titolo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $msg = '<span class="notifica-evidenza notifica-intranet-news"><img src="assets/icons/upgrade.png" alt="Aggiornamento Intranet" class="icona-intranet-news"> La Intranet si aggiorna!</span> Guarda cos’è cambiato: ' . $titoloSafe;
            $link = "index.php?section=changelog&page=changelog";

            foreach ($utenti as $user) {
                \Services\NotificationService::inviaNotifica($user['id'], $msg, $link);
            }
        }

        return ['success' => $ok !== false];
    }

    public static function deleteChangelog($id) {
        global $database;
        $id = intval($id);
        if ($id <= 0) {
            return ['success' => false, 'message' => 'ID non valido'];
        }
        $sql = "DELETE FROM changelog WHERE id = :id";
        $params = [':id' => $id];
        $ok = $database->query($sql, $params, __FILE__);
        return ['success' => $ok !== false];
    }

    public static function getNextVersion($input = []): array
    {
        global $database;

        // recupera l'ultima versione
        $row = $database->query("SELECT versione_major, versione_minor FROM changelog ORDER BY versione_major DESC, versione_minor DESC, id DESC LIMIT 1", [], __FILE__)->fetch(\PDO::FETCH_ASSOC);

        $major = (int)($row['versione_major'] ?? 0);
        $minor = (int)($row['versione_minor'] ?? 0);

        // se nuova major, incrementa major e azzera minor
        if (!empty($input['nuova_major'])) {
            $major += 1;
            $minor = 0;
        } else {
            $minor += 1;
        }

        $version = $major . '.' . $minor;
        return ['success' => true, 'data' => $version];
    }

}
