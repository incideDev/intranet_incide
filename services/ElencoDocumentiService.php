<?php
// FILE: services/ElencoDocumentiService.php
// Namespace: Services\ElencoDocumentiService
//
// Fonti dati commessa (read-only):
//   - elenco_commesse (codice, oggetto, stato, cliente, responsabile_commessa, business_unit, ...)
//
// Tabelle proprie intranet (schema v2 da piano_integrazione):
//   - elenco_doc_commessa    → template lookup per commessa
//   - elenco_doc_sections    → sezioni di raggruppamento
//   - elenco_doc_documents   → documenti con tutti i campi
//   - elenco_doc_revisions   → storico revisioni
//   - elenco_doc_submittals  → submittal con stato e destinatari
//
// Permessi: 'view_commesse', 'edit_commessa'

namespace Services;

class ElencoDocumentiService
{
    /**
     * Main action router for elenco_documenti section
     * Follows project pattern: handleAction($action, $input)
     */
    /**
     * Decodes the categories from a template row.
     * Reads from the `categories` JSON column.
     */
    private static function decodeCategories(array $template): array
    {
        if (!empty($template['categories'])) {
            $cats = json_decode($template['categories'], true);
            if (is_array($cats)) {
                return $cats;
            }
        }

        return [];
    }

    /**
     * Builds a dynamic document code from segments + categories order.
     */
    private static function buildCodeFromSegments(string $idProject, array $segments, array $categories, int $num, string $rev): string
    {
        $parts = [$idProject];
        foreach ($categories as $cat) {
            $parts[] = $segments[$cat['key']] ?? '';
        }
        $parts[] = str_pad($num, 4, '0', STR_PAD_LEFT);
        $parts[] = $rev;
        return implode('-', $parts);
    }

    public static function handleAction($action, $input)
    {
        switch ($action) {
            case 'getCommessaData':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::getCommessaData($idProject);

            case 'getRisorse':
                return self::getPersonale();

            case 'getTemplate':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $templateId = isset($input['templateId']) ? (int)$input['templateId'] : null;
                return self::getTemplate($idProject, $templateId);

            case 'saveTemplate':
                return self::saveTemplate($input);

            case 'getTemplateList':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::getTemplateList($idProject);

            case 'applyTemplate':
                return self::applyTemplate($input);

            case 'duplicateTemplate':
                return self::duplicateTemplate($input);

            case 'deleteTemplate':
                return self::deleteTemplate($input);

            case 'getSections':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::getSections($idProject);

            case 'saveSection':
                return self::saveSection($input);

            case 'deleteSection':
                return self::deleteSection($input);

            case 'getDocumenti':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::getDocumenti($idProject);

            case 'saveDocumento':
                return self::saveDocumento($input);

            case 'deleteDocumento':
                return self::deleteDocumento($input);

            case 'createRevision':
                return self::createRevision($input);

            case 'getSubmittals':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::getSubmittals($idProject);

            case 'saveSubmittal':
                return self::saveSubmittal($input);

            case 'sendMail':
                return self::sendMail($input);

            case 'exportExcel':
                return self::exportExcel($input);

            // ── Nextcloud ──────────────────────────────────────────
            case 'listNcFolder':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::listNcFolder($idProject);

            case 'uploadNcFile':
                return self::uploadNcFile($input);

            case 'attachNcFile':
                return self::attachNcFile($input);

            case 'detachNcFile':
                return self::detachNcFile($input);

            case 'deleteNcFile':
                return self::deleteNcFile($input);

            // ── Repository ──────────────────────────────────────────
            case 'listRepoFolders':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::listRepoFolders($idProject);

            case 'listRepoFiles':
                $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $folder = filter_var($input['folder'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                return self::listRepoFiles($idProject, $folder);

            case 'moveRepoFile':
                return self::moveRepoFile($input);

            case 'deleteRepoFile':
                return self::deleteRepoFile($input);

            case 'uploadRepoFile':
                return self::uploadRepoFile($input);

            default:
                return ['success' => false, 'message' => "Action '{$action}' non riconosciuta"];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 1. DATI COMMESSA (da elenco_commesse)
    // ─────────────────────────────────────────────────────────────

    /**
     * Restituisce i metadati di una commessa per l'intestazione della pagina
     * e per la lettera di trasmissione.
     */
    public static function getCommessaData(string $idProject): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($idProject, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (!$idProject) {
            return ['success' => false, 'message' => 'IdProject non valido'];
        }

        $sql = "
            SELECT
                codice,
                oggetto,
                stato,
                responsabile_commessa   AS respCommessa,
                data_inizio_prevista    AS dataApertura,
                data_fine_prevista      AS dataChiusura,
                business_unit           AS businessUnit,
                cliente
            FROM elenco_commesse
            WHERE codice = ?
            LIMIT 1
        ";

        $stmt = $database->query($sql, [$idProject], __FILE__);
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['success' => false, 'message' => "Commessa '{$idProject}' non trovata"];
        }

        return ['success' => true, 'data' => $row];
    }

    // ─────────────────────────────────────────────────────────────
    // 2. RISORSE HR (per select Responsabile documento)
    // ─────────────────────────────────────────────────────────────

    /**
     * Wrapper che estrae la BU dal progetto e restituisce le risorse.
     */
    /**
     * Restituisce il personale attivo per i dropdown responsabile.
     */
    public static function getPersonale(): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $stmt = $database->query(
            "SELECT user_id, Nominativo FROM personale WHERE attivo = 1 ORDER BY Nominativo ASC",
            [],
            __FILE__
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['success' => true, 'data' => $rows];
    }

    // ─────────────────────────────────────────────────────────────
    // 3. TEMPLATE CONFIGURAZIONE (lookup Fase/Zona/Disciplina/Tipo)
    // ─────────────────────────────────────────────────────────────

    /**
     * Returns the active template for a project.
     * If $templateId is given, fetches that specific template (for editor).
     * Otherwise looks up the project's assigned template, falling back to global.
     */
    public static function getTemplate(string $idProject, ?int $templateId = null): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        if ($templateId) {
            // Fetch specific template by ID (for editing)
            $sql = "SELECT * FROM elenco_doc_commessa WHERE id = ? LIMIT 1";
            $stmt = $database->query($sql, [$templateId], __FILE__);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC);
        } else {
            // Look up project's assigned template
            $sql = "
                SELECT t.* FROM elenco_doc_commessa t
                INNER JOIN elenco_doc_project_template pt ON pt.template_id = t.id
                WHERE pt.id_project = ?
                LIMIT 1
            ";
            $stmt = $database->query($sql, [$idProject], __FILE__);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Fallback: global template
            if (!$template) {
                $sql = "SELECT * FROM elenco_doc_commessa WHERE is_global = 1 ORDER BY id LIMIT 1";
                $stmt = $database->query($sql, [], __FILE__);
                $template = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
        }

        if (!$template) {
            $defaultCategories = [
                ['key' => 'fase', 'label' => 'Fase', 'items' => [
                    ['cod' => 'PP', 'desc' => 'PP'], ['cod' => 'PD', 'desc' => 'PD'],
                    ['cod' => 'PE', 'desc' => 'PE'], ['cod' => 'ES', 'desc' => 'ES']
                ]],
                ['key' => 'zona', 'label' => 'Zona', 'items' => [
                    ['cod' => 'GG', 'desc' => 'GG'], ['cod' => '00', 'desc' => '00'],
                    ['cod' => '01', 'desc' => '01'], ['cod' => '02', 'desc' => '02']
                ]],
                ['key' => 'disc', 'label' => 'Disciplina', 'items' => [
                    ['cod' => 'GE', 'desc' => 'GE'], ['cod' => 'AR', 'desc' => 'AR'],
                    ['cod' => 'SA', 'desc' => 'SA'], ['cod' => 'EE', 'desc' => 'EE'], ['cod' => 'MA', 'desc' => 'MA']
                ]],
                ['key' => 'tipo', 'label' => 'Tipo', 'items' => [
                    ['cod' => 'RT', 'desc' => 'Relazione tecnica'],
                    ['cod' => 'E1', 'desc' => 'Piante'],
                    ['cod' => 'E2', 'desc' => 'Sezioni']
                ]]
            ];
            return [
                'success' => true,
                'data' => [
                    'id' => null,
                    'nome_template' => 'Nessun Template',
                    'categories' => $defaultCategories
                ]
            ];
        }

        // Decode categories (new format or fallback to old columns)
        $template['categories'] = self::decodeCategories($template);

        return ['success' => true, 'data' => $template];
    }

    /**
     * Salva il template di configurazione per la commessa.
     */
    public static function saveTemplate(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $nomeTemplate = filter_var($input['nomeTemplate'] ?? 'Template', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $isGlobal = !empty($input['isGlobal']) ? 1 : 0;

        // Accept categories array (new dynamic format)
        $categoriesRaw = $input['categories'] ?? [];
        // Sanitize category keys and labels
        $categories = [];
        foreach ($categoriesRaw as $cat) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower($cat['key'] ?? ''));
            if (empty($key)) continue;
            $categories[] = [
                'key' => $key,
                'label' => filter_var($cat['label'] ?? $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'items' => array_map(function ($item) {
                    return [
                        'cod' => filter_var($item['cod'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                        'desc' => filter_var($item['desc'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                    ];
                }, $cat['items'] ?? [])
            ];
        }
        // Validate unique keys
        $seenKeys = [];
        foreach ($categories as $cat) {
            if (in_array($cat['key'], $seenKeys)) {
                return ['success' => false, 'message' => 'Chiave categoria duplicata: ' . $cat['key']];
            }
            $seenKeys[] = $cat['key'];
        }

        $categoriesJson = json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!$idProject && !$isGlobal) {
            return ['success' => false, 'message' => 'idProject obbligatorio per template non globale'];
        }

        $templateId = $input['templateId'] ?? null;

        if ($templateId) {
            // UPDATE
            $sql = "
                UPDATE elenco_doc_commessa
                SET nome_template = ?, categories = ?, updated_at = NOW()
                WHERE id = ?
            ";
            $database->query($sql, [$nomeTemplate, $categoriesJson, $templateId], __FILE__);
        } else {
            // INSERT — new templates are always global/shared
            $sql = "
                INSERT INTO elenco_doc_commessa
                    (id_project, nome_template, is_global, categories)
                VALUES ('GLOBAL', ?, 1, ?)
            ";
            $database->query($sql, [
                $nomeTemplate, $categoriesJson
            ], __FILE__);
            $templateId = $database->lastInsertId();
        }

        // Return full updated template data
        return self::getTemplate('', (int)$templateId);
    }

    // ─────────────────────────────────────────────────────────────
    // 3b. TEMPLATE MANAGEMENT (list, apply, duplicate, delete)
    // ─────────────────────────────────────────────────────────────

    /**
     * Returns all templates with in_use flag and used_by_count for the given project.
     */
    public static function getTemplateList(string $idProject): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        // Get all templates with usage count
        $sql = "
            SELECT t.*,
                   COUNT(pt.id_project) AS used_by_count
            FROM elenco_doc_commessa t
            LEFT JOIN elenco_doc_project_template pt ON pt.template_id = t.id
            GROUP BY t.id
            ORDER BY t.is_global DESC, t.nome_template ASC
        ";
        $stmt = $database->query($sql, [], __FILE__);
        $templates = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get active template for this project
        $sqlActive = "SELECT template_id FROM elenco_doc_project_template WHERE id_project = ? LIMIT 1";
        $stmtActive = $database->query($sqlActive, [$idProject], __FILE__);
        $activeRow = $stmtActive->fetch(\PDO::FETCH_ASSOC);
        $activeId = $activeRow ? (int)$activeRow['template_id'] : null;

        // Decode categories and add flags
        foreach ($templates as &$t) {
            $t['categories'] = self::decodeCategories($t);
            $t['in_use'] = ((int)$t['id'] === $activeId);
            $t['used_by_count'] = (int)$t['used_by_count'];
        }

        return ['success' => true, 'data' => $templates];
    }

    /**
     * Associates a template to a project (upsert).
     */
    public static function applyTemplate(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $templateId = isset($input['templateId']) ? (int)$input['templateId'] : null;

        if (!$idProject || !$templateId) {
            return ['success' => false, 'message' => 'idProject e templateId obbligatori'];
        }

        // Verify template exists
        $sql = "SELECT id FROM elenco_doc_commessa WHERE id = ? LIMIT 1";
        $stmt = $database->query($sql, [$templateId], __FILE__);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'Template non trovato'];
        }

        // Upsert association
        $sql = "
            INSERT INTO elenco_doc_project_template (id_project, template_id, assigned_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE template_id = VALUES(template_id), assigned_at = NOW()
        ";
        $database->query($sql, [$idProject, $templateId], __FILE__);

        return ['success' => true];
    }

    /**
     * Duplicates a template with a new name.
     */
    public static function duplicateTemplate(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $templateId = isset($input['templateId']) ? (int)$input['templateId'] : null;
        if (!$templateId) {
            return ['success' => false, 'message' => 'templateId obbligatorio'];
        }

        // Fetch source template
        $sql = "SELECT * FROM elenco_doc_commessa WHERE id = ? LIMIT 1";
        $stmt = $database->query($sql, [$templateId], __FILE__);
        $source = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$source) {
            return ['success' => false, 'message' => 'Template sorgente non trovato'];
        }

        $newName = filter_var(
            $input['nomeTemplate'] ?? ('Copia di ' . $source['nome_template']),
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );

        // Insert clone as global
        $sql = "
            INSERT INTO elenco_doc_commessa
                (id_project, nome_template, is_global, categories)
            VALUES ('GLOBAL', ?, 1, ?)
        ";
        $database->query($sql, [
            $newName, $source['categories']
        ], __FILE__);
        $newId = $database->lastInsertId();

        return ['success' => true, 'data' => ['id' => $newId, 'nome_template' => $newName]];
    }

    /**
     * Deletes a template if not used by any project.
     */
    public static function deleteTemplate(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $templateId = isset($input['templateId']) ? (int)$input['templateId'] : null;
        if (!$templateId) {
            return ['success' => false, 'message' => 'templateId obbligatorio'];
        }

        // Check usage
        $sql = "SELECT COUNT(*) AS cnt FROM elenco_doc_project_template WHERE template_id = ?";
        $stmt = $database->query($sql, [$templateId], __FILE__);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ((int)($row['cnt'] ?? 0) > 0) {
            return ['success' => false, 'message' => 'Impossibile eliminare: template in uso da ' . $row['cnt'] . ' commesse'];
        }

        $database->query("DELETE FROM elenco_doc_commessa WHERE id = ?", [$templateId], __FILE__);

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────
    // 4. SEZIONI DI RAGGRUPPAMENTO
    // ─────────────────────────────────────────────────────────────

    /**
     * Restituisce le sezioni di una commessa.
     */
    public static function getSections(string $idProject): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $sql = "SELECT * FROM elenco_doc_sections WHERE id_project = ? ORDER BY ordine, id";
        $stmt = $database->query($sql, [$idProject], __FILE__);
        $sections = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['success' => true, 'data' => $sections];
    }

    /**
     * Salva o crea una sezione.
     */
    public static function saveSection(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $sectionId = filter_var($input['sectionId'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $nome = filter_var($input['nome'] ?? 'Nuova Sezione', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $ordine = filter_var($input['ordine'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $rangeNumDa = filter_var($input['rangeNumDa'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $rangeNumA = filter_var($input['rangeNumA'] ?? 999, FILTER_VALIDATE_INT) ?: 999;

        if (!$idProject) {
            return ['success' => false, 'message' => 'idProject obbligatorio'];
        }

        if ($sectionId) {
            // UPDATE
            $sql = "
                UPDATE elenco_doc_sections
                SET nome = ?, ordine = ?, range_num_da = ?, range_num_a = ?
                WHERE id = ? AND id_project = ?
            ";
            $database->query($sql, [$nome, $ordine, $rangeNumDa, $rangeNumA, $sectionId, $idProject], __FILE__);
        } else {
            // INSERT
            $sql = "
                INSERT INTO elenco_doc_sections (id_project, nome, ordine, range_num_da, range_num_a)
                VALUES (?, ?, ?, ?, ?)
            ";
            $database->query($sql, [$idProject, $nome, $ordine, $rangeNumDa, $rangeNumA], __FILE__);
            $sectionId = $database->lastInsertId();
        }

        return ['success' => true, 'sectionId' => (int)$sectionId];
    }

    /**
     * Elimina una sezione (solo se vuota).
     */
    public static function deleteSection(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $sectionId = filter_var($input['sectionId'] ?? null, FILTER_VALIDATE_INT);
        if (!$sectionId) {
            return ['success' => false, 'message' => 'sectionId obbligatorio'];
        }

        // Verifica che la sezione sia vuota
        $sql = "SELECT COUNT(*) FROM elenco_doc_documents WHERE id_section = ?";
        $stmt = $database->query($sql, [$sectionId], __FILE__);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            return ['success' => false, 'message' => 'Impossibile eliminare: la sezione contiene documenti'];
        }

        $sql = "DELETE FROM elenco_doc_sections WHERE id = ?";
        $database->query($sql, [$sectionId], __FILE__);

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────
    // 5. DOCUMENTI
    // ─────────────────────────────────────────────────────────────

    /**
     * Restituisce tutti i documenti di una commessa raggruppati per sezione.
     * Include template lookups per i dropdown.
     */
    public static function getDocumenti(string $idProject): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        // Ottieni sezioni
        $sectionsResult = self::getSections($idProject);
        $sections = $sectionsResult['data'] ?? [];

        // Ottieni documenti
        $sql = "
            SELECT d.*, s.nome AS section_name
            FROM elenco_doc_documents d
            LEFT JOIN elenco_doc_sections s ON s.id = d.id_section
            WHERE d.id_project = ?
            ORDER BY s.ordine, d.seg_numero
        ";
        $stmt = $database->query($sql, [$idProject], __FILE__);
        $docs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Raggruppa per sezione
        $docsBySection = [];
        foreach ($docs as $doc) {
            $secId = $doc['id_section'] ?: 0;
            if (!isset($docsBySection[$secId])) {
                $docsBySection[$secId] = [];
            }
            // Decodifica nc_files JSON
            $doc['nc_files'] = json_decode($doc['nc_files'] ?? '[]', true) ?: [];
            $docsBySection[$secId][] = $doc;
        }

        // Assembla risultato con sezioni e documenti
        $result = [];
        foreach ($sections as $sec) {
            $secId = $sec['id'] ?? 0;
            $result[] = [
                'section' => $sec,
                'docs' => $docsBySection[$secId] ?? []
            ];
        }

        // Ottieni template lookups (dynamic categories)
        $templateResult = self::getTemplate($idProject);
        $categories = [];
        $templateName = '';
        if ($templateResult['success'] && !empty($templateResult['data'])) {
            $t = $templateResult['data'];
            $categories = $t['categories'] ?? [];
            $templateName = $t['nome_template'] ?? '';
        }

        return [
            'success' => true,
            'data' => [
                'sections' => $result,
                'categories' => $categories,
                'template_name' => $templateName
            ]
        ];
    }

    /**
     * Salva o aggiorna un documento.
     */
    public static function saveDocumento(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $docId = filter_var($input['docId'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $idSection = filter_var($input['idSection'] ?? null, FILTER_VALIDATE_INT);

        // Dynamic segments (new format)
        $segmentsRaw = $input['segments'] ?? [];
        $segments = [];
        foreach ($segmentsRaw as $k => $v) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower($k));
            $segments[$key] = filter_var($v, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        }
        $segmentsJson = json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $segNumero = filter_var($input['segNumero'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $titolo = filter_var($input['titolo'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $tipoDocumento = filter_var($input['tipoDocumento'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $responsabile = filter_var($input['responsabile'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $outputSoftware = filter_var($input['outputSoftware'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $avanzamentoPct = filter_var($input['avanzamentoPct'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $stato = in_array($input['stato'] ?? '', ['PIANIFICATO', 'IN CORSO', 'EMESSO', 'IN REVISIONE'])
                 ? $input['stato'] : 'PIANIFICATO';
        $revisione = filter_var($input['revisione'] ?? 'RA', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $dataInizio = $input['dataInizio'] ?: null;
        $dataFinePrev = $input['dataFinePrev'] ?: null;
        $dataEmissione = $input['dataEmissione'] ?: null;
        $idSubmittal = filter_var($input['idSubmittal'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $ncFiles = json_encode($input['ncFiles'] ?? []);
        $note = filter_var($input['note'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$idProject || !$idSection || !$titolo) {
            return ['success' => false, 'message' => 'Campi obbligatori mancanti'];
        }

        // Capture old doc data BEFORE update for NC folder rename
        $oldDocData = null;
        if ($docId) {
            $oldDocStmt = $database->query(
                "SELECT segments, seg_numero, revisione, titolo FROM elenco_doc_documents WHERE id = ?",
                [$docId], __FILE__
            );
            $oldDocData = !empty($oldDocStmt[0]) ? $oldDocStmt[0] : null;
        }

        if ($docId) {
            // UPDATE
            $sql = "
                UPDATE elenco_doc_documents SET
                    id_section = ?, segments = ?, seg_numero = ?,
                    titolo = ?, tipo_documento = ?, responsabile = ?, output_software = ?,
                    avanzamento_pct = ?, stato = ?, revisione = ?,
                    data_inizio = ?, data_fine_prev = ?, data_emissione = ?,
                    id_submittal = ?, nc_files = ?, note = ?, updated_at = NOW()
                WHERE id = ? AND id_project = ?
            ";
            $database->query($sql, [
                $idSection, $segmentsJson, $segNumero,
                $titolo, $tipoDocumento, $responsabile, $outputSoftware,
                $avanzamentoPct, $stato, $revisione,
                $dataInizio, $dataFinePrev, $dataEmissione,
                $idSubmittal, $ncFiles, $note, $docId, $idProject
            ], __FILE__);
        } else {
            // INSERT
            $sql = "
                INSERT INTO elenco_doc_documents
                    (id_project, id_section, segments, seg_numero,
                     titolo, tipo_documento, responsabile, output_software,
                     avanzamento_pct, stato, revisione,
                     data_inizio, data_fine_prev, data_emissione,
                     id_submittal, nc_files, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $database->query($sql, [
                $idProject, $idSection, $segmentsJson, $segNumero,
                $titolo, $tipoDocumento, $responsabile, $outputSoftware,
                $avanzamentoPct, $stato, $revisione,
                $dataInizio, $dataFinePrev, $dataEmissione,
                $idSubmittal, $ncFiles, $note
            ], __FILE__);
            $docId = $database->lastInsertId();
        }

        // Auto-creazione/rename cartella Nextcloud
        $ncWarning = null;
        // Load template categories for folder name building
        $tplResult = self::getTemplate($idProject);
        $tplCategories = ($tplResult['success'] && !empty($tplResult['data']['categories']))
            ? $tplResult['data']['categories'] : [];
        try {
            $newFolderName = self::buildNcFolderName($idProject, $segments, $tplCategories, $segNumero, $revisione, $titolo);
            $ncBasePath = self::NC_ROOT . $idProject . '/';

            if (!empty($input['docId']) && $oldDocData) {
                // UPDATE: controlla se il codice è cambiato e rinomina
                // Uses $oldDocData captured BEFORE the UPDATE
                $od = $oldDocData;
                $oldSegments = !empty($od['segments'])
                    ? (json_decode($od['segments'], true) ?: [])
                    : [];
                $oldFolderName = self::buildNcFolderName($idProject, $oldSegments, $tplCategories, $od['seg_numero'], $od['revisione'], $od['titolo']);
                if ($oldFolderName !== $newFolderName) {
                    try {
                        \Services\Nextcloud\NextcloudService::movePath(
                            $ncBasePath . $oldFolderName,
                            $ncBasePath . $newFolderName
                        );
                    } catch (\Exception $e) {
                        // Se il move fallisce, prova a creare la nuova cartella
                        try {
                            \Services\Nextcloud\NextcloudService::ensureFolderExists($ncBasePath . $newFolderName . '/');
                        } catch (\Exception $e2) {
                            $ncWarning = 'Impossibile rinominare/creare cartella Nextcloud: ' . $e2->getMessage();
                        }
                    }
                }
            } else {
                // INSERT: crea cartella
                try {
                    \Services\Nextcloud\NextcloudService::ensureFolderExists($ncBasePath);
                    \Services\Nextcloud\NextcloudService::ensureFolderExists($ncBasePath . $newFolderName . '/');
                } catch (\Exception $e) {
                    $ncWarning = 'Impossibile creare cartella Nextcloud: ' . $e->getMessage();
                }
            }
        } catch (\Exception $e) {
            $ncWarning = 'Errore Nextcloud: ' . $e->getMessage();
        }

        $response = ['success' => true, 'docId' => (int)$docId];
        if ($ncWarning) $response['nc_warning'] = $ncWarning;
        return $response;
    }

    /**
     * Costruisce il nome cartella Nextcloud per un documento.
     * Supports dynamic segments from template categories.
     */
    private static function buildNcFolderName($idProject, $segments, $categories, $num, $rev, $titolo)
    {
        $code = self::buildCodeFromSegments($idProject, $segments, $categories, (int)$num, $rev);
        $safeName = preg_replace('/[<>:"\/\\\\|?*]/', '_', $code . ' - ' . $titolo);
        return trim($safeName);
    }

    /**
     * Elimina un documento.
     */
    public static function deleteDocumento(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $docId = filter_var($input['docId'] ?? null, FILTER_VALIDATE_INT);
        if (!$docId) {
            return ['success' => false, 'message' => 'docId obbligatorio'];
        }

        // Prima elimina revisioni associate
        $database->query("DELETE FROM elenco_doc_revisions WHERE id_document = ?", [$docId], __FILE__);
        // Poi elimina documento
        $database->query("DELETE FROM elenco_doc_documents WHERE id = ?", [$docId], __FILE__);

        return ['success' => true];
    }

    // ─────────────────────────────────────────────────────────────
    // 6. REVISIONI
    // ─────────────────────────────────────────────────────────────

    /**
     * Crea una nuova revisione di un documento EMESSO.
     * Sequenza: RA → R0 → RB → R1 → RC → R2 → RD → R3 ...
     */
    public static function createRevision(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $docId = filter_var($input['docId'] ?? null, FILTER_VALIDATE_INT);
        if (!$docId) {
            return ['success' => false, 'message' => 'docId obbligatorio'];
        }

        // Recupera documento originale
        $sql = "SELECT * FROM elenco_doc_documents WHERE id = ?";
        $stmt = $database->query($sql, [$docId], __FILE__);
        $doc = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$doc) {
            return ['success' => false, 'message' => 'Documento non trovato'];
        }

        if ($doc['stato'] !== 'EMESSO') {
            return ['success' => false, 'message' => 'Solo documenti EMESSO possono avere revisioni'];
        }

        // Calcola nuova revisione
        $currentRev = $doc['revisione'] ?? 'R0';
        $newRev = self::nextRevision($currentRev);

        // Salva revisione nello storico
        $database->query(
            "INSERT INTO elenco_doc_revisions (id_document, revisione, data) VALUES (?, ?, CURDATE())",
            [$docId, $currentRev], __FILE__
        );

        // Crea nuovo documento IN REVISIONE con stessi dati
        $sql = "
            INSERT INTO elenco_doc_documents
                (id_project, id_section, segments, seg_numero,
                 titolo, tipo_documento, responsabile, output_software,
                 avanzamento_pct, stato, revisione,
                 data_inizio, data_fine_prev, nc_files, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'IN REVISIONE', ?, NULL, NULL, ?, ?)
        ";
        $database->query($sql, [
            $doc['id_project'], $doc['id_section'],
            $doc['segments'], $doc['seg_numero'],
            $doc['titolo'], $doc['tipo_documento'], $doc['responsabile'], $doc['output_software'],
            $newRev, $doc['nc_files'], $doc['note']
        ], __FILE__);

        $newDocId = $database->lastInsertId();

        return ['success' => true, 'newDocId' => $newDocId, 'newRev' => $newRev];
    }

    /**
     * Calcola la prossima revisione nella sequenza.
     * RA → R0 → RB → R1 → RC → R2 → RD → R3 ...
     */
    private static function nextRevision(string $current): string
    {
        $map = [
            'RA' => 'R0', 'R0' => 'RB', 'RB' => 'R1', 'R1' => 'RC', 'RC' => 'R2',
            'R2' => 'RD', 'RD' => 'R3', 'R3' => 'RE', 'RE' => 'R4', 'R4' => 'RF', 'RF' => 'R5'
        ];
        return $map[$current] ?? 'R0';
    }

    // ─────────────────────────────────────────────────────────────
    // 7. SUBMITTAL
    // ─────────────────────────────────────────────────────────────

    /**
     * Restituisce tutti i submittal di una commessa con i documenti associati.
     */
    public static function getSubmittals(string $idProject): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $sql = "
            SELECT s.*,
                   (SELECT COUNT(*) FROM elenco_doc_documents d WHERE d.id_submittal = s.id) AS doc_count
            FROM elenco_doc_submittals s
            WHERE s.id_project = ?
            ORDER BY s.created_at DESC
        ";
        $stmt = $database->query($sql, [$idProject], __FILE__);
        $subs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Per ogni submittal, recupera i docIds
        foreach ($subs as &$sub) {
            $sqlDocs = "SELECT id FROM elenco_doc_documents WHERE id_submittal = ?";
            $stmtDocs = $database->query($sqlDocs, [$sub['id']], __FILE__);
            $sub['docIds'] = array_column($stmtDocs->fetchAll(\PDO::FETCH_ASSOC), 'id');
        }

        return ['success' => true, 'data' => $subs];
    }

    /**
     * Salva o aggiorna un submittal e assegna i documenti.
     */
    public static function saveSubmittal(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $subId = filter_var($input['subId'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $codice = filter_var($input['codice'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $segTipo = filter_var($input['segTipo'] ?? 'TR', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $segLettera = filter_var($input['segLettera'] ?? 'A', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $oggetto = filter_var($input['oggetto'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $destinatario = filter_var($input['destinatario'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $cc = filter_var($input['cc'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $scopo = filter_var($input['scopo'] ?? 'Per approvazione', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $modalita = filter_var($input['modalita'] ?? 'E-mail', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $riferimentoRup = filter_var($input['riferimentoRup'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $riferimentoImp = filter_var($input['riferimentoImp'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $dataConsegna = $input['dataConsegna'] ?: null;
        $stato = in_array($input['stato'] ?? '', ['Pianificato', 'Emesso']) ? $input['stato'] : 'Pianificato';
        $note = filter_var($input['note'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $docIds = is_array($input['docIds'] ?? null) ? $input['docIds'] : [];

        if (!$idProject || !$codice) {
            return ['success' => false, 'message' => 'Campi obbligatori mancanti'];
        }

        if ($subId) {
            // UPDATE
            $sql = "
                UPDATE elenco_doc_submittals SET
                    codice = ?, seg_tipo = ?, seg_lettera = ?, oggetto = ?,
                    destinatario = ?, cc = ?, scopo = ?, modalita = ?,
                    riferimento_rup = ?, riferimento_imp = ?,
                    data_consegna = ?, stato = ?, note = ?, updated_at = NOW()
                WHERE id = ? AND id_project = ?
            ";
            $database->query($sql, [
                $codice, $segTipo, $segLettera, $oggetto,
                $destinatario, $cc, $scopo, $modalita,
                $riferimentoRup, $riferimentoImp,
                $dataConsegna, $stato, $note, $subId, $idProject
            ], __FILE__);
        } else {
            // INSERT
            $sql = "
                INSERT INTO elenco_doc_submittals
                    (id_project, codice, seg_tipo, seg_lettera, oggetto,
                     destinatario, cc, scopo, modalita, riferimento_rup, riferimento_imp,
                     data_consegna, stato, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $database->query($sql, [
                $idProject, $codice, $segTipo, $segLettera, $oggetto,
                $destinatario, $cc, $scopo, $modalita, $riferimentoRup, $riferimentoImp,
                $dataConsegna, $stato, $note
            ], __FILE__);
            $subId = $database->lastInsertId();
        }

        // Aggiorna id_submittal sui documenti
        // Prima rimuovi da vecchi documenti
        $database->query("UPDATE elenco_doc_documents SET id_submittal = NULL WHERE id_submittal = ?", [$subId], __FILE__);
        // Poi assegna ai nuovi
        foreach ($docIds as $docId) {
            $docId = filter_var($docId, FILTER_VALIDATE_INT);
            if ($docId) {
                $database->query("UPDATE elenco_doc_documents SET id_submittal = ? WHERE id = ?", [$subId, $docId], __FILE__);
            }
        }

        // Se stato = Emesso, aggiorna data_emissione sui documenti
        if ($stato === 'Emesso' && $dataConsegna) {
            $database->query(
                "UPDATE elenco_doc_documents SET data_emissione = ?, stato = 'EMESSO' WHERE id_submittal = ?",
                [$dataConsegna, $subId], __FILE__
            );
        }

        return ['success' => true, 'subId' => $subId];
    }

    // ─────────────────────────────────────────────────────────────
    // 8. INVIO MAIL TRASMISSIONE (PHPMailer)
    // ─────────────────────────────────────────────────────────────

    /**
     * Invia la lettera di trasmissione via SMTP (PHPMailer).
     */
    public static function sendMail(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        // Validate input
        $to = filter_var($input['to'] ?? '', FILTER_VALIDATE_EMAIL);
        $ccRaw = $input['cc'] ?? '';
        $subject = filter_var($input['subject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $body = $input['body'] ?? '';
        $submittalId = filter_var($input['submittalId'] ?? 0, FILTER_VALIDATE_INT);
        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$to || !$subject) {
            return ['success' => false, 'message' => 'Destinatario e oggetto obbligatori'];
        }

        // Parse CC addresses
        $ccAddresses = [];
        if (!empty($ccRaw)) {
            $ccList = array_map('trim', explode(',', $ccRaw));
            foreach ($ccList as $addr) {
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $ccAddresses[] = $addr;
                }
            }
        }

        // PHPMailer è in IntLibs (non Composer) — stesso pattern di NotificationService
        require_once ROOT . '/IntLibs/PHPMailer/src/Exception.php';
        require_once ROOT . '/IntLibs/PHPMailer/src/PHPMailer.php';
        require_once ROOT . '/IntLibs/PHPMailer/src/SMTP.php';

        try {
            // Crea istanza PHPMailer con configurazione SMTP da .env
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            // Configurazione SMTP da environment variables
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USERNAME') ?: getenv('SMTP_USER') ?: '';
            $mail->Password = getenv('SMTP_PASSWORD') ?: getenv('SMTP_PASS') ?: '';
            $mail->SMTPSecure = getenv('SMTP_SECURE') ?: 'tls';
            $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
            $mail->CharSet = 'UTF-8';

            // Mittente
            $fromEmail = getenv('SMTP_FROM_EMAIL') ?: getenv('SMTP_USERNAME') ?: 'noreply@incide.it';
            $fromName = getenv('SMTP_FROM_NAME') ?: 'Intranet INCIDE';
            $mail->setFrom($fromEmail, $fromName);

            // Setup email
            $mail->Subject = $subject;
            $mail->Body = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
            $mail->AltBody = $body;
            $mail->isHTML(true);

            // Add recipient
            $mail->addAddress($to);

            // Add CC
            foreach ($ccAddresses as $addr) {
                $mail->addCC($addr);
            }

            // Generate and attach PDF from submittal
            if ($submittalId && $idProject) {
                $pdfBytes = ElencoDocumentiPdfService::generatePdfBytes([
                    'submittalId' => $submittalId,
                    'idProject'   => $idProject
                ]);
                if ($pdfBytes) {
                    $sub = $database->query(
                        "SELECT codice FROM elenco_doc_submittals WHERE id = ? LIMIT 1",
                        [$submittalId], __FILE__
                    )->fetch(\PDO::FETCH_ASSOC);
                    $subCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $sub['codice'] ?? 'submittal');
                    $filename = "Trasmissione_{$subCode}.pdf";
                    $mail->addStringAttachment($pdfBytes, $filename, 'base64', 'application/pdf');
                }
            }

            // Send
            $mail->send();

            return ['success' => true, 'message' => 'Mail inviata con successo'];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore SMTP: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 9. NEXTCLOUD — File allegati agli elaborati
    // Root: /INTRANET/ELABORATI/{idProject}/
    // ─────────────────────────────────────────────────────────────

    private const NC_ROOT = '/INTRANET/ELABORATI/';

    /**
     * Restituisce la lista dei file nella cartella Nextcloud del progetto.
     * Crea la cartella se non esiste.
     */
    public static function listNcFolder(string $idProject): array
    {
        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }
        if (!$idProject) {
            return ['success' => false, 'message' => 'idProject mancante'];
        }

        try {
            \Services\Nextcloud\NextcloudService::init();
            $folder = self::NC_ROOT . $idProject . '/';
            \Services\Nextcloud\NextcloudService::ensureFolderExists($folder);
            $items = \Services\Nextcloud\NextcloudService::listFolder($folder);
            // Filtra la cartella stessa, restituisce solo i file
            $files = array_values(array_filter($items, fn($i) => !($i['is_dir'] ?? false)));
            return ['success' => true, 'data' => $files, 'folder' => $folder];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Carica un file su Nextcloud e lo allega al documento specificato.
     * Legge da $_FILES['file'].
     */
    public static function uploadNcFile(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $docId = filter_var($input['docId'] ?? 0, FILTER_VALIDATE_INT);

        if (!$idProject || !$docId) {
            return ['success' => false, 'message' => 'idProject e docId obbligatori'];
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'File non ricevuto o errore upload'];
        }

        $tmpPath = $_FILES['file']['tmp_name'];
        $origName = basename($_FILES['file']['name']);
        // Sanifica nome file: mantieni solo caratteri sicuri
        $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $origName);
        $remotePath = self::NC_ROOT . $idProject . '/' . $safeName;

        try {
            \Services\Nextcloud\NextcloudService::init();
            \Services\Nextcloud\NextcloudService::ensureFolderExists(self::NC_ROOT . $idProject . '/');
            \Services\Nextcloud\NextcloudService::uploadFile($tmpPath, $remotePath);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Upload Nextcloud fallito: ' . $e->getMessage()];
        }

        // Allega al documento
        return self::attachNcFile([
            'docId'    => $docId,
            'idProject' => $idProject,
            'path'     => $remotePath,
            'name'     => $safeName,
            'mime'     => $_FILES['file']['type'] ?? 'application/octet-stream',
            'size'     => $_FILES['file']['size'] ?? 0,
        ]);
    }

    /**
     * Aggiunge un percorso Nextcloud all'array nc_files di un documento.
     */
    public static function attachNcFile(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $docId    = filter_var($input['docId'] ?? 0, FILTER_VALIDATE_INT);
        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $path     = filter_var($input['path'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $name     = filter_var($input['name'] ?? basename($path), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $mime     = filter_var($input['mime'] ?? 'application/octet-stream', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $size     = filter_var($input['size'] ?? 0, FILTER_VALIDATE_INT) ?: 0;

        if (!$docId || !$path) {
            return ['success' => false, 'message' => 'docId e path obbligatori'];
        }

        $stmt = $database->query(
            "SELECT nc_files FROM elenco_doc_documents WHERE id = ? AND id_project = ? LIMIT 1",
            [$docId, $idProject],
            __FILE__
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'message' => 'Documento non trovato'];
        }

        $files = json_decode($row['nc_files'] ?? '[]', true) ?: [];
        // Evita duplicati per path
        foreach ($files as $f) {
            if ($f['path'] === $path) {
                return ['success' => true, 'data' => $files, 'message' => 'File già allegato'];
            }
        }

        $files[] = [
            'path'          => $path,
            'name'          => $name,
            'mime'          => $mime,
            'size'          => $size,
            'last_modified' => date('Y-m-d H:i:s'),
        ];

        $database->query(
            "UPDATE elenco_doc_documents SET nc_files = ?, updated_at = NOW() WHERE id = ? AND id_project = ?",
            [json_encode($files, JSON_UNESCAPED_UNICODE), $docId, $idProject],
            __FILE__
        );

        return ['success' => true, 'data' => $files];
    }

    /**
     * Rimuove un file dall'array nc_files di un documento (non lo cancella da Nextcloud).
     */
    public static function detachNcFile(array $input): array
    {
        global $database;

        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $docId    = filter_var($input['docId'] ?? 0, FILTER_VALIDATE_INT);
        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $path     = $input['path'] ?? '';

        if (!$docId || !$path) {
            return ['success' => false, 'message' => 'docId e path obbligatori'];
        }

        $stmt = $database->query(
            "SELECT nc_files FROM elenco_doc_documents WHERE id = ? AND id_project = ? LIMIT 1",
            [$docId, $idProject],
            __FILE__
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'message' => 'Documento non trovato'];
        }

        $files = json_decode($row['nc_files'] ?? '[]', true) ?: [];
        $files = array_values(array_filter($files, fn($f) => $f['path'] !== $path));

        $database->query(
            "UPDATE elenco_doc_documents SET nc_files = ?, updated_at = NOW() WHERE id = ? AND id_project = ?",
            [json_encode($files, JSON_UNESCAPED_UNICODE), $docId, $idProject],
            __FILE__
        );

        return ['success' => true, 'data' => $files];
    }

    /**
     * Elimina un file da Nextcloud e lo rimuove dall'array nc_files del documento.
     */
    public static function deleteNcFile(array $input): array
    {
        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $path = $input['path'] ?? '';
        if (!$path) {
            return ['success' => false, 'message' => 'path obbligatorio'];
        }

        try {
            \Services\Nextcloud\NextcloudService::init();
            \Services\Nextcloud\NextcloudService::deletePath($path);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Cancellazione Nextcloud fallita: ' . $e->getMessage()];
        }

        // Rimuovi anche dal documento se docId passato
        if (!empty($input['docId'])) {
            return self::detachNcFile($input);
        }

        return ['success' => true];
    }

    // ── Export Excel ──────────────────────────────────────────

    private static function exportExcel($input)
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (empty($idProject)) {
            return ['success' => false, 'message' => 'idProject obbligatorio'];
        }

        $docs = $database->query(
            "SELECT d.*, s.nome as section_name
             FROM elenco_doc_documents d
             LEFT JOIN elenco_doc_sections s ON s.id = d.id_section
             WHERE d.id_project = ?
             ORDER BY s.ordine, s.id, d.seg_numero",
            [$idProject], __FILE__
        );

        // Get template categories for dynamic columns
        $tplResult = self::getTemplate($idProject);
        $tplCategories = ($tplResult['success'] && !empty($tplResult['data']['categories']))
            ? $tplResult['data']['categories'] : [];

        // Build dynamic header: Sezione + [category labels] + Numero + Rev + Codice + ...
        $header = ['<b>Sezione</b>'];
        foreach ($tplCategories as $cat) {
            $header[] = '<b>' . htmlspecialchars($cat['label']) . '</b>';
        }
        $header = array_merge($header, [
            '<b>Numero</b>','<b>Rev</b>','<b>Codice</b>','<b>Titolo</b>',
            '<b>Tipo Documento</b>','<b>Resp.</b>','<b>Output</b>',
            '<b>Stato</b>','<b>Avanzamento %</b>',
            '<b>Data Inizio</b>','<b>Data Fine Prev.</b>','<b>Data Emissione</b>'
        ]);

        $rows = [$header];
        foreach ($docs as $d) {
            // Decode segments
            $segs = !empty($d['segments'])
                ? (json_decode($d['segments'], true) ?: [])
                : [];

            $code = self::buildCodeFromSegments($idProject, $segs, $tplCategories, (int)($d['seg_numero'] ?? 0), $d['revisione'] ?? '');

            $row = [$d['section_name'] ?? ''];
            foreach ($tplCategories as $cat) {
                $row[] = $segs[$cat['key']] ?? '';
            }
            $row = array_merge($row, [
                $d['seg_numero'] ?? '', $d['revisione'] ?? '',
                $code, $d['titolo'] ?? '', $d['tipo_documento'] ?? '',
                $d['responsabile'] ?? '', $d['output_software'] ?? '',
                $d['stato'] ?? '', $d['avanzamento_pct'] ?? 0,
                $d['data_inizio'] ?? '', $d['data_fine_prev'] ?? '',
                $d['data_emissione'] ?? ''
            ]);
            $rows[] = $row;
        }

        require_once __DIR__ . '/../IntLibs/SimpleXLSXGen/SimpleXLSXGen.php';
        $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($rows);
        $filename = 'Elenco_Documenti_' . $idProject . '_' . date('Ymd') . '.xlsx';
        $xlsx->downloadAs($filename);
        exit;
    }

    // ─────────────────────────────────────────────────────────────
    // 12. REPOSITORY — Navigazione cartelle/file per la tab Repository
    // ─────────────────────────────────────────────────────────────

    /**
     * Lista le sottocartelle nella directory del progetto su Nextcloud.
     * Parsa il nome cartella: "{CODICE} - {TITOLO}" → codice + titolo separati.
     */
    private static function listRepoFolders(string $idProject): array
    {
        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }
        if (!$idProject) {
            return ['success' => false, 'message' => 'idProject mancante'];
        }

        try {
            \Services\Nextcloud\NextcloudService::init();
            $basePath = self::NC_ROOT . $idProject . '/';
            \Services\Nextcloud\NextcloudService::ensureFolderExists($basePath);
            $items = \Services\Nextcloud\NextcloudService::listFolder($basePath);

            $folders = [];
            foreach ($items as $item) {
                if (!($item['is_dir'] ?? false)) continue;
                $name = $item['name'] ?? '';
                if ($name === '' || $name === $idProject) continue;

                // Parse: "PRJ-FASE-ZONA-DISC-TIPO-NUM-REV - Titolo"
                $parts = explode(' - ', $name, 2);
                $code = $parts[0] ?? $name;
                $title = $parts[1] ?? '';

                // Codice breve: rimuovi prefisso progetto
                $shortCode = $code;
                if (str_starts_with($code, $idProject . '-')) {
                    $shortCode = substr($code, strlen($idProject) + 1);
                }

                $folders[] = [
                    'name' => $name,
                    'code' => $code,
                    'shortCode' => $shortCode,
                    'title' => $title,
                    'path' => $basePath . $name . '/',
                ];
            }

            usort($folders, fn($a, $b) => strcmp($a['code'], $b['code']));

            return ['success' => true, 'data' => $folders];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Lista i file dentro una specifica sottocartella del progetto.
     */
    private static function listRepoFiles(string $idProject, string $folder): array
    {
        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }
        if (!$idProject || !$folder) {
            return ['success' => false, 'message' => 'idProject e folder obbligatori'];
        }
        if (strpos($folder, '..') !== false) {
            return ['success' => false, 'message' => 'Nome cartella non valido'];
        }

        try {
            \Services\Nextcloud\NextcloudService::init();
            $folderPath = self::NC_ROOT . $idProject . '/' . $folder . '/';
            $items = \Services\Nextcloud\NextcloudService::listFolder($folderPath);

            $files = [];
            foreach ($items as $item) {
                if ($item['is_dir'] ?? false) continue;
                $files[] = [
                    'name' => $item['name'] ?? '',
                    'path' => $item['path'] ?? '',
                    'size' => $item['size'] ?? 0,
                    'mime' => $item['mime'] ?? '',
                    'lastModified' => $item['last_modified'] ?? '',
                    'fileUrl' => 'ajax.php?section=nextcloud&action=file&path=' . urlencode($item['path'] ?? ''),
                ];
            }

            return ['success' => true, 'data' => $files, 'folder' => $folder];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Sposta un file da una cartella a un'altra dentro il progetto.
     */
    private static function moveRepoFile(array $input): array
    {
        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $fromPath = $input['fromPath'] ?? '';
        $toFolder = filter_var($input['toFolder'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$idProject || !$fromPath || !$toFolder) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        $projectRoot = self::NC_ROOT . $idProject . '/';
        if (strpos($fromPath, $projectRoot) !== 0) {
            return ['success' => false, 'message' => 'Path non valido'];
        }
        if (strpos($toFolder, '..') !== false) {
            return ['success' => false, 'message' => 'Cartella destinazione non valida'];
        }

        $fileName = basename($fromPath);
        $toPath = $projectRoot . $toFolder . '/' . $fileName;

        try {
            \Services\Nextcloud\NextcloudService::init();
            \Services\Nextcloud\NextcloudService::movePath($fromPath, $toPath);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore spostamento: ' . $e->getMessage()];
        }
    }

    /**
     * Elimina un file da Nextcloud.
     */
    private static function deleteRepoFile(array $input): array
    {
        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $filePath = $input['filePath'] ?? '';

        if (!$idProject || !$filePath) {
            return ['success' => false, 'message' => 'Parametri mancanti'];
        }

        $projectRoot = self::NC_ROOT . $idProject . '/';
        if (strpos($filePath, $projectRoot) !== 0) {
            return ['success' => false, 'message' => 'Path non valido'];
        }

        try {
            \Services\Nextcloud\NextcloudService::init();
            \Services\Nextcloud\NextcloudService::deletePath($filePath);
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore eliminazione: ' . $e->getMessage()];
        }
    }

    /**
     * Carica un file in una specifica sottocartella del progetto.
     */
    private static function uploadRepoFile(array $input): array
    {
        if (!userHasPermission('edit_commessa')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        $idProject = filter_var($input['idProject'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $folder = filter_var($input['folder'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (!$idProject || !$folder) {
            return ['success' => false, 'message' => 'idProject e folder obbligatori'];
        }
        if (strpos($folder, '..') !== false) {
            return ['success' => false, 'message' => 'Nome cartella non valido'];
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'File non ricevuto'];
        }

        $tmpPath = $_FILES['file']['tmp_name'];
        $origName = basename($_FILES['file']['name']);
        $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $origName);
        $remotePath = self::NC_ROOT . $idProject . '/' . $folder . '/' . $safeName;

        try {
            \Services\Nextcloud\NextcloudService::init();
            \Services\Nextcloud\NextcloudService::uploadFile($tmpPath, $remotePath);
            return ['success' => true, 'data' => ['name' => $safeName, 'path' => $remotePath]];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Errore upload: ' . $e->getMessage()];
        }
    }
}
