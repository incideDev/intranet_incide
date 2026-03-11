<?php
if (!defined('accessofileinterni')) {
    die('Accesso diretto non consentito');
}

// Recupero codice commessa con validazione
$commessaCode = '';
if (isset($tabella) && !empty($tabella)) {
    $commessaCode = $tabella;
} else {
    $commessaCode = $_GET['tabella'] ?? '';
}

// Normalizza e valida
$commessaCode = strtoupper(trim($commessaCode));
if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $commessaCode)) {
    echo '<div class="alert alert-warning">Codice commessa non valido.</div>';
    return;
}
?>
<link rel="stylesheet" href="assets/css/commessa_crono.css?v=<?= time() ?>">

<div class="cronoprogramma-wrapper">
    <!-- Toolbar (switcher vista + filtro periodo + CTA) -->
    <div id="toolbar-placeholder"></div>

    <!-- Vista Gantt (default) -->
    <div id="gantt-container"></div>

    <!-- Vista Lista (hidden by default) -->
    <div id="lista-container" style="display:none;"></div>

    <!-- Riepilogo (KPI + scadenze pagamento) -->
    <div id="riepilogo-container"></div>
</div>

<script>
    window.COMMESSA_ID = <?= json_encode($commessaCode) ?>;
    console.log('COMMESSA_ID impostato:', window.COMMESSA_ID);
</script>
<script src="assets/js/commessa_crono.js?v=<?= time() ?>"></script>
