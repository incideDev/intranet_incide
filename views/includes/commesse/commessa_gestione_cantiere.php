<?php
if (!defined('accessofileinterni')) { http_response_code(404); exit; }

$tabella = preg_replace('/[^a-z0-9_]/i','', $_GET['tabella'] ?? '');
$titolo  = $_GET['titolo'] ?? 'Commessa';
$base    = "index.php?section=commesse&page=commessa&tabella=".urlencode($tabella)."&titolo=".urlencode($titolo)."&view=";
?>
<div class="main-container">
  <?php renderPageTitle('Gestione cantiere', '#ffeadf'); ?>
  <div class="commessa-grid">
    <a class="commessa-card cantiere-card" href="<?=$base?>organigramma_imprese" data-tooltip="Organigramma Imprese">
        <div class="commessa-card-title">Organigramma Imprese</div>
        <div class="commessa-card-preview"></div>
    </a>
    <a class="commessa-card cantiere-card" href="<?=$base?>organigramma_cantiere" data-tooltip="Organigramma Cantiere">
        <div class="commessa-card-title">Organigramma Cantiere</div>
        <div class="commessa-card-preview"></div>
    </a>
    <a class="commessa-card cantiere-card" href="<?=$base?>documenti_sicurezza" data-tooltip="Documenti Sicurezza">
        <div class="commessa-card-title">Documenti Sicurezza</div>
        <div class="commessa-card-preview"></div>
    </a>
    <a class="commessa-card cantiere-card" href="<?=$base?>controlli_sicurezza" data-tooltip="Controlli Sicurezza">
        <div class="commessa-card-title">Controlli Sicurezza</div>
        <div class="commessa-card-preview"></div>
    </a>
  </div>
</div>
