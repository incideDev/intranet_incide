document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("form-nuova-bacheca");

    if (form) {
        form.addEventListener("submit", async (e) => {
            e.preventDefault();

            const titolo = form.querySelector("input[name='titolo']").value.trim();

            if (!titolo) {
                showToast("Inserisci il titolo della bacheca", "error");
                return;
            }
            
            // 🔄 Prendi anche i dati anagrafici se compilati
            const anagraficaForm = document.getElementById("form-anagrafica-commessa");
            let anagrafica = {};

            if (anagraficaForm) {
                const formData = new FormData(anagraficaForm);
                formData.forEach((value, key) => {
                    const match = key.match(/^anagrafica\[(.+)\]$/);
                    if (match) {
                        anagrafica[match[1]] = value.trim();
                    }
                });
            }

            const payload = {
                section: "commesse",
                action: "createTabella",
                titolo,
                anagrafica: window.anagraficaTemp || {}
            };

            const res = await customFetch("commesse", "createTabella", payload);

            if (res.success) {
                const tabellaGenerata = titolo
                    .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
                    .replace(/\s+/g, "_")
                    .replace(/[^a-zA-Z0-9_]/g, "")
                    .toLowerCase();

                window.location.href = `index.php?section=commesse&page=commessa&tabella=${encodeURIComponent(tabellaGenerata)}&titolo=${encodeURIComponent(titolo)}`;
                window.anagraficaTemp = null;
            } else {
                showToast("Errore: " + (res.message || "Impossibile creare la bacheca."), "error");
                console.error("Errore creazione bacheca:", res);
            }
        });
    }

    // Menu contestuale per eliminare commesse
    registerContextMenu(".form-preview", [
        {
            label: "Elimina Commessa",
            action: (el) => {
                const id = el.dataset.id;
                if (!id) return;
                showConfirm("Vuoi davvero eliminare questa commessa?", () => {
                    eliminaCommessa(id);
                });
                
            }
        }
    ]);
});

// Modale anagrafica: apertura e precompilazione
window.openAnagraficaEditor = async function (bachecaId) {
    const form = document.getElementById("form-anagrafica-commessa");
    if (!form) return;

    form.reset();
    form.dataset.bachecaId = bachecaId;

    // Apri PRIMA il modale
    document.getElementById("modal-anagrafica-commessa").style.display = "block";

    const res = await customFetch("commesse", "getAnagrafica", { bacheca_id: bachecaId });

    if (res.success && res.data) {
        Object.entries(res.data).forEach(([key, value]) => {
            const input = form.querySelector(`[name="anagrafica[${key}]"]`);
            if (input) input.value = value ?? ''; // fallback se null
        });
    } else {
        showToast("Errore caricamento anagrafica", "error");
    }
};

// Salvataggio anagrafica commessa
document.getElementById("form-anagrafica-commessa")?.addEventListener("submit", async function (e) {
    e.preventDefault();

    const form = e.target;
    const bachecaId = form.dataset.bachecaId || null;

    if (!bachecaId) {
        // Crea una nuova bacheca: salva l’anagrafica temporaneamente
        window.anagraficaTemp = {};
    
        const formData = new FormData(form);
        formData.forEach((value, key) => {
            const match = key.match(/^anagrafica\[(.+)\]$/);
            if (match) {
                window.anagraficaTemp[match[1]] = value.trim();
            }
        });
    
        showToast("Anagrafica salvata. Ora completa la creazione della bacheca.");
        form.reset();
        document.getElementById("modal-anagrafica-commessa").style.display = "none";
        return;
    }
    
    const formData = new FormData(form);
    const anagrafica = {};

    formData.forEach((value, key) => {
        const match = key.match(/^anagrafica\[(.+)\]$/);
        if (match) {
            anagrafica[match[1]] = value.trim();
        }
    });

    const res = await customFetch("commesse", "saveAnagrafica", {
        bacheca_id: bachecaId,
        anagrafica
    });

    if (res.success) {
        showToast("Anagrafica salvata con successo");
    } else {
        showToast("Errore nel salvataggio: " + (res.message || "Errore sconosciuto"), "error");
        console.error(res);
    }
});

async function eliminaCommessa(id) {
    const res = await customFetch("commesse", "deleteBacheca", { id });
    if (res.success) {
        showToast("Commessa eliminata con successo");
        location.reload();
    } else {
        showToast("Errore eliminazione: " + (res.message || "Errore sconosciuto"), "error");
    }
}
