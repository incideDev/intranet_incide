export function addColumnSearch(table) {
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
                .attr('placeholder', 'Cerca...')
                .css({
                    width: '100%',
                    boxSizing: 'border-box',
                    padding: '5px',
                    border: 'none', // Rimuove i bordi
                    background: 'transparent', // Sfondo trasparente
                    outline: 'none' // Rimuove il contorno focus
                })
                .on('keyup change', function () {
                    const value = $(this).val();
                    column.search(value, false, false).draw(); // Ricerca case-insensitive
                });

            // Inserisce l'input nella cella senza margini esterni
            $('<th>')
                .css({ padding: 0 }) // Rimuove il padding del contenitore
                .append($input)
                .appendTo($searchRow);
        } else {
            $('<th>')
                .css({ padding: 0 }) // Cella vuota con padding rimosso
                .appendTo($searchRow);
        }
    });
}

export function removeColumnSearch() {
    // Rimuove la riga delle barre di ricerca
    $('.filters').remove();

    // Rimuove l'evento associato
    $(document).off('keyup', '.column-search');
}
