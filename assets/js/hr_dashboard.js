document.addEventListener("DOMContentLoaded", () => {
    // Verifica se gli elementi della dashboard esistono
    const jobProfilesCounter = document.getElementById("jobProfilesCount");
    const openSearchCounter = document.getElementById("openSearchCount");
    const candidatesCounter = document.getElementById("candidatesCount");

    // Se gli elementi non esistono, esci dal codice
    if (!jobProfilesCounter || !openSearchCounter || !candidatesCounter) {
        return;
    }

    // Se gli elementi esistono, carica i dati
    fetch("/api/hr/get_dashboard_data.php")
        .then((response) => {
            if (!response.ok) throw new Error("Errore nella risposta del server");
            return response.json();
        })
        .then((data) => {
            if (data.success) {
                jobProfilesCounter.querySelector("span").textContent = data.jobProfilesCount || 0;
                openSearchCounter.querySelector("span").textContent = data.openSearchCount || 0;
                candidatesCounter.querySelector("span").textContent = data.candidatesCount || 0;
            } else {
                console.error("Errore: " + data.message);
            }
        })
        .catch((error) => console.error("Errore nel caricamento dei contatori:", error));
});
