<?php
namespace Services;

if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}

class NotificationService
{
    // === EMAIL HANDLING ===

    /**
     * Invia una email usando PHPMailer (configurazione centralizzata)
     */
    public static function sendEmail(string|array $to, string $subject, string $htmlBody, array $attachments = [], $cc = []): array
    {
        if (!defined('ROOT'))
            define('ROOT', $_SERVER['DOCUMENT_ROOT']); // Safety check

        require_once(ROOT . '/IntLibs/PHPMailer/src/Exception.php');
        require_once(ROOT . '/IntLibs/PHPMailer/src/PHPMailer.php');
        require_once(ROOT . '/IntLibs/PHPMailer/src/SMTP.php');

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';

        try {
            // Configurazione SMTP da .env (nessun fallback hardcoded)
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USERNAME') ?: getenv('SMTP_USER');
            $mail->Password = getenv('SMTP_PASSWORD') ?: getenv('SMTP_PASS');
            $mail->SMTPSecure = getenv('SMTP_SECURE') ?: 'tls';
            $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);

            if (!$mail->Host || !$mail->Username || !$mail->Password) {
                return ['success' => false, 'message' => 'Configurazione SMTP mancante nel .env'];
            }

            // Mittente
            $fromEmail = getenv('SMTP_FROM_EMAIL');
            $fromName = getenv('SMTP_FROM_NAME') ?: 'Intranet';
            $mail->setFrom($fromEmail, $fromName);

            // Destinatari
            if (is_string($to)) {
                $to = array_filter(array_map('trim', explode(',', $to)));
            }
            foreach ($to as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $mail->addAddress($email);
                }
            }

            // CC
            if (!empty($cc)) {
                if (is_string($cc))
                    $cc = array_filter(array_map('trim', explode(',', $cc)));
                foreach ($cc as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $mail->addCC($email);
                    }
                }
            }

            // Allegati
            foreach ($attachments as $path) {
                if (file_exists($path)) {
                    $mail->addAttachment($path);
                }
            }

            // Contenuto
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $htmlBody));

            $mail->send();
            return ['success' => true, 'message' => 'Email inviata'];

        } catch (\Exception $e) {
            $err = $mail->ErrorInfo ?: $e->getMessage();
            error_log("Errore invio email NotificationService: " . $err);
            return ['success' => false, 'message' => 'Errore invio: ' . $err];
        }
    }

    /**
     * Salva una voce nel log delle notifiche
     */
    private static function logNotification(string $formName, string $eventType, string $recipient, string $channel, ?string $subject, ?string $body, string $status, ?string $errorMessage = null)
    {
        global $database;
        try {
            $sql = "INSERT INTO notification_logs (form_name, event_type, recipient, channel, subject, body, status, error_message, created_at)
                    VALUES (:fn, :et, :rc, :ch, :sub, :body, :st, :err, NOW())";
            $database->query($sql, [
                ':fn' => $formName,
                ':et' => $eventType,
                ':rc' => $recipient,
                ':ch' => $channel,
                ':sub' => $subject,
                ':body' => $body,
                ':st' => $status,
                ':err' => $errorMessage
            ], __FILE__);
        } catch (\Throwable $e) {
            error_log("[NotificationService] Errore salvataggio log: " . $e->getMessage());
        }
    }

    // === RULE ENGINE ===

    /**
     * Controlla ed esegue le regole di notifica per un dato trigger
     * @param string $formName es. 'segnalazione_it'
     * @param string $event es. 'on_submit', 'on_status_change'
     * @param array $recordData Dati del record (per placeholder e destinatari dinamici)
     */
    public static function processRules(string $formName, string $event, array $recordData)
    {
        global $database;

        // 1. Carica regole attive per il form
        $stmt = $database->query(
            "SELECT * FROM notification_rules WHERE form_name = :fn AND enabled = 1",
            [':fn' => $formName],
            __FILE__
        );
        $rules = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        error_log("[NotificationService] Processing rules for $formName, event $event. Found " . count($rules) . " rules.");

        if (!$rules)
            return;

        // Arricchimento dati record per placeholder
        if (!isset($recordData['now']))
            $recordData['now'] = date('d/m/Y H:i');
        if (!isset($recordData['attore'])) {
            $recordData['attore'] = $database->getNominativoByUserId((int) ($_SESSION['user_id'] ?? 0)) ?? 'Sistema';
        }
        // Se autore non è settato o è l'attore (vecchia logica), prova a risolverlo dal creatore reale del record
        if (!isset($recordData['autore_id']) && isset($recordData['submitted_by'])) {
            $recordData['autore_id'] = (int) $recordData['submitted_by'];
        }

        $autoreId = (int) ($recordData['autore_id'] ?? 0);
        if ($autoreId > 0) {
            $recordData['autore'] = $database->getNominativoByUserId($autoreId) ?? 'N/D';
        }

        foreach ($rules as $rule) {
            // Parsing JSON fields e normalizzazione
            $eventsRaw = json_decode($rule['events'] ?? '[]', true) ?? [];
            $events = [];
            if (is_array($eventsRaw)) {
                // Compatibilità per PHP < 8.1 (senza array_is_list)
                $isList = (array_keys($eventsRaw) === range(0, count($eventsRaw) - 1));
                if (empty($eventsRaw) || $isList) {
                    $events = $eventsRaw;
                } else {
                    $events = array_keys(array_filter($eventsRaw));
                }
            }

            if (!in_array($event, $events))
                continue;

            // Channels: ["email"] o {"email":true}
            $channelsRaw = json_decode($rule['channels'] ?? '[]', true) ?? [];
            $channels = [];
            if (is_array($channelsRaw)) {
                $isList = (array_keys($channelsRaw) === range(0, count($channelsRaw) - 1));
                if (empty($channelsRaw) || $isList) {
                    $channels = $channelsRaw;
                } else {
                    $channels = array_keys(array_filter($channelsRaw));
                }
            }

            if (empty($channels))
                continue;

            // Recipients: ["email:.."] oppure {"responsabile":true, "custom_email_value":"..."}
            $recipientsRaw = json_decode($rule['recipients'] ?? '[]', true) ?? [];
            $recipientsDef = [];

            $isList = (is_array($recipientsRaw) && (empty($recipientsRaw) || array_keys($recipientsRaw) === range(0, count($recipientsRaw) - 1)));
            if ($isList) {
                $recipientsDef = $recipientsRaw;
            } else {
                // Converti oggetto flag in elenco definizioni standard
                // Esempio: {"responsabile":true, "custom_email":true, "custom_email_value":"x@y.z"}
                if (!empty($recipientsRaw['responsabile']))
                    $recipientsDef[] = 'responsabile';
                if (!empty($recipientsRaw['assegnatario']))
                    $recipientsDef[] = 'assegnatario';
                if (!empty($recipientsRaw['creatore']) || !empty($recipientsRaw['autore']))
                    $recipientsDef[] = 'autore';
                if (!empty($recipientsRaw['custom_email']) && !empty($recipientsRaw['custom_email_value'])) {
                    $recipientsDef[] = 'email:' . $recipientsRaw['custom_email_value'];
                }
                // Gestione altri user specifici se salvati (es. user_ids o roles)
            }

            $messagesDef = json_decode($rule['messages'] ?? '{}', true) ?? [];

            // Risoluzione Destinatari (restituisce array di email o user_id)
            $targets = self::resolveRecipients($recipientsDef, $recordData, $formName);

            // Regola globale: Escludi l'attore (chi compie l'azione non riceve la notifica)
            // ECCEZIONE: Se è l'invio iniziale (on_submit), permettiamo all'autore di ricevere email (conferma)
            $actorId = (int) ($_SESSION['user_id'] ?? 0);
            if ($actorId > 0 && $event !== 'on_submit') {
                $targets = array_filter($targets, function ($t) use ($actorId, $database) {
                    if (is_numeric($t) && (int) $t === $actorId)
                        return false;
                    // Se è una stringa (email), risolvi user_id per confrontare
                    if (is_string($t) && strpos($t, '@') !== false) {
                        $uid = $database->query("SELECT id FROM users WHERE email = :e LIMIT 1", [':e' => $t])->fetchColumn();
                        if ($uid && (int) $uid === $actorId)
                            return false;
                    }
                    return true;
                });
            }

            if (empty($targets)) {
                error_log("[NotificationService] No targets resolved for event $event (after actor exclusion)");
                continue;
            }

            error_log("[NotificationService] Resolved targets (after actor exclusion): " . json_encode($targets));

            error_log("[Notification] From: $formName | Event: $event | Targets: " . json_encode($targets));

            // Preparazione Messaggio (Normalizzazione chiavi da frontend JS)
            $rawSubject = $messagesDef['email_subject'] ?? $messagesDef['subject'] ?? 'Notifica da {form_name}';
            $rawBody = $messagesDef['email_body'] ?? $messagesDef['body'] ?? '';
            $rawInApp = $messagesDef['in_app_message'] ?? $rawSubject;

            $subject = self::replacePlaceholders($rawSubject, $recordData, $formName);
            $body = self::replacePlaceholders($rawBody, $recordData, $formName);
            $notificaMsg = self::replacePlaceholders($rawInApp, $recordData, $formName);

            // Gestione Template Email
            if (in_array('email', $channels)) {
                $templateName = $messagesDef['email_template'] ?? 'base_template';
                if ($templateName !== 'none') {
                    $body = self::renderEmailTemplate($templateName, [
                        'header_title' => $subject,
                        'content' => $body,
                        'record_table' => self::renderRecordTable($recordData, $formName),
                        'view_url' => self::getRecordUrl($formName, $recordData['id'] ?? ''),
                        'year' => date('Y')
                    ]);
                }
            }

            // Invio
            if (in_array('email', $channels)) {
                // Filtra solo email valide
                $emails = [];
                foreach ($targets as $t) {
                    if (strpos($t, '@') !== false)
                        $emails[] = $t;
                    elseif (is_numeric($t)) {
                        // Risolvi email da user_id (Tabella users)
                        $email = $database->query("SELECT email FROM users WHERE id=:id", [':id' => $t])->fetchColumn();
                        // Fallback su personale se users non ha email
                        if (!$email) {
                            $email = $database->query("SELECT Email FROM personale WHERE user_id=:id", [':id' => $t])->fetchColumn();
                        }
                        if ($email)
                            $emails[] = $email;
                    }
                }
                if (!empty($emails)) {
                    $res = self::sendEmail($emails, $subject, $body);
                    foreach ($emails as $email) {
                        self::logNotification($formName, $event, $email, 'email', $subject, $body, $res['success'] ? 'sent' : 'error', $res['message'] ?? null);
                    }
                }
            }

            if (in_array('in_app', $channels)) {
                // Filtra solo user_id
                foreach ($targets as $t) {
                    if (is_numeric($t)) {
                        $link = "index.php?section=collaborazione&page=form_viewer&form_name=" . urlencode($formName) . "&id=" . ($recordData['id'] ?? '');
                        $res = self::inviaNotifica((int) $t, $notificaMsg, $link);
                        self::logNotification($formName, $event, (string) $t, 'in_app', null, $notificaMsg, $res['success'] ? 'sent' : 'error', $res['message'] ?? null);
                    }
                }
            }
        }
    }

    private static function resolveRecipients(array $definitions, array $data, string $formName = '')
    {
        global $database;
        $finalList = []; // Array misto: email stringhe e user_id interi

        // Cache form info se serve risolvere "responsabile"
        $formInfo = null;
        $getFormInfo = function () use (&$formInfo, $database, $formName) {
            if ($formInfo === null && $formName) {
                // BUGFIX: Tabella forms usa 'name' non 'form_name' e 'responsabile' non 'responsabile_id'
                $stmt = $database->query("SELECT responsabile, created_by FROM forms WHERE name = :fn LIMIT 1", [':fn' => $formName]);
                $formInfo = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            }
            return $formInfo;
        };

        foreach ($definitions as $def) {
            // Esempi struttura $def:
            // "user:123", "email:test@test.com", "field:email_utente"
            // "responsabile", "assegnatario"

            $type = '';
            $value = '';

            if (is_string($def)) {
                if (strpos($def, ':') !== false) {
                    $parts = explode(':', $def, 2);
                    $type = $parts[0];
                    $value = $parts[1] ?? '';
                } else {
                    $type = $def; // es. "responsabile"
                }
            } elseif (is_array($def)) {
                $type = $def['type'] ?? '';
                $value = $def['value'] ?? ($def['id'] ?? '');
            }

            switch ($type) {
                case 'fixed_email':
                case 'email':
                    if (filter_var($value, FILTER_VALIDATE_EMAIL))
                        $finalList[] = $value;
                    break;
                case 'user':
                    if (is_numeric($value))
                        $finalList[] = (int) $value;
                    break;
                case 'field_email':
                case 'field': // Campo del form contenente email o user_id
                    if (isset($data[$value])) {
                        $val = $data[$value];
                        // Se il campo contiene email
                        if (filter_var($val, FILTER_VALIDATE_EMAIL))
                            $finalList[] = $val;
                        // Se il campo è user_id (es. assegnato_a)
                        elseif (is_numeric($val))
                            $finalList[] = (int) $val;
                    }
                    break;
                case 'autore':
                case 'creatore':
                case 'creator': // Autore record
                    if (!empty($data['submitted_by']))
                        $finalList[] = (int) $data['submitted_by'];
                    break;

                case 'assegnatario': // Shortcut per field:assegnato_a
                    if (!empty($data['assegnato_a'])) {
                        $assegnatiIds = array_filter(explode(',', (string) $data['assegnato_a']));
                        foreach ($assegnatiIds as $val) {
                            $val = trim($val);
                            if (is_numeric($val)) {
                                $finalList[] = (int) $val;
                            } else {
                                // Prova a risolvere da nominativo
                                $uid = $database->query("SELECT user_id FROM personale WHERE Nominativo = ? LIMIT 1", [$val])->fetchColumn();
                                if ($uid)
                                    $finalList[] = (int) $uid;
                            }
                        }
                    }
                    break;

                case 'responsabile': // Responsabile del form (da tabella forms)
                    $info = $getFormInfo();
                    if (!empty($info['responsabile'])) {
                        $responsabiliIds = array_filter(explode(',', (string) $info['responsabile']));
                        foreach ($responsabiliIds as $val) {
                            $val = trim($val);
                            if (is_numeric($val)) {
                                $finalList[] = (int) $val;
                            } else {
                                $uid = $database->query("SELECT user_id FROM personale WHERE Nominativo = ? LIMIT 1", [$val])->fetchColumn();
                                if ($uid)
                                    $finalList[] = (int) $uid;
                            }
                        }
                    }
                    break;
            }
        }
        return array_unique($finalList);
    }

    private static function replacePlaceholders(string $text, array $data, string $formName)
    {
        $text = str_replace('{form_name}', $formName, $text);
        $text = str_replace('{id}', $data['id'] ?? '', $text);
        $text = str_replace('{link}', self::getRecordUrl($formName, $data['id'] ?? ''), $text);
        $text = str_replace('{record_table}', self::renderRecordTable($data, $formName), $text);

        foreach ($data as $k => $v) {
            if (is_string($v) || is_numeric($v)) {
                $text = str_replace('{' . $k . '}', (string) $v, $text);
            }
        }
        return $text;
    }

    private static function getRecordUrl(string $formName, $id): string
    {
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
        return $baseUrl . "/index.php?section=collaborazione&page=form_viewer&form_name=" . urlencode($formName) . "&id=" . $id;
    }

    private static function renderRecordTable(array $data, string $formName): string
    {
        global $database;
        $stmt = $database->query("SELECT field_name, field_label FROM form_fields WHERE form_id = (SELECT id FROM forms WHERE name = :fn LIMIT 1)", [':fn' => $formName]);
        $labels = [];
        if ($stmt) {
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $labels[$row['field_name']] = $row['field_label'] ?: $row['field_name'];
            }
        }

        $html = '<div class="record-info"><table style="width:100%; border-collapse: collapse;">';
        $exclude = ['id', 'submitted_by', 'created_at', 'updated_at', 'now', 'autore'];
        foreach ($data as $k => $v) {
            if (in_array($k, $exclude) || empty($v) || is_array($v) || is_object($v))
                continue;
            $label = $labels[$k] ?? ucwords(str_replace('_', ' ', $k));
            $html .= "<tr><td style=\"font-weight:bold; color:#555; width:150px; padding:5px 0;\">$label:</td><td style=\"padding:5px 0;\">$v</td></tr>";
        }
        $html .= '</table></div>';
        return $html;
    }

    private static function renderEmailTemplate(string $templateName, array $placeholders): string
    {
        if ($templateName === 'minimal_template') {
            $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; color: #444; }
        .container { border: 1px solid #ccc; padding: 20px; border-radius: 5px; }
        .footer { font-size: 11px; color: #999; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h3>{header_title}</h3>
        <div>{content}</div>
        <div class="footer">
            Notifica automatica Intranet
        </div>
    </div>
</body>
</html>';
        } else {
            // Default: base_template
            $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f7f9; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .header { background-color: #3498db; color: #ffffff; padding: 25px; text-align: center; }
        .header h1 { margin: 0; font-size: 22px; font-weight: 600; }
        .content { padding: 30px; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #888; background-color: #f9f9f9; border-top: 1px solid #eee; }
        .button { display: inline-block; padding: 12px 25px; background-color: #3498db; color: #ffffff !important; text-decoration: none; border-radius: 5px; font-weight: 600; margin-top: 20px; }
        .record-info { background-color: #f8f9fa; border-left: 4px solid #3498db; padding: 15px; margin: 20px 0; font-size: 14px; }
        .record-info table { width: 100%; border-collapse: collapse; }
        .record-info td { padding: 5px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header"><h1>{header_title}</h1></div>
        <div class="content">
            {content}
            <div style="text-align: center; margin-top: 30px;">
                <a href="{view_url}" class="button">Visualizza Dettagli del Record</a>
            </div>
        </div>
        <div class="footer">
            <p>Questa è una notifica automatica dal sistema Intranet.</p>
            <p>&copy; {year} Incide Engineering S.r.l. - Tutti i diritti riservati.</p>
        </div>
    </div>
</body>
</html>';
        }

        foreach ($placeholders as $k => $v) {
            $html = str_replace('{' . $k . '}', (string) $v, $html);
        }

        return $html;
    }

    public static function getUnread($user_id)
    {
        global $database;

        if (!$user_id)
            return [];

        $res = $database->query("
            select id, messaggio, link, creato_il 
            from notifiche 
            where user_id = :uid and letto = 0 
            order by creato_il desc limit 20
        ", ['uid' => $user_id], __FILE__);

        return $res instanceof \PDOStatement ? $res->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public static function inviaNotifica($user_id, $messaggio, $link = null)
    {
        global $database;

        if (!$user_id || !$messaggio) {
            return false;
        }

        // Imposta il limite massimo (deve corrispondere a quello in DB)
        $maxLength = 1000; // Adatta se la colonna è diversa
        $messaggioTroncato = false;
        if (mb_strlen($messaggio, 'UTF-8') > $maxLength) {
            $messaggio = mb_substr($messaggio, 0, $maxLength - 3, 'UTF-8') . '...';
            $messaggioTroncato = true;
        }

        $params = [
            'user_id' => $user_id,
            'messaggio' => $messaggio,
            'link' => $link
        ];

        $sql = "insert into notifiche (user_id, messaggio, link, creato_il)
                values (:user_id, :messaggio, :link, now())";

        $database->query($sql, $params, __FILE__);

        return [
            'success' => true,
            'troncato' => $messaggioTroncato,
            'message' => $messaggioTroncato
                ? 'Messaggio troppo lungo, è stato troncato automaticamente.'
                : 'Notifica inviata con successo.'
        ];
    }

    public static function markAsRead($id, $user_id)
    {
        global $database;

        if (!$id || !$user_id)
            return false;

        $sql = "UPDATE notifiche SET letto = 1 WHERE id = :id AND user_id = :uid";
        $params = ['id' => $id, 'uid' => $user_id];
        $database->query($sql, $params, __FILE__);

        return true;
    }

    public static function getAll($user_id)
    {
        global $database;

        if (!$user_id)
            return [];

        $res = $database->query("
            SELECT id, messaggio, link, creato_il, letto, pinned
            FROM notifiche
            WHERE user_id = :uid
            ORDER BY pinned DESC, creato_il DESC
        ", ['uid' => $user_id], __FILE__);

        return $res instanceof \PDOStatement ? $res->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public static function togglePin($id, $user_id)
    {
        global $database;
        $n = $database->query("SELECT pinned FROM notifiche WHERE id = :id AND user_id = :uid", ['id' => $id, 'uid' => $user_id], __FILE__)->fetch();
        $val = ($n && $n['pinned']) ? 0 : 1;
        $database->query("UPDATE notifiche SET pinned = :val WHERE id = :id AND user_id = :uid", ['val' => $val, 'id' => $id, 'uid' => $user_id], __FILE__);
        return true;
    }

    public static function delete($id, $user_id)
    {
        global $database;
        $database->query("DELETE FROM notifiche WHERE id = :id AND user_id = :uid", ['id' => $id, 'uid' => $user_id], __FILE__);
        return true;
    }

    public static function markAllAsRead($user_id)
    {
        global $database;
        $database->query("UPDATE notifiche SET letto = 1 WHERE user_id = :uid", ['uid' => $user_id], __FILE__);
        return true;
    }

    public static function deleteAll($user_id)
    {
        global $database;
        $database->query("DELETE FROM notifiche WHERE user_id = :uid", ['uid' => $user_id], __FILE__);
        return true;
    }

    public static function getUltime($user_id, $limit = 5)
    {
        global $database;

        if (!$user_id)
            return [];

        $limit = intval($limit);
        if ($limit < 1 || $limit > 20)
            $limit = 5;

        $res = $database->query("
            select id, messaggio, link, creato_il, letto
            from notifiche
            where user_id = :uid
            order by creato_il desc
            limit $limit
        ", ['uid' => $user_id], __FILE__);

        $dati = $res instanceof \PDOStatement ? $res->fetchAll(\PDO::FETCH_ASSOC) : [];
        foreach ($dati as &$n) {
            $n['messaggio'] = htmlspecialchars($n['messaggio']);
        }
        return $dati;
    }

}

