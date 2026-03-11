document.addEventListener("DOMContentLoaded", function () {
    console.log(" moduli_admin.js caricato");

    const container = document.getElementById("admin-form-grid");
    if (!container) {
        console.error(" Contenitore #admin-form-grid non trovato.");
        return;
    }

    customFetch("segnalazioni", "getFormStatistics")
        .then(data => {
            if (!data.success || !Array.isArray(data.stats)) {
                console.error(" Errore nel caricamento moduli:", data.error || data.message);
                container.innerHTML = "<p style='color: #888;'>Nessun modulo disponibile.</p>";
                return;
            }

            container.innerHTML = "";

            data.stats.forEach(form => {
                const card = document.createElement("div");
                card.classList.add("form-preview");
                card.dataset.id = form.id;
                // Usa original_name per i link (se presente, altrimenti fallback a name)
                const originalName = form.original_name || form.name;
                card.style.setProperty("--form-color", form.color || "#ccc");
                
                card.innerHTML = `
                <div class="form-header">
                    <h3 class="form-title">${form.name}</h3>
                </div>
                <div class="form-description">${form.description || "Nessuna descrizione disponibile."}</div>
                
                <div class="total-reports"><strong>Segnalazioni:</strong> ${form.total_reports}</div>
                <div class="form-actions">
                    <button class="button view-btn" data-name="${originalName}">Visualizza</button>
                    <button class="button edit-btn" data-name="${originalName}" data-id="${form.last_entry_id || 1}">Modifica</button>
                </div>
                <div class="form-meta">
                    <span class="form-created-by">Creato da</span>
                    <img src="${form.created_by_img || 'assets/images/default_profile.png'}" alt="Creatore" class="profile-icon">
                    <span class="form-date">${form.created_at || "-"}</span>
                </div>
            `;
            
                container.appendChild(card);            
            });

            registerContextMenu(".form-preview", [
                {
                    label: "Elimina Modulo",
                    action: (el) => {
                        const formId = el.dataset.id; // usa ID, non name
                        if (!formId) return;
            
                        showConfirm("Vuoi davvero eliminare il modulo?", () => {
                            eliminaModulo(formId);
                        });
                    }
                }
            ]);
             
            document.querySelectorAll(".view-btn").forEach(btn => {
                btn.addEventListener("click", () => {
                    const name = btn.dataset.name;
                    window.location.href = `index.php?section=collaborazione&page=form&form_name=${encodeURIComponent(name)}`;
                });
            });

            document.querySelectorAll(".edit-btn").forEach(btn => {
                btn.addEventListener("click", () => {
                    const name = btn.dataset.name;
                    const id = btn.dataset.id || 1;
                    window.location.href = `index.php?section=collaborazione&page=form_editor&form_name=${encodeURIComponent(name)}&id=${id}`;
                });
            });
        })
        .catch(err => {
            console.error(" Errore nella richiesta:", err);
            container.innerHTML = "<p style='color: red;'>Errore durante il caricamento dei moduli.</p>";
        });

        async function eliminaModulo(formId) {
            const res = await customFetch("segnalazioni", "deleteForm", {
                form_id: parseInt(formId)
            });
        
            if (res.success) {
                showToast("Modulo eliminato con successo");
                location.reload();
            } else {
                showToast("Errore eliminazione: " + (res.message || "Errore sconosciuto"), "error");
            }
        }
        
});