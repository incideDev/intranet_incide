<?php
if (!defined('HostDbDataConnector')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : null;
$titolo  = isset($_GET['titolo'])  ? trim($_GET['titolo']) : 'Commessa';

if (!$tabella) {
    echo "<div class='error'>Parametro 'tabella' mancante.</div>";
    return;
}

/* URL base per future view (se/quando le creerai) */
$urlBase = "index.php?section=commesse&page=commessa&tabella="
    . urlencode($tabella)
    . "&titolo=" . urlencode($titolo)
    . "&view=";

/* Stub delle 3 macro-sezioni dei controlli (link placeholder '#').
   Appena hai le view vere (es. 'sic_scheduler', 'sic_registro', 'sic_nca'),
   sostituisci '#' con $urlBase.'nome_view' */
$cards = [
  ['sic_scheduler', 'Piano dei Controlli (scheduler)'],
  ['sic_registro',  'Registro Controlli Effettuati'],
  ['sic_nca',       'Non Conformità e Azioni'],
];
?>
<div class="main-container">
  <?php renderPageTitle('Controlli di Sicurezza', '#1F5F8B'); ?>
  <div class="commessa-grid" id="sic-ctrl-grid">
    <?php foreach ($cards as [$key,$label]): ?>
      <a class="commessa-card"
         href="<?= $urlBase . $key ?>"
         data-tooltip="<?= htmlspecialchars($label) ?>"
         aria-label="<?= htmlspecialchars($label) ?>">
        <div class="commessa-card-title"><?= htmlspecialchars($label) ?></div>
        <div class="commessa-card-preview"></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<script>
    // debug non invasivo
    console.debug('[controlli_sicurezza] include ok | tabella=<?= htmlspecialchars($tabella, ENT_QUOTES) ?> | titolo=<?= htmlspecialchars($titolo, ENT_QUOTES) ?>');
</script>