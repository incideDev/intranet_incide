<?php
namespace Services;
// Sicurezza gestita dal bootstrap centrale

class GestioneIntranetService {
    public static function getComunicazioni() {
        global $database; // Usa la tua variabile globale
        // TODO: Sostituisci con SELECT reale
        $res = $database->query("SELECT * FROM comunicazioni_home WHERE visibile = 1 ORDER BY data_creazione DESC", [], __FILE__);
        $rows = $res->fetchAll(\PDO::FETCH_ASSOC);
        return ['success' => true, 'data' => $rows];
    }

    /**
     * Ottiene lo stato corrente della modalità manutenzione
     * @return array
     */
    public static function getMaintenanceStatus(): array {
        global $database;

        try {
            $check = $database->query("SHOW TABLES LIKE 'app_settings'", [], __FILE__);
            if (!$check || $check->rowCount() === 0) {
                return ['success' => true, 'maintenance_mode' => 0];
            }

            $result = $database->query(
                "SELECT setting_value FROM app_settings WHERE setting_key = 'maintenance_mode' LIMIT 1",
                [],
                __FILE__
            );

            $maintenanceMode = 0;
            if ($result) {
                $row = $result->fetch(PDO::FETCH_ASSOC);
                $maintenanceMode = isset($row['setting_value']) ? intval($row['setting_value']) : 0;
            }

            return ['success' => true, 'maintenance_mode' => $maintenanceMode];
        } catch (\Throwable $e) {
            error_log("Errore lettura stato manutenzione: " . $e->getMessage());
            return ['success' => true, 'maintenance_mode' => 0];
        }
    }

    /**
     * Salva le impostazioni di manutenzione
     * @param int $maintenanceMode 0 o 1
     * @param string $maintenanceMessage Messaggio opzionale
     * @return array
     */
    public static function saveMaintenanceSettings(int $maintenanceMode, string $maintenanceMessage): array {
        global $database;

        try {
            // Verifica se la tabella esiste
            $check = $database->query("SHOW TABLES LIKE 'app_settings'", [], __FILE__);
            if (!$check || $check->rowCount() === 0) {
                return ['success' => false, 'message' => 'Tabella app_settings non trovata. Esegui prima la query SQL per crearla.'];
            }

            // Normalizza valori
            $maintenanceMode = $maintenanceMode === 1 ? 1 : 0;
            $maintenanceMessage = trim($maintenanceMessage);

            // Salva maintenance_mode
            $database->query(
                "INSERT INTO app_settings (setting_key, setting_value) 
                 VALUES ('maintenance_mode', ?) 
                 ON DUPLICATE KEY UPDATE setting_value = ?",
                [$maintenanceMode, $maintenanceMode],
                __FILE__
            );

            // Salva maintenance_message
            $database->query(
                "INSERT INTO app_settings (setting_key, setting_value) 
                 VALUES ('maintenance_message', ?) 
                 ON DUPLICATE KEY UPDATE setting_value = ?",
                [$maintenanceMessage, $maintenanceMessage],
                __FILE__
            );

            return ['success' => true, 'message' => 'Impostazioni salvate con successo'];
        } catch (\Throwable $e) {
            error_log("Errore salvataggio impostazioni manutenzione: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore durante il salvataggio: ' . $e->getMessage()];
        }
    }
}
