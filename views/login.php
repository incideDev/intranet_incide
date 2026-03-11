<?php
header("Strict-Transport-Security: max-age=63072000; includeSubDomains; preload");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*require_once __DIR__ . '/../config/database.php';*/

if (!isset($pdo)) {
    error_log('Connessione al database non riuscita.');
}

global $pdo;

// Verifica del token "Ricordami"
if (!isset($_SESSION['user_id']) && isset($_COOKIE['auth_token'])) {
    $token = $_COOKIE['auth_token'];

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE auth_token = :auth_token");
        $stmt->execute([':auth_token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Ripristina la sessione
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['profile_picture'] = $user['profile_picture'];

            // Rigenera il token e aggiorna il cookie
            $newToken = bin2hex(random_bytes(32));
            setcookie('auth_token', $newToken, [
                'expires' => time() + (86400 * 30),
                'path' => '/',
                'secure' => false, // Cambia in true se usi HTTPS
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $stmt = $pdo->prepare("UPDATE users SET auth_token = :new_token WHERE id = :id");
            $stmt->execute([':new_token' => $newToken, ':id' => $user['id']]);

            // Reindirizza alla home
            header('Location: index.php?page=home');
            exit;
        } else {
            // Token non valido, rimuovi il cookie
            setcookie('auth_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    } catch (PDOException $e) {
        error_log('Errore durante la verifica del token "Ricordami": ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']); // Verifica se "Ricordami" è stato selezionato

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // Imposta i dettagli della sessione
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['profile_picture'] = $user['profile_picture'];

            // Gestione del "Ricordami"
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                setcookie('auth_token', $token, [
                    'expires' => time() + (86400 * 30),
                    'path' => '/',
                    'secure' => false, // Cambia in true se usi HTTPS
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                $stmt = $pdo->prepare("UPDATE users SET auth_token = :token WHERE id = :id");
                $stmt->execute([':token' => $token, ':id' => $user['id']]);
            } else {
                // Rimuovi eventuali cookie
                setcookie('auth_token', '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => false,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }

            // Reindirizza in base alle condizioni
            if ($user['password_reset_required']) {
                header('Location: index.php?page=change_password');
            } else {
                header('Location: index.php?page=home');
            }
            exit;
        } else {
            $error_message = 'Credenziali non valide, riprova.';
        }
    } catch (PDOException $e) {
        $error_message = 'Errore del server, riprovare più tardi.';
        error_log('Errore login: ' . $e->getMessage());
    }
}

            // Preleva i valori dai cookie, se esistono
            $username_cookie = $_COOKIE['username'] ?? '';
            ?>

            <title>Login</title>

            <div class="background-container">
                <img src="assets/logo/logo_shaping_innovation-min.png" alt="Shaping Innovation" class="svg-overlay">
                <div class="login-container">
                    <img src="./assets/logo/logo_incide_engineering.png" alt="Logo Incide Engineering" class="login-logo">
                    <!-- <h2>Benvenuto</h2> --> 
                    <form action="/" method="post" class="login-form">
                        <?php if (!empty($error_message)): ?>
                            <div class="error-message">
                                <?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php endif; ?>

                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" autocomplete="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>

                        <label for="password">Password:</label>
                        <div class="password-container">
                <input type="password" id="password" name="password" autocomplete="current-password" class="password-input" required>
                <img src="assets/icons/show.png" alt="Mostra Password" class="toggle-password-icon" id="togglePassword">
            </div>

            <div class="remember-me">
                <input type="checkbox" name="remember" id="remember">
                <label for="remember">Ricordami</label>
            </div>
            
            <button type="submit" class="button" id="login">Login</button>
        </form>
    </div>
</div>

<?php
// Log di debug solo in modalità sviluppo
if (defined('DEBUG') && DEBUG) {
    var_dump($_SESSION);
}
?>

<script src="assets/js/login.js" defer></script>

