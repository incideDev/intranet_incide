<?php
namespace Services;

if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    exit;
}

require_once substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/services')) . '/core/session.php';

class OfficeMapService
{
    public static function getPositions(string $floor) {
        global $database;

        $floor = filter_var($floor, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (!$floor) return ['success' => false, 'error' => 'Parametro "floor" mancante'];

        $rows = $database->query("
            SELECT 
                p.user_id, 
                p.x_position, 
                p.y_position, 
                p.interno, 
                u.Nominativo AS nominativo, 
                u.Ruolo AS ruolo,
                u.Email_Aziendale AS email_aziendale,
                u.Cellulare_Aziendale AS cellulare_aziendale
            FROM office_positions p
            LEFT JOIN personale u ON p.user_id = u.user_id
            WHERE p.floor = :floor AND u.attivo = 1
        ", ['floor' => $floor], __FILE__);

        $positions = [];
        foreach ($rows as $pos) {
            $img = getProfileImage($pos['nominativo'] ?? '');

            $positions[] = array_merge($pos, [
                'profile_image' => $img ?: '/assets/images/default_profile.png'
            ]);            
        }

        return ['success' => true, 'positions' => $positions];
    }

    public static function getPostazioni(string $floor) {
        global $database;

        $floor = filter_var($floor, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (!$floor) return ['success' => false, 'error' => 'Parametro "floor" mancante'];

        $stmt = $database->query("SELECT * FROM office_postazioni WHERE floor = :floor", ['floor' => $floor], __FILE__);
        return ['success' => true, 'postazioni' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    public static function getAvailableContacts(bool $all) {
        global $database;

        $all = (bool) $all;

        $query = $all
            ? "SELECT user_id, Nominativo, attivo FROM personale WHERE user_id IS NOT NULL ORDER BY Nominativo ASC"
            : "SELECT user_id, Nominativo, attivo FROM personale
               WHERE user_id IS NOT NULL AND (
                   (attivo = 1 AND user_id NOT IN (SELECT user_id FROM office_positions))
                   OR attivo = 0
               )
               ORDER BY Nominativo ASC";

        $contacts = $database->query($query, [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        $base = substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/services')) . '/assets/images/profile_pictures/';
        $rel = '/assets/images/profile_pictures/';

        foreach ($contacts as &$contact) {
            $contact['profile_image'] = getProfileImage($contact['Nominativo']);
        }
        
        return ['success' => true, 'contacts' => $contacts];
    }

    public static function archiveContact(int $user_id) {
        global $database;
        $user_id = filter_var($user_id, FILTER_SANITIZE_NUMBER_INT);
        if (!$user_id) return ['success' => false, 'error' => 'ID utente mancante'];

        $exists = $database->query("SELECT user_id FROM personale WHERE user_id = :id", ['id' => $user_id], __FILE__)->fetch();
        if (!$exists) return ['success' => false, 'error' => 'Utente non trovato'];

        $database->query("UPDATE personale SET attivo = 0 WHERE user_id = :id", ['id' => $user_id], __FILE__);
        $database->query("DELETE FROM office_positions WHERE user_id = :id", ['id' => $user_id], __FILE__);

        return ['success' => true, 'message' => 'Utente archiviato e rimosso dalla mappa.'];
    }

    public static function deletePostazione(string $floor, string $interno) {
        global $database;

        $floor = filter_var($floor, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $interno = filter_var($interno, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        
        if (!$floor || !$interno) {
            return ['success' => false, 'error' => 'Dati mancanti: floor o interno non forniti.'];
        }

        $database->query(
            "DELETE FROM office_postazioni WHERE floor = :floor AND interno = :interno",
            ['floor' => $floor, 'interno' => $interno],
            __FILE__
        );

        return ['success' => true];
    }

    public static function changeFloor(int $user_id, string $new_floor) {
        global $database;

        $user_id = filter_var($user_id, FILTER_SANITIZE_NUMBER_INT);
        $new_floor = filter_var($new_floor, FILTER_SANITIZE_FULL_SPECIAL_CHARS);        

        if (!$user_id || !$new_floor) {
            return ['success' => false, 'error' => 'Dati mancanti o non validi.'];
        }

        $res = $database->query("
            UPDATE office_positions 
            SET floor = :new_floor 
            WHERE user_id = :user_id
        ", ['new_floor' => $new_floor, 'user_id' => $user_id], __FILE__);

        return ['success' => true];
    }

    public static function savePosition(int $user_id, string $floor, float $x, float $y, ?string $interno) {
        global $database;

        $user_id = filter_var($user_id, FILTER_SANITIZE_NUMBER_INT);
        $floor = filter_var($floor, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $interno = $interno !== null ? filter_var($interno, FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
        
        if (!$user_id || !$floor) return ['success' => false, 'error' => 'Parametri mancanti'];

        // Riattiva sempre l'utente quando viene salvato sulla mappa
        $database->query("UPDATE personale SET attivo = 1 WHERE user_id = :id", ['id' => $user_id], __FILE__);

        $sql = "
            INSERT INTO office_positions (user_id, floor, x_position, y_position, interno, updated_at)
            VALUES (:user_id, :floor, :x, :y, :interno, NOW())
            ON DUPLICATE KEY UPDATE
                floor = VALUES(floor),
                x_position = VALUES(x_position),
                y_position = VALUES(y_position),
                interno = VALUES(interno),
                updated_at = NOW()
        ";

        $database->query($sql, [
            'user_id' => $user_id,
            'floor' => $floor,
            'x' => $x,
            'y' => $y,
            'interno' => $interno
        ], __FILE__);

        return ['success' => true, 'message' => 'Posizione salvata e utente riattivato'];
    }

    public static function removePositions(int $user_id) {
        global $database;

        $user_id = filter_var($user_id, FILTER_SANITIZE_NUMBER_INT);
        if (!$user_id) return ['success' => false, 'error' => 'ID mancante.'];

        $database->query("DELETE FROM office_positions WHERE user_id = :id", ['id' => $user_id], __FILE__);
        return ['success' => true];
    }
public static function savePostazione(
    string $floor,
    string $interno,
    float $x,
    float $y,
    float $width,
    float $height,
    ?string $old_interno = null,
    ?int $id = null
) {
    global $database;

    // -- Sanitizzazione ------------------------------------------------------
    $floor    = filter_var($floor,    FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $interno  = filter_var($interno,  FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $old_int  = $old_interno !== null ? filter_var($old_interno, FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
    $id       = $id   ? filter_var($id,   FILTER_SANITIZE_NUMBER_INT) : null;

    // -----------------------------------------------------------------------
    // 1.  Se ho l’ID ⇒ UPDATE secco su quella riga e STOP
    // -----------------------------------------------------------------------
    if ($id) {
        $database->query(
            "UPDATE office_postazioni
             SET floor = :floor,
                 interno = :interno,
                 x_position = :x,
                 y_position = :y,
                 width  = :w,
                 height = :h
             WHERE id = :id",
            [
                'floor'   => $floor,
                'interno' => $interno,
                'x'       => $x,
                'y'       => $y,
                'w'       => $width,
                'h'       => $height,
                'id'      => $id
            ],
            __FILE__
        );

        return ['success' => true];
    }

    // -----------------------------------------------------------------------
    // 2.  Se NON ho ID…
    //     a) provo a fare UPDATE su (floor, interno)
    //     b) se non trovo nulla e c’è old_interno ⇒ UPDATE su (floor, old_interno)
    //     c) se ancora nulla ⇒ INSERT
    // -----------------------------------------------------------------------

    // a) UPDATE su (floor, interno)
    $update = $database->query(
        "UPDATE office_postazioni
         SET x_position = :x,
             y_position = :y,
             width  = :w,
             height = :h
         WHERE floor = :floor AND interno = :interno",
        [
            'x'       => $x,
            'y'       => $y,
            'w'       => $width,
            'h'       => $height,
            'floor'   => $floor,
            'interno' => $interno
        ],
        __FILE__
    );

    if ($update->rowCount() > 0) {
        return ['success' => true];
    }

    // b) UPDATE su (floor, old_interno) se esiste ed è diverso
    if ($old_int && $old_int !== $interno) {
        $updateOld = $database->query(
            "UPDATE office_postazioni
             SET interno = :interno,
                 x_position = :x,
                 y_position = :y,
                 width  = :w,
                 height = :h
             WHERE floor = :floor AND interno = :old_interno",
            [
                'interno'      => $interno,
                'x'            => $x,
                'y'            => $y,
                'w'            => $width,
                'h'            => $height,
                'floor'        => $floor,
                'old_interno'  => $old_int
            ],
            __FILE__
        );

        if ($updateOld->rowCount() > 0) {
            return ['success' => true];
        }
    }

    // c) INSERT (capiterà solo quando si disegna DAVVERO una nuova area)
    $database->query(
        "INSERT INTO office_postazioni
            (floor, interno, x_position, y_position, width, height)
         VALUES
            (:floor, :interno, :x, :y, :w, :h)",
        [
            'floor'   => $floor,
            'interno' => $interno,
            'x'       => $x,
            'y'       => $y,
            'w'       => $width,
            'h'       => $height
        ],
        __FILE__
    );

    return ['success' => true];
}

}