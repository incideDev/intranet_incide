import { initializeTable } from './table/initializeTable.js';
import { addColumnFilters } from './table/filters.js';
import { openModal, setupModalEvents } from './table/modals.js';
import { exportTableToCSV } from './table/export.js';
import { addColumnSearch } from './table/search.js'; // Importa il modulo per le barre di ricerca

$(document).ready(function () {
    const tableElement = $('#universalTable');
    const tableName = window.tableName || 'default_table';

    if (!tableName) {
        console.error("Nome della tabella non fornito.");
        tableElement.after('<p class="error-message">Errore: Nome della tabella mancante.</p>');
        return;
    }

    console.log(`Caricamento dati per la tabella: ${tableName}`);

    // Caricamento dinamico di colonne e dati
    $.ajax({
        url: `index.php?page=get_table_data&table=${tableName}`,
        type: 'GET',
        dataType: 'json',
        beforeSend: function () {
            $('#loadingSpinner').show();
        },
        success: function (response) {
            console.log("Risposta AJAX ricevuta:", response);
            
            if (response.columns && response.data) {
                // Configurazione dinamica di DataTables
                const config = {
                    ajax: function (data, callback) {
                        callback({ data: response.data });
                    },
                    columns: response.columns,
                    ordering: false, // Disattiva completamente il riordino
                    paging: true, // Abilita paginazione
                    pageLength: 10, // Numero di righe per pagina
                    lengthChange: true, // Consenti all'utente di cambiare il numero di righe visibili
                    language: {
                        paginate: {
                            first: "Prima",
                            last: "Ultima",
                            next: "Prossima",
                            previous: "Precedente"
                        },
                        lengthMenu: "Mostra _MENU_ righe",
                        info: "Visualizzando da _START_ a _END_ di _TOTAL_ righe"
                    }
                };

                // Inizializza la tabella
                const table = initializeTable(config);

                // Aggiungi input di ricerca per ogni colonna
                addColumnSearch(table);

                // Aggiungi filtri alle colonne
                addColumnFilters(table);

                // Configura gli eventi per i modali
                setupModalEvents();

                // Aggiungi esportazione CSV
                $('#exportCsvButton').click(function () {
                    exportTableToCSV('dati.csv', 'universalTable');
                });

                // Apertura modale con dati della riga
                tableElement.on('click', 'tbody tr', function () {
                    const data = table.row(this).data();
                    let modalContent = '';

                    for (let key in data) {
                        modalContent += `<p><strong>${key}:</strong> ${data[key]}</p>`;
                    }

                    openModal('infoModal', modalContent);
                });
            } else {
                console.error("Errore: Mancano colonne o dati nella risposta.");
                tableElement.after('<p class="error-message">Errore durante il caricamento dei dati della tabella.</p>');
            }
        },
        error: function (xhr, status, error) {
            console.error("Errore nel caricamento dati:", error);
            tableElement.after('<p class="error-message">Errore durante la richiesta al server.</p>');
        },
        complete: function () {
            $('#loadingSpinner').hide();
        }
    });
});
