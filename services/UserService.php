<?php
namespace Services;

class UserService
{

    public static function getUserList()
    {
        global $database;
        $res = $database->query("
            SELECT u.id as user_id, u.email as Email_Aziendale, p.Nominativo, u.attivato_il
            FROM users u
            LEFT JOIN personale p ON u.id = p.user_id
            ORDER BY p.Nominativo ASC
        ", [], __FILE__);
        $users = $res->fetchAll(\PDO::FETCH_ASSOC);
        return ['success' => true, 'users' => $users];
    }

    public static function updateEmail($userId, $email)
    {
        global $database;

        $userId = intval($userId);
        $email = trim($email);
        if (!$userId || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Email non valida.'];
        }
        $database->query("UPDATE users SET email = ? WHERE id = ?", [$email, $userId], __FILE__);
        return ['success' => true];
    }

    public static function changePassword($userId, $currentPassword, $newPassword, $confirmPassword)
    {
        global $database;

        // Sanificazione
        $userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);
        $currentPassword = is_string($currentPassword) ? trim($currentPassword) : '';
        $newPassword = is_string($newPassword) ? trim($newPassword) : '';
        $confirmPassword = is_string($confirmPassword) ? trim($confirmPassword) : '';

        if (!$userId || !$currentPassword || !$newPassword || !$confirmPassword) {
            return ['success' => false, 'message' => 'Dati mancanti o non validi.'];
        }

        if ($newPassword !== $confirmPassword) {
            return ['success' => false, 'message' => 'Le nuove password non coincidono.'];
        }

        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $newPassword)) {
            return ['success' => false, 'message' => 'La password deve contenere almeno 8 caratteri, una maiuscola, una minuscola e un numero.'];
        }

        // Recupero dell'hash attuale
        $query = "SELECT password FROM users WHERE id = ?";
        $stmt = $database->query($query, [$userId], __FILE__);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !isset($user['password']) || !is_string($user['password'])) {
            return ['success' => false, 'message' => 'Errore nel recupero delle credenziali.'];
        }

        // Verifica password attuale
        if (!password_verify($currentPassword, $user['password'])) {
            return ['success' => false, 'message' => 'La password attuale non è corretta.'];
        }

        // Verifica che la nuova password sia diversa
        if (password_verify($newPassword, $user['password'])) {
            return ['success' => false, 'message' => 'La nuova password deve essere diversa da quella attuale.'];
        }

        // Salvataggio nuova password
        $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateQuery = "UPDATE users SET password = ? WHERE id = ?";
        $database->query($updateQuery, [$newHashedPassword, $userId], __FILE__);

        return ['success' => true, 'message' => 'Password aggiornata con successo.'];
    }

    public static function inviaInvito($userId)
    {
        global $database;
        $userId = intval($userId);

        $stmt = $database->query("SELECT id, email, username FROM users WHERE id = ?", [$userId], __FILE__);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !$user['email']) {
            return ['success' => false, 'message' => 'Utente o email non trovati.'];
        }

        $token = bin2hex(random_bytes(24));
        $database->query("UPDATE users SET temp_key = ? WHERE id = ?", [$token, $userId], __FILE__);

        // --- primo accesso ---
        $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST'] . '/index.php?page=primo_accesso&token=' . $token;

        // Recupera nominativo reale da personale, se disponibile
        $nominativo = '';
        $stmtPers = $database->query(
            "SELECT Nominativo FROM personale WHERE user_id = ? LIMIT 1",
            [$user['id']],
            __FILE__
        );
        if ($row = $stmtPers->fetch(\PDO::FETCH_ASSOC)) {
            $nominativo = $row['Nominativo'];
        }

        $destinatario = $nominativo ?: ($user['username'] ?: $user['email']);

        $oggetto = "Benvenuto in Incide – Invito di accesso alla Intranet Aziendale";


        $logoPath = ROOT . '/assets/logo/logo_incide_engineering-xs.png';

        $logoHtml = '';
        if (file_exists($logoPath)) {
            $logoHtml = '<div style="text-align:center;margin-bottom:20px;">
                <img src="cid:logoIncide" style="height:32px;width:auto;opacity:0.85;margin-bottom:12px;" alt="Logo Incide">
            </div>';
        }

        $testo = '
        <!DOCTYPE html>
        <html lang="it">
        <head>
        <meta charset="UTF-8">
        </head>
        <body style="font-family:Arial,Helvetica,sans-serif; background:#f7f7f7; color:#222; margin:0; padding:0;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f7f7f7;">
            <tr>
            <td align="center">
                <table width="420" cellpadding="0" cellspacing="0" border="0" style="background:#fff; border-radius:8px; box-shadow:0 3px 18px #0001; margin:24px 0;">
                <tr>
                    <td style="padding:16px 32px 0 32px;">
                        ' . $logoHtml . '
                        <h2 style="color:rgb(205,33,29); margin:0 0 18px 0; font-size:22px; font-weight:bold; text-align:center;">
                            Benvenuto ' . htmlspecialchars($destinatario) . '!
                        </h2>
                        <p style="font-size:15px; margin:0 0 14px 0;">
                            Hai ricevuto un invito per accedere all’Intranet Aziendale di Incide.
                        </p>
                        <p style="font-size:15px; margin:0 0 16px 0;">
                            <b>Il tuo username personale è:</b>
                            <span style="display:inline-block;background:#eee;border-radius:4px;padding:4px 9px;margin-left:5px;font-family:monospace;letter-spacing:0.5px;color:#C00000;font-size:15px;">
                                ' . htmlspecialchars($user['username']) . '
                            </span>
                        </p>
                        <p style="font-size:15px; margin:0 0 18px 0;">
                            Per iniziare, clicca qui sotto e imposta la tua password personale:
                        </p>
                        <div style="text-align:center; margin-bottom:22px;">
                            <a href="' . htmlspecialchars($link) . '" style="
                                display:inline-block;
                                font-family:Arial,Helvetica,sans-serif;
                                font-size:10px;
                                color:#111;
                                background:#fff;
                                padding:5px 12px;
                                border-radius:5px;
                                text-align:center;
                                text-transform:uppercase;
                                border:1px solid #111;
                                box-sizing:border-box;
                                letter-spacing:0.1em;
                                vertical-align:middle;
                                margin:8px auto 0 auto;
                                text-decoration:none;
                                font-weight: bold;
                                ">
                                Attiva il tuo account
                            </a>
                        </div>
                        <p style="font-size:14px; color:#777; margin:0 0 10px 0;">
                            Il link è personale e valido per una sola attivazione.<br>
                            Se non sei stato tu a richiedere l’accesso puoi ignorare questa email.
                        </p>
                        <hr style="border:none; border-top:1px solid #eee; margin:16px 0;">
                        <p style="font-size:13px; color:#555;">
                            Hai bisogno di aiuto?<br>
                            <a href="mailto:marketing_2@incide.it" style="
                                color:#111;
                                text-decoration:none;
                                font-weight:bold;
                                border-bottom:1px dotted #d5d5d5;
                            " onmouseover="this.style.color=\'rgb(205,33,29)\'" onmouseout="this.style.color=\'#111\'">marketing_2@incide.it</a>
                        </p>
                        <p style="font-size:12px; color:#bbb; margin:16px 0 0 0;">
                            © ' . date('Y') . ' Incide Engineering. Tutti i diritti riservati.
                        </p>
                    </td>
                </tr>
                </table>
            </td>
            </tr>
        </table>
        </body>
        </html>
        ';

        $smtpHost = getenv('SMTP_HOST');
        if ($smtpHost === false || $smtpHost === '') {
            return ['success' => false, 'message' => 'Missing ENV: SMTP_HOST'];
        }
        $smtpPort = getenv('SMTP_PORT');
        if ($smtpPort === false || $smtpPort === '') {
            return ['success' => false, 'message' => 'Missing ENV: SMTP_PORT'];
        }
        $smtpUsername = getenv('SMTP_USERNAME');
        if ($smtpUsername === false || $smtpUsername === '') {
            return ['success' => false, 'message' => 'Missing ENV: SMTP_USERNAME'];
        }
        $smtpPassword = getenv('SMTP_PASSWORD');
        if ($smtpPassword === false || $smtpPassword === '') {
            return ['success' => false, 'message' => 'Missing ENV: SMTP_PASSWORD'];
        }
        $smtpSecure = getenv('SMTP_SECURE');
        if ($smtpSecure === false) {
            $smtpSecure = '';
        }
        $smtpFromEmail = getenv('SMTP_FROM_EMAIL');
        if ($smtpFromEmail === false || $smtpFromEmail === '') {
            return ['success' => false, 'message' => 'Missing ENV: SMTP_FROM_EMAIL'];
        }
        $smtpFromName = getenv('SMTP_FROM_NAME');
        if ($smtpFromName === false || $smtpFromName === '') {
            return ['success' => false, 'message' => 'Missing ENV: SMTP_FROM_NAME'];
        }
        $smtpCcDefault = getenv('SMTP_CC_DEFAULT');
        if ($smtpCcDefault === false) {
            $smtpCcDefault = '';
        }

        require_once(ROOT . '/IntLibs/PHPMailer/src/Exception.php');
        require_once(ROOT . '/IntLibs/PHPMailer/src/PHPMailer.php');
        require_once(ROOT . '/IntLibs/PHPMailer/src/SMTP.php');
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';

        try {
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUsername;
            $mail->Password = $smtpPassword;
            $mail->SMTPSecure = $smtpSecure;
            $mail->Port = (int) $smtpPort;

            $mail->setFrom($smtpFromEmail, $smtpFromName);
            $mail->addAddress($user['email'], $destinatario);
            if (file_exists($logoPath)) {
                $mail->addEmbeddedImage($logoPath, 'logoIncide');
            }
            $mail->isHTML(true);
            $mail->Subject = $oggetto;
            $mail->Body = $testo;
            $mail->AltBody = "Ciao $destinatario,\nHai ricevuto un invito per accedere all’Intranet Incide.\n"
                . "Per completare il primo accesso vai su: $link\n"
                . "Se non sei stato tu a richiedere l’accesso ignora questa email.";
            if ($smtpCcDefault !== '') {
                $mail->addCC($smtpCcDefault);
            }


            $mail->send();
            return ['success' => true, 'message' => 'Invito inviato con successo'];
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            $msg = "INVIO MAIL FALLITO<br>To: " . $user['email'] . "<br>Subject: " . $oggetto . "<br>Body:<br><pre>" . htmlspecialchars($testo) . "</pre><br>Errore: " . $mail->ErrorInfo;
            return ['success' => false, 'message' => $msg];
        }
    }

}
?>