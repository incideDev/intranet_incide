<?php
namespace Services;

// Sicurezza gestita dal bootstrap centrale

include_once ROOT . '/IntLibs/phpWord/phpword_init.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\TemplateProcessor;

class ProtocolloEmailService
{

    private static function checkSoftLock($row, $requiredUserId = null, $requiredUsername = null, $ore = 1)
    {
        if (!$row)
            return [false, 'Comunicazione non trovata'];
        $userId = $requiredUserId ?? ($_SESSION['user_id'] ?? 0);
        $username = $requiredUsername ?? ($_SESSION['username'] ?? '');
        $isOwner = (
            ($userId && isset($row['inviato_da_id']) && intval($row['inviato_da_id']) === intval($userId)) ||
            ($username && isset($row['inviato_da']) && $row['inviato_da'] === $username)
        );
        if (!$isOwner) {
            return [false, 'Solo il creatore può modificare questa comunicazione'];
        }
        $createdTime = strtotime($row['data']);
        $maxSeconds = $ore * 3600;
        if (time() - $createdTime > $maxSeconds) {
            $unit = ($ore > 1 ? 'ore' : 'ora');
            return [false, "Modifica/rigenerazione permessa solo entro $ore $unit dalla creazione"];
        }
        return [true, ''];
    }

    public static function getCommesse(): array
    {
        global $database;
        $res = $database->query("
            select
                codice,
                oggetto
            from elenco_commesse
            where codice is not null and codice != '' and oggetto is not null and oggetto != ''
            order by codice asc
        ", [], __FILE__);
        $rows = $res?->fetchAll(\PDO::FETCH_ASSOC) ?? [];
        // Applica fixMojibake ai campi che potrebbero contenere mojibake
        foreach ($rows as &$row) {
            $row['codice'] = fixMojibake($row['codice'] ?? '');
            $row['oggetto'] = fixMojibake($row['oggetto'] ?? '');
        }
        unset($row);
        return $rows;
    }

    public static function caricaAziende(): array
    {
        global $database;
        $res = $database->query("
            select id, ragionesociale
            from anagrafiche
            where ragionesociale is not null and ragionesociale != ''
            order by ragionesociale asc
        ", [], __FILE__);
        return $res?->fetchAll(\PDO::FETCH_ASSOC) ?? [];
    }

    public static function getArchivio(int $pagina = 1, int $limite = 0, $id = null): array
    {
        global $database;

        $sql = "
            select 
                ae.id, ae.protocollo, ae.commessa, ae.inviato_da, ae.inviato_da_id,
                ae.data, ae.oggetto, ae.tipologia, ae.modello_lettera,
                cast(substring_index(substring_index(ae.protocollo, '_', -2), '_', 1) as unsigned) as progressivo
            from archivio_email ae
        ";

        // Usa funzione centralizzata per visibilità (single source of truth)
        $visibilita = getProtocolloEmailVisibility();
        $puo_generale = $visibilita['generale'];
        $puo_singole = $visibilita['commesse'];
        $solo_proprie = false; // Non più supportato, sempre false

        $is_admin = isAdmin();
        $current_uid = intval($_SESSION['user_id'] ?? 0);

        // leggi filtri richiesti dal client (opzionali)
        $solo_aree = null;
        // 1) post/get
        if (isset($_POST['solo_aree']))
            $solo_aree = $_POST['solo_aree'];
        elseif (isset($_REQUEST['solo_aree']))
            $solo_aree = $_REQUEST['solo_aree'];
        // 2) json body
        if ($solo_aree === null) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $j = json_decode($raw, true);
                if (isset($j['solo_aree']))
                    $solo_aree = $j['solo_aree'];
            }
        }
        if (!is_array($solo_aree))
            $solo_aree = [];
        $solo_aree = array_values(array_unique(array_map(function ($v) {
            $v = strtolower(trim((string) $v));
            return ($v === 'generale' || $v === 'commessa') ? $v : '';
        }, $solo_aree)));
        $solo_aree = array_filter($solo_aree);

        // Validazione: se richiedono aree non permesse, ritorna errore JSON pulito
        if (!empty($solo_aree)) {
            $richiedeGeneraleNonPermesso = in_array('generale', $solo_aree, true) && !$puo_generale;
            $richiedeCommesseNonPermesso = in_array('commessa', $solo_aree, true) && !$puo_singole;

            if ($richiedeGeneraleNonPermesso || $richiedeCommesseNonPermesso) {
                $areeNegate = [];
                if ($richiedeGeneraleNonPermesso)
                    $areeNegate[] = 'generale';
                if ($richiedeCommesseNonPermesso)
                    $areeNegate[] = 'commessa';

                return [
                    'success' => false,
                    'error' => 'non autorizzato: area ' . implode(', ', $areeNegate) . ' non permessa'
                ];
            }
        }

        // Applica intersezione con permessi: se chiedono aree non permesse, vengono ignorate
        $richiede_generale = in_array('generale', $solo_aree, true) && $puo_generale;
        $richiede_commesse = in_array('commessa', $solo_aree, true) && $puo_singole;

        // Se nessun filtro specificato, default: tutte le aree che l'utente può vedere
        if (empty($solo_aree)) {
            $richiede_generale = $puo_generale;
            $richiede_commesse = $puo_singole;
        }

        // lista codici generali
        $generali = ['gar', 'amm', 'off', 'acq', 'hrr', 'sqq', 'gco', 'con'];

        $where = [];
        $params = [];

        if ($id !== null && intval($id) > 0) {
            $where[] = "ae.id = :id";
            $params[':id'] = intval($id);
        }

        // filtro duro per aree richieste + permessi
        if ($richiede_generale && !$richiede_commesse) {
            // solo generale
            $where[] = "lower(ae.commessa) in ('gar','amm','off','acq','hrr','sqq','gco','con')";
        } elseif (!$richiede_generale && $richiede_commesse) {
            // solo commesse (non generali)
            $where[] = "lower(ae.commessa) not in ('gar','amm','off','acq','hrr','sqq','gco','con')";
        } else {
            // entrambe o nessuna selezione → applichiamo solo i divieti dovuti ai permessi
            if (!$puo_generale) {
                $where[] = "lower(ae.commessa) not in ('gar','amm','off','acq','hrr','sqq','gco','con')";
            }
            if (!$puo_singole) {
                $where[] = "lower(ae.commessa) in ('gar','amm','off','acq','hrr','sqq','gco','con')";
            }
        }

        // solo proprie (se abilitato e non admin)
        if ($solo_proprie && !$is_admin) {
            $where[] = "ae.inviato_da_id = :uid";
            $params[':uid'] = $current_uid;
        }

        if ($where)
            $sql .= " where " . implode(" and ", $where);
        $sql .= " order by id desc";

        // --- COUNT coerente
        $countSql = "select count(*) from archivio_email ae" . ($where ? " where " . implode(" and ", $where) : "");
        $countStmt = $database->connection->prepare($countSql);
        foreach ($params as $k => $v) {
            if ($k === ':limite' || $k === ':offset')
                continue;
            $type = ($k === ':id' || $k === ':uid') ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $countStmt->bindValue($k, $v, $type);
        }
        $countStmt->execute();
        $total = intval($countStmt->fetchColumn());

        // --- LIMIT/OFFSET
        if ($limite > 0) {
            $offset = max(0, ($pagina - 1) * $limite);
            $sql .= " limit :limite offset :offset";
            $params[':limite'] = (int) $limite;
            $params[':offset'] = (int) $offset;
        }

        $stmt = $database->connection->prepare($sql);

        // Bind parametri
        foreach ($params as $key => $val) {
            if ($key === ':limite' || $key === ':offset' || $key === ':id' || $key === ':uid') {
                $stmt->bindValue($key, $val, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $val, \PDO::PARAM_STR);
            }
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?? [];

        foreach ($rows as &$row) {
            $row['data_iso'] = $row['data'];
            $row['data'] = $database->formatDate($row['data']);
            unset($row['progressivo']);

            $dest = $database->query("
                select d.*, a.ragionesociale as ditta_nome
                from archivio_email_destinatari d
                left join anagrafiche a on a.id = d.azienda_id
                where d.protocollo_email_id = :pid
                order by case when d.tipo = 'to' then 0 else 1 end, d.id asc
                limit 1
            ", [':pid' => $row['id']], __FILE__)->fetch(\PDO::FETCH_ASSOC);

            $row['ditta_nome'] = fixMojibake($dest['ditta_nome'] ?? '');
            $row['contatto_referente'] = $dest['email'] ?? '';
            $row['nome_referente'] = fixMojibake($dest['nome_referente'] ?? '');
            $row['oggetto'] = fixMojibake($row['oggetto'] ?? '');
            $row['commessa'] = fixMojibake($row['commessa'] ?? '');
            $row['inviato_da'] = fixMojibake($row['inviato_da'] ?? '');
        }
        unset($row);

        return [
            'success' => true,
            'data' => $rows,
            'total' => $total,
            'pagina' => $pagina,
            'limite' => $limite
        ];
    }

    public static function aggiungiAzienda($azienda = null)
    {
        global $database;
        if ($azienda === null) {
            if (!empty($_POST)) {
                $azienda = $_POST;
            } elseif (!empty($_REQUEST)) {
                $azienda = $_REQUEST;
            } else {
                $raw = file_get_contents('php://input');
                $azienda = json_decode($raw, true);
            }
        }

        if (is_array($azienda)) {
            $arr = [];
            foreach ($azienda as $k => $v) {
                $arr[strtolower(trim($k))] = is_string($v) ? trim($v) : $v;
            }
            $azienda = $arr;
        }

        $ragione = isset($azienda['ragionesociale']) ? $azienda['ragionesociale'] : '';
        if (!$ragione) {
            return ['success' => false, 'error' => 'Ragione sociale mancante (debug: ricevuto=' . print_r($azienda, true) . ')'];
        }

        $check = $database->query(
            "SELECT id FROM anagrafiche WHERE ragionesociale = :az",
            [':az' => $ragione],
            __FILE__
        );
        $id = $check ? $check->fetchColumn() : null;
        if ($id) {
            // Se esiste già, aggiorna i campi se forniti
            $updateFields = [];
            $updateParams = [':id' => $id];

            if (isset($azienda['partitaiva']) && $azienda['partitaiva'] !== '') {
                $updateFields[] = 'partitaiva = :piva';
                $updateParams[':piva'] = trim($azienda['partitaiva']);
            }
            if (isset($azienda['citta']) && $azienda['citta'] !== '') {
                $updateFields[] = 'citt = :citt';
                $updateParams[':citt'] = trim($azienda['citta']);
            }
            if (isset($azienda['email']) && $azienda['email'] !== '') {
                $updateFields[] = 'email = :email';
                $updateParams[':email'] = trim($azienda['email']);
            }
            if (isset($azienda['telefono']) && $azienda['telefono'] !== '') {
                $updateFields[] = 'telefono = :telefono';
                $updateParams[':telefono'] = trim($azienda['telefono']);
            }

            if (!empty($updateFields)) {
                $updateSql = "UPDATE anagrafiche SET " . implode(', ', $updateFields) . " WHERE id = :id";
                $database->query($updateSql, $updateParams, __FILE__);
            }

            return ['success' => true, 'id' => $id];
        }

        // INSERT con tutti i campi disponibili
        $columns = ['ragionesociale'];
        $placeholders = [':ragione'];
        $params = [':ragione' => $ragione];

        if (isset($azienda['partitaiva']) && $azienda['partitaiva'] !== '') {
            $columns[] = 'partitaiva';
            $placeholders[] = ':piva';
            $params[':piva'] = trim($azienda['partitaiva']);
        }
        if (isset($azienda['citta']) && $azienda['citta'] !== '') {
            $columns[] = 'citt';
            $placeholders[] = ':citt';
            $params[':citt'] = trim($azienda['citta']);
        }
        if (isset($azienda['email']) && $azienda['email'] !== '') {
            $columns[] = 'email';
            $placeholders[] = ':email';
            $params[':email'] = trim($azienda['email']);
        }
        if (isset($azienda['telefono']) && $azienda['telefono'] !== '') {
            $columns[] = 'telefono';
            $placeholders[] = ':telefono';
            $params[':telefono'] = trim($azienda['telefono']);
        }

        $sql = "INSERT INTO anagrafiche (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $database->query($sql, $params, __FILE__);
        if ($stmt) {
            $newId = $database->lastInsertId();
            return ['success' => true, 'id' => $newId];
        } else {
            return ['success' => false, 'error' => 'Errore DB'];
        }
    }

    public static function aggiungiContatto($aziendaId, $dati)
    {
        global $database;
        if (!$aziendaId || empty($dati['email']) || empty($dati['nome']) || empty($dati['cognome'])) {
            return ['success' => false, 'error' => 'Dati obbligatori mancanti'];
        }
        $nome = trim($dati['nome']);
        $cognome = trim($dati['cognome']);
        $cognomeENome = $cognome . ' ' . $nome;

        $check = $database->query(
            "SELECT 1 FROM anagrafiche_contatti WHERE azienda_id = :azienda_id AND email = :email",
            [':azienda_id' => $aziendaId, ':email' => $dati['email']],
            __FILE__
        );
        if ($check && $check->fetchColumn())
            return ['success' => true];

        $sql = "INSERT INTO anagrafiche_contatti (
                    azienda_id, cognome_e_nome, nome, cognome, email, cellulare, telefono, titolo, ruolo
                ) VALUES (
                    :azienda_id, :cognome_e_nome, :nome, :cognome, :email, :cellulare, :telefono, :titolo, :ruolo
                )";
        $params = [
            ':azienda_id' => $aziendaId,
            ':cognome_e_nome' => $cognomeENome,
            ':nome' => $nome,
            ':cognome' => $cognome,
            ':email' => $dati['email'],
            ':cellulare' => $dati['cellulare'] ?? '',
            ':telefono' => $dati['telefono'] ?? '',
            ':titolo' => $dati['titolo'] ?? '',
            ':ruolo' => $dati['ruolo'] ?? ''
        ];
        $res = $database->query($sql, $params, __FILE__);
        return $res ? ['success' => true] : ['success' => false, 'error' => 'Errore DB'];
    }

    public static function getNextProgressivoCommessa($commessa, $year)
    {
        global $database;
        $year = substr(trim((string) $year), -2);

        $generali = ['GAR', 'AMM', 'OFF', 'ACQ', 'HRR', 'SQQ', 'GCO', 'CON'];
        if (in_array(strtoupper($commessa), $generali)) {
            $sql = "
                SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(protocollo, '_', -2), '_', 1) AS UNSIGNED)) AS maxProg
                FROM archivio_email
                WHERE commessa IN ('GAR','AMM','OFF','ACQ','HRR','SQQ','GCO','CON')
                AND SUBSTRING_INDEX(protocollo, '_', -1) = :year
            ";
            $res = $database->query($sql, [':year' => $year], __FILE__);
        } else {
            $sql = "
                SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(protocollo, '_', -2), '_', 1) AS UNSIGNED)) AS maxProg
                FROM archivio_email
                WHERE commessa = :commessa
                AND SUBSTRING_INDEX(protocollo, '_', -1) = :year
            ";
            $res = $database->query($sql, [':commessa' => $commessa, ':year' => $year], __FILE__);
        }
        $max = $res ? intval($res->fetchColumn()) : 0;
        return $max + 1;
    }

    public static function getPreviewProtocollo($data)
    {
        global $database;

        $commessa = $data['commessa'] ?? '';
        $oggetto = $data['oggetto'] ?? '';

        if (!$commessa)
            return ['success' => false, 'error' => 'Commessa mancante'];

        // 👇 NUOVO: permessi su commessa scelta
        $is_generale = self::isGeneraleCode($commessa);
        if ($is_generale && !self::userCan('generale')) {
            return ['success' => false, 'error' => 'non autorizzato: blocco generale'];
        }
        if (!$is_generale && !self::userCan('singole_commesse')) {
            return ['success' => false, 'error' => 'non autorizzato: blocco singole_commesse'];
        }

        $type = 'M';

        $year = date('y');

        $progressivo = self::getNextProgressivoCommessa($commessa, $year);
        $progressivoStr = str_pad($progressivo, 3, '0', STR_PAD_LEFT);

        $protocollo = "{$type}_{$commessa}_{$progressivoStr}_{$year}";
        $finalCode = $protocollo;
        if (!empty($oggetto)) {
            $finalCode .= ' - ' . $oggetto;
        }

        return [
            'success' => true,
            'protocollo' => $protocollo,
            'final_code' => $finalCode
        ];
    }

    public static function genera(array $data): array
    {
        global $database;

        if (isset($data['id']) && intval($data['id']) > 0) {
            $res = self::modificaProtocollo($data);
            if (!$res['success'])
                return $res;

            $row = $database->query("SELECT * FROM archivio_email WHERE id = :id", [':id' => $data['id']], __FILE__);
            $proto = $row ? $row->fetch(\PDO::FETCH_ASSOC) : null;

            return [
                'success' => true,
                'id' => $data['id'],
                'protocollo' => $proto['protocollo'] ?? '',
                'final_code' => ($proto['protocollo'] ?? '') . (empty($data['oggetto']) ? '' : ' - ' . $data['oggetto'])
            ];
        }

        $required = ['commessa', 'inviato_da', 'ditta', 'oggetto', 'tipologia'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'error' => "campo mancante: $field"];
            }
        }

        /* permessi su area scelta */
        $commessa = trim((string) $data['commessa']);
        $is_generale = self::isGeneraleCode($commessa);
        if ($is_generale && !self::userCan('generale')) {
            return ['success' => false, 'error' => 'non autorizzato: blocco generale'];
        }
        if (!$is_generale && !self::userCan('singole_commesse')) {
            return ['success' => false, 'error' => 'non autorizzato: blocco singole_commesse'];
        }

        $utenteCorrenteId = $_SESSION['user_id'] ?? 0;
        $isAdmin = isAdmin();

        if (isset($data['id']) && intval($data['id']) > 0 && !$isAdmin) {
            $row = $database->query("SELECT data, inviato_da, inviato_da_id FROM archivio_email WHERE id = :id", [':id' => $data['id']], __FILE__);
            $old = $row ? $row->fetch(\PDO::FETCH_ASSOC) : null;
            list($allowed, $err) = self::checkSoftLock($old);
            if (!$allowed) {
                return ['success' => false, 'error' => $err];
            }
        }

        $data = array_map(function ($v) {
            return trim((string) ($v ?? ''));
        }, $data);
        $data['ccn'] = $data['ccn'] ?? '';
        $data['oggetto'] = htmlspecialchars($data['oggetto'], ENT_QUOTES, 'UTF-8');

        $stmt = $database->query("
            INSERT INTO archivio_email (
                commessa, inviato_da, inviato_da_id, data, oggetto, protocollo, tipologia, modello_lettera
            ) VALUES (
                :commessa, :inviato_da, :inviato_da_id, NOW(), :oggetto, 'TMP', :tipologia, :modello_lettera
            )
        ", [
            ':commessa' => $data['commessa'],
            ':inviato_da' => $data['inviato_da'],
            ':inviato_da_id' => $_SESSION['user_id'] ?? 0,
            ':oggetto' => $data['oggetto'],
            ':tipologia' => $data['tipologia'],
            ':modello_lettera' => $data['modello'] ?? null
        ], __FILE__);

        if (!$stmt) {
            return ['success' => false, 'error' => 'Errore nel salvataggio'];
        }

        $id = $database->lastInsertId();
        if (!empty($data['destinatari_json'])) {
            self::salvaDestinatariDettaglio($id, $data['destinatari_json']);
        }

        $project = $data['commessa'];
        $type = 'M';

        $year = date('y');

        $progressivo = self::getNextProgressivoCommessa($project, $year);
        $progressivoStr = str_pad($progressivo, 3, '0', STR_PAD_LEFT);
        $protocollo = "{$type}_{$project}_{$progressivoStr}_{$year}";

        $database->query("UPDATE archivio_email SET protocollo = :protocollo WHERE id = :id", [
            ':protocollo' => $protocollo,
            ':id' => $id
        ], __FILE__);

        $finalCode = $protocollo;
        if (!empty($data['oggetto'])) {
            $finalCode .= ' - ' . $data['oggetto'];
        }

        return [
            'success' => true,
            'id' => $id,
            'protocollo' => $protocollo,
            'final_code' => $finalCode
        ];
    }

    public static function generaEApri(array $data): array
    {
        global $database;

        // PATCH: Se c'è ID, aggiorna invece di inserire E POI ESCI SUBITO!
        if (isset($data['id']) && intval($data['id']) > 0) {
            $res = self::modificaProtocollo($data);
            if (!$res['success'])
                return $res;

            $row = $database->query("SELECT * FROM archivio_email WHERE id = :id", [':id' => $data['id']], __FILE__);
            $proto = $row ? $row->fetch(\PDO::FETCH_ASSOC) : null;
            $protocollo = $proto['protocollo'] ?? '';
            $finalCode = $protocollo . (empty($data['oggetto']) ? '' : ' - ' . $data['oggetto']);

            if ($data['tipologia'] === 'email') {
                $a = $data['a'] ?? $data['to'] ?? '';
                if (is_array($a)) {
                    $to = $a;
                } else if (is_string($a)) {
                    $to = array_filter(array_map('trim', preg_split('/[;,]/', $a)));
                } else {
                    $to = [];
                }

                $cc = $data['cc'] ?? '';
                if (is_array($cc)) {
                    $ccArr = $cc;
                } else if (is_string($cc)) {
                    $ccArr = array_filter(array_map('trim', preg_split('/[;,]/', $cc)));
                } else {
                    $ccArr = [];
                }

                $bcc = $data['ccn'] ?? '';
                if (is_array($bcc)) {
                    $bccArr = $bcc;
                } else if (is_string($bcc)) {
                    $bccArr = array_filter(array_map('trim', preg_split('/[;,]/', $bcc)));
                } else {
                    $bccArr = [];
                }

                $mailto = 'mailto:';
                if (!empty($to)) {
                    $mailto .= implode(';', $to);
                }
                $params = [];
                $subjectSafe = preg_replace("/[\r\n]+/", ' ', $finalCode);
                $params[] = "subject=" . urlencode($subjectSafe);
                if (!empty($ccArr)) {
                    $params[] = "cc=" . urlencode(implode(';', $ccArr));
                }
                if (!empty($bccArr)) {
                    $params[] = "bcc=" . urlencode(implode(';', $bccArr));
                }
                if (count($params) > 0) {
                    $mailto .= "?" . implode('&', $params);
                }

                return [
                    'success' => true,
                    'id' => $data['id'],
                    'protocollo' => $protocollo,
                    'final_code' => $finalCode,
                    'mailto' => $mailto
                ];
            }

            // ATTENZIONE: qui il required VA messo SOLO per nuovo, NON per update
            if ($data['tipologia'] === 'lettera') {
                if (empty($data['modello'])) {
                    return ['success' => false, 'error' => 'Modello lettera mancante'];
                }

                $userId = $_SESSION['user_id'];
                $dataIt = date('dmY');
                $base = defined('ROOT') ? ROOT : dirname(__DIR__);
                $relative = "uploads/tmp_protocollo_email/{$userId}_{$dataIt}";
                $dir = rtrim($base, '/\\') . '/' . $relative;

                if (!is_dir($dir)) {
                    if (!is_writable(dirname($dir))) {
                        error_log("[ProtocolloEmailService] ATTENZIONE: Padre " . dirname($dir) . " non scrivibile?");
                    }
                    if (!@mkdir($dir, 0777, true)) {
                        $error = error_get_last();
                        return ['success' => false, 'error' => 'Impossibile creare la cartella di output: ' . ($error['message'] ?? 'errore sconosciuto')];
                    }
                    @chmod($dir, 0777);
                }

                $baseDir = defined('ROOT') ? ROOT : dirname(__DIR__);
                $templatePath = rtrim($baseDir, '/\\') . "/IntLibs/phpWord/templates/{$data['modello']}.docx";
                if (!file_exists($templatePath)) {
                    return ['success' => false, 'error' => 'Template Word non trovato: ' . $templatePath];
                }

                try {
                    $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

                    $dittaVal = $data['ditta'] ?? '';
                    if (is_numeric($dittaVal)) {
                        $rowAzienda = $database->query(
                            "SELECT ragionesociale FROM anagrafiche WHERE id = :id",
                            [':id' => $dittaVal],
                            __FILE__
                        );
                        $dittaVal = $rowAzienda ? fixMojibake($rowAzienda->fetchColumn()) : $dittaVal;
                    }
                    $templateProcessor->setValue('destinatario', htmlspecialchars($dittaVal));
                    $templateProcessor->setValue('referente', htmlspecialchars($data['nome_referente'] ?? ''));
                    $templateProcessor->setValue('descrizione', htmlspecialchars($data['oggetto'] ?? ''));
                    $templateProcessor->setValue('data', date('d/m/Y'));
                    $templateProcessor->setValue('protocollo', htmlspecialchars($protocollo));

                    $nomeFile = 'Lettera_' . preg_replace('/[^a-zA-Z0-9]/', '_', $protocollo) . '.docx';
                    $pathAssoluto = $dir . '/' . $nomeFile;
                    $pathRelativo = $relative . '/' . $nomeFile;

                    $templateProcessor->saveAs($pathAssoluto);

                    return [
                        'success' => true,
                        'id' => $data['id'],
                        'protocollo' => $protocollo,
                        'final_code' => $finalCode,
                        'url' => '/' . $pathRelativo,
                        'filename' => $nomeFile
                    ];
                } catch (\Throwable $e) {
                    return ['success' => false, 'error' => 'Errore generazione: ' . $e->getMessage()];
                }
            }

            return [
                'success' => false,
                'error' => 'Tipologia non gestita'
            ];
        }

        // PATCH: SOLO per nuovo (INSERT) fai il required!
        $required = ['commessa', 'inviato_da', 'ditta', 'oggetto', 'tipologia'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'error' => "Campo mancante: $field"];
            }
        }

        // === RESTO DEL CODICE INSERT (identico al tuo) ===

        $data = array_map(function ($v) {
            return trim((string) ($v ?? ''));
        }, $data);
        $data['ccn'] = $data['ccn'] ?? '';
        $data['oggetto'] = htmlspecialchars($data['oggetto'], ENT_QUOTES, 'UTF-8');

        $stmt = $database->query("
        INSERT INTO archivio_email (
            commessa, inviato_da, inviato_da_id, data, oggetto, protocollo, tipologia, modello_lettera
        ) VALUES (
            :commessa, :inviato_da, :inviato_da_id, NOW(), :oggetto, 'TMP', :tipologia, :modello_lettera
        )
    ", [
            ':commessa' => $data['commessa'],
            ':inviato_da' => $data['inviato_da'],
            ':inviato_da_id' => $_SESSION['user_id'] ?? 0,
            ':oggetto' => $data['oggetto'],
            ':tipologia' => $data['tipologia'],
            ':modello_lettera' => $data['modello'] ?? null
        ], __FILE__);

        if (!$stmt) {
            return ['success' => false, 'error' => 'Errore nel salvataggio'];
        }

        $id = $database->lastInsertId();
        if (!empty($data['destinatari_json'])) {
            self::salvaDestinatariDettaglio($id, $data['destinatari_json']);
        }

        $project = $data['commessa'];
        $type = 'M';

        $year = date('y');

        $progressivo = self::getNextProgressivoCommessa($project, $year);
        $progressivoStr = str_pad($progressivo, 3, '0', STR_PAD_LEFT);
        $protocollo = "{$type}_{$project}_{$progressivoStr}_{$year}";

        $database->query("UPDATE archivio_email SET protocollo = :protocollo WHERE id = :id", [
            ':protocollo' => $protocollo,
            ':id' => $id
        ], __FILE__);

        $finalCode = $protocollo;
        if (!empty($data['oggetto'])) {
            $finalCode .= ' - ' . $data['oggetto'];
        }

        if ($data['tipologia'] === 'email') {
            $a = $data['a'] ?? $data['to'] ?? '';
            if (is_array($a)) {
                $to = $a;
            } else if (is_string($a)) {
                $to = array_filter(array_map('trim', preg_split('/[;,]/', $a)));
            } else {
                $to = [];
            }

            $cc = $data['cc'] ?? '';
            if (is_array($cc)) {
                $ccArr = $cc;
            } else if (is_string($cc)) {
                $ccArr = array_filter(array_map('trim', preg_split('/[;,]/', $cc)));
            } else {
                $ccArr = [];
            }

            $bcc = $data['ccn'] ?? '';
            if (is_array($bcc)) {
                $bccArr = $bcc;
            } else if (is_string($bcc)) {
                $bccArr = array_filter(array_map('trim', preg_split('/[;,]/', $bcc)));
            } else {
                $bccArr = [];
            }

            $mailto = 'mailto:';
            if (!empty($to)) {
                $mailto .= implode(';', $to);
            }
            $params = [];
            $subjectSafe = preg_replace("/[\r\n]+/", ' ', $finalCode);
            $params[] = "subject=" . urlencode($subjectSafe);

            if (!empty($ccArr)) {
                $params[] = "cc=" . urlencode(implode(';', $ccArr));
            }
            if (!empty($bccArr)) {
                $params[] = "bcc=" . urlencode(implode(';', $bccArr));
            }
            if (count($params) > 0) {
                $mailto .= "?" . implode('&', $params);
            }

            return [
                'success' => true,
                'id' => $id,
                'protocollo' => $protocollo,
                'final_code' => $finalCode,
                'mailto' => $mailto
            ];
        }

        if ($data['tipologia'] === 'lettera') {
            if (empty($data['modello'])) {
                return ['success' => false, 'error' => 'Modello lettera mancante'];
            }

            $userId = $_SESSION['user_id'];
            $dataIt = date('dmY');
            $base = defined('ROOT') ? ROOT : dirname(__DIR__);
            $relative = "uploads/tmp_protocollo_email/{$userId}_{$dataIt}";
            $dir = rtrim($base, '/\\') . '/' . $relative;

            if (!is_dir($dir)) {
                if (!is_writable(dirname($dir))) {
                    error_log("[ProtocolloEmailService] ATTENZIONE: Padre " . dirname($dir) . " non scrivibile?");
                }
                if (!@mkdir($dir, 0777, true)) {
                    $error = error_get_last();
                    return ['success' => false, 'error' => 'Impossibile creare la cartella di output: ' . ($error['message'] ?? 'errore sconosciuto')];
                }
                @chmod($dir, 0777);
            }

            $baseDir = defined('ROOT') ? ROOT : dirname(__DIR__);
            $templatePath = rtrim($baseDir, '/\\') . "/IntLibs/phpWord/templates/{$data['modello']}.docx";
            if (!file_exists($templatePath)) {
                return ['success' => false, 'error' => 'Template Word non trovato: ' . $templatePath];
            }

            try {
                $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

                $dittaVal = $data['ditta'] ?? '';
                if (is_numeric($dittaVal)) {
                    $rowAzienda = $database->query(
                        "SELECT ragionesociale FROM anagrafiche WHERE id = :id",
                        [':id' => $dittaVal],
                        __FILE__
                    );
                    $dittaVal = $rowAzienda ? fixMojibake($rowAzienda->fetchColumn()) : $dittaVal;
                }
                $templateProcessor->setValue('destinatario', htmlspecialchars($dittaVal));
                $templateProcessor->setValue('referente', htmlspecialchars($data['nome_referente'] ?? ''));
                $templateProcessor->setValue('descrizione', htmlspecialchars($data['oggetto'] ?? ''));
                $templateProcessor->setValue('data', date('d/m/Y'));
                $templateProcessor->setValue('protocollo', htmlspecialchars($protocollo));

                $nomeFile = 'Lettera_' . preg_replace('/[^a-zA-Z0-9]/', '_', $protocollo) . '.docx';
                $pathAssoluto = $dir . '/' . $nomeFile;
                $pathRelativo = $relative . '/' . $nomeFile;

                $templateProcessor->saveAs($pathAssoluto);

                return [
                    'success' => true,
                    'id' => $id,
                    'protocollo' => $protocollo,
                    'final_code' => $finalCode,
                    'url' => '/' . $pathRelativo,
                    'filename' => $nomeFile
                ];
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Errore generazione: ' . $e->getMessage()];
            }
        }

        return [
            'success' => false,
            'error' => 'Tipologia non gestita'
        ];
    }

    public static function modificaProtocollo($dati)
    {
        global $database;

        $id = isset($dati['id']) ? intval($dati['id']) : 0;
        if ($id <= 0) {
            return ['success' => false, 'error' => 'ID mancante o non valido'];
        }

        $isAdmin = isAdmin();
        if (!$isAdmin) {
            $row = $database->query("SELECT data, inviato_da, inviato_da_id FROM archivio_email WHERE id = :id", [':id' => $id], __FILE__);
            $old = $row ? $row->fetch(\PDO::FETCH_ASSOC) : null;
            list($allowed, $err) = self::checkSoftLock($old);
            if (!$allowed) {
                return ['success' => false, 'error' => $err];
            }
        }

        $fields = [
            'commessa',
            'inviato_da',
            'oggetto',
            'tipologia',
            'modello_lettera'
        ];

        $params = [];
        $setSql = [];
        foreach ($fields as $f) {
            if (isset($dati[$f])) {
                $setSql[] = "$f = :$f";
                $params[":$f"] = trim((string) ($dati[$f] ?? ''));
            }
        }
        if (!$setSql) {
            return ['success' => false, 'error' => 'Nessun campo da aggiornare'];
        }

        $params[':id'] = $id;
        $sql = "UPDATE archivio_email SET " . implode(', ', $setSql) . " WHERE id = :id";
        $res = $database->query($sql, $params, __FILE__);

        if ($res && !empty($dati['destinatari_json'])) {
            self::salvaDestinatariDettaglio($id, $dati['destinatari_json']);
        }
        return $res
            ? ['success' => true]
            : ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
    }

    public static function eliminaProtocollo($id)
    {
        global $database;
        $id = intval($id);
        if ($id <= 0) {
            return ['success' => false, 'error' => 'ID non valido'];
        }

        $row = $database->query("SELECT * FROM archivio_email WHERE id = :id", [':id' => $id], __FILE__);
        $r = $row ? $row->fetch(\PDO::FETCH_ASSOC) : null;
        if (!$r) {
            return ['success' => false, 'error' => 'Protocollo non trovato'];
        }

        $isAdmin = isAdmin();

        $commessa = $r['commessa'];
        $protocollo = $r['protocollo'];
        $proto_parts = explode('_', $protocollo);
        $progressivo = isset($proto_parts[2]) ? intval($proto_parts[2]) : 0;
        $year = isset($proto_parts[3]) ? $proto_parts[3] : '';

        $generali = ['GAR', 'AMM', 'OFF', 'ACQ', 'HRR', 'SQQ', 'GCO', 'CON'];
        if (in_array(strtoupper($commessa), $generali)) {
            $sql = "SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(protocollo, '_', -2), '_', 1) AS UNSIGNED)) AS max_prog
                    FROM archivio_email
                    WHERE commessa IN ('GAR','AMM','OFF','ACQ','HRR','SQQ','GCO','CON') AND SUBSTRING_INDEX(protocollo, '_', -1) = :year";
            $params = [':year' => $year];
        } else {
            $sql = "SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(protocollo, '_', -2), '_', 1) AS UNSIGNED)) AS max_prog
                    FROM archivio_email
                    WHERE commessa = :commessa AND SUBSTRING_INDEX(protocollo, '_', -1) = :year";
            $params = [':commessa' => $commessa, ':year' => $year];
        }
        $res = $database->query($sql, $params, __FILE__);
        $max_prog = $res ? intval($res->fetchColumn()) : 0;

        if ($progressivo < $max_prog && !$isAdmin) {
            return [
                'success' => false,
                'error' => 'Non puoi più eliminare: esiste già un protocollo successivo su questa commessa. Solo l\'ultimo progressivo può essere eliminato.'
            ];
        }

        if (!$isAdmin) {
            list($allowed, $err) = self::checkSoftLock($r);
            if (!$allowed) {
                return ['success' => false, 'error' => 'Eliminazione consentita solo entro 1 ora dalla creazione.'];
            }
        }

        $database->query("DELETE FROM archivio_email_destinatari WHERE protocollo_email_id = :id", [':id' => $id], __FILE__);

        $res = $database->query("DELETE FROM archivio_email WHERE id = :id", [':id' => $id], __FILE__);
        return $res
            ? ['success' => true]
            : ['success' => false, 'error' => 'Errore durante l\'eliminazione'];
    }

    public static function getTuttiContatti()
    {
        global $database;
        $res = $database->query("
            select
                ac.id,
                ac.azienda_id,
                a.ragionesociale as azienda,
                ac.cognome_e_nome,
                ac.nome,
                ac.cognome,
                ac.email,
                ac.cellulare,
                ac.telefono,
                ac.titolo,
                ac.ruolo
            from anagrafiche_contatti ac
            inner join anagrafiche a on a.id = ac.azienda_id
            where ac.email is not null and ac.email != ''
            order by a.ragionesociale asc, ac.cognome_e_nome asc
        ", [], __FILE__);
        $rows = $res ? $res->fetchAll(\PDO::FETCH_ASSOC) : [];
        // Applica fixMojibake ai campi che potrebbero contenere mojibake
        foreach ($rows as &$row) {
            $row['azienda'] = fixMojibake($row['azienda']);
            $row['cognome_e_nome'] = fixMojibake($row['cognome_e_nome']);
            $row['nome'] = fixMojibake($row['nome']);
            $row['cognome'] = fixMojibake($row['cognome']);
            $row['titolo'] = fixMojibake($row['titolo']);
            $row['ruolo'] = fixMojibake($row['ruolo']);
        }
        unset($row);
        return $rows;
    }

    public static function getContattiByAzienda($azienda)
    {
        global $database;
        if (!$azienda || $azienda === '' || $azienda === '0')
            return [];

        // Normalizza: se è stringa numerica, convertila in int
        if (is_string($azienda) && is_numeric($azienda)) {
            $azienda = intval($azienda);
        }

        if (is_numeric($azienda) && intval($azienda) > 0) {
            $sql = "select ac.id, ac.azienda_id, a.ragionesociale as azienda, ac.cognome_e_nome, ac.nome, ac.cognome, ac.email, ac.cellulare, ac.telefono, ac.titolo, ac.ruolo
                    from anagrafiche_contatti ac
                    inner join anagrafiche a on a.id = ac.azienda_id
                    where ac.azienda_id = :azienda
                    order by ac.cognome_e_nome asc";
            $params = [':azienda' => intval($azienda)];
        } else {
            $sql = "select ac.id, ac.azienda_id, a.ragionesociale as azienda, ac.cognome_e_nome, ac.nome, ac.cognome, ac.email, ac.cellulare, ac.telefono, ac.titolo, ac.ruolo
                    from anagrafiche_contatti ac
                    inner join anagrafiche a on a.id = ac.azienda_id
                    where a.ragionesociale = :azienda
                    order by ac.cognome_e_nome asc";
            $params = [':azienda' => trim($azienda)];
        }
        $res = $database->query($sql, $params, __FILE__);
        $rows = $res ? $res->fetchAll(\PDO::FETCH_ASSOC) : [];
        // Applica fixMojibake ai campi che potrebbero contenere mojibake
        foreach ($rows as &$row) {
            $row['azienda'] = fixMojibake($row['azienda']);
            $row['cognome_e_nome'] = fixMojibake($row['cognome_e_nome']);
            $row['nome'] = fixMojibake($row['nome']);
            $row['cognome'] = fixMojibake($row['cognome']);
            $row['titolo'] = fixMojibake($row['titolo']);
            $row['ruolo'] = fixMojibake($row['ruolo']);
        }
        unset($row);
        return $rows;
    }

    public static function getDestinatariDettaglio($protocollo_email_id)
    {
        global $database;
        // Prendi destinatari senza join
        $destinatari = $database->query("
        select *
        from archivio_email_destinatari
        where protocollo_email_id = :id
        order by id asc
    ", [':id' => $protocollo_email_id], __FILE__)->fetchAll(\PDO::FETCH_ASSOC);

        if (!$destinatari)
            return [];

        // Estrai ID unici da caricare
        $azienda_ids = array_unique(array_filter(array_column($destinatari, 'azienda_id')));
        $contatto_ids = array_unique(array_filter(array_column($destinatari, 'contatto_id')));

        // Carica tutte le aziende (ragionesociale) in un array associativo id => ragionesociale
        $aziende = [];
        if ($azienda_ids) {
            $in = implode(',', array_map('intval', $azienda_ids));
            $aziendeRaw = $database->query("select id, ragionesociale from anagrafiche where id in ($in)", [], __FILE__)->fetchAll(\PDO::FETCH_KEY_PAIR);
            // Applica fixMojibake a tutti i valori
            foreach ($aziendeRaw as $id => $ragione) {
                $aziende[$id] = fixMojibake($ragione);
            }
        }

        // Carica tutte le email contatti in array id => email (separando CRM da Personale per evitare collisioni ID)
        $contattiCrm = [];
        $contattiIncide = [];

        // Separa gli ID
        $idsCrm = [];
        $idsIncide = [];

        foreach ($destinatari as $d) {
            $aid = intval($d['azienda_id'] ?? 0);
            $cid = intval($d['contatto_id'] ?? 0);
            if ($cid > 0) {
                if ($aid === 194) {
                    $idsIncide[] = $cid;
                } else {
                    $idsCrm[] = $cid;
                }
            }
        }
        $idsCrm = array_unique($idsCrm);
        $idsIncide = array_unique($idsIncide);

        if (!empty($idsCrm)) {
            $in = implode(',', array_map('intval', $idsCrm));
            $contattiCrm = $database->query("select id, email from anagrafiche_contatti where id in ($in)", [], __FILE__)->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
        if (!empty($idsIncide)) {
            $in = implode(',', array_map('intval', $idsIncide));
            $contattiIncide = $database->query("select user_id, Email_Aziendale from personale where user_id in ($in)", [], __FILE__)->fetchAll(\PDO::FETCH_KEY_PAIR);
        }

        foreach ($destinatari as &$d) {
            $d['azienda'] = isset($aziende[$d['azienda_id']]) ? $aziende[$d['azienda_id']] : null;

            $aid = intval($d['azienda_id'] ?? 0);
            $cid = intval($d['contatto_id'] ?? 0);

            if ($aid === 194) {
                // Caso Incide: ID è user_id di personale
                $d['contatto_email'] = isset($contattiIncide[$cid]) ? $contattiIncide[$cid] : $d['email'];
            } else {
                // Caso Standard: ID è id di anagrafiche_contatti
                $d['contatto_email'] = isset($contattiCrm[$cid]) ? $contattiCrm[$cid] : $d['email'];
            }

            // Blindatura: se contatto_id è valorizzato e non corrisponde all'azienda_id della riga, forzalo a null
            // Nota: per Incide saltiamo il controllo incrociato su anagrafiche_contatti perchè non sono lì
            if ($d['contatto_id'] && (!isset($d['azienda_id']) || !isset($aziende[$d['azienda_id']]))) {
                $d['contatto_id'] = null;
                $d['contatto_email'] = null;
            }
        }
        unset($d);
        return $destinatari;
    }

    // Salva i destinatari dettaglio in archivio_email_destinatari
    public static function salvaDestinatariDettaglio($protocollo_email_id, $json)
    {
        global $database;

        // Cancella prima i precedenti (update)
        $database->query(
            "DELETE FROM archivio_email_destinatari WHERE protocollo_email_id = :id",
            [':id' => $protocollo_email_id],
            __FILE__
        );

        // Dedup in input
        $gia_salvati = [];

        // Decodifica
        $lista = [];
        if (is_string($json)) {
            $lista = json_decode($json, true);
        } elseif (is_array($json)) {
            $lista = $json;
        }
        if (!is_array($lista))
            return;

        foreach ($lista as $d) {
            $azienda_id = intval($d['azienda_id'] ?? 0);
            $contatto_id = intval($d['contatto_id'] ?? 0);
            $tipo = substr(strtolower($d['tipo'] ?? ''), 0, 2);
            $nome_referente = trim($d['nome_referente'] ?? '');
            $email = trim($d['email'] ?? '');

            // PATCH: salta righe duplicate!
            $key = "{$azienda_id}_{$contatto_id}_{$tipo}_{$email}";
            if (in_array($key, $gia_salvati))
                continue;
            $gia_salvati[] = $key;

            // BLINDATURA: se c'è sia azienda che contatto, il contatto deve essere realmente di quell'azienda!
            // BLINDATURA: se c'è sia azienda che contatto, il contatto deve essere realmente di quell'azienda!
            if ($azienda_id > 0 && $contatto_id > 0) {
                if ($azienda_id === 194) {
                    // ECCEZIONE INCIDE: controlla su tabella personale
                    $personaleCheck = $database->query(
                        "SELECT 1 FROM personale WHERE user_id = :uid AND attivo = 1",
                        [':uid' => $contatto_id],
                        __FILE__
                    );
                    if (!$personaleCheck || !$personaleCheck->fetchColumn()) {
                        // Contatto (dipendente) non trovato o non attivo
                        continue;
                    }
                } else {
                    // STANDARD: controlla su tabella anagrafiche_contatti
                    $contattoCheck = $database->query(
                        "SELECT 1 FROM anagrafiche_contatti WHERE id = :cid AND azienda_id = :aid",
                        [':cid' => $contatto_id, ':aid' => $azienda_id],
                        __FILE__
                    );
                    if (!$contattoCheck || !$contattoCheck->fetchColumn()) {
                        // Salto: contatto non appartiene all'azienda selezionata
                        continue;
                    }
                }
            }

            // Almeno uno tra azienda, contatto o tipo deve essere valorizzato
            if ($azienda_id > 0 || $contatto_id > 0 || $tipo) {
                $database->query(
                    "INSERT INTO archivio_email_destinatari
                    (protocollo_email_id, azienda_id, contatto_id, tipo, email, nome_referente, data_inserimento)
                    VALUES (:pid, :aid, :cid, :tipo, :email, :nome, NOW())",
                    [
                        ':pid' => $protocollo_email_id,
                        ':aid' => $azienda_id,
                        ':cid' => $contatto_id,
                        ':tipo' => $tipo,
                        ':email' => $email,
                        ':nome' => $nome_referente
                    ],
                    __FILE__
                );
            }
        }
    }

    /**
     * Verifica se l'utente corrente può accedere a un blocco protocollo.
     * Usa la funzione centralizzata getProtocolloEmailVisibility() con permessi avanzati.
     * 
     * @param string $blocco 'generale' o 'singole_commesse' o 'commessa'
     * @return bool true se l'utente può accedere, false altrimenti
     */
    public static function userCan($blocco)
    {
        // Admin vede sempre tutto
        if (isAdmin()) {
            return true;
        }

        // Normalizza blocco
        $blocco = preg_replace('/[^a-z0-9_]/i', '', strtolower((string) $blocco));

        // Usa funzione centralizzata (single source of truth)
        $visibilita = getProtocolloEmailVisibility();

        // Mappa blocchi legacy ai nuovi permessi
        if ($blocco === 'generale') {
            return $visibilita['generale'];
        }

        if ($blocco === 'singole_commesse' || $blocco === 'commessa') {
            return $visibilita['commesse'];
        }

        // Per retrocompatibilità: solo_proprie_commesse e solo_proprie non più gestiti
        // (mantenuti per non rompere codice esistente, ma sempre false)
        if ($blocco === 'solo_proprie_commesse' || $blocco === 'solo_proprie') {
            return false; // Non più supportato, sempre false
        }

        // Default: negato se blocco non riconosciuto
        return false;
    }

    public static function isGeneraleCode($code)
    {
        $generali = ['gar', 'amm', 'off', 'acq', 'hrr', 'sqq', 'gco', 'con'];
        return in_array(strtolower((string) $code), $generali, true);
    }

}
