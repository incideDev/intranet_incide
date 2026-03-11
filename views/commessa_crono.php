<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found');
    die();
}

if (!checkPermissionOrWarn('view_commesse')) {
    return;
}

// Recupera codice commessa e valida
$idProject = filter_input(INPUT_GET, 'tabella', FILTER_SANITIZE_SPECIAL_CHARS) ?? '';
if (!$idProject) {
    echo '<div class="alert alert-warning">Commessa non specificata.</div>';
    return;
}

// Normalizza e valida whitelist
$idProject = strtoupper(trim($idProject));
if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $idProject)) {
    echo '<div class="alert alert-warning">Codice commessa non valido.</div>';
    return;
}

// REDIRECT verso il tab unificato
$redirectUrl = 'index.php?section=commesse&page=commessa&tabella=' . urlencode($idProject) . '&view=crono';
header('Location: ' . $redirectUrl, true, 302);
exit;
