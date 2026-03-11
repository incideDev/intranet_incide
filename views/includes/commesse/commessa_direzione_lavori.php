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
?>
<div class="main-container">
  <?php renderPageTitle('Direzione lavori', '#cccccc'); ?>
  
  <div style="padding: 40px 20px; text-align: center;">
    <h2>TODO</h2>
    <p style="color: #888; margin-top: 10px;">Sezione in fase di sviluppo</p>
  </div>
</div>

