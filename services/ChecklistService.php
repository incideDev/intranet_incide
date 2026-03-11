<?php

namespace Services;

/**
 * CHECKLIST SERVICE GLOBALE
 * 
 * Gestisce checklist generiche su tabella sys_checklists.
 * Utilizzabile da qualsiasi entità (mom_item, task, ticket, ecc).
 */
class ChecklistService
{
    /**
     * Recupera la checklist per una entità
     * @param string $entityType
     * @param int $entityId
     * @return array
     */
    public static function listItems(string $entityType, int $entityId): array
    {
        global $database;
        try {
            // Se entityType/id vuoti, ritorna vuoto
            if (empty($entityType) || $entityId <= 0) {
                return ['success' => true, 'data' => []];
            }

            $items = $database->query(
                "SELECT c.*, p.Nominativo as done_by_name
                 FROM sys_checklists c
                 LEFT JOIN personale p ON c.done_by = p.user_id
                 WHERE c.entity_type = ? AND c.entity_id = ?
                 ORDER BY c.sort_order ASC, c.id ASC",
                [$entityType, $entityId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            return ['success' => true, 'data' => $items];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Aggiunge un elemento alla checklist
     * @param string $entityType
     * @param int $entityId
     * @param string $label
     * @return array
     */
    public static function add(string $entityType, int $entityId, string $label): array
    {
        global $database;
        $label = trim($label);
        $userId = $_SESSION['user_id'] ?? null;

        if (empty($entityType) || $entityId <= 0 || empty($label)) {
            return ['success' => false, 'message' => 'Dati mancanti'];
        }

        try {
            $database->query(
                "INSERT INTO sys_checklists (entity_type, entity_id, label, created_by) VALUES (?, ?, ?, ?)",
                [$entityType, $entityId, $label, $userId],
                __FILE__
            );
            $id = $database->lastInsertId();

            return ['success' => true, 'data' => ['id' => $id]];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Cambia stato completamento (toggle)
     * @param int $id
     * @return array
     */
    public static function toggle(int $id): array
    {
        global $database;
        $userId = $_SESSION['user_id'] ?? null;

        try {
            $item = $database->query(
                "SELECT * FROM sys_checklists WHERE id = ?",
                [$id],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$item) {
                return ['success' => false, 'message' => 'Elemento non trovato'];
            }

            $newStatus = $item['is_done'] ? 0 : 1;
            $doneBy = $newStatus ? $userId : null;
            $doneAt = $newStatus ? date('Y-m-d H:i:s') : null;

            $database->query(
                "UPDATE sys_checklists SET is_done = ?, done_by = ?, done_at = ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                [$newStatus, $doneBy, $doneAt, $userId, $id],
                __FILE__
            );

            return ['success' => true, 'data' => ['is_done' => $newStatus]];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Elimina un elemento
     * @param int $id
     * @return array
     */
    public static function delete(int $id): array
    {
        global $database;

        try {
            $database->query("DELETE FROM sys_checklists WHERE id = ?", [$id], __FILE__);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
