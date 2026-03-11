<?php
namespace Services;

// Sicurezza gestita dal bootstrap centrale - questo file deve essere caricato solo tramite autoload

class RoleService
{
    public static function getRoles(){
        global $database;

        $roles = $database->query("SELECT * FROM sys_roles ORDER BY id ASC", [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);
        $roleIds = array_column($roles, 'id');

        $permessi = [];
        if (!empty($roleIds)) {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $rows = $database->query("SELECT role_id, permission FROM sys_role_permissions WHERE role_id IN ($placeholders)", $roleIds, __FILE__)->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $permessi[$row['role_id']][] = $row['permission'];
            }
        }

        foreach ($roles as &$ruolo) {
            $ruolo['permissions'] = $permessi[$ruolo['id']] ?? [];
        
            // NON forzare permessi speciali per admin - admin bypass è logico in userHasPermission
        }        

        return ['success' => true, 'data' => $roles];
    }

    public static function saveRole($input) {
        global $database;
    
        try {
            $id = isset($input['id']) ? intval($input['id']) : null;
            $name = filter_var(trim($input['name'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
            $description = filter_var(trim($input['description'] ?? ''), FILTER_SANITIZE_SPECIAL_CHARS);
        
            $permissions = is_array($input['permissions'] ?? []) 
                ? array_map(fn($p) => filter_var(trim($p), FILTER_SANITIZE_SPECIAL_CHARS), $input['permissions']) 
                : [];
        
            if (!$name) {
                return ['success' => false, 'message' => 'Nome ruolo obbligatorio'];
            }
        
            if ($id) {
                $database->query("UPDATE sys_roles SET name = :name, description = :descr WHERE id = :id", [
                    'name' => $name,
                    'descr' => $description,
                    'id' => $id
                ], __FILE__);
                $database->query("DELETE FROM sys_role_permissions WHERE role_id = :id", ['id' => $id], __FILE__);
            } else {
                $database->query("INSERT INTO sys_roles (name, description) VALUES (:name, :descr)", [
                    'name' => $name,
                    'descr' => $description
                ], __FILE__);
                $id = $database->lastInsertId();
            }
        
            foreach ($permissions as $perm) {
                if (empty($perm)) continue; // Skip permessi vuoti
                $database->query("INSERT INTO sys_role_permissions (role_id, permission) VALUES (:rid, :perm)", [
                    'rid' => $id,
                    'perm' => $perm
                ], __FILE__);
            }
        
            return ['success' => true, 'message' => 'Ruolo salvato', 'id' => $id];
        } catch (\PDOException $e) {
            error_log("RoleService::saveRole PDOException: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore database: ' . $e->getMessage()];
        } catch (\Exception $e) {
            error_log("RoleService::saveRole Exception: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore: ' . $e->getMessage()];
        }
    }
    
    public static function deleteRole($id) {
        global $database;
    
        // Verifica se il ruolo è assegnato a qualche utente
        $count = $database->query("SELECT COUNT(*) as cnt FROM sys_user_roles WHERE role_id = ?", [$id], __FILE__)
            ->fetch(\PDO::FETCH_ASSOC)['cnt'] ?? 0;
    
        if ($count > 0) {
            return ['success' => false, 'message' => 'Il ruolo è ancora assegnato a degli utenti'];
        }
    
        // Elimina permessi e ruolo
        $database->query("DELETE FROM sys_role_permissions WHERE role_id = ?", [$id], __FILE__);
        $database->query("DELETE FROM sys_roles WHERE id = ?", [$id], __FILE__);
    
        return ['success' => true];
    }
    
    public static function getUserRoleMappings() {
        global $database;

        require_once __DIR__ . '/ContactService.php';
        $utenti = \Services\ContactService::getContacts();

        $ruoli = $database->query("SELECT id, name FROM sys_roles ORDER BY name ASC", [], __FILE__)
            ->fetchAll(\PDO::FETCH_ASSOC);

        $assegnati = $database->query("
            SELECT user_id, role_id, r.name as role_name
            FROM sys_user_roles ur
            JOIN sys_roles r ON ur.role_id = r.id
            ORDER BY user_id, r.name
        ", [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        // Raggruppa per utente
        $mappaAssegnati = [];
        $mappaNomiRuoli = [];
        foreach ($assegnati as $row) {
            $uid = $row['user_id'];
            if (!isset($mappaAssegnati[$uid])) {
                $mappaAssegnati[$uid] = [];
                $mappaNomiRuoli[$uid] = [];
            }
            $mappaAssegnati[$uid][] = $row['role_id'];
            $mappaNomiRuoli[$uid][] = $row['role_name'];
        }

        foreach ($utenti as &$utente) {
            $uid = $utente['user_id'];
            $utente['role_ids'] = $mappaAssegnati[$uid] ?? [];
            $utente['role_names'] = $mappaNomiRuoli[$uid] ?? [];
            // LEGACY: mantieni role_id come primo ruolo per retrocompatibilità
            $utente['role_id'] = $utente['role_ids'][0] ?? null;
        }

        return [
            'success' => true,
            'users' => $utenti,
            'roles' => $ruoli
        ];
    }

    public static function addRoleToUser($input) {
        global $database;

        $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
        $roleId = isset($input['role_id']) ? (int)$input['role_id'] : null;

        if (!$userId || !$roleId) {
            return ['success' => false, 'message' => 'ID utente e ruolo mancanti'];
        }

        // INSERT IGNORE sfrutta la PK (user_id, role_id) per idempotenza
        $result = $database->query(
            "INSERT IGNORE INTO sys_user_roles (user_id, role_id) VALUES (:uid, :rid)",
            ['uid' => $userId, 'rid' => $roleId],
            __FILE__
        );

        // Verifica se l'inserimento è avvenuto (affected_rows > 0) o era già presente
        $affectedRows = $result ? $result->rowCount() : 0;
        $message = $affectedRows > 0 ? 'Ruolo aggiunto' : 'Ruolo già assegnato';

        return ['success' => true, 'message' => $message];
    }

    public static function removeRoleFromUser($input) {
        global $database;

        $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
        $roleId = isset($input['role_id']) ? (int)$input['role_id'] : null;

        if (!$userId || !$roleId) {
            return ['success' => false, 'message' => 'ID utente e ruolo mancanti'];
        }

        $database->query(
            "DELETE FROM sys_user_roles WHERE user_id = :uid AND role_id = :rid",
            ['uid' => $userId, 'rid' => $roleId],
            __FILE__
        );

        return ['success' => true, 'message' => 'Ruolo rimosso'];
    }

    // LEGACY: mantiene compatibilità con vecchio assignRoleToUser
    // Questo viene chiamato dal frontend esistente durante la transizione
    public static function assignRoleToUser($input) {
        global $database;

        $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
        $roleId = isset($input['role_id']) ? (int)$input['role_id'] : null;

        if (!$userId) {
            return ['success' => false, 'message' => 'ID utente mancante'];
        }

        // Prima rimuovi tutti i ruoli esistenti
        $database->query("DELETE FROM sys_user_roles WHERE user_id = :uid", ['uid' => $userId], __FILE__);

        // Poi aggiungi il nuovo ruolo se specificato
        if ($roleId) {
            $database->query(
                "INSERT INTO sys_user_roles (user_id, role_id) VALUES (:uid, :rid)",
                ['uid' => $userId, 'rid' => $roleId],
                __FILE__
            );
        }

        return ['success' => true, 'message' => 'Ruolo aggiornato (modalità legacy)'];
    }

    public static function getRoleIdsByUserId($userId) {
        global $database;
        $sql = "SELECT role_id FROM sys_user_roles WHERE user_id = ? ORDER BY role_id";
        $rows = $database->query($sql, [$userId], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);
        return array_column($rows, 'role_id');
    }

    public static function getPermissionsByRoleIds($roleIds) {
        if (empty($roleIds)) {
            return [];
        }

        global $database;
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $sql = "SELECT DISTINCT permission FROM sys_role_permissions WHERE role_id IN ($placeholders) ORDER BY permission";
        $rows = $database->query($sql, $roleIds, __FILE__)->fetchAll(\PDO::FETCH_ASSOC);
        return array_column($rows, 'permission');
    }

    public static function getPermissionsByRoleId($role_id) {
        // Nota: questo metodo è mantenuto per retrocompatibilità ma non dovrebbe più essere usato
        // per autorizzazioni dato che ora abbiamo multi-ruolo
        global $database;
        $sql = "SELECT permission FROM sys_role_permissions WHERE role_id = ?";
        $rows = $database->query($sql, [$role_id], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);
        return array_column($rows, 'permission');
    }

    public static function getAllRoles() {
        global $database;

        $roles = $database->query("SELECT id, name, description FROM sys_roles ORDER BY name ASC", [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);
        return ['success' => true, 'data' => $roles];
    }

    public static function searchUsers($q) {
        global $database;

        // Sanitizza input
        $q = trim($q);
        if (strlen($q) < 2) {
            return ['success' => false, 'message' => 'Query troppo corta'];
        }

        // Cerca per username o email
        $sql = "SELECT id, username, email FROM users WHERE username LIKE :q OR email LIKE :q ORDER BY username ASC LIMIT 20";
        $users = $database->query($sql, [':q' => '%' . $q . '%'], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        return ['success' => true, 'data' => $users];
    }

    public static function setUserRoles($userId, array $roleIds) {
        global $database;

        // Valida userId
        if ($userId <= 0) {
            return ['success' => false, 'message' => 'ID utente non valido'];
        }

        // Valida che i roleIds esistano
        if (!empty($roleIds)) {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $existingRoles = $database->query(
                "SELECT id FROM sys_roles WHERE id IN ($placeholders)",
                $roleIds,
                __FILE__
            )->fetchAll(\PDO::FETCH_COLUMN);

            if (count($existingRoles) !== count($roleIds)) {
                return ['success' => false, 'message' => 'Uno o più ruoli non esistono'];
            }
        }

        // Ottieni ruoli attuali
        $currentRoleIds = self::getRoleIdsByUserId($userId);

        // Calcola differenze
        $toAdd = array_diff($roleIds, $currentRoleIds);
        $toRemove = array_diff($currentRoleIds, $roleIds);

        // Rimuovi ruoli non più assegnati
        if (!empty($toRemove)) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $params = array_merge([$userId], $toRemove);
            $database->query(
                "DELETE FROM sys_user_roles WHERE user_id = ? AND role_id IN ($placeholders)",
                $params,
                __FILE__
            );
        }

        // Aggiungi nuovi ruoli (usando INSERT IGNORE per idempotenza)
        foreach ($toAdd as $roleId) {
            $database->query(
                "INSERT IGNORE INTO sys_user_roles (user_id, role_id) VALUES (?, ?)",
                [$userId, $roleId],
                __FILE__
            );
        }

        return ['success' => true, 'message' => 'Ruoli aggiornati con successo'];
    }

    /**
     * Gestione permessi accesso pagine Page Editor per ruoli
     * Usa tabella: sys_role_permissions con permessi "page_editor_form_view:<form_id>"
     * Tabella forms: id, name, description, table_name
     */

    public static function getAllPageEditorForms() {
        global $database;

        // Query per ottenere tutti i form con campi richiesti e opzionali
        $sql = "SELECT 
                    id, 
                    name, 
                    description, 
                    COALESCE(is_restricted, 0) as is_restricted,
                    table_name,
                    color,
                    protocollo,
                    created_at,
                    created_by,
                    responsabile
                FROM forms 
                WHERE 1 
                ORDER BY name ASC";
        
        $stmt = $database->query($sql, [], __FILE__);
        $forms = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        // Normalizza i dati: cast a int e garantisce stringhe non-null
        foreach ($forms as &$form) {
            $form['id'] = (int)$form['id'];
            $form['is_restricted'] = (int)$form['is_restricted'];
            $form['name'] = (string)($form['name'] ?? '');
            $form['description'] = (string)($form['description'] ?? '');
            // Campi opzionali possono rimanere null se non presenti
        }
        unset($form);

        return ['success' => true, 'data' => $forms];
    }

    public static function getPageEditorFormIdsByRoleId($roleId) {
        global $database;

        if ($roleId <= 0) {
            return [];
        }

        // Estrai form_id dai permessi che iniziano con "page_editor_form_view:"
        $stmt = $database->query(
            "SELECT permission FROM sys_role_permissions
             WHERE role_id = ? AND permission LIKE 'page_editor_form_view:%'
             ORDER BY permission",
            [$roleId],
            __FILE__
        );

        $formIds = [];
        if ($stmt) {
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                // Estrai form_id dal permesso "page_editor_form_view:123"
                if (preg_match('/page_editor_form_view:(\d+)/', $row['permission'], $matches)) {
                    $formIds[] = (int)$matches[1];
                }
            }
        }

        return $formIds;
    }

    public static function setPageEditorFormIdsForRole($roleId, array $formIds) {
        global $database;

        if ($roleId <= 0) {
            return ['success' => false, 'message' => 'ID ruolo non valido'];
        }

        // Valida che i form_id esistano nella tabella forms
        if (!empty($formIds)) {
            $placeholders = implode(',', array_fill(0, count($formIds), '?'));
            $existingForms = $database->query(
                "SELECT id FROM forms WHERE id IN ($placeholders)",
                $formIds,
                __FILE__
            )->fetchAll(\PDO::FETCH_COLUMN);

            if (count($existingForms) !== count($formIds)) {
                return ['success' => false, 'message' => 'Uno o più form_id non esistono'];
            }
        }

        // Genera i nomi dei permessi
        $newPermissions = array_map(function($formId) {
            return "page_editor_form_view:{$formId}";
        }, $formIds);

        // Ottieni permessi attuali per questo ruolo
        $stmt = $database->query(
            "SELECT permission FROM sys_role_permissions
             WHERE role_id = ? AND permission LIKE 'page_editor_form_view:%'",
            [$roleId],
            __FILE__
        );

        $currentPermissions = [];
        if ($stmt) {
            $currentPermissions = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'permission');
        }

        // Calcola differenze
        $toAdd = array_diff($newPermissions, $currentPermissions);
        $toRemove = array_diff($currentPermissions, $newPermissions);

        // Rimuovi permessi non più necessari
        if (!empty($toRemove)) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $params = array_merge([$roleId], $toRemove);
            $database->query(
                "DELETE FROM sys_role_permissions WHERE role_id = ? AND permission IN ($placeholders)",
                $params,
                __FILE__
            );
        }

        // Aggiungi nuovi permessi
        foreach ($toAdd as $permission) {
            $database->query(
                "INSERT IGNORE INTO sys_role_permissions (role_id, permission) VALUES (?, ?)",
                [$roleId, $permission],
                __FILE__
            );
        }

        return ['success' => true, 'message' => 'Permessi pagine aggiornati con successo'];
    }

}
