<?php
if (!defined('HostDbDataConnector')) {
  header('HTTP/1.0 403 Forbidden');
  exit;
}

$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : null;
$titolo  = isset($_GET['titolo'])  ? trim($_GET['titolo']) : 'Commessa';

if (!$tabella) {
  echo "<div class='error'>Parametro 'tabella' mancante.</div>";
  return;
}

$base = "index.php?section=commesse&page=commessa&tabella=" . urlencode($tabella) . "&titolo=" . urlencode($titolo) . "&view=";

/* Card moduli sicurezza */
$cards = [
  ['documenti_sicurezza', 'Documenti Sicurezza'],
  ['controlli_sicurezza', 'Controlli Sicurezza'],
  ['sic_vvcs', 'Verbale Visita in Cantiere (VVCS)'],
  ['sic_vcs', 'Verbale Riunione Coordinamento (VCS)'],
  ['sic_vrtp', 'Verbale Riunione Tecnica Periodica (VRTP)'],
  ['sic_vpos', 'Verbale Posizione (VPOS)'],
  ['sic_vfp', 'Verbale Fine Presenza (VFP)'],
  ['sic_elenco_doc', 'Elenco documenti per impresa'],
];
?>
<?php renderPageTitle('Sicurezza', '#1F5F8B'); ?>

<div class="commessa-grid" id="sicurezza-grid">
  <?php foreach ($cards as [$key, $label]): ?>
    <a class="commessa-card"
      href="<?= $base . $key ?>"
      data-key="<?= htmlspecialchars($key) ?>"
      data-tooltip="<?= htmlspecialchars($label) ?>"
      aria-label="<?= htmlspecialchars($label) ?>">
      <div class="commessa-card-title"><?= htmlspecialchars($label) ?></div>
      <div class="commessa-card-preview"></div>
    </a>
  <?php endforeach; ?>
</div>

