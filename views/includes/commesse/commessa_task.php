<?php
if (!defined('hostdbdataconnector')) define('hostdbdataconnector', true);
if (!defined('accessofileinterni')) define('accessofileinterni', true);

use Services\CommesseService;

if (!checkPermissionOrWarn('view_commesse')) return;

$tabella = isset($_GET['tabella']) ? strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['tabella'])) : null;
if (!$tabella) {
    echo "<div class='error'>Parametro 'tabella' mancante nell'URL.</div>";
    return;
}

global $database;

$res = $database->query(
    "SELECT id, titolo FROM commesse_bacheche WHERE tabella = :t LIMIT 1",
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
$responsabileId = $row['responsabile_id'] ?? null;
$utenteId = $_SESSION['user_id'] ?? null;

$isResponsabile = ($utenteId && $responsabileId && (int)$utenteId === (int)$responsabileId);
$isMembro = false;
if ($utenteId) {
    // BLOCCO DA ELIMINARE O COMMENTARE
    // $isMembro = $database->query(
    //     "SELECT 1 FROM commesse_utenti WHERE bacheca_id = ? AND user_id = ? LIMIT 1",
    //     [$commessaId, $utenteId],
    //     __FILE__
    // )->fetchColumn() ? true : false;
    $isMembro = true; // Considera sempre membro
}
if (!$isResponsabile && !$isMembro) {
    echo "<div class='no-commesse-msg' style='padding:32px;text-align:center;font-size:1.15em;color:#b12d1c;background:#fff3f1;border-radius:10px;margin:40px auto;max-width:500px;'>
        <b>Non hai commesse assegnate.</b><br>
        Contatta l’amministratore per essere aggiunto a un gruppo di lavoro.
    </div>";
    return;
}

$statimap = [
    1 => "DA DEFINIRE",
    2 => "APERTO",
    3 => "IN CORSO",
    4 => "CHIUSO"
];
$utentiMappa = [];
foreach ($database->query("SELECT user_id, Nominativo FROM personale", [], __FILE__) as $u) {
    $utentiMappa[$u['user_id']] = $u['Nominativo'];
}

// Render del kanban direttamente
?>
<script>
window.__userId = <?= (int)($utenteId ?? 0) ?>;
window.__userIsResponsabile = <?= $isResponsabile ? 'true' : 'false' ?>;
window.TABELLA_ATTUALE = "<?= htmlspecialchars($tabella) ?>";
</script>

<div class="page-commesse">
<?php
if ($responsabileId) {
    $nominativoResponsabile = $database->query("SELECT Nominativo FROM personale WHERE user_id = ?", [$responsabileId], __FILE__)->fetchColumn();
    $imgProfilo = function_exists('getProfileImage') ? getProfileImage($nominativoResponsabile, 'nominativo') : 'assets/images/default_profile.png';
    ?>
    <div style="display: flex; align-items: center; gap: 10px; margin-top: -18px; margin-bottom: 20px; padding-left: 6px;">
        <img src="<?= htmlspecialchars($imgProfilo) ?>"
             alt="Responsabile"
             data-tooltip="Responsabile bacheca"
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
    // Prepara i dati per il kanban template
    $nomeFisicoTabella = 'com_' . strtolower(preg_replace('/[^a-z0-9_]/', '_', $tabella));
    
    // NUOVO SISTEMA GLOBALE: usa TaskService con contesto
    $entity_type = 'commessa';
    $entity_id = $tabella; // Tabella logica senza prefisso com_
    $include_subtasks = true;
    
    // Parametri per il template kanban
    // NOTA: $tabella SENZA prefisso com_ (es: ADR06G)
    // NOTA: $statimap è già definito sopra (righe 52-57)
    $kanbanType = 'commesse';
    $showAddButton = true;
    $dataAttributes = ['tabella' => $nomeFisicoTabella, 'tipo' => 'commessa'];
    
    // Include il template unificato (ora supporta entity_type/entity_id)
    include dirname(__FILE__) . '/../../components/kanban_template.php';
    ?>
</div>

<!-- TABELLA LISTA TASK -->
<div id="table-view" class="hidden">
    <?php
    $nomeFisicoTabella = 'com_' . strtolower(preg_replace('/[^a-z0-9_]/', '_', $tabella));
    // Usa TaskService per caricare board (statuses + tasks)
    if (class_exists('Services\TaskService')) {
        $context = [
            'contextType' => 'commessa',
            'contextId' => $tabella
        ];
        $boardResult = Services\TaskService::loadBoard($context);
        $tasks = $boardResult['success'] ? ($boardResult['tasks'] ?? []) : [];
    } else {
        // Fallback legacy
        $tasks = Services\CommesseService::gettasks($nomeFisicoTabella);
    }
    ?>
    <div id="filter-container"
        data-table="<?= htmlspecialchars($nomeFisicoTabella) ?>"
        data-filters='["titolo", "specializzazione", "responsabile", "stato"]'>
    </div>
    <table class="table table-filterable" id="commesseTable" style="width:100%;margin-top:12px;">
        <thead>
            <tr>
                <th class="azioni-colonna">Azioni</th>
                <th class="id-colonna">ID</th>
                <th class="titolo-colonna">Titolo</th>
                <th class="specializzazione-colonna">Disciplina</th>
                <th class="data-colonna">Scadenza</th>
                <th class="utente-colonna">Creato da</th>
                <th class="utenteassegnato-colonna">Assegnato a</th>
                <th class="stato-colonna">Stato</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tasks as $task): ?>
                <tr>
                    <td class="azioni-colonna">
                        <button class="action-icon" title="Dettagli" onclick="openTaskDetails(<?= $task['id'] ?>, '<?= htmlspecialchars($task['tabella']) ?>')">
                            <img src="assets/icons/show.png" alt="Dettagli">
                        </button>
                        <?php if (($task['submitted_by'] ?? null) == $utenteId || $isResponsabile): ?>
                            <button class="action-icon" title="Elimina" onclick="onKanbanTaskDelete(<?= $task['id'] ?>, '<?= htmlspecialchars($task['tabella']) ?>')">
                                <img src="assets/icons/delete.png" alt="Elimina">
                            </button>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($task['id']) ?></td>
                    <td><?= htmlspecialchars($task['titolo']) ?></td>
                    <td><?= htmlspecialchars($task['specializzazione'] ?? '-') ?></td>
                    <td><?= !empty($task['data_scadenza']) ? date('d/m/Y', strtotime($task['data_scadenza'])) : '-' ?></td>
                    <td style="min-width:120px;">
                        <?php if (!empty($task['img_creatore'])): ?>
                            <img src="<?= htmlspecialchars($task['img_creatore']) ?>" alt="" style="width:18px;height:18px;border-radius:50%;margin-right:4px;vertical-align:-5px;">
                        <?php endif; ?>
                        <?= htmlspecialchars($task['creatore_nome'] ?? '-') ?>
                    </td>
                    <td style="min-width:120px;">
                        <?php if (!empty($task['img_assegnato'])): ?>
                            <img src="<?= htmlspecialchars($task['img_assegnato']) ?>" alt="" style="width:18px;height:18px;border-radius:50%;margin-right:4px;vertical-align:-5px;">
                        <?php endif; ?>
                        <?= htmlspecialchars($task['assegnato_a_nome'] ?? '-') ?>
                    </td>
                    <td><?= htmlspecialchars($statimap[$task['stato'] ?? $task['status_id'] ?? 1] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- MODALE DETTAGLI/MODIFICA TASK (UNICO MODALE USATO ANCHE PER CREAZIONE) -->
<div id="modal-dettagli-task" class="modal custom-modal" style="display:none;">
    <div class="pagina-foglio" style="max-width:760px; width:95vw;">
        <div class="view-form-header" style="--form-color:#ccc">
            <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                <h1 id="modale-task-title">Nuova Task</h1>
                <span class="close-modal" onclick="toggleModal('modal-dettagli-task', 'close')" style="font-size: 2rem; cursor:pointer;">&times;</span>
            </div>
            <hr class="form-title-divider" style="border: none; border-top: 3px solid #bbb; margin-top: 8px; margin-bottom: 24px;">
        </div>
        <form id="form-dettagli-task" class="view-form-grid task-details-form" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="task_id" id="dt-task-id">
            <input type="hidden" name="tabella" id="dt-tabella" value="<?= htmlspecialchars($tabella) ?>">
            <div class="form-section-title" style="grid-column: span 2; margin-bottom: 0;">Dati generali</div>
            <div>
                <label for="dt-titolo">Titolo</label>
                <input type="text" name="titolo" id="dt-titolo" required autocomplete="off">
            </div>
            <div>
                <label>Creato da</label>
                <?php
                $user_id = $_SESSION['user_id'] ?? 0;
                $nome = $user_id ? $database->getNominativoByUserId($user_id) : 'Utente';
                ?>
                <input type="text" id="dt-referente-nome" value="<?= htmlspecialchars($nome) ?>" disabled style="width:100%;">
            </div>
            <div>
                <label for="dt-stato">Stato</label>
                <select id="dt-stato" name="stato" required>
                    <option value="1">Da Definire</option>
                    <option value="2">Aperto</option>
                    <option value="3">In Corso</option>
                    <option value="4">Chiuso</option>
                </select>
            </div>
            <div>
                <label for="dt-spec">Disciplina</label>
                <select name="specializzazione" id="dt-spec" required>
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
                <label for="dt-fase-doc">Fase</label>
                <select name="fase_doc" id="dt-fase-doc">
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
                <label for="dt-priority">Priorità</label>
                <select name="priority" id="dt-priority" required>
                    <option value="Bassa">Bassa</option>
                    <option value="Media">Media</option>
                    <option value="Alta">Alta</option>
                </select>
            </div>
            <hr class="form-section-divider" style="grid-column: span 2;">
            <div class="form-section-title" style="grid-column: span 2; margin-bottom: 0;">Assegnazione e Scadenze</div>
            <div>
                <label for="dt-assegnato-a">Assegna a</label>
                <select name="assegnato_a" id="dt-assegnato-a">
                    <option value="">—</option>
                    <?php
                    // Recupera la lista dei partecipanti dall'organigramma
                    $orgRow = $database->query("SELECT organigramma FROM commesse_bacheche WHERE tabella = :t LIMIT 1", [':t' => $tabella], __FILE__)->fetch(PDO::FETCH_ASSOC);
                    $partecipanti = [];
                    function estraiPartecipanti($node, &$out) {
                        if (isset($node['user_id'])) $out[] = $node['user_id'];
                        if (!empty($node['children'])) foreach ($node['children'] as $child) estraiPartecipanti($child, $out);
                    }
                    if ($orgRow && !empty($orgRow['organigramma'])) {
                        $orgData = json_decode($orgRow['organigramma'], true);
                        estraiPartecipanti($orgData, $partecipanti);
                        $partecipanti = array_unique($partecipanti);
                    }
                    // Se vuoto, fallback a nessuno
                    if ($partecipanti) {
                        // Recupera i nominativi dei partecipanti
                        $in = implode(',', array_fill(0, count($partecipanti), '?'));
                        $pers = $database->query(
                            "SELECT user_id, Nominativo FROM personale WHERE user_id IN ($in) ORDER BY Nominativo ASC",
                            $partecipanti,
                            __FILE__
                        );
                        foreach ($pers as $p) {
                            echo '<option value="' . $p['user_id'] . '">' . htmlspecialchars($p['Nominativo']) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="dt-data-scadenza">Data Scadenza</label>
                <input type="date" name="data_scadenza" id="dt-data-scadenza">
            </div>
            <div>
                <label for="dt-data-apertura">Data apertura</label>
                <input type="date" name="data_apertura" id="dt-data-apertura" disabled>
            </div>
            <div>
                <label for="dt-chiusura">Data chiusura</label>
                <input type="date" name="data_chiusura" id="dt-chiusura" disabled>
            </div>
            <hr class="form-section-divider" style="grid-column: span 2;">
            <div class="form-section-title" style="grid-column: span 2; margin-bottom: 0;">Dettagli</div>
            <div style="grid-column: span 2;">
                <label for="dt-azione">Descrizione azione</label>
                <textarea name="descrizione_azione" id="dt-azione" style="width: 100%; min-height: 60px;"></textarea>
            </div>
            <div style="grid-column: span 2;">
                <label for="dt-path">Percorso documento</label>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <input type="text" name="path_allegato" id="dt-path" placeholder="Es: \\server\documenti\commessa123" style="flex: 1;">
                    <img src="assets/icons/copy.png" alt="Copia percorso" id="btn-copia-dt-path" title="Copia negli appunti"
                        style="cursor: pointer; width: 20px; height: 20px; opacity: 0.7;">
                </div>
            </div>
            <div style="grid-column: span 2;">
                <label>Screenshot (immagini)</label>
                <!-- Anteprima -->
                <div id="dettagli-task-image-preview" style="margin-bottom: 10px;"></div>
                <!-- Upload -->
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

<script src="/assets/js/modules/kanban_renderer.js"></script>
<script src="/assets/js/commesse/commessa_task.js"></script>
