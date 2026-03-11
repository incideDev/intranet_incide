<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php?page=login');
    exit;
}

// Include il file di configurazione del database
require_once __DIR__ . '/../config/database.php';

global $pdo;

// Verifica se l'utente è loggato
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

// Verifica se è necessario il cambio di password
$stmt = $pdo->prepare("SELECT password_reset_required FROM users WHERE username = ?");
$stmt->execute([$_SESSION['username']]);
$user = $stmt->fetch();

if (!$user || !$user['password_reset_required']) {
    header('Location: home.php');
    exit;
}

// Gestione del form di cambio password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error = 'Le password non coincidono, riprova.';
    } else {
        // Aggiorna la password nel database
        $stmt = $pdo->prepare("UPDATE users SET password = ?, password_reset_required = FALSE WHERE username = ?");
        $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $_SESSION['username']]);

        header('Location: index.php?page=home');
        exit;
    }
}
?>

    <div class="main-container">
        <div class="change-password-container">
            <h2>Cambia Password</h2>
            <?php if (!empty($error)) echo "<p>$error</p>"; ?>
            <form action="index.php?page=change_password" method="post">
                <label for="new_password">Nuova Password:</label>
                <input type="password" id="new_password" name="new_password" required>
                <label for="confirm_password">Conferma Password:</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
                <button type="submit" class="btn-custom">Cambia Password</button>
            </form>
        </div>
    </div>
