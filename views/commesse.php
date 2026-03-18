<?php
if (!defined('HostDbDataConnector')) define('HostDbDataConnector', true);
if (!defined('AccessoFileInterni')) define('AccessoFileInterni', true);

use Services\CommesseService;

if (!checkPermissionOrWarn('view_commesse')) return;

$tabella = isset($_GET['tabella']) ? strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['tabella'])) : null;
if (!$tabella) {
    echo "<div class='error'>Parametro 'tabella' mancante nell'URL.</div>";
    return;
}

global $database;

$res = $database->query(
    "SELECT b.id, b.titolo, b.tabella, c.oggetto AS nome_commessa, c.responsabile_commessa
     FROM commesse_bacheche b
     LEFT JOIN elenco_commesse c ON LOWER(c.codice) = LOWER(b.tabella)
     WHERE b.tabella = :t LIMIT 1",
    [':t' => $tabella],
    __FILE__
);
if (!$res || !$res->rowCount()) {
    echo "<div class='error'>Tabella non valida o non autorizzata.</div>";
    return;
}
$row = $res->fetch();
$commessaId = (int)$row['id'];
$titolo = $row['titolo'] ?? 'Bacheca';
$nome_commessa = $row['nome_commessa'] ?? '';
// responsabile_commessa può essere un nome o un user_id
$responsabileId = $row['responsabile_commessa'] ?? null;
if (!empty($responsabileId) && !is_numeric($responsabileId)) {
    $responsabileId = $database->query(
        "SELECT user_id FROM personale WHERE LOWER(Nominativo) = LOWER(?) LIMIT 1",
        [trim($responsabileId)],
        __FILE__
    )->fetchColumn() ?: null;
}
$responsabileId = $responsabileId ? (int)$responsabileId : null;
$utenteId = $_SESSION['user_id'] ?? null;

$isResponsabile = ($utenteId && $responsabileId && (int)$utenteId === (int)$responsabileId);
$isMembro = false;
if ($utenteId) {
    $isMembro = $database->query(
        "SELECT 1 FROM commesse_utenti WHERE bacheca_id = ? AND user_id = ? LIMIT 1",
        [$commessaId, $utenteId],
        __FILE__
    )->fetchColumn() ? true : false;
}
if (!isAdmin() && !$isResponsabile && !$isMembro) {
    echo "<div class='no-commesse-msg' style='padding:32px;text-align:center;font-size:1.15em;color:#b12d1c;background:#fff3f1;border-radius:10px;margin:40px auto;max-width:500px;'>
        <b>Non hai commesse assegnate.</b><br>
        Contatta l’amministratore per essere aggiunto a un gruppo di lavoro.
    </div>";
    return;
}

// ===== Variabili GLOBALI comuni =====
$statimap = [
    1 => "DA DEFINIRE",
    2 => "APERTO",
    3 => "IN CORSO",
    4 => "CHIUSO"
];
$utentiMappa = [];
foreach ($database->query("SELECT user_id, Nominativo FROM personale") as $u) {
    $utentiMappa[$u['user_id']] = $u['Nominativo'];
}

$renderTaskHtml = function($task) use ($utentiMappa, $statimap) {
    ob_start(); ?>
    <div class="task-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div class="title-field text-truncate" style="flex:1 1 auto;">
            <strong><?= htmlspecialchars($task['titolo'] ?? '-') ?></strong>
        </div>
        <?php if (!empty($task['specializzazione'])): ?>
            <span class="kanban-discipline-badge"
                data-tooltip="Disciplina: <?= htmlspecialchars($task['specializzazione']) ?>"
                style="margin-left:12px;flex-shrink:0;">
                <?= htmlspecialchars($task['specializzazione']) ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="task-body">
        <div class="priority-field">
            <img src="assets/icons/status.png"
                 class="task-icon"
                 alt="Priorità"
                 data-tooltip="Priorità: <?= !empty($task['priority']) ? htmlspecialchars($task['priority']) : '-' ?>">
            <span><?= !empty($task['priority']) ? htmlspecialchars($task['priority']) : '-' ?></span>
        </div>
        <?php if (!empty($task['data_scadenza'])): ?>
            <div class="date-field">
                <img src="assets/icons/calendar.png" class="task-icon" alt="Scadenza" data-tooltip="Scadenza">
                <span><?= date('d/m/Y', strtotime($task['data_scadenza'])) ?></span>
            </div>
        <?php endif; ?>

        <!-- BLOCCO ASSEGNATARIO & CREATORE: SOLO ICONE + MINIATURE + DATA-TOOLTIP -->
        <div class="kanban-icons-col" style="display: flex; flex-direction: column; gap: 8px; align-items: flex-start;">
            <?php if (!empty($task['assegnato_a_nome']) && $task['assegnato_a_nome'] !== '—'): ?>
                <div class="kanban-icon-wrap" style="display: flex; align-items: center;">
                    <img src="assets/icons/user.png"
                         class="task-icon"
                         alt="Assegnato a"
                         style="margin-right: 4px;"
                         data-tooltip="Assegnato a: <?= htmlspecialchars($task['assegnato_a_nome']) ?>">
                    <img src="<?= htmlspecialchars($task['img_assegnato'] ?? 'assets/images/default_profile.png') ?>"
                         alt="<?= htmlspecialchars($task['assegnato_a_nome']) ?>"
                         class="profile-icon"
                         style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover;"
                         data-tooltip="<?= htmlspecialchars($task['assegnato_a_nome']) ?>">
                </div>
            <?php endif; ?>
            <?php if (!empty($task['creatore_nome']) && $task['creatore_nome'] !== '—'): ?>
                <div class="kanban-icon-wrap" style="display: flex; align-items: center;">
                    <img src="assets/icons/two_users.png"
                         class="task-icon"
                         alt="Creato da"
                         style="margin-right: 4px;"
                         data-tooltip="Creato da: <?= htmlspecialchars($task['creatore_nome']) ?>">
                    <img src="<?= htmlspecialchars($task['img_creatore'] ?? 'assets/images/default_profile.png') ?>"
                         alt="<?= htmlspecialchars($task['creatore_nome']) ?>"
                         class="profile-icon"
                         style="width: 20px; height: 20px; border-radius: 50%; object-fit: cover;"
                         data-tooltip="<?= htmlspecialchars($task['creatore_nome']) ?>">
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
};

?>

<script>
window.__userId = <?= (int)($utenteId ?? 0) ?>;
window.__userIsResponsabile = <?= $isResponsabile ? 'true' : 'false' ?>;
window.TABELLA_ATTUALE = "<?= htmlspecialchars($tabella) ?>";
</script>

<div class="main-container page-commesse">
<?php renderPageTitle($titolo . ($nome_commessa ? ' – ' . $nome_commessa : ''), '#C0392B'); ?>
<?php
if ($responsabileId) {
    $nominativoResponsabile = $database->query("SELECT Nominativo FROM personale WHERE user_id = ?", [$responsabileId], __FILE__)->fetchColumn();
    $imgProfilo = function_exists('get_profile_image') ? get_profile_image($nominativoResponsabile, 'nominativo') : 'assets/images/default_profile.png';
    ?>
    <div style="display: flex; align-items: center; gap: 10px; margin-top: -18px; margin-bottom: 20px; padding-left: 6px;">
        <img src="<?= htmlspecialchars($imgProfilo) ?>"
             alt="Responsabile"
             title="Responsabile bacheca"
             class="profile-image-small"
             style="width: 32px; height: 32px; border-radius: 50%; box-shadow: 0 0 0 2px #ccc;">
        <span style="font-weight: 500; font-size: 15px; color: #444;">
            Responsabile: <?= htmlspecialchars($nominativoResponsabile) ?>
        </span>
    </div>
<?php } ?>

<!-- KANBAN -->
<div id="kanban-view" style="margin-bottom:40px;">
    <?php
    $_GET['tabella'] = $tabella;
    $_GET['titolo'] = $titolo;
    $kanbanType = 'commesse';
    include 'components/kanban_template.php';
    ?>
</div>

<!-- TABELLA LISTA TASK (opzionale, puoi lasciare hidden) -->
<div id="table-view" class="hidden">
    <?php
    $nomeFisicoTabella = 'com_' . strtolower(preg_replace('/[^a-z0-9_]/', '_', $tabella));
    $tasks = CommesseService::gettasks($nomeFisicoTabella);
    ?>
    <div id="filter-container"
        data-table="<?= htmlspecialchars($nomeFisicoTabella) ?>"
        data-filters='["titolo", "specializzazione", "responsabile", "stato"]'>
    </div>
    <table class="table table-filterable" id="commesseTable">
        <thead>
            <tr>
                <th class="azioni-colonna">Azioni</th>
                <th class="id-colonna">ID</th>
                <th class="titolo-colonna">Titolo</th>
                <th class="specializzazione-colonna">Disciplina</th>
                <th class="data-colonna">Scadenza</th>
                <th class="utente-colonna">Referente</th>
                <th class="utentespecializzazionedisciplina-colonna">Assegnato a</th>
                <th class="stato-colonna">Stato</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tasks as $task): ?>
                <tr>
                    <td>
                        <button class="action-icon" title="Dettagli" onclick="openTaskDetails(<?= $task['id'] ?>, '<?= htmlspecialchars($task['tabella']) ?>')">
                            <img src="assets/icons/show.png" alt="Dettagli" style="width:18px;height:18px;">
                        </button>
                        <?php if (($task['submitted_by'] ?? null) == $utenteId || $isResponsabile): ?>
                            <button class="action-icon" title="Elimina" onclick="onKanbanTaskDelete(<?= $task['id'] ?>, '<?= htmlspecialchars($task['tabella']) ?>')">
                                <img src="assets/icons/delete.png" alt="Elimina" style="width:18px;height:18px;">
                            </button>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($task['id']) ?></td>
                    <td><?= htmlspecialchars($task['titolo']) ?></td>
                    <td><?= htmlspecialchars($task['specializzazione'] ?? '-') ?></td>
                    <td><?= !empty($task['data_scadenza']) ? date('d/m/Y', strtotime($task['data_scadenza'])) : '-' ?></td>
                    <td><?= htmlspecialchars($task['img_creatore'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($task['assegnato_a_nome'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($statimap[$task['stato'] ?? $task['status_id'] ?? 1] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- MODALE NUOVA TASK -->
<div id="modal-nuova-task" class="modal custom-modal" style="display: none;">
    <div class="pagina-foglio" style="max-width:760px; width:95vw;">
        <div class="view-form-header" style="--form-color:#ccc">
            <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                <h1 id="modale-nuova-task-title">Nuova Task</h1>
                <span class="close-modal" onclick="toggleModal('modal-nuova-task', 'close')" style="font-size: 2rem; cursor:pointer;">&times;</span>
            </div>
            <hr class="form-title-divider" style="border: none; border-top: 3px solid #bbb; margin-top: 8px; margin-bottom: 24px;">
        </div>
        <form id="form-nuova-task" class="view-form-grid task-details-form" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="tabella" value="<?= htmlspecialchars($tabella) ?>">
            <div class="form-section-title" style="grid-column: span 2; margin-bottom: 0;">Dati generali</div>
            <div><label for="nt-titolo">Titolo</label><input type="text" name="titolo" id="nt-titolo" required autocomplete="off"></div>
            <div>
                <label>Creato da</label>
                <?php
                $user_id = $_SESSION['user_id'] ?? 0;
                $nome = $user_id ? $database->getNominativoByUserId($user_id) : 'Utente';
                ?>
                <input type="text" id="nt-referente-nome" value="<?= htmlspecialchars($nome) ?>" disabled style="width:100%;">
            </div>
            <div>
                <label for="nt-stato">Stato</label>
                <select id="nt-stato" name="stato" required>
                    <option value="1">Da Definire</option>
                    <option value="2">Aperto</option>
                    <option value="3">In Corso</option>
                    <option value="4">Chiuso</option>
                </select>
            </div>
            <div>
                <label for="nt-spec">Disciplina</label>
                <select name="specializzazione" id="nt-spec" required>
                    <option value="">—</option>
                    <option value="GEN">GEN</option>
                    <option value="ARC">ARC</option>
                    <option value="CIV">CIV</option>
                    <option value="STR">STR</option>
                    <option value="ELE">ELE</option>
                    <option value="MEC">MEC</option>
                    <option value="VVF">VVF</option>
                    <option value="SIC">SIC</option>
                </select>
            </div>
            <div>
                <label for="nt-fase-doc">Fase</label>
                <select name="fase_doc" id="nt-fase-doc">
                    <option value="">—</option>
                    <option value="PFTE">PFTE</option>
                    <option value="DEFINITIVO">DEFINITIVO</option>
                    <option value="ESECUTIVO">ESECUTIVO</option>
                    <option value="DIR. LAVORI">DIR. LAVORI</option>
                    <option value="OFFERTA">OFFERTA</option>
                    <option value="GARA">GARA</option>
                </select>
            </div>
            <div>
                <label for="nt-priority">Priorità</label>
                <select name="priority" id="nt-priority" required>
                    <option value="Bassa">Bassa</option>
                    <option value="Media">Media</option>
                    <option value="Alta">Alta</option>
                </select>
            </div>
            <hr class="form-section-divider" style="grid-column: span 2;">
            <div class="form-section-title" style="grid-column: span 2; margin-bottom: 0;">Assegnazione e Scadenze</div>
            <div>
                <label for="nt-assegnato-a">Assegna a</label>
                <select name="assegnato_a" id="nt-assegnato-a">
                    <option value="">—</option>
                    <?php
                    $pers = $database->query("SELECT user_id, Nominativo FROM personale ORDER BY Nominativo ASC", [], __FILE__);
                    foreach ($pers as $p) {
                        echo '<option value="' . $p['user_id'] . '">' . htmlspecialchars($p['Nominativo']) . '</option>';
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="nt-data-scadenza">Data Scadenza</label>
                <input type="date" name="data_scadenza" id="nt-data-scadenza">
            </div>
            <div>
                <label for="nt-data-apertura">Data apertura</label>
                <input type="date" name="data_apertura" id="nt-data-apertura" disabled>
            </div>
            <div>
                <label for="nt-chiusura">Data chiusura</label>
                <input type="date" name="data_chiusura" id="nt-chiusura" disabled>
            </div>
            <hr class="form-section-divider" style="grid-column: span 2;">
            <div class="form-section-title" style="grid-column: span 2; margin-bottom: 0;">Dettagli</div>
            <div style="grid-column: span 2;">
                <label for="nt-azione">Descrizione azione</label>
                <textarea name="descrizione_azione" id="nt-azione" style="width: 100%; min-height: 60px;"></textarea>
            </div>
            <div style="grid-column: span 2;">
                <label for="nt-path">Percorso documento</label>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <input type="text" name="path_allegato" id="nt-path" placeholder="Es: \\server\documenti\commessa123" style="flex: 1;">
                    <img src="assets/icons/copy.png" alt="Copia percorso" id="btn-copia-nt-path" title="Copia negli appunti"
                        style="cursor: pointer; width: 20px; height: 20px; opacity: 0.7;">
                </div>
            </div>
            <div style="grid-column: span 2;">
                <label for="nt-screen">Screenshot (immagini)</label>
                <div class="dropzone-upload" id="dropzone-nuova-task">
                    <div class="upload-preview"></div>
                    <div class="dropzone-helper-text">Trascina qui, clicca o incolla uno screenshot (CTRL+V)</div>
                    <input type="file" name="screenshots" accept="image/jpeg,image/png" style="display:none;">
                    <button type="button" class="upload-remove-btn" style="display:none;">Rimuovi immagine</button>
                    <div class="upload-info">Formati accettati: JPG, PNG. Max 5MB.</div>
                </div>
            </div>
            <div style="grid-column: span 2; text-align: right; margin-top: 24px;">
                <button type="submit" class="button">Salva</button>
            </div>
        </form>
    </div>
</div>

<!-- MODALE DETTAGLI/MODIFICA TASK -->
<div id="modal-dettagli-task" class="modal custom-modal" style="display: none;">
    <div class="pagina-foglio" style="max-width:760px; width:95vw;">
        <div class="view-form-header" style="--form-color:#ccc">
            <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                <h1 id="modale-task-title">Modifica Task</h1>
                <span class="close-modal" onclick="toggleModal('modal-dettagli-task', 'close')" style="font-size: 2rem; cursor:pointer;">&times;</span>
            </div>
            <hr class="form-title-divider" style="border: none; border-top: 3px solid #bbb; margin-top: 8px; margin-bottom: 24px;">
        </div>
        <form id="form-dettagli-task" class="view-form-grid task-details-form" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="task_id" id="dt-task-id">
            <input type="hidden" name="tabella" id="dt-tabella" value="<?= htmlspecialchars($tabella) ?>">
            <div class="form-section-title" style="grid-column: span 2; margin-bottom: 0;">Dati generali</div>
            <div>
                <label for="td-titolo">Titolo</label>
                <input type="text" name="titolo" id="td-titolo" required autocomplete="off">
            </div>
            <div>
                <label>Creato da</label>
                <input type="text" id="td-referente-nome" value="<?= htmlspecialchars($nome) ?>" disabled style="width:100%;">
            </div>
            <div>
                <label for="td-stato">Stato</label>
                <select id="td-stato" name="stato" required>
                    <option value="1">Da Definire</option>
                    <option value="2">Aperto</option>
                    <option value="3">In Corso</option>
                    <option value="4">Chiuso</option>
                </select>
            </div>
            <div>
                <label for="td-spec">Disciplina</label>
                <select name="specializzazione" id="td-spec" required>
                    <option value="">—</option>
                    <option value="GEN">GEN</option>
                    <option value="ARC">ARC</option>
                    <option value="CIV">CIV</option>
                    <option value="STR">STR</option>
                    <option value="ELE">ELE</option>
                    <option value="MEC">MEC</option>
                    <option value="VVF">VVF</option>
                    <option value="SIC">SIC</option>
                </select>
            </div>
            <div>
                <label for="fase_doc">Fase</label>
                <select name="fase_doc" id="td-fase-doc">
                    <option value="">—</option>
                    <option value="PFTE">PFTE</option>
                    <option value="DEFINITIVO">DEFINITIVO</option>
                    <option value="ESECUTIVO">ESECUTIVO</option>
                    <option value="DIR. LAVORI">DIR. LAVORI</option>
                    <option value="OFFERTA">OFFERTA</option>
                    <option value="GARA">GARA</option>
                </select>
            </div>
            <div>
                <label for="td-priority">Priorità</label>
                <select name="priority" id="td-priority" required>
                    <option value="Bassa">Bassa</option>
                    <option value="Media">Media</option>
                    <option value="Alta">Alta</option>
                </select>
            </div>
            <hr class="form-section-divider" style="grid-column: span 2;">
            <div class="form-section-title" style="grid-column: span 2; margin-bottom: 0;">Assegnazione e Scadenze</div>
            <div>
                <label for="td-assegnato-a">Assegna a</label>
                <select name="assegnato_a" id="td-assegnato-a">
                    <option value="">—</option>
                    <?php
                    $pers = $database->query("SELECT user_id, Nominativo FROM personale ORDER BY Nominativo ASC", [], __FILE__);
                    foreach ($pers as $p) {
                        echo '<option value="' . $p['user_id'] . '">' . htmlspecialchars($p['Nominativo']) . '</option>';
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="td-data-scadenza">Data Scadenza</label>
                <input type="date" name="data_scadenza" id="td-data-scadenza">
            </div>
            <div>
                <label for="td-data-apertura">Data apertura</label>
                <input type="date" name="data_apertura" id="td-data-apertura" disabled>
            </div>
            <div>
                <label for="td-chiusura">Data chiusura</label>
                <input type="date" name="data_chiusura" id="td-chiusura" disabled>
            </div>
            <hr class="form-section-divider" style="grid-column: span 2;">
            <div class="form-section-title" style="grid-column: span 2; margin-bottom: 0;">Dettagli</div>
            <div style="grid-column: span 2;">
                <label for="td-azione">Descrizione azione</label>
                <textarea name="descrizione_azione" id="td-azione" style="width: 100%; min-height: 60px;"></textarea>
            </div>
            <div style="grid-column: span 2;">
                <label for="td-path">Percorso documento</label>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <input type="text" name="path_allegato" id="td-path" placeholder="Es: \\server\documenti\commessa123" style="flex: 1;">
                    <img src="assets/icons/copy.png" alt="Copia percorso" id="btn-copia-path" title="Copia negli appunti"
                        style="cursor: pointer; width: 20px; height: 20px; opacity: 0.7;">
                </div>
            </div>
            <!-- CAMPO SCREEN SOLO PREVIEW -->
            <div style="grid-column: span 2;">
                <label>Screenshot (immagini)</label>
                <!-- Qui mettiamo l’anteprima separata -->
                <div id="dettagli-task-image-preview" style="margin-bottom: 10px;"></div>
                <!-- Il campo upload resta ma sotto -->
                <div class="dropzone-upload" id="dropzone-dettagli-task">
                    <div class="upload-preview"></div>
                    <div class="dropzone-helper-text">Trascina qui, clicca o incolla uno screenshot (CTRL+V)</div>
                    <input type="file" name="screenshots" accept="image/jpeg,image/png" style="display:none;">
                    <button type="button" class="upload-remove-btn" style="display:none;">Rimuovi immagine</button>
                    <div class="upload-info">Formati accettati: JPG, PNG. Max 5MB.</div>
                </div>
            </div>
            <div style="grid-column: span 2; text-align: right; margin-top: 24px;">
                <button type="submit" class="button">Salva</button>
            </div>
        </form>
    </div>
</div>

</div>
