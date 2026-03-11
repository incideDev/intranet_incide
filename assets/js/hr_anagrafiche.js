document.addEventListener("DOMContentLoaded", function() {
    // Funzione per cambiare scheda
    window.openTab = function(evt, tabName) {
        const tabContents = document.querySelectorAll(".tabcontent");
        tabContents.forEach(tab => tab.style.display = "none");

        const tabLinks = document.querySelectorAll(".tablinks");
        tabLinks.forEach(link => link.classList.remove("active"));

        document.getElementById(tabName).style.display = "block";
        evt.currentTarget.classList.add("active");
    };

    // Modal gestione per Aree
    window.openAddAreaModal = function() {
        document.getElementById("addAreaModal").style.display = "block";
    };

    window.closeAddAreaModal = function() {
        document.getElementById("addAreaModal").style.display = "none";
    };

    // Modal gestione per Competenze
    window.openAddCompetenzaModal = function() {
        fetch("/api/hr/anagrafiche/get_areas.php")
            .then(response => response.json())
            .then(aree => {
                const competenzaAreaSelect = document.getElementById("competenzaArea");
                competenzaAreaSelect.innerHTML = ""; // Svuota il select
                aree.forEach(area => {
                    const option = document.createElement("option");
                    option.value = area.id;
                    option.textContent = area.nome;
                    competenzaAreaSelect.appendChild(option);
                });
                document.getElementById("addCompetenzaModal").style.display = "block";
            })
            .catch(err => console.error("Errore nel caricamento delle aree:", err));
    };

    window.closeAddCompetenzaModal = function() {
        document.getElementById("addCompetenzaModal").style.display = "none";
    };

    // Funzione per caricare le aree nella tabella
    function loadAree() {
        fetch("/api/hr/anagrafiche/get_areas.php")
            .then(response => response.json())
            .then(data => {
                const tableBody = document.querySelector("#aree-table tbody");
                tableBody.innerHTML = ""; // Svuota la tabella prima di popolarla

                if (data.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="2">Nessuna area trovata.</td></tr>';
                } else {
                    data.forEach(area => {
                        const row = `
                            <tr>
                                <td>${area.nome}</td>
                                <td>
                                    <button class="btn-action btn-delete" onclick="deleteArea(${area.id})">Elimina</button>
                                </td>
                            </tr>`;
                        tableBody.innerHTML += row;
                    });
                }
            })
            .catch(err => console.error("Errore nel caricamento delle aree:", err));
    }

    // Funzione per aggiungere un'area
    window.addArea = function(event) {
        event.preventDefault();
        
        // ✅ Controlla se l'elemento esiste prima di accedere al valore
        const areaNameInput = document.getElementById("areaName");
        
        if (!areaNameInput) {
            console.error("Elemento #areaName non trovato!");
            alert("Errore interno: il campo di input non è disponibile.");
            return;
        }

        const areaName = areaNameInput.value.trim();

        if (!areaName) {
            alert("Inserisci un nome per l'area.");
            return;
        }

        fetch("/api/hr/anagrafiche/add_area.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({ nome: areaName })
        })
        .then(response => {
            if (!response.ok) throw new Error("Errore nella richiesta.");
            return response.json();
        })
        .then(data => {
            if (data.success) {
                alert("Area aggiunta con successo.");

                // ✅ Pulisce l'input SOLO se l'aggiunta è andata a buon fine
                areaNameInput.value = "";

                loadAree(); // Ricarica la tabella
            } else {
                console.error("Errore ricevuto:", data);
                alert("Errore durante l'aggiunta dell'area: " + (data.error || "Errore sconosciuto."));
            }
        })
        .catch(err => {
            console.error("Errore nella richiesta:", err);
            alert("Errore di connessione. Controlla la console.");
        });
    };


    // Funzione per eliminare un'area
    window.deleteArea = function(id) {
        if (!confirm("Sei sicuro di voler eliminare quest'area?")) return;

        fetch("/api/hr/anagrafiche/delete_area.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    id
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert("Area eliminata con successo.");
                    loadAree();
                } else {
                    alert("Errore durante l'eliminazione dell'area.");
                }
            })
            .catch(err => console.error("Errore nella richiesta:", err));
    };

    function loadCompetenze() {
    fetch("/api/hr/anagrafiche/get_areas.php")
        .then(response => response.json())
        .then(aree => {
            const tabLinksContainer = document.getElementById("tab-links-container");
            const tabContentContainer = document.getElementById("tab-content-container");

            // Svuota i contenitori prima di popolare
            tabLinksContainer.innerHTML = "";
            tabContentContainer.innerHTML = "";

            // Aggiunge una scheda e contenitore per ogni area
            aree.forEach((area, index) => {
                // Crea il link per la scheda
                const tabLink = document.createElement("li");
                tabLink.innerHTML = `<a href="#" class="${index === 0 ? 'active' : ''}" data-tab="tab-${area.id}">${area.nome}</a>`;
                tabLinksContainer.appendChild(tabLink);

                // Crea il contenuto della scheda
                const tabContent = document.createElement("div");
                tabContent.id = `tab-${area.id}`;
                tabContent.className = `tab-content ${index === 0 ? 'active' : ''}`;
                tabContent.innerHTML = `
                    <table class="hr-table">
                        <thead>
                            <tr>
                                <th>Competenza</th>
                                <th>Descrizione</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="competence-body-${area.id}">
                            <!-- Le competenze verranno aggiunte dinamicamente -->
                        </tbody>
                    </table>`;
                tabContentContainer.appendChild(tabContent);

                // Carica le competenze per l'area corrente
                fetch(`/api/hr/anagrafiche/get_competenze.php?area_id=${area.id}`)
                    .then(response => response.json())
                    .then(data => {

                        const competenceBody = document.getElementById(`competence-body-${area.id}`);
                        competenceBody.innerHTML = ""; // Pulizia tabella prima di popolare

                        if (!data || typeof data !== "object" || !Array.isArray(data.data)) {
                            console.error(`Errore nel formato dati per l'area ${area.id}:`, data);
                            competenceBody.innerHTML = '<tr><td colspan="3">Errore nel caricamento delle competenze.</td></tr>';
                            return;
                        }
                        if (data.data.length === 0) {
                            competenceBody.innerHTML = '<tr><td colspan="3">Nessuna competenza trovata.</td></tr>';
                        } else {
                            data.data.forEach(comp => {
                                const row = `
                                    <tr>
                                        <td>${comp.nome}</td>
                                        <td>${comp.descrizione || 'N/A'}</td>
                                        <td>
                                            <button class="btn-action btn-edit" onclick="editCompetenza(${comp.id})">Modifica</button>
                                            <button class="btn-action btn-delete" onclick="deleteCompetenza(${comp.id})">Elimina</button>
                                        </td>
                                    </tr>`;
                                competenceBody.innerHTML += row;
                            });
                        }
                    })
                    .catch(err => console.error(`Errore nel caricamento delle competenze per l'area ${area.id}:`, err));

            });

            // Aggiungi gestione clic per le schede
            document.querySelectorAll(".tab-links a").forEach(link => {
                link.addEventListener("click", function (e) {
                    e.preventDefault();
                    const targetTab = this.getAttribute("data-tab");

                    // Cambia scheda attiva
                    document.querySelectorAll(".tab-links a").forEach(a => a.classList.remove("active"));
                    document.querySelectorAll(".tab-content").forEach(content => content.classList.remove("active"));

                    this.classList.add("active");
                    document.getElementById(targetTab).classList.add("active");
                });
            });
        })
        .catch(err => console.error("Errore nel caricamento delle aree:", err));
}

    function scrollTabs(direction) {
        const tabLinksContainer = document.getElementById("tab-links-container");
        const scrollAmount = 150; // Quantità di scorrimento in pixel

        if (direction === "left") {
            tabLinksContainer.scrollBy({
                left: -scrollAmount,
                behavior: "smooth"
            });
        } else if (direction === "right") {
            tabLinksContainer.scrollBy({
                left: scrollAmount,
                behavior: "smooth"
            });
        }
    }

    // Esponi la funzione nel contesto globale
    window.scrollTabs = scrollTabs;


    // Aggiorna lo stato delle frecce in base alla posizione dello scroll
    function updateArrowState() {
        const tabLinksContainer = document.getElementById("tab-links-container");
        const leftArrow = document.querySelector(".tab-arrow.left-arrow");
        const rightArrow = document.querySelector(".tab-arrow.right-arrow");

        const scrollLeft = Math.round(tabLinksContainer.scrollLeft);
        const maxScrollLeft = Math.round(tabLinksContainer.scrollWidth - tabLinksContainer.clientWidth);

        leftArrow.disabled = scrollLeft <= 0;
        rightArrow.disabled = scrollLeft >= maxScrollLeft;
    }

    // Inizializza le frecce e il comportamento delle schede
    document.addEventListener("DOMContentLoaded", function() {
        const tabLinksContainer = document.getElementById("tab-links-container");

        // Aggiorna lo stato delle frecce al caricamento
        updateArrowState();

        // Aggiorna lo stato delle frecce durante lo scroll
        tabLinksContainer.addEventListener("scroll", updateArrowState);

        // Assegna le funzioni alle frecce
        document.querySelector(".tab-arrow.left-arrow").addEventListener("click", function() {
            scrollTabs("left");
        });
        document.querySelector(".tab-arrow.right-arrow").addEventListener("click", function() {
            scrollTabs("right");
        });
    });

    window.addCompetenza = function(event) {
        event.preventDefault();

        const competenzaArea = document.getElementById("competenzaArea").value;
        const competenzaName = document.getElementById("competenzaName").value;
        const competenzaDescrizione = document.getElementById("competenzaDescrizione").value;

        if (!competenzaArea || !competenzaName || !competenzaDescrizione) {
            alert("Compila tutti i campi richiesti.");
            return;
        }

        fetch("/api/hr/anagrafiche/add_area_competence.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                area_id: parseInt(competenzaArea, 10),
                nome: competenzaName,
                descrizione: competenzaDescrizione
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Competenza aggiunta con successo.");
                document.getElementById("competenzaName").value = "";
                document.getElementById("competenzaDescrizione").value = "";
                loadCompetenze(); // Ricarica la lista delle competenze
                closeAddCompetenzaModal();
            } else {
                console.error("Errore: ", data);
                alert("Errore durante l'aggiunta della competenza: " + data.message);
            }
        })
        .catch(err => console.error("Errore nella richiesta:", err));
    };

    window.deleteCompetenza = function(competenzaId) {
        if (!competenzaId) {
            alert("ID competenza mancante.");
            return;
        }

        if (!confirm("Sei sicuro di voler eliminare questa competenza?")) {
            return;
        }

        fetch("/api/hr/anagrafiche/remove_competence.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    id: competenzaId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert("Competenza eliminata con successo.");
                    loadCompetenze(); // Ricarica la lista delle competenze
                } else {
                    alert("Errore durante l'eliminazione della competenza: " + data.message);
                }
            })
            .catch(err => console.error("Errore nella richiesta:", err));
    };

    // Inizializza la scheda predefinita
    const defaultTab = document.querySelector(".tablinks.active");
    if (defaultTab) {
        const tabName = defaultTab.getAttribute("onclick").match(/'([^']+)'/)[1];
        document.getElementById(tabName).style.display = "block";
    }

    // Caricamento iniziale
    loadAree();
    loadCompetenze();
});

window.editCompetenza = function(competenzaId) {
    // Trova la riga corrispondente
    const row = document.querySelector(`[data-id='${competenzaId}']`);
    if (!row) {
        console.error(`Riga con ID competenza ${competenzaId} non trovata.`);
        return;
    }

    const nomeCell = row.querySelector(".competenza-nome");
    const descrizioneCell = row.querySelector(".competenza-descrizione");
    const azioniCell = row.querySelector(".azioni");

    if (!nomeCell || !descrizioneCell || !azioniCell) {
        console.error("Elementi della riga mancanti.");
        return;
    }

    // Salva i valori attuali
    const currentName = nomeCell.textContent.trim();
    const currentDescrizione = descrizioneCell.textContent.trim();

    // Trasforma le celle in campi input
    nomeCell.innerHTML = `<input type="text" value="${currentName}" class="edit-competenza-nome">`;
    descrizioneCell.innerHTML = `<input type="text" value="${currentDescrizione}" class="edit-competenza-descrizione">`;

    // Modifica i pulsanti in "Salva" e "Annulla"
    azioniCell.innerHTML = `
        <button class="btn-action btn-save" onclick="saveCompetenza(${competenzaId})">Salva</button>
        <button class="btn-action btn-cancel" onclick="cancelEditCompetenza(${competenzaId}, '${currentName}', '${currentDescrizione}')">Annulla</button>
    `;
};

window.saveCompetenza = function(competenzaId) {
    const row = document.querySelector(`[data-id='${competenzaId}']`);
    const nomeCell = row.querySelector(".competenza-nome");
    const descrizioneCell = row.querySelector(".competenza-descrizione");
    const azioniCell = row.querySelector(".azioni");

    // Recupera i nuovi valori dai campi input
    const newName = nomeCell.querySelector(".edit-competenza-nome").value.trim();
    const newDescrizione = descrizioneCell.querySelector(".edit-competenza-descrizione").value.trim();

    if (!newName) {
        alert("Il nome della competenza non può essere vuoto.");
        return;
    }

    // Invia i dati aggiornati al server
    fetch("/api/hr/anagrafiche/update_competence.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                id: competenzaId,
                nome: newName,
                descrizione: newDescrizione
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Competenza aggiornata con successo.");

                // Ripristina le celle in modalità visualizzazione
                nomeCell.textContent = newName;
                descrizioneCell.textContent = newDescrizione;
                azioniCell.innerHTML = `
                    <button class="btn-action btn-edit" onclick="editCompetenza(${competenzaId})">Modifica</button>
                    <button class="btn-action btn-delete" onclick="deleteCompetenza(${competenzaId})">Elimina</button>
                `;
            } else {
                alert("Errore durante l'aggiornamento della competenza: " + data.message);
            }
        })
        .catch(err => console.error("Errore nella richiesta:", err));
};

window.cancelEditCompetenza = function(competenzaId, originalName, originalDescrizione) {
    const row = document.querySelector(`[data-id='${competenzaId}']`);
    const nomeCell = row.querySelector(".competenza-nome");
    const descrizioneCell = row.querySelector(".competenza-descrizione");
    const azioniCell = row.querySelector(".azioni");

    // Ripristina i valori originali
    nomeCell.textContent = originalName;
    descrizioneCell.textContent = originalDescrizione;
    azioniCell.innerHTML = `
        <button class="btn-action btn-edit" onclick="editCompetenza(${competenzaId})">Modifica</button>
        <button class="btn-action btn-delete" onclick="deleteCompetenza(${competenzaId})">Elimina</button>
    `;
};