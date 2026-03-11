<?php
/**
 * Template Filtri Dinamici Riutilizzabile
 * 
 * Uso:
 * include 'views/components/filters_container.php';
 * 
 * Parametri opzionali (prima dell'include):
 * $filterConfig = [
 *     'table' => 'nome_tabella',
 *     'columns' => ['col1', 'col2'],
 *     'enablePeriod' => true
 * ];
 */

// Valori di default se non specificati
$filterConfig = $filterConfig ?? null;

if ($filterConfig && isset($filterConfig['table']) && isset($filterConfig['columns'])):
    $table = htmlspecialchars($filterConfig['table']);
    $columns = json_encode($filterConfig['columns']);
    $enablePeriod = $filterConfig['enablePeriod'] ?? false;
?>

<!-- FILTRI DINAMICI -->
<div id="filter-container" 
     data-table="<?= $table ?>" 
     data-filters='<?= $columns ?>'
     <?= $enablePeriod ? 'data-enable-period-filter="true"' : '' ?>
     style="margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px;">
    <!-- Popolato automaticamente da filters.js -->
</div>

<?php endif; ?>

