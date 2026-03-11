document.addEventListener('DOMContentLoaded', function() {
    // Aggiungi eventuali personalizzazioni per la tabella delle gare qui

    // Esempio: modifica della larghezza delle colonne
    const table = document.querySelector("#myTable");
    if (table) {
        const columns = table.querySelectorAll("th");
        columns.forEach((col) => {
            col.style.width = "150px"; // Imposta una larghezza personalizzata
        });
    }
});