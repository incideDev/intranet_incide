<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    die();
}
$token = $_GET['token'] ?? '';
$utente = null;
if ($token) {
    global $database;
    $stmt = $database->query("SELECT * FROM users WHERE temp_key = ?", [$token], __FILE__);
    $utente = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intranet Aziendale - Primo Accesso</title>
    <link rel="preload" href="/assets/css/styles.css" as="style">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="stylesheet" href="/assets/css/buttons.css">
    <link rel="icon" href="/assets/favicon/incide.ico" type="image/x-icon">
</head>

<body>
    <div class="background-container home-background">
        <img src="/assets/logo/logo_shaping_innovation-min.png" alt="Shaping Innovation" class="svg-overlay">
        <div class="login-container">
            <img src="/assets/logo/logo_incide_engineering.png" alt="Logo Incide Engineering" class="login-logo">

            <?php if (!$token): ?>
                <div class="error-message" style="margin-bottom:18px;">Token non fornito o link non valido.</div>
            <?php elseif (!$utente): ?>
                <div class="error-message" style="margin-bottom:18px;">Token non valido o già usato.</div>
            <?php else: ?>

                <?php
                $nominativo = null;
                $imgPath = 'assets/images/default_profile.png';
                if ($utente && isset($utente['id'])) {
                    require_once(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/MainPage')) . '/core/functions.php');
                    // recupero nominativo da personale
                    $stmt2 = $database->query("SELECT Nominativo FROM personale WHERE user_id = ?", [$utente['id']], __FILE__);
                    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                    $nominativo = $row2 ? $row2['Nominativo'] : null;
                    if ($nominativo) {
                        $imgPath = getProfileImage($nominativo, 'nominativo', 'assets/images/default_profile.png');
                    }
                }
                ?>
                <?php if ($utente): ?>
                    <div style="display: flex; justify-content: center; margin-bottom: 10px;">
                        <img src="/<?= htmlspecialchars($imgPath) ?>" alt="Immagine profilo"
                            style="width:66px; height:66px; border-radius:50%; border:2px solid #e3e3e3; object-fit:cover;">
                    </div>
                <?php endif; ?>

                <div style="margin-bottom:22px;">
                    <div style="font-size:20px;">
                        Benvenuto <b><?= htmlspecialchars($utente['username'] ?? $utente['email']) ?></b>!
                    </div>
                    <div style="font-size:15px; color:#333; margin-top:6px;">
                        Inserisci la tua nuova password per attivare l’account.
                    </div>
                </div>

                <form action="" method="post" class="login-form" autocomplete="off">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <label for="password">Nuova password:</label>
                    <div class="password-container">
                        <input type="password" id="password" name="password" class="password-input" autocomplete="off"
                            required minlength="8" />
                        <img src="/assets/icons/show.png" alt="Mostra Password" class="toggle-password-icon"
                            id="togglePassword" />
                    </div>

                    <label for="password2">Ripeti password:</label>
                    <div class="password-container">
                        <input type="password" id="password2" name="password2" class="password-input" autocomplete="off"
                            required minlength="8" />
                        <img src="/assets/icons/show.png" alt="Mostra Password" class="toggle-password-icon"
                            id="togglePassword2" />
                    </div>

                    <button type="submit" class="btn-submit" style="margin-top:14px;">Imposta password</button>
                </form>
                <?php
                // GESTIONE SUBMIT CAMBIO PASSWORD (solo esempio base, personalizza sicurezza/validazioni come vuoi)
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && isset($_POST['password']) && isset($_POST['password2'])) {
                    $pass = $_POST['password'];
                    $pass2 = $_POST['password2'];
                    if ($pass !== $pass2) {
                        echo '<div class="error-message" style="margin-top:10px;">Le password non coincidono.</div>';
                    } elseif (strlen($pass) < 8) {
                        echo '<div class="error-message" style="margin-top:10px;">La password deve essere almeno di 8 caratteri.</div>';
                    } else {
                        $userId = $utente['id'];
                        $newHash = password_hash($pass, PASSWORD_DEFAULT);
                        $database->query("UPDATE users SET password = ?, temp_key = NULL, attivato_il = NOW() WHERE id = ?", [$newHash, $userId], __FILE__);
                        echo '<div class="success-message" style="margin-top:10px;">
                            Password impostata! Ora puoi <a href="/index.php">accedere alla Home</a>.<br>
                            Verrai reindirizzato automaticamente tra pochi secondi...
                        </div>
                        <script>
                        setTimeout(function() {
                            window.location.href = "/index.php";
                        }, 3000);
                        </script>';
                    }
                }
                ?>
            <?php endif; ?>
        </div>
    </div>
    <script>
        // toggle show/hide password
        document.addEventListener('DOMContentLoaded', function () {
            function togglePassword(inputId, iconId) {
                const input = document.getElementById(inputId);
                const icon = document.getElementById(iconId);
                if (!input || !icon) return;
                icon.addEventListener('click', function () {
                    input.type = (input.type === 'password' ? 'text' : 'password');
                });
            }
            togglePassword('password', 'togglePassword');
            togglePassword('password2', 'togglePassword2');
        });
    </script>
</body>

</html>