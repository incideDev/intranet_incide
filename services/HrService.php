<?php
namespace Services;

class HrService {

    public static function getAreas() {
        global $database;
        $query = "SELECT id, nome FROM hr_aree ORDER BY nome ASC";
        return $database->query($query, [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function getCompetencesForArea($areaId) {
        global $database;
        $areaId = filter_var($areaId, FILTER_SANITIZE_NUMBER_INT);

        $query = "SELECT id, nome FROM hr_competenze WHERE area_id = ?";
        $data = $database->query($query, [$areaId], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        return ['success' => true, 'data' => $data];
    }

    public static function getUserCompetences($userId) {
        global $database;
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);

        $query = "
            SELECT 
                uc.competenza_id, 
                c.nome AS competenza_nome, 
                a.nome AS area_nome,
                uc.lvl AS lvl
            FROM user_competences uc
            INNER JOIN hr_competenze c ON uc.competenza_id = c.id
            INNER JOIN hr_aree a ON c.area_id = a.id
            WHERE uc.user_id = ?
        ";

        $data = $database->query($query, [$userId], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        return ['success' => true, 'data' => $data];
    }

    public static function addCompetence($userId, $competenceId, $level) {
        global $database;
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);
        $competenceId = filter_var($competenceId, FILTER_SANITIZE_NUMBER_INT);
        $level = filter_var($level, FILTER_SANITIZE_NUMBER_INT);

        $query = "INSERT INTO user_competences (user_id, competenza_id, livello) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE livello = ?";
        $database->query($query, [$userId, $competenceId, $level, $level], __FILE__);

        return ['success' => true, 'message' => 'Competenza aggiunta o aggiornata.'];
    }

    public static function removeCompetence($userId, $competenceId) {
        global $database;
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);
        $competenceId = filter_var($competenceId, FILTER_SANITIZE_NUMBER_INT);

        $query = "DELETE FROM user_competences WHERE user_id = ? AND competenza_id = ?";
        $database->query($query, [$userId, $competenceId], __FILE__);

        return ['success' => true, 'message' => 'Competenza rimossa.'];
    }
}
?>
