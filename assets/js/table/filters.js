export function addColumnFilters(table) {
    console.log("Aggiunta delle icone filtro alle colonne");

    $('#universalTable thead tr:first-child th').each(function (index) {
        const headerContent = $(this).text();

        $(this).html(`
            <div class="filter-header" style="display: flex; justify-content: space-between; align-items: center;">
                <span>${headerContent}</span>
                <img src="assets/icons/filter.png" class="filter-icon" data-column="${index}" style="cursor: pointer; margin-left: 5px;" />
            </div>
        `);
    });

    $('#universalTable').off('click', '.filter-icon').on('click', '.filter-icon', function (e) {
        e.stopPropagation();
        const columnIndex = $(this).data('column');
        const column = table.column(columnIndex);

        // Se il filtro è già attivo, rimuovilo
        if ($(this).hasClass('filter-active')) {
            console.log("Rimozione filtro per colonna:", columnIndex);
            column.search('').draw(); // Rimuove il filtro
            $(this).removeClass('filter-active'); // Rimuove lo stato attivo
            $('.filter-dropdown-dynamic').remove(); // Chiude il dropdown
            return;
        }

        console.log("Icona filtro cliccata");

        const uniqueValues = [...new Set(column.data().toArray())].sort();
        console.log("Valori univoci:", uniqueValues);

        $('.filter-dropdown-dynamic').remove(); // Rimuove eventuali dropdown esistenti

        if (uniqueValues.length === 0) {
            console.warn("Nessun valore da filtrare per la colonna:", columnIndex);
            return;
        }

        // Crea il dropdown con una barra di ricerca
        let dropdownContent = `
            <div class="filter-dropdown-dynamic">
                <div style="padding: 5px;">
                    <input type="text" class="filter-search" placeholder="Cerca..." style="width: 100%; padding: 5px; box-sizing: border-box;">
                </div>
        `;
        uniqueValues.forEach(value => {
            dropdownContent += `<div class="filter-item" data-value="${value}" style="padding: 5px; cursor: pointer;">${value}</div>`;
        });
        dropdownContent += `
            <div class="filter-item" data-value="" style="padding: 5px; cursor: pointer; font-weight: bold; color: red;">
                Rimuovi Filtro
            </div>
        </div>`;

        $('body').append(dropdownContent);
        const dropdown = $('.filter-dropdown-dynamic');

        const offset = $(this).offset();
        dropdown.css({
            top: offset.top + $(this).outerHeight(),
            left: offset.left,
        });

        dropdown.addClass('visible');

        // Evento per filtrare i valori dinamicamente
        $('.filter-search').off('keyup').on('keyup', function () {
            const searchValue = $(this).val().toLowerCase();
            $('.filter-item').each(function () {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.includes(searchValue));
            });
        });

        // Evento per selezionare un filtro
        $('.filter-item').off('click').on('click', function () {
            const value = $(this).data('value');
            console.log("Filtro selezionato:", value);

            if (value) {
                column.search(value).draw();
                $(`.filter-icon[data-column="${columnIndex}"]`).addClass('filter-active'); // Aggiungi stato attivo
            } else {
                column.search('').draw();
                $(`.filter-icon[data-column="${columnIndex}"]`).removeClass('filter-active'); // Rimuovi stato attivo
            }

            dropdown.remove();
        });

        // Chiude il dropdown cliccando fuori
        $(document).off('click').on('click', function (event) {
            if (!$(event.target).closest('.filter-dropdown-dynamic, .filter-icon').length) {
                dropdown.remove();
            }
        });
    });
}


export function setupColumnVisibilityMenu(table) {
    // Evento per aprire il menù contestuale con il tasto destro
    $(document).on('contextmenu', '#universalTable th', function (e) {
        e.preventDefault();

        // Rimuove eventuali menù aperti
        $('.column-menu').remove();

        // Crea il menù contestuale
        const $menu = $('<div class="column-menu"></div>');

        // Itera sulle colonne e aggiunge una checkbox per ogni colonna
        table.columns().every(function (index) {
            const column = this;
            const columnTitle = $(column.header()).text();
            const isVisible = column.visible();

            $menu.append(`
                <div class="menu-item" style="padding: 5px;">
                    <label style="display: flex; align-items: center;">
                        <input type="checkbox" class="toggle-column" data-column-index="${index}" ${isVisible ? 'checked' : ''}>
                        ${columnTitle}
                    </label>
                </div>
            `);
        });

        // Aggiunge il menù al DOM
        $('body').append($menu);
        $menu.css({
            position: 'absolute',
            top: e.pageY + 'px',
            left: e.pageX + 'px',
            background: 'white',
            border: '1px solid #ccc',
            borderRadius: '5px',
            boxShadow: '0px 4px 8px rgba(0, 0, 0, 0.2)',
            zIndex: 9999
        });

        // Gestisce il cambio di visibilità delle colonne
        $menu.off('change').on('change', '.toggle-column', function () {
            const columnIndex = $(this).data('column-index');
            const column = table.column(columnIndex);

            // Cambia la visibilità della colonna
            column.visible(!column.visible());

            // Aggiorna il localStorage per persistenza
            const visibleColumns = [];
            table.columns().every(function (index) {
                if (this.visible()) visibleColumns.push(index);
            });
            localStorage.setItem('visibleColumns', JSON.stringify(visibleColumns));
        });

        // Chiude il menù cliccando fuori
        $(document).off('click').on('click', function (event) {
            if (!$(event.target).closest('.column-menu').length) {
                $menu.remove();
            }
        });
    });

    // Recupera la visibilità delle colonne dal localStorage all'avvio
    const savedColumns = JSON.parse(localStorage.getItem('visibleColumns')) || [];
    table.columns().every(function (index) {
        this.visible(savedColumns.includes(index));
    });
}
