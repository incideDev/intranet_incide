document.addEventListener("DOMContentLoaded", function () {
    const tabParam = new URLSearchParams(window.location.search).get("tab");
    if (tabParam) {
        const fullTabId = `tab-${tabParam}`;
        const link = document.querySelector(`.tab-link[data-tab="${fullTabId}"]`);
        const content = document.getElementById(fullTabId);
        if (link && content) {
            document.querySelectorAll(".tab-link").forEach(l => l.classList.remove("active"));
            document.querySelectorAll(".tabcontent").forEach(tab => tab.classList.remove("active"));
            link.classList.add("active");
            content.classList.add("active");

            // carica contenuto della tab assegnazione se attiva
            if (fullTabId === "tab-assegnazione") {
                caricaUtentiERuoli();
            }
        }
    }

    const listaRuoli = document.getElementById("ruoli-list");
    const btnNuovoRuolo = document.getElementById("btn-nuovo-ruolo");
    const titolo = document.getElementById("ruolo-titolo");
    const inputNome = document.getElementById("ruolo-nome");
    const inputDescrizione = document.getElementById("ruolo-descrizione");
    const contenitorePermessi = document.getElementById("permessi-container");
    const btnSalva = document.getElementById("salva-ruolo");

    let ruoli = [];
    let permessiPageEditor = []; // Caricati dinamicamente

    const permessiViste = [
        "view_segnalazioni",
        "view_contatti",
        "view_mappa",
        "view_commesse",
        "view_dashboard_ore",
        "view_protocollo_email",
        "view_gare",
        "view_archivio",
        "view_qualita",
        "view_formazione",
        "view_mom"
    ];

    const permessiSpeciali = [
        "view_gestione_intranet",

        "view_gestione_ruoli",
        "view_mappa_admin",
        "view_gestione_commesse",
        "view_gestione_changelog",
        "view_import_manager",
        "manage_archivio",
        "manage_qualita",
        "manage_formazione",
        "view_gestione_protocollo_email",
        "edit_mom",
        "delete_mom",
        "export_mom"
    ];

    const permessiAvanzati = [
        "view_competenze_profilo",
        "protocollo_menu_generale",
        "protocollo_menu_commesse",
        "view_mom_generale",
        "view_mom_commessa"
    ];

    const etichettePermessi = {
        view_gestione_intranet: "Gestione Intranet",
        view_segnalazioni: "Segnalazioni",
        view_moduli: "Gestione Moduli",
        view_commesse: "Commesse",
        view_dashboard_ore: "Dashboard Ore",
        view_protocollo_email: "Protocollo Email",
        view_contatti: "Contatti",
        view_mappa: "Mappa Ufficio",
        view_gestione_commesse: "Gestione Commesse",
        view_mappa_admin: "Mappa Ufficio (Admin)",
        view_gestione_ruoli: "Gestione Ruoli",
        view_competenze_profilo: "Visualizza Competenze in Profilo",
        view_gestione_changelog: "Gestione Changelog",
        view_gare: "Gare",
        view_import_manager: "Import Manager",
        view_archivio: "Archivio",
        manage_archivio: "Gestione Archivio (creazione/upload)",
        view_qualita: "Qualità",
        manage_qualita: "Gestione Qualità (creazione/upload)",
        view_formazione: "Formazione",
        manage_formazione: "Gestione Formazione (creazione/upload)",
        view_gestione_protocollo_email: "Gestione Impostazioni Protocollo Email",
        protocollo_menu_generale: "protocollo - menu generale",
        protocollo_menu_commesse: "protocollo - menu commesse",
        view_mom: "Verbali Riunione (MOM)",
        edit_mom: "Modifica Verbali Riunione",
        delete_mom: "Elimina Verbali Riunione",
        export_mom: "Esporta Verbali Riunione (PDF)",
        view_mom_generale: "Verbali Riunione - Area Generale",
        view_mom_commessa: "Verbali Riunione - Area Commessa"
    };

    let ruoloCorrente = null;

    async function caricaPermessiPageEditor() {
        if (permessiPageEditor.length > 0) return permessiPageEditor; // Cache

        try {
            const res = await customFetch("roles", "listPageEditorForms");
            console.log("Response listPageEditorForms:", res); // Debug

            if (res.success && Array.isArray(res.data)) {
                // Cleanup soft: genera permessi solo per form esistenti
                // I permessi orfani (form cancellati) non vengono mostrati come checkbox
                // ma rimangono in DB per non perdere configurazioni storiche
                permessiPageEditor = res.data.map(form => ({
                    id: `page_editor_form_view:${form.id}`,
                    name: form.name,
                    description: form.description || 'Nessuna descrizione'
                }));

                console.log("Permessi Page Editor caricati:", permessiPageEditor.length); // Debug

                // Aggiungi alle etichette
                permessiPageEditor.forEach(p => {
                    etichettePermessi[p.id] = `${p.name} (Page Editor)`;
                });
            } else {
                console.warn("listPageEditorForms: risposta non valida", res);
                permessiPageEditor = [];
            }
        } catch (error) {
            console.error("Errore caricamento permessi Page Editor:", error);
            permessiPageEditor = [];
        }

        return permessiPageEditor;
    }

    function aggiornaContatorePermessi() {
        const contatore = document.getElementById("permessi-contatore");
        if (!contatore) return;

        const checked = contenitorePermessi.querySelectorAll("input[type=checkbox]:checked").length;
        contatore.textContent = `(Selezionati: ${checked})`;
    }

    function creaCheckboxPermesso(permesso, ruolo) {
        const label = document.createElement("label");
        label.className = "checkbox-label";

        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.value = permesso;
        checkbox.checked = ruolo.permissions?.includes(permesso);

        // Event listener per aggiornare contatore
        checkbox.addEventListener("change", aggiornaContatorePermessi);

        // Tooltip per permessi protocollo (logica "limita a")
        if (permesso === "protocollo_menu_generale" || permesso === "protocollo_menu_commesse") {
            label.setAttribute("data-tooltip", "Se nessuna voce è selezionata, sono visibili entrambe. Se selezioni una voce, limiti la visibilità a quella.");
        }

        label.appendChild(checkbox);

        const nomeChiaro = etichettePermessi[permesso] || permesso;
        label.appendChild(document.createTextNode(" " + nomeChiaro));

        return label;
    }

    async function caricaRuoli() {
        const res = await customFetch("roles", "getRoles");
        if (res.success && Array.isArray(res.data)) {
            ruoli = res.data;
            aggiornaSidebar();
        } else {
            console.error("Errore nel caricamento ruoli:", res.message);
        }
    }

    function aggiornaSidebar() {
        listaRuoli.innerHTML = "";
        ruoli.forEach(ruolo => {
            const li = document.createElement("li");
            li.className = "ruolo-item";
            li.style.display = "flex";
            li.style.justifyContent = "space-between";
            li.style.alignItems = "center";
            li.style.gap = "8px";

            const span = document.createElement("span");
            span.textContent = ruolo.name;
            span.style.flex = "1";
            span.style.cursor = "default";

            const iconWrapper = document.createElement("div");
            iconWrapper.style.display = "flex";
            iconWrapper.style.gap = "8px";

            // 🖉 icona modifica
            const editIcon = document.createElement("img");
            editIcon.src = "assets/icons/edit.png";
            editIcon.alt = "Modifica";
            editIcon.title = "Modifica ruolo";
            editIcon.style.width = "18px";
            editIcon.style.height = "18px";
            editIcon.style.cursor = "pointer";
            editIcon.onclick = (e) => {
                e.stopPropagation();
                selezionaRuolo(ruolo); // apre la form con i dati
            };

            // 🗑 icona elimina
            const deleteIcon = document.createElement("img");
            deleteIcon.src = "assets/icons/delete.png";
            deleteIcon.alt = "Elimina";
            deleteIcon.title = "Elimina ruolo";
            deleteIcon.style.width = "18px";
            deleteIcon.style.height = "18px";
            deleteIcon.style.cursor = "pointer";
            deleteIcon.onclick = (e) => {
                e.stopPropagation();
                eliminaRuolo(ruolo.id, ruolo.name);
            };

            iconWrapper.appendChild(editIcon);
            iconWrapper.appendChild(deleteIcon);

            li.appendChild(span);
            li.appendChild(iconWrapper);
            listaRuoli.appendChild(li);
        });
    }


    function selezionaRuolo(ruolo) {
        document.getElementById("ruolo-editor").style.display = "block";
        ruoloCorrente = ruolo;
        titolo.textContent = "Modifica: " + ruolo.name;
        inputNome.value = ruolo.name;
        inputDescrizione.value = ruolo.description || "";

        contenitorePermessi.innerHTML = "";

        // Se il ruolo è il superuser (id = 1), mostra solo info
        if (ruolo.id === 1) {
            const infoBox = document.createElement("div");
            infoBox.className = "info-superuser";
            infoBox.style.padding = "10px";
            infoBox.style.backgroundColor = "#f1f1f1";
            infoBox.style.border = "1px solid #ccc";
            infoBox.style.marginTop = "10px";
            infoBox.innerHTML = `
                <p><strong>Administrator</strong> è un superutente con accesso completo.<br>
                Non ha bisogno di permessi specifici.</p>
            `;
            contenitorePermessi.appendChild(infoBox);
            return;
        }

        // Sezione Accesso Pagine
        const sezioneViste = document.createElement("div");
        sezioneViste.innerHTML = "<h4>Accesso alle pagine</h4>";
        permessiViste.forEach(p => {
            sezioneViste.appendChild(creaCheckboxPermesso(p, ruoloCorrente || { permissions: [] }));
        });
        contenitorePermessi.appendChild(sezioneViste);

        // Sezione Permessi speciali
        const sezioneSpeciali = document.createElement("div");
        sezioneSpeciali.innerHTML = "<h4>Permessi speciali</h4>";
        permessiSpeciali.forEach(p => {
            sezioneSpeciali.appendChild(creaCheckboxPermesso(p, ruoloCorrente || { permissions: [] }));
        });
        contenitorePermessi.appendChild(sezioneSpeciali);

        // Sezione Pagine Page Editor (carica dinamicamente)
        caricaPermessiPageEditor().then(() => {
            const sezionePageEditor = document.createElement("div");
            sezionePageEditor.innerHTML = "<h4>Pagine Page Editor</h4>";

            if (permessiPageEditor.length === 0) {
                const noFormsMsg = document.createElement("p");
                noFormsMsg.style.color = "#666";
                noFormsMsg.style.fontStyle = "italic";
                noFormsMsg.style.margin = "10px 0";
                noFormsMsg.textContent = "Nessuna pagina Page Editor disponibile.";
                sezionePageEditor.appendChild(noFormsMsg);
            } else {
                permessiPageEditor.forEach(p => {
                    const label = creaCheckboxPermesso(p.id, ruoloCorrente || { permissions: [] });
                    // Aggiungi tooltip se disponibile
                    if (p.description) {
                        label.setAttribute('data-tooltip', p.description);
                    }
                    sezionePageEditor.appendChild(label);
                });
            }

            contenitorePermessi.appendChild(sezionePageEditor);

            // Sezione Permessi Avanzati (ultima a destra)
            const sezioneAvanzati = document.createElement("div");
            sezioneAvanzati.innerHTML = "<h4>Permessi Avanzati</h4>";
            permessiAvanzati.forEach(p => {
                sezioneAvanzati.appendChild(creaCheckboxPermesso(p, ruoloCorrente || { permissions: [] }));
            });
            contenitorePermessi.appendChild(sezioneAvanzati);

            aggiornaContatorePermessi();
        }).catch(error => {
            console.error("Errore caricamento sezione Page Editor:", error);
            const sezionePageEditor = document.createElement("div");
            sezionePageEditor.innerHTML = "<h4>Pagine Page Editor</h4>";
            const errorMsg = document.createElement("p");
            errorMsg.style.color = "#dc3545";
            errorMsg.style.margin = "10px 0";
            errorMsg.textContent = "Errore nel caricamento delle pagine.";
            sezionePageEditor.appendChild(errorMsg);
            contenitorePermessi.appendChild(sezionePageEditor);

            // Sezione Permessi Avanzati (ultima a destra) - anche in caso di errore
            const sezioneAvanzati = document.createElement("div");
            sezioneAvanzati.innerHTML = "<h4>Permessi Avanzati</h4>";
            permessiAvanzati.forEach(p => {
                sezioneAvanzati.appendChild(creaCheckboxPermesso(p, ruoloCorrente || { permissions: [] }));
            });
            contenitorePermessi.appendChild(sezioneAvanzati);
        });

        // Aggiorna contatore anche per permessi già caricati
        aggiornaContatorePermessi();

    }

    function eliminaRuolo(id, nome) {
        showConfirm(`Vuoi eliminare il ruolo "${nome}"?`, function () {
            customFetch("roles", "deleteRole", { id }).then(res => {
                if (res.success) {
                    showToast("Ruolo eliminato con successo.", "success");
                    caricaRuoli();
                } else {
                    showToast("Errore durante l'eliminazione: " + (res.message || "Errore sconosciuto"), "error");
                }
            });
        });
    }

    btnNuovoRuolo.addEventListener("click", () => {
        document.getElementById("ruolo-editor").style.display = "block";
        ruoloCorrente = null;
        titolo.textContent = "Nuovo Ruolo";
        inputNome.value = "";
        inputDescrizione.value = "";
        contenitorePermessi.innerHTML = "";

        // Sezione Accesso Pagine
        const sezioneViste = document.createElement("div");
        sezioneViste.innerHTML = "<h4>Accesso alle pagine</h4>";
        permessiViste.forEach(p => {
            sezioneViste.appendChild(creaCheckboxPermesso(p, ruoloCorrente || { permissions: [] }));
        });
        contenitorePermessi.appendChild(sezioneViste);

        // Sezione Permessi speciali
        const sezioneSpeciali = document.createElement("div");
        sezioneSpeciali.innerHTML = "<h4>Permessi speciali</h4>";
        permessiSpeciali.forEach(p => {
            sezioneSpeciali.appendChild(creaCheckboxPermesso(p, ruoloCorrente || { permissions: [] }));
        });
        contenitorePermessi.appendChild(sezioneSpeciali);

        // Sezione Pagine Page Editor (carica dinamicamente)
        caricaPermessiPageEditor().then(() => {
            const sezionePageEditor = document.createElement("div");
            sezionePageEditor.innerHTML = "<h4>Pagine Page Editor</h4>";

            if (permessiPageEditor.length === 0) {
                const noFormsMsg = document.createElement("p");
                noFormsMsg.style.color = "#666";
                noFormsMsg.style.fontStyle = "italic";
                noFormsMsg.style.margin = "10px 0";
                noFormsMsg.textContent = "Nessuna pagina Page Editor disponibile.";
                sezionePageEditor.appendChild(noFormsMsg);
            } else {
                permessiPageEditor.forEach(p => {
                    const label = creaCheckboxPermesso(p.id, ruoloCorrente || { permissions: [] });
                    // Aggiungi tooltip se disponibile
                    if (p.description) {
                        label.setAttribute('data-tooltip', p.description);
                    }
                    sezionePageEditor.appendChild(label);
                });
            }

            contenitorePermessi.appendChild(sezionePageEditor);

            // Sezione Permessi Avanzati (ultima a destra)
            const sezioneAvanzati = document.createElement("div");
            sezioneAvanzati.innerHTML = "<h4>Permessi Avanzati</h4>";
            permessiAvanzati.forEach(p => {
                sezioneAvanzati.appendChild(creaCheckboxPermesso(p, ruoloCorrente || { permissions: [] }));
            });
            contenitorePermessi.appendChild(sezioneAvanzati);

            aggiornaContatorePermessi();
        }).catch(error => {
            console.error("Errore caricamento sezione Page Editor:", error);
            const sezionePageEditor = document.createElement("div");
            sezionePageEditor.innerHTML = "<h4>Pagine Page Editor</h4>";
            const errorMsg = document.createElement("p");
            errorMsg.style.color = "#dc3545";
            errorMsg.style.margin = "10px 0";
            errorMsg.textContent = "Errore nel caricamento delle pagine.";
            sezionePageEditor.appendChild(errorMsg);
            contenitorePermessi.appendChild(sezionePageEditor);

            // Sezione Permessi Avanzati (ultima a destra) - anche in caso di errore
            const sezioneAvanzati = document.createElement("div");
            sezioneAvanzati.innerHTML = "<h4>Permessi Avanzati</h4>";
            permessiAvanzati.forEach(p => {
                sezioneAvanzati.appendChild(creaCheckboxPermesso(p, ruoloCorrente || { permissions: [] }));
            });
            contenitorePermessi.appendChild(sezioneAvanzati);
        });

        // Aggiorna contatore anche per permessi già caricati
        aggiornaContatorePermessi();
    });

    btnSalva.addEventListener("click", () => {
        const nome = inputNome.value.trim();
        const descrizione = inputDescrizione.value.trim();
        const permessi = Array.from(contenitorePermessi.querySelectorAll("input[type=checkbox]"))
            .filter(cb => cb.checked)
            .map(cb => cb.value);

        if (!nome) {
            showToast("Inserisci un nome per il ruolo", "error");
            return;
        }

        const payload = {
            id: ruoloCorrente?.id || null,
            name: nome,
            description: descrizione,
            permissions: permessi
        };

        customFetch("roles", "saveRole", payload).then(res => {
            if (res.success) {
                showToast("Ruolo salvato con successo", "success");
                caricaRuoli();
                ruoloCorrente = null;
                titolo.textContent = "Nessun ruolo selezionato";
                inputNome.value = "";
                inputDescrizione.value = "";
                contenitorePermessi.innerHTML = "";
            } else {
                showToast("Errore durante il salvataggio: " + (res.message || "Errore generico"), "error");
            }
        });

    });
    caricaRuoli();
});

document.querySelectorAll(".tab-link").forEach(link => {
    link.addEventListener("click", function () {
        document.querySelectorAll(".tab-link").forEach(l => l.classList.remove("active"));
        document.querySelectorAll(".tabcontent").forEach(tab => tab.classList.remove("active"));

        this.classList.add("active");
        const tabId = this.getAttribute("data-tab");
        document.getElementById(tabId).classList.add("active");

        // carica utenti e ruoli se tab è "assegnazione"
        if (tabId === "tab-assegnazione") {
            caricaUtentiERuoli();
        }
    });
});

// --- VARIABILI GLOBALI per filtri (mettile all’inizio file ma anche qui vanno bene)
let utentiRuoliData = [];
let ruoliData = [];
let selectedUsers = [];

async function caricaUtentiERuoli() {
    const res = await customFetch("roles", "getUserRoleMappings");
    if (!res.success) {
        console.error("Errore nel caricamento utenti/ruoli:", res.message);
        return;
    }

    utentiRuoliData = res.users || [];
    ruoliData = res.roles || [];

    renderUserRoleRows();
}

// Funzione per renderizzare le righe filtrate
function renderUserRoleRows() {
    const tbody = document.getElementById("user-role-body");
    if (!tbody) return;
    tbody.innerHTML = "";

    (utentiRuoliData || []).forEach(utente => {
        const tr = document.createElement("tr");

        const tdCheck = document.createElement("td");
        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.classList.add("user-checkbox");
        checkbox.dataset.userId = utente.user_id;
        checkbox.checked = selectedUsers.includes(String(utente.user_id)); // <-- qui!
        checkbox.addEventListener("change", function () {
            const id = String(utente.user_id);
            if (this.checked) {
                if (!selectedUsers.includes(id)) selectedUsers.push(id);
            } else {
                selectedUsers = selectedUsers.filter(uid => uid !== id);
            }
        });
        tdCheck.appendChild(checkbox);
        tr.appendChild(tdCheck);

        const tdNome = document.createElement("td");
        tdNome.textContent = utente.Nominativo;

        const tdEmail = document.createElement("td");
        tdEmail.textContent = utente.Email_Aziendale || "-";

        const tdRuolo = document.createElement("td");
        const ruoliContainer = document.createElement("div");
        ruoliContainer.classList.add("user-roles-container");
        ruoliContainer.dataset.userId = utente.user_id;
        ruoliContainer.style.display = "flex";
        ruoliContainer.style.flexWrap = "wrap";
        ruoliContainer.style.gap = "5px";

        ruoliData.forEach(ruolo => {
            const label = document.createElement("label");
            label.classList.add("role-checkbox-label");
            label.style.display = "flex";
            label.style.alignItems = "center";
            label.style.fontSize = "12px";
            label.style.marginRight = "10px";

            const checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.classList.add("user-role-checkbox");
            checkbox.dataset.userId = utente.user_id;
            checkbox.dataset.roleId = ruolo.id;
            checkbox.checked = (utente.role_ids || []).includes(ruolo.id);

            checkbox.addEventListener("change", async function () {
                const userId = this.dataset.userId;
                const roleId = this.dataset.roleId;
                const isChecked = this.checked;

                try {
                    const action = isChecked ? "addRoleToUser" : "removeRoleFromUser";
                    const res = await customFetch("roles", action, {
                        user_id: userId,
                        role_id: roleId
                    });

                    if (!res.success) {
                        console.error(`Errore ${action}:`, res.message);
                        showToast(`Errore nell'${isChecked ? 'aggiunta' : 'rimozione'} del ruolo: ${res.message}`, "error");
                        // Ripristina lo stato precedente
                        this.checked = !isChecked;
                    } else {
                        console.log(`Ruolo ${isChecked ? 'aggiunto' : 'rimosso'} per utente ${userId}`);
                    }
                } catch (error) {
                    console.error("Errore nella richiesta:", error);
                    showToast("Errore nella richiesta al server", "error");
                    // Ripristina lo stato precedente
                    this.checked = !isChecked;
                }
            });

            const span = document.createElement("span");
            span.textContent = ruolo.name + " ";

            label.appendChild(checkbox);
            label.appendChild(span);
            ruoliContainer.appendChild(label);
        });

        tdRuolo.appendChild(ruoliContainer);

        tr.appendChild(tdNome);
        tr.appendChild(tdEmail);
        tr.appendChild(tdRuolo);
        tbody.appendChild(tr);
    });

    // Popola la select massiva (ruoli per assegnazione multipla)
    const selectMassiva = document.getElementById("mass-role-select");
    if (selectMassiva) {
        selectMassiva.innerHTML = '<option value="">— Assegna ruolo —</option>';
        ruoliData.forEach(ruolo => {
            const opt = document.createElement("option");
            opt.value = ruolo.id;
            opt.textContent = ruolo.name;
            selectMassiva.appendChild(opt);
        });
    }

    // --- Gestione SELEZIONA TUTTI ---
    const selectAll = document.getElementById("select-all-users");
    if (selectAll) {
        // Setta lo stato coerente (spuntato solo se tutte le righe sono selezionate)
        const checkboxes = document.querySelectorAll(".user-checkbox");
        selectAll.checked = checkboxes.length > 0 && Array.from(checkboxes).every(cb => cb.checked);

        // Rimuovi eventuali listener doppi
        selectAll.onchange = null;

        selectAll.addEventListener("change", function () {
            const allChecked = this.checked;
            document.querySelectorAll(".user-checkbox").forEach(cb => {
                cb.checked = allChecked;
                const id = String(cb.dataset.userId);
                if (allChecked) {
                    if (!selectedUsers.includes(id)) selectedUsers.push(id);
                } else {
                    selectedUsers = [];
                }
            });
        });
    }

}

document.addEventListener("change", function (e) {
    if (e.target.id === "select-all-users") {
        const checkboxes = document.querySelectorAll(".user-checkbox");
        checkboxes.forEach(cb => cb.checked = e.target.checked);
    }
});

// Event listener per checkbox ruoli rimosso - gestito inline negli elementi

document.getElementById("apply-mass-role").addEventListener("click", async () => {
    const selectedRoleId = document.getElementById("mass-role-select").value;
    if (!selectedRoleId) {
        showToast("Seleziona un ruolo da assegnare.", "error");
        return;
    }

    const selectedCheckboxes = document.querySelectorAll(".user-checkbox:checked");
    if (selectedCheckboxes.length === 0) {
        showToast("Seleziona almeno un utente.", "error");
        return;
    }

    let count = 0;
    let promises = [];

    selectedCheckboxes.forEach(cb => {
        const userId = cb.dataset.userId;

        if (!userId || isNaN(parseInt(userId))) {
            console.warn(" Skipping utente con ID non valido:", userId);
            return;
        }

        promises.push(
            customFetch("roles", "addRoleToUser", {
                user_id: userId,
                role_id: selectedRoleId
            }).then(res => {
                if (!res.success) {
                    console.error(" Errore su utente", userId, res.message);
                }
            })
        );
        count++;
    });

    await Promise.all(promises);

    selectedUsers = [];
    showToast(`Ruolo assegnato a ${count} utente/i.`, "success");
    caricaUtentiERuoli();
});
