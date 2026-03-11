<?php

namespace Services;

class ContactService
{

    public static function getContacts()
    {
        global $database;

        // TODO: rimuovere fallback dopo deprecazione personale
        $sql = "
            SELECT
                p.user_id,
                COALESCE(CONCAT(hr.firstname, ' ', hr.surname), p.Nominativo) AS Nominativo,
                COALESCE(hr.email_aziendale, p.Email_Aziendale) AS Email_Aziendale,
                COALESCE(op.interno, 'N/A') AS phone,
                uc.competenza_id,
                hr.department_desc AS Reparto,
                (
                    SELECT hr2.hr_role_desc
                    FROM hr_resource_role hrr2
                    JOIN hr_role hr2 ON hr2.id_hrrole = hrr2.id_hrrole
                    WHERE hrr2.id_hresource = p.Cod_Operatore
                    ORDER BY hrr2.relev_role_perc DESC
                    LIMIT 1
                ) AS hr_role_desc
            FROM personale p
            LEFT JOIN hr_resource hr ON hr.id_hresource = p.Cod_Operatore
            LEFT JOIN user_competences uc ON p.user_id = uc.user_id
            LEFT JOIN office_positions op ON p.user_id = op.user_id
            WHERE p.attivo = 1
            ORDER BY Nominativo ASC
        ";

        $rows = $database->query($sql, [], __FILE__) ?: [];

        $result = [];

        foreach ($rows as $row) {
            $uid = $row['user_id'];

            if (!isset($result[$uid])) {
                $result[$uid] = [
                    'user_id' => $row['user_id'],
                    'Nominativo' => $row['Nominativo'],
                    'Email_Aziendale' => $row['Email_Aziendale'],
                    'phone' => $row['phone'],
                    'competenze' => [],
                    'Reparto' => $row['Reparto'],
                    'hr_role_desc' => $row['hr_role_desc'],
                    'profile_picture' => getProfileImage($row['Nominativo'], 'nominativo'),
                ];
            }

            if (!empty($row['competenza_id'])) {
                $result[$uid]['competenze'][] = $row['competenza_id'];
            }
        }

        return array_values($result);
    }

    public static function getProfileData($userId)
    {
        global $database;
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);

        // TODO: rimuovere fallback dopo deprecazione personale
        // Vietato SELECT p.* - lista esplicita dei campi
        $query = "
            SELECT
                p.user_id,
                p.Cod_Operatore,
                p.attivo,
                p.bio,
                COALESCE(op.interno, '') AS interno,
                hr.firstname,
                hr.surname,
                COALESCE(CONCAT(hr.firstname, ' ', hr.surname), p.Nominativo) AS Nominativo,
                COALESCE(hr.email_aziendale, p.Email_Aziendale) AS Email_Aziendale,
                COALESCE(hr.cellulare_aziendale, p.Cellulare_Aziendale) AS Cellulare_Aziendale,
                hr.department_desc AS Reparto,
                hr.factory_desc,
                hr.area_desc,
                hr.business_unit_desc,
                hr.hire_date AS Data_Assunzione,
                hr.birth_date AS Data_di_Nascita,
                hr.gender AS Genere,
                hr.nationality,
                hr.int_company_name AS Company,
                hr.resource_type,
                hr.city_residence AS Luogo_di_Nascita,
                hr.province_res,
                hr.cap_residence,
                hr.is_active AS hr_is_active
            FROM personale p
            LEFT JOIN hr_resource hr ON hr.id_hresource = p.Cod_Operatore
            LEFT JOIN office_positions op ON p.user_id = op.user_id
            WHERE p.user_id = ?
        ";
        $result = $database->query($query, [$userId], __FILE__);
        $data = $result ? $result->fetch(\PDO::FETCH_ASSOC) : null;

        if ($data) {
            // Formatta la data nascita in formato italiano con slash
            if (!empty($data['Data_di_Nascita']) && $data['Data_di_Nascita'] !== '0000-00-00') {
                $data['Data_di_Nascita'] = $database->formatDate($data['Data_di_Nascita']);
            }
            if (!empty($data['Data_Assunzione']) && $data['Data_Assunzione'] !== '0000-00-00') {
                $data['Data_Assunzione'] = $database->formatDate($data['Data_Assunzione']);
            }
            return ['success' => true, 'data' => $data];
        } else {
            return ['success' => false, 'message' => 'Profilo non trovato.'];
        }
    }

    public static function getProfileImageByName($nominativo)
    {
        require_once(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/services')) . '/core/functions.php');

        $imagePath = getProfileImage($nominativo, 'nominativo');
        if ($imagePath && $imagePath !== 'assets/images/default_profile.png') {
            return ['status' => 'success', 'image' => $imagePath];
        } else {
            return ['status' => 'error', 'message' => 'Immagine non trovata.'];
        }
    }

    public static function getCompetencesByArea($areaId)
    {
        global $database;
        $areaId = filter_var($areaId, FILTER_SANITIZE_NUMBER_INT);
        $query = "SELECT * FROM competenze WHERE area_id = ?";
        return $database->query($query, [$areaId], __FILE__);
    }

    public static function getUserCompetences($userId)
    {
        global $database;
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);

        $query = "
            SELECT 
                c.nome AS competenza_nome, 
                a.nome AS area_nome
            FROM user_competences uc
            INNER JOIN hr_competenze c ON uc.competenza_id = c.id
            INNER JOIN hr_aree a ON c.area_id = a.id
            WHERE uc.user_id = ?
        ";

        $data = $database->query($query, [$userId], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        if ($data) {
            return ['success' => true, 'data' => $data];
        } else {
            return ['success' => false, 'message' => 'Nessuna competenza trovata.'];
        }
    }

    public static function getFilteredContacts($input)
    {
        global $database;

        $where = 'p.attivo = 1';
        $params = [];
        $joinHrRoleFilter = false;
        $joinProjectFilter = false;

        // TODO: rimuovere fallback dopo deprecazione personale
        if (!empty($input['search'])) {
            $search = filter_var($input['search'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            // Cerca su hr_resource (nome/cognome) e fallback su personale
            $where .= " AND (
                CONCAT(hres.firstname, ' ', hres.surname) LIKE :search
                OR p.Nominativo LIKE :search
                OR COALESCE(hres.email_aziendale, p.Email_Aziendale) LIKE :search
            )";
            $params[':search'] = "%$search%";
        }

        if (!empty($input['department'])) {
            $department = filter_var($input['department'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            // Filtro su hr_resource.department_desc
            $where .= " AND hres.department_desc = :department";
            $params[':department'] = $department;
        }

        // Filtro Ruoli multipli con logica AND
        $hasRoleFilter = false;
        $roleFilterClause = '';
        if (!empty($input['roles']) && is_array($input['roles'])) {
            // Filtra ruoli vuoti e sanitizza (id_hrrole è una stringa, non un intero!)
            $roleIds = array_filter(
                array_map(function ($r) {
                    return filter_var($r, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                }, $input['roles']),
                function ($r) {
                    return !empty($r); }
            );
            $roleCount = count($roleIds);
            if ($roleCount > 0) {
                $hasRoleFilter = true;
                $joinHrRoleFilter = true;
                // Usa named parameters per ogni ruolo
                $placeholders = [];
                foreach ($roleIds as $index => $roleId) {
                    $paramName = ":role{$index}";
                    $placeholders[] = $paramName;
                    $params[$paramName] = $roleId;
                }
                $placeholderStr = implode(',', $placeholders);
                // Invece di WHERE, useremo HAVING dopo GROUP BY
                $roleFilterClause = " HAVING COUNT(DISTINCT CASE WHEN hrr.id_hrrole IN ($placeholderStr) THEN hrr.id_hrrole END) = $roleCount";
            }
        }

        // Filtro Area/Business Unit
        if (!empty($input['area'])) {
            $area = filter_var($input['area'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $where .= " AND (hres.area_desc = :area OR hres.business_unit_desc = :area)";
            $params[':area'] = $area;
        }

        // Filtro Commessa
        if (!empty($input['project'])) {
            $project = filter_var($input['project'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $joinProjectFilter = true;
            $where .= " AND pt.idProject = :project";
            $params[':project'] = $project;
        }

        // Filtro Anzianità
        if (!empty($input['seniority'])) {
            $seniority = filter_var($input['seniority'], FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $now = date('Y-m-d');
            switch ($seniority) {
                case '0-1':
                    $where .= " AND TIMESTAMPDIFF(YEAR, hres.hire_date, '$now') < 1";
                    break;
                case '1-3':
                    $where .= " AND TIMESTAMPDIFF(YEAR, hres.hire_date, '$now') BETWEEN 1 AND 2";
                    break;
                case '3-5':
                    $where .= " AND TIMESTAMPDIFF(YEAR, hres.hire_date, '$now') BETWEEN 3 AND 4";
                    break;
                case '5-10':
                    $where .= " AND TIMESTAMPDIFF(YEAR, hres.hire_date, '$now') BETWEEN 5 AND 9";
                    break;
                case '10+':
                    $where .= " AND TIMESTAMPDIFF(YEAR, hres.hire_date, '$now') >= 10";
                    break;
            }
        }

        $joinClause = "
            LEFT JOIN hr_resource hres ON hres.id_hresource = p.Cod_Operatore
            LEFT JOIN office_positions op ON p.user_id = op.user_id";
        if ($joinHrRoleFilter) {
            $joinClause .= "
                LEFT JOIN hr_resource_role hrr ON hrr.id_hresource = p.Cod_Operatore
                LEFT JOIN hr_role hrrole ON hrrole.id_hrrole = hrr.id_hrrole";
        }
        if ($joinProjectFilter) {
            $joinClause .= "
                LEFT JOIN project_time pt ON pt.idHResource = p.Cod_Operatore";
        }

        // TODO: rimuovere fallback dopo deprecazione personale
        if ($hasRoleFilter) {
            // Con filtro ruoli: usa GROUP BY + HAVING
            $sql = "
                SELECT
                    p.user_id,
                    COALESCE(CONCAT(hres.firstname, ' ', hres.surname), p.Nominativo) AS Nominativo,
                    COALESCE(hres.email_aziendale, p.Email_Aziendale) AS Email_Aziendale,
                    COALESCE(op.interno, 'N/A') AS phone,
                    hres.department_desc AS Reparto,
                    hres.area_desc,
                    hres.business_unit_desc,
                    hres.hire_date AS Data_Assunzione,
                    (
                        SELECT hr2.hr_role_desc
                        FROM hr_resource_role hrr2
                        JOIN hr_role hr2 ON hr2.id_hrrole = hrr2.id_hrrole
                        WHERE hrr2.id_hresource = p.Cod_Operatore
                        ORDER BY hrr2.relev_role_perc DESC
                        LIMIT 1
                    ) AS hr_role_desc
                FROM personale p
                $joinClause
                WHERE $where
                GROUP BY p.user_id, p.Cod_Operatore, hres.firstname, hres.surname, p.Nominativo,
                         hres.email_aziendale, p.Email_Aziendale, op.interno, hres.department_desc,
                         hres.area_desc, hres.business_unit_desc, hres.hire_date
                $roleFilterClause
            ";
        } else {
            // Senza filtro ruoli: query semplice con DISTINCT
            $sql = "
                SELECT DISTINCT
                    p.user_id,
                    COALESCE(CONCAT(hres.firstname, ' ', hres.surname), p.Nominativo) AS Nominativo,
                    COALESCE(hres.email_aziendale, p.Email_Aziendale) AS Email_Aziendale,
                    COALESCE(op.interno, 'N/A') AS phone,
                    hres.department_desc AS Reparto,
                    hres.area_desc,
                    hres.business_unit_desc,
                    hres.hire_date AS Data_Assunzione,
                    (
                        SELECT hr2.hr_role_desc
                        FROM hr_resource_role hrr2
                        JOIN hr_role hr2 ON hr2.id_hrrole = hrr2.id_hrrole
                        WHERE hrr2.id_hresource = p.Cod_Operatore
                        ORDER BY hrr2.relev_role_perc DESC
                        LIMIT 1
                    ) AS hr_role_desc
                FROM personale p
                $joinClause
                WHERE $where
            ";
        }

        $result = $database->query($sql, $params, __FILE__);
        $data = $result ? $result->fetchAll(\PDO::FETCH_ASSOC) : [];

        // Arricchisci i dati con profile_picture (come in getContacts)
        foreach ($data as &$row) {
            $row['profile_picture'] = getProfileImage($row['Nominativo'], 'nominativo');
        }

        return [
            'success' => true,
            'data' => $data
        ];

    }

    public static function getUniqueRoles()
    {
        global $database;
        $query = "SELECT id_hrrole, hr_role_desc FROM hr_role ORDER BY hr_role_desc";
        return $database->query($query, [], __FILE__);
    }

    public static function getUniqueDepartments()
    {
        global $database;
        // Fonte primaria: hr_resource.department_desc
        // Output key "Reparto" per compatibilità view
        $query = "
            SELECT DISTINCT hr.department_desc AS Reparto
            FROM hr_resource hr
            WHERE hr.department_desc IS NOT NULL AND hr.department_desc != ''
            ORDER BY hr.department_desc ASC
        ";
        return $database->query($query, [], __FILE__);
    }

    public static function getUniqueAreas()
    {
        global $database;
        $query = "
            SELECT DISTINCT hr.area_desc AS Area
            FROM hr_resource hr
            WHERE hr.area_desc IS NOT NULL AND hr.area_desc != ''
            ORDER BY hr.area_desc ASC
        ";
        return $database->query($query, [], __FILE__);
    }

    public static function getUniqueBusinessUnits()
    {
        global $database;
        $query = "
            SELECT DISTINCT hr.business_unit_desc AS BusinessUnit
            FROM hr_resource hr
            WHERE hr.business_unit_desc IS NOT NULL AND hr.business_unit_desc != ''
            ORDER BY hr.business_unit_desc ASC
        ";
        return $database->query($query, [], __FILE__);
    }

    public static function getAllActiveProjects()
    {
        global $database;
        $query = "
            SELECT DISTINCT
                ap.IdProject AS code,
                ap.ProjectDesc AS name
            FROM akeron_project ap
            WHERE ap.ProjectStatusCode LIKE '%APERTA%'
            ORDER BY ap.ProjectDesc ASC
        ";
        return $database->query($query, [], __FILE__);
    }

    public static function getAllCompetences()
    {
        global $database;
        $query = "SELECT * FROM hr_competenze ORDER BY nome";
        return $database->query($query, [], __FILE__);
    }

    public static function getAllCompetenceAreas()
    {
        global $database;
        $query = "SELECT * FROM hr_aree ORDER BY nome";
        return $database->query($query, [], __FILE__);
    }

    public static function checkCurriculumExistence($filename)
    {
        $baseDir = substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/services')) . '/uploads/cv/';

        // Sanifica il nome file
        $safeFilename = basename($filename);
        $fullPath = $baseDir . $safeFilename;

        if (file_exists($fullPath) && is_file($fullPath) && is_readable($fullPath)) {
            return ['success' => true, 'message' => 'File trovato'];
        } else {
            return ['success' => false, 'message' => 'File non trovato', 'checked_path' => $fullPath];
        }
    }

    public static function resetUserPassword($userId)
    {
        global $database;
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);
        if (!$userId) {
            return ['success' => false, 'message' => 'ID utente non valido.'];
        }

        // Genera password forte temporanea
        $password = self::generaPasswordTemporanea(12); // lunghezza 12
        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Aggiorna la password nel db
        $database->query("UPDATE users SET password = ? WHERE id = ?", [$hash, $userId], __FILE__);

        // (Opzionale) puoi anche mettere un flag "forza cambio" se hai un campo del genere

        return ['success' => true, 'password' => $password];
    }

    public static function getMinifiedUserList()
    {
        global $database;

        // TODO: rimuovere fallback dopo deprecazione personale
        $sql = "
            SELECT
                p.user_id,
                COALESCE(CONCAT(hr.firstname, ' ', hr.surname), p.Nominativo) AS Nominativo,
                COALESCE(hr.email_aziendale, p.Email_Aziendale) AS Email_Aziendale
            FROM personale p
            LEFT JOIN hr_resource hr ON hr.id_hresource = p.Cod_Operatore
            WHERE p.attivo = 1
            ORDER BY Nominativo ASC
        ";
        $rows = $database->query($sql, [], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['profile_picture'] = getProfileImage($row['Nominativo'], 'nominativo');
        }

        return ['success' => true, 'data' => $rows];
    }

    public static function generaPasswordTemporanea($length = 12)
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%&*-_=+';
        $password = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        return $password;
    }

    /**
     * Recupera tutti i ruoli HR associati a un utente.
     *
     * @param int $userId ID dell'utente (personale.user_id)
     * @return array ['success' => bool, 'data' => [...]]
     */
    public static function getProfileRoles($userId)
    {
        global $database;
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);

        if (!$userId) {
            return ['success' => false, 'message' => 'ID utente non valido.'];
        }

        // Recupera Cod_Operatore dal personale
        $queryCod = "SELECT Cod_Operatore FROM personale WHERE user_id = ?";
        $resultCod = $database->query($queryCod, [$userId], __FILE__);
        $rowCod = $resultCod ? $resultCod->fetch(\PDO::FETCH_ASSOC) : null;

        if (!$rowCod || empty($rowCod['Cod_Operatore'])) {
            return ['success' => false, 'message' => 'Cod_Operatore non trovato.'];
        }

        $codOperatore = $rowCod['Cod_Operatore'];

        // Query per tutti i ruoli associati
        // Ordinamento: relev_role_perc DESC, se tutti 0 → hr_role_desc ASC
        $query = "
            SELECT
                hr.id_hrrole,
                hr.hr_role_desc,
                hrr.relev_role_perc
            FROM hr_resource_role hrr
            INNER JOIN hr_role hr ON hr.id_hrrole = hrr.id_hrrole
            WHERE hrr.id_hresource = ?
            ORDER BY hrr.relev_role_perc DESC, hr.hr_role_desc ASC
        ";

        $result = $database->query($query, [$codOperatore], __FILE__);
        $roles = $result ? $result->fetchAll(\PDO::FETCH_ASSOC) : [];

        // Se nessun ruolo
        if (empty($roles)) {
            return ['success' => true, 'data' => null];
        }

        // Primo ruolo = main, restanti = others
        $mainRole = $roles[0];
        $otherRoles = array_slice($roles, 1);

        return [
            'success' => true,
            'data' => [
                'main' => $mainRole,
                'others' => $otherRoles
            ]
        ];
    }

    /**
     * Recupera le commesse attive assegnate a un utente (max 5).
     *
     * @param int $userId ID dell'utente (personale.user_id)
     * @return array ['success' => bool, 'data' => [...]]
     */
    public static function getProfileActiveProjects($userId)
    {
        global $database;
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);

        if (!$userId) {
            return ['success' => false, 'message' => 'ID utente non valido.'];
        }

        // Recupera Cod_Operatore dal personale
        $queryCod = "SELECT Cod_Operatore FROM personale WHERE user_id = ?";
        $resultCod = $database->query($queryCod, [$userId], __FILE__);
        $rowCod = $resultCod ? $resultCod->fetch(\PDO::FETCH_ASSOC) : null;

        if (!$rowCod || empty($rowCod['Cod_Operatore'])) {
            return ['success' => false, 'message' => 'Cod_Operatore non trovato.'];
        }

        $codOperatore = $rowCod['Cod_Operatore'];

        // Query per progetti attivi assegnati (da project_time)
        // Join con akeron_project per ottenere codice e nome commessa
        // Usa COLLATE per gestire mismatch collation
        $collate = 'utf8mb4_general_ci';
        $query = "
            SELECT DISTINCT
                ap.IdProject AS idProject,
                ap.IdProject AS code,
                ap.ProjectDesc AS name
            FROM project_time pt
            LEFT JOIN akeron_project ap
                ON ap.IdProject COLLATE {$collate} = pt.idProject COLLATE {$collate}
            WHERE pt.idHResource = ?
              AND ap.ProjectStatusCode LIKE '%APERTA%'
            ORDER BY ap.ProjectDesc ASC
            LIMIT 5
        ";

        $result = $database->query($query, [$codOperatore], __FILE__);
        $projects = $result ? $result->fetchAll(\PDO::FETCH_ASSOC) : [];

        return [
            'success' => true,
            'data' => $projects
        ];
    }

    /**
     * Recupera i colleghi con cui l'utente collabora più spesso (top 6).
     * Basato su progetti in comune dalla tabella project_time.
     * Esclude progetti con >25 persone assegnate.
     *
     * @param int $userId ID dell'utente (personale.user_id)
     * @return array ['success' => bool, 'data' => [...]]
     */
    public static function getProfileCoworkers($userId)
    {
        global $database;
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);

        if (!$userId) {
            return ['success' => false, 'message' => 'ID utente non valido.'];
        }

        // Recupera Cod_Operatore dal personale
        $queryCod = "SELECT Cod_Operatore FROM personale WHERE user_id = ?";
        $resultCod = $database->query($queryCod, [$userId], __FILE__);
        $rowCod = $resultCod ? $resultCod->fetch(\PDO::FETCH_ASSOC) : null;

        if (!$rowCod || empty($rowCod['Cod_Operatore'])) {
            return ['success' => false, 'message' => 'Cod_Operatore non trovato.'];
        }

        $codOperatore = $rowCod['Cod_Operatore'];

        // Query ottimizzata per trovare colleghi con progetti in comune
        // Step 1: Trova i progetti dell'utente corrente (limitati a quelli con <=25 persone)
        // Step 2: Trova altri utenti su quegli stessi progetti
        // Usa subquery correlata più efficiente invece di IN con subquery pesante
        $query = "
            SELECT
                p2.user_id AS idPersonale,
                COALESCE(CONCAT(hr.firstname, ' ', hr.surname), p2.Nominativo) AS fullname,
                COUNT(DISTINCT pt2.idProject) AS shared_projects
            FROM project_time pt2
            INNER JOIN personale p2 ON p2.Cod_Operatore = pt2.idHResource
            LEFT JOIN hr_resource hr ON hr.id_hresource = p2.Cod_Operatore
            WHERE pt2.idHResource != ?
              AND p2.attivo = 1
              AND pt2.idProject IN (
                  SELECT pt1.idProject
                  FROM project_time pt1
                  WHERE pt1.idHResource = ?
              )
            GROUP BY p2.user_id, fullname
            HAVING shared_projects > 0
            ORDER BY shared_projects DESC, fullname ASC
            LIMIT 6
        ";

        $result = $database->query($query, [$codOperatore, $codOperatore], __FILE__);
        $coworkers = $result ? $result->fetchAll(\PDO::FETCH_ASSOC) : [];

        return [
            'success' => true,
            'data' => $coworkers
        ];
    }

    /**
     * Recupera i contatti di un'azienda (Personale interno o CRM).
     *
     * @param int $aziendaId ID dell'azienda (194 = personale interno)
     * @param array $fields Whitelist campi da restituire (default: nomeCompleto, email)
     * @param bool $includeRaw Se true, include il record originale nel campo 'raw'
     * @return array
     */
    public static function getCompanyContacts(int $aziendaId, array $fields = [], bool $includeRaw = false)
    {
        global $database;
        $aziendaId = (int) $aziendaId;
        if ($aziendaId <= 0)
            return [];

        if (empty($fields)) {
            $fields = ['nomeCompleto', 'email', 'telefono', 'cellulare'];
        }

        $result = [];

        if ($aziendaId === 194) {
            // Caso Personale (solo attivi)
            // TODO: rimuovere fallback dopo deprecazione personale
            $sql = "
                SELECT
                    p.user_id,
                    COALESCE(CONCAT(hr.firstname, ' ', hr.surname), p.Nominativo) AS Nominativo,
                    COALESCE(hr.email_aziendale, p.Email_Aziendale) AS Email_Aziendale,
                    COALESCE(op.interno, '') AS telefono,
                    COALESCE(hr.cellulare_aziendale, p.Cellulare_Aziendale) AS cellulare,
                    (
                        SELECT hr2.hr_role_desc
                        FROM hr_resource_role hrr2
                        JOIN hr_role hr2 ON hr2.id_hrrole = hrr2.id_hrrole
                        WHERE hrr2.id_hresource = p.Cod_Operatore
                        ORDER BY hrr2.relev_role_perc DESC
                        LIMIT 1
                    ) AS hr_role_desc
                FROM personale p
                LEFT JOIN hr_resource hr ON hr.id_hresource = p.Cod_Operatore
                LEFT JOIN office_positions op ON p.user_id = op.user_id
                WHERE p.attivo = 1
                ORDER BY Nominativo ASC
            ";

            // Usa pattern esistente nel file: $database->query()
            $rows = $database->query($sql, [], __FILE__);
            // Se query fallisce o è vuota, $rows potrebbe essere false o array vuoto
            if ($rows) {
                foreach ($rows as $r) {
                    $item = [
                        'id' => (int) $r['user_id'],
                        'source' => 'personale'
                    ];

                    // Mapping dinamico campi richiesti
                    if (in_array('nomeCompleto', $fields))
                        $item['nomeCompleto'] = $r['Nominativo'];
                    if (in_array('email', $fields))
                        $item['email'] = $r['Email_Aziendale'];
                    if (in_array('telefono', $fields))
                        $item['telefono'] = $r['telefono'];
                    if (in_array('cellulare', $fields))
                        $item['cellulare'] = $r['cellulare'];
                    if (in_array('ruolo', $fields))
                        $item['ruolo'] = $r['hr_role_desc'];

                    if ($includeRaw)
                        $item['raw'] = $r;

                    $result[] = $item;
                }
            }
        } else {
            // Caso CRM (Anagrafiche Contatti)
            // Nota: non usiamo ProtocolloEmailService per disaccoppiamento
            $sql = "SELECT id, cognome_e_nome, email, telefono, cellulare, ruolo 
                     FROM anagrafiche_contatti 
                     WHERE azienda_id = :aid
                     ORDER BY cognome_e_nome ASC";

            $rows = $database->query($sql, [':aid' => $aziendaId], __FILE__);
            if ($rows) {
                foreach ($rows as $r) {
                    $item = [
                        'id' => (int) $r['id'],
                        'source' => 'crm'
                    ];

                    // Mapping
                    if (in_array('nomeCompleto', $fields))
                        $item['nomeCompleto'] = $r['cognome_e_nome'];
                    if (in_array('email', $fields))
                        $item['email'] = $r['email'];
                    if (in_array('telefono', $fields))
                        $item['telefono'] = $r['telefono'];
                    if (in_array('cellulare', $fields))
                        $item['cellulare'] = $r['cellulare'];
                    if (in_array('ruolo', $fields))
                        $item['ruolo'] = $r['ruolo'];

                    if ($includeRaw)
                        $item['raw'] = $r;

                    $result[] = $item;
                }
            }
        }

        return $result;
    }

}
?>