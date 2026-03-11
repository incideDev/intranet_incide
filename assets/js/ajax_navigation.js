document.addEventListener("DOMContentLoaded", () => {
    const mainContainer = document.querySelector(".main-container");
    const sidebarLinks = document.querySelectorAll(".fixed-sidebar a");

    // Funzione per caricare la pagina via AJAX
    function loadPage(url) {
        // Evita richieste duplicate o URL non validi
        if (!url) return;

        const formattedUrl = formatUrl(url);

        fetch(formattedUrl + "&ajax=true")
            .then((response) => {
                if (!response.ok) throw new Error("Errore nel caricamento della pagina");
                return response.text();
            })
            .then((html) => {
                if (mainContainer) {
                    mainContainer.innerHTML = html;
                    history.pushState(null, "", formattedUrl); // Aggiorna URL senza ricaricare
                }
            })
            .catch((error) => {
                console.error("Errore:", error);
                if (mainContainer) {
                    mainContainer.innerHTML = "<p>Errore durante il caricamento della pagina.</p>";
                }
            });
    }

    // Funzione per assicurarsi che l'URL includa il parametro 'page'
    function formatUrl(url) {
        const urlObj = new URL(url, window.location.origin);
        const section = urlObj.searchParams.get("section");
        const page = urlObj.searchParams.get("page");

        if (!page && section) {
            const defaultPages = {
                archivio: "archivio",
                collaborazione: "segnalazioni_dashboard",
                "area-tecnica": "standard_progetti",
                gestione: "gare",
            };

            const defaultPage = defaultPages[section] || "home";
            urlObj.searchParams.set("page", defaultPage);
        }

        return urlObj.toString();
    }

    // Gestione dei link nella sidebar
    sidebarLinks.forEach((link) => {
        link.addEventListener("click", (event) => {
            event.preventDefault();
            const url = link.getAttribute("href");
            loadPage(url);
        });
    });

    // Supporto per il tasto indietro
    window.addEventListener("popstate", () => {
        loadPage(location.href);
    });
});
