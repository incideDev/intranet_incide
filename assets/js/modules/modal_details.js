export function openDetailModal(id, type) {
    console.log(`🟢 Opening modal for ${type} with ID: ${id}`);

    let modal = document.getElementById("modalDetail");
    if (!modal) {
        console.error("❌ Modal not found.");
        return;
    }

    modal.style.display = "block";

    let taskElement = document.getElementById(`task-${id}`);
    let statusId = null;
    let columnColor = null;

    if (taskElement) {
        statusId = taskElement.getAttribute("data-status-id");
    }

    if (statusId) {
        let column = document.querySelector(`.kanban-column[data-status-id="${statusId}"]`);
        if (column) {
            columnColor = window.getComputedStyle(column).getPropertyValue("--header-color");
        }
    }

    if (!columnColor) {
        console.warn(`⚠️ Colonna Kanban con status_id=${statusId} non trovata.`);
    } else {
        // ✅ Imposta il colore dinamicamente per il modal-header
        document.documentElement.style.setProperty("--modal-header-color", columnColor);
        let modalHeader = document.querySelector(".modal-header");
        if (modalHeader) {
            modalHeader.style.borderBottom = `3px solid ${columnColor}`;
        }
    }

    let apiUrl = `/api/gare/get_details.php?id=${id}&type=${type}`;
    let viewUrl = `/views/includes/${type === "task" ? "task_management/task_details.php" : "gare/gare_details.php"}`;

    console.log(`📡 Fetching API: ${apiUrl}`);

    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            console.log("📡 API Response:", data);
            if (data.error) {
                throw new Error(`❌ API Error: ${data.error}`);
            }

            return fetch(viewUrl)
                .then(response => response.text())
                .then(viewContent => {
                    console.log(`📡 Loading view from: ${viewUrl}`);
                    document.getElementById("modal-dynamic-content").innerHTML = viewContent;

                    if (type === "task") {
                        populateTaskDetails(data);
                    } else {
                        populateGaraDetails(data);
                    }

                    // ✅ Dopo il caricamento, assegna il colore direttamente
                    let modalHeader = document.querySelector(".modal-header");
                    if (modalHeader) {
                        let headerColor = data.status_color || columnColor || "#3498db"; // Default se qualcosa va storto
                        document.documentElement.style.setProperty("--modal-header-color", headerColor);
                        modalHeader.style.borderBottom = `3px solid ${headerColor}`;
                        console.log(`🎨 Modal color set to ${headerColor}`);
                    }
                });
        })
        .catch(error => {
            console.error("❌ Error fetching details:", error.message);
            document.getElementById("modal-dynamic-content").innerHTML = "<p>Errore nel caricamento dei dettagli.</p>";
        });
}

export function closeModal() {
    let modal = document.getElementById("modalDetail");
    if (modal) {
        modal.style.display = "none";
    }
}

// ✅ Unico Event Listener per il doppio click sulle `.task`
document.addEventListener("dblclick", function (event) {
    let taskElement = event.target.closest(".task");

    if (!taskElement) return; // ✅ Se il click non è su una .task, esce subito

    let idAttr = taskElement.getAttribute("id");

    if (!idAttr || !idAttr.includes("task-")) {
        console.warn("⚠️ Gara senza ID valido, modale non aperto.");
        return;
    }

    let id = idAttr.replace("task-", "").trim();
    if (!id || id === "new") {
        console.warn("⚠️ La gara non è ancora stata salvata, impossibile aprire il dettaglio.");
        return;
    }

    let tipo = document.querySelector(".kanban-container")?.getAttribute("data-tipo") || "task";

    console.log(`🟢 Doppio click su ID: ${id}, Tipo: ${tipo}`);

    if (typeof openDetailModal === "function") {
        openDetailModal(id, tipo);
    } else {
        console.error("❌ `openDetailModal` NON è definita!");
    }
});

export function populateTaskDetails(data) {
    console.log("📌 Popolando i dettagli della Task:", data);

    document.getElementById("modal-title").innerText = data.titolo || "Titolo non disponibile";
    document.getElementById("task-status").value = data.status_id || "";
    document.getElementById("task-start-date").value = data.data_inizio || "";
    document.getElementById("task-end-date").value = data.data_fine || "";
    document.getElementById("task-details").value = data.descrizione || "";
}

export function populateGaraDetails(data) {
    console.log("📌 Popolando i dettagli della Gara:", data);

    let gara = data.data; // ✅ Prende i dati dall'oggetto `data`

    document.getElementById("modal-title").innerText = gara.titolo || "Titolo non disponibile";
    document.getElementById("gara-ente").innerText = gara.ente || "N/A";
    document.getElementById("gara-settore").innerText = gara.settore || "N/A";
    document.getElementById("gara-tipologia").innerText = gara.tipologia || "N/A";
    document.getElementById("gara-data-uscita").innerText = gara.data_uscita || "N/A";
    document.getElementById("gara-data-scadenza").innerText = gara.data_scadenza || "N/A";
    document.getElementById("gara-luogo").innerText = gara.luogo || "N/A";
    document.getElementById("gara-sopralluogo").innerText = gara.sopralluogo || "N/A";
    document.getElementById("gara-termini-chiarimenti").innerText = gara.termini_chiarimenti || "N/A";
    document.getElementById("gara-importo-lavori").innerText = gara.importo_lavori ? `${parseFloat(gara.importo_lavori).toLocaleString("it-IT", { style: "currency", currency: "EUR" })}` : "N/A";
    document.getElementById("gara-importo-parcella").innerText = gara.importo_parcella ? `${parseFloat(gara.importo_parcella).toLocaleString("it-IT", { style: "currency", currency: "EUR" })}` : "N/A";
    
    let linkPortale = document.getElementById("gara-link-portale");
    linkPortale.href = gara.link_portale || "#";
    linkPortale.innerText = gara.link_portale || "N/A";

    document.getElementById("gara-categorie").innerText = gara.categorie_id_opere || "N/A";
    document.getElementById("gara-details").value = gara.descrizione || "";
}

window.openDetailModal = openDetailModal;
window.closeModal = closeModal;
