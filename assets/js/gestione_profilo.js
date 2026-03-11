document.addEventListener("DOMContentLoaded", function () {
    const areaSelect = document.getElementById("areaSelect");
    const competenceSelect = document.getElementById("competenceSelect");
    const addCompetenceForm = document.getElementById("addCompetenceForm");
    const userCompetencesContainer = document.getElementById("userCompetencesContainer");
    
    const competenzeTabPresente = areaSelect && competenceSelect && addCompetenceForm && userCompetencesContainer;
    const userIdInput = document.getElementById("userId");

    if (!userIdInput) return;
    const userId = userIdInput.value;

    // Carica info personali dal backend
    async function loadPersonalInfo() {
        const result = await customFetch("profile", "getPersonalInfo", { id: userId });
    
        if (result.success && result.data) {
            document.getElementById("Luogo_di_Nascita").value = result.data.Luogo_di_Nascita || '';
            document.getElementById("Data_di_Nascita").value = result.data.Data_di_Nascita || '';
            document.getElementById("Codice_Fiscale").value = result.data.Codice_Fiscale || '';
            document.getElementById("Email_Personale").value = result.data.Email_Personale || '';
            document.getElementById("Cellulare_Personale").value = result.data.Cellulare_Personale || '';
            document.getElementById("Cellulare_Aziendale").value = result.data.Cellulare_Aziendale || '';
            document.getElementById("Telefono_Personale").value = result.data.Telefono_Personale || '';
            document.getElementById("Indirizzo").value = result.data.Indirizzo || '';
            document.getElementById("CAP").value = result.data.CAP || '';
            document.getElementById("Citt").value = result.data.Citt || '';
            document.getElementById("Provincia").value = result.data.Provincia || '';
            document.getElementById("Nazione").value = result.data.Nazione || '';
            document.getElementById("Titolo_di_Studio").value = result.data.Titolo_di_Studio || '';
        } else {
            console.error("Errore nel caricamento delle informazioni personali:", result.message);
        }
    }
    
    loadPersonalInfo();

    // Gestione invio form aggiornamento
    document.getElementById("updatePersonalInfoForm").addEventListener("submit", async function (e) {
        e.preventDefault();
    
        const data = {
            id: userId,
            Luogo_di_Nascita: document.getElementById("Luogo_di_Nascita").value,
            Data_di_Nascita: document.getElementById("Data_di_Nascita").value,
            Codice_Fiscale: document.getElementById("Codice_Fiscale").value,
            Email_Personale: document.getElementById("Email_Personale").value,
            Cellulare_Personale: document.getElementById("Cellulare_Personale").value,
            Cellulare_Aziendale: document.getElementById("Cellulare_Aziendale").value,
            Telefono_Personale: document.getElementById("Telefono_Personale").value,
            Indirizzo: document.getElementById("Indirizzo").value,
            CAP: document.getElementById("CAP").value,
            Citt: document.getElementById("Citt").value,
            Provincia: document.getElementById("Provincia").value,
            Nazione: document.getElementById("Nazione").value,
            Titolo_di_Studio: document.getElementById("Titolo_di_Studio").value
        };
    
        const result = await customFetch("profile", "updatePersonalInfo", data);
    
        if (result.success) {
            showToast("Informazioni aggiornate con successo.", "success");
        } else {
            showToast("Errore: " + result.message, "error");
        }
    });
    
    function maskPhoneInput(input) {
        input.addEventListener("input", function(e) {
            let value = e.target.value.replace(/\D/g, "");
            if (value.length > 10) value = value.substring(0, 10);
    
            if (value.length > 6) {
                e.target.value = value.replace(/(\d{3})(\d{3})(\d{0,4})/, "$1 $2 $3").trim();
            } else if (value.length > 3) {
                e.target.value = value.replace(/(\d{3})(\d{0,3})/, "$1 $2").trim();
            } else {
                e.target.value = value;
            }
        });
    }
    
    ["Telefono_Personale", "Cellulare_Personale", "Cellulare_Aziendale"].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) maskPhoneInput(el);
    });
    
    document.getElementById("Codice_Fiscale").addEventListener("input", function(e) {
        e.target.value = e.target.value.toUpperCase();
    });
    
    const updateBioForm = document.getElementById("updateBioForm");
    if (updateBioForm) {
        updateBioForm.addEventListener("submit", async function (e) {
            e.preventDefault();
    
            const bioInput = document.getElementById("bio_textarea");
            if (!bioInput) {
                showToast("Errore interno: campo bio non trovato.", "error");
                return;
            }
    
            const bio = (bioInput.value || "").trim();
    
            // puoi permettere anche una bio vuota (ma avvisa)
            // if (!bio) {
            //     showToast("La bio non può essere vuota.", "error");
            //     return;
            // }
    
            const result = await customFetch("profile", "updateBio", { bio });
    
            if (result.success) {
                showToast("Bio aggiornata con successo.", "success");
            } else {
                showToast("Errore: " + result.message, "error");
            }
        });
    }
    
if (competenzeTabPresente) {
    // Caricamento aree da HrService
    async function loadAreas() {
        const result = await customFetch("hr", "getAreas");

        if (result.success && Array.isArray(result.data)) {
            const options = result.data.map(({ id, nome }) => `<option value="${nome}" data-id="${id}">${nome}</option>`).join('');
            areaSelect.innerHTML = `<option value="">-- Seleziona un'area --</option>${options}`;
        } else {
            console.warn("Errore nel caricamento delle aree:", result.message);
        }
    }

    // Caricamento competenze per area da HrService
    async function loadCompetencesForArea(areaName) {
        // Cerca l'opzione selezionata e ricava il suo ID (valore numerico)
        const selectedOption = Array.from(areaSelect.options).find(opt => opt.value === areaName);
        const areaId = selectedOption?.getAttribute("data-id");
    
        if (!areaId) {
            competenceSelect.innerHTML = '<option value="" disabled selected>Nessuna competenza disponibile.</option>';
            return;
        }
    
        const result = await customFetch("hr", "getCompetencesForArea", { areaId: parseInt(areaId) });
    
        if (result.success && Array.isArray(result.data)) {
            const options = result.data.map(({ id, nome }) => `<option value="${id}">${nome}</option>`).join('');
            competenceSelect.innerHTML = `<option value="">-- Seleziona una competenza --</option>${options}`;
        } else {
            competenceSelect.innerHTML = '<option value="" disabled selected>Nessuna competenza disponibile.</option>';
        }
    }

    areaSelect.addEventListener("change", function () {
        const selectedArea = areaSelect.value;
        if (selectedArea) loadCompetencesForArea(selectedArea);
    });

    loadAreas();

    // Aggiorna il livello di competenza visualizzato
    document.getElementById("competenceLevel").addEventListener("input", function () {
        document.getElementById("levelIndicator").textContent = this.value;
    });

    // Carica le competenze associate all'utente
    async function loadUserCompetences() {
        const userId = document.getElementById("userId").value;

        const result = await customFetch("hr", "getUserCompetences", { id: userId });

        if (result.success && Array.isArray(result.data)) {
            const tags = result.data.map(({ competenza_nome, area_nome, lvl = 0, competenza_id }) => {
                const dots = Array.from({ length: 3 }, (_, i) =>
                    `<span class="dot ${i < lvl ? 'filled' : ''}"></span>`
                ).join('');

                return `
                    <div class="competence-tag">
                        <span class="competence-name">${competenza_nome}</span>
                        <span class="area-name">(${area_nome})</span>
                        <div class="competence-dots">${dots}</div>
                        <button class="btn-delete-tag" onclick="removeCompetence(${competenza_id})">✕</button>
                    </div>`;
            }).join('');

            userCompetencesContainer.innerHTML = tags;
        } else {
            console.error("Errore nel caricamento competenze:", result.message);
            userCompetencesContainer.innerHTML = '<p>Nessuna competenza trovata.</p>';
        }
    }

    // Aggiungi una competenza
    addCompetenceForm.addEventListener("submit", async function (e) {
        e.preventDefault();

        const competenceId = competenceSelect.value;
        const level = document.getElementById("competenceLevel").value;

        if (!competenceId) {
            showToast("Seleziona una competenza.", "error");
            return;
        }
        
        const result = await customFetch("hr", "addCompetence", {
            competenza_id: competenceId,
            lvl: level
        });
        
        if (result.success) {
            showToast("Competenza aggiunta con successo.", "success");
            loadUserCompetences();
        } else {
            showToast("Errore: " + result.message, "error");
        }        
    });

    // Rimuovi una competenza
    window.removeCompetence = async function (competenceId) {
        showConfirm("Sei sicuro di voler rimuovere questa competenza?", async function () {
            const result = await customFetch("hr", "removeCompetence", {
                competenza_id: competenceId
            });
    
            if (result.success) {
                showToast("Competenza rimossa con successo.", "success");
                loadUserCompetences();
            } else {
                showToast("Errore: " + result.message, "error");
            }
        });
    };
    
    loadAreas();
    loadUserCompetences();
}

    // Gestione delle schede
    const tabs = document.querySelectorAll('.tab-link');
    const contents = document.querySelectorAll('.tabcontent');

    tabs.forEach(tab => {
        tab.addEventListener('click', function () {
            const targetTab = this.getAttribute('data-tab');

            // Aggiorna l'URL senza ricaricare la pagina
            const url = new URL(window.location);
            url.searchParams.set('tab', targetTab);
            window.history.pushState({}, '', url);

            // Rimuovi "active" da tutte le schede/contenuti
            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(content => content.classList.remove('active'));

            // Attiva quella selezionata
            this.classList.add('active');
            document.getElementById(targetTab).classList.add('active');
        });
    });

    // Attiva la scheda giusta al primo load
    const activeTab = new URLSearchParams(window.location.search).get('tab') || 'personal-info';

    const tabButton = document.querySelector(`[data-tab="${activeTab}"]`);
    const tabContent = document.getElementById(activeTab);
    
    if (tabButton && tabContent) {
        tabButton.classList.add('active');
        tabContent.classList.add('active');
    } else {
        // fallback su personal-info se la tab richiesta non esiste
        document.querySelector(`[data-tab="personal-info"]`).classList.add('active');
        document.getElementById("personal-info").classList.add('active');
    }
    
});

document.addEventListener("DOMContentLoaded", function () {
    const changePasswordForm = document.querySelector("#changePasswordForm");

    if (changePasswordForm) {
        changePasswordForm.addEventListener("submit", async function (event) {
            event.preventDefault();

            const currentPassword = document.getElementById("currentPassword").value.trim();
            const newPassword = document.getElementById("newPassword").value.trim();
            const confirmPassword = document.getElementById("confirmNewPassword").value.trim();

            // prendi l'hidden e popola sessionstorage, così ajax.js invia l'header 'Csrf-Token'
            const csrfTokenEl = document.getElementById("token-csrf");
            if (csrfTokenEl && csrfTokenEl.value) {
                try { sessionStorage.setItem("CSRFtoken", csrfTokenEl.value); } catch(e) {}
            }

            try {
                const result = await customFetch("user", "changePassword", {
                    currentPassword,
                    newPassword,
                    confirmPassword
                });

                showMessage(result.success ? "success" : "error", result.message);

                if (result.success) {
                    changePasswordForm.reset();
                } else {
                    document.getElementById("currentPassword").value = "";
                    document.getElementById("newPassword").value = "";
                    document.getElementById("confirmNewPassword").value = "";
                }
            } catch (err) {
                console.error("errore nel cambio password:", err);
                showMessage("error", "errore durante l'invio della richiesta.");
            }
        });
    }
});

// Funzione di messaggio
function showMessage(type, message) {
    const messageBox = document.createElement("div");
    messageBox.className = type === "success" ? "success-message" : "error-message";
    messageBox.textContent = message;

    const formContainer = document.querySelector("#password-messages");
    formContainer.innerHTML = "";
    formContainer.appendChild(messageBox);

    setTimeout(() => messageBox.remove(), 3000);
}

