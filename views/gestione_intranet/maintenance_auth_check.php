<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include('page-errors/404.php');
    die();
}

// Include extra_auth.php che gestisce tutto (modale, verifica, ecc.)
// Se non autenticato, extra_auth.php mostrerà il modale e farà exit
require_once __DIR__ . '/../components/extra_auth.php';

// Se arriviamo qui, significa che extra_auth.php ha già verificato la password
// e ha impostato $_SESSION['extra_auth_ok'] = true
// Ora possiamo comunicare al parent che l'autenticazione è riuscita
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verifica password</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: transparent;
        }
    </style>
</head>
<body>
<script>
    // Comunica al parent window che l'autenticazione è riuscita
    if (window.parent && window.parent !== window) {
        window.parent.postMessage('extra_auth_success', '*');
    }
</script>
</body>
</html>

