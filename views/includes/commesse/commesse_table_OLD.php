<?php
if (!defined('hostdbdataconnector')) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

use Services\CommesseService;

$tabella = $_GET['tabella'] ?? null;
if (!$tabella) {
    echo "<div class='error'> tabella mancante.</div>";
    return;
}

$statimap = [
    1 => "DA DEFINIRE",
    2 => "APERTO",
    3 => "IN CORSO",
    4 => "CHIUSO"
];

$nomeFisicoTabella = 'com_' . strtolower(preg_replace('/[^a-z0-9_]/', '_', $tabella));
$tasks = \Services\CommesseService::gettasks($nomeFisicoTabella);
?>

<!-- Filtro dinamico future-ready -->
<div id="filter-container" 
    data-table="<?= htmlspecialchars($nomeFisicoTabella) ?>"
    data-filters='["titolo", "descrizione", "responsabile", "stato"]'>
</div>

<div id="table-view">
    <table class="table">
        <thead>
        <tr>
            <th>Azioni</th>
            <th>ID</th>
            <th>Titolo</th>
            <th>Descrizione</th>
            <th>Scadenza</th>
            <th>Referente</th>
            <th>Assegnato a</th>
            <th>Stato</th>
        </tr>
    </thead>
        <tbody>
            <?php foreach ($tasks as $task): ?>
                <tr>
                    <td>
                        <button class="action-icon" data-tooltip="Dettagli" onclick="loadTaskDetails(<?= $task['id'] ?>, '<?= htmlspecialchars($task['tabella']) ?>')">
                            <img src="assets/icons/show.png" alt="Dettagli" style="width:18px;height:18px;">
                        </button>
                    </td>
                    <td><?= htmlspecialchars($task['id']) ?></td>
                    <td><?= htmlspecialchars($task['titolo']) ?></td>
                    <td><?= htmlspecialchars($task['descrizione']) ?></td>
                    <td><?= !empty($task['data_scadenza']) ? date('d/m/Y', strtotime($task['data_scadenza'])) : '-' ?></td>
                    <td><?= htmlspecialchars($task['referente_nome'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($task['assegnato_a_nome'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($statimap[$task['stato']] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
