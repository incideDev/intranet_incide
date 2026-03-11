<?php
namespace Services;

class ProfileService {

    public static function getPersonalInfo($userId) {
        global $database;

        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);
        if (!$userId) return ['success' => false, 'message' => 'ID non valido.'];

        $query = "SELECT 
            Nominativo,
            Email_Aziendale,
            Codice_Fiscale,
            Email_Personale,
            Titolo_di_Studio,
            Cellulare_Personale,
            Cellulare_Aziendale,
            Telefono_Personale,
            Indirizzo,
            CAP,
            Citt,
            Provincia,
            Nazione,
            Data_di_Nascita,
            Luogo_di_Nascita
        FROM personale WHERE user_id = ?";
        
        $record = $database->query($query, [$userId], __FILE__)->fetch(\PDO::FETCH_ASSOC);

        if ($record) {
            // NON formattare per l'input type="date", serve formato YYYY-MM-DD
            if ($record['Data_di_Nascita'] === '0000-00-00' || !$record['Data_di_Nascita']) {
                $record['Data_di_Nascita'] = '';
            }
            // Se invece vuoi formattarla solo per visualizzazione (NON per input), usa formatDate
        }
        
        if ($record) {
            return ['success' => true, 'data' => $record];
        } else {
            return ['success' => false, 'message' => 'Dati non trovati.'];
        }
    }

    public static function updatePersonalInfo($userId, $data) {
        global $database;

        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);
        if (!$userId) return ['success' => false, 'message' => 'ID non valido.'];

        $fields = [
            'Codice_Fiscale',
            'Email_Personale',
            'Titolo_di_Studio',
            'Cellulare_Personale',
            'Cellulare_Aziendale',
            'Telefono_Personale',
            'Indirizzo',
            'CAP',
            'Citt',
            'Provincia',
            'Nazione',
            'Data_di_Nascita',
            'Luogo_di_Nascita'
        ];

        $setParts = [];
        $params = [];

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $val = trim($data[$field]);
                if ($field === 'Codice_Fiscale') {
                    $val = strtoupper($val);
                }
                if ($field === 'Data_di_Nascita' && $val === '') {
                    $setParts[] = "$field = NULL";
                    continue;
                }
                $setParts[] = "$field = ?";
                $params[] = $val;
            }
        }
        
        if (empty($setParts)) {
            return ['success' => false, 'message' => 'Nessun campo da aggiornare.'];
        }

        $params[] = $userId;
        $query = "UPDATE personale SET " . implode(", ", $setParts) . " WHERE user_id = ?";
        $database->query($query, $params, __FILE__);

        return ['success' => true, 'message' => 'Informazioni aggiornate.'];
    }

    public static function updateUserBio($userId, $bio) {
        global $database;
    
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);
        $bio = htmlspecialchars(trim($bio));
    
        $query = "UPDATE personale SET bio = ? WHERE user_id = ?";
        $database->query($query, [$bio, $userId], __FILE__);
    
        return ['success' => true, 'message' => 'Biografia aggiornata.'];
    }
    
    public static function changePassword($userId, $currentPassword, $newPassword, $confirmPassword) {
        global $database;

        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);
        if (!$userId) return ['success' => false, 'message' => 'ID utente non valido.'];

        if ($newPassword !== $confirmPassword) {
            return ['success' => false, 'message' => 'Le nuove password non coincidono.'];
        }

        $user = $database->getUserInfoById($userId);
        if (!$user || !isset($user['password'])) {
            return ['success' => false, 'message' => 'Utente non trovato.'];
        }

        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'Password attuale errata.'];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $query = "UPDATE users SET password = ? WHERE id = ?";
        $database->query($query, [$hash, $userId], __FILE__);

        return ['success' => true, 'message' => 'Password aggiornata con successo.'];
    }

    public static function getUserProfile($userId) {
        global $database;

        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);
        if (!$userId) return ['success' => false, 'message' => 'ID non valido.'];

        $query = "SELECT u.username, p.Nominativo, p.Email_Aziendale, p.Bio FROM users u 
                  LEFT JOIN personale p ON u.id = p.user_id 
                  WHERE u.id = ?";
        $record = $database->query($query, [$userId], __FILE__)->fetch(\PDO::FETCH_ASSOC);

        if ($record) {
            return ['success' => true, 'data' => $record];
        } else {
            return ['success' => false, 'message' => 'Profilo non trovato.'];
        }
    }

}
?>
