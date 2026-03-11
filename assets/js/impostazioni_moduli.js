document.addEventListener("DOMContentLoaded", function () {
  const container = document.getElementById("admin-form-grid");
  if (!container) return;



  customFetch("page_editor", "getAllFormsForAdmin")
    .then((data) => {
      if (!data.success || !Array.isArray(data.stats)) {
        container.innerHTML = "<p style='color:#888'>Nessun modulo disponibile.</p>";
        return;
      }
      container.innerHTML = "";

      // Filtra le pagine in base ai permessi (fail-closed: se permessi non disponibili, non mostrare nulla)
      const filteredForms = data.stats.filter((form) => {
        // Verifica che i permessi siano disponibili
        if (!window.CURRENT_USER || !window.userHasPermission) {
          return false; // Fail-closed: senza permessi disponibili, non mostrare
        }

        // Admin vede tutto (controllo affidabile su is_admin o role_ids)
        if (window.CURRENT_USER.is_admin === true) {
          return true;
        }
        const roleIds = window.CURRENT_USER.role_ids || [];
        if (Array.isArray(roleIds) && roleIds.includes(1)) {
          return true;
        }

        // Controlla permesso specifico per questa pagina
        const requiredPermission = `page_editor_form_view:${form.id}`;
        return window.userHasPermission(requiredPermission) === true;
      });

      filteredForms.forEach((form) => {
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
            <button class="button page-editor-btn" data-name="${originalName}">Modifica</button>
          </div>
          <div class="form-meta">
            <span class="form-created-by">Creato da</span>
            <img src="${form.created_by_img || 'assets/images/default_profile.png'}" alt="Creatore" class="profile-icon">
            <span class="form-date">${form.created_at || "-"}</span>
          </div>
        `;
        container.appendChild(card);
      });

      container.querySelectorAll(".view-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
          const name = btn.dataset.name;
          window.location.href = `index.php?section=collaborazione&page=form&form_name=${encodeURIComponent(name)}`;
        });
      });

      container.querySelectorAll(".page-editor-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
          const name = btn.dataset.name;
          window.location.href = `index.php?section=gestione_intranet&page=page_editor&form_name=${encodeURIComponent(name)}`;
        });
      });

      if (typeof registerContextMenu === "function") {
        registerContextMenu(".form-preview", [
          {
            label: "Proprietà pagina…",
            action: (el) => {
              const editBtn = el.querySelector(".page-editor-btn");
              const name = editBtn ? editBtn.dataset.name : el.dataset.name || "";
              if (!name) return;
              openProprietaModal(name);
            },
          },
          {
            label: "Elimina Modulo",
            action: (el) => {
              const formId = el.dataset.id;
              if (!formId) return;
              showConfirm("Vuoi davvero eliminare il modulo?", () => eliminaModulo(formId));
            },
          },
        ]);
      }
    })
    .catch(() => {
      container.innerHTML = "<p style='color:red'>Errore durante il caricamento dei moduli.</p>";
    });

  async function eliminaModulo(formId) {
    const res = await customFetch("page_editor", "deleteForm", { form_id: parseInt(formId, 10) });
    if (res.success) {
      showToast("Modulo eliminato con successo");
      location.reload();
    } else {
      showToast("Errore eliminazione: " + (res.message || "Errore sconosciuto"), "error");
    }
  }

  // ========== Modale Proprietà Pagina ==========
  const modalProprieta = document.getElementById('modal-proprieta-pagina');
  const propFormName = document.getElementById('prop-form-name');
  const propDisplayName = document.getElementById('prop-display-name');
  const propDescription = document.getElementById('prop-description');
  const propColor = document.getElementById('prop-color');
  const propColorHex = document.getElementById('prop-color-hex');
  const propResponsabile = document.getElementById('prop-responsabile');
  const propSection = document.getElementById('prop-section');
  const propParent = document.getElementById('prop-parent');
  const btnSaveProprieta = document.getElementById('btn-save-proprieta');

  // Sincronizza color picker con input hex
  propColor?.addEventListener('input', () => {
    if (propColorHex) propColorHex.value = propColor.value.toUpperCase();
  });
  propColorHex?.addEventListener('input', () => {
    const hex = propColorHex.value;
    if (/^#[0-9A-Fa-f]{6}$/.test(hex) && propColor) {
      propColor.value = hex;
    }
  });

  // Carica sezioni e menu padre
  async function loadSectionsForModal() {
    try {
      const res = await customFetch('menu_custom', 'getSectionsAndParents', {});
      if (res?.success && res.data) {
        const map = res.data;
        propSection.innerHTML = '';
        Object.keys(map).forEach(k => {
          const opt = document.createElement('option');
          opt.value = k;
          opt.textContent = k;
          propSection.appendChild(opt);
        });

        const refreshParents = () => {
          const s = propSection.value;
          propParent.innerHTML = '';
          (map[s] || []).forEach(p => {
            const o = document.createElement('option');
            o.value = p;
            o.textContent = p;
            propParent.appendChild(o);
          });
        };
        propSection.onchange = refreshParents;
        refreshParents();
        return map;
      }
    } catch (e) {
      console.error('loadSectionsForModal error:', e);
    }
    return {};
  }

  // Carica responsabili
  async function loadResponsabiliForModal() {
    try {
      const res = await customFetch('page_editor', 'listResponsabili', {});
      if (res?.success && Array.isArray(res.options)) {
        propResponsabile.innerHTML = '<option value="">— nessun responsabile —</option>';
        res.options.forEach(o => {
          const opt = document.createElement('option');
          opt.value = String(o.id);
          opt.textContent = o.label;
          propResponsabile.appendChild(opt);
        });
      }
    } catch (e) {
      console.error('loadResponsabiliForModal error:', e);
    }
  }

  // Apri modale proprietà
  async function openProprietaModal(formName) {
    if (!modalProprieta) return;

    // Mostra il modale
    window.toggleModal('modal-proprieta-pagina', 'open');

    // Reset campi
    propFormName.value = formName;
    propDisplayName.value = '';
    propDescription.value = '';
    propColor.value = '#CCCCCC';
    propColorHex.value = '#CCCCCC';

    // Carica dati in parallelo
    const [sectionsMap] = await Promise.all([
      loadSectionsForModal(),
      loadResponsabiliForModal()
    ]);

    // Carica dati del form
    try {
      const formData = await customFetch('page_editor', 'getForm', { form_name: formName });
      if (formData?.success && formData.form) {
        const f = formData.form;
        propDisplayName.value = f.display_name || f.name?.replace(/_/g, ' ') || '';
        propDescription.value = f.description || '';
        propColor.value = f.color || '#CCCCCC';
        propColorHex.value = f.color || '#CCCCCC';
        
        if (f.responsabile) {
          propResponsabile.value = String(f.responsabile);
        }
      }

      // Carica posizione menu corrente
      const placement = await customFetch('page_editor', 'getMenuPlacementForForm', { form_name: formName });
      if (placement?.success && placement.placement) {
        const p = placement.placement;
        if (p.section && propSection) {
          propSection.value = p.section;
          propSection.dispatchEvent(new Event('change'));
          setTimeout(() => {
            if (p.parent_title && propParent) {
              propParent.value = p.parent_title;
            }
          }, 50);
        }
      }
    } catch (e) {
      console.error('openProprietaModal error:', e);
    }
  }

  // Salva proprietà
  btnSaveProprieta?.addEventListener('click', async () => {
    const formName = propFormName.value;
    if (!formName) return;

    const displayName = propDisplayName.value.trim();
    const description = propDescription.value.trim();
    const color = propColor.value;
    const responsabile = propResponsabile.value;
    const section = propSection.value;
    const parent = propParent.value;

    if (!displayName) {
      showToast('Inserisci un nome visualizzato', 'error');
      return;
    }
    if (!section || !parent) {
      showToast('Seleziona sezione e menu padre', 'error');
      return;
    }

    try {
      console.log('Salvataggio proprietà:', { formName, displayName, description, color, responsabile, section, parent });
      
      // Aggiorna descrizione, colore e display_name
      const updateRes = await customFetch('page_editor', 'updateFormMeta', {
        form_name: formName,
        description: description,
        color: color,
        display_name: displayName
      });
      console.log('updateFormMeta response:', updateRes);

      if (!updateRes?.success) {
        showToast(updateRes?.message || 'Errore aggiornamento proprietà', 'error');
        return;
      }

      // Aggiorna responsabile se impostato
      if (responsabile) {
        const respRes = await customFetch('page_editor', 'setFormResponsabile', {
          form_name: formName,
          user_id: parseInt(responsabile, 10)
        });
        console.log('setFormResponsabile response:', respRes);
      }

      // Aggiorna posizione menu
      const menuRes = await customFetch('menu_custom', 'upsert', {
        menu_section: section,
        parent_title: parent,
        title: formName,
        link: `index.php?section=${section}&page=gestione_segnalazioni&form_name=${encodeURIComponent(formName)}`,
        attivo: 1
      });
      console.log('menu_custom upsert response:', menuRes);

      showToast('Proprietà salvate con successo', 'success');
      window.toggleModal('modal-proprieta-pagina', 'close');
      
      // Ricarica la pagina dopo un tempo sufficiente per vedere il toast
      setTimeout(() => location.reload(), 1500);

    } catch (e) {
      console.error('Errore salvataggio proprietà:', e);
      showToast('Errore durante il salvataggio: ' + e.message, 'error');
    }
  });

});
