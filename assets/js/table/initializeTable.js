export function initializeTable(config) {
    const table = $('#universalTable').DataTable({
        pagingType: "simple_numbers",
        deferRender: true,
        paging: true,
        searching: config.enableSearch,
        ordering: false, // Disattivazione completa del riordino
        pageLength: parseInt(localStorage.getItem('pageLength') || 10),
        lengthChange: false,
        info: false,
        dom: 'lrtip',
        language: {
            paginate: {
                first: "Prima",
                last: "Ultima",
                next: "Prossima",
                previous: "Precedente"
            }
        },
        createdRow: function (row) {
            $('td', row).each(function () {
                $(this).addClass('subtitle');
            });
        },
        initComplete: function () {
            $('#universalTable tbody').addClass('loaded');
        },
        ajax: config.ajax || null,
        columns: config.columns || [],
        autoWidth: false, // Disattiva larghezza dinamica di DataTables
        columnDefs: [
            { targets: '_all', width: '200px', minWidth: '120px' } // Applica larghezze minime
        ]
    });

    $('#universalTable thead th').css('min-width', '120px'); // Forza la larghezza minima sulle intestazioni
    return table;
}
