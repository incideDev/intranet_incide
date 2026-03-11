<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include("page-errors/404.php");
    die();
}
if ($database->LockedTime() > 0) {
    header("Location: /systemlock");
}

// Gestione azioni "Bentornato"
$rememberAction = filter_input(INPUT_POST, 'remember_action', FILTER_SANITIZE_SPECIAL_CHARS);
$rememberCandidate = null;

// Azione "Non sei tu?" - cancella cookie e token
if ($rememberAction === 'forget') {
    clearRememberToken();
    // Redirect per rimuovere POST dalla history
    header("Location: /");
    exit;
}

// Azione "Accedi" dal bentornato - esegue auto-login
if ($rememberAction === 'quicklogin') {
    if (tryAutoLoginFromRememberCookie()) {
        header("Location: /index.php?section=home&page=home");
        exit;
    }
    // Se fallisce, mostra login normale
}

// Verifica se c'è un utente "candidato" per bentornato
if (!$Session->logged_in) {
    $rememberCandidate = getRememberCandidateUser();
}

// DEBUG TEMPORANEO - rimuovere dopo test
if (isset($_GET['debug_remember'])) {
    echo '<pre style="background:#fff;padding:10px;margin:10px;">';
    echo 'Cookie remember_me: ' . (isset($_COOKIE['remember_me']) ? htmlspecialchars($_COOKIE['remember_me']) : 'NON PRESENTE') . "\n";
    echo 'Session logged_in: ' . ($Session->logged_in ? 'true' : 'false') . "\n";
    echo 'rememberCandidate: ';
    print_r($rememberCandidate);
    echo '</pre>';
}
?>
<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intranet Aziendale - Login</title>
    <link rel="preload" href="/assets/css/styles.css" as="style">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <link rel="stylesheet" href="/assets/css/buttons.css">
    <link rel="icon" href="/assets/favicon/incide.ico" type="image/x-icon">
</head>

<body>
    <?php
    $SubmitLogin = filter_input(INPUT_POST, 'subLogin', FILTER_SANITIZE_SPECIAL_CHARS);
    if ($SubmitLogin !== null && $SubmitLogin == 'Login') {
        $data = filter_input_array(INPUT_POST, [
            "user" => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
            "pass" => FILTER_UNSAFE_RAW,
            "remember" => FILTER_SANITIZE_NUMBER_INT,
        ]);
        /* Login attempt */
        $retval = $Session->login($data);
        if ($retval) {
            //connessione riuscita senza errori
            if (!isset($retval["error"])) {
                header("Location: /index.php?section=home&page=home");
                exit;
            } else {
                $error_message = null;
                foreach ($retval["error"] as $er) {
                    $error_message .= " " . $er;
                }
            }
        }
    }
    ?>

    <div class="background-container home-background">
        <img src="/assets/logo/logo_shaping_innovation-min.png" alt="Shaping Innovation" class="svg-overlay">
        <div class="login-container">
            <img src="/assets/logo/logo_incide_engineering.png" alt="Logo Incide Engineering" class="login-logo">
            <?php if (defined('APP_ENV') && APP_ENV === 'dev'): ?>
                <h2>Sviluppo</h2>
            <?php endif; ?>

            <?php if ($rememberCandidate !== null): ?>
                <!-- ========== SCHERMATA BENTORNATO ========== -->
                <div class="welcome-back-container">
                    <?php
                    $displayName = !empty($rememberCandidate['ragsoc'])
                        ? htmlspecialchars($rememberCandidate['ragsoc'])
                        : htmlspecialchars($rememberCandidate['username']);

                    $imgPath = 'assets/images/default_profile.png';

                    if (!empty($rememberCandidate['id'])) {
                        require_once(substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/MainPage')) . '/core/functions.php');

                        $stmt2 = $database->query(
                            "SELECT Nominativo FROM personale WHERE user_id = ?",
                            [$rememberCandidate['id']],
                            __FILE__
                        );
                        $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);

                        $nominativo = $row2 ? ($row2['Nominativo'] ?? null) : null;

                        if (!empty($nominativo)) {
                            $imgPath = getProfileImage($nominativo, 'nominativo', 'assets/images/default_profile.png');
                        }
                    }

                    $profileImg = '/' . ltrim($imgPath, '/');
                    ?>
                    <div class="welcome-back-avatar">
                        <img src="<?php echo htmlspecialchars($profileImg); ?>" alt="Avatar" class="welcome-back-img">
                    </div>

                    <p class="welcome-back-greeting">Bentornato,</p>
                    <p class="welcome-back-name"><?php echo $displayName; ?></p>

                    <form action="/" method="post" class="welcome-back-form">
                        <input type="hidden" name="remember_action" value="quicklogin">
                        <button type="submit" class="button welcome-back-btn">Accedi</button>
                    </form>

                    <form action="/" method="post" class="welcome-back-forget">
                        <input type="hidden" name="remember_action" value="forget">
                        <button type="submit" class="welcome-back-link">Non sei tu?</button>
                    </form>
                </div>

            <?php else: ?>
                <!-- ========== FORM LOGIN NORMALE ========== -->
                <form action="/" method="post" class="login-form">
                    <?php if (!empty($error_message)): ?>
                        <div class="error-message">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <label for="username">Username:</label>
                    <input type="text" id="username" name="user" autocomplete="username" value="<?php
                    echo (isset($data["user"]) && !empty($data["user"]) ? htmlspecialchars($data["user"]) : ''); ?>"
                        required />

                    <label for="password">Password:</label>
                    <div class="password-container">
                        <input type="password" id="password" name="pass" autocomplete="current-password"
                            class="password-input" required />
                        <img src="/assets/icons/show.png" alt="Mostra Password" class="toggle-password-icon"
                            id="togglePassword" />
                    </div>

                    <div class="remember-me">
                        <input type="checkbox" name="remember" id="remember" value="1">
                        <label for="remember">Ricordami</label>
                    </div>

                    <button type="submit" class="button" id="login" name="subLogin" value="Login">LOGIN</button>
                </form>
            <?php endif; ?>

        </div>
    </div>

    <?php
    // Log di debug solo in modalità sviluppo
    if (defined('DEBUG') && DEBUG) {
        var_dump($_SESSION);
    }
    ?>

    <script src="/assets/js/login.js?v=20251223_1" defer></script>
</body>

</html>
