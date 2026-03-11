import { initializeTable } from './table/initializeTable.js';
import { addColumnFilters, setupColumnVisibilityMenu } from './table/filters.js';
import { openModal, setupModalEvents } from './table/modals.js';
import { exportTableToCSV } from './table/export.js';

$(document).ready(function () {
    const defaultConfig = {
        tableName: 'default_table',
        tableColumns: [
            { data: 'col1', title: 'Colonna 1' },
            { data: 'col2', title: 'Colonna 2' },
            { data: 'col3', title: 'Colonna 3' }
        ]
    };

    const tableName = window.tableName || defaultConfig.tableName;
    const tableColumns = window.tableColumns || defaultConfig.tableColumns;

    const config = {
        enableSearch: true,
        enableOrdering: false,
        pageLength: parseInt(localStorage.getItem('pageLength')) || 10,
        ajax: {
            url: `index.php?page=get_table_data&table=${tableName}`,
            dataSrc: function (json) {
                if (json.error) {
                    console.error("Errore nel caricamento dati:", json.error);
                    return [];
                }
                return json;
            }
        },
        columns: tableColumns
    };

    const table = initializeTable(config);

    // Aggiunge input di ricerca per ogni colonna
    addColumnSearch(table);

    // Aggiunge menù contestuale per visibilità colonne
    setupColumnVisibilityMenu(table, addColumnSearch);

    // Aggiunge icone filtro
    addColumnFilters(table);

    // Esportazione in CSV
    $('#exportCsvButton').click(() => exportTableToCSV('dati.csv'));

    // Gestione clic sulle righe per aprire il modale
    $('#universalTable tbody').on('click', 'tr', function () {
        const data = table.row(this).data();
        let modalContent = '';
        for (let key in data) {
            modalContent += `<p><strong>${key}:</strong> ${data[key]}</p>`;
        }
        openModal('infoModal', modalContent);
    });

    // Salva il numero di righe per pagina nel LocalStorage
    table.on('length.dt', function (e, settings, len) {
        localStorage.setItem('pageLength', len);
    });
});

function addColumnSearch(table) {
    console.log('Aggiunta o aggiornamento barre di ricerca');

    // Rimuove eventuali righe di ricerca precedenti
    $('#universalTable thead .filters').remove();

    // Aggiunge una nuova riga per gli input di ricerca
    const $searchRow = $('<tr class="filters"></tr>').appendTo('#universalTable thead');

    table.columns().every(function (index) {
        if (this.visible()) { // Solo colonne visibili
            const column = this;

            const $input = $('<input>')
                .attr('type', 'text')
                .attr('placeholder', `Cerca...`)
                .css({ width: '100%', boxSizing: 'border-box', padding: '5px' })
                .on('keyup change', function () {
                    const value = $(this).val();
                    column.search(value, false, false).draw(); // Ricerca case-insensitive
                });

            $('<th>').append($input).appendTo($searchRow);
        } else {
            $('<th>').appendTo($searchRow); // Cella vuota per colonne nascoste
        }
    });
}
