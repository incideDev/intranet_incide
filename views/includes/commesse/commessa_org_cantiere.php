<?php
if (!defined('HostDbDataConnector')) { header("HTTP/1.0 403 Forbidden"); exit; }

use Services\CommesseService;

global $database;

/* --- Parametri --- */
$tabella = isset($_GET['tabella']) ? preg_replace('/[^a-z0-9_]/i', '', $_GET['tabella']) : null;
if (!$tabella) {
    echo "<div class='error'>Parametro 'tabella' mancante nell'URL.</div>";
    return;
}

/* --- Dati commessa --- */
$commessa = $database->query(
    "SELECT b.id, b.titolo
     FROM commesse_bacheche b
     WHERE b.tabella = ? LIMIT 1",
    [$tabella],
    __FILE__
)->fetch(\PDO::FETCH_ASSOC);

if (!$commessa) {
    echo "<div class='error'>Commessa non trovata.</div>";
    return;
}

$commessaId = (int)$commessa['id'];

/* --- RUOLI standard del cantiere (code, label, color) --- */
$ruoliCantiere = [
    ['code' => 'RESP_CANTIERE',         'label' => 'Responsabile Cantiere',     'color' => '#27649c'],
    ['code' => 'PREPOSTO',               'label' => 'Preposto',                  'color' => '#2c7a7b'],
    ['code' => 'COORD_SICUREZZA',        'label' => 'Coordinatore Sicurezza',    'color' => '#9c4221'],
    ['code' => 'RSPP',                   'label' => 'RSPP',                      'color' => '#7b341e'],
    ['code' => 'ASPP',                   'label' => 'ASPP',                      'color' => '#b7791f'],
    ['code' => 'ADDETTO_ANTINCENDIO',    'label' => 'Addetto Antincendio',       'color' => '#c53030'],
    ['code' => 'ADDETTO_PRIMO_SOCCORSO', 'label' => 'Addetto Primo Soccorso',    'color' => '#2f855a'],
    ['code' => 'MEDICO_COMPETENTE',      'label' => 'Medico Competente',         'color' => '#553c9a'],
];

/* --- Carica organigramma cantiere (ALBERO COMPLETO) ---
   Nodo = { azienda_id:int|null, role:string|null, children:[] } */
$tree = CommesseService::getOrganigrammaCantiere($tabella);
if (!is_array($tree) || !array_key_exists('children', $tree)) {
    $tree = ['azienda_id' => null, 'role' => null, 'children' => []];
}
if (!is_array($tree['children'])) $tree['children'] = [];
if (!array_key_exists('azienda_id', $tree)) $tree['azienda_id'] = null;
if (!array_key_exists('role', $tree))       $tree['role'] = null;
?>
<div class="main-container commessa-organigramma">
  <?php renderPageTitle('Organigramma Cantiere', '#1F5F8B'); ?>

  <div id="org-tree-view">
    <div class="org-main-wrap">
      <div class="org-canvas-area">
        <div class="org-tree-scrollwrap">
          <div class="org-tree-inner">
            <div id="org-fasce-area" class="org-fasce-area"></div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div id="org-sidebar" class="org-sidebar-toolbox">
        <div class="toolbox-header">Strumenti</div>

        <div class="toolbox-section">
          <div class="toolbox-title">Ruoli cantiere</div>
          <div id="sidebar-roles" class="toolbox-badges"></div>
        </div>

        <div class="toolbox-section">
          <div class="toolbox-title">Imprese (anagrafiche)</div>
          <input type="text" id="org-search-imprese" class="org-users-search" placeholder="Cerca impresa..." autocomplete="off" style="margin-bottom:6px;">
          <div id="sidebar-imprese" class="org-users-scroll toolbox-badges"></div>
          <div style="color:#64748b;font-size:.9em;margin-top:6px;">
            Trascina un’impresa in uno <em>slot</em> dell’albero, poi trascina un <strong>ruolo</strong> sul nodo per assegnarlo.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  // Boot JS
  window._orgCantiereInitial = <?= json_encode($tree, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  window._ruoliCantiere      = <?= json_encode($ruoliCantiere, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  window._tabellaCommessa    = <?= json_encode($tabella, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
</script>

<script src="/assets/js/commesse/commessa_org_cantiere.js"></script>
