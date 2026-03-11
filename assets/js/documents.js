document.addEventListener("DOMContentLoaded", function() {
    const gridView = document.getElementById("gridView");
    const tableView = document.getElementById("tableView");
    const gridViewButton = document.getElementById("gridViewButton");
    const tableViewButton = document.getElementById("tableViewButton");

    // Controlla l'URL per vedere quale vista è attiva (griglia o tabella)
    const urlParams = new URLSearchParams(window.location.search);
    const currentView = urlParams.get('view') || 'grid'; // Di default 'grid'

    // Imposta la vista iniziale in base al parametro URL
    if (currentView === 'table') {
        gridView.style.display = "none";
        tableView.style.display = "block";
        gridViewButton.classList.remove("active");
        tableViewButton.classList.add("active");
    } else {
        gridView.style.display = "flex";
        tableView.style.display = "none";
        gridViewButton.classList.add("active");
        tableViewButton.classList.remove("active");
    }

    // Cambia vista a griglia
    gridViewButton.addEventListener("click", function() {
        gridView.style.display = "flex";
        tableView.style.display = "none";
        gridViewButton.classList.add("active");
        tableViewButton.classList.remove("active");
        updateUrl('view', 'grid');
    });

    // Cambia vista a tabella
    tableViewButton.addEventListener("click", function() {
        gridView.style.display = "none";
        tableView.style.display = "block";
        gridViewButton.classList.remove("active");
        tableViewButton.classList.add("active");
        updateUrl('view', 'table');
    });

    // Funzione per aggiornare l'URL senza ricaricare la pagina
    function updateUrl(key, value) {
        const url = new URL(window.location);
        url.searchParams.set(key, value);
        window.history.pushState({ path: url.href }, '', url.href);
    }
});
