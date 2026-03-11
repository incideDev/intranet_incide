<?php
if (!defined('HostDbDataConnector')) {
  header('HTTP/1.0 403 Forbidden');
  exit;
}

$tabella   = isset($_GET['tabella'])    ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : null;
$titolo    = isset($_GET['titolo'])     ? trim($_GET['titolo']) : 'Commessa';
$aziendaId = isset($_GET['azienda_id']) ? intval($_GET['azienda_id']) : 0;

if (!$tabella) {
  echo "<div class='error'>Parametro 'tabella' mancante.</div>";
  return;
}
// NOTA: su questa pagina mostriamo solo le CARD di accesso ai moduli.
// L'azienda può non essere ancora selezionata: la richiederemo nelle view che la necessitano.
$aziendaId = ($aziendaId > 0) ? $aziendaId : 0;

/* percorsi semi-dinamici per include/require (coerenti con progetto) */
$__BASE = substr(dirname(__FILE__), 0, strpos(dirname(__FILE__), '/assets') ?: strlen(dirname(__FILE__)));

/* URL base per le view sicurezza (azienda_id opzionale) */
$urlBase = "index.php?section=commesse&page=commessa"
  . "&tabella=" . urlencode($tabella)
  . "&titolo="  . urlencode($titolo)
  . ($aziendaId > 0 ? "&azienda_id=" . urlencode((string)$aziendaId) : "")
  . "&view=";

/* Card (aggiungo anche “Elenco documenti per impresa”) */
$cards = [
  ['sic_vvcs', 'Verbale Visita in Cantiere (VVCS)'],
  ['sic_vcs', 'Verbale Riunione Coordinamento (VCS)'],
  ['sic_elenco_doc', 'Elenco documenti per impresa'],
];
?>

<div class="main-container">
  <?php renderPageTitle('Documenti della Sicurezza', '#1F5F8B'); ?>

  <div class="commessa-grid" id="sic-doc-grid">
    <?php foreach ($cards as [$key, $label]): ?>
      <a class="commessa-card"
        href="<?= $urlBase . $key ?>"
        data-key="<?= htmlspecialchars($key) ?>"
        data-tooltip="<?= htmlspecialchars($label) ?>"
        aria-label="<?= htmlspecialchars($label) ?>">
        <div class="commessa-card-title"><?= htmlspecialchars($label) ?></div>
        <div class="commessa-card-preview"></div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
