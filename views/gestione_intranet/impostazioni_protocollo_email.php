<?php
/* ATTENZIONE: la costante va verificata con la stessa capitalizzazione
   usata nell'index: 'AccessoFileInterni' (case-sensitive). */
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include('page-errors/404.php');
    die();
}

/* gli admin devono poter entrare sempre: la permission resta,
   ma se il tuo sistema mappa l'admin come 'tutto permesso',
   passerà comunque */
if (!userhaspermission('view_gestione_intranet')) {
    echo "<div class='error'>accesso non autorizzato.</div>";
    return;
}

use services\roleservice;

/* normalizzo i ruoli per avere sempre id e label stringa non nulla */
$raw_roles = roleservice::getroles();
$allroles = [];
if (isset($raw_roles['data']) && is_array($raw_roles['data'])) {
    foreach ($raw_roles['data'] as $r) {
        $id = 0;
        if (isset($r['id'])) {
            $id = (int)$r['id'];
        } elseif (isset($r['role_id'])) {
            $id = (int)$r['role_id'];
        }

        /* prova i possibili campi testo: label/nome/name/descrizione/code */
        $label = '';
        foreach (['label','nome','name','descrizione','code'] as $k) {
            if (isset($r[$k]) && is_string($r[$k]) && $r[$k] !== '') {
                $label = $r[$k];
                break;
            }
        }

        if ($id > 0) {
            if ($label === '') { $label = 'ruolo '.$id; }
            $allroles[] = ['id' => $id, 'label' => $label];
        }
    }
}

/* etichette blocchi */
$bloccokey2label = [
    'generale' => 'area generale (gar, amm, off, ...)',
    'singole_commesse' => 'area commesse (non generali)',
];

?>

<div class="main-container">
  <?php renderPageTitle("Impostazioni protocollo Email", "#2C3E50");?>
  <div class="page-title">Impostazioni Visibilità – Protocollo Email</div>
  <div class="section-subtitle">
    Configura chi può vedere ciascun blocco della sezione <strong>Protocollo Email</strong>.
  </div>

  <form id="visibilitaProtocolloForm" class="visibilita-sezioni-form">
    <table class="table table-bordered">
      <thead>
        <tr>
          <th>blocco</th>
          <?php foreach ($allroles as $role): ?>
            <th><?= htmlspecialchars((string)$role['label'], ENT_QUOTES, 'UTF-8') ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bloccokey2label as $blocco => $descrizione): ?>
          <tr data-blocco="<?= htmlspecialchars($blocco, ENT_QUOTES, 'UTF-8') ?>">
            <td><?= htmlspecialchars($descrizione, ENT_QUOTES, 'UTF-8') ?></td>
            <?php foreach ($allroles as $role): ?>
              <td style="text-align:center">
                <input type="checkbox"
                      class="vis-checkbox"
                      data-blocco="<?= htmlspecialchars($blocco, ENT_QUOTES, 'UTF-8') ?>"
                      data-ruolo-id="<?= (int)$role['id'] ?>">
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <button type="button"
            id="salvaVisibilitaProtocolloBtn"
            class="button btn-success"
            data-tooltip="Salva configurazione">
      Salva Visibilità
    </button>
  </form>
</div>

<script src="assets/js/gestione_intranet/impostazioni_protocollo_email.js?v=<?= time() ?>"></script>
