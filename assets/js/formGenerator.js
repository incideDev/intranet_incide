document.addEventListener('DOMContentLoaded', function () {
    console.log("formGenerator.js caricato correttamente.");
    loadResponsabili();
    function getCurrentSection() {
        const params = new URLSearchParams(location.search);
        // fallback "collaborazione" se non c’è il parametro
        return (params.get('section') || 'collaborazione').trim();
    }
    function getDefaultParentForSection(section) {
        // scegli il “blocco” giusto della sidebar per la sezione
        // — adatta se hai altri parent specifici
        if (section === 'commesse') return 'Gestione Commesse';
        if (section === 'gestione') return 'Gestione Personale'; // esempio
        if (section === 'archivio') return 'Modulistica';        // esempio
        // per Collaborazione i moduli stanno tipicamente sotto “Segnalazioni”
        return 'Segnalazioni';
    }

    // CREAZIONE FORM
    const form = document.getElementById("createForm");
    if (form) {
        form.addEventListener("submit", async function (event) {
            event.preventDefault();

            const formName = document.getElementById("form_name").value.trim();
            const description = document.getElementById("description").value.trim();
            const responsabile = document.getElementById("responsabile").value.trim() || null;
            const color = document.getElementById("color")?.value || "#CCCCCC";

            // Colore obbligatorio diverso da #CCCCCC (come da tua UX)
            if (color.toLowerCase() === "#cccccc") {
                showToast("Scegli un colore valido per la segnalazione!", "error");
                return;
            }

            // Raccogli campi dinamici (escludi i fissi/sistema)
            const fields = [];
            document.querySelectorAll("#form-fields-container .form-group").forEach((field) => {
                const fieldName = field.querySelector("input[name^='fields'][name$='[field_name]']")?.value.trim() || "";
                if (!fieldName) return;

                // campi riservati/sistema
                const lower = fieldName.toLowerCase();
                if (["descrizione", "deadline", "titolo", "priority"].includes(lower)) {
                    console.warn(` Il campo '${fieldName}' è riservato e non può essere aggiunto manualmente.`);
                    return;
                }

                const fieldType = field.querySelector("select[name^='fields'][name$='[field_type]']")?.value || "text";
                const fieldPlaceholder = field.querySelector("input[name^='fields'][name$='[field_placeholder]']")?.value.trim() || "";
                const required = field.querySelector("input[name^='fields'][name$='[required]']")?.checked ? 1 : 0;

                let fieldOptions = [];
                if (["select", "checkbox", "radio"].includes(fieldType)) {
                    field.querySelectorAll("input[name^='fields'][name$='[field_options][]']").forEach(option => {
                        const val = option.value.trim();
                        if (val) fieldOptions.push(val);
                    });
                }

                fields.push({
                    field_name: fieldName,
                    field_type: fieldType,
                    field_placeholder: fieldPlaceholder,
                    field_options: fieldOptions,
                    required
                });
            });

            try {
                console.log("📤 Creazione form:", { form_name: formName, description, color });

                // 1) Crea/aggiorna il form (tabella + fixed fields + meta)
                const ef = await customFetch('page_editor', 'ensureForm', {
                    form_name: formName,
                    description: description,
                    color: color
                });
                if (!ef?.success) throw new Error(ef?.message || "Errore ensureForm");

                // 2) Salva la struttura dei campi dinamici
                const ss = await customFetch('page_editor', 'saveFormStructure', {
                    form_name: formName,
                    fields: fields
                });
                if (!ss?.success) throw new Error(ss?.message || "Errore salvataggio struttura");

                // 3) Imposta il responsabile (se selezionato)
                if (responsabile) {
                    const sr = await customFetch('page_editor', 'setFormResponsabile', {
                        form_name: formName,
                        user_id: responsabile
                    });
                    if (!sr?.success) throw new Error(sr?.message || "Errore setFormResponsabile");
                }

                // 4) (opzionale ma consigliato) Attacca il modulo gestione_richiesta
                await customFetch('page_editor', 'attachModule', {
                    form_name: formName,
                    module_key: 'gestione_richiesta'
                }).catch(() => { }); // ignora se "già presente"

                // 5) Crea/aggiorna voce di menu (necessario se vuoi vederlo nella sidebar)
                // Richiede permesso 'manage_menu_custom'.
                const sectionNow = getCurrentSection();
                const link = `index.php?section=${encodeURIComponent(sectionNow)}&page=form_listing&form_name=${encodeURIComponent(formName)}`;
                await customFetch('menu_custom', 'upsert', {
                    menu_section: sectionNow,
                    parent_title: getDefaultParentForSection(sectionNow),
                    title: formName,
                    link: link,
                    attivo: 1
                }).catch(() => { /* se l'utente non ha permessi, semplicemente non compare in sidebar */ });

                // 6) Recupera dati completi del form per avere l'ID
                const gf = await customFetch('page_editor', 'getForm', { form_name: formName });
                if (!gf?.success) throw new Error("Impossibile recuperare i dettagli del form appena creato.");

                // 7) UI: chiudi modale, reset, card, toast, refresh sidebar
                toggleModal('createFormModal', 'close');
                document.getElementById("createForm").reset();
                document.getElementById("form-fields-container").innerHTML = "";
                fieldCount = 0;

                function createFormCard(formData) {
                    const template = document.querySelector(".form-preview");

                    // sezione corretta: passa da formData o deduci da URL
                    const section = (formData.section || getCurrentSection() || 'collaborazione').trim();

                    let newCard;
                    if (template) {
                        newCard = template.cloneNode(true);

                        newCard.setAttribute("data-form-id", formData.form_id);
                        newCard.style.setProperty("--form-color", formData.color);

                        newCard.querySelector(".form-title").textContent = formData.form_name;
                        newCard.querySelector(".form-description").textContent = formData.description || "Nessuna descrizione";

                        const openLink = newCard.querySelector(".open-form-link");
                        if (openLink) {
                            openLink.href = `index.php?section=${encodeURIComponent(section)}&page=form_listing&form_name=${encodeURIComponent(formData.form_name)}`;
                        }

                        const delBtn = newCard.querySelector(".delete-form-btn");
                        if (delBtn) delBtn.setAttribute("data-form-id", formData.form_id);

                        const counter = newCard.querySelector(".total-reports");
                        if (counter) counter.textContent = "0 segnalazioni";

                    } else {
                        newCard = document.createElement("div");
                        newCard.className = "form-preview";
                        newCard.style.setProperty("--form-color", formData.color);
                        newCard.setAttribute("data-form-id", formData.form_id);

                        newCard.innerHTML = `
                                            <div class="form-header">
                                                <h3 class="form-title">${formData.form_name}</h3>
                                                <button type="button" class="icon-button delete-form-btn" data-form-id="${formData.form_id}">
                                                    <img src="assets/icons/delete.png" class="delete-icon" alt="Elimina">
                                                </button>
                                            </div>
                                            <p class="form-description">${formData.description || "Nessuna descrizione"}</p>
                                            <div class="form-meta"><span class="total-reports">0 segnalazioni</span></div>
                                            <a class="open-form-link"
                                                href="index.php?section=${encodeURIComponent(section)}&page=form_listing&form_name=${encodeURIComponent(formData.form_name)}">
                                            </a>
                                            `;
                    }

                    const grid = document.getElementById("admin-form-grid") || document.querySelector(".form-grid");
                    if (grid) grid.appendChild(newCard);
                }

                showToast('Modulo creato!', 'success');
                forceSidebarRefresh(formName, 0, sectionNow);

            } catch (err) {
                console.error("❌ Errore creazione form:", err);
                showToast("Errore durante la creazione del form. " + (err?.message || ''), "error");
            }
        });
    } else {
        console.error(" ERRORE: Il form 'createForm' non è stato trovato nel DOM.");
    }

    // ELIMINAZIONE FORM
    console.log(" Eventi per eliminazione form attivati.");
    document.addEventListener("click", function (event) {
        const button = event.target.closest(".delete-form-btn");
        if (!button) return;

        const formId = button.getAttribute("data-form-id");
        if (!formId) {
            console.error(" Errore: ID form non trovato.");
            return;
        }

        showConfirm("⚠ Sei sicuro di voler eliminare questo form?", function () {
            customFetch("page_editor", "deleteForm", { form_id: formId })
                .then(data => {
                    if (data === true || data.success) {
                        console.log(` orm #${formId} eliminato con successo.`);
                        const card = button.closest(".form-preview");
                        if (card) {
                            card.remove();
                            console.log("🧹 Card rimossa dal DOM.");
                        } else {
                            console.warn(" Card non trovata nel DOM.");
                        }
                        if (window.showToast) showToast("Form eliminato con successo!", "success");
                        if (typeof loadForms === "function") loadForms();
                    } else {
                        const msg = data?.error || "Errore sconosciuto";
                        console.error(" Errore eliminazione:", msg);
                        if (window.showToast) showToast(msg, "error");
                    }
                })
                .catch(error => {
                    console.error(" Errore di connessione:", error);
                    if (window.showToast) showToast("Errore di connessione al server.", "error");
                });
        });
    });
});

function forceSidebarRefresh(expectedTitle, tentativi = 0, section = getCurrentSection()) {
    window.sidebarMenuData = null;
    // ricarica la sidebar della sezione corretta
    if (typeof window.refreshSidebarMenu === 'function') {
        window.refreshSidebarMenu(section);
    }
    setTimeout(() => {
        const voci = [...document.querySelectorAll('#sidebar-menu .menu-text')].map(el => el.textContent.trim().toLowerCase());
        if (!voci.includes(expectedTitle.trim().toLowerCase()) && tentativi < 6) {
            forceSidebarRefresh(expectedTitle, tentativi + 1, section);
        }
    }, 200);
}

window.openCreateFormModal = function () {
    toggleModal('createFormModal', 'open');
};

function createFormCard(formData) {
    const template = document.querySelector(".form-preview");

    // sezione di default: collaborazione (fallback)
    const section = formData.section || 'collaborazione';

    let newCard;
    if (template) {
        newCard = template.cloneNode(true);

        newCard.setAttribute("data-form-id", formData.form_id);
        newCard.style.setProperty("--form-color", formData.color);

        newCard.querySelector(".form-title").textContent = formData.form_name;
        newCard.querySelector(".form-description").textContent = formData.description || "Nessuna descrizione";

        const openLink = newCard.querySelector(".open-form-link");
        if (openLink) {
            openLink.href =
                `index.php?section=${encodeURIComponent(section)}&page=form_listing&form_name=${encodeURIComponent(formData.form_name)}`;
        }

        const delBtn = newCard.querySelector(".delete-form-btn");
        if (delBtn) delBtn.setAttribute("data-form-id", formData.form_id);

        const counter = newCard.querySelector(".total-reports");
        if (counter) counter.textContent = "0 segnalazioni";

    } else {
        newCard = document.createElement("div");
        newCard.className = "form-preview";
        newCard.style.setProperty("--form-color", formData.color);
        newCard.setAttribute("data-form-id", formData.form_id);

        newCard.innerHTML = `
          <div class="form-header">
              <h3 class="form-title">${formData.form_name}</h3>
              <button type="button"
                      class="icon-button delete-form-btn"
                      data-form-id="${formData.form_id}">
                  <img src="assets/icons/delete.png" class="delete-icon" alt="Elimina">
              </button>
          </div>

          <p class="form-description">${formData.description || "Nessuna descrizione"}</p>

          <div class="form-meta">
              <span class="total-reports">0 segnalazioni</span>
          </div>

          <a class="open-form-link"
             href="index.php?section=${encodeURIComponent(section)}&page=form_listing&form_name=${encodeURIComponent(formData.form_name)}">
          </a>
        `;
    }

    const grid = document.getElementById("admin-form-grid")
        || document.querySelector(".form-grid");
    if (grid) grid.appendChild(newCard);
}

// Gestione aggiunta campi dinamici
let fieldCount = document.querySelectorAll("#form-fields-container .form-group").length || 1;

function addField() {
    const container = document.getElementById("form-fields-container");
    if (!container) {
        console.error(" ERRORE: Contenitore campi non trovato.");
        return;
    }

    // Chiude tutti i campi esistenti prima di aggiungere uno nuovo
    document.querySelectorAll(".field-body").forEach(body => {
        body.style.display = "none";
    });

    document.querySelectorAll(".toggle-field").forEach(button => {
        button.textContent = "▲";
    });

    fieldCount++;
    const fieldHtml = `
            <div class="form-group" id="field-${fieldCount}">
                <!-- Intestazione con toggle -->
                <div class="field-header">
                    <span class="field-title" id="field-title-${fieldCount}">Nuovo Campo</span>
                    <button type="button" class="toggle-field" data-field-id="${fieldCount}">▼</button>
                </div>

                <!-- Corpo del campo -->
                <div class="field-body">
                    <label for="fields[${fieldCount}][field_name]">Nome del Campo:</label>
                    <input type="text" name="fields[${fieldCount}][field_name]" required>

                    <label for="fields[${fieldCount}][field_type]">Tipo di Campo:</label>
                    <select name="fields[${fieldCount}][field_type]" class="field-type-select" onchange="toggleOptions(event, ${fieldCount})" required>
                        <option value="text">Testo</option>
                        <option value="textarea">Textarea</option>
                        <option value="select">Selectbox</option>
                        <option value="checkbox">Checkbox</option>
                        <option value="radio">Radio</option>
                        <option value="file">File Upload</option>
                    </select>

                    <label for="fields[${fieldCount}][field_placeholder]">Placeholder:</label>
                    <input type="text" name="fields[${fieldCount}][field_placeholder]">

                    <!-- Contenitore per le opzioni (visibile solo per Select, Checkbox e Radio) -->
                    <div id="options-container-${fieldCount}" class="options-container" style="display:none;">
                        <h4>Opzioni</h4>
                        <div id="options-${fieldCount}">
                            <input type="text" name="fields[${fieldCount}][field_options][]" placeholder="Opzione 1">
                        </div>
                        <button type="button" class="add-option-btn button" data-field-id="${fieldCount}">+ Aggiungi Opzione</button>
                    </div>

                    <label for="fields[${fieldCount}][required]">Campo Obbligatorio:</label>
                    <input type="checkbox" name="fields[${fieldCount}][required]" value="1">
                    
                    <!-- Bottone per eliminare il campo -->
                    <button type="button" class="delete-btn" onclick="removeField(${fieldCount})">❌</button>
                </div>
            </div>`;

    container.insertAdjacentHTML("beforeend", fieldHtml);

    const newField = document.getElementById(`field-${fieldCount}`);
    const fieldNameInput = newField.querySelector(`input[name="fields[${fieldCount}][field_name]"]`);
    const fieldTypeSelect = newField.querySelector(`select[name="fields[${fieldCount}][field_type]"]`);
    const fieldPlaceholderInput = newField.querySelector(`input[name="fields[${fieldCount}][field_placeholder]"]`);

    //  Evento per aggiornare l'anteprima in tempo reale mentre scrivi
    fieldNameInput.addEventListener("input", () => {
        updateFieldTitle(fieldCount);
        updateFormPreview();
    });

    fieldTypeSelect.addEventListener("change", (event) => {
        toggleOptions(event, fieldCount);
        updateFormPreview();
    });

    fieldPlaceholderInput.addEventListener("input", updateFormPreview);

    //  Evento per il toggle del campo (chiudi/apri)
    newField.querySelector(".toggle-field").addEventListener("click", function () {
        const id = this.getAttribute("data-field-id");
        toggleField(id);
    });

    //  Evento per il bottone di aggiunta opzioni nei Select, Checkbox e Radio
    const addOptionBtn = newField.querySelector(".add-option-btn");
    if (addOptionBtn) {
        addOptionBtn.addEventListener("click", function () {
            addOption(fieldCount);
        });
    }

    console.log(` Campo #${fieldCount} aggiunto con successo.`);
    updateFormPreview(); // Aggiorna subito l'anteprima
}

function toggleField(fieldId) {
    const fieldBody = document.querySelector(`#field-${fieldId} .field-body`);
    const toggleButton = document.querySelector(`#field-${fieldId} .toggle-field`);

    if (!fieldBody || !toggleButton) return;

    // Aggiorna il riferimento globale al campo attivo
    fieldCount = parseInt(fieldId); // <--  QUESTA È LA RIGA CHIAVE

    // Controlla lo stato del campo e cambia l'icona
    if (fieldBody.style.display === "none") {
        fieldBody.style.display = "block";
        toggleButton.textContent = "▼";
    } else {
        fieldBody.style.display = "none";
        toggleButton.textContent = "▲";
    }
}

function removeField(fieldId) {
    const field = document.getElementById(`field-${fieldId}`);
    if (field) {
        field.remove();
        updateFormPreview();
    }
}

function updateFieldTitle(fieldId) {
    const fieldInput = document.querySelector(`#field-${fieldId} input[name^='fields'][name$='[field_name]']`);
    const fieldTitle = document.getElementById(`field-title-${fieldId}`);

    if (!fieldInput || !fieldTitle) return;

    // Se l'input è vuoto, mostra "Nuovo Campo", altrimenti usa il valore dell'input
    fieldTitle.textContent = fieldInput.value.trim() || "Nuovo Campo";
}

// Funzione per aggiungere un'opzione nel Selectbox
function addOption(fieldId) {
    const optionsDiv = document.getElementById(`options-${fieldId}`);
    if (!optionsDiv) {
        console.error(` ERRORE: Contenitore opzioni non trovato per il campo #${fieldId}`);
        return;
    }

    const newOptionWrapper = document.createElement("div");
    newOptionWrapper.classList.add("option-wrapper");

    const newOption = document.createElement("input");
    newOption.type = "text";
    newOption.name = `fields[${fieldId}][field_options][]`;
    newOption.placeholder = "Nuova Opzione";
    newOption.classList.add("option-input");

    // Pulsante per eliminare l'opzione
    const deleteOptionBtn = document.createElement("button");
    deleteOptionBtn.type = "button";
    deleteOptionBtn.classList.add("delete-option-btn");
    deleteOptionBtn.textContent = "❌";
    deleteOptionBtn.addEventListener("click", function () {
        newOptionWrapper.remove();
        updateFormPreview(); // Aggiorna l'anteprima dopo la rimozione
    });

    newOption.addEventListener("input", updateFormPreview);

    newOptionWrapper.appendChild(newOption);
    newOptionWrapper.appendChild(deleteOptionBtn);
    optionsDiv.appendChild(newOptionWrapper);

    console.log(` Opzione aggiunta al campo #${fieldId}.`);
    updateFormPreview();
}

// Funzione per mostrare le opzioni del Selectbox in modo robusto e sicuro
function toggleOptions(event, fieldId) {
    // Se l'evento non è passato correttamente, proviamo a identificarlo dal `fieldId`
    let fieldType;
    if (event && event.target) {
        fieldType = event.target.value;
    } else {
        console.error(` Errore: Evento non definito o target mancante. Tentativo di fallback con fieldId: ${fieldId}`);
        const selectField = document.querySelector(`select[name="fields[${fieldId}][field_type]"]`);
        if (selectField) {
            fieldType = selectField.value;
        } else {
            console.error(` Errore critico: impossibile recuperare il tipo di campo per fieldId: ${fieldId}`);
            return; // Esce dalla funzione se non può risolvere il problema
        }
    }

    const optionsContainer = document.getElementById(`options-container-${fieldId}`);
    const placeholderInput = document.querySelector(`input[name="fields[${fieldId}][field_placeholder]"]`);
    const fieldNameInput = document.querySelector(`input[name="fields[${fieldId}][field_name]"]`);

    // Se il container delle opzioni esiste, mostra/nasconde in base al tipo di campo
    if (optionsContainer) {
        optionsContainer.style.display = ["select", "checkbox", "radio"].includes(fieldType) ? "block" : "none";
    }

    // Gestione placeholder per il campo "file"
    if (placeholderInput) {
        if (fieldType === "file") {
            placeholderInput.value = "";
            placeholderInput.disabled = true;
            if (optionsContainer) optionsContainer.style.display = "none"; // Nasconde le opzioni per il file
        } else {
            placeholderInput.disabled = false;

            // Controlla se `fieldNameInput` esiste prima di accedere al valore
            const fieldName = fieldNameInput ? fieldNameInput.value.trim() : "";

            // Imposta un placeholder di default basato sul nome del campo se è vuoto
            if (!placeholderInput.value.trim() && fieldName) {
                placeholderInput.value = `Inserisci ${fieldName}`;
            }
        }
    }
}

// Assegniamo il click al bottone "Aggiungi Campo"
const addFieldBtn = document.getElementById("addFieldBtn");
if (addFieldBtn) {
    addFieldBtn.addEventListener("click", addField);
    console.log(" Evento 'click' assegnato al bottone 'Aggiungi Campo'.");
} else {
    console.error(" ERRORE: Bottone 'Aggiungi Campo' non trovato.");
}

// Rende globali le funzioni necessarie
window.addField = addField;
window.addOption = addOption;
window.toggleOptions = toggleOptions;

console.log("formGenerator.js eseguito completamente senza errori.");

// Eventi per eliminazione form
console.log(" Eventi per eliminazione form attivati.");

document.addEventListener("click", function (event) {
    const button = event.target.closest(".delete-form-btn");
    if (!button) return;

    const formId = button.getAttribute("data-form-id");
    if (!formId) {
        console.error(" Errore: ID form non trovato.");
        if (window.showToast) showToast("Errore: ID form non trovato.", "error");
        return;
    }

    showConfirm(" Sei sicuro di voler eliminare questo form?", function () {
        customFetch("page_editor", "deleteForm", { form_id: formId })
            .then(data => {
                if (data === true || data.success) {
                    console.log(` orm #${formId} eliminato con successo.`);
                    const card = button.closest(".form-preview");
                    if (card) {
                        card.remove();
                        console.log(" Card rimossa dal DOM.");
                    } else {
                        console.warn(" Card non trovata nel DOM.");
                    }
                    if (window.showToast) showToast("Form eliminato con successo!", "success");
                    if (typeof loadForms === "function") loadForms();
                } else {
                    const msg = data?.error || "Errore sconosciuto";
                    console.error(" Errore eliminazione:", msg);
                    if (window.showToast) showToast(msg, "error");
                }
            })
            .catch(error => {
                console.error(" Errore di connessione:", error);
                if (window.showToast) showToast("Errore di connessione al server.", "error");
            });
    });
});

function updateFormPreview() {
    const previewContainer = document.getElementById("form-preview-container");
    if (!previewContainer) return;
    previewContainer.style.overflowX = "hidden";
    previewContainer.innerHTML = "";

    const previewPage = document.createElement("div");
    previewPage.classList.add("preview-page");
    previewContainer.appendChild(previewPage);

    // --- Intestazione dinamica ---
    const formTitle = document.getElementById("form_name").value.trim();
    const formDescription = document.getElementById("description").value.trim();
    const responsabileSelect = document.getElementById("responsabile");
    const responsabile = responsabileSelect?.selectedOptions[0]?.textContent;
    const color = document.getElementById("color")?.value;

    // Blocco in alto (info modulo)
    const titlePreview = document.createElement("div");
    titlePreview.classList.add("preview-title");
    let infoHtml = "";
    infoHtml += `<p><strong>Nome della Segnalazione:</strong> <span style="${formTitle ? "" : "color:#bbb"}">${formTitle || "Non inserito"}</span></p>`;
    infoHtml += `<p><strong>Descrizione:</strong> <span style="${formDescription ? "" : "color:#bbb"}">${formDescription || "Non inserita"}</span></p>`;
    infoHtml += `<p><strong>Responsabile:</strong> <span style="${(responsabile && responsabile !== "-- Seleziona un Responsabile --") ? "" : "color:#bbb"}">${(responsabile && responsabile !== "-- Seleziona un Responsabile --") ? responsabile : "Non selezionato"}</span></p>`;
    infoHtml += `<p><strong>Colore della Segnalazione:</strong> 
            <span style="display:inline-block;width:20px;height:20px;background:${(color && color !== "#CCCCCC") ? color : "#e4e4e4"};vertical-align:middle;margin-right:6px;border-radius:5px;border:1px solid #dadada"></span>
            ${(color && color !== "#CCCCCC") ? color : "#e4e4e4"}
        </p>`;
    titlePreview.innerHTML = infoHtml;
    previewPage.appendChild(titlePreview);

    // --- Riga colorata sempre visibile ---
    const previewDivider = document.createElement("hr");
    previewDivider.classList.add("section-divider");
    previewDivider.style.borderTop = `3.5px solid ${(color && color !== "#CCCCCC") ? color : "#e4e4e4"}`;
    previewDivider.title = "Colore selezionato";
    previewPage.appendChild(previewDivider);

    // --- Contenitore centrale, più largo ---
    const gridWrapper = document.createElement("div");
    gridWrapper.style.width = "90%";
    gridWrapper.style.margin = "0 auto";
    gridWrapper.style.marginTop = "10px";
    gridWrapper.style.background = "transparent";
    gridWrapper.style.paddingBottom = "48px";
    gridWrapper.style.position = "relative";
    previewPage.appendChild(gridWrapper);

    // --- Griglia 2 colonne ---
    const grid = document.createElement("div");
    grid.className = "form-grid preview-grid";
    grid.style.display = "grid";
    grid.style.gridTemplateColumns = "1fr 1fr";
    grid.style.gap = "18px 24px";
    grid.style.background = "transparent";
    gridWrapper.appendChild(grid);

    // --- Campi fissi (sempre visibili e già "prenotati") ---
    let argBlock = document.createElement("div");
    argBlock.className = "preview-field";
    argBlock.innerHTML = `
            <label style="font-weight:600;">Titolo della segnalazione</label>
            <input type="text" disabled style="width:100%;opacity:0.8;" placeholder="Sarà compilato dall'utente">
        `;
    grid.appendChild(argBlock);

    let descBlock = document.createElement("div");
    descBlock.className = "preview-field";
    descBlock.innerHTML = `
            <label style="font-weight:600;">Descrizione dettagliata</label>
            <textarea disabled style="width:100%;opacity:0.8;" rows="2" placeholder="Sarà compilato dall'utente"></textarea>
        `;
    grid.appendChild(descBlock);

    // --- Banda campi personalizzati sempre visibile ---
    let dynBox = document.createElement("div");
    dynBox.style.gridColumn = "1 / 3";
    dynBox.style.background = "#e7f0fa";
    /*dynBox.style.margin = "32px 0 0 0";*/
    dynBox.style.padding = "0 0 18px 0";
    dynBox.style.minHeight = "270px";
    /*dynBox.style.display = "flex";
    dynBox.style.flexDirection = "column";*/
    dynBox.style.border = "none";
    dynBox.style.boxShadow = "none";
    dynBox.style.borderRadius = "0";
    dynBox.style.position = "relative";
    dynBox.style.overflowY = "auto";
    dynBox.style.height = "365px";

    let dynTitle = document.createElement("div");
    dynTitle.style = "font-weight:700;color:#376391;margin:0 0 16px 0;font-size:15px;letter-spacing:0.2px;padding:10px 28px 0 28px;";
    dynTitle.textContent = "Altri campi della segnalazione";
    dynBox.appendChild(dynTitle);

    // Griglia dei campi personalizzati
    let dynGrid = document.createElement("div");
    dynGrid.style.display = "grid";
    dynGrid.style.gridTemplateColumns = "1fr 1fr";
    dynGrid.style.gap = "18px 24px";
    /*dynGrid.style.padding = "0 28px 0 28px";*/
    dynGrid.style.flex = "1";
    /*dynGrid.style.minHeight = "100%";*/
    dynGrid.style.marginLeft = "15px";
    dynGrid.style.marginRight = "15px";
    dynBox.appendChild(dynGrid);

    // Mostra SEMPRE un solo placeholder solo se non ci sono campi personalizzati
    let renderedFields = 0;
    Array.from(document.querySelectorAll("#form-fields-container .form-group")).forEach((field) => {
        const fieldName = field.querySelector("input[name^='fields'][name$='[field_name]']").value.trim();
        const fieldType = field.querySelector("select[name^='fields'][name$='[field_type]']").value;
        const fieldPlaceholder = field.querySelector("input[name^='fields'][name$='[field_placeholder]']").value.trim();
        if (["titolo", "descrizione", "deadline", "priority"].includes(fieldName.toLowerCase())) return;

        let labelVisiva = fieldName
            ? (fieldName.charAt(0).toUpperCase() + fieldName.slice(1).replace(/_/g, " "))
            : '<span style="color:#b7b7b7;font-style:italic;">Nome campo</span>';

        let fieldPreview = document.createElement("div");
        fieldPreview.classList.add("preview-field");

        if (fieldType === "text") {
            fieldPreview.innerHTML = `<label>${labelVisiva}</label><input type="text" placeholder="${fieldPlaceholder}" style="width:100%;">`;
        } else if (fieldType === "textarea") {
            fieldPreview.innerHTML = `<label>${labelVisiva}</label><textarea placeholder="${fieldPlaceholder}" style="width:100%;"></textarea>`;
        } else if (fieldType === "select") {
            let select = document.createElement("select");
            select.style.width = "100%";
            select.innerHTML = `<option disabled selected>-- Seleziona un'opzione --</option>`;
            field.querySelectorAll("input[name^='fields'][name$='[field_options][]']").forEach(option => {
                let optionElement = document.createElement("option");
                optionElement.textContent = option.value.trim();
                select.appendChild(optionElement);
            });
            fieldPreview.innerHTML = `<label>${labelVisiva}</label>`;
            fieldPreview.appendChild(select);
        } else if (fieldType === "checkbox") {
            let checkContainer = document.createElement("div");
            field.querySelectorAll("input[name^='fields'][name$='[field_options][]']").forEach(option => {
                let checkWrapper = document.createElement("label");
                checkWrapper.innerHTML = `<input type="checkbox"> ${option.value.trim()}`;
                checkContainer.appendChild(checkWrapper);
            });
            fieldPreview.innerHTML = `<fieldset><legend>${labelVisiva}</legend></fieldset>`;
            fieldPreview.querySelector("fieldset").appendChild(checkContainer);
        } else if (fieldType === "radio") {
            let radioContainer = document.createElement("div");
            field.querySelectorAll("input[name^='fields'][name$='[field_options][]']").forEach(option => {
                let radioWrapper = document.createElement("label");
                radioWrapper.innerHTML = `<input type="radio" name="radio_${fieldName}"> ${option.value.trim()}`;
                radioContainer.appendChild(radioWrapper);
            });
            fieldPreview.innerHTML = `<fieldset><legend>${labelVisiva}</legend></fieldset>`;
            fieldPreview.querySelector("fieldset").appendChild(radioContainer);
        } else if (fieldType === "file") {
            fieldPreview.innerHTML = `
                    <label>${labelVisiva}</label>
                    <div style="border: 1.5px dashed #b8b8b8; padding: 14px 10px; text-align: center; border-radius: 7px; margin-bottom:0; background:#f9f9f9; position:relative;">
                        <span style="color:#6d6d6d; font-size:14px;display:block;">Trascina qui l'immagine, clicca o incolla uno screenshot (CTRL+V)</span>
                        <div style="font-size: 10px; color: #555; margin-top: 6px; margin-bottom: 3px;">Formati accettati: JPG, PNG. Max 5MB.</div>
                        <input type="file" accept="image/jpeg, image/png" style="display:none;">
                        <div style="margin-top:10px; color:#bbb; font-size:13px;">[Upload immagine non attivo in anteprima]</div>
                    </div>
                `;
        } else {
            // Campo non ancora definito (nessun type) → segnaposto editabile
            fieldPreview.innerHTML = `<label>${labelVisiva}</label><input type="text" placeholder="(Definisci il campo)" style="width:100%;">`;
        }
        dynGrid.appendChild(fieldPreview);
        renderedFields++;
    });
    if (renderedFields === 0) {
        let fieldPreview = document.createElement("div");
        fieldPreview.classList.add("preview-field");
        fieldPreview.innerHTML = `<label style="color:#b7b7b7;font-style:italic;">Campo personalizzato</label>
                <input type="text" disabled placeholder="(Aggiungi un campo sopra)">
            `;
        dynGrid.appendChild(fieldPreview);
    }

    grid.appendChild(dynBox);

    // --- Blocca sticky finale in fondo, più staccato ---
    let bottomFields = document.createElement("div");
    bottomFields.style.position = "absolute";
    bottomFields.style.left = "0";
    bottomFields.style.right = "0";
    bottomFields.style.bottom = "-38px";
    bottomFields.style.width = "100%";
    bottomFields.style.background = "white";
    bottomFields.style.display = "flex";
    bottomFields.style.justifyContent = "space-between";
    bottomFields.style.alignItems = "flex-end";
    bottomFields.style.padding = "30px 0 0 0";
    bottomFields.style.gap = "28px";
    bottomFields.style.zIndex = "2";

    // data di scadenza
    let deadlineBlock = document.createElement("div");
    deadlineBlock.className = "preview-field";
    deadlineBlock.style.width = "48%";
    deadlineBlock.innerHTML = `
            <label style="font-weight:600;">Data di scadenza</label>
            <input type="date" disabled style="width:100%;opacity:0.8;">
        `;
    bottomFields.appendChild(deadlineBlock);

    // priorità
    let priorityBlock = document.createElement("div");
    priorityBlock.className = "preview-field";
    priorityBlock.style.width = "48%";
    priorityBlock.innerHTML = `
            <label style="font-weight:600;">Priorità</label>
            <select disabled style="width:100%;opacity:0.8;">
                <option value="Bassa">Bassa</option>
                <option value="Media" selected>Media</option>
                <option value="Alta">Alta</option>
            </select>
        `;
    bottomFields.appendChild(priorityBlock);

    gridWrapper.appendChild(bottomFields);
}

const formNameEl = document.getElementById("form_name");
if (formNameEl) formNameEl.addEventListener("input", updateFormPreview);

const descriptionEl = document.getElementById("description");
if (descriptionEl) descriptionEl.addEventListener("input", updateFormPreview);

const titoloEl = document.getElementById("titolo");
if (titoloEl) titoloEl.addEventListener("input", updateFormPreview);

const responsabileEl = document.getElementById("responsabile");
if (responsabileEl) responsabileEl.addEventListener("change", updateFormPreview);

const colorEl = document.getElementById("color");
if (colorEl) colorEl.addEventListener("input", updateFormPreview);

function loadResponsabili() {
    customFetch("forms", "getUtentiList")
        .then(data => {
            const select = document.getElementById("responsabile");
            if (!select) return;

            select.innerHTML = '<option value="">-- Seleziona un Responsabile --</option>';

            if (data.success && Array.isArray(data.data)) {
                data.data.forEach(persona => {
                    const option = document.createElement("option");
                    option.value = persona.user_id;
                    option.textContent = persona.Nominativo;
                    select.appendChild(option);
                });
            } else {
                console.error(" Errore nel recupero dei responsabili:", data.error || data.message);
            }
        })
        .catch(error => console.error(" Errore di connessione:", error));
}

const colorInput = document.getElementById("color");
if (colorInput) {
    colorInput.addEventListener("input", function () {
        const colorPreview = document.getElementById("color-preview");
        if (colorPreview) {
            colorPreview.style.backgroundColor = this.value;
        }
    });
}
