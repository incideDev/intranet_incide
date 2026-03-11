<?php
if (!defined('hostdbdataconnector')) define('hostdbdataconnector', true);
if (!defined('accessofileinterni')) define('accessofileinterni', true);

// Controllo permessi robusto
if (!checkPermissionOrWarn('view_gestione_commesse')) return;

global $database;

// Caricamento sicuro bacheche
$userId = $_SESSION['user_id'] ?? 0;
if (userHasPermission('view_gestione_commesse')) {
    $bacheche = $database->query("SELECT * FROM commesse_bacheche ORDER BY id DESC", [], __FILE__)->fetchAll(PDO::FETCH_ASSOC);
} else {
    $bacheche = $database->query(
        "SELECT b.* FROM commesse_bacheche b
         JOIN commesse_utenti u ON b.id = u.bacheca_id
         WHERE u.user_id = ?
         ORDER BY b.id DESC",
        [$userId],
        __FILE__
    )->fetchAll(PDO::FETCH_ASSOC);
}

$utenti = $database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__);
$utenti_map = [];
foreach ($utenti as $u) {
    $utenti_map[$u['user_id']] = [
        'nome' => $u['Nominativo'],
        'img' => function_exists('get_profile_image')
            ? get_profile_image($u['Nominativo'], 'nominativo')
            : 'assets/images/default_profile.png'
    ];
}
?>

<div class="main-container page-gestione-commesse">
    <?php renderPageTitle("Gestione Commesse", '#C0392B'); ?>

<div class="form-grid">
<?php foreach ($bacheche as $b): ?>
    <div class="form-preview"
        data-form-name="<?= htmlspecialchars($b['tabella']) ?>"
        data-id="<?= (int)$b['id'] ?>"
        style="--form-color: #cccccc; position:relative;">
        
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:0 10px 10px 10px;">
            <h3 class="form-title" style="margin-bottom:2px;text-align:center;width:100%;font-size:22px;"><?= htmlspecialchars($b['titolo']) ?></h3>
            <div class="commessa-subtitle" style="font-size:15px;color:#777;text-align:center;width:100%;margin-bottom:10px;">
                <?= htmlspecialchars($b['nome_commessa'] ?? '—') ?>
            </div>
        </div>
        
        <div class="form-details" style="margin:0 10px 0 10px;">
            <?php
            $tabella_sql = 'com_' . preg_replace('/[^a-zA-Z0-9_]/', '', $b['tabella']);
            $ultima_modifica = $database->query(
                "SELECT MAX(submitted_at) FROM `$tabella_sql`", [], __FILE__
            )->fetchColumn();
            if (!$ultima_modifica || $ultima_modifica === '1970-01-01 00:00:00') $ultima_modifica = "-";
            else $ultima_modifica = date("d/m/Y H:i", strtotime($ultima_modifica));
            $resp_id = $b['responsabile_id'] ?? null;
            $img = $resp_id && isset($utenti_map[$resp_id]) ? $utenti_map[$resp_id]['img'] : 'assets/images/default_profile.png';
            ?>
            <!-- Gruppo di lavoro -->
            <div class="user-avatars-group" id="group-<?= (int)$b['id'] ?>" style="margin-bottom:15px;"></div>
        </div>
        <!-- Ultima modifica in basso -->
        <div class="meta-row" style="position:absolute;left:0;right:0;bottom:7px;text-align:center;font-size:12px;color:#888;">
            <span class="meta-label">Ultima modifica:</span>
            <span class="meta-value"><?= $ultima_modifica ?></span>
        </div>
    </div>
<?php endforeach; ?>
</div>

</div>

<!-- Modale Nuova Bacheca -->
<div id="modal-nuova-bacheca" class="modal modal-small">
  <div class="modal-content">
    <span class="close">&times;</span>
    <div style="display:flex;justify-content:space-between;align-items:end;">
        <h3 class="modal-title">Nuova Bacheca</h3>
        <span class="anagrafica-edit-btn"
            data-tooltip="Gestisci Anagrafica"
            onclick="document.getElementById('modal-anagrafica-commessa').style.display = 'block'"
            style="cursor:pointer; display:inline-flex;align-items:center; margin-left:20px; margin-bottom:5px;">
            <img src="assets/icons/change.png" alt="Gestisci Anagrafica" style="width:16px;height:auto;opacity:0.77;transition:opacity .2s;">
        </span>
    </div>
    <div class="modal-header-line"></div>
      <form id="form-nuova-bacheca">
        <div class="modal-grid-2col">
          <div>
            <div class="form-group">
              <label for="titolo">Titolo Bacheca:</label>
              <input type="text" name="titolo" required>
            </div>
            <div class="form-group">
              <label for="nome_commessa">Nome Commessa:</label>
              <input type="text" name="nome_commessa" id="nome_commessa" required>
            </div>
            <div class="form-group">
              <label for="responsabile_id">Responsabile commessa</label>
              <select name="responsabile_id" id="responsabile_id" required>
                  <option value="">—</option>
                  <?php
                  $pers = $database->query("SELECT user_id, Nominativo FROM personale ORDER BY Nominativo ASC", [], __FILE__);
                  foreach ($pers as $p) {
                      echo '<option value="' . $p['user_id'] . '">' . htmlspecialchars($p['Nominativo']) . '</option>';
                  }
                  ?>
              </select>
            </div>
<div class="form-group">
    <label for="dropdown-membri-trigger">Gruppo di lavoro</label>
    <div id="dropdown-membri-wrapper" style="position:relative;">
        <div id="dropdown-membri-trigger" tabindex="0" class="dropdown-membri-input" style="display:flex; align-items:center; border:1px solid #ccc; border-radius:4px; background:#fff; cursor:pointer; padding:4px 5px;">
            <span id="dropdown-membri-placeholder" style="color:#aaa;">Seleziona membri...</span>
            <span id="dropdown-membri-counter" style="margin-left:auto; color:#666;"></span>
            <svg style="width:16px;height:16px;margin-left:10px;opacity:.8;" viewBox="0 0 24 24"><path fill="#888" d="M7 10l5 5 5-5H7z"/></svg>
        </div>
        <div id="dropdown-membri" class="dropdown-membri-list" style="display:none; position:absolute; left:0; top:42px; background:#fff; border:1px solid #ccc; border-radius:5px; width:100%; max-height:240px; overflow:auto; z-index:1010; box-shadow:0 6px 24px #0001;">
            <input type="text" id="dropdown-membri-search" placeholder="Cerca..." style="width:94%;margin:9px 3%;border-radius:3px;border:1px solid #e2e2e2;padding:6px;">
            <div id="dropdown-membri-utenti"></div>
        </div>
        <div id="selezionati-membri-list" class="selected-members-list" style="margin-top:6px; min-height:22px;"></div>
    </div>
</div>

          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="button">Crea</button>
        </div>
      </form>

  </div>
</div>

<!-- Modale Anagrafica Commessa -->
<div id="modal-anagrafica-commessa" class="modal modal-large">
  <div class="modal-content">
    <span class="close">&times;</span>
    <h3 class="modal-title">Gestisci Anagrafica</h3>
    <div class="modal-header-line"></div>
    <form id="form-anagrafica-commessa" class="modale-form-gara">
      <div class="modale-form-grid">
        <div class="modale-form-group">
          <label>Data Creazione</label>
          <input type="date" name="anagrafica[data_creazione]">
        </div>

        <div class="modale-form-group">
          <label>Cliente</label>
          <input type="text" name="anagrafica[cliente]">
        </div>

        <div class="modale-form-group full-width">
          <label>Oggetto</label>
          <input type="text" name="anagrafica[titolo]">
        </div>

        <div class="modale-form-group">
          <label>Business Unit</label>
          <input type="text" name="anagrafica[business_unit]">
        </div>

        <div class="modale-form-group">
          <label>Categoria</label>
          <input type="text" name="anagrafica[categoria]">
        </div>

        <div class="modale-form-group">
          <label>Data Inizio</label>
          <input type="date" name="anagrafica[data_inizio]">
        </div>

        <div class="modale-form-group">
          <label>Data Fine</label>
          <input type="date" name="anagrafica[data_fine]">
        </div>

        <div class="modale-form-group">
          <label>Resp. Commessa</label>
          <input type="text" name="anagrafica[resp_commessa]">
        </div>

        <div class="modale-form-group">
          <label>Referente Commerciale</label>
          <input type="text" name="anagrafica[referente_commerciale]">
        </div>

        <div class="modale-form-group">
          <label>Referente Cliente</label>
          <input type="text" name="anagrafica[referente_cliente]">
        </div>

        <div class="modale-form-group">
          <label>Referente Tecnico</label>
          <input type="text" name="anagrafica[referente_tecnico]">
        </div>

        <div class="modale-form-group">
          <label>Referente Amministrativo</label>
          <input type="text" name="anagrafica[referente_amministrativo]">
        </div>

        <div class="modale-form-group">
          <label>Numero Ordine</label>
          <input type="text" name="anagrafica[numero_ordine]">
        </div>

        <div class="modale-form-group">
          <label>Data Ordine</label>
          <input type="date" name="anagrafica[data_ordine]">
        </div>

        <div class="modale-form-group">
          <label>CIG</label>
          <input type="text" name="anagrafica[cig]">
        </div>

        <div class="modale-form-group">
          <label>CUP</label>
          <input type="text" name="anagrafica[cup]">
        </div>

        <div class="modale-form-group full-width">
          <label>Importo Lavori (€)</label>
          <input type="text" name="anagrafica[importo_lavori]" placeholder="es. 12500.00">
        </div>
      </div>

        <div class="modal-footer" style="display: flex; justify-content: space-between; margin-top: 15px;">
            <button type="submit" class="button">Salva</button>
        </div>
    </form>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    window._commesseMembriData = <?= json_encode(array_map(function($b) use ($database, $utenti_map) {
        $membri = $database->query("SELECT user_id FROM commesse_utenti WHERE bacheca_id = ?", [$b['id']], __FILE__)->fetchAll(PDO::FETCH_COLUMN);
        $utentiGruppo = [];
        foreach ($membri as $mid) {
            if (isset($utenti_map[$mid])) $utentiGruppo[] = [
                'user_id' => $mid,
                'nome' => $utenti_map[$mid]['nome'],
                'img' => $utenti_map[$mid]['img']
            ];
        }
        return [
            'id' => $b['id'],
            'membri' => $utentiGruppo,
            'responsabile_id' => $b['responsabile_id'] ?? null
        ];
    }, $bacheche)) ?>;

    window._commesseMembriData.forEach(function(b) {
        window.renderUserAvatarsGroup("#group-" + b.id, b.membri, b.responsabile_id);
    });
});
</script>
