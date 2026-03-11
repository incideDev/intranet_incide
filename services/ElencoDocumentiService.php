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
                return self::getTemplate($idProject);

            case 'saveTemplate':
                return self::saveTemplate($input);

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
     * Restituisce il template attivo per la commessa (o il globale se non esiste).
     */
    public static function getTemplate(string $idProject): array
    {
        global $database;

        if (!userHasPermission('view_commesse')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        // Prima cerca template specifico per commessa
        $sql = "SELECT * FROM elenco_doc_commessa WHERE id_project = ? AND is_global = 0 LIMIT 1";
        $stmt = $database->query($sql, [$idProject], __FILE__);
        $template = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Se non esiste, usa il globale
        if (!$template) {
            $sql = "SELECT * FROM elenco_doc_commessa WHERE is_global = 1 LIMIT 1";
            $stmt = $database->query($sql, [], __FILE__);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$template) {
            // Restituisci template vuoto di default
            return [
                'success' => true,
                'data' => [
                    'id' => null,
                    'nome_template' => 'Nuovo Template',
                    'fasi' => ['PP', 'PD', 'PE', 'ES'],
                    'zone' => ['GG', '00', '01', '02'],
                    'discipline' => ['GE', 'AR', 'SA', 'EE', 'MA'],
                    'tipi_documento' => [
                        ['cod' => 'RT', 'desc' => 'Relazione tecnica', 'tipo' => 'Report'],
                        ['cod' => 'E1', 'desc' => 'Piante', 'tipo' => 'Disegno'],
                        ['cod' => 'E2', 'desc' => 'Sezioni', 'tipo' => 'Disegno']
                    ]
                ]
            ];
        }

        // Decodifica JSON
        $template['fasi'] = json_decode($template['fasi'] ?? '[]', true) ?: [];
        $template['zone'] = json_decode($template['zone'] ?? '[]', true) ?: [];
        $template['discipline'] = json_decode($template['discipline'] ?? '[]', true) ?: [];
        $template['tipi_documento'] = json_decode($template['tipi_documento'] ?? '[]', true) ?: [];

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
        $fasi = json_encode($input['fasi'] ?? []);
        $zone = json_encode($input['zone'] ?? []);
        $discipline = json_encode($input['discipline'] ?? []);
        $tipiDocumento = json_encode($input['tipiDocumento'] ?? []);

        if (!$idProject && !$isGlobal) {
            return ['success' => false, 'message' => 'idProject obbligatorio per template non globale'];
        }

        $templateId = $input['templateId'] ?? null;

        if ($templateId) {
            // UPDATE
            $sql = "
                UPDATE elenco_doc_commessa
                SET nome_template = ?, fasi = ?, zone = ?, discipline = ?, tipi_documento = ?, updated_at = NOW()
                WHERE id = ?
            ";
            $database->query($sql, [$nomeTemplate, $fasi, $zone, $discipline, $tipiDocumento, $templateId], __FILE__);
        } else {
            // INSERT
            $sql = "
                INSERT INTO elenco_doc_commessa
                    (id_project, nome_template, is_global, fasi, zone, discipline, tipi_documento)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            $database->query($sql, [
                $isGlobal ? 'GLOBAL' : $idProject,
                $nomeTemplate, $isGlobal, $fasi, $zone, $discipline, $tipiDocumento
            ], __FILE__);
            $templateId = $database->lastInsertId();
        }

        return ['success' => true, 'templateId' => $templateId];
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

        // Ottieni template lookups
        $templateResult = self::getTemplate($idProject);
        $lookups = [];
        if ($templateResult['success'] && !empty($templateResult['data'])) {
            $t = $templateResult['data'];
            $lookups = [
                'fasi' => $t['fasi'] ?? [],
                'zone' => $t['zone'] ?? [],
                'discipline' => $t['discipline'] ?? [],
                'tipi_documento' => $t['tipi_documento'] ?? []
            ];
        }

        return [
            'success' => true,
            'data' => [
                'sections' => $result,
                'lookups' => $lookups
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
        $segFase = filter_var($input['segFase'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $segZona = filter_var($input['segZona'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $segDisc = filter_var($input['segDisc'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $segTipo = filter_var($input['segTipo'] ?? '', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
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

        if ($docId) {
            // UPDATE
            $sql = "
                UPDATE elenco_doc_documents SET
                    id_section = ?, seg_fase = ?, seg_zona = ?, seg_disc = ?, seg_tipo = ?, seg_numero = ?,
                    titolo = ?, tipo_documento = ?, responsabile = ?, output_software = ?,
                    avanzamento_pct = ?, stato = ?, revisione = ?,
                    data_inizio = ?, data_fine_prev = ?, data_emissione = ?,
                    id_submittal = ?, nc_files = ?, note = ?, updated_at = NOW()
                WHERE id = ? AND id_project = ?
            ";
            $database->query($sql, [
                $idSection, $segFase, $segZona, $segDisc, $segTipo, $segNumero,
                $titolo, $tipoDocumento, $responsabile, $outputSoftware,
                $avanzamentoPct, $stato, $revisione,
                $dataInizio, $dataFinePrev, $dataEmissione,
                $idSubmittal, $ncFiles, $note, $docId, $idProject
            ], __FILE__);
        } else {
            // INSERT
            $sql = "
                INSERT INTO elenco_doc_documents
                    (id_project, id_section, seg_fase, seg_zona, seg_disc, seg_tipo, seg_numero,
                     titolo, tipo_documento, responsabile, output_software,
                     avanzamento_pct, stato, revisione,
                     data_inizio, data_fine_prev, data_emissione,
                     id_submittal, nc_files, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $database->query($sql, [
                $idProject, $idSection, $segFase, $segZona, $segDisc, $segTipo, $segNumero,
                $titolo, $tipoDocumento, $responsabile, $outputSoftware,
                $avanzamentoPct, $stato, $revisione,
                $dataInizio, $dataFinePrev, $dataEmissione,
                $idSubmittal, $ncFiles, $note
            ], __FILE__);
            $docId = $database->lastInsertId();
        }

        return ['success' => true, 'docId' => (int)$docId];
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
                (id_project, id_section, seg_fase, seg_zona, seg_disc, seg_tipo, seg_numero,
                 titolo, tipo_documento, responsabile, output_software,
                 avanzamento_pct, stato, revisione,
                 data_inizio, data_fine_prev, nc_files, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'IN REVISIONE', ?, NULL, NULL, ?, ?)
        ";
        $database->query($sql, [
            $doc['id_project'], $doc['id_section'],
            $doc['seg_fase'], $doc['seg_zona'], $doc['seg_disc'], $doc['seg_tipo'], $doc['seg_numero'],
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
        $scopo = in_array($input['scopo'] ?? '', ['email', 'PEC', 'portale']) ? $input['scopo'] : 'email';
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
                    destinatario = ?, cc = ?, scopo = ?,
                    riferimento_rup = ?, riferimento_imp = ?,
                    data_consegna = ?, stato = ?, note = ?, updated_at = NOW()
                WHERE id = ? AND id_project = ?
            ";
            $database->query($sql, [
                $codice, $segTipo, $segLettera, $oggetto,
                $destinatario, $cc, $scopo,
                $riferimentoRup, $riferimentoImp,
                $dataConsegna, $stato, $note, $subId, $idProject
            ], __FILE__);
        } else {
            // INSERT
            $sql = "
                INSERT INTO elenco_doc_submittals
                    (id_project, codice, seg_tipo, seg_lettera, oggetto,
                     destinatario, cc, scopo, riferimento_rup, riferimento_imp,
                     data_consegna, stato, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $database->query($sql, [
                $idProject, $codice, $segTipo, $segLettera, $oggetto,
                $destinatario, $cc, $scopo, $riferimentoRup, $riferimentoImp,
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
        $pdfB64 = $input['pdfB64'] ?? null;
        $subCode = filter_var($input['subCode'] ?? 'submittal', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

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

            // Add PDF attachment if provided
            if ($pdfB64) {
                $pdfBytes = base64_decode($pdfB64);
                if ($pdfBytes !== false) {
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
}
