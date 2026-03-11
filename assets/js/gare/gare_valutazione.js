let isEditing = false;
let numeroGara = null;

document.addEventListener("DOMContentLoaded", () => {
    console.log(" Il file `gare_valutazione.js` è stato caricato correttamente!");
    document.getElementById("settore").addEventListener("change", updateTipologieAppalto);
    
    const params = new URLSearchParams(window.location.search);
    let idGara = params.get("id_gara");

    if (!idGara || isNaN(idGara) || idGara === "null" || idGara.trim() === "") {
        console.error(" ERRORE: ID Gara non valido o assente.");
        return;
    }

    idGara = parseInt(idGara, 10);
    console.log(`Recupero dati per Gara ID: ${idGara}`);

    document.getElementById("modalForm").addEventListener("click", function (event) {
        if (event.target === this) {
            chiudiModale();
        }
    });

customFetch('gare', 'getGaraSingola', { id_gara: idGara })
    .then(data => {
        console.log(" Dati ricevuti dalla API:", data);
        if (!data.success) {
            console.error(" Errore API:", data.message || data.error);
            return;
        }

        numeroGara = data.data.n_gara.replace(/\//g, '_');
        console.log(`DEBUG: Numero Gara salvato -> ${numeroGara}`);

        popolaDettagliGara(data.data);
    })
    .catch(error => console.error(" Errore nel recupero dati gara:", error));

console.log(`Inizio caricamento dati per Gara ID: ${idGara}`);

    Promise.all([
        fetchDati("dati_economici", popolaTabella, "economici-body", "dati_economici"),
        fetchDati("dati_tecnici", popolaTabella, "tecnici-body", "dati_tecnici"),
        fetchDati("criteri", popolaTabella, "criteri-body", "criteri")
    ])
    .then(() => console.log(" Tutti i dati della gara sono stati caricati!"))
    .catch(error => console.error("Errore nel caricamento dei dati della gara:", error));
    

    // ATTIVA LA PRIMA TAB (Dati Economici) SE NON C'È GIÀ UNA SELEZIONE
    const firstTabButton = document.querySelector(".tablinks[data-tab='DatiEconomici']");
    if (firstTabButton) {
        firstTabButton.classList.add("active");
        openTab(null, 'DatiEconomici');
    } else {
        console.warn("Nessun bottone tab trovato per Dati Economici.");
    }
});

function apriModale(scheda, idRecord = null) {
    console.log(`Aprendo modale per ${scheda} ${idRecord ? "(modifica)" : "(aggiunta)"}`);

    let titolo = idRecord ? "Modifica" : "Aggiungi Nuovo";
    document.getElementById("modal-title").textContent = `${titolo} ${scheda}`;

    // Salviamo l'ID del record nel modale
    let modal = document.getElementById("modalForm");
    modal.setAttribute("data-id", idRecord || "");

    // Puliamo il contenuto precedente e generiamo il form
    document.getElementById("modal-body").innerHTML = generaFormHtml(scheda, idRecord);

    // Mostriamo il modale
    modal.style.display = "block";
}

function chiudiModale() {
    console.log("Chiusura del modale");

    let modal = document.getElementById("modalForm");
    
    // Nasconde il modale correttamente
    modal.style.display = "none";

    // Svuota completamente il contenuto per evitare il secondo modale "fantasma"
    setTimeout(() => {
        document.getElementById("modal-title").textContent = ""; // Svuota il titolo
        document.getElementById("modal-body").innerHTML = ""; // Svuota il form
    }, 200); // Aspettiamo un piccolo delay per evitare glitch
}

// Genera il form HTML dinamicamente dentro il modale
function generaFormHtml(scheda, idRecord) {
    let datiPreesistenti = idRecord ? recuperaDati(scheda, idRecord) : {};

    let campi = {
        "dati_economici": ["parcella_base", "importo_lavori", "parcella_requisiti_progettazione", "requisiti_servizi_punta"],
        "dati_tecnici": ["importo_prestazioni", "requisiti_figure_professionali", "requisiti_capacita_economica", "requisiti_importo_servizi_tecnici", "requisiti_servizi_punta"],
        "criteri": ["categoria", "criterio", "elaborati", "punteggio"]
    }[scheda];

    return `
        <div class="modale-form-gara">
            ${campi.map(campo => `
                <div class="modale-form-group">
                    <label for="${campo}">${campo.replace('_', ' ').toUpperCase()}</label>
                    <input type="text" id="${campo}" value="${datiPreesistenti[campo] || ''}">
                </div>
            `).join("")}
        </div>
    `;
}

function recuperaDati(scheda, idRecord) {
    console.log(`🔍 Recupero dati per ${scheda}, ID: ${idRecord}`);

    let riga = document.querySelector(`[data-id="${idRecord}"]`);
    if (!riga) {
        console.error(`Nessuna riga trovata per ID ${idRecord} in ${scheda}`);
        return {};
    }

    let campi = {
        "dati_economici": ["parcella_base", "importo_lavori", "parcella_requisiti_progettazione", "requisiti_servizi_punta"],
        "dati_tecnici": ["importo_prestazioni", "requisiti_figure_professionali", "requisiti_capacita_economica", "requisiti_importo_servizi_tecnici", "requisiti_servizi_punta"],
        "criteri": ["categoria", "criterio", "elaborati", "punteggio"]
    }[scheda];

    let dati = {};
    let celle = riga.querySelectorAll("td");

    campi.forEach((campo, index) => {
        dati[campo] = celle[index] ? celle[index].textContent.trim() : "";
    });

    return dati;
}

function salvaDati() {
    let titoloModale = document.getElementById("modal-title").textContent;
    let scheda = titoloModale.split(" ").pop().toLowerCase().trim();
    let formData = {};
    const params = new URLSearchParams(window.location.search);
    let idGara = params.get("id_gara");
    if (!idGara || isNaN(idGara)) {
        console.error("ERRORE: ID Gara non valido o mancante.");
        return;
    }
    formData["id_gara"] = idGara;
    if (document.getElementById("azienda")) {
        formData["id_azienda"] = document.getElementById("azienda").value;
    }
    let idRecord = document.getElementById("modalForm").getAttribute("data-id");
    if (idRecord) formData["id"] = idRecord;

    document.querySelectorAll("#modal-body input, #modal-body select").forEach(input => {
        formData[input.id] = input.value.trim() === "" ? (input.id === "quota" ? "0" : "") : input.value;
    });

    let action = "";
    if (titoloModale.includes("Raggruppamento") || titoloModale.includes("Aggiungi Nuova Azienda")) {
        action = "insertRaggruppamento";
    } else if (titoloModale.includes("Aggiungi Nuovo")) {
        action = scheda === "criteri" ? "insertCriteri" : (scheda === "dati_economici" ? "insertDatiEconomici" : "insertDatiTecnici");
    } else {
        action = scheda === "criteri" ? "updateCriteri" : (scheda === "dati_economici" ? "updateDatiEconomici" : "updateDatiTecnici");
    }

    customFetch('gare', action, formData)
        .then(data => {
            if (data.success) {
                chiudiModale();
                const savedTab = sessionStorage.getItem("activeTab");
                if (savedTab === "RTP" || savedTab === "raggruppamento") {
                    loadRaggruppamento(idGara);
                } else {
                    caricaDatiGara(idGara, savedTab);
                }
            } else {
                console.error(`Errore nel salvataggio ${scheda}:`, data.message || data.error);
            }
        })
        .catch(error => console.error(`Errore nel salvataggio ${scheda}:`, error));
}

// Funzione per eliminare un record
function eliminaRecord(scheda, idRecord) {
    if (!confirm(`Sei sicuro di voler eliminare questo record da ${scheda}?`)) return;

    customFetch('gare', `delete${scheda.charAt(0).toUpperCase() + scheda.slice(1)}`, { id: idRecord })
        .then(data => {
            if (data.success) {
                const params = new URLSearchParams(window.location.search);
                let idGara = params.get("id_gara");
                const savedTab = sessionStorage.getItem("activeTab");
                caricaDatiGara(idGara, savedTab);
            } else {
                console.error(`Errore nell'eliminazione da ${scheda}:`, data.message || data.error);
            }
        })
    .catch(error => console.error(`Errore nella richiesta di eliminazione da ${scheda}:`, error));
}

// Rendi le funzioni disponibili globalmente
window.apriModale = apriModale;
window.chiudiModale = chiudiModale;
window.salvaDati = salvaDati;
window.eliminaRecord = eliminaRecord;

// Popola i dettagli della gara nella pagina
function popolaDettagliGara(dati) {
    console.log("Popolamento dettagli gara:", dati);

    document.getElementById("n_gara_titolo").textContent = dati.n_gara || "Numero Gara";
    document.getElementById("oggetto_appalto").value = dati.titolo || "";

    //Controlliamo se il campo tipo_lavori esiste
    let tipologiaGaraField = document.getElementById("tipologia_gara");
    if (tipologiaGaraField) {
        let option = tipologiaGaraField.querySelector(`option[value="${dati.tipo_lavori}"]`);
        if (option) {
            tipologiaGaraField.value = dati.tipo_lavori;
        } else {
            console.warn("Tipologia di gara non trovata nel `select`. Valore ricevuto:", dati.tipo_lavori);
        }
    } else {
        console.error("ERRORE: Campo 'tipologia_gara' non trovato nel DOM.");
    }    

    document.getElementById("stazione_appaltante").value = dati.ente || "";
    document.getElementById("data_uscita_gara").value = dati.data_uscita || "";
    document.getElementById("scadenza").value = dati.data_scadenza || "";
    document.getElementById("luogo").value = dati.luogo || "";
    document.getElementById("link_portale").value = dati.link_portale || "";
    let sopralluogoField = document.getElementById("sopralluogo");
    if (sopralluogoField) {
        sopralluogoField.value = ["Sì", "No"].includes(dati.sopralluogo) ? dati.sopralluogo : "No";
    }
    
    // Settore - Aspettiamo il caricamento del dropdown prima di impostare il valore
    let settoreDropdown = document.getElementById("settore");
    if (settoreDropdown) {
        let checkSettore = setInterval(() => {
            if (settoreDropdown.options.length > 1) {
                settoreDropdown.value = dati.settore || "";
                let option = settoreDropdown.querySelector(`option[value='${dati.settore}']`);
                if (option) {
                    option.setAttribute("selected", "selected");
                } else {
                    console.warn(`Opzione settore non trovata per ID: ${dati.settore}`);
                }
                clearInterval(checkSettore);

                // Richiama l'aggiornamento delle tipologie basato sul settore selezionato
                updateTipologieAppalto();

                // Tipologia Appalto - Mostra il nome corretto
                let tipologiaDropdown = document.getElementById("tipologia_appalto");
                if (tipologiaDropdown) {
                    setTimeout(() => { // Aspetta il caricamento delle opzioni
                        console.log("🔍 DEBUG: Valore di categorie_id_opere dalla API:", dati.categorie_id_opere);
                        console.log("🔍 DEBUG: Valori disponibili nel menu a tendina:", [...tipologiaDropdown.options].map(o => o.value));

                        tipologiaDropdown.value = dati.categorie_id_opere || "";
                        let option = tipologiaDropdown.querySelector(`option[value='${dati.categorie_id_opere}']`);
                        if (option) {
                            option.setAttribute("selected", "selected");
                            console.log(" Tipologia Appalto impostata correttamente:", option.textContent);
                        } else {
                            console.warn("Opzione tipologia appalto non trovata per ID:", dati.categorie_id_opere);
                        }
                    }, 500);
                }
            }
        }, 300);
    }

    console.log(" Dettagli della gara aggiornati!");
}

function caricaDatiGara(idGara, tab = null) {
    console.log(`Recupero dati per Gara ID: ${idGara}, Tab Attiva: ${tab}`);

    let richieste = [
        fetchDati("dati_economici", popolaTabella, "economici-body", "dati_economici"),
        fetchDati("dati_tecnici", popolaTabella, "tecnici-body", "dati_tecnici"),
        fetchDati("criteri", popolaTabella, "criteri-body", "criteri")
    ];

    // AGGIUNGIAMO RTP SOLO SE LA TAB ATTIVA È RTP
    if (tab === "RTP" || tab === "raggruppamento") {
        console.log(" Aggiornamento in tempo reale della tabella RTP...");
        richieste.push(customFetch('gare', 'getRaggruppamento', { id_gara: idGara })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    popolaTabellaRTP(data.data);
                } else {
                    console.warn("Nessun dato RTP trovato.");
                }
            })
            .catch(error => console.error(" Errore nel caricamento RTP:", error))
        );
    }

    Promise.all(richieste)
        .then(() => console.log(" Tutti i dati della gara sono stati caricati!"))
        .catch(error => console.error(" Errore nel caricamento dei dati della gara:", error));
}

function fetchDati(tipo, callback, ...args) {
    // tipo: 'dati_economici' | 'dati_tecnici' | 'criteri'
    const params = new URLSearchParams(window.location.search);
    let idGara = params.get("id_gara");
    let action = "";

    if (tipo === "dati_economici") action = "getDatiEconomici";
    if (tipo === "dati_tecnici") action = "getDatiTecnici";
    if (tipo === "criteri") action = "getCriteri";

    return customFetch('gare', action, { id_gara: idGara })
        .then(data => {
            if (!data.success || !data.data || data.data.length === 0) {
                console.warn(`⚠️ Nessun dato trovato per ${tipo}. La tabella rimarrà vuota.`);
                return;
            }
            callback(data.data, ...args);
        })
        .catch(error => console.error(`Errore nel recupero dati per ${tipo}:`, error));
}

// Popola la tabella in base alla categoria (economici, tecnici, criteri)
function popolaTabella(dati, tbodyId, scheda) {
    const tbody = document.getElementById(tbodyId);
    tbody.innerHTML = dati.map(dato => `
        <tr data-id="${dato.id}">
            <td class="azioni-colonna">
                <img src="assets/icons/edit.png" class="action-icon" alt="Modifica" onclick="apriModale('${scheda}', ${dato.id})">
                <img src="assets/icons/delete.png" class="action-icon" alt="Elimina" onclick="eliminaRecord('${scheda}', ${dato.id})">
            </td>
            ${generaCelleTabella(scheda, dato)}
        </tr>
    `).join("");

    console.log(` Tabella ${scheda} aggiornata!`);
}

// Genera le celle della tabella in base al tipo di dati (senza formattaValore)
function generaCelleTabella(scheda, dato) {
    let campi = {
        "dati_economici": ["parcella_base", "importo_lavori", "parcella_requisiti_progettazione", "requisiti_servizi_punta"],
        "dati_tecnici": ["importo_prestazioni", "requisiti_figure_professionali", "requisiti_capacita_economica", "requisiti_importo_servizi_tecnici", "requisiti_servizi_punta"],
        "criteri": ["categoria", "criterio", "elaborati", "punteggio"]
    }[scheda];

    return campi.map(campo => {
        let valore = dato[campo] || "N/A"; // Se il valore è null o undefined, usa "N/A"
        
        // Se è un numero sopra i 1000, formattalo come valuta
        if (!isNaN(valore) && parseFloat(valore) > 1000) {
            valore = parseFloat(valore).toLocaleString("it-IT", { style: "currency", currency: "EUR" });
        }

        return `<td>${valore}</td>`;
    }).join("");
}

function openTab(event, tabName) {
    console.log(`Cambio scheda a ${tabName}`);

    // Nascondi tutti i contenuti delle tab
    document.querySelectorAll(".tabcontent").forEach(tab => tab.style.display = "none");

    // Rimuovi la classe "active" da tutti i bottoni tab
    document.querySelectorAll(".tablinks").forEach(tab => tab.classList.remove("active"));

    // Mostra il contenuto della tab selezionata
    let selectedTab = document.getElementById(tabName);
    if (!selectedTab) {
        console.warn(`Nessun tab trovato con ID '${tabName}', verificare il nome.`);
        return;
    }
    selectedTab.style.display = "block";

    // Se la funzione è chiamata con `event`, attiva il bottone corretto
    if (event) {
        event.currentTarget.classList.add("active");
    } else {
        // Se `event` è null (come in `DOMContentLoaded`), attiva la tab giusta manualmente
        let tabButton = document.querySelector(`.tablinks[data-tab="${tabName}"]`);
        if (tabButton) {
            tabButton.classList.add("active");
        }
    }

    // Salviamo la tab attiva in sessionStorage
    sessionStorage.setItem("activeTab", tabName);
}

// Imposta il primo tab attivo di default o recupera dalla cache
document.addEventListener("DOMContentLoaded", () => {
    const savedTab = sessionStorage.getItem("activeTab");
    const firstTab = document.querySelector(".tablinks");

    if (savedTab && document.getElementById(savedTab)) {
        openTab(null, savedTab);
    } else if (firstTab) {
        firstTab.classList.add("active");
        openTab(null, firstTab.getAttribute("data-tab"));
    } else {
        console.warn("Nessun tab attivo trovato!");
    }
});

// Rendi la funzione disponibile globalmente
window.openTab = openTab;

document.addEventListener("DOMContentLoaded", () => {
    const editIcon = document.getElementById("editIcon");
    let isEditing = false;

    if (editIcon) {
        editIcon.addEventListener("click", () => {
            isEditing = !isEditing;

            // Abilita/disabilita i campi del form
            document.querySelectorAll(".editable-field, .editable-field textarea").forEach(field => {
                field.readOnly = !isEditing;
                field.classList.toggle("editing", isEditing);
            });

            // Cambia l'icona tra matita e dischetto
            editIcon.src = isEditing ? "assets/icons/save.png" : "assets/icons/edit.png";

            // Abilita/disabilita i select
            document.querySelectorAll("select").forEach(select => {
                select.disabled = !isEditing;
            });

            // Se si sta salvando, esegui il salvataggio
            if (!isEditing) {
                salvaModificheGara();
            }
        });
    } else {
        console.error(" ERRORE: L'elemento editIcon non è stato trovato nel DOM.");
    }
});

function salvaModificheGara() {
    const idGara = new URLSearchParams(window.location.search).get("id_gara");

    if (!idGara) {
        console.error(" ERRORE: ID Gara non valido.");
        return;
    }

    let formData = {
        id_gara: idGara,
        titolo: document.getElementById("oggetto_appalto").value.trim(),
        tipo_lavori: document.getElementById("tipologia_gara").value.trim(),
        settore: document.getElementById("settore").value.trim(),
        ente: document.getElementById("stazione_appaltante").value.trim(),
        data_uscita: document.getElementById("data_uscita_gara").value,
        data_scadenza: document.getElementById("scadenza").value,
        luogo: document.getElementById("luogo").value.trim(),
        tipologia_appalto: document.getElementById("tipologia_appalto").value.trim(),
        sopralluogo: document.getElementById("sopralluogo").value || "No",
        link_portale: document.getElementById("link_portale").value.trim()
    };

    console.log("📡 Invio dati al server:", formData);

    customFetch('gare', 'updateGara', formData)
        .then(data => {
            if (data.success) {
                console.log(" Modifiche salvate con successo.");

                // Rimuove la classe editing dopo il salvataggio
                document.querySelectorAll(".editable-field, .editable-field textarea").forEach(field => {
                    field.classList.remove("editing");
                    field.readOnly = true;
                });

                document.getElementById("editIcon").src = "assets/icons/edit.png";
            } else {
                console.error(" Errore nel salvataggio:", data.error);
                alert(" Errore nel salvataggio. Verifica i dati e riprova.");
            }
        })
        .catch(error => {
            console.error(" Errore di connessione:", error);
            alert(" Errore di connessione al server. Riprova più tardi.");
        });
}

// Caricamento dati dropdown
document.addEventListener("DOMContentLoaded", () => {
    loadDropdownData("settore", "getSettori");
    loadDropdownData("stazione_appaltante", "getStazioniAppaltanti");

    // Chiudiamo tutti i dropdown all'avvio
    document.querySelectorAll(".dropdown-menu").forEach(menu => menu.style.display = "none");

    // Chiudiamo i dropdown quando si clicca fuori
    document.addEventListener("click", function (event) {
        document.querySelectorAll(".dropdown-menu").forEach(menu => {
            if (!menu.contains(event.target) && !event.target.classList.contains("dropdown-arrow")) {
                menu.style.display = "none";
            }
        });
    });
});

function loadDropdownData(fieldId, action) {
    customFetch('gare', action)
        .then(data => {
            if (!data.success || !Array.isArray(data.data)) {
                console.error(`Errore nella risposta per '${fieldId}'`, data);
                return;
            }
            let dropdown;
            if (document.getElementById(fieldId) && document.getElementById(fieldId).tagName === "SELECT") {
                dropdown = document.getElementById(fieldId);
                dropdown.innerHTML = '<option value="">Seleziona</option>';
                data.data.forEach(item => {
                    let option = document.createElement("option");
                    option.value = item.id;
                    option.textContent = item.nome;
                    dropdown.appendChild(option);
                });
            } else if (document.getElementById("dropdown-" + fieldId)) {
                dropdown = document.getElementById("dropdown-" + fieldId);
                dropdown.innerHTML = "";
                if (data.data.length === 0) {
                    dropdown.innerHTML = "<div class='dropdown-item'>Nessun dato disponibile</div>";
                    return;
                }
                data.data.forEach(item => {
                    let option = document.createElement("div");
                    option.textContent = item.nome;
                    option.classList.add("dropdown-item");
                    option.onclick = () => {
                        document.getElementById(fieldId).value = item.nome;
                        dropdown.style.display = "none";
                    };
                    dropdown.appendChild(option);
                });
            } else {
                console.error(`Errore: Nessun dropdown trovato per '${fieldId}'`);
            }
            console.log(`Dropdown '${fieldId}' popolato con ${data.data.length} elementi.`);
        })
        .catch(error => console.error(`Errore nel caricamento dati per '${fieldId}':`, error));
}

// Aggiorna Tipologia Appalto basata su Settore
function updateTipologieAppalto() {
    let settoreId = document.getElementById("settore").value;

    if (!settoreId) {
        console.warn("Nessun settore selezionato, non aggiorno Tipologia Appalto.");
        document.getElementById("tipologia_appalto").innerHTML = '<option value="">Seleziona una tipologia</option>';
        return;
    }

    console.log(`Recupero Tipologie Appalto per Settore ID: ${settoreId}`);

    customFetch('gare', 'getPrestazioni', { settore_id: settoreId })
        .then(data => {
            if (!data.success || !Array.isArray(data.data)) {
            return;
        }
        let dropdown = document.getElementById("tipologia_appalto");
        dropdown.innerHTML = '<option value="">Seleziona una tipologia</option>';
        if (data.data.length === 0) {
            dropdown.innerHTML += '<option value="">Nessuna tipologia disponibile</option>';
            return;
        }
        data.data.forEach(item => {
            let option = document.createElement("option");
            option.value = item.id;
            option.textContent = item.nome;
            dropdown.appendChild(option);
        });
        console.log(`Tipologie Appalto aggiornate con ${data.data.length} elementi.`);
    })
    .catch(error => console.error("Errore nel caricamento delle Tipologie Appalto:", error));
}

// Funzione per l'autocomplete della Stazione Appaltante
function autocompleteAzienda() {
    let query = document.getElementById("stazione_appaltante").value;
    if (query.length < 2) {
        document.getElementById("autocomplete-stazione_appaltante").style.display = "none";
        return;
    }

    customFetch('gare', 'getAziende', { query })
        .then(data => {
            let list = document.getElementById("autocomplete-stazione_appaltante");
            list.innerHTML = "";
            list.style.display = "block";
            if (!data.success || !Array.isArray(data.data)) return;
            data.data.forEach(item => {
                let option = document.createElement("div");
                option.textContent = item.nome;
                option.onclick = () => {
                    document.getElementById("stazione_appaltante").value = item.nome;
                    list.style.display = "none";
                };
                list.appendChild(option);
            });
        })
        .catch(error => console.error("Errore nel caricamento aziende:", error));
}

// Autocomplete Provincia
function autocompleteProvincia() {
    let query = document.getElementById("luogo").value;
    if (query.length < 2) {
        document.getElementById("autocomplete-luogo").style.display = "none";
        return;
    }

    customFetch('gare', 'getProvince', { query })
        .then(data => {
            let list = document.getElementById("autocomplete-luogo");
            list.innerHTML = "";
            list.style.display = "block";
            if (!data.success || !Array.isArray(data.data)) return;
            data.data.forEach(item => {
                let option = document.createElement("div");
                option.textContent = `${item.nome} (${item.sigla})`;
                option.onclick = () => {
                    document.getElementById("luogo").value = item.nome;
                    list.style.display = "none";
                };
                list.appendChild(option);
            });
        })
        .catch(error => console.error("Errore nel caricamento province:", error));
}

// Toggle Dropdown
function toggleDropdown(fieldId) {
    if (!isEditing) return; // Blocca l'apertura se la modifica non è attiva

    let dropdown = document.getElementById("dropdown-" + fieldId);
    if (!dropdown) {
        console.error(` Errore: Nessun dropdown trovato con ID dropdown-${fieldId}`);
        return;
    }

    // Chiude altri dropdown aperti prima di aprire il nuovo
    document.querySelectorAll(".dropdown-menu").forEach(menu => {
        if (menu !== dropdown) menu.style.display = "none";
    });

    // Alterna la visibilità del dropdown selezionato
    dropdown.style.display = (dropdown.style.display === "block") ? "none" : "block";
}

document.addEventListener("DOMContentLoaded", () => {
    const uploadRow = document.getElementById("uploadRow");
    const fileInput = document.getElementById("fileInput");
    const dropzone = document.querySelector(".dropzone");
    const documentiBody = document.getElementById("documenti-body");
    const manualUploadTrigger = document.getElementById("manualUploadTrigger");

    if (!fileInput || !manualUploadTrigger || !dropzone) {
        console.error(" ERRORE: Elementi per il caricamento file non trovati nel DOM.");
        return;
    }

    // Trigger manuale per il file input
    manualUploadTrigger.addEventListener("click", () => {
        console.log("DEBUG: Click su bottone upload manuale");
        fileInput.click();
    });

    fileInput.addEventListener("change", (event) => {
        console.log("DEBUG: File selezionato tramite manual upload:", event.target.files);
        handleFileUpload(event);
    });

    // Drag & Drop sulla riga fissa
    dropzone.addEventListener("dragover", (e) => {
        e.preventDefault();
        dropzone.classList.add("dragover");
    });

    dropzone.addEventListener("dragleave", () => {
        dropzone.classList.remove("dragover");
    });

    dropzone.addEventListener("drop", (e) => {
        e.preventDefault();
        dropzone.classList.remove("dragover");
        console.log("DEBUG: File trascinato:", e.dataTransfer.files);
        handleFileUpload(e.dataTransfer);
    });

    // Gestione Upload
    function handleFileUpload(event) {
        const files = event.files || event.target.files;
        if (!files || files.length === 0) {
            console.warn("Nessun file selezionato per l'upload.");
            return;
        }
    
        const params = new URLSearchParams(window.location.search);
        let idGara = params.get("id_gara");
        if (!idGara) {
            alert("Errore: ID Gara non trovato.");
            return;
        }
    
        if (!numeroGara) {
            console.error(" ERRORE: Numero Gara non è stato ancora caricato.");
            return;
        }
    
        console.log(`Inizio upload per Gara: ${numeroGara}`);
    
        let uploadPromises = [];
    
        for (const file of files) {
            let formData = new FormData();
            formData.append("file", file);
            formData.append("n_gara", numeroGara);
    
            let uploadPromise = customFetch('gare', 'uploadDocumentoGara', { n_gara: numeroGara, file: file })
            .then(data => {
                if (data.success) {
                    console.log(` File "${file.name}" caricato con successo!`);
                } else {
                    console.error(" Errore durante l'upload:", data.error);
                    alert(" Errore: " + data.error);
                }
            })
            .catch(error => {
                console.error(" Errore durante il caricamento del file:", error);
                alert(" Errore durante il caricamento del file.");
            });
    
            uploadPromises.push(uploadPromise);
        }
    
        // Dopo che tutti i file sono stati caricati, aggiorniamo la tabella
        Promise.all(uploadPromises).then(() => {
            console.log("DEBUG: Tutti i file sono stati caricati, aggiornamento tabella...");
            loadDocumentiGara();
        });
    }
    
function addFileToTable(fileName, tipologia, uploadDate) {
    const tableBody = document.getElementById("documenti-body");
    if (!tableBody) return;

    const row = document.createElement("tr");
    row.innerHTML = `
        <td class="azioni-colonna">
            <img src="assets/icons/ia.png" class="action-icon ia-icon" alt="Leggi con AI" onclick="leggiConIA('${fileName}')">
            <img src="assets/icons/down.png" class="action-icon" alt="Scarica" onclick="downloadFile('${fileName}', numeroGara)">
            <img src="assets/icons/delete.png" class="action-icon" alt="Elimina" onclick="deleteFile('${fileName}', numeroGara, this)">
        </td>
        <td>${fileName}</td>
        <td>
            <select class="tipologia-select" onchange="aggiornaTipologia('${fileName}', this.value)">
                <option value="Bando di Gara" ${tipologia === "Bando di Gara" ? "selected" : ""}>Bando di Gara</option>
                <option value="Disciplinare" ${tipologia === "Disciplinare" ? "selected" : ""}>Disciplinare</option>
                <option value="Parcella" ${tipologia === "Parcella" ? "selected" : ""}>Parcella</option>
                <option value="Allegati Tecnici" ${tipologia === "Allegati Tecnici" ? "selected" : ""}>Allegati Tecnici</option>
            </select>
        </td>
        <td>${uploadDate}</td>
    `;
    tableBody.appendChild(row);
}

    // Assicuriamoci che `addFileToTable()` sia disponibile globalmente
    window.addFileToTable = addFileToTable;
    
    // Simulazione Download
    window.downloadFile = (fileName) => {
        alert(`Simulazione download: ${fileName}`);
    };

    window.deleteFile = (fileName, nGara, element) => {
        if (!confirm("Sei sicuro di voler eliminare questo documento?")) return;
        customFetch('gare', 'deleteDocumentoGara', { file_name: fileName, n_gara: nGara })
            .then(data => {
                if (data.success) {
                    element.closest("tr").remove();
                } else {
                    alert("Errore: " + (data.error || data.message));
                }
            })
            .catch(error => alert("Errore di connessione!"));
    };
});

function leggiConIA(fileName) {
    alert(`📖 AI sta leggendo il documento: ${fileName}`);
}

function aggiornaTipologia(fileName, nuovaTipologia) {
    console.log(`DEBUG: Aggiornamento tipologia per ${fileName} a ${nuovaTipologia}`);

    const params = new URLSearchParams(window.location.search);
    let idGara = params.get("id_gara");

    if (!idGara) {
        console.error(" ERRORE: ID Gara non trovato.");
        return;
    }

    customFetch('gare', 'updateTipologiaDocumento', { file_name: fileName, tipologia: nuovaTipologia, id_gara: idGara })
        .then(data => {
            if (data.success) {
                console.log(` Tipologia aggiornata con successo per ${fileName}`);
            } else {
                console.error(` Errore nell'aggiornamento della tipologia: ${data.error}`);
                alert(`Errore: ${data.error}`);
            }
        })
        .catch(error => console.error(" Errore nel salvataggio della tipologia:", error));
}

function loadDocumentiGara() {
    if (!numeroGara) {
        console.warn("ATTENZIONE: Numero Gara non ancora caricato. Riprovo più tardi...");
        setTimeout(loadDocumentiGara, 500); 
        return;
    }

    console.log(`DEBUG: Recupero documenti per gara: ${numeroGara}`);

    customFetch('gare', 'getDocumentiGara', { n_gara: numeroGara })
        .then(data => {
            const tableBody = document.getElementById("documenti-body");
            if (!tableBody) {
                console.error(" ERRORE: Elemento `documenti-body` non trovato.");
                return;
            }

            const uploadRow = document.getElementById("uploadRow");
            tableBody.innerHTML = "";

            if (!data.success || !data.data || data.data.length === 0) {
                console.warn(`Nessun documento trovato per la gara: ${numeroGara}`);
                tableBody.innerHTML = "<tr><td colspan='4'>Nessun documento trovato</td></tr>";
            } else {
                data.data.forEach(doc => {
                    if (typeof addFileToTable === "function") {
                        addFileToTable(doc.fileName, doc.tipologia, doc.uploadDate);
                    } else {
                        console.error(" ERRORE: `addFileToTable()` non è definita.");
                    }
                });            
            }

            if (!document.getElementById("uploadRow")) {
                console.log("DEBUG: Ripristino riga per Drag & Drop.");
                tableBody.appendChild(uploadRow);
            }
        })
        .catch(error => console.error(" Errore nel recupero documenti:", error));
}

// Chiamiamo la funzione quando carichiamo la scheda "DocumentiGara"
document.addEventListener("DOMContentLoaded", () => {
    if (window.location.href.includes("DocumentiGara")) {
        loadDocumentiGara();
    }
});

document.addEventListener("DOMContentLoaded", () => {
    document.querySelector(".tablinks[data-tab='DocumentiGara']").addEventListener("click", () => {
        console.log("DEBUG: Cambio scheda a DocumentiGara, ricarico i documenti...");
        loadDocumentiGara();
    });
});

// Funzione per aprire il modale di aggiunta/modifica azienda nel raggruppamento
function apriModaleRaggruppamento(idRecord = null) {
    console.log(`Aprendo modale per il raggruppamento ${idRecord ? "(modifica)" : "(aggiunta)"}`);

    let titolo = idRecord ? "Modifica Azienda" : "Aggiungi Nuova Azienda";
    document.getElementById("modal-title").textContent = titolo;

    let modal = document.getElementById("modalForm");
    modal.setAttribute("data-id", idRecord || "");

    // Generiamo il form dinamico
    document.getElementById("modal-body").innerHTML = `
        <div class="modale-form-group">
            <label for="azienda">Azienda</label>
            <select id="azienda"></select>
        </div>
        <div class="modale-form-group">
            <label for="ruolo">Ruolo</label>
            <select id="ruolo">
                <option value="Mandataria">Mandataria</option>
                <option value="Mandante">Mandante</option>
                <option value="Associata">Associata</option>
            </select>
        </div>
        <div class="modale-form-group">
            <label for="quota">Quota %</label>
            <input type="number" id="quota" min="1" max="100">
        </div>
    `;

    // Carichiamo i dati delle aziende nel dropdown
    caricaAziende();

    // Mostriamo il modale
    modal.style.display = "block";
}

// Carica la lista delle aziende disponibili per il dropdown
function caricaAziende() {
    customFetch('gare', 'getAziende')
        .then(data => {
            if (!data.success || !Array.isArray(data.data)) return;
            let select = document.getElementById("azienda");
            select.innerHTML = '<option value="">Seleziona un\'azienda</option>';
            data.data.forEach(azienda => {
                let option = document.createElement("option");
                option.value = azienda.id;
                option.textContent = azienda.nome;
                select.appendChild(option);
            });
        })
        .catch(error => console.error("Errore nel caricamento aziende:", error));
}

// Carica il raggruppamento per la gara
function loadRaggruppamento(idGara) {
    customFetch('gare', 'getRaggruppamento', { id_gara: idGara })
        .then(data => {
        console.log("DEBUG: Risposta API get_raggruppamento:", data);

        const tableBody = document.getElementById("raggruppamento-body");
        tableBody.innerHTML = "";

        if (!data.success || data.data.length === 0) {
            tableBody.innerHTML = "<tr><td colspan='4'>Nessuna azienda registrata</td></tr>";
            return;
        }

        data.data.forEach(row => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td class="azioni">
                    <img src="assets/icons/delete.png" class="action-icon" alt="Elimina" onclick="deleteRaggruppamento(${row.id})">
                </td>
                <td>${row.Azienda}</td>
                <td>${row.ruolo}</td>
                <td class="quota-cell" data-id="${row.id}" data-quota="${row.quota}">
                    ${row.quota}%
                </td>
            `;
            tableBody.appendChild(tr);
        });

        // Attiviamo l'edit in linea per la colonna "Quota"
        attivaModificaQuota();
    })
    .catch(error => console.error(" Errore nel caricamento del raggruppamento:", error));
}

function salvaRaggruppamento() {
    const params = new URLSearchParams(window.location.search);
    let idGara = params.get("id_gara");

    if (!idGara) {
        console.error(" ERRORE: ID Gara non trovato.");
        return;
    }

    let idAzienda = document.getElementById("azienda").value;
    let ruolo = document.getElementById("ruolo").value;
    let quota = document.getElementById("quota").value;

    if (!idAzienda || !ruolo || !quota) {
        alert("Tutti i campi sono obbligatori.");
        return;
    }

    customFetch('gare', 'insertRaggruppamento', {
        id_gara: idGara,
        id_azienda: idAzienda,
        ruolo: ruolo,
        quota: quota
    })
    .then(data => {
        if (data.success) {
            console.log("Azienda aggiunta con successo al raggruppamento!");
            loadRaggruppamento(idGara);
            chiudiModale();
        } else {
            console.error(`Errore nell'aggiunta dell'azienda: ${data.message || data.error}`);
        }
    })
    .catch(error => console.error("Errore nel salvataggio del raggruppamento:", error));
}

// Elimina un'azienda dal raggruppamento
function deleteRaggruppamento(id) {
    if (!confirm("Sei sicuro di voler eliminare questa azienda dal raggruppamento?")) return;

    customFetch('gare', 'deleteRaggruppamento', { id: id })
    .then(data => {
        if (data.success) {
            console.log(`Azienda eliminata con successo!`);
            const params = new URLSearchParams(window.location.search);
            loadRaggruppamento(params.get("id_gara"));
        } else {
            console.error("Errore nell'eliminazione:", data.message || data.error);
        }
    })
    .catch(error => console.error("Errore di connessione:", error));
}

// Carichiamo il raggruppamento al caricamento della pagina
document.addEventListener("DOMContentLoaded", () => {
    const params = new URLSearchParams(window.location.search);
    let idGara = params.get("id_gara");

    if (idGara) {
        loadRaggruppamento(idGara);
    }
});

function attivaModificaQuota() {
    document.querySelectorAll(".quota-cell").forEach(cell => {
        cell.addEventListener("click", function () {
            let id = this.getAttribute("data-id");
            let quotaAttuale = this.getAttribute("data-quota");

            // Se c'è già un input aperto, evita di crearne uno nuovo
            if (this.querySelector("input")) return;

            // Creiamo l'input
            let input = document.createElement("input");
            input.type = "number";
            input.min = "0";
            input.max = "100";
            input.value = quotaAttuale;
            input.classList.add("quota-input");

            // Sostituiamo il valore con l'input
            this.innerHTML = "";
            this.appendChild(input);
            input.focus();

            // 🔹 Quando l'utente preme ENTER, salviamo la modifica
            input.addEventListener("keypress", function (event) {
                if (event.key === "Enter") {
                    aggiornaQuota(id, input.value);
                }
            });

            // 🔹 Se l'utente clicca fuori, ripristina il valore originale se non è stato modificato
            input.addEventListener("blur", function () {
                let nuovoValore = input.value;
                if (nuovoValore !== quotaAttuale) {
                    aggiornaQuota(id, nuovoValore);
                } else {
                    cell.innerHTML = `${quotaAttuale}%`;
                }
            });
        });
    });
}

function aggiornaQuota(id, nuovaQuota) {
    if (nuovaQuota < 0 || nuovaQuota > 100) {
        alert("La quota deve essere tra 0 e 100.");
        return;
    }

    customFetch('gare', 'updateQuotaRaggruppamento', { id: id, quota: nuovaQuota })
        .then(data => {
            if (data.success) {
                loadRaggruppamento(new URLSearchParams(window.location.search).get("id_gara"));
            }
        })
        .catch(error => console.error(" Errore di connessione:", error));
}

document.addEventListener("DOMContentLoaded", () => {
    const raggruppamentoTab = document.querySelector(".tablinks[data-tab='Ragruppamento']");

    if (raggruppamentoTab) {
        raggruppamentoTab.addEventListener("click", () => {
            console.log("DEBUG: Cambio scheda a Raggruppamento, ricarico i dati...");
            const params = new URLSearchParams(window.location.search);
            let idGara = params.get("id_gara");
            loadRaggruppamento(idGara);
        });
    }
});


