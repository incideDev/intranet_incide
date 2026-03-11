<?php

namespace Services;

// Carica funzioni globali se non già caricate
if (!function_exists('isAdmin')) {
    require_once(substr(__DIR__, 0, strpos(__DIR__, '/services')) . '/core/functions.php');
}

/**
 * MOM SERVICE GLOBALE - ModQC06
 * 
 * Service centralizzato per tutte le operazioni MOM (Verbale Riunione).
 * Replica struttura template ModQC06 (Verbale Riunione / sopralluogo).
 * 
 * Integra TaskService per sincronizzazione Action Items (AI) -> Tasks.
 * 
 * Usa tabelle normalizzate:
 * - mom (testata con campi documentali QC)
 * - mom_partecipanti (partecipante, societa, copia_a, ordinamento)
 * - mom_items (tabella unica: item_type ENUM('AI','OBS','EVE'))
 * - mom_allegati
 * 
 * ARCHITETTURA:
 * - context_type: tipo di entità (es: 'commessa', 'gara', 'crm', 'hr', 'generale')
 * - context_id: identificatore dell'entità (es: codice commessa, ID gara)
 * - progressivo: unico per anno, generato atomicamente
 * - ai_number: progressivo globale unico per AI (contatore atomico)
 * 
 * Campi documentali QC (ModQC06):
 * - doc_category: progetto/commessa/generale
 * - doc_ref_type: protocollo/commessa/altro
 * - doc_ref_code, doc_ref_progressivo, doc_sigla
 * 
 * DB usa snake_case (context_type, context_id, data_meeting, stato, progressivo)
 * JS usa camelCase (contextType, contextId, dataMeeting, stato, progressivo)
 * Il service mappa automaticamente tra i due formati.
 */
class MomService
{
    /**
     * Controllo permessi centralizzato per tutte le operazioni MOM
     * 
     * @param string $contextType Tipo contesto
     * @param string $contextId ID contesto
     * @param string $azione Azione richiesta: 'read', 'write', 'delete', 'export'
     * @return bool True se permesso, false altrimenti
     */
    private static function assertMomAccess(string $contextType, string $contextId, string $azione): bool
    {
        global $database;

        // Admin ha sempre accesso
        if (isAdmin()) {
            return true;
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return false;
        }

        // Permessi base
        $permessiMap = [
            'read' => 'view_mom',
            'write' => 'edit_mom',
            'delete' => 'delete_mom',
            'export' => 'export_mom'
        ];

        $permessoRichiesto = $permessiMap[$azione] ?? 'view_mom';
        if (!userHasPermission($permessoRichiesto)) {
            return false;
        }

        // Controlli contesto-specifici
        // Tipi di contesto validi: commessa, generale, commerciale (legacy), mom
        $tipiConsentiti = ['commessa', 'generale', 'commerciale', 'mom'];
        if (!empty($contextType) && !in_array($contextType, $tipiConsentiti, true)) {
            return false;
        }

        return true;
    }

    /**
     * Codici generali che condividono lo stesso progressivo
     */
    private static $codiciGenerali = ['GAR', 'AMM', 'OFF', 'ACQ', 'HRR', 'SQQ', 'GCO', 'CON'];

    /**
     * Genera progressivo calcolando MAX dalla tabella mom (come ProtocolloEmailService)
     * 
     * @param string $codice Codice protocollo (commessa o generale)
     * @param int $anno Anno corrente (4 cifre)
     * @return int Nuovo progressivo
     */
    private static function generaProgressivo(string $codice, int $anno): int
    {
        global $database;

        if (empty($codice)) {
            return 1;
        }

        $codice = strtoupper(trim($codice));

        try {
            // Per codici generali: progressivo condiviso tra tutti i codici generali
            if (in_array($codice, self::$codiciGenerali, true)) {
                $placeholders = implode(',', array_fill(0, count(self::$codiciGenerali), '?'));
                $sql = "SELECT MAX(progressivo) AS maxProg 
                        FROM mom 
                        WHERE codice_protocollo IN ({$placeholders})
                        AND anno = ?";
                $params = array_merge(self::$codiciGenerali, [$anno]);
            } else {
                // Per commesse specifiche: progressivo separato per commessa
                $sql = "SELECT MAX(progressivo) AS maxProg 
                        FROM mom 
                        WHERE codice_protocollo = ?
                        AND anno = ?";
                $params = [$codice, $anno];
            }

            $stmt = $database->connection->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $maxProg = (int) ($row['maxProg'] ?? 0);
            return $maxProg + 1;

        } catch (\Exception $e) {
            error_log("[MomService] Errore generazione progressivo: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Legge il prossimo progressivo senza incrementarlo (per preview)
     * 
     * @param string $codice Codice protocollo
     * @param int $anno Anno corrente (4 cifre)
     * @return int Prossimo progressivo
     */
    private static function getNextProgressivo(string $codice, int $anno): int
    {
        global $database;

        if (empty($codice)) {
            return 1;
        }

        $codice = strtoupper(trim($codice));

        try {
            // Per codici generali: progressivo condiviso tra tutti i codici generali
            if (in_array($codice, self::$codiciGenerali, true)) {
                $placeholders = implode(',', array_fill(0, count(self::$codiciGenerali), '?'));
                $sql = "SELECT MAX(progressivo) AS maxProg 
                        FROM mom 
                        WHERE codice_protocollo IN ({$placeholders})
                        AND anno = ?";
                $params = array_merge(self::$codiciGenerali, [$anno]);
            } else {
                // Per commesse specifiche: progressivo separato per commessa
                $sql = "SELECT MAX(progressivo) AS maxProg 
                        FROM mom 
                        WHERE codice_protocollo = ?
                        AND anno = ?";
                $params = [$codice, $anno];
            }

            $stmt = $database->connection->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $maxProg = (int) ($row['maxProg'] ?? 0);
            return $maxProg + 1;

        } catch (\Exception $e) {
            error_log("[MomService] Errore lettura progressivo: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Ottiene preview del progressivo MOM
     * 
     * @param array $data {codice: string}
     * @return array {success: bool, progressivo: string}
     */
    public static function getPreviewProgressivo(array $data): array
    {
        $codice = isset($data['codice']) ? trim((string) $data['codice']) : '';

        if (empty($codice)) {
            return ['success' => false, 'message' => 'Codice mancante'];
        }

        $codice = strtoupper($codice);
        $anno = (int) date('Y');
        $annoShort = substr((string) $anno, -2);

        try {
            $progressivo = self::getNextProgressivo($codice, $anno);
            $progressivoStr = str_pad($progressivo, 3, '0', STR_PAD_LEFT);

            // Formato: MOM_{codice}_{progressivo}_{anno}
            $progressivoComposto = "MOM_{$codice}_{$progressivoStr}_{$annoShort}";

            return [
                'success' => true,
                'progressivo' => $progressivoComposto,
                'progressivoNum' => $progressivo,
                'codice' => $codice,
                'anno' => $anno
            ];
        } catch (\Exception $e) {
            error_log("[MomService] Errore getPreviewProgressivo: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nella generazione preview: ' . $e->getMessage()];
        }
    }

    /**
     * Carica archivio MOM con filtri
     * 
     * @param array $filtri Filtri: section, stato, dataDa, dataA, testo, contextType, contextId
     * @return array ['success' => bool, 'data' => []]
     */
    public static function getArchivio(array $filtri = []): array
    {
        global $database;

        // DEBUG: log filtri ricevuti
        error_log("[MOM getArchivio] Filtri ricevuti: " . json_encode($filtri));

        // Sanifica filtri
        $section = isset($filtri['filterSection']) ? trim((string) $filtri['filterSection']) : '';
        $tipo = isset($filtri['tipo']) ? trim((string) $filtri['tipo']) : '';
        $stato = isset($filtri['stato']) ? trim((string) $filtri['stato']) : '';
        $dataDa = isset($filtri['dataDa']) ? trim((string) $filtri['dataDa']) : '';
        $dataA = isset($filtri['dataA']) ? trim((string) $filtri['dataA']) : '';
        $testo = isset($filtri['testo']) ? trim((string) $filtri['testo']) : '';
        $contextType = isset($filtri['contextType']) ? trim((string) $filtri['contextType']) : '';
        $contextId = isset($filtri['contextId']) ? trim((string) $filtri['contextId']) : '';

        // Controllo permessi: se contextType/contextId specificati, verifica accesso
        if (!empty($contextType) && !empty($contextId)) {
            if (!self::assertMomAccess($contextType, $contextId, 'read')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }
        } else {
            // Se non specificato, verifica permesso generico
            if (!userHasPermission('view_mom') && !isAdmin()) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }
        }

        // Costruisci query
        $where = [];
        $params = [];

        // Filtro per sezione (obbligatorio se specificato)
        error_log("[MOM getArchivio] Section filtro: '$section'");
        if (!empty($section)) {
            $where[] = "m.section = :section";
            $params[':section'] = $section;
            error_log("[MOM getArchivio] Filtro section applicato: $section");
        }

        // Filtro stato rimosso - tutti i MOM sono sempre in stato 'bozza'

        if (!empty($tipo) && in_array($tipo, ['generale', 'commessa'], true)) {
            $where[] = "m.context_type = :tipo";
            $params[':tipo'] = $tipo;
        }

        if (!empty($dataDa)) {
            $where[] = "m.data_meeting >= :dataDa";
            $params[':dataDa'] = $dataDa;
        }

        if (!empty($dataA)) {
            $where[] = "m.data_meeting <= :dataA";
            $params[':dataA'] = $dataA;
        }

        if (!empty($testo)) {
            $where[] = "(m.titolo LIKE :testo OR m.note LIKE :testo)";
            $params[':testo'] = '%' . $testo . '%';
        }

        if (!empty($contextType)) {
            $where[] = "m.context_type = :contextType";
            $params[':contextType'] = $contextType;
        }

        if (!empty($contextId)) {
            $where[] = "m.context_id = :contextId";
            $params[':contextId'] = $contextId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT m.*, 
                       p.Nominativo as creatore_nome,
                       p2.Nominativo as aggiornatore_nome,
                       CASE 
                           WHEN m.codice_protocollo IS NOT NULL THEN CONCAT('MOM_', m.codice_protocollo, '_', LPAD(m.progressivo, 3, '0'), '_', RIGHT(m.anno, 2))
                           ELSE CONCAT(m.progressivo, '/', m.anno)
                       END as progressivo_completo
                FROM mom m
                LEFT JOIN personale p ON p.user_id = m.created_by
                LEFT JOIN personale p2 ON p2.user_id = m.updated_by
                {$whereClause}
                ORDER BY m.data_meeting DESC, m.progressivo DESC
                LIMIT 500";

        error_log("[MOM getArchivio] WHERE clause: $whereClause | Params: " . json_encode($params));

        try {
            $result = $database->query($sql, $params, __FILE__);
            $momList = [];

            if ($result) {
                foreach ($result as $row) {
                    $momList[] = [
                        'id' => (int) $row['id'],
                        'section' => $row['section'] ?? 'collaborazione',
                        'contextType' => $row['context_type'],
                        'contextId' => $row['context_id'],
                        'titolo' => $row['titolo'],
                        'dataMeeting' => $row['data_meeting'],
                        'oraInizio' => $row['ora_inizio'],
                        'oraFine' => $row['ora_fine'],
                        'luogo' => $row['luogo'],
                        'stato' => $row['stato'],
                        'progressivo' => (int) $row['progressivo'],
                        'anno' => (int) $row['anno'],
                        'codice' => $row['codice_protocollo'] ?? null,
                        'progressivoCompleto' => $row['progressivo_completo'] ?? null,
                        'note' => $row['note'],
                        'createdAt' => $row['created_at'],
                        'updatedAt' => $row['updated_at'],
                        'createdBy' => (int) $row['created_by'],
                        'updatedBy' => !empty($row['updated_by']) ? (int) $row['updated_by'] : null,
                        'creatoreNome' => $row['creatore_nome'],
                        'aggiornatoreNome' => $row['aggiornatore_nome']
                    ];
                }
            }

            return ['success' => true, 'data' => $momList, '_debug' => ['filterSection' => $section, 'whereClause' => $whereClause, 'resultCount' => count($momList)]];
        } catch (\Exception $e) {
            error_log("[MomService] Errore getArchivio: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nel caricamento archivio'];
        }
    }

    /**
     * Carica dettaglio completo MOM
     * 
     * @param int $momId ID MOM
     * @return array ['success' => bool, 'data' => []]
     */
    public static function getDettaglio(int $momId): array
    {
        global $database;

        if ($momId <= 0) {
            return ['success' => false, 'message' => 'ID MOM non valido'];
        }

        try {
            // Carica testata
            $mom = $database->query(
                "SELECT m.*,
                       p.Nominativo as creatore_nome,
                       p2.Nominativo as aggiornatore_nome,
                       CASE
                           WHEN m.codice_protocollo IS NOT NULL THEN CONCAT('MOM_', m.codice_protocollo, '_', LPAD(m.progressivo, 3, '0'), '_', RIGHT(m.anno, 2))
                           ELSE CONCAT(m.progressivo, '/', m.anno)
                       END as progressivo_completo,
                       CASE
                           WHEN m.codice_protocollo IS NOT NULL AND EXISTS (SELECT 1 FROM elenco_commesse WHERE codice = m.codice_protocollo) THEN 'commessa'
                           WHEN m.codice_protocollo IS NOT NULL THEN 'generale'
                           ELSE NULL
                       END as area
                FROM mom m
                LEFT JOIN personale p ON p.user_id = m.created_by
                LEFT JOIN personale p2 ON p2.user_id = m.updated_by
                WHERE m.id = :id",
                [':id' => $momId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$mom) {
                return ['success' => false, 'message' => 'MOM non trovato'];
            }

            if (!$mom) {
                return ['success' => false, 'message' => 'MOM non trovato'];
            }

            // Controllo permessi
            if (!self::assertMomAccess($mom['context_type'], $mom['context_id'], 'read')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }

            // Carica partecipanti (usa nominativo invece di partecipante)
            $partecipanti = $database->query(
                "SELECT id, mom_id, nominativo as partecipante, ruolo as societa, 
                        COALESCE(copia_a, 0) as copia_a, ordinamento
                 FROM mom_partecipanti 
                 WHERE mom_id = :momId 
                 ORDER BY ordinamento ASC",
                [':momId' => $momId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            // ARRICCHIMENTO DATI (Email/Telefono)
            foreach ($partecipanti as &$p) {
                $p['email'] = '';
                $p['telefono'] = '';

                $nominativo = trim($p['partecipante']);
                $societa = trim($p['societa'] ?? '');

                if (empty($nominativo))
                    continue;

                // Logica: se società è Incide -> Personale, altrimenti CRM
                // Check approssimativo su stringa 'Incide' (case insensitive)
                if (stripos($societa, 'Incide Engineering') !== false) {
                    // Cerca in Personale
                    $res = $database->query(
                        "SELECT Email_Aziendale, u.interno, p.Cellulare_Aziendale
                         FROM personale p
                         LEFT JOIN office_positions u ON p.user_id = u.user_id 
                         WHERE p.Nominativo = :nom LIMIT 1",
                        [':nom' => $nominativo],
                        __FILE__
                    )->fetch(\PDO::FETCH_ASSOC);

                    if ($res) {
                        $p['email'] = $res['Email_Aziendale'];

                        $tels = [];
                        if (!empty($res['interno']))
                            $tels[] = $res['interno'];
                        if (!empty($res['Cellulare_Aziendale']))
                            $tels[] = $res['Cellulare_Aziendale'];
                        $p['telefono'] = implode(' / ', $tels);
                    }
                } else {
                    // Cerca in Anagrafiche Contatti
                    // (Opzionale: filtrare per società se possibile, ma mom_partecipanti salva solo nome società stringa)
                    // Cerchiamo match esatto nome contatto
                    $res = $database->query(
                        "SELECT email, telefono, cellulare 
                         FROM anagrafiche_contatti 
                         WHERE cognome_e_nome = :nom LIMIT 1",
                        [':nom' => $nominativo],
                        __FILE__
                    )->fetch(\PDO::FETCH_ASSOC);

                    if ($res) {
                        $p['email'] = $res['email'];
                        // Combina telefono e cellulare se presenti
                        $tels = [];
                        if (!empty($res['telefono']))
                            $tels[] = $res['telefono'];
                        if (!empty($res['cellulare']))
                            $tels[] = $res['cellulare'];
                        $p['telefono'] = implode(' / ', $tels);
                    }
                }
            }
            unset($p); // Break reference

            // Carica items dalla tabella unificata mom_items con stato task collegata
            $itemsRaw = $database->query(
                "SELECT mi.id, mi.mom_id, mi.item_type, mi.titolo, mi.descrizione, mi.responsabile, mi.data_target,
                        mi.stato, mi.task_id, mi.ordinamento, mi.created_at, mi.item_code,
                        LOWER(REPLACE(sts.name, ' ', '_')) as task_status
                 FROM mom_items mi
                 LEFT JOIN sys_tasks t ON mi.task_id = t.id AND mi.task_id IS NOT NULL AND t.context_type = 'mom' AND t.context_id = mi.mom_id
                 LEFT JOIN sys_task_status sts ON t.status_id = sts.id
                 WHERE mi.mom_id = :momId
                 ORDER BY mi.ordinamento ASC, mi.id ASC",
                [':momId' => $momId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            // DEBUG: log numero items trovati
            error_log("[MomService] getDettaglio MOM $momId: items trovati: " . count($itemsRaw));

            // Converti in camelCase per compatibilità frontend
            $items = [];
            foreach ($itemsRaw as $item) {
                // Sincronizza stato con task collegata se presente
                $statoEffettivo = $item['stato']; // Default allo stato dell'item
                if ($item['task_id'] && isset($item['task_status']) && $item['task_status']) {
                    // Gli stati sono gli stessi tra items e task - usa direttamente lo stato della task
                    $statoEffettivo = $item['task_status'];
                }

                $items[] = [
                    'id' => (int) $item['id'],
                    'mom_id' => (int) $item['mom_id'],
                    'item_type' => $item['item_type'],
                    'titolo' => $item['titolo'],
                    'descrizione' => $item['descrizione'],
                    'responsabile' => $item['responsabile'],
                    'data_target' => $item['data_target'],
                    'stato' => $statoEffettivo,
                    'task_id' => $item['task_id'] ? (int) $item['task_id'] : null,
                    'ordinamento' => (int) $item['ordinamento'],
                    'created_at' => $item['created_at'],
                    'item_code' => $item['item_code']
                ];
            }

            // Carica stati disponibili per gli items (da sys_task_status con context_type = 'mom')
            $itemStatuses = $database->query(
                "SELECT id, name, color, position
                 FROM sys_task_status
                 WHERE context_type = 'mom'
                 ORDER BY position ASC",
                [],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            // Carica allegati
            $allegati = $database->query(
                "SELECT a.*, p.Nominativo as creatore_nome
                 FROM mom_allegati a
                 LEFT JOIN personale p ON p.user_id = a.created_by
                 WHERE a.mom_id = :momId
                 ORDER BY a.created_at ASC",
                [':momId' => $momId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'data' => [
                    'id' => (int) $mom['id'],
                    'section' => $mom['section'] ?? 'collaborazione',
                    'contextType' => $mom['context_type'],
                    'contextId' => $mom['context_id'],
                    'titolo' => $mom['titolo'],
                    'dataMeeting' => $mom['data_meeting'],
                    'oraInizio' => $mom['ora_inizio'],
                    'oraFine' => $mom['ora_fine'],
                    'luogo' => $mom['luogo'],
                    'stato' => $mom['stato'],
                    'progressivo' => (int) $mom['progressivo'],
                    'anno' => (int) $mom['anno'],
                    'codice' => $mom['codice_protocollo'] ?? null,
                    'area' => $mom['area'] ?? null,
                    'progressivoCompleto' => $mom['progressivo_completo'] ?? null,
                    'note' => $mom['note'] ?? null,
                    'createdAt' => $mom['created_at'],
                    'updatedAt' => $mom['updated_at'],
                    'createdBy' => (int) $mom['created_by'],
                    'updatedBy' => !empty($mom['updated_by']) ? (int) $mom['updated_by'] : null,
                    'creatoreNome' => $mom['creatore_nome'],
                    'aggiornatoreNome' => $mom['aggiornatore_nome'],
                    'partecipanti' => $partecipanti,
                    'items' => $items,
                    'itemStatuses' => $itemStatuses,
                    'allegati' => $allegati,
                    'revisione' => (int) ($mom['revisione'] ?? 1)
                ]
            ];
        } catch (\Exception $e) {
            error_log("[MomService] Errore getDettaglio: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nel caricamento dettaglio'];
        }
    }

    /**
     * Aggiorna stato item basato su stato task
     */
    public static function updateItemStatusFromTask(int $taskId): array
    {
        global $database;

        try {
            // Trova l'item collegato alla task (con filtro contesto per sicurezza)
            $item = $database->query(
                "SELECT mi.id, mi.mom_id, mi.stato, sts.name as task_status_name
                 FROM mom_items mi
                 INNER JOIN sys_tasks t ON mi.task_id = t.id AND t.context_type = 'mom'
                 LEFT JOIN sys_task_status sts ON t.status_id = sts.id
                 WHERE mi.task_id = ?",
                [$taskId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$item) {
                // error_log("[MomService] updateItemStatusFromTask: Item non trovato per taskId $taskId");
                return ['success' => false, 'message' => 'Item collegato alla task non trovato'];
            }

            // Converti stato task in stato item
            $taskStatusMap = [
                'Aperta' => 'aperta',
                'In corso' => 'in_corso',
                'In attesa' => 'in_attesa',
                'Completata' => 'completata',
                'Chiusa' => 'chiusa'
            ];

            $newItemStatus = $taskStatusMap[$item['task_status_name']] ?? $item['stato'];

            // Aggiorna stato item se diverso
            if ($newItemStatus !== $item['stato']) {
                $database->query(
                    "UPDATE mom_items SET stato = ? WHERE id = ?",
                    [$newItemStatus, $item['id']],
                    __FILE__
                );
                error_log("[MomService] updateItemStatusFromTask: Aggiornato stato item {$item['id']} a $newItemStatus (taskId $taskId)");
            }

            return ['success' => true, 'message' => 'Stato item aggiornato'];

        } catch (\Exception $e) {
            error_log("[MomService] Errore updateItemStatusFromTask: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nell\'aggiornamento dello stato dell\'item'];
        }
    }

    /**
     * Ottiene i dettagli base di un singolo item
     */
    public static function getItemDetails(int $itemId): array
    {
        global $database;
        try {
            $item = $database->query(
                "SELECT mi.*, m.context_type, m.context_id,
                 CASE 
                     WHEN m.codice_protocollo IS NOT NULL THEN CONCAT('MOM_', m.codice_protocollo, '_', LPAD(m.progressivo, 3, '0'), '_', RIGHT(m.anno, 2))
                     ELSE CONCAT(m.progressivo, '/', m.anno)
                 END as mom_protocollo
                 FROM mom_items mi
                 JOIN mom m ON mi.mom_id = m.id
                 WHERE mi.id = ?",
                [$itemId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$item)
                return ['success' => false, 'message' => 'Item non trovato'];

            if (!self::assertMomAccess($item['context_type'], $item['context_id'], 'read')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }

            return ['success' => true, 'data' => $item];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Ottiene la checklist di un item (usa tabella globale sys_checklists)
     */
    public static function getItemChecklist(int $itemId): array
    {
        return \Services\ChecklistService::listItems('mom_item', $itemId);
    }

    /**
     * Aggiunge un elemento alla checklist (tabella globale)
     */
    public static function addChecklistItem(array $data): array
    {
        global $database;
        $itemId = (int) ($data['itemId'] ?? 0);
        $label = trim($data['label'] ?? '');

        // Usa ChecklistService
        $res = \Services\ChecklistService::add('mom_item', $itemId, $label);

        if ($res['success'] && !empty($res['data']['id'])) {
            // Log Audit
            try {
                $momId = $database->query("SELECT mom_id FROM mom_items WHERE id = ?", [$itemId])->fetchColumn();
                self::logItemAudit($momId, 'CHECKLIST_ADD', $itemId, ['label' => $label, 'check_id' => $res['data']['id']]);
            } catch (\Exception $e) {
                // Ignore log error
            }
        }

        return $res;
    }

    /**
     * Toggle stato di un elemento checklist
     */
    public static function toggleChecklistItem(int $checkId): array
    {
        global $database;

        // Recupera item prima del toggle per log
        $item = $database->query("SELECT * FROM sys_checklists WHERE id = ?", [$checkId])->fetch(\PDO::FETCH_ASSOC);

        $res = \Services\ChecklistService::toggle($checkId);

        if ($res['success'] && $item) {
            try {
                $momId = $database->query("SELECT mom_id FROM mom_items WHERE id = ?", [$item['entity_id']])->fetchColumn();
                $newStatus = !$item['is_done']; // Inverted because we just toggled it
                $action = $newStatus ? 'CHECKLIST_DONE' : 'CHECKLIST_UNDONE';
                self::logItemAudit($momId, $action, (int) $item['entity_id'], ['label' => $item['label'], 'check_id' => $checkId]);
            } catch (\Exception $e) {
            }
        }

        return $res;
    }

    /**
     * Elimina un elemento dalla checklist
     */
    public static function deleteChecklistItem(int $checkId): array
    {
        global $database;

        // Recupera item prima di eliminare per log
        $item = $database->query("SELECT * FROM sys_checklists WHERE id = ?", [$checkId])->fetch(\PDO::FETCH_ASSOC);

        $res = \Services\ChecklistService::delete($checkId);

        if ($res['success'] && $item) {
            try {
                $momId = $database->query("SELECT mom_id FROM mom_items WHERE id = ?", [$item['entity_id']])->fetchColumn();
                self::logItemAudit($momId, 'CHECKLIST_DELETE', (int) $item['entity_id'], ['label' => $item['label'], 'check_id' => $checkId]);
            } catch (\Exception $e) {
            }
        }

        return $res;
    }

    /**
     * Ottiene il log attività di un item (da mom_audit_log centralizzato)
     */
    public static function getItemActivity(int $itemId): array
    {
        global $database;
        try {
            // Recupero mom_id dell'item
            $momId = $database->query("SELECT mom_id FROM mom_items WHERE id = ?", [$itemId])->fetchColumn();

            // Query su mom_audit_log filtrando per mom_id e metadata->item_id
            $activity = $database->query(
                "SELECT a.azione as action, a.metadata as meta_json, a.created_at, p.Nominativo as user_name
                 FROM mom_audit_log a
                 LEFT JOIN personale p ON a.user_id = p.user_id
                 WHERE a.mom_id = ? AND JSON_EXTRACT(a.metadata, '$.item_id') = ?
                 ORDER BY a.created_at DESC",
                [$momId, $itemId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            return ['success' => true, 'data' => $activity];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Registra un'attività sull'item nel log audit centralizzato
     */
    private static function logItemAudit(int $momId, string $action, int $itemId, array $meta = []): void
    {
        global $database;
        $userId = $_SESSION['user_id'] ?? 0;
        try {
            $fullMeta = array_merge(['entity' => 'mom_item', 'item_id' => $itemId], $meta);

            $database->query(
                "INSERT INTO mom_audit_log (mom_id, azione, metadata, user_id) 
                 VALUES (?, ?, ?, ?)",
                [$momId, $action, json_encode($fullMeta), $userId],
                __FILE__
            );
        } catch (\Exception $e) {
            error_log("[MomService] Errore log audit: " . $e->getMessage());
        }
    }

    /**
     * Salva MOM (crea o aggiorna) con transazione e upsert smart
     *
     * @param array $data Dati MOM
     * @return array ['success' => bool, 'message' => string, 'momId' => int]
     */
    public static function saveMom(array $data): array
    {
        global $database;

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return ['success' => false, 'message' => 'Utente non autenticato'];
        }

        // Sanifica input
        $originalMomId = isset($data['id']) ? (int) $data['id'] : 0;
        $momId = $originalMomId;

        $section = isset($data['intranetSection']) ? trim((string) $data['intranetSection']) : 'collaborazione';
        $contextType = isset($data['contextType']) ? trim((string) $data['contextType']) : '';
        $contextId = isset($data['contextId']) ? trim((string) $data['contextId']) : '';
        $codiceProtocollo = isset($data['codice']) ? strtoupper(trim((string) $data['codice'])) : null;
        $titolo = isset($data['titolo']) ? trim((string) $data['titolo']) : '';
        $dataMeeting = isset($data['dataMeeting']) ? trim((string) $data['dataMeeting']) : '';
        $oraInizio = isset($data['oraInizio']) ? trim((string) $data['oraInizio']) : null;
        $oraFine = isset($data['oraFine']) ? trim((string) $data['oraFine']) : null;
        $luogo = isset($data['luogo']) ? trim((string) $data['luogo']) : null;
        $stato = isset($data['stato']) ? trim((string) $data['stato']) : 'bozza';

        $note = isset($data['note']) ? trim((string) $data['note']) : null;
        $revisione = isset($data['revisione']) ? (int) $data['revisione'] : 1;

        // DEBUG: log tentativo salvataggio
        error_log("[MomService] saveMom called with momId=$momId, codice=$codiceProtocollo, dataMeeting=$dataMeeting");

        // Validazione
        if (empty($contextType) || empty($contextId)) {
            return ['success' => false, 'message' => 'Contesto mancante'];
        }

        if (empty($titolo)) {
            return ['success' => false, 'message' => 'Titolo obbligatorio'];
        }

        if (empty($dataMeeting)) {
            return ['success' => false, 'message' => 'Data riunione obbligatoria'];
        }

        // Validazione stato: deve essere uno dei valori consentiti
        if (!in_array($stato, ['bozza', 'in_revisione', 'chiuso'], true)) {
            $stato = 'bozza'; // Fallback a bozza se valore non valido
        }


        // Controllo permessi
        if ($momId > 0) {
            // Update: verifica esistenza e permessi
            $existing = $database->query(
                "SELECT context_type, context_id, stato, progressivo, anno, codice_protocollo FROM mom WHERE id = :id",
                [':id' => $momId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$existing) {
                error_log("[MomService] Tentativo UPDATE MOM id=$momId ma record non trovato. Payload: " . json_encode($data));
                return ['success' => false, 'message' => 'MOM non trovato'];
            }

            if (!self::assertMomAccess($existing['context_type'], $existing['context_id'], 'write')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }

            // Controllo stato rimosso - tutti i MOM sono sempre in stato 'bozza'
        } else {
            // Create: verifica permessi
            if (!self::assertMomAccess($contextType, $contextId, 'write')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }
        }

        try {
            $database->connection->beginTransaction();

            $anno = (int) date('Y', strtotime($dataMeeting));

            if ($momId > 0) {
                // UPDATE: verifica se il codice_protocollo sta cambiando
                $currentRecord = $database->query(
                    "SELECT progressivo, anno, codice_protocollo FROM mom WHERE id = :id",
                    [':id' => $momId],
                    __FILE__
                )->fetch(\PDO::FETCH_ASSOC);

                $codiceStaCambiando = $currentRecord && $currentRecord['codice_protocollo'] !== $codiceProtocollo;

                if ($codiceStaCambiando) {
                    // Verifica se la nuova combinazione (progressivo, anno, nuovo_codice) già esiste
                    $existingConflict = $database->query(
                        "SELECT id FROM mom WHERE progressivo = :progressivo AND anno = :anno AND codice_protocollo = :codice AND id != :currentId",
                        [
                            ':progressivo' => $currentRecord['progressivo'],
                            ':anno' => $currentRecord['anno'],
                            ':codice' => $codiceProtocollo,
                            ':currentId' => $momId
                        ],
                        __FILE__
                    )->fetch(\PDO::FETCH_ASSOC);

                    if ($existingConflict) {
                        // Conflitto: genera nuovo progressivo per il nuovo codice
                        $nuovoProgressivo = self::generaProgressivo($codiceProtocollo, $currentRecord['anno']);

                        // Aggiorna anche il progressivo nel record corrente
                        $database->query(
                            "UPDATE mom SET progressivo = :progressivo WHERE id = :id",
                            [':progressivo' => $nuovoProgressivo, ':id' => $momId],
                            __FILE__
                        );
                    }
                }

                $sql = "UPDATE mom SET
                        titolo = :titolo,
                        data_meeting = :dataMeeting,
                        ora_inizio = :oraInizio,
                        ora_fine = :oraFine,
                        luogo = :luogo,
                        stato = :stato,
                        note = :note,
                        codice_protocollo = :codiceProtocollo,
                        updated_by = :updatedBy,
                        updated_at = NOW()
                        WHERE id = :momId";

                $params = [
                    ':momId' => $momId,
                    ':titolo' => $titolo,
                    ':dataMeeting' => $dataMeeting,
                    ':oraInizio' => $oraInizio,
                    ':oraFine' => $oraFine,
                    ':luogo' => $luogo,
                    ':stato' => $stato,
                    ':note' => $note,
                    ':codiceProtocollo' => $codiceProtocollo,
                    ':updatedBy' => $userId
                ];

                $database->query($sql, $params, __FILE__);
            } else {
                // INSERT: genera progressivo
                if (empty($codiceProtocollo)) {
                    $database->connection->rollBack();
                    return ['success' => false, 'message' => 'Codice protocollo obbligatorio per nuovi MOM'];
                }

                $progressivo = self::generaProgressivo($codiceProtocollo, $anno);

                $sql = "INSERT INTO mom (
                        section, context_type, context_id, titolo, data_meeting,
                        ora_inizio, ora_fine, luogo, stato,
                        progressivo, anno, codice_protocollo, note, revisione, created_by, created_at
                        ) VALUES (
                        :section, :contextType, :contextId, :titolo, :dataMeeting,
                        :oraInizio, :oraFine, :luogo, :stato,
                        :progressivo, :anno, :codiceProtocollo, :note, :revisione, :createdBy, NOW()
                        )";

                $params = [
                    ':section' => $section,
                    ':contextType' => $contextType,
                    ':contextId' => $contextId,
                    ':titolo' => $titolo,
                    ':dataMeeting' => $dataMeeting,
                    ':oraInizio' => $oraInizio,
                    ':oraFine' => $oraFine,
                    ':luogo' => $luogo,
                    ':stato' => $stato,
                    ':progressivo' => $progressivo,
                    ':anno' => $anno,
                    ':codiceProtocollo' => $codiceProtocollo,
                    ':note' => $note,
                    ':revisione' => $revisione,
                    ':createdBy' => $userId
                ];

                $database->query($sql, $params, __FILE__);
                $momId = (int) $database->lastInsertId();
            }

            // Salva blocchi figli (upsert smart)
            self::saveChildBlock($database, 'mom_partecipanti', $momId, $data['partecipanti'] ?? [], $userId);

            // Salva items nella tabella unificata mom_items
            self::saveChildBlock($database, 'mom_items', $momId, $data['items'] ?? [], $userId);

            // Rigenera item_code con progressivi per verbale
            self::rigeneraItemCodes($momId);

            // Sposta allegati temporanei (sia per MOM nuovi che esistenti)
            self::moveTemporaryAllegati($momId, $userId);

            // Sincronizza AI items to Tasks (MVP: sync idempotente)
            self::syncItemsToTasks($momId, $userId);

            $database->connection->commit();

            return ['success' => true, 'message' => 'MOM salvato con successo', 'momId' => $momId];
        } catch (\Exception $e) {
            // Rollback solo se la transazione è attiva
            if ($database->connection->inTransaction()) {
                $database->connection->rollBack();
            }
            error_log("[MomService] Errore saveMom: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nel salvataggio: ' . $e->getMessage()];
        }
    }

    /**
     * Salva blocco figlio con upsert smart (update se id presente, insert se null)
     * 
     * @param object $database Database connection
     * @param string $tableName Nome tabella
     * @param int $momId ID MOM
     * @param array $rows Array righe con {id, fields, ordinamento}
     * @param int $userId ID utente
     */
    private static function saveChildBlock($database, string $tableName, int $momId, array $rows, int $userId): void
    {
        // Estrai deleteIds dal payload (se presente)
        $deleteIds = [];
        $validRows = [];

        foreach ($rows as $row) {
            if (isset($row['_delete']) && $row['_delete'] === true && !empty($row['id'])) {
                $deleteIds[] = (int) $row['id'];
            } else {
                $validRows[] = $row;
            }
        }

        // Delete mirate (solo se appartengono al mom)
        if (!empty($deleteIds)) {
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $sql = "DELETE FROM `{$tableName}` WHERE id IN ({$placeholders}) AND mom_id = ?";
            $params = array_merge($deleteIds, [$momId]);
            $database->query($sql, $params, __FILE__);
        }

        // Upsert: update se id presente, insert se id null
        foreach ($validRows as $row) {
            $id = isset($row['id']) && !empty($row['id']) ? (int) $row['id'] : null;
            $ordinamento = isset($row['ordinamento']) ? (int) $row['ordinamento'] : 0;

            // Verifica che l'id appartenga al mom (se update)
            if ($id !== null) {
                $check = $database->query(
                    "SELECT id FROM `{$tableName}` WHERE id = ? AND mom_id = ?",
                    [$id, $momId],
                    __FILE__
                )->fetch(\PDO::FETCH_ASSOC);

                if (!$check) {
                    // ID non appartiene al mom, ignora
                    continue;
                }
            }

            // Costruisci query upsert in base alla tabella
            if ($tableName === 'mom_partecipanti') {
                // Mappa: partecipante → nominativo, societa → ruolo, presente → copia_a
                $nominativo = isset($row['partecipante']) ? trim((string) $row['partecipante']) : '';
                $ruolo = isset($row['societa']) ? trim((string) $row['societa']) : null;
                $copiaA = isset($row['presente']) ? ((int) $row['presente'] === 1 ? 1 : 0) : 0;

                if (empty($nominativo)) {
                    continue; // Salta righe vuote
                }

                if ($id !== null) {
                    $sql = "UPDATE `{$tableName}` SET 
                            nominativo = ?, ruolo = ?, copia_a = ?, ordinamento = ?
                            WHERE id = ? AND mom_id = ?";
                    $params = [$nominativo, $ruolo, $copiaA, $ordinamento, $id, $momId];
                } else {
                    $sql = "INSERT INTO `{$tableName}` (mom_id, nominativo, ruolo, copia_a, ordinamento) 
                            VALUES (?, ?, ?, ?, ?)";
                    $params = [$momId, $nominativo, $ruolo, $copiaA, $ordinamento];
                }
            } elseif ($tableName === 'mom_items') {
                // Tabella unificata mom_items
                $itemType = isset($row['itemType']) ? trim((string) $row['itemType']) : 'AI';
                $titolo = isset($row['titolo']) ? trim((string) $row['titolo']) : null;
                $descrizione = isset($row['descrizione']) ? trim((string) $row['descrizione']) : null;
                $responsabile = isset($row['responsabile']) ? trim((string) $row['responsabile']) : null;
                $dataTarget = isset($row['dataTarget']) ? trim((string) $row['dataTarget']) : null;
                $stato = isset($row['stato']) ? trim((string) $row['stato']) : null;
                $taskId = isset($row['taskId']) ? (int) $row['taskId'] : null;
                $itemCode = isset($row['itemCode']) ? trim((string) $row['itemCode']) : null;

                // Validazione: item_type obbligatorio
                if (empty($itemType) || !in_array($itemType, ['AI', 'OBS', 'EVE'], true)) {
                    $itemType = 'AI'; // Default
                }

                if (empty($descrizione)) {
                    continue; // Descrizione obbligatoria
                }

                if ($id !== null) {
                    $sql = "UPDATE `{$tableName}` SET
                            item_type = ?, titolo = ?, descrizione = ?, responsabile = ?, data_target = ?,
                            stato = ?, task_id = ?, ordinamento = ?, item_code = ?
                            WHERE id = ? AND mom_id = ?";
                    $params = [$itemType, $titolo, $descrizione, $responsabile, $dataTarget, $stato, $taskId, $ordinamento, $itemCode, $id, $momId];
                } else {
                    $sql = "INSERT INTO `{$tableName}` (mom_id, item_type, titolo, descrizione, responsabile, data_target, stato, task_id, ordinamento, item_code, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    $params = [$momId, $itemType, $titolo, $descrizione, $responsabile, $dataTarget, $stato, $taskId, $ordinamento, $itemCode];
                }
            } else {
                continue; // Tabella non supportata
            }

            $database->query($sql, $params, __FILE__);

            // Se è un item con task collegata, aggiorna anche lo stato della task
            if ($tableName === 'mom_items' && $taskId && $stato) {
                // Converti data-status in ID della tabella sys_task_status
                $dataStatusToId = [
                    'aperta' => 17,
                    'in_corso' => 18,
                    'in_attesa' => 3,
                    'completata' => 4,
                    'chiusa' => 21
                ];

                $newStatusId = $dataStatusToId[$stato] ?? 17;

                // Aggiorna lo stato della task usando TaskService
                require_once __DIR__ . '/TaskService.php';
                $updateResult = \Services\TaskService::updateTask([
                    'taskId' => $taskId,
                    'statusId' => $newStatusId
                ]);

                if (!$updateResult['success']) {
                    error_log("[MomService] Errore aggiornamento stato task {$taskId}: " . ($updateResult['message'] ?? 'Unknown error'));
                    // Non blocco il salvataggio per errori nell'aggiornamento task
                }
            }
        }
    }

    /**
     * Aggiorna solo lo stato di un MOM
     *
     * @param array $data ['momId' => int, 'stato' => string]
     * @return array ['success' => bool, 'message' => string]
     */
    public static function updateMomStatus(array $data): array
    {
        global $database;

        $momId = isset($data['momId']) ? (int) $data['momId'] : (isset($data['mom_id']) ? (int) $data['mom_id'] : 0);
        $nuovoStato = isset($data['stato']) ? trim((string) $data['stato']) : '';

        if ($momId <= 0) {
            return ['success' => false, 'message' => 'ID MOM non valido'];
        }

        // Validazione stato
        $statiConsentiti = ['bozza', 'in_revisione', 'chiuso'];
        if (!in_array($nuovoStato, $statiConsentiti, true)) {
            return ['success' => false, 'message' => 'Stato non valido'];
        }

        try {
            // Recupera MOM per verificare permessi
            $mom = $database->query(
                "SELECT context_type, context_id, stato, created_by FROM mom WHERE id = :id",
                [':id' => $momId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$mom) {
                return ['success' => false, 'message' => 'MOM non trovato'];
            }

            // Controllo permessi: admin, utente con edit_mom, o creatore
            $userId = $_SESSION['user_id'] ?? null;
            $isAdmin = isAdmin();
            $hasEditPerm = userHasPermission('edit_mom');
            $isCreatore = ($mom['created_by'] && (string) $mom['created_by'] === (string) $userId);

            if (!$isAdmin && !$hasEditPerm && !$isCreatore) {
                return ['success' => false, 'message' => 'Permesso negato'];
            }

            // Aggiorna stato
            $database->query(
                "UPDATE mom SET stato = :stato WHERE id = :id",
                [':stato' => $nuovoStato, ':id' => $momId],
                __FILE__
            );

            return ['success' => true, 'message' => 'Stato aggiornato', 'stato' => $nuovoStato];

        } catch (\Exception $e) {
            error_log("[MomService] Errore updateMomStatus: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore durante l\'aggiornamento'];
        }
    }

    /**
     * Elimina MOM (solo se bozza)
     * 
     * @param int $momId ID MOM
     * @return array ['success' => bool, 'message' => string]
     */
    public static function deleteMom(int $momId): array
    {
        global $database;

        if ($momId <= 0) {
            return ['success' => false, 'message' => 'ID MOM non valido'];
        }

        try {
            $mom = $database->query(
                "SELECT context_type, context_id, stato FROM mom WHERE id = :id",
                [':id' => $momId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$mom) {
                return ['success' => false, 'message' => 'MOM non trovato'];
            }

            // Eliminazione sempre consentita per utenti autorizzati

            // Controllo permessi
            if (!self::assertMomAccess($mom['context_type'], $mom['context_id'], 'delete')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }

            // Delete (CASCADE elimina anche i figli)
            $database->query(
                "DELETE FROM mom WHERE id = :id",
                [':id' => $momId],
                __FILE__
            );

            return ['success' => true, 'message' => 'MOM eliminato con successo'];
        } catch (\Exception $e) {
            error_log("[MomService] Errore deleteMom: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nell\'eliminazione'];
        }
    }

    /**
     * Clona (duplica) un MOM esistente con tutti i dati correlati
     *
     * Crea una copia completa del verbale con:
     * - Nuovo progressivo generato automaticamente
     * - Titolo univoco con suffisso "_copia" (gestendo collisioni)
     * - Stato reset a "bozza"
     * - Timestamp e utente creazione aggiornati
     * - Duplicazione di partecipanti e items (senza task_id)
     * - Allegati: solo riferimenti DB (non copia file fisici)
     *
     * @param int $momId ID del MOM da clonare
     * @return array ['success' => bool, 'data' => ['newMomId' => int, 'newTitle' => string, 'newProgressivoCompleto' => string], 'message' => string]
     */
    public static function cloneMom(int $momId): array
    {
        global $database;

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return ['success' => false, 'message' => 'Utente non autenticato'];
        }

        if ($momId <= 0) {
            return ['success' => false, 'message' => 'ID MOM non valido'];
        }

        try {
            // Carica MOM originale
            $original = $database->query(
                "SELECT * FROM mom WHERE id = :id",
                [':id' => $momId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$original) {
                return ['success' => false, 'message' => 'MOM non trovato'];
            }

            // Verifica permessi (richiede permesso write/edit per creare copia)
            if (!self::assertMomAccess($original['context_type'], $original['context_id'], 'write')) {
                return ['success' => false, 'message' => 'Permesso negato per la duplicazione'];
            }

            // Inizia transazione
            $database->connection->beginTransaction();

            // Genera titolo univoco con suffisso "_copia"
            $newTitle = self::buildCloneTitle(
                $original['titolo'],
                $original['context_type'],
                $original['context_id'],
                $original['codice_protocollo']
            );

            // Genera nuovo progressivo
            $anno = (int) date('Y');
            $newProgressivo = self::generaProgressivo($original['codice_protocollo'], $anno);

            // Inserisci nuovo MOM
            $sql = "INSERT INTO mom (
                section, context_type, context_id, titolo, data_meeting,
                ora_inizio, ora_fine, luogo, stato,
                progressivo, anno, codice_protocollo, note, revisione,
                created_by, created_at
            ) VALUES (
                :section, :contextType, :contextId, :titolo, :dataMeeting,
                :oraInizio, :oraFine, :luogo, :stato,
                :progressivo, :anno, :codiceProtocollo, :note, :revisione,
                :createdBy, NOW()
            )";

            $params = [
                ':section' => $original['section'],
                ':contextType' => $original['context_type'],
                ':contextId' => $original['context_id'],
                ':titolo' => $newTitle,
                ':dataMeeting' => $original['data_meeting'],
                ':oraInizio' => $original['ora_inizio'],
                ':oraFine' => $original['ora_fine'],
                ':luogo' => $original['luogo'],
                ':stato' => 'bozza', // Reset a bozza
                ':progressivo' => $newProgressivo,
                ':anno' => $anno,
                ':codiceProtocollo' => $original['codice_protocollo'],
                ':note' => $original['note'],
                ':revisione' => 0, // Reset revisione
                ':createdBy' => $userId
            ];

            $database->query($sql, $params, __FILE__);
            $newMomId = (int) $database->lastInsertId();

            if (!$newMomId) {
                throw new \Exception('Errore nella creazione del nuovo MOM');
            }

            // Duplica partecipanti
            self::cloneChildTable($database, 'mom_partecipanti', $momId, $newMomId, [
                'nominativo',
                'ruolo',
                'copia_a',
                'ordinamento'
            ]);

            // Duplica items (senza task_id - verranno create nuove task se necessario)
            self::cloneChildTable($database, 'mom_items', $momId, $newMomId, [
                'item_type',
                'descrizione',
                'responsabile',
                'data_target',
                'stato',
                'ordinamento',
                'item_code'
            ], ['task_id']); // Escludi task_id

            // Rigenera item_code per il nuovo MOM
            self::rigeneraItemCodes($newMomId);

            // Commit transazione
            $database->connection->commit();

            // Costruisci progressivo completo per la risposta
            $codice = $original['codice_protocollo'];
            $annoShort = substr((string) $anno, -2);
            $progressivoStr = str_pad($newProgressivo, 3, '0', STR_PAD_LEFT);
            $progressivoCompleto = $codice ? "MOM_{$codice}_{$progressivoStr}_{$annoShort}" : "{$newProgressivo}/{$anno}";

            return [
                'success' => true,
                'message' => 'MOM duplicato con successo',
                'data' => [
                    'newMomId' => $newMomId,
                    'newTitle' => $newTitle,
                    'newProgressivoCompleto' => $progressivoCompleto
                ]
            ];

        } catch (\Exception $e) {
            // Rollback se transazione attiva
            if ($database->connection->inTransaction()) {
                $database->connection->rollBack();
            }
            error_log("[MomService] Errore cloneMom: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nella duplicazione: ' . $e->getMessage()];
        }
    }

    /**
     * Genera un titolo univoco per la copia del MOM
     *
     * Logica naming:
     * - Base: "<titolo_originale>_copia"
     * - Se esiste già: "<titolo_originale>_copia_02", "_copia_03", ecc.
     *
     * @param string|null $originalTitle Titolo originale
     * @param string|null $contextType Tipo contesto
     * @param string|null $contextId ID contesto
     * @param string|null $codice Codice protocollo
     * @return string Titolo univoco
     */
    private static function buildCloneTitle(?string $originalTitle, ?string $contextType, ?string $contextId, ?string $codice): string
    {
        global $database;

        // Fallback se titolo vuoto
        if (empty($originalTitle) || trim($originalTitle) === '') {
            $originalTitle = $codice ? "MOM_{$codice}" : 'MOM';
        }

        // Normalizza: trim e collassa spazi multipli
        $originalTitle = trim(preg_replace('/\s+/', ' ', $originalTitle));

        // Rimuovi eventuali suffissi _copia esistenti per ottenere il titolo base
        $baseTitle = preg_replace('/_copia(_\d{2})?$/', '', $originalTitle);

        // Cerca titoli esistenti che matchano il pattern
        $pattern = $baseTitle . '_copia%';

        $sql = "SELECT titolo FROM mom WHERE titolo LIKE :pattern";
        $params = [':pattern' => $pattern];

        // Aggiungi filtro contesto se specificato
        if (!empty($contextType) && !empty($contextId)) {
            $sql .= " AND context_type = :contextType AND context_id = :contextId";
            $params[':contextType'] = $contextType;
            $params[':contextId'] = $contextId;
        }

        // Cerca anche il titolo base esatto con _copia
        $exactPattern = $baseTitle . '_copia';
        $sql2 = "SELECT COUNT(*) as cnt FROM mom WHERE titolo = :exact";
        $params2 = [':exact' => $exactPattern];
        if (!empty($contextType) && !empty($contextId)) {
            $sql2 .= " AND context_type = :contextType AND context_id = :contextId";
            $params2[':contextType'] = $contextType;
            $params2[':contextId'] = $contextId;
        }

        try {
            // Conta quanti titoli _copia già esistono
            $existingTitles = $database->query($sql, $params, __FILE__)->fetchAll(\PDO::FETCH_COLUMN);
            $exactExists = (int) $database->query($sql2, $params2, __FILE__)->fetchColumn();

            if (empty($existingTitles) && $exactExists === 0) {
                // Nessuna copia esiste: usa "_copia"
                return $baseTitle . '_copia';
            }

            // Trova il numero massimo esistente
            $maxNum = 0;
            foreach ($existingTitles as $title) {
                if ($title === $baseTitle . '_copia') {
                    $maxNum = max($maxNum, 1);
                } elseif (preg_match('/_copia_(\d{2})$/', $title, $matches)) {
                    $num = (int) $matches[1];
                    $maxNum = max($maxNum, $num);
                }
            }

            if ($exactExists > 0) {
                $maxNum = max($maxNum, 1);
            }

            // Incrementa e formatta con 2 cifre
            $newNum = $maxNum + 1;
            if ($newNum === 1) {
                return $baseTitle . '_copia';
            }
            return $baseTitle . '_copia_' . str_pad($newNum, 2, '0', STR_PAD_LEFT);

        } catch (\Exception $e) {
            error_log("[MomService] Errore buildCloneTitle: " . $e->getMessage());
            // Fallback: usa timestamp per unicità
            return $baseTitle . '_copia_' . date('His');
        }
    }

    /**
     * Duplica righe da una tabella figlia per un nuovo MOM
     *
     * @param object $database Database connection
     * @param string $tableName Nome tabella
     * @param int $oldMomId ID MOM originale
     * @param int $newMomId ID nuovo MOM
     * @param array $columns Colonne da copiare
     * @param array $excludeColumns Colonne da escludere (impostare a NULL)
     */
    private static function cloneChildTable($database, string $tableName, int $oldMomId, int $newMomId, array $columns, array $excludeColumns = []): void
    {
        try {
            // Leggi righe originali
            $rows = $database->query(
                "SELECT * FROM `{$tableName}` WHERE mom_id = :momId ORDER BY ordinamento ASC, id ASC",
                [':momId' => $oldMomId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return;
            }

            // Prepara insert
            $insertColumns = array_merge(['mom_id'], $columns);
            $placeholders = array_map(fn($c) => ':' . $c, $insertColumns);

            // Aggiungi created_at se la tabella lo supporta
            $hasCreatedAt = isset($rows[0]['created_at']);
            if ($hasCreatedAt) {
                $insertColumns[] = 'created_at';
                $placeholders[] = 'NOW()';
            }

            $columnsList = implode(', ', $insertColumns);
            $placeholdersList = implode(', ', $placeholders);

            // Rimuovi NOW() dai placeholder per il bind
            $placeholdersList = str_replace(':created_at', 'NOW()', $placeholdersList);
            $insertColumns = array_filter($insertColumns, fn($c) => $c !== 'created_at');

            $sql = "INSERT INTO `{$tableName}` ({$columnsList}) VALUES ({$placeholdersList})";

            foreach ($rows as $row) {
                $params = [':mom_id' => $newMomId];

                foreach ($columns as $col) {
                    if (in_array($col, $excludeColumns)) {
                        $params[':' . $col] = null;
                    } else {
                        $params[':' . $col] = $row[$col] ?? null;
                    }
                }

                $database->query($sql, $params, __FILE__);
            }

        } catch (\Exception $e) {
            error_log("[MomService] Errore cloneChildTable {$tableName}: " . $e->getMessage());
            throw $e; // Propaga per rollback transazione
        }
    }

    /**
     * Ottiene allegati temporanei dell'utente corrente
     *
     * @return array ['success' => bool, 'data' => array, 'message' => string]
     */
    public static function getAllegatiTemporanei(): array
    {
        global $database;

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return ['success' => false, 'message' => 'Utente non autenticato'];
        }

        try {
            $allegati = $database->query(
                "SELECT id, nome_file, path_file, dimensione, mime_type, created_at
                 FROM mom_allegati
                 WHERE mom_id IS NULL AND created_by = :userId
                 ORDER BY created_at DESC",
                [':userId' => $userId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'data' => $allegati ?: [],
                'message' => 'Allegati temporanei caricati'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Errore nel caricamento allegati temporanei: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Pulisce allegati temporanei dell'utente corrente
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function pulisciAllegatiTemporanei(): array
    {
        global $database;

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return ['success' => false, 'message' => 'Utente non autenticato'];
        }

        try {
            // Trova tutti gli allegati temporanei dell'utente
            $tempAllegati = $database->query(
                "SELECT id, path_file FROM mom_allegati
                 WHERE mom_id = 0 AND created_by = :userId",
                [':userId' => $userId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            // Elimina i file fisici
            foreach ($tempAllegati as $allegato) {
                $filePath = dirname(__DIR__) . '/' . $allegato['path_file'];
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            // Elimina i record dal database
            $database->query(
                "DELETE FROM mom_allegati WHERE mom_id = 0 AND created_by = :userId",
                [':userId' => $userId],
                __FILE__
            );

            // Rimuovi directory temporanea se vuota
            $tempDir = dirname(__DIR__) . "/uploads/temp/mom/" . session_id();
            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*');
                if (empty($files)) {
                    @rmdir($tempDir);
                }
            }

            return [
                'success' => true,
                'message' => 'Allegati temporanei puliti'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Errore nella pulizia allegati temporanei: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Sposta allegati temporanei per un nuovo MOM
     *
     * @param int $newMomId ID del MOM appena creato
     * @param int $userId ID dell'utente
     */
    private static function moveTemporaryAllegati(int $newMomId, int $userId): void
    {
        global $database;

        try {
            // Trova tutti gli allegati temporanei dell'utente
            $tempAllegati = $database->query(
                "SELECT id, path_file, nome_file FROM mom_allegati
                 WHERE mom_id IS NULL AND created_by = :userId",
                [':userId' => $userId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($tempAllegati)) {
                return; // Nessun allegato temporaneo
            }

            $baseDir = dirname(__DIR__);
            $sessionId = session_id();

            foreach ($tempAllegati as $allegato) {
                $oldPath = $baseDir . '/' . $allegato['path_file'];
                $newRelDir = "uploads/mom/{$newMomId}";
                $newAbsDir = $baseDir . '/' . $newRelDir;

                // Crea directory di destinazione se non esiste
                if (!is_dir($newAbsDir)) {
                    mkdir($newAbsDir, 0755, true);
                }

                // Nuovo path del file
                $filename = basename($allegato['path_file']);
                $newPath = $newAbsDir . '/' . $filename;

                // Sposta il file
                if (file_exists($oldPath) && rename($oldPath, $newPath)) {
                    // Aggiorna il database
                    $database->query(
                        "UPDATE mom_allegati SET
                            mom_id = :momId,
                            path_file = :newPath
                         WHERE id = :id",
                        [
                            ':momId' => $newMomId,
                            ':newPath' => $newRelDir . '/' . $filename,
                            ':id' => $allegato['id']
                        ],
                        __FILE__
                    );
                }
            }

            // Rimuovi directory temporanea se vuota
            $tempDir = $baseDir . "/uploads/temp/mom/{$sessionId}";
            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*');
                if (empty($files)) {
                    rmdir($tempDir);
                }
            }

        } catch (\Exception $e) {
            // Log dell'errore ma non fallire il salvataggio del MOM
            error_log("Errore spostamento allegati temporanei: " . $e->getMessage());
        }
    }

    /**
     * Upload allegato
     * 
     * @param int $momId ID MOM
     * @param array $file File da $_FILES
     * @return array ['success' => bool, 'message' => string, 'allegato' => []]
     */
    public static function uploadAllegato(int $momId, array $file): array
    {
        global $database;

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return ['success' => false, 'message' => 'Utente non autenticato'];
        }

        $isTemporary = false;
        $tempSessionId = null;

        if ($momId <= 0) {
            // Per MOM nuovi, usa directory temporanea basata sulla sessione
            $isTemporary = true;
            $tempSessionId = session_id();
            $momId = null; // Usa NULL per identificare come temporaneo
        } else {
            // Verifica esistenza MOM e permessi per MOM esistenti
            $mom = $database->query(
                "SELECT context_type, context_id, stato FROM mom WHERE id = :id",
                [':id' => $momId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$mom) {
                return ['success' => false, 'message' => 'MOM non trovato'];
            }

            // Tutti i MOM sono sempre in stato 'bozza', controllo permessi
            $canModifyAllegati = (userHasPermission('edit_mom') || isAdmin());
            if (!$canModifyAllegati) {
                return ['success' => false, 'message' => 'Allegati modificabili solo con permesso edit_mom'];
            }

            if (!self::assertMomAccess($mom['context_type'], $mom['context_id'], 'write')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }
        }

        // Validazione file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Errore upload (code: ' . $file['error'] . ')'];
        }

        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'message' => 'File troppo pesante (max 10MB)'];
        }

        // Crea directory uploads/mom/{momId}/ o temp/{sessionId}/ per MOM nuovi
        $baseDir = dirname(__DIR__);
        if ($isTemporary) {
            $relDir = "uploads/temp/mom/{$tempSessionId}";
        } else {
            $relDir = "uploads/mom/{$momId}";
        }
        $absDir = $baseDir . '/' . $relDir;

        if (!is_dir($absDir)) {
            if (!@mkdir($absDir, 0775, true)) {
                return ['success' => false, 'message' => 'Impossibile creare directory upload'];
            }
            @chmod($absDir, 0775);
        }

        // Nome file univoco
        $nomeFile = basename($file['name']);
        $ext = pathinfo($nomeFile, PATHINFO_EXTENSION);
        $nomeUnico = uniqid('mom_', true) . '.' . $ext;
        $destPath = $absDir . '/' . $nomeUnico;

        // Sposta file
        if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['success' => false, 'message' => 'Errore nel salvataggio file'];
        }

        // Salva in DB
        $mimeType = $file['type'] ?? mime_content_type($destPath);

        $sql = "INSERT INTO mom_allegati (mom_id, nome_file, path_file, dimensione, mime_type, created_by)
                VALUES (?, ?, ?, ?, ?, ?)";
        $params = [
            $momId, // Può essere NULL per allegati temporanei
            $nomeFile,
            $relDir . '/' . $nomeUnico,
            (int) $file['size'],
            $mimeType,
            $userId
        ];

        $database->query($sql, $params, __FILE__);
        $allegatoId = (int) $database->lastInsertId();

        return [
            'success' => true,
            'message' => 'Allegato caricato con successo',
            'allegato' => [
                'id' => $allegatoId,
                'nomeFile' => $nomeFile,
                'pathFile' => $relDir . '/' . $nomeUnico,
                'dimensione' => (int) $file['size'],
                'mimeType' => $mimeType
            ]
        ];
    }

    /**
     * Elimina allegato
     * 
     * @param int $allegatoId ID allegato
     * @return array ['success' => bool, 'message' => string]
     */
    public static function deleteAllegato(int $allegatoId): array
    {
        global $database;

        if ($allegatoId <= 0) {
            return ['success' => false, 'message' => 'ID allegato non valido'];
        }

        try {
            // Carica allegato con info MOM
            $allegato = $database->query(
                "SELECT a.*, m.context_type, m.context_id, m.stato 
                 FROM mom_allegati a
                 INNER JOIN mom m ON m.id = a.mom_id
                 WHERE a.id = :id",
                [':id' => $allegatoId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$allegato) {
                return ['success' => false, 'message' => 'Allegato non trovato'];
            }

            // Controllo permessi per eliminazione allegati (tutti MOM sono sempre in bozza)
            $canModifyAllegati = (userHasPermission('edit_mom') || isAdmin());
            if (!$canModifyAllegati) {
                return ['success' => false, 'message' => 'Allegati eliminabili solo con permesso edit_mom'];
            }

            // Controllo permessi
            if (!self::assertMomAccess($allegato['context_type'], $allegato['context_id'], 'write')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }

            // Elimina file fisico
            $baseDir = dirname(__DIR__);
            $absPath = $baseDir . '/' . $allegato['path_file'];
            if (file_exists($absPath)) {
                @unlink($absPath);
            }

            // Elimina record DB
            $database->query(
                "DELETE FROM mom_allegati WHERE id = :id",
                [':id' => $allegatoId],
                __FILE__
            );

            return ['success' => true, 'message' => 'Allegato eliminato con successo'];
        } catch (\Exception $e) {
            error_log("[MomService] Errore deleteAllegato: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nell\'eliminazione'];
        }
    }


    /**
     * REMOVED: Crea task da Action Item MOM
     * Task buttons removed from UI - this method is no longer used
     * 
     * @param int $itemId ID item MOM (deve essere tipo AI)
     * @return array ['success' => bool, 'taskId' => int|null, 'message' => string]
     */
    /*
    public static function createTaskFromItem(int $itemId): array
    {
        global $database;

        if ($itemId <= 0) {
            return ['success' => false, 'message' => 'ID item non valido'];
        }

        // Carica item da mom_items (solo AI possono creare task)
        $item = $database->query(
            "SELECT mi.*, m.context_type, m.context_id, m.titolo as mom_titolo, m.progressivo, m.anno
             FROM mom_items mi
             INNER JOIN mom m ON m.id = mi.mom_id
             WHERE mi.id = ? AND mi.item_type = 'AI' AND (mi.task_id IS NULL OR mi.task_id = 0)
             LIMIT 1",
            [$itemId],
            __FILE__
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$item) {
            return ['success' => false, 'message' => 'Item non trovato o già collegato a task'];
        }

        // Verifica permessi
        if (!self::assertMomAccess($item['context_type'], $item['context_id'], 'write')) {
            return ['success' => false, 'message' => 'Accesso negato'];
        }

        // Crea task usando TaskService
        require_once __DIR__ . '/TaskService.php';

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return ['success' => false, 'message' => 'Utente non autenticato'];
        }

        // Determina lo statusId per la task basandosi sullo stato dell'item
        $statusId = null;
        if (!empty($item['stato'])) {
            // Converti data-status in ID della tabella sys_task_status
            $dataStatusToId = [
                'aperta' => 17,     // id dalla tabella sys_task_status
                'in_corso' => 18,   // id dalla tabella sys_task_status
                'in_attesa' => 3,   // id dalla tabella sys_task_status (ipotetico)
                'completata' => 4,  // id dalla tabella sys_task_status (ipotetico)
                'chiusa' => 21      // id dalla tabella sys_task_status
            ];
            $statusId = $dataStatusToId[$item['stato']] ?? 17; // Default ad "aperta"
        }

        // Prepara dati task
        $taskData = [
            'contextType' => 'mom',
            'contextId' => (string) $item['mom_id'],
            'title' => ($item['item_code'] ?? 'AI-' . $item['id']) . ': ' . substr($item['descrizione'], 0, 100),
            'description' => $item['descrizione'],
            'dueDate' => $item['data_target'] ?? null,
            'priority' => 'Media',
            'statusId' => $statusId,
            'momItemId' => $itemId
        ];

        // Cerca responsabile per user_id (se presente)
        if (!empty($item['responsabile'])) {
            $personale = $database->query(
                "SELECT user_id FROM personale WHERE Nominativo = ? LIMIT 1",
                [trim($item['responsabile'])],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if ($personale) {
                $taskData['assigneeUserId'] = (int) $personale['user_id'];
            }
        }

        // Crea task
        $result = \Services\TaskService::createTask($taskData);

        if (!$result['success']) {
            return $result;
        }

        $taskId = $result['task_id'];

        // Aggiorna mom_items con task_id
        $database->query(
            "UPDATE mom_items SET task_id = ? WHERE id = ?",
            [$taskId, $itemId],
            __FILE__
        );

        return [
            'success' => true,
            'taskId' => $taskId,
            'message' => 'Task creata con successo'
        ];
    }
    */

    /**
     * Export PDF MOM
     * 
     * @param int $momId ID MOM
     * @return array ['success' => bool, 'message' => string, 'pdfPath' => string]
     */
    public static function exportPdf(int $momId): array
    {
        global $database;

        if ($momId <= 0) {
            return ['success' => false, 'message' => 'ID MOM non valido'];
        }

        try {
            // Carica dettaglio completo
            $dettaglio = self::getDettaglio($momId);
            if (!$dettaglio['success']) {
                return $dettaglio;
            }

            $mom = $dettaglio['data'];

            // Controllo permessi export
            if (!self::assertMomAccess($mom['contextType'], $mom['contextId'], 'export')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }

            // Verifica se phpWord è disponibile
            if (!defined('AccessoFileInterni')) {
                define('AccessoFileInterni', true);
            }

            $basePath = dirname(__DIR__);
            $phpWordInit = $basePath . '/IntLibs/phpWord/phpword_init.php';
            if (!file_exists($phpWordInit)) {
                return ['success' => false, 'message' => 'Libreria PDF non disponibile'];
            }

            include_once $phpWordInit;

            // Media e Style non sono inclusi in phpword_init.php, quindi li includiamo manualmente
            $mediaFile = $basePath . '/IntLibs/phpWord/Media.php';
            if (file_exists($mediaFile) && !class_exists('PhpOffice\PhpWord\Media', false)) {
                require_once $mediaFile;
            }

            $styleFile = $basePath . '/IntLibs/phpWord/Style.php';
            if (file_exists($styleFile) && !class_exists('PhpOffice\PhpWord\Style', false)) {
                require_once $styleFile;
            }

            // Includi classi Collection necessarie (non incluse in phpword_init.php)
            $collectionFiles = [
                'Collection/AbstractCollection.php',
                'Collection/Bookmarks.php',
                'Collection/Titles.php',
                'Collection/Footnotes.php',
                'Collection/Endnotes.php',
                'Collection/Charts.php',
                'Collection/Comments.php'
            ];

            foreach ($collectionFiles as $file) {
                $fullPath = $basePath . '/IntLibs/phpWord/' . $file;
                if (file_exists($fullPath)) {
                    $className = 'PhpOffice\\PhpWord\\' . str_replace(['/', '.php'], ['\\', ''], $file);
                    if (!class_exists($className, false)) {
                        require_once $fullPath;
                    }
                }
            }

            // Includi anche Metadata necessarie
            $metadataFiles = [
                'Metadata/DocInfo.php',
                'Metadata/Settings.php',
                'Metadata/Compatibility.php'
            ];

            foreach ($metadataFiles as $file) {
                $fullPath = $basePath . '/IntLibs/phpWord/' . $file;
                if (file_exists($fullPath)) {
                    $className = 'PhpOffice\\PhpWord\\' . str_replace(['/', '.php'], ['\\', ''], $file);
                    if (!class_exists($className, false)) {
                        require_once $fullPath;
                    }
                }
            }

            // Includi classi Shared necessarie (prima di tutto)
            $sharedFiles = [
                'Shared/AbstractEnum.php',
                'Shared/Converter.php',
                'Shared/XMLWriter.php',
                'Shared/ZipArchive.php',
                'Shared/Text.php'
            ];

            foreach ($sharedFiles as $file) {
                $fullPath = $basePath . '/IntLibs/phpWord/' . $file;
                if (file_exists($fullPath)) {
                    $className = 'PhpOffice\\PhpWord\\' . str_replace(['/', '.php'], ['\\', ''], $file);
                    if (!class_exists($className, false)) {
                        require_once $fullPath;
                    }
                }
            }

            // Includi classi SimpleType necessarie (prima di Style)
            $simpleTypeFiles = [
                'SimpleType/VerticalJc.php',
                'SimpleType/Jc.php',
                'SimpleType/JcTable.php'
            ];

            foreach ($simpleTypeFiles as $file) {
                $fullPath = $basePath . '/IntLibs/phpWord/' . $file;
                if (file_exists($fullPath)) {
                    $className = 'PhpOffice\\PhpWord\\' . str_replace(['/', '.php'], ['\\', ''], $file);
                    if (!class_exists($className, false)) {
                        require_once $fullPath;
                    }
                }
            }

            // Includi TUTTE le classi Style necessarie (in ordine di dipendenze)
            // Prima le classi base, poi quelle che le usano
            $styleFiles = [
                'Style/AbstractStyle.php',
                'Style/Border.php',
                'Style/Shading.php',
                'Style/Indentation.php',
                'Style/Tab.php',
                'Style/Alignment.php',
                'Style/LineHeight.php',
                'Style/Spacing.php',
                'Style/Paper.php',
                'Style/LineNumbering.php',
                'Style/Font.php',
                'Style/Paragraph.php',
                'Style/Section.php',
                'Style/Table.php',
                'Style/Row.php',
                'Style/Cell.php'
            ];

            foreach ($styleFiles as $file) {
                $fullPath = $basePath . '/IntLibs/phpWord/' . $file;
                if (file_exists($fullPath)) {
                    $className = 'PhpOffice\\PhpWord\\' . str_replace(['/', '.php'], ['\\', ''], $file);
                    if (!class_exists($className, false)) {
                        require_once $fullPath;
                    }
                }
            }

            // Includi classi ComplexType necessarie
            $complexTypeFiles = [
                'ComplexType/FootnoteProperties.php'
            ];

            foreach ($complexTypeFiles as $file) {
                $fullPath = $basePath . '/IntLibs/phpWord/' . $file;
                if (file_exists($fullPath)) {
                    $className = 'PhpOffice\\PhpWord\\' . str_replace(['/', '.php'], ['\\', ''], $file);
                    if (!class_exists($className, false)) {
                        require_once $fullPath;
                    }
                }
            }

            // Includi classi Element necessarie (in ordine di dipendenze)
            $elementFiles = [
                'Element/AbstractElement.php',
                'Element/AbstractContainer.php',
                'Element/Section.php',
                'Element/Text.php',
                'Element/TextBreak.php',
                'Element/Table.php',
                'Element/Row.php',
                'Element/Cell.php'
            ];

            foreach ($elementFiles as $file) {
                $fullPath = $basePath . '/IntLibs/phpWord/' . $file;
                if (file_exists($fullPath)) {
                    $className = 'PhpOffice\\PhpWord\\' . str_replace(['/', '.php'], ['\\', ''], $file);
                    if (!class_exists($className, false)) {
                        require_once $fullPath;
                    }
                }
            }

            // Includi IOFactory e Writer necessari (prima le interfacce, poi le classi)
            $ioFiles = [
                'IOFactory.php',
                'Writer/WriterInterface.php',
                'Writer/WriterPartInterface.php',
                'Writer/AbstractWriter.php',
                'Writer/Word2007.php',
                'Writer/HTML.php',
                'Writer/PDF.php',
                'Writer/PDF/AbstractRenderer.php',
                'Writer/PDF/TCPDF.php'
            ];

            foreach ($ioFiles as $file) {
                $fullPath = $basePath . '/IntLibs/phpWord/' . $file;
                if (file_exists($fullPath)) {
                    $className = 'PhpOffice\\PhpWord\\' . str_replace(['/', '.php'], ['\\', ''], $file);
                    if (!class_exists($className, false)) {
                        require_once $fullPath;
                    }
                }
            }

            // Verifica che le classi necessarie siano caricate
            if (!class_exists('PhpOffice\PhpWord\Media', false)) {
                return ['success' => false, 'message' => 'Classe Media non trovata. File: ' . $mediaFile];
            }

            if (!class_exists('PhpOffice\PhpWord\Style', false)) {
                return ['success' => false, 'message' => 'Classe Style non trovata. File: ' . $styleFile];
            }

            if (!class_exists('PhpOffice\PhpWord\Collection\Bookmarks', false)) {
                return ['success' => false, 'message' => 'Classe Bookmarks non trovata'];
            }

            if (!class_exists('PhpOffice\PhpWord\Element\Section', false)) {
                return ['success' => false, 'message' => 'Classe Section non trovata'];
            }

            // Crea documento Word
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();

            // Titolo
            $section->addText('VERBALE RIUNIONE', ['bold' => true, 'size' => 16]);
            $section->addTextBreak(1);

            // Testata
            $section->addText('Titolo: ' . htmlspecialchars($mom['titolo']), ['bold' => true]);
            $section->addText('Data: ' . date('d/m/Y', strtotime($mom['dataMeeting'])));
            if (!empty($mom['oraInizio'])) {
                $section->addText('Ora inizio: ' . $mom['oraInizio']);
            }
            if (!empty($mom['oraFine'])) {
                $section->addText('Ora fine: ' . $mom['oraFine']);
            }
            if (!empty($mom['luogo'])) {
                $section->addText('Luogo: ' . htmlspecialchars($mom['luogo']));
            }
            // Usa progressivoCompleto (camelCase) dal response di getDettaglio, altrimenti fallback
            $protocollo = $mom['progressivoCompleto'] ?? $mom['progressivo_completo'] ?? null;
            if (!$protocollo && isset($mom['codice']) && isset($mom['progressivo']) && isset($mom['anno'])) {
                $progressivoStr = str_pad($mom['progressivo'], 3, '0', STR_PAD_LEFT);
                $annoShort = substr((string) $mom['anno'], -2);
                $protocollo = 'MOM_' . $mom['codice'] . '_' . $progressivoStr . '_' . $annoShort;
            }
            if (!$protocollo) {
                $protocollo = ($mom['progressivo'] ?? 0) . '/' . ($mom['anno'] ?? date('Y'));
            }
            $section->addText('Protocollo: ' . $protocollo);
            $section->addTextBreak(1);

            // Partecipanti
            if (!empty($mom['partecipanti'])) {
                $section->addText('PARTECIPANTI', ['bold' => true]);
                foreach ($mom['partecipanti'] as $part) {
                    $line = htmlspecialchars($part['partecipante'] ?? '');
                    if (!empty($part['societa'])) {
                        $line .= ' - ' . htmlspecialchars($part['societa']);
                    }
                    if (!empty($part['copia_a']) && (int) $part['copia_a'] === 1) {
                        $line .= ' (Copia a)';
                    }
                    $section->addText($line);
                }
                $section->addTextBreak(1);
            }

            // Items (AI, OBS, EVE)
            if (!empty($mom['items'])) {
                // Raggruppa per tipo
                $itemsByType = ['AI' => [], 'OBS' => [], 'EVE' => []];
                foreach ($mom['items'] as $item) {
                    $type = $item['item_type'] ?? '';
                    if (isset($itemsByType[$type])) {
                        $itemsByType[$type][] = $item;
                    }
                }

                // Action Items (AI)
                if (!empty($itemsByType['AI'])) {
                    $section->addText('ACTION ITEMS (AI)', ['bold' => true]);
                    foreach ($itemsByType['AI'] as $ai) {
                        $line = '';
                        if (!empty($ai['item_code'])) {
                            $line .= $ai['item_code'] . ' - ';
                        }
                        $line .= htmlspecialchars($ai['descrizione'] ?? '');
                        $section->addText($line, ['bold' => true]);
                        if (!empty($ai['responsabile'])) {
                            $section->addText('Responsabile: ' . htmlspecialchars($ai['responsabile']));
                        }
                        if (!empty($ai['data_target'])) {
                            $section->addText('Data target: ' . date('d/m/Y', strtotime($ai['data_target'])));
                        }
                        if (!empty($ai['stato'])) {
                            $section->addText('Stato: ' . htmlspecialchars($ai['stato']));
                        }
                    }
                    $section->addTextBreak(1);
                }

                // Osservazioni (OBS)
                if (!empty($itemsByType['OBS'])) {
                    $section->addText('OSSERVAZIONI (OBS)', ['bold' => true]);
                    foreach ($itemsByType['OBS'] as $obs) {
                        $line = '';
                        if (!empty($obs['item_code'])) {
                            $line .= $obs['item_code'] . ' - ';
                        }
                        $line .= htmlspecialchars($obs['descrizione'] ?? '');
                        $section->addText($line);
                    }
                    $section->addTextBreak(1);
                }

                // Eventi (EVE)
                if (!empty($itemsByType['EVE'])) {
                    $section->addText('EVENTI (EVE)', ['bold' => true]);
                    foreach ($itemsByType['EVE'] as $eve) {
                        $line = '';
                        if (!empty($eve['item_code'])) {
                            $line .= $eve['item_code'] . ' - ';
                        }
                        $line .= htmlspecialchars($eve['descrizione'] ?? '');
                        $section->addText($line);
                    }
                    $section->addTextBreak(1);
                }
            }

            // Note
            if (!empty($mom['note'])) {
                $section->addText('NOTE', ['bold' => true]);
                $section->addText(htmlspecialchars($mom['note']));
                $section->addTextBreak(1);
            }

            // Allegati (solo nomi)
            if (!empty($mom['allegati'])) {
                $section->addText('ALLEGATI', ['bold' => true]);
                foreach ($mom['allegati'] as $allegato) {
                    $section->addText(htmlspecialchars($allegato['nome_file']));
                }
            }

            // Salva come HTML semplice (più compatibile)
            // L'utente può poi convertirlo in PDF dal browser o da Word
            $baseDir = dirname(__DIR__);
            $outputDir = $baseDir . '/uploads/mom/pdf';
            if (!is_dir($outputDir)) {
                @mkdir($outputDir, 0775, true);
            }

            $annoShort = substr((string) ($mom['anno'] ?? date('Y')), -2);
            $progressivoStr = str_pad($mom['progressivo'] ?? 0, 3, '0', STR_PAD_LEFT);
            $fileName = 'MOM_' . ($mom['codice'] ?? 'XXX') . '_' . $progressivoStr . '_' . $annoShort . '_' . date('Ymd_His') . '.html';
            $outputPath = $outputDir . '/' . $fileName;

            // Genera HTML con intestazione professionale
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Verbale Riunione</title>';
            $html .= '<style>
                @page { margin: 15mm 15mm 20mm 15mm; }
                body { font-family: "Segoe UI", Arial, sans-serif; margin: 0; padding: 20px; color: #333; font-size: 11pt; line-height: 1.4; }

                /* Header intestazione - Colori Incide */
                .mom-header { background: linear-gradient(135deg, #CD211D 0%, #a51b18 100%); color: #fff; padding: 20px 25px; margin: -20px -20px 20px -20px; border-radius: 0 0 8px 8px; }
                .mom-header-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
                .mom-header h1 { margin: 0; font-size: 22pt; font-weight: 600; letter-spacing: 0.5px; }
                .mom-protocollo { background: rgba(255,255,255,0.2); padding: 6px 14px; border-radius: 4px; font-size: 10pt; font-weight: 500; }
                .mom-titolo { font-size: 14pt; margin-top: 8px; opacity: 0.95; font-weight: 400; border-top: 1px solid rgba(255,255,255,0.25); padding-top: 12px; }

                /* Box metadati */
                .mom-meta { display: flex; flex-wrap: wrap; gap: 0; margin-bottom: 20px; border: 1px solid #ccc; border-radius: 6px; overflow: hidden; }
                .mom-meta-item { flex: 1 1 50%; min-width: 200px; padding: 12px 16px; background: #f9f9f9; border-bottom: 1px solid #ccc; box-sizing: border-box; }
                .mom-meta-item:nth-child(odd) { border-right: 1px solid #ccc; }
                .mom-meta-item:nth-last-child(-n+2) { border-bottom: none; }
                .mom-meta-label { font-size: 9pt; text-transform: uppercase; color: #8F8E8C; letter-spacing: 0.5px; margin-bottom: 3px; }
                .mom-meta-value { font-size: 11pt; color: #333; font-weight: 500; }

                /* Sezioni */
                h2 { color: #CD211D; font-size: 13pt; margin: 25px 0 12px 0; padding-bottom: 6px; border-bottom: 2px solid #eee; }

                /* Tabelle */
                table { border-collapse: collapse; width: 100%; margin: 10px 0 20px 0; font-size: 10pt; }
                th { background: #666; color: #fff; font-weight: 600; text-transform: uppercase; font-size: 9pt; letter-spacing: 0.3px; }
                th, td { border: 1px solid #ccc; padding: 10px 12px; text-align: left; }
                tr:nth-child(even) { background: #f9f9f9; }

                /* Note e allegati */
                .note-box { background: #fef3f2; border-left: 4px solid #CD211D; padding: 12px 16px; margin: 10px 0; }
                ul { margin: 10px 0; padding-left: 20px; }
                li { margin: 5px 0; }

                /* Footer */
                .mom-footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #ccc; font-size: 9pt; color: #8F8E8C; text-align: center; }
            </style>';
            $html .= '</head><body>';

            // Costruisci protocollo
            $protocolloHtml = $mom['progressivoCompleto'] ?? $mom['progressivo_completo'] ?? null;
            if (!$protocolloHtml && isset($mom['codice']) && isset($mom['progressivo']) && isset($mom['anno'])) {
                $progStr = str_pad($mom['progressivo'], 3, '0', STR_PAD_LEFT);
                $annShort = substr((string) $mom['anno'], -2);
                $protocolloHtml = 'MOM_' . $mom['codice'] . '_' . $progStr . '_' . $annShort;
            }
            if (!$protocolloHtml) {
                $protocolloHtml = ($mom['progressivo'] ?? 0) . '/' . ($mom['anno'] ?? date('Y'));
            }

            // Header
            $html .= '<div class="mom-header">';
            $html .= '<div class="mom-header-top">';
            $html .= '<h1>VERBALE RIUNIONE</h1>';
            $html .= '<div class="mom-protocollo">' . htmlspecialchars($protocolloHtml) . '</div>';
            $html .= '</div>';
            $html .= '<div class="mom-titolo">' . htmlspecialchars($mom['titolo'] ?? 'Senza titolo') . '</div>';
            $html .= '</div>';

            // Box metadati
            $html .= '<div class="mom-meta">';
            $html .= '<div class="mom-meta-item"><div class="mom-meta-label">Data</div><div class="mom-meta-value">' . date('d/m/Y', strtotime($mom['dataMeeting'])) . '</div></div>';

            // Orario (combina inizio e fine se presenti)
            $orario = '';
            if (!empty($mom['oraInizio'])) {
                $orario = substr($mom['oraInizio'], 0, 5);
                if (!empty($mom['oraFine'])) {
                    $orario .= ' - ' . substr($mom['oraFine'], 0, 5);
                }
            }
            $html .= '<div class="mom-meta-item"><div class="mom-meta-label">Orario</div><div class="mom-meta-value">' . ($orario ?: '-') . '</div></div>';
            $html .= '<div class="mom-meta-item"><div class="mom-meta-label">Luogo</div><div class="mom-meta-value">' . htmlspecialchars($mom['luogo'] ?? '-') . '</div></div>';
            $html .= '<div class="mom-meta-item"><div class="mom-meta-label">Stato</div><div class="mom-meta-value">' . htmlspecialchars(ucfirst($mom['stato'] ?? 'bozza')) . '</div></div>';
            $html .= '</div>';

            // Partecipanti
            if (!empty($mom['partecipanti'])) {
                $html .= '<h2>PARTECIPANTI</h2><table><tr><th>Nome</th><th>Ruolo/Società</th><th style="width:80px;text-align:center;">Presenza</th></tr>';
                foreach ($mom['partecipanti'] as $part) {
                    $copiaA = (int) ($part['copia_a'] ?? 0);
                    // copia_a = 1 (checkbox spuntato) → Presente, copia_a = 0 → CC
                    $presenzaLabel = $copiaA === 1 ? '✓ Presente' : 'CC';
                    $presenzaStyle = $copiaA === 1 ? 'color:#16a34a;font-weight:500;' : 'color:#8F8E8C;';
                    $html .= '<tr>';
                    $html .= '<td>' . htmlspecialchars($part['partecipante'] ?? '') . '</td>';
                    $html .= '<td>' . htmlspecialchars($part['societa'] ?? '') . '</td>';
                    $html .= '<td style="text-align:center;' . $presenzaStyle . '">' . $presenzaLabel . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</table>';
            }

            // Items
            if (!empty($mom['items'])) {
                $itemsByType = ['AI' => [], 'OBS' => [], 'EVE' => []];
                foreach ($mom['items'] as $item) {
                    $type = $item['item_type'] ?? '';
                    if (isset($itemsByType[$type])) {
                        $itemsByType[$type][] = $item;
                    }
                }

                if (!empty($itemsByType['AI'])) {
                    $html .= '<h2>ACTION ITEMS (AI)</h2><table><tr><th>Codice</th><th>Descrizione</th><th>Responsabile</th><th>Data Target</th></tr>';
                    foreach ($itemsByType['AI'] as $ai) {
                        $html .= '<tr>';
                        $html .= '<td>' . htmlspecialchars($ai['item_code'] ?? '') . '</td>';
                        $html .= '<td>' . htmlspecialchars($ai['descrizione'] ?? '') . '</td>';
                        $html .= '<td>' . htmlspecialchars($ai['responsabile'] ?? '') . '</td>';
                        $html .= '<td>' . (!empty($ai['data_target']) ? date('d/m/Y', strtotime($ai['data_target'])) : '') . '</td>';
                        $html .= '</tr>';
                    }
                    $html .= '</table>';
                }

                if (!empty($itemsByType['OBS'])) {
                    $html .= '<h2>OSSERVAZIONI (OBS)</h2><table><tr><th>Codice</th><th>Descrizione</th></tr>';
                    foreach ($itemsByType['OBS'] as $obs) {
                        $html .= '<tr><td>' . htmlspecialchars($obs['item_code'] ?? '') . '</td>';
                        $html .= '<td>' . htmlspecialchars($obs['descrizione'] ?? '') . '</td></tr>';
                    }
                    $html .= '</table>';
                }

                if (!empty($itemsByType['EVE'])) {
                    $html .= '<h2>EVENTI (EVE)</h2><table><tr><th>Codice</th><th>Descrizione</th><th>Data</th></tr>';
                    foreach ($itemsByType['EVE'] as $eve) {
                        $html .= '<tr><td>' . htmlspecialchars($eve['item_code'] ?? '') . '</td>';
                        $html .= '<td>' . htmlspecialchars($eve['descrizione'] ?? '') . '</td>';
                        $html .= '<td>' . (!empty($eve['data_target']) ? date('d/m/Y', strtotime($eve['data_target'])) : '') . '</td></tr>';
                    }
                    $html .= '</table>';
                }
            }

            // Note
            if (!empty($mom['note'])) {
                $html .= '<h2>NOTE</h2><div class="note-box">' . nl2br(htmlspecialchars($mom['note'])) . '</div>';
            }

            // Allegati
            if (!empty($mom['allegati'])) {
                $html .= '<h2>ALLEGATI</h2><ul>';
                foreach ($mom['allegati'] as $allegato) {
                    $html .= '<li>' . htmlspecialchars($allegato['nome_file']) . '</li>';
                }
                $html .= '</ul>';
            }

            // Footer
            $html .= '<div class="mom-footer">';
            $html .= 'Documento generato il ' . date('d/m/Y H:i') . ' — ' . htmlspecialchars($protocolloHtml);
            $html .= '</div>';

            $html .= '</body></html>';

            file_put_contents($outputPath, $html);

            return [
                'success' => true,
                'message' => 'Documento HTML generato con successo (puoi stamparlo come PDF dal browser)',
                'pdfPath' => 'uploads/mom/pdf/' . $fileName,
                'url' => '/uploads/mom/pdf/' . $fileName,
                'format' => 'html'
            ];
        } catch (\Exception $e) {
            error_log("[MomService] Errore exportPdf: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nella generazione PDF: ' . $e->getMessage()];
        }
    }

    /**
     * Download/visualizza allegato
     *
     * @param int $allegatoId ID allegato
     * @return void (invia file direttamente al browser)
     */
    public static function downloadAllegato(int $allegatoId): void
    {
        global $database;

        if ($allegatoId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID allegato non valido']);
            return;
        }

        try {
            // Carica allegato con info MOM
            $allegato = $database->query(
                "SELECT a.*, m.context_type, m.context_id, m.stato
                 FROM mom_allegati a
                 INNER JOIN mom m ON m.id = a.mom_id
                 WHERE a.id = :id",
                [':id' => $allegatoId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$allegato) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Allegato non trovato']);
                return;
            }

            // Controllo permessi
            if (!self::assertMomAccess($allegato['context_type'], $allegato['context_id'], 'read')) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Accesso negato']);
                return;
            }

            // Verifica esistenza file fisico
            $baseDir = dirname(__DIR__);
            $absPath = $baseDir . '/' . $allegato['path_file'];

            if (!file_exists($absPath)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'File non trovato sul server']);
                return;
            }

            // Imposta headers per download/view
            header('Content-Type: ' . ($allegato['mime_type'] ?? 'application/octet-stream'));
            header('Content-Length: ' . filesize($absPath));
            header('Content-Disposition: attachment; filename="' . $allegato['nome_file'] . '"');
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            // Disabilita output buffering
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Invia file
            readfile($absPath);
            exit;

        } catch (\Exception $e) {
            error_log("[MomService] Errore downloadAllegato: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Errore nel download']);
        }
    }

    /**
     * Rigenera item_code per un MOM specifico (progressivi per verbale invece che globali)
     *
     * @param int $momId ID del MOM
     * @return void
     */
    private static function rigeneraItemCodes(int $momId): void
    {
        global $database;

        try {
            // Per ogni tipo di item, rigenera i codici basati sull'ordine
            $itemTypes = ['AI', 'OBS', 'EVE'];

            foreach ($itemTypes as $itemType) {
                // Recupera items di questo tipo per il MOM, ordinati per ordinamento e poi per ID
                $items = $database->query(
                    "SELECT id FROM mom_items
                     WHERE mom_id = :momId AND item_type = :itemType
                     ORDER BY ordinamento ASC, id ASC",
                    [':momId' => $momId, ':itemType' => $itemType],
                    __FILE__
                )->fetchAll(\PDO::FETCH_ASSOC);

                // Assegna numeri progressivi
                $counter = 1;
                foreach ($items as $item) {
                    $itemCode = $itemType . '_' . str_pad($counter, 3, '0', STR_PAD_LEFT);

                    $database->query(
                        "UPDATE mom_items SET item_code = :itemCode WHERE id = :id",
                        [':itemCode' => $itemCode, ':id' => $item['id']],
                        __FILE__
                    );

                    $counter++;
                }
            }
        } catch (\Exception $e) {
            error_log("[MomService] Errore rigeneraItemCodes: " . $e->getMessage());
            // Non lanciare eccezione per non interrompere il salvataggio
        }
    }

    /**
     * Salva ordine items e rigenera item_code
     * 
     * @param array $data {momId: int, items: [{id: int, ordinamento: int}]}
     * @return array ['success' => bool, 'message' => string]
     */
    public static function saveItemsOrder(array $data): array
    {
        global $database;

        $momId = isset($data['momId']) ? (int) $data['momId'] : 0;
        $items = isset($data['items']) ? $data['items'] : [];

        if ($momId <= 0) {
            return ['success' => false, 'message' => 'momId mancante'];
        }

        if (empty($items)) {
            return ['success' => false, 'message' => 'items mancanti'];
        }

        // Controllo permessi
        try {
            $mom = $database->query(
                "SELECT context_type, context_id FROM mom WHERE id = :id",
                [':id' => $momId],
                __FILE__
            )->fetch(\PDO::FETCH_ASSOC);

            if (!$mom) {
                return ['success' => false, 'message' => 'MOM non trovato'];
            }

            if (!self::assertMomAccess($mom['context_type'], $mom['context_id'], 'write')) {
                return ['success' => false, 'message' => 'Accesso negato'];
            }
        } catch (\Exception $e) {
            error_log("[MomService] Errore controllo permessi saveItemsOrder: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nel controllo permessi'];
        }

        try {
            $database->connection->beginTransaction();

            // Aggiorna ordinamento per ogni item
            foreach ($items as $item) {
                $itemId = isset($item['id']) ? (int) $item['id'] : 0;
                $ordinamento = isset($item['ordinamento']) ? (int) $item['ordinamento'] : 0;

                if ($itemId <= 0)
                    continue;

                // Verifica che l'item appartenga al mom
                $check = $database->query(
                    "SELECT id FROM mom_items WHERE id = :id AND mom_id = :momId",
                    [':id' => $itemId, ':momId' => $momId],
                    __FILE__
                )->fetch(\PDO::FETCH_ASSOC);

                if (!$check) {
                    $database->connection->rollBack();
                    return ['success' => false, 'message' => 'Item non appartiene al MOM'];
                }

                // Aggiorna ordinamento
                $database->query(
                    "UPDATE mom_items SET ordinamento = :ordinamento WHERE id = :id",
                    [':ordinamento' => $ordinamento, ':id' => $itemId],
                    __FILE__
                );
            }

            // Rigenera item_code per questo MOM
            self::rigeneraItemCodes($momId);

            $database->connection->commit();

            return ['success' => true, 'message' => 'Ordine salvato con successo'];

        } catch (\Exception $e) {
            if ($database->connection->inTransaction()) {
                $database->connection->rollBack();
            }
            error_log("[MomService] Errore saveItemsOrder: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nel salvataggio ordine'];
        }
    }

    /**
     * Inizializza ordinamento per items esistenti che hanno ordinamento=0
     * Utility per migrare dati legacy
     * 
     * @param int $momId ID del MOM (opzionale, se 0 aggiorna tutti i MOM)
     * @return array ['success' => bool, 'message' => string, 'updated' => int]
     */
    public static function initializeItemsOrdering(int $momId = 0): array
    {
        global $database;

        try {
            $updated = 0;

            // Determina quali MOM processare
            if ($momId > 0) {
                $moms = [$momId];
            } else {
                // Trova tutti i MOM che hanno items con ordinamento=0
                $result = $database->query(
                    "SELECT DISTINCT mom_id FROM mom_items WHERE ordinamento = 0",
                    [],
                    __FILE__
                )->fetchAll(\PDO::FETCH_COLUMN);
                $moms = $result ?: [];
            }

            foreach ($moms as $currentMomId) {
                // Carica items ordinati per ID (ordine di creazione)
                $items = $database->query(
                    "SELECT id FROM mom_items WHERE mom_id = :momId ORDER BY id ASC",
                    [':momId' => $currentMomId],
                    __FILE__
                )->fetchAll(\PDO::FETCH_ASSOC);

                // Aggiorna ordinamento
                foreach ($items as $index => $item) {
                    $database->query(
                        "UPDATE mom_items SET ordinamento = :ordinamento WHERE id = :id",
                        [':ordinamento' => $index + 1, ':id' => $item['id']],
                        __FILE__
                    );
                    $updated++;
                }
            }

            return [
                'success' => true,
                'message' => "Ordinamento inizializzato per $updated items",
                'updated' => $updated
            ];

        } catch (\Exception $e) {
            error_log("[MomService] Errore initializeItemsOrdering: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore nell\'inizializzazione ordinamento'];
        }
    }
    /**
     * Sincronizza items AI verso SysTasks
     * 
     * @param int $momId ID del MOM
     * @param int $userId ID utente che ha effettuato l'operazione
     * @return void
     */
    private static function syncItemsToTasks(int $momId, int $userId): void
    {
        global $database;

        // Verifica se TaskService è caricato
        if (!class_exists('Services\TaskService')) {
            $taskServicePath = substr(__DIR__, 0, strpos(__DIR__, '/services')) . '/services/TaskService.php';
            if (file_exists($taskServicePath)) {
                require_once $taskServicePath;
            } else {
                error_log("[MomService] TaskService non trovato in $taskServicePath");
                return;
            }
        }

        try {
            // Carica Action Items e Eventi del MOM (entrambi diventano task)
            $items = $database->query(
                "SELECT * FROM mom_items WHERE mom_id = :momId AND item_type IN ('AI', 'EVE')",
                [':momId' => $momId],
                __FILE__
            )->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($items)) {
                return;
            }

            // Per ogni AI/EVE item, crea o aggiorna la task corrispondente
            foreach ($items as $item) {
                // Prepara dati task
                $itemType = $item['item_type'] ?? 'AI';
                $taskCode = $item['item_code'] ?? ($itemType . '-' . $item['id']);
                $defaultTitle = $itemType === 'EVE' ? 'Evento' : 'Action Item';
                $taskTitle = "[$taskCode] " . ($item['titolo'] ?? $defaultTitle);
                // Se c'è solo descrizione e non titolo, usa inizio descrizione come titolo (limitato) o fallback
                if (empty($item['titolo']) && !empty($item['descrizione'])) {
                    $taskTitle = "[$taskCode] " . substr(strip_tags($item['descrizione']), 0, 50) . '...';
                }

                $taskId = !empty($item['task_id']) ? (int) $item['task_id'] : null;

                // Mappa assignee (responsabile stringa -> user_id)
                $assigneeUserId = null;
                if (!empty($item['responsabile'])) {
                    $respName = trim($item['responsabile']);
                    // Cerca utente per nominativo
                    $user = $database->query(
                        "SELECT user_id FROM personale WHERE Nominativo = :nom LIMIT 1",
                        [':nom' => $respName],
                        __FILE__
                    )->fetch(\PDO::FETCH_ASSOC);

                    if ($user) {
                        $assigneeUserId = (int) $user['user_id'];
                    }
                }

                $taskData = [
                    'contextType' => 'mom',
                    'contextId' => (string) $momId,
                    'momItemId' => (int) $item['id'],
                    'title' => $taskTitle,
                    'description' => $item['descrizione'] ?? '',
                    'dueDate' => $item['data_target'] ?? null,
                    'assigneeUserId' => $assigneeUserId,
                    // Se lo stato è closed/chiuso, prova a mappare statusId se possibile
                    // Per ora lasciamo gestione stato manuale o default
                ];

                // Se task esiste, aggiorna
                if ($taskId > 0) {
                    // Verifica se task esiste ancora
                    $exists = $database->query(
                        "SELECT id FROM sys_tasks WHERE id = :id",
                        [':id' => $taskId],
                        __FILE__
                    )->fetchColumn();

                    if ($exists) {
                        $taskData['taskId'] = $taskId;
                        \Services\TaskService::updateTask($taskData);
                    } else {
                        // Task eliminata o non trovata, ricrea? O nullifica puntatore?
                        // Per sicurezza ricreiamo
                        $res = \Services\TaskService::createTask($taskData);
                        if ($res['success']) {
                            $newTaskId = $res['task_id'];
                            $database->query(
                                "UPDATE mom_items SET task_id = :tid WHERE id = :id",
                                [':tid' => $newTaskId, ':id' => $item['id']],
                                __FILE__
                            );
                        }
                    }
                } else {
                    // Crea nuova task
                    $res = \Services\TaskService::createTask($taskData);
                    if ($res['success']) {
                        $newTaskId = $res['task_id'];
                        $database->query(
                            "UPDATE mom_items SET task_id = :tid WHERE id = :id",
                            [':tid' => $newTaskId, ':id' => $item['id']],
                            __FILE__
                        );
                    }
                }
            }

        } catch (\Exception $e) {
            error_log("[MomService] Errore syncItemsToTasks: " . $e->getMessage());
        }
    }
    /**
     * Recupera eventi (items EVE) per il calendario
     * 
     * @param array $filters
     * @return array
     */
    public static function getEvents(array $filters = []): array
    {
        global $database;

        // Verifica permessi base
        if (!userHasPermission('view_mom') && !isAdmin()) {
            return ['success' => false, 'message' => 'Accesso negato', 'data' => []];
        }

        $params = [];
        $where = ["i.item_type = 'EVE'"];
        // NOTE: removed m.deleted_at check as column implies it does not exist in this schema version

        // Filtro per data (opzionale)
        if (!empty($filters['start'])) {
            // Assicura formato YYYY-MM-DD
            $start = substr($filters['start'], 0, 10);
            $where[] = "i.data_target >= :start";
            $params[':start'] = $start;
        }
        if (!empty($filters['end'])) {
            $end = substr($filters['end'], 0, 10);
            $where[] = "i.data_target <= :end";
            $params[':end'] = $end;
        }

        // Filtro per section (commentato/disabilitato per debug o non necessario)
        /*
        if (!empty($filters['section'])) {
            $where[] = "m.section = :section";
            $params[':section'] = $filters['section'];
        }
        */

        $whereClause = implode(' AND ', $where);

        // DISTINCT per evitare duplicati
        $sql = "SELECT DISTINCT i.*, m.titolo as mom_titolo, m.section
                FROM mom_items i
                JOIN mom m ON i.mom_id = m.id
                WHERE {$whereClause}
                ORDER BY i.data_target ASC";

        try {
            $result = $database->query($sql, $params, __FILE__);
            $events = [];

            if ($result) {
                foreach ($result as $row) {
                    // ID namespaced
                    $id = 'mom_item:' . $row['id'];

                    // Url al dettaglio MOM
                    $section = $row['section'] ?? 'commerciale';
                    $url = "index.php?section={$section}&page=mom&action=view&id=" . $row['mom_id'];

                    $events[] = [
                        'id' => $id,
                        'title' => ($row['descrizione'] ?? 'Evento') . ' (' . ($row['mom_titolo'] ?? '') . ')',
                        'start' => $row['data_target'],
                        'end' => $row['data_target'], // All-day sync
                        'allDay' => true,
                        'url' => $url,
                        'extendedProps' => [
                            'mom_id' => $row['mom_id'],
                            'item_id' => $row['id'],
                            'item_type' => 'EVE',
                            'original_title' => $row['mom_titolo'],
                            'kind' => 'mom'
                        ],
                        // Meta per CalendarView custom
                        'meta' => array_merge($row, ['kind' => 'mom'])
                    ];
                }
            }

            return ['success' => true, 'data' => $events];

        } catch (\Exception $e) {
            error_log("[MomService::getEvents] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore database: ' . $e->getMessage(), 'data' => []];
        }
    }

    /**
     * Restituisce i dati dell'utente corrente (per frontend)
     * @return array
     */
    public static function getUser(): array
    {
        if (!isset($_SESSION['user'])) {
            return ['success' => false, 'message' => 'Utente non loggato'];
        }

        $user = $_SESSION['user'];
        // Arricchisci con permessi e ruoli calcolati
        $user['role_id'] = $_SESSION['role_id'] ?? ($user['role_id'] ?? null);
        $user['permissions'] = $_SESSION['role_permissions'] ?? [];
        $user['role_ids'] = $_SESSION['role_ids'] ?? [];
        $user['is_admin'] = isAdmin();

        // Rimuovi dati sensibili
        unset($user['password'], $user['auth_token'], $user['temp_key']);

        return ['success' => true, 'data' => $user];
    }

    /**
     * Recupera items globali (Action Items, Events, Observations) da tutti i MOM
     * Filtrabili per sezione e contesto
     */
    public static function getGlobalItems(array $filtri = []): array
    {
        global $database;

        if (!checkPermissionOrWarn('view_mom')) {
            return ['success' => false, 'message' => 'Permesso negato'];
        }

        // Usa 'filterSection' per evitare conflitti con parametro routing 'section'
        $section = isset($filtri['filterSection']) ? trim((string) $filtri['filterSection']) : (isset($filtri['section']) ? trim((string) $filtri['section']) : '');
        $contextType = isset($filtri['contextType']) ? trim((string) $filtri['contextType']) : '';
        $contextId = isset($filtri['contextId']) ? trim((string) $filtri['contextId']) : '';

        // Query base - alias camelCase per frontend JS
        $sql = "SELECT
                    mi.*,
                    mi.item_type as itemType,
                    mi.data_target as dataTarget,
                    mi.item_code as itemCode,
                    mi.mom_id as momId,
                    CASE
                       WHEN m.codice_protocollo IS NOT NULL THEN CONCAT('MOM_', m.codice_protocollo, '_', LPAD(m.progressivo, 3, '0'), '_', RIGHT(m.anno, 2))
                       ELSE CONCAT(m.progressivo, '/', m.anno)
                    END as momProtocollo,
                    m.titolo as momTitolo,
                    m.data_meeting as momData,
                    m.section as momSection
                FROM mom_items mi
                JOIN mom m ON mi.mom_id = m.id
                WHERE m.stato != 'archiviato'"; // Escludi archiviati se necessario, o rendilo opzionale

        $params = [];

        // Filtro Section (su MOM)
        if (!empty($section)) {
            $sql .= " AND m.section = :section";
            $params[':section'] = $section;
        }

        // Filtro Context (su MOM)
        if (!empty($contextType) && !empty($contextId)) {
            // Nota: MOM salva context in context_type/context_id
            $sql .= " AND m.context_type = :ctype AND m.context_id = :cid";
            $params[':ctype'] = $contextType;
            $params[':cid'] = $contextId;
        }

        // Filtro Tipo Item (opzionale)
        if (!empty($filtri['tipo'])) {
            $sql .= " AND mi.item_type = :tipo";
            $params[':tipo'] = $filtri['tipo'];
        }

        $sql .= " ORDER BY COALESCE(mi.data_target, m.data_meeting) DESC, mi.id DESC LIMIT 500";

        try {
            // Usa il wrapper $database->query
            $items = $database->query($sql, $params, __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

            return ['success' => true, 'data' => $items];
        } catch (\Throwable $e) {
            error_log("[MOM getGlobalItems] Errore: " . $e->getMessage());
            return ['success' => false, 'message' => 'Errore database: ' . $e->getMessage()];
        }
    }
}

