<?php
// Assicurati che $table_columns sia un array di array
if (!isset($table_columns) || !is_array($table_columns)) {
    error_log("Table columns non fornito o non valido, uso valori di fallback.");
    $table_columns = [
        ['data' => 'Colonna 1'],
        ['data' => 'Colonna 2'],
        ['data' => 'Colonna 3']
    ];  // Valore di fallback se non passato dal controller
}

// Aggiungi la variabile per gestire i documenti, di default è false
$isDocumentsTable = isset($isDocumentsTable) ? $isDocumentsTable : false;
?>

<div id="loadingSpinner" class="spinner" style="display: none;">
    <img src="assets/icons/spinner.gif" alt="Loading...">
</div>

<!-- Modal per la visualizzazione dei dettagli -->
<div id="infoModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h3>Dettagli dell'elemento</h3>
        <div id="modalContent" class="title">
            <!-- Qui mostreremo i dettagli -->
        </div>
    </div>
</div>

<div class="table-container">
    <!-- Barra di ricerca e icone sulla stessa riga -->
    <?php if (!isset($isDocumentsTable) || !$isDocumentsTable): ?>

    <div class="custom-controls" style="display: flex; justify-content: space-between; margin-bottom: 15px;">

        <div class="icon-container" right;>
            <img id="columnToggleIcon" src="assets/icons/plus.png" class="filter-icon" alt="Icona per modificare colonne">
            <img id="exportCsvButton" src="assets/icons/export.png" class="filter-icon" alt="Icona per esportare CSV" style="cursor: pointer;">
        </div>
    </div>
    <?php endif; ?>
<div class="table-container" style="overflow-x: auto;">
    <table id="universalTable" class="display nowrap" style="width:100%" data-is-documents-table="<?php echo $isDocumentsTable ? 'true' : 'false'; ?>">
    <thead>
        <tr>
            <?php foreach ($table_columns as $column): ?>
                <th draggable="true">
                    <div class="filter-container">
                        <span class="filter-text"><?php echo str_replace('_', ' ', $column['data']); ?></span>
                    </div>
                </th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php if (isset($documents) && is_array($documents)): ?>
            <?php foreach ($documents as $item): ?>
                <tr class="clickable-row" data-href="<?php echo is_dir($fullPath . '/' . $item['name']) ? 'index.php?page=' . htmlspecialchars($section) . '&path=' . urlencode($currentPath . $item['name'] . '/') . '&view=' . $currentView : $fullPath . '/' . $item['name']; ?>">
                    <td><?php echo htmlspecialchars($item['name']); ?></td>
                    <td><?php echo htmlspecialchars($item['type']); ?></td>
                    <td><?php echo htmlspecialchars($item['date']); ?></td> <!-- Colonna Data -->
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="3">Nessun documento trovato.</td>
            </tr>
        <?php endif; ?>
    </tbody>
    </table>
</div>
    <!-- Selettore del numero di righe e paginazione sulla stessa riga -->
    <div class="table-footer" style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px;">
        <!-- Selettore del numero di righe a sinistra -->
        <div class="row-selector" style="text-align: left;">
            <label for="custom-length" style="margin-right: 10px;">Mostra</label>
            <select name="customTable_length" aria-controls="universalTable" class="custom-length-input" id="custom-length" style="padding: 5px; border-radius: 5px; border: 1px solid #ccc;">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="-1">Tutto</option>
            </select>
        </div>
        <div id="pagination-controls" class="pagination-controls" style="text-align: right;">
            <!-- I pulsanti di navigazione (paginazione) verranno gestiti dinamicamente dal JS -->
        </div>
    </div>
</div>
</div>
<script>
    var tableName = "<?php echo $table_name ?? 'default_table'; ?>";
    var tableColumns = <?php echo json_encode($table_columns); ?>;

    // Aggiungi un listener per far sì che l'intera riga sia cliccabile
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.clickable-row').forEach(function(row) {
            row.addEventListener('click', function() {
                window.location.href = row.getAttribute('data-href');
            });
        });
    });
</script>

<script src="assets/js/jquery.min.js" defer></script>
<script src="assets/js/jquery-ui.min.js" defer></script>
<script src="assets/js/datatables.min.js" defer></script>
<script type="module" src="assets/js/table.js"></script>
